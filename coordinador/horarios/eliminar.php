<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

// Obtener ID del horario
$id_horario = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_horario) {
    Funciones::redireccionar('index.php', 'ID de horario no válido', 'error');
}

// Obtener datos completos del horario antes de eliminar
$stmt = $db->prepare("
    SELECT h.*, 
           c.nombre_curso, c.codigo_curso,
           p.nombres as profesor_nombres, p.apellidos as profesor_apellidos,
           a.codigo_aula, a.nombre_aula,
           s.codigo_semestre, s.estado as estado_semestre
    FROM horarios h
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN profesores p ON h.id_profesor = p.id_profesor
    JOIN aulas a ON h.id_aula = h.id_aula
    JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
    WHERE h.id_horario = ?
");
$stmt->execute([$id_horario]);
$horario = $stmt->fetch();

if (!$horario) {
    Funciones::redireccionar('index.php', 'Horario no encontrado', 'error');
}

// Verificar si el semestre está finalizado
if ($horario['estado_semestre'] == 'finalizado') {
    Session::setFlash('No se puede eliminar horarios de semestres finalizados', 'error');
    Funciones::redireccionar('index.php');
}

// Verificar si hay estudiantes inscritos
$stmt = $db->prepare("
    SELECT COUNT(*) as total, 
           GROUP_CONCAT(CONCAT(e.nombres, ' ', e.apellidos) SEPARATOR ', ') as nombres_estudiantes
    FROM inscripciones i
    JOIN estudiantes e ON i.id_estudiante = e.id_estudiante
    WHERE i.id_horario = ? AND i.estado = 'inscrito'
");
$stmt->execute([$id_horario]);
$inscripciones = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $confirmacion = isset($_POST['confirmacion']) ? Funciones::sanitizar($_POST['confirmacion']) : '';
    $motivo = isset($_POST['motivo']) ? Funciones::sanitizar($_POST['motivo']) : '';
    
    if ($confirmacion !== 'ELIMINAR') {
        Session::setFlash('Debe escribir "ELIMINAR" en mayúsculas para confirmar', 'error');
        Funciones::redireccionar('eliminar.php?id=' . $id_horario);
    }
    
    if (empty($motivo)) {
        Session::setFlash('Debe especificar un motivo para la eliminación', 'error');
        Funciones::redireccionar('eliminar.php?id=' . $id_horario);
    }
    
    try {
        $db->beginTransaction();
        
        // 1. Primero crear notificaciones para estudiantes y profesor
        if ($inscripciones['total'] > 0) {
            // Obtener IDs de usuarios de estudiantes inscritos
            $stmt = $db->prepare("
                SELECT u.id_usuario 
                FROM inscripciones i
                JOIN estudiantes e ON i.id_estudiante = e.id_estudiante
                JOIN usuarios u ON e.id_usuario = u.id_usuario
                WHERE i.id_horario = ? AND i.estado = 'inscrito'
            ");
            $stmt->execute([$id_horario]);
            $estudiantes_usuarios = $stmt->fetchAll();
            
            foreach ($estudiantes_usuarios as $estudiante) {
                $stmt = $db->prepare("
                    INSERT INTO notificaciones 
                    (id_usuario, tipo_notificacion, titulo, mensaje, link_accion)
                    VALUES (?, 'cambio_horario', ?, ?, ?)
                ");
                
                $mensaje = "El horario del curso {$horario['nombre_curso']} ha sido eliminado. " .
                          "Día: {$horario['dia_semana']}, Hora: " .
                          Funciones::formatearHora($horario['hora_inicio']) . " - " .
                          Funciones::formatearHora($horario['hora_fin']) .
                          ". Motivo: $motivo";
                
                $stmt->execute([
                    $estudiante['id_usuario'],
                    'Horario Eliminado',
                    $mensaje,
                    '/estudiante/mis_cursos.php'
                ]);
            }
        }
        
        // 2. Notificación para el profesor
        $stmt = $db->prepare("
            SELECT u.id_usuario 
            FROM profesores p
            JOIN usuarios u ON p.id_usuario = u.id_usuario
            WHERE p.id_profesor = ?
        ");
        $stmt->execute([$horario['id_profesor']]);
        $profesor_usuario = $stmt->fetch();
        
        if ($profesor_usuario) {
            $stmt = $db->prepare("
                INSERT INTO notificaciones 
                (id_usuario, tipo_notificacion, titulo, mensaje, link_accion)
                VALUES (?, 'cambio_horario', ?, ?, ?)
            ");
            
            $mensaje_prof = "Su horario del curso {$horario['nombre_curso']} ha sido eliminado. " .
                           "Día: {$horario['dia_semana']}, Hora: " .
                           Funciones::formatearHora($horario['hora_inicio']) . " - " .
                           Funciones::formatearHora($horario['hora_fin']) .
                           ". Motivo: $motivo";
            
            $stmt->execute([
                $profesor_usuario['id_usuario'],
                'Horario Eliminado',
                $mensaje_prof,
                '/profesor/mis_horarios.php'
            ]);
        }
        
        // 3. Registrar cambio en el historial
        $stmt = $db->prepare("
            INSERT INTO cambios_horario 
            (id_horario, tipo_cambio, valor_anterior, valor_nuevo, realizado_por, motivo) 
            VALUES (?, 'eliminado', ?, ?, ?, ?)
        ");
        
        $info_horario = "Curso: {$horario['codigo_curso']} - {$horario['nombre_curso']}, " .
                       "Profesor: {$horario['profesor_nombres']} {$horario['profesor_apellidos']}, " .
                       "Aula: {$horario['codigo_aula']}, " .
                       "Día: {$horario['dia_semana']}, " .
                       "Hora: " . Funciones::formatearHora($horario['hora_inicio']) . " - " . 
                       Funciones::formatearHora($horario['hora_fin']);
        
        $stmt->execute([
            $id_horario, 
            $info_horario, 
            'ELIMINADO', 
            $user['id'],
            $motivo
        ]);
        
        // 4. Cambiar estado de inscripciones a 'retirado' (en lugar de eliminarlas para mantener historial)
        $stmt = $db->prepare("
            UPDATE inscripciones 
            SET estado = 'retirado', 
                fecha_retiro = CURDATE()
            WHERE id_horario = ? AND estado = 'inscrito'
        ");
        $stmt->execute([$id_horario]);
        
        // 5. Eliminar horario
        $stmt = $db->prepare("DELETE FROM horarios WHERE id_horario = ?");
        $stmt->execute([$id_horario]);
        
        $db->commit();
        
        Session::setFlash('Horario eliminado exitosamente. ' . 
                         $inscripciones['total'] . ' estudiantes fueron desinscritos y notificados.');
        Funciones::redireccionar('index.php');
        
    } catch (Exception $e) {
        $db->rollBack();
        Session::setFlash('Error al eliminar el horario: ' . $e->getMessage(), 'error');
        Funciones::redireccionar('eliminar.php?id=' . $id_horario);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eliminar Horario - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .warning-box {
            background: linear-gradient(135deg, #fed7d7 0%, #feb2b2 100%);
            border-left: 6px solid var(--danger);
            padding: 25px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .warning-box h3 {
            color: #c53030;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-box {
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            border-left: 6px solid var(--primary);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .info-box h4 {
            color: var(--dark);
            margin-bottom: 15px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .info-item {
            display: flex;
            margin-bottom: 10px;
        }
        
        .info-label {
            font-weight: 600;
            color: var(--gray-700);
            width: 150px;
            flex-shrink: 0;
        }
        
        .info-value {
            color: var(--dark);
            flex: 1;
        }
        
        .confirm-input {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            text-align: center;
        }
        
        .students-box {
            background: var(--gray-50);
            padding: 15px;
            border-radius: var(--radius);
            margin-top: 10px;
            max-height: 150px;
            overflow-y: auto;
            font-size: 14px;
        }
        
        .student-item {
            padding: 5px 0;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .student-item:last-child {
            border-bottom: none;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-700);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-trash"></i> Eliminar Horario</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-calendar-alt"></i> Horarios</a>
                <a href="ver.php?id=<?php echo $id_horario; ?>"><i class="fas fa-eye"></i> Ver Horario</a>
                <a href="eliminar.php?id=<?php echo $id_horario; ?>" class="active"><i class="fas fa-trash"></i> Eliminar</a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <div class="warning-box">
            <h3><i class="fas fa-exclamation-triangle"></i> ADVERTENCIA: Acción Crítica</h3>
            <p>Está a punto de eliminar permanentemente un horario. Esta acción:</p>
            <ul style="margin: 15px 0 15px 20px;">
                <li><strong>NO se puede deshacer</strong></li>
                <li>Desinscribirá a <strong><?php echo $inscripciones['total']; ?> estudiantes</strong></li>
                <li>Enviará notificaciones a todos los afectados</li>
                <li>Quedará registrada en el historial de cambios</li>
                <li>El profesor será notificado y perderá esta asignación</li>
            </ul>
            <p><strong>Recomendación:</strong> Considere desactivar el horario en lugar de eliminarlo.</p>
            <p><strong>¿Está absolutamente seguro de que desea continuar?</strong></p>
        </div>
        
        <div class="info-box">
            <h4><i class="fas fa-info-circle"></i> Información del Horario</h4>
            
            <div class="info-grid">
                <div>
                    <div class="info-item">
                        <div class="info-label">Curso:</div>
                        <div class="info-value">
                            <strong><?php echo htmlspecialchars($horario['codigo_curso']); ?></strong> - 
                            <?php echo htmlspecialchars($horario['nombre_curso']); ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Profesor:</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($horario['profesor_nombres'] . ' ' . $horario['profesor_apellidos']); ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Semestre:</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($horario['codigo_semestre']); ?>
                        </div>
                    </div>
                </div>
                
                <div>
                    <div class="info-item">
                        <div class="info-label">Aula:</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($horario['codigo_aula']); ?> - 
                            <?php echo htmlspecialchars($horario['nombre_aula']); ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Horario:</div>
                        <div class="info-value">
                            <strong><?php echo $horario['dia_semana']; ?></strong> 
                            <?php echo Funciones::formatearHora($horario['hora_inicio']); ?> - 
                            <?php echo Funciones::formatearHora($horario['hora_fin']); ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Tipo de Clase:</div>
                        <div class="info-value">
                            <?php echo ucfirst($horario['tipo_clase']); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Estudiantes Inscritos:</div>
                <div class="info-value">
                    <strong style="color: <?php echo $inscripciones['total'] > 0 ? 'var(--danger)' : 'var(--success)'; ?>">
                        <?php echo $inscripciones['total']; ?> estudiantes
                    </strong>
                    <?php if ($inscripciones['total'] > 0): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" 
                                onclick="toggleStudents()" style="margin-left: 10px;">
                            <i class="fas fa-users"></i> Ver lista
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($inscripciones['total'] > 0 && !empty($inscripciones['nombres_estudiantes'])): ?>
            <div id="studentsList" class="students-box" style="display: none;">
                <?php 
                $estudiantes_lista = explode(', ', $inscripciones['nombres_estudiantes']);
                foreach ($estudiantes_lista as $estudiante): ?>
                    <div class="student-item"><?php echo htmlspecialchars($estudiante); ?></div>
                <?php endforeach; ?>
            </div>
            
            <script>
                function toggleStudents() {
                    const list = document.getElementById('studentsList');
                    list.style.display = list.style.display === 'none' ? 'block' : 'none';
                }
            </script>
            <?php endif; ?>
        </div>
        
        <form method="POST" class="form-box" onsubmit="return confirmDelete()">
            <div class="form-group">
                <label for="motivo">
                    <i class="fas fa-comment"></i> Motivo de la eliminación *
                </label>
                <textarea name="motivo" id="motivo" rows="3" 
                          class="form-control" 
                          placeholder="Describa el motivo por el cual está eliminando este horario..."
                          required></textarea>
                <small class="text-muted">Este motivo será visible en el historial y en las notificaciones.</small>
            </div>
            
            <div class="form-group">
                <label for="confirmacion">
                    <i class="fas fa-keyboard"></i> Confirmación *
                </label>
                <input type="text" 
                       name="confirmacion" 
                       id="confirmacion" 
                       class="form-control confirm-input" 
                       placeholder="ESCRIBA 'ELIMINAR' PARA CONFIRMAR"
                       required
                       style="color: var(--danger); font-size: 18px;">
                <small class="text-muted">Debe escribir exactamente <strong>ELIMINAR</strong> en mayúsculas para proceder.</small>
            </div>
            
            <div class="form-actions">
                <a href="ver.php?id=<?php echo $id_horario; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Cancelar y Volver
                </a>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Eliminar Permanentemente
                </button>
            </div>
        </form>
    </div>
    
    <script>
        function confirmDelete() {
            const confirmacion = document.getElementById('confirmacion').value;
            if (confirmacion !== 'ELIMINAR') {
                alert('Debe escribir "ELIMINAR" para confirmar la eliminación.');
                return false;
            }
            
            return confirm('¿ESTÁ ABSOLUTAMENTE SEGURO?\n\nEsta acción eliminará permanentemente el horario, desinscribirá a los estudiantes y enviará notificaciones.\n\nNo podrá deshacer esta acción.');
        }
        
        // Enfocar el campo de confirmación
        document.getElementById('confirmacion').focus();
    </script>
</body>
</html>