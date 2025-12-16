<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

$errores = [];
$success = '';

// Obtener ID del usuario a editar
$id_usuario = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_usuario) {
    Funciones::redireccionar('index.php', 'ID de usuario no válido', 'error');
}

// Obtener datos del usuario
$stmt = $db->prepare("SELECT * FROM usuarios WHERE id_usuario = ?");
$stmt->execute([$id_usuario]);
$usuario_actual = $stmt->fetch();

if (!$usuario_actual) {
    Funciones::redireccionar('index.php', 'Usuario no encontrado', 'error');
}

// Obtener datos adicionales según el rol
if ($usuario_actual['rol'] == 'estudiante') {
    $stmt = $db->prepare("SELECT * FROM estudiantes WHERE id_usuario = ?");
    $stmt->execute([$id_usuario]);
    $datos_adicionales = $stmt->fetch();
} elseif ($usuario_actual['rol'] == 'profesor') {
    $stmt = $db->prepare("SELECT * FROM profesores WHERE id_usuario = ?");
    $stmt->execute([$id_usuario]);
    $datos_adicionales = $stmt->fetch();
} else {
    $datos_adicionales = [];
}

// Obtener programas para select
$programas = $db->query("SELECT * FROM programas_estudio WHERE activo = 1")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = Funciones::sanitizar($_POST['username']);
    $email = Funciones::sanitizar($_POST['email']);
    $rol = Funciones::sanitizar($_POST['rol']);
    $programa_estudio = Funciones::sanitizar($_POST['programa_estudio']);
    $activo = isset($_POST['activo']) ? 1 : 0;
    
    // Validaciones
    if (empty($username)) $errores[] = 'El usuario es requerido';
    if (empty($email)) $errores[] = 'El email es requerido';
    if (empty($rol)) $errores[] = 'El rol es requerido';
    
    if (!Funciones::validarEmail($email)) {
        $errores[] = 'El email no es válido';
    }
    
    // Verificar si el usuario/email ya existe (excluyendo el actual)
    $stmt = $db->prepare("SELECT id_usuario FROM usuarios WHERE (username = ? OR email = ?) AND id_usuario != ?");
    $stmt->execute([$username, $email, $id_usuario]);
    if ($stmt->fetch()) {
        $errores[] = 'El usuario o email ya está en uso';
    }
    
    if (empty($errores)) {
        try {
            $db->beginTransaction();
            
            // Actualizar usuario
            $stmt = $db->prepare("UPDATE usuarios SET 
                username = ?, email = ?, rol = ?, programa_estudio = ?, activo = ? 
                WHERE id_usuario = ?");
            $stmt->execute([$username, $email, $rol, $programa_estudio, $activo, $id_usuario]);
            
            // Si es estudiante y cambió a otro rol, eliminar registro de estudiantes
            if ($usuario_actual['rol'] == 'estudiante' && $rol != 'estudiante') {
                $stmt = $db->prepare("DELETE FROM estudiantes WHERE id_usuario = ?");
                $stmt->execute([$id_usuario]);
            }
            // Si es profesor y cambió a otro rol, eliminar registro de profesores
            elseif ($usuario_actual['rol'] == 'profesor' && $rol != 'profesor') {
                $stmt = $db->prepare("DELETE FROM profesores WHERE id_usuario = ?");
                $stmt->execute([$id_usuario]);
            }
            // Si cambió a estudiante, crear/actualizar registro
            elseif ($rol == 'estudiante') {
                $nombres = Funciones::sanitizar($_POST['est_nombres']);
                $apellidos = Funciones::sanitizar($_POST['est_apellidos']);
                $documento = Funciones::sanitizar($_POST['est_documento']);
                $id_programa = Funciones::sanitizar($_POST['est_programa']);
                
                if ($usuario_actual['rol'] == 'estudiante') {
                    // Actualizar estudiante existente
                    $stmt = $db->prepare("UPDATE estudiantes SET 
                        nombres = ?, apellidos = ?, documento_identidad = ?, id_programa = ? 
                        WHERE id_usuario = ?");
                    $stmt->execute([$nombres, $apellidos, $documento, $id_programa, $id_usuario]);
                } else {
                    // Crear nuevo estudiante
                    $codigo_estudiante = 'EST-' . date('Y') . str_pad($id_usuario, 4, '0', STR_PAD_LEFT);
                    $stmt = $db->prepare("INSERT INTO estudiantes 
                        (id_usuario, id_programa, codigo_estudiante, nombres, apellidos, documento_identidad, fecha_ingreso) 
                        VALUES (?, ?, ?, ?, ?, ?, CURDATE())");
                    $stmt->execute([$id_usuario, $id_programa, $codigo_estudiante, $nombres, $apellidos, $documento]);
                }
            }
            // Si cambió a profesor, crear/actualizar registro
            elseif ($rol == 'profesor') {
                $nombres = Funciones::sanitizar($_POST['prof_nombres']);
                $apellidos = Funciones::sanitizar($_POST['prof_apellidos']);
                $documento = Funciones::sanitizar($_POST['prof_documento']);
                $especialidad = Funciones::sanitizar($_POST['prof_especialidad']);
                
                if ($usuario_actual['rol'] == 'profesor') {
                    // Actualizar profesor existente
                    $stmt = $db->prepare("UPDATE profesores SET 
                        nombres = ?, apellidos = ?, documento_identidad = ?, especialidad = ? 
                        WHERE id_usuario = ?");
                    $stmt->execute([$nombres, $apellidos, $documento, $especialidad, $id_usuario]);
                } else {
                    // Crear nuevo profesor
                    $codigo_profesor = 'PROF-' . date('Y') . str_pad($id_usuario, 4, '0', STR_PAD_LEFT);
                    $stmt = $db->prepare("INSERT INTO profesores 
                        (id_usuario, codigo_profesor, nombres, apellidos, documento_identidad, especialidad) 
                        VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$id_usuario, $codigo_profesor, $nombres, $apellidos, $documento, $especialidad]);
                }
            }
            
            $db->commit();
            Funciones::redireccionar('index.php', 'Usuario actualizado exitosamente');
            
        } catch (Exception $e) {
            $db->rollBack();
            $errores[] = 'Error al actualizar el usuario: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Usuario - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-edit"></i> Editar Usuario</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-users"></i> Usuarios</a>
                <a href="editar.php?id=<?php echo $id_usuario; ?>" class="active"><i class="fas fa-user-edit"></i> Editar Usuario</a>
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
            <form method="POST" action="" id="formUsuario">
                <div class="grid-2">
                    <div class="form-group">
                        <label for="username">Usuario *</label>
                        <input type="text" id="username" name="username" class="form-control" required
                               value="<?php echo htmlspecialchars($usuario_actual['username']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" class="form-control" required
                               value="<?php echo htmlspecialchars($usuario_actual['email']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="rol">Rol *</label>
                        <select id="rol" name="rol" class="form-control" required onchange="mostrarCamposRol()">
                            <option value="">Seleccionar rol</option>
                            <option value="coordinador" <?php echo $usuario_actual['rol'] == 'coordinador' ? 'selected' : ''; ?>>Coordinador</option>
                            <option value="profesor" <?php echo $usuario_actual['rol'] == 'profesor' ? 'selected' : ''; ?>>Profesor</option>
                            <option value="estudiante" <?php echo $usuario_actual['rol'] == 'estudiante' ? 'selected' : ''; ?>>Estudiante</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="programa_estudio">Programa (si aplica)</label>
                        <select id="programa_estudio" name="programa_estudio" class="form-control">
                            <option value="">Seleccionar programa</option>
                            <?php foreach ($programas as $programa): ?>
                                <option value="<?php echo $programa['nombre_programa']; ?>"
                                    <?php echo ($usuario_actual['programa_estudio'] == $programa['nombre_programa']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($programa['nombre_programa']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="activo">
                            <input type="checkbox" id="activo" name="activo" value="1" 
                                   <?php echo $usuario_actual['activo'] ? 'checked' : ''; ?>>
                            Usuario Activo
                        </label>
                    </div>
                </div>
                
                <!-- Campos para Estudiante -->
                <div id="camposEstudiante" style="display: <?php echo $usuario_actual['rol'] == 'estudiante' ? 'block' : 'none'; ?>;">
                    <h3 style="margin: 20px 0 15px 0; color: var(--dark);">Información del Estudiante</h3>
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="est_nombres">Nombres *</label>
                            <input type="text" id="est_nombres" name="est_nombres" class="form-control"
                                   value="<?php echo htmlspecialchars($datos_adicionales['nombres'] ?? ''); ?>"
                                   <?php echo $usuario_actual['rol'] == 'estudiante' ? 'required' : ''; ?>>
                        </div>
                        
                        <div class="form-group">
                            <label for="est_apellidos">Apellidos *</label>
                            <input type="text" id="est_apellidos" name="est_apellidos" class="form-control"
                                   value="<?php echo htmlspecialchars($datos_adicionales['apellidos'] ?? ''); ?>"
                                   <?php echo $usuario_actual['rol'] == 'estudiante' ? 'required' : ''; ?>>
                        </div>
                        
                        <div class="form-group">
                            <label for="est_documento">Documento de Identidad *</label>
                            <input type="text" id="est_documento" name="est_documento" class="form-control"
                                   value="<?php echo htmlspecialchars($datos_adicionales['documento_identidad'] ?? ''); ?>"
                                   <?php echo $usuario_actual['rol'] == 'estudiante' ? 'required' : ''; ?>>
                        </div>
                        
                        <div class="form-group">
                            <label for="est_programa">Programa de Estudio *</label>
                            <select id="est_programa" name="est_programa" class="form-control"
                                    <?php echo $usuario_actual['rol'] == 'estudiante' ? 'required' : ''; ?>>
                                <option value="">Seleccionar programa</option>
                                <?php foreach ($programas as $programa): ?>
                                    <option value="<?php echo $programa['id_programa']; ?>"
                                        <?php echo (($datos_adicionales['id_programa'] ?? 0) == $programa['id_programa']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($programa['nombre_programa']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Campos para Profesor -->
                <div id="camposProfesor" style="display: <?php echo $usuario_actual['rol'] == 'profesor' ? 'block' : 'none'; ?>;">
                    <h3 style="margin: 20px 0 15px 0; color: var(--dark);">Información del Profesor</h3>
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="prof_nombres">Nombres *</label>
                            <input type="text" id="prof_nombres" name="prof_nombres" class="form-control"
                                   value="<?php echo htmlspecialchars($datos_adicionales['nombres'] ?? ''); ?>"
                                   <?php echo $usuario_actual['rol'] == 'profesor' ? 'required' : ''; ?>>
                        </div>
                        
                        <div class="form-group">
                            <label for="prof_apellidos">Apellidos *</label>
                            <input type="text" id="prof_apellidos" name="prof_apellidos" class="form-control"
                                   value="<?php echo htmlspecialchars($datos_adicionales['apellidos'] ?? ''); ?>"
                                   <?php echo $usuario_actual['rol'] == 'profesor' ? 'required' : ''; ?>>
                        </div>
                        
                        <div class="form-group">
                            <label for="prof_documento">Documento de Identidad *</label>
                            <input type="text" id="prof_documento" name="prof_documento" class="form-control"
                                   value="<?php echo htmlspecialchars($datos_adicionales['documento_identidad'] ?? ''); ?>"
                                   <?php echo $usuario_actual['rol'] == 'profesor' ? 'required' : ''; ?>>
                        </div>
                        
                        <div class="form-group">
                            <label for="prof_especialidad">Especialidad *</label>
                            <input type="text" id="prof_especialidad" name="prof_especialidad" class="form-control"
                                   value="<?php echo htmlspecialchars($datos_adicionales['especialidad'] ?? ''); ?>"
                                   <?php echo $usuario_actual['rol'] == 'profesor' ? 'required' : ''; ?>>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 30px; display: flex; gap: 15px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Actualizar Usuario
                    </button>
                    <a href="index.php" class="btn">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <script src="../../assets/js/main.js"></script>
    <script>
    function mostrarCamposRol() {
        const rol = document.getElementById('rol').value;
        
        // Ocultar todos los campos específicos
        document.getElementById('camposEstudiante').style.display = 'none';
        document.getElementById('camposProfesor').style.display = 'none';
        
        // Mostrar campos según el rol seleccionado
        if (rol === 'estudiante') {
            document.getElementById('camposEstudiante').style.display = 'block';
            // Hacer campos requeridos
            document.getElementById('est_nombres').required = true;
            document.getElementById('est_apellidos').required = true;
            document.getElementById('est_documento').required = true;
            document.getElementById('est_programa').required = true;
        } else if (rol === 'profesor') {
            document.getElementById('camposProfesor').style.display = 'block';
            // Hacer campos requeridos
            document.getElementById('prof_nombres').required = true;
            document.getElementById('prof_apellidos').required = true;
            document.getElementById('prof_documento').required = true;
            document.getElementById('prof_especialidad').required = true;
        } else {
            // Quitar requeridos si no es estudiante ni profesor
            document.getElementById('est_nombres').required = false;
            document.getElementById('est_apellidos').required = false;
            document.getElementById('est_documento').required = false;
            document.getElementById('est_programa').required = false;
            document.getElementById('prof_nombres').required = false;
            document.getElementById('prof_apellidos').required = false;
            document.getElementById('prof_documento').required = false;
            document.getElementById('prof_especialidad').required = false;
        }
    }
    </script>
</body>
</html>