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

$programas = Funciones::obtenerProgramas();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $db->beginTransaction();
        
        // Actualizar datos del profesor
        $nombres = Funciones::sanitizar($_POST['nombres']);
        $apellidos = Funciones::sanitizar($_POST['apellidos']);
        $especialidad = Funciones::sanitizar($_POST['especialidad']);
        $titulo = Funciones::sanitizar($_POST['titulo_academico']);
        $telefono = Funciones::sanitizar($_POST['telefono']);
        $email_institucional = Funciones::sanitizar($_POST['email_institucional']);
        
        // Programas dictados
        $programas_dictados = [];
        if (isset($_POST['programas_dictados']) && is_array($_POST['programas_dictados'])) {
            $programas_dictados = $_POST['programas_dictados'];
        }
        $programas_str = implode(',', $programas_dictados);
        
        $stmt = $db->prepare("
            UPDATE profesores 
            SET nombres = ?, apellidos = ?, especialidad = ?, 
                titulo_academico = ?, telefono = ?, email_institucional = ?,
                programas_dictados = ?
            WHERE id_profesor = ?
        ");
        
        $stmt->execute([
            $nombres, $apellidos, $especialidad, $titulo,
            $telefono, $email_institucional, $programas_str,
            $id_profesor
        ]);
        
        // Actualizar email del usuario si cambió
        $nuevo_email = Funciones::sanitizar($_POST['email']);
        if ($nuevo_email !== $profesor['email']) {
            $stmt = $db->prepare("UPDATE usuarios SET email = ? WHERE id_usuario = ?");
            $stmt->execute([$nuevo_email, $profesor['id_usuario']]);
        }
        
        // Actualizar contraseña si se proporcionó
        if (!empty($_POST['password'])) {
            $password = Funciones::sanitizar($_POST['password']);
            $stmt = $db->prepare("UPDATE usuarios SET password = ? WHERE id_usuario = ?");
            $stmt->execute([$password, $profesor['id_usuario']]);
        }
        
        $db->commit();
        
        Session::setFlash('Profesor actualizado exitosamente');
        Funciones::redireccionar('index.php');
        
    } catch (Exception $e) {
        $db->rollBack();
        Session::setFlash('Error al actualizar profesor: ' . $e->getMessage(), 'error');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Profesor - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .profile-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            box-shadow: var(--shadow-md);
        }
        
        .profile-info h2 {
            margin: 0 0 5px 0;
            color: var(--dark);
        }
        
        .profile-info p {
            margin: 0;
            color: var(--gray-600);
        }
        
        .stats {
            display: flex;
            gap: 15px;
            margin-top: 10px;
        }
        
        .stat-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px 15px;
            background: var(--gray-50);
            border-radius: var(--radius);
            min-width: 100px;
            border: 1px solid var(--gray-200);
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: 600;
            color: var(--primary);
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--gray-600);
            text-align: center;
        }
        
        .programas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            margin: 20px 0;
        }
        
        .programa-checkbox {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px;
            border: 2px solid var(--gray-300);
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .programa-checkbox:hover {
            border-color: var(--primary);
            background-color: var(--gray-50);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .programa-checkbox input[type="checkbox"] {
            margin: 0;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            padding-top: 25px;
            margin-top: 25px;
            border-top: 2px solid var(--gray-100);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-edit"></i> Editar Profesor</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
                <a href="ver.php?id=<?php echo $id_profesor; ?>"><i class="fas fa-eye"></i> Ver</a>
                <a href="editar.php?id=<?php echo $id_profesor; ?>" class="active"><i class="fas fa-edit"></i> Editar</a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <!-- Encabezado del perfil -->
        <div class="profile-header">
            <div class="profile-icon">
                <i class="fas fa-chalkboard-teacher"></i>
            </div>
            <div class="profile-info">
                <h2><?php echo htmlspecialchars($profesor['nombres'] . ' ' . $profesor['apellidos']); ?></h2>
                <p><?php echo htmlspecialchars($profesor['titulo_academico']); ?></p>
                <p><i class="fas fa-id-card"></i> <?php echo $profesor['codigo_profesor']; ?> | 
                   <i class="fas fa-envelope"></i> <?php echo $profesor['email_institucional']; ?></p>
                
                <?php
                // Obtener estadísticas del profesor
                $stmt = $db->prepare("
                    SELECT COUNT(DISTINCT h.id_curso) as cursos_activos,
                           COUNT(*) as horarios_activos
                    FROM horarios h
                    WHERE h.id_profesor = ? AND h.activo = 1
                ");
                $stmt->execute([$id_profesor]);
                $stats = $stmt->fetch();
                ?>
                
                <div class="stats">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $stats['cursos_activos'] ?? 0; ?></div>
                        <div class="stat-label">Cursos Activos</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $stats['horarios_activos'] ?? 0; ?></div>
                        <div class="stat-label">Horarios</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">
                            <?php 
                            $programas_arr = explode(',', $profesor['programas_dictados']);
                            echo count(array_filter($programas_arr));
                            ?>
                        </div>
                        <div class="stat-label">Programas</div>
                    </div>
                </div>
            </div>
        </div>
        
        <form method="POST" class="card">
            <div class="card-header">
                <h2><i class="fas fa-user-edit"></i> Información del Profesor</h2>
            </div>
            
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="codigo_profesor">
                            <i class="fas fa-id-card"></i> Código del Profesor
                        </label>
                        <input type="text" id="codigo_profesor" value="<?php echo htmlspecialchars($profesor['codigo_profesor']); ?>" 
                               class="form-control" readonly disabled>
                        <div class="help-text">Código único (no editable)</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="documento_identidad">
                            <i class="fas fa-id-card"></i> Documento de Identidad
                        </label>
                        <input type="text" id="documento_identidad" value="<?php echo htmlspecialchars($profesor['documento_identidad']); ?>" 
                               class="form-control" readonly disabled>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="nombres">
                            <i class="fas fa-user"></i> Nombres
                            <span class="required">*</span>
                        </label>
                        <input type="text" id="nombres" name="nombres" required
                               class="form-control"
                               value="<?php echo htmlspecialchars($profesor['nombres']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="apellidos">
                            <i class="fas fa-user"></i> Apellidos
                            <span class="required">*</span>
                        </label>
                        <input type="text" id="apellidos" name="apellidos" required
                               class="form-control"
                               value="<?php echo htmlspecialchars($profesor['apellidos']); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="titulo_academico">
                            <i class="fas fa-graduation-cap"></i> Título Académico
                            <span class="required">*</span>
                        </label>
                        <input type="text" id="titulo_academico" name="titulo_academico" required
                               class="form-control"
                               value="<?php echo htmlspecialchars($profesor['titulo_academico']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="especialidad">
                            <i class="fas fa-tools"></i> Especialidad
                            <span class="required">*</span>
                        </label>
                        <input type="text" id="especialidad" name="especialidad" required
                               class="form-control"
                               value="<?php echo htmlspecialchars($profesor['especialidad']); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="telefono">
                            <i class="fas fa-phone"></i> Teléfono
                        </label>
                        <input type="tel" id="telefono" name="telefono"
                               class="form-control"
                               value="<?php echo htmlspecialchars($profesor['telefono']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="email_institucional">
                            <i class="fas fa-envelope"></i> Email Institucional
                            <span class="required">*</span>
                        </label>
                        <input type="email" id="email_institucional" name="email_institucional" required
                               class="form-control"
                               value="<?php echo htmlspecialchars($profesor['email_institucional']); ?>">
                    </div>
                </div>
                
                <!-- Datos de acceso -->
                <div class="card" style="margin: 25px 0; background: var(--gray-50); border: 1px solid var(--gray-200);">
                    <div class="card-header">
                        <h3><i class="fas fa-key"></i> Datos de Acceso al Sistema</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="username">
                                    <i class="fas fa-user-circle"></i> Nombre de Usuario
                                </label>
                                <input type="text" id="username" value="<?php echo htmlspecialchars($profesor['username']); ?>" 
                                       class="form-control" readonly disabled>
                            </div>
                            
                            <div class="form-group">
                                <label for="email">
                                    <i class="fas fa-envelope"></i> Email Personal
                                    <span class="required">*</span>
                                </label>
                                <input type="email" id="email" name="email" required
                                       class="form-control"
                                       value="<?php echo htmlspecialchars($profesor['email']); ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="password">
                                    <i class="fas fa-lock"></i> Nueva Contraseña
                                </label>
                                <input type="password" id="password" name="password"
                                       class="form-control"
                                       placeholder="Dejar vacío para no cambiar">
                                <div class="help-text">Solo llene si desea cambiar la contraseña</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Programas dictados -->
                <div class="card" style="margin: 25px 0; background: var(--gray-50); border: 1px solid var(--gray-200);">
                    <div class="card-header">
                        <h3><i class="fas fa-graduation-cap"></i> Programas que Dicta</h3>
                    </div>
                    <div class="card-body">
                        <div class="programas-grid">
                            <?php 
                            $programas_actuales = explode(',', $profesor['programas_dictados']);
                            $programas_actuales = array_map('trim', $programas_actuales);
                            ?>
                            <?php foreach ($programas as $programa): ?>
                                <label class="programa-checkbox">
                                    <input type="checkbox" name="programas_dictados[]" 
                                           value="<?php echo htmlspecialchars($programa['nombre_programa']); ?>"
                                           <?php echo in_array($programa['nombre_programa'], $programas_actuales) ? 'checked' : ''; ?>>
                                    <div>
                                        <strong><?php echo htmlspecialchars($programa['nombre_programa']); ?></strong>
                                        <div class="help-text">Código: <?php echo $programa['codigo_programa']; ?></div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Botones -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Cambios
                    </button>
                    <a href="ver.php?id=<?php echo $id_profesor; ?>" class="btn btn-info">
                        <i class="fas fa-eye"></i> Ver Perfil
                    </a>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
            </div>
        </form>
    </div>
</body>
</html>