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

// Obtener estadísticas del estudiante
$stats = [];
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT i.id_inscripcion) as cursos_inscritos,
        SUM(CASE WHEN i.estado = 'aprobado' THEN 1 ELSE 0 END) as cursos_aprobados,
        SUM(CASE WHEN i.estado = 'reprobado' THEN 1 ELSE 0 END) as cursos_reprobados
    FROM inscripciones i
    JOIN horarios h ON i.id_horario = h.id_horario
    WHERE i.id_estudiante = ?
");
$stmt->execute([$estudiante['id_estudiante']]);
$stats_est = $stmt->fetch();

$stats['inscritos'] = $stats_est['cursos_inscritos'];
$stats['aprobados'] = $stats_est['cursos_aprobados'];
$stats['reprobados'] = $stats_est['cursos_reprobados'];

// Obtener horarios de hoy del estudiante
$dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$dia_numero = date('N');
$horarios_hoy = [];

if ($dia_numero >= 1 && $dia_numero <= 6) {
    $hoy = $dias[$dia_numero - 1];
    
    $stmt = $db->prepare("
        SELECT h.*, c.nombre_curso, c.codigo_curso, a.codigo_aula, p.nombres as profesor
        FROM inscripciones i
        JOIN horarios h ON i.id_horario = h.id_horario
        JOIN cursos c ON h.id_curso = c.id_curso
        JOIN aulas a ON h.id_aula = a.id_aula
        JOIN profesores p ON h.id_profesor = p.id_profesor
        WHERE i.id_estudiante = ? AND i.estado = 'inscrito' 
        AND h.dia_semana = ? AND h.activo = 1
        ORDER BY h.hora_inicio
        LIMIT 10
    ");
    $stmt->execute([$estudiante['id_estudiante'], $hoy]);
    $horarios_hoy = $stmt->fetchAll();
}

// Obtener próximas clases
$stmt = $db->prepare("
    SELECT h.*, c.nombre_curso, c.codigo_curso, a.codigo_aula, p.nombres as profesor
    FROM inscripciones i
    JOIN horarios h ON i.id_horario = h.id_horario
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN aulas a ON h.id_aula = a.id_aula
    JOIN profesores p ON h.id_profesor = p.id_profesor
    WHERE i.id_estudiante = ? AND i.estado = 'inscrito' 
    AND h.activo = 1
    ORDER BY h.dia_semana, h.hora_inicio
    LIMIT 5
");
$stmt->execute([$estudiante['id_estudiante']]);
$proximas_clases = $stmt->fetchAll();

// Obtener últimas calificaciones
$stmt = $db->prepare("
    SELECT c.nombre_curso, c.codigo_curso, i.nota_final, i.estado
    FROM inscripciones i
    JOIN horarios h ON i.id_horario = h.id_horario
    JOIN cursos c ON h.id_curso = c.id_curso
    WHERE i.id_estudiante = ? AND i.nota_final IS NOT NULL
    ORDER BY i.fecha_inscripcion DESC
    LIMIT 5
");
$stmt->execute([$estudiante['id_estudiante']]);
$calificaciones = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Estudiante - Sistema de Horarios</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fas fa-user-graduate"></i> Panel del Estudiante</h1>
                <div class="nav">
                    <a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    <a href="mi_horario.php"><i class="fas fa-calendar-alt"></i> Mi Horario</a>
                    <a href="mis_cursos.php"><i class="fas fa-book"></i> Mis Cursos</a>
                    <a href="inscribir_curso.php"><i class="fas fa-plus-circle"></i> Inscribir Curso</a>
                    <a href="mis_calificaciones.php"><i class="fas fa-star"></i> Mis Calificaciones</a>
                    <a href="../perfil.php"><i class="fas fa-user-circle"></i> Mi Perfil</a>
                    <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Salir</a>
                </div>
            </div>
            <div class="user-info">
                <span>Bienvenido, <?php echo htmlspecialchars($estudiante['nombres'] . ' ' . $estudiante['apellidos']); ?></span>
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
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #4299e1;">
                    <i class="fas fa-id-card"></i>
                </div>
                <div class="stat-info">
                    <h3>Código</h3>
                    <div style="font-size: 18px; color: #2d3748;"><?php echo htmlspecialchars($estudiante['codigo_estudiante']); ?></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: #48bb78;">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="stat-info">
                    <h3>Programa</h3>
                    <div style="font-size: 18px; color: #2d3748;"><?php echo htmlspecialchars($estudiante['nombre_programa']); ?></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: #ed8936;">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-info">
                    <h3>Cursos Inscritos</h3>
                    <div class="stat-number"><?php echo $stats['inscritos']; ?></div>
                </div>
            </div>
            
            <div class="stat-card stat-students">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3>Cursos Aprobados</h3>
                    <div class="stat-number"><?php echo $stats['aprobados']; ?></div>
                </div>
            </div>
        </div>
        
        <div class="grid-3">
            <div class="card">
                <h2><i class="fas fa-calendar-day"></i> Clases de Hoy 
                    <?php if ($dia_numero >= 1 && $dia_numero <= 6): ?>
                        (<?php echo $dias[$dia_numero - 1]; ?>)
                    <?php endif; ?>
                </h2>
                <?php if ($dia_numero >= 1 && $dia_numero <= 6 && count($horarios_hoy) > 0): ?>
                    <div style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($horarios_hoy as $horario): ?>
                        <div class="schedule-item">
                            <div class="schedule-time">
                                <?php echo Funciones::formatearHora($horario['hora_inicio']); ?>
                            </div>
                            <div class="schedule-course">
                                <strong><?php echo htmlspecialchars($horario['nombre_curso']); ?></strong><br>
                                <small><?php echo htmlspecialchars($horario['profesor']); ?></small>
                            </div>
                            <div class="schedule-classroom">
                                <?php echo htmlspecialchars($horario['codigo_aula']); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($dia_numero >= 1 && $dia_numero <= 6): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <p>No tienes clases programadas para hoy.</p>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-check"></i>
                        <p>Hoy es domingo, no hay clases programadas.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h2><i class="fas fa-clock"></i> Próximas Clases</h2>
                <?php if (count($proximas_clases) > 0): ?>
                    <div style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($proximas_clases as $clase): ?>
                        <div class="schedule-item">
                            <div>
                                <strong><?php echo $clase['dia_semana']; ?></strong><br>
                                <small><?php echo Funciones::formatearHora($clase['hora_inicio']); ?></small>
                            </div>
                            <div>
                                <?php echo htmlspecialchars($clase['nombre_curso']); ?><br>
                                <small><?php echo htmlspecialchars($clase['profesor']); ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-plus"></i>
                        <p>No tienes clases inscritas.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h2><i class="fas fa-star"></i> Últimas Calificaciones</h2>
                <?php if (count($calificaciones) > 0): ?>
                    <div style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($calificaciones as $calif): ?>
                        <div class="schedule-item">
                            <div>
                                <strong><?php echo htmlspecialchars($calif['nombre_curso']); ?></strong><br>
                                <small><?php echo $calif['codigo_curso']; ?></small>
                            </div>
                            <div style="text-align: right;">
                                <strong style="font-size: 18px; color: <?php echo $calif['nota_final'] >= 10.5 ? '#38a169' : '#e53e3e'; ?>">
                                    <?php echo number_format($calif['nota_final'], 1); ?>
                                </strong><br>
                                <span class="badge <?php echo $calif['estado'] == 'aprobado' ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo ucfirst($calif['estado']); ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-star-half-alt"></i>
                        <p>No hay calificaciones registradas.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card">
            <h2><i class="fas fa-bolt"></i> Acciones Rápidas</h2>
            <div class="quick-actions">
                <a href="mi_horario.php" class="action-btn">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Ver Mi Horario</span>
                </a>
                <a href="mis_cursos.php" class="action-btn">
                    <i class="fas fa-book-open"></i>
                    <span>Mis Cursos</span>
                </a>
                <a href="inscribir_curso.php" class="action-btn">
                    <i class="fas fa-plus-circle"></i>
                    <span>Inscribir Curso</span>
                </a>
                <a href="../perfil.php" class="action-btn">
                    <i class="fas fa-user-edit"></i>
                    <span>Editar Perfil</span>
                </a>
            </div>
        </div>
    </div>
    
    <script src="../assets/js/main.js"></script>
</body>
</html>