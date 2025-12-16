l<?php
require_once '../includes/auth.php';
require_once '../includes/funciones.php';
Auth::requireRole('profesor');

$db = Database::getConnection();
$user = Auth::getUserData();

// Obtener datos del profesor
$stmt = $db->prepare("SELECT * FROM profesores WHERE id_usuario = ?");
$stmt->execute([$user['id']]);
$profesor = $stmt->fetch();

if (!$profesor) {
    Funciones::redireccionar('../login.php', 'Perfil de profesor no encontrado', 'error');
}

// Filtros
$programa = isset($_GET['programa']) ? intval($_GET['programa']) : 0;
$semestre = isset($_GET['semestre']) ? intval($_GET['semestre']) : 0;
$tipo = isset($_GET['tipo']) ? Funciones::sanitizar($_GET['tipo']) : '';

// Consulta base
$sql = "
    SELECT 
        c.id_curso,
        c.codigo_curso,
        c.nombre_curso,
        c.descripcion,
        c.creditos,
        c.horas_semanales,
        c.semestre,
        c.tipo_curso,
        p.nombre_programa,
        p.id_programa,
        COUNT(DISTINCT h.id_horario) as total_horarios,
        COUNT(DISTINCT i.id_estudiante) as total_estudiantes,
        GROUP_CONCAT(DISTINCT CONCAT(h.dia_semana, ' ', TIME_FORMAT(h.hora_inicio, '%h:%i %p'))) as horarios_info,
        MIN(s.fecha_inicio) as fecha_inicio,
        MAX(s.fecha_fin) as fecha_fin,
        AVG(i.nota_final) as promedio_notas
    FROM cursos c
    JOIN programas_estudio p ON c.id_programa = p.id_programa
    JOIN horarios h ON c.id_curso = h.id_curso AND h.id_profesor = ?
    LEFT JOIN inscripciones i ON h.id_horario = i.id_horario AND i.estado IN ('inscrito', 'aprobado')
    LEFT JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
    WHERE h.activo = 1
";

$params = [$profesor['id_profesor']];

// Aplicar filtros
if ($programa > 0) {
    $sql .= " AND p.id_programa = ?";
    $params[] = $programa;
}

if ($semestre > 0) {
    $sql .= " AND c.semestre = ?";
    $params[] = $semestre;
}

if ($tipo && in_array($tipo, ['obligatorio', 'electivo', 'taller', 'laboratorio'])) {
    $sql .= " AND c.tipo_curso = ?";
    $params[] = $tipo;
}

$sql .= " GROUP BY c.id_curso 
          ORDER BY p.nombre_programa, c.semestre, c.nombre_curso";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$cursos = $stmt->fetchAll();

// Obtener programas para filtro
$stmt = $db->prepare("
    SELECT DISTINCT p.id_programa, p.nombre_programa
    FROM programas_estudio p
    JOIN cursos c ON p.id_programa = c.id_programa
    JOIN horarios h ON c.id_curso = h.id_curso
    WHERE h.id_profesor = ? AND h.activo = 1
    ORDER BY p.nombre_programa
");
$stmt->execute([$profesor['id_profesor']]);
$programas_filtro = $stmt->fetchAll();

// Obtener semestres para filtro
$stmt = $db->prepare("
    SELECT DISTINCT c.semestre
    FROM cursos c
    JOIN horarios h ON c.id_curso = h.id_curso
    WHERE h.id_profesor = ? AND h.activo = 1
    ORDER BY c.semestre
");
$stmt->execute([$profesor['id_profesor']]);
$semestres_filtro = $stmt->fetchAll();

// Tipos de curso
$tipos_curso = [
    'obligatorio' => 'Obligatorio',
    'electivo' => 'Electivo', 
    'taller' => 'Taller',
    'laboratorio' => 'Laboratorio'
];

// Calcular estadísticas
$total_cursos = count($cursos);
$total_estudiantes = array_sum(array_column($cursos, 'total_estudiantes'));
$total_horas = array_sum(array_column($cursos, 'horas_semanales'));
$total_creditos = array_sum(array_column($cursos, 'creditos'));

// Promedio de notas
$cursos_con_notas = array_filter($cursos, function($curso) {
    return $curso['promedio_notas'] !== null;
});
$promedio_general = count($cursos_con_notas) > 0 
    ? array_sum(array_column($cursos_con_notas, 'promedio_notas')) / count($cursos_con_notas)
    : 0;

// Colores para los tipos de curso
$colores_tipos = [
    'obligatorio' => '#3B82F6',
    'electivo' => '#10B981',
    'taller' => '#F59E0B',
    'laboratorio' => '#EF4444'
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Cursos - Sistema de Horarios</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.css">
    <style>
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .dashboard-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }
        
        .card-icon::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            z-index: -1;
        }
        
        .card-value {
            font-size: 32px;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 5px;
            line-height: 1;
        }
        
        .card-label {
            font-size: 14px;
            color: var(--gray-600);
            font-weight: 500;
        }
        
        .card-subtitle {
            font-size: 12px;
            color: var(--gray-500);
            margin-top: 8px;
        }
        
        .cursos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }
        
        .curso-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
        }
        
        .curso-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: var(--shadow-xl);
        }
        
        .curso-header {
            padding: 25px;
            position: relative;
            min-height: 140px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .curso-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0.4) 100%);
            z-index: 1;
        }
        
        .curso-codigo {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.9);
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }
        
        .curso-titulo {
            font-size: 20px;
            font-weight: 700;
            color: white;
            line-height: 1.3;
            margin-bottom: 10px;
            position: relative;
            z-index: 2;
        }
        
        .curso-meta {
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
            z-index: 2;
        }
        
        .curso-semestre {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            color: white;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .curso-tipo {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            color: white;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .curso-body {
            padding: 25px;
        }
        
        .curso-descripcion {
            color: var(--gray-700);
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 20px;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .curso-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-200);
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-number {
            font-size: 20px;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 3px;
        }
        
        .stat-label {
            font-size: 11px;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .curso-footer {
            padding: 20px 25px;
            background: var(--gray-50);
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .curso-programa {
            font-size: 13px;
            color: var(--gray-700);
            font-weight: 600;
        }
        
        .curso-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            border: 1px solid var(--gray-300);
            color: var(--gray-700);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-icon:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: translateY(-2px);
        }
        
        .filters-section {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .filter-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-label {
            font-size: 13px;
            color: var(--gray-700);
            font-weight: 600;
        }
        
        .chart-container {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .chart-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .no-courses {
            grid-column: 1 / -1;
            text-align: center;
            padding: 80px 40px;
        }
        
        .no-courses-icon {
            font-size: 80px;
            color: var(--gray-300);
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .progress-circle {
            width: 100px;
            height: 100px;
            position: relative;
            margin: 0 auto 20px;
        }
        
        .progress-circle svg {
            width: 100%;
            height: 100%;
            transform: rotate(-90deg);
        }
        
        .progress-circle-bg {
            fill: none;
            stroke: var(--gray-200);
            stroke-width: 8;
        }
        
        .progress-circle-fill {
            fill: none;
            stroke: var(--primary);
            stroke-width: 8;
            stroke-linecap: round;
            stroke-dasharray: 283;
            stroke-dashoffset: 283;
            transition: stroke-dashoffset 1s ease;
        }
        
        .progress-value {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 24px;
            font-weight: 800;
            color: var(--dark);
        }
        
        .progress-label {
            text-align: center;
            font-size: 14px;
            color: var(--gray-600);
            margin-top: 10px;
        }
        
        .curso-advanced-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 20px;
        }
        
        .advanced-stat {
            background: var(--gray-50);
            border-radius: 12px;
            padding: 15px;
        }
        
        .advanced-stat-label {
            font-size: 11px;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .advanced-stat-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark);
        }
        
        @media (max-width: 768px) {
            .cursos-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-cards {
                grid-template-columns: 1fr;
            }
            
            .curso-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .curso-advanced-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fas fa-book"></i> Mis Cursos</h1>
                <div class="nav">
                    <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    <a href="mis_horarios.php"><i class="fas fa-calendar-alt"></i> Mis Horarios</a>
                    <a href="mis_cursos.php" class="active"><i class="fas fa-book"></i> Mis Cursos</a>
                    <a href="mis_estudiantes.php"><i class="fas fa-user-graduate"></i> Mis Estudiantes</a>
                    <a href="perfil.php"><i class="fas fa-user-circle"></i> Mi Perfil</a>
                    <a href="notificaciones.php"><i class="fas fa-bell"></i> Notificaciones</a>
                    <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Salir</a>
                </div>
            </div>
            <div class="user-info">
                <span><?php echo htmlspecialchars($profesor['nombres'] . ' ' . $profesor['apellidos']); ?></span>
                <span class="badge badge-profesor">Profesor</span>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <!-- Dashboard Cards -->
        <div class="dashboard-cards">
            <div class="dashboard-card">
                <div class="card-icon" style="background: linear-gradient(135deg, #3B82F6 0%, #1D4ED8 100%);">
                    <i class="fas fa-book-open"></i>
                </div>
                <div class="card-value"><?php echo $total_cursos; ?></div>
                <div class="card-label">Cursos Activos</div>
                <div class="card-subtitle"><?php echo $total_creditos; ?> créditos totales</div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-icon" style="background: linear-gradient(135deg, #10B981 0%, #047857 100%);">
                    <i class="fas fa-users"></i>
                </div>
                <div class="card-value"><?php echo $total_estudiantes; ?></div>
                <div class="card-label">Total Estudiantes</div>
                <div class="card-subtitle">
                    Promedio: <?php echo $total_cursos > 0 ? round($total_estudiantes / $total_cursos, 1) : 0; ?> por curso
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-icon" style="background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="card-value"><?php echo $total_horas; ?></div>
                <div class="card-label">Horas Semanales</div>
                <div class="card-subtitle"><?php echo round($total_horas / 6, 1); ?> horas promedio por día</div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-icon" style="background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 100%);">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="card-value"><?php echo number_format($promedio_general, 1); ?></div>
                <div class="card-label">Promedio General</div>
                <div class="card-subtitle"><?php echo count($cursos_con_notas); ?> cursos calificados</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filters-section">
            <h2 style="margin-bottom: 10px; color: var(--dark); display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-filter"></i> Filtros Avanzados
            </h2>
            <p style="color: var(--gray-600); margin-bottom: 20px; font-size: 14px;">
                Filtra tus cursos por diferentes criterios para un análisis detallado
            </p>
            
            <form method="GET" class="filters-grid">
                <div class="filter-item">
                    <label class="filter-label">
                        <i class="fas fa-graduation-cap"></i> Programa de Estudio
                    </label>
                    <select name="programa" class="form-control">
                        <option value="0">Todos los programas</option>
                        <?php foreach ($programas_filtro as $prog): ?>
                            <option value="<?php echo $prog['id_programa']; ?>" <?php echo $programa == $prog['id_programa'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($prog['nombre_programa']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label class="filter-label">
                        <i class="fas fa-layer-group"></i> Semestre
                    </label>
                    <select name="semestre" class="form-control">
                        <option value="0">Todos los semestres</option>
                        <?php foreach ($semestres_filtro as $sem): ?>
                            <option value="<?php echo $sem['semestre']; ?>" <?php echo $semestre == $sem['semestre'] ? 'selected' : ''; ?>>
                                Semestre <?php echo $sem['semestre']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label class="filter-label">
                        <i class="fas fa-tag"></i> Tipo de Curso
                    </label>
                    <select name="tipo" class="form-control">
                        <option value="">Todos los tipos</option>
                        <?php foreach ($tipos_curso as $key => $nombre): ?>
                            <option value="<?php echo $key; ?>" <?php echo $tipo == $key ? 'selected' : ''; ?>>
                                <?php echo $nombre; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-item" style="align-self: flex-end;">
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-search"></i> Aplicar Filtros
                    </button>
                    <a href="mis_cursos.php" class="btn btn-secondary" style="width: 100%; margin-top: 10px;">
                        <i class="fas fa-redo"></i> Limpiar
                    </a>
                </div>
            </form>
        </div>
        
     
        
        <!-- Lista de Cursos -->
        <div class="card" style="border-radius: 16px; overflow: hidden;">
            <div style="padding: 30px; border-bottom: 1px solid var(--gray-200);">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="color: var(--dark); display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-list"></i> Mis Cursos 
                        <span style="font-size: 14px; color: var(--gray-600); font-weight: normal; margin-left: 10px;">
                            (<?php echo $total_cursos; ?> encontrados)
                        </span>
                    </h2>
                    <div style="display: flex; gap: 10px;">
                        <div style="font-size: 13px; color: var(--gray-600); background: var(--gray-100); padding: 8px 15px; border-radius: 20px;">
                            <i class="fas fa-sort-amount-down"></i> Ordenado por: Programa → Semestre
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($total_cursos > 0): ?>
                <div class="cursos-grid" style="padding: 30px;">
                    <?php foreach ($cursos as $curso): 
                        // Determinar color de fondo del header
                        $header_color = $colores_tipos[$curso['tipo_curso']] ?? '#3B82F6';
                        $header_gradient = "linear-gradient(135deg, $header_color 0%, " . ajustarBrillo($header_color, -30) . " 100%)";
                        
                        // Calcular porcentaje de aprobación
                        $porcentaje_aprobacion = $curso['promedio_notas'] !== null 
                            ? min(100, ($curso['promedio_notas'] / 20) * 100)
                            : 0;
                    ?>
                        <div class="curso-card">
                            <div class="curso-header" style="background: <?php echo $header_gradient; ?>;">
                                <div>
                                    <div class="curso-codigo">
                                        <?php echo htmlspecialchars($curso['codigo_curso']); ?>
                                        <span style="float: right;"><?php echo $curso['creditos']; ?> créditos</span>
                                    </div>
                                    <h3 class="curso-titulo"><?php echo htmlspecialchars($curso['nombre_curso']); ?></h3>
                                </div>
                                <div class="curso-meta">
                                    <div class="curso-semestre">
                                        <i class="fas fa-layer-group"></i> Semestre <?php echo $curso['semestre']; ?>
                                    </div>
                                    <div class="curso-tipo">
                                        <i class="fas fa-tag"></i> <?php echo $tipos_curso[$curso['tipo_curso']]; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="curso-body">
                                <?php if ($curso['descripcion']): ?>
                                    <div class="curso-descripcion">
                                        <?php echo nl2br(htmlspecialchars($curso['descripcion'])); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="curso-descripcion" style="color: var(--gray-500); font-style: italic;">
                                        Este curso no tiene descripción disponible.
                                    </div>
                                <?php endif; ?>
                                
                                <div class="curso-stats">
                                    <div class="stat-item">
                                        <div class="stat-number"><?php echo $curso['horas_semanales']; ?></div>
                                        <div class="stat-label">Horas/Sem</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-number"><?php echo $curso['total_horarios']; ?></div>
                                        <div class="stat-label">Horarios</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-number"><?php echo $curso['total_estudiantes']; ?></div>
                                        <div class="stat-label">Estudiantes</div>
                                    </div>
                                </div>
                                
                                <div class="curso-advanced-stats">
                                    <div class="advanced-stat">
                                        <div class="advanced-stat-label">Promedio de Notas</div>
                                        <div class="advanced-stat-value">
                                            <?php if ($curso['promedio_notas'] !== null): ?>
                                                <?php echo number_format($curso['promedio_notas'], 1); ?>/20
                                            <?php else: ?>
                                                <span style="color: var(--gray-500);">Sin calificaciones</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="advanced-stat">
                                        <div class="advanced-stat-label">Horarios Activos</div>
                                        <div class="advanced-stat-value">
                                            <?php 
                                            $horarios_info = $curso['horarios_info'] ? explode(',', $curso['horarios_info']) : [];
                                            echo count($horarios_info);
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="curso-footer">
                                <div class="curso-programa">
                                    <i class="fas fa-graduation-cap"></i> 
                                    <?php echo htmlspecialchars($curso['nombre_programa']); ?>
                                </div>
                                <div class="curso-actions">
                                    <a href="mis_horarios.php?curso=<?php echo $curso['id_curso']; ?>" 
                                       class="btn-icon" 
                                       title="Ver horarios de este curso">
                                        <i class="fas fa-calendar-alt"></i>
                                    </a>
                                    <a href="mis_estudiantes.php?curso=<?php echo $curso['id_curso']; ?>" 
                                       class="btn-icon" 
                                       title="Ver estudiantes inscritos">
                                        <i class="fas fa-users"></i>
                                    </a>
                                    <?php if ($curso['promedio_notas'] !== null): ?>
                                        <a href="#" 
                                           class="btn-icon" 
                                           title="Ver calificaciones"
                                           onclick="alert('Aquí irían las calificaciones del curso')">
                                            <i class="fas fa-chart-bar"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-courses">
                    <div class="no-courses-icon">
                        <i class="fas fa-book-open-reader"></i>
                    </div>
                    <h3 style="color: var(--gray-600); margin-bottom: 10px;">No tienes cursos asignados</h3>
                    <p style="color: var(--gray-500); margin-bottom: 20px;">
                        <?php if ($programa > 0 || $semestre > 0 || $tipo): ?>
                            No se encontraron cursos con los filtros aplicados.
                        <?php else: ?>
                            Contacta al coordinador para que te asignen cursos.
                        <?php endif; ?>
                    </p>
                    <?php if ($programa > 0 || $semestre > 0 || $tipo): ?>
                        <a href="mis_cursos.php" class="btn btn-primary">
                            <i class="fas fa-eye"></i> Ver Todos los Cursos
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
      l
    
    <script src="../assets/js/main.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script>
        // Función para ajustar brillo de colores
        function ajustarBrillo(color, porcentaje) {
            // Implementación simplificada para el ejemplo
            return color;
        }
        
        // Gráfico de distribución por tipo de curso
        <?php if ($total_cursos > 0): 
            // Preparar datos para el gráfico
            $tipos_data = [];
            $tipos_labels = [];
            $tipos_colors = [];
            
            foreach ($tipos_curso as $key => $nombre) {
                $count = 0;
                foreach ($cursos as $curso) {
                    if ($curso['tipo_curso'] == $key) {
                        $count++;
                    }
                }
                if ($count > 0) {
                    $tipos_data[] = $count;
                    $tipos_labels[] = $nombre;
                    $tipos_colors[] = $colores_tipos[$key];
                }
            }
        ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Gráfico de tipos de curso
            const tipoCtx = document.getElementById('tipoCursoChart').getContext('2d');
            const tipoChart = new Chart(tipoCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($tipos_labels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($tipos_data); ?>,
                        backgroundColor: <?php echo json_encode($tipos_colors); ?>,
                        borderColor: 'white',
                        borderWidth: 3,
                        hoverOffset: 15
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                font: {
                                    size: 13
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ${value} cursos (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
            
            // Gráfico de programas
            <?php
            // Preparar datos para gráfico de programas
            $programas_data = [];
            $programas_labels = [];
            $programas_estudiantes = [];
            $programas_horas = [];
            $programa_colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899'];
            
            $programas_agrupados = [];
            foreach ($cursos as $curso) {
                $programa = $curso['nombre_programa'];
                if (!isset($programas_agrupados[$programa])) {
                    $programas_agrupados[$programa] = [
                        'cursos' => 0,
                        'estudiantes' => 0,
                        'horas' => 0
                    ];
                }
                $programas_agrupados[$programa]['cursos']++;
                $programas_agrupados[$programa]['estudiantes'] += $curso['total_estudiantes'];
                $programas_agrupados[$programa]['horas'] += $curso['horas_semanales'];
            }
            
            $i = 0;
            foreach ($programas_agrupados as $nombre => $data) {
                $programas_labels[] = $nombre;
                $programas_data[] = $data['cursos'];
                $programas_estudiantes[] = $data['estudiantes'];
                $programas_horas[] = $data['horas'];
                $i++;
            }
            ?>
            
            const programaCtx = document.getElementById('programaChart').getContext('2d');
            const programaChart = new Chart(programaCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($programas_labels); ?>,
                    datasets: [
                        {
                            label: 'Cursos',
                            data: <?php echo json_encode($programas_data); ?>,
                            backgroundColor: 'rgba(59, 130, 246, 0.7)',
                            borderColor: 'rgb(59, 130, 246)',
                            borderWidth: 1,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Estudiantes',
                            data: <?php echo json_encode($programas_estudiantes); ?>,
                            backgroundColor: 'rgba(16, 185, 129, 0.7)',
                            borderColor: 'rgb(16, 185, 129)',
                            borderWidth: 1,
                            yAxisID: 'y1'
                        },
                        {
                            label: 'Horas Semanales',
                            data: <?php echo json_encode($programas_horas); ?>,
                            backgroundColor: 'rgba(245, 158, 11, 0.7)',
                            borderColor: 'rgb(245, 158, 11)',
                            borderWidth: 1,
                            yAxisID: 'y'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Cursos / Horas'
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Estudiantes'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        },
                        legend: {
                            position: 'top',
                        }
                    }
                }
            });
        });
        <?php endif; ?>
        
        // Animación de tarjetas al cargar
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.curso-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
            
            // Animación de dashboard cards
            const dashboardCards = document.querySelectorAll('.dashboard-card');
            dashboardCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'scale(0.9)';
                
                setTimeout(() => {
                    card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'scale(1)';
                }, index * 150);
            });
        });
    </script>
</body>
</html>

<?php
// Función para ajustar brillo de color (usada en PHP para el gradiente)
function ajustarBrillo($color, $porcentaje) {
    // Convierte hex a RGB
    $color = str_replace('#', '', $color);
    if (strlen($color) == 3) {
        $color = $color[0].$color[0].$color[1].$color[1].$color[2].$color[2];
    }
    
    $r = hexdec(substr($color, 0, 2));
    $g = hexdec(substr($color, 2, 2));
    $b = hexdec(substr($color, 4, 2));
    
    // Ajusta brillo
    $r = max(0, min(255, $r + $r * $porcentaje / 100));
    $g = max(0, min(255, $g + $g * $porcentaje / 100));
    $b = max(0, min(255, $b + $b * $porcentaje / 100));
    
    // Convierte de nuevo a hex
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}
?>