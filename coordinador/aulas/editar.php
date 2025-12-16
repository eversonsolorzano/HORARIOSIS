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

// Obtener datos actuales del aula
$stmt = $db->prepare("SELECT * FROM aulas WHERE id_aula = ?");
$stmt->execute([$id_aula]);
$aula = $stmt->fetch();

if (!$aula) {
    Funciones::redireccionar('index.php', 'Aula no encontrada', 'error');
}

// Verificar si hay horarios activos en esta aula
$stmt = $db->prepare("SELECT COUNT(*) FROM horarios WHERE id_aula = ? AND activo = 1");
$stmt->execute([$id_aula]);
$horarios_activos = $stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $codigo_aula = Funciones::sanitizar($_POST['codigo_aula']);
    $nombre_aula = Funciones::sanitizar($_POST['nombre_aula']);
    $capacidad = intval($_POST['capacidad']);
    $piso = !empty($_POST['piso']) ? intval($_POST['piso']) : NULL;
    $edificio = !empty($_POST['edificio']) ? Funciones::sanitizar($_POST['edificio']) : NULL;
    $tipo_aula = Funciones::sanitizar($_POST['tipo_aula']);
    $equipamiento = !empty($_POST['equipamiento']) ? Funciones::sanitizar($_POST['equipamiento']) : NULL;
    $programas_permitidos = isset($_POST['programas_permitidos']) ? $_POST['programas_permitidos'] : [];
    $disponible = isset($_POST['disponible']) ? 1 : 0;
    
    // Convertir array de programas a string SET
    $programas_str = implode(',', $programas_permitidos);
    
    // Validaciones
    $errores = [];
    
    if (empty($codigo_aula)) {
        $errores[] = 'El código del aula es requerido';
    }
    
    if (empty($nombre_aula)) {
        $errores[] = 'El nombre del aula es requerido';
    }
    
    if ($capacidad <= 0) {
        $errores[] = 'La capacidad debe ser mayor a 0';
    }
    
    if ($capacidad > 500) {
        $errores[] = 'La capacidad no puede exceder los 500 estudiantes';
    }
    
    // Verificar si el código ya existe en otra aula
    if ($codigo_aula != $aula['codigo_aula']) {
        $stmt = $db->prepare("SELECT id_aula FROM aulas WHERE codigo_aula = ? AND id_aula != ?");
        $stmt->execute([$codigo_aula, $id_aula]);
        if ($stmt->fetch()) {
            $errores[] = 'El código del aula ya está registrado en otra aula';
        }
    }
    
    // Verificar si se está reduciendo la capacidad por debajo de estudiantes inscritos
    if ($capacidad < $aula['capacidad'] && $horarios_activos > 0) {
        // Obtener el máximo de estudiantes inscritos en esta aula
        $stmt = $db->prepare("
            SELECT MAX(estudiantes_por_horario) as max_estudiantes
            FROM (
                SELECT COUNT(i.id_inscripcion) as estudiantes_por_horario
                FROM horarios h
                LEFT JOIN inscripciones i ON h.id_horario = i.id_horario AND i.estado = 'inscrito'
                WHERE h.id_aula = ? AND h.activo = 1
                GROUP BY h.id_horario
            ) as conteos
        ");
        $stmt->execute([$id_aula]);
        $max_estudiantes = $stmt->fetchColumn();
        
        if ($max_estudiantes && $capacidad < $max_estudiantes) {
            $errores[] = "No puede reducir la capacidad a $capacidad porque hay horarios con hasta $max_estudiantes estudiantes inscritos";
        }
    }
    
    if (empty($errores)) {
        try {
            $stmt = $db->prepare("
                UPDATE aulas SET
                    codigo_aula = ?,
                    nombre_aula = ?,
                    capacidad = ?,
                    piso = ?,
                    edificio = ?,
                    tipo_aula = ?,
                    equipamiento = ?,
                    programas_permitidos = ?,
                    disponible = ?
                WHERE id_aula = ?
            ");
            
            $stmt->execute([
                $codigo_aula,
                $nombre_aula,
                $capacidad,
                $piso,
                $edificio,
                $tipo_aula,
                $equipamiento,
                $programas_str,
                $disponible,
                $id_aula
            ]);
            
            Session::setFlash('Aula actualizada exitosamente');
            Funciones::redireccionar("ver.php?id=$id_aula");
            
        } catch (Exception $e) {
            Session::setFlash('Error al actualizar el aula: ' . $e->getMessage(), 'error');
        }
    } else {
        Session::setFlash(implode('<br>', $errores), 'error');
    }
    
    // Actualizar datos del formulario
    $aula = array_merge($aula, $_POST);
}

// Obtener lista de programas para el select
$programas = $db->query("SELECT nombre_programa FROM programas_estudio WHERE activo = 1")->fetchAll();

// Convertir string SET a array para el formulario
$programas_permitidos = !empty($aula['programas_permitidos']) ? explode(',', $aula['programas_permitidos']) : [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Aula - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .form-section {
            background: white;
            padding: 25px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            box-shadow: var(--shadow);
        }
        
        .form-section h3 {
            color: var(--dark);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border-left: 6px solid #ffc107;
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .alert-warning h4 {
            color: #856404;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-edit"></i> Editar Aula: <?php echo htmlspecialchars($aula['codigo_aula']); ?></h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-school"></i> Aulas</a>
                <a href="ver.php?id=<?php echo $id_aula; ?>"><i class="fas fa-eye"></i> Ver Aula</a>
                <a href="editar.php?id=<?php echo $id_aula; ?>" class="active"><i class="fas fa-edit"></i> Editar</a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($horarios_activos > 0): ?>
        <div class="alert-warning">
            <h4><i class="fas fa-exclamation-triangle"></i> Advertencia</h4>
            <p>Esta aula tiene <strong><?php echo $horarios_activos; ?> horarios activos</strong> asignados.</p>
            <p>Algunos cambios (como reducir la capacidad) podrían afectar a los estudiantes ya inscritos.</p>
        </div>
        <?php endif; ?>
        
        <form method="POST" id="formAula">
            <!-- Información Básica -->
            <div class="form-section">
                <h3><i class="fas fa-info-circle"></i> Información Básica</h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="codigo_aula">Código del Aula *</label>
                        <input type="text" 
                               name="codigo_aula" 
                               id="codigo_aula" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($aula['codigo_aula']); ?>"
                               placeholder="Ej: A-101, LAB-201, TALL-301" 
                               required>
                        <small class="text-muted">Código único de identificación del aula</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="nombre_aula">Nombre del Aula *</label>
                        <input type="text" 
                               name="nombre_aula" 
                               id="nombre_aula" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($aula['nombre_aula']); ?>"
                               placeholder="Ej: Aula Principal, Laboratorio de Química" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="capacidad">Capacidad (estudiantes) *</label>
                        <input type="number" 
                               name="capacidad" 
                               id="capacidad" 
                               class="form-control" 
                               value="<?php echo $aula['capacidad']; ?>"
                               min="1" 
                               max="500" 
                               required>
                        <div class="capacity-preview">
                            <div class="capacity-visual">
                                <div class="capacity-fill" id="capacityFill"></div>
                            </div>
                            <div class="capacity-numbers">
                                <span id="capacityText"><?php echo $aula['capacidad']; ?> estudiantes</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Ubicación -->
            <div class="form-section">
                <h3><i class="fas fa-map-marker-alt"></i> Ubicación</h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edificio">Edificio</label>
                        <input type="text" 
                               name="edificio" 
                               id="edificio" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($aula['edificio'] ?? ''); ?>"
                               placeholder="Ej: Edificio Principal, Edificio de Ciencias">
                    </div>
                    
                    <div class="form-group">
                        <label for="piso">Piso</label>
                        <input type="number" 
                               name="piso" 
                               id="piso" 
                               class="form-control" 
                               value="<?php echo $aula['piso'] ?? ''; ?>"
                               min="0" 
                               max="20"
                               placeholder="Número de piso">
                    </div>
                </div>
            </div>
            
            <!-- Tipo de Aula -->
            <div class="form-section">
                <h3><i class="fas fa-tag"></i> Tipo de Aula *</h3>
                
                <div class="tipo-options">
                    <label class="tipo-option <?php echo $aula['tipo_aula'] == 'normal' ? 'selected' : ''; ?>" for="tipo_normal">
                        <input type="radio" name="tipo_aula" id="tipo_normal" value="normal" 
                               <?php echo $aula['tipo_aula'] == 'normal' ? 'checked' : ''; ?> style="display: none;">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Aula Normal</span>
                        <small>Para clases teóricas regulares</small>
                    </label>
                    
                    <label class="tipo-option <?php echo $aula['tipo_aula'] == 'laboratorio' ? 'selected' : ''; ?>" for="tipo_laboratorio">
                        <input type="radio" name="tipo_aula" id="tipo_laboratorio" value="laboratorio" 
                               <?php echo $aula['tipo_aula'] == 'laboratorio' ? 'checked' : ''; ?> style="display: none;">
                        <i class="fas fa-flask"></i>
                        <span>Laboratorio</span>
                        <small>Para prácticas científicas</small>
                    </label>
                    
                    <label class="tipo-option <?php echo $aula['tipo_aula'] == 'taller' ? 'selected' : ''; ?>" for="tipo_taller">
                        <input type="radio" name="tipo_aula" id="tipo_taller" value="taller" 
                               <?php echo $aula['tipo_aula'] == 'taller' ? 'checked' : ''; ?> style="display: none;">
                        <i class="fas fa-tools"></i>
                        <span>Taller</span>
                        <small>Para actividades prácticas</small>
                    </label>
                    
                    <label class="tipo-option <?php echo $aula['tipo_aula'] == 'clinica' ? 'selected' : ''; ?>" for="tipo_clinica">
                        <input type="radio" name="tipo_aula" id="tipo_clinica" value="clinica" 
                               <?php echo $aula['tipo_aula'] == 'clinica' ? 'checked' : ''; ?> style="display: none;">
                        <i class="fas fa-clinic-medical"></i>
                        <span>Clínica</span>
                        <small>Para prácticas de enfermería</small>
                    </label>
                    
                    <label class="tipo-option <?php echo $aula['tipo_aula'] == 'aula_especial' ? 'selected' : ''; ?>" for="tipo_aula_especial">
                        <input type="radio" name="tipo_aula" id="tipo_aula_especial" value="aula_especial" 
                               <?php echo $aula['tipo_aula'] == 'aula_especial' ? 'checked' : ''; ?> style="display: none;">
                        <i class="fas fa-star"></i>
                        <span>Aula Especial</span>
                        <small>Para usos específicos</small>
                    </label>
                </div>
            </div>
            
            <!-- Programas Permitidos -->
            <div class="form-section">
                <h3><i class="fas fa-graduation-cap"></i> Programas Permitidos</h3>
                
                <div class="checkbox-group">
                    <?php 
                    $todos_programas = empty($programas_permitidos) || 
                                      (count($programas_permitidos) == 1 && $programas_permitidos[0] == '');
                    ?>
                    <div class="checkbox-item">
                        <input type="checkbox" name="programas_permitidos[]" id="programa_todas" value="" 
                               <?php echo $todos_programas ? 'checked' : ''; ?>>
                        <label for="programa_todas">Todos los programas</label>
                    </div>
                    
                    <?php foreach ($programas as $programa): 
                        $seleccionado = in_array($programa['nombre_programa'], $programas_permitidos);
                    ?>
                    <div class="checkbox-item">
                        <input type="checkbox" name="programas_permitidos[]" 
                               id="programa_<?php echo strtolower($programa['nombre_programa']); ?>" 
                               value="<?php echo htmlspecialchars($programa['nombre_programa']); ?>"
                               <?php echo $seleccionado ? 'checked' : ''; ?>
                               <?php echo $todos_programas ? 'disabled' : ''; ?>>
                        <label for="programa_<?php echo strtolower($programa['nombre_programa']); ?>">
                            <?php echo htmlspecialchars($programa['nombre_programa']); ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="form-note">
                    <i class="fas fa-info-circle"></i> Seleccione los programas que pueden utilizar esta aula. 
                    Si selecciona "Todos los programas", no es necesario seleccionar los demás.
                </div>
            </div>
            
            <!-- Equipamiento -->
            <div class="form-section">
                <h3><i class="fas fa-toolbox"></i> Equipamiento</h3>
                
                <div class="form-group">
                    <label for="equipamiento">Descripción del equipamiento</label>
                    <textarea name="equipamiento" 
                              id="equipamiento" 
                              class="form-control equipamiento-box"
                              placeholder="Describa el equipamiento disponible en el aula (proyectores, computadoras, equipos de laboratorio, etc.)"><?php echo htmlspecialchars($aula['equipamiento'] ?? ''); ?></textarea>
                    <small class="text-muted">Separe los items con comas o puntos</small>
                </div>
            </div>
            
            <!-- Estado -->
            <div class="form-section">
                <h3><i class="fas fa-toggle-on"></i> Estado</h3>
                
                <div class="form-group">
                    <div class="checkbox-item">
                        <input type="checkbox" name="disponible" id="disponible" value="1" 
                               <?php echo $aula['disponible'] ? 'checked' : ''; ?>>
                        <label for="disponible">Aula disponible para uso</label>
                    </div>
                    <small class="text-muted">Si desactiva esta opción, el aula no podrá ser asignada en horarios</small>
                </div>
            </div>
            
            <!-- Botones de acción -->
            <div class="form-actions">
                <a href="ver.php?id=<?php echo $id_aula; ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancelar
                </a>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Guardar Cambios
                </button>
            </div>
        </form>
    </div>
    
    <script>
        // Incluir los mismos scripts que en crear.php
        document.querySelectorAll('.tipo-option').forEach(option => {
            option.addEventListener('click', function() {
                document.querySelectorAll('.tipo-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                this.classList.add('selected');
                const radio = this.querySelector('input[type="radio"]');
                if (radio) radio.checked = true;
            });
        });
        
        const capacityInput = document.getElementById('capacidad');
        const capacityFill = document.getElementById('capacityFill');
        const capacityText = document.getElementById('capacityText');
        
        function updateCapacity() {
            const capacity = parseInt(capacityInput.value) || <?php echo $aula['capacidad']; ?>;
            const percentage = Math.min((capacity / 500) * 100, 100);
            
            capacityFill.style.width = percentage + '%';
            capacityText.textContent = capacity + ' estudiantes';
            
            if (capacity > 100) {
                capacityFill.style.background = 'var(--success)';
            } else if (capacity > 50) {
                capacityFill.style.background = 'var(--primary)';
            } else {
                capacityFill.style.background = 'var(--warning)';
            }
        }
        
        capacityInput.addEventListener('input', updateCapacity);
        updateCapacity();
        
        const todasCheckbox = document.getElementById('programa_todas');
        const otrosCheckboxes = document.querySelectorAll('input[name="programas_permitidos[]"]:not(#programa_todas)');
        
        todasCheckbox.addEventListener('change', function() {
            if (this.checked) {
                otrosCheckboxes.forEach(cb => {
                    cb.checked = false;
                    cb.disabled = true;
                });
            } else {
                otrosCheckboxes.forEach(cb => {
                    cb.disabled = false;
                });
            }
        });
        
        otrosCheckboxes.forEach(cb => {
            cb.addEventListener('change', function() {
                if (this.checked) {
                    todasCheckbox.checked = false;
                }
                
                const allChecked = Array.from(otrosCheckboxes).every(cb => cb.checked);
                if (allChecked) {
                    todasCheckbox.checked = true;
                    otrosCheckboxes.forEach(cb => cb.disabled = true);
                }
            });
        });
        
        document.getElementById('formAula').addEventListener('submit', function(e) {
            const codigo = document.getElementById('codigo_aula').value.trim();
            const nombre = document.getElementById('nombre_aula').value.trim();
            const capacidad = document.getElementById('capacidad').value;
            
            if (!codigo) {
                e.preventDefault();
                alert('El código del aula es requerido');
                document.getElementById('codigo_aula').focus();
                return false;
            }
            
            if (!nombre) {
                e.preventDefault();
                alert('El nombre del aula es requerido');
                document.getElementById('nombre_aula').focus();
                return false;
            }
            
            if (capacidad < 1 || capacidad > 500) {
                e.preventDefault();
                alert('La capacidad debe estar entre 1 y 500 estudiantes');
                document.getElementById('capacidad').focus();
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>