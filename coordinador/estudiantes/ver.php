<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

// Obtener ID del estudiante
$id_estudiante = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_estudiante) {
    Funciones::redireccionar('index.php', 'ID de estudiante no válido', 'error');
}

// Obtener datos del estudiante con información del programa
$stmt = $db->prepare("
    SELECT e.*, p.nombre_programa, p.duracion_semestres, u.email, u.username, u.activo
    FROM estudiantes e
    JOIN programas_estudio p ON e.id_programa = p.id_programa
    JOIN usuarios u ON e.id_usuario = u.id_usuario
    WHERE e.id_estudiante = ?
");
$stmt->execute([$id_estudiante]);
$estudiante = $stmt->fetch();

if (!$estudiante) {
    Funciones::redireccionar('index.php', 'Estudiante no encontrado', 'error');
}

// Obtener cursos inscritos
$stmt = $db->prepare("
    SELECT c.nombre_curso, c.codigo_curso, c.creditos, h.dia_semana, 
           h.hora_inicio, h.hora_fin, a.codigo_aula, 
           CONCAT(pr.nombres, ' ', pr.apellidos) as profesor,
           i.estado, i.nota_final
    FROM inscripciones i
    JOIN horarios h ON i.id_horario = h.id_horario
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN aulas a ON h.id_aula = a.id_aula
    JOIN profesores pr ON h.id_profesor = pr.id_profesor
    WHERE i.id_estudiante = ?
    ORDER BY i.fecha_inscripcion DESC
");
$stmt->execute([$id_estudiante]);
$cursos_inscritos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ver Estudiante - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-graduate"></i> Detalles del Estudiante</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-user-graduate"></i> Estudiantes</a>
                <a href="ver.php?id=<?php echo $id_estudiante; ?>" class="active"><i class="fas fa-eye"></i> Ver Estudiante</a>
            </div>
        </div>
        
        <div class="grid-2">
            <!-- Información Personal -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-user"></i> Información Personal</h2>
                    <a href="editar.php?id=<?php echo $id_estudiante; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Editar
                    </a>
                </div>
                
                <div style="padding: 20px 0;">
                    <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 30px;">
                        <div style="width: 100px; height: 100px; border-radius: 50%; 
                                    background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
                                    display: flex; align-items: center; justify-content: center; color: white; font-size: 40px;">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div>
                            <h2 style="color: var(--dark); margin-bottom: 5px;">
                                <?php echo htmlspecialchars($estudiante['nombres'] . ' ' . $estudiante['apellidos']); ?>
                            </h2>
                            <p style="color: var(--gray-600);">
                                <strong>Código:</strong> <?php echo htmlspecialchars($estudiante['codigo_estudiante']); ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="grid-2">
                        <div class="info-item">
                            <strong>Documento:</strong> 
                            <span><?php echo htmlspecialchars($estudiante['documento_identidad']); ?></span>
                        </div>
                        
                        <div class="info-item">
                            <strong>Email:</strong> 
                            <span><?php echo htmlspecialchars($estudiante['email']); ?></span>
                        </div>
                        
                        <div class="info-item">
                            <strong>Teléfono:</strong> 
                            <span><?php echo htmlspecialchars($estudiante['telefono'] ?? 'No registrado'); ?></span>
                        </div>
                        
                        <div class="info-item">
                            <strong>Fecha Nacimiento:</strong> 
                            <span><?php echo $estudiante['fecha_nacimiento'] ? Funciones::formatearFecha($estudiante['fecha_nacimiento']) : 'No registrada'; ?></span>
                        </div>
                        
                        <div class="info-item">
                            <strong>Género:</strong> 
                            <span><?php 
                                switch($estudiante['genero']) {
                                    case 'M': echo 'Masculino'; break;
                                    case 'F': echo 'Femenino'; break;
                                    case 'Otro': echo 'Otro'; break;
                                    default: echo 'No registrado';
                                }
                            ?></span>
                        </div>
                        
                        <div class="info-item">
                            <strong>Estado:</strong> 
                            <span class="badge <?php 
                                switch($estudiante['estado']) {
                                    case 'activo': echo 'badge-success'; break;
                                    case 'inactivo': echo 'badge-inactive'; break;
                                    case 'graduado': echo 'badge-info'; break;
                                    case 'retirado': echo 'badge-warning'; break;
                                }
                            ?>"><?php echo ucfirst($estudiante['estado']); ?></span>
                        </div>
                    </div>
                    
                    <?php if ($estudiante['direccion']): ?>
                    <div class="info-item" style="margin-top: 20px;">
                        <strong>Dirección:</strong> 
                        <p style="color: var(--gray-600); margin-top: 5px;"><?php echo nl2br(htmlspecialchars($estudiante['direccion'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Información Académica -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-graduation-cap"></i> Información Académica</h2>
                </div>
                
                <div style="padding: 20px 0;">
                    <div class="info-item">
                        <strong>Programa:</strong> 
                        <span><?php echo htmlspecialchars($estudiante['nombre_programa']); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <strong>Semestre Actual:</strong> 
                        <span>Semestre <?php echo $estudiante['semestre_actual']; ?> de <?php echo $estudiante['duracion_semestres']; ?></span>
                    </div>
                    
                    <div class="info-item">
                        <strong>Fecha de Ingreso:</strong> 
                        <span><?php echo Funciones::formatearFecha($estudiante['fecha_ingreso']); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <strong>Usuario:</strong> 
                        <span><?php echo htmlspecialchars($estudiante['username']); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <strong>Estado del Usuario:</strong> 
                        <span class="badge <?php echo $estudiante['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                            <?php echo $estudiante['activo'] ? 'Activo' : 'Inactivo'; ?>
                        </span>
                    </div>
                    
                    <!-- Progreso del semestre -->
                    <div style="margin-top: 30px;">
                        <strong>Progreso del Programa:</strong>
                        <div style="margin-top: 10px;">
                            <div style="height: 10px; background: var(--gray-200); border-radius: 5px; overflow: hidden;">
                                <div style="height: 100%; width: <?php echo ($estudiante['semestre_actual'] / $estudiante['duracion_semestres']) * 100; ?>%; 
                                     background: linear-gradient(90deg, var(--primary), var(--secondary));">
                                </div>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-top: 5px; color: var(--gray-600); font-size: 13px;">
                                <span>0%</span>
                                <span><?php echo round(($estudiante['semestre_actual'] / $estudiante['duracion_semestres']) * 100); ?>%</span>
                                <span>100%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Cursos Inscritos -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-book"></i> Cursos Inscritos</h2>
                <span class="badge badge-info"><?php echo count($cursos_inscritos); ?> cursos</span>
            </div>
            
            <?php if (count($cursos_inscritos) > 0): ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Curso</th>
                                <th>Código</th>
                                <th>Profesor</th>
                                <th>Horario</th>
                                <th>Aula</th>
                                <th>Créditos</th>
                                <th>Estado</th>
                                <th>Nota</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cursos_inscritos as $curso): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($curso['nombre_curso']); ?></td>
                                <td><?php echo $curso['codigo_curso']; ?></td>
                                <td><?php echo htmlspecialchars($curso['profesor']); ?></td>
                                <td>
                                    <?php echo $curso['dia_semana']; ?><br>
                                    <small><?php echo Funciones::formatearHora($curso['hora_inicio']); ?> - <?php echo Funciones::formatearHora($curso['hora_fin']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($curso['codigo_aula']); ?></td>
                                <td><?php echo $curso['creditos']; ?></td>
                                <td>
                                    <span class="badge <?php 
                                        switch($curso['estado']) {
                                            case 'inscrito': echo 'badge-info'; break;
                                            case 'aprobado': echo 'badge-success'; break;
                                            case 'reprobado': echo 'badge-warning'; break;
                                            case 'retirado': echo 'badge-inactive'; break;
                                        }
                                    ?>">
                                        <?php echo ucfirst($curso['estado']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($curso['nota_final'] !== null): ?>
                                        <strong style="color: <?php echo $curso['nota_final'] >= 10.5 ? 'var(--success)' : 'var(--danger)'; ?>">
                                            <?php echo number_format($curso['nota_final'], 1); ?>
                                        </strong>
                                    <?php else: ?>
                                        <span style="color: var(--gray-500);">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-book-open"></i>
                    <p>El estudiante no tiene cursos inscritos.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div style="display: flex; gap: 15px; margin-top: 20px;">
            <a href="index.php" class="btn">
                <i class="fas fa-arrow-left"></i> Volver a la lista
            </a>
            <a href="editar.php?id=<?php echo $id_estudiante; ?>" class="btn btn-primary">
                <i class="fas fa-edit"></i> Editar Estudiante
            </a>
        </div>
    </div>
    
    <style>
    .info-item {
        margin-bottom: 15px;
        padding-bottom: 15px;
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
        font-size: 15px;
    }
    </style>
    
    <script src="../../assets/js/main.js"></script>
</body>
</html>