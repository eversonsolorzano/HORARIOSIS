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

if (!$semestre_actual) {
    Session::setFlash('No hay un semestre activo para inscripciones', 'error');
    Funciones::redireccionar('dashboard.php');
}

// Procesar inscripción
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['inscribir'])) {
    $id_horario = isset($_POST['id_horario']) ? intval($_POST['id_horario']) : 0;
    
    if ($id_horario > 0) {
        try {
            // Verificar si ya está inscrito
            $stmt = $db->prepare("
                SELECT COUNT(*) as total 
                FROM inscripciones 
                WHERE id_estudiante = ? 
                AND id_horario = ?
                AND estado = 'inscrito'
            ");
            $stmt->execute([$estudiante['id_estudiante'], $id_horario]);
            $ya_inscrito = $stmt->fetch()['total'] > 0;
            
            if ($ya_inscrito) {
                Session::setFlash('Ya estás inscrito en este curso', 'error');
            } else {
                // Verificar cupo disponible
                $stmt = $db->prepare("
                    SELECT h.capacidad_grupo, COUNT(i.id_inscripcion) as inscritos
                    FROM horarios h
                    LEFT JOIN inscripciones i ON h.id_horario = i.id_horario AND i.estado = 'inscrito'
                    WHERE h.id_horario = ?
                    GROUP BY h.id_horario
                ");
                $stmt->execute([$id_horario]);
                $cupo = $stmt->fetch();
                
                if ($cupo['capacidad_grupo'] && $cupo['inscritos'] >= $cupo['capacidad_grupo']) {
                    Session::setFlash('No hay cupo disponible en este grupo', 'error');
                } else {
                    // Obtener información del curso para verificar prerrequisitos
                    $stmt = $db->prepare("
                        SELECT c.id_curso, c.nombre_curso, c.prerrequisitos
                        FROM horarios h
                        JOIN cursos c ON h.id_curso = c.id_curso
                        WHERE h.id_horario = ?
                    ");
                    $stmt->execute([$id_horario]);
                    $curso = $stmt->fetch();
                    
                    // Verificar prerrequisitos si existen
                    if (!empty($curso['prerrequisitos'])) {
                        $prerrequisitos = explode(',', $curso['prerrequisitos']);
                        $prerrequisitos_ids = array_map('intval', $prerrequisitos);
                        
                        $placeholders = str_repeat('?,', count($prerrequisitos_ids) - 1) . '?';
                        $stmt = $db->prepare("
                            SELECT COUNT(*) as aprobados
                            FROM inscripciones i
                            JOIN horarios h ON i.id_horario = h.id_horario
                            WHERE i.id_estudiante = ?
                            AND h.id_curso IN ($placeholders)
                            AND i.estado = 'aprobado'
                        ");
                        $params = array_merge([$estudiante['id_estudiante']], $prerrequisitos_ids);
                        $stmt->execute($params);
                        $aprobados = $stmt->fetch()['aprobados'];
                        
                        if ($aprobados < count($prerrequisitos_ids)) {
                            Session::setFlash('No cumples con los prerrequisitos para este curso', 'error');
                            Funciones::redireccionar('inscribir_curso.php');
                        }
                    }
                    
                    // Verificar conflictos de horario
                    $stmt = $db->prepare("
                        SELECT h.dia_semana, h.hora_inicio, h.hora_fin
                        FROM horarios h
                        WHERE h.id_horario = ?
                    ");
                    $stmt->execute([$id_horario]);
                    $nuevo_horario = $stmt->fetch();
                    
                    $stmt = $db->prepare("
                        SELECT h2.*, c2.nombre_curso
                        FROM inscripciones i
                        JOIN horarios h2 ON i.id_horario = h2.id_horario
                        JOIN cursos c2 ON h2.id_curso = c2.id_curso
                        WHERE i.id_estudiante = ? 
                        AND i.estado = 'inscrito'
                        AND h2.dia_semana = ?
                        AND (
                            (h2.hora_inicio <= ? AND h2.hora_fin > ?) OR
                            (h2.hora_inicio < ? AND h2.hora_fin >= ?) OR
                            (h2.hora_inicio >= ? AND h2.hora_fin <= ?)
                        )
                    ");
                    $stmt->execute([
                        $estudiante['id_estudiante'],
                        $nuevo_horario['dia_semana'],
                        $nuevo_horario['hora_inicio'], $nuevo_horario['hora_inicio'],
                        $nuevo_horario['hora_fin'], $nuevo_horario['hora_fin'],
                        $nuevo_horario['hora_inicio'], $nuevo_horario['hora_fin']
                    ]);
                    $conflictos = $stmt->fetchAll();
                    
                    if (count($conflictos) > 0) {
                        $cursos_conflicto = array_map(function($c) {
                            return $c['nombre_curso'] . ' (' . $c['dia_semana'] . ' ' . 
                                   substr($c['hora_inicio'], 0, 5) . '-' . substr($c['hora_fin'], 0, 5) . ')';
                        }, $conflictos);
                        
                        Session::setFlash('Conflicto de horario con: ' . implode(', ', $cursos_conflicto), 'error');
                        Funciones::redireccionar('inscribir_curso.php');
                    }
                    
                    // Realizar la inscripción
                    $stmt = $db->prepare("
                        INSERT INTO inscripciones 
                        (id_estudiante, id_horario, fecha_inscripcion, estado)
                        VALUES (?, ?, CURDATE(), 'inscrito')
                    ");
                    $stmt->execute([$estudiante['id_estudiante'], $id_horario]);
                    
                    // Enviar notificación al estudiante
                    $stmt = $db->prepare("
                        INSERT INTO notificaciones 
                        (id_usuario, tipo_notificacion, titulo, mensaje, link_accion)
                        VALUES (?, 'nuevo_curso', ?, ?, ?)
                    ");
                    
                    $titulo = "Inscripción exitosa";
                    $mensaje = "Te has inscrito exitosamente en el curso. Revisa tu horario para más detalles.";
                    $link = "estudiante/mi_horario.php";
                    
                    $stmt->execute([
                        $user['id'],
                        $titulo,
                        $mensaje,
                        $link
                    ]);
                    
                    Session::setFlash('Inscripción exitosa en el curso');
                    Funciones::redireccionar('mis_cursos.php');
                }
            }
        } catch (Exception $e) {
            Session::setFlash('Error al realizar la inscripción: ' . $e->getMessage(), 'error');
        }
    }
}

// Procesar retiro de curso
if (isset($_GET['retirar'])) {
    $id_curso = intval($_GET['retirar']);
    
    // Obtener horarios del curso donde está inscrito el estudiante
    $stmt = $db->prepare("
        SELECT i.id_inscripcion, h.id_horario, c.nombre_curso
        FROM inscripciones i
        JOIN horarios h ON i.id_horario = h.id_horario
        JOIN cursos c ON h.id_curso = c.id_curso
        WHERE i.id_estudiante = ? 
        AND h.id_curso = ?
        AND i.estado = 'inscrito'
    ");
    $stmt->execute([$estudiante['id_estudiante'], $id_curso]);
    $inscripcion = $stmt->fetch();
    
    if ($inscripcion) {
        try {
            $stmt = $db->prepare("DELETE FROM inscripciones WHERE id_inscripcion = ?");
            $stmt->execute([$inscripcion['id_inscripcion']]);
            
            Session::setFlash('Has sido retirado del curso exitosamente');
            Funciones::redireccionar('mis_cursos.php');
        } catch (Exception $e) {
            Session::setFlash('Error al retirarse del curso: ' . $e->getMessage(), 'error');
        }
    }
}

// Obtener cursos disponibles para inscripción
$stmt = $db->prepare("
    SELECT DISTINCT
        c.id_curso,
        c.codigo_curso,
        c.nombre_curso,
        c.descripcion,
        c.creditos,
        c.horas_semanales,
        c.semestre as nivel_curso,
        c.tipo_curso,
        c.prerrequisitos,
        p.nombre_programa
    FROM cursos c
    JOIN programas_estudio p ON c.id_programa = p.id_programa
    WHERE c.activo = 1
    AND c.id_programa = ?
    AND c.semestre <= ?
    ORDER BY c.semestre, c.nombre_curso
");
$stmt->execute([$estudiante['id_programa'], $estudiante['semestre_actual']]);
$cursos_disponibles = $stmt->fetchAll();

// Obtener cursos ya inscritos del estudiante
$stmt = $db->prepare("
    SELECT DISTINCT c.id_curso
    FROM inscripciones i
    JOIN horarios h ON i.id_horario = h.id_horario
    JOIN cursos c ON h.id_curso = c.id_curso
    WHERE i.id_estudiante = ? 
    AND i.estado = 'inscrito'
");
$stmt->execute([$estudiante['id_estudiante']]);
$cursos_inscritos = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Obtener cursos aprobados para verificar prerrequisitos
$stmt = $db->prepare("
    SELECT DISTINCT c.id_curso
    FROM inscripciones i
    JOIN horarios h ON i.id_horario = h.id_horario
    JOIN cursos c ON h.id_curso = c.id_curso
    WHERE i.id_estudiante = ? 
    AND i.estado = 'aprobado'
");
$stmt->execute([$estudiante['id_estudiante']]);
$cursos_aprobados = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Filtrar cursos disponibles
$cursos_filtrados = [];
foreach ($cursos_disponibles as $curso) {
    // Verificar si ya está inscrito
    if (in_array($curso['id_curso'], $cursos_inscritos)) {
        continue;
    }
    
    // Verificar prerrequisitos
    $cumple_prerrequisitos = true;
    if (!empty($curso['prerrequisitos'])) {
        $prerrequisitos = explode(',', $curso['prerrequisitos']);
        $prerrequisitos_ids = array_map('intval', $prerrequisitos);
        
        foreach ($prerrequisitos_ids as $prerreq_id) {
            if (!in_array($prerreq_id, $cursos_aprobados)) {
                $cumple_prerrequisitos = false;
                break;
            }
        }
    }
    
    if ($cumple_prerrequisitos) {
        $cursos_filtrados[] = $curso;
    }
}

// Obtener horarios disponibles para cada curso
foreach ($cursos_filtrados as &$curso) {
    $stmt = $db->prepare("
        SELECT 
            h.id_horario,
            h.dia_semana,
            h.hora_inicio,
            h.hora_fin,
            h.tipo_clase,
            h.grupo,
            h.capacidad_grupo,
            a.codigo_aula,
            a.nombre_aula,
            CONCAT(pr.nombres, ' ', pr.apellidos) as profesor,
            COUNT(i.id_inscripcion) as inscritos
        FROM horarios h
        JOIN aulas a ON h.id_aula = a.id_aula
        JOIN profesores pr ON h.id_profesor = pr.id_profesor
        LEFT JOIN inscripciones i ON h.id_horario = i.id_horario AND i.estado = 'inscrito'
        WHERE h.id_curso = ?
        AND h.id_semestre = ?
        AND h.activo = 1
        GROUP BY h.id_horario
        ORDER BY h.dia_semana, h.hora_inicio
    ");
    $stmt->execute([$curso['id_curso'], $semestre_actual['id_semestre']]);
    $curso['horarios'] = $stmt->fetchAll();
}
unset($curso); // Eliminar referencia

// Calcular límite de créditos
$creditos_inscritos = 0;
$stmt = $db->prepare("
    SELECT SUM(c.creditos) as total
    FROM inscripciones i
    JOIN horarios h ON i.id_horario = h.id_horario
    JOIN cursos c ON h.id_curso = c.id_curso
    WHERE i.id_estudiante = ? 
    AND i.estado = 'inscrito'
    AND h.id_semestre = ?
");
$stmt->execute([$estudiante['id_estudiante'], $semestre_actual['id_semestre']]);
$creditos_inscritos = $stmt->fetch()['total'] ?? 0;

// Límite de créditos por semestre (configurable)
$limite_creditos = 20; // Valor por defecto

// Obtener configuración de límite de créditos
$stmt = $db->prepare("SELECT valor FROM configuracion WHERE clave = 'limite_creditos_semestre'");
$stmt->execute();
$config_limite = $stmt->fetch();
if ($config_limite) {
    $limite_creditos = intval($config_limite['valor']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscribir Cursos - Sistema de Horarios</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .resumen-inscripcion {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            padding: 25px;
            border-radius: var(--radius);
            margin-bottom: 30px;
            border-left: 5px solid var(--primary);
        }
        
        .resumen-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .resumen-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: var(--shadow);
        }
        
        .resumen-label {
            font-size: 13px;
            color: var(--gray-600);
            margin-bottom: 5px;
        }
        
        .resumen-valor {
            font-size: 20px;
            font-weight: 700;
            color: var(--dark);
        }
        
        .curso-disponible {
            background: white;
            border-radius: var(--radius);
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
            border: 2px solid #e5e7eb;
            transition: all 0.3s;
        }
        
        .curso-disponible:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow-lg);
        }
        
        .curso-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .curso-info h3 {
            color: var(--dark);
            margin-bottom: 8px;
            font-size: 20px;
        }
        
        .curso-meta {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            color: var(--gray-600);
        }
        
        .meta-item i {
            color: var(--primary);
        }
        
        .curso-creditos {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 16px;
        }
        
        .horarios-container {
            margin-top: 20px;
        }
        
        .horarios-title {
            font-size: 16px;
            color: var(--gray-700);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .horarios-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
        }
        
        .horario-option {
            background: var(--gray-50);
            border-radius: var(--radius);
            padding: 20px;
            border: 2px solid transparent;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
        }
        
        .horario-option:hover {
            border-color: var(--primary);
            background: white;
        }
        
        .horario-option.selected {
            border-color: var(--success);
            background: #f0fdf4;
        }
        
        .horario-dia {
            font-weight: 700;
            color: var(--dark);
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .horario-hora {
            font-size: 14px;
            color: var(--gray-600);
            margin-bottom: 10px;
        }
        
        .horario-detalles {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            font-size: 13px;
        }
        
        .detalle-item {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--gray-600);
        }
        
        .cupo-info {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 10px;
            font-size: 13px;
        }
        
        .cupo-libre {
            color: var(--success);
        }
        
        .cupo-lleno {
            color: var(--danger);
        }
        
        .radio-seleccion {
            position: absolute;
            top: 15px;
            right: 15px;
        }
        
        .curso-actions {
            margin-top: 20px;
            text-align: right;
        }
        
        .empty-cursos {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-cursos i {
            font-size: 48px;
            color: var(--gray-300);
            margin-bottom: 20px;
        }
        
        .prerrequisitos-info {
            background: #fffbeb;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            border-radius: 6px;
            margin-top: 15px;
            font-size: 14px;
        }
        
        .sin-horarios {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 15px;
            border-radius: 6px;
            margin-top: 15px;
            font-size: 14px;
            color: var(--gray-700);
        }
        
        .progreso-creditos {
            margin-top: 10px;
        }
        
        .progreso-bar {
            height: 8px;
            background: var(--gray-200);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 5px;
        }
        
        .progreso-fill {
            height: 100%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: 4px;
            transition: width 0.3s;
        }
        
        @media (max-width: 768px) {
            .resumen-grid {
                grid-template-columns: 1fr;
            }
            
            .horarios-grid {
                grid-template-columns: 1fr;
            }
            
            .curso-header {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fas fa-plus-circle"></i> Inscribir Cursos</h1>
                <div class="nav">
                    <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    <a href="mi_horario.php"><i class="fas fa-calendar-alt"></i> Mi Horario</a>
                    <a href="mis_cursos.php"><i class="fas fa-book"></i> Mis Cursos</a>
                    <a href="inscribir_curso.php" class="active"><i class="fas fa-plus-circle"></i> Inscribir Curso</a>
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
        
        <!-- Resumen de Inscripción -->
        <div class="resumen-inscripcion">
            <h2><i class="fas fa-user-graduate"></i> Resumen de Inscripción</h2>
            <div class="resumen-grid">
                <div class="resumen-item">
                    <div class="resumen-label">Estudiante</div>
                    <div class="resumen-valor"><?php echo htmlspecialchars($estudiante['nombres'] . ' ' . $estudiante['apellidos']); ?></div>
                    <div style="font-size: 14px; color: var(--gray-600); margin-top: 5px;">
                        <?php echo htmlspecialchars($estudiante['codigo_estudiante']); ?>
                    </div>
                </div>
                
                <div class="resumen-item">
                    <div class="resumen-label">Programa</div>
                    <div class="resumen-valor"><?php echo htmlspecialchars($estudiante['nombre_programa']); ?></div>
                    <div style="font-size: 14px; color: var(--gray-600); margin-top: 5px;">
                        Semestre <?php echo $estudiante['semestre_actual']; ?>
                    </div>
                </div>
                
                <div class="resumen-item">
                    <div class="resumen-label">Créditos Inscritos</div>
                    <div class="resumen-valor"><?php echo $creditos_inscritos; ?> / <?php echo $limite_creditos; ?></div>
                    <div class="progreso-creditos">
                        <div class="progreso-bar">
                            <div class="progreso-fill" style="width: <?php echo min(100, ($creditos_inscritos / $limite_creditos) * 100); ?>%"></div>
                        </div>
                        <div style="font-size: 12px; color: var(--gray-600); margin-top: 5px;">
                            <?php echo $limite_creditos - $creditos_inscritos; ?> créditos disponibles
                        </div>
                    </div>
                </div>
                
                <div class="resumen-item">
                    <div class="resumen-label">Semestre Académico</div>
                    <div class="resumen-valor"><?php echo htmlspecialchars($semestre_actual['codigo_semestre']); ?></div>
                    <div style="font-size: 14px; color: var(--gray-600); margin-top: 5px;">
                        <?php echo htmlspecialchars($semestre_actual['nombre_semestre']); ?>
                    </div>
                </div>
            </div>
            
            <?php if ($creditos_inscritos >= $limite_creditos): ?>
            <div style="margin-top: 20px; padding: 15px; background: #fef2f2; border-radius: 6px; border-left: 4px solid #ef4444;">
                <h4 style="color: #dc2626; margin-bottom: 10px;">
                    <i class="fas fa-exclamation-triangle"></i> Límite de Créditos Alcanzado
                </h4>
                <p style="color: #7f1d1d; margin: 0;">
                    Has alcanzado el límite de <?php echo $limite_creditos; ?> créditos para este semestre.
                    Para inscribir más cursos, debes retirarte de algún curso actual.
                </p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Lista de Cursos Disponibles -->
        <div class="card">
            <h2><i class="fas fa-book-open"></i> Cursos Disponibles para Inscripción</h2>
            <p style="color: var(--gray-600); margin-bottom: 25px;">
                Selecciona un horario para cada curso que deseas inscribir.
                <strong>Nota:</strong> Solo se muestran cursos que cumples con prerrequisitos y que no tienes conflictos de horario.
            </p>
            
            <?php if (count($cursos_filtrados) > 0): ?>
                <?php foreach ($cursos_filtrados as $curso): ?>
                    <div class="curso-disponible">
                        <div class="curso-header">
                            <div class="curso-info">
                                <h3><?php echo htmlspecialchars($curso['nombre_curso']); ?></h3>
                                <div class="curso-meta">
                                    <span class="meta-item">
                                        <i class="fas fa-hashtag"></i> <?php echo $curso['codigo_curso']; ?>
                                    </span>
                                    <span class="meta-item">
                                        <i class="fas fa-layer-group"></i> Nivel <?php echo $curso['nivel_curso']; ?>
                                    </span>
                                    <span class="meta-item">
                                        <i class="fas fa-clock"></i> <?php echo $curso['horas_semanales']; ?> hrs/semana
                                    </span>
                                    <span class="meta-item">
                                        <i class="fas fa-graduation-cap"></i> <?php echo $curso['tipo_curso']; ?>
                                    </span>
                                </div>
                                <?php if ($curso['descripcion']): ?>
                                <p style="margin-top: 10px; color: var(--gray-600); line-height: 1.5;">
                                    <?php echo htmlspecialchars(substr($curso['descripcion'], 0, 150)); ?>
                                    <?php if (strlen($curso['descripcion']) > 150): ?>...<?php endif; ?>
                                </p>
                                <?php endif; ?>
                            </div>
                            
                            <div class="curso-creditos">
                                <?php echo $curso['creditos']; ?> créditos
                            </div>
                        </div>
                        
                        <?php if (!empty($curso['prerrequisitos'])): ?>
                        <div class="prerrequisitos-info">
                            <strong><i class="fas fa-check-circle"></i> Cumples con los prerrequisitos</strong>
                            <p style="margin: 5px 0 0 0; font-size: 13px;">
                                Este curso requiere cursos previos que ya has aprobado.
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (count($curso['horarios']) > 0): ?>
                        <div class="horarios-container">
                            <h4 class="horarios-title">
                                <i class="fas fa-calendar-alt"></i> Horarios Disponibles
                            </h4>
                            
                            <form method="POST" action="inscribir_curso.php">
                                <input type="hidden" name="curso_nombre" value="<?php echo htmlspecialchars($curso['nombre_curso']); ?>">
                                
                                <div class="horarios-grid">
                                    <?php foreach ($curso['horarios'] as $horario): ?>
                                        <?php
                                        // Verificar si hay cupo disponible
                                        $cupo_disponible = true;
                                        if ($horario['capacidad_grupo'] !== null) {
                                            $cupo_disponible = $horario['inscritos'] < $horario['capacidad_grupo'];
                                        }
                                        
                                        // Verificar si excede el límite de créditos
                                        $excede_creditos = ($creditos_inscritos + $curso['creditos']) > $limite_creditos;
                                        ?>
                                        
                                        <label class="horario-option <?php echo $excede_creditos ? 'disabled' : ''; ?>" 
                                               <?php if (!$excede_creditos): ?>onclick="seleccionarHorario(this)"<?php endif; ?>>
                                            <input type="radio" 
                                                   name="id_horario" 
                                                   value="<?php echo $horario['id_horario']; ?>" 
                                                   class="radio-seleccion"
                                                   <?php echo $excede_creditos ? 'disabled' : ''; ?>
                                                   required>
                                            
                                            <div class="horario-dia">
                                                <?php echo $horario['dia_semana']; ?>
                                            </div>
                                            
                                            <div class="horario-hora">
                                                <?php echo Funciones::formatearHora($horario['hora_inicio']); ?> - 
                                                <?php echo Funciones::formatearHora($horario['hora_fin']); ?>
                                            </div>
                                            
                                            <div class="horario-detalles">
                                                <div class="detalle-item">
                                                    <i class="fas fa-door-open"></i>
                                                    <?php echo htmlspecialchars($horario['codigo_aula']); ?>
                                                </div>
                                                
                                                <div class="detalle-item">
                                                    <i class="fas fa-chalkboard-teacher"></i>
                                                    <?php echo htmlspecialchars(substr($horario['profesor'], 0, 20)); ?>
                                                </div>
                                                
                                                <div class="detalle-item">
                                                    <i class="fas fa-users"></i>
                                                    Grupo <?php echo $horario['grupo'] ?: 'A'; ?>
                                                </div>
                                                
                                                <div class="detalle-item">
                                                    <i class="fas fa-graduation-cap"></i>
                                                    <?php echo ucfirst($horario['tipo_clase']); ?>
                                                </div>
                                            </div>
                                            
                                            <div class="cupo-info">
                                                <?php if ($horario['capacidad_grupo'] !== null): ?>
                                                    <i class="fas fa-user-friends"></i>
                                                    <span class="<?php echo $cupo_disponible ? 'cupo-libre' : 'cupo-lleno'; ?>">
                                                        <?php echo $horario['inscritos']; ?> / <?php echo $horario['capacidad_grupo']; ?> 
                                                        <?php echo $cupo_disponible ? 'Cupos libres' : 'Cupo lleno'; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <i class="fas fa-infinity"></i>
                                                    <span class="cupo-libre">Sin límite de cupo</span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if ($excede_creditos): ?>
                                            <div style="margin-top: 10px; padding: 8px; background: #fef2f2; border-radius: 4px; font-size: 12px; color: #dc2626;">
                                                <i class="fas fa-exclamation-circle"></i> Excede límite de créditos
                                            </div>
                                            <?php endif; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="curso-actions">
                                    <button type="submit" 
                                            name="inscribir" 
                                            class="btn btn-primary"
                                            onclick="return confirmarInscripcion('<?php echo htmlspecialchars($curso['nombre_curso']); ?>')">
                                        <i class="fas fa-check-circle"></i> Inscribir en este curso
                                    </button>
                                </div>
                            </form>
                        </div>
                        <?php else: ?>
                        <div class="sin-horarios">
                            <i class="fas fa-calendar-times"></i>
                            <strong>No hay horarios disponibles</strong>
                            <p style="margin: 5px 0 0 0;">
                                Este curso no tiene horarios asignados para el semestre actual.
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-cursos">
                    <i class="fas fa-book-open"></i>
                    <h3>No hay cursos disponibles</h3>
                    <p>No hay cursos disponibles para inscripción en este momento.</p>
                    <div style="margin-top: 20px;">
                        <p style="color: var(--gray-600); margin-bottom: 15px;">
                            Posibles razones:
                        </p>
                        <ul style="text-align: left; display: inline-block; color: var(--gray-600);">
                            <li>Ya estás inscrito en todos los cursos disponibles</li>
                            <li>No cumples con los prerrequisitos de los cursos</li>
                            <li>No hay horarios asignados para los cursos de tu nivel</li>
                            <li>Has alcanzado el límite de créditos del semestre</li>
                        </ul>
                    </div>
                    <a href="mis_cursos.php" class="btn btn-primary mt-3">
                        <i class="fas fa-arrow-left"></i> Volver a Mis Cursos
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Notas importantes -->
        <div class="card" style="background: #fef3c7; border-color: #f59e0b;">
            <h3 style="color: #92400e;">
                <i class="fas fa-exclamation-circle"></i> Notas Importantes
            </h3>
            <ul style="color: #92400e; margin-left: 20px;">
                <li>La inscripción es definitiva después de confirmar</li>
                <li>Puedes retirarte de un curso hasta la segunda semana de clases</li>
                <li>Verifica cuidadosamente los horarios para evitar conflictos</li>
                <li>El límite de créditos por semestre es de <?php echo $limite_creditos; ?> créditos</li>
                <li>Para problemas con la inscripción, contacta al coordinador de tu programa</li>
            </ul>
        </div>
    </div>
    
    <script src="../assets/js/main.js"></script>
    <script>
        // Seleccionar horario
        function seleccionarHorario(elemento) {
            // Deseleccionar todos los horarios de este curso
            const contenedor = elemento.closest('.horarios-container');
            contenedor.querySelectorAll('.horario-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            
            // Seleccionar el horario clickeado
            elemento.classList.add('selected');
            
            // Marcar el radio button
            const radio = elemento.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
            }
        }
        
        // Confirmar inscripción
        function confirmarInscripcion(cursoNombre) {
            const horarioSeleccionado = document.querySelector('input[name="id_horario"]:checked');
            
            if (!horarioSeleccionado) {
                alert('Por favor, selecciona un horario para el curso.');
                return false;
            }
            
            return confirm(`¿Estás seguro de que deseas inscribirte en el curso "${cursoNombre}"?\n\nEsta acción registrará tu inscripción en el sistema.`);
        }
        
        // Deshabilitar opciones si excede créditos
        document.addEventListener('DOMContentLoaded', function() {
            const creditoInscritos = <?php echo $creditos_inscritos; ?>;
            const limiteCreditos = <?php echo $limite_creditos; ?>;
            
            if (creditoInscritos >= limiteCreditos) {
                document.querySelectorAll('.horario-option').forEach(option => {
                    option.classList.add('disabled');
                    option.querySelector('input[type="radio"]').disabled = true;
                });
            }
        });
        
        // Mostrar detalles del curso al hacer clic en el nombre
        function verDetallesCurso(cursoId) {
            // Aquí podrías implementar un modal con más detalles
            alert('Detalles completos del curso #' + cursoId + '\n\nEsta funcionalidad mostraría información detallada del curso, incluyendo descripción completa, objetivos, metodología, etc.');
        }
    </script>
</body>
</html>