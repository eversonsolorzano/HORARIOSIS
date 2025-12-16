<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

$id_inscripcion = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_inscripcion) {
    Funciones::redireccionar('index.php', 'ID de inscripción no válido', 'error');
}

// Obtener datos de la inscripción
$stmt = $db->prepare("
    SELECT i.*, 
           e.codigo_estudiante, e.nombres as estudiante_nombres, e.apellidos as estudiante_apellidos,
           c.nombre_curso, c.codigo_curso,
           h.dia_semana, h.hora_inicio, h.hora_fin,
           s.nombre_semestre,
           CONCAT(p.nombres, ' ', p.apellidos) as profesor_nombre
    FROM inscripciones i
    JOIN estudiantes e ON i.id_estudiante = e.id_estudiante
    JOIN horarios h ON i.id_horario = h.id_horario
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
    JOIN profesores p ON h.id_profesor = p.id_profesor
    WHERE i.id_inscripcion = ? AND i.estado = 'inscrito'
");

$stmt->execute([$id_inscripcion]);
$inscripcion = $stmt->fetch();

if (!$inscripcion) {
    Funciones::redireccionar('index.php', 'Inscripción no encontrada o no se puede retirar', 'error');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $motivo = isset($_POST['motivo']) ? Funciones::sanitizar($_POST['motivo']) : '';
    
    if (empty($motivo)) {
        Session::setFlash('Debe especificar un motivo para el retiro', 'error');
        Funciones::redireccionar('retirar.php?id=' . $id_inscripcion);
    }
    
    try {
        $db->beginTransaction();
        
        // Actualizar estado de la inscripción
        $stmt = $db->prepare("
            UPDATE inscripciones 
            SET estado = 'retirado' 
            WHERE id_inscripcion = ?
        ");
        $stmt->execute([$id_inscripcion]);
        
        // Registrar en cambios_horario
        $info_inscripcion = "Estudiante: {$inscripcion['estudiante_nombres']} {$inscripcion['estudiante_apellidos']}, " .
                           "Curso: {$inscripcion['nombre_curso']}, " .
                           "Horario: {$inscripcion['dia_semana']} {$inscripcion['hora_inicio']}-{$inscripcion['hora_fin']}";
        
        $stmt = $db->prepare("
            INSERT INTO cambios_horario 
            (id_horario, tipo_cambio, valor_anterior, valor_nuevo, realizado_por, motivo) 
            VALUES (?, 'inscripcion', ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $inscripcion['id_horario'],
            $info_inscripcion . " - Estado: inscrito",
            $info_inscripcion . " - Estado: retirado",
            $user['id'],
            $motivo
        ]);
        
        // Notificar al estudiante
        $stmt = $db->prepare("
            SELECT u.id_usuario 
            FROM estudiantes e
            JOIN usuarios u ON e.id_usuario = u.id_usuario
            WHERE e.id_estudiante = ?
        ");
        $stmt->execute([$inscripcion['id_estudiante']]);
        $estudiante_usuario = $stmt->fetch();
        
        if ($estudiante_usuario) {
            $mensaje = "Su inscripción en el curso <strong>{$inscripcion['nombre_curso']}</strong> ha sido retirada.<br>";
            $mensaje .= "Motivo: $motivo<br>";
            $mensaje .= "Fecha de retiro: " . date('d/m/Y');
            
            $stmt = $db->prepare("
                INSERT INTO notificaciones (id_usuario, tipo_notificacion, titulo, mensaje)
                VALUES (?, 'recordatorio', 'Inscripción Retirada', ?)
            ");
            $stmt->execute([$estudiante_usuario['id_usuario'], $mensaje]);
        }
        
        $db->commit();
        
        Session::setFlash('Inscripción retirada exitosamente');
        Funciones::redireccionar('index.php');
        
    } catch (Exception $e) {
        $db->rollBack();
        Session::setFlash('Error al retirar la inscripción: ' . $e->getMessage(), 'error');
        Funciones::redireccionar('retirar.php?id=' . $id_inscripcion);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retirar Inscripción - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .warning-box {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border-left: 6px solid var(--warning);
            padding: 25px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .warning-box h3 {
            color: #856404;
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
        
        .info-item {
            display: flex;
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .info-item:last-child {
            border-bottom: none;
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
        
        .motivo-box {
            margin-top: 30px;
        }
        
        .motivo-box label {
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 10px;
            display: block;
        }
        
        .motivo-box textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid var(--gray-200);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-sign-out-alt"></i> Retirar Inscripción</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-clipboard-check"></i> Inscripciones</a>
                <a href="ver.php?id=<?php echo $id_inscripcion; ?>"><i class="fas fa-eye"></i> Ver Detalles</a>
                <a href="retirar.php?id=<?php echo $id_inscripcion; ?>" class="active"><i class="fas fa-sign-out-alt"></i> Retirar</a>
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
            <h3><i class="fas fa-exclamation-triangle"></i> Confirmar Retiro de Inscripción</h3>
            <p>Esta acción cambiará el estado de la inscripción a <strong>"retirado"</strong>. Por favor, verifique la siguiente información antes de continuar:</p>
            <ul style="margin: 15px 0 15px 20px;">
                <li>El estudiante será retirado del curso seleccionado</li>
                <li>Se registrará el motivo del retiro en el historial</li>
                <li>Se notificará al estudiante sobre el retiro</li>
                <li>Esta acción puede afectar la carga académica del estudiante</li>
            </ul>
        </div>
        
        <form method="POST" class="card">
            <div class="card-header">
                <h2><i class="fas fa-clipboard-list"></i> Información de la Inscripción</h2>
            </div>
            
            <div class="card-body">
                <div class="info-box">
                    <h4><i class="fas fa-info-circle"></i> Detalles de la Inscripción a Retirar</h4>
                    
                    <div class="info-item">
                        <div class="info-label">Estudiante:</div>
                        <div class="info-value">
                            <strong><?php echo htmlspecialchars($inscripcion['estudiante_nombres'] . ' ' . $inscripcion['estudiante_apellidos']); ?></strong>
                            <span style="margin-left: 10px; color: var(--gray-600);">
                                (<?php echo $inscripcion['codigo_estudiante']; ?>)
                            </span>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Curso:</div>
                        <div class="info-value">
                            <strong><?php echo htmlspecialchars($inscripcion['nombre_curso']); ?></strong>
                            <span style="margin-left: 10px; color: var(--gray-600);">
                                (<?php echo $inscripcion['codigo_curso']; ?>)
                            </span>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Horario:</div>
                        <div class="info-value">
                            <?php echo $inscripcion['dia_semana']; ?> 
                            <?php echo Funciones::formatearHora($inscripcion['hora_inicio']); ?> - 
                            <?php echo Funciones::formatearHora($inscripcion['hora_fin']); ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Profesor:</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($inscripcion['profesor_nombre']); ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Semestre:</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($inscripcion['nombre_semestre']); ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Estado Actual:</div>
                        <div class="info-value">
                            <span class="badge badge-inscrito">Inscrito</span>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Fecha Inscripción:</div>
                        <div class="info-value">
                            <?php echo Funciones::formatearFecha($inscripcion['fecha_inscripcion']); ?>
                        </div>
                    </div>
                </div>
                
                <div class="motivo-box">
                    <label for="motivo">
                        <i class="fas fa-comment"></i> Motivo del Retiro
                        <span class="required">*</span>
                    </label>
                    <textarea id="motivo" name="motivo" class="form-control" 
                              placeholder="Describa el motivo por el cual se retira la inscripción..."
                              required></textarea>
                    <div class="help-text">
                        Este motivo será registrado en el historial y se notificará al estudiante.
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-warning" 
                            onclick="return confirm('¿Está seguro de retirar esta inscripción?')">
                        <i class="fas fa-check-circle"></i> Confirmar Retiro
                    </button>
                    <a href="ver.php?id=<?php echo $id_inscripcion; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
            </div>
        </form>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Validar que el motivo no esté vacío
        document.querySelector('form').addEventListener('submit', function(e) {
            const motivo = document.getElementById('motivo').value.trim();
            if (motivo.length < 10) {
                e.preventDefault();
                alert('Por favor, proporcione un motivo detallado (mínimo 10 caracteres)');
                document.getElementById('motivo').focus();
                return false;
            }
            
            return true;
        });
    });
    </script>
</body>
</html>