<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

$errores = [];

// Obtener ID del estudiante
$id_estudiante = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_estudiante) {
    Funciones::redireccionar('index.php', 'ID de estudiante no válido', 'error');
}

// Obtener datos del estudiante
$stmt = $db->prepare("
    SELECT e.*, u.email, u.username, u.activo 
    FROM estudiantes e 
    JOIN usuarios u ON e.id_usuario = u.id_usuario 
    WHERE e.id_estudiante = ?
");
$stmt->execute([$id_estudiante]);
$estudiante = $stmt->fetch();

if (!$estudiante) {
    Funciones::redireccionar('index.php', 'Estudiante no encontrado', 'error');
}

// Obtener programas
$programas = $db->query("SELECT * FROM programas_estudio WHERE activo = 1")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombres = Funciones::sanitizar($_POST['nombres']);
    $apellidos = Funciones::sanitizar($_POST['apellidos']);
    $documento = Funciones::sanitizar($_POST['documento']);
    $id_programa = intval($_POST['id_programa']);
    $email = Funciones::sanitizar($_POST['email']);
    $telefono = Funciones::sanitizar($_POST['telefono']);
    $direccion = Funciones::sanitizar($_POST['direccion']);
    $fecha_nacimiento = Funciones::sanitizar($_POST['fecha_nacimiento']);
    $genero = Funciones::sanitizar($_POST['genero']);
    $semestre_actual = intval($_POST['semestre_actual']);
    $estado = Funciones::sanitizar($_POST['estado']);
    
    // Validaciones
    if (empty($nombres)) $errores[] = 'Los nombres son requeridos';
    if (empty($apellidos)) $errores[] = 'Los apellidos son requeridos';
    if (empty($documento)) $errores[] = 'El documento es requerido';
    if (empty($id_programa)) $errores[] = 'El programa es requerido';
    if (empty($email)) $errores[] = 'El email es requerido';
    
    if (!Funciones::validarEmail($email)) {
        $errores[] = 'El email no es válido';
    }
    
    if ($semestre_actual < 1 || $semestre_actual > 6) {
        $errores[] = 'El semestre debe estar entre 1 y 6';
    }
    
    // Verificar si el email ya existe (excluyendo el actual)
    $stmt = $db->prepare("SELECT id_usuario FROM usuarios WHERE email = ? AND id_usuario != ?");
    $stmt->execute([$email, $estudiante['id_usuario']]);
    if ($stmt->fetch()) {
        $errores[] = 'El email ya está en uso';
    }
    
    // Verificar si el documento ya existe (excluyendo el actual)
    $stmt = $db->prepare("SELECT id_estudiante FROM estudiantes WHERE documento_identidad = ? AND id_estudiante != ?");
    $stmt->execute([$documento, $id_estudiante]);
    if ($stmt->fetch()) {
        $errores[] = 'El documento de identidad ya está registrado';
    }
    
    if (empty($errores)) {
        try {
            $db->beginTransaction();
            
            // Actualizar estudiante
            $stmt = $db->prepare("UPDATE estudiantes SET 
                nombres = ?, apellidos = ?, documento_identidad = ?, id_programa = ?,
                fecha_nacimiento = ?, genero = ?, telefono = ?, direccion = ?,
                semestre_actual = ?, estado = ?
                WHERE id_estudiante = ?");
            
            $stmt->execute([
                $nombres, $apellidos, $documento, $id_programa,
                $fecha_nacimiento, $genero, $telefono, $direccion,
                $semestre_actual, $estado, $id_estudiante
            ]);
            
            // Obtener nombre del programa para actualizar usuario
            $stmt_programa = $db->prepare("SELECT nombre_programa FROM programas_estudio WHERE id_programa = ?");
            $stmt_programa->execute([$id_programa]);
            $programa_nombre = $stmt_programa->fetch()['nombre_programa'];
            
            // Actualizar usuario
            $stmt = $db->prepare("UPDATE usuarios SET email = ?, programa_estudio = ? WHERE id_usuario = ?");
            $stmt->execute([$email, $programa_nombre, $estudiante['id_usuario']]);
            
            // Actualizar password si se proporcionó uno nuevo
            if (!empty($_POST['password'])) {
                $password = Funciones::sanitizar($_POST['password']);
                $stmt = $db->prepare("UPDATE usuarios SET password = ? WHERE id_usuario = ?");
                $stmt->execute([$password, $estudiante['id_usuario']]);
            }
            
            $db->commit();
            Funciones::redireccionar('index.php', 'Estudiante actualizado exitosamente');
            
        } catch (Exception $e) {
            $db->rollBack();
            $errores[] = 'Error al actualizar el estudiante: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Estudiante - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-edit"></i> Editar Estudiante</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-user-graduate"></i> Estudiantes</a>
                <a href="editar.php?id=<?php echo $id_estudiante; ?>" class="active"><i class="fas fa-user-edit"></i> Editar Estudiante</a>
            </div>
        </div>
        
        <?php if (!empty($errores)): ?>
            <div class="alert alert-error">
                <?php foreach ($errores as $error): ?>
                    <p><?php echo $error; ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <form method="POST" action="" id="formEstudiante">
                <h3 style="margin-bottom: 20px; color: var(--dark);">Información Personal</h3>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="nombres">Nombres *</label>
                        <input type="text" id="nombres" name="nombres" class="form-control" required
                               value="<?php echo htmlspecialchars($estudiante['nombres']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="apellidos">Apellidos *</label>
                        <input type="text" id="apellidos" name="apellidos" class="form-control" required
                               value="<?php echo htmlspecialchars($estudiante['apellidos']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="documento">Documento de Identidad *</label>
                        <input type="text" id="documento" name="documento" class="form-control" required
                               value="<?php echo htmlspecialchars($estudiante['documento_identidad']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="fecha_nacimiento">Fecha de Nacimiento</label>
                        <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" class="form-control"
                               value="<?php echo htmlspecialchars($estudiante['fecha_nacimiento']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="genero">Género</label>
                        <select id="genero" name="genero" class="form-control">
                            <option value="">Seleccionar...</option>
                            <option value="M" <?php echo $estudiante['genero'] == 'M' ? 'selected' : ''; ?>>Masculino</option>
                            <option value="F" <?php echo $estudiante['genero'] == 'F' ? 'selected' : ''; ?>>Femenino</option>
                            <option value="Otro" <?php echo $estudiante['genero'] == 'Otro' ? 'selected' : ''; ?>>Otro</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="telefono">Teléfono</label>
                        <input type="text" id="telefono" name="telefono" class="form-control"
                               value="<?php echo htmlspecialchars($estudiante['telefono']); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="direccion">Dirección</label>
                    <textarea id="direccion" name="direccion" class="form-control" rows="3"><?php echo htmlspecialchars($estudiante['direccion']); ?></textarea>
                </div>
                
                <h3 style="margin: 30px 0 20px 0; color: var(--dark);">Información Académica</h3>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="id_programa">Programa de Estudio *</label>
                        <select id="id_programa" name="id_programa" class="form-control" required>
                            <option value="">Seleccionar programa</option>
                            <?php foreach ($programas as $programa): ?>
                                <option value="<?php echo $programa['id_programa']; ?>"
                                    <?php echo $estudiante['id_programa'] == $programa['id_programa'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($programa['nombre_programa']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="semestre_actual">Semestre Actual *</label>
                        <select id="semestre_actual" name="semestre_actual" class="form-control" required>
                            <option value="">Seleccionar semestre</option>
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <option value="<?php echo $i; ?>"
                                    <?php echo $estudiante['semestre_actual'] == $i ? 'selected' : ''; ?>>
                                    Semestre <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="estado">Estado *</label>
                        <select id="estado" name="estado" class="form-control" required>
                            <option value="activo" <?php echo $estudiante['estado'] == 'activo' ? 'selected' : ''; ?>>Activo</option>
                            <option value="inactivo" <?php echo $estudiante['estado'] == 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                            <option value="graduado" <?php echo $estudiante['estado'] == 'graduado' ? 'selected' : ''; ?>>Graduado</option>
                            <option value="retirado" <?php echo $estudiante['estado'] == 'retirado' ? 'selected' : ''; ?>>Retirado</option>
                        </select>
                    </div>
                </div>
                
                <h3 style="margin: 30px 0 20px 0; color: var(--dark);">Credenciales de Acceso</h3>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" class="form-control" required
                               value="<?php echo htmlspecialchars($estudiante['email']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="username">Usuario</label>
                        <input type="text" id="username" class="form-control" disabled
                               value="<?php echo htmlspecialchars($estudiante['username']); ?>">
                        <small class="text-muted">El usuario no puede ser modificado</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Nueva Contraseña (dejar en blanco para no cambiar)</label>
                        <div style="position: relative;">
                            <input type="password" id="password" name="password" class="form-control">
                            <button type="button" onclick="togglePassword('password')" 
                                    style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); 
                                           background: none; border: none; cursor: pointer; color: var(--gray-500);">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 30px; display: flex; gap: 15px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Actualizar Estudiante
                    </button>
                    <a href="index.php" class="btn">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <script src="../../assets/js/main.js"></script>
</body>
</html>