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

// Obtener datos del usuario
$stmt = $db->prepare("SELECT * FROM usuarios WHERE id_usuario = ?");
$stmt->execute([$user['id']]);
$usuario = $stmt->fetch();

// Actualizar información personal
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['actualizar_perfil'])) {
    $nombres = Funciones::sanitizar($_POST['nombres']);
    $apellidos = Funciones::sanitizar($_POST['apellidos']);
    $documento = Funciones::sanitizar($_POST['documento_identidad']);
    $especialidad = Funciones::sanitizar($_POST['especialidad']);
    $titulo = Funciones::sanitizar($_POST['titulo_academico']);
    $telefono = Funciones::sanitizar($_POST['telefono']);
    $email_institucional = Funciones::sanitizar($_POST['email_institucional']);
    $programas_dictados = isset($_POST['programas_dictados']) ? $_POST['programas_dictados'] : [];
    
    try {
        $stmt = $db->prepare("
            UPDATE profesores 
            SET nombres = ?, apellidos = ?, documento_identidad = ?, 
                especialidad = ?, titulo_academico = ?, telefono = ?,
                email_institucional = ?, programas_dictados = ?
            WHERE id_profesor = ?
        ");
        
        $programas_str = implode(',', $programas_dictados);
        
        $stmt->execute([
            $nombres, $apellidos, $documento, $especialidad, 
            $titulo, $telefono, $email_institucional, $programas_str,
            $profesor['id_profesor']
        ]);
        
        Session::setFlash('Perfil actualizado exitosamente');
        Funciones::redireccionar('perfil.php');
        
    } catch (Exception $e) {
        Session::setFlash('Error al actualizar el perfil: ' . $e->getMessage(), 'error');
    }
}

// Cambiar contraseña
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cambiar_password'])) {
    $password_actual = $_POST['password_actual'];
    $password_nueva = $_POST['password_nueva'];
    $password_confirmar = $_POST['password_confirmar'];
    
    if ($password_nueva !== $password_confirmar) {
        Session::setFlash('Las contraseñas nuevas no coinciden', 'error');
    } elseif ($password_actual !== $usuario['password']) { // En producción usar password_verify()
        Session::setFlash('La contraseña actual es incorrecta', 'error');
    } else {
        try {
            $stmt = $db->prepare("UPDATE usuarios SET password = ? WHERE id_usuario = ?");
            $stmt->execute([$password_nueva, $user['id']]);
            Session::setFlash('Contraseña cambiada exitosamente');
            Funciones::redireccionar('perfil.php');
        } catch (Exception $e) {
            Session::setFlash('Error al cambiar la contraseña: ' . $e->getMessage(), 'error');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - Sistema de Horarios</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
            margin-top: 20px;
        }
        
        .profile-sidebar {
            background: white;
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            text-align: center;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 50%;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: white;
        }
        
        .profile-name {
            font-size: 22px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .profile-title {
            color: var(--gray-600);
            font-size: 14px;
            margin-bottom: 20px;
        }
        
        .profile-info {
            text-align: left;
            margin-top: 20px;
        }
        
        .info-item {
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .info-label {
            font-size: 12px;
            color: var(--gray-500);
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 3px;
        }
        
        .info-value {
            font-size: 15px;
            color: var(--dark);
        }
        
        .profile-tabs {
            display: flex;
            border-bottom: 2px solid var(--gray-200);
            margin-bottom: 25px;
        }
        
        .tab-btn {
            padding: 12px 25px;
            background: none;
            border: none;
            font-size: 15px;
            font-weight: 600;
            color: var(--gray-600);
            cursor: pointer;
            position: relative;
            transition: all 0.3s;
        }
        
        .tab-btn:hover {
            color: var(--primary);
        }
        
        .tab-btn.active {
            color: var(--primary);
        }
        
        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .tab-content.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 768px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fas fa-user-circle"></i> Mi Perfil</h1>
                <div class="nav">
                    <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    <a href="mis_horarios.php"><i class="fas fa-calendar-alt"></i> Mis Horarios</a>
                    <a href="mis_cursos.php"><i class="fas fa-book"></i> Mis Cursos</a>
                    <a href="mis_estudiantes.php"><i class="fas fa-user-graduate"></i> Mis Estudiantes</a>
                    <a href="perfil.php" class="active"><i class="fas fa-user-circle"></i> Mi Perfil</a>
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
        
        <div class="profile-grid">
            <div class="profile-sidebar">
                <div class="profile-avatar">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                
                <div class="profile-name">
                    <?php echo htmlspecialchars($profesor['nombres'] . ' ' . $profesor['apellidos']); ?>
                </div>
                
                <div class="profile-title">
                    <span class="badge badge-profesor">Profesor</span>
                </div>
                
                <div class="profile-info">
                    <div class="info-item">
                        <div class="info-label">Código</div>
                        <div class="info-value"><?php echo htmlspecialchars($profesor['codigo_profesor']); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Especialidad</div>
                        <div class="info-value"><?php echo htmlspecialchars($profesor['especialidad']); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Estado</div>
                        <div class="info-value">
                            <span class="badge <?php echo $profesor['activo'] ? 'badge-success' : 'badge-danger'; ?>">
                                <?php echo $profesor['activo'] ? 'Activo' : 'Inactivo'; ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Usuario</div>
                        <div class="info-value"><?php echo htmlspecialchars($usuario['username']); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Correo</div>
                        <div class="info-value"><?php echo htmlspecialchars($usuario['email']); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="profile-main">
                <div class="profile-tabs">
                    <button class="tab-btn active" data-tab="informacion">Información Personal</button>
                    <button class="tab-btn" data-tab="seguridad">Seguridad</button>
                    <button class="tab-btn" data-tab="estadisticas">Estadísticas</button>
                </div>
                
                <!-- Pestaña Información Personal -->
                <div id="informacion" class="tab-content active">
                    <div class="card">
                        <h2><i class="fas fa-user-edit"></i> Editar Información Personal</h2>
                        <form method="POST">
                            <input type="hidden" name="actualizar_perfil" value="1">
                            
                            <div class="grid-2">
                                <div class="form-group">
                                    <label for="nombres">Nombres *</label>
                                    <input type="text" id="nombres" name="nombres" 
                                           class="form-control" required
                                           value="<?php echo htmlspecialchars($profesor['nombres'] ?? ''); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="apellidos">Apellidos *</label>
                                    <input type="text" id="apellidos" name="apellidos" 
                                           class="form-control" required
                                           value="<?php echo htmlspecialchars($profesor['apellidos'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="grid-2">
                                <div class="form-group">
                                    <label for="documento_identidad">Documento de Identidad *</label>
                                    <input type="text" id="documento_identidad" name="documento_identidad" 
                                           class="form-control" required
                                           value="<?php echo htmlspecialchars($profesor['documento_identidad'] ?? ''); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="telefono">Teléfono</label>
                                    <input type="tel" id="telefono" name="telefono" 
                                           class="form-control"
                                           value="<?php echo htmlspecialchars($profesor['telefono'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="especialidad">Especialidad *</label>
                                <input type="text" id="especialidad" name="especialidad" 
                                       class="form-control" required
                                       value="<?php echo htmlspecialchars($profesor['especialidad'] ?? ''); ?>">
                            </div>
                            
                            <div class="grid-2">
                                <div class="form-group">
                                    <label for="titulo_academico">Título Académico</label>
                                    <input type="text" id="titulo_academico" name="titulo_academico" 
                                           class="form-control"
                                           value="<?php echo htmlspecialchars($profesor['titulo_academico'] ?? ''); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="email_institucional">Correo Institucional</label>
                                    <input type="email" id="email_institucional" name="email_institucional" 
                                           class="form-control"
                                           value="<?php echo htmlspecialchars($profesor['email_institucional'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Programas en los que dicta clases</label>
                                <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 10px;">
                                    <?php 
                                    $programas = ['Topografía', 'Arquitectura', 'Enfermería'];
                                    $programas_actuales = explode(',', $profesor['programas_dictados'] ?? '');
                                    ?>
                                    <?php foreach ($programas as $programa): ?>
                                        <label class="checkbox-label">
                                            <input type="checkbox" name="programas_dictados[]" 
                                                   value="<?php echo $programa; ?>"
                                                   <?php echo in_array($programa, $programas_actuales) ? 'checked' : ''; ?>>
                                            <span><?php echo $programa; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Guardar Cambios
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Pestaña Seguridad -->
                <div id="seguridad" class="tab-content">
                    <div class="card">
                        <h2><i class="fas fa-lock"></i> Cambiar Contraseña</h2>
                        <form method="POST">
                            <input type="hidden" name="cambiar_password" value="1">
                            
                            <div class="form-group">
                                <label for="password_actual">Contraseña Actual *</label>
                                <input type="password" id="password_actual" name="password_actual" 
                                       class="form-control" required>
                            </div>
                            
                            <div class="grid-2">
                                <div class="form-group">
                                    <label for="password_nueva">Nueva Contraseña *</label>
                                    <input type="password" id="password_nueva" name="password_nueva" 
                                           class="form-control" required minlength="6">
                                    <small class="form-text">Mínimo 6 caracteres</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="password_confirmar">Confirmar Nueva Contraseña *</label>
                                    <input type="password" id="password_confirmar" name="password_confirmar" 
                                           class="form-control" required minlength="6">
                                </div>
                            </div>
                            
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Importante:</strong> La contraseña se almacena en texto plano en este sistema de desarrollo. 
                                En producción, se debe implementar encriptación.
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-key"></i> Cambiar Contraseña
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Pestaña Estadísticas -->
                <div id="estadisticas" class="tab-content">
                    <div class="card">
                        <h2><i class="fas fa-chart-line"></i> Mis Estadísticas</h2>
                        
                        <?php
                        // Obtener estadísticas del profesor
                        $stats = [
                            'total_cursos' => 0,
                            'total_horarios' => 0,
                            'total_estudiantes' => 0,
                            'horas_semanales' => 0
                        ];
                        
                        $stmt = $db->prepare("
                            SELECT COUNT(DISTINCT h.id_curso) as cursos,
                                   COUNT(DISTINCT h.id_horario) as horarios,
                                   SUM(c.horas_semanales) as horas
                            FROM horarios h
                            JOIN cursos c ON h.id_curso = c.id_curso
                            WHERE h.id_profesor = ? AND h.activo = 1
                        ");
                        $stmt->execute([$profesor['id_profesor']]);
                        $stats_db = $stmt->fetch();
                        
                        $stmt = $db->prepare("
                            SELECT COUNT(DISTINCT i.id_estudiante) as estudiantes
                            FROM inscripciones i
                            JOIN horarios h ON i.id_horario = h.id_horario
                            WHERE h.id_profesor = ? AND h.activo = 1 AND i.estado = 'inscrito'
                        ");
                        $stmt->execute([$profesor['id_profesor']]);
                        $estudiantes_db = $stmt->fetch();
                        
                        if ($stats_db) {
                            $stats['total_cursos'] = $stats_db['cursos'];
                            $stats['total_horarios'] = $stats_db['horarios'];
                            $stats['horas_semanales'] = $stats_db['horas'];
                        }
                        
                        if ($estudiantes_db) {
                            $stats['total_estudiantes'] = $estudiantes_db['estudiantes'];
                        }
                        ?>
                        
                        <div class="stats-grid" style="margin-top: 20px;">
                            <div class="stat-card">
                                <div class="stat-icon" style="background: #4299e1;">
                                    <i class="fas fa-book"></i>
                                </div>
                                <div class="stat-info">
                                    <h3>Cursos Activos</h3>
                                    <div class="stat-number"><?php echo $stats['total_cursos']; ?></div>
                                </div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-icon" style="background: #48bb78;">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                                <div class="stat-info">
                                    <h3>Horarios Semanales</h3>
                                    <div class="stat-number"><?php echo $stats['total_horarios']; ?></div>
                                </div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-icon" style="background: #ed8936;">
                                    <i class="fas fa-user-graduate"></i>
                                </div>
                                <div class="stat-info">
                                    <h3>Total Estudiantes</h3>
                                    <div class="stat-number"><?php echo $stats['total_estudiantes']; ?></div>
                                </div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-icon" style="background: #9f7aea;">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="stat-info">
                                    <h3>Horas Semanales</h3>
                                    <div class="stat-number"><?php echo $stats['horas_semanales']; ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <div style="margin-top: 30px;">
                            <h3><i class="fas fa-history"></i> Actividad Reciente</h3>
                            <?php
                            $stmt = $db->prepare("
                                SELECT n.titulo, n.mensaje, n.fecha_notificacion
                                FROM notificaciones n
                                WHERE n.id_usuario = ? AND n.leido = 0
                                ORDER BY n.fecha_notificacion DESC
                                LIMIT 5
                            ");
                            $stmt->execute([$user['id']]);
                            $notificaciones = $stmt->fetchAll();
                            
                            if (count($notificaciones) > 0):
                            ?>
                                <div style="margin-top: 15px;">
                                    <?php foreach ($notificaciones as $notif): ?>
                                        <div style="padding: 10px 15px; background: var(--gray-50); 
                                                    border-radius: 8px; margin-bottom: 10px;">
                                            <div style="font-weight: 600; color: var(--dark);">
                                                <?php echo htmlspecialchars($notif['titulo']); ?>
                                            </div>
                                            <div style="font-size: 13px; color: var(--gray-600); margin-top: 5px;">
                                                <?php echo htmlspecialchars($notif['mensaje']); ?>
                                            </div>
                                            <div style="font-size: 12px; color: var(--gray-500); margin-top: 5px;">
                                                <i class="far fa-clock"></i> 
                                                <?php echo Funciones::formatearFecha($notif['fecha_notificacion']); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div style="text-align: center; padding: 30px; color: var(--gray-500);">
                                    <i class="fas fa-bell-slash fa-2x"></i>
                                    <p style="margin-top: 10px;">No tienes notificaciones nuevas</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../assets/js/main.js"></script>
    <script>
        // Tabs del perfil
        document.addEventListener('DOMContentLoaded', function() {
            const tabBtns = document.querySelectorAll('.tab-btn');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    // Remover clase active de todos
                    tabBtns.forEach(b => b.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));
                    
                    // Agregar clase active al seleccionado
                    this.classList.add('active');
                    document.getElementById(tabId).classList.add('active');
                });
            });
        });
    </script>
</body>
</html>