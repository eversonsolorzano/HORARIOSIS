<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

// Obtener ID del aula
$id_aula = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_aula) {
    Funciones::redireccionar('index.php', 'ID de aula no válido', 'error');
}

// Obtener datos del aula
$stmt = $db->prepare("SELECT * FROM aulas WHERE id_aula = ?");
$stmt->execute([$id_aula]);
$aula = $stmt->fetch();

if (!$aula) {
    Funciones::redireccionar('index.php', 'Aula no encontrada', 'error');
}

// Obtener horarios actuales del aula
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
    WHERE h.id_aula = ? AND h.activo = 1
    ORDER BY 
        FIELD(h.dia_semana, 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'),
        h.hora_inicio
");
$stmt->execute([$id_aula]);
$horarios = $stmt->fetchAll();

// Contar horarios por día
$horarios_por_dia = [];
foreach ($horarios as $horario) {
    $dia = $horario['dia_semana'];
    if (!isset($horarios_por_dia[$dia])) {
        $horarios_por_dia[$dia] = 0;
    }
    $horarios_por_dia[$dia]++;
}

// Convertir programas permitidos a array
$programas_permitidos = !empty($aula['programas_permitidos']) ? explode(',', $aula['programas_permitidos']) : [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ver Aula - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .aula-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 25px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .aula-header h2 {
            margin: 0;
            font-size: 28px;
        }
        
        .aula-header .codigo {
            font-size: 18px;
            opacity: 0.9;
            margin-top: 5px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .info-card {
            background: white;
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        
        .info-card h3 {
            color: var(--dark);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-item {
            display: flex;
            margin-bottom: 12px;
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
        
        .badge-tipo {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .tipo-normal { background: #e3f2fd; color: #1976d2; }
        .tipo-laboratorio { background: #f3e5f5; color: #7b1fa2; }
        .tipo-taller { background: #e8f5e9; color: #388e3c; }
        .tipo-clinica { background: #fff3e0; color: #f57c00; }
        .tipo-aula_especial { background: #fce4ec; color: #c2185b; }
        
        .disponible-si { color: var(--success); }
        .disponible-no { color: var(--danger); }
        
        .programas-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        
        .horario-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .horario-item {
            background: white;
            padding: 15px;
            border-radius: var(--radius);
            border-left: 4px solid var(--primary);
            box-shadow: var(--shadow-sm);
        }
        
        .horario-dia {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .horario-curso {
            color: var(--gray-700);
            font-size: 14px;
            margin-bottom: 3px;
        }
        
        .horario-profesor {
            color: var(--gray-600);
            font-size: 13px;
            margin-bottom: 3px;
        }
        
        .horario-hora {
            color: var(--primary);
            font-weight: 600;
            font-size: 13px;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--gray-600);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .equipamiento-box {
            background: var(--gray-50);
            padding: 15px;
            border-radius: var(--radius);
            margin-top: 10px;
            white-space: pre-line;
        }
        
        .actions-bar {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-school"></i> Detalles del Aula</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-school"></i> Aulas</a>
                <a href="ver.php?id=<?php echo $id_aula; ?>" class="active"><i class="fas fa-eye"></i> Ver Aula</a>
                <a href="editar.php?id=<?php echo $id_aula; ?>"><i class="fas fa-edit"></i> Editar</a>
                <a href="disponibilidad.php?aula=<?php echo $id_aula; ?>"><i class="fas fa-calendar-alt"></i> Disponibilidad</a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <!-- Encabezado del Aula -->
        <div class="aula-header">
            <h2><?php echo htmlspecialchars($aula['nombre_aula']); ?></h2>
            <div class="codigo"><?php echo htmlspecialchars($aula['codigo_aula']); ?></div>
        </div>
        
        <!-- Información Principal -->
        <div class="info-grid">
            <!-- Información Básica -->
            <div class="info-card">
                <h3><i class="fas fa-info-circle"></i> Información Básica</h3>
                
                <div class="info-item">
                    <div class="info-label">Código:</div>
                    <div class="info-value"><?php echo htmlspecialchars($aula['codigo_aula']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Nombre:</div>
                    <div class="info-value"><?php echo htmlspecialchars($aula['nombre_aula']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Capacidad:</div>
                    <div class="info-value">
                        <strong><?php echo $aula['capacidad']; ?></strong> estudiantes
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Tipo:</div>
                    <div class="info-value">
                        <span class="badge-tipo tipo-<?php echo $aula['tipo_aula']; ?>">
                            <?php echo ucfirst($aula['tipo_aula']); ?>
                        </span>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Estado:</div>
                    <div class="info-value">
                        <?php if ($aula['disponible']): ?>
                            <span class="disponible-si">
                                <i class="fas fa-check-circle"></i> Disponible
                            </span>
                        <?php else: ?>
                            <span class="disponible-no">
                                <i class="fas fa-times-circle"></i> No Disponible
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Ubicación -->
            <div class="info-card">
                <h3><i class="fas fa-map-marker-alt"></i> Ubicación</h3>
                
                <div class="info-item">
                    <div class="info-label">Edificio:</div>
                    <div class="info-value">
                        <?php echo !empty($aula['edificio']) ? htmlspecialchars($aula['edificio']) : '<span class="text-muted">No especificado</span>'; ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Piso:</div>
                    <div class="info-value">
                        <?php echo !empty($aula['piso']) ? $aula['piso'] : '<span class="text-muted">No especificado</span>'; ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Programas Permitidos:</div>
                    <div class="info-value">
                        <?php if (empty($programas_permitidos) || (count($programas_permitidos) == 1 && $programas_permitidos[0] == '')): ?>
                            <span class="badge badge-primary">Todos los programas</span>
                        <?php else: ?>
                            <div class="programas-list">
                                <?php foreach ($programas_permitidos as $programa): 
                                    if (!empty(trim($programa))): ?>
                                    <span class="badge badge-primary"><?php echo trim($programa); ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Equipamiento -->
        <?php if (!empty($aula['equipamiento'])): ?>
        <div class="info-card">
            <h3><i class="fas fa-toolbox"></i> Equipamiento</h3>
            <div class="equipamiento-box">
                <?php echo nl2br(htmlspecialchars($aula['equipamiento'])); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Horarios Actuales -->
        <div class="info-card">
            <h3><i class="fas fa-calendar-alt"></i> Horarios Asignados (<?php echo count($horarios); ?>)</h3>
            
            <?php if (empty($horarios)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <p>No hay horarios asignados a esta aula</p>
                    <a href="../../horarios/crear.php?aula=<?php echo $id_aula; ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus"></i> Asignar Horario
                    </a>
                </div>
            <?php else: ?>
                <!-- Resumen por días -->
                <div style="margin-bottom: 20px;">
                    <h4>Resumen por días:</h4>
                    <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 10px;">
                        <?php 
                        $dias_semana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
                        foreach ($dias_semana as $dia): 
                            $cantidad = $horarios_por_dia[$dia] ?? 0;
                        ?>
                        <div style="text-align: center;">
                            <div style="font-size: 24px; font-weight: 700; color: var(--primary);">
                                <?php echo $cantidad; ?>
                            </div>
                            <div style="font-size: 12px; color: var(--gray-600); text-transform: uppercase;">
                                <?php echo substr($dia, 0, 3); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Lista de horarios -->
                <div class="horario-grid">
                    <?php foreach ($horarios as $horario): ?>
                    <div class="horario-item">
                        <div class="horario-dia"><?php echo $horario['dia_semana']; ?></div>
                        <div class="horario-curso">
                            <strong><?php echo htmlspecialchars($horario['codigo_curso']); ?></strong> - 
                            <?php echo htmlspecialchars($horario['nombre_curso']); ?>
                        </div>
                        <div class="horario-profesor">
                            <i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($horario['profesor_nombre']); ?>
                        </div>
                        <div class="horario-hora">
                            <i class="fas fa-clock"></i> 
                            <?php echo Funciones::formatearHora($horario['hora_inicio']); ?> - 
                            <?php echo Funciones::formatearHora($horario['hora_fin']); ?>
                            (<?php echo $horario['tipo_clase']; ?>)
                        </div>
                        <div style="margin-top: 10px; font-size: 12px; color: var(--gray-600);">
                            Semestre: <?php echo htmlspecialchars($horario['codigo_semestre']); ?>
                            <?php if ($horario['grupo']): ?>
                                | Grupo: <?php echo htmlspecialchars($horario['grupo']); ?>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top: 10px;">
                            <a href="../../horarios/ver.php?id=<?php echo $horario['id_horario']; ?>" 
                               class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-external-link-alt"></i> Ver horario
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Barra de acciones -->
        <div class="actions-bar">
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver a la lista
            </a>
            <a href="editar.php?id=<?php echo $id_aula; ?>" class="btn btn-warning">
                <i class="fas fa-edit"></i> Editar Aula
            </a>
            <?php if ($aula['disponible']): ?>
            <a href="../../horarios/crear.php?aula=<?php echo $id_aula; ?>" class="btn btn-primary">
                <i class="fas fa-plus"></i> Asignar Horario
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
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
        
        // Resaltar horarios del día actual
        document.querySelectorAll('.horario-dia').forEach(element => {
            if (element.textContent === diaActual) {
                element.style.color = 'var(--success)';
                element.innerHTML = '<i class="fas fa-star"></i> ' + element.textContent + ' (Hoy)';
            }
        });
    </script>
</body>
</html>