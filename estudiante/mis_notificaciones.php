<?php
require_once '../includes/auth.php';
require_once '../includes/funciones.php';
Auth::requireRole('estudiante');

$db = Database::getConnection();
$user = Auth::getUserData();

// Obtener datos del estudiante
$stmt = $db->prepare("
    SELECT e.* 
    FROM estudiantes e 
    WHERE e.id_usuario = ?
");
$stmt->execute([$user['id']]);
$estudiante = $stmt->fetch();

// Obtener notificaciones del estudiante
$sql = "
    SELECT 
        n.*,
        CASE 
            WHEN n.fecha_notificacion >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 'reciente'
            WHEN n.fecha_notificacion >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 'hoy'
            ELSE 'antigua'
        END as antiguedad
    FROM notificaciones n
    WHERE n.id_usuario = ?
    ORDER BY n.leido ASC, n.fecha_notificacion DESC
";

$stmt = $db->prepare($sql);
$stmt->execute([$user['id']]);
$notificaciones = $stmt->fetchAll();

// Contar notificaciones no leídas
$notificaciones_no_leidas = array_filter($notificaciones, function($n) {
    return !$n['leido'];
});

// Marcar todas como leídas si se solicita
if (isset($_GET['marcar_todas']) && $_GET['marcar_todas'] == '1') {
    try {
        $stmt = $db->prepare("UPDATE notificaciones SET leido = TRUE WHERE id_usuario = ? AND leido = FALSE");
        $stmt->execute([$user['id']]);
        Session::setFlash('Todas las notificaciones han sido marcadas como leídas');
        Funciones::redireccionar('mis_notificaciones.php');
    } catch (Exception $e) {
        Session::setFlash('Error al marcar notificaciones: ' . $e->getMessage(), 'error');
    }
}

// Marcar una notificación específica como leída
if (isset($_GET['marcar_leido'])) {
    $id_notificacion = intval($_GET['marcar_leido']);
    
    try {
        $stmt = $db->prepare("UPDATE notificaciones SET leido = TRUE WHERE id_notificacion = ? AND id_usuario = ?");
        $stmt->execute([$id_notificacion, $user['id']]);
        Session::setFlash('Notificación marcada como leída');
        Funciones::redireccionar('mis_notificaciones.php');
    } catch (Exception $e) {
        Session::setFlash('Error al marcar notificación: ' . $e->getMessage(), 'error');
    }
}

// Eliminar una notificación
if (isset($_GET['eliminar'])) {
    $id_notificacion = intval($_GET['eliminar']);
    
    try {
        $stmt = $db->prepare("DELETE FROM notificaciones WHERE id_notificacion = ? AND id_usuario = ?");
        $stmt->execute([$id_notificacion, $user['id']]);
        Session::setFlash('Notificación eliminada');
        Funciones::redireccionar('mis_notificaciones.php');
    } catch (Exception $e) {
        Session::setFlash('Error al eliminar notificación: ' . $e->getMessage(), 'error');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Notificaciones - Sistema de Horarios</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .notificaciones-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
        }
        
        .notificacion-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: var(--shadow);
            border-left: 5px solid var(--primary);
            transition: transform 0.2s, box-shadow 0.2s;
            position: relative;
        }
        
        .notificacion-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .notificacion-card.no-leida {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-left: 5px solid var(--primary-dark);
        }
        
        .notificacion-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .notificacion-titulo {
            color: var(--dark);
            font-size: 16px;
            font-weight: 600;
            margin: 0;
        }
        
        .notificacion-fecha {
            font-size: 13px;
            color: var(--gray-500);
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .notificacion-tipo {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .tipo-cambio_horario {
            background: #fef3c7;
            color: #92400e;
        }
        
        .tipo-nuevo_curso {
            background: #d1fae5;
            color: #065f46;
        }
        
        .tipo-recordatorio {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .tipo-sistema {
            background: #f3e8ff;
            color: #6b21a8;
        }
        
        .notificacion-mensaje {
            color: var(--gray-700);
            line-height: 1.6;
            margin-bottom: 15px;
        }
        
        .notificacion-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .badge-reciente {
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--danger);
            color: white;
            font-size: 10px;
            padding: 3px 6px;
            border-radius: 10px;
            font-weight: 600;
        }
        
        .empty-notificaciones {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-notificaciones i {
            font-size: 48px;
            color: var(--gray-300);
            margin-bottom: 20px;
        }
        
        .filter-container {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-badge {
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .filter-badge:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }
        
        .filter-badge.active {
            border-color: var(--primary);
            background: var(--primary-light);
            color: var(--primary-dark);
            font-weight: 600;
        }
        
        .badge-todas {
            background: var(--gray-100);
            color: var(--gray-700);
        }
        
        .badge-no-leidas {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-recientes {
            background: #d1fae5;
            color: #065f46;
        }
        
        @media (max-width: 768px) {
            .notificaciones-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .header-actions {
                width: 100%;
                justify-content: flex-start;
            }
            
            .filter-container {
                flex-direction: column;
                align-items: flex-start;
            }
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
                    <a href="mi_horario.php"><i class="fas fa-calendar-alt"></i> Mi Horario</a>
                    <a href="mis_cursos.php"><i class="fas fa-book"></i> Mis Cursos</a>
                    <a href="inscribir_curso.php"><i class="fas fa-plus-circle"></i> Inscribir Curso</a>
                    <a href="mis_calificaciones.php"><i class="fas fa-star"></i> Mis Calificaciones</a>
                    <a href="mis_notificaciones.php" class="active"><i class="fas fa-bell"></i> Notificaciones</a>
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
        
        <div class="notificaciones-header">
            <h2><i class="fas fa-inbox"></i> Bandeja de Notificaciones</h2>
            <div class="header-actions">
                <a href="mis_notificaciones.php?marcar_todas=1" class="btn btn-primary">
                    <i class="fas fa-check-double"></i> Marcar todas como leídas
                </a>
                <button onclick="vaciarNotificaciones()" class="btn btn-danger">
                    <i class="fas fa-trash-alt"></i> Vaciar notificaciones
                </button>
            </div>
        </div>
        
        <!-- Estadísticas -->
        <div class="grid-3">
            <div class="card">
                <h3><i class="fas fa-bell"></i> Total</h3>
                <div style="font-size: 32px; font-weight: 700; color: var(--dark); text-align: center;">
                    <?php echo count($notificaciones); ?>
                </div>
            </div>
            
            <div class="card">
                <h3><i class="fas fa-envelope"></i> No Leídas</h3>
                <div style="font-size: 32px; font-weight: 700; color: var(--danger); text-align: center;">
                    <?php echo count($notificaciones_no_leidas); ?>
                </div>
            </div>
            
            <div class="card">
                <h3><i class="fas fa-clock"></i> Recientes (24h)</h3>
                <?php
                $recientes = array_filter($notificaciones, function($n) {
                    return $n['antiguedad'] === 'reciente' || $n['antiguedad'] === 'hoy';
                });
                ?>
                <div style="font-size: 32px; font-weight: 700; color: var(--success); text-align: center;">
                    <?php echo count($recientes); ?>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card">
            <h3><i class="fas fa-filter"></i> Filtrar Notificaciones</h3>
            <div class="filter-container">
                <span class="filter-badge badge-todas active" onclick="filtrarNotificaciones('todas')">
                    Todas (<?php echo count($notificaciones); ?>)
                </span>
                <span class="filter-badge badge-no-leidas" onclick="filtrarNotificaciones('no-leidas')">
                    No leídas (<?php echo count($notificaciones_no_leidas); ?>)
                </span>
                <span class="filter-badge badge-recientes" onclick="filtrarNotificaciones('recientes')">
                    Recientes (<?php echo count($recientes); ?>)
                </span>
                <span class="filter-badge" onclick="filtrarNotificaciones('cambio_horario')">
                    <i class="fas fa-exchange-alt"></i> Cambios horario
                </span>
                <span class="filter-badge" onclick="filtrarNotificaciones('nuevo_curso')">
                    <i class="fas fa-plus-circle"></i> Nuevos cursos
                </span>
                <span class="filter-badge" onclick="filtrarNotificaciones('recordatorio')">
                    <i class="fas fa-clock"></i> Recordatorios
                </span>
            </div>
        </div>
        
        <!-- Lista de Notificaciones -->
        <div class="card">
            <h3><i class="fas fa-list"></i> Lista de Notificaciones</h3>
            
            <?php if (count($notificaciones) > 0): ?>
                <?php foreach ($notificaciones as $notif): ?>
                    <div class="notificacion-card <?php echo $notif['leido'] ? '' : 'no-leida'; ?>" 
                         data-tipo="<?php echo $notif['tipo_notificacion']; ?>"
                         data-leida="<?php echo $notif['leido'] ? '1' : '0'; ?>"
                         data-antiguedad="<?php echo $notif['antiguedad']; ?>">
                        
                        <?php if (!$notif['leido']): ?>
                            <span class="badge-reciente">NUEVO</span>
                        <?php endif; ?>
                        
                        <div class="notificacion-header">
                            <div>
                                <h4 class="notificacion-titulo">
                                    <i class="fas 
                                        <?php 
                                        switch ($notif['tipo_notificacion']) {
                                            case 'cambio_horario': echo 'fa-exchange-alt'; break;
                                            case 'nuevo_curso': echo 'fa-plus-circle'; break;
                                            case 'recordatorio': echo 'fa-clock'; break;
                                            case 'sistema': echo 'fa-cog'; break;
                                            default: echo 'fa-bell';
                                        }
                                        ?>
                                    "></i>
                                    <?php echo htmlspecialchars($notif['titulo']); ?>
                                </h4>
                                <div class="notificacion-fecha">
                                    <i class="fas fa-clock"></i>
                                    <?php echo Funciones::formatearFechaRelativa($notif['fecha_notificacion']); ?>
                                    (<?php echo date('d/m/Y H:i', strtotime($notif['fecha_notificacion'])); ?>)
                                </div>
                            </div>
                            
                            <span class="notificacion-tipo tipo-<?php echo $notif['tipo_notificacion']; ?>">
                                <?php 
                                $tipos = [
                                    'cambio_horario' => 'Cambio Horario',
                                    'nuevo_curso' => 'Nuevo Curso',
                                    'recordatorio' => 'Recordatorio',
                                    'sistema' => 'Sistema'
                                ];
                                echo $tipos[$notif['tipo_notificacion']] ?? $notif['tipo_notificacion'];
                                ?>
                            </span>
                        </div>
                        
                        <div class="notificacion-mensaje">
                            <?php echo nl2br(htmlspecialchars($notif['mensaje'])); ?>
                        </div>
                        
                        <div class="notificacion-actions">
                            <?php if (!$notif['leido']): ?>
                                <a href="mis_notificaciones.php?marcar_leido=<?php echo $notif['id_notificacion']; ?>" 
                                   class="btn btn-success btn-sm">
                                    <i class="fas fa-check"></i> Marcar como leída
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($notif['link_accion']): ?>
                                <a href="<?php echo htmlspecialchars($notif['link_accion']); ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-external-link-alt"></i> Ver acción
                                </a>
                            <?php endif; ?>
                            
                            <a href="mis_notificaciones.php?eliminar=<?php echo $notif['id_notificacion']; ?>" 
                               class="btn btn-danger btn-sm"
                               onclick="return confirm('¿Eliminar esta notificación?')">
                                <i class="fas fa-trash"></i> Eliminar
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-notificaciones">
                    <i class="fas fa-bell-slash"></i>
                    <h3>No tienes notificaciones</h3>
                    <p>No hay notificaciones en tu bandeja de entrada.</p>
                    <p style="color: var(--gray-600); margin-top: 10px;">
                        Las notificaciones aparecerán aquí cuando haya novedades en tus cursos, horarios o calificaciones.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="../assets/js/main.js"></script>
    <script>
        // Filtrar notificaciones
        function filtrarNotificaciones(filtro) {
            const notificaciones = document.querySelectorAll('.notificacion-card');
            const filtros = document.querySelectorAll('.filter-badge');
            
            // Actualizar clases activas en filtros
            filtros.forEach(f => f.classList.remove('active'));
            event.target.classList.add('active');
            
            // Aplicar filtro
            notificaciones.forEach(notif => {
                let mostrar = true;
                
                switch (filtro) {
                    case 'no-leidas':
                        if (notif.dataset.leida === '1') mostrar = false;
                        break;
                    case 'recientes':
                        if (notif.dataset.antiguedad === 'antigua') mostrar = false;
                        break;
                    case 'cambio_horario':
                    case 'nuevo_curso':
                    case 'recordatorio':
                    case 'sistema':
                        if (notif.dataset.tipo !== filtro) mostrar = false;
                        break;
                    // 'todas' muestra todas
                }
                
                notif.style.display = mostrar ? 'block' : 'none';
            });
        }
        
        // Vaciar notificaciones
        function vaciarNotificaciones() {
            if (confirm('¿Estás seguro de que deseas eliminar todas las notificaciones?\n\nEsta acción no se puede deshacer.')) {
                // En una implementación real, esto haría una petición AJAX
                // Por ahora, redirigimos a una URL que eliminaría todas
                window.location.href = 'mis_notificaciones.php?eliminar_todas=1';
            }
        }
        
        // Marcar como leída al hacer clic
        document.addEventListener('DOMContentLoaded', function() {
            const notificaciones = document.querySelectorAll('.notificacion-card.no-leida');
            
            notificaciones.forEach(notif => {
                notif.addEventListener('click', function(e) {
                    // Evitar marcar como leída si se hace clic en un enlace o botón
                    if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || 
                        e.target.closest('a') || e.target.closest('button')) {
                        return;
                    }
                    
                    const id = this.querySelector('a[href*="marcar_leido"]')?.href?.match(/marcar_leido=(\d+)/)?.[1];
                    if (id) {
                        window.location.href = `mis_notificaciones.php?marcar_leido=${id}`;
                    }
                });
            });
        });
        
        // Actualizar contador en tiempo real (simulado)
        function actualizarContadorNotificaciones() {
            // En una implementación real, esto haría polling o usaría WebSockets
            // Por ahora, solo es un placeholder
            console.log('Actualizando contador de notificaciones...');
        }
        
        // Actualizar cada 30 segundos
        setInterval(actualizarContadorNotificaciones, 30000);
    </script>
</body>
</html>