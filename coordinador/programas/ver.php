<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

$id_programa = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_programa) {
    Funciones::redireccionar('index.php', 'ID de programa no válido', 'error');
}

// Obtener datos completos del programa
$stmt = $db->prepare("
    SELECT p.*, u.username as coordinador_username, u.email as coordinador_email
    FROM programas_estudio p
    LEFT JOIN usuarios u ON p.coordinador_id = u.id_usuario
    WHERE p.id_programa = ?
");
$stmt->execute([$id_programa]);
$programa = $stmt->fetch();

if (!$programa) {
    Funciones::redireccionar('index.php', 'Programa no encontrado', 'error');
}

// Obtener estadísticas
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT e.id_estudiante) as total_estudiantes,
        COUNT(DISTINCT CASE WHEN e.estado = 'activo' THEN e.id_estudiante END) as estudiantes_activos,
        COUNT(DISTINCT CASE WHEN e.estado = 'graduado' THEN e.id_estudiante END) as estudiantes_graduados,
        COUNT(DISTINCT pr.id_profesor) as total_profesores,
        COUNT(DISTINCT c.id_curso) as total_cursos,
        COUNT(DISTINCT h.id_horario) as total_horarios
    FROM programas_estudio p
    LEFT JOIN estudiantes e ON p.id_programa = e.id_programa
    LEFT JOIN profesores pr ON FIND_IN_SET(p.nombre_programa, pr.programas_dictados) > 0 AND pr.activo = 1
    LEFT JOIN cursos c ON p.id_programa = c.id_programa AND c.activo = 1
    LEFT JOIN horarios h ON c.id_curso = h.id_curso AND h.activo = 1
    WHERE p.id_programa = ?
");
$stmt->execute([$id_programa]);
$estadisticas = $stmt->fetch();

// Obtener estudiantes por semestre
$stmt = $db->prepare("
    SELECT semestre_actual, COUNT(*) as cantidad,
           GROUP_CONCAT(CONCAT(e.nombres, ' ', e.apellidos) SEPARATOR ', ') as estudiantes
    FROM estudiantes e
    WHERE e.id_programa = ? AND e.estado = 'activo'
    GROUP BY semestre_actual
    ORDER BY semestre_actual
");
$stmt->execute([$id_programa]);
$estudiantes_semestre = $stmt->fetchAll();

// Obtener profesores asignados
$stmt = $db->prepare("
    SELECT p.* 
    FROM profesores p
    WHERE FIND_IN_SET(?, p.programas_dictados) > 0 AND p.activo = 1
    ORDER BY p.apellidos, p.nombres
");
$stmt->execute([$programa['nombre_programa']]);
$profesores = $stmt->fetchAll();

// Obtener últimos cursos
$stmt = $db->prepare("
    SELECT c.*, COUNT(DISTINCT h.id_horario) as total_horarios
    FROM cursos c
    LEFT JOIN horarios h ON c.id_curso = h.id_curso AND h.activo = 1
    WHERE c.id_programa = ? AND c.activo = 1
    GROUP BY c.id_curso
    ORDER BY c.semestre, c.nombre_curso
    LIMIT 10
");
$stmt->execute([$id_programa]);
$cursos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ver Programa - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .programa-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            padding: 30px;
            border-radius: var(--radius-xl);
            color: white;
            margin-bottom: 30px;
            box-shadow: var(--shadow-lg);
        }
        
        .programa-title {
            text-align: center;
        }
        
        .programa-title h1 {
            color: white;
            margin: 0 0 10px 0;
            font-size: 32px;
        }
        
        .programa-title p {
            margin: 5px 0;
            opacity: 0.9;
            font-size: 16px;
        }
        
        .programa-stats {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .stat-item {
            text-align: center;
            min-width: 120px;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: white;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            opacity: 0.8;
            color: white;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .info-card {
            background: white;
            padding: 25px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
        }
        
        .info-card h3 {
            color: var(--dark);
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--gray-100);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-item {
            display: flex;
            margin-bottom: 15px;
        }
        
        .info-label {
            width: 150px;
            font-weight: 600;
            color: var(--gray-700);
            font-size: 14px;
            flex-shrink: 0;
        }
        
        .info-value {
            flex: 1;
            color: var(--gray-800);
            font-size: 14px;
        }
        
        .coordinador-card {
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            border-left: 4px solid var(--primary);
        }
        
        .coordinador-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .coordinador-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .coordinador-details {
            flex: 1;
        }
        
        .coordinador-details h4 {
            margin: 0 0 5px 0;
            color: var(--dark);
        }
        
        .coordinador-details p {
            margin: 3px 0;
            color: var(--gray-600);
            font-size: 14px;
        }
        
        .semestre-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .semestre-card {
            background: var(--gray-50);
            padding: 15px;
            border-radius: var(--radius);
            text-align: center;
            border: 1px solid var(--gray-200);
        }
        
        .semestre-card .number {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .semestre-card .label {
            font-size: 12px;
            color: var(--gray-600);
        }
        
        .semestre-card .count {
            font-size: 20px;
            font-weight: 600;
            color: var(--dark);
            margin-top: 5px;
        }
        
        .lista-profesores {
            margin-top: 15px;
        }
        
        .profesor-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .profesor-item:last-child {
            border-bottom: none;
        }
        
        .profesor-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--gray-300) 0%, var(--gray-400) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
            flex-shrink: 0;
        }
        
        .profesor-info {
            flex: 1;
        }
        
        .profesor-info strong {
            color: var(--dark);
            font-size: 14px;
            display: block;
            margin-bottom: 2px;
        }
        
        .profesor-info small {
            color: var(--gray-500);
            font-size: 12px;
        }
        
        .lista-cursos {
            margin-top: 15px;
        }
        
        .curso-item {
            padding: 12px;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .curso-item:last-child {
            border-bottom: none;
        }
        
        .curso-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }
        
        .curso-header strong {
            color: var(--dark);
            font-size: 14px;
        }
        
        .curso-header .badge {
            font-size: 11px;
            padding: 2px 8px;
        }
        
        .curso-details {
            display: flex;
            gap: 15px;
            font-size: 12px;
            color: var(--gray-600);
        }
        
        .actions {
            display: flex;
            gap: 10px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid var(--gray-100);
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-graduation-cap"></i> Detalles del Programa</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-graduation-cap"></i> Programas</a>
                <a href="ver.php?id=<?php echo $id_programa; ?>" class="active"><i class="fas fa-eye"></i> Ver</a>
                <a href="editar.php?id=<?php echo $id_programa; ?>" class="btn-primary">
                    <i class="fas fa-edit"></i> Editar
                </a>
            </div>
        </div>
        
        <!-- Encabezado del programa -->
        <div class="programa-header">
            <div class="programa-title">
                <h1><?php echo htmlspecialchars($programa['nombre_programa']); ?></h1>
                <p><i class="fas fa-hashtag"></i> <?php echo $programa['codigo_programa']; ?></p>
                <p><i class="fas fa-calendar-alt"></i> <?php echo $programa['duracion_semestres']; ?> semestres</p>
                <p>
                    <span class="badge <?php echo $programa['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                        <?php echo $programa['activo'] ? 'Activo' : 'Inactivo'; ?>
                    </span>
                </p>
            </div>
            
            <div class="programa-stats">
                <div class="stat-item">
                    <div class="stat-number"><?php echo $estadisticas['estudiantes_activos'] ?? 0; ?></div>
                    <div class="stat-label">Estudiantes Activos</div>
                </div>
                
                <div class="stat-item">
                    <div class="stat-number"><?php echo $estadisticas['total_profesores'] ?? 0; ?></div>
                    <div class="stat-label">Profesores</div>
                </div>
                
                <div class="stat-item">
                    <div class="stat-number"><?php echo $estadisticas['total_cursos'] ?? 0; ?></div>
                    <div class="stat-label">Cursos</div>
                </div>
                
                <div class="stat-item">
                    <div class="stat-number"><?php echo $estadisticas['total_horarios'] ?? 0; ?></div>
                    <div class="stat-label">Horarios</div>
                </div>
            </div>
        </div>
        
        <!-- Información detallada -->
        <div class="info-grid">
            <!-- Información General -->
            <div class="info-card">
                <h3><i class="fas fa-info-circle"></i> Información General</h3>
                
                <div class="info-item">
                    <div class="info-label">Código:</div>
                    <div class="info-value"><?php echo $programa['codigo_programa']; ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Nombre:</div>
                    <div class="info-value"><?php echo htmlspecialchars($programa['nombre_programa']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Duración:</div>
                    <div class="info-value">
                        <?php echo $programa['duracion_semestres']; ?> semestres 
                        (<?php echo ceil($programa['duracion_semestres'] / 2); ?> años)
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Estado:</div>
                    <div class="info-value">
                        <span class="badge <?php echo $programa['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                            <?php echo $programa['activo'] ? 'Activo' : 'Inactivo'; ?>
                        </span>
                    </div>
                </div>
                
                <?php if ($programa['descripcion']): ?>
                <div class="info-item">
                    <div class="info-label">Descripción:</div>
                    <div class="info-value">
                        <?php echo nl2br(htmlspecialchars($programa['descripcion'])); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Coordinador -->
            <div class="info-card coordinador-card">
                <h3><i class="fas fa-user-tie"></i> Coordinador</h3>
                
                <?php if ($programa['coordinador_username']): ?>
                    <div class="coordinador-info">
                        <div class="coordinador-avatar">
                            <?php 
                            $iniciales = strtoupper(substr($programa['coordinador_username'], 0, 2));
                            echo $iniciales;
                            ?>
                        </div>
                        <div class="coordinador-details">
                            <h4><?php echo htmlspecialchars($programa['coordinador_username']); ?></h4>
                            <p><i class="fas fa-envelope"></i> <?php echo $programa['coordinador_email']; ?></p>
                            <p><i class="fas fa-user-tag"></i> Coordinador asignado</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="coordinador-info">
                        <div class="coordinador-avatar" style="background: var(--gray-400);">
                            <i class="fas fa-user-slash"></i>
                        </div>
                        <div class="coordinador-details">
                            <h4 style="color: var(--danger);">Sin coordinador</h4>
                            <p>Este programa no tiene coordinador asignado.</p>
                            <a href="asignar_coordinador.php?id=<?php echo $id_programa; ?>" 
                               class="btn btn-sm btn-primary">
                                <i class="fas fa-user-plus"></i> Asignar coordinador
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Estudiantes por Semestre -->
            <div class="info-card">
                <h3><i class="fas fa-user-graduate"></i> Estudiantes por Semestre</h3>
                
                <?php if (!empty($estudiantes_semestre)): ?>
                    <div class="semestre-grid">
                        <?php foreach ($estudiantes_semestre as $semestre): ?>
                            <div class="semestre-card">
                                <div class="label">Semestre</div>
                                <div class="number"><?php echo $semestre['semestre_actual']; ?></div>
                                <div class="count"><?php echo $semestre['cantidad']; ?> estudiantes</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="info-item" style="margin-top: 15px;">
                        <div class="info-label">Total Activos:</div>
                        <div class="info-value">
                            <strong><?php echo $estadisticas['estudiantes_activos'] ?? 0; ?></strong> estudiantes
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Total Graduados:</div>
                        <div class="info-value">
                            <strong><?php echo $estadisticas['estudiantes_graduados'] ?? 0; ?></strong> estudiantes
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No hay estudiantes activos en este programa.</p>
                <?php endif; ?>
            </div>
            
            <!-- Profesores Asignados -->
            <div class="info-card">
                <h3><i class="fas fa-chalkboard-teacher"></i> Profesores</h3>
                
                <?php if (!empty($profesores)): ?>
                    <div class="lista-profesores">
                        <?php foreach ($profesores as $profesor): ?>
                            <div class="profesor-item">
                                <div class="profesor-avatar">
                                    <?php 
                                    $iniciales = strtoupper(substr($profesor['nombres'], 0, 1) . substr($profesor['apellidos'], 0, 1));
                                    echo $iniciales;
                                    ?>
                                </div>
                                <div class="profesor-info">
                                    <strong><?php echo htmlspecialchars($profesor['nombres'] . ' ' . $profesor['apellidos']); ?></strong>
                                    <small><?php echo htmlspecialchars($profesor['especialidad']); ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="info-item" style="margin-top: 15px;">
                        <div class="info-label">Total:</div>
                        <div class="info-value">
                            <strong><?php echo count($profesores); ?></strong> profesores asignados
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No hay profesores asignados a este programa.</p>
                <?php endif; ?>
            </div>
            
            <!-- Cursos del Programa -->
            <div class="info-card">
                <h3><i class="fas fa-book"></i> Cursos Recientes</h3>
                
                <?php if (!empty($cursos)): ?>
                    <div class="lista-cursos">
                        <?php foreach ($cursos as $curso): ?>
                            <div class="curso-item">
                                <div class="curso-header">
                                    <strong><?php echo htmlspecialchars($curso['nombre_curso']); ?></strong>
                                    <span class="badge badge-info">Semestre <?php echo $curso['semestre']; ?></span>
                                </div>
                                <div class="curso-details">
                                    <span><i class="fas fa-hashtag"></i> <?php echo $curso['codigo_curso']; ?></span>
                                    <span><i class="fas fa-clock"></i> <?php echo $curso['horas_semanales']; ?> hrs/sem</span>
                                    <span><i class="fas fa-calendar-alt"></i> <?php echo $curso['total_horarios']; ?> horarios</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="info-item" style="margin-top: 15px;">
                        <div class="info-label">Total Cursos:</div>
                        <div class="info-value">
                            <strong><?php echo $estadisticas['total_cursos'] ?? 0; ?></strong> cursos activos
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No hay cursos registrados para este programa.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Acciones -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-cogs"></i> Acciones</h3>
            </div>
            <div class="card-body">
                <div class="actions">
                    <a href="editar.php?id=<?php echo $id_programa; ?>" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Editar Programa
                    </a>
                    <a href="asignar_coordinador.php?id=<?php echo $id_programa; ?>" class="btn btn-info">
                        <i class="fas fa-user-tie"></i> Gestionar Coordinador
                    </a>
                    <a href="cambiar_estado.php?id=<?php echo $id_programa; ?>" 
                       class="btn <?php echo $programa['activo'] ? 'btn-danger' : 'btn-success'; ?>">
                        <i class="fas fa-<?php echo $programa['activo'] ? 'times' : 'check'; ?>"></i>
                        <?php echo $programa['activo'] ? 'Desactivar Programa' : 'Activar Programa'; ?>
                    </a>
                    <a href="../cursos/index.php?programa=<?php echo $id_programa; ?>" class="btn btn-primary">
                        <i class="fas fa-book"></i> Ver Cursos
                    </a>
                    <a href="../estudiantes/index.php?programa=<?php echo $id_programa; ?>" class="btn btn-success">
                        <i class="fas fa-user-graduate"></i> Ver Estudiantes
                    </a>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Volver a Lista
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>