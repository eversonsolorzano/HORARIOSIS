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
        $nombre_programa = Funciones::sanitizar($_POST['nombre_programa']);
        $duracion_semestres = intval($_POST['duracion_semestres']);
        $descripcion = Funciones::sanitizar($_POST['descripcion']);
        $coordinador_id = !empty($_POST['coordinador_id']) ? intval($_POST['coordinador_id']) : null;
        
        // Verificar si el nombre ya existe (excluyendo el actual)
        $stmt = $db->prepare("SELECT id_programa FROM programas_estudio WHERE nombre_programa = ? AND id_programa != ?");
        $stmt->execute([$nombre_programa, $id_programa]);
        if ($stmt->fetch()) {
            throw new Exception("El nombre del programa ya está registrado en otro programa");
        }
        
        // Obtener coordinador anterior para notificación si cambió
        $coordinador_anterior = $programa['coordinador_id'];
        
        // Actualizar programa
        $stmt = $db->prepare("
            UPDATE programas_estudio 
            SET nombre_programa = ?, duracion_semestres = ?, descripcion = ?, coordinador_id = ?
            WHERE id_programa = ?
        ");
        
        $stmt->execute([
            $nombre_programa, 
            $duracion_semestres, 
            $descripcion, 
            $coordinador_id,
            $id_programa
        ]);
        
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
            $stmt = $db->prepare("
                INSERT INTO notificaciones (id_usuario, tipo_notificacion, titulo, mensaje)
                VALUES (?, 'sistema', 'Asignación como coordinador', ?)
            ");
            
            $mensaje = "Ha sido asignado como coordinador del programa: <strong>$nombre_programa</strong><br>";
            $mensaje .= "Código: {$programa['codigo_programa']}<br>";
            $mensaje .= "Duración: $duracion_semestres semestres<br>";
            $mensaje .= "Ahora puede gestionar este programa desde el sistema.";
            
            $stmt->execute([$coordinador_id, $mensaje]);
        }
        
        Session::setFlash('Programa actualizado exitosamente');
        Funciones::redireccionar('ver.php?id=' . $id_programa);
        
    } catch (Exception $e) {
        Session::setFlash('Error al actualizar programa: ' . $e->getMessage(), 'error');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Programa - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .form-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-700);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .required {
            color: var(--danger);
        }
        
        .help-text {
            font-size: 13px;
            color: var(--gray-500);
            margin-top: 5px;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            padding-top: 25px;
            margin-top: 25px;
            border-top: 2px solid var(--gray-100);
        }
        
        .programa-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .programa-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
        }
        
        .programa-info h2 {
            color: var(--dark);
            margin: 0 0 5px 0;
        }
        
        .programa-info p {
            margin: 0;
            color: var(--gray-600);
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
            <h1><i class="fas fa-edit"></i> Editar Programa</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-graduation-cap"></i> Programas</a>
                <a href="ver.php?id=<?php echo $id_programa; ?>"><i class="fas fa-eye"></i> Ver</a>
                <a href="editar.php?id=<?php echo $id_programa; ?>" class="active"><i class="fas fa-edit"></i> Editar</a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <!-- Encabezado del programa -->
        <div class="programa-header">
            <div class="programa-icon">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <div class="programa-info">
                <h2><?php echo htmlspecialchars($programa['nombre_programa']); ?></h2>
                <p><i class="fas fa-hashtag"></i> <?php echo $programa['codigo_programa']; ?> | 
                   <i class="fas fa-calendar-alt"></i> <?php echo $programa['duracion_semestres']; ?> semestres</p>
                <p>
                    <span class="badge <?php echo $programa['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                        <?php echo $programa['activo'] ? 'Activo' : 'Inactivo'; ?>
                    </span>
                </p>
            </div>
        </div>
        
        <form method="POST" class="card form-container">
            <div class="card-header">
                <h2><i class="fas fa-edit"></i> Editar Información del Programa</h2>
            </div>
            
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="codigo_programa">
                            <i class="fas fa-hashtag"></i> Código del Programa
                        </label>
                        <input type="text" id="codigo_programa" value="<?php echo htmlspecialchars($programa['codigo_programa']); ?>" 
                               class="form-control" readonly disabled>
                        <div class="help-text">Código único (no editable)</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="nombre_programa">
                            <i class="fas fa-font"></i> Nombre del Programa
                            <span class="required">*</span>
                        </label>
                        <input type="text" id="nombre_programa" name="nombre_programa" required
                               class="form-control"
                               value="<?php echo htmlspecialchars($programa['nombre_programa']); ?>">
                        <div class="help-text">Nombre completo del programa de estudio</div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="duracion_semestres">
                            <i class="fas fa-calendar-alt"></i> Duración (semestres)
                            <span class="required">*</span>
                        </label>
                        <select id="duracion_semestres" name="duracion_semestres" required class="form-control">
                            <option value="4" <?php echo $programa['duracion_semestres'] == 4 ? 'selected' : ''; ?>>4 semestres (2 años)</option>
                            <option value="6" <?php echo $programa['duracion_semestres'] == 6 ? 'selected' : ''; ?>>6 semestres (3 años)</option>
                            <option value="8" <?php echo $programa['duracion_semestres'] == 8 ? 'selected' : ''; ?>>8 semestres (4 años)</option>
                            <option value="10" <?php echo $programa['duracion_semestres'] == 10 ? 'selected' : ''; ?>>10 semestres (5 años)</option>
                        </select>
                        <div class="help-text">Número total de semestres del programa</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="coordinador_id">
                            <i class="fas fa-user-tie"></i> Coordinador
                        </label>
                        <select id="coordinador_id" name="coordinador_id" class="form-control">
                            <option value="">Sin coordinador asignado</option>
                            <?php foreach ($coordinadores as $coordinador): ?>
                                <option value="<?php echo $coordinador['id_usuario']; ?>"
                                    <?php echo $programa['coordinador_id'] == $coordinador['id_usuario'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($coordinador['username']); ?> 
                                    (<?php echo $coordinador['email']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">
                            <?php if ($programa['coordinador_username']): ?>
                                Coordinador actual: <?php echo htmlspecialchars($programa['coordinador_username']); ?>
                            <?php else: ?>
                                No tiene coordinador asignado
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="descripcion">
                        <i class="fas fa-align-left"></i> Descripción
                    </label>
                    <textarea id="descripcion" name="descripcion" class="form-control" 
                              rows="5" placeholder="Describa el programa de estudio, objetivos, perfil del egresado..."><?php echo htmlspecialchars($programa['descripcion']); ?></textarea>
                    <div class="help-text">Información detallada sobre el programa</div>
                </div>
                
                <!-- Botones -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Cambios
                    </button>
                    <a href="ver.php?id=<?php echo $id_programa; ?>" class="btn btn-info">
                        <i class="fas fa-eye"></i> Ver Programa
                    </a>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
            </div>
        </form>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Validar formulario
            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                const nombre = document.getElementById('nombre_programa').value.trim();
                const duracion = document.getElementById('duracion_semestres').value;
                
                if (!nombre || !duracion) {
                    e.preventDefault();
                    alert('Por favor complete todos los campos obligatorios');
                    return false;
                }
                
                return true;
            });
            
            // Confirmar si se remueve coordinador
            const coordinadorSelect = document.getElementById('coordinador_id');
            const coordinadorOriginal = '<?php echo $programa['coordinador_id']; ?>';
            
            coordinadorSelect.addEventListener('change', function() {
                if (coordinadorOriginal && this.value === '') {
                    if (!confirm('¿Está seguro de remover al coordinador actual? Se le notificará sobre este cambio.')) {
                        this.value = coordinadorOriginal;
                    }
                }
            });
        });
    </script>
</body>
</html>