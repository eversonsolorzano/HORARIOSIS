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

// Obtener todos los cursos inscritos del estudiante
$stmt = $db->prepare("
    SELECT 
        c.id_curso,
        c.codigo_curso,
        c.nombre_curso,
        c.descripcion,
        c.creditos,
        c.horas_semanales,
        c.tipo_curso,
        c.semestre as nivel_curso,
        COUNT(DISTINCT h.id_horario) as num_horarios,
        GROUP_CONCAT(DISTINCT CONCAT(
            h.dia_semana, ' ', 
            TIME_FORMAT(h.hora_inicio, '%h:%i %p'), '-',
            TIME_FORMAT(h.hora_fin, '%h:%i %p'), ' (', 
            a.nombre_aula, ')'
        ) SEPARATOR '; ') as horarios_info,
        GROUP_CONCAT(DISTINCT CONCAT(pr.nombres, ' ', pr.apellidos) SEPARATOR ', ') as profesores,
        MAX(i.fecha_inscripcion) as fecha_inscripcion,
        i.estado as estado_inscripcion,
        i.nota_final,
        s.codigo_semestre,
        s.nombre_semestre
    FROM inscripciones i
    JOIN horarios h ON i.id_horario = h.id_horario
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN aulas a ON h.id_aula = a.id_aula
    JOIN profesores pr ON h.id_profesor = pr.id_profesor
    JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
    WHERE i.id_estudiante = ?
    GROUP BY c.id_curso, s.id_semestre
    ORDER BY s.fecha_inicio DESC, c.nombre_curso
");
$stmt->execute([$estudiante['id_estudiante']]);
$cursos = $stmt->fetchAll();

// Calcular estadísticas
$total_cursos = count($cursos);
$total_creditos = 0;
$total_aprobados = 0;
$total_reprobados = 0;
$total_inscritos = 0;

foreach ($cursos as $curso) {
    $total_creditos += $curso['creditos'];
    
    switch ($curso['estado_inscripcion']) {
        case 'aprobado':
            $total_aprobados++;
            break;
        case 'reprobado':
            $total_reprobados++;
            break;
        case 'inscrito':
            $total_inscritos++;
            break;
    }
}

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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Cursos - Sistema de Horarios</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .estadisticas-cursos {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .estadistica-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            transition: transform 0.3s, box-shadow 0.3s;
            border: 1px solid #e5e7eb;
        }
        
        .estadistica-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        .estadistica-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: white;
            flex-shrink: 0;
        }
        
        .estadistica-info h3 {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .estadistica-numero {
            font-size: 32px;
            font-weight: 700;
            color: #1f2937;
            line-height: 1;
        }
        
        .estadistica-desc {
            font-size: 14px;
            color: #9ca3af;
            margin-top: 5px;
        }
        
        /* Tabla de cursos */
        .cursos-table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .table-header {
            padding: 25px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .table-header h2 {
            color: #1f2937;
            margin: 0;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .table-controls {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .cursos-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .cursos-table thead {
            background: #f9fafb;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .cursos-table th {
            padding: 18px 20px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        
        .cursos-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
            transition: background-color 0.2s;
        }
        
        .cursos-table tbody tr:hover {
            background-color: #f9fafb;
        }
        
        .cursos-table td {
            padding: 20px;
            vertical-align: top;
            color: #4b5563;
        }
        
        .curso-nombre {
            font-weight: 600;
            color: #1f2937;
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .curso-codigo {
            font-size: 14px;
            color: #6b7280;
            background: #f3f4f6;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-block;
        }
        
        .estado-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            display: inline-block;
        }
        
        .estado-inscrito {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .estado-aprobado {
            background: #d1fae5;
            color: #065f46;
        }
        
        .estado-reprobado {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .nota-cell {
            font-weight: 700;
            font-size: 18px;
            text-align: center;
        }
        
        .nota-aprobatoria {
            color: #10b981;
        }
        
        .nota-reprobatoria {
            color: #ef4444;
        }
        
        .nota-pendiente {
            color: #9ca3af;
            font-size: 14px;
        }
        
        .curso-detalles {
            font-size: 13px;
            color: #6b7280;
            margin-top: 8px;
            line-height: 1.5;
        }
        
        .detalle-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }
        
        .detalle-item i {
            color: #9ca3af;
            width: 16px;
        }
        
        .acciones-cell {
            white-space: nowrap;
        }
        
        .acciones-btns {
            display: flex;
            gap: 8px;
        }
        
        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            color: white;
            font-size: 14px;
        }
        
        .btn-icon:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .btn-ver {
            background: #3b82f6;
        }
        
        .btn-ver:hover {
            background: #2563eb;
        }
        
        .btn-horario {
            background: #10b981;
        }
        
        .btn-horario:hover {
            background: #059669;
        }
        
        .btn-retirar {
            background: #ef4444;
        }
        
        .btn-retirar:hover {
            background: #dc2626;
        }
        
        .btn-info {
            background: #8b5cf6;
        }
        
        .btn-info:hover {
            background: #7c3aed;
        }
        
        .empty-table {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-table i {
            font-size: 48px;
            color: #d1d5db;
            margin-bottom: 20px;
        }
        
        .empty-table h3 {
            color: #4b5563;
            margin-bottom: 10px;
        }
        
        .empty-table p {
            color: #6b7280;
            max-width: 500px;
            margin: 0 auto 25px;
        }
        
        .semestre-info {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .semestre-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }
        
        .semestre-item {
            padding: 20px;
            background: #f9fafb;
            border-radius: 8px;
            border-left: 4px solid #3b82f6;
        }
        
        .semestre-label {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .semestre-valor {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .cursos-table {
                display: block;
                overflow-x: auto;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .table-controls {
                width: 100%;
            }
        }
        
        @media (max-width: 768px) {
            .estadisticas-cursos {
                grid-template-columns: 1fr;
            }
            
            .acciones-btns {
                flex-direction: column;
            }
            
            .btn-icon {
                width: 100%;
            }
        }
        
        /* Modal para detalles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            width: 100%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        .modal-header {
            padding: 25px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            color: #1f2937;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: #9ca3af;
            cursor: pointer;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            transition: background-color 0.2s;
        }
        
        .modal-close:hover {
            background: #f3f4f6;
            color: #6b7280;
        }
        
        .modal-body {
            padding: 25px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fas fa-book"></i> Mis Cursos</h1>
                <div class="nav">
                    <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    <a href="mi_horario.php"><i class="fas fa-calendar-alt"></i> Mi Horario</a>
                    <a href="mis_cursos.php" class="active"><i class="fas fa-book"></i> Mis Cursos</a>
                    <a href="inscribir_curso.php"><i class="fas fa-plus-circle"></i> Inscribir Curso</a>
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
        
        <!-- Estadísticas -->
        <div class="estadisticas-cursos">
            <div class="estadistica-card">
                <div class="estadistica-icon" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);">
                    <i class="fas fa-book"></i>
                </div>
                <div class="estadistica-info">
                    <div class="estadistica-numero"><?php echo $total_cursos; ?></div>
                    <h3>Cursos Totales</h3>
                    <div class="estadistica-desc">Historial completo</div>
                </div>
            </div>
            
            <div class="estadistica-card">
                <div class="estadistica-icon" style="background: linear-gradient(135deg, #10b981 0%, #047857 100%);">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="estadistica-info">
                    <div class="estadistica-numero"><?php echo $total_creditos; ?></div>
                    <h3>Créditos Totales</h3>
                    <div class="estadistica-desc">Acumulados</div>
                </div>
            </div>
            
            <div class="estadistica-card">
                <div class="estadistica-icon" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="estadistica-info">
                    <div class="estadistica-numero"><?php echo $total_aprobados; ?></div>
                    <h3>Aprobados</h3>
                    <div class="estadistica-desc">Cursos finalizados</div>
                </div>
            </div>
            
            <div class="estadistica-card">
                <div class="estadistica-icon" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                    <i class="fas fa-spinner"></i>
                </div>
                <div class="estadistica-info">
                    <div class="estadistica-numero"><?php echo $total_inscritos; ?></div>
                    <h3>En Curso</h3>
                    <div class="estadistica-desc">Cursos activos</div>
                </div>
            </div>
        </div>
        
        <!-- Tabla de Cursos -->
        <div class="cursos-table-container">
            <div class="table-header">
                <h2><i class="fas fa-table"></i> Lista de Cursos</h2>
                
                <div class="table-controls">
                    <div class="filter-group" style="min-width: 200px;">
                        <select id="filtroEstado" class="form-control" style="width: 100%;">
                            <option value="">Todos los estados</option>
                            <option value="inscrito">En curso</option>
                            <option value="aprobado">Aprobados</option>
                            <option value="reprobado">Reprobados</option>
                        </select>
                    </div>
                    
                    <div class="filter-group" style="min-width: 200px;">
                        <select id="filtroSemestre" class="form-control" style="width: 100%;">
                            <option value="">Todos los semestres</option>
                            <?php
                            $semestres_unicos = [];
                            foreach ($cursos as $curso) {
                                $semestre_key = $curso['codigo_semestre'];
                                if (!in_array($semestre_key, $semestres_unicos)) {
                                    $semestres_unicos[] = $semestre_key;
                                    $selected = ($semestre_actual && $semestre_actual['codigo_semestre'] == $curso['codigo_semestre']) ? 'selected' : '';
                                    echo "<option value='{$curso['codigo_semestre']}' {$selected}>{$curso['nombre_semestre']}</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <button id="btnAplicarFiltros" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Filtrar
                        </button>
                        <button id="btnLimpiarFiltros" class="btn btn-secondary">
                            <i class="fas fa-redo"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if (count($cursos) > 0): ?>
                <div style="overflow-x: auto;">
                    <table class="cursos-table">
                        <thead>
                            <tr>
                                <th>Curso</th>
                                <th>Semestre</th>
                                <th>Créditos</th>
                                <th>Estado</th>
                                <th>Calificación</th>
                                <th>Detalles</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cursos as $curso): 
                                // Color para el badge de estado
                                $estado_class = 'estado-' . $curso['estado_inscripcion'];
                                $estado_text = $curso['estado_inscripcion'] == 'aprobado' ? 'Aprobado' : 
                                             ($curso['estado_inscripcion'] == 'reprobado' ? 'Reprobado' : 'En Curso');
                            ?>
                            <tr data-estado="<?php echo $curso['estado_inscripcion']; ?>" 
                                data-semestre="<?php echo $curso['codigo_semestre']; ?>">
                                <td style="min-width: 250px;">
                                    <div class="curso-nombre"><?php echo htmlspecialchars($curso['nombre_curso']); ?></div>
                                    <span class="curso-codigo"><?php echo $curso['codigo_curso']; ?></span>
                                </td>
                                
                                <td style="min-width: 150px;">
                                    <div><?php echo $curso['nivel_curso']; ?>° Semestre</div>
                                    <div class="curso-detalles"><?php echo $curso['nombre_semestre']; ?></div>
                                </td>
                                
                                <td>
                                    <div style="font-weight: 600; font-size: 18px; color: #1f2937;">
                                        <?php echo $curso['creditos']; ?>
                                    </div>
                                    <div class="curso-detalles">
                                        <div class="detalle-item">
                                            <i class="fas fa-clock"></i>
                                            <?php echo $curso['horas_semanales']; ?> hrs/semana
                                        </div>
                                        <div class="detalle-item">
                                            <i class="fas fa-graduation-cap"></i>
                                            <?php echo ucfirst($curso['tipo_curso']); ?>
                                        </div>
                                    </div>
                                </td>
                                
                                <td>
                                    <span class="estado-badge <?php echo $estado_class; ?>">
                                        <?php echo $estado_text; ?>
                                    </span>
                                    <div class="curso-detalles">
                                        <?php echo date('d/m/Y', strtotime($curso['fecha_inscripcion'])); ?>
                                    </div>
                                </td>
                                
                                <td>
                                    <?php if ($curso['estado_inscripcion'] == 'aprobado' || $curso['estado_inscripcion'] == 'reprobado'): ?>
                                        <div class="nota-cell <?php echo $curso['nota_final'] >= 10.5 ? 'nota-aprobatoria' : 'nota-reprobatoria'; ?>">
                                            <?php echo number_format($curso['nota_final'], 1); ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="nota-pendiente">PENDIENTE</div>
                                    <?php endif; ?>
                                </td>
                                
                                <td style="min-width: 200px;">
                                    <div class="curso-detalles">
                                        <div class="detalle-item">
                                            <i class="fas fa-chalkboard-teacher"></i>
                                            <?php echo htmlspecialchars(substr($curso['profesores'], 0, 30)); ?>
                                            <?php if (strlen($curso['profesores']) > 30): ?>...<?php endif; ?>
                                        </div>
                                        <?php if ($curso['descripcion']): ?>
                                        <div class="detalle-item">
                                            <i class="fas fa-align-left"></i>
                                            <?php echo htmlspecialchars(substr($curso['descripcion'], 0, 50)); ?>
                                            <?php if (strlen($curso['descripcion']) > 50): ?>...<?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                
                                <td class="acciones-cell">
                                    <div class="acciones-btns">
                                        <button class="btn-icon btn-info" 
                                                onclick="verDetallesCurso(<?php echo $curso['id_curso']; ?>)" 
                                                title="Ver detalles">
                                            <i class="fas fa-info-circle"></i>
                                        </button>
                                        
                                        <a href="mi_horario.php" class="btn-icon btn-horario" title="Ver en horario">
                                            <i class="fas fa-calendar-alt"></i>
                                        </a>
                                        
                                        <?php if ($curso['estado_inscripcion'] == 'inscrito'): ?>
                                        <a href="inscribir_curso.php?retirar=<?php echo $curso['id_curso']; ?>" 
                                           class="btn-icon btn-retirar" 
                                           title="Retirar curso"
                                           onclick="return confirm('¿Estás seguro de retirarte de este curso?')">
                                            <i class="fas fa-sign-out-alt"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-table">
                    <i class="fas fa-book-open"></i>
                    <h3>No tienes cursos registrados</h3>
                    <p>No estás inscrito en ningún curso actualmente. Puedes inscribirte en nuevos cursos desde la sección de inscripciones.</p>
                    <a href="inscribir_curso.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Inscribirse en Cursos
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Información del Semestre Actual -->
        <?php if ($semestre_actual): ?>
        <div class="semestre-info">
            <h2><i class="fas fa-calendar"></i> Semestre Actual</h2>
            <div class="semestre-grid">
                <div class="semestre-item">
                    <div class="semestre-label">Código</div>
                    <div class="semestre-valor"><?php echo htmlspecialchars($semestre_actual['codigo_semestre']); ?></div>
                </div>
                
                <div class="semestre-item">
                    <div class="semestre-label">Nombre</div>
                    <div class="semestre-valor"><?php echo htmlspecialchars($semestre_actual['nombre_semestre']); ?></div>
                </div>
                
                <div class="semestre-item">
                    <div class="semestre-label">Fecha de Inicio</div>
                    <div class="semestre-valor"><?php echo date('d/m/Y', strtotime($semestre_actual['fecha_inicio'])); ?></div>
                </div>
                
                <div class="semestre-item">
                    <div class="semestre-label">Fecha de Fin</div>
                    <div class="semestre-valor"><?php echo date('d/m/Y', strtotime($semestre_actual['fecha_fin'])); ?></div>
                </div>
                
                <div class="semestre-item">
                    <div class="semestre-label">Estado</div>
                    <div class="semestre-valor">
                        <span class="badge badge-success">EN CURSO</span>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Modal para detalles del curso -->
        <div id="modalDetalles" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-info-circle"></i> Detalles del Curso</h3>
                    <button class="modal-close" onclick="cerrarModal()">&times;</button>
                </div>
                <div class="modal-body" id="modalDetallesBody">
                    <!-- Los detalles se cargarán aquí dinámicamente -->
                </div>
            </div>
        </div>
    </div>
    
    <script src="../assets/js/main.js"></script>
    <script>
        // Filtrado de cursos
        document.addEventListener('DOMContentLoaded', function() {
            const btnAplicar = document.getElementById('btnAplicarFiltros');
            const btnLimpiar = document.getElementById('btnLimpiarFiltros');
            const filtroEstado = document.getElementById('filtroEstado');
            const filtroSemestre = document.getElementById('filtroSemestre');
            const filas = document.querySelectorAll('.cursos-table tbody tr');
            
            function aplicarFiltros() {
                const estado = filtroEstado.value;
                const semestre = filtroSemestre.value;
                let filasVisibles = 0;
                
                filas.forEach(fila => {
                    const filaEstado = fila.dataset.estado;
                    const filaSemestre = fila.dataset.semestre;
                    
                    let mostrar = true;
                    
                    if (estado && filaEstado !== estado) {
                        mostrar = false;
                    }
                    
                    if (semestre && filaSemestre !== semestre) {
                        mostrar = false;
                    }
                    
                    fila.style.display = mostrar ? '' : 'none';
                    if (mostrar) filasVisibles++;
                });
                
                // Mostrar mensaje si no hay resultados
                const tablaContainer = document.querySelector('.cursos-table-container');
                let mensajeNoResultados = tablaContainer.querySelector('.no-resultados');
                
                if (filasVisibles === 0) {
                    if (!mensajeNoResultados) {
                        mensajeNoResultados = document.createElement('div');
                        mensajeNoResultados.className = 'empty-table no-resultados';
                        mensajeNoResultados.innerHTML = `
                            <i class="fas fa-search"></i>
                            <h3>No se encontraron cursos</h3>
                            <p>No hay cursos que coincidan con los filtros aplicados.</p>
                            <button onclick="limpiarFiltros()" class="btn btn-primary">
                                <i class="fas fa-redo"></i> Limpiar Filtros
                            </button>
                        `;
                        const tabla = document.querySelector('.cursos-table');
                        tabla.style.display = 'none';
                        tablaContainer.appendChild(mensajeNoResultados);
                    }
                } else {
                    if (mensajeNoResultados) {
                        mensajeNoResultados.remove();
                        document.querySelector('.cursos-table').style.display = 'table';
                    }
                }
            }
            
            function limpiarFiltros() {
                filtroEstado.value = '';
                filtroSemestre.value = '';
                
                filas.forEach(fila => {
                    fila.style.display = '';
                });
                
                const mensajeNoResultados = document.querySelector('.no-resultados');
                if (mensajeNoResultados) {
                    mensajeNoResultados.remove();
                    document.querySelector('.cursos-table').style.display = 'table';
                }
            }
            
            btnAplicar.addEventListener('click', aplicarFiltros);
            btnLimpiar.addEventListener('click', limpiarFiltros);
            
            // Aplicar filtros al cambiar selección
            filtroEstado.addEventListener('change', aplicarFiltros);
            filtroSemestre.addEventListener('change', aplicarFiltros);
        });
        
        // Mostrar detalles del curso en modal
        function verDetallesCurso(idCurso) {
            // Aquí normalmente harías una petición AJAX para obtener los detalles
            // Por ahora, simularemos con datos estáticos
            const modal = document.getElementById('modalDetalles');
            const modalBody = document.getElementById('modalDetallesBody');
            
            // Simular carga de datos
            modalBody.innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: #3b82f6;"></i>
                    <p style="margin-top: 20px; color: #6b7280;">Cargando detalles del curso...</p>
                </div>
            `;
            
            modal.style.display = 'flex';
            
            // Simular respuesta después de 500ms
            setTimeout(() => {
                modalBody.innerHTML = `
                    <div style="display: grid; gap: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <h4 style="color: #1f2937; margin-bottom: 5px;">Curso de Ejemplo</h4>
                                <span style="color: #6b7280; font-size: 14px;">CUR-${idCurso}</span>
                            </div>
                            <span class="estado-badge estado-inscrito">En Curso</span>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                            <div style="background: #f9fafb; padding: 15px; border-radius: 8px;">
                                <div style="font-size: 12px; color: #6b7280; margin-bottom: 5px;">Créditos</div>
                                <div style="font-size: 24px; font-weight: 700; color: #1f2937;">3</div>
                            </div>
                            <div style="background: #f9fafb; padding: 15px; border-radius: 8px;">
                                <div style="font-size: 12px; color: #6b7280; margin-bottom: 5px;">Horas/Semana</div>
                                <div style="font-size: 24px; font-weight: 700; color: #1f2937;">4</div>
                            </div>
                        </div>
                        
                        <div>
                            <h5 style="color: #374151; margin-bottom: 10px;">Descripción</h5>
                            <p style="color: #6b7280; line-height: 1.6;">
                                Este es un curso de ejemplo con una descripción detallada. Aquí se mostraría información completa sobre los objetivos, contenidos, metodología y evaluación del curso.
                            </p>
                        </div>
                        
                        <div>
                            <h5 style="color: #374151; margin-bottom: 10px;">Profesores</h5>
                            <div style="display: flex; align-items: center; gap: 10px; padding: 12px; background: #f9fafb; border-radius: 8px;">
                                <div style="width: 40px; height: 40px; background: #3b82f6; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div>
                                    <div style="font-weight: 600; color: #1f2937;">Profesor Ejemplo</div>
                                    <div style="font-size: 13px; color: #6b7280;">Especialista en el área</div>
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <h5 style="color: #374151; margin-bottom: 10px;">Horarios</h5>
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f9fafb; border-radius: 8px;">
                                    <div>
                                        <div style="font-weight: 600; color: #1f2937;">Lunes</div>
                                        <div style="font-size: 13px; color: #6b7280;">08:00 AM - 10:00 AM</div>
                                    </div>
                                    <div style="font-size: 13px; color: #6b7280;">Aula 301</div>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f9fafb; border-radius: 8px;">
                                    <div>
                                        <div style="font-weight: 600; color: #1f2937;">Miércoles</div>
                                        <div style="font-size: 13px; color: #6b7280;">10:00 AM - 12:00 PM</div>
                                    </div>
                                    <div style="font-size: 13px; color: #6b7280;">Laboratorio 2</div>
                                </div>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button onclick="cerrarModal()" class="btn btn-secondary" style="flex: 1;">
                                Cerrar
                            </button>
                            <a href="mi_horario.php" class="btn btn-primary" style="flex: 1;">
                                Ver en Horario
                            </a>
                        </div>
                    </div>
                `;
            }, 500);
        }
        
        // Cerrar modal
        function cerrarModal() {
            document.getElementById('modalDetalles').style.display = 'none';
        }
        
        // Cerrar modal al hacer clic fuera
        document.getElementById('modalDetalles').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarModal();
            }
        });
        
        // Cerrar modal con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cerrarModal();
            }
        });
        
        // Exportar a Excel (simulado)
        function exportarExcel() {
            alert('Exportando a Excel...\n\nEn una implementación real, esto generaría un archivo Excel con todos los cursos.');
        }
        
        // Imprimir tabla
        function imprimirTabla() {
            window.print();
        }
    </script>
</body>
</html>