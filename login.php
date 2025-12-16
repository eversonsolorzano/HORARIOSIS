<?php
require_once 'includes/config.php';
require_once 'includes/funciones.php';
require_once 'includes/auth.php';

$error = '';
$login_success = false;
$rol = '';
$user_name = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = Funciones::sanitizar($_POST['username']);
    $password = Funciones::sanitizar($_POST['password']);
    
    // Iniciar sesión y obtener rol
    if (Auth::login($username, $password)) {
        $user = Auth::getUserData();
        $rol = $user['rol'];
        $user_name = $user['nombre'];
        $login_success = true;
    } else {
        $error = 'Usuario o contraseña incorrectos';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SchedulePro</title>
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="stylesheet" href="assets/css/loaders.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@700&family=Orbitron:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        /* Quitar debug styles */
        /* * {
            outline: 1px solid rgba(255,0,0,0.1);
        } */
        
        /* Asegurar que el login se vea */
        .login-container {
            opacity: 1 !important;
            transform: translateY(0) !important;
            display: flex !important;
        }
    </style>
</head>
<body class="login-page">
    <!-- Overlay para cargadores -->
    <div id="loaderOverlay" class="loader-overlay">
        <div class="loader-container">
            <!-- Cargador se mostrará aquí dinámicamente -->
        </div>
    </div>
    
    <div class="background-animation">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
        <div class="shape shape-4"></div>
        <div class="shape shape-5"></div>
    </div>
    
    <?php if (!$login_success): ?>
    <div class="login-wrapper">
        <div class="login-container animated-container">
            <!-- Logo personalizado -->
            <div class="logo-container">
                <div class="logo-icon">
                    <div class="logo-circle">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="logo-pulse"></div>
                </div>
                <div class="logo-text">
                    <h1 class="logo-main">Sistema<span class="logo-highlight">Horarios</span></h1>
                    <p class="logo-subtitle">Sistema de Horarios</p>
                </div>
            </div>
            
            <div class="form-section">
                <div class="login-header">
                    <h2>Iniciar Sesión</h2>
                    <p>Accede a tu cuenta para gestionar horarios</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="login-error animated-shake">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo $error; ?></span>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" class="login-form" id="loginForm">
                    <div class="form-group animated-input">
                        <div class="input-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <input type="text" id="username" name="username" required 
                               placeholder="Usuario o Email">
                        <label for="username">Usuario</label>
                        <div class="focus-border"></div>
                    </div>
                    
                    <div class="form-group animated-input">
                        <div class="input-icon">
                            <i class="fas fa-lock"></i>
                        </div>
                        <input type="password" id="password" name="password" required 
                               placeholder="Contraseña">
                        <label for="password">Contraseña</label>
                        <button type="button" class="toggle-password" onclick="togglePassword('password')">
                            <i class="fas fa-eye"></i>
                        </button>
                        <div class="focus-border"></div>
                    </div>
                    
                    <div class="form-options">
                        <div class="remember-me">
                            <input type="checkbox" id="remember">
                            <label for="remember">Recordarme</label>
                        </div>
                        <a href="#" class="forgot-password">¿Olvidaste tu contraseña?</a>
                    </div>
                    
                    <button type="submit" class="btn-login" id="loginButton">
                        <span class="btn-text">Ingresar</span>
                        <span class="btn-icon">
                            <i class="fas fa-arrow-right"></i>
                        </span>
                        <div class="btn-loader">
                            <div class="loader-spinner"></div>
                        </div>
                    </button>
                </form>
            
            </div>
        </div>
        
        <div class="login-footer">
            <p>© 2025 SistemaHorarios. Todos los derechos reservados.</p>
            <p><a href="#">Términos de uso</a> | <a href="#">Política de privacidad</a></p>
        </div>
    </div>
    <?php endif; ?>
    
    <script src="assets/js/login.js"></script>
    <script src="assets/js/loaders.js"></script>
    <script>
    function togglePassword(fieldId) {
        const field = document.getElementById(fieldId);
        const button = field.parentElement.querySelector('.toggle-password');
        const icon = button.querySelector('i');
        
        if (field.type === 'password') {
            field.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            field.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
    
    // Detectar el rol después del login exitoso
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM cargado');
        
        // Si el login fue exitoso (PHP), mostrar cargador inmediatamente
        <?php if ($login_success): ?>
        console.log('Login exitoso desde PHP, rol:', '<?php echo $rol; ?>');
        setTimeout(function() {
            if (typeof showRoleLoader === 'function') {
                showRoleLoader('<?php echo $rol; ?>', '<?php echo $user_name; ?>');
            }
            
            // Redirigir después de 3 segundos
            setTimeout(() => {
                window.location.href = '<?php echo $rol; ?>/dashboard.php';
            }, 3000);
        }, 500);
        <?php endif; ?>
        
        // Configurar el formulario
        const form = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginButton');
        
        if (form && loginBtn) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Mostrar cargador de validación
                loginBtn.classList.add('loading');
                
                // Detectar rol basado en el usuario ingresado
                const username = document.getElementById('username').value;
                const detectedRole = detectRoleFromUsername(username);
                console.log('Rol detectado para validación:', detectedRole);
                
                // Simular validación (2 segundos)
                setTimeout(() => {
                    // Enviar formulario real
                    this.submit();
                }, 2000);
            });
        }
        
        // Verificar que los inputs tengan la clase focused si tienen valor
        const inputs = document.querySelectorAll('.animated-input input');
        inputs.forEach(input => {
            // Inicializar estado de los inputs
            if (input.value) {
                input.parentElement.classList.add('focused');
            }
            
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('focused');
            });
            
            input.addEventListener('blur', function() {
                if (!this.value) {
                    this.parentElement.classList.remove('focused');
                }
            });
        });
        
        // Animación de entrada del formulario
        setTimeout(() => {
            const container = document.querySelector('.login-container');
            if (container) {
                container.classList.add('loaded');
            }
        }, 300);
    });
    
    function detectRoleFromUsername(username) {
        const user = username.toLowerCase();
        if (user.includes('admin')) return 'coordinador';
        if (user.includes('prof')) return 'profesor';
        if (user.includes('est')) return 'estudiante';
        return 'coordinador'; // default
    }
    
    // Función showRoleLoader si no está definida en loaders.js
    if (typeof showRoleLoader === 'undefined') {
        function showRoleLoader(role, userName) {
            console.log('Mostrando cargador para:', role, userName);
            const overlay = document.getElementById('loaderOverlay');
            if (overlay) {
                overlay.classList.add('active');
                console.log('Overlay activado');
                
                // Crear HTML básico del cargador
                const container = overlay.querySelector('.loader-container');
                const roleNames = {
                    'coordinador': 'COORDINADOR',
                    'profesor': 'PROFESOR', 
                    'estudiante': 'ESTUDIANTE'
                };
                
                container.innerHTML = `
                    <div class="role-loader ${role}-loader">
                        <h2 class="loader-title">ACCESO ${roleNames[role] || 'USUARIO'}</h2>
                        <p class="loader-subtitle">Redirigiendo al panel de control...</p>
                        <div class="loader-visual">
                            <div class="loader-spinner-large"></div>
                        </div>
                        <div class="user-info">
                            <div class="user-avatar">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="user-name">${userName || 'Usuario'}</div>
                            <div class="user-role-badge">${roleNames[role] || 'Usuario'}</div>
                        </div>
                    </div>
                `;
            }
        }
    }
    </script>
    <style>
        .loader-spinner-large {
            width: 80px;
            height: 80px;
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top-color: #00ff88;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 30px auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .coordinator-loader .loader-spinner-large {
            border-top-color: #00ff88;
        }
        
        .teacher-loader .loader-spinner-large {
            border-top-color: #ff9900;
        }
        
        .student-loader .loader-spinner-large {
            border-top-color: #9d4edd;
        }
    </style>
</body>
</html>