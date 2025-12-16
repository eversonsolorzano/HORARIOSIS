<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

// Obtener usuarios
$search = isset($_GET['search']) ? Funciones::sanitizar($_GET['search']) : '';
$rol_filter = isset($_GET['rol']) ? Funciones::sanitizar($_GET['rol']) : '';

$sql = "SELECT u.*, 
        CASE 
            WHEN u.rol = 'estudiante' THEN e.nombres 
            WHEN u.rol = 'profesor' THEN p.nombres 
            ELSE u.username 
        END as nombre_completo
        FROM usuarios u
        LEFT JOIN estudiantes e ON u.id_usuario = e.id_usuario
        LEFT JOIN profesores p ON u.id_usuario = p.id_usuario
        WHERE 1=1";

$params = [];

if ($search) {
    $sql .= " AND (u.username LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($rol_filter) {
    $sql .= " AND u.rol = ?";
    $params[] = $rol_filter;
}

$sql .= " ORDER BY u.fecha_creacion DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-users"></i> Gestión de Usuarios</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php" class="active"><i class="fas fa-users"></i> Usuarios</a>
                <a href="../estudiantes/"><i class="fas fa-user-graduate"></i> Estudiantes</a>
                <a href="../profesores/"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
                <a href="../cursos/"><i class="fas fa-book"></i> Cursos</a>
                <a href="../horarios/"><i class="fas fa-calendar-alt"></i> Horarios</a>
                <a href="../../perfil.php"><i class="fas fa-user-circle"></i> Mi Perfil</a>
                <a href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Salir</a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-list"></i> Lista de Usuarios</h2>
                <div class="search-filter">
                    <form method="GET" action="" style="display: flex; gap: 10px;">
                        <input type="text" name="search" class="search-input" placeholder="Buscar usuario o email..." value="<?php echo htmlspecialchars($search); ?>">
                        <select name="rol" class="select-input">
                            <option value="">Todos los roles</option>
                            <option value="coordinador" <?php echo $rol_filter == 'coordinador' ? 'selected' : ''; ?>>Coordinador</option>
                            <option value="profesor" <?php echo $rol_filter == 'profesor' ? 'selected' : ''; ?>>Profesor</option>
                            <option value="estudiante" <?php echo $rol_filter == 'estudiante' ? 'selected' : ''; ?>>Estudiante</option>
                        </select>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Buscar
                        </button>
                        <a href="crear.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Nuevo Usuario
                        </a>
                    </form>
                </div>
            </div>
            
            <div class="table-container">
                <?php if (count($usuarios) > 0): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Usuario</th>
                                <th>Nombre</th>
                                <th>Email</th>
                                <th>Rol</th>
                                <th>Programa</th>
                                <th>Estado</th>
                                <th>Fecha Creación</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $usuario): ?>
                            <tr>
                                <td>#<?php echo $usuario['id_usuario']; ?></td>
                                <td><strong><?php echo htmlspecialchars($usuario['username']); ?></strong></td>
                                <td><?php echo htmlspecialchars($usuario['nombre_completo']); ?></td>
                                <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $usuario['rol']; ?>">
                                        <?php echo ucfirst($usuario['rol']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($usuario['programa_estudio'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="badge <?php echo $usuario['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                                        <?php echo $usuario['activo'] ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td><?php echo Funciones::formatearFecha($usuario['fecha_creacion']); ?></td>
                                <td>
                                    <div class="actions">
                                        <a href="editar.php?id=<?php echo $usuario['id_usuario']; ?>" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">
                                            <i class="fas fa-edit"></i> Editar
                                        </a>
                                        <a href="eliminar.php?id=<?php echo $usuario['id_usuario']; ?>" 
                                           class="btn btn-danger" 
                                           style="padding: 5px 10px; font-size: 12px;"
                                           onclick="return confirm('¿Estás seguro de cambiar el estado de este usuario?')">
                                            <i class="fas fa-trash"></i> <?php echo $usuario['activo'] ? 'Desactivar' : 'Activar'; ?>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users-slash"></i>
                        <p>No se encontraron usuarios.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="../../assets/js/main.js"></script>
</body>
</html>