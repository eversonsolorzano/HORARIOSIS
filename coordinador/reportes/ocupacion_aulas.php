<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

// Parámetros de filtro
$id_semestre = isset($_GET['semestre']) ? intval($_GET['semestre']) : 0;
$dia_semana = isset($_GET['dia']) ? Funciones::sanitizar($_GET['dia']) : '';
$tipo_aula = isset($_GET['tipo_aula']) ? Funciones::sanitizar($_GET['tipo_aula']) : '';
$edificio = isset($_GET['edificio']) ? Funciones::sanitizar($_GET['edificio']) : '';

// Obtener semestres para filtro
$semestres = $db->query("
    SELECT id_semestre, codigo_semestre, nombre_semestre 
    FROM semestres_academicos 
    WHERE estado != 'finalizado'
    ORDER BY fecha_inicio DESC
")->fetchAll();

// Días de la semana
$dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

// Tipos de aula
$tipos_aula = $db->query("SELECT DISTINCT tipo_aula FROM aulas WHERE tipo_aula IS NOT NULL ORDER BY tipo_aula")->fetchAll();

// Edificios
$edificios = $db->query("SELECT DISTINCT edificio FROM aulas WHERE edificio IS NOT NULL AND edificio != '' ORDER BY edificio")->fetchAll();

// Construir consulta para aulas
$where_aulas = ["a.disponible = 1"];
$params_aulas = [];

if (!empty($tipo_aula)) {
    $where_aulas[] = "a.tipo_aula = ?";
    $params_aulas[] = $tipo_aula;
}

if (!empty($edificio)) {
    $where_aulas[] = "a.edificio = ?";
    $params_aulas[] = $edificio;
}

$where_aulas_clause = !empty($where_aulas) ? 'WHERE ' . implode(' AND ', $where_aulas) : '';

// Obtener aulas
$sql_aulas = "
    SELECT a.*,
           COUNT(DISTINCT h.id_horario) as total_horarios,
           COUNT(DISTINCT h.id_profesor) as total_profesores,
           COUNT(DISTINCT c.id_programa) as total_programas
    FROM aulas a
    LEFT JOIN horarios h ON a.id_aula = h.id_aula AND h.activo = 1
    LEFT JOIN cursos c ON h.id_curso = c.id_curso
    LEFT JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
    $where_aulas_clause
    GROUP BY a.id_aula
    ORDER BY a.edificio, a.piso, a.nombre_aula
";

$stmt_aulas = $db->prepare($sql_aulas);
$stmt_aulas->execute($params_aulas);
$aulas = $stmt_aulas->fetchAll();

// Construir consulta para horarios por aula
$where_horarios = ["h.activo = 1"];
$params_horarios = [];

if ($id_semestre > 0) {
    $where_horarios[] = "s.id_semestre = ?";
    $params_horarios[] = $id_semestre;
}

if (!empty($dia_semana)) {
    $where_horarios[] = "h.dia_semana = ?";
    $params_horarios[] = $dia_semana;
}

if (!empty($tipo_aula)) {
    $where_horarios[] = "a.tipo_aula = ?";
    $params_horarios[] = $tipo_aula;
}

if (!empty($edificio)) {
    $where_horarios[] = "a.edificio = ?";
    $params_horarios[] = $edificio;
}

$where_horarios_clause = !empty($where_horarios) ? 'WHERE ' . implode(' AND ', $where_horarios) : '';

// Obtener horarios para análisis de ocupación
$sql_horarios = "
    SELECT a.id_aula, a.nombre_aula, a.capacidad,
           h.dia_semana, h.hora_inicio, h.hora_fin,
           c.nombre_curso,
           p.nombre_programa,
           CONCAT(pr.nombres, ' ', pr.apellidos) as profesor_nombre,
           COUNT(DISTINCT i.id_inscripcion) as estudiantes_inscritos
    FROM aulas a
    JOIN horarios h ON a.id_aula = h.id_aula
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN programas_estudio p ON c.id_programa = p.id_programa
    JOIN profesores pr ON h.id_profesor = pr.id_profesor
    LEFT JOIN inscripciones i ON h.id_horario = i.id_horario AND i.estado = 'inscrito'
    JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
    $where_horarios_clause
    GROUP BY h.id_horario
    ORDER BY a.nombre_aula, h.dia_semana, h.hora_inicio
";

$stmt_horarios = $db->prepare($sql_horarios);
$stmt_horarios->execute($params_horarios);
$horarios = $stmt_horarios->fetchAll();

// Organizar horarios por aula
$horarios_por_aula = [];
foreach ($horarios as $horario) {
    $aula_id = $horario['id_aula'];
    if (!isset($horarios_por_aula[$aula_id])) {
        $horarios_por_aula[$aula_id] = [];
    }
    $horarios_por_aula[$aula_id][] = $horario;
}

// Calcular estadísticas
$total_aulas = count($aulas);
$aulas_ocupadas = 0;
$aulas_libres = 0;
$capacidad_total = 0;
$ocupacion_promedio = 0;

foreach ($aulas as $aula) {
    $capacidad_total += $aula['capacidad'];
    
    if ($aula['total_horarios'] > 0) {
        $aulas_ocupadas++;
    } else {
        $aulas_libres++;
    }
}

// Calcular ocupación por franja horaria
$franjas_horarias = [
    '07:00-09:00' => ['inicio' => '07:00:00', 'fin' => '09:00:00'],
    '09:00-11:00' => ['inicio' => '09:00:00', 'fin' => '11:00:00'],
    '11:00-13:00' => ['inicio' => '11:00:00', 'fin' => '13:00:00'],
    '13:00-15:00' => ['inicio' => '13:00:00', 'fin' => '15:00:00'],
    '15:00-17:00' => ['inicio' => '15:00:00', 'fin' => '17:00:00'],
    '17:00-19:00' => ['inicio' => '17:00:00', 'fin' => '19:00:00'],
    '19:00-21:00' => ['inicio' => '19:00:00', 'fin' => '21:00:00'],
];

$ocupacion_por_franja = [];
foreach ($franjas_horarias as $franja => $horas) {
    $ocupacion_por_franja[$franja] = [
        'total_aulas' => 0,
        'aulas_ocupadas' => 0,
        'porcentaje_ocupacion' => 0
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Ocupación de Aulas - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .report-header {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%);
            color: white;
            padding: 30px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .report-header h1 {
            color: white;
            margin: 0;
            font-size: 28px;
        }
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
        
        .progress-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }
        
        .progress-card h3 {
            color: var(--primary);
            margin-bottom: 15px;
        }
        
        .progress-bar {
            height: 20px;
            background: var(--gray-200);
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 10px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            transition: width 0.5s ease;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            color: var(--gray-700);
        }
        
        .aulas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 25px;
        }
        
        .aula-card {
            background: white;
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }
        
        .aula-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .aula-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        
        .aula-title h3 {
            color: var(--dark);
            margin: 0;
            font-size: 18px;
        }
        
        .aula-title .codigo {
            color: var(--gray-600);
            font-size: 14px;
            margin-top: 5px;
        }
        
        .aula-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-libre {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
        }
        
        .status-ocupada {
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            color: #0c5460;
        }
        
        .status-completa {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
        }
        
        .aula-details {
            margin-bottom: 20px;
        }
        
        .detail-row {
            display: flex;
            margin-bottom: 8px;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--gray-700);
            width: 150px;
            flex-shrink: 0;
        }
        
        .detail-value {
            color: var(--dark);
            flex: 1;
        }
        
        .aula-schedule {
            background: var(--gray-50);
            padding: 15px;
            border-radius: var(--radius);
            margin-top: 15px;
        }
        
        .schedule-title {
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .schedule-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .schedule-item:last-child {
            border-bottom: none;
        }
        
        .schedule-course {
            font-weight: 500;
            color: var(--dark);
        }
        
        .schedule-time {
            color: var(--gray-600);
            font-size: 14px;
        }
        
        .empty-schedule {
            text-align: center;
            color: var(--gray-500);
            padding: 20px;
            font-size: 14px;
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
        
        .franjas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .franja-card {
            background: white;
            border-radius: var(--radius);
            padding: 15px;
            box-shadow: var(--shadow);
            text-align: center;
        }
        
        .franja-title {
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 10px;
        }
        
        .franja-ocupacion {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .franja-label {
            font-size: 12px;
            color: var(--gray-600);
        }
        
        .ocupacion-baja { color: var(--success); }
        .ocupacion-media { color: var(--warning); }
        .ocupacion-alta { color: var(--danger); }
        
        @media print {
            .filters, .export-options, .action-buttons {
                display: none;
            }
            
            .aulas-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .aulas-grid {
                grid-template-columns: 1fr;
            }
            
            .detail-row {
                flex-direction: column;
                gap: 3px;
            }
            
            .detail-label {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="report-header">
            <h1><i class="fas fa-building"></i> Reporte de Ocupación de Aulas</h1>
            <div style="margin-top: 10px; font-size: 14px; opacity: 0.9;">
                Análisis de utilización de espacios académicos | Generado el <?php echo date('d/m/Y H:i'); ?>
            </div>
        </div>
        
        <!-- Estadísticas -->
        <div class="stats-cards">
            <div class="stat-card">
                <i class="fas fa-door-open"></i>
                <h3><?php echo $total_aulas; ?></h3>
                <p>Aulas Disponibles</p>
            </div>
            
            <div class="stat-card">
                <i class="fas fa-calendar-check"></i>
                <h3><?php echo $aulas_ocupadas; ?></h3>
                <p>Aulas en Uso</p>
            </div>
            
            <div class="stat-card">
                <i class="fas fa-door-closed"></i>
                <h3><?php echo $aulas_libres; ?></h3>
                <p>Aulas Disponibles</p>
            </div>
            
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <h3><?php echo $capacidad_total; ?></h3>
                <p>Capacidad Total</p>
            </div>
        </div>
        
        <!-- Filtros -->
        <form method="GET" class="filters">
            <div class="filter-group">
                <label for="semestre"><i class="fas fa-calendar"></i> Semestre Académico</label>
                <select id="semestre" name="semestre" class="form-control">
                    <option value="">Todos los semestres</option>
                    <?php foreach ($semestres as $s): ?>
                        <option value="<?php echo $s['id_semestre']; ?>"
                            <?php echo $id_semestre == $s['id_semestre'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['nombre_semestre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="dia"><i class="fas fa-calendar-day"></i> Día de la Semana</label>
                <select id="dia" name="dia" class="form-control">
                    <option value="">Todos los días</option>
                    <?php foreach ($dias as $dia): ?>
                        <option value="<?php echo $dia; ?>"
                            <?php echo $dia_semana == $dia ? 'selected' : ''; ?>>
                            <?php echo $dia; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="tipo_aula"><i class="fas fa-school"></i> Tipo de Aula</label>
                <select id="tipo_aula" name="tipo_aula" class="form-control">
                    <option value="">Todos los tipos</option>
                    <?php foreach ($tipos_aula as $tipo): ?>
                        <option value="<?php echo $tipo['tipo_aula']; ?>"
                            <?php echo $tipo_aula == $tipo['tipo_aula'] ? 'selected' : ''; ?>>
                            <?php echo ucfirst($tipo['tipo_aula']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="edificio"><i class="fas fa-building"></i> Edificio</label>
                <select id="edificio" name="edificio" class="form-control">
                    <option value="">Todos los edificios</option>
                    <?php foreach ($edificios as $e): ?>
                        <option value="<?php echo $e['edificio']; ?>"
                            <?php echo $edificio == $e['edificio'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($e['edificio']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Filtrar
                </button>
                <a href="ocupacion_aulas.php" class="btn btn-secondary" style="margin-top: 5px;">
                    <i class="fas fa-redo"></i> Limpiar
                </a>
            </div>
        </form>
        
        <!-- Resumen de ocupación -->
        <div class="progress-card">
            <h3><i class="fas fa-chart-pie"></i> Nivel de Ocupación General</h3>
            
            <?php
            $porcentaje_ocupacion = $total_aulas > 0 ? ($aulas_ocupadas / $total_aulas) * 100 : 0;
            $color_ocupacion = 'var(--success)';
            if ($porcentaje_ocupacion >= 80) {
                $color_ocupacion = 'var(--danger)';
            } elseif ($porcentaje_ocupacion >= 60) {
                $color_ocupacion = 'var(--warning)';
            }
            ?>
            
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $porcentaje_ocupacion; ?>%; background: <?php echo $color_ocupacion; ?>;"></div>
            </div>
            
            <div class="progress-label">
                <span><?php echo $aulas_libres; ?> aulas libres</span>
                <span><?php echo number_format($porcentaje_ocupacion, 1); ?>% de ocupación</span>
                <span><?php echo $aulas_ocupadas; ?> aulas en uso</span>
            </div>
        </div>
        
        <!-- Ocupación por franja horaria -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-clock"></i> Ocupación por Franja Horaria</h3>
            </div>
            <div class="card-body">
                <div class="franjas-grid">
                    <?php foreach ($franjas_horarias as $franja => $horas): ?>
                        <?php
                        // Calcular aulas ocupadas en esta franja
                        $aulas_ocupadas_franja = 0;
                        foreach ($horarios as $horario) {
                            if ($horario['hora_inicio'] >= $horas['inicio'] && $horario['hora_inicio'] < $horas['fin']) {
                                $aulas_ocupadas_franja++;
                            }
                        }
                        
                        $porcentaje_ocupacion_franja = $total_aulas > 0 ? ($aulas_ocupadas_franja / $total_aulas) * 100 : 0;
                        
                        $clase_ocupacion = 'ocupacion-baja';
                        if ($porcentaje_ocupacion_franja >= 70) {
                            $clase_ocupacion = 'ocupacion-alta';
                        } elseif ($porcentaje_ocupacion_franja >= 40) {
                            $clase_ocupacion = 'ocupacion-media';
                        }
                        ?>
                        
                        <div class="franja-card">
                            <div class="franja-title"><?php echo $franja; ?></div>
                            <div class="franja-ocupacion <?php echo $clase_ocupacion; ?>">
                                <?php echo number_format($porcentaje_ocupacion_franja, 1); ?>%
                            </div>
                            <div class="franja-label">
                                <?php echo $aulas_ocupadas_franja; ?> aulas ocupadas
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Lista de aulas -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Detalle de Aulas</h3>
                <div style="margin-top: 10px; font-size: 14px; color: var(--gray-600);">
                    Total: <?php echo $total_aulas; ?> aulas | 
                    Ocupadas: <?php echo $aulas_ocupadas; ?> | 
                    Libres: <?php echo $aulas_libres; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($aulas)): ?>
                    <div class="aulas-grid">
                        <?php foreach ($aulas as $aula): ?>
                            <?php
                            // Determinar estado del aula
                            $estado = 'libre';
                            $clase_estado = 'status-libre';
                            $texto_estado = 'Disponible';
                            
                            if ($aula['total_horarios'] > 0) {
                                $estado = 'ocupada';
                                $clase_estado = 'status-ocupada';
                                $texto_estado = 'En Uso';
                                
                                // Calcular porcentaje de ocupación
                                $horarios_aula = isset($horarios_por_aula[$aula['id_aula']]) ? $horarios_por_aula[$aula['id_aula']] : [];
                                $total_estudiantes_aula = 0;
                                foreach ($horarios_aula as $horario) {
                                    $total_estudiantes_aula += $horario['estudiantes_inscritos'];
                                }
                                
                                $porcentaje_ocupacion_aula = $aula['capacidad'] > 0 ? ($total_estudiantes_aula / $aula['capacidad']) * 100 : 0;
                                
                                if ($porcentaje_ocupacion_aula >= 90) {
                                    $clase_estado = 'status-completa';
                                    $texto_estado = 'Alta Ocupación';
                                }
                            }
                            ?>
                            
                            <div class="aula-card">
                                <div class="aula-header">
                                    <div class="aula-title">
                                        <h3>
                                            <i class="fas fa-door-open"></i>
                                            <?php echo htmlspecialchars($aula['nombre_aula']); ?>
                                        </h3>
                                        <div class="codigo">
                                            <?php echo $aula['codigo_aula']; ?>
                                            <?php if ($aula['edificio']): ?>
                                                | Edificio <?php echo htmlspecialchars($aula['edificio']); ?>
                                            <?php endif; ?>
                                            <?php if ($aula['piso']): ?>
                                                | Piso <?php echo $aula['piso']; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <span class="aula-status <?php echo $clase_estado; ?>">
                                        <?php if ($estado == 'ocupada'): ?>
                                            <i class="fas fa-chart-line"></i>
                                        <?php else: ?>
                                            <i class="fas fa-check-circle"></i>
                                        <?php endif; ?>
                                        <?php echo $texto_estado; ?>
                                    </span>
                                </div>
                                
                                <div class="aula-details">
                                    <div class="detail-row">
                                        <div class="detail-label">Tipo:</div>
                                        <div class="detail-value">
                                            <?php echo ucfirst($aula['tipo_aula']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="detail-row">
                                        <div class="detail-label">Capacidad:</div>
                                        <div class="detail-value">
                                            <?php echo $aula['capacidad']; ?> estudiantes
                                        </div>
                                    </div>
                                    
                                    <div class="detail-row">
                                        <div class="detail-label">Horarios:</div>
                                        <div class="detail-value">
                                            <?php echo $aula['total_horarios']; ?> clases programadas
                                        </div>
                                    </div>
                                    
                                    <div class="detail-row">
                                        <div class="detail-label">Profesores:</div>
                                        <div class="detail-value">
                                            <?php echo $aula['total_profesores']; ?> profesores asignados
                                        </div>
                                    </div>
                                    
                                    <div class="detail-row">
                                        <div class="detail-label">Programas:</div>
                                        <div class="detail-value">
                                            <?php echo $aula['total_programas']; ?> programas
                                        </div>
                                    </div>
                                    
                                    <?php if ($estado == 'ocupada' && $aula['total_horarios'] > 0): ?>
                                        <div class="detail-row">
                                            <div class="detail-label">Ocupación:</div>
                                            <div class="detail-value">
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <div style="flex: 1;">
                                                        <div class="progress-bar" style="height: 10px;">
                                                            <div class="progress-fill" 
                                                                 style="width: <?php echo min($porcentaje_ocupacion_aula, 100); ?>%;
                                                                        background: <?php echo $porcentaje_ocupacion_aula >= 90 ? 'var(--danger)' : ($porcentaje_ocupacion_aula >= 70 ? 'var(--warning)' : 'var(--success)'); ?>;">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <span style="font-size: 12px; color: var(--gray-600);">
                                                        <?php echo number_format($porcentaje_ocupacion_aula, 1); ?>%
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (isset($horarios_por_aula[$aula['id_aula']]) && !empty($horarios_por_aula[$aula['id_aula']])): ?>
                                    <div class="aula-schedule">
                                        <div class="schedule-title">
                                            <i class="fas fa-calendar-alt"></i>
                                            Horarios de Hoy
                                        </div>
                                        
                                        <?php foreach ($horarios_por_aula[$aula['id_aula']] as $horario): ?>
                                            <div class="schedule-item">
                                                <div class="schedule-course">
                                                    <?php echo htmlspecialchars($horario['nombre_curso']); ?>
                                                    <small style="color: var(--gray-600);">
                                                        (<?php echo $horario['estudiantes_inscritos']; ?> est.)
                                                    </small>
                                                </div>
                                                <div class="schedule-time">
                                                    <?php echo Funciones::formatearHora($horario['hora_inicio']); ?> - 
                                                    <?php echo Funciones::formatearHora($horario['hora_fin']); ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php elseif ($estado == 'ocupada'): ?>
                                    <div class="aula-schedule">
                                        <div class="empty-schedule">
                                            <i class="fas fa-calendar-times"></i>
                                            <p>No hay clases programadas para hoy</p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <div style="margin-top: 15px; display: flex; gap: 10px;">
                                    <a href="../aulas/ver.php?id=<?php echo $aula['id_aula']; ?>" 
                                       class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> Detalles
                                    </a>
                                    <a href="../aulas/disponibilidad.php?id=<?php echo $aula['id_aula']; ?>" 
                                       class="btn btn-sm btn-secondary">
                                        <i class="fas fa-calendar"></i> Calendario
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-door-closed"></i>
                        <h3>No hay aulas registradas</h3>
                        <p>No se encontraron aulas con los filtros aplicados.</p>
                        <a href="../aulas/crear.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Registrar Primera Aula
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Opciones de exportación -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-download"></i> Exportar Reporte</h3>
            </div>
            <div class="card-body">
                <div class="export-options">
                    <a href="exportar_ocupacion.php?formato=pdf&semestre=<?php echo $id_semestre; ?>&dia=<?php echo urlencode($dia_semana); ?>&tipo_aula=<?php echo urlencode($tipo_aula); ?>&edificio=<?php echo urlencode($edificio); ?>" 
                       class="btn btn-secondary" target="_blank">
                        <i class="fas fa-file-pdf"></i> PDF
                    </a>
                    <a href="exportar_ocupacion.php?formato=excel&semestre=<?php echo $id_semestre; ?>&dia=<?php echo urlencode($dia_semana); ?>&tipo_aula=<?php echo urlencode($tipo_aula); ?>&edificio=<?php echo urlencode($edificio); ?>" 
                       class="btn btn-secondary" target="_blank">
                        <i class="fas fa-file-excel"></i> Excel
                    </a>
                    <button type="button" class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Acciones -->
        <div class="action-buttons">
            <a href="../dashboard.php" class="btn btn-secondary">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="index.php" class="btn btn-primary">
                <i class="fas fa-chart-bar"></i> Todos los Reportes
            </a>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Actualizar gráficos de progreso
        const progressBars = document.querySelectorAll('.progress-fill');
        progressBars.forEach(bar => {
            const width = bar.style.width;
            bar.style.width = '0';
            setTimeout(() => {
                bar.style.width = width;
            }, 100);
        });
        
        // Efecto hover en tarjetas de aulas
        const aulaCards = document.querySelectorAll('.aula-card');
        aulaCards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
                this.style.boxShadow = 'var(--shadow-lg)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = 'var(--shadow)';
            });
        });
        
        // Mostrar/ocultar horarios al hacer clic
        const scheduleTitles = document.querySelectorAll('.schedule-title');
        scheduleTitles.forEach(title => {
            title.addEventListener('click', function() {
                const schedule = this.nextElementSibling;
                schedule.style.display = schedule.style.display === 'none' ? 'block' : 'none';
                this.querySelector('i').classList.toggle('fa-chevron-down');
                this.querySelector('i').classList.toggle('fa-chevron-right');
            });
        });
    });
    </script>
</body>
</html>