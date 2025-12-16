<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

// Obtener cursos con búsqueda
$search = isset($_GET['search']) ? Funciones::sanitizar($_GET['search']) : '';
$programa_filter = isset($_GET['programa']) ? intval($_GET['programa']) : 0;
$semestre_filter = isset($_GET['semestre']) ? intval($_GET['semestre']) : 0;
$tipo_filter = isset($_GET['tipo']) ? Funciones::sanitizar($_GET['tipo']) : '';

$sql = "SELECT c.*, p.nombre_programa 
        FROM cursos c
        JOIN programas_estudio p ON c.id_programa = p.id_programa
        WHERE 1=1";

$params = [];

if ($search) {
    $sql .= " AND (c.nombre_curso LIKE ? OR c.codigo_curso LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($programa_filter) {
    $sql .= " AND c.id_programa = ?";
    $params[] = $programa_filter;
}

if ($semestre_filter) {
    $sql .= " AND c.semestre = ?";
    $params[] = $semestre_filter;
}

if ($tipo_filter) {
    $sql .= " AND c.tipo_curso = ?";
    $params[] = $tipo_filter;
}

$sql .= " ORDER BY c.semestre, c.codigo_curso";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$cursos = $stmt->fetchAll();

// Obtener programas para filtro
$programas = $db->query("SELECT * FROM programas_estudio WHERE activo = 1")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cursos - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-book"></i> Gestión de Cursos</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="../estudiantes/"><i class="fas fa-user-graduate"></i> Estudiantes</a>
                <a href="../profesores/"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
                <a href="index.php" class="active"><i class="fas fa-book"></i> Cursos</a>
                <a href="../horarios/"><i class="fas fa-calendar-alt"></i> Horarios</a>
                <a href="../aulas/"><i class="fas fa-school"></i> Aulas</a>
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
                <h2><i class="fas fa-list"></i> Lista de Cursos</h2>
                <div class="search-filter">
                    <form method="GET" action="" style="display: flex; gap: 10px;">
                        <input type="text" name="search" class="search-input" placeholder="Buscar curso..." 
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
                        <select name="semestre" class="select-input">
                            <option value="">Todos los semestres</option>
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <option value="<?php echo $i; ?>"
                                    <?php echo $semestre_filter == $i ? 'selected' : ''; ?>>
                                    Semestre <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <select name="tipo" class="select-input">
                            <option value="">Todos los tipos</option>
                            <option value="obligatorio" <?php echo $tipo_filter == 'obligatorio' ? 'selected' : ''; ?>>Obligatorio</option>
                            <option value="electivo" <?php echo $tipo_filter == 'electivo' ? 'selected' : ''; ?>>Electivo</option>
                            <option value="taller" <?php echo $tipo_filter == 'taller' ? 'selected' : ''; ?>>Taller</option>
                            <option value="laboratorio" <?php echo $tipo_filter == 'laboratorio' ? 'selected' : ''; ?>>Laboratorio</option>
                        </select>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Buscar
                        </button>
                        <a href="crear.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Nuevo Curso
                        </a>
                    </form>
                </div>
            </div>
            
            <div class="table-container">
                <?php if (count($cursos) > 0): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre del Curso</th>
                                <th>Programa</th>
                                <th>Semestre</th>
                                <th>Tipo</th>
                                <th>Créditos</th>
                                <th>Horas/Sem</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cursos as $curso): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($curso['codigo_curso']); ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars($curso['nombre_curso']); ?>
                                    <?php if ($curso['descripcion']): ?>
                                        <small style="display: block; color: var(--gray-500); margin-top: 5px;">
                                            <?php echo htmlspecialchars(substr($curso['descripcion'], 0, 100)); ?>
                                            <?php echo strlen($curso['descripcion']) > 100 ? '...' : ''; ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($curso['nombre_programa']); ?></td>
                                <td>Sem <?php echo $curso['semestre']; ?></td>
                                <td>
                                    <?php 
                                    $badge_class = '';
                                    switch ($curso['tipo_curso']) {
                                        case 'obligatorio': $badge_class = 'badge-info'; break;
                                        case 'electivo': $badge_class = 'badge-success'; break;
                                        case 'taller': $badge_class = 'badge-warning'; break;
                                        case 'laboratorio': $badge_class = 'badge-primary'; break;
                                    }
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo ucfirst($curso['tipo_curso']); ?>
                                    </span>
                                </td>
                                <td><?php echo $curso['creditos']; ?></td>
                                <td><?php echo $curso['horas_semanales']; ?>h</td>
                                <td>
                                    <span class="badge <?php echo $curso['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                                        <?php echo $curso['activo'] ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="actions">
                                        <a href="editar.php?id=<?php echo $curso['id_curso']; ?>" 
                                           class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">
                                            <i class="fas fa-edit"></i> Editar
                                        </a>
                                        <a href="gestionar_prerrequisitos.php?id=<?php echo $curso['id_curso']; ?>" 
                                           class="btn btn-success" style="padding: 5px 10px; font-size: 12px;">
                                            <i class="fas fa-link"></i> Prerreq.
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-book"></i>
                        <p>No se encontraron cursos.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="../../assets/js/main.js"></script>
</body>
</html>