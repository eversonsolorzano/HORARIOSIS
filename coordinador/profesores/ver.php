<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

$id_profesor = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_profesor) {
    Funciones::redireccionar('index.php', 'ID de profesor no válido', 'error');
}

// Obtener datos completos del profesor
$stmt = $db->prepare("
    SELECT p.*, u.email, u.username, u.fecha_creacion
    FROM profesores p
    JOIN usuarios u ON p.id_usuario = u.id_usuario
    WHERE p.id_profesor = ?
");
$stmt->execute([$id_profesor]);
$profesor = $stmt->fetch();

if (!$profesor) {
    Funciones::redireccionar('index.php', 'Profesor no encontrado', 'error');
}

// Obtener programas asignados
$programas_arr = explode(',', $profesor['programas_dictados']);
$programas_arr = array_map('trim', $programas_arr);

// Obtener horarios del profesor
$stmt = $db->prepare("
    SELECT h.*, c.nombre_curso, c.codigo_curso, a.nombre_aula, 
           s.codigo_semestre, s.nombre_semestre
    FROM horarios h
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN aulas a ON h.id_aula = a.id_aula
    JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
    WHERE h.id_profesor = ? AND h.activo = 1
    ORDER BY h.dia_semana, h.hora_inicio
");
$stmt->execute([$id_profesor]);
$horarios = $stmt->fetchAll();

// Obtener estadísticas
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT h.id_curso) as total_cursos,
        COUNT(*) as total_horarios,
        SUM(TIME_TO_SEC(TIMEDIFF(h.hora_fin, h.hora_inicio)) / 3600) as horas_semanales
    FROM horarios h
    WHERE h.id_profesor = ? AND h.activo = 1
");
$stmt->execute([$id_profesor]);
$estadisticas = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ver Profesor - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .profile-header {
            display: flex;
            align-items: center;
            gap: 30px;
            margin-bottom: 30px;
            padding: 25px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: var(--radius-xl);
            color: white;
            box-shadow: var(--shadow-lg);
        }
        
        .profile-icon {
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 40px;
            border: 3px solid rgba(255, 255, 255, 0.2);
        }
        
        .profile-info {
            flex: 1;
        }
        
        .profile-info h2 {
            color: white;
            margin: 0 0 10px 0;
            font-size: 28px;
        }
        
        .profile-info p {
            margin: 5px 0;
            opacity: 0.9;
        }
        
        .profile-actions {
            display: flex;
            gap: 10px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            text-align: center;
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-card .icon {
            font-size: 32px;
            margin-bottom: 10px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-card .number {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary);
            margin: 10px 0;
        }
        
        .stat-card .label {
            color: var(--gray-600);
            font-size: 14px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .info-section {
            background: white;
            padding: 25px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
        }
        
        .info-section h3 {
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
            margin-bottom: 15px;
            align-items: flex-start;
        }
        
        .info-label {
            width: 150px;
            font-weight: 600;
            color: var(--gray-700);
            font-size: 14px;
        }
        
        .info-value {
            flex: 1;
            color: var(--gray-800);
            font-size: 14px;
        }
        
        .programas-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        
        .programa-tag {
            background: linear-gradient(135deg, var(--success-light) 0%, var(--success) 100%);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .horarios-table {
            margin-top: 20px;
        }
        
        .horarios-table th {
            background: var(--gray-50);
        }
        
        .day-badge {
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .day-lunes { background: #fee2e2; color: #991b1b; }
        .day-martes { background: #fef3c7; color: #92400e; }
        .day-miércoles { background: #d1fae5; color: #065f46; }
        .day-jueves { background: #dbeafe; color: #1e40af; }
        .day-viernes { background: #ede9fe; color: #5b21b6; }
        .day-sábado { background: #fce7f3; color: #9d174d; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-chalkboard-teacher"></i> Detalles del Profesor</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
                <a href="ver.php?id=<?php echo $id_profesor; ?>" class="active"><i class="fas fa-eye"></i> Ver</a>
                <a href="editar.php?id=<?php echo $id_profesor; ?>" class="btn-primary">
                    <i class="fas fa-edit"></i> Editar
                </a>
            </div>
        </div>
        
        <!-- Perfil del profesor -->
        <div class="profile-header">
            <div class="profile-icon">
                <i class="fas fa-chalkboard-teacher"></i>
            </div>
            <div class="profile-info">
                <h2><?php echo htmlspecialchars($profesor['nombres'] . ' ' . $profesor['apellidos']); ?></h2>
                <p><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($profesor['titulo_academico']); ?></p>
                <p><i class="fas fa-tools"></i> <?php echo htmlspecialchars($profesor['especialidad']); ?></p>
                <p><i class="fas fa-id-card"></i> <?php echo $profesor['codigo_profesor']; ?> | 
                   <i class="fas fa-id-card"></i> <?php echo $profesor['documento_identidad']; ?></p>
            </div>
            <div class="profile-actions">
                <a href="editar.php?id=<?php echo $id_profesor; ?>" class="btn btn-warning">
                    <i class="fas fa-edit"></i> Editar
                </a>
                <a href="cambiar_estado.php?id=<?php echo $id_profesor; ?>" 
                   class="btn <?php echo $profesor['activo'] ? 'btn-danger' : 'btn-success'; ?>">
                    <i class="fas fa-<?php echo $profesor['activo'] ? 'times' : 'check'; ?>"></i>
                    <?php echo $profesor['activo'] ? 'Desactivar' : 'Activar'; ?>
                </a>
            </div>
        </div>
        
        <!-- Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon"><i class="fas fa-book"></i></div>
                <div class="number"><?php echo $estadisticas['total_cursos'] ?? 0; ?></div>
                <div class="label">Cursos Activos</div>
            </div>
            
            <div class="stat-card">
                <div class="icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="number"><?php echo $estadisticas['total_horarios'] ?? 0; ?></div>
                <div class="label">Horarios Semanales</div>
            </div>
            
            <div class="stat-card">
                <div class="icon"><i class="fas fa-clock"></i></div>
                <div class="number"><?php echo number_format($estadisticas['horas_semanales'] ?? 0, 1); ?></div>
                <div class="label">Horas por Semana</div>
            </div>
            
            <div class="stat-card">
                <div class="icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="number"><?php echo count($programas_arr); ?></div>
                <div class="label">Programas Asignados</div>
            </div>
        </div>
        
        <!-- Información detallada -->
        <div class="info-grid">
            <!-- Información Personal -->
            <div class="info-section">
                <h3><i class="fas fa-user-circle"></i> Información Personal</h3>
                
                <div class="info-item">
                    <div class="info-label">Código:</div>
                    <div class="info-value"><?php echo $profesor['codigo_profesor']; ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Documento:</div>
                    <div class="info-value"><?php echo $profesor['documento_identidad']; ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Nombres:</div>
                    <div class="info-value"><?php echo htmlspecialchars($profesor['nombres']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Apellidos:</div>
                    <div class="info-value"><?php echo htmlspecialchars($profesor['apellidos']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Título:</div>
                    <div class="info-value"><?php echo htmlspecialchars($profesor['titulo_academico']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Especialidad:</div>
                    <div class="info-value"><?php echo htmlspecialchars($profesor['especialidad']); ?></div>
                </div>
            </div>
            
            <!-- Información de Contacto -->
            <div class="info-section">
                <h3><i class="fas fa-address-card"></i> Contacto</h3>
                
                <div class="info-item">
                    <div class="info-label">Teléfono:</div>
                    <div class="info-value">
                        <?php echo $profesor['telefono'] ? htmlspecialchars($profesor['telefono']) : '<span class="text-muted">No especificado</span>'; ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Email Institucional:</div>
                    <div class="info-value">
                        <a href="mailto:<?php echo $profesor['email_institucional']; ?>">
                            <?php echo $profesor['email_institucional']; ?>
                        </a>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Email Personal:</div>
                    <div class="info-value">
                        <a href="mailto:<?php echo $profesor['email']; ?>">
                            <?php echo $profesor['email']; ?>
                        </a>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Usuario:</div>
                    <div class="info-value"><?php echo $profesor['username']; ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Estado:</div>
                    <div class="info-value">
                        <span class="badge <?php echo $profesor['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                            <?php echo $profesor['activo'] ? 'Activo' : 'Inactivo'; ?>
                        </span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Fecha Registro:</div>
                    <div class="info-value"><?php echo Funciones::formatearFecha($profesor['fecha_creacion'], 'd/m/Y H:i'); ?></div>
                </div>
            </div>
            
            <!-- Programas Asignados -->
            <div class="info-section">
                <h3><i class="fas fa-graduation-cap"></i> Programas Asignados</h3>
                
                <?php if (!empty($programas_arr[0])): ?>
                    <div class="programas-list">
                        <?php foreach ($programas_arr as $programa): ?>
                            <?php if (!empty(trim($programa))): ?>
                                <span class="programa-tag">
                                    <i class="fas fa-book"></i> <?php echo trim($programa); ?>
                                </span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No tiene programas asignados</p>
                    <a href="asignar_programas.php?id=<?php echo $id_profesor; ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-plus"></i> Asignar Programas
                    </a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Horarios del Profesor -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-calendar-alt"></i> Horarios Asignados</h3>
            </div>
            
            <div class="card-body">
                <?php if (!empty($horarios)): ?>
                    <div class="table-responsive">
                        <table class="table horarios-table">
                            <thead>
                                <tr>
                                    <th>Día</th>
                                    <th>Horario</th>
                                    <th>Curso</th>
                                    <th>Aula</th>
                                    <th>Semestre</th>
                                    <th>Tipo</th>
                                    <th>Grupo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($horarios as $horario): ?>
                                <tr>
                                    <td>
                                        <span class="day-badge day-<?php echo strtolower($horario['dia_semana']); ?>">
                                            <?php echo $horario['dia_semana']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo Funciones::formatearHora($horario['hora_inicio']); ?> - 
                                               <?php echo Funciones::formatearHora($horario['hora_fin']); ?></strong>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($horario['nombre_curso']); ?></strong><br>
                                        <small class="text-muted"><?php echo $horario['codigo_curso']; ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($horario['nombre_aula']); ?></td>
                                    <td><?php echo $horario['codigo_semestre']; ?></td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo ucfirst($horario['tipo_clase']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($horario['grupo']): ?>
                                            <span class="badge badge-secondary"><?php echo $horario['grupo']; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <p>El profesor no tiene horarios asignados</p>
                        <a href="../horarios/crear.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Crear Horario
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Acciones adicionales -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-cogs"></i> Acciones</h3>
            </div>
            <div class="card-body">
                <div class="actions">
                    <a href="editar.php?id=<?php echo $id_profesor; ?>" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Editar Profesor
                    </a>
                    <a href="asignar_programas.php?id=<?php echo $id_profesor; ?>" class="btn btn-info">
                        <i class="fas fa-graduation-cap"></i> Gestionar Programas
                    </a>
                    <a href="cambiar_estado.php?id=<?php echo $id_profesor; ?>" 
                       class="btn <?php echo $profesor['activo'] ? 'btn-danger' : 'btn-success'; ?>">
                        <i class="fas fa-<?php echo $profesor['activo'] ? 'times' : 'check'; ?>"></i>
                        <?php echo $profesor['activo'] ? 'Desactivar Profesor' : 'Activar Profesor'; ?>
                    </a>
                    <a href="../horarios/crear.php?profesor=<?php echo $id_profesor; ?>" class="btn btn-primary">
                        <i class="fas fa-calendar-plus"></i> Asignar Horario
                    </a>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Volver a Lista
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>