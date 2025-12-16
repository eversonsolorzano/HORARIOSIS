<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

// Obtener ID del semestre
$id_semestre = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_semestre) {
    Funciones::redireccionar('index.php', 'ID de semestre no válido', 'error');
}

// Obtener datos del semestre
$stmt = $db->prepare("SELECT * FROM semestres_academicos WHERE id_semestre = ?");
$stmt->execute([$id_semestre]);
$semestre = $stmt->fetch();

if (!$semestre) {
    Funciones::redireccionar('index.php', 'Semestre no encontrado', 'error');
}

// Obtener estadísticas del semestre
$stats = [];

// Horarios activos
$stmt = $db->prepare("
    SELECT COUNT(*) as total,
           SUM(CASE WHEN tipo_clase = 'teoría' THEN 1 ELSE 0 END) as teoria,
           SUM(CASE WHEN tipo_clase = 'práctica' THEN 1 ELSE 0 END) as practica,
           SUM(CASE WHEN tipo_clase = 'laboratorio' THEN 1 ELSE 0 END) as laboratorio,
           SUM(CASE WHEN tipo_clase = 'taller' THEN 1 ELSE 0 END) as taller
    FROM horarios 
    WHERE id_semestre = ? AND activo = 1
");
$stmt->execute([$id_semestre]);
$stats['horarios'] = $stmt->fetch();

// Estudiantes inscritos
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT i.id_estudiante) as total
    FROM inscripciones i
    JOIN horarios h ON i.id_horario = h.id_horario
    WHERE h.id_semestre = ? AND i.estado = 'inscrito'
");
$stmt->execute([$id_semestre]);
$stats['estudiantes'] = $stmt->fetch();

// Profesores asignados
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT h.id_profesor) as total
    FROM horarios h
    WHERE h.id_semestre = ? AND h.activo = 1
");
$stmt->execute([$id_semestre]);
$stats['profesores'] = $stmt->fetch();

// Cursos impartidos
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT h.id_curso) as total
    FROM horarios h
    WHERE h.id_semestre = ? AND h.activo = 1
");
$stmt->execute([$id_semestre]);
$stats['cursos'] = $stmt->fetch();

// Obtener horarios recientes
$stmt = $db->prepare("
    SELECT 
        h.*,
        c.nombre_curso,
        c.codigo_curso,
        CONCAT(p.nombres, ' ', p.apellidos) as profesor_nombre,
        a.codigo_aula,
        pr.nombre_programa
    FROM horarios h
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN profesores p ON h.id_profesor = p.id_profesor
    JOIN aulas a ON h.id_aula = a.id_aula
    JOIN programas_estudio pr ON c.id_programa = pr.id_programa
    WHERE h.id_semestre = ? AND h.activo = 1
    ORDER BY h.dia_semana, h.hora_inicio
    LIMIT 15
");
$stmt->execute([$id_semestre]);
$horarios_recientes = $stmt->fetchAll();

// Obtener distribución por días
$stmt = $db->prepare("
    SELECT 
        dia_semana,
        COUNT(*) as cantidad,
        GROUP_CONCAT(c.nombre_curso SEPARATOR ', ') as cursos
    FROM horarios h
    JOIN cursos c ON h.id_curso = c.id_curso
    WHERE h.id_semestre = ? AND h.activo = 1
    GROUP BY dia_semana
    ORDER BY FIELD(dia_semana, 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado')
");
$stmt->execute([$id_semestre]);
$distribucion_dias = $stmt->fetchAll();

// Calcular progreso del semestre
$hoy = new DateTime();
$inicio = new DateTime($semestre['fecha_inicio']);
$fin = new DateTime($semestre['fecha_fin']);

$total_dias = $inicio->diff($fin)->days;
$dias_transcurridos = $inicio->diff($hoy)->days;

if ($hoy > $fin) {
    $porcentaje = 100;
    $dias_restantes = 0;
} elseif ($hoy < $inicio) {
    $porcentaje = 0;
    $dias_restantes = $inicio->diff($hoy)->days;
} else {
    $porcentaje = ($dias_transcurridos / $total_dias) * 100;
    $dias_restantes = $hoy->diff($fin)->days;
}

// Formatear fechas para display
$fecha_inicio_formatted = Funciones::formatearFecha($semestre['fecha_inicio']);
$fecha_fin_formatted = Funciones::formatearFecha($semestre['fecha_fin']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ver Semestre - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .semestre-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 30px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .semestre-header h2 {
            margin: 0 0 10px 0;
            font-size: 32px;
        }
        
        .semestre-header .codigo {
            font-size: 20px;
            opacity: 0.9;
        }
        
        .semestre-header .estado {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            margin-top: 15px;
        }
        
        .estado-planificacion { background: #ff9800; color: white; }
        .estado-en_curso { background: #4caf50; color: white; }
        .estado-finalizado { background: #757575; color: white; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            text-align: center;
        }
        
        .stat-card .stat-icon {
            font-size: 32px;
            color: var(--primary);
            margin-bottom: 15px;
        }
        
        .stat-card .stat-number {
            font-size: 36px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .stat-card .stat-label {
            color: var(--gray-600);
            font-size: 14px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .info-card {
            background: white;
            padding: 25px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        
        .info-card h3 {
            color: var(--dark);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-item {
            display: flex;
            margin-bottom: 15px;
        }
        
        .info-label {
            font-weight: 600;
            color: var(--gray-700);
            width: 150px;
            flex-shrink: 0;
        }
        
        .info-value {
            color: var(--dark);
            flex: 1;
        }
        
        .progress-container {
            margin-top: 20px;
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .progress-bar {
            height: 12px;
            background: var(--gray-300);
            border-radius: 6px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 6px;
        }
        
        .distribution-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .distribution-item {
            background: var(--gray-50);
            padding: 15px;
            border-radius: var(--radius);
            text-align: center;
        }
        
        .distribution-day {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .distribution-count {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .distribution-label {
            font-size: 12px;
            color: var(--gray-600);
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--gray-600);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .actions-bar {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 30px;
        }
        
        .type-distribution {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        
        .type-item {
            text-align: center;
            padding: 10px;
            background: var(--gray-50);
            border-radius: var(--radius);
        }
        
        .type-count {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .type-label {
            font-size: 12px;
            color: var(--gray-600);
            text-transform: capitalize;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-calendar-alt"></i> Detalles del Semestre</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-calendar-alt"></i> Semestres</a>
                <a href="ver.php?id=<?php echo $id_semestre; ?>" class="active"><i class="fas fa-eye"></i> Ver Semestre</a>
                <a href="editar.php?id=<?php echo $id_semestre; ?>"><i class="fas fa-edit"></i> Editar</a>
                <a href="cambiar_estado.php?id=<?php echo $id_semestre; ?>"><i class="fas fa-sync-alt"></i> Cambiar Estado</a>
            </div>
        </div>
        
        <!-- Encabezado del Semestre -->
        <div class="semestre-header">
            <h2><?php echo htmlspecialchars($semestre['nombre_semestre']); ?></h2>
            <div class="codigo"><?php echo htmlspecialchars($semestre['codigo_semestre']); ?></div>
            <div class="estado estado-<?php echo $semestre['estado']; ?>">
                <?php 
                $estado_text = [
                    'planificación' => 'En Planificación',
                    'en_curso' => 'En Curso',
                    'finalizado' => 'Finalizado'
                ];
                echo $estado_text[$semestre['estado']];
                ?>
            </div>
        </div>
        
        <!-- Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-number"><?php echo $stats['horarios']['total'] ?? 0; ?></div>
                <div class="stat-label">Horarios Activos</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="stat-number"><?php echo $stats['estudiantes']['total'] ?? 0; ?></div>
                <div class="stat-label">Estudiantes Inscritos</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="stat-number"><?php echo $stats['profesores']['total'] ?? 0; ?></div>
                <div class="stat-label">Profesores Asignados</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-number"><?php echo $stats['cursos']['total'] ?? 0; ?></div>
                <div class="stat-label">Cursos Impartidos</div>
            </div>
        </div>
        
        <!-- Información Principal -->
        <div class="info-grid">
            <!-- Información Básica -->
            <div class="info-card">
                <h3><i class="fas fa-info-circle"></i> Información del Semestre</h3>
                
                <div class="info-item">
                    <div class="info-label">Código:</div>
                    <div class="info-value"><?php echo htmlspecialchars($semestre['codigo_semestre']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Nombre:</div>
                    <div class="info-value"><?php echo htmlspecialchars($semestre['nombre_semestre']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Estado:</div>
                    <div class="info-value">
                        <span class="estado estado-<?php echo $semestre['estado']; ?>" style="padding: 4px 12px; font-size: 12px;">
                            <?php echo $estado_text[$semestre['estado']]; ?>
                        </span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Fecha Inicio:</div>
                    <div class="info-value"><?php echo $fecha_inicio_formatted; ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Fecha Fin:</div>
                    <div class="info-value"><?php echo $fecha_fin_formatted; ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Duración:</div>
                    <div class="info-value">
                        <?php echo $total_dias; ?> días 
                        (<?php echo floor($total_dias/30); ?> meses aproximadamente)
                    </div>
                </div>
                
                <!-- Progreso -->
                <div class="progress-container">
                    <div class="progress-header">
                        <span>Progreso del Semestre</span>
                        <span><?php echo number_format($porcentaje, 1); ?>%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo min(100, $porcentaje); ?>%;"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 12px; margin-top: 5px; color: var(--gray-600);">
                        <span>Inicio: <?php echo $fecha_inicio_formatted; ?></span>
                        <span>
                            <?php if ($semestre['estado'] == 'en_curso'): ?>
                                <?php echo $dias_restantes; ?> días restantes
                            <?php elseif ($semestre['estado'] == 'finalizado'): ?>
                                Finalizado
                            <?php else: ?>
                                Inicia en <?php echo $dias_restantes; ?> días
                            <?php endif; ?>
                        </span>
                        <span>Fin: <?php echo $fecha_fin_formatted; ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Distribución -->
            <div class="info-card">
                <h3><i class="fas fa-chart-pie"></i> Distribución</h3>
                
                <div>
                    <strong>Horarios por Día:</strong>
                    <?php if (count($distribucion_dias) > 0): ?>
                        <div class="distribution-grid">
                            <?php foreach ($distribucion_dias as $distribucion): ?>
                            <div class="distribution-item">
                                <div class="distribution-day"><?php echo $distribucion['dia_semana']; ?></div>
                                <div class="distribution-count"><?php echo $distribucion['cantidad']; ?></div>
                                <div class="distribution-label">horarios</div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p style="color: var(--gray-600); text-align: center; padding: 20px;">
                            No hay horarios asignados
                        </p>
                    <?php endif; ?>
                </div>
                
                <div style="margin-top: 20px;">
                    <strong>Tipos de Clase:</strong>
                    <?php if ($stats['horarios']['total'] > 0): ?>
                        <div class="type-distribution">
                            <?php if ($stats['horarios']['teoria'] > 0): ?>
                            <div class="type-item">
                                <div class="type-count"><?php echo $stats['horarios']['teoria']; ?></div>
                                <div class="type-label">Teoría</div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($stats['horarios']['practica'] > 0): ?>
                            <div class="type-item">
                                <div class="type-count"><?php echo $stats['horarios']['practica']; ?></div>
                                <div class="type-label">Práctica</div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($stats['horarios']['laboratorio'] > 0): ?>
                            <div class="type-item">
                                <div class="type-count"><?php echo $stats['horarios']['laboratorio']; ?></div>
                                <div class="type-label">Laboratorio</div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($stats['horarios']['taller'] > 0): ?>
                            <div class="type-item">
                                <div class="type-count"><?php echo $stats['horarios']['taller']; ?></div>
                                <div class="type-label">Taller</div>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p style="color: var(--gray-600); text-align: center; padding: 10px;">
                            Sin tipos de clase asignados
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Horarios Recientes -->
        <div class="info-card">
            <h3><i class="fas fa-calendar-day"></i> Horarios Asignados (<?php echo $stats['horarios']['total'] ?? 0; ?>)</h3>
            
            <?php if (count($horarios_recientes) > 0): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Día</th>
                                <th>Hora</th>
                                <th>Curso</th>
                                <th>Profesor</th>
                                <th>Aula</th>
                                <th>Tipo</th>
                                <th>Programa</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($horarios_recientes as $horario): ?>
                            <tr>
                                <td><?php echo $horario['dia_semana']; ?></td>
                                <td>
                                    <?php echo Funciones::formatearHora($horario['hora_inicio']); ?> - 
                                    <?php echo Funciones::formatearHora($horario['hora_fin']); ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($horario['codigo_curso']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($horario['nombre_curso']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($horario['profesor_nombre']); ?></td>
                                <td><?php echo htmlspecialchars($horario['codigo_aula']); ?></td>
                                <td>
                                    <span class="badge badge-primary">
                                        <?php echo ucfirst($horario['tipo_clase']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($horario['nombre_programa']); ?></td>
                                <td>
                                    <a href="../../horarios/ver.php?id=<?php echo $horario['id_horario']; ?>" 
                                       class="btn btn-sm btn-info" title="Ver">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="margin-top: 15px; text-align: center;">
                    <a href="../../horarios/index.php?semestre=<?php echo $id_semestre; ?>" class="btn btn-primary">
                        <i class="fas fa-list"></i> Ver todos los horarios de este semestre
                    </a>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <p>No hay horarios asignados a este semestre</p>
                    <a href="../../horarios/crear.php?semestre=<?php echo $id_semestre; ?>" class="btn btn-success">
                        <i class="fas fa-plus"></i> Crear Horario
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Barra de acciones -->
        <div class="actions-bar">
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver a la lista
            </a>
            <a href="editar.php?id=<?php echo $id_semestre; ?>" class="btn btn-warning">
                <i class="fas fa-edit"></i> Editar Semestre
            </a>
            <a href="cambiar_estado.php?id=<?php echo $id_semestre; ?>" class="btn btn-primary">
                <i class="fas fa-sync-alt"></i> Cambiar Estado
            </a>
            <?php if ($semestre['estado'] != 'finalizado'): ?>
            <a href="../../horarios/crear.php?semestre=<?php echo $id_semestre; ?>" class="btn btn-success">
                <i class="fas fa-plus"></i> Agregar Horario
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Actualizar progreso en tiempo real (solo para semestres en curso)
        const estado = '<?php echo $semestre["estado"]; ?>';
        
        if (estado === 'en_curso') {
            function updateProgress() {
                const today = new Date();
                const startDate = new Date('<?php echo $semestre["fecha_inicio"]; ?>');
                const endDate = new Date('<?php echo $semestre["fecha_fin"]; ?>');
                
                const totalDays = Math.ceil((endDate - startDate) / (1000 * 60 * 60 * 24));
                const daysPassed = Math.ceil((today - startDate) / (1000 * 60 * 60 * 24));
                const percentage = Math.min(100, Math.max(0, (daysPassed / totalDays) * 100));
                
                const daysRemaining = Math.ceil((endDate - today) / (1000 * 60 * 60 * 24));
                
                // Actualizar barra de progreso
                const progressFill = document.querySelector('.progress-fill');
                if (progressFill) {
                    progressFill.style.width = percentage + '%';
                }
                
                // Actualizar porcentaje
                const percentageSpan = document.querySelector('.progress-header span:nth-child(2)');
                if (percentageSpan) {
                    percentageSpan.textContent = percentage.toFixed(1) + '%';
                }
                
                // Actualizar días restantes
                const daysSpan = document.querySelector('.progress-container div:last-child span:nth-child(2)');
                if (daysSpan) {
                    if (daysRemaining > 0) {
                        daysSpan.textContent = daysRemaining + ' días restantes';
                    } else if (daysRemaining === 0) {
                        daysSpan.textContent = 'Finaliza hoy';
                    } else {
                        daysSpan.textContent = 'Finalizado';
                    }
                }
            }
            
            // Actualizar cada hora
            updateProgress();
            setInterval(updateProgress, 3600000);
        }
    </script>
</body>
</html>