<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

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
    
    // Verificar si el código ya existe
    $stmt = $db->prepare("SELECT id_semestre FROM semestres_academicos WHERE codigo_semestre = ?");
    $stmt->execute([$codigo_semestre]);
    if ($stmt->fetch()) {
        $errores[] = 'El código del semestre ya está registrado';
    }
    
    // Verificar superposición con otros semestres
    $stmt = $db->prepare("
        SELECT codigo_semestre 
        FROM semestres_academicos 
        WHERE (? BETWEEN fecha_inicio AND fecha_fin 
               OR ? BETWEEN fecha_inicio AND fecha_fin 
               OR fecha_inicio BETWEEN ? AND ?)
    ");
    $stmt->execute([$fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin]);
    if ($stmt->fetch()) {
        $errores[] = 'El período del semestre se superpone con otro semestre existente';
    }
    
    if (empty($errores)) {
        try {
            $stmt = $db->prepare("
                INSERT INTO semestres_academicos 
                (codigo_semestre, nombre_semestre, fecha_inicio, fecha_fin, estado)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $codigo_semestre,
                $nombre_semestre,
                $fecha_inicio,
                $fecha_fin,
                $estado
            ]);
            
            // Obtener el ID del semestre creado
            $id_semestre = $db->lastInsertId();
            
            Session::setFlash('Semestre creado exitosamente');
            Funciones::redireccionar("ver.php?id=$id_semestre");
            
        } catch (Exception $e) {
            Session::setFlash('Error al crear el semestre: ' . $e->getMessage(), 'error');
        }
    } else {
        Session::setFlash(implode('<br>', $errores), 'error');
    }
}

// Establecer fechas por defecto (próximo semestre)
$hoy = new DateTime();
$proximo_semestre = clone $hoy;
$proximo_semestre->modify('+1 month');

// Calcular fechas sugeridas para semestre
if ($hoy->format('m') >= 1 && $hoy->format('m') <= 6) {
    // Segundo semestre del año
    $sugerido_inicio = $hoy->format('Y') . '-07-01';
    $sugerido_fin = $hoy->format('Y') . '-12-20';
    $sugerido_codigo = $hoy->format('Y') . '-2';
    $sugerido_nombre = 'Segundo Semestre ' . $hoy->format('Y');
} else {
    // Primer semestre del próximo año
    $proximo_anio = $hoy->format('Y') + 1;
    $sugerido_inicio = $proximo_anio . '-01-15';
    $sugerido_fin = $proximo_anio . '-06-30';
    $sugerido_codigo = $proximo_anio . '-1';
    $sugerido_nombre = 'Primer Semestre ' . $proximo_anio;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Semestre - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .form-section {
            background: white;
            padding: 25px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            box-shadow: var(--shadow);
        }
        
        .form-section h3 {
            color: var(--dark);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .calendar-preview {
            background: var(--gray-50);
            padding: 20px;
            border-radius: var(--radius);
            margin-top: 15px;
        }
        
        .duration-info {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            font-size: 14px;
            color: var(--gray-700);
        }
        
        .duration-days {
            font-weight: 600;
            color: var(--primary);
        }
        
        .suggestion-box {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-left: 4px solid var(--primary);
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 20px;
        }
        
        .suggestion-box h4 {
            color: var(--dark);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .suggestion-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .calendar-visual {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 15px;
            padding: 15px;
            background: white;
            border-radius: var(--radius);
            border: 1px solid var(--gray-300);
        }
        
        .calendar-date {
            text-align: center;
        }
        
        .calendar-date .month {
            font-size: 14px;
            color: var(--gray-600);
            text-transform: uppercase;
        }
        
        .calendar-date .day {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .calendar-date .year {
            font-size: 14px;
            color: var(--gray-600);
        }
        
        .calendar-arrow {
            font-size: 24px;
            color: var(--gray-400);
        }
        
        .estado-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        
        .estado-option {
            border: 2px solid var(--gray-300);
            border-radius: var(--radius);
            padding: 15px;
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
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .estado-planificacion i { color: #ff9800; }
        .estado-en_curso i { color: #4caf50; }
        .estado-finalizado i { color: #9e9e9e; }
        
        .estado-option span {
            display: block;
            font-weight: 600;
            color: var(--dark);
        }
        
        .estado-option small {
            display: block;
            color: var(--gray-600);
            font-size: 12px;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-plus-circle"></i> Crear Nuevo Semestre</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-calendar-alt"></i> Semestres</a>
                <a href="crear.php" class="active"><i class="fas fa-plus-circle"></i> Nuevo Semestre</a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <!-- Sugerencia automática -->
        <div class="suggestion-box">
            <h4><i class="fas fa-lightbulb"></i> Sugerencia Automática</h4>
            <p>Basado en la fecha actual, sugerimos los siguientes datos para el próximo semestre:</p>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 10px;">
                <div>
                    <strong>Código:</strong> <?php echo $sugerido_codigo; ?>
                </div>
                <div>
                    <strong>Nombre:</strong> <?php echo $sugerido_nombre; ?>
                </div>
                <div>
                    <strong>Período:</strong> <?php echo date('d/m/Y', strtotime($sugerido_inicio)); ?> - <?php echo date('d/m/Y', strtotime($sugerido_fin)); ?>
                </div>
            </div>
            <div class="suggestion-actions">
                <button type="button" class="btn btn-primary btn-sm" onclick="useSuggestion()">
                    <i class="fas fa-magic"></i> Usar esta sugerencia
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="clearForm()">
                    <i class="fas fa-times"></i> Limpiar formulario
                </button>
            </div>
        </div>
        
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
                               value="<?php echo isset($_POST['codigo_semestre']) ? htmlspecialchars($_POST['codigo_semestre']) : $sugerido_codigo; ?>"
                               placeholder="Ej: 2024-1, 2024-2" 
                               required
                               pattern="\d{4}-[12]"
                               title="Formato: AÑO-NÚMERO (ej: 2024-1)">
                        <small class="text-muted">Formato: AÑO-NÚMERO (1 para primer semestre, 2 para segundo semestre)</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="nombre_semestre">Nombre del Semestre *</label>
                        <input type="text" 
                               name="nombre_semestre" 
                               id="nombre_semestre" 
                               class="form-control" 
                               value="<?php echo isset($_POST['nombre_semestre']) ? htmlspecialchars($_POST['nombre_semestre']) : $sugerido_nombre; ?>"
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
                        <div class="month" id="startMonth">Jul</div>
                        <div class="day" id="startDay">01</div>
                        <div class="year" id="startYear">2024</div>
                    </div>
                    
                    <div class="calendar-arrow">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                    
                    <div class="calendar-date" id="dateEnd">
                        <div class="month" id="endMonth">Dic</div>
                        <div class="day" id="endDay">20</div>
                        <div class="year" id="endYear">2024</div>
                    </div>
                </div>
                
                <div class="form-grid" style="margin-top: 20px;">
                    <div class="form-group">
                        <label for="fecha_inicio">Fecha de Inicio *</label>
                        <input type="date" 
                               name="fecha_inicio" 
                               id="fecha_inicio" 
                               class="form-control" 
                               value="<?php echo isset($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : $sugerido_inicio; ?>"
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="fecha_fin">Fecha de Fin *</label>
                        <input type="date" 
                               name="fecha_fin" 
                               id="fecha_fin" 
                               class="form-control" 
                               value="<?php echo isset($_POST['fecha_fin']) ? $_POST['fecha_fin'] : $sugerido_fin; ?>"
                               required>
                    </div>
                </div>
                
                <div class="calendar-preview">
                    <div id="durationInfo">
                        <!-- Información de duración se actualizará con JavaScript -->
                    </div>
                    <div class="duration-info">
                        <span>Duración estimada:</span>
                        <span class="duration-days" id="durationDays">0 días</span>
                    </div>
                </div>
            </div>
            
            <!-- Estado del Semestre -->
            <div class="form-section">
                <h3><i class="fas fa-toggle-on"></i> Estado del Semestre</h3>
                
                <div class="estado-options">
                    <label class="estado-option estado-planificacion" for="estado_planificacion">
                        <input type="radio" name="estado" id="estado_planificacion" value="planificación" checked style="display: none;">
                        <i class="fas fa-calendar-plus"></i>
                        <span>Planificación</span>
                        <small>En preparación, no activo</small>
                    </label>
                    
                    <label class="estado-option estado-en_curso" for="estado_en_curso">
                        <input type="radio" name="estado" id="estado_en_curso" value="en_curso" style="display: none;">
                        <i class="fas fa-play-circle"></i>
                        <span>En Curso</span>
                        <small>Activo y en ejecución</small>
                    </label>
                    
                    <label class="estado-option estado-finalizado" for="estado_finalizado">
                        <input type="radio" name="estado" id="estado_finalizado" value="finalizado" style="display: none;">
                        <i class="fas fa-flag-checkered"></i>
                        <span>Finalizado</span>
                        <small>Completado y cerrado</small>
                    </label>
                </div>
            </div>
            
            <!-- Botones de acción -->
            <div class="form-actions">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancelar
                </a>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Crear Semestre
                </button>
            </div>
        </form>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
    <script>
        // Configurar Flatpickr para fechas
        flatpickr("#fecha_inicio", {
            locale: "es",
            dateFormat: "Y-m-d",
            minDate: "today",
            onChange: function(selectedDates, dateStr, instance) {
                updateCalendarVisual();
                updateDuration();
                validateDates();
            }
        });
        
        flatpickr("#fecha_fin", {
            locale: "es",
            dateFormat: "Y-m-d",
            minDate: "today",
            onChange: function(selectedDates, dateStr, instance) {
                updateCalendarVisual();
                updateDuration();
                validateDates();
            }
        });
        
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
                } else {
                    document.getElementById('fecha_fin').style.borderColor = '';
                }
            }
        }
        
        // Función para usar la sugerencia automática
        function useSuggestion() {
            document.getElementById('codigo_semestre').value = '<?php echo $sugerido_codigo; ?>';
            document.getElementById('nombre_semestre').value = '<?php echo $sugerido_nombre; ?>';
            document.getElementById('fecha_inicio').value = '<?php echo $sugerido_inicio; ?>';
            document.getElementById('fecha_fin').value = '<?php echo $sugerido_fin; ?>';
            
            updateCalendarVisual();
            updateDuration();
        }
        
        // Función para limpiar el formulario
        function clearForm() {
            document.getElementById('formSemestre').reset();
            updateCalendarVisual();
            updateDuration();
        }
        
        // Función para validar el formulario antes de enviar
        document.getElementById('formSemestre').addEventListener('submit', function(e) {
            const codigo = document.getElementById('codigo_semestre').value.trim();
            const nombre = document.getElementById('nombre_semestre').value.trim();
            const fechaInicio = document.getElementById('fecha_inicio').value;
            const fechaFin = document.getElementById('fecha_fin').value;
            
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
            if (new Date(fechaInicio) >= new Date(fechaFin)) {
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
            
            return true;
        });
        
        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            updateCalendarVisual();
            updateDuration();
            
            // Marcar la opción de estado por defecto como seleccionada
            document.querySelector('.estado-option:first-child').classList.add('selected');
        });
    </script>
</body>
</html>