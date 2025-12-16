<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

// Obtener ID del aula si se pasa como parámetro
$aula_id = isset($_GET['aula']) ? intval($_GET['aula']) : 0;
$semestre_id = isset($_GET['semestre']) ? intval($_GET['semestre']) : 0;
$dia = isset($_GET['dia']) ? Funciones::sanitizar($_GET['dia']) : '';

// Obtener lista de aulas para el selector
$stmt = $db->query("SELECT id_aula, codigo_aula, nombre_aula FROM aulas WHERE disponible = 1 ORDER BY codigo_aula");
$aulas = $stmt->fetchAll();

// Obtener semestres activos
$stmt = $db->query("SELECT id_semestre, codigo_semestre, nombre_semestre FROM semestres_academicos WHERE estado IN ('planificación', 'en_curso') ORDER BY fecha_inicio DESC");
$semestres = $stmt->fetchAll();

// Días de la semana
$dias_semana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

// Si se seleccionó un aula, obtener su información
$aula_info = null;
if ($aula_id) {
    $stmt = $db->prepare("SELECT * FROM aulas WHERE id_aula = ?");
    $stmt->execute([$aula_id]);
    $aula_info = $stmt->fetch();
}

// Obtener horarios del aula
$horarios = [];
if ($aula_id && $semestre_id) {
    $where = "h.id_aula = ? AND h.id_semestre = ?";
    $params = [$aula_id, $semestre_id];
    
    if ($dia) {
        $where .= " AND h.dia_semana = ?";
        $params[] = $dia;
    }
    
    $stmt = $db->prepare("
        SELECT 
            h.*,
            c.nombre_curso,
            c.codigo_curso,
            CONCAT(p.nombres, ' ', p.apellidos) as profesor_nombre,
            s.codigo_semestre
        FROM horarios h
        JOIN cursos c ON h.id_curso = c.id_curso
        JOIN profesores p ON h.id_profesor = p.id_profesor
        JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
        WHERE $where AND h.activo = 1
        ORDER BY 
            FIELD(h.dia_semana, 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'),
            h.hora_inicio
    ");
    $stmt->execute($params);
    $horarios = $stmt->fetchAll();
}

// Organizar horarios por día y hora
$horarios_organizados = [];
foreach ($horarios as $horario) {
    $dia = $horario['dia_semana'];
    if (!isset($horarios_organizados[$dia])) {
        $horarios_organizados[$dia] = [];
    }
    $horarios_organizados[$dia][] = $horario;
}

// Generar slots de tiempo (7am a 9pm)
$slots_horarios = [];
for ($hora = 7; $hora <= 21; $hora++) {
    for ($minuto = 0; $minuto < 60; $minuto += 30) {
        $time = sprintf('%02d:%02d:00', $hora, $minuto);
        $slots_horarios[$time] = '';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disponibilidad de Aulas - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .filtros-box {
            background: white;
            padding: 25px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            box-shadow: var(--shadow);
        }
        
        .filtros-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .aula-info {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .aula-info h3 {
            margin: 0 0 10px 0;
        }
        
        .disponibilidad-grid {
            display: grid;
            grid-template-columns: 100px repeat(6, 1fr);
            gap: 1px;
            background: var(--gray-300);
            border-radius: var(--radius);
            overflow: hidden;
        }
        
        .grid-header {
            background: var(--dark);
            color: white;
            padding: 15px;
            text-align: center;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 14px;
        }
        
        .time-slot {
            background: var(--gray-100);
            padding: 10px;
            text-align: center;
            font-size: 13px;
            color: var(--gray-700);
            border-right: 1px solid var(--gray-300);
            border-bottom: 1px solid var(--gray-300);
        }
        
        .day-cell {
            background: white;
            padding: 5px;
            min-height: 60px;
            border-right: 1px solid var(--gray-300);
            border-bottom: 1px solid var(--gray-300);
            position: relative;
        }
        
        .horario-block {
            position: absolute;
            left: 2px;
            right: 2px;
            border-radius: 4px;
            padding: 8px;
            color: white;
            font-size: 12px;
            overflow: hidden;
            cursor: pointer;
            transition: transform 0.2s;
            z-index: 1;
        }
        
        .horario-block:hover {
            transform: scale(1.02);
            z-index: 2;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .horario-block.teoria { background: linear-gradient(135deg, #4caf50 0%, #2e7d32 100%); }
        .horario-block.practica { background: linear-gradient(135deg, #2196f3 0%, #1565c0 100%); }
        .horario-block.laboratorio { background: linear-gradient(135deg, #9c27b0 0%, #6a1b9a 100%); }
        .horario-block.taller { background: linear-gradient(135deg, #ff9800 0%, #ef6c00 100%); }
        
        .horario-title {
            font-weight: 600;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .horario-time {
            font-size: 11px;
            opacity: 0.9;
        }
        
        .legend {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
        }
        
        .empty-message {
            text-align: center;
            padding: 60px;
            color: var(--gray-600);
        }
        
        .empty-message i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .capacity-warning {
            background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
            border-left: 4px solid var(--danger);
            padding: 15px;
            border-radius: var(--radius);
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-calendar-check"></i> Disponibilidad de Aulas</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-school"></i> Aulas</a>
                <a href="disponibilidad.php" class="active"><i class="fas fa-calendar-check"></i> Disponibilidad</a>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filtros-box">
            <h3><i class="fas fa-filter"></i> Filtros de Disponibilidad</h3>
            <form method="GET" class="filtros-grid">
                <div class="form-group">
                    <label for="aula">Seleccionar Aula:</label>
                    <select name="aula" id="aula" class="form-control" required>
                        <option value="">-- Seleccione un aula --</option>
                        <?php foreach ($aulas as $aula): ?>
                            <option value="<?php echo $aula['id_aula']; ?>"
                                <?php echo $aula_id == $aula['id_aula'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($aula['codigo_aula']); ?> - <?php echo htmlspecialchars($aula['nombre_aula']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="semestre">Semestre Académico:</label>
                    <select name="semestre" id="semestre" class="form-control" required>
                        <option value="">-- Seleccione un semestre --</option>
                        <?php foreach ($semestres as $semestre): ?>
                            <option value="<?php echo $semestre['id_semestre']; ?>"
                                <?php echo $semestre_id == $semestre['id_semestre'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($semestre['codigo_semestre']); ?> - <?php echo htmlspecialchars($semestre['nombre_semestre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="dia">Día específico (opcional):</label>
                    <select name="dia" id="dia" class="form-control">
                        <option value="">Todos los días</option>
                        <?php foreach ($dias_semana as $dia_option): ?>
                            <option value="<?php echo $dia_option; ?>"
                                <?php echo $dia == $dia_option ? 'selected' : ''; ?>>
                                <?php echo $dia_option; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="grid-column: span 3; display: flex; gap: 10px; align-items: flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Ver Disponibilidad
                    </button>
                    <a href="disponibilidad.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Limpiar
                    </a>
                </div>
            </form>
        </div>
        
        <?php if ($aula_info): ?>
        <!-- Información del Aula -->
        <div class="aula-info">
            <h3><?php echo htmlspecialchars($aula_info['codigo_aula']); ?> - <?php echo htmlspecialchars($aula_info['nombre_aula']); ?></h3>
            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                <div>
                    <i class="fas fa-users"></i> Capacidad: <?php echo $aula_info['capacidad']; ?> estudiantes
                </div>
                <div>
                    <i class="fas fa-tag"></i> Tipo: <?php echo ucfirst($aula_info['tipo_aula']); ?>
                </div>
                <?php if ($aula_info['edificio']): ?>
                <div>
                    <i class="fas fa-building"></i> <?php echo htmlspecialchars($aula_info['edificio']); ?>
                    <?php if ($aula_info['piso']): ?> - Piso <?php echo $aula_info['piso']; endif; ?>
                </div>
                <?php endif; ?>
                <div>
                    <i class="fas fa-calendar"></i> Horarios asignados: <?php echo count($horarios); ?>
                </div>
            </div>
        </div>
        
        <?php if (empty($horarios) && $semestre_id): ?>
            <div class="empty-message">
                <i class="fas fa-calendar-times"></i>
                <h3>¡Aula completamente disponible!</h3>
                <p>No hay horarios asignados a esta aula para el semestre seleccionado.</p>
                <a href="../../horarios/crear.php?aula=<?php echo $aula_id; ?>&semestre=<?php echo $semestre_id; ?>" 
                   class="btn btn-success">
                    <i class="fas fa-plus"></i> Crear Horario
                </a>
            </div>
        <?php elseif ($semestre_id): ?>
        
        <!-- Grid de Disponibilidad -->
        <div class="disponibilidad-grid">
            <!-- Cabecera de horas -->
            <div class="grid-header">Hora</div>
            <?php foreach ($dias_semana as $dia_nombre): ?>
                <div class="grid-header">
                    <?php echo $dia_nombre; ?>
                    <?php 
                    $count_dia = isset($horarios_organizados[$dia_nombre]) ? count($horarios_organizados[$dia_nombre]) : 0;
                    if ($count_dia > 0): ?>
                        <div style="font-size: 12px; opacity: 0.8;">(<?php echo $count_dia; ?>)</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            
            <!-- Slots de tiempo -->
            <?php 
            $slot_index = 0;
            foreach ($slots_horarios as $time => $value): 
                $slot_index++;
                $hora_display = date('h:i A', strtotime($time));
            ?>
                <div class="time-slot">
                    <?php echo $hora_display; ?>
                </div>
                
                <?php foreach ($dias_semana as $dia_nombre): ?>
                    <div class="day-cell" id="slot-<?php echo $dia_nombre . '-' . $slot_index; ?>">
                        <?php 
                        // Buscar si hay un horario en este slot
                        if (isset($horarios_organizados[$dia_nombre])) {
                            foreach ($horarios_organizados[$dia_nombre] as $horario) {
                                $hora_inicio = strtotime($horario['hora_inicio']);
                                $hora_fin = strtotime($horario['hora_fin']);
                                $slot_time = strtotime($time);
                                $next_slot_time = strtotime($time) + 1800; // 30 minutos
                                
                                // Verificar si el horario ocupa este slot (al menos parcialmente)
                                if ($slot_time < $hora_fin && $next_slot_time > $hora_inicio) {
                                    // Calcular posición y altura
                                    $duracion_minutos = ($hora_fin - $hora_inicio) / 60;
                                    $inicio_relativo = ($hora_inicio - strtotime('07:00:00')) / 60;
                                    $altura_slots = ceil($duracion_minutos / 30);
                                    
                                    $top = ($inicio_relativo / 30) * 60;
                                    $height = $altura_slots * 60;
                                    
                                    // Solo mostrar si este slot es el inicio del bloque
                                    if ($slot_time == $hora_inicio) {
                                    ?>
                                    <div class="horario-block <?php echo $horario['tipo_clase']; ?>" 
                                         style="top: <?php echo $top; ?>px; height: <?php echo $height; ?>px;"
                                         title="Click para ver detalles"
                                         onclick="window.location.href='../../horarios/ver.php?id=<?php echo $horario['id_horario']; ?>'">
                                        <div class="horario-title">
                                            <?php echo htmlspecialchars($horario['codigo_curso']); ?>
                                        </div>
                                        <div class="horario-time">
                                            <?php echo date('h:i A', $hora_inicio); ?> - <?php echo date('h:i A', $hora_fin); ?>
                                        </div>
                                        <div style="font-size: 11px; margin-top: 3px;">
                                            <?php echo htmlspecialchars($horario['profesor_nombre']); ?>
                                        </div>
                                    </div>
                                    <?php
                                    }
                                }
                            }
                        }
                        ?>
                    </div>
                <?php endforeach; ?>
                
            <?php endforeach; ?>
        </div>
        
        <!-- Leyenda -->
        <div class="legend">
            <div class="legend-item">
                <div class="legend-color" style="background: linear-gradient(135deg, #4caf50 0%, #2e7d32 100%);"></div>
                <span>Clase Teórica</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: linear-gradient(135deg, #2196f3 0%, #1565c0 100%);"></div>
                <span>Clase Práctica</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: linear-gradient(135deg, #9c27b0 0%, #6a1b9a 100%);"></div>
                <span>Laboratorio</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: linear-gradient(135deg, #ff9800 0%, #ef6c00 100%);"></div>
                <span>Taller</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: var(--gray-100); border: 1px solid var(--gray-300);"></div>
                <span>Disponible</span>
            </div>
        </div>
        
        <!-- Lista de horarios -->
        <div style="margin-top: 30px;">
            <h3><i class="fas fa-list"></i> Lista de Horarios Asignados</h3>
            <div class="table-responsive" style="margin-top: 15px;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Día</th>
                            <th>Hora</th>
                            <th>Curso</th>
                            <th>Profesor</th>
                            <th>Tipo</th>
                            <th>Semestre</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($horarios as $horario): ?>
                        <tr>
                            <td><?php echo $horario['dia_semana']; ?></td>
                            <td>
                                <?php echo Funciones::formatearHora($horario['hora_inicio']); ?> - 
                                <?php echo Funciones::formatearHora($horario['hora_fin']); ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($horario['codigo_curso']); ?></strong><br>
                                <small><?php echo htmlspecialchars($horario['nombre_curso']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($horario['profesor_nombre']); ?></td>
                            <td>
                                <span class="badge badge-primary">
                                    <?php echo ucfirst($horario['tipo_clase']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($horario['codigo_semestre']); ?></td>
                            <td>
                                <a href="../../horarios/ver.php?id=<?php echo $horario['id_horario']; ?>" 
                                   class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php endif; ?>
        
        <?php elseif ($aula_id && !$semestre_id): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Por favor, seleccione un semestre académico para ver la disponibilidad.
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Auto-submit cuando cambian los select (excepto el día)
        document.getElementById('aula').addEventListener('change', function() {
            if (this.value && document.getElementById('semestre').value) {
                this.form.submit();
            }
        });
        
        document.getElementById('semestre').addEventListener('change', function() {
            if (this.value && document.getElementById('aula').value) {
                this.form.submit();
            }
        });
        
        // Resaltar el día actual
        const dias = {
            'Sunday': 'Domingo',
            'Monday': 'Lunes', 
            'Tuesday': 'Martes',
            'Wednesday': 'Miércoles',
            'Thursday': 'Jueves',
            'Friday': 'Viernes',
            'Saturday': 'Sábado'
        };
        
        const hoy = new Date().toLocaleDateString('es-ES', { weekday: 'long' });
        const diaActual = dias[new Date().toLocaleDateString('en-US', { weekday: 'long' })];
        
        // Resaltar columna del día actual
        document.querySelectorAll('.grid-header').forEach(header => {
            if (header.textContent.includes(diaActual)) {
                header.style.background = 'var(--success)';
                header.innerHTML = '<i class="fas fa-star"></i> ' + header.innerHTML;
            }
        });
        
        // Resaltar la hora actual
        const ahora = new Date();
        const horaActual = ahora.getHours();
        const minutoActual = ahora.getMinutes();
        const totalMinutos = horaActual * 60 + minutoActual;
        
        // Encontrar el slot correspondiente (asumiendo que empieza a las 7am)
        const minutosDesde7am = totalMinutos - (7 * 60);
        if (minutosDesde7am >= 0 && minutosDesde7am <= (21-7)*60) {
            const slotIndex = Math.floor(minutosDesde7am / 30) + 1;
            
            // Resaltar toda la fila de la hora actual
            for (let i = 0; i <= 6; i++) {
                const slotId = 'slot-' + ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'][i] + '-' + slotIndex;
                const cell = document.getElementById(slotId);
                if (cell) {
                    cell.style.background = 'linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%)';
                    cell.style.border = '2px solid #ffb300';
                }
            }
        }
    </script>
</body>
</html>