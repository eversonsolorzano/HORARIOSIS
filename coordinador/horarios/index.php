<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

// Obtener horarios con búsqueda
$search = isset($_GET['search']) ? Funciones::sanitizar($_GET['search']) : '';
$programa_filter = isset($_GET['programa']) ? intval($_GET['programa']) : 0;
$dia_filter = isset($_GET['dia']) ? Funciones::sanitizar($_GET['dia']) : '';
$profesor_filter = isset($_GET['profesor']) ? intval($_GET['profesor']) : 0;
$semestre_filter = isset($_GET['semestre']) ? intval($_GET['semestre']) : 0;
$export = isset($_GET['export']) ? Funciones::sanitizar($_GET['export']) : '';

// Obtener programas para filtro
$programas = $db->query("SELECT * FROM programas_estudio WHERE activo = 1")->fetchAll();

// Obtener profesores para filtro
$profesores = $db->query("SELECT * FROM profesores WHERE activo = 1 ORDER BY apellidos, nombres")->fetchAll();

// Obtener semestres para filtro
$semestres = $db->query("SELECT * FROM semestres_academicos WHERE estado IN ('planificación', 'en_curso') ORDER BY fecha_inicio DESC")->fetchAll();

// Días de la semana
$dias_semana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'];

// Construir SQL para horarios
$sql = "SELECT h.*, c.nombre_curso, c.codigo_curso, 
               CONCAT(p.nombres, ' ', p.apellidos) as profesor_nombre,
               a.codigo_aula, a.nombre_aula, a.capacidad,
               pr.nombre_programa, s.codigo_semestre
        FROM horarios h
        JOIN cursos c ON h.id_curso = c.id_curso
        JOIN profesores p ON h.id_profesor = p.id_profesor
        JOIN aulas a ON h.id_aula = a.id_aula
        JOIN programas_estudio pr ON c.id_programa = pr.id_programa
        JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
        WHERE h.activo = 1";

$params = [];

if ($search) {
    $sql .= " AND (c.nombre_curso LIKE ? OR c.codigo_curso LIKE ? OR a.codigo_aula LIKE ? OR p.nombres LIKE ? OR p.apellidos LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($programa_filter) {
    $sql .= " AND c.id_programa = ?";
    $params[] = $programa_filter;
}

if ($dia_filter) {
    $sql .= " AND h.dia_semana = ?";
    $params[] = $dia_filter;
}

if ($profesor_filter) {
    $sql .= " AND h.id_profesor = ?";
    $params[] = $profesor_filter;
}

if ($semestre_filter) {
    $sql .= " AND h.id_semestre = ?";
    $params[] = $semestre_filter;
}

$sql .= " ORDER BY 
        CASE h.dia_semana 
            WHEN 'Lunes' THEN 1
            WHEN 'Martes' THEN 2
            WHEN 'Miércoles' THEN 3
            WHEN 'Jueves' THEN 4
            WHEN 'Viernes' THEN 5
        END,
        h.hora_inicio";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $horarios = $stmt->fetchAll();
} catch (PDOException $e) {
    Session::setFlash('Error al cargar los horarios: ' . $e->getMessage(), 'error');
    $horarios = [];
}

// Agrupar horarios por día
$horarios_por_dia = [];
foreach ($horarios as $horario) {
    $dia = $horario['dia_semana'];
    if (!isset($horarios_por_dia[$dia])) {
        $horarios_por_dia[$dia] = [];
    }
    $horarios_por_dia[$dia][] = $horario;
}

// **CORRECCIÓN COMPLETA: CONFIGURACIÓN DE LA TABLA (8:00 AM a 2:00 PM)**
$hora_inicio_tabla = 8;  // 8:00 AM
$hora_fin_tabla = 14;    // 2:00 PM
$altura_hora_px = 70;    // Altura por hora en píxeles

// Generar horas para la tabla
$horas_tabla = [];
for ($hora = $hora_inicio_tabla; $hora < $hora_fin_tabla; $hora++) {
    $hora_formato = sprintf('%02d:00', $hora);
    $hora_siguiente = sprintf('%02d:00', $hora + 1);
    $horas_tabla[] = [
        'inicio' => $hora_formato,
        'fin' => $hora_siguiente,
        'display' => date('g:i A', strtotime($hora_formato)) . ' - ' . date('g:i A', strtotime($hora_siguiente))
    ];
}

// **CORRECCIÓN: Sistema mejorado para mostrar bloques**
// Primero, procesar todos los horarios y calcular su posición
$horarios_procesados = [];
foreach ($horarios as $horario) {
    $dia = $horario['dia_semana'];
    $hora_inicio = strtotime($horario['hora_inicio']);
    $hora_fin = strtotime($horario['hora_fin']);
    
    // Calcular en qué slot de la tabla empieza
    $hora_inicio_numero = (int)date('H', $hora_inicio);
    $hora_fin_numero = (int)date('H', $hora_fin);
    
    // Solo procesar si está dentro del horario de la tabla (8:00-14:00)
    if ($hora_inicio_numero >= $hora_inicio_tabla && $hora_inicio_numero < $hora_fin_tabla) {
        // Calcular duración en horas completas
        $duracion_segundos = $hora_fin - $hora_inicio;
        $duracion_horas = $duracion_segundos / 3600;
        
        // Redondear a la hora más cercana para posicionamiento en la tabla
        $hora_tabla_inicio = $hora_inicio_numero;
        $slot_inicio = $hora_tabla_inicio - $hora_inicio_tabla; // Índice en la tabla
        
        $horario['slot_inicio'] = $slot_inicio;
        $horario['duracion_horas'] = $duracion_horas;
        $horario['hora_inicio_real'] = date('H:i', $hora_inicio);
        $horario['hora_fin_real'] = date('H:i', $hora_fin);
        
        $horarios_procesados[] = $horario;
    }
}

// **CORRECCIÓN: Crear estructura para la tabla**
$tabla_datos = [];
foreach ($dias_semana as $dia) {
    $tabla_datos[$dia] = [];
    for ($i = 0; $i < count($horas_tabla); $i++) {
        $tabla_datos[$dia][$i] = null; // Inicializar como vacío
    }
}

// **CORRECCIÓN: Asignar horarios a sus posiciones correctas**
foreach ($horarios_procesados as $horario) {
    $dia = $horario['dia_semana'];
    $slot_inicio = $horario['slot_inicio'];
    
    // Solo asignar si el slot está dentro del rango
    if ($slot_inicio >= 0 && $slot_inicio < count($horas_tabla)) {
        $tabla_datos[$dia][$slot_inicio] = $horario;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horarios - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ESTILOS PARA LA TABLA SEMANAL */
        .tabla-semanal-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-top: 20px;
            border: 1px solid #e0e0e0;
        }
        
        .tabla-semanal-header {
            display: grid;
            grid-template-columns: 150px repeat(5, 1fr);
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            color: white;
            font-weight: bold;
        }
        
        .header-hora {
            padding: 15px;
            text-align: center;
            border-right: 2px solid #1a5276;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .header-dia {
            padding: 15px;
            text-align: center;
            border-right: 1px solid rgba(255,255,255,0.2);
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .header-dia:last-child {
            border-right: none;
        }
        
        .header-dia.today {
            background: rgba(52, 152, 219, 0.3);
            border-bottom: 3px solid #f1c40f;
        }
        
        .tabla-semanal-body {
            display: grid;
            grid-template-columns: 150px repeat(5, 1fr);
        }
        
        .hora-slot {
            padding: 15px 10px;
            text-align: center;
            border-bottom: 1px solid #e0e0e0;
            border-right: 1px solid #e0e0e0;
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 70px;
        }
        
        .celda-dia {
            padding: 5px;
            border-bottom: 1px solid #e0e0e0;
            border-right: 1px solid #e0e0e0;
            min-height: 70px;
            position: relative;
            background: white;
            transition: all 0.3s;
        }
        
        .celda-dia:last-child {
            border-right: none;
        }
        
        /* **CORRECCIÓN: BLOQUE QUE OCUPA MÚLTIPLES HORAS** */
        .bloque-curso {
            position: absolute;
            left: 5px;
            right: 5px;
            border-radius: 8px;
            padding: 10px;
            color: white;
            font-size: 12px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s;
            z-index: 2;
            border-left: 5px solid;
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            opacity: 0.95;
        }
        
        /* **CORRECCIÓN: ESTILOS PARA BLOQUES DE DIFERENTES DURACIONES** */
        .bloque-1hora { height: calc(70px - 10px); }
        .bloque-2horas { height: calc(140px - 10px); }
        .bloque-3horas { height: calc(210px - 10px); }
        .bloque-4horas { height: calc(280px - 10px); }
        .bloque-5horas { height: calc(350px - 10px); }
        .bloque-6horas { height: calc(420px - 10px); }
        
        .bloque-curso:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            opacity: 1;
            z-index: 10;
        }
        
        /* COLORES POR PROGRAMA */
        .bloque-topografia { 
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%); 
            border-left-color: #219653;
        }
        .bloque-arquitectura { 
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); 
            border-left-color: #1c6ea4;
        }
        .bloque-enfermeria { 
            background: linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%); 
            border-left-color: #7d3c98;
        }
        .bloque-pediatria { 
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); 
            border-left-color: #a93226;
        }
        .bloque-fundamentos { 
            background: linear-gradient(135deg, #f39c12 0%, #d35400 100%); 
            border-left-color: #ba4a00;
        }
        .bloque-tecnologia { 
            background: linear-gradient(135deg, #1abc9c 0%, #16a085 100%); 
            border-left-color: #138d75;
        }
        .bloque-otros { 
            background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%); 
            border-left-color: #5d6d7e;
        }
        
        /* CONTENIDO DEL BLOQUE */
        .curso-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        
        .curso-horario {
            font-size: 11px;
            font-weight: 600;
            background: rgba(0,0,0,0.2);
            padding: 3px 8px;
            border-radius: 4px;
            white-space: nowrap;
        }
        
        .curso-tipo {
            font-size: 10px;
            background: rgba(255,255,255,0.3);
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .curso-nombre {
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 5px;
            line-height: 1.2;
            text-shadow: 1px 1px 1px rgba(0,0,0,0.2);
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        
        .curso-detalles {
            font-size: 11px;
            opacity: 0.9;
            line-height: 1.3;
        }
        
        .curso-profesor {
            display: block;
            font-weight: 600;
            margin-bottom: 3px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .curso-aula {
            display: block;
            font-weight: 500;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        /* CELDA VACÍA */
        .celda-vacia {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #95a5a6;
            font-size: 12px;
            font-style: italic;
        }
        
        /* LEYENDA */
        .leyenda-horarios {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 25px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
        }
        
        .leyenda-titulo {
            font-weight: 700;
            color: #2c3e50;
            font-size: 16px;
            margin-bottom: 12px;
            width: 100%;
            border-bottom: 2px solid #3498db;
            padding-bottom: 8px;
        }
        
        .leyenda-programas {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            min-width: 250px;
        }
        
        .leyenda-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 12px;
            background: white;
            border-radius: 6px;
            border: 1px solid #e0e0e0;
        }
        
        .leyenda-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .leyenda-texto {
            font-size: 14px;
            color: #34495e;
            font-weight: 500;
        }
        
        /* ESTADÍSTICAS */
        .estadisticas-horarios {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .tarjeta-estadistica {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .tarjeta-estadistica .numero {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .tarjeta-estadistica .texto {
            font-size: 14px;
            color: #7f8c8d;
        }
        
        /* BOTONES Y CONTROLES */
        .controles-horarios {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .boton-accion {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .boton-accion:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }
        
        .boton-exportar {
            background: #27ae60;
        }
        
        .boton-exportar:hover {
            background: #219653;
        }
        
        /* RESPONSIVE */
        @media (max-width: 1200px) {
            .tabla-semanal-container {
                overflow-x: auto;
            }
            
            .tabla-semanal-header,
            .tabla-semanal-body {
                min-width: 900px;
            }
        }
        
        /* ANIMACIONES */
        @keyframes aparecer {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .bloque-curso {
            animation: aparecer 0.3s ease-out;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-calendar-alt"></i> Horario Semanal</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Inicio</a>
                <a href="../cursos/"><i class="fas fa-book"></i> Cursos</a>
                <a href="index.php" class="active"><i class="fas fa-calendar-alt"></i> Horarios</a>
                <a href="../aulas/"><i class="fas fa-school"></i> Aulas</a>
                <a href="../profesores/"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
            </div>
        </div>
        
        <?php if (isset($_SESSION['flash'])): 
            $flash = $_SESSION['flash'];
            unset($_SESSION['flash']);
        ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <div class="controles-horarios">
            <div>
                <h2 style="margin: 0; color: #2c3e50;">Horario: <?php echo date('d/m/Y'); ?></h2>
                <p style="margin: 5px 0 0 0; color: #7f8c8d; font-size: 14px;">
                    <i class="fas fa-clock"></i> Horario de 8:00 AM a 2:00 PM
                </p>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="crear.php" class="boton-accion">
                    <i class="fas fa-plus"></i> Nuevo Horario
                </a>
                <a href="?export=excel" class="boton-accion boton-exportar">
                    <i class="fas fa-file-excel"></i> Exportar Excel
                </a>
            </div>
        </div>
        
        <!-- FILTROS -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-header">
                <h3 style="margin: 0; color: #2c3e50;"><i class="fas fa-filter"></i> Filtros</h3>
            </div>
            <div class="card-body">
                <form method="GET" action="" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #2c3e50;">Buscar</label>
                        <input type="text" name="search" class="form-control" 
                               placeholder="Curso, profesor o aula..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #2c3e50;">Programa</label>
                        <select name="programa" class="form-control">
                            <option value="">Todos los programas</option>
                            <?php foreach ($programas as $programa): ?>
                                <option value="<?php echo $programa['id_programa']; ?>"
                                    <?php echo $programa_filter == $programa['id_programa'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($programa['nombre_programa']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #2c3e50;">Día</label>
                        <select name="dia" class="form-control">
                            <option value="">Todos los días</option>
                            <?php foreach ($dias_semana as $dia): ?>
                                <option value="<?php echo $dia; ?>"
                                    <?php echo $dia_filter == $dia ? 'selected' : ''; ?>>
                                    <?php echo $dia; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #2c3e50;">Profesor</label>
                        <select name="profesor" class="form-control">
                            <option value="">Todos los profesores</option>
                            <?php foreach ($profesores as $profesor): ?>
                                <option value="<?php echo $profesor['id_profesor']; ?>"
                                    <?php echo $profesor_filter == $profesor['id_profesor'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($profesor['nombres'] . ' ' . $profesor['apellidos']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div style="display: flex; align-items: flex-end;">
                        <button type="submit" class="boton-accion" style="width: 100%;">
                            <i class="fas fa-search"></i> Aplicar Filtros
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if (count($horarios) > 0): ?>
        
        <!-- ESTADÍSTICAS -->
        <div class="estadisticas-horarios">
            <div class="tarjeta-estadistica">
                <div class="numero"><?php echo count($horarios); ?></div>
                <div class="texto">Cursos Programados</div>
            </div>
            
            <?php 
            // Contar por programa
            $contador_programas = [];
            foreach ($horarios as $horario) {
                $programa = $horario['nombre_programa'];
                if (!isset($contador_programas[$programa])) {
                    $contador_programas[$programa] = 0;
                }
                $contador_programas[$programa]++;
            }
            ?>
            
            <?php foreach ($contador_programas as $programa => $cantidad): ?>
            <div class="tarjeta-estadistica">
                <div class="numero"><?php echo $cantidad; ?></div>
                <div class="texto"><?php echo htmlspecialchars($programa); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- **CORRECCIÓN COMPLETA: TABLA SEMANAL QUE MUESTRA LOS HORARIOS** -->
        <div class="tabla-semanal-container">
            <!-- CABECERA -->
            <div class="tabla-semanal-header">
                <div class="header-hora">
                    <i class="fas fa-clock" style="margin-right: 8px;"></i> HORARIO
                </div>
                <?php 
                $hoy = date('N'); // 1=Lunes, 2=Martes, etc.
                foreach ($dias_semana as $index => $dia): 
                    $es_hoy = ($hoy == $index + 1);
                ?>
                <div class="header-dia <?php echo $es_hoy ? 'today' : ''; ?>">
                    <?php echo $dia; ?>
                    <?php if (isset($horarios_por_dia[$dia])): ?>
                    <div style="font-size: 12px; opacity: 0.8; margin-top: 3px;">
                        <?php echo count($horarios_por_dia[$dia]); ?> cursos
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- CUERPO DE LA TABLA - **CORREGIDO** -->
            <div class="tabla-semanal-body">
                <?php foreach ($horas_tabla as $hora_index => $hora): ?>
                    <!-- COLUMNA DE HORA -->
                    <div class="hora-slot">
                        <?php echo $hora['display']; ?>
                    </div>
                    
                    <!-- CELDAS POR DÍA PARA ESTA HORA -->
                    <?php foreach ($dias_semana as $dia): ?>
                    <div class="celda-dia" 
                         data-hora="<?php echo $hora['inicio']; ?>" 
                         data-dia="<?php echo $dia; ?>"
                         data-index="<?php echo $hora_index; ?>"
                         id="celda-<?php echo $dia . '-' . str_replace(':', '-', $hora['inicio']); ?>">
                        
                        <?php 
                        // **CORRECCIÓN: Obtener el horario en esta celda**
                        $horario_en_celda = $tabla_datos[$dia][$hora_index] ?? null;
                        
                        if ($horario_en_celda): 
                            // Calcular duración
                            $duracion_horas = $horario_en_celda['duracion_horas'];
                            
                            // Determinar clase CSS según programa
                            $programa_nombre = strtolower($horario_en_celda['nombre_programa']);
                            $curso_nombre = strtolower($horario_en_celda['nombre_curso']);
                            $clase_programa = 'bloque-otros';
                            
                            if (strpos($programa_nombre, 'topograf') !== false || strpos($curso_nombre, 'topograf') !== false) {
                                $clase_programa = 'bloque-topografia';
                            } elseif (strpos($programa_nombre, 'arquitect') !== false || strpos($curso_nombre, 'arquitect') !== false) {
                                $clase_programa = 'bloque-arquitectura';
                            } elseif (strpos($programa_nombre, 'enfermer') !== false || strpos($curso_nombre, 'enfermer') !== false) {
                                $clase_programa = 'bloque-enfermeria';
                            } elseif (strpos($curso_nombre, 'pediatr') !== false) {
                                $clase_programa = 'bloque-pediatria';
                            } elseif (strpos($curso_nombre, 'fundament') !== false) {
                                $clase_programa = 'bloque-fundamentos';
                            } elseif (strpos($programa_nombre, 'tecnolog') !== false || strpos($curso_nombre, 'tecnolog') !== false) {
                                $clase_programa = 'bloque-tecnologia';
                            }
                            
                            // Determinar clase de duración
                            $clase_duracion = 'bloque-1hora';
                            if ($duracion_horas >= 2 && $duracion_horas < 3) $clase_duracion = 'bloque-2horas';
                            elseif ($duracion_horas >= 3 && $duracion_horas < 4) $clase_duracion = 'bloque-3horas';
                            elseif ($duracion_horas >= 4 && $duracion_horas < 5) $clase_duracion = 'bloque-4horas';
                            elseif ($duracion_horas >= 5 && $duracion_horas < 6) $clase_duracion = 'bloque-5horas';
                            elseif ($duracion_horas >= 6) $clase_duracion = 'bloque-6horas';
                            
                            // Solo mostrar si este es el slot de inicio del curso
                            if ($horario_en_celda['slot_inicio'] == $hora_index):
                                $hora_inicio = strtotime($horario_en_celda['hora_inicio']);
                                $hora_fin = strtotime($horario_en_celda['hora_fin']);
                            ?>
                            
                            <!-- **BLOQUE DEL CURSO - AHORA SÍ SE MUESTRA** -->
                            <div class="bloque-curso <?php echo $clase_programa . ' ' . $clase_duracion; ?>" 
                                 onclick="window.location.href='ver.php?id=<?php echo $horario_en_celda['id_horario']; ?>'"
                                 data-curso="<?php echo htmlspecialchars($horario_en_celda['nombre_curso']); ?>"
                                 data-codigo="<?php echo htmlspecialchars($horario_en_celda['codigo_curso']); ?>"
                                 data-profesor="<?php echo htmlspecialchars($horario_en_celda['profesor_nombre']); ?>"
                                 data-aula="<?php echo htmlspecialchars($horario_en_celda['codigo_aula'] . ' - ' . $horario_en_celda['nombre_aula']); ?>"
                                 data-hora="<?php echo date('h:i A', $hora_inicio) . ' - ' . date('h:i A', $hora_fin); ?>"
                                 data-tipo="<?php echo ucfirst($horario_en_celda['tipo_clase']); ?>"
                                 data-programa="<?php echo htmlspecialchars($horario_en_celda['nombre_programa']); ?>"
                                 data-semestre="<?php echo htmlspecialchars($horario_en_celda['codigo_semestre']); ?>"
                                 data-duracion="<?php echo round(($hora_fin - $hora_inicio) / 60); ?> minutos"
                                 data-duracion-horas="<?php echo $duracion_horas; ?>">
                                
                                <div class="curso-header">
                                    <div class="curso-horario">
                                        <?php echo date('h:i', $hora_inicio) . '-' . date('h:i', $hora_fin); ?>
                                    </div>
                                    <div class="curso-tipo">
                                        <?php echo substr(strtoupper($horario_en_celda['tipo_clase']), 0, 1); ?>
                                    </div>
                                </div>
                                
                                <div class="curso-nombre">
                                    <?php echo htmlspecialchars($horario_en_celda['nombre_curso']); ?>
                                </div>
                                
                                <div class="curso-detalles">
                                    <span class="curso-profesor">
                                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($horario_en_celda['profesor_nombre']); ?>
                                    </span>
                                    <span class="curso-aula">
                                        <i class="fas fa-door-open"></i> <?php echo htmlspecialchars($horario_en_celda['codigo_aula']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <?php else: ?>
                                <!-- Para slots que están ocupados por un curso que empezó antes -->
                                <?php 
                                // Verificar si este slot está ocupado por un curso que empezó antes
                                $ocupado_por_curso_anterior = false;
                                foreach ($horarios_procesados as $horario_check) {
                                    if ($horario_check['dia_semana'] == $dia) {
                                        $slot_inicio_check = $horario_check['slot_inicio'];
                                        $duracion_check = ceil($horario_check['duracion_horas']);
                                        
                                        if ($hora_index > $slot_inicio_check && 
                                            $hora_index < ($slot_inicio_check + $duracion_check)) {
                                            $ocupado_por_curso_anterior = true;
                                            break;
                                        }
                                    }
                                }
                                ?>
                                
                                <?php if ($ocupado_por_curso_anterior): ?>
                                    <!-- Celda ocupada por un curso que empezó antes -->
                                    <div style="height: 70px; background: transparent;"></div>
                                <?php else: ?>
                                    <!-- CELDA VACÍA -->
                                    <div class="celda-vacia">
                                        <i class="far fa-calendar-times"></i> Disponible
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <!-- CELDA VACÍA -->
                            <div class="celda-vacia">
                                <i class="far fa-calendar-times"></i> Disponible
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- LEYENDA -->
        <div class="leyenda-horarios">
            <div class="leyenda-titulo">Leyenda de Programas</div>
            
            <div class="leyenda-programas">
                <div class="leyenda-item">
                    <div class="leyenda-color" style="background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);"></div>
                    <div class="leyenda-texto">Topografía</div>
                </div>
                <div class="leyenda-item">
                    <div class="leyenda-color" style="background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);"></div>
                    <div class="leyenda-texto">Arquitectura</div>
                </div>
                <div class="leyenda-item">
                    <div class="leyenda-color" style="background: linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%);"></div>
                    <div class="leyenda-texto">Enfermería</div>
                </div>
            </div>
            
            <div class="leyenda-programas">
                <div class="leyenda-item">
                    <div class="leyenda-color" style="background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);"></div>
                    <div class="leyenda-texto">Pediatría</div>
                </div>
                <div class="leyenda-item">
                    <div class="leyenda-color" style="background: linear-gradient(135deg, #f39c12 0%, #d35400 100%);"></div>
                    <div class="leyenda-texto">Fundamentos</div>
                </div>
                <div class="leyenda-item">
                    <div class="leyenda-color" style="background: linear-gradient(135deg, #1abc9c 0%, #16a085 100%);"></div>
                    <div class="leyenda-texto">Tecnología</div>
                </div>
            </div>
            
            <div class="leyenda-programas">
                <div class="leyenda-item">
                    <div style="width: 20px; height: 20px; border-radius: 50%; background: rgba(255,255,255,0.3); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: bold; color: white;">T</div>
                    <div class="leyenda-texto">Teoría</div>
                </div>
                <div class="leyenda-item">
                    <div style="width: 20px; height: 20px; border-radius: 50%; background: rgba(255,255,255,0.3); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: bold; color: white;">P</div>
                    <div class="leyenda-texto">Práctica</div>
                </div>
                <div class="leyenda-item">
                    <div style="width: 20px; height: 20px; border-radius: 50%; background: rgba(255,255,255,0.3); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: bold; color: white;">L</div>
                    <div class="leyenda-texto">Laboratorio</div>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <!-- SIN HORARIOS -->
        <div class="empty-state" style="text-align: center; padding: 50px 20px; background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
            <i class="fas fa-calendar-times" style="font-size: 60px; color: #bdc3c7; margin-bottom: 20px;"></i>
            <h3 style="color: #2c3e50; margin-bottom: 10px;">No hay horarios programados</h3>
            <p style="color: #7f8c8d; margin-bottom: 25px; max-width: 500px; margin-left: auto; margin-right: auto;">
                No se encontraron horarios con los filtros aplicados. Puedes crear nuevos horarios para mostrarlos en esta vista.
            </p>
            <a href="crear.php" class="boton-accion">
                <i class="fas fa-plus"></i> Crear Primer Horario
            </a>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        // JavaScript para manejar tooltips
        document.addEventListener('DOMContentLoaded', function() {
            const bloques = document.querySelectorAll('.bloque-curso');
            
            bloques.forEach(bloque => {
                // Crear tooltip para el bloque
                bloque.addEventListener('mouseenter', function(e) {
                    const tooltip = document.createElement('div');
                    tooltip.className = 'tooltip-custom';
                    tooltip.style.position = 'fixed';
                    tooltip.style.zIndex = '1000';
                    tooltip.style.background = 'rgba(0,0,0,0.9)';
                    tooltip.style.color = 'white';
                    tooltip.style.padding = '12px';
                    tooltip.style.borderRadius = '6px';
                    tooltip.style.fontSize = '13px';
                    tooltip.style.maxWidth = '300px';
                    tooltip.style.boxShadow = '0 4px 12px rgba(0,0,0,0.3)';
                    
                    tooltip.innerHTML = `
                        <div style="font-weight: 700; margin-bottom: 8px; color: #fff; font-size: 14px; border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 4px;">
                            ${this.dataset.curso}
                        </div>
                        <div style="display: grid; grid-template-columns: auto 1fr; gap: 5px 15px; margin-bottom: 3px;">
                            <span style="color: #ccc;">Código:</span>
                            <span style="font-weight: 600; color: #fff; text-align: right;">${this.dataset.codigo}</span>
                        </div>
                        <div style="display: grid; grid-template-columns: auto 1fr; gap: 5px 15px; margin-bottom: 3px;">
                            <span style="color: #ccc;">Horario:</span>
                            <span style="font-weight: 600; color: #fff; text-align: right;">${this.dataset.hora}</span>
                        </div>
                        <div style="display: grid; grid-template-columns: auto 1fr; gap: 5px 15px; margin-bottom: 3px;">
                            <span style="color: #ccc;">Duración:</span>
                            <span style="font-weight: 600; color: #fff; text-align: right;">${this.dataset.duracion}</span>
                        </div>
                        <div style="display: grid; grid-template-columns: auto 1fr; gap: 5px 15px; margin-bottom: 3px;">
                            <span style="color: #ccc;">Profesor:</span>
                            <span style="font-weight: 600; color: #fff; text-align: right;">${this.dataset.profesor}</span>
                        </div>
                        <div style="display: grid; grid-template-columns: auto 1fr; gap: 5px 15px; margin-bottom: 3px;">
                            <span style="color: #ccc;">Aula:</span>
                            <span style="font-weight: 600; color: #fff; text-align: right;">${this.dataset.aula}</span>
                        </div>
                        <div style="display: grid; grid-template-columns: auto 1fr; gap: 5px 15px; margin-bottom: 3px;">
                            <span style="color: #ccc;">Tipo:</span>
                            <span style="font-weight: 600; color: #fff; text-align: right;">${this.dataset.tipo}</span>
                        </div>
                        <div style="display: grid; grid-template-columns: auto 1fr; gap: 5px 15px; margin-bottom: 3px;">
                            <span style="color: #ccc;">Programa:</span>
                            <span style="font-weight: 600; color: #fff; text-align: right;">${this.dataset.programa}</span>
                        </div>
                        <div style="display: grid; grid-template-columns: auto 1fr; gap: 5px 15px;">
                            <span style="color: #ccc;">Semestre:</span>
                            <span style="font-weight: 600; color: #fff; text-align: right;">${this.dataset.semestre}</span>
                        </div>
                    `;
                    
                    document.body.appendChild(tooltip);
                    
                    const rect = this.getBoundingClientRect();
                    let top = rect.top - tooltip.offsetHeight - 10;
                    let left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2);
                    
                    if (left < 10) left = 10;
                    if (left + tooltip.offsetWidth > window.innerWidth - 10) {
                        left = window.innerWidth - tooltip.offsetWidth - 10;
                    }
                    
                    if (top < 10) {
                        top = rect.bottom + 10;
                    }
                    
                    tooltip.style.top = top + 'px';
                    tooltip.style.left = left + 'px';
                    
                    this._tooltip = tooltip;
                });
                
                bloque.addEventListener('mouseleave', function() {
                    if (this._tooltip) {
                        this._tooltip.remove();
                        delete this._tooltip;
                    }
                });
            });
        });
    </script>
</body>
</html>