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

// Verificar si se encontró el profesor
if (!$profesor) {
    // Si no existe, crear un registro básico
    $stmt = $db->prepare("
        INSERT INTO profesores (id_usuario, codigo_profesor, nombres, apellidos, documento_identidad, especialidad) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $codigo = 'PROF-' . date('Y') . str_pad($user['id'], 4, '0', STR_PAD_LEFT);
    $stmt->execute([
        $user['id'], 
        $codigo, 
        $user['username'], 
        'Apellido', 
        '00000000', 
        'Especialidad por definir'
    ]);
    
    // Obtener el profesor recién creado
    $stmt = $db->prepare("SELECT * FROM profesores WHERE id_usuario = ?");
    $stmt->execute([$user['id']]);
    $profesor = $stmt->fetch();
}

// Obtener estadísticas del profesor - con verificación
$stats = [
    'cursos' => 0,
    'horarios' => 0,
    'estudiantes' => 0
];

$stmt = $db->prepare("
    SELECT COUNT(DISTINCT h.id_curso) as cursos, 
           COUNT(DISTINCT h.id_horario) as horarios,
           COUNT(DISTINCT i.id_estudiante) as estudiantes
    FROM horarios h
    LEFT JOIN inscripciones i ON h.id_horario = i.id_horario
    WHERE h.id_profesor = ? AND h.activo = 1
");
$stmt->execute([$profesor['id_profesor']]);
$stats_prof = $stmt->fetch();

if ($stats_prof) {
    $stats['cursos'] = $stats_prof['cursos'] ?? 0;
    $stats['horarios'] = $stats_prof['horarios'] ?? 0;
    $stats['estudiantes'] = $stats_prof['estudiantes'] ?? 0;
}

// Obtener horarios de hoy del profesor
$dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$dia_numero = date('N');
$horarios_hoy = [];

if ($dia_numero >= 1 && $dia_numero <= 6) {
    $hoy = $dias[$dia_numero - 1];
    
    $stmt = $db->prepare("
        SELECT h.*, c.nombre_curso, c.codigo_curso, a.codigo_aula
        FROM horarios h
        JOIN cursos c ON h.id_curso = c.id_curso
        JOIN aulas a ON h.id_aula = a.id_aula
        WHERE h.id_profesor = ? AND h.dia_semana = ? AND h.activo = 1
        ORDER BY h.hora_inicio
        LIMIT 10
    ");
    $stmt->execute([$profesor['id_profesor'], $hoy]);
    $horarios_hoy = $stmt->fetchAll();
}

// Obtener próximos horarios (esta semana)
$stmt = $db->prepare("
    SELECT h.*, c.nombre_curso, a.codigo_aula
    FROM horarios h
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN aulas a ON h.id_aula = a.id_aula
    WHERE h.id_profesor = ? AND h.activo = 1
    ORDER BY h.dia_semana, h.hora_inicio
    LIMIT 5
");
$stmt->execute([$profesor['id_profesor']]);
$proximos_horarios = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Profesor - Sistema de Horarios</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fas fa-chalkboard"></i> Panel del Profesor</h1>
                <div class="nav">
                    <a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    <a href="mis_horarios.php"><i class="fas fa-calendar-alt"></i> Mis Horarios</a>
                    <a href="mis_cursos.php"><i class="fas fa-book"></i> Mis Cursos</a>
                    <a href="mis_estudiantes.php"><i class="fas fa-user-graduate"></i> Mis Estudiantes</a>
                    <a href="../perfil.php"><i class="fas fa-user-circle"></i> Mi Perfil</a>
                    <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Salir</a>
                </div>
            </div>
            <div class="user-info">
                <span>Bienvenido, <?php echo htmlspecialchars($profesor['nombres'] . ' ' . $profesor['apellidos']); ?></span>
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
        
        <div class="stats-grid">
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
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-info">
                    <h3>Horarios Semanales</h3>
                    <div class="stat-number"><?php echo $stats['horarios']; ?></div>
                </div>
            </div>
            
            <div class="stat-card stat-students">
                <div class="stat-icon">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="stat-info">
                    <h3>Total Estudiantes</h3>
                    <div class="stat-number"><?php echo $stats['estudiantes']; ?></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: #ed8936;">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="stat-info">
                    <h3>Especialidad</h3>
                    <div style="font-size: 18px; color: #2d3748;"><?php echo htmlspecialchars($profesor['especialidad'] ?? 'No definida'); ?></div>
                </div>
            </div>
        </div>
        
        <div class="grid-2">
            <div class="card">
                <h2><i class="fas fa-calendar-day"></i> Horarios de Hoy 
                    <?php if ($dia_numero >= 1 && $dia_numero <= 6): ?>
                        (<?php echo $dias[$dia_numero - 1]; ?>)
                    <?php endif; ?>
                </h2>
                <?php if ($dia_numero >= 1 && $dia_numero <= 6 && count($horarios_hoy) > 0): ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Hora</th>
                                    <th>Curso</th>
                                    <th>Aula</th>
                                    <th>Tipo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($horarios_hoy as $horario): ?>
                                <tr>
                                    <td><?php echo Funciones::formatearHora($horario['hora_inicio'] ?? '00:00:00') . ' - ' . Funciones::formatearHora($horario['hora_fin'] ?? '00:00:00'); ?></td>
                                    <td><?php echo htmlspecialchars($horario['nombre_curso'] ?? 'Curso no definido'); ?> (<?php echo $horario['codigo_curso'] ?? 'N/A'; ?>)</td>
                                    <td><?php echo htmlspecialchars($horario['codigo_aula'] ?? 'N/A'); ?></td>
                                    <td><span class="badge badge-info"><?php echo ucfirst($horario['tipo_clase'] ?? 'teoría'); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
                <?php if (count($proximos_horarios) > 0): ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Día</th>
                                    <th>Hora</th>
                                    <th>Curso</th>
                                    <th>Aula</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($proximos_horarios as $horario): ?>
                                <tr>
                                    <td><?php echo $horario['dia_semana'] ?? 'N/A'; ?></td>
                                    <td><?php echo Funciones::formatearHora($horario['hora_inicio'] ?? '00:00:00') . ' - ' . Funciones::formatearHora($horario['hora_fin'] ?? '00:00:00'); ?></td>
                                    <td><?php echo htmlspecialchars($horario['nombre_curso'] ?? 'Curso no definido'); ?></td>
                                    <td><?php echo htmlspecialchars($horario['codigo_aula'] ?? 'N/A'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-plus"></i>
                        <p>No tienes horarios asignados aún.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card">
            <h2><i class="fas fa-bolt"></i> Acciones Rápidas</h2>
            <div class="quick-actions">
                <a href="mis_horarios.php" class="action-btn">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Ver Mis Horarios</span>
                </a>
                <a href="mis_cursos.php" class="action-btn">
                    <i class="fas fa-book-open"></i>
                    <span>Mis Cursos</span>
                </a>
                <a href="mis_estudiantes.php" class="action-btn">
                    <i class="fas fa-users"></i>
                    <span>Mis Estudiantes</span>
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