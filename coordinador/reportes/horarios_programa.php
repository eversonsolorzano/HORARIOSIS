<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

// Parámetros de filtro
$id_programa = isset($_GET['programa']) ? intval($_GET['programa']) : 0;
$id_semestre = isset($_GET['semestre']) ? intval($_GET['semestre']) : 0;
$dia_semana = isset($_GET['dia']) ? Funciones::sanitizar($_GET['dia']) : '';

// Obtener programas para filtro
$programas = $db->query("
    SELECT id_programa, codigo_programa, nombre_programa 
    FROM programas_estudio 
    WHERE activo = 1
    ORDER BY nombre_programa
")->fetchAll();

// Obtener semestres para filtro
$semestres = $db->query("
    SELECT id_semestre, codigo_semestre, nombre_semestre 
    FROM semestres_academicos 
    WHERE estado != 'finalizado'
    ORDER BY fecha_inicio DESC
")->fetchAll();

// Días de la semana
$dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

// Construir consulta
$where = ["h.activo = 1"];
$params = [];

if ($id_programa > 0) {
    $where[] = "p.id_programa = ?";
    $params[] = $id_programa;
}

if ($id_semestre > 0) {
    $where[] = "s.id_semestre = ?";
    $params[] = $id_semestre;
}

if (!empty($dia_semana)) {
    $where[] = "h.dia_semana = ?";
    $params[] = $dia_semana;
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Obtener horarios
$sql = "
    SELECT h.*,
           c.codigo_curso, c.nombre_curso, c.semestre, c.creditos, c.horas_semanales,
           p.nombre_programa, p.codigo_programa,
           s.codigo_semestre, s.nombre_semestre,
           CONCAT(pr.nombres, ' ', pr.apellidos) as profesor_nombre,
           pr.codigo_profesor,
           a.codigo_aula, a.nombre_aula, a.capacidad,
           COUNT(i.id_inscripcion) as estudiantes_inscritos
    FROM horarios h
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN programas_estudio p ON c.id_programa = p.id_programa
    JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
    JOIN profesores pr ON h.id_profesor = pr.id_profesor
    JOIN aulas a ON h.id_aula = a.id_aula
    LEFT JOIN inscripciones i ON h.id_horario = i.id_horario AND i.estado = 'inscrito'
    $where_clause
    GROUP BY h.id_horario
    ORDER BY p.nombre_programa, c.semestre, h.dia_semana, h.hora_inicio
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$horarios = $stmt->fetchAll();

// Estadísticas
$total_horarios = count($horarios);
$total_estudiantes = array_sum(array_column($horarios, 'estudiantes_inscritos'));

// Agrupar por programa
$horarios_por_programa = [];
foreach ($horarios as $horario) {
    $programa = $horario['nombre_programa'];
    if (!isset($horarios_por_programa[$programa])) {
        $horarios_por_programa[$programa] = [];
    }
    $horarios_por_programa[$programa][] = $horario;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Horarios por Programa - Sistema de Horarios</title>
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
        
        .programa-section {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            overflow: hidden;
        }
        
        .programa-header {
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            padding: 20px;
            border-bottom: 2px solid var(--primary-light);
        }
        
        .programa-header h3 {
            color: var(--primary);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .programa-stats {
            display: flex;
            gap: 15px;
            margin-top: 10px;
            font-size: 14px;
        }
        
        .programa-stat {
            background: white;
            padding: 5px 10px;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
        }
        
        .horarios-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 15px;
            padding: 20px;
        }
        
        .horario-card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .horario-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
            border-color: var(--primary-light);
        }
        
        .horario-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .horario-header h4 {
            color: var(--dark);
            margin: 0;
            font-size: 16px;
        }
        
        .horario-details {
            margin-bottom: 15px;
        }
        
        .detail-item {
            display: flex;
            margin-bottom: 8px;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--gray-700);
            width: 120px;
            flex-shrink: 0;
        }
        
        .detail-value {
            color: var(--dark);
            flex: 1;
        }
        
        .horario-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid var(--gray-100);
        }
        
        .ocupacion {
            font-size: 13px;
            color: var(--gray-600);
        }
        
        .ocupacion-alta { color: var(--danger); }
        .ocupacion-media { color: var(--warning); }
        .ocupacion-baja { color: var(--success); }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: flex-end;
        }
        
        .export-options {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
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
        
        @media (max-width: 768px) {
            .horarios-grid {
                grid-template-columns: 1fr;
            }
            
            .horario-card {
                padding: 15px;
            }
            
            .detail-item {
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
            <h1><i class="fas fa-calendar-alt"></i> Reporte de Horarios por Programa</h1>
            <div style="margin-top: 10px; font-size: 14px; opacity: 0.9;">
                Generado el <?php echo date('d/m/Y H:i'); ?>
            </div>
        </div>
        
        <!-- Estadísticas -->
        <div class="stats-cards">
            <div class="stat-card">
                <i class="fas fa-graduation-cap"></i>
                <h3><?php echo count($programas); ?></h3>
                <p>Programas Activos</p>
            </div>
            
            <div class="stat-card">
                <i class="fas fa-calendar"></i>
                <h3><?php echo $total_horarios; ?></h3>
                <p>Horarios Programados</p>
            </div>
            
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <h3><?php echo $total_estudiantes; ?></h3>
                <p>Estudiantes Inscritos</p>
            </div>
            
            <div class="stat-card">
                <i class="fas fa-chalkboard-teacher"></i>
                <h3><?php 
                    $profesores_unicos = array_unique(array_column($horarios, 'id_profesor'));
                    echo count($profesores_unicos);
                ?></h3>
                <p>Profesores Asignados</p>
            </div>
        </div>
        
        <!-- Filtros -->
        <form method="GET" class="filters">
            <div class="filter-group">
                <label for="programa"><i class="fas fa-graduation-cap"></i> Programa</label>
                <select id="programa" name="programa" class="form-control">
                    <option value="">Todos los programas</option>
                    <?php foreach ($programas as $p): ?>
                        <option value="<?php echo $p['id_programa']; ?>"
                            <?php echo $id_programa == $p['id_programa'] ? 'selected' : ''; ?>>
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
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Filtrar
                </button>
                <a href="horarios_programa.php" class="btn btn-secondary" style="margin-top: 5px;">
                    <i class="fas fa-redo"></i> Limpiar
                </a>
            </div>
        </form>
        
        <!-- Opciones de exportación -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-download"></i> Exportar Reporte</h3>
            </div>
            <div class="card-body">
                <div class="export-options">
                    <a href="exportar_horarios.php?formato=pdf&programa=<?php echo $id_programa; ?>&semestre=<?php echo $id_semestre; ?>&dia=<?php echo urlencode($dia_semana); ?>" 
                       class="btn btn-secondary" target="_blank">
                        <i class="fas fa-file-pdf"></i> PDF
                    </a>
                    <a href="exportar_horarios.php?formato=excel&programa=<?php echo $id_programa; ?>&semestre=<?php echo $id_semestre; ?>&dia=<?php echo urlencode($dia_semana); ?>" 
                       class="btn btn-secondary" target="_blank">
                        <i class="fas fa-file-excel"></i> Excel
                    </a>
                    <a href="exportar_horarios.php?formato=csv&programa=<?php echo $id_programa; ?>&semestre=<?php echo $id_semestre; ?>&dia=<?php echo urlencode($dia_semana); ?>" 
                       class="btn btn-secondary" target="_blank">
                        <i class="fas fa-file-csv"></i> CSV
                    </a>
                    <button type="button" class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Reporte por programas -->
        <?php if (!empty($horarios_por_programa)): ?>
            <?php foreach ($horarios_por_programa as $nombre_programa => $horarios_programa): ?>
                <?php
                $total_horarios_programa = count($horarios_programa);
                $total_estudiantes_programa = array_sum(array_column($horarios_programa, 'estudiantes_inscritos'));
                $primer_horario = reset($horarios_programa);
                ?>
                
                <div class="programa-section">
                    <div class="programa-header">
                        <h3>
                            <i class="fas fa-graduation-cap"></i>
                            <?php echo htmlspecialchars($nombre_programa); ?>
                            <small style="color: var(--gray-600); font-size: 14px; margin-left: 10px;">
                                (<?php echo $primer_horario['codigo_programa']; ?>)
                            </small>
                        </h3>
                        
                        <div class="programa-stats">
                            <span class="programa-stat">
                                <i class="fas fa-calendar"></i> 
                                <?php echo $total_horarios_programa; ?> horarios
                            </span>
                            <span class="programa-stat">
                                <i class="fas fa-users"></i> 
                                <?php echo $total_estudiantes_programa; ?> estudiantes
                            </span>
                            <span class="programa-stat">
                                <i class="fas fa-star"></i> 
                                <?php 
                                    $creditos_totales = array_sum(array_column($horarios_programa, 'creditos'));
                                    echo $creditos_totales; 
                                ?> créditos totales
                            </span>
                        </div>
                    </div>
                    
                    <div class="horarios-grid">
                        <?php foreach ($horarios_programa as $horario): ?>
                            <?php
                            // Calcular porcentaje de ocupación
                            $capacidad = $horario['capacidad_grupo'] ?: $horario['capacidad'];
                            $porcentaje_ocupacion = $capacidad > 0 ? ($horario['estudiantes_inscritos'] / $capacidad) * 100 : 0;
                            
                            $clase_ocupacion = 'ocupacion-baja';
                            if ($porcentaje_ocupacion >= 90) {
                                $clase_ocupacion = 'ocupacion-alta';
                            } elseif ($porcentaje_ocupacion >= 70) {
                                $clase_ocupacion = 'ocupacion-media';
                            }
                            ?>
                            
                            <div class="horario-card">
                                <div class="horario-header">
                                    <h4><?php echo htmlspecialchars($horario['nombre_curso']); ?></h4>
                                    <span class="badge badge-secondary">
                                        <?php echo $horario['codigo_curso']; ?>
                                    </span>
                                </div>
                                
                                <div class="horario-details">
                                    <div class="detail-item">
                                        <div class="detail-label">Semestre:</div>
                                        <div class="detail-value"><?php echo $horario['semestre']; ?></div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Horario:</div>
                                        <div class="detail-value">
                                            <?php echo $horario['dia_semana']; ?> 
                                            <?php echo Funciones::formatearHora($horario['hora_inicio']); ?> - 
                                            <?php echo Funciones::formatearHora($horario['hora_fin']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Profesor:</div>
                                        <div class="detail-value">
                                            <?php echo htmlspecialchars($horario['profesor_nombre']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Aula:</div>
                                        <div class="detail-value">
                                            <?php echo htmlspecialchars($horario['nombre_aula']); ?>
                                            (Capacidad: <?php echo $horario['capacidad']; ?>)
                                        </div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Créditos:</div>
                                        <div class="detail-value">
                                            <?php echo $horario['creditos']; ?> | 
                                            <?php echo $horario['horas_semanales']; ?> horas/semana
                                        </div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Semestre Académico:</div>
                                        <div class="detail-value">
                                            <?php echo htmlspecialchars($horario['nombre_semestre']); ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="horario-footer">
                                    <div class="ocupacion <?php echo $clase_ocupacion; ?>">
                                        <i class="fas fa-users"></i>
                                        <?php echo $horario['estudiantes_inscritos']; ?> / 
                                        <?php echo $capacidad ?: '∞'; ?> estudiantes
                                        <?php if ($capacidad > 0): ?>
                                            (<?php echo number_format($porcentaje_ocupacion, 1); ?>%)
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div>
                                        <a href="../horarios/ver.php?id=<?php echo $horario['id_horario']; ?>" 
                                           class="btn btn-sm btn-primary" title="Ver Detalles">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3>No hay horarios programados</h3>
                <p>No se encontraron horarios con los filtros aplicados.</p>
                <a href="../horarios/crear.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Crear Primer Horario
                </a>
            </div>
        <?php endif; ?>
        
        <!-- Información del reporte -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-info-circle"></i> Información del Reporte</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Parámetros aplicados:</strong></p>
                        <ul>
                            <li><strong>Programa:</strong> <?php echo $id_programa > 0 ? htmlspecialchars($programas[array_search($id_programa, array_column($programas, 'id_programa'))]['nombre_programa']) : 'Todos'; ?></li>
                            <li><strong>Semestre Académico:</strong> <?php echo $id_semestre > 0 ? htmlspecialchars($semestres[array_search($id_semestre, array_column($semestres, 'id_semestre'))]['nombre_semestre']) : 'Todos'; ?></li>
                            <li><strong>Día:</strong> <?php echo !empty($dia_semana) ? $dia_semana : 'Todos'; ?></li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Totales del reporte:</strong></p>
                        <ul>
                            <li>Total Programas: <?php echo count($horarios_por_programa); ?></li>
                            <li>Total Horarios: <?php echo $total_horarios; ?></li>
                            <li>Total Estudiantes Inscritos: <?php echo $total_estudiantes; ?></li>
                            <li>Última actualización: <?php echo date('d/m/Y H:i:s'); ?></li>
                        </ul>
                    </div>
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
    // Mostrar/ocultar detalles al hacer clic
    document.addEventListener('DOMContentLoaded', function() {
        const horarioCards = document.querySelectorAll('.horario-card');
        horarioCards.forEach(card => {
            card.addEventListener('click', function(e) {
                if (!e.target.closest('a, button')) {
                    this.classList.toggle('expanded');
                }
            });
        });
        
        // Actualizar estadísticas en tiempo real
        function actualizarEstadisticas() {
            const totalHorarios = <?php echo $total_horarios; ?>;
            const totalEstudiantes = <?php echo $total_estudiantes; ?>;
            
            // Puedes agregar aquí lógica para actualizar estadísticas dinámicamente
            console.log('Estadísticas cargadas:', { totalHorarios, totalEstudiantes });
        }
        
        actualizarEstadisticas();
    });
    </script>
</body>
</html>