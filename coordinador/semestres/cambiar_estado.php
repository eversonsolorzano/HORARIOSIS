<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

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

// Verificar si hay horarios activos
$stmt = $db->prepare("SELECT COUNT(*) FROM horarios WHERE id_semestre = ? AND activo = 1");
$stmt->execute([$id_semestre]);
$horarios_activos = $stmt->fetchColumn();

// Obtener estados posibles
$estados = [
    'planificación' => 'Planificación',
    'en_curso' => 'En Curso',
    'finalizado' => 'Finalizado'
];

$estado_actual = $semestre['estado'];

// Determinar próximos estados posibles
$proximos_estados = [];

switch ($estado_actual) {
    case 'planificación':
        $proximos_estados['en_curso'] = 'Marcar como En Curso';
        break;
        
    case 'en_curso':
        $proximos_estados['finalizado'] = 'Finalizar Semestre';
        break;
        
    case 'finalizado':
        // Una vez finalizado, no se puede cambiar
        break;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nuevo_estado = Funciones::sanitizar($_POST['nuevo_estado']);
    $motivo = Funciones::sanitizar($_POST['motivo']);
    $confirmacion = Funciones::sanitizar($_POST['confirmacion']);
    
    // Validaciones
    $errores = [];
    
    if (!in_array($nuevo_estado, array_keys($proximos_estados))) {
        $errores[] = 'Estado de transición no válido';
    }
    
    if (empty($motivo)) {
        $errores[] = 'Debe especificar un motivo para el cambio';
    }
    
    if ($confirmacion !== 'CONFIRMAR') {
        $errores[] = 'Debe escribir CONFIRMAR para proceder';
    }
    
    // Validaciones específicas por estado
    if ($nuevo_estado == 'en_curso') {
        // Verificar que no haya otro semestre en curso
        $stmt = $db->prepare("SELECT COUNT(*) FROM semestres_academicos WHERE estado = 'en_curso' AND id_semestre != ?");
        $stmt->execute([$id_semestre]);
        $otros_en_curso = $stmt->fetchColumn();
        
        if ($otros_en_curso > 0) {
            $errores[] = 'Ya existe otro semestre en curso. Solo puede haber un semestre activo a la vez.';
        }
        
        // Verificar que la fecha de inicio no sea futura
        $hoy = new DateTime();
        $inicio_semestre = new DateTime($semestre['fecha_inicio']);
        
        if ($hoy < $inicio_semestre) {
            $errores[] = 'No puede iniciar un semestre antes de su fecha de inicio programada';
        }
    }
    
    if ($nuevo_estado == 'finalizado') {
        // Verificar que la fecha de fin haya pasado
        $hoy = new DateTime();
        $fin_semestre = new DateTime($semestre['fecha_fin']);
        
        if ($hoy < $fin_semestre) {
            $errores[] = 'No puede finalizar un semestre antes de su fecha de fin programada';
        }
        
        // Verificar que no haya estudiantes inscritos sin calificación
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM inscripciones i
            JOIN horarios h ON i.id_horario = h.id_horario
            WHERE h.id_semestre = ? 
            AND i.estado = 'inscrito'
            AND i.nota_final IS NULL
        ");
        $stmt->execute([$id_semestre]);
        $sin_calificacion = $stmt->fetchColumn();
        
        if ($sin_calificacion > 0) {
            $errores[] = "No puede finalizar el semestre porque hay $sin_calificacion estudiantes sin calificación final";
        }
    }
    
    if (empty($errores)) {
        try {
            $db->beginTransaction();
            
            // Actualizar estado del semestre
            $stmt = $db->prepare("UPDATE semestres_academicos SET estado = ? WHERE id_semestre = ?");
            $stmt->execute([$nuevo_estado, $id_semestre]);
            
            // Acciones específicas según el nuevo estado
            if ($nuevo_estado == 'finalizado') {
                // Desactivar todos los horarios del semestre
                $stmt = $db->prepare("UPDATE horarios SET activo = 0 WHERE id_semestre = ?");
                $stmt->execute([$id_semestre]);
                
                // Procesar calificaciones finales
                $stmt = $db->prepare("
                    UPDATE inscripciones 
                    SET estado = CASE 
                        WHEN nota_final >= 60 THEN 'aprobado'
                        WHEN nota_final < 60 AND nota_final IS NOT NULL THEN 'reprobado'
                        ELSE 'retirado'
                    END
                    WHERE id_horario IN (SELECT id_horario FROM horarios WHERE id_semestre = ?)
                    AND estado = 'inscrito'
                ");
                $stmt->execute([$id_semestre]);
            }
            
            // Registrar el cambio en el sistema (podrías tener una tabla de logs)
            $stmt = $db->prepare("
                INSERT INTO cambios_horario 
                (id_horario, tipo_cambio, valor_anterior, valor_nuevo, realizado_por, motivo) 
                VALUES (?, 'cambio_estado_semestre', ?, ?, ?, ?)
            ");
            $stmt->execute([
                0, // 0 para cambios de semestre
                "Semestre: {$semestre['codigo_semestre']} - Estado: {$estados[$estado_actual]}",
                "Semestre: {$semestre['codigo_semestre']} - Estado: {$estados[$nuevo_estado]}",
                $user['id'],
                "Cambio de estado de semestre: $motivo"
            ]);
            
            $db->commit();
            
            Session::setFlash("Semestre actualizado exitosamente a: {$estados[$nuevo_estado]}");
            Funciones::redireccionar("ver.php?id=$id_semestre");
            
        } catch (Exception $e) {
            $db->rollBack();
            Session::setFlash('Error al cambiar el estado: ' . $e->getMessage(), 'error');
        }
    } else {
        Session::setFlash(implode('<br>', $errores), 'error');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambiar Estado - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .warning-box {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border-left: 6px solid #ffc107;
            padding: 25px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .warning-box h3 {
            color: #856404;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-box {
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            border-left: 6px solid var(--primary);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .info-box h4 {
            color: var(--dark);
            margin-bottom: 15px;
        }
        
        .info-item {
            display: flex;
            margin-bottom: 10px;
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
        
        .estado-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .estado-option {
            border: 2px solid var(--gray-300);
            border-radius: var(--radius);
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .estado-option:hover {
            border-color: var(--primary);
            background: var(--gray-50);
        }
        
        .estado-option.selected {
            border-color: var(--primary);
            background: var(--primary-light);
        }
        
        .estado-option i {
            font-size: 32px;
            margin-bottom: 15px;
        }
        
        .estado-en_curso i { color: #4caf50; }
        .estado-finalizado i { color: #757575; }
        
        .estado-option span {
            display: block;
            font-weight: 600;
            color: var(--dark);
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .estado-option small {
            display: block;
            color: var(--gray-600);
            font-size: 13px;
        }
        
        .consequences-box {
            background: var(--gray-50);
            padding: 20px;
            border-radius: var(--radius);
            margin-top: 20px;
            border-left: 4px solid var(--warning);
        }
        
        .consequences-box h5 {
            color: var(--dark);
            margin-bottom: 10px;
        }
        
        .consequences-list {
            list-style: none;
            padding-left: 0;
        }
        
        .consequences-list li {
            padding: 8px 0;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        
        .consequences-list li:last-child {
            border-bottom: none;
        }
        
        .consequences-list i {
            color: var(--warning);
            margin-top: 3px;
        }
        
        .confirm-input {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-sync-alt"></i> Cambiar Estado del Semestre</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-calendar-alt"></i> Semestres</a>
                <a href="ver.php?id=<?php echo $id_semestre; ?>"><i class="fas fa-eye"></i> Ver Semestre</a>
                <a href="cambiar_estado.php?id=<?php echo $id_semestre; ?>" class="active"><i class="fas fa-sync-alt"></i> Cambiar Estado</a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <div class="warning-box">
            <h3><i class="fas fa-exclamation-triangle"></i> Cambio de Estado Crítico</h3>
            <p>Está a punto de cambiar el estado del semestre. Esta es una acción importante que afectará a:</p>
            <ul style="margin: 15px 0 15px 20px;">
                <li><strong><?php echo $horarios_activos; ?> horarios activos</strong></li>
                <li>Profesores asignados</li>
                <li>Estudiantes inscritos</li>
                <li>Calificaciones y registros académicos</li>
            </ul>
            <p><strong>Por favor, verifique cuidadosamente antes de proceder.</strong></p>
        </div>
        
        <div class="info-box">
            <h4><i class="fas fa-info-circle"></i> Información del Semestre</h4>
            
            <div class="info-item">
                <div class="info-label">Semestre:</div>
                <div class="info-value">
                    <strong><?php echo htmlspecialchars($semestre['codigo_semestre']); ?></strong> - 
                    <?php echo htmlspecialchars($semestre['nombre_semestre']); ?>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Período:</div>
                <div class="info-value">
                    <?php echo Funciones::formatearFecha($semestre['fecha_inicio']); ?> - 
                    <?php echo Funciones::formatearFecha($semestre['fecha_fin']); ?>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Estado Actual:</div>
                <div class="info-value">
                    <span class="estado estado-<?php echo $estado_actual; ?>" style="padding: 4px 12px;">
                        <?php echo $estados[$estado_actual]; ?>
                    </span>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Horarios Activos:</div>
                <div class="info-value">
                    <strong><?php echo $horarios_activos; ?> horarios</strong>
                </div>
            </div>
        </div>
        
        <?php if (empty($proximos_estados)): ?>
            <div class="alert alert-info">
                <h4><i class="fas fa-info-circle"></i> No hay cambios de estado disponibles</h4>
                <p>Este semestre está en estado <strong><?php echo $estados[$estado_actual]; ?></strong> y no hay transiciones disponibles.</p>
                <p>Una vez que un semestre está finalizado, no se puede cambiar su estado.</p>
            </div>
            
            <div class="form-actions">
                <a href="ver.php?id=<?php echo $id_semestre; ?>" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Volver al semestre
                </a>
            </div>
        
        <?php else: ?>
        
        <form method="POST" id="formCambioEstado">
            <!-- Selección de nuevo estado -->
            <div class="info-box">
                <h4><i class="fas fa-toggle-on"></i> Seleccionar Nuevo Estado</h4>
                
                <div class="estado-options">
                    <?php foreach ($proximos_estados as $estado_key => $estado_nombre): ?>
                    <label class="estado-option estado-<?php echo $estado_key; ?>" for="estado_<?php echo $estado_key; ?>">
                        <input type="radio" 
                               name="nuevo_estado" 
                               id="estado_<?php echo $estado_key; ?>" 
                               value="<?php echo $estado_key; ?>" 
                               style="display: none;"
                               required>
                        <i class="fas fa-<?php echo $estado_key == 'en_curso' ? 'play-circle' : 'flag-checkered'; ?>"></i>
                        <span><?php echo $estado_nombre; ?></span>
                        <small>
                            <?php if ($estado_key == 'en_curso'): ?>
                                Activar el semestre para comenzar clases
                            <?php elseif ($estado_key == 'finalizado'): ?>
                                Finalizar el semestre y procesar calificaciones
                            <?php endif; ?>
                        </small>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Consecuencias del cambio -->
            <div class="consequences-box">
                <h5><i class="fas fa-exclamation-circle"></i> Consecuencias de este cambio:</h5>
                <ul class="consequences-list" id="consequencesList">
                    <!-- Se llenará con JavaScript según el estado seleccionado -->
                </ul>
            </div>
            
            <!-- Motivo del cambio -->
            <div class="form-group">
                <label for="motivo">
                    <i class="fas fa-comment"></i> Motivo del cambio de estado *
                </label>
                <textarea name="motivo" 
                          id="motivo" 
                          class="form-control" 
                          rows="4" 
                          placeholder="Describa el motivo por el cual está cambiando el estado del semestre..."
                          required></textarea>
                <small class="text-muted">Este motivo quedará registrado en el historial del sistema.</small>
            </div>
            
            <!-- Confirmación -->
            <div class="form-group">
                <label for="confirmacion">
                    <i class="fas fa-keyboard"></i> Confirmación *
                </label>
                <input type="text" 
                       name="confirmacion" 
                       id="confirmacion" 
                       class="form-control confirm-input" 
                       placeholder="ESCRIBA 'CONFIRMAR' PARA PROCEDER"
                       required
                       style="color: var(--danger); font-size: 18px;">
                <small class="text-muted">Debe escribir exactamente <strong>CONFIRMAR</strong> en mayúsculas para proceder.</small>
            </div>
            
            <!-- Botones de acción -->
            <div class="form-actions">
                <a href="ver.php?id=<?php echo $id_semestre; ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancelar
                </a>
                <button type="submit" class="btn btn-primary" id="submitButton">
                    <i class="fas fa-sync-alt"></i> Cambiar Estado
                </button>
            </div>
        </form>
        
        <?php endif; ?>
    </div>
    
    <script>
        // Script para manejar la selección de estado
        document.querySelectorAll('.estado-option').forEach(option => {
            option.addEventListener('click', function() {
                // Desmarcar todas las opciones
                document.querySelectorAll('.estado-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                
                // Marcar la opción seleccionada
                this.classList.add('selected');
                
                // Marcar el radio button correspondiente
                const radio = this.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;
                    updateConsequences(radio.value);
                }
            });
        });
        
        // Función para actualizar las consecuencias según el estado seleccionado
        function updateConsequences(nuevoEstado) {
            const consequencesList = document.getElementById('consequencesList');
            const submitButton = document.getElementById('submitButton');
            
            let consequences = [];
            let buttonText = '';
            let buttonColor = '';
            
            if (nuevoEstado === 'en_curso') {
                consequences = [
                    'El semestre se activará y será visible para profesores y estudiantes',
                    'Los horarios asignados estarán disponibles para inscripción',
                    'Se notificará a todos los profesores asignados',
                    'No podrá haber otro semestre en curso simultáneamente',
                    'Las fechas del semestre no podrán modificarse'
                ];
                buttonText = 'Iniciar Semestre';
                buttonColor = 'success';
            } else if (nuevoEstado === 'finalizado') {
                consequences = [
                    'Todos los horarios del semestre serán desactivados',
                    'Las inscripciones activas serán procesadas',
                    'Los estudiantes recibirán sus calificaciones finales',
                    'No se podrán crear nuevos horarios en este semestre',
                    'El estado no podrá revertirse una vez finalizado'
                ];
                buttonText = 'Finalizar Semestre';
                buttonColor = 'warning';
            }
            
            // Actualizar lista de consecuencias
            consequencesList.innerHTML = '';
            consequences.forEach(consequence => {
                const li = document.createElement('li');
                li.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${consequence}`;
                consequencesList.appendChild(li);
            });
            
            // Actualizar botón de envío
            if (submitButton) {
                submitButton.innerHTML = `<i class="fas fa-sync-alt"></i> ${buttonText}`;
                submitButton.className = `btn btn-${buttonColor}`;
            }
        }
        
        // Validar el formulario antes de enviar
        document.getElementById('formCambioEstado')?.addEventListener('submit', function(e) {
            const nuevoEstado = document.querySelector('input[name="nuevo_estado"]:checked');
            const motivo = document.getElementById('motivo').value.trim();
            const confirmacion = document.getElementById('confirmacion').value;
            
            if (!nuevoEstado) {
                e.preventDefault();
                alert('Debe seleccionar un nuevo estado para el semestre');
                return false;
            }
            
            if (!motivo) {
                e.preventDefault();
                alert('Debe especificar un motivo para el cambio de estado');
                document.getElementById('motivo').focus();
                return false;
            }
            
            if (confirmacion !== 'CONFIRMAR') {
                e.preventDefault();
                alert('Debe escribir "CONFIRMAR" para confirmar el cambio');
                document.getElementById('confirmacion').focus();
                return false;
            }
            
            // Confirmación adicional para cambios críticos
            const estadoSeleccionado = nuevoEstado.value;
            let mensajeConfirmacion = '';
            
            if (estadoSeleccionado === 'en_curso') {
                mensajeConfirmacion = '¿Está seguro de iniciar este semestre?\n\nEsta acción activará todos los horarios y permitirá inscripciones.';
            } else if (estadoSeleccionado === 'finalizado') {
                mensajeConfirmacion = '¿ESTÁ ABSOLUTAMENTE SEGURO DE FINALIZAR EL SEMESTRE?\n\nEsta acción desactivará todos los horarios y procesará calificaciones finales.\n\nNO PODRÁ DESHACER ESTA ACCIÓN.';
            }
            
            if (mensajeConfirmacion && !confirm(mensajeConfirmacion)) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });
        
        // Enfocar el campo de confirmación
        document.getElementById('confirmacion')?.addEventListener('focus', function() {
            this.select();
        });
        
        // Inicializar consecuencias si hay una opción seleccionada por defecto
        const estadoSeleccionado = document.querySelector('input[name="nuevo_estado"]:checked');
        if (estadoSeleccionado) {
            updateConsequences(estadoSeleccionado.value);
        }
    </script>
</body>
</html>