<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

// Obtener coordinadores disponibles
$coordinadores = $db->query("
    SELECT id_usuario, username, email
    FROM usuarios 
    WHERE rol = 'coordinador' AND activo = 1
    ORDER BY username
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $codigo_programa = Funciones::sanitizar($_POST['codigo_programa']);
        $nombre_programa = Funciones::sanitizar($_POST['nombre_programa']);
        $duracion_semestres = intval($_POST['duracion_semestres']);
        $descripcion = Funciones::sanitizar($_POST['descripcion']);
        $coordinador_id = !empty($_POST['coordinador_id']) ? intval($_POST['coordinador_id']) : null;
        
        // Verificar si el código ya existe
        $stmt = $db->prepare("SELECT id_programa FROM programas_estudio WHERE codigo_programa = ?");
        $stmt->execute([$codigo_programa]);
        if ($stmt->fetch()) {
            throw new Exception("El código del programa ya está registrado");
        }
        
        // Verificar si el nombre ya existe
        $stmt = $db->prepare("SELECT id_programa FROM programas_estudio WHERE nombre_programa = ?");
        $stmt->execute([$nombre_programa]);
        if ($stmt->fetch()) {
            throw new Exception("El nombre del programa ya está registrado");
        }
        
        // Crear programa
        $stmt = $db->prepare("
            INSERT INTO programas_estudio 
            (codigo_programa, nombre_programa, duracion_semestres, descripcion, coordinador_id, activo)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        
        $stmt->execute([
            $codigo_programa, 
            $nombre_programa, 
            $duracion_semestres, 
            $descripcion, 
            $coordinador_id
        ]);
        
        $id_programa = $db->lastInsertId();
        
        // Si se asignó un coordinador, notificar
        if ($coordinador_id) {
            $stmt = $db->prepare("
                INSERT INTO notificaciones (id_usuario, tipo_notificacion, titulo, mensaje)
                VALUES (?, 'sistema', 'Asignación como coordinador', ?)
            ");
            
            $mensaje = "Ha sido asignado como coordinador del programa: <strong>$nombre_programa</strong><br>";
            $mensaje .= "Código: $codigo_programa<br>";
            $mensaje .= "Duración: $duracion_semestres semestres<br>";
            $mensaje .= "Ahora puede gestionar este programa desde el sistema.";
            
            $stmt->execute([$coordinador_id, $mensaje]);
        }
        
        Session::setFlash('Programa creado exitosamente');
        Funciones::redireccionar('index.php');
        
    } catch (Exception $e) {
        Session::setFlash('Error al crear programa: ' . $e->getMessage(), 'error');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Programa - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .form-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-700);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .required {
            color: var(--danger);
        }
        
        .help-text {
            font-size: 13px;
            color: var(--gray-500);
            margin-top: 5px;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            padding-top: 25px;
            margin-top: 25px;
            border-top: 2px solid var(--gray-100);
        }
        
        .info-box {
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            border-left: 4px solid var(--primary);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .info-box h4 {
            color: var(--dark);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
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
            <h1><i class="fas fa-plus"></i> Crear Nuevo Programa</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-graduation-cap"></i> Programas</a>
                <a href="crear.php" class="active"><i class="fas fa-plus"></i> Nuevo Programa</a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <h4><i class="fas fa-info-circle"></i> Información importante</h4>
            <p>Los programas de estudio son la base del sistema. Cada programa:</p>
            <ul style="margin: 10px 0 10px 20px; line-height: 1.8;">
                <li>Contiene cursos específicos por semestre</li>
                <li>Tiene estudiantes inscritos</li>
                <li>Puede tener un coordinador asignado</li>
                <li>Define la duración total de la carrera</li>
                <li>Organiza los horarios académicos</li>
            </ul>
        </div>
        
        <form method="POST" class="card form-container">
            <div class="card-header">
                <h2><i class="fas fa-graduation-cap"></i> Información del Programa</h2>
            </div>
            
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="codigo_programa">
                            <i class="fas fa-hashtag"></i> Código del Programa
                            <span class="required">*</span>
                        </label>
                        <input type="text" id="codigo_programa" name="codigo_programa" required
                               class="form-control"
                               placeholder="Ej: TOP-001, ARQ-001">
                        <div class="help-text">Código único para identificar el programa</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="nombre_programa">
                            <i class="fas fa-font"></i> Nombre del Programa
                            <span class="required">*</span>
                        </label>
                        <input type="text" id="nombre_programa" name="nombre_programa" required
                               class="form-control"
                               placeholder="Ej: Topografía, Arquitectura, Enfermería">
                        <div class="help-text">Nombre completo del programa de estudio</div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="duracion_semestres">
                            <i class="fas fa-calendar-alt"></i> Duración (semestres)
                            <span class="required">*</span>
                        </label>
                        <select id="duracion_semestres" name="duracion_semestres" required class="form-control">
                            <option value="">Seleccionar duración</option>
                            <option value="4">4 semestres (2 años)</option>
                            <option value="6" selected>6 semestres (3 años)</option>
                            <option value="8">8 semestres (4 años)</option>
                            <option value="10">10 semestres (5 años)</option>
                        </select>
                        <div class="help-text">Número total de semestres del programa</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="coordinador_id">
                            <i class="fas fa-user-tie"></i> Coordinador (opcional)
                        </label>
                        <select id="coordinador_id" name="coordinador_id" class="form-control">
                            <option value="">Sin coordinador asignado</option>
                            <?php foreach ($coordinadores as $coordinador): ?>
                                <option value="<?php echo $coordinador['id_usuario']; ?>">
                                    <?php echo htmlspecialchars($coordinador['username']); ?> 
                                    (<?php echo $coordinador['email']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">Puede asignar un coordinador ahora o después</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="descripcion">
                        <i class="fas fa-align-left"></i> Descripción
                    </label>
                    <textarea id="descripcion" name="descripcion" class="form-control" 
                              rows="5" placeholder="Describa el programa de estudio, objetivos, perfil del egresado..."></textarea>
                    <div class="help-text">Información detallada sobre el programa (opcional)</div>
                </div>
                
                <!-- Botones -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Programa
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
            </div>
        </form>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-generar código basado en nombre
            const nombreInput = document.getElementById('nombre_programa');
            const codigoInput = document.getElementById('codigo_programa');
            
            nombreInput.addEventListener('blur', function() {
                if (!codigoInput.value) {
                    const nombre = this.value.trim();
                    if (nombre.length > 0) {
                        // Tomar primeras 3 letras y poner en mayúscula
                        let codigo = nombre.substring(0, 3).toUpperCase();
                        // Quitar espacios y caracteres especiales
                        codigo = codigo.replace(/[^A-Z]/g, '');
                        
                        if (codigo.length === 3) {
                            // Verificar si ya existe
                            fetch(`verificar_codigo.php?codigo=${codigo}-001`)
                                .then(response => response.json())
                                .then(data => {
                                    if (!data.existe) {
                                        codigoInput.value = codigo + '-001';
                                    }
                                });
                        }
                    }
                }
            });
            
            // Validar formulario
            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                const codigo = codigoInput.value.trim();
                const nombre = nombreInput.value.trim();
                const duracion = document.getElementById('duracion_semestres').value;
                
                if (!codigo || !nombre || !duracion) {
                    e.preventDefault();
                    alert('Por favor complete todos los campos obligatorios');
                    return false;
                }
                
                // Validar formato de código (ej: ABC-001)
                const codigoRegex = /^[A-Z]{3}-\d{3}$/;
                if (!codigoRegex.test(codigo)) {
                    e.preventDefault();
                    alert('El código debe tener el formato: ABC-001 (3 letras, guión, 3 números)');
                    codigoInput.focus();
                    return false;
                }
                
                return true;
            });
        });
    </script>
</body>
</html>