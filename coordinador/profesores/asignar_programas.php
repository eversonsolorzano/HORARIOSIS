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

// Obtener datos del profesor
$stmt = $db->prepare("
    SELECT p.*, u.email, u.username
    FROM profesores p
    JOIN usuarios u ON p.id_usuario = u.id_usuario
    WHERE p.id_profesor = ?
");
$stmt->execute([$id_profesor]);
$profesor = $stmt->fetch();

if (!$profesor) {
    Funciones::redireccionar('index.php', 'Profesor no encontrado', 'error');
}

$programas = Funciones::obtenerProgramas();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Programas dictados (SET)
        $programas_dictados = [];
        if (isset($_POST['programas_dictados']) && is_array($_POST['programas_dictados'])) {
            $programas_dictados = $_POST['programas_dictados'];
        }
        $programas_str = implode(',', $programas_dictados);
        
        // Verificar si hay cambios
        $programas_actuales = explode(',', $profesor['programas_dictados']);
        $programas_actuales = array_map('trim', $programas_actuales);
        
        if (sort($programas_dictados) != sort($programas_actuales)) {
            $stmt = $db->prepare("
                UPDATE profesores 
                SET programas_dictados = ?
                WHERE id_profesor = ?
            ");
            
            $stmt->execute([$programas_str, $id_profesor]);
            
            // Registrar cambio
            $stmt = $db->prepare("
                INSERT INTO cambios_horario 
                (tipo_cambio, valor_anterior, valor_nuevo, realizado_por, motivo, id_horario)
                VALUES (?, ?, ?, ?, ?, NULL)
            ");
            
            $valor_anterior = empty($profesor['programas_dictados']) ? 'Ninguno' : $profesor['programas_dictados'];
            $valor_nuevo = empty($programas_str) ? 'Ninguno' : $programas_str;
            
            $stmt->execute([
                'profesor', 
                "Programas asignados a {$profesor['nombres']} {$profesor['apellidos']}: {$valor_anterior}",
                "Programas asignados a {$profesor['nombres']} {$profesor['apellidos']}: {$valor_nuevo}",
                $user['id'],
                'Actualización de programas desde panel'
            ]);
            
            // Enviar notificación al profesor
            $stmt = $db->prepare("
                INSERT INTO notificaciones (id_usuario, tipo_notificacion, titulo, mensaje)
                VALUES (?, 'sistema', 'Programas actualizados', ?)
            ");
            
            $mensaje = "Sus programas asignados han sido actualizados.<br>";
            $mensaje .= "Programas actuales: " . (empty($programas_str) ? 'Ninguno' : $programas_str);
            
            $stmt->execute([$profesor['id_usuario'], $mensaje]);
            
            Session::setFlash('Programas asignados exitosamente');
        } else {
            Session::setFlash('No se detectaron cambios en los programas', 'info');
        }
        
        Funciones::redireccionar('ver.php?id=' . $id_profesor);
        
    } catch (Exception $e) {
        Session::setFlash('Error al asignar programas: ' . $e->getMessage(), 'error');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asignar Programas - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .programas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin: 25px 0;
        }
        
        .programa-checkbox {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 18px;
            border: 2px solid var(--gray-300);
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }
        
        .programa-checkbox:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }
        
        .programa-checkbox.checked {
            border-color: var(--primary);
            background: linear-gradient(135deg, var(--primary-light) 0%, rgba(59, 130, 246, 0.1) 100%);
            box-shadow: var(--shadow);
        }
        
        .programa-checkbox input[type="checkbox"] {
            margin: 0;
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .programa-info {
            flex: 1;
        }
        
        .programa-info strong {
            color: var(--dark);
            font-size: 15px;
            display: block;
            margin-bottom: 3px;
        }
        
        .programa-info .help-text {
            color: var(--gray-500);
            font-size: 13px;
            margin-top: 3px;
        }
        
        .stats-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            background: var(--gray-100);
            border-radius: 12px;
            font-size: 12px;
            color: var(--gray-600);
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            padding-top: 25px;
            margin-top: 25px;
            border-top: 2px solid var(--gray-100);
        }
        
        .nav .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: var(--radius);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .nav .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-graduation-cap"></i> Asignar Programas al Profesor</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
                <a href="ver.php?id=<?php echo $id_profesor; ?>"><i class="fas fa-eye"></i> Ver</a>
                <a href="asignar_programas.php?id=<?php echo $id_profesor; ?>" class="active">
                    <i class="fas fa-graduation-cap"></i> Asignar Programas
                </a>
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
            <div class="card-header">
                <h2>
                    <i class="fas fa-chalkboard-teacher"></i>
                    <?php echo htmlspecialchars($profesor['nombres'] . ' ' . $profesor['apellidos']); ?>
                </h2>
                <p class="text-muted">
                    <?php echo $profesor['titulo_academico']; ?> | 
                    Código: <?php echo $profesor['codigo_profesor']; ?>
                </p>
            </div>
            
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Seleccione los programas</strong> en los que este profesor puede dictar clases.
                    Un profesor puede estar asignado a múltiples programas simultáneamente.
                    <br><br>
                    <small><i class="fas fa-lightbulb"></i> Los cambios afectarán a la asignación de nuevos horarios.</small>
                </div>
                
                <form method="POST">
                    <div class="programas-grid">
                        <?php 
                        $programas_actuales = explode(',', $profesor['programas_dictados']);
                        $programas_actuales = array_map('trim', $programas_actuales);
                        ?>
                        
                        <?php foreach ($programas as $programa): ?>
                            <?php
                            // Contar cursos del programa
                            $stmt = $db->prepare("
                                SELECT COUNT(*) as total_cursos
                                FROM cursos 
                                WHERE id_programa = ? AND activo = 1
                            ");
                            $stmt->execute([$programa['id_programa']]);
                            $total_cursos = $stmt->fetch()['total_cursos'];
                            
                            // Contar profesores en este programa
                            $stmt = $db->prepare("
                                SELECT COUNT(*) as total_profesores
                                FROM profesores 
                                WHERE programas_dictados LIKE ? AND activo = 1
                            ");
                            $stmt->execute(['%' . $programa['nombre_programa'] . '%']);
                            $total_profesores = $stmt->fetch()['total_profesores'];
                            ?>
                            
                            <label class="programa-checkbox <?php echo in_array($programa['nombre_programa'], $programas_actuales) ? 'checked' : ''; ?>">
                                <input type="checkbox" name="programas_dictados[]" 
                                       value="<?php echo htmlspecialchars($programa['nombre_programa']); ?>"
                                       <?php echo in_array($programa['nombre_programa'], $programas_actuales) ? 'checked' : ''; ?>
                                       onchange="toggleCheckbox(this)">
                                <div class="programa-info">
                                    <strong><?php echo htmlspecialchars($programa['nombre_programa']); ?></strong>
                                    <div class="help-text">Código: <?php echo $programa['codigo_programa']; ?></div>
                                    
                                    <div style="margin-top: 8px; display: flex; gap: 10px;">
                                        <span class="stats-badge">
                                            <i class="fas fa-book"></i> <?php echo $total_cursos; ?> cursos
                                        </span>
                                        <span class="stats-badge">
                                            <i class="fas fa-users"></i> <?php echo $total_profesores; ?> profesores
                                        </span>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Importante:</strong> Al remover un programa, el profesor no podrá ser asignado 
                        a nuevos horarios de ese programa. Los horarios existentes no se verán afectados.
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Guardar Asignación
                        </button>
                        <a href="ver.php?id=<?php echo $id_profesor; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                        <button type="button" class="btn btn-info" onclick="seleccionarTodos()">
                            <i class="fas fa-check-double"></i> Seleccionar Todos
                        </button>
                        <button type="button" class="btn btn-info" onclick="deseleccionarTodos()">
                            <i class="fas fa-times"></i> Deseleccionar Todos
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Información adicional -->
        <div class="card" style="margin-top: 20px;">
            <div class="card-header">
                <h3><i class="fas fa-lightbulb"></i> ¿Cómo funciona la asignación de programas?</h3>
            </div>
            <div class="card-body">
                <div class="grid-2">
                    <div>
                        <h4><i class="fas fa-check-circle text-success"></i> Permite:</h4>
                        <ul style="line-height: 1.8;">
                            <li>Asignar al profesor a horarios de los programas seleccionados</li>
                            <li>Que el profesor vea solo los cursos de sus programas asignados</li>
                            <li>Múltiples programas simultáneamente</li>
                            <li>Cambios en cualquier momento</li>
                        </ul>
                    </div>
                    <div>
                        <h4><i class="fas fa-times-circle text-danger"></i> No permite:</h4>
                        <ul style="line-height: 1.8;">
                            <li>Asignar a programas no seleccionados</li>
                            <li>Modificar horarios existentes automáticamente</li>
                            <li>Acceso a información de otros programas</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function toggleCheckbox(checkbox) {
            const label = checkbox.closest('.programa-checkbox');
            if (checkbox.checked) {
                label.classList.add('checked');
            } else {
                label.classList.remove('checked');
            }
        }
        
        function seleccionarTodos() {
            document.querySelectorAll('.programa-checkbox input[type="checkbox"]').forEach(checkbox => {
                checkbox.checked = true;
                toggleCheckbox(checkbox);
            });
        }
        
        function deseleccionarTodos() {
            document.querySelectorAll('.programa-checkbox input[type="checkbox"]').forEach(checkbox => {
                checkbox.checked = false;
                toggleCheckbox(checkbox);
            });
        }
        
        // Inicializar checkboxes
        document.querySelectorAll('.programa-checkbox input[type="checkbox"]').forEach(checkbox => {
            toggleCheckbox(checkbox);
        });
        
        // Confirmar antes de salir si hay cambios
        let formChanged = false;
        const form = document.querySelector('form');
        const initialValues = [];
        
        // Guardar estado inicial
        document.querySelectorAll('.programa-checkbox input[type="checkbox"]').forEach((checkbox, index) => {
            initialValues[index] = checkbox.checked;
        });
        
        // Detectar cambios
        form.addEventListener('change', function() {
            let changed = false;
            document.querySelectorAll('.programa-checkbox input[type="checkbox"]').forEach((checkbox, index) => {
                if (checkbox.checked !== initialValues[index]) {
                    changed = true;
                }
            });
            formChanged = changed;
        });
        
        // Confirmar antes de salir
        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = 'Tiene cambios sin guardar. ¿Está seguro de querer salir?';
            }
        });
        
        // Limpiar el evento al enviar el formulario
        form.addEventListener('submit', function() {
            formChanged = false;
        });
    </script>
</body>
</html>