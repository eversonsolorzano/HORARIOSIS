<?php
require_once '../includes/auth.php';
require_once '../includes/funciones.php';
Auth::requireRole('profesor');

$db = Database::getConnection();
$user = Auth::getUserData();

// Obtener datos del profesor
$stmt = $db->prepare("SELECT * FROM profesores WHERE id_usuario = ?");
$stmt->execute([$user['id']]);
$profesor = $stmt->fetch();

if (!$profesor) {
    Funciones::redireccionar('../login.php', 'Perfil de profesor no encontrado', 'error');
}

// Marcar como leída si se solicita
if (isset($_GET['marcar_leido']) && is_numeric($_GET['marcar_leido'])) {
    $id_notificacion = intval($_GET['marcar_leido']);
    $stmt = $db->prepare("UPDATE notificaciones SET leido = 1 WHERE id_notificacion = ? AND id_usuario = ?");
    $stmt->execute([$id_notificacion, $user['id']]);
    
    Session::setFlash('Notificación marcada como leída');
    Funciones::redireccionar('notificaciones.php');
}

// Marcar todas como leídas
if (isset($_POST['marcar_todas'])) {
    $stmt = $db->prepare("UPDATE notificaciones SET leido = 1 WHERE id_usuario = ? AND leido = 0");
    $stmt->execute([$user['id']]);
    
    Session::setFlash('Todas las notificaciones marcadas como leídas');
    Funciones::redireccionar('notificaciones.php');
}

// Filtros
$filtro = isset($_GET['filtro']) ? $_GET['filtro'] : 'todas';
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$por_pagina = 20;

// Construir consulta
$sql = "SELECT * FROM notificaciones WHERE id_usuario = ?";
$params = [$user['id']];

if ($filtro == 'no_leidas') {
    $sql .= " AND leido = 0";
} elseif ($filtro == 'leidas') {
    $sql .= " AND leido = 1";
}

$sql .= " ORDER BY fecha_notificacion DESC";

// Paginación
$stmt = $db->prepare(str_replace('*', 'COUNT(*)', $sql));
$stmt->execute($params);
$total_notificaciones = $stmt->fetchColumn();

$total_paginas = ceil($total_notificaciones / $por_pagina);
$offset = ($pagina - 1) * $por_pagina;

$sql .= " LIMIT ? OFFSET ?";
$params[] = $por_pagina;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$notificaciones = $stmt->fetchAll();

// Contadores
$stmt = $db->prepare("
    SELECT 
        SUM(CASE WHEN leido = 0 THEN 1 ELSE 0 END) as no_leidas,
        SUM(CASE WHEN leido = 1 THEN 1 ELSE 0 END) as leidas,
        COUNT(*) as total
    FROM notificaciones 
    WHERE id_usuario = ?
");
$stmt->execute([$user['id']]);
$contadores = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Notificaciones - Sistema de Horarios</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .notificacion-item {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid var(--gray-300);
            transition: all 0.3s;
            position: relative;
        }
        
        .notificacion-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .notificacion-item.no-leida {
            border-left-color: var(--primary);
            background: linear-gradient(135deg, #edf2f7 0%, #e2e8f0 100%);
        }
        
        .notificacion-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .notificacion-titulo {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark);
            flex: 1;
        }
        
        .notificacion-fecha {
            font-size: 13px;
            color: var(--gray-500);
            white-space: nowrap;
            margin-left: 15px;
        }
        
        .notificacion-mensaje {
            color: var(--gray-700);
            line-height: 1.5;
            margin-bottom: 15px;
        }
        
        .notificacion-tipo {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-right: 10px;
        }
        
        .tipo-cambio_horario { background: #fed7d7; color: #9b2c2c; }
        .tipo-nuevo_curso { background: #c6f6d5; color: #22543d; }
        .tipo-recordatorio { background: #e9d8fd; color: #553c9a; }
        .tipo-sistema { background: #bee3f8; color: #2c5282; }
        
        .notificacion-acciones {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--gray-200);
        }
        
        .btn-marcar {
            font-size: 12px;
            padding: 5px 12px;
        }
        
        .paginacion {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 30px;
        }
        
        .pagina-link {
            padding: 8px 15px;
            background: white;
            border: 1px solid var(--gray-300);
            border-radius: 6px;
            color: var(--gray-700);
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .pagina-link:hover {
            background: var(--gray-100);
            border-color: var(--gray-400);
        }
        
        .pagina-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .empty-notificaciones {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-500);
        }
        
        .filters-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-tab {
            padding: 8px 20px;
            background: var(--gray-100);
            border: none;
            border-radius: 20px;
            color: var(--gray-700);
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .filter-tab:hover {
            background: var(--gray-200);
        }
        
        .filter-tab.active {
            background: var(--primary);
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fas fa-bell"></i> Mis Notificaciones</h1>
                <div class="nav">
                    <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    <a href="mis_horarios.php"><i class="fas fa-calendar-alt"></i> Mis Horarios</a>
                    <a href="mis_cursos.php"><i class="fas fa-book"></i> Mis Cursos</a>
                    <a href="mis_estudiantes.php"><i class="fas fa-user-graduate"></i> Mis Estudiantes</a>
                    <a href="perfil.php"><i class="fas fa-user-circle"></i> Mi Perfil</a>
                    <a href="notificaciones.php" class="active"><i class="fas fa-bell"></i> Notificaciones</a>
                    <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Salir</a>
                </div>
            </div>
            <div class="user-info">
                <span><?php echo htmlspecialchars($profesor['nombres'] . ' ' . $profesor['apellidos']); ?></span>
                <span class="badge badge-profesor">Profesor</span>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2><i class="fas fa-inbox"></i> Centro de Notificaciones</h2>
                <form method="POST" style="margin: 0;">
                    <input type="hidden" name="marcar_todas" value="1">
                    <button type="submit" class="btn btn-secondary btn-sm">
                        <i class="fas fa-check-double"></i> Marcar Todas como Leídas
                    </button>
                </form>
            </div>
            
            <div class="filters-tabs">
                <a href="notificaciones.php?filtro=todas" 
                   class="filter-tab <?php echo $filtro == 'todas' ? 'active' : ''; ?>">
                    <i class="fas fa-inbox"></i> Todas (<?php echo $contadores['total'] ?? 0; ?>)
                </a>
                <a href="notificaciones.php?filtro=no_leidas" 
                   class="filter-tab <?php echo $filtro == 'no_leidas' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i> No Leídas (<?php echo $contadores['no_leidas'] ?? 0; ?>)
                </a>
                <a href="notificaciones.php?filtro=leidas" 
                   class="filter-tab <?php echo $filtro == 'leidas' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope-open"></i> Leídas (<?php echo $contadores['leidas'] ?? 0; ?>)
                </a>
            </div>
            
            <?php if (count($notificaciones) > 0): ?>
                <div class="notificaciones-list">
                    <?php foreach ($notificaciones as $notif): 
                        $tipo_clases = [
                            'cambio_horario' => 'Cambio de Horario',
                            'nuevo_curso' => 'Nuevo Curso',
                            'recordatorio' => 'Recordatorio',
                            'sistema' => 'Sistema'
                        ];
                    ?>
                        <div class="notificacion-item <?php echo $notif['leido'] ? '' : 'no-leida'; ?>">
                            <div class="notificacion-header">
                                <div class="notificacion-titulo">
                                    <?php echo htmlspecialchars($notif['titulo']); ?>
                                </div>
                                <div class="notificacion-fecha">
                                    <i class="far fa-clock"></i> 
                                    <?php echo Funciones::formatearFecha($notif['fecha_notificacion']); ?>
                                </div>
                            </div>
                            
                            <div>
                                <span class="notificacion-tipo tipo-<?php echo $notif['tipo_notificacion']; ?>">
                                    <?php echo $tipo_clases[$notif['tipo_notificacion']] ?? $notif['tipo_notificacion']; ?>
                                </span>
                            </div>
                            
                            <div class="notificacion-mensaje">
                                <?php echo nl2br(htmlspecialchars($notif['mensaje'])); ?>
                            </div>
                            
                            <div class="notificacion-acciones">
                                <?php if (!$notif['leido']): ?>
                                    <a href="notificaciones.php?marcar_leido=<?php echo $notif['id_notificacion']; ?>" 
                                       class="btn btn-success btn-sm btn-marcar">
                                        <i class="fas fa-check"></i> Marcar como Leída
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($notif['link_accion']): ?>
                                    <a href="<?php echo htmlspecialchars($notif['link_accion']); ?>" 
                                       class="btn btn-primary btn-sm btn-marcar">
                                        <i class="fas fa-external-link-alt"></i> Ver Detalles
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($total_paginas > 1): ?>
                <div class="paginacion">
                    <?php if ($pagina > 1): ?>
                        <a href="notificaciones.php?filtro=<?php echo $filtro; ?>&pagina=<?php echo $pagina - 1; ?>" 
                           class="pagina-link">
                            <i class="fas fa-chevron-left"></i> Anterior
                        </a>
                    <?php endif; ?>
                    
                    <?php 
                    $inicio = max(1, $pagina - 2);
                    $fin = min($total_paginas, $pagina + 2);
                    
                    for ($i = $inicio; $i <= $fin; $i++): 
                    ?>
                        <a href="notificaciones.php?filtro=<?php echo $filtro; ?>&pagina=<?php echo $i; ?>" 
                           class="pagina-link <?php echo $i == $pagina ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($pagina < $total_paginas): ?>
                        <a href="notificaciones.php?filtro=<?php echo $filtro; ?>&pagina=<?php echo $pagina + 1; ?>" 
                           class="pagina-link">
                            Siguiente <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="empty-notificaciones">
                    <i class="fas fa-bell-slash fa-3x" style="color: var(--gray-300); margin-bottom: 20px;"></i>
                    <h3>No hay notificaciones</h3>
                    <p>No tienes notificaciones con los filtros aplicados.</p>
                    <?php if ($filtro != 'todas'): ?>
                        <a href="notificaciones.php?filtro=todas" class="btn btn-primary mt-3">
                            <i class="fas fa-eye"></i> Ver Todas las Notificaciones
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="../assets/js/main.js"></script>
</body>
</html>