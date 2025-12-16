<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

$programas = Funciones::obtenerProgramas();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $db->beginTransaction();
        
        // Datos del usuario
        $username = Funciones::sanitizar($_POST['username']);
        $email = Funciones::sanitizar($_POST['email']);
        $password = Funciones::sanitizar($_POST['password']);
        
        // Verificar si usuario ya existe
        $stmt = $db->prepare("SELECT id_usuario FROM usuarios WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            throw new Exception("El nombre de usuario o email ya está registrado");
        }
        
        // Crear usuario primero
        $stmt = $db->prepare("
            INSERT INTO usuarios (username, password, rol, email, activo) 
            VALUES (?, ?, 'profesor', ?, 1)
        ");
        $stmt->execute([$username, $password, $email]);
        $id_usuario = $db->lastInsertId();
        
        // Datos del profesor
        $codigo_profesor = Funciones::sanitizar($_POST['codigo_profesor']);
        $nombres = Funciones::sanitizar($_POST['nombres']);
        $apellidos = Funciones::sanitizar($_POST['apellidos']);
        $documento = Funciones::sanitizar($_POST['documento_identidad']);
        $especialidad = Funciones::sanitizar($_POST['especialidad']);
        $titulo = Funciones::sanitizar($_POST['titulo_academico']);
        $telefono = Funciones::sanitizar($_POST['telefono']);
        $email_institucional = Funciones::sanitizar($_POST['email_institucional']);
        
        // Programas dictados (SET)
        $programas_dictados = [];
        if (isset($_POST['programas_dictados']) && is_array($_POST['programas_dictados'])) {
            $programas_dictados = $_POST['programas_dictados'];
        }
        $programas_str = implode(',', $programas_dictados);
        
        // Crear profesor
        $stmt = $db->prepare("
            INSERT INTO profesores 
            (id_usuario, codigo_profesor, nombres, apellidos, documento_identidad, 
             especialidad, titulo_academico, telefono, email_institucional, 
             programas_dictados, activo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        
        $stmt->execute([
            $id_usuario, $codigo_profesor, $nombres, $apellidos, $documento,
            $especialidad, $titulo, $telefono, $email_institucional, $programas_str
        ]);
        
        $id_profesor = $db->lastInsertId();
        
        $db->commit();
        
        // Enviar notificación al profesor
        $stmt = $db->prepare("
            INSERT INTO notificaciones (id_usuario, tipo_notificacion, titulo, mensaje)
            VALUES (?, 'sistema', 'Cuenta creada', ?)
        ");
        $mensaje = "Su cuenta de profesor ha sido creada exitosamente.<br>";
        $mensaje .= "Usuario: $username<br>";
        $mensaje .= "Puede iniciar sesión en el sistema.";
        $stmt->execute([$id_usuario, $mensaje]);
        
        Session::setFlash('Profesor creado exitosamente');
        Funciones::redireccionar('index.php');
        
    } catch (Exception $e) {
        $db->rollBack();
        Session::setFlash('Error al crear profesor: ' . $e->getMessage(), 'error');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Nuevo Profesor - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .form-tabs {
            display: flex;
            border-bottom: 2px solid var(--gray-200);
            margin-bottom: 25px;
            gap: 5px;
        }
        
        .tab {
            padding: 12px 24px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            font-weight: 500;
            color: var(--gray-600);
            transition: all 0.3s ease;
            border-radius: var(--radius-sm) var(--radius-sm) 0 0;
        }
        
        .tab:hover {
            background-color: var(--gray-50);
            color: var(--primary);
        }
        
        .tab.active {
            border-bottom-color: var(--primary);
            color: var(--primary);
            background-color: var(--gray-50);
        }
        
        .tab-content {
            display: none;
            padding: 20px 0;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
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
        
        .programa-checkbox.checked {
            border-color: var(--primary);
            background-color: var(--primary-light);
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            padding-top: 25px;
            margin-top: 25px;
            border-top: 2px solid var(--gray-100);
        }
        
        .password-strength {
            margin-top: 5px;
            height: 4px;
            border-radius: 2px;
            background: var(--gray-200);
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 2px;
        }
        
        .password-weak { background: var(--danger); }
        .password-medium { background: var(--warning); }
        .password-strong { background: var(--success); }
        
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
            <h1><i class="fas fa-user-plus"></i> Crear Nuevo Profesor</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
                <a href="crear.php" class="active"><i class="fas fa-user-plus"></i> Nuevo Profesor</a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="card">
            <div class="card-header">
                <h2><i class="fas fa-user-tie"></i> Información del Profesor</h2>
            </div>
            
            <div class="card-body">
                <!-- Pestañas -->
                <div class="form-tabs">
                    <div class="tab active" onclick="mostrarTab('datos-personales')">
                        <i class="fas fa-user"></i> Datos Personales
                    </div>
                    <div class="tab" onclick="mostrarTab('cuenta-acceso')">
                        <i class="fas fa-key"></i> Cuenta de Acceso
                    </div>
                    <div class="tab" onclick="mostrarTab('asignacion-programas')">
                        <i class="fas fa-graduation-cap"></i> Programas
                    </div>
                </div>
                
                <!-- Tab 1: Datos Personales -->
                <div id="datos-personales" class="tab-content active">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        Complete la información personal del profesor
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="codigo_profesor">
                                <i class="fas fa-id-card"></i> Código del Profesor
                                <span class="required">*</span>
                            </label>
                            <input type="text" id="codigo_profesor" name="codigo_profesor" required
                                   class="form-control"
                                   placeholder="Ej: PROF-2024001">
                            <div class="help-text">Código único para identificar al profesor</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="documento_identidad">
                                <i class="fas fa-id-card"></i> Documento de Identidad
                                <span class="required">*</span>
                            </label>
                            <input type="text" id="documento_identidad" name="documento_identidad" required
                                   class="form-control"
                                   placeholder="Número de documento">
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
                                   placeholder="Nombres completos">
                        </div>
                        
                        <div class="form-group">
                            <label for="apellidos">
                                <i class="fas fa-user"></i> Apellidos
                                <span class="required">*</span>
                            </label>
                            <input type="text" id="apellidos" name="apellidos" required
                                   class="form-control"
                                   placeholder="Apellidos completos">
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
                                   placeholder="Ej: Magíster en Educación">
                        </div>
                        
                        <div class="form-group">
                            <label for="especialidad">
                                <i class="fas fa-tools"></i> Especialidad
                                <span class="required">*</span>
                            </label>
                            <input type="text" id="especialidad" name="especialidad" required
                                   class="form-control"
                                   placeholder="Área de especialización">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="telefono">
                                <i class="fas fa-phone"></i> Teléfono
                            </label>
                            <input type="tel" id="telefono" name="telefono"
                                   class="form-control"
                                   placeholder="Número de contacto">
                        </div>
                        
                        <div class="form-group">
                            <label for="email_institucional">
                                <i class="fas fa-envelope"></i> Email Institucional
                                <span class="required">*</span>
                            </label>
                            <input type="email" id="email_institucional" name="email_institucional" required
                                   class="form-control"
                                   placeholder="correo@instituto.edu">
                        </div>
                    </div>
                </div>
                
                <!-- Tab 2: Cuenta de Acceso -->
                <div id="cuenta-acceso" class="tab-content">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        Esta información servirá para que el profesor acceda al sistema
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="username">
                                <i class="fas fa-user-circle"></i> Nombre de Usuario
                                <span class="required">*</span>
                            </label>
                            <input type="text" id="username" name="username" required
                                   class="form-control"
                                   placeholder="Ej: prof.juan.perez">
                            <div class="help-text">Solo letras, números y puntos. Sin espacios.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">
                                <i class="fas fa-envelope"></i> Email Personal
                                <span class="required">*</span>
                            </label>
                            <input type="email" id="email" name="email" required
                                   class="form-control"
                                   placeholder="correo@dominio.com">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="password">
                                <i class="fas fa-lock"></i> Contraseña
                                <span class="required">*</span>
                            </label>
                            <input type="password" id="password" name="password" required
                                   class="form-control"
                                   placeholder="Mínimo 6 caracteres">
                            <div class="password-strength">
                                <div class="password-strength-bar" id="password-strength-bar"></div>
                            </div>
                            <div class="help-text">
                                <i class="fas fa-shield-alt"></i> 
                                La contraseña se almacena sin encriptar (solo para desarrollo)
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">
                                <i class="fas fa-lock"></i> Confirmar Contraseña
                                <span class="required">*</span>
                            </label>
                            <input type="password" id="confirm_password" name="confirm_password" required
                                   class="form-control"
                                   placeholder="Repita la contraseña">
                            <div class="help-text" id="password-match"></div>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>ADVERTENCIA:</strong> En un entorno de producción, las contraseñas 
                        deben almacenarse encriptadas. Esta configuración es solo para desarrollo.
                    </div>
                </div>
                
                <!-- Tab 3: Programas -->
                <div id="asignacion-programas" class="tab-content">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        Seleccione los programas en los que el profesor puede dictar clases
                    </div>
                    
                    <div class="programas-grid">
                        <?php foreach ($programas as $programa): ?>
                            <label class="programa-checkbox">
                                <input type="checkbox" name="programas_dictados[]" 
                                       value="<?php echo htmlspecialchars($programa['nombre_programa']); ?>"
                                       onchange="toggleCheckbox(this)">
                                <div>
                                    <strong><?php echo htmlspecialchars($programa['nombre_programa']); ?></strong>
                                    <div class="help-text">Código: <?php echo $programa['codigo_programa']; ?></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        Un profesor puede dictar clases en múltiples programas simultáneamente
                    </div>
                </div>
                
                <!-- Botones -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Profesor
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
            </div>
        </form>
    </div>
    
    <script>
        function mostrarTab(tabId) {
            // Ocultar todas las pestañas
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Mostrar pestaña seleccionada
            document.getElementById(tabId).classList.add('active');
            document.querySelector(`[onclick="mostrarTab('${tabId}')"]`).classList.add('active');
        }
        
        function toggleCheckbox(checkbox) {
            const label = checkbox.closest('.programa-checkbox');
            if (checkbox.checked) {
                label.classList.add('checked');
            } else {
                label.classList.remove('checked');
            }
        }
        
        // Inicializar checkboxes
        document.querySelectorAll('.programa-checkbox input[type="checkbox"]').forEach(checkbox => {
            toggleCheckbox(checkbox);
        });
        
        // Validar contraseña
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const strengthBar = document.getElementById('password-strength-bar');
        const passwordMatch = document.getElementById('password-match');
        
        function checkPasswordStrength(pass) {
            let strength = 0;
            if (pass.length >= 6) strength++;
            if (pass.length >= 8) strength++;
            if (/[A-Z]/.test(pass)) strength++;
            if (/[0-9]/.test(pass)) strength++;
            if (/[^A-Za-z0-9]/.test(pass)) strength++;
            
            strengthBar.className = 'password-strength-bar';
            strengthBar.style.width = (strength * 20) + '%';
            
            if (strength < 2) {
                strengthBar.classList.add('password-weak');
            } else if (strength < 4) {
                strengthBar.classList.add('password-medium');
            } else {
                strengthBar.classList.add('password-strong');
            }
        }
        
        function checkPasswordMatch() {
            if (password.value !== confirmPassword.value) {
                passwordMatch.innerHTML = '<span style="color: var(--danger);"><i class="fas fa-times-circle"></i> Las contraseñas no coinciden</span>';
                return false;
            } else if (password.value.length > 0) {
                passwordMatch.innerHTML = '<span style="color: var(--success);"><i class="fas fa-check-circle"></i> Las contraseñas coinciden</span>';
                return true;
            }
            return true;
        }
        
        password.addEventListener('input', function() {
            checkPasswordStrength(this.value);
            checkPasswordMatch();
        });
        
        confirmPassword.addEventListener('input', checkPasswordMatch);
        
        // Validar formulario
        document.querySelector('form').addEventListener('submit', function(e) {
            let valid = true;
            
            // Validar datos personales
            const codigo = document.getElementById('codigo_profesor').value;
            const documento = document.getElementById('documento_identidad').value;
            const nombres = document.getElementById('nombres').value;
            const apellidos = document.getElementById('apellidos').value;
            const titulo = document.getElementById('titulo_academico').value;
            const especialidad = document.getElementById('especialidad').value;
            const emailInstitucional = document.getElementById('email_institucional').value;
            
            if (!codigo || !documento || !nombres || !apellidos || !titulo || !especialidad || !emailInstitucional) {
                alert('Complete todos los campos obligatorios en Datos Personales');
                mostrarTab('datos-personales');
                valid = false;
            }
            
            // Validar cuenta de acceso
            if (valid) {
                const username = document.getElementById('username').value;
                const email = document.getElementById('email').value;
                
                if (!username || !email || !password.value) {
                    alert('Complete todos los campos obligatorios en Cuenta de Acceso');
                    mostrarTab('cuenta-acceso');
                    valid = false;
                }
            }
            
            // Validar contraseña
            if (valid && !checkPasswordMatch()) {
                alert('Las contraseñas no coinciden');
                mostrarTab('cuenta-acceso');
                valid = false;
            }
            
            if (valid && password.value.length < 6) {
                alert('La contraseña debe tener al menos 6 caracteres');
                mostrarTab('cuenta-acceso');
                valid = false;
            }
            
            if (!valid) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>