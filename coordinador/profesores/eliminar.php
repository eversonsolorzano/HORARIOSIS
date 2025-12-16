<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

$id_profesor = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_profesor) {
    Funciones::redireccionar('index.php', 'ID de profesor no válido', 'error');
}

// Obtener datos del profesor
$stmt = $db->prepare("
    SELECT p.*, u.email, u.username
    FROM profesores p
    JOIN usuarios u ON p.id_usuario = u.id_usuario
    WHERE p.id_profesor = ?
");
$stmt->execute([$id_profesor]);
$profesor = $stmt->fetch();

if (!$profesor) {
    Funciones::redireccionar('index.php', 'Profesor no encontrado', 'error');
}

// Verificar si hay horarios activos
$stmt = $db->prepare("
    SELECT COUNT(*) as total_horarios,
           COUNT(DISTINCT i.id_estudiante) as total_estudiantes
    FROM horarios h
    LEFT JOIN inscripciones i ON h.id_horario = i.id_horario AND i.estado = 'inscrito'
    WHERE h.id_profesor = ? AND h.activo = 1
");
$stmt->execute([$id_profesor]);
$estadisticas = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $confirmacion = isset($_POST['confirmacion']) ? Funciones::sanitizar($_POST['confirmacion']) : '';
    
    if ($confirmacion !== 'ELIMINAR') {
        Session::setFlash('Debe escribir ELIMINAR para confirmar', 'error');
        Funciones::redireccionar('eliminar.php?id=' . $id_profesor);
    }
    
    try {
        $db->beginTransaction();
        
        // 1. Registrar cambio
        $stmt = $db->prepare("
            INSERT INTO cambios_horario 
            (tipo_cambio, valor_anterior, valor_nuevo, realizado_por, motivo, id_horario)
            VALUES (?, ?, ?, ?, ?, NULL)
        ");
        
        $info_profesor = "Profesor eliminado: {$profesor['nombres']} {$profesor['apellidos']} - " .
                        "Código: {$profesor['codigo_profesor']} - " .
                        "Documento: {$profesor['documento_identidad']}";
        
        $stmt->execute([
            'eliminado', 
            $info_profesor,
            'ELIMINADO PERMANENTEMENTE', 
            $user['id'],
            $_POST['motivo'] ?? 'Eliminación permanente desde panel'
        ]);
        
        // 2. Desactivar todos los horarios del profesor
        $stmt = $db->prepare("UPDATE horarios SET activo = 0 WHERE id_profesor = ?");
        $stmt->execute([$id_profesor]);
        
        // 3. Notificar a estudiantes afectados
        $stmt = $db->prepare("
            SELECT DISTINCT e.id_usuario, e.nombres, e.apellidos
            FROM inscripciones i
            JOIN estudiantes e ON i.id_estudiante = e.id_estudiante
            JOIN horarios h ON i.id_horario = h.id_horario
            WHERE h.id_profesor = ? AND i.estado = 'inscrito'
        ");
        $stmt->execute([$id_profesor]);
        $estudiantes_afectados = $stmt->fetchAll();
        
        foreach ($estudiantes_afectados as $estudiante) {
            $stmt = $db->prepare("
                INSERT INTO notificaciones (id_usuario, tipo_notificacion, titulo, mensaje)
                VALUES (?, 'cambio_horario', 'Profesor eliminado', ?)
            ");
            $mensaje = "El profesor " . htmlspecialchars($profesor['nombres'] . ' ' . $profesor['apellidos']) . 
                      " ha sido eliminado del sistema. Sus horarios han sido cancelados.";
            $stmt->execute([$estudiante['id_usuario'], $mensaje]);
        }
        
        // 4. Desactivar usuario (en lugar de eliminar para mantener integridad referencial)
        $stmt = $db->prepare("UPDATE usuarios SET activo = 0 WHERE id_usuario = ?");
        $stmt->execute([$profesor['id_usuario']]);
        
        // 5. Desactivar profesor
        $stmt = $db->prepare("UPDATE profesores SET activo = 0 WHERE id_profesor = ?");
        $stmt->execute([$id_profesor]);
        
        // 6. Registrar eliminación
        $stmt = $db->prepare("
            INSERT INTO eliminaciones_registro 
            (tipo_entidad, id_entidad, datos_entidad, eliminado_por, motivo, fecha_eliminacion)
            VALUES ('profesor', ?, ?, ?, ?, NOW())
        ");
        
        $datos_profesor = json_encode([
            'codigo_profesor' => $profesor['codigo_profesor'],
            'documento_identidad' => $profesor['documento_identidad'],
            'nombres' => $profesor['nombres'],
            'apellidos' => $profesor['apellidos'],
            'email' => $profesor['email'],
            'email_institucional' => $profesor['email_institucional'],
            'telefono' => $profesor['telefono'],
            'titulo_academico' => $profesor['titulo_academico'],
            'especialidad' => $profesor['especialidad'],
            'programas_dictados' => $profesor['programas_dictados']
        ], JSON_UNESCAPED_UNICODE);
        
        $stmt->execute([
            $id_profesor,
            $datos_profesor,
            $user['id'],
            $_POST['motivo'] ?? 'Eliminación permanente'
        ]);
        
        $db->commit();
        
        Session::setFlash('Profesor eliminado exitosamente. ' . 
                         $estadisticas['total_horarios'] . ' horarios desactivados. ' .
                         $estadisticas['total_estudiantes'] . ' estudiantes afectados.');
        Funciones::redireccionar('index.php');
        
    } catch (Exception $e) {
        $db->rollBack();
        Session::setFlash('Error al eliminar profesor: ' . $e->getMessage(), 'error');
        Funciones::redireccionar('eliminar.php?id=' . $id_profesor);
    }
}

// Crear tabla de eliminaciones_registro si no existe
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS eliminaciones_registro (
            id_eliminacion INT PRIMARY KEY AUTO_INCREMENT,
            tipo_entidad VARCHAR(50) NOT NULL,
            id_entidad INT NOT NULL,
            datos_entidad TEXT NOT NULL,
            eliminado_por INT NOT NULL,
            motivo TEXT,
            fecha_eliminacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (eliminado_por) REFERENCES usuarios(id_usuario)
        )
    ");
} catch (Exception $e) {
    // Tabla ya existe o error, continuar
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eliminar Profesor - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .danger-box {
            background: linear-gradient(135deg, #fed7d7 0%, #feb2b2 100%);
            border-left: 6px solid var(--danger);
            padding: 25px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .info-box {
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            border-left: 6px solid var(--warning);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .danger-box h3, .info-box h3 {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .danger-box h3 { color: #c53030; }
        .info-box h3 { color: var(--dark); }
        
        .info-item {
            display: flex;
            margin-bottom: 8px;
        }
        
        .info-label {
            font-weight: 600;
            color: var(--gray-700);
            width: 150px;
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
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .consecuencias {
            background: var(--gray-100);
            padding: 15px;
            border-radius: var(--radius);
            margin: 20px 0;
        }
        
        .consecuencias li {
            margin-bottom: 8px;
            padding-left: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-trash"></i> Eliminar Profesor</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
                <a href="ver.php?id=<?php echo $id_profesor; ?>"><i class="fas fa-eye"></i> Ver</a>
                <a href="cambiar_estado.php?id=<?php echo $id_profesor; ?>">
                    <i class="fas fa-<?php echo $profesor['activo'] ? 'times' : 'check'; ?>"></i>
                    <?php echo $profesor['activo'] ? 'Desactivar' : 'Activar'; ?>
                </a>
                <a href="eliminar.php?id=<?php echo $id_profesor; ?>" class="active">
                    <i class="fas fa-trash"></i> Eliminar
                </a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <div class="danger-box">
            <h3><i class="fas fa-skull-crossbones"></i> ADVERTENCIA: ACCIÓN PERMANENTE</h3>
            <p>Está a punto de eliminar permanentemente un profesor del sistema. Esta acción:</p>
            <div class="consecuencias">
                <ul>
                    <li><strong>NO SE PUEDE DESHACER</strong> - La eliminación es permanente</li>
                    <li><strong>Desactivará todos los horarios</strong> asignados (<?php echo $estadisticas['total_horarios']; ?> horarios)</li>
                    <li><strong>Afectará a <?php echo $estadisticas['total_estudiantes']; ?> estudiantes</strong> inscritos en sus cursos</li>
                    <li><strong>El profesor no podrá volver a acceder</strong> al sistema</li>
                    <li><strong>Se mantendrá un registro</strong> de la eliminación para auditoría</li>
                    <li><strong>Los datos permanecerán</strong> en la base de datos pero desactivados</li>
                </ul>
            </div>
            <p><strong style="color: #c53030;">¿Está ABSOLUTAMENTE seguro de que desea continuar?</strong></p>
            <p style="margin-top: 15px;">
                <i class="fas fa-lightbulb"></i> 
                <strong>Recomendación:</strong> Considere usar "Desactivar" en lugar de eliminar, 
                para mantener el historial y poder reactivar en el futuro.
            </p>
        </div>
        
        <div class="info-box">
            <h3><i class="fas fa-info-circle"></i> Información del Profesor a Eliminar</h3>
            
            <div class="info-item">
                <div class="info-label">Profesor:</div>
                <div class="info-value">
                    <strong><?php echo htmlspecialchars($profesor['nombres'] . ' ' . $profesor['apellidos']); ?></strong>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Código:</div>
                <div class="info-value"><?php echo htmlspecialchars($profesor['codigo_profesor']); ?></div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Documento:</div>
                <div class="info-value"><?php echo htmlspecialchars($profesor['documento_identidad']); ?></div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Email Institucional:</div>
                <div class="info-value"><?php echo htmlspecialchars($profesor['email_institucional']); ?></div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Horarios Activos:</div>
                <div class="info-value">
                    <strong style="color: var(--danger);"><?php echo $estadisticas['total_horarios']; ?> horarios</strong>
                    <span style="color: var(--danger); font-size: 13px; margin-left: 10px;">
                        <i class="fas fa-exclamation-circle"></i> Serán desactivados
                    </span>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Estudiantes Afectados:</div>
                <div class="info-value">
                    <strong style="color: var(--danger);"><?php echo $estadisticas['total_estudiantes']; ?> estudiantes</strong>
                    <span style="color: var(--danger); font-size: 13px; margin-left: 10px;">
                        <i class="fas fa-exclamation-circle"></i> Serán notificados
                    </span>
                </div>
            </div>
        </div>
        
        <form method="POST" class="card">
            <div class="card-header">
                <h3><i class="fas fa-shield-alt"></i> Confirmar Eliminación</h3>
            </div>
            
            <div class="card-body">
                <div class="form-group">
                    <label for="confirmacion">
                        <i class="fas fa-keyboard"></i> 
                        Para confirmar la eliminación PERMANENTE, escriba 
                        <strong style="color: var(--danger); text-transform: uppercase;">"ELIMINAR"</strong>
                        en el siguiente campo:
                    </label>
                    <input type="text" id="confirmacion" name="confirmacion" 
                           class="form-control confirm-input" 
                           placeholder="ELIMINAR"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="motivo">
                        <i class="fas fa-comment"></i> Motivo de la eliminación (obligatorio)
                    </label>
                    <textarea id="motivo" name="motivo" class="form-control" 
                              rows="4" placeholder="Explique detalladamente el motivo de la eliminación..."
                              required></textarea>
                    <div class="help-text">Este motivo quedará registrado en el historial de auditoría.</div>
                </div>
                
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Última advertencia:</strong> Una vez eliminado, el profesor no podrá ser recuperado.
                    Considere desactivarlo en lugar de eliminarlo.
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-danger" id="submitBtn" disabled>
                        <i class="fas fa-trash"></i> Eliminar Permanentemente
                    </button>
                    <a href="cambiar_estado.php?id=<?php echo $id_profesor; ?>" class="btn btn-warning">
                        <i class="fas fa-times"></i> Solo Desactivar
                    </a>
                    <a href="ver.php?id=<?php echo $id_profesor; ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Cancelar
                    </a>
                </div>
            </div>
        </form>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const confirmInput = document.getElementById('confirmacion');
            const motivoInput = document.getElementById('motivo');
            const submitBtn = document.getElementById('submitBtn');
            
            function validateForm() {
                const confirmValue = confirmInput.value.toUpperCase().trim();
                const motivoValue = motivoInput.value.trim();
                
                if (confirmValue === 'ELIMINAR' && motivoValue.length >= 10) {
                    submitBtn.disabled = false;
                    confirmInput.style.borderColor = 'var(--success)';
                    confirmInput.style.backgroundColor = 'rgba(16, 185, 129, 0.1)';
                } else {
                    submitBtn.disabled = true;
                    confirmInput.style.borderColor = 'var(--danger)';
                    confirmInput.style.backgroundColor = 'rgba(239, 68, 68, 0.1)';
                }
            }
            
            confirmInput.addEventListener('input', validateForm);
            motivoInput.addEventListener('input', validateForm);
            
            // Validación inicial
            validateForm();
            
            // Confirmación final
            document.querySelector('form').addEventListener('submit', function(e) {
                if (!confirm('¿ESTÁ ABSOLUTAMENTE SEGURO DE QUE DESEA ELIMINAR PERMANENTEMENTE ESTE PROFESOR?\n\nEsta acción NO se puede deshacer.')) {
                    e.preventDefault();
                    return false;
                }
                
                return true;
            });
        });
    </script>
</body>
</html>