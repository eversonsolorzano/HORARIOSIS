<?php
require_once 'includes/config.php';

// Destruir sesión
session_destroy();

// Redirigir usando SITE_URL
header('Location: ' . SITE_URL . 'login.php');
exit();
?>