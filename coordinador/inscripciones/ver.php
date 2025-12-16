<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

$id_inscripcion = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_inscripcion) {
    Funciones::redireccionar('index.php', 'ID de inscripción no válido', 'error');
}

// Obtener datos completos de la inscripción
$stmt = $db->prepare("
    SELECT i.*, 
           e.id_estudiante, e.codigo_estudiante, 
           e.nombres as estudiante_nombres, 
           e.apellidos as estudiante_apellidos,
           e.documento_identidad, e.fecha_nacimiento, e.genero, 
           e.telefono, e.direccion, e.semestre_actual,
           es.nombre_programa, es.codigo_programa,
           c.id_curso, c.codigo_curso, c.nombre_curso, 
           c.descripcion, c.creditos, c.horas_semanales, c.semestre as curso_semestre,
           h.id_horario, h.dia_semana, h.hora_inicio, h.hora_fin, h.tipo_clase, h.grupo,
           h.capacidad_grupo, h.fecha_creacion as fecha_asignacion,
           s.codigo_semestre, s.nombre_semestre, s.fecha_inicio, s.fecha_fin,
           CONCAT(p.nombres, ' ', p.apellidos) as profesor_nombre,
           p.id_profesor, p.codigo_profesor, p.especialidad,
           a.id_aula, a.codigo_aula, a.nombre_aula, a.tipo_aula, a.capacidad as capacidad_aula,
           COUNT(insc.id_inscripcion) as total_inscritos_curso
    FROM inscripciones i
    JOIN estudiantes e ON i.id_estudiante = e.id_estudiante
    JOIN programas_estudio es ON e.id_programa = es.id_programa
    JOIN horarios h ON i.id_horario = h.id_horario
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
    JOIN profesores p ON h.id_profesor = p.id_profesor
    JOIN aulas a ON h.id_aula = a.id_aula
    LEFT JOIN inscripciones insc ON h.id_horario = insc.id_horario AND insc.estado = 'inscrito'
    WHERE i.id_inscripcion = ?
    GROUP BY i.id_inscripcion
");

$stmt->execute([$id_inscripcion]);
$inscripcion = $stmt->fetch();

if (!$inscripcion) {
    Funciones::redireccionar('index.php', 'Inscripción no encontrada', 'error');
}

// Obtener historial académico del estudiante (solo inscripciones activas)
$stmt = $db->prepare("
    SELECT i.*, 
           c.codigo_curso, c.nombre_curso, c.creditos,
           h.dia_semana, h.hora_inicio, h.hora_fin,
           CONCAT(p.nombres, ' ', p.apellidos) as profesor_nombre,
           s.nombre_semestre
    FROM inscripciones i
    JOIN horarios h ON i.id_horario = h.id_horario
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
    JOIN profesores p ON h.id_profesor = p.id_profesor
    WHERE i.id_estudiante = ?
    ORDER BY s.fecha_inicio DESC, i.fecha_inscripcion DESC
");

$stmt->execute([$inscripcion['id_estudiante']]);
$historial = $stmt->fetchAll();

// Obtener estudiantes en el mismo horario (compañeros de clase)
$stmt = $db->prepare("
    SELECT e.codigo_estudiante, 
           CONCAT(e.nombres, ' ', e.apellidos) as nombre_completo,
           e.semestre_actual
    FROM inscripciones i
    JOIN estudiantes e ON i.id_estudiante = e.id_estudiante
    WHERE i.id_horario = ? AND i.estado = 'inscrito'
    AND i.id_estudiante != ?
    ORDER BY e.apellidos, e.nombres
");

$stmt->execute([$inscripcion['id_horario'], $inscripcion['id_estudiante']]);
$companeros = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalles de Inscripción - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .page-header {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%);
            color: white;
            padding: 30px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
        }
        
        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }
        
        .page-header h1 {
            color: white;
            margin: 0;
            font-size: 28px;
            position: relative;
            z-index: 1;
        }
        
        .page-header .subtitle {
            font-size: 16px;
            opacity: 0.9;
            margin-top: 10px;
            position: relative;
            z-index: 1;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .info-card {
            background: white;
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
        }
        
        .info-card h3 {
            color: var(--primary);
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--primary-light);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-item {
            display: flex;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .info-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
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
        
        .badge-lg {
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 2px solid var(--gray-100);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .stat-item {
            text-align: center;
            padding: 15px;
            background: var(--gray-50);
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .timeline {
            position: relative;
            padding-left: 30px;
            margin: 20px 0;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--primary-light);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .timeline-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -36px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary);
            border: 3px solid white;
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        
        .timeline-date {
            font-size: 13px;
            color: var(--gray-600);
            margin-bottom: 5px;
        }
        
        .timeline-content {
            background: white;
            padding: 15px;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
        }
        
        .companeros-list {
            margin-top: 15px;
        }
        
        .companero-item {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            margin-bottom: 8px;
            background: var(--gray-50);
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }
        
        .companero-item:hover {
            background: var(--gray-100);
            transform: translateX(5px);
        }
        
        .companero-info {
            flex: 1;
        }
        
        .companero-nombre {
            font-weight: 600;
            color: var(--dark);
        }
        
        .companero-codigo {
            font-size: 12px;
            color: var(--gray-600);
            margin-top: 3px;
        }
        
        .companero-semestre {
            font-size: 12px;
            color: var(--primary);
            background: var(--gray-100);
            padding: 2px 8px;
            border-radius: 12px;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: white;
            text-decoration: none;
            opacity: 0.9;
            transition: opacity 0.3s ease;
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
        }
        
        .back-link:hover {
            opacity: 1;
            color: white;
            text-decoration: underline;
        }
        
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        
        .status-dot.inscrito { background: var(--success); }
        .status-dot.aprobado { background: var(--primary); }
        .status-dot.reprobado { background: var(--danger); }
        .status-dot.retirado { background: var(--warning); }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-500);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            background: linear-gradient(135deg, var(--gray-300) 0%, var(--gray-400) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        @media (max-width: 768px) {
            .info-item {
                flex-direction: column;
                gap: 5px;
            }
            
            .info-label {
                width: 100%;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .page-header {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Encabezado con gradiente -->
        <div class="page-header">
            <a href="index.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Volver a Inscripciones
            </a>
            
            <h1>
                <i class="fas fa-clipboard-check"></i> 
                Detalles de Inscripción
            </h1>
            <div class="subtitle">
                ID: <?php echo $inscripcion['id_inscripcion']; ?> | 
                Estado: 
                <span class="badge-lg badge-<?php echo $inscripcion['estado']; ?>">
                    <?php echo ucfirst($inscripcion['estado']); ?>
                </span>
                | Fecha Inscripción: <?php echo Funciones::formatearFecha($inscripcion['fecha_inscripcion']); ?>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <!-- Información principal -->
        <div class="info-grid">
            <!-- Estudiante -->
            <div class="info-card">
                <h3><i class="fas fa-user-graduate"></i> Información del Estudiante</h3>
                
                <div class="info-item">
                    <div class="info-label">Nombre Completo:</div>
                    <div class="info-value">
                        <strong><?php echo htmlspecialchars($inscripcion['estudiante_nombres'] . ' ' . $inscripcion['estudiante_apellidos']); ?></strong>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Código:</div>
                    <div class="info-value">
                        <span class="badge badge-secondary">
                            <?php echo $inscripcion['codigo_estudiante']; ?>
                        </span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Programa:</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($inscripcion['nombre_programa']); ?>
                        (<?php echo $inscripcion['codigo_programa']; ?>)
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Semestre Actual:</div>
                    <div class="info-value">
                        <?php echo $inscripcion['semestre_actual']; ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Documento:</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($inscripcion['documento_identidad']); ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Teléfono:</div>
                    <div class="info-value">
                        <?php echo $inscripcion['telefono'] ?: 'No registrado'; ?>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <a href="../estudiantes/ver.php?id=<?php echo $inscripcion['id_estudiante']; ?>" 
                       class="btn btn-primary">
                        <i class="fas fa-user"></i> Ver Perfil Completo
                    </a>
                    <a href="../estudiantes/editar.php?id=<?php echo $inscripcion['id_estudiante']; ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-edit"></i> Editar Estudiante
                    </a>
                </div>
            </div>
            
            <!-- Curso y Horario -->
            <div class="info-card">
                <h3><i class="fas fa-book"></i> Información del Curso</h3>
                
                <div class="info-item">
                    <div class="info-label">Curso:</div>
                    <div class="info-value">
                        <strong><?php echo htmlspecialchars($inscripcion['nombre_curso']); ?></strong>
                        (<?php echo $inscripcion['codigo_curso']; ?>)
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Créditos:</div>
                    <div class="info-value">
                        <?php echo $inscripcion['creditos']; ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Horas Semanales:</div>
                    <div class="info-value">
                        <?php echo $inscripcion['horas_semanales']; ?> horas
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Semestre del Curso:</div>
                    <div class="info-value">
                        <?php echo $inscripcion['curso_semestre']; ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Horario:</div>
                    <div class="info-value">
                        <?php echo $inscripcion['dia_semana']; ?> 
                        <?php echo Funciones::formatearHora($inscripcion['hora_inicio']); ?> - 
                        <?php echo Funciones::formatearHora($inscripcion['hora_fin']); ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Tipo de Clase:</div>
                    <div class="info-value">
                        <?php echo ucfirst($inscripcion['tipo_clase']); ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Grupo:</div>
                    <div class="info-value">
                        <?php echo $inscripcion['grupo'] ?: 'Sin grupo específico'; ?>
                    </div>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $inscripcion['capacidad_grupo'] ?: '∞'; ?></div>
                        <div class="stat-label">Capacidad Máxima</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $inscripcion['total_inscritos_curso']; ?></div>
                        <div class="stat-label">Inscritos Actuales</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $inscripcion['creditos']; ?></div>
                        <div class="stat-label">Créditos</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $inscripcion['horas_semanales']; ?></div>
                        <div class="stat-label">Horas/Semana</div>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <a href="../horarios/ver.php?id=<?php echo $inscripcion['id_horario']; ?>" 
                       class="btn btn-primary">
                        <i class="fas fa-calendar-alt"></i> Ver Horario
                    </a>
                    <a href="../cursos/ver.php?id=<?php echo $inscripcion['id_curso']; ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-book-open"></i> Ver Curso
                    </a>
                </div>
            </div>
            
            <!-- Profesor y Aula -->
            <div class="info-card">
                <h3><i class="fas fa-chalkboard-teacher"></i> Información de Clase</h3>
                
                <div class="info-item">
                    <div class="info-label">Profesor:</div>
                    <div class="info-value">
                        <strong><?php echo htmlspecialchars($inscripcion['profesor_nombre']); ?></strong>
                        (<?php echo $inscripcion['codigo_profesor']; ?>)
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Especialidad:</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($inscripcion['especialidad']); ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Aula:</div>
                    <div class="info-value">
                        <?php echo $inscripcion['nombre_aula']; ?>
                        (<?php echo $inscripcion['codigo_aula']; ?>)
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Tipo de Aula:</div>
                    <div class="info-value">
                        <?php echo ucfirst($inscripcion['tipo_aula']); ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Capacidad Aula:</div>
                    <div class="info-value">
                        <?php echo $inscripcion['capacidad_aula']; ?> estudiantes
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Semestre Académico:</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($inscripcion['nombre_semestre']); ?>
                        (<?php echo $inscripcion['codigo_semestre']; ?>)
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Fecha Inicio Semestre:</div>
                    <div class="info-value">
                        <?php echo Funciones::formatearFecha($inscripcion['fecha_inicio']); ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Fecha Fin Semestre:</div>
                    <div class="info-value">
                        <?php echo Funciones::formatearFecha($inscripcion['fecha_fin']); ?>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <a href="../profesores/ver.php?id=<?php echo $inscripcion['id_profesor']; ?>" 
                       class="btn btn-primary">
                        <i class="fas fa-user-tie"></i> Ver Profesor
                    </a>
                    <a href="../aulas/ver.php?id=<?php echo $inscripcion['id_aula']; ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-building"></i> Ver Aula
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Compañeros de Clase -->
        <div class="info-card">
            <h3><i class="fas fa-users"></i> Compañeros de Clase</h3>
            
            <?php if (!empty($companeros)): ?>
                <p>Estudiantes inscritos en el mismo horario:</p>
                
                <div class="companeros-list">
                    <?php foreach ($companeros as $companero): ?>
                        <div class="companero-item">
                            <div class="companero-info">
                                <div class="companero-nombre">
                                    <?php echo htmlspecialchars($companero['nombre_completo']); ?>
                                </div>
                                <div class="companero-codigo">
                                    <?php echo $companero['codigo_estudiante']; ?>
                                </div>
                            </div>
                            <div class="companero-semestre">
                                Semestre <?php echo $companero['semestre_actual']; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="text-center" style="margin-top: 15px;">
                    <small class="text-muted">
                        Total de compañeros: <?php echo count($companeros); ?> estudiantes
                    </small>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-user-friends"></i>
                    <p>No hay otros estudiantes inscritos en este horario</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Historial Académico del Estudiante -->
        <div class="info-card">
            <h3><i class="fas fa-history"></i> Historial de Inscripciones del Estudiante</h3>
            
            <?php if (!empty($historial)): ?>
                <div class="timeline">
                    <?php foreach ($historial as $registro): ?>
                        <div class="timeline-item">
                            <div class="timeline-date">
                                <?php echo Funciones::formatearFecha($registro['fecha_inscripcion']); ?>
                                | Semestre: <?php echo htmlspecialchars($registro['nombre_semestre']); ?>
                            </div>
                            <div class="timeline-content">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <div>
                                        <strong><?php echo htmlspecialchars($registro['nombre_curso']); ?></strong>
                                        <small style="margin-left: 10px; color: var(--gray-600);">
                                            <?php echo $registro['codigo_curso']; ?>
                                        </small>
                                    </div>
                                    <div>
                                        <span class="badge badge-<?php echo $registro['estado']; ?>">
                                            <?php echo ucfirst($registro['estado']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div style="font-size: 13px; color: var(--gray-600);">
                                    <i class="fas fa-chalkboard-teacher"></i> 
                                    <?php echo htmlspecialchars($registro['profesor_nombre']); ?>
                                    <span style="margin-left: 15px;">
                                        <i class="fas fa-clock"></i> 
                                        <?php echo $registro['dia_semana']; ?> 
                                        <?php echo Funciones::formatearHora($registro['hora_inicio']); ?> - 
                                        <?php echo Funciones::formatearHora($registro['hora_fin']); ?>
                                    </span>
                                    <span style="margin-left: 15px;">
                                        <i class="fas fa-star"></i> 
                                        <?php echo $registro['creditos']; ?> créditos
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Estadísticas del historial -->
                <?php
                $total_cursos = count($historial);
                $inscritos_actuales = array_filter($historial, function($h) {
                    return $h['estado'] == 'inscrito';
                });
                $retirados = array_filter($historial, function($h) {
                    return $h['estado'] == 'retirado';
                });
                
                $creditos_actuales = 0;
                foreach ($inscritos_actuales as $curso) {
                    $creditos_actuales += $curso['creditos'];
                }
                ?>
                
                <div class="stats-grid" style="margin-top: 20px;">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $total_cursos; ?></div>
                        <div class="stat-label">Total Cursos</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo count($inscritos_actuales); ?></div>
                        <div class="stat-label">Cursos Activos</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo count($retirados); ?></div>
                        <div class="stat-label">Cursos Retirados</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $creditos_actuales; ?></div>
                        <div class="stat-label">Créditos Actuales</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-info-circle"></i>
                    <p>El estudiante no tiene historial de inscripciones</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Acciones -->
        <div class="info-card">
            <h3><i class="fas fa-cogs"></i> Acciones</h3>
            
            <div class="action-buttons">
                <?php if ($inscripcion['estado'] == 'inscrito'): ?>
                    <a href="retirar.php?id=<?php echo $id_inscripcion; ?>" 
                       class="btn btn-warning"
                       onclick="return confirm('¿Está seguro de retirar esta inscripción?')">
                        <i class="fas fa-sign-out-alt"></i> Retirar Inscripción
                    </a>
                <?php endif; ?>
                
                <button type="button" class="btn btn-info" onclick="generarConstanciaInscripcion()">
                    <i class="fas fa-file-certificate"></i> Generar Constancia
                </button>
                
                <button type="button" class="btn btn-secondary" onclick="generarComprobante()">
                    <i class="fas fa-file-alt"></i> Comprobante de Inscripción
                </button>
                
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
            </div>
        </div>
    </div>
    
    <script>
    function generarConstanciaInscripcion() {
        if (confirm('¿Generar constancia de inscripción?')) {
            window.open('constancia.php?id=<?php echo $id_inscripcion; ?>', '_blank');
        }
    }
    
    function generarComprobante() {
        if (confirm('¿Generar comprobante de inscripción?')) {
            window.open('comprobante.php?id=<?php echo $id_inscripcion; ?>', '_blank');
        }
    }
    
    // Efecto de animación en las tarjetas
    document.addEventListener('DOMContentLoaded', function() {
        const cards = document.querySelectorAll('.info-card');
        cards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.1}s`;
            card.classList.add('fade-in');
        });
    });
    </script>
</body>
</html>
</html>