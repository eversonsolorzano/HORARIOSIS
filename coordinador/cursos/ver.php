<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

// Obtener ID del curso
$id_curso = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_curso) {
    Funciones::redireccionar('index.php', 'ID de curso no válido', 'error');
}

// Obtener datos del curso
$stmt = $db->prepare("
    SELECT c.*, p.nombre_programa, p.duracion_semestres 
    FROM cursos c 
    JOIN programas_estudio p ON c.id_programa = p.id_programa 
    WHERE c.id_curso = ?
");
$stmt->execute([$id_curso]);
$curso = $stmt->fetch();

if (!$curso) {
    Funciones::redireccionar('index.php', 'Curso no encontrado', 'error');
}

// Obtener prerrequisitos
$prerrequisitos = [];
if ($curso['prerrequisitos']) {
    $ids_prerrequisitos = explode(',', $curso['prerrequisitos']);
    if (!empty($ids_prerrequisitos)) {
        $placeholders = str_repeat('?,', count($ids_prerrequisitos) - 1) . '?';
        $stmt = $db->prepare("
            SELECT c.*, p.nombre_programa 
            FROM cursos c 
            JOIN programas_estudio p ON c.id_programa = p.id_programa 
            WHERE c.id_curso IN ($placeholders)
            ORDER BY c.semestre, c.nombre_curso
        ");
        $stmt->execute($ids_prerrequisitos);
        $prerrequisitos = $stmt->fetchAll();
    }
}

// Obtener horarios del curso
$stmt = $db->prepare("
    SELECT h.*, a.codigo_aula, a.nombre_aula, 
           CONCAT(p.nombres, ' ', p.apellidos) as profesor,
           COUNT(DISTINCT i.id_estudiante) as estudiantes_inscritos
    FROM horarios h
    JOIN aulas a ON h.id_aula = a.id_aula
    JOIN profesores p ON h.id_profesor = p.id_profesor
    LEFT JOIN inscripciones i ON h.id_horario = i.id_horario AND i.estado = 'inscrito'
    WHERE h.id_curso = ? AND h.activo = 1
    GROUP BY h.id_horario
    ORDER BY 
        CASE h.dia_semana 
            WHEN 'Lunes' THEN 1
            WHEN 'Martes' THEN 2
            WHEN 'Miércoles' THEN 3
            WHEN 'Jueves' THEN 4
            WHEN 'Viernes' THEN 5
            WHEN 'Sábado' THEN 6
        END,
        h.hora_inicio
");
$stmt->execute([$id_curso]);
$horarios = $stmt->fetchAll();

// Obtener estudiantes inscritos
$stmt = $db->prepare("
    SELECT e.*, i.estado, i.nota_final
    FROM inscripciones i
    JOIN estudiantes e ON i.id_estudiante = e.id_estudiante
    JOIN horarios h ON i.id_horario = h.id_horario
    WHERE h.id_curso = ? AND i.estado = 'inscrito'
    ORDER BY e.apellidos, e.nombres
");
$stmt->execute([$id_curso]);
$estudiantes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ver Curso - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-eye"></i> Detalles del Curso</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-book"></i> Cursos</a>
                <a href="ver.php?id=<?php echo $id_curso; ?>" class="active"><i class="fas fa-eye"></i> Ver Curso</a>
            </div>
        </div>
        
        <div class="grid-2">
            <!-- Información del Curso -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-info-circle"></i> Información General</h2>
                    <div>
                        <a href="editar.php?id=<?php echo $id_curso; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Editar
                        </a>
                        <a href="gestionar_prerrequisitos.php?id=<?php echo $id_curso; ?>" class="btn btn-success">
                            <i class="fas fa-link"></i> Prerreq.
                        </a>
                    </div>
                </div>
                
                <div style="padding: 20px 0;">
                    <h2 style="color: var(--dark); margin-bottom: 10px;">
                        <?php echo htmlspecialchars($curso['nombre_curso']); ?>
                    </h2>
                    <p style="color: var(--gray-600); margin-bottom: 20px;">
                        <strong>Código:</strong> <?php echo htmlspecialchars($curso['codigo_curso']); ?> |
                        <strong>Programa:</strong> <?php echo htmlspecialchars($curso['nombre_programa']); ?>
                    </p>
                    
                    <?php if ($curso['descripcion']): ?>
                        <div style="background: var(--gray-50); padding: 15px; border-radius: var(--radius); margin-bottom: 20px;">
                            <strong style="color: var(--gray-700);">Descripción:</strong>
                            <p style="color: var(--gray-600); margin-top: 5px;"><?php echo nl2br(htmlspecialchars($curso['descripcion'])); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="grid-2">
                        <div class="info-item">
                            <strong>Tipo de Curso:</strong> 
                            <span class="badge <?php 
                                switch($curso['tipo_curso']) {
                                    case 'obligatorio': echo 'badge-info'; break;
                                    case 'electivo': echo 'badge-success'; break;
                                    case 'taller': echo 'badge-warning'; break;
                                    case 'laboratorio': echo 'badge-primary'; break;
                                }
                            ?>">
                                <?php echo ucfirst($curso['tipo_curso']); ?>
                            </span>
                        </div>
                        
                        <div class="info-item">
                            <strong>Semestre:</strong> 
                            <span>Semestre <?php echo $curso['semestre']; ?></span>
                        </div>
                        
                        <div class="info-item">
                            <strong>Créditos:</strong> 
                            <span><?php echo $curso['creditos']; ?></span>
                        </div>
                        
                        <div class="info-item">
                            <strong>Horas Semanales:</strong> 
                            <span><?php echo $curso['horas_semanales']; ?> horas</span>
                        </div>
                        
                        <div class="info-item">
                            <strong>Estado:</strong> 
                            <span class="badge <?php echo $curso['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                                <?php echo $curso['activo'] ? 'Activo' : 'Inactivo'; ?>
                            </span>
                        </div>
                        
                        <div class="info-item">
                            <strong>Estudiantes Inscritos:</strong> 
                            <span><?php echo count($estudiantes); ?> estudiantes</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Prerrequisitos -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-link"></i> Prerrequisitos</h2>
                    <span class="badge badge-info"><?php echo count($prerrequisitos); ?> cursos</span>
                </div>
                
                <?php if (count($prerrequisitos) > 0): ?>
                    <div style="max-height: 300px; overflow-y: auto; padding: 10px;">
                        <?php foreach ($prerrequisitos as $prerrequisito): ?>
                            <div class="prerrequisito-card">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="width: 40px; height: 40px; border-radius: 8px; 
                                                background: linear-gradient(135deg, var(--gray-200) 0%, var(--gray-300) 100%);
                                                display: flex; align-items: center; justify-content: center;">
                                        <i class="fas fa-book" style="color: var(--gray-600);"></i>
                                    </div>
                                    <div>
                                        <strong><?php echo htmlspecialchars($prerrequisito['nombre_curso']); ?></strong>
                                        <p style="color: var(--gray-500); font-size: 12px; margin-top: 2px;">
                                            <?php echo $prerrequisito['codigo_curso']; ?> | 
                                            Sem <?php echo $prerrequisito['semestre']; ?> | 
                                            <?php echo htmlspecialchars($prerrequisito['nombre_programa']); ?>
                                        </p>
                                    </div>
                                </div>
                                <a href="ver.php?id=<?php echo $prerrequisito['id_curso']; ?>" 
                                   class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">
                                    <i class="fas fa-eye"></i> Ver
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-unlink"></i>
                        <p>Este curso no tiene prerrequisitos.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Horarios -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-calendar-alt"></i> Horarios del Curso</h2>
                <a href="../horarios/crear.php?curso_id=<?php echo $id_curso; ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Nuevo Horario
                </a>
            </div>
            
            <?php if (count($horarios) > 0): ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Día</th>
                                <th>Horario</th>
                                <th>Profesor</th>
                                <th>Aula</th>
                                <th>Tipo</th>
                                <th>Grupo</th>
                                <th>Estudiantes</th>
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
                                <td><?php echo htmlspecialchars($horario['profesor']); ?></td>
                                <td><?php echo htmlspecialchars($horario['codigo_aula']); ?> (<?php echo htmlspecialchars($horario['nombre_aula']); ?>)</td>
                                <td>
                                    <span class="badge badge-info">
                                        <?php echo ucfirst($horario['tipo_clase']); ?>
                                    </span>
                                </td>
                                <td><?php echo $horario['grupo'] ?: 'Único'; ?></td>
                                <td><?php echo $horario['estudiantes_inscritos']; ?> estudiantes</td>
                                <td>
                                    <div class="actions">
                                        <a href="../horarios/editar.php?id=<?php echo $horario['id_horario']; ?>" 
                                           class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="../horarios/ver.php?id=<?php echo $horario['id_horario']; ?>" 
                                           class="btn btn-success" style="padding: 5px 10px; font-size: 12px;">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <p>Este curso no tiene horarios asignados.</p>
                    <a href="../horarios/crear.php?curso_id=<?php echo $id_curso; ?>" class="btn btn-primary" style="margin-top: 15px;">
                        <i class="fas fa-plus"></i> Crear Primer Horario
                    </a>
                </div>
            <?php endif; ?>
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
                                <td><?php 
                                    // Obtener nombre del programa
                                    $stmt_programa = $db->prepare("SELECT nombre_programa FROM programas_estudio WHERE id_programa = ?");
                                    $stmt_programa->execute([$estudiante['id_programa']]);
                                    $programa = $stmt_programa->fetch();
                                    echo htmlspecialchars($programa['nombre_programa'] ?? 'N/A');
                                ?></td>
                                <td><?php echo $estudiante['semestre_actual']; ?></td>
                                <td>
                                    <span class="badge <?php 
                                        switch($estudiante['estado']) {
                                            case 'activo': echo 'badge-success'; break;
                                            case 'inactivo': echo 'badge-inactive'; break;
                                            case 'graduado': echo 'badge-info'; break;
                                            case 'retirado': echo 'badge-warning'; break;
                                        }
                                    ?>">
                                        <?php echo ucfirst($estudiante['estado']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($estudiante['nota_final'] !== null): ?>
                                        <strong style="color: <?php echo $estudiante['nota_final'] >= 10.5 ? 'var(--success)' : 'var(--danger)'; ?>">
                                            <?php echo number_format($estudiante['nota_final'], 1); ?>
                                        </strong>
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
                    <p>No hay estudiantes inscritos en este curso.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div style="display: flex; gap: 15px; margin-top: 20px;">
            <a href="index.php" class="btn">
                <i class="fas fa-arrow-left"></i> Volver a la lista
            </a>
            <a href="editar.php?id=<?php echo $id_curso; ?>" class="btn btn-primary">
                <i class="fas fa-edit"></i> Editar Curso
            </a>
            <a href="../horarios/crear.php?curso_id=<?php echo $id_curso; ?>" class="btn btn-success">
                <i class="fas fa-plus"></i> Agregar Horario
            </a>
        </div>
    </div>
    
    <style>
    .prerrequisito-card {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 15px;
        border-bottom: 1px solid var(--gray-200);
        transition: background-color 0.2s ease;
    }
    
    .prerrequisito-card:hover {
        background: var(--gray-50);
    }
    
    .prerrequisito-card:last-child {
        border-bottom: none;
    }
    
    .info-item {
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--gray-100);
    }
    
    .info-item strong {
        display: block;
        color: var(--gray-700);
        font-size: 13px;
        margin-bottom: 5px;
    }
    
    .info-item span {
        color: var(--dark);
        font-size: 14px;
    }
    </style>
    
    <script src="../../assets/js/main.js"></script>
</body>
</html>