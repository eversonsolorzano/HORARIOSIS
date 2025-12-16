<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

// Obtener ID del usuario
$id_usuario = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_usuario) {
    Funciones::redireccionar('index.php', 'ID de usuario no válido', 'error');
}

// No permitir eliminar al usuario actual
if ($id_usuario == Auth::getUserData()['id']) {
    Funciones::redireccionar('index.php', 'No puedes cambiar tu propio estado', 'error');
}

// Obtener estado actual
$stmt = $db->prepare("SELECT activo FROM usuarios WHERE id_usuario = ?");
$stmt->execute([$id_usuario]);
$usuario = $stmt->fetch();

if (!$usuario) {
    Funciones::redireccionar('index.php', 'Usuario no encontrado', 'error');
}

// Cambiar estado (activo/inactivo)
$nuevo_estado = $usuario['activo'] ? 0 : 1;
$accion = $nuevo_estado ? 'activado' : 'desactivado';

$stmt = $db->prepare("UPDATE usuarios SET activo = ? WHERE id_usuario = ?");
$stmt->execute([$nuevo_estado, $id_usuario]);

Funciones::redireccionar('index.php', "Usuario {$accion} exitosamente");
?>