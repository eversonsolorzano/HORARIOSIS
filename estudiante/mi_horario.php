<?php
require_once '../includes/auth.php';
require_once '../includes/funciones.php';
Auth::requireRole('estudiante');

$db = Database::getConnection();
$user = Auth::getUserData();

// Obtener datos del estudiante
$stmt = $db->prepare("
    SELECT e.*, p.nombre_programa 
    FROM estudiantes e 
    JOIN programas_estudio p ON e.id_programa = p.id_programa 
    WHERE e.id_usuario = ?
");
$stmt->execute([$user['id']]);
$estudiante = $stmt->fetch();

if (!$estudiante) {
    Funciones::redireccionar('../login.php', 'Perfil de estudiante no encontrado', 'error');
}

// Función para calcular duración en slots de 30 minutos
function calcularDuracion($inicio, $fin) {
    $inicio_ts = strtotime($inicio);
    $fin_ts = strtotime($fin);
    $diferencia = ($fin_ts - $inicio_ts) / 1800; // 30 minutos = 1800 segundos
    return max(1, $diferencia);
}

// Obtener horarios del estudiante
$stmt = $db->prepare("
    SELECT h.*, 
           c.id_curso,
           c.nombre_curso, 
           c.codigo_curso, 
           c.creditos,
           c.horas_semanales,
           a.codigo_aula, 
           a.nombre_aula,
           a.capacidad,
           s.codigo_semestre,
           s.nombre_semestre,
           p.nombre_programa,
           p.id_programa,
           CONCAT(pr.nombres, ' ', pr.apellidos) as profesor_nombre
    FROM inscripciones i
    JOIN horarios h ON i.id_horario = h.id_horario
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN aulas a ON h.id_aula = a.id_aula
    JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
    JOIN programas_estudio p ON c.id_programa = p.id_programa
    JOIN profesores pr ON h.id_profesor = pr.id_profesor
    WHERE i.id_estudiante = ? 
    AND i.estado = 'inscrito'
    AND h.activo = 1
    ORDER BY FIELD(h.dia_semana, 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'), 
             h.hora_inicio
");
$stmt->execute([$estudiante['id_estudiante']]);
$horarios = $stmt->fetchAll();

// Días de la semana
$dias_semana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

// Horas del día (8:00 AM a 2:00 PM en intervalos de 30 minutos)
$horas_dia = [];
for ($i = 8; $i <= 13; $i++) { // Hasta la 1:00 PM
    $horas_dia[] = str_pad($i, 2, '0', STR_PAD_LEFT) . ':00';
    if ($i < 13) {
        $horas_dia[] = str_pad($i, 2, '0', STR_PAD_LEFT) . ':30';
    }
}
// Añadir la hora 14:00 (2:00 PM)
$horas_dia[] = '14:00';

// Generar colores únicos para cada curso
$colores_cursos = [];
$colores_disponibles = [
    '#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', 
    '#EC4899', '#14B8A6', '#F97316', '#6366F1', '#84CC16'
];

foreach ($horarios as $index => $horario) {
    $curso_id = $horario['id_curso'];
    if (!isset($colores_cursos[$curso_id])) {
        $color_index = count($colores_cursos) % count($colores_disponibles);
        $colores_cursos[$curso_id] = $colores_disponibles[$color_index];
    }
}

// Organizar horarios por día y hora
$horarios_organizados = [];
foreach ($horarios as $horario) {
    $dia = $horario['dia_semana'];
    $hora_inicio = $horario['hora_inicio'];
    $hora_fin = $horario['hora_fin'];
    
    if (!isset($horarios_organizados[$dia])) {
        $horarios_organizados[$dia] = [];
    }
    
    $horarios_organizados[$dia][] = [
        'hora_inicio' => $hora_inicio,
        'hora_fin' => $hora_fin,
        'curso' => $horario['nombre_curso'],
        'codigo_curso' => $horario['codigo_curso'],
        'aula' => $horario['codigo_aula'],
        'tipo_clase' => $horario['tipo_clase'],
        'programa' => $horario['nombre_programa'],
        'profesor' => $horario['profesor_nombre'],
        'semestre' => $horario['nombre_semestre'],
        'id_curso' => $horario['id_curso'],
        'creditos' => $horario['creditos'],
        'duracion' => calcularDuracion($hora_inicio, $hora_fin)
    ];
}

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

// Calcular estadísticas - CORREGIDO EL ERROR DE DIVISIÓN POR CERO
$total_cursos = count(array_unique(array_column($horarios, 'id_curso')));
$total_creditos = 0;
$horas_totales = 0;

// Calcular créditos totales (suma de créditos de cursos únicos)
$cursos_unicos_creditos = [];
foreach ($horarios as $horario) {
    if (!in_array($horario['id_curso'], $cursos_unicos_creditos)) {
        $cursos_unicos_creditos[] = $horario['id_curso'];
        $total_creditos += $horario['creditos'];
    }
    
    // Calcular horas totales
    $inicio = strtotime($horario['hora_inicio']);
    $fin = strtotime($horario['hora_fin']);
    $horas_totales += ($fin - $inicio) / 3600;
}

// Obtener semestre actual
$stmt = $db->prepare("
    SELECT s.* 
    FROM semestres_academicos s 
    WHERE s.estado = 'en_curso' 
    ORDER BY s.fecha_inicio DESC 
    LIMIT 1
");
$stmt->execute();
$semestre_actual = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Horario - Sistema de Horarios</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .horario-grid {
            display: grid;
            grid-template-columns: 80px repeat(6, 1fr);
            gap: 1px;
            background: var(--gray-200);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            overflow: hidden;
            margin-top: 30px;
        }
        
        .hora-header {
            background: var(--gray-900);
            color: white;
            padding: 12px;
            text-align: center;
            font-weight: 600;
            font-size: 13px;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .dia-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 15px;
            text-align: center;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 14px;
        }
        
        .celda-hora {
            background: white;
            padding: 10px;
            text-align: center;
            font-size: 12px;
            color: var(--gray-600);
            font-weight: 500;
            border-bottom: 1px solid var(--gray-100);
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .celda-clase {
            background: white;
            border-bottom: 1px solid var(--gray-100);
            height: 50px;
            position: relative;
        }
        
        .clase-bloque {
            position: absolute;
            left: 2px;
            right: 2px;
            border-radius: 6px;
            padding: 10px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 11px;
            line-height: 1.3;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            z-index: 1;
        }
        
        .clase-bloque:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 2;
        }
        
        .clase-curso {
            font-weight: 700;
            margin-bottom: 2px;
            font-size: 10px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .clase-detalle {
            font-size: 9px;
            opacity: 0.9;
            display: flex;
            align-items: center;
            gap: 3px;
            margin-top: 2px;
        }
        
        .clase-aula {
            display: inline-flex;
            align-items: center;
            gap: 2px;
        }
        
        .estadisticas-horario {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .estadistica-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: var(--shadow);
        }
        
        .estadistica-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }
        
        .estadistica-info h3 {
            font-size: 14px;
            color: var(--gray-600);
            margin-bottom: 5px;
        }
        
        .estadistica-numero {
            font-size: 28px;
            font-weight: 700;
            color: var(--dark);
        }
        
        .leyenda-cursos {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 30px;
            padding: 20px;
            background: var(--gray-50);
            border-radius: var(--radius);
        }
        
        .leyenda-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .leyenda-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
        }
        
        .leyenda-texto {
            font-size: 13px;
            color: var(--gray-700);
        }
        
        .modal-clase {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: var(--radius-xl);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
        }
        
        .modal-header {
            padding: 25px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .modal-info-item {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .modal-info-label {
            font-size: 12px;
            color: var(--gray-600);
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .modal-info-value {
            font-size: 16px;
            color: var(--dark);
            font-weight: 500;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            color: var(--gray-500);
            cursor: pointer;
            transition: color 0.3s;
        }
        
        .modal-close:hover {
            color: var(--danger);
        }
        
        .filters-container {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-label {
            font-size: 13px;
            color: var(--gray-600);
            font-weight: 500;
        }
        
        .empty-horario {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
        }
        
        .celda-hora.fuera-horario {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: var(--gray-400);
            font-style: italic;
        }
        
        .clase-bloque.fuera-horario {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%) !important;
            color: var(--gray-600);
            border: 1px dashed var(--gray-300);
        }
        
        .clase-bloque.fuera-horario:hover {
            background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%) !important;
        }
        
        .dia-actual {
            background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%) !important;
            color: white !important;
            position: relative;
        }
        
        .dia-actual::after {
            content: 'HOY';
            position: absolute;
            top: 5px;
            right: 5px;
            font-size: 9px;
            background: #10b981;
            color: white;
            padding: 2px 5px;
            border-radius: 3px;
            font-weight: bold;
        }
        
        @media (max-width: 1200px) {
            .horario-grid {
                grid-template-columns: 70px repeat(6, 1fr);
            }
            
            .clase-curso {
                font-size: 9px;
            }
            
            .clase-detalle {
                font-size: 8px;
            }
        }
        
        @media (max-width: 992px) {
            .horario-grid {
                display: block;
                overflow-x: auto;
            }
            
            .grid-header {
                display: grid;
                grid-template-columns: 70px repeat(6, 150px);
                min-width: 1050px;
            }
            
            .grid-body {
                display: grid;
                grid-template-columns: 70px repeat(6, 150px);
                min-width: 1050px;
            }
        }
        
        @media (max-width: 768px) {
            .estadisticas-horario {
                grid-template-columns: 1fr;
            }
            
            .filters-container {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fas fa-calendar-alt"></i> Mi Horario Académico</h1>
                <div class="nav">
                    <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    <a href="mi_horario.php" class="active"><i class="fas fa-calendar-alt"></i> Mi Horario</a>
                    <a href="mis_cursos.php"><i class="fas fa-book"></i> Mis Cursos</a>
                    <a href="inscribir_curso.php"><i class="fas fa-plus-circle"></i> Inscribir Curso</a>
                    <a href="mis_calificaciones.php"><i class="fas fa-star"></i> Mis Calificaciones</a>
                </div>
            </div>
            <div class="user-info">
                <span><?php echo htmlspecialchars($estudiante['nombres'] . ' ' . $estudiante['apellidos']); ?></span>
                <span class="badge badge-estudiante">Estudiante</span>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <div class="estadisticas-horario">
            <div class="estadistica-card">
                <div class="estadistica-icon" style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="estadistica-info">
                    <h3>Horas Semanales</h3>
                    <div class="estadistica-numero"><?php echo number_format($horas_totales, 1); ?></div>
                </div>
            </div>
            
            <div class="estadistica-card">
                <div class="estadistica-icon" style="background: linear-gradient(135deg, var(--success) 0%, #34d399 100%);">
                    <i class="fas fa-book"></i>
                </div>
                <div class="estadistica-info">
                    <h3>Cursos Inscritos</h3>
                    <div class="estadistica-numero"><?php echo $total_cursos; ?></div>
                </div>
            </div>
            
            <div class="estadistica-card">
                <div class="estadistica-icon" style="background: linear-gradient(135deg, var(--secondary) 0%, #a78bfa 100%);">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="estadistica-info">
                    <h3>Total Créditos</h3>
                    <div class="estadistica-numero"><?php echo number_format($total_creditos, 1); ?></div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2><i class="fas fa-calendar-week"></i> Horario Semanal (8:00 AM - 2:00 PM)</h2>
                <div style="font-size: 13px; color: var(--gray-600);">
                    <i class="fas fa-info-circle"></i> Haz clic en cualquier clase para ver detalles
                </div>
            </div>
            
            <?php if (count($horarios) > 0): ?>
                <!-- Tabla de horarios -->
                <div class="horario-grid">
                    <!-- Fila de encabezados -->
                    <div class="hora-header">HORA</div>
                    <?php 
                    $dias_numeros = ['Lunes' => 1, 'Martes' => 2, 'Miércoles' => 3, 'Jueves' => 4, 'Viernes' => 5, 'Sábado' => 6];
                    $hoy_numero = date('N');
                    
                    foreach ($dias_semana as $dia): 
                        $clase_dia = '';
                        if ($dias_numeros[$dia] == $hoy_numero) {
                            $clase_dia = 'dia-actual';
                        }
                    ?>
                        <div class="dia-header <?php echo $clase_dia; ?>"><?php echo $dia; ?></div>
                    <?php endforeach; ?>
                    
                    <!-- Filas de horas -->
                    <?php foreach ($horas_dia as $index => $hora): ?>
                        <?php
                        $hora_actual = $hora;
                        $hora_numero = intval(substr($hora, 0, 2));
                        $minutos = substr($hora, 3, 2);
                        
                        // Determinar si está en horario (8:00 AM - 2:00 PM)
                        $en_horario = ($hora_numero >= 8 && $hora_numero <= 14);
                        ?>
                        
                        <!-- Celda de hora -->
                        <div class="celda-hora <?php echo $en_horario ? '' : 'fuera-horario'; ?>">
                            <?php 
                            // Formatear la hora para mostrar AM/PM
                            $hora_formateada = '';
                            if ($hora_numero < 12) {
                                $hora_formateada = $hora . ' AM';
                            } elseif ($hora_numero == 12) {
                                $hora_formateada = $hora . ' PM';
                            } else {
                                $hora_formateada = ($hora_numero - 12) . ':' . $minutos . ' PM';
                            }
                            echo $hora_formateada;
                            ?>
                        </div>
                        
                        <!-- Celdas de clases por día -->
                        <?php foreach ($dias_semana as $dia): ?>
                            <div class="celda-clase" id="celda-<?php echo strtolower($dia); ?>-<?php echo str_replace(':', '', $hora_actual); ?>">
                                <?php
                                // Buscar si hay una clase en esta celda
                                $clase_encontrada = null;
                                if (isset($horarios_organizados[$dia])) {
                                    foreach ($horarios_organizados[$dia] as $clase) {
                                        $hora_clase = strtotime($clase['hora_inicio']);
                                        $hora_clase_str = date('H:i', $hora_clase);
                                        
                                        // Verificar si esta hora coincide con el inicio de una clase
                                        if ($hora_clase_str === $hora_actual) {
                                            $clase_encontrada = $clase;
                                            break;
                                        }
                                    }
                                }
                                ?>
                                
                                <?php if ($clase_encontrada): ?>
                                    <?php
                                    $duracion = $clase_encontrada['duracion'];
                                    $color_curso = $colores_cursos[$clase_encontrada['id_curso']] ?? '#3B82F6';
                                    $altura = $duracion * 50; // 50px por cada slot de 30 minutos
                                    $top = 0;
                                    
                                    // Verificar si la clase está fuera del horario
                                    $hora_inicio_numero = intval(substr($clase_encontrada['hora_inicio'], 0, 2));
                                    $clase_fuera_horario = ($hora_inicio_numero < 8 || $hora_inicio_numero > 14);
                                    ?>
                                    
                                    <div class="clase-bloque <?php echo $clase_fuera_horario ? 'fuera-horario' : ''; ?>" 
                                         style="
                                            top: <?php echo $top; ?>px;
                                            height: <?php echo $altura - 4; ?>px;
                                            <?php if (!$clase_fuera_horario): ?>
                                            background: linear-gradient(135deg, <?php echo $color_curso; ?> 0%, <?php echo ajustarBrillo($color_curso, -20); ?> 100%);
                                            <?php endif; ?>
                                         "
                                         data-clase='<?php echo json_encode($clase_encontrada); ?>'
                                         onclick="mostrarDetallesClase(this)">
                                        
                                        <div class="clase-curso" title="<?php echo htmlspecialchars($clase_encontrada['curso']); ?>">
                                            <?php echo htmlspecialchars(substr($clase_encontrada['curso'], 0, 12)); ?><?php echo strlen($clase_encontrada['curso']) > 12 ? '...' : ''; ?>
                                        </div>
                                        
                                        <div class="clase-detalle">
                                            <span class="clase-aula">
                                                <i class="fas fa-door-open"></i> <?php echo $clase_encontrada['aula']; ?>
                                            </span>
                                        </div>
                                        
                                        <div class="clase-detalle">
                                            <i class="fas fa-chalkboard-teacher"></i> <?php echo substr($clase_encontrada['profesor'], 0, 10); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
                
                <!-- Leyenda de cursos -->
                <div class="leyenda-cursos">
                    <h4 style="width: 100%; margin-bottom: 10px; color: var(--gray-700);">
                        <i class="fas fa-palette"></i> Leyenda de Cursos
                    </h4>
                    <?php 
                    $cursos_unicos = [];
                    foreach ($horarios as $horario) {
                        $curso_id = $horario['id_curso'];
                        if (!isset($cursos_unicos[$curso_id])) {
                            $cursos_unicos[$curso_id] = [
                                'nombre' => $horario['nombre_curso'],
                                'codigo' => $horario['codigo_curso'],
                                'color' => $colores_cursos[$curso_id]
                            ];
                        }
                    }
                    ?>
                    <?php foreach ($cursos_unicos as $curso): ?>
                        <div class="leyenda-item">
                            <div class="leyenda-color" style="background: <?php echo $curso['color']; ?>;"></div>
                            <div class="leyenda-texto">
                                <?php echo htmlspecialchars($curso['nombre']); ?>
                                <small style="color: var(--gray-500);">(<?php echo $curso['codigo']; ?>)</small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times fa-3x" style="color: var(--gray-300); margin-bottom: 20px;"></i>
                    <h3>No tienes horarios asignados</h3>
                    <p>No estás inscrito en ningún curso para el semestre actual.</p>
                    <a href="inscribir_curso.php" class="btn btn-primary mt-3">
                        <i class="fas fa-plus-circle"></i> Inscribirse en Cursos
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Modal para detalles de clase -->
        <div id="modalClase" class="modal-clase">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-info-circle"></i> Detalles de la Clase</h3>
                    <button class="modal-close" onclick="cerrarModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="modalDetalles"></div>
                </div>
            </div>
        </div>
        
        <!-- Lista Detallada de Horarios -->
        <?php if (count($horarios) > 0): ?>
        <div class="card">
            <h2><i class="fas fa-list"></i> Lista Detallada de Horarios</h2>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Curso</th>
                            <th>Día</th>
                            <th>Horario</th>
                            <th>Aula</th>
                            <th>Profesor</th>
                            <th>Tipo</th>
                            <th>Programa</th>
                            <th>Créditos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($horarios as $h): ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 12px; height: 12px; border-radius: 3px; background: <?php echo $colores_cursos[$h['id_curso']]; ?>;"></div>
                                    <div>
                                        <strong><?php echo htmlspecialchars($h['nombre_curso']); ?></strong><br>
                                        <small><?php echo $h['codigo_curso']; ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo $h['dia_semana']; ?></td>
                            <td>
                                <?php echo Funciones::formatearHora($h['hora_inicio']); ?><br>
                                <?php echo Funciones::formatearHora($h['hora_fin']); ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($h['codigo_aula']); ?><br>
                                <small><?php echo htmlspecialchars($h['nombre_aula']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($h['profesor_nombre']); ?></td>
                            <td>
                                <?php
                                $badge_class = '';
                                switch ($h['tipo_clase']) {
                                    case 'teoría': $badge_class = 'badge-primary'; break;
                                    case 'práctica': $badge_class = 'badge-success'; break;
                                    case 'laboratorio': $badge_class = 'badge-warning'; break;
                                    case 'taller': $badge_class = 'badge-secondary'; break;
                                    default: $badge_class = 'badge-info';
                                }
                                ?>
                                <span class="badge <?php echo $badge_class; ?>">
                                    <?php echo ucfirst($h['tipo_clase']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($h['nombre_programa']); ?></td>
                            <td><?php echo $h['creditos']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script src="../assets/js/main.js"></script>
    <script>
        // Mostrar detalles de la clase
        function mostrarDetallesClase(elemento) {
            const datos = JSON.parse(elemento.getAttribute('data-clase'));
            
            const modal = document.getElementById('modalClase');
            const detalles = document.getElementById('modalDetalles');
            
            // Formatear hora para mostrar AM/PM
            function formatearHora(hora24) {
                const [horas, minutos] = hora24.split(':');
                let horas12 = parseInt(horas);
                const ampm = horas12 >= 12 ? 'PM' : 'AM';
                horas12 = horas12 % 12;
                horas12 = horas12 ? horas12 : 12; // 0 se convierte en 12
                return `${horas12}:${minutos} ${ampm}`;
            }
            
            const html = `
                <div class="modal-info-item">
                    <div class="modal-info-label">Curso</div>
                    <div class="modal-info-value">${datos.curso}</div>
                    <div style="font-size: 14px; color: var(--gray-600); margin-top: 5px;">
                        Código: ${datos.codigo_curso} | ${datos.creditos} créditos
                    </div>
                </div>
                
                <div class="modal-info-item">
                    <div class="modal-info-label">Horario</div>
                    <div class="modal-info-value">
                        ${formatearHora(datos.hora_inicio)} - ${formatearHora(datos.hora_fin)}<br>
                        <small style="color: var(--gray-600);">Duración: ${datos.duracion/2} hora(s)</small>
                    </div>
                </div>
                
                <div class="grid-2">
                    <div class="modal-info-item">
                        <div class="modal-info-label">Aula</div>
                        <div class="modal-info-value">
                            <i class="fas fa-door-open"></i> ${datos.aula}
                        </div>
                    </div>
                    
                    <div class="modal-info-item">
                        <div class="modal-info-label">Tipo de Clase</div>
                        <div class="modal-info-value">
                            <span class="badge ${datos.tipo_clase === 'teoría' ? 'badge-primary' : 
                                               datos.tipo_clase === 'práctica' ? 'badge-success' : 
                                               datos.tipo_clase === 'laboratorio' ? 'badge-warning' : 'badge-secondary'}">
                                ${datos.tipo_clase}
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="grid-2">
                    <div class="modal-info-item">
                        <div class="modal-info-label">Profesor</div>
                        <div class="modal-info-value">${datos.profesor}</div>
                    </div>
                    
                    <div class="modal-info-item">
                        <div class="modal-info-label">Programa</div>
                        <div class="modal-info-value">${datos.programa}</div>
                    </div>
                </div>
                
                <div class="modal-info-item">
                    <div class="modal-info-label">Semestre Académico</div>
                    <div class="modal-info-value">${datos.semestre}</div>
                </div>
            `;
            
            detalles.innerHTML = html;
            modal.style.display = 'flex';
        }
        
        // Cerrar modal
        function cerrarModal() {
            document.getElementById('modalClase').style.display = 'none';
        }
        
        // Cerrar modal al hacer clic fuera
        document.getElementById('modalClase').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarModal();
            }
        });
        
        // Cerrar modal con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cerrarModal();
            }
        });
        
        // Resaltar hora actual
        document.addEventListener('DOMContentLoaded', function() {
            const ahora = new Date();
            const horas = ahora.getHours();
            const minutos = ahora.getMinutes();
            
            // Redondear a la media hora más cercana
            const minutosRedondeados = minutos < 15 ? '00' : (minutos < 45 ? '30' : '00');
            const horaActual = horas + (minutos >= 45 ? 1 : 0);
            
            // Formatear para coincidir con las celdas (HH:MM)
            const horaFormateada = (horaActual < 10 ? '0' : '') + horaActual + ':' + minutosRedondeados;
            
            // Obtener día actual
            const dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
            const diaActual = dias[ahora.getDay()];
            
            // Resaltar celda actual
            if (horaActual >= 8 && horaActual <= 14 && diaActual !== 'Domingo') {
                const celdaId = `celda-${diaActual.toLowerCase()}-${horaFormateada.replace(':', '')}`;
                const celda = document.getElementById(celdaId);
                
                if (celda) {
                    celda.style.border = '2px solid #EF4444';
                    celda.style.boxShadow = 'inset 0 0 0 2px rgba(239, 68, 68, 0.3)';
                }
            }
        });
    </script>
</body>
</html>