<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

// Paginación
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$limite = 20;
$offset = ($pagina - 1) * $limite;

// Filtros
$busqueda = isset($_GET['busqueda']) ? Funciones::sanitizar($_GET['busqueda']) : '';
$estado = isset($_GET['estado']) ? Funciones::sanitizar($_GET['estado']) : '';
$programa = isset($_GET['programa']) ? intval($_GET['programa']) : 0;
$semestre = isset($_GET['semestre']) ? intval($_GET['semestre']) : 0;
$curso = isset($_GET['curso']) ? intval($_GET['curso']) : 0;

// Construir consulta
$where = [];
$params = [];

if (!empty($busqueda)) {
    $where[] = "(e.nombres LIKE ? OR e.apellidos LIKE ? OR e.codigo_estudiante LIKE ?)";
    $like = "%$busqueda%";
    $params = array_merge($params, [$like, $like, $like]);
}

if (!empty($estado)) {
    $where[] = "i.estado = ?";
    $params[] = $estado;
}

if ($programa > 0) {
    $where[] = "p.id_programa = ?";
    $params[] = $programa;
}

if ($semestre > 0) {
    $where[] = "s.id_semestre = ?";
    $params[] = $semestre;
}

if ($curso > 0) {
    $where[] = "c.id_curso = ?";
    $params[] = $curso;
}

$where_clause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

// Contar total
$count_sql = "SELECT COUNT(*) as total
              FROM inscripciones i
              JOIN estudiantes e ON i.id_estudiante = e.id_estudiante
              JOIN horarios h ON i.id_horario = h.id_horario
              JOIN cursos c ON h.id_curso = c.id_curso
              JOIN programas_estudio p ON c.id_programa = p.id_programa
              JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
              $where_clause";

$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total = $stmt->fetch()['total'];
$total_paginas = ceil($total / $limite);

// Obtener inscripciones
$sql = "SELECT i.*, 
               e.codigo_estudiante, e.nombres as estudiante_nombres, e.apellidos as estudiante_apellidos,
               e.semestre_actual as estudiante_semestre,
               c.codigo_curso, c.nombre_curso, c.semestre as curso_semestre, c.creditos,
               p.nombre_programa,
               s.codigo_semestre, s.nombre_semestre,
               h.dia_semana, h.hora_inicio, h.hora_fin, h.grupo,
               CONCAT(pr.nombres, ' ', pr.apellidos) as profesor_nombre
        FROM inscripciones i
        JOIN estudiantes e ON i.id_estudiante = e.id_estudiante
        JOIN horarios h ON i.id_horario = h.id_horario
        JOIN cursos c ON h.id_curso = c.id_curso
        JOIN programas_estudio p ON c.id_programa = p.id_programa
        JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
        JOIN profesores pr ON h.id_profesor = pr.id_profesor
        $where_clause
        ORDER BY i.fecha_inscripcion DESC, i.id_inscripcion DESC
        LIMIT ? OFFSET ?";

$params[] = $limite;
$params[] = $offset;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$inscripciones = $stmt->fetchAll();

// Obtener programas para filtro
$programas = Funciones::obtenerProgramas();

// Obtener semestres para filtro
$semestres = $db->query("
    SELECT id_semestre, codigo_semestre, nombre_semestre 
    FROM semestres_academicos 
    WHERE estado != 'finalizado'
    ORDER BY fecha_inicio DESC
")->fetchAll();

// Obtener cursos para filtro
$cursos = $db->query("
    SELECT c.id_curso, c.codigo_curso, c.nombre_curso, p.nombre_programa
    FROM cursos c
    JOIN programas_estudio p ON c.id_programa = p.id_programa
    WHERE c.activo = 1
    ORDER BY p.nombre_programa, c.nombre_curso
")->fetchAll();

// Estadísticas
$total_inscritos = $db->query("SELECT COUNT(*) as total FROM inscripciones WHERE estado = 'inscrito'")->fetch()['total'];
$total_aprobados = $db->query("SELECT COUNT(*) as total FROM inscripciones WHERE estado = 'aprobado'")->fetch()['total'];
$total_reprobados = $db->query("SELECT COUNT(*) as total FROM inscripciones WHERE estado = 'reprobado'")->fetch()['total'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Inscripciones - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            text-align: center;
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-card h3 {
            font-size: 32px;
            margin: 10px 0;
            color: var(--primary);
        }
        
        .stat-card p {
            color: var(--gray-600);
            font-size: 14px;
        }
        
        .stat-card i {
            font-size: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .filters {
            background: var(--gray-50);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--gray-700);
            font-size: 14px;
        }
        
        .table-responsive {
            overflow-x: auto;
            margin-top: 24px;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
        }
        
        .inscripcion-card {
            background: white;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .inscripcion-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
            border-color: var(--primary-light);
        }
        
        .inscripcion-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .estudiante-info {
            flex: 1;
        }
        
        .estudiante-info h4 {
            color: var(--dark);
            margin: 0 0 5px 0;
            font-size: 16px;
        }
        
        .estudiante-info .codigo {
            color: var(--primary);
            font-weight: 600;
            font-size: 13px;
            background: var(--gray-100);
            padding: 2px 8px;
            border-radius: 12px;
            display: inline-block;
        }
        
        .curso-info {
            flex: 1;
        }
        
        .curso-info h4 {
            color: var(--dark);
            margin: 0 0 5px 0;
            font-size: 16px;
        }
        
        .curso-info .detalles {
            font-size: 13px;
            color: var(--gray-600);
        }
        
        .horario-info {
            background: var(--gray-50);
            padding: 10px 15px;
            border-radius: var(--radius);
            margin: 10px 0;
        }
        
        .horario-info .dia {
            font-weight: 600;
            color: var(--primary);
        }
        
        .horario-info .hora {
            color: var(--dark);
        }
        
        .inscripcion-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--gray-100);
        }
        
        .inscripcion-meta {
            font-size: 13px;
            color: var(--gray-600);
        }
        
        .inscripcion-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .badge-inscrito { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%); color: white; }
        .badge-aprobado { background: linear-gradient(135deg, var(--success) 0%, #34d399 100%); color: white; }
        .badge-reprobado { background: linear-gradient(135deg, var(--danger) 0%, #f87171 100%); color: white; }
        .badge-retirado { background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%); color: white; }
        
        .empty-state {
            text-align: center;
            padding: 60px 40px;
            color: var(--gray-500);
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 24px;
            background: linear-gradient(135deg, var(--gray-300) 0%, var(--gray-400) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .empty-state p {
            font-size: 16px;
            margin-top: 12px;
        }
        
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
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            padding: 20px;
            flex-wrap: wrap;
        }
        
        @media (max-width: 768px) {
            .inscripcion-header {
                flex-direction: column;
                gap: 15px;
            }
            
            .inscripcion-footer {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .inscripcion-actions {
                width: 100%;
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-clipboard-check"></i> Gestión de Inscripciones</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php" class="active"><i class="fas fa-clipboard-check"></i> Inscripciones</a>
                <a href="crear.php" class="btn-primary">
                    <i class="fas fa-plus"></i> Nueva Inscripción
                </a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <!-- Estadísticas -->
        <div class="stats-cards">
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <h3><?php echo $total; ?></h3>
                <p>Total Inscripciones</p>
            </div>
            
            <div class="stat-card">
                <i class="fas fa-check-circle"></i>
                <h3><?php echo $total_inscritos; ?></h3>
                <p>Inscritos Actuales</p>
            </div>
            
            <div class="stat-card">
                <i class="fas fa-graduation-cap"></i>
                <h3><?php echo $total_aprobados; ?></h3>
                <p>Cursos Aprobados</p>
            </div>
            
            <div class="stat-card">
                <i class="fas fa-times-circle"></i>
                <h3><?php echo $total_reprobados; ?></h3>
                <p>Cursos Reprobados</p>
            </div>
        </div>
        
        <!-- Filtros -->
        <form method="GET" class="filters">
            <div class="filter-group">
                <label for="busqueda"><i class="fas fa-search"></i> Buscar Estudiante</label>
                <input type="text" id="busqueda" name="busqueda" class="form-control"
                       value="<?php echo htmlspecialchars($busqueda); ?>"
                       placeholder="Nombre, apellido, código...">
            </div>
            
            <div class="filter-group">
                <label for="estado"><i class="fas fa-circle"></i> Estado</label>
                <select id="estado" name="estado" class="form-control">
                    <option value="">Todos</option>
                    <option value="inscrito" <?php echo $estado === 'inscrito' ? 'selected' : ''; ?>>Inscrito</option>
                    <option value="aprobado" <?php echo $estado === 'aprobado' ? 'selected' : ''; ?>>Aprobado</option>
                    <option value="reprobado" <?php echo $estado === 'reprobado' ? 'selected' : ''; ?>>Reprobado</option>
                    <option value="retirado" <?php echo $estado === 'retirado' ? 'selected' : ''; ?>>Retirado</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="programa"><i class="fas fa-graduation-cap"></i> Programa</label>
                <select id="programa" name="programa" class="form-control">
                    <option value="">Todos los programas</option>
                    <?php foreach ($programas as $p): ?>
                        <option value="<?php echo $p['id_programa']; ?>"
                            <?php echo $programa == $p['id_programa'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['nombre_programa']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="semestre"><i class="fas fa-calendar"></i> Semestre Académico</label>
                <select id="semestre" name="semestre" class="form-control">
                    <option value="">Todos los semestres</option>
                    <?php foreach ($semestres as $s): ?>
                        <option value="<?php echo $s['id_semestre']; ?>"
                            <?php echo $semestre == $s['id_semestre'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['nombre_semestre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="curso"><i class="fas fa-book"></i> Curso</label>
                <select id="curso" name="curso" class="form-control">
                    <option value="">Todos los cursos</option>
                    <?php foreach ($cursos as $c): ?>
                        <option value="<?php echo $c['id_curso']; ?>"
                            <?php echo $curso == $c['id_curso'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['nombre_programa'] . ' - ' . $c['nombre_curso']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Filtrar
                </button>
                <a href="index.php" class="btn btn-secondary" style="margin-top: 5px;">
                    <i class="fas fa-redo"></i> Limpiar
                </a>
            </div>
        </form>
        
        <!-- Lista de inscripciones -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-list"></i> Lista de Inscripciones</h2>
                <div class="actions">
                    <a href="crear.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Nueva Inscripción
                    </a>
                </div>
            </div>
            
            <div class="card-body">
                <?php if (empty($inscripciones)): ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list fa-3x"></i>
                        <h3>No hay inscripciones registradas</h3>
                        <p>Comience inscribiendo estudiantes a horarios disponibles.</p>
                        <a href="crear.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Crear Primera Inscripción
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($inscripciones as $inscripcion): ?>
                        <div class="inscripcion-card">
                            <div class="inscripcion-header">
                                <div class="estudiante-info">
                                    <h4>
                                        <?php echo htmlspecialchars($inscripcion['estudiante_nombres'] . ' ' . $inscripcion['estudiante_apellidos']); ?>
                                        <span class="badge badge-<?php echo $inscripcion['estado']; ?>">
                                            <?php echo ucfirst($inscripcion['estado']); ?>
                                        </span>
                                    </h4>
                                    <div>
                                        <span class="codigo"><?php echo $inscripcion['codigo_estudiante']; ?></span>
                                        <span style="margin-left: 10px; color: var(--gray-600); font-size: 13px;">
                                            <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($inscripcion['nombre_programa']); ?>
                                        </span>
                                        <span style="margin-left: 10px; color: var(--gray-600); font-size: 13px;">
                                            <i class="fas fa-layer-group"></i> Semestre <?php echo $inscripcion['estudiante_semestre']; ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="curso-info">
                                    <h4><?php echo htmlspecialchars($inscripcion['nombre_curso']); ?></h4>
                                    <div class="detalles">
                                        <span><i class="fas fa-hashtag"></i> <?php echo $inscripcion['codigo_curso']; ?></span>
                                        <span style="margin-left: 10px;"><i class="fas fa-star"></i> <?php echo $inscripcion['creditos']; ?> créditos</span>
                                        <span style="margin-left: 10px;"><i class="fas fa-layer-group"></i> Semestre <?php echo $inscripcion['curso_semestre']; ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="horario-info">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <span class="dia"><?php echo $inscripcion['dia_semana']; ?></span>
                                        <span class="hora" style="margin-left: 10px;">
                                            <?php echo Funciones::formatearHora($inscripcion['hora_inicio']); ?> - 
                                            <?php echo Funciones::formatearHora($inscripcion['hora_fin']); ?>
                                        </span>
                                        <?php if ($inscripcion['grupo']): ?>
                                            <span style="margin-left: 10px; color: var(--gray-600);">
                                                <i class="fas fa-users"></i> Grupo <?php echo $inscripcion['grupo']; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="color: var(--gray-600); font-size: 13px;">
                                        <i class="fas fa-chalkboard-teacher"></i> <?php echo htmlspecialchars($inscripcion['profesor_nombre']); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="inscripcion-footer">
                                <div class="inscripcion-meta">
                                    <span><i class="fas fa-calendar"></i> Inscrito: <?php echo Funciones::formatearFecha($inscripcion['fecha_inscripcion']); ?></span>
                                    <span style="margin-left: 15px;">
                                        <i class="fas fa-university"></i> <?php echo htmlspecialchars($inscripcion['nombre_semestre']); ?>
                                    </span>
                                    <?php if ($inscripcion['nota_final'] !== null): ?>
                                        <span style="margin-left: 15px; color: <?php echo $inscripcion['nota_final'] >= 3.0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                                            <i class="fas fa-check-circle"></i> Nota: <?php echo number_format($inscripcion['nota_final'], 1); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="inscripcion-actions">
                                    <?php if ($inscripcion['estado'] == 'inscrito'): ?>
                                        <a href="retirar.php?id=<?php echo $inscripcion['id_inscripcion']; ?>" 
                                           class="btn btn-sm btn-warning" title="Retirar">
                                            <i class="fas fa-sign-out-alt"></i> Retirar
                                        </a>
                                        <a href="calificar.php?id=<?php echo $inscripcion['id_inscripcion']; ?>" 
                                           class="btn btn-sm btn-success" title="Calificar">
                                            <i class="fas fa-graduation-cap"></i> Calificar
                                        </a>
                                    <?php elseif ($inscripcion['estado'] == 'aprobado' || $inscripcion['estado'] == 'reprobado'): ?>
                                        <a href="calificar.php?id=<?php echo $inscripcion['id_inscripcion']; ?>" 
                                           class="btn btn-sm btn-info" title="Ver/Editar Calificación">
                                            <i class="fas fa-edit"></i> Editar Nota
                                        </a>
                                    <?php endif; ?>
                                    
                                    <a href="ver.php?id=<?php echo $inscripcion['id_inscripcion']; ?>" 
                                       class="btn btn-sm btn-primary" title="Ver Detalles">
                                        <i class="fas fa-eye"></i> Ver
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Paginación -->
            <?php if ($total_paginas > 1): ?>
                <div class="pagination">
                    <?php if ($pagina > 1): ?>
                        <a href="?pagina=1<?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?><?php echo !empty($estado) ? '&estado=' . urlencode($estado) : ''; ?><?php echo $programa > 0 ? '&programa=' . $programa : ''; ?><?php echo $semestre > 0 ? '&semestre=' . $semestre : ''; ?><?php echo $curso > 0 ? '&curso=' . $curso : ''; ?>" 
                           class="btn btn-sm btn-secondary">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?pagina=<?php echo $pagina - 1; ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?><?php echo !empty($estado) ? '&estado=' . urlencode($estado) : ''; ?><?php echo $programa > 0 ? '&programa=' . $programa : ''; ?><?php echo $semestre > 0 ? '&semestre=' . $semestre : ''; ?><?php echo $curso > 0 ? '&curso=' . $curso : ''; ?>" 
                           class="btn btn-sm btn-secondary">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $inicio = max(1, $pagina - 2);
                    $fin = min($total_paginas, $pagina + 2);
                    
                    for ($i = $inicio; $i <= $fin; $i++):
                    ?>
                        <a href="?pagina=<?php echo $i; ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?><?php echo !empty($estado) ? '&estado=' . urlencode($estado) : ''; ?><?php echo $programa > 0 ? '&programa=' . $programa : ''; ?><?php echo $semestre > 0 ? '&semestre=' . $semestre : ''; ?><?php echo $curso > 0 ? '&curso=' . $curso : ''; ?>" 
                           class="btn btn-sm <?php echo $i == $pagina ? 'btn-primary' : 'btn-secondary'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($pagina < $total_paginas): ?>
                        <a href="?pagina=<?php echo $pagina + 1; ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?><?php echo !empty($estado) ? '&estado=' . urlencode($estado) : ''; ?><?php echo $programa > 0 ? '&programa=' . $programa : ''; ?><?php echo $semestre > 0 ? '&semestre=' . $semestre : ''; ?><?php echo $curso > 0 ? '&curso=' . $curso : ''; ?>" 
                           class="btn btn-sm btn-secondary">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?pagina=<?php echo $total_paginas; ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?><?php echo !empty($estado) ? '&estado=' . urlencode($estado) : ''; ?><?php echo $programa > 0 ? '&programa=' . $programa : ''; ?><?php echo $semestre > 0 ? '&semestre=' . $semestre : ''; ?><?php echo $curso > 0 ? '&curso=' . $curso : ''; ?>" 
                           class="btn btn-sm btn-secondary">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Exportar -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-download"></i> Exportar Datos</h3>
            </div>
            <div class="card-body">
                <div class="actions">
                    <a href="exportar.php?formato=csv&busqueda=<?php echo urlencode($busqueda); ?>&estado=<?php echo urlencode($estado); ?>&programa=<?php echo $programa; ?>&semestre=<?php echo $semestre; ?>&curso=<?php echo $curso; ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-file-csv"></i> CSV
                    </a>
                    <a href="exportar.php?formato=pdf&busqueda=<?php echo urlencode($busqueda); ?>&estado=<?php echo urlencode($estado); ?>&programa=<?php echo $programa; ?>&semestre=<?php echo $semestre; ?>&curso=<?php echo $curso; ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-file-pdf"></i> PDF
                    </a>
                    <a href="exportar.php?formato=excel&busqueda=<?php echo urlencode($busqueda); ?>&estado=<?php echo urlencode($estado); ?>&programa=<?php echo $programa; ?>&semestre=<?php echo $semestre; ?>&curso=<?php echo $curso; ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-file-excel"></i> Excel
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Confirmación para retirar inscripción
        const retirarBtns = document.querySelectorAll('a[href*="retirar.php"]');
        retirarBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                if (!confirm('¿Está seguro de retirar esta inscripción? Esta acción cambiará el estado a "retirado".')) {
                    e.preventDefault();
                }
            });
        });
        
        // Efecto hover en tarjetas de estadísticas
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
                this.style.boxShadow = 'var(--shadow-lg)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = 'var(--shadow)';
            });
        });
        
        // Efecto hover en inscripciones
        const inscripcionCards = document.querySelectorAll('.inscripcion-card');
        inscripcionCards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.boxShadow = 'var(--shadow-lg)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.boxShadow = 'var(--shadow)';
            });
        });
    });
    </script>
</body>
</html>