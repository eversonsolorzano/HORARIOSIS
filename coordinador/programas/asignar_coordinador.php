<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

$id_programa = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_programa) {
    Funciones::redireccionar('index.php', 'ID de programa no válido', 'error');
}

// Obtener datos del programa
$stmt = $db->prepare("
    SELECT p.*, u.username as coordinador_username, u.email as coordinador_email
    FROM programas_estudio p
    LEFT JOIN usuarios u ON p.coordinador_id = u.id_usuario
    WHERE p.id_programa = ?
");
$stmt->execute([$id_programa]);
$programa = $stmt->fetch();

if (!$programa) {
    Funciones::redireccionar('index.php', 'Programa no encontrado', 'error');
}

// Obtener coordinadores disponibles
$coordinadores = $db->query("
    SELECT id_usuario, username, email
    FROM usuarios 
    WHERE rol = 'coordinador' AND activo = 1
    ORDER BY username
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $coordinador_id = !empty($_POST['coordinador_id']) ? intval($_POST['coordinador_id']) : null;
        $coordinador_anterior = $programa['coordinador_id'];
        
        // Actualizar coordinador
        $stmt = $db->prepare("
            UPDATE programas_estudio 
            SET coordinador_id = ?
            WHERE id_programa = ?
        ");
        
        $stmt->execute([$coordinador_id, $id_programa]);
        
        // Notificar al coordinador anterior si fue removido
        if ($coordinador_anterior && $coordinador_anterior != $coordinador_id) {
            $stmt = $db->prepare("
                INSERT INTO notificaciones (id_usuario, tipo_notificacion, titulo, mensaje)
                VALUES (?, 'sistema', 'Remoción como coordinador', ?)
            ");
            
            $mensaje = "Ha sido removido como coordinador del programa: <strong>{$programa['nombre_programa']}</strong><br>";
            $mensaje .= "Código: {$programa['codigo_programa']}<br>";
            $mensaje .= "Ya no tendrá acceso a la gestión de este programa.";
            
            $stmt->execute([$coordinador_anterior, $mensaje]);
        }
        
        // Notificar al nuevo coordinador si fue asignado
        if ($coordinador_id && $coordinador_anterior != $coordinador_id) {
            // Obtener información del coordinador
            $stmt = $db->prepare("SELECT username, email FROM usuarios WHERE id_usuario = ?");
            $stmt->execute([$coordinador_id]);
            $nuevo_coordinador = $stmt->fetch();
            
            $stmt = $db->prepare("
                INSERT INTO notificaciones (id_usuario, tipo_notificacion, titulo, mensaje)
                VALUES (?, 'sistema', 'Asignación como coordinador', ?)
            ");
            
            $mensaje = "Ha sido asignado como coordinador del programa: <strong>{$programa['nombre_programa']}</strong><br>";
            $mensaje .= "Código: {$programa['codigo_programa']}<br>";
            $mensaje .= "Duración: {$programa['duracion_semestres']} semestres<br>";
            $mensaje .= "Ahora puede gestionar este programa desde el sistema.";
            
            $stmt->execute([$coordinador_id, $mensaje]);
        }
        
        Session::setFlash('Coordinador asignado exitosamente');
        Funciones::redireccionar('ver.php?id=' . $id_programa);
        
    } catch (Exception $e) {
        Session::setFlash('Error al asignar coordinador: ' . $e->getMessage(), 'error');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asignar Coordinador - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .form-container {
            max-width: 700px;
            margin: 0 auto;
        }
        
        .programa-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
            padding: 20px;
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            border-radius: var(--radius);
            border-left: 4px solid var(--primary);
        }
        
        .programa-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        
        .programa-info h3 {
            color: var(--dark);
            margin: 0 0 5px 0;
        }
        
        .programa-info p {
            margin: 0;
            color: var(--gray-600);
        }
        
        .coordinador-current {
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            border: 1px solid var(--gray-200);
        }
        
        .coordinador-current h4 {
            color: var(--dark);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .coordinador-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .coordinador-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .coordinador-details {
            flex: 1;
        }
        
        .coordinador-details strong {
            color: var(--dark);
            display: block;
            margin-bottom: 3px;
        }
        
        .coordinador-details p {
            margin: 0;
            color: var(--gray-600);
            font-size: 14px;
        }
        
        .coordinador-avatar.empty {
            background: var(--gray-400);
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--gray-700);
            font-size: 15px;
        }
        
        .coordinador-option {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border: 2px solid var(--gray-300);
            border-radius: var(--radius);
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .coordinador-option:hover {
            border-color: var(--primary);
            background-color: var(--gray-50);
        }
        
        .coordinador-option.selected {
            border-color: var(--primary);
            background: linear-gradient(135deg, var(--primary-light) 0%, rgba(59, 130, 246, 0.1) 100%);
        }
        
        .coordinador-option input[type="radio"] {
            margin: 0;
        }
        
        .coordinador-option .avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--gray-300) 0%, var(--gray-400) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
            flex-shrink: 0;
        }
        
        .coordinador-option.selected .avatar {
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
        }
        
        .coordinador-option .info {
            flex: 1;
        }
        
        .coordinador-option .info strong {
            color: var(--dark);
            display: block;
            margin-bottom: 3px;
        }
        
        .coordinador-option .info p {
            margin: 0;
            color: var(--gray-600);
            font-size: 13px;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            padding-top: 25px;
            margin-top: 25px;
            border-top: 2px solid var(--gray-100);
        }
        
        .nav .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: var(--radius);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .nav .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-tie"></i> Asignar Coordinador</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-graduation-cap"></i> Programas</a>
                <a href="ver.php?id=<?php echo $id_programa; ?>"><i class="fas fa-eye"></i> Ver</a>
                <a href="asignar_coordinador.php?id=<?php echo $id_programa; ?>" class="active">
                    <i class="fas fa-user-tie"></i> Asignar Coordinador
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
        
        <div class="programa-header">
            <div class="programa-icon">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <div class="programa-info">
                <h3><?php echo htmlspecialchars($programa['nombre_programa']); ?></h3>
                <p><i class="fas fa-hashtag"></i> <?php echo $programa['codigo_programa']; ?> | 
                   <i class="fas fa-calendar-alt"></i> <?php echo $programa['duracion_semestres']; ?> semestres</p>
            </div>
        </div>
        
        <form method="POST" class="card form-container">
            <div class="card-header">
                <h2><i class="fas fa-user-tie"></i> Seleccionar Coordinador</h2>
            </div>
            
            <div class="card-body">
                <!-- Coordinador Actual -->
                <div class="coordinador-current">
                    <h4><i class="fas fa-info-circle"></i> Coordinador Actual</h4>
                    
                    <?php if ($programa['coordinador_username']): ?>
                        <div class="coordinador-info">
                            <div class="coordinador-avatar">
                                <?php 
                                $iniciales = strtoupper(substr($programa['coordinador_username'], 0, 2));
                                echo $iniciales;
                                ?>
                            </div>
                            <div class="coordinador-details">
                                <strong><?php echo htmlspecialchars($programa['coordinador_username']); ?></strong>
                                <p><i class="fas fa-envelope"></i> <?php echo $programa['coordinador_email']; ?></p>
                                <p><i class="fas fa-user-tag"></i> Coordinador asignado actualmente</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="coordinador-info">
                            <div class="coordinador-avatar empty">
                                <i class="fas fa-user-slash"></i>
                            </div>
                            <div class="coordinador-details">
                                <strong style="color: var(--danger);">Sin coordinador asignado</strong>
                                <p>Este programa no tiene coordinador asignado actualmente.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Selección de coordinador -->
                <div class="form-group">
                    <label><i class="fas fa-users"></i> Seleccione un coordinador:</label>
                    
                    <!-- Opción: Sin coordinador -->
                    <label class="coordinador-option <?php echo !$programa['coordinador_id'] ? 'selected' : ''; ?>">
                        <input type="radio" name="coordinador_id" value="" 
                               <?php echo !$programa['coordinador_id'] ? 'checked' : ''; ?>
                               onchange="selectCoordinador(this)">
                        <div class="avatar">
                            <i class="fas fa-user-slash"></i>
                        </div>
                        <div class="info">
                            <strong>Sin coordinador</strong>
                            <p>No asignar coordinador a este programa</p>
                        </div>
                    </label>
                    
                    <!-- Lista de coordinadores -->
                    <?php foreach ($coordinadores as $coordinador): ?>
                        <label class="coordinador-option <?php echo $programa['coordinador_id'] == $coordinador['id_usuario'] ? 'selected' : ''; ?>">
                            <input type="radio" name="coordinador_id" value="<?php echo $coordinador['id_usuario']; ?>"
                                   <?php echo $programa['coordinador_id'] == $coordinador['id_usuario'] ? 'checked' : ''; ?>
                                   onchange="selectCoordinador(this)">
                            <div class="avatar">
                                <?php 
                                $iniciales = strtoupper(substr($coordinador['username'], 0, 2));
                                echo $iniciales;
                                ?>
                            </div>
                            <div class="info">
                                <strong><?php echo htmlspecialchars($coordinador['username']); ?></strong>
                                <p><i class="fas fa-envelope"></i> <?php echo $coordinador['email']; ?></p>
                                <p><i class="fas fa-user-tag"></i> Coordinador del sistema</p>
                            </div>
                        </label>
                    <?php endforeach; ?>
                    
                    <?php if (empty($coordinadores)): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            No hay coordinadores disponibles en el sistema.
                            <a href="../usuarios/crear.php?rol=coordinador" class="btn btn-sm btn-primary" style="margin-left: 10px;">
                                <i class="fas fa-plus"></i> Crear Coordinador
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Nota:</strong> El coordinador seleccionado recibirá una notificación y 
                    tendrá acceso para gestionar este programa específico.
                </div>
                
                <!-- Botones -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Asignación
                    </button>
                    <a href="ver.php?id=<?php echo $id_programa; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
            </div>
        </form>
    </div>
    
    <script>
        function selectCoordinador(radio) {
            // Remover clase selected de todas las opciones
            document.querySelectorAll('.coordinador-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Agregar clase selected a la opción seleccionada
            const label = radio.closest('.coordinador-option');
            label.classList.add('selected');
        }
        
        // Inicializar selección
        document.querySelectorAll('.coordinador-option input[type="radio"]').forEach(radio => {
            if (radio.checked) {
                selectCoordinador(radio);
            }
        });
        
        // Confirmar antes de remover coordinador
        const form = document.querySelector('form');
        form.addEventListener('submit', function(e) {
            const coordinadorActual = '<?php echo $programa['coordinador_id']; ?>';
            const nuevoCoordinador = document.querySelector('input[name="coordinador_id"]:checked').value;
            
            // Si se está removiendo un coordinador existente
            if (coordinadorActual && nuevoCoordinador === '') {
                if (!confirm('¿Está seguro de remover al coordinador actual? Se le notificará sobre este cambio.')) {
                    e.preventDefault();
                    return false;
                }
            }
            
            // Si se está cambiando de coordinador
            if (coordinadorActual && nuevoCoordinador && coordinadorActual != nuevoCoordinador) {
                if (!confirm('¿Está seguro de cambiar el coordinador? Ambos serán notificados.')) {
                    e.preventDefault();
                    return false;
                }
            }
            
            return true;
        });
    </script>
</body>
</html>