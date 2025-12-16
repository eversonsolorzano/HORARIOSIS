<?php
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
$curso_id = isset($_GET['curso']) ? intval($_GET['curso']) : 0;
$horario_id = isset($_GET['horario']) ? intval($_GET['horario']) : 0;
$programa_id = isset($_GET['programa']) ? intval($_GET['programa']) : 0;
$estado = isset($_GET['estado']) ? Funciones::sanitizar($_GET['estado']) : '';

// Consulta base de estudiantes
$sql = "
    SELECT DISTINCT
           e.id_estudiante,
           e.codigo_estudiante,
           e.nombres,
           e.apellidos,
           e.documento_identidad,
           e.semestre_actual,
           e.estado,
           p.nombre_programa,
           p.id_programa,
           u.email,
           c.nombre_curso,
           c.id_curso,
           h.dia_semana,
           h.hora_inicio,
           h.hora_fin,
           a.codigo_aula,
           i.fecha_inscripcion,
           i.nota_final,
           i.estado as estado_inscripcion
    FROM estudiantes e
    JOIN usuarios u ON e.id_usuario = u.id_usuario
    JOIN programas_estudio p ON e.id_programa = p.id_programa
    JOIN inscripciones i ON e.id_estudiante = i.id_estudiante
    JOIN horarios h ON i.id_horario = h.id_horario
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN aulas a ON h.id_aula = a.id_aula
    WHERE h.id_profesor = ? 
      AND h.activo = 1
      AND i.estado IN ('inscrito', 'aprobado', 'reprobado')
";

$params = [$profesor['id_profesor']];

// Aplicar filtros
if ($curso_id > 0) {
    $sql .= " AND c.id_curso = ?";
    $params[] = $curso_id;
}

if ($horario_id > 0) {
    $sql .= " AND h.id_horario = ?";
    $params[] = $horario_id;
}

if ($programa_id > 0) {
    $sql .= " AND p.id_programa = ?";
    $params[] = $programa_id;
}

if ($estado && in_array($estado, ['inscrito', 'aprobado', 'reprobado'])) {
    $sql .= " AND i.estado = ?";
    $params[] = $estado;
}

$sql .= " ORDER BY e.apellidos, e.nombres";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$estudiantes_raw = $stmt->fetchAll();

// Procesar datos para agrupar por estudiante
$estudiantes = [];
foreach ($estudiantes_raw as $row) {
    $id = $row['id_estudiante'];
    if (!isset($estudiantes[$id])) {
        $estudiantes[$id] = [
            'id_estudiante' => $row['id_estudiante'],
            'codigo_estudiante' => $row['codigo_estudiante'],
            'nombres' => $row['nombres'],
            'apellidos' => $row['apellidos'],
            'documento_identidad' => $row['documento_identidad'],
            'semestre_actual' => $row['semestre_actual'],
            'estado' => $row['estado'],
            'nombre_programa' => $row['nombre_programa'],
            'id_programa' => $row['id_programa'],
            'email' => $row['email'],
            'cursos' => [],
            'cursos_aprobados' => 0,
            'cursos_reprobados' => 0,
            'cursos_inscritos' => 0,
            'promedio_general' => 0
        ];
    }
    
    $estudiantes[$id]['cursos'][] = [
        'id_curso' => $row['id_curso'],
        'nombre_curso' => $row['nombre_curso'],
        'dia_semana' => $row['dia_semana'],
        'hora_inicio' => $row['hora_inicio'],
        'hora_fin' => $row['hora_fin'],
        'codigo_aula' => $row['codigo_aula'],
        'fecha_inscripcion' => $row['fecha_inscripcion'],
        'nota_final' => $row['nota_final'],
        'estado_inscripcion' => $row['estado_inscripcion']
    ];
    
    // Contar cursos por estado
    if ($row['estado_inscripcion'] == 'aprobado') {
        $estudiantes[$id]['cursos_aprobados']++;
        if ($row['nota_final'] !== null) {
            $estudiantes[$id]['promedio_general'] += $row['nota_final'];
        }
    } elseif ($row['estado_inscripcion'] == 'reprobado') {
        $estudiantes[$id]['cursos_reprobados']++;
    } elseif ($row['estado_inscripcion'] == 'inscrito') {
        $estudiantes[$id]['cursos_inscritos']++;
    }
}

// Calcular promedios
foreach ($estudiantes as $id => $estudiante) {
    $total_cursos_calificados = $estudiante['cursos_aprobados'] + $estudiante['cursos_reprobados'];
    if ($total_cursos_calificados > 0) {
        $estudiantes[$id]['promedio_general'] = $estudiante['promedio_general'] / $total_cursos_calificados;
    }
}

// Obtener filtros disponibles
$stmt = $db->prepare("
    SELECT DISTINCT c.id_curso, c.nombre_curso
    FROM cursos c
    JOIN horarios h ON c.id_curso = h.id_curso
    WHERE h.id_profesor = ? AND h.activo = 1
    ORDER BY c.nombre_curso
");
$stmt->execute([$profesor['id_profesor']]);
$cursos_filtro = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT DISTINCT p.id_programa, p.nombre_programa
    FROM programas_estudio p
    JOIN estudiantes e ON p.id_programa = e.id_programa
    JOIN inscripciones i ON e.id_estudiante = i.id_estudiante
    JOIN horarios h ON i.id_horario = h.id_horario
    WHERE h.id_profesor = ? AND h.activo = 1
    ORDER BY p.nombre_programa
");
$stmt->execute([$profesor['id_profesor']]);
$programas_filtro = $stmt->fetchAll();

// Obtener horarios para filtro
$stmt = $db->prepare("
    SELECT DISTINCT h.id_horario, c.nombre_curso, h.dia_semana, 
           TIME_FORMAT(h.hora_inicio, '%h:%i %p') as hora_inicio_formatted
    FROM horarios h
    JOIN cursos c ON h.id_curso = c.id_curso
    WHERE h.id_profesor = ? AND h.activo = 1
    ORDER BY h.dia_semana, h.hora_inicio
");
$stmt->execute([$profesor['id_profesor']]);
$horarios_filtro = $stmt->fetchAll();

// CORRECCIÓN: Calcular estadísticas manualmente en lugar de usar array_column con closure
$total_estudiantes = count($estudiantes);
$total_cursos = count(array_unique(array_column($estudiantes_raw, 'nombre_curso')));

// Calcular totales de estados manualmente
$total_inscritos = 0;
$total_aprobados = 0;
$total_reprobados = 0;

foreach ($estudiantes_raw as $row) {
    if ($row['estado_inscripcion'] == 'inscrito') {
        $total_inscritos++;
    } elseif ($row['estado_inscripcion'] == 'aprobado') {
        $total_aprobados++;
    } elseif ($row['estado_inscripcion'] == 'reprobado') {
        $total_reprobados++;
    }
}

// Colores por programa (para diferenciar visualmente)
$colores_programas = [
    'Topografía' => '#3B82F6',
    'Arquitectura' => '#10B981', 
    'Enfermería' => '#8B5CF6'
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Estudiantes - Sistema de Horarios</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
        
        .estudiantes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }
        
        .estudiante-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
        }
        
        .estudiante-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: var(--shadow-xl);
        }
        
        .estudiante-header {
            padding: 25px;
            position: relative;
            min-height: 140px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .estudiante-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0.4) 100%);
            z-index: 1;
        }
        
        .estudiante-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: white;
            font-weight: 700;
            position: relative;
            z-index: 2;
            margin-bottom: 15px;
        }
        
        .estudiante-info {
            position: relative;
            z-index: 2;
        }
        
        .estudiante-nombre {
            font-size: 20px;
            font-weight: 700;
            color: white;
            line-height: 1.3;
            margin-bottom: 5px;
        }
        
        .estudiante-codigo {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.9);
            font-weight: 600;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .estudiante-meta {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .estudiante-tag {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .estudiante-body {
            padding: 25px;
        }
        
        .estudiante-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .estudiante-stat {
            text-align: center;
            padding: 15px;
            background: var(--gray-50);
            border-radius: 12px;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .estudiante-cursos {
            margin-top: 20px;
        }
        
        .cursos-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .cursos-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .curso-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            background: var(--gray-50);
            border-radius: 10px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .curso-item:hover {
            background: var(--gray-100);
            transform: translateX(5px);
        }
        
        .curso-info {
            flex: 1;
        }
        
        .curso-nombre {
            font-size: 14px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 3px;
        }
        
        .curso-detalle {
            font-size: 12px;
            color: var(--gray-600);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .curso-estado {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .estado-aprobado {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.2) 100%);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .estado-reprobado {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(239, 68, 68, 0.2) 100%);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .estado-inscrito {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(59, 130, 246, 0.2) 100%);
            color: var(--primary);
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        
        .curso-nota {
            font-size: 18px;
            font-weight: 800;
            color: var(--dark);
            min-width: 50px;
            text-align: right;
        }
        
        .estudiante-footer {
            padding: 20px 25px;
            background: var(--gray-50);
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .estudiante-email {
            font-size: 13px;
            color: var(--gray-700);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .estudiante-actions {
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
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .no-estudiantes {
            grid-column: 1 / -1;
            text-align: center;
            padding: 80px 40px;
        }
        
        .no-estudiantes-icon {
            font-size: 80px;
            color: var(--gray-300);
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .ver-mas-btn {
            width: 100%;
            padding: 12px;
            background: var(--gray-100);
            border: none;
            border-radius: 10px;
            color: var(--gray-700);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            margin-top: 15px;
        }
        
        .ver-mas-btn:hover {
            background: var(--gray-200);
            color: var(--dark);
        }
        
        .cursos-container {
            max-height: 300px;
            overflow-y: auto;
            padding-right: 5px;
        }
        
        .cursos-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .cursos-container::-webkit-scrollbar-track {
            background: var(--gray-100);
            border-radius: 3px;
        }
        
        .cursos-container::-webkit-scrollbar-thumb {
            background: var(--gray-400);
            border-radius: 3px;
        }
        
        @media (max-width: 768px) {
            .estudiantes-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-cards {
                grid-template-columns: 1fr;
            }
            
            .estudiante-stats {
                grid-template-columns: 1fr;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .estudiante-meta {
                flex-direction: column;
                gap: 8px;
            }
        }
        
        .progress-circle {
            width: 80px;
            height: 80px;
            position: relative;
            margin: 0 auto 15px;
        }
        
        .progress-circle svg {
            width: 100%;
            height: 100%;
            transform: rotate(-90deg);
        }
        
        .progress-circle-bg {
            fill: none;
            stroke: var(--gray-200);
            stroke-width: 6;
        }
        
        .progress-circle-fill {
            fill: none;
            stroke: var(--primary);
            stroke-width: 6;
            stroke-linecap: round;
            stroke-dasharray: 251;
            stroke-dashoffset: 251;
            transition: stroke-dashoffset 1s ease;
        }
        
        .progress-value {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 18px;
            font-weight: 800;
            color: var(--dark);
        }
        
        .progress-label {
            text-align: center;
            font-size: 12px;
            color: var(--gray-600);
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fas fa-user-graduate"></i> Mis Estudiantes</h1>
                <div class="nav">
                    <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    <a href="mis_horarios.php"><i class="fas fa-calendar-alt"></i> Mis Horarios</a>
                    <a href="mis_cursos.php"><i class="fas fa-book"></i> Mis Cursos</a>
                    <a href="mis_estudiantes.php" class="active"><i class="fas fa-user-graduate"></i> Mis Estudiantes</a>
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
                    <i class="fas fa-users"></i>
                </div>
                <div class="card-value"><?php echo $total_estudiantes; ?></div>
                <div class="card-label">Estudiantes Totales</div>
                <div class="card-subtitle">
                    <?php echo $total_cursos; ?> cursos diferentes
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-icon" style="background: linear-gradient(135deg, #10B981 0%, #047857 100%);">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="card-value"><?php echo $total_aprobados; ?></div>
                <div class="card-label">Cursos Aprobados</div>
                <div class="card-subtitle">
                    <?php echo $total_estudiantes > 0 ? round($total_aprobados / $total_estudiantes, 1) : 0; ?> por estudiante
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-icon" style="background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);">
                    <i class="fas fa-user-clock"></i>
                </div>
                <div class="card-value"><?php echo $total_inscritos; ?></div>
                <div class="card-label">Cursos Inscritos</div>
                <div class="card-subtitle">
                    En proceso de evaluación
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-icon" style="background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 100%);">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="card-value">
                    <?php 
                    // Calcular promedio total manualmente
                    $promedio_total = 0;
                    $estudiantes_con_promedio = 0;
                    foreach ($estudiantes as $estudiante) {
                        if ($estudiante['promedio_general'] > 0) {
                            $promedio_total += $estudiante['promedio_general'];
                            $estudiantes_con_promedio++;
                        }
                    }
                    echo $estudiantes_con_promedio > 0 ? number_format($promedio_total / $estudiantes_con_promedio, 1) : '0.0';
                    ?>
                </div>
                <div class="card-label">Promedio General</div>
                <div class="card-subtitle">
                    <?php echo $estudiantes_con_promedio; ?> estudiantes calificados
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filters-section">
            <h2 style="margin-bottom: 10px; color: var(--dark); display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-filter"></i> Filtros Avanzados
            </h2>
            <p style="color: var(--gray-600); margin-bottom: 20px; font-size: 14px;">
                Filtra tus estudiantes por diferentes criterios para un análisis detallado
            </p>
            
            <form method="GET" class="filters-grid">
                <div class="filter-item">
                    <label class="filter-label">
                        <i class="fas fa-book"></i> Curso
                    </label>
                    <select name="curso" class="form-control">
                        <option value="0">Todos los cursos</option>
                        <?php foreach ($cursos_filtro as $curso): ?>
                            <option value="<?php echo $curso['id_curso']; ?>" <?php echo $curso_id == $curso['id_curso'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($curso['nombre_curso']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label class="filter-label">
                        <i class="fas fa-graduation-cap"></i> Programa
                    </label>
                    <select name="programa" class="form-control">
                        <option value="0">Todos los programas</option>
                        <?php foreach ($programas_filtro as $programa): ?>
                            <option value="<?php echo $programa['id_programa']; ?>" <?php echo $programa_id == $programa['id_programa'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($programa['nombre_programa']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label class="filter-label">
                        <i class="fas fa-calendar-alt"></i> Horario
                    </label>
                    <select name="horario" class="form-control">
                        <option value="0">Todos los horarios</option>
                        <?php foreach ($horarios_filtro as $horario): ?>
                            <option value="<?php echo $horario['id_horario']; ?>" <?php echo $horario_id == $horario['id_horario'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($horario['nombre_curso']); ?> - 
                                <?php echo $horario['dia_semana']; ?> <?php echo $horario['hora_inicio_formatted']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-item">
                    <label class="filter-label">
                        <i class="fas fa-tag"></i> Estado
                    </label>
                    <select name="estado" class="form-control">
                        <option value="">Todos los estados</option>
                        <option value="inscrito" <?php echo $estado == 'inscrito' ? 'selected' : ''; ?>>Inscrito</option>
                        <option value="aprobado" <?php echo $estado == 'aprobado' ? 'selected' : ''; ?>>Aprobado</option>
                        <option value="reprobado" <?php echo $estado == 'reprobado' ? 'selected' : ''; ?>>Reprobado</option>
                    </select>
                </div>
                
                <div class="filter-item" style="align-self: flex-end;">
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-search"></i> Aplicar Filtros
                    </button>
                    <a href="mis_estudiantes.php" class="btn btn-secondary" style="width: 100%; margin-top: 10px;">
                        <i class="fas fa-redo"></i> Limpiar
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Lista de Estudiantes -->
        <div class="card" style="border-radius: 16px; overflow: hidden;">
            <div style="padding: 30px; border-bottom: 1px solid var(--gray-200);">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="color: var(--dark); display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-list"></i> Mis Estudiantes 
                        <span style="font-size: 14px; color: var(--gray-600); font-weight: normal; margin-left: 10px;">
                            (<?php echo $total_estudiantes; ?> encontrados)
                        </span>
                    </h2>
                    <div style="font-size: 13px; color: var(--gray-600); background: var(--gray-100); padding: 8px 15px; border-radius: 20px;">
                        <i class="fas fa-sort-alpha-down"></i> Ordenado por: Apellidos
                    </div>
                </div>
            </div>
            
            <?php if ($total_estudiantes > 0): ?>
                <div class="estudiantes-grid" style="padding: 30px;">
                    <?php foreach ($estudiantes as $estudiante): 
                        // Determinar color de fondo del header según programa
                        $header_color = isset($colores_programas[$estudiante['nombre_programa']]) 
                            ? $colores_programas[$estudiante['nombre_programa']] 
                            : '#3B82F6';
                        $header_gradient = "linear-gradient(135deg, $header_color 0%, " . ajustarBrillo($header_color, -30) . " 100%)";
                        
                        // Calcular porcentaje de aprobación
                        $total_cursos_estudiante = count($estudiante['cursos']);
                        $porcentaje_aprobacion = $total_cursos_estudiante > 0 
                            ? ($estudiante['cursos_aprobados'] / $total_cursos_estudiante) * 100
                            : 0;
                        
                        // Iniciales para avatar
                        $iniciales = substr($estudiante['nombres'], 0, 1) . substr($estudiante['apellidos'], 0, 1);
                    ?>
                        <div class="estudiante-card">
                            <div class="estudiante-header" style="background: <?php echo $header_gradient; ?>;">
                                <div class="estudiante-avatar">
                                    <?php echo strtoupper($iniciales); ?>
                                </div>
                                
                                <div class="estudiante-info">
                                    <h3 class="estudiante-nombre">
                                        <?php echo htmlspecialchars($estudiante['apellidos'] . ', ' . $estudiante['nombres']); ?>
                                    </h3>
                                    
                                    <div class="estudiante-codigo">
                                        <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($estudiante['codigo_estudiante']); ?>
                                    </div>
                                    
                                    <div class="estudiante-meta">
                                        <div class="estudiante-tag">
                                            <i class="fas fa-graduation-cap"></i> 
                                            <?php echo htmlspecialchars($estudiante['nombre_programa']); ?>
                                        </div>
                                        <div class="estudiante-tag">
                                            <i class="fas fa-layer-group"></i> 
                                            Semestre <?php echo $estudiante['semestre_actual']; ?>
                                        </div>
                                        <div class="estudiante-tag">
                                            <i class="fas fa-circle" style="font-size: 8px; color: 
                                                <?php echo $estudiante['estado'] == 'activo' ? '#10B981' : 
                                                       ($estudiante['estado'] == 'graduado' ? '#3B82F6' : '#F59E0B'); ?>">
                                            </i> 
                                            <?php echo ucfirst($estudiante['estado']); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="estudiante-body">
                                <div class="estudiante-stats">
                                    <div class="estudiante-stat">
                                        <div class="stat-number"><?php echo $total_cursos_estudiante; ?></div>
                                        <div class="stat-label">Cursos Totales</div>
                                    </div>
                                    
                                    <div class="estudiante-stat">
                                        <div class="progress-circle">
                                            <svg viewBox="0 0 100 100">
                                                <circle class="progress-circle-bg" cx="50" cy="50" r="40"></circle>
                                                <circle class="progress-circle-fill" cx="50" cy="50" r="40" 
                                                        stroke-dashoffset="<?php echo 251 - ($porcentaje_aprobacion * 2.51); ?>">
                                                </circle>
                                            </svg>
                                            <div class="progress-value"><?php echo round($porcentaje_aprobacion); ?>%</div>
                                        </div>
                                        <div class="progress-label">Tasa de Aprobación</div>
                                    </div>
                                    
                                    <div class="estudiante-stat">
                                        <div class="stat-number">
                                            <?php echo number_format($estudiante['promedio_general'], 1); ?>
                                        </div>
                                        <div class="stat-label">Promedio General</div>
                                    </div>
                                    
                                    <div class="estudiante-stat">
                                        <div class="stat-number"><?php echo $estudiante['cursos_inscritos']; ?></div>
                                        <div class="stat-label">Cursos Inscritos</div>
                                    </div>
                                </div>
                                
                                <div class="estudiante-cursos">
                                    <div class="cursos-header">
                                        <h4 class="cursos-title">
                                            <i class="fas fa-book-open"></i> Cursos con este profesor
                                        </h4>
                                        <span style="font-size: 12px; color: var(--gray-600);">
                                            <?php echo $total_cursos_estudiante; ?> cursos
                                        </span>
                                    </div>
                                    
                                    <div class="cursos-container">
                                        <?php 
                                        // Mostrar solo los primeros 3 cursos
                                        $cursos_mostrados = array_slice($estudiante['cursos'], 0, 3);
                                        foreach ($cursos_mostrados as $curso):
                                        ?>
                                            <div class="curso-item">
                                                <div class="curso-info">
                                                    <div class="curso-nombre">
                                                        <?php echo htmlspecialchars($curso['nombre_curso']); ?>
                                                    </div>
                                                    <div class="curso-detalle">
                                                        <span>
                                                            <i class="far fa-calendar"></i> 
                                                            <?php echo $curso['dia_semana']; ?>
                                                        </span>
                                                        <span>
                                                            <i class="far fa-clock"></i> 
                                                            <?php echo Funciones::formatearHora($curso['hora_inicio']); ?> - 
                                                            <?php echo Funciones::formatearHora($curso['hora_fin']); ?>
                                                        </span>
                                                        <span>
                                                            <i class="fas fa-door-open"></i> 
                                                            <?php echo htmlspecialchars($curso['codigo_aula']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div style="display: flex; align-items: center; gap: 15px;">
                                                    <div class="curso-estado estado-<?php echo $curso['estado_inscripcion']; ?>">
                                                        <?php echo $curso['estado_inscripcion']; ?>
                                                    </div>
                                                    <?php if ($curso['nota_final'] !== null): ?>
                                                        <div class="curso-nota">
                                                            <?php echo number_format($curso['nota_final'], 1); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        
                                        <?php if (count($estudiante['cursos']) > 3): ?>
                                            <button class="ver-mas-btn" onclick="mostrarMasCursos(<?php echo $estudiante['id_estudiante']; ?>, this)">
                                                <i class="fas fa-chevron-down"></i>
                                                Ver <?php echo count($estudiante['cursos']) - 3; ?> cursos más
                                            </button>
                                            <div id="cursos-extra-<?php echo $estudiante['id_estudiante']; ?>" style="display: none;">
                                                <?php 
                                                $cursos_extra = array_slice($estudiante['cursos'], 3);
                                                foreach ($cursos_extra as $curso):
                                                ?>
                                                    <div class="curso-item">
                                                        <div class="curso-info">
                                                            <div class="curso-nombre">
                                                                <?php echo htmlspecialchars($curso['nombre_curso']); ?>
                                                            </div>
                                                            <div class="curso-detalle">
                                                                <span>
                                                                    <i class="far fa-calendar"></i> 
                                                                    <?php echo $curso['dia_semana']; ?>
                                                                </span>
                                                                <span>
                                                                    <i class="far fa-clock"></i> 
                                                                    <?php echo Funciones::formatearHora($curso['hora_inicio']); ?> - 
                                                                    <?php echo Funciones::formatearHora($curso['hora_fin']); ?>
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <div style="display: flex; align-items: center; gap: 15px;">
                                                            <div class="curso-estado estado-<?php echo $curso['estado_inscripcion']; ?>">
                                                                <?php echo $curso['estado_inscripcion']; ?>
                                                            </div>
                                                            <?php if ($curso['nota_final'] !== null): ?>
                                                                <div class="curso-nota">
                                                                    <?php echo number_format($curso['nota_final'], 1); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="estudiante-footer">
                                <div class="estudiante-email">
                                    <i class="fas fa-envelope"></i> 
                                    <?php echo htmlspecialchars($estudiante['email']); ?>
                                </div>
                                <div class="estudiante-actions">
                                    <a href="#" 
                                       class="btn-icon" 
                                       title="Enviar mensaje"
                                       onclick="alert('Aquí se abriría el formulario para enviar mensaje a ' + '<?php echo htmlspecialchars($estudiante['email']); ?>')">
                                        <i class="fas fa-envelope"></i>
                                    </a>
                                    <a href="#" 
                                       class="btn-icon" 
                                       title="Ver perfil completo"
                                       onclick="alert('Aquí se vería el perfil completo del estudiante')">
                                        <i class="fas fa-user"></i>
                                    </a>
                                    <a href="mis_horarios.php?estudiante=<?php echo $estudiante['id_estudiante']; ?>" 
                                       class="btn-icon" 
                                       title="Ver horarios compartidos">
                                        <i class="fas fa-calendar-alt"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-estudiantes">
                    <div class="no-estudiantes-icon">
                        <i class="fas fa-user-slash"></i>
                    </div>
                    <h3 style="color: var(--gray-600); margin-bottom: 10px;">No tienes estudiantes asignados</h3>
                    <p style="color: var(--gray-500); margin-bottom: 20px;">
                        <?php if ($curso_id > 0 || $programa_id > 0 || $horario_id > 0 || $estado): ?>
                            No se encontraron estudiantes con los filtros aplicados.
                        <?php else: ?>
                            No hay estudiantes inscritos en tus cursos actualmente.
                        <?php endif; ?>
                    </p>
                    <?php if ($curso_id > 0 || $programa_id > 0 || $horario_id > 0 || $estado): ?>
                        <a href="mis_estudiantes.php" class="btn btn-primary">
                            <i class="fas fa-eye"></i> Ver Todos los Estudiantes
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="../assets/js/main.js"></script>
    <script>
        // Función para ajustar brillo de colores
        function ajustarBrillo(color, porcentaje) {
            return color;
        }
        
        // Mostrar más cursos
        function mostrarMasCursos(estudianteId, boton) {
            const container = document.getElementById('cursos-extra-' + estudianteId);
            const icon = boton.querySelector('i');
            
            if (container.style.display === 'none' || !container.style.display) {
                container.style.display = 'block';
                icon.className = 'fas fa-chevron-up';
                boton.innerHTML = '<i class="fas fa-chevron-up"></i> Ocultar cursos';
            } else {
                container.style.display = 'none';
                icon.className = 'fas fa-chevron-down';
                boton.innerHTML = '<i class="fas fa-chevron-down"></i> Ver cursos más';
            }
        }
        
        // Animación de tarjetas al cargar
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.estudiante-card');
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
            
            // Animación de círculos de progreso
            const progressCircles = document.querySelectorAll('.progress-circle-fill');
            progressCircles.forEach(circle => {
                const offset = circle.getAttribute('stroke-dashoffset');
                circle.style.strokeDashoffset = offset;
            });
        });
    </script>
</body>
</html>

<?php
// Función para ajustar brillo de color
function ajustarBrillo($color, $porcentaje) {
    $color = str_replace('#', '', $color);
    if (strlen($color) == 3) {
        $color = $color[0].$color[0].$color[1].$color[1].$color[2].$color[2];
    }
    
    $r = hexdec(substr($color, 0, 2));
    $g = hexdec(substr($color, 2, 2));
    $b = hexdec(substr($color, 4, 2));
    
    $r = max(0, min(255, $r + $r * $porcentaje / 100));
    $g = max(0, min(255, $g + $g * $porcentaje / 100));
    $b = max(0, min(255, $b + $b * $porcentaje / 100));
    
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}
?>