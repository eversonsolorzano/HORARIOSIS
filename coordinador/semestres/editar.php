<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

// Obtener ID del semestre
$id_semestre = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_semestre) {
    Funciones::redireccionar('index.php', 'ID de semestre no válido', 'error');
}

// Obtener datos actuales del semestre
$stmt = $db->prepare("SELECT * FROM semestres_academicos WHERE id_semestre = ?");
$stmt->execute([$id_semestre]);
$semestre = $stmt->fetch();

if (!$semestre) {
    Funciones::redireccionar('index.php', 'Semestre no encontrado', 'error');
}

// Verificar si hay horarios activos en este semestre
$stmt = $db->prepare("SELECT COUNT(*) FROM horarios WHERE id_semestre = ? AND activo = 1");
$stmt->execute([$id_semestre]);
$horarios_activos = $stmt->fetchColumn();

// Verificar si hay inscripciones activas en este semestre
$stmt = $db->prepare("
    SELECT COUNT(*) 
    FROM inscripciones i
    JOIN horarios h ON i.id_horario = h.id_horario
    WHERE h.id_semestre = ? AND i.estado = 'inscrito'
");
$stmt->execute([$id_semestre]);
$inscripciones_activas = $stmt->fetchColumn();

// Verificar si el semestre está finalizado
$semestre_finalizado = ($semestre['estado'] == 'finalizado');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $codigo_semestre = Funciones::sanitizar($_POST['codigo_semestre']);
    $nombre_semestre = Funciones::sanitizar($_POST['nombre_semestre']);
    $fecha_inicio = Funciones::sanitizar($_POST['fecha_inicio']);
    $fecha_fin = Funciones::sanitizar($_POST['fecha_fin']);
    $estado = Funciones::sanitizar($_POST['estado']);
    
    // Validaciones
    $errores = [];
    
    if (empty($codigo_semestre)) {
        $errores[] = 'El código del semestre es requerido';
    }
    
    if (empty($nombre_semestre)) {
        $errores[] = 'El nombre del semestre es requerido';
    }
    
    if (empty($fecha_inicio)) {
        $errores[] = 'La fecha de inicio es requerida';
    }
    
    if (empty($fecha_fin)) {
        $errores[] = 'La fecha de fin es requerida';
    }
    
    if ($fecha_inicio && $fecha_fin) {
        $inicio = new DateTime($fecha_inicio);
        $fin = new DateTime($fecha_fin);
        
        if ($inicio >= $fin) {
            $errores[] = 'La fecha de inicio debe ser anterior a la fecha de fin';
        }
        
        // Verificar que la duración sea razonable (entre 90 y 180 días)
        $diferencia = $inicio->diff($fin);
        $dias = $diferencia->days;
        
        if ($dias < 90) {
            $errores[] = 'La duración del semestre debe ser de al menos 90 días';
        }
        
        if ($dias > 180) {
            $errores[] = 'La duración del semestre no puede exceder los 180 días';
        }
    }
    
    // Verificar si el código ya existe en otro semestre
    if ($codigo_semestre != $semestre['codigo_semestre']) {
        $stmt = $db->prepare("SELECT id_semestre FROM semestres_academicos WHERE codigo_semestre = ? AND id_semestre != ?");
        $stmt->execute([$codigo_semestre, $id_semestre]);
        if ($stmt->fetch()) {
            $errores[] = 'El código del semestre ya está registrado en otro semestre';
        }
    }
    
    // Verificar superposición con otros semestres (excepto este mismo)
    $stmt = $db->prepare("
        SELECT codigo_semestre 
        FROM semestres_academicos 
        WHERE id_semestre != ?
        AND (? BETWEEN fecha_inicio AND fecha_fin 
             OR ? BETWEEN fecha_inicio AND fecha_fin 
             OR fecha_inicio BETWEEN ? AND ?)
    ");
    $stmt->execute([$id_semestre, $fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin]);
    if ($stmt->fetch()) {
        $errores[] = 'El período del semestre se superpone con otro semestre existente';
    }
    
    // Validaciones especiales para semestres con horarios activos
    if ($horarios_activos > 0) {
        // No permitir cambiar a estado de planificación si hay horarios activos
        if ($semestre['estado'] == 'en_curso' && $estado == 'planificación') {
            $errores[] = 'No puede cambiar a estado "planificación" un semestre que tiene horarios activos';
        }
        
        // No permitir cambiar fechas si hay horarios activos
        if ($fecha_inicio != $semestre['fecha_inicio'] || $fecha_fin != $semestre['fecha_fin']) {
            $errores[] = 'No puede cambiar las fechas de un semestre que tiene horarios activos';
        }
    }
    
    // Validar cambio de estado a finalizado
    if ($estado == 'finalizado' && $semestre['estado'] != 'finalizado') {
        $hoy = new DateTime();
        $fin_semestre = new DateTime($fecha_fin);
        
        if ($hoy < $fin_semestre) {
            $errores[] = 'No puede finalizar un semestre antes de su fecha de fin';
        }
    }
    
    if (empty($errores)) {
        try {
            // Iniciar transacción
            $db->beginTransaction();
            
            // Actualizar semestre
            $stmt = $db->prepare("
                UPDATE semestres_academicos SET
                    codigo_semestre = ?,
                    nombre_semestre = ?,
                    fecha_inicio = ?,
                    fecha_fin = ?,
                    estado = ?
                WHERE id_semestre = ?
            ");
            
            $stmt->execute([
                $codigo_semestre,
                $nombre_semestre,
                $fecha_inicio,
                $fecha_fin,
                $estado,
                $id_semestre
            ]);
            
            // Si se cambió a estado "finalizado", desactivar todos los horarios
            if ($estado == 'finalizado' && $semestre['estado'] != 'finalizado') {
                // Desactivar horarios
                $stmt = $db->prepare("UPDATE horarios SET activo = 0 WHERE id_semestre = ?");
                $stmt->execute([$id_semestre]);
                
                // Cambiar estado de inscripciones a "aprobado" o "reprobado" según sea necesario
                $stmt = $db->prepare("
                    UPDATE inscripciones 
                    SET estado = CASE 
                        WHEN nota_final >= 60 THEN 'aprobado'
                        ELSE 'reprobado'
                    END
                    WHERE id_horario IN (SELECT id_horario FROM horarios WHERE id_semestre = ?)
                    AND estado = 'inscrito'
                ");
                $stmt->execute([$id_semestre]);
            }
            
            $db->commit();
            
            Session::setFlash('Semestre actualizado exitosamente');
            Funciones::redireccionar("ver.php?id=$id_semestre");
            
        } catch (Exception $e) {
            $db->rollBack();
            Session::setFlash('Error al actualizar el semestre: ' . $e->getMessage(), 'error');
        }
    } else {
        Session::setFlash(implode('<br>', $errores), 'error');
    }
    
    // Actualizar datos del formulario
    $semestre = array_merge($semestre, $_POST);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Semestre - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .alert-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border-left: 6px solid #ffc107;
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .alert-warning h4 {
            color: #856404;
            margin-bottom: 10px;
        }
        
        .restrictions-box {
            background: var(--gray-50);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            border-left: 4px solid var(--warning);
        }
        
        .restrictions-box h4 {
            color: var(--dark);
            margin-bottom: 10px;
        }
        
        .restrictions-list {
            list-style: none;
            padding-left: 0;
        }
        
        .restrictions-list li {
            padding: 8px 0;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .restrictions-list li:last-child {
            border-bottom: none;
        }
        
        .restrictions-list i {
            color: var(--warning);
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-edit"></i> Editar Semestre: <?php echo htmlspecialchars($semestre['codigo_semestre']); ?></h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-calendar-alt"></i> Semestres</a>
                <a href="ver.php?id=<?php echo $id_semestre; ?>"><i class="fas fa-eye"></i> Ver Semestre</a>
                <a href="editar.php?id=<?php echo $id_semestre; ?>" class="active"><i class="fas fa-edit"></i> Editar</a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($horarios_activos > 0 || $inscripciones_activas > 0): ?>
        <div class="alert-warning">
            <h4><i class="fas fa-exclamation-triangle"></i> Advertencia: Semestre con actividad</h4>
            <p>Este semestre tiene:</p>
            <ul style="margin: 10px 0 10px 20px;">
                <?php if ($horarios_activos > 0): ?>
                    <li><strong><?php echo $horarios_activos; ?> horarios activos</strong></li>
                <?php endif; ?>
                <?php if ($inscripciones_activas > 0): ?>
                    <li><strong><?php echo $inscripciones_activas; ?> inscripciones activas</strong></li>
                <?php endif; ?>
            </ul>
            <p>Algunos cambios pueden estar restringidos para no afectar a estudiantes y profesores.</p>
        </div>
        <?php endif; ?>
        
        <?php if ($semestre_finalizado): ?>
        <div class="alert alert-info">
            <h4><i class="fas fa-info-circle"></i> Semestre Finalizado</h4>
            <p>Este semestre ha sido marcado como finalizado. Solo se permite editar información básica.</p>
        </div>
        <?php endif; ?>
        
        <!-- Restricciones -->
        <?php if ($horarios_activos > 0): ?>
        <div class="restrictions-box">
            <h4><i class="fas fa-ban"></i> Restricciones Aplicables</h4>
            <ul class="restrictions-list">
                <li><i class="fas fa-calendar-times"></i> No se pueden modificar las fechas del semestre</li>
                <li><i class="fas fa-sync-alt"></i> No se puede cambiar a estado "planificación"</li>
                <li><i class="fas fa-exclamation-circle"></i> Cualquier cambio puede afectar a estudiantes y profesores</li>
            </ul>
        </div>
        <?php endif; ?>
        
        <form method="POST" id="formSemestre">
            <!-- Información Básica -->
            <div class="form-section">
                <h3><i class="fas fa-info-circle"></i> Información Básica</h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="codigo_semestre">Código del Semestre *</label>
                        <input type="text" 
                               name="codigo_semestre" 
                               id="codigo_semestre" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($semestre['codigo_semestre']); ?>"
                               placeholder="Ej: 2024-1, 2024-2" 
                               required
                               pattern="\d{4}-[12]"
                               title="Formato: AÑO-NÚMERO (ej: 2024-1)"
                               <?php echo ($horarios_activos > 0) ? 'readonly' : ''; ?>>
                        <small class="text-muted">Formato: AÑO-NÚMERO (1 para primer semestre, 2 para segundo semestre)</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="nombre_semestre">Nombre del Semestre *</label>
                        <input type="text" 
                               name="nombre_semestre" 
                               id="nombre_semestre" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($semestre['nombre_semestre']); ?>"
                               placeholder="Ej: Primer Semestre 2024, Segundo Semestre 2024" 
                               required>
                        <small class="text-muted">Nombre descriptivo del semestre académico</small>
                    </div>
                </div>
            </div>
            
            <!-- Período del Semestre -->
            <div class="form-section">
                <h3><i class="fas fa-calendar-day"></i> Período del Semestre</h3>
                
                <div class="calendar-visual" id="calendarVisual">
                    <div class="calendar-date" id="dateStart">
                        <div class="month" id="startMonth"><?php echo date('M', strtotime($semestre['fecha_inicio'])); ?></div>
                        <div class="day" id="startDay"><?php echo date('d', strtotime($semestre['fecha_inicio'])); ?></div>
                        <div class="year" id="startYear"><?php echo date('Y', strtotime($semestre['fecha_inicio'])); ?></div>
                    </div>
                    
                    <div class="calendar-arrow">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                    
                    <div class="calendar-date" id="dateEnd">
                        <div class="month" id="endMonth"><?php echo date('M', strtotime($semestre['fecha_fin'])); ?></div>
                        <div class="day" id="endDay"><?php echo date('d', strtotime($semestre['fecha_fin'])); ?></div>
                        <div class="year" id="endYear"><?php echo date('Y', strtotime($semestre['fecha_fin'])); ?></div>
                    </div>
                </div>
                
                <div class="form-grid" style="margin-top: 20px;">
                    <div class="form-group">
                        <label for="fecha_inicio">Fecha de Inicio *</label>
                        <input type="date" 
                               name="fecha_inicio" 
                               id="fecha_inicio" 
                               class="form-control" 
                               value="<?php echo $semestre['fecha_inicio']; ?>"
                               required
                               <?php echo ($horarios_activos > 0 || $semestre_finalizado) ? 'readonly' : ''; ?>>
                    </div>
                    
                    <div class="form-group">
                        <label for="fecha_fin">Fecha de Fin *</label>
                        <input type="date" 
                               name="fecha_fin" 
                               id="fecha_fin" 
                               class="form-control" 
                               value="<?php echo $semestre['fecha_fin']; ?>"
                               required
                               <?php echo ($horarios_activos > 0 || $semestre_finalizado) ? 'readonly' : ''; ?>>
                    </div>
                </div>
                
                <div class="calendar-preview">
                    <div id="durationInfo">
                        <?php 
                        $inicio = new DateTime($semestre['fecha_inicio']);
                        $fin = new DateTime($semestre['fecha_fin']);
                        $diferencia = $inicio->diff($fin);
                        $dias = $diferencia->days;
                        $semanas = floor($dias / 7);
                        $dias_restantes = $dias % 7;
                        $meses = floor($dias / 30);
                        ?>
                        <div><strong>Detalle de duración:</strong></div>
                        <div>• <?php echo $dias; ?> días calendario</div>
                        <div>• <?php echo $semanas; ?> semanas <?php echo $dias_restantes > 0 ? "y $dias_restantes días" : ''; ?></div>
                        <div>• Aproximadamente <?php echo $meses; ?> <?php echo $meses === 1 ? 'mes' : 'meses'; ?></div>
                    </div>
                    <div class="duration-info">
                        <span>Duración estimada:</span>
                        <span class="duration-days" id="durationDays"><?php echo $dias; ?> días</span>
                    </div>
                </div>
            </div>
            
            <!-- Estado del Semestre -->
            <div class="form-section">
                <h3><i class="fas fa-toggle-on"></i> Estado del Semestre</h3>
                
                <div class="estado-options">
                    <label class="estado-option estado-planificacion <?php echo $semestre['estado'] == 'planificación' ? 'selected' : ''; ?>" 
                           for="estado_planificacion"
                           <?php echo ($horarios_activos > 0 && $semestre['estado'] != 'planificación') ? 'style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>
                        <input type="radio" 
                               name="estado" 
                               id="estado_planificacion" 
                               value="planificación" 
                               <?php echo $semestre['estado'] == 'planificación' ? 'checked' : ''; ?>
                               <?php echo ($horarios_activos > 0 && $semestre['estado'] != 'planificación') ? 'disabled' : ''; ?>
                               style="display: none;">
                        <i class="fas fa-calendar-plus"></i>
                        <span>Planificación</span>
                        <small>En preparación, no activo</small>
                    </label>
                    
                    <label class="estado-option estado-en_curso <?php echo $semestre['estado'] == 'en_curso' ? 'selected' : ''; ?>" 
                           for="estado_en_curso">
                        <input type="radio" 
                               name="estado" 
                               id="estado_en_curso" 
                               value="en_curso" 
                               <?php echo $semestre['estado'] == 'en_curso' ? 'checked' : ''; ?>
                               style="display: none;">
                        <i class="fas fa-play-circle"></i>
                        <span>En Curso</span>
                        <small>Activo y en ejecución</small>
                    </label>
                    
                    <label class="estado-option estado-finalizado <?php echo $semestre['estado'] == 'finalizado' ? 'selected' : ''; ?>" 
                           for="estado_finalizado">
                        <input type="radio" 
                               name="estado" 
                               id="estado_finalizado" 
                               value="finalizado" 
                               <?php echo $semestre['estado'] == 'finalizado' ? 'checked' : ''; ?>
                               <?php echo $semestre_finalizado ? 'disabled' : ''; ?>
                               style="display: none;">
                        <i class="fas fa-flag-checkered"></i>
                        <span>Finalizado</span>
                        <small>Completado y cerrado</small>
                    </label>
                </div>
            </div>
            
            <!-- Botones de acción -->
            <div class="form-actions">
                <a href="ver.php?id=<?php echo $id_semestre; ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancelar
                </a>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Guardar Cambios
                </button>
            </div>
        </form>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
    <script>
        // Script para manejar la selección de estado
        document.querySelectorAll('.estado-option:not([style*="cursor: not-allowed"])').forEach(option => {
            option.addEventListener('click', function() {
                if (!this.querySelector('input[type="radio"]')?.disabled) {
                    document.querySelectorAll('.estado-option').forEach(opt => {
                        opt.classList.remove('selected');
                    });
                    this.classList.add('selected');
                    const radio = this.querySelector('input[type="radio"]');
                    if (radio) radio.checked = true;
                }
            });
        });
        
        // Función para actualizar la visualización del calendario
        function updateCalendarVisual() {
            const fechaInicio = document.getElementById('fecha_inicio').value;
            const fechaFin = document.getElementById('fecha_fin').value;
            
            if (fechaInicio) {
                const inicio = new Date(fechaInicio);
                document.getElementById('startMonth').textContent = inicio.toLocaleDateString('es-ES', { month: 'short' });
                document.getElementById('startDay').textContent = inicio.getDate();
                document.getElementById('startYear').textContent = inicio.getFullYear();
            }
            
            if (fechaFin) {
                const fin = new Date(fechaFin);
                document.getElementById('endMonth').textContent = fin.toLocaleDateString('es-ES', { month: 'short' });
                document.getElementById('endDay').textContent = fin.getDate();
                document.getElementById('endYear').textContent = fin.getFullYear();
            }
        }
        
        // Función para calcular y mostrar la duración
        function updateDuration() {
            const fechaInicio = document.getElementById('fecha_inicio').value;
            const fechaFin = document.getElementById('fecha_fin').value;
            
            if (fechaInicio && fechaFin) {
                const inicio = new Date(fechaInicio);
                const fin = new Date(fechaFin);
                
                // Calcular diferencia en días
                const diferencia = fin.getTime() - inicio.getTime();
                const dias = Math.ceil(diferencia / (1000 * 3600 * 24));
                
                // Calcular semanas
                const semanas = Math.floor(dias / 7);
                const diasRestantes = dias % 7;
                
                // Calcular meses aproximados
                const meses = Math.floor(dias / 30);
                
                // Actualizar información de duración
                const durationInfo = document.getElementById('durationInfo');
                durationInfo.innerHTML = `
                    <div><strong>Detalle de duración:</strong></div>
                    <div>• ${dias} días calendario</div>
                    <div>• ${semanas} semanas ${diasRestantes > 0 ? `y ${diasRestantes} días` : ''}</div>
                    <div>• Aproximadamente ${meses} ${meses === 1 ? 'mes' : 'meses'}</div>
                `;
                
                // Actualizar días
                document.getElementById('durationDays').textContent = dias + ' días';
                
                // Cambiar color según duración
                const durationElement = document.getElementById('durationDays');
                if (dias < 90) {
                    durationElement.style.color = 'var(--danger)';
                } else if (dias > 180) {
                    durationElement.style.color = 'var(--warning)';
                } else {
                    durationElement.style.color = 'var(--success)';
                }
            }
        }
        
        // Función para validar fechas
        function validateDates() {
            const fechaInicio = document.getElementById('fecha_inicio').value;
            const fechaFin = document.getElementById('fecha_fin').value;
            
            if (fechaInicio && fechaFin) {
                const inicio = new Date(fechaInicio);
                const fin = new Date(fechaFin);
                
                if (inicio >= fin) {
                    document.getElementById('fecha_fin').style.borderColor = 'var(--danger)';
                    return false;
                } else {
                    document.getElementById('fecha_fin').style.borderColor = '';
                    return true;
                }
            }
            return false;
        }
        
        // Función para validar el formulario antes de enviar
        document.getElementById('formSemestre').addEventListener('submit', function(e) {
            const codigo = document.getElementById('codigo_semestre').value.trim();
            const nombre = document.getElementById('nombre_semestre').value.trim();
            const fechaInicio = document.getElementById('fecha_inicio').value;
            const fechaFin = document.getElementById('fecha_fin').value;
            const estadoSeleccionado = document.querySelector('input[name="estado"]:checked');
            
            // Validar código (formato: 2024-1 o 2024-2)
            const codigoRegex = /^\d{4}-[12]$/;
            if (!codigoRegex.test(codigo)) {
                e.preventDefault();
                alert('El código debe tener el formato: AÑO-NÚMERO (ej: 2024-1, 2024-2)');
                document.getElementById('codigo_semestre').focus();
                return false;
            }
            
            if (!nombre) {
                e.preventDefault();
                alert('El nombre del semestre es requerido');
                document.getElementById('nombre_semestre').focus();
                return false;
            }
            
            if (!fechaInicio) {
                e.preventDefault();
                alert('La fecha de inicio es requerida');
                document.getElementById('fecha_inicio').focus();
                return false;
            }
            
            if (!fechaFin) {
                e.preventDefault();
                alert('La fecha de fin es requerida');
                document.getElementById('fecha_fin').focus();
                return false;
            }
            
            // Validar que la fecha de inicio sea anterior a la de fin
            if (!validateDates()) {
                e.preventDefault();
                alert('La fecha de inicio debe ser anterior a la fecha de fin');
                document.getElementById('fecha_inicio').focus();
                return false;
            }
            
            // Validar duración
            const inicio = new Date(fechaInicio);
            const fin = new Date(fechaFin);
            const dias = Math.ceil((fin - inicio) / (1000 * 3600 * 24));
            
            if (dias < 90) {
                e.preventDefault();
                alert('La duración del semestre debe ser de al menos 90 días');
                return false;
            }
            
            if (dias > 180) {
                e.preventDefault();
                alert('La duración del semestre no puede exceder los 180 días');
                return false;
            }
            
            // Validar estado seleccionado
            if (!estadoSeleccionado) {
                e.preventDefault();
                alert('Debe seleccionar un estado para el semestre');
                return false;
            }
            
            // Validar cambio a estado finalizado
            const estadoActual = '<?php echo $semestre["estado"]; ?>';
            const nuevoEstado = estadoSeleccionado.value;
            
            if (nuevoEstado === 'finalizado' && estadoActual !== 'finalizado') {
                const hoy = new Date();
                const finSemestre = new Date(fechaFin);
                
                if (hoy < finSemestre) {
                    e.preventDefault();
                    alert('No puede finalizar un semestre antes de su fecha de fin');
                    return false;
                }
                
                if (!confirm('¿Está seguro de finalizar este semestre?\n\nEsta acción desactivará todos los horarios y procesará las calificaciones.')) {
                    e.preventDefault();
                    return false;
                }
            }
            
            return true;
        });
        
        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            updateCalendarVisual();
            updateDuration();
        });
    </script>
</body>
</html>