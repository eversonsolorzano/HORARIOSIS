<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

// Obtener estudiantes con búsqueda
$search = isset($_GET['search']) ? Funciones::sanitizar($_GET['search']) : '';
$programa_filter = isset($_GET['programa']) ? intval($_GET['programa']) : 0;
$estado_filter = isset($_GET['estado']) ? Funciones::sanitizar($_GET['estado']) : '';

$sql = "SELECT e.*, p.nombre_programa, u.email, u.activo
        FROM estudiantes e
        JOIN programas_estudio p ON e.id_programa = p.id_programa
        JOIN usuarios u ON e.id_usuario = u.id_usuario
        WHERE 1=1";

$params = [];

if ($search) {
    $sql .= " AND (e.nombres LIKE ? OR e.apellidos LIKE ? OR e.codigo_estudiante LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($programa_filter) {
    $sql .= " AND e.id_programa = ?";
    $params[] = $programa_filter;
}

if ($estado_filter) {
    $sql .= " AND e.estado = ?";
    $params[] = $estado_filter;
}

$sql .= " ORDER BY e.fecha_ingreso DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$estudiantes = $stmt->fetchAll();

// Obtener programas para filtro
$programas = $db->query("SELECT * FROM programas_estudio WHERE activo = 1")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estudiantes - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-graduate"></i> Gestión de Estudiantes</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="../usuarios/"><i class="fas fa-users"></i> Usuarios</a>
                <a href="index.php" class="active"><i class="fas fa-user-graduate"></i> Estudiantes</a>
                <a href="../profesores/"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
                <a href="../cursos/"><i class="fas fa-book"></i> Cursos</a>
                <a href="../horarios/"><i class="fas fa-calendar-alt"></i> Horarios</a>
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
                <h2><i class="fas fa-list"></i> Lista de Estudiantes</h2>
                <div class="search-filter">
                    <form method="GET" action="" style="display: flex; gap: 10px;">
                        <input type="text" name="search" class="search-input" placeholder="Buscar estudiante..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <select name="programa" class="select-input">
                            <option value="">Todos los programas</option>
                            <?php foreach ($programas as $programa): ?>
                                <option value="<?php echo $programa['id_programa']; ?>"
                                    <?php echo $programa_filter == $programa['id_programa'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($programa['nombre_programa']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="estado" class="select-input">
                            <option value="">Todos los estados</option>
                            <option value="activo" <?php echo $estado_filter == 'activo' ? 'selected' : ''; ?>>Activo</option>
                            <option value="inactivo" <?php echo $estado_filter == 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                            <option value="graduado" <?php echo $estado_filter == 'graduado' ? 'selected' : ''; ?>>Graduado</option>
                            <option value="retirado" <?php echo $estado_filter == 'retirado' ? 'selected' : ''; ?>>Retirado</option>
                        </select>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Buscar
                        </button>
                        <a href="crear.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Nuevo Estudiante
                        </a>
                    </form>
                </div>
            </div>
            
            <div class="table-container">
                <?php if (count($estudiantes) > 0): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Estudiante</th>
                                <th>Documento</th>
                                <th>Programa</th>
                                <th>Semestre</th>
                                <th>Estado</th>
                                <th>Email</th>
                                <th>Fecha Ingreso</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($estudiantes as $estudiante): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($estudiante['codigo_estudiante']); ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars($estudiante['nombres'] . ' ' . $estudiante['apellidos']); ?>
                                    <?php if (!$estudiante['activo']): ?>
                                        <span class="badge badge-inactive">Usuario inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($estudiante['documento_identidad']); ?></td>
                                <td><?php echo htmlspecialchars($estudiante['nombre_programa']); ?></td>
                                <td><?php echo $estudiante['semestre_actual']; ?></td>
                                <td>
                                    <?php 
                                    $badge_class = '';
                                    switch ($estudiante['estado']) {
                                        case 'activo': $badge_class = 'badge-success'; break;
                                        case 'inactivo': $badge_class = 'badge-inactive'; break;
                                        case 'graduado': $badge_class = 'badge-info'; break;
                                        case 'retirado': $badge_class = 'badge-warning'; break;
                                    }
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo ucfirst($estudiante['estado']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($estudiante['email']); ?></td>
                                <td><?php echo Funciones::formatearFecha($estudiante['fecha_ingreso']); ?></td>
                                <td>
                                    <div class="actions">
                                        <a href="ver.php?id=<?php echo $estudiante['id_estudiante']; ?>" 
                                           class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">
                                            <i class="fas fa-eye"></i> Ver
                                        </a>
                                        <a href="editar.php?id=<?php echo $estudiante['id_estudiante']; ?>" 
                                           class="btn btn-success" style="padding: 5px 10px; font-size: 12px;">
                                            <i class="fas fa-edit"></i> Editar
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-user-graduate"></i>
                        <p>No se encontraron estudiantes.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="../../assets/js/main.js"></script>
</body>
</html>