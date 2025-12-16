<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

// Obtener ID del curso
$id_curso = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_curso) {
    Funciones::redireccionar('index.php', 'ID de curso no válido', 'error');
}

// Obtener datos del curso
$stmt = $db->prepare("
    SELECT c.*, p.nombre_programa 
    FROM cursos c 
    JOIN programas_estudio p ON c.id_programa = p.id_programa 
    WHERE c.id_curso = ?
");
$stmt->execute([$id_curso]);
$curso = $stmt->fetch();

if (!$curso) {
    Funciones::redireccionar('index.php', 'Curso no encontrado', 'error');
}

// Obtener prerrequisitos actuales
$prerrequisitos_actuales = [];
if ($curso['prerrequisitos']) {
    $ids_prerrequisitos = explode(',', $curso['prerrequisitos']);
    if (!empty($ids_prerrequisitos)) {
        $placeholders = str_repeat('?,', count($ids_prerrequisitos) - 1) . '?';
        $stmt = $db->prepare("
            SELECT c.*, p.nombre_programa 
            FROM cursos c 
            JOIN programas_estudio p ON c.id_programa = p.id_programa 
            WHERE c.id_curso IN ($placeholders)
            ORDER BY c.semestre, c.nombre_curso
        ");
        $stmt->execute($ids_prerrequisitos);
        $prerrequisitos_actuales = $stmt->fetchAll();
    }
}

// Obtener cursos disponibles para agregar como prerrequisitos
$stmt = $db->prepare("
    SELECT c.*, p.nombre_programa 
    FROM cursos c 
    JOIN programas_estudio p ON c.id_programa = p.id_programa 
    WHERE c.activo = 1 AND c.id_curso != ? AND c.semestre < ?
    AND c.id_curso NOT IN (
        SELECT id_curso FROM cursos 
        WHERE FIND_IN_SET(?, prerrequisitos) > 0
    )
    ORDER BY c.semestre, c.nombre_curso
");
$stmt->execute([$id_curso, $curso['semestre'], $id_curso]);
$cursos_disponibles = $stmt->fetchAll();

// Manejar agregar prerrequisito
if (isset($_GET['agregar']) && isset($_GET['prerrequisito_id'])) {
    $prerrequisito_id = intval($_GET['prerrequisito_id']);
    
    // Verificar que no se agregue a sí mismo
    if ($prerrequisito_id == $id_curso) {
        Funciones::redireccionar('gestionar_prerrequisitos.php?id=' . $id_curso, 'No puede ser prerrequisito de sí mismo', 'error');
    }
    
    // Verificar que no cree ciclos
    $stmt = $db->prepare("SELECT prerrequisitos FROM cursos WHERE id_curso = ?");
    $stmt->execute([$prerrequisito_id]);
    $prerreq_del_prerreq = $stmt->fetch();
    
    if ($prerreq_del_prerreq && $prerreq_del_prerreq['prerrequisitos']) {
        $ids_prerreq = explode(',', $prerreq_del_prerreq['prerrequisitos']);
        if (in_array($id_curso, $ids_prerreq)) {
            Funciones::redireccionar('gestionar_prerrequisitos.php?id=' . $id_curso, 'No se puede crear un ciclo de dependencias', 'error');
        }
    }
    
    // Actualizar prerrequisitos
    $nuevos_prerrequisitos = $curso['prerrequisitos'];
    if (empty($nuevos_prerrequisitos)) {
        $nuevos_prerrequisitos = $prerrequisito_id;
    } else {
        $ids = explode(',', $nuevos_prerrequisitos);
        if (!in_array($prerrequisito_id, $ids)) {
            $ids[] = $prerrequisito_id;
            $nuevos_prerrequisitos = implode(',', $ids);
        }
    }
    
    $stmt = $db->prepare("UPDATE cursos SET prerrequisitos = ? WHERE id_curso = ?");
    $stmt->execute([$nuevos_prerrequisitos, $id_curso]);
    
    Funciones::redireccionar('gestionar_prerrequisitos.php?id=' . $id_curso, 'Prerrequisito agregado exitosamente');
}

// Manejar eliminar prerrequisito
if (isset($_GET['eliminar']) && isset($_GET['prerrequisito_id'])) {
    $prerrequisito_id = intval($_GET['prerrequisito_id']);
    
    if ($curso['prerrequisitos']) {
        $ids = explode(',', $curso['prerrequisitos']);
        $nuevos_ids = array_filter($ids, function($id) use ($prerrequisito_id) {
            return $id != $prerrequisito_id;
        });
        
        $nuevos_prerrequisitos = implode(',', $nuevos_ids);
        $stmt = $db->prepare("UPDATE cursos SET prerrequisitos = ? WHERE id_curso = ?");
        $stmt->execute([$nuevos_prerrequisitos, $id_curso]);
        
        Funciones::redireccionar('gestionar_prerrequisitos.php?id=' . $id_curso, 'Prerrequisito eliminado exitosamente');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionar Prerrequisitos - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-link"></i> Gestionar Prerrequisitos</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-book"></i> Cursos</a>
                <a href="gestionar_prerrequisitos.php?id=<?php echo $id_curso; ?>" class="active"><i class="fas fa-link"></i> Prerrequisitos</a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <div class="grid-2">
            <!-- Información del Curso -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-info-circle"></i> Información del Curso</h2>
                    <a href="editar.php?id=<?php echo $id_curso; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Editar Curso
                    </a>
                </div>
                
                <div style="padding: 20px 0;">
                    <h3 style="color: var(--dark); margin-bottom: 10px;">
                        <?php echo htmlspecialchars($curso['nombre_curso']); ?>
                    </h3>
                    <p style="color: var(--gray-600); margin-bottom: 20px;">
                        <strong>Código:</strong> <?php echo htmlspecialchars($curso['codigo_curso']); ?> |
                        <strong>Programa:</strong> <?php echo htmlspecialchars($curso['nombre_programa']); ?> |
                        <strong>Semestre:</strong> <?php echo $curso['semestre']; ?>
                    </p>
                    
                    <?php if ($curso['descripcion']): ?>
                        <div style="background: var(--gray-50); padding: 15px; border-radius: var(--radius); margin-bottom: 20px;">
                            <strong style="color: var(--gray-700);">Descripción:</strong>
                            <p style="color: var(--gray-600); margin-top: 5px;"><?php echo nl2br(htmlspecialchars($curso['descripcion'])); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="grid-2">
                        <div class="info-item">
                            <strong>Tipo:</strong> 
                            <span class="badge <?php 
                                switch($curso['tipo_curso']) {
                                    case 'obligatorio': echo 'badge-info'; break;
                                    case 'electivo': echo 'badge-success'; break;
                                    case 'taller': echo 'badge-warning'; break;
                                    case 'laboratorio': echo 'badge-primary'; break;
                                }
                            ?>">
                                <?php echo ucfirst($curso['tipo_curso']); ?>
                            </span>
                        </div>
                        
                        <div class="info-item">
                            <strong>Créditos:</strong> 
                            <span><?php echo $curso['creditos']; ?></span>
                        </div>
                        
                        <div class="info-item">
                            <strong>Horas Semanales:</strong> 
                            <span><?php echo $curso['horas_semanales']; ?>h</span>
                        </div>
                        
                        <div class="info-item">
                            <strong>Estado:</strong> 
                            <span class="badge <?php echo $curso['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                                <?php echo $curso['activo'] ? 'Activo' : 'Inactivo'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Prerrequisitos Actuales -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-list-check"></i> Prerrequisitos Actuales</h2>
                    <span class="badge badge-info"><?php echo count($prerrequisitos_actuales); ?> cursos</span>
                </div>
                
                <?php if (count($prerrequisitos_actuales) > 0): ?>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <?php foreach ($prerrequisitos_actuales as $prerrequisito): ?>
                            <div class="prerrequisito-item">
                                <div>
                                    <strong><?php echo htmlspecialchars($prerrequisito['nombre_curso']); ?></strong>
                                    <p style="color: var(--gray-600); margin-top: 5px; font-size: 13px;">
                                        <?php echo $prerrequisito['codigo_curso']; ?> | 
                                        Sem <?php echo $prerrequisito['semestre']; ?> | 
                                        <?php echo htmlspecialchars($prerrequisito['nombre_programa']); ?>
                                    </p>
                                </div>
                                <div>
                                    <a href="gestionar_prerrequisitos.php?id=<?php echo $id_curso; ?>&eliminar=1&prerrequisito_id=<?php echo $prerrequisito['id_curso']; ?>" 
                                       class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;"
                                       onclick="return confirm('¿Estás seguro de eliminar este prerrequisito?')">
                                        <i class="fas fa-trash"></i> Eliminar
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-unlink"></i>
                        <p>Este curso no tiene prerrequisitos asignados.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Cursos Disponibles para Agregar -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-plus-circle"></i> Agregar Nuevo Prerrequisito</h2>
                <p style="color: var(--gray-600);">Solo se muestran cursos de semestres anteriores</p>
            </div>
            
            <?php if (count($cursos_disponibles) > 0): ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Curso</th>
                                <th>Código</th>
                                <th>Programa</th>
                                <th>Semestre</th>
                                <th>Tipo</th>
                                <th>Créditos</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cursos_disponibles as $curso_disponible): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($curso_disponible['nombre_curso']); ?></td>
                                <td><?php echo $curso_disponible['codigo_curso']; ?></td>
                                <td><?php echo htmlspecialchars($curso_disponible['nombre_programa']); ?></td>
                                <td>Sem <?php echo $curso_disponible['semestre']; ?></td>
                                <td>
                                    <span class="badge <?php 
                                        switch($curso_disponible['tipo_curso']) {
                                            case 'obligatorio': echo 'badge-info'; break;
                                            case 'electivo': echo 'badge-success'; break;
                                            case 'taller': echo 'badge-warning'; break;
                                            case 'laboratorio': echo 'badge-primary'; break;
                                        }
                                    ?>">
                                        <?php echo ucfirst($curso_disponible['tipo_curso']); ?>
                                    </span>
                                </td>
                                <td><?php echo $curso_disponible['creditos']; ?></td>
                                <td>
                                    <a href="gestionar_prerrequisitos.php?id=<?php echo $id_curso; ?>&agregar=1&prerrequisito_id=<?php echo $curso_disponible['id_curso']; ?>" 
                                       class="btn btn-success" style="padding: 5px 10px; font-size: 12px;">
                                        <i class="fas fa-plus"></i> Agregar
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <p>No hay cursos disponibles para agregar como prerrequisitos.</p>
                    <p style="color: var(--gray-500); font-size: 13px; margin-top: 10px;">
                        Todos los cursos de semestres anteriores ya son prerrequisitos o no hay cursos disponibles.
                    </p>
                </div>
            <?php endif; ?>
        </div>
        
        <div style="display: flex; gap: 15px; margin-top: 20px;">
            <a href="index.php" class="btn">
                <i class="fas fa-arrow-left"></i> Volver a la lista
            </a>
            <a href="editar.php?id=<?php echo $id_curso; ?>" class="btn btn-primary">
                <i class="fas fa-edit"></i> Editar Curso
            </a>
        </div>
    </div>
    
    <style>
    .prerrequisito-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px;
        border-bottom: 1px solid var(--gray-200);
        transition: background-color 0.2s ease;
    }
    
    .prerrequisito-item:hover {
        background: var(--gray-50);
    }
    
    .prerrequisito-item:last-child {
        border-bottom: none;
    }
    
    .info-item {
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--gray-100);
    }
    
    .info-item strong {
        display: block;
        color: var(--gray-700);
        font-size: 13px;
        margin-bottom: 5px;
    }
    
    .info-item span {
        color: var(--dark);
        font-size: 14px;
    }
    </style>
    
    <script src="../../assets/js/main.js"></script>
</body>
</html>