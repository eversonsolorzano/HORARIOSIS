<?php
// Configuración del sistema
define('SITE_NAME', 'Sistema de Horarios');

// Obtener la URL base automáticamente
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$script = $_SERVER['SCRIPT_NAME'];
$base_url = $protocol . "://" . $host . dirname($script);

// Remover la última barra si existe
$base_url = rtrim($base_url, '/');

// Si estás en la raíz de HORARIOS
define('BASE_URL', $base_url);
define('SITE_URL', BASE_URL . '/');

// O puedes definirla manualmente si prefieres
// define('SITE_URL', 'http://localhost/HORARIOS/');

define('DB_HOST', 'localhost');
define('DB_NAME', 'horarios_instituto');
define('DB_USER', 'root');
define('DB_PASS', ''); // Cambiar según tu configuración
define('TIMEZONE', 'America/Lima');

// Niveles de acceso
define('ROL_COORDINADOR', 'coordinador');
define('ROL_PROFESOR', 'profesor');
define('ROL_ESTUDIANTE', 'estudiante');

// Estados
define('ESTADO_ACTIVO', 'activo');
define('ESTADO_INACTIVO', 'inactivo');

// Iniciar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>