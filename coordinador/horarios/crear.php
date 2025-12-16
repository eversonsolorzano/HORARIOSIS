<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

$errores = [];
$conflictos = [];

// Obtener datos para selects
$cursos = $db->query("SELECT c.*, p.nombre_programa FROM cursos c JOIN programas_estudio p ON c.id_programa = p.id_programa WHERE c.activo = 1 ORDER BY c.semestre, c.nombre_curso")->fetchAll();
$profesores = $db->query("SELECT * FROM profesores WHERE activo = 1 ORDER BY apellidos, nombres")->fetchAll();
$aulas = $db->query("SELECT * FROM aulas WHERE disponible = 1 ORDER BY codigo_aula")->fetchAll();

// Obtener semestres activos
$semestres = $db->query("SELECT * FROM semestres_academicos WHERE estado = 'en_curso' OR estado = 'planificación' ORDER BY fecha_inicio DESC")->fetchAll();

$dias_semana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_curso = intval($_POST['id_curso']);
    $id_profesor = intval($_POST['id_profesor']);
    $id_aula = intval($_POST['id_aula']);
    $id_semestre = intval($_POST['id_semestre']);
    $dia_semana = Funciones::sanitizar($_POST['dia_semana']);
    $hora_inicio = Funciones::sanitizar($_POST['hora_inicio']);
    $hora_fin = Funciones::sanitizar($_POST['hora_fin']);
    $tipo_clase = Funciones::sanitizar($_POST['tipo_clase']);
    $grupo = Funciones::sanitizar($_POST['grupo']);
    $capacidad_grupo = intval($_POST['capacidad_grupo']);
    
    // Validaciones
    if (empty($id_curso)) $errores[] = 'El curso es requerido';
    if (empty($id_profesor)) $errores[] = 'El profesor es requerido';
    if (empty($id_aula)) $errores[] = 'El aula es requerida';
    if (empty($id_semestre)) $errores[] = 'El semestre es requerido';
    if (empty($dia_semana)) $errores[] = 'El día de la semana es requerido';
    if (empty($hora_inicio)) $errores[] = 'La hora de inicio es requerida';
    if (empty($hora_fin)) $errores[] = 'La hora de fin es requerida';
    if (empty($tipo_clase)) $errores[] = 'El tipo de clase es requerido';
    
    // Validar que hora_inicio sea menor que hora_fin
    if (strtotime($hora_inicio) >= strtotime($hora_fin)) {
        $errores[] = 'La hora de inicio debe ser anterior a la hora de fin';
    }
    
    // Validar capacidad del aula
    $stmt = $db->prepare("SELECT capacidad FROM aulas WHERE id_aula = ?");
    $stmt->execute([$id_aula]);
    $aula = $stmt->fetch();
    
    if ($aula && $capacidad_grupo > $aula['capacidad']) {
        $errores[] = 'La capacidad del grupo no puede exceder la capacidad del aula (' . $aula['capacidad'] . ' estudiantes)';
    }
    
    // Verificar conflictos
    if (empty($errores)) {
        // 1. Verificar conflicto de aula
        $stmt = $db->prepare("
            SELECT h.*, c.nombre_curso, CONCAT(p.nombres, ' ', p.apellidos) as profesor
            FROM horarios h
            JOIN cursos c ON h.id_curso = c.id_curso
            JOIN profesores p ON h.id_profesor = p.id_profesor
            WHERE h.id_aula = ? AND h.dia_semana = ? AND h.id_semestre = ? AND h.activo = 1
            AND (
                (h.hora_inicio < ? AND h.hora_fin > ?) OR
                (h.hora_inicio >= ? AND h.hora_inicio < ?) OR
                (h.hora_fin > ? AND h.hora_fin <= ?)
            )
        ");
        $stmt->execute([$id_aula, $dia_semana, $id_semestre, $hora_fin, $hora_inicio, $hora_inicio, $hora_fin, $hora_inicio, $hora_fin]);
        $conflicto_aula = $stmt->fetchAll();
        
        // 2. Verificar conflicto de profesor
        $stmt = $db->prepare("
            SELECT h.*, c.nombre_curso, a.codigo_aula
            FROM horarios h
            JOIN cursos c ON h.id_curso = c.id_curso
            JOIN aulas a ON h.id_aula = a.id_aula
            WHERE h.id_profesor = ? AND h.dia_semana = ? AND h.id_semestre = ? AND h.activo = 1
            AND (
                (h.hora_inicio < ? AND h.hora_fin > ?) OR
                (h.hora_inicio >= ? AND h.hora_inicio < ?) OR
                (h.hora_fin > ? AND h.hora_fin <= ?)
            )
        ");
        $stmt->execute([$id_profesor, $dia_semana, $id_semestre, $hora_fin, $hora_inicio, $hora_inicio, $hora_fin, $hora_inicio, $hora_fin]);
        $conflicto_profesor = $stmt->fetchAll();
        
        // 3. Verificar si ya existe el mismo curso/grupo/día/hora
        $stmt = $db->prepare("
            SELECT h.*, a.codigo_aula, CONCAT(p.nombres, ' ', p.apellidos) as profesor
            FROM horarios h
            JOIN aulas a ON h.id_aula = a.id_aula
            JOIN profesores p ON h.id_profesor = p.id_profesor
            WHERE h.id_curso = ? AND h.dia_semana = ? AND h.id_semestre = ? AND h.grupo = ? AND h.activo = 1
            AND (
                (h.hora_inicio < ? AND h.hora_fin > ?) OR
                (h.hora_inicio >= ? AND h.hora_inicio < ?) OR
                (h.hora_fin > ? AND h.hora_fin <= ?)
            )
        ");
        $stmt->execute([$id_curso, $dia_semana, $id_semestre, $grupo, $hora_fin, $hora_inicio, $hora_inicio, $hora_fin, $hora_inicio, $hora_fin]);
        $conflicto_curso = $stmt->fetchAll();
        
        if (!empty($conflicto_aula)) {
            foreach ($conflicto_aula as $conflicto) {
                $conflictos[] = [
                    'tipo' => 'aula',
                    'mensaje' => 'El aula ya está ocupada en ese horario por: ' . $conflicto['nombre_curso'] . ' (' . $conflicto['profesor'] . ')'
                ];
            }
        }
        
        if (!empty($conflicto_profesor)) {
            foreach ($conflicto_profesor as $conflicto) {
                $conflictos[] = [
                    'tipo' => 'profesor',
                    'mensaje' => 'El profesor ya tiene clase en ese horario: ' . $conflicto['nombre_curso'] . ' (Aula: ' . $conflicto['codigo_aula'] . ')'
                ];
            }
        }
        
        if (!empty($conflicto_curso)) {
            foreach ($conflicto_curso as $conflicto) {
                $conflictos[] = [
                    'tipo' => 'curso',
                    'mensaje' => 'Ya existe un horario para este curso/grupo en ese horario: Aula ' . $conflicto['codigo_aula'] . ' (' . $conflicto['profesor'] . ')'
                ];
            }
        }
    }
    
    if (empty($errores) && empty($conflictos)) {
        try {
            // Crear horario
            $stmt = $db->prepare("INSERT INTO horarios 
                (id_curso, id_profesor, id_aula, id_semestre, dia_semana, hora_inicio, hora_fin, 
                 tipo_clase, grupo, capacidad_grupo, creado_por) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $id_curso, $id_profesor, $id_aula, $id_semestre, $dia_semana, $hora_inicio, $hora_fin,
                $tipo_clase, $grupo, $capacidad_grupo, $user['id']
            ]);
            
            $id_horario = $db->lastInsertId();
            
            Funciones::redireccionar('index.php', 'Horario creado exitosamente');
            
        } catch (Exception $e) {
            $errores[] = 'Error al crear el horario: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Horario - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .conflicto-item {
            background: #fee;
            border-left: 4px solid #f56565;
            padding: 12px 15px;
            margin-bottom: 10px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .conflicto-item i {
            color: #f56565;
        }
        
        .conflicto-aula {
            border-left-color: #ed8936;
            background: #feebc8;
        }
        
        .conflicto-profesor {
            border-left-color: #4299e1;
            background: #bee3f8;
        }
        
        .conflicto-curso {
            border-left-color: #48bb78;
            background: #c6f6d5;
        }
        
        .horario-preview {
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            border-radius: var(--radius-xl);
            padding: 25px;
            margin-top: 20px;
            border: 2px solid var(--gray-200);
        }
        
        .preview-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary);
        }
        
        .preview-item {
            display: flex;
            margin-bottom: 10px;
        }
        
        .preview-label {
            font-weight: 600;
            color: var(--gray-700);
            width: 150px;
        }
        
        .preview-value {
            color: var(--dark);
            flex: 1;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-calendar-plus"></i> Crear Nuevo Horario</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-calendar-alt"></i> Horarios</a>
                <a href="crear.php" class="active"><i class="fas fa-calendar-plus"></i> Nuevo Horario</a>
            </div>
        </div>
        
        <?php if (!empty($errores)): ?>
            <div class="alert alert-error">
                <?php foreach ($errores as $error): ?>
                    <p><?php echo $error; ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($conflictos)): ?>
            <div class="alert alert-warning">
                <h4 style="margin-bottom: 10px;"><i class="fas fa-exclamation-triangle"></i> Conflictos Detectados</h4>
                <?php foreach ($conflictos as $conflicto): ?>
                    <div class="conflicto-item conflicto-<?php echo $conflicto['tipo']; ?>">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $conflicto['mensaje']; ?>
                    </div>
                <?php endforeach; ?>
                <p style="margin-top: 10px; font-size: 14px;">
                    <strong>¿Desea continuar de todas formas?</strong> El sistema le permitirá guardar, pero tenga en cuenta los conflictos.
                </p>
            </div>
        <?php endif; ?>
        
        <div class="grid-2">
            <!-- Formulario -->
            <div class="card">
                <form method="POST" action="" id="formHorario">
                    <h3 style="margin-bottom: 20px; color: var(--dark);">Información del Horario</h3>
                    
                    <div class="form-group">
                        <label for="id_curso">Curso *</label>
                        <select id="id_curso" name="id_curso" class="form-control" required onchange="actualizarPreview()">
                            <option value="">Seleccionar curso</option>
                            <?php foreach ($cursos as $curso): ?>
                                <option value="<?php echo $curso['id_curso']; ?>" 
                                    <?php echo isset($_POST['id_curso']) && $_POST['id_curso'] == $curso['id_curso'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($curso['nombre_curso']); ?> 
                                    (<?php echo $curso['codigo_curso']; ?> - Sem <?php echo $curso['semestre']; ?>)
                                    - <?php echo htmlspecialchars($curso['nombre_programa']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="id_profesor">Profesor *</label>
                        <select id="id_profesor" name="id_profesor" class="form-control" required onchange="actualizarPreview()">
                            <option value="">Seleccionar profesor</option>
                            <?php foreach ($profesores as $profesor): ?>
                                <option value="<?php echo $profesor['id_profesor']; ?>"
                                    <?php echo isset($_POST['id_profesor']) && $_POST['id_profesor'] == $profesor['id_profesor'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($profesor['nombres'] . ' ' . $profesor['apellidos']); ?>
                                    (<?php echo htmlspecialchars($profesor['especialidad']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="id_aula">Aula *</label>
                        <select id="id_aula" name="id_aula" class="form-control" required onchange="actualizarPreview()">
                            <option value="">Seleccionar aula</option>
                            <?php foreach ($aulas as $aula): ?>
                                <option value="<?php echo $aula['id_aula']; ?>"
                                    <?php echo isset($_POST['id_aula']) && $_POST['id_aula'] == $aula['id_aula'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($aula['codigo_aula']); ?> - 
                                    <?php echo htmlspecialchars($aula['nombre_aula']); ?>
                                    (Cap: <?php echo $aula['capacidad']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="id_semestre">Semestre Académico *</label>
                            <select id="id_semestre" name="id_semestre" class="form-control" required onchange="actualizarPreview()">
                                <option value="">Seleccionar semestre</option>
                                <?php foreach ($semestres as $semestre): ?>
                                    <option value="<?php echo $semestre['id_semestre']; ?>"
                                        <?php echo isset($_POST['id_semestre']) && $_POST['id_semestre'] == $semestre['id_semestre'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($semestre['nombre_semestre']); ?>
                                        (<?php echo $semestre['codigo_semestre']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="dia_semana">Día de la Semana *</label>
                            <select id="dia_semana" name="dia_semana" class="form-control" required onchange="actualizarPreview()">
                                <option value="">Seleccionar día</option>
                                <?php foreach ($dias_semana as $dia): ?>
                                    <option value="<?php echo $dia; ?>"
                                        <?php echo isset($_POST['dia_semana']) && $_POST['dia_semana'] == $dia ? 'selected' : ''; ?>>
                                        <?php echo $dia; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="hora_inicio">Hora de Inicio *</label>
                            <input type="time" id="hora_inicio" name="hora_inicio" class="form-control" required 
                                   value="<?php echo isset($_POST['hora_inicio']) ? htmlspecialchars($_POST['hora_inicio']) : '08:00'; ?>"
                                   onchange="actualizarPreview()">
                        </div>
                        
                        <div class="form-group">
                            <label for="hora_fin">Hora de Fin *</label>
                            <input type="time" id="hora_fin" name="hora_fin" class="form-control" required 
                                   value="<?php echo isset($_POST['hora_fin']) ? htmlspecialchars($_POST['hora_fin']) : '10:00'; ?>"
                                   onchange="actualizarPreview()">
                        </div>
                    </div>
                    
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="tipo_clase">Tipo de Clase *</label>
                            <select id="tipo_clase" name="tipo_clase" class="form-control" required onchange="actualizarPreview()">
                                <option value="">Seleccionar tipo</option>
                                <option value="teoría" <?php echo isset($_POST['tipo_clase']) && $_POST['tipo_clase'] == 'teoría' ? 'selected' : ''; ?>>Teoría</option>
                                <option value="práctica" <?php echo isset($_POST['tipo_clase']) && $_POST['tipo_clase'] == 'práctica' ? 'selected' : ''; ?>>Práctica</option>
                                <option value="laboratorio" <?php echo isset($_POST['tipo_clase']) && $_POST['tipo_clase'] == 'laboratorio' ? 'selected' : ''; ?>>Laboratorio</option>
                                <option value="taller" <?php echo isset($_POST['tipo_clase']) && $_POST['tipo_clase'] == 'taller' ? 'selected' : ''; ?>>Taller</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="grupo">Grupo</label>
                            <input type="text" id="grupo" name="grupo" class="form-control" 
                                   value="<?php echo isset($_POST['grupo']) ? htmlspecialchars($_POST['grupo']) : 'A'; ?>"
                                   placeholder="Ej: A, B, C..." onchange="actualizarPreview()">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="capacidad_grupo">Capacidad del Grupo</label>
                        <input type="number" id="capacidad_grupo" name="capacidad_grupo" class="form-control" 
                               value="<?php echo isset($_POST['capacidad_grupo']) ? htmlspecialchars($_POST['capacidad_grupo']) : '30'; ?>"
                               min="1" max="100" onchange="actualizarPreview()">
                    </div>
                    
                    <div style="margin-top: 30px; display: flex; gap: 15px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Guardar Horario
                        </button>
                        <a href="index.php" class="btn">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Vista Previa -->
            <div class="horario-preview">
                <h3 style="margin-bottom: 20px; color: var(--dark);">Vista Previa del Horario</h3>
                <div class="preview-card" id="previewHorario">
                    <div class="preview-item">
                        <div class="preview-label">Curso:</div>
                        <div class="preview-value" id="previewCurso">Seleccionar curso</div>
                    </div>
                    <div class="preview-item">
                        <div class="preview-label">Profesor:</div>
                        <div class="preview-value" id="previewProfesor">Seleccionar profesor</div>
                    </div>
                    <div class="preview-item">
                        <div class="preview-label">Aula:</div>
                        <div class="preview-value" id="previewAula">Seleccionar aula</div>
                    </div>
                    <div class="preview-item">
                        <div class="preview-label">Horario:</div>
                        <div class="preview-value" id="previewHorarioDetalle">
                            <span id="previewDia">---</span> 
                            <span id="previewHora">--:-- --:--</span>
                        </div>
                    </div>
                    <div class="preview-item">
                        <div class="preview-label">Tipo:</div>
                        <div class="preview-value" id="previewTipo">---</div>
                    </div>
                    <div class="preview-item">
                        <div class="preview-label">Grupo:</div>
                        <div class="preview-value" id="previewGrupo">---</div>
                    </div>
                    <div class="preview-item">
                        <div class="preview-label">Capacidad:</div>
                        <div class="preview-value" id="previewCapacidad">--- estudiantes</div>
                    </div>
                    <div class="preview-item">
                        <div class="preview-label">Semestre:</div>
                        <div class="preview-value" id="previewSemestre">Seleccionar semestre</div>
                    </div>
                </div>
                
                <!-- Información del Aula -->
                <div style="margin-top: 20px; padding: 15px; background: white; border-radius: var(--radius); border: 1px solid var(--gray-200);">
                    <h4 style="color: var(--dark); margin-bottom: 10px;">Información del Aula Seleccionada</h4>
                    <div id="infoAula" style="color: var(--gray-600);">
                        Seleccione un aula para ver sus detalles
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../../assets/js/main.js"></script>
    <script>
    // Datos de aulas para mostrar información
    const aulas = <?php echo json_encode($aulas); ?>;
    
    function actualizarPreview() {
        // Curso
        const cursoSelect = document.getElementById('id_curso');
        const cursoText = cursoSelect.options[cursoSelect.selectedIndex]?.text || 'Seleccionar curso';
        document.getElementById('previewCurso').textContent = cursoText;
        
        // Profesor
        const profesorSelect = document.getElementById('id_profesor');
        const profesorText = profesorSelect.options[profesorSelect.selectedIndex]?.text || 'Seleccionar profesor';
        document.getElementById('previewProfesor').textContent = profesorText;
        
        // Aula
        const aulaSelect = document.getElementById('id_aula');
        const aulaId = aulaSelect.value;
        const aulaText = aulaSelect.options[aulaSelect.selectedIndex]?.text || 'Seleccionar aula';
        document.getElementById('previewAula').textContent = aulaText;
        
        // Información del aula
        const infoAulaDiv = document.getElementById('infoAula');
        if (aulaId) {
            const aula = aulas.find(a => a.id_aula == aulaId);
            if (aula) {
                infoAulaDiv.innerHTML = `
                    <p><strong>Código:</strong> ${aula.codigo_aula}</p>
                    <p><strong>Nombre:</strong> ${aula.nombre_aula}</p>
                    <p><strong>Capacidad:</strong> ${aula.capacidad} estudiantes</p>
                    <p><strong>Tipo:</strong> ${aula.tipo_aula}</p>
                    <p><strong>Edificio:</strong> ${aula.edificio || 'No especificado'}</p>
                    <p><strong>Piso:</strong> ${aula.piso || 'No especificado'}</p>
                `;
            }
        } else {
            infoAulaDiv.textContent = 'Seleccione un aula para ver sus detalles';
        }
        
        // Día y hora
        const dia = document.getElementById('dia_semana').value || '---';
        const horaInicio = document.getElementById('hora_inicio').value || '--:--';
        const horaFin = document.getElementById('hora_fin').value || '--:--';
        
        // Formatear horas
        let horaInicioFormatted = '--:--';
        let horaFinFormatted = '--:--';
        
        if (horaInicio !== '--:--') {
            const [hours, minutes] = horaInicio.split(':');
            horaInicioFormatted = formatTime(hours, minutes);
        }
        
        if (horaFin !== '--:--') {
            const [hours, minutes] = horaFin.split(':');
            horaFinFormatted = formatTime(hours, minutes);
        }
        
        document.getElementById('previewDia').textContent = dia;
        document.getElementById('previewHora').textContent = `${horaInicioFormatted} - ${horaFinFormatted}`;
        
        // Tipo
        const tipo = document.getElementById('tipo_clase').value || '---';
        document.getElementById('previewTipo').textContent = tipo.charAt(0).toUpperCase() + tipo.slice(1);
        
        // Grupo
        const grupo = document.getElementById('grupo').value || '---';
        document.getElementById('previewGrupo').textContent = grupo;
        
        // Capacidad
        const capacidad = document.getElementById('capacidad_grupo').value || '---';
        document.getElementById('previewCapacidad').textContent = capacidad + ' estudiantes';
        
        // Semestre
        const semestreSelect = document.getElementById('id_semestre');
        const semestreText = semestreSelect.options[semestreSelect.selectedIndex]?.text || 'Seleccionar semestre';
        document.getElementById('previewSemestre').textContent = semestreText;
    }
    
    function formatTime(hours, minutes) {
        const h = parseInt(hours);
        const m = parseInt(minutes);
        const ampm = h >= 12 ? 'PM' : 'AM';
        const formattedHours = h % 12 || 12;
        return `${formattedHours}:${m.toString().padStart(2, '0')} ${ampm}`;
    }
    
    // Inicializar vista previa
    document.addEventListener('DOMContentLoaded', function() {
        actualizarPreview();
        
        // Validar que hora_inicio sea menor que hora_fin
        document.getElementById('formHorario').addEventListener('submit', function(e) {
            const horaInicio = document.getElementById('hora_inicio').value;
            const horaFin = document.getElementById('hora_fin').value;
            
            if (horaInicio && horaFin && horaInicio >= horaFin) {
                e.preventDefault();
                alert('La hora de inicio debe ser anterior a la hora de fin.');
                return false;
            }
        });
    });
    
    // Cargar información del aula cuando se selecciona
    document.getElementById('id_aula').addEventListener('change', function() {
        const aulaId = this.value;
        const infoAulaDiv = document.getElementById('infoAula');
        
        if (aulaId) {
            const aula = aulas.find(a => a.id_aula == aulaId);
            if (aula) {
                infoAulaDiv.innerHTML = `
                    <p><strong>Código:</strong> ${aula.codigo_aula}</p>
                    <p><strong>Nombre:</strong> ${aula.nombre_aula}</p>
                    <p><strong>Capacidad:</strong> ${aula.capacidad} estudiantes</p>
                    <p><strong>Tipo:</strong> ${aula.tipo_aula}</p>
                    <p><strong>Edificio:</strong> ${aula.edificio || 'No especificado'}</p>
                    <p><strong>Piso:</strong> ${aula.piso || 'No especificado'}</p>
                `;
            }
        } else {
            infoAulaDiv.textContent = 'Seleccione un aula para ver sus detalles';
        }
    });
    </script>
</body>
</html>