<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

// Obtener parámetros para pre-selección
$estudiante_id = isset($_GET['estudiante']) ? intval($_GET['estudiante']) : 0;
$horario_id = isset($_GET['horario']) ? intval($_GET['horario']) : 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $id_estudiante = intval($_POST['id_estudiante']);
        $id_horario = intval($_POST['id_horario']);
        
        // Verificar si ya está inscrito
        $stmt = $db->prepare("
            SELECT id_inscripcion 
            FROM inscripciones 
            WHERE id_estudiante = ? AND id_horario = ? AND estado != 'retirado'
        ");
        $stmt->execute([$id_estudiante, $id_horario]);
        
        if ($stmt->fetch()) {
            throw new Exception("El estudiante ya está inscrito en este horario");
        }
        
        // Verificar capacidad del horario
        $stmt = $db->prepare("
            SELECT h.capacidad_grupo, 
                   COUNT(i.id_inscripcion) as inscritos_actuales
            FROM horarios h
            LEFT JOIN inscripciones i ON h.id_horario = i.id_horario AND i.estado = 'inscrito'
            WHERE h.id_horario = ?
            GROUP BY h.id_horario
        ");
        $stmt->execute([$id_horario]);
        $capacidad = $stmt->fetch();
        
        if ($capacidad['capacidad_grupo'] && $capacidad['inscritos_actuales'] >= $capacidad['capacidad_grupo']) {
            throw new Exception("El horario ha alcanzado su capacidad máxima de estudiantes");
        }
        
        // Verificar conflictos de horario
        $stmt = $db->prepare("
            SELECT h.dia_semana, h.hora_inicio, h.hora_fin, c.nombre_curso
            FROM inscripciones i
            JOIN horarios h ON i.id_horario = h.id_horario
            JOIN cursos c ON h.id_curso = c.id_curso
            WHERE i.id_estudiante = ? AND i.estado = 'inscrito'
            AND h.dia_semana = (
                SELECT dia_semana FROM horarios WHERE id_horario = ?
            )
            AND (
                (h.hora_inicio <= (SELECT hora_fin FROM horarios WHERE id_horario = ?) 
                AND h.hora_fin >= (SELECT hora_inicio FROM horarios WHERE id_horario = ?))
            )
        ");
        $stmt->execute([$id_estudiante, $id_horario, $id_horario, $id_horario]);
        
        if ($conflicto = $stmt->fetch()) {
            throw new Exception("El estudiante tiene conflicto de horario con: " . 
                              $conflicto['nombre_curso'] . " (" . 
                              $conflicto['dia_semana'] . " " . 
                              Funciones::formatearHora($conflicto['hora_inicio']) . "-" . 
                              Funciones::formatearHora($conflicto['hora_fin']) . ")");
        }
        
        // Verificar prerrequisitos
        $stmt = $db->prepare("
            SELECT c.prerrequisitos, c.nombre_curso
            FROM horarios h
            JOIN cursos c ON h.id_curso = c.id_curso
            WHERE h.id_horario = ?
        ");
        $stmt->execute([$id_horario]);
        $curso = $stmt->fetch();
        
        if ($curso['prerrequisitos']) {
            $prerrequisitos = array_map('intval', explode(',', $curso['prerrequisitos']));
            $prerrequisitos = array_filter($prerrequisitos);
            
            if (!empty($prerrequisitos)) {
                $placeholders = str_repeat('?,', count($prerrequisitos) - 1) . '?';
                $sql = "SELECT COUNT(DISTINCT c.id_curso) as aprobados
                        FROM inscripciones i
                        JOIN horarios h ON i.id_horario = h.id_horario
                        JOIN cursos c ON h.id_curso = c.id_curso
                        WHERE i.id_estudiante = ? 
                        AND i.estado = 'aprobado'
                        AND c.id_curso IN ($placeholders)";
                
                $params = array_merge([$id_estudiante], $prerrequisitos);
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $aprobados = $stmt->fetch()['aprobados'];
                
                if ($aprobados < count($prerrequisitos)) {
                    throw new Exception("El estudiante no cumple con los prerrequisitos del curso");
                }
            }
        }
        
        // Insertar inscripción
        $stmt = $db->prepare("
            INSERT INTO inscripciones (id_estudiante, id_horario, fecha_inscripcion, estado)
            VALUES (?, ?, CURDATE(), 'inscrito')
        ");
        $stmt->execute([$id_estudiante, $id_horario]);
        
        $id_inscripcion = $db->lastInsertId();
        
        // Obtener información para notificación
        $stmt = $db->prepare("
            SELECT e.nombres, e.apellidos, e.id_usuario,
                   c.nombre_curso, h.dia_semana, h.hora_inicio, h.hora_fin
            FROM estudiantes e
            JOIN horarios h ON h.id_horario = ?
            JOIN cursos c ON h.id_curso = c.id_curso
            WHERE e.id_estudiante = ?
        ");
        $stmt->execute([$id_horario, $id_estudiante]);
        $info = $stmt->fetch();
        
        // Notificar al estudiante
        $stmt = $db->prepare("
            INSERT INTO notificaciones (id_usuario, tipo_notificacion, titulo, mensaje)
            VALUES (?, 'nuevo_curso', 'Inscripción realizada', ?)
        ");
        
        $mensaje = "Has sido inscrito exitosamente en: <strong>" . $info['nombre_curso'] . "</strong><br>";
        $mensaje .= "Horario: " . $info['dia_semana'] . " " . 
                   Funciones::formatearHora($info['hora_inicio']) . " - " . 
                   Funciones::formatearHora($info['hora_fin']) . "<br>";
        $mensaje .= "Fecha de inscripción: " . date('d/m/Y');
        
        $stmt->execute([$info['id_usuario'], $mensaje]);
        
        Session::setFlash('Inscripción realizada exitosamente');
        Funciones::redireccionar('index.php');
        
    } catch (Exception $e) {
        Session::setFlash('Error al realizar la inscripción: ' . $e->getMessage(), 'error');
    }
}

// Obtener estudiantes activos
$estudiantes = $db->query("
    SELECT e.*, p.nombre_programa
    FROM estudiantes e
    JOIN programas_estudio p ON e.id_programa = p.id_programa
    WHERE e.estado = 'activo'
    ORDER BY e.apellidos, e.nombres
")->fetchAll();

// Obtener horarios disponibles
$horarios = $db->query("
    SELECT h.*, 
           c.nombre_curso, c.codigo_curso, c.semestre,
           p.nombre_programa,
           s.codigo_semestre, s.nombre_semestre,
           CONCAT(pr.nombres, ' ', pr.apellidos) as profesor_nombre,
           COUNT(i.id_inscripcion) as inscritos_actuales
    FROM horarios h
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN programas_estudio p ON c.id_programa = p.id_programa
    JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
    JOIN profesores pr ON h.id_profesor = pr.id_profesor
    LEFT JOIN inscripciones i ON h.id_horario = i.id_horario AND i.estado = 'inscrito'
    WHERE h.activo = 1 AND s.estado != 'finalizado'
    GROUP BY h.id_horario
    HAVING h.capacidad_grupo IS NULL OR inscritos_actuales < h.capacidad_grupo
    ORDER BY p.nombre_programa, c.semestre, c.nombre_curso, h.dia_semana, h.hora_inicio
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Inscripción - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .form-container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .form-steps {
            display: flex;
            margin-bottom: 30px;
            border-bottom: 2px solid var(--gray-200);
        }
        
        .step {
            padding: 15px 25px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            font-weight: 500;
            color: var(--gray-600);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .step:hover {
            color: var(--primary);
        }
        
        .step.active {
            border-bottom-color: var(--primary);
            color: var(--primary);
        }
        
        .step-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .step-content.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-700);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .required {
            color: var(--danger);
        }
        
        .help-text {
            font-size: 13px;
            color: var(--gray-500);
            margin-top: 5px;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            padding-top: 25px;
            margin-top: 25px;
            border-top: 2px solid var(--gray-100);
        }
        
        .info-box {
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            border-left: 4px solid var(--primary);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .info-box h4 {
            color: var(--dark);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
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
        
        .horario-card {
            background: white;
            border: 2px solid var(--gray-300);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .horario-card:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .horario-card.selected {
            border-color: var(--primary);
            background: linear-gradient(135deg, var(--primary-light) 0%, rgba(59, 130, 246, 0.1) 100%);
            box-shadow: var(--shadow);
        }
        
        .horario-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .horario-header h4 {
            color: var(--dark);
            margin: 0;
            font-size: 16px;
        }
        
        .horario-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .detail-label {
            font-size: 12px;
            color: var(--gray-600);
            margin-bottom: 3px;
        }
        
        .detail-value {
            font-size: 14px;
            color: var(--dark);
            font-weight: 500;
        }
        
        .capacidad-info {
            font-size: 13px;
            color: var(--gray-600);
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid var(--gray-200);
        }
        
        .estudiante-card {
            background: white;
            border: 2px solid var(--gray-300);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .estudiante-card:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .estudiante-card.selected {
            border-color: var(--primary);
            background: linear-gradient(135deg, var(--primary-light) 0%, rgba(59, 130, 246, 0.1) 100%);
            box-shadow: var(--shadow);
        }
        
        .estudiante-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .estudiante-header h4 {
            color: var(--dark);
            margin: 0;
            font-size: 16px;
        }
        
        .estudiante-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-plus"></i> Nueva Inscripción</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-clipboard-check"></i> Inscripciones</a>
                <a href="crear.php" class="active"><i class="fas fa-plus"></i> Nueva Inscripción</a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <h4><i class="fas fa-info-circle"></i> Información importante</h4>
            <p>Para realizar una inscripción, debe:</p>
            <ul style="margin: 10px 0 10px 20px; line-height: 1.8;">
                <li>Seleccionar un estudiante activo</li>
                <li>Seleccionar un horario disponible</li>
                <li>Verificar que no haya conflictos de horario</li>
                <li>Verificar que el estudiante cumpla con los prerrequisitos</li>
                <li>Verificar la capacidad disponible del horario</li>
            </ul>
        </div>
        
        <form method="POST" class="card form-container">
            <div class="card-header">
                <h2><i class="fas fa-user-graduate"></i> Inscribir Estudiante</h2>
            </div>
            
            <div class="card-body">
                <!-- Pasos -->
                <div class="form-steps">
                    <div class="step active" onclick="mostrarPaso(1)">
                        <i class="fas fa-user"></i> Paso 1: Seleccionar Estudiante
                    </div>
                    <div class="step" onclick="mostrarPaso(2)">
                        <i class="fas fa-calendar-alt"></i> Paso 2: Seleccionar Horario
                    </div>
                    <div class="step" onclick="mostrarPaso(3)">
                        <i class="fas fa-check-circle"></i> Paso 3: Confirmar
                    </div>
                </div>
                
                <!-- Paso 1: Seleccionar Estudiante -->
                <div id="paso1" class="step-content active">
                    <div class="form-group">
                        <label for="buscar_estudiante">
                            <i class="fas fa-search"></i> Buscar Estudiante
                        </label>
                        <input type="text" id="buscar_estudiante" class="form-control"
                               placeholder="Buscar por nombre, apellido o código..."
                               onkeyup="filtrarEstudiantes()">
                    </div>
                    
                    <div id="lista-estudiantes">
                        <?php foreach ($estudiantes as $estudiante): ?>
                            <div class="estudiante-card" 
                                 onclick="seleccionarEstudiante(<?php echo $estudiante['id_estudiante']; ?>, this)"
                                 data-nombre="<?php echo htmlspecialchars($estudiante['nombres'] . ' ' . $estudiante['apellidos']); ?>"
                                 data-codigo="<?php echo $estudiante['codigo_estudiante']; ?>"
                                 <?php echo $estudiante_id == $estudiante['id_estudiante'] ? 'data-selected="true"' : ''; ?>>
                                
                                <div class="estudiante-header">
                                    <h4><?php echo htmlspecialchars($estudiante['nombres'] . ' ' . $estudiante['apellidos']); ?></h4>
                                    <span class="codigo"><?php echo $estudiante['codigo_estudiante']; ?></span>
                                </div>
                                
                                <div class="estudiante-info">
                                    <div class="detail-item">
                                        <div class="detail-label">Programa</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($estudiante['nombre_programa']); ?></div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Semestre Actual</div>
                                        <div class="detail-value"><?php echo $estudiante['semestre_actual']; ?></div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Documento</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($estudiante['documento_identidad']); ?></div>
                                    </div>
                                </div>
                                
                                <input type="radio" name="id_estudiante" 
                                       value="<?php echo $estudiante['id_estudiante']; ?>"
                                       style="display: none;"
                                       <?php echo $estudiante_id == $estudiante['id_estudiante'] ? 'checked' : ''; ?>>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (empty($estudiantes)): ?>
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <p>No hay estudiantes activos disponibles</p>
                            <a href="../estudiantes/crear.php" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Crear Estudiante
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Paso 2: Seleccionar Horario -->
                <div id="paso2" class="step-content">
                    <div class="form-group">
                        <label for="buscar_horario">
                            <i class="fas fa-search"></i> Buscar Horario
                        </label>
                        <input type="text" id="buscar_horario" class="form-control"
                               placeholder="Buscar por curso, programa o profesor..."
                               onkeyup="filtrarHorarios()">
                    </div>
                    
                    <div id="lista-horarios">
                        <?php foreach ($horarios as $horario): ?>
                            <?php
                            $disponibilidad = '';
                            $color_disponibilidad = 'var(--success)';
                            if ($horario['capacidad_grupo']) {
                                $disponibles = $horario['capacidad_grupo'] - $horario['inscritos_actuales'];
                                $disponibilidad = "$disponibles de {$horario['capacidad_grupo']} cupos disponibles";
                                if ($disponibles <= 0) {
                                    $color_disponibilidad = 'var(--danger)';
                                } elseif ($disponibles <= 3) {
                                    $color_disponibilidad = 'var(--warning)';
                                }
                            } else {
                                $disponibilidad = "Sin límite de capacidad";
                            }
                            ?>
                            
                            <div class="horario-card" 
                                 onclick="seleccionarHorario(<?php echo $horario['id_horario']; ?>, this)"
                                 data-curso="<?php echo htmlspecialchars($horario['nombre_curso']); ?>"
                                 data-programa="<?php echo htmlspecialchars($horario['nombre_programa']); ?>"
                                 data-profesor="<?php echo htmlspecialchars($horario['profesor_nombre']); ?>"
                                 <?php echo $horario_id == $horario['id_horario'] ? 'data-selected="true"' : ''; ?>>
                                
                                <div class="horario-header">
                                    <h4><?php echo htmlspecialchars($horario['nombre_curso']); ?></h4>
                                    <span class="codigo"><?php echo $horario['codigo_curso']; ?></span>
                                </div>
                                
                                <div class="horario-details">
                                    <div class="detail-item">
                                        <div class="detail-label">Programa</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($horario['nombre_programa']); ?></div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Semestre Curso</div>
                                        <div class="detail-value"><?php echo $horario['semestre']; ?></div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Horario</div>
                                        <div class="detail-value">
                                            <?php echo $horario['dia_semana']; ?> 
                                            <?php echo Funciones::formatearHora($horario['hora_inicio']); ?> - 
                                            <?php echo Funciones::formatearHora($horario['hora_fin']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Profesor</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($horario['profesor_nombre']); ?></div>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Semestre Académico</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($horario['nombre_semestre']); ?></div>
                                    </div>
                                    
                                    <?php if ($horario['grupo']): ?>
                                    <div class="detail-item">
                                        <div class="detail-label">Grupo</div>
                                        <div class="detail-value"><?php echo $horario['grupo']; ?></div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="detail-item">
                                        <div class="detail-label">Tipo de Clase</div>
                                        <div class="detail-value"><?php echo ucfirst($horario['tipo_clase']); ?></div>
                                    </div>
                                </div>
                                
                                <div class="capacidad-info" style="color: <?php echo $color_disponibilidad; ?>;">
                                    <i class="fas fa-users"></i> <?php echo $disponibilidad; ?>
                                </div>
                                
                                <input type="radio" name="id_horario" 
                                       value="<?php echo $horario['id_horario']; ?>"
                                       style="display: none;"
                                       <?php echo $horario_id == $horario['id_horario'] ? 'checked' : ''; ?>>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (empty($horarios)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <p>No hay horarios disponibles</p>
                            <a href="../horarios/crear.php" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Crear Horario
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Paso 3: Confirmar -->
                <div id="paso3" class="step-content">
                    <div id="resumen-inscripcion" class="info-box">
                        <h4><i class="fas fa-clipboard-check"></i> Resumen de la Inscripción</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 15px;">
                            <div>
                                <h5><i class="fas fa-user"></i> Estudiante Seleccionado</h5>
                                <div id="resumen-estudiante" class="text-muted">
                                    Seleccione un estudiante en el paso 1
                                </div>
                            </div>
                            <div>
                                <h5><i class="fas fa-calendar-alt"></i> Horario Seleccionado</h5>
                                <div id="resumen-horario" class="text-muted">
                                    Seleccione un horario en el paso 2
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Verificaciones automáticas:</strong> Al enviar el formulario, el sistema verificará:
                        <ul style="margin: 10px 0 10px 20px;">
                            <li>Conflictos de horario</li>
                            <li>Cumplimiento de prerrequisitos</li>
                            <li>Capacidad disponible</li>
                            <li>Inscripción duplicada</li>
                        </ul>
                    </div>
                </div>
                
                <!-- Botones de navegación -->
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="btn-anterior" onclick="pasoAnterior()" style="display: none;">
                        <i class="fas fa-arrow-left"></i> Anterior
                    </button>
                    
                    <button type="button" class="btn btn-primary" id="btn-siguiente" onclick="pasoSiguiente()">
                        Siguiente <i class="fas fa-arrow-right"></i>
                    </button>
                    
                    <button type="submit" class="btn btn-success" id="btn-enviar" style="display: none;">
                        <i class="fas fa-check-circle"></i> Confirmar Inscripción
                    </button>
                    
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
            </div>
        </form>
    </div>
    
    <script>
        let pasoActual = 1;
        let estudianteSeleccionado = null;
        let horarioSeleccionado = null;
        
        function mostrarPaso(paso) {
            if (paso < 1 || paso > 3) return;
            
            // Validar paso actual antes de cambiar
            if (paso === 2 && !estudianteSeleccionado) {
                alert('Debe seleccionar un estudiante primero');
                return;
            }
            
            if (paso === 3 && (!estudianteSeleccionado || !horarioSeleccionado)) {
                alert('Debe seleccionar un estudiante y un horario primero');
                return;
            }
            
            // Ocultar todos los pasos
            document.querySelectorAll('.step-content').forEach(content => {
                content.classList.remove('active');
            });
            document.querySelectorAll('.step').forEach(step => {
                step.classList.remove('active');
            });
            
            // Mostrar paso seleccionado
            document.getElementById('paso' + paso).classList.add('active');
            document.querySelectorAll('.step')[paso - 1].classList.add('active');
            
            // Actualizar botones
            pasoActual = paso;
            actualizarBotones();
            
            // Actualizar resumen si es el paso 3
            if (paso === 3) {
                actualizarResumen();
            }
        }
        
        function pasoAnterior() {
            mostrarPaso(pasoActual - 1);
        }
        
        function pasoSiguiente() {
            mostrarPaso(pasoActual + 1);
        }
        
        function actualizarBotones() {
            const btnAnterior = document.getElementById('btn-anterior');
            const btnSiguiente = document.getElementById('btn-siguiente');
            const btnEnviar = document.getElementById('btn-enviar');
            
            btnAnterior.style.display = pasoActual > 1 ? 'inline-flex' : 'none';
            btnSiguiente.style.display = pasoActual < 3 ? 'inline-flex' : 'none';
            btnEnviar.style.display = pasoActual === 3 ? 'inline-flex' : 'none';
        }
        
        function seleccionarEstudiante(id, elemento) {
            // Remover selección anterior
            document.querySelectorAll('.estudiante-card').forEach(card => {
                card.classList.remove('selected');
                card.querySelector('input[type="radio"]').checked = false;
            });
            
            // Seleccionar nuevo
            elemento.classList.add('selected');
            elemento.querySelector('input[type="radio"]').checked = true;
            
            estudianteSeleccionado = {
                id: id,
                nombre: elemento.getAttribute('data-nombre'),
                codigo: elemento.getAttribute('data-codigo')
            };
            
            // Habilitar paso 2
            document.querySelectorAll('.step')[1].style.opacity = '1';
            document.querySelectorAll('.step')[1].style.cursor = 'pointer';
        }
        
        function seleccionarHorario(id, elemento) {
            // Remover selección anterior
            document.querySelectorAll('.horario-card').forEach(card => {
                card.classList.remove('selected');
                card.querySelector('input[type="radio"]').checked = false;
            });
            
            // Seleccionar nuevo
            elemento.classList.add('selected');
            elemento.querySelector('input[type="radio"]').checked = true;
            
            horarioSeleccionado = {
                id: id,
                curso: elemento.getAttribute('data-curso'),
                programa: elemento.getAttribute('data-programa'),
                profesor: elemento.getAttribute('data-profesor')
            };
            
            // Habilitar paso 3
            document.querySelectorAll('.step')[2].style.opacity = '1';
            document.querySelectorAll('.step')[2].style.cursor = 'pointer';
        }
        
        function actualizarResumen() {
            if (estudianteSeleccionado) {
                document.getElementById('resumen-estudiante').innerHTML = `
                    <strong>${estudianteSeleccionado.nombre}</strong><br>
                    <small>Código: ${estudianteSeleccionado.codigo}</small>
                `;
            }
            
            if (horarioSeleccionado) {
                document.getElementById('resumen-horario').innerHTML = `
                    <strong>${horarioSeleccionado.curso}</strong><br>
                    <small>Programa: ${horarioSeleccionado.programa}</small><br>
                    <small>Profesor: ${horarioSeleccionado.profesor}</small>
                `;
            }
        }
        
        function filtrarEstudiantes() {
            const busqueda = document.getElementById('buscar_estudiante').value.toLowerCase();
            const estudiantes = document.querySelectorAll('.estudiante-card');
            
            estudiantes.forEach(estudiante => {
                const nombre = estudiante.getAttribute('data-nombre').toLowerCase();
                const codigo = estudiante.getAttribute('data-codigo').toLowerCase();
                
                if (nombre.includes(busqueda) || codigo.includes(busqueda)) {
                    estudiante.style.display = 'block';
                } else {
                    estudiante.style.display = 'none';
                }
            });
        }
        
        function filtrarHorarios() {
            const busqueda = document.getElementById('buscar_horario').value.toLowerCase();
            const horarios = document.querySelectorAll('.horario-card');
            
            horarios.forEach(horario => {
                const curso = horario.getAttribute('data-curso').toLowerCase();
                const programa = horario.getAttribute('data-programa').toLowerCase();
                const profesor = horario.getAttribute('data-profesor').toLowerCase();
                
                if (curso.includes(busqueda) || programa.includes(busqueda) || profesor.includes(busqueda)) {
                    horario.style.display = 'block';
                } else {
                    horario.style.display = 'none';
                }
            });
        }
        
        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            actualizarBotones();
            
            // Preseleccionar si hay parámetros
            <?php if ($estudiante_id > 0): ?>
                const estudiantePre = document.querySelector(`.estudiante-card input[value="<?php echo $estudiante_id; ?>"]`);
                if (estudiantePre) {
                    seleccionarEstudiante(<?php echo $estudiante_id; ?>, estudiantePre.closest('.estudiante-card'));
                }
            <?php endif; ?>
            
            <?php if ($horario_id > 0): ?>
                const horarioPre = document.querySelector(`.horario-card input[value="<?php echo $horario_id; ?>"]`);
                if (horarioPre) {
                    seleccionarHorario(<?php echo $horario_id; ?>, horarioPre.closest('.horario-card'));
                }
            <?php endif; ?>
            
            // Deshabilitar pasos no completados
            document.querySelectorAll('.step')[1].style.opacity = estudianteSeleccionado ? '1' : '0.5';
            document.querySelectorAll('.step')[1].style.cursor = estudianteSeleccionado ? 'pointer' : 'not-allowed';
            
            document.querySelectorAll('.step')[2].style.opacity = (estudianteSeleccionado && horarioSeleccionado) ? '1' : '0.5';
            document.querySelectorAll('.step')[2].style.cursor = (estudianteSeleccionado && horarioSeleccionado) ? 'pointer' : 'not-allowed';
        });
        
        // Validar formulario antes de enviar
        document.querySelector('form').addEventListener('submit', function(e) {
            if (!estudianteSeleccionado || !horarioSeleccionado) {
                e.preventDefault();
                alert('Debe seleccionar un estudiante y un horario');
                return false;
            }
            
            if (!confirm('¿Confirmar la inscripción del estudiante en el horario seleccionado?')) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>