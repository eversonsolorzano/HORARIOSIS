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
    SELECT p.*, u.email, u.username, u.activo as usuario_activo
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
$stmt = $db->prepare("SELECT COUNT(*) as total FROM horarios WHERE id_profesor = ? AND activo = 1");
$stmt->execute([$id_profesor]);
$horarios_activos = $stmt->fetch()['total'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $confirmacion = isset($_POST['confirmacion']) ? Funciones::sanitizar($_POST['confirmacion']) : '';
    
    $accion = $profesor['activo'] ? 'desactivar' : 'activar';
    $palabra_confirmacion = strtoupper($accion);
    
    if ($confirmacion !== $palabra_confirmacion) {
        Session::setFlash("Debe escribir '$palabra_confirmacion' para confirmar", 'error');
        Funciones::redireccionar('cambiar_estado.php?id=' . $id_profesor);
    }
    
    try {
        $db->beginTransaction();
        
        $nuevo_estado = $profesor['activo'] ? 0 : 1;
        $nuevo_estado_usuario = $profesor['usuario_activo'] ? 0 : 1;
        
        // Actualizar estado del profesor
        $stmt = $db->prepare("UPDATE profesores SET activo = ? WHERE id_profesor = ?");
        $stmt->execute([$nuevo_estado, $id_profesor]);
        
        // Actualizar estado del usuario
        $stmt = $db->prepare("UPDATE usuarios SET activo = ? WHERE id_usuario = ?");
        $stmt->execute([$nuevo_estado_usuario, $profesor['id_usuario']]);
        
        // Desactivar horarios si se está desactivando al profesor
        if ($nuevo_estado == 0) {
            $stmt = $db->prepare("UPDATE horarios SET activo = 0 WHERE id_profesor = ?");
            $stmt->execute([$id_profesor]);
            
            // Notificar a estudiantes inscritos
            $stmt = $db->prepare("
                SELECT DISTINCT i.id_estudiante, e.id_usuario 
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
                    VALUES (?, 'cambio_horario', 'Profesor desactivado', ?)
                ");
                $mensaje = "El profesor " . htmlspecialchars($profesor['nombres'] . ' ' . $profesor['apellidos']) . 
                          " ha sido desactivado. Sus horarios han sido suspendidos temporalmente.";
                $stmt->execute([$estudiante['id_usuario'], $mensaje]);
            }
        }
        
        // Registrar en cambios_horario
        $stmt = $db->prepare("
            INSERT INTO cambios_horario 
            (tipo_cambio, valor_anterior, valor_nuevo, realizado_por, motivo, id_horario)
            VALUES (?, ?, ?, ?, ?, NULL)
        ");
        
        $valor_anterior = $profesor['activo'] ? 'Activo' : 'Inactivo';
        $valor_nuevo = $nuevo_estado ? 'Activo' : 'Inactivo';
        $motivo = $_POST['motivo'] ?? ($nuevo_estado ? 'Activación desde panel' : 'Desactivación desde panel');
        
        $stmt->execute([
            'profesor', 
            "Profesor: {$profesor['nombres']} {$profesor['apellidos']} - Estado: {$valor_anterior}",
            "Profesor: {$profesor['nombres']} {$profesor['apellidos']} - Estado: {$valor_nuevo}",
            $user['id'],
            $motivo
        ]);
        
        // Enviar notificación al profesor
        $stmt = $db->prepare("
            INSERT INTO notificaciones (id_usuario, tipo_notificacion, titulo, mensaje)
            VALUES (?, 'sistema', 'Estado de cuenta actualizado', ?)
        ");
        
        if ($nuevo_estado) {
            $mensaje = "Su cuenta ha sido activada. Ya puede acceder al sistema.";
        } else {
            $mensaje = "Su cuenta ha sido desactivada. No podrá acceder al sistema temporalmente.";
        }
        
        $stmt->execute([$profesor['id_usuario'], $mensaje]);
        
        $db->commit();
        
        Session::setFlash("Profesor " . ($nuevo_estado ? "activado" : "desactivado") . " exitosamente");
        Funciones::redireccionar('ver.php?id=' . $id_profesor);
        
    } catch (Exception $e) {
        $db->rollBack();
        Session::setFlash('Error al cambiar estado: ' . $e->getMessage(), 'error');
        Funciones::redireccionar('cambiar_estado.php?id=' . $id_profesor);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambiar Estado - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .warning-box {
            background: linear-gradient(135deg, #fed7d7 0%, #feb2b2 100%);
            border-left: 6px solid var(--danger);
            padding: 25px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .info-box {
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            border-left: 6px solid var(--primary);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .warning-box h3, .info-box h3 {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .warning-box h3 { color: #c53030; }
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-<?php echo $profesor['activo'] ? 'times' : 'check'; ?>"></i>
                <?php echo $profesor['activo'] ? 'Desactivar' : 'Activar'; ?> Profesor
            </h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
                <a href="ver.php?id=<?php echo $id_profesor; ?>"><i class="fas fa-eye"></i> Ver</a>
                <a href="cambiar_estado.php?id=<?php echo $id_profesor; ?>" class="active">
                    <i class="fas fa-<?php echo $profesor['activo'] ? 'times' : 'check'; ?>"></i>
                    <?php echo $profesor['activo'] ? 'Desactivar' : 'Activar'; ?>
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
        
        <?php if ($profesor['activo']): ?>
            <div class="warning-box">
                <h3><i class="fas fa-exclamation-triangle"></i> ADVERTENCIA: Desactivar Profesor</h3>
                <p>Está a punto de desactivar un profesor. Esta acción:</p>
                <ul style="margin: 15px 0 15px 20px;">
                    <li><strong>Desactivará la cuenta de usuario</strong> del profesor</li>
                    <li><strong>Desactivará todos los horarios</strong> asignados al profesor (<?php echo $horarios_activos; ?> horarios activos)</li>
                    <li><strong>Notificará a los estudiantes</strong> inscritos en sus cursos</li>
                    <li>El profesor <strong>no podrá acceder al sistema</strong></li>
                    <li>Se puede reactivar en cualquier momento</li>
                </ul>
                <p><strong>¿Está seguro de que desea continuar?</strong></p>
            </div>
        <?php else: ?>
            <div class="info-box">
                <h3><i class="fas fa-check-circle"></i> Activar Profesor</h3>
                <p>Está a punto de activar un profesor. Esta acción:</p>
                <ul style="margin: 15px 0 15px 20px;">
                    <li><strong>Activará la cuenta de usuario</strong> del profesor</li>
                    <li>El profesor <strong>podrá acceder al sistema</strong></li>
                    <li><strong>No reactivará automáticamente</strong> los horarios anteriores</li>
                    <li>Debe asignar nuevos horarios manualmente si es necesario</li>
                </ul>
                <p><strong>¿Desea activar este profesor?</strong></p>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <h3><i class="fas fa-info-circle"></i> Información del Profesor</h3>
            
            <div class="info-item">
                <div class="info-label">Profesor:</div>
                <div class="info-value">
                    <?php echo htmlspecialchars($profesor['nombres'] . ' ' . $profesor['apellidos']); ?>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Código:</div>
                <div class="info-value">
                    <?php echo htmlspecialchars($profesor['codigo_profesor']); ?>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Documento:</div>
                <div class="info-value">
                    <?php echo htmlspecialchars($profesor['documento_identidad']); ?>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Estado Actual:</div>
                <div class="info-value">
                    <span class="badge <?php echo $profesor['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                        <?php echo $profesor['activo'] ? 'Activo' : 'Inactivo'; ?>
                    </span>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Horarios Activos:</div>
                <div class="info-value">
                    <strong style="color: <?php echo $horarios_activos > 0 ? 'var(--danger)' : 'var(--success)'; ?>">
                        <?php echo $horarios_activos; ?> horarios
                    </strong>
                    <?php if ($horarios_activos > 0 && $profesor['activo']): ?>
                        <span style="color: var(--danger); font-size: 13px; margin-left: 10px;">
                            <i class="fas fa-exclamation-circle"></i> Serán desactivados automáticamente
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <form method="POST" class="card">
            <div class="card-header">
                <h3><i class="fas fa-shield-alt"></i> Confirmar Acción</h3>
            </div>
            
            <div class="card-body">
                <div class="form-group">
                    <label for="confirmacion">
                        <i class="fas fa-keyboard"></i> 
                        Para confirmar, escriba 
                        <strong style="color: var(--danger); text-transform: uppercase;">
                            "<?php echo $profesor['activo'] ? 'DESACTIVAR' : 'ACTIVAR'; ?>"
                        </strong>
                        en el siguiente campo:
                    </label>
                    <input type="text" id="confirmacion" name="confirmacion" 
                           class="form-control confirm-input" 
                           placeholder="<?php echo $profesor['activo'] ? 'DESACTIVAR' : 'ACTIVAR'; ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="motivo">
                        <i class="fas fa-comment"></i> Motivo (opcional)
                    </label>
                    <textarea id="motivo" name="motivo" class="form-control" 
                              rows="3" placeholder="Explique el motivo de esta acción..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-<?php echo $profesor['activo'] ? 'danger' : 'success'; ?>">
                        <i class="fas fa-<?php echo $profesor['activo'] ? 'times' : 'check'; ?>"></i>
                        <?php echo $profesor['activo'] ? 'Desactivar Profesor' : 'Activar Profesor'; ?>
                    </button>
                    <a href="ver.php?id=<?php echo $id_profesor; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
            </div>
        </form>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const confirmInput = document.getElementById('confirmacion');
            const submitBtn = document.querySelector('button[type="submit"]');
            
            function validateConfirmation() {
                const requiredWord = "<?php echo $profesor['activo'] ? 'DESACTIVAR' : 'ACTIVAR'; ?>";
                const currentValue = confirmInput.value.toUpperCase().trim();
                
                if (currentValue === requiredWord) {
                    confirmInput.style.borderColor = 'var(--success)';
                    confirmInput.style.backgroundColor = 'rgba(16, 185, 129, 0.1)';
                    submitBtn.disabled = false;
                } else {
                    confirmInput.style.borderColor = 'var(--danger)';
                    confirmInput.style.backgroundColor = 'rgba(239, 68, 68, 0.1)';
                    submitBtn.disabled = true;
                }
            }
            
            confirmInput.addEventListener('input', validateConfirmation);
            validateConfirmation(); // Validación inicial
        });
    </script>
</body>
</html>