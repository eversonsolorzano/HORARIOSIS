<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

$errores = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = Funciones::sanitizar($_POST['username']);
    $password = Funciones::sanitizar($_POST['password']);
    $rol = Funciones::sanitizar($_POST['rol']);
    $email = Funciones::sanitizar($_POST['email']);
    $programa_estudio = Funciones::sanitizar($_POST['programa_estudio']);
    
    // Validaciones
    if (empty($username)) $errores[] = 'El usuario es requerido';
    if (empty($password)) $errores[] = 'La contraseña es requerida';
    if (empty($email)) $errores[] = 'El email es requerido';
    if (empty($rol)) $errores[] = 'El rol es requerido';
    
    if (!Funciones::validarEmail($email)) {
        $errores[] = 'El email no es válido';
    }
    
    // Verificar si el usuario ya existe
    $stmt = $db->prepare("SELECT id_usuario FROM usuarios WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        $errores[] = 'El usuario o email ya existe';
    }
    
    if (empty($errores)) {
        try {
            $db->beginTransaction();
            
            // Crear usuario
            $stmt = $db->prepare("INSERT INTO usuarios (username, password, rol, email, programa_estudio) 
                                 VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$username, $password, $rol, $email, $programa_estudio]);
            $id_usuario = $db->lastInsertId();
            
            // Si es estudiante, crear registro en estudiantes
            if ($rol == 'estudiante') {
                $codigo_estudiante = 'EST-' . date('Y') . str_pad($id_usuario, 4, '0', STR_PAD_LEFT);
                $nombres = Funciones::sanitizar($_POST['est_nombres']);
                $apellidos = Funciones::sanitizar($_POST['est_apellidos']);
                $documento = Funciones::sanitizar($_POST['est_documento']);
                $id_programa = Funciones::sanitizar($_POST['est_programa']);
                
                $stmt = $db->prepare("INSERT INTO estudiantes (id_usuario, id_programa, codigo_estudiante, nombres, apellidos, documento_identidad, fecha_ingreso) 
                                     VALUES (?, ?, ?, ?, ?, ?, CURDATE())");
                $stmt->execute([$id_usuario, $id_programa, $codigo_estudiante, $nombres, $apellidos, $documento]);
            }
            
            // Si es profesor, crear registro en profesores
            if ($rol == 'profesor') {
                $codigo_profesor = 'PROF-' . date('Y') . str_pad($id_usuario, 4, '0', STR_PAD_LEFT);
                $nombres = Funciones::sanitizar($_POST['prof_nombres']);
                $apellidos = Funciones::sanitizar($_POST['prof_apellidos']);
                $documento = Funciones::sanitizar($_POST['prof_documento']);
                $especialidad = Funciones::sanitizar($_POST['prof_especialidad']);
                
                $stmt = $db->prepare("INSERT INTO profesores (id_usuario, codigo_profesor, nombres, apellidos, documento_identidad, especialidad) 
                                     VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$id_usuario, $codigo_profesor, $nombres, $apellidos, $documento, $especialidad]);
            }
            
            $db->commit();
            Funciones::redireccionar('index.php', 'Usuario creado exitosamente');
            
        } catch (Exception $e) {
            $db->rollBack();
            $errores[] = 'Error al crear el usuario: ' . $e->getMessage();
        }
    }
}

// Obtener programas para select
$programas = $db->query("SELECT * FROM programas_estudio WHERE activo = 1")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Usuario - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-plus"></i> Crear Nuevo Usuario</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-users"></i> Usuarios</a>
                <a href="crear.php" class="active"><i class="fas fa-user-plus"></i> Nuevo Usuario</a>
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
                        <input type="text" id="username" name="username" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Contraseña *</label>
                        <div style="position: relative;">
                            <input type="password" id="password" name="password" class="form-control" required>
                            <button type="button" onclick="togglePassword('password')" 
                                    style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer;">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="rol">Rol *</label>
                        <select id="rol" name="rol" class="form-control" required onchange="mostrarCamposRol()">
                            <option value="">Seleccionar rol</option>
                            <option value="coordinador">Coordinador</option>
                            <option value="profesor">Profesor</option>
                            <option value="estudiante">Estudiante</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="programa_estudio">Programa (si aplica)</label>
                        <select id="programa_estudio" name="programa_estudio" class="form-control">
                            <option value="">Seleccionar programa</option>
                            <?php foreach ($programas as $programa): ?>
                                <option value="<?php echo $programa['nombre_programa']; ?>">
                                    <?php echo htmlspecialchars($programa['nombre_programa']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Campos para Estudiante -->
                <div id="camposEstudiante" style="display: none;">
                    <h3 style="margin: 20px 0 15px 0; color: #2d3748;">Información del Estudiante</h3>
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="est_nombres">Nombres *</label>
                            <input type="text" id="est_nombres" name="est_nombres" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="est_apellidos">Apellidos *</label>
                            <input type="text" id="est_apellidos" name="est_apellidos" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="est_documento">Documento de Identidad *</label>
                            <input type="text" id="est_documento" name="est_documento" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="est_programa">Programa de Estudio *</label>
                            <select id="est_programa" name="est_programa" class="form-control">
                                <option value="">Seleccionar programa</option>
                                <?php foreach ($programas as $programa): ?>
                                    <option value="<?php echo $programa['id_programa']; ?>">
                                        <?php echo htmlspecialchars($programa['nombre_programa']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Campos para Profesor -->
                <div id="camposProfesor" style="display: none;">
                    <h3 style="margin: 20px 0 15px 0; color: #2d3748;">Información del Profesor</h3>
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="prof_nombres">Nombres *</label>
                            <input type="text" id="prof_nombres" name="prof_nombres" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="prof_apellidos">Apellidos *</label>
                            <input type="text" id="prof_apellidos" name="prof_apellidos" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="prof_documento">Documento de Identidad *</label>
                            <input type="text" id="prof_documento" name="prof_documento" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="prof_especialidad">Especialidad *</label>
                            <input type="text" id="prof_especialidad" name="prof_especialidad" class="form-control">
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 30px; display: flex; gap: 15px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Usuario
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
    
    // Validar formulario antes de enviar
    document.getElementById('formUsuario').addEventListener('submit', function(e) {
        const rol = document.getElementById('rol').value;
        let valido = true;
        
        // Validaciones básicas
        const camposRequeridos = this.querySelectorAll('[required]');
        camposRequeridos.forEach(campo => {
            if (!campo.value.trim()) {
                campo.style.borderColor = '#f56565';
                valido = false;
            } else {
                campo.style.borderColor = '';
            }
        });
        
        if (!valido) {
            e.preventDefault();
            alert('Por favor complete todos los campos requeridos.');
        }
    });
    </script>
</body>
</html>