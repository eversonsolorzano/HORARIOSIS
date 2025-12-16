<?php
require_once '../includes/auth.php';
require_once '../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

// Obtener estadísticas
$stats = [];
$stats['estudiantes'] = $db->query("SELECT COUNT(*) as total FROM estudiantes WHERE estado = 'activo'")->fetch()['total'];
$stats['profesores'] = $db->query("SELECT COUNT(*) as total FROM profesores WHERE activo = 1")->fetch()['total'];
$stats['cursos'] = $db->query("SELECT COUNT(*) as total FROM cursos WHERE activo = 1")->fetch()['total'];
$stats['aulas'] = $db->query("SELECT COUNT(*) as total FROM aulas WHERE disponible = 1")->fetch()['total'];
$stats['semestres'] = $db->query("SELECT COUNT(*) as total FROM semestres_academicos")->fetch()['total'];

// Obtener semestre actual
$semestre_actual = $db->query("SELECT * FROM semestres_academicos WHERE estado = 'en_curso' LIMIT 1")->fetch();

// Obtener horarios del día
$dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$dia_numero = date('N'); // 1=Lunes, 7=Domingo

// Solo mostrar horarios si es día laboral (1-6)
$hoy = '';
$horarios_hoy = [];
if ($dia_numero >= 1 && $dia_numero <= 6) {
    $hoy = $dias[$dia_numero - 1]; // Restar 1 porque el array empieza en 0
    
    $stmt = $db->prepare("
        SELECT h.*, c.nombre_curso, p.nombres as prof_nombre, a.codigo_aula
        FROM horarios h
        JOIN cursos c ON h.id_curso = c.id_curso
        JOIN profesores p ON h.id_profesor = p.id_profesor
        JOIN aulas a ON h.id_aula = a.id_aula
        WHERE h.dia_semana = ? AND h.activo = 1
        ORDER BY h.hora_inicio
        LIMIT 10
    ");
    $stmt->execute([$hoy]);
    $horarios_hoy = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Coordinador - Sistema de Horarios</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Estilos adicionales para el dashboard */
        .semestre-actual {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-left: 6px solid var(--primary);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .semestre-actual h3 {
            color: var(--dark);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .semestre-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .semestre-item {
            background: white;
            padding: 15px;
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
        }
        
        .semestre-item strong {
            display: block;
            color: var(--primary);
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .semestre-item small {
            color: var(--gray-600);
            font-size: 13px;
        }
        
        .progress-bar {
            height: 8px;
            background: var(--gray-300);
            border-radius: 4px;
            margin-top: 5px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--success);
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fas fa-chalkboard-teacher"></i> Panel del Coordinador</h1>
                <div class="nav">
                    <a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    <a href="usuarios/"><i class="fas fa-users"></i> Usuarios</a>
                    <a href="programas/"><i class="fas fa-graduation-cap"></i> Programas</a>
                    <a href="estudiantes/"><i class="fas fa-user-graduate"></i> Estudiantes</a>
                    <a href="profesores/"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
                    <a href="semestres/"><i class="fas fa-calendar-alt"></i> Semestres</a>
                    <a href="cursos/"><i class="fas fa-book"></i> Cursos</a>
                    <a href="aulas/"><i class="fas fa-school"></i> Aulas</a>
                    <a href="horarios/"><i class="fas fa-calendar-alt"></i> Horarios</a>
                    <a href="inscripciones/"><i class="fas fa-clipboard-list"></i> Inscripciones</a>
                    <a href="../perfil.php"><i class="fas fa-user-circle"></i> Mi Perfil</a>
                    <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Salir</a>
                </div>
            </div>
            <div class="user-info">
                <span>Bienvenido, <?php echo htmlspecialchars($user['username']); ?></span>
                <span class="badge badge-coordinador">Coordinador</span>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <!-- Semestre Actual -->
        <?php if ($semestre_actual): ?>
        <div class="semestre-actual">
            <h3><i class="fas fa-star"></i> Semestre Actualmente en Curso</h3>
            <div class="semestre-info">
                <div class="semestre-item">
                    <strong><?php echo htmlspecialchars($semestre_actual['codigo_semestre']); ?></strong>
                    <small><?php echo htmlspecialchars($semestre_actual['nombre_semestre']); ?></small>
                </div>
                
                <div class="semestre-item">
                    <strong><?php echo Funciones::formatearFecha($semestre_actual['fecha_inicio']); ?></strong>
                    <small>Fecha de inicio</small>
                </div>
                
                <div class="semestre-item">
                    <strong><?php echo Funciones::formatearFecha($semestre_actual['fecha_fin']); ?></strong>
                    <small>Fecha de fin</small>
                </div>
                
                <div class="semestre-item">
                    <?php 
                    $hoy = new DateTime();
                    $inicio = new DateTime($semestre_actual['fecha_inicio']);
                    $fin = new DateTime($semestre_actual['fecha_fin']);
                    
                    $total_dias = $inicio->diff($fin)->days;
                    $dias_transcurridos = $inicio->diff($hoy)->days;
                    
                    if ($hoy > $fin) {
                        $porcentaje = 100;
                    } elseif ($hoy < $inicio) {
                        $porcentaje = 0;
                    } else {
                        $porcentaje = ($dias_transcurridos / $total_dias) * 100;
                    }
                    
                    $dias_restantes = $hoy->diff($fin)->days;
                    ?>
                    <strong><?php echo number_format($porcentaje, 1); ?>%</strong>
                    <small>Progreso (<?php echo $dias_restantes; ?> días restantes)</small>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo min(100, $porcentaje); ?>%;"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card stat-students">
                <div class="stat-icon">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="stat-info">
                    <h3>Estudiantes Activos</h3>
                    <div class="stat-number"><?php echo $stats['estudiantes']; ?></div>
                </div>
            </div>
            
            <div class="stat-card stat-teachers">
                <div class="stat-icon">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="stat-info">
                    <h3>Profesores</h3>
                    <div class="stat-number"><?php echo $stats['profesores']; ?></div>
                </div>
            </div>
            
            <div class="stat-card stat-courses">
                <div class="stat-icon">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-info">
                    <h3>Cursos Activos</h3>
                    <div class="stat-number"><?php echo $stats['cursos']; ?></div>
                </div>
            </div>
            
            <div class="stat-card stat-classrooms">
                <div class="stat-icon">
                    <i class="fas fa-school"></i>
                </div>
                <div class="stat-info">
                    <h3>Aulas Disponibles</h3>
                    <div class="stat-number"><?php echo $stats['aulas']; ?></div>
                </div>
            </div>
            
            <div class="stat-card stat-semesters">
                <div class="stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-info">
                    <h3>Semestres</h3>
                    <div class="stat-number"><?php echo $stats['semestres']; ?></div>
                    <small><?php echo $semestre_actual ? '1 en curso' : 'Sin semestre activo'; ?></small>
                </div>
            </div>
        </div>
        
        <div class="grid-2">
            <div class="card">
                <h2><i class="fas fa-calendar-day"></i> Horarios de Hoy 
                    <?php if ($hoy): ?>
                        (<?php echo $hoy; ?>)
                    <?php endif; ?>
                </h2>
                <?php if ($hoy && count($horarios_hoy) > 0): ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Hora</th>
                                    <th>Curso</th>
                                    <th>Profesor</th>
                                    <th>Aula</th>
                                    <th>Tipo</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($horarios_hoy as $horario): ?>
                                <tr>
                                    <td><?php echo Funciones::formatearHora($horario['hora_inicio']) . ' - ' . Funciones::formatearHora($horario['hora_fin']); ?></td>
                                    <td><?php echo htmlspecialchars($horario['nombre_curso']); ?></td>
                                    <td><?php echo htmlspecialchars($horario['prof_nombre']); ?></td>
                                    <td><?php echo htmlspecialchars($horario['codigo_aula']); ?></td>
                                    <td><span class="badge badge-info"><?php echo ucfirst($horario['tipo_clase']); ?></span></td>
                                    <td>
                                        <a href="horarios/ver.php?id=<?php echo $horario['id_horario']; ?>" 
                                           class="btn btn-sm btn-info">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer">
                        <a href="horarios/" class="btn btn-primary">
                            <i class="fas fa-calendar-alt"></i> Ver todos los horarios
                        </a>
                    </div>
                <?php elseif ($hoy): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <p>No hay horarios programados para hoy.</p>
                        <a href="horarios/crear.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Crear horario
                        </a>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-check"></i>
                        <p>Hoy es domingo, no hay clases programadas.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h2><i class="fas fa-bolt"></i> Acciones Rápidas</h2>
                <div class="quick-actions">
                    <a href="horarios/crear.php" class="action-btn">
                        <i class="fas fa-plus-circle"></i>
                        <span>Nuevo Horario</span>
                    </a>
                    <a href="estudiantes/crear.php" class="action-btn">
                        <i class="fas fa-user-plus"></i>
                        <span>Nuevo Estudiante</span>
                    </a>
                    <a href="profesores/crear.php" class="action-btn">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Nuevo Profesor</span>
                    </a>
                    <a href="semestres/crear.php" class="action-btn">
                        <i class="fas fa-calendar-plus"></i>
                        <span>Nuevo Semestre</span>
                    </a>
                    <a href="cursos/crear.php" class="action-btn">
                        <i class="fas fa-book-medical"></i>
                        <span>Nuevo Curso</span>
                    </a>
                    <a href="aulas/crear.php" class="action-btn">
                        <i class="fas fa-school"></i>
                        <span>Nueva Aula</span>
                    </a>
                    <a href="reportes/horarios_programa.php" class="action-btn">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reportes</span>
                    </a>
                </div>
                
                <h2 style="margin-top: 30px;"><i class="fas fa-bell"></i> Próximos Eventos</h2>
                <?php 
                // Obtener eventos próximos (7 días)
                $fecha_inicio = date('Y-m-d');
                $fecha_fin = date('Y-m-d', strtotime('+7 days'));
                
                $stmt = $db->prepare("
                    SELECT 
                        sa.codigo_semestre,
                        sa.nombre_semestre,
                        sa.fecha_inicio,
                        sa.fecha_fin,
                        'inicio_semestre' as tipo
                    FROM semestres_academicos sa
                    WHERE sa.fecha_inicio BETWEEN ? AND ?
                    AND sa.estado = 'planificación'
                    
                    UNION
                    
                    SELECT 
                        sa.codigo_semestre,
                        sa.nombre_semestre,
                        sa.fecha_fin,
                        sa.fecha_fin,
                        'fin_semestre' as tipo
                    FROM semestres_academicos sa
                    WHERE sa.fecha_fin BETWEEN ? AND ?
                    AND sa.estado = 'en_curso'
                    
                    ORDER BY fecha_inicio
                    LIMIT 5
                ");
                $stmt->execute([$fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin]);
                $eventos = $stmt->fetchAll();
                ?>
                
                <?php if (count($eventos) > 0): ?>
                    <div class="events-list">
                        <?php foreach ($eventos as $evento): ?>
                        <div class="event-item">
                            <div class="event-icon">
                                <?php if ($evento['tipo'] == 'inicio_semestre'): ?>
                                    <i class="fas fa-play-circle text-success"></i>
                                <?php else: ?>
                                    <i class="fas fa-flag-checkered text-warning"></i>
                                <?php endif; ?>
                            </div>
                            <div class="event-details">
                                <div class="event-title">
                                    <?php echo $evento['tipo'] == 'inicio_semestre' ? 'Inicio' : 'Fin'; ?> de semestre
                                </div>
                                <div class="event-subtitle">
                                    <?php echo htmlspecialchars($evento['nombre_semestre']); ?>
                                </div>
                                <div class="event-date">
                                    <i class="far fa-calendar"></i> 
                                    <?php echo Funciones::formatearFecha($evento['fecha_inicio']); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state small">
                        <i class="far fa-calendar-check"></i>
                        <p>No hay eventos próximos en los próximos 7 días.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="../assets/js/main.js"></script>
    <script>
        // Actualizar progreso del semestre en tiempo real
        function updateSemesterProgress() {
            const today = new Date();
            const progressBars = document.querySelectorAll('.progress-fill');
            
            progressBars.forEach(bar => {
                const parentCard = bar.closest('.semestre-item');
                if (parentCard) {
                    const startDateText = document.querySelector('.semestre-item:nth-child(2) strong')?.textContent;
                    const endDateText = document.querySelector('.semestre-item:nth-child(3) strong')?.textContent;
                    
                    if (startDateText && endDateText) {
                        const startDate = new Date(startDateText.split('/').reverse().join('-'));
                        const endDate = new Date(endDateText.split('/').reverse().join('-'));
                        
                        const totalDays = Math.ceil((endDate - startDate) / (1000 * 60 * 60 * 24));
                        const daysPassed = Math.ceil((today - startDate) / (1000 * 60 * 60 * 24));
                        const percentage = Math.min(100, Math.max(0, (daysPassed / totalDays) * 100));
                        
                        bar.style.width = percentage + '%';
                        
                        // Actualizar texto
                        const strongElement = parentCard.querySelector('strong');
                        const smallElement = parentCard.querySelector('small');
                        
                        if (strongElement && smallElement) {
                            strongElement.textContent = percentage.toFixed(1) + '%';
                            
                            const daysRemaining = Math.ceil((endDate - today) / (1000 * 60 * 60 * 24));
                            if (daysRemaining > 0) {
                                smallElement.textContent = 'Progreso (' + daysRemaining + ' días restantes)';
                            } else if (daysRemaining === 0) {
                                smallElement.textContent = 'Progreso (Finaliza hoy)';
                            } else {
                                smallElement.textContent = 'Progreso (Finalizado)';
                            }
                        }
                    }
                }
            });
        }
        
        // Actualizar cada hora
        updateSemesterProgress();
        setInterval(updateSemesterProgress, 3600000); // Cada hora
    </script>
</body>
</html>