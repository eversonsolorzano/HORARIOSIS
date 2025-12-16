<?php
require_once 'includes/auth.php';
require_once 'includes/funciones.php';
Auth::requireLogin();

$db = Database::getConnection();
$user = Auth::getUserData();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombres = Funciones::sanitizar($_POST['nombres']);
    $apellidos = Funciones::sanitizar($_POST['apellidos']);
    $email = Funciones::sanitizar($_POST['email']);
    $telefono = Funciones::sanitizar($_POST['telefono']);
    $direccion = Funciones::sanitizar($_POST['direccion']);
    
    // Actualizar según el rol
    if ($user['rol'] == 'estudiante') {
        $stmt = $db->prepare("UPDATE estudiantes SET 
            nombres = ?, apellidos = ?, telefono = ?, direccion = ? 
            WHERE id_usuario = ?");
        $stmt->execute([$nombres, $apellidos, $telefono, $direccion, $user['id']]);
    } elseif ($user['rol'] == 'profesor') {
        $stmt = $db->prepare("UPDATE profesores SET 
            nombres = ?, apellidos = ?, telefono = ? 
            WHERE id_usuario = ?");
        $stmt->execute([$nombres, $apellidos, $telefono, $user['id']]);
    }
    
    // Actualizar email en usuarios
    $stmt = $db->prepare("UPDATE usuarios SET email = ? WHERE id_usuario = ?");
    $stmt->execute([$email, $user['id']]);
    
    Funciones::redireccionar('perfil.php', 'Perfil actualizado correctamente');
}

// Obtener datos actuales
if ($user['rol'] == 'estudiante') {
    $stmt = $db->prepare("SELECT * FROM estudiantes WHERE id_usuario = ?");
    $stmt->execute([$user['id']]);
    $datos = $stmt->fetch();
} elseif ($user['rol'] == 'profesor') {
    $stmt = $db->prepare("SELECT * FROM profesores WHERE id_usuario = ?");
    $stmt->execute([$user['id']]);
    $datos = $stmt->fetch();
} else {
    $datos = ['nombres' => '', 'apellidos' => ''];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - Sistema de Horarios</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f7fafc; 
            color: #4a5568; 
        }
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            padding: 20px; 
        }
        .header { 
            background: white; 
            padding: 20px; 
            border-radius: 10px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
            margin-bottom: 30px; 
        }
        .header h1 { 
            color: #2d3748; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
        }
        .nav { 
            display: flex; 
            gap: 15px; 
            margin-top: 15px; 
        }
        .nav a { 
            color: #4a5568; 
            text-decoration: none; 
            padding: 8px 15px; 
            border-radius: 5px; 
            transition: all 0.3s; 
        }
        .nav a:hover { 
            background: #edf2f7; 
        }
        .nav a.active { 
            background: #667eea; 
            color: white; 
        }
        .content { 
            display: grid; 
            grid-template-columns: 1fr 2fr; 
            gap: 30px; 
        }
        .card { 
            background: white; 
            border-radius: 10px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
            padding: 30px; 
        }
        .profile-info { 
            text-align: center; 
        }
        .avatar { 
            width: 150px; 
            height: 150px; 
            border-radius: 50%; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: white; 
            font-size: 60px; 
            margin: 0 auto 20px; 
        }
        .form-group { 
            margin-bottom: 20px; 
        }
        .form-group label { 
            display: block; 
            margin-bottom: 5px; 
            color: #4a5568; 
            font-weight: 500; 
        }
        .form-group input, .form-group textarea { 
            width: 100%; 
            padding: 12px; 
            border: 1px solid #e2e8f0; 
            border-radius: 5px; 
            font-size: 16px; 
        }
        .form-group input:focus, .form-group textarea:focus { 
            outline: none; 
            border-color: #667eea; 
        }
        .btn-submit { 
            background: #667eea; 
            color: white; 
            border: none; 
            padding: 12px 30px; 
            border-radius: 5px; 
            font-size: 16px; 
            cursor: pointer; 
            transition: background 0.3s; 
        }
        .btn-submit:hover { 
            background: #5a67d8; 
        }
        .alert { 
            padding: 15px; 
            border-radius: 5px; 
            margin-bottom: 20px; 
        }
        .alert.success { 
            background: #c6f6d5; 
            color: #22543d; 
            border-left: 4px solid #38a169; 
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-circle"></i> Mi Perfil</h1>
            <div class="nav">
                <a href="<?php echo $user['rol']; ?>/dashboard.php">Dashboard</a>
                <a href="perfil.php" class="active">Mi Perfil</a>
                <a href="logout.php">Cerrar Sesión</a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert <?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <div class="content">
            <div class="card profile-info">
                <div class="avatar">
                    <i class="fas fa-user"></i>
                </div>
                <h2><?php echo htmlspecialchars($datos['nombres'] . ' ' . $datos['apellidos']); ?></h2>
                <p><strong>Rol:</strong> <?php echo ucfirst($user['rol']); ?></p>
                <p><strong>Usuario:</strong> <?php echo htmlspecialchars($user['username']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
            </div>
            
            <div class="card">
                <h2 style="margin-bottom: 20px;">Editar Información</h2>
                <form method="POST" action="">
                    <div class="form-group">
                        <label>Nombres</label>
                        <input type="text" name="nombres" value="<?php echo htmlspecialchars($datos['nombres'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Apellidos</label>
                        <input type="text" name="apellidos" value="<?php echo htmlspecialchars($datos['apellidos'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Teléfono</label>
                        <input type="text" name="telefono" value="<?php echo htmlspecialchars($datos['telefono'] ?? ''); ?>">
                    </div>
                    
                    <?php if ($user['rol'] == 'estudiante'): ?>
                    <div class="form-group">
                        <label>Dirección</label>
                        <textarea name="direccion" rows="3"><?php echo htmlspecialchars($datos['direccion'] ?? ''); ?></textarea>
                    </div>
                    <?php endif; ?>
                    
                    <button type="submit" class="btn-submit">Actualizar Perfil</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>