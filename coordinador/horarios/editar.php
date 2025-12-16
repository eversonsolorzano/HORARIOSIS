<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

$errores = [];
$conflictos = [];

// Obtener ID del horario
$id_horario = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_horario) {
    Funciones::redireccionar('index.php', 'ID de horario no válido', 'error');
}

// Obtener datos del horario
$stmt = $db->prepare("
    SELECT h.*, c.nombre_curso, c.codigo_curso, 
           CONCAT(p.nombres, ' ', p.apellidos) as profesor_nombre,
           a.codigo_aula, a.nombre_aula, a.capacidad as capacidad_aula,
           s.nombre_semestre, s.codigo_semestre
    FROM horarios h
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN profesores p ON h.id_profesor = p.id_profesor
    JOIN aulas a ON h.id_aula = a.id_aula
    JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
    WHERE h.id_horario = ?
");
$stmt->execute([$id_horario]);
$horario = $stmt->fetch();

if (!$horario) {
    Funciones::redireccionar('index.php', 'Horario no encontrado', 'error');
}

// Obtener datos para selects
$cursos = $db->query("SELECT c.*, pr.nombre_programa FROM cursos c JOIN programas_estudio pr ON c.id_programa = pr.id_programa WHERE c.activo = 1 ORDER BY c.semestre, c.nombre_curso")->fetchAll();
$profesores = $db->query("SELECT * FROM profesores WHERE activo = 1 ORDER BY apellidos, nombres")->fetchAll();
$aulas = $db->query("SELECT * FROM aulas WHERE disponible = 1 ORDER BY codigo_aula")->fetchAll();
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
    $activo = isset($_POST['activo']) ? 1 : 0;
    
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
    
    // Verificar conflictos (excluyendo el horario actual)
    if (empty($errores)) {
        // 1. Verificar conflicto de aula
        $stmt = $db->prepare("
            SELECT h.*, c.nombre_curso, CONCAT(p.nombres, ' ', p.apellidos) as profesor
            FROM horarios h
            JOIN cursos c ON h.id_curso = c.id_curso
            JOIN profesores p ON h.id_profesor = p.id_profesor
            WHERE h.id_aula = ? AND h.dia_semana = ? AND h.id_semestre = ? 
            AND h.id_horario != ? AND h.activo = 1
            AND (
                (h.hora_inicio < ? AND h.hora_fin > ?) OR
                (h.hora_inicio >= ? AND h.hora_inicio < ?) OR
                (h.hora_fin > ? AND h.hora_fin <= ?)
            )
        ");
        $stmt->execute([$id_aula, $dia_semana, $id_semestre, $id_horario, $hora_fin, $hora_inicio, $hora_inicio, $hora_fin, $hora_inicio, $hora_fin]);
        $conflicto_aula = $stmt->fetchAll();
        
        // 2. Verificar conflicto de profesor
        $stmt = $db->prepare("
            SELECT h.*, c.nombre_curso, a.codigo_aula
            FROM horarios h
            JOIN cursos c ON h.id_curso = c.id_curso
            JOIN aulas a ON h.id_aula = a.id_aula
            WHERE h.id_profesor = ? AND h.dia_semana = ? AND h.id_semestre = ? 
            AND h.id_horario != ? AND h.activo = 1
            AND (
                (h.hora_inicio < ? AND h.hora_fin > ?) OR
                (h.hora_inicio >= ? AND h.hora_inicio < ?) OR
                (h.hora_fin > ? AND h.hora_fin <= ?)
            )
        ");
        $stmt->execute([$id_profesor, $dia_semana, $id_semestre, $id_horario, $hora_fin, $hora_inicio, $hora_inicio, $hora_fin, $hora_inicio, $hora_fin]);
        $conflicto_profesor = $stmt->fetchAll();
        
        // 3. Verificar si ya existe el mismo curso/grupo/día/hora
        $stmt = $db->prepare("
            SELECT h.*, a.codigo_aula, CONCAT(p.nombres, ' ', p.apellidos) as profesor
            FROM horarios h
            JOIN aulas a ON h.id_aula = a.id_aula
            JOIN profesores p ON h.id_profesor = p.id_profesor
            WHERE h.id_curso = ? AND h.dia_semana = ? AND h.id_semestre = ? 
            AND h.grupo = ? AND h.id_horario != ? AND h.activo = 1
            AND (
                (h.hora_inicio < ? AND h.hora_fin > ?) OR
                (h.hora_inicio >= ? AND h.hora_inicio < ?) OR
                (h.hora_fin > ? AND h.hora_fin <= ?)
            )
        ");
        $stmt->execute([$id_curso, $dia_semana, $id_semestre, $grupo, $id_horario, $hora_fin, $hora_inicio, $hora_inicio, $hora_fin, $hora_inicio, $hora_fin]);
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
            // Registrar cambio en la tabla cambios_horario
            $cambios = [];
            
            if ($horario['id_curso'] != $id_curso) {
                $stmt = $db->prepare("SELECT nombre_curso FROM cursos WHERE id_curso = ?");
                $stmt->execute([$horario['id_curso']]);
                $curso_viejo = $stmt->fetch();
                $stmt->execute([$id_curso]);
                $curso_nuevo = $stmt->fetch();
                
                $cambios[] = [
                    'tipo' => 'curso',
                    'anterior' => $curso_viejo['nombre_curso'] ?? '',
                    'nuevo' => $curso_nuevo['nombre_curso'] ?? ''
                ];
            }
            
            if ($horario['id_profesor'] != $id_profesor) {
                $stmt = $db->prepare("SELECT CONCAT(nombres, ' ', apellidos) as nombre FROM profesores WHERE id_profesor = ?");
                $stmt->execute([$horario['id_profesor']]);
                $profesor_viejo = $stmt->fetch();
                $stmt->execute([$id_profesor]);
                $profesor_nuevo = $stmt->fetch();
                
                $cambios[] = [
                    'tipo' => 'profesor',
                    'anterior' => $profesor_viejo['nombre'] ?? '',
                    'nuevo' => $profesor_nuevo['nombre'] ?? ''
                ];
            }
            
            if ($horario['id_aula'] != $id_aula) {
                $stmt = $db->prepare("SELECT codigo_aula FROM aulas WHERE id_aula = ?");
                $stmt->execute([$horario['id_aula']]);
                $aula_vieja = $stmt->fetch();
                $stmt->execute([$id_aula]);
                $aula_nueva = $stmt->fetch();
                
                $cambios[] = [
                    'tipo' => 'aula',
                    'anterior' => $aula_vieja['codigo_aula'] ?? '',
                    'nuevo' => $aula_nueva['codigo_aula'] ?? ''
                ];
            }
            
            if ($horario['dia_semana'] != $dia_semana || $horario['hora_inicio'] != $hora_inicio || $horario['hora_fin'] != $hora_fin) {
                $cambios[] = [
                    'tipo' => 'hora',
                    'anterior' => $horario['dia_semana'] . ' ' . $horario['hora_inicio'] . '-' . $horario['hora_fin'],
                    'nuevo' => $dia_semana . ' ' . $hora_inicio . '-' . $hora_fin
                ];
            }
            
            // Actualizar horario
            $stmt = $db->prepare("UPDATE horarios SET 
                id_curso = ?, id_profesor = ?, id_aula = ?, id_semestre = ?,
                dia_semana = ?, hora_inicio = ?, hora_fin = ?,
                tipo_clase = ?, grupo = ?, capacidad_grupo = ?, activo = ?
                WHERE id_horario = ?");
            
            $stmt->execute([
                $id_curso, $id_profesor, $id_aula, $id_semestre,
                $dia_semana, $hora_inicio, $hora_fin,
                $tipo_clase, $grupo, $capacidad_grupo, $activo, $id_horario
            ]);
            
            // Registrar cambios si los hay
            foreach ($cambios as $cambio) {
                $stmt = $db->prepare("INSERT INTO cambios_horario 
                    (id_horario, tipo_cambio, valor_anterior, valor_nuevo, realizado_por, motivo) 
                    VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $id_horario, $cambio['tipo'], $cambio['anterior'], $cambio['nuevo'],
                    $user['id'], 'Actualización desde el panel de coordinador'
                ]);
            }
            
            Funciones::redireccionar('index.php', 'Horario actualizado exitosamente');
            
        } catch (Exception $e) {
            $errores[] = 'Error al actualizar el horario: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Horario - Sistema de Horarios</title>
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
        
        .info-horario-actual {
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            border-radius: var(--radius-xl);
            padding: 20px;
            margin-bottom: 20px;
            border: 2px solid var(--primary);
        }
        
        .info-item {
            display: flex;
            margin-bottom: 8px;
        }
        
        .info-label {
            font-weight: 600;
            color: var(--gray-700);
            width: 150px;
        }
        
        .info-value {
            color: var(--dark);
            flex: 1;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-edit"></i> Editar Horario</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-calendar-alt"></i> Horarios</a>
                <a href="editar.php?id=<?php echo $id_horario; ?>" class="active"><i class="fas fa-edit"></i> Editar Horario</a>
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
        
        <!-- Información actual del horario -->
        <div class="info-horario-actual">
            <h3 style="margin-bottom: 15px; color: var(--dark);">
                <i class="fas fa-info-circle"></i> Horario Actual
            </h3>
            <div class="info-item">
                <div class="info-label">Curso:</div>
                <div class="info-value">
                    <?php echo htmlspecialchars($horario['nombre_curso']); ?> 
                    (<?php echo htmlspecialchars($horario['codigo_curso']); ?>)
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Profesor:</div>
                <div class="info-value"><?php echo htmlspecialchars($horario['profesor_nombre']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Aula:</div>
                <div class="info-value">
                    <?php echo htmlspecialchars($horario['codigo_aula']); ?> - 
                    <?php echo htmlspecialchars($horario['nombre_aula']); ?>
                    (Cap: <?php echo $horario['capacidad_aula']; ?>)
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Horario:</div>
                <div class="info-value">
                    <?php echo $horario['dia_semana']; ?> 
                    <?php echo Funciones::formatearHora($horario['hora_inicio']); ?> - 
                    <?php echo Funciones::formatearHora($horario['hora_fin']); ?>
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Tipo:</div>
                <div class="info-value"><?php echo ucfirst($horario['tipo_clase']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Grupo:</div>
                <div class="info-value"><?php echo $horario['grupo'] ?: 'Único'; ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Capacidad Grupo:</div>
                <div class="info-value"><?php echo $horario['capacidad_grupo']; ?> estudiantes</div>
            </div>
            <div class="info-item">
                <div class="info-label">Semestre:</div>
                <div class="info-value">
                    <?php echo htmlspecialchars($horario['nombre_semestre']); ?>
                    (<?php echo htmlspecialchars($horario['codigo_semestre']); ?>)
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Estado:</div>
                <div class="info-value">
                    <span class="badge <?php echo $horario['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                        <?php echo $horario['activo'] ? 'Activo' : 'Inactivo'; ?>
                    </span>
                </div>
            </div>
        </div>
        
        <div class="card">
            <form method="POST" action="" id="formHorario">
                <h3 style="margin-bottom: 20px; color: var(--dark);">Editar Información del Horario</h3>
                
                <div class="form-group">
                    <label for="id_curso">Curso *</label>
                    <select id="id_curso" name="id_curso" class="form-control" required>
                        <option value="">Seleccionar curso</option>
                        <?php foreach ($cursos as $curso_item): ?>
                            <option value="<?php echo $curso_item['id_curso']; ?>" 
                                <?php echo (isset($_POST['id_curso']) && $_POST['id_curso'] == $curso_item['id_curso']) || $horario['id_curso'] == $curso_item['id_curso'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($curso_item['nombre_curso']); ?> 
                                (<?php echo $curso_item['codigo_curso']; ?> - Sem <?php echo $curso_item['semestre']; ?>)
                                - <?php echo htmlspecialchars($curso_item['nombre_programa']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label for="id_profesor">Profesor *</label>
                        <select id="id_profesor" name="id_profesor" class="form-control" required>
                            <option value="">Seleccionar profesor</option>
                            <?php foreach ($profesores as $profesor): ?>
                                <option value="<?php echo $profesor['id_profesor']; ?>"
                                    <?php echo (isset($_POST['id_profesor']) && $_POST['id_profesor'] == $profesor['id_profesor']) || $horario['id_profesor'] == $profesor['id_profesor'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($profesor['nombres'] . ' ' . $profesor['apellidos']); ?>
                                    (<?php echo htmlspecialchars($profesor['especialidad']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="id_aula">Aula *</label>
                        <select id="id_aula" name="id_aula" class="form-control" required>
                            <option value="">Seleccionar aula</option>
                            <?php foreach ($aulas as $aula): ?>
                                <option value="<?php echo $aula['id_aula']; ?>"
                                    <?php echo (isset($_POST['id_aula']) && $_POST['id_aula'] == $aula['id_aula']) || $horario['id_aula'] == $aula['id_aula'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($aula['codigo_aula']); ?> - 
                                    <?php echo htmlspecialchars($aula['nombre_aula']); ?>
                                    (Cap: <?php echo $aula['capacidad']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label for="id_semestre">Semestre Académico *</label>
                        <select id="id_semestre" name="id_semestre" class="form-control" required>
                            <option value="">Seleccionar semestre</option>
                            <?php foreach ($semestres as $semestre): ?>
                                <option value="<?php echo $semestre['id_semestre']; ?>"
                                    <?php echo (isset($_POST['id_semestre']) && $_POST['id_semestre'] == $semestre['id_semestre']) || $horario['id_semestre'] == $semestre['id_semestre'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($semestre['nombre_semestre']); ?>
                                    (<?php echo $semestre['codigo_semestre']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="dia_semana">Día de la Semana *</label>
                        <select id="dia_semana" name="dia_semana" class="form-control" required>
                            <option value="">Seleccionar día</option>
                            <?php foreach ($dias_semana as $dia): ?>
                                <option value="<?php echo $dia; ?>"
                                    <?php echo (isset($_POST['dia_semana']) && $_POST['dia_semana'] == $dia) || $horario['dia_semana'] == $dia ? 'selected' : ''; ?>>
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
                               value="<?php echo isset($_POST['hora_inicio']) ? htmlspecialchars($_POST['hora_inicio']) : $horario['hora_inicio']; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="hora_fin">Hora de Fin *</label>
                        <input type="time" id="hora_fin" name="hora_fin" class="form-control" required 
                               value="<?php echo isset($_POST['hora_fin']) ? htmlspecialchars($_POST['hora_fin']) : $horario['hora_fin']; ?>">
                    </div>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label for="tipo_clase">Tipo de Clase *</label>
                        <select id="tipo_clase" name="tipo_clase" class="form-control" required>
                            <option value="">Seleccionar tipo</option>
                            <option value="teoría" <?php echo (isset($_POST['tipo_clase']) && $_POST['tipo_clase'] == 'teoría') || $horario['tipo_clase'] == 'teoría' ? 'selected' : ''; ?>>Teoría</option>
                            <option value="práctica" <?php echo (isset($_POST['tipo_clase']) && $_POST['tipo_clase'] == 'práctica') || $horario['tipo_clase'] == 'práctica' ? 'selected' : ''; ?>>Práctica</option>
                            <option value="laboratorio" <?php echo (isset($_POST['tipo_clase']) && $_POST['tipo_clase'] == 'laboratorio') || $horario['tipo_clase'] == 'laboratorio' ? 'selected' : ''; ?>>Laboratorio</option>
                            <option value="taller" <?php echo (isset($_POST['tipo_clase']) && $_POST['tipo_clase'] == 'taller') || $horario['tipo_clase'] == 'taller' ? 'selected' : ''; ?>>Taller</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="grupo">Grupo</label>
                        <input type="text" id="grupo" name="grupo" class="form-control" 
                               value="<?php echo isset($_POST['grupo']) ? htmlspecialchars($_POST['grupo']) : $horario['grupo']; ?>"
                               placeholder="Ej: A, B, C...">
                    </div>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label for="capacidad_grupo">Capacidad del Grupo</label>
                        <input type="number" id="capacidad_grupo" name="capacidad_grupo" class="form-control" 
                               value="<?php echo isset($_POST['capacidad_grupo']) ? htmlspecialchars($_POST['capacidad_grupo']) : $horario['capacidad_grupo']; ?>"
                               min="1" max="100">
                    </div>
                    
                    <div class="form-group">
                        <label for="activo">
                            <input type="checkbox" id="activo" name="activo" value="1" 
                                   <?php echo (isset($_POST['activo']) || $horario['activo']) ? 'checked' : ''; ?>>
                            Horario Activo
                        </label>
                    </div>
                </div>
                
                <div style="margin-top: 30px; display: flex; gap: 15px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Actualizar Horario
                    </button>
                    <a href="ver.php?id=<?php echo $id_horario; ?>" class="btn btn-success">
                        <i class="fas fa-eye"></i> Ver Detalles
                    </a>
                    <a href="index.php" class="btn">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <script src="../../assets/js/main.js"></script>
    <script>
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
    </script>
</body>
</html>