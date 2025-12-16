<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

// Obtener ID del horario
$id_horario = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_horario) {
    Funciones::redireccionar('index.php', 'ID de horario no válido', 'error');
}

// Obtener datos del horario
$stmt = $db->prepare("
    SELECT h.*, 
           c.nombre_curso, c.codigo_curso, c.descripcion as descripcion_curso, 
           c.creditos, c.horas_semanales, c.semestre as semestre_curso,
           CONCAT(p.nombres, ' ', p.apellidos) as profesor_nombre,
           p.especialidad, p.codigo_profesor,
           a.codigo_aula, a.nombre_aula, a.capacidad, a.tipo_aula, a.edificio, a.piso,
           s.nombre_semestre, s.codigo_semestre, s.fecha_inicio, s.fecha_fin,
           pr.nombre_programa, pr.codigo_programa,
           u.username as creador
    FROM horarios h
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN profesores p ON h.id_profesor = p.id_profesor
    JOIN aulas a ON h.id_aula = a.id_aula
    JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
    JOIN programas_estudio pr ON c.id_programa = pr.id_programa
    LEFT JOIN usuarios u ON h.creado_por = u.id_usuario
    WHERE h.id_horario = ?
");
$stmt->execute([$id_horario]);
$horario = $stmt->fetch();

if (!$horario) {
    Funciones::redireccionar('index.php', 'Horario no encontrado', 'error');
}

// Obtener estudiantes inscritos
$stmt = $db->prepare("
    SELECT e.*, i.fecha_inscripcion, i.estado, i.nota_final
    FROM inscripciones i
    JOIN estudiantes e ON i.id_estudiante = e.id_estudiante
    WHERE i.id_horario = ?
    ORDER BY e.apellidos, e.nombres
");
$stmt->execute([$id_horario]);
$estudiantes = $stmt->fetchAll();

// Obtener historial de cambios
$stmt = $db->prepare("
    SELECT ch.*, u.username as realizado_por_nombre
    FROM cambios_horario ch
    LEFT JOIN usuarios u ON ch.realizado_por = u.id_usuario
    WHERE ch.id_horario = ?
    ORDER BY ch.fecha_cambio DESC
    LIMIT 10
");
$stmt->execute([$id_horario]);
$cambios = $stmt->fetchAll();

// Obtener capacidad ocupada
$capacidad_ocupada = count($estudiantes);
$capacidad_disponible = $horario['capacidad_grupo'] - $capacidad_ocupada;
$porcentaje_ocupacion = $horario['capacidad_grupo'] > 0 ? ($capacidad_ocupada / $horario['capacidad_grupo']) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ver Horario - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .info-card {
            background: linear-gradient(135deg, white 0%, var(--gray-50) 100%);
            border-radius: var(--radius-xl);
            padding: 25px;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
        }
        
        .info-card h3 {
            color: var(--dark);
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--gray-100);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-item {
            display: flex;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--gray-100);
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
        
        .progress-bar-container {
            margin-top: 15px;
        }
        
        .progress-bar {
            height: 10px;
            background: var(--gray-200);
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 5px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transition: width 1s ease-in-out;
        }
        
        .progress-info {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: var(--gray-600);
        }
        
        .estudiante-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid var(--gray-100);
            transition: background-color 0.2s ease;
        }
        
        .estudiante-item:hover {
            background: var(--gray-50);
        }
        
        .estudiante-info {
            flex: 1;
        }
        
        .estudiante-nombre {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 3px;
        }
        
        .estudiante-details {
            font-size: 13px;
            color: var(--gray-600);
        }
        
        .cambio-item {
            padding: 15px;
            border-left: 4px solid var(--primary);
            background: var(--gray-50);
            border-radius: var(--radius);
            margin-bottom: 10px;
        }
        
        .cambio-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .cambio-tipo {
            font-weight: 600;
            color: var(--primary);
            text-transform: capitalize;
        }
        
        .cambio-fecha {
            font-size: 12px;
            color: var(--gray-500);
        }
        
        .cambio-details {
            font-size: 14px;
            color: var(--gray-700);
            margin-top: 5px;
        }
        
        .badge-estado {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-inscrito { background: #bee3f8; color: #2c5282; }
        .badge-aprobado { background: #c6f6d5; color: #22543d; }
        .badge-reprobado { background: #fed7d7; color: #c53030; }
        .badge-retirado { background: #feebc8; color: #744210; }
        
        .nota {
            font-weight: 700;
            font-size: 14px;
        }
        
        .nota-aprobado { color: var(--success); }
        .nota-reprobado { color: var(--danger); }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-eye"></i> Detalles del Horario</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-calendar-alt"></i> Horarios</a>
                <a href="ver.php?id=<?php echo $id_horario; ?>" class="active"><i class="fas fa-eye"></i> Ver Horario</a>
            </div>
        </div>
        
        <div class="info-grid">
            <!-- Información del Horario -->
            <div class="info-card">
                <h3><i class="fas fa-calendar-alt"></i> Información del Horario</h3>
                
                <div class="info-item">
                    <div class="info-label">Curso:</div>
                    <div class="info-value">
                        <strong><?php echo htmlspecialchars($horario['nombre_curso']); ?></strong>
                        <div style="font-size: 13px; color: var(--gray-600); margin-top: 3px;">
                            <?php echo $horario['codigo_curso']; ?> | 
                            Sem <?php echo $horario['semestre_curso']; ?> | 
                            <?php echo $horario['creditos']; ?> créditos
                        </div>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Horario:</div>
                    <div class="info-value">
                        <strong><?php echo $horario['dia_semana']; ?></strong><br>
                        <?php echo Funciones::formatearHora($horario['hora_inicio']); ?> - 
                        <?php echo Funciones::formatearHora($horario['hora_fin']); ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Profesor:</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($horario['profesor_nombre']); ?>
                        <div style="font-size: 13px; color: var(--gray-600); margin-top: 3px;">
                            <?php echo htmlspecialchars($horario['especialidad']); ?> | 
                            <?php echo $horario['codigo_profesor']; ?>
                        </div>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Aula:</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($horario['codigo_aula']); ?> - 
                        <?php echo htmlspecialchars($horario['nombre_aula']); ?>
                        <div style="font-size: 13px; color: var(--gray-600); margin-top: 3px;">
                            <?php echo $horario['capacidad']; ?> estudiantes | 
                            <?php echo ucfirst($horario['tipo_aula']); ?> |
                            <?php echo $horario['edificio'] ? 'Edificio ' . $horario['edificio'] : ''; ?>
                            <?php echo $horario['piso'] ? 'Piso ' . $horario['piso'] : ''; ?>
                        </div>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Tipo de Clase:</div>
                    <div class="info-value">
                        <span class="badge badge-info">
                            <?php echo ucfirst($horario['tipo_clase']); ?>
                        </span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Grupo:</div>
                    <div class="info-value">
                        <?php echo $horario['grupo'] ?: 'Único'; ?>
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
                
                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <a href="editar.php?id=<?php echo $id_horario; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Editar
                    </a>
                    <a href="eliminar.php?id=<?php echo $id_horario; ?>" class="btn btn-danger"
                       onclick="return confirm('¿Estás seguro de eliminar este horario?')">
                        <i class="fas fa-trash"></i> Eliminar
                    </a>
                </div>
            </div>
            
            <!-- Información del Curso -->
            <div class="info-card">
                <h3><i class="fas fa-book"></i> Información del Curso</h3>
                
                <div class="info-item">
                    <div class="info-label">Programa:</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($horario['nombre_programa']); ?>
                        <div style="font-size: 13px; color: var(--gray-600); margin-top: 3px;">
                            <?php echo $horario['codigo_programa']; ?>
                        </div>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Semestre:</div>
                    <div class="info-value">
                        Semestre <?php echo $horario['semestre_curso']; ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Créditos:</div>
                    <div class="info-value">
                        <?php echo $horario['creditos']; ?> créditos
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Horas/Semana:</div>
                    <div class="info-value">
                        <?php echo $horario['horas_semanales']; ?> horas
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Semestre Académico:</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($horario['nombre_semestre']); ?>
                        <div style="font-size: 13px; color: var(--gray-600); margin-top: 3px;">
                            <?php echo $horario['codigo_semestre']; ?> | 
                            <?php echo Funciones::formatearFecha($horario['fecha_inicio']); ?> - 
                            <?php echo Funciones::formatearFecha($horario['fecha_fin']); ?>
                        </div>
                    </div>
                </div>
                
                <?php if ($horario['descripcion_curso']): ?>
                <div class="info-item" style="border-bottom: none;">
                    <div class="info-label">Descripción:</div>
                    <div class="info-value" style="font-size: 14px; line-height: 1.5;">
                        <?php echo nl2br(htmlspecialchars($horario['descripcion_curso'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div style="margin-top: 20px;">
                    <a href="../cursos/ver.php?id=<?php 
                        // Necesitamos obtener el id_curso
                        $stmt = $db->prepare("SELECT id_curso FROM horarios WHERE id_horario = ?");
                        $stmt->execute([$id_horario]);
                        $curso_id = $stmt->fetch()['id_curso'];
                        echo $curso_id;
                    ?>" class="btn btn-success">
                        <i class="fas fa-external-link-alt"></i> Ver Curso Completo
                    </a>
                </div>
            </div>
            
            <!-- Ocupación del Grupo -->
            <div class="info-card">
                <h3><i class="fas fa-users"></i> Ocupación del Grupo</h3>
                
                <div class="info-item">
                    <div class="info-label">Capacidad:</div>
                    <div class="info-value">
                        <strong><?php echo $horario['capacidad_grupo']; ?> estudiantes</strong>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Inscritos:</div>
                    <div class="info-value">
                        <strong style="color: var(--primary);"><?php echo $capacidad_ocupada; ?> estudiantes</strong>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Disponibles:</div>
                    <div class="info-value">
                        <strong style="color: <?php echo $capacidad_disponible > 0 ? 'var(--success)' : 'var(--danger)'; ?>">
                            <?php echo $capacidad_disponible; ?> cupos
                        </strong>
                    </div>
                </div>
                
                <div class="progress-bar-container">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo min($porcentaje_ocupacion, 100); ?>%;"></div>
                    </div>
                    <div class="progress-info">
                        <span>0%</span>
                        <span><?php echo round($porcentaje_ocupacion); ?>% de ocupación</span>
                        <span>100%</span>
                    </div>
                </div>
                
                <div style="margin-top: 20px;">
                    <a href="../inscripciones/crear.php?horario_id=<?php echo $id_horario; ?>" 
                       class="btn btn-primary" <?php echo $capacidad_disponible <= 0 ? 'disabled' : ''; ?>>
                        <i class="fas fa-user-plus"></i> Inscribir Estudiante
                    </a>
                    <?php if ($capacidad_disponible <= 0): ?>
                        <p style="color: var(--danger); font-size: 13px; margin-top: 10px;">
                            <i class="fas fa-exclamation-triangle"></i> Grupo lleno
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Estudiantes Inscritos -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-user-graduate"></i> Estudiantes Inscritos</h2>
                <span class="badge badge-info"><?php echo count($estudiantes); ?> estudiantes</span>
            </div>
            
            <?php if (count($estudiantes) > 0): ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Estudiante</th>
                                <th>Documento</th>
                                <th>Programa</th>
                                <th>Semestre</th>
                                <th>Fecha Inscripción</th>
                                <th>Estado</th>
                                <th>Nota</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($estudiantes as $estudiante): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($estudiante['codigo_estudiante']); ?></strong></td>
                                <td><?php echo htmlspecialchars($estudiante['nombres'] . ' ' . $estudiante['apellidos']); ?></td>
                                <td><?php echo htmlspecialchars($estudiante['documento_identidad']); ?></td>
                                <td>
                                    <?php 
                                    // Obtener nombre del programa
                                    $stmt_programa = $db->prepare("SELECT nombre_programa FROM programas_estudio WHERE id_programa = ?");
                                    $stmt_programa->execute([$estudiante['id_programa']]);
                                    $programa = $stmt_programa->fetch();
                                    echo htmlspecialchars($programa['nombre_programa'] ?? 'N/A');
                                    ?>
                                </td>
                                <td><?php echo $estudiante['semestre_actual']; ?></td>
                                <td><?php echo Funciones::formatearFecha($estudiante['fecha_inscripcion']); ?></td>
                                <td>
                                    <?php 
                                    $badge_class = '';
                                    switch($estudiante['estado']) {
                                        case 'inscrito': $badge_class = 'badge-inscrito'; break;
                                        case 'aprobado': $badge_class = 'badge-aprobado'; break;
                                        case 'reprobado': $badge_class = 'badge-reprobado'; break;
                                        case 'retirado': $badge_class = 'badge-retirado'; break;
                                    }
                                    ?>
                                    <span class="badge-estado <?php echo $badge_class; ?>">
                                        <?php echo ucfirst($estudiante['estado']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($estudiante['nota_final'] !== null): ?>
                                        <span class="nota <?php echo $estudiante['nota_final'] >= 10.5 ? 'nota-aprobado' : 'nota-reprobado'; ?>">
                                            <?php echo number_format($estudiante['nota_final'], 1); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--gray-500);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="../estudiantes/ver.php?id=<?php echo $estudiante['id_estudiante']; ?>" 
                                       class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">
                                        <i class="fas fa-eye"></i> Ver
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-user-graduate"></i>
                    <p>No hay estudiantes inscritos en este horario.</p>
                    <a href="../inscripciones/crear.php?horario_id=<?php echo $id_horario; ?>" class="btn btn-primary" style="margin-top: 15px;">
                        <i class="fas fa-user-plus"></i> Inscribir Primer Estudiante
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Historial de Cambios -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-history"></i> Historial de Cambios</h2>
                <span class="badge badge-info"><?php echo count($cambios); ?> cambios</span>
            </div>
            
            <?php if (count($cambios) > 0): ?>
                <div style="max-height: 300px; overflow-y: auto; padding: 10px;">
                    <?php foreach ($cambios as $cambio): ?>
                        <div class="cambio-item">
                            <div class="cambio-header">
                                <span class="cambio-tipo"><?php echo ucfirst($cambio['tipo_cambio']); ?></span>
                                <span class="cambio-fecha">
                                    <?php echo Funciones::formatearFecha($cambio['fecha_cambio'], 'd/m/Y H:i'); ?> 
                                    por <?php echo htmlspecialchars($cambio['realizado_por_nombre'] ?? 'Sistema'); ?>
                                </span>
                            </div>
                            <div class="cambio-details">
                                <strong>Anterior:</strong> <?php echo htmlspecialchars($cambio['valor_anterior'] ?: 'N/A'); ?><br>
                                <strong>Nuevo:</strong> <?php echo htmlspecialchars($cambio['valor_nuevo'] ?: 'N/A'); ?>
                                <?php if ($cambio['motivo']): ?>
                                    <br><strong>Motivo:</strong> <?php echo htmlspecialchars($cambio['motivo']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <p>No hay cambios registrados para este horario.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div style="display: flex; gap: 15px; margin-top: 20px;">
            <a href="index.php" class="btn">
                <i class="fas fa-arrow-left"></i> Volver a la lista
            </a>
            <a href="editar.php?id=<?php echo $id_horario; ?>" class="btn btn-primary">
                <i class="fas fa-edit"></i> Editar Horario
            </a>
            <a href="../inscripciones/crear.php?horario_id=<?php echo $id_horario; ?>" class="btn btn-success">
                <i class="fas fa-user-plus"></i> Inscribir Estudiante
            </a>
        </div>
    </div>
    
    <script src="../../assets/js/main.js"></script>
</body>
</html>