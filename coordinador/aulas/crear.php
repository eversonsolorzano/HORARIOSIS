<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

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
    
    // Verificar si el código ya existe
    $stmt = $db->prepare("SELECT id_aula FROM aulas WHERE codigo_aula = ?");
    $stmt->execute([$codigo_aula]);
    if ($stmt->fetch()) {
        $errores[] = 'El código del aula ya está registrado';
    }
    
    if (empty($errores)) {
        try {
            $stmt = $db->prepare("
                INSERT INTO aulas 
                (codigo_aula, nombre_aula, capacidad, piso, edificio, tipo_aula, 
                 equipamiento, programas_permitidos, disponible)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                $disponible
            ]);
            
            // Obtener el ID del aula creada
            $id_aula = $db->lastInsertId();
            
            Session::setFlash('Aula creada exitosamente');
            Funciones::redireccionar("ver.php?id=$id_aula");
            
        } catch (Exception $e) {
            Session::setFlash('Error al crear el aula: ' . $e->getMessage(), 'error');
        }
    } else {
        Session::setFlash(implode('<br>', $errores), 'error');
    }
}

// Obtener lista de programas para el select
$programas = $db->query("SELECT nombre_programa FROM programas_estudio WHERE activo = 1")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Aula - Sistema de Horarios</title>
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
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .equipamiento-box {
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            padding: 10px;
            min-height: 100px;
            resize: vertical;
        }
        
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .capacity-preview {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        
        .capacity-visual {
            flex: 1;
            height: 10px;
            background: var(--gray-300);
            border-radius: 5px;
            overflow: hidden;
        }
        
        .capacity-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 5px;
            width: 50%;
        }
        
        .form-note {
            background: var(--gray-50);
            padding: 15px;
            border-radius: var(--radius);
            margin-top: 15px;
            font-size: 14px;
            color: var(--gray-600);
        }
        
        .tipo-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        
        .tipo-option {
            border: 2px solid var(--gray-300);
            border-radius: var(--radius);
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .tipo-option:hover {
            border-color: var(--primary);
            background: var(--gray-50);
        }
        
        .tipo-option.selected {
            border-color: var(--primary);
            background: var(--primary-light);
        }
        
        .tipo-option i {
            font-size: 24px;
            margin-bottom: 10px;
            color: var(--primary);
        }
        
        .tipo-option span {
            display: block;
            font-weight: 600;
            color: var(--dark);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-plus-circle"></i> Crear Nueva Aula</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-school"></i> Aulas</a>
                <a href="crear.php" class="active"><i class="fas fa-plus-circle"></i> Nueva Aula</a>
            </div>
        </div>
        
        <?php 
        $flash = Session::getFlash();
        if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <?php echo $flash['message']; ?>
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
                               value="<?php echo isset($_POST['codigo_aula']) ? htmlspecialchars($_POST['codigo_aula']) : ''; ?>"
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
                               value="<?php echo isset($_POST['nombre_aula']) ? htmlspecialchars($_POST['nombre_aula']) : ''; ?>"
                               placeholder="Ej: Aula Principal, Laboratorio de Química" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="capacidad">Capacidad (estudiantes) *</label>
                        <input type="number" 
                               name="capacidad" 
                               id="capacidad" 
                               class="form-control" 
                               value="<?php echo isset($_POST['capacidad']) ? $_POST['capacidad'] : 30; ?>"
                               min="1" 
                               max="500" 
                               required>
                        <div class="capacity-preview">
                            <div class="capacity-visual">
                                <div class="capacity-fill" id="capacityFill"></div>
                            </div>
                            <div class="capacity-numbers">
                                <span id="capacityText">30 estudiantes</span>
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
                               value="<?php echo isset($_POST['edificio']) ? htmlspecialchars($_POST['edificio']) : ''; ?>"
                               placeholder="Ej: Edificio Principal, Edificio de Ciencias">
                    </div>
                    
                    <div class="form-group">
                        <label for="piso">Piso</label>
                        <input type="number" 
                               name="piso" 
                               id="piso" 
                               class="form-control" 
                               value="<?php echo isset($_POST['piso']) ? $_POST['piso'] : ''; ?>"
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
                    <label class="tipo-option" for="tipo_normal">
                        <input type="radio" name="tipo_aula" id="tipo_normal" value="normal" checked style="display: none;">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Aula Normal</span>
                        <small>Para clases teóricas regulares</small>
                    </label>
                    
                    <label class="tipo-option" for="tipo_laboratorio">
                        <input type="radio" name="tipo_aula" id="tipo_laboratorio" value="laboratorio" style="display: none;">
                        <i class="fas fa-flask"></i>
                        <span>Laboratorio</span>
                        <small>Para prácticas científicas</small>
                    </label>
                    
                    <label class="tipo-option" for="tipo_taller">
                        <input type="radio" name="tipo_aula" id="tipo_taller" value="taller" style="display: none;">
                        <i class="fas fa-tools"></i>
                        <span>Taller</span>
                        <small>Para actividades prácticas</small>
                    </label>
                    
                    <label class="tipo-option" for="tipo_clinica">
                        <input type="radio" name="tipo_aula" id="tipo_clinica" value="clinica" style="display: none;">
                        <i class="fas fa-clinic-medical"></i>
                        <span>Clínica</span>
                        <small>Para prácticas de enfermería</small>
                    </label>
                    
                    <label class="tipo-option" for="tipo_aula_especial">
                        <input type="radio" name="tipo_aula" id="tipo_aula_especial" value="aula_especial" style="display: none;">
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
                    <div class="checkbox-item">
                        <input type="checkbox" name="programas_permitidos[]" id="programa_todas" value="" checked>
                        <label for="programa_todas">Todos los programas</label>
                    </div>
                    
                    <?php foreach ($programas as $programa): ?>
                    <div class="checkbox-item">
                        <input type="checkbox" name="programas_permitidos[]" id="programa_<?php echo strtolower($programa['nombre_programa']); ?>" 
                               value="<?php echo htmlspecialchars($programa['nombre_programa']); ?>">
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
                              placeholder="Describa el equipamiento disponible en el aula (proyectores, computadoras, equipos de laboratorio, etc.)"><?php echo isset($_POST['equipamiento']) ? htmlspecialchars($_POST['equipamiento']) : ''; ?></textarea>
                    <small class="text-muted">Separe los items con comas o puntos</small>
                </div>
            </div>
            
            <!-- Estado -->
            <div class="form-section">
                <h3><i class="fas fa-toggle-on"></i> Estado</h3>
                
                <div class="form-group">
                    <div class="checkbox-item">
                        <input type="checkbox" name="disponible" id="disponible" value="1" checked>
                        <label for="disponible">Aula disponible para uso</label>
                    </div>
                    <small class="text-muted">Si desactiva esta opción, el aula no podrá ser asignada en horarios</small>
                </div>
            </div>
            
            <!-- Botones de acción -->
            <div class="form-actions">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancelar
                </a>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Crear Aula
                </button>
            </div>
        </form>
    </div>
    
    <script>
        // Script para manejar la selección de tipo de aula
        document.querySelectorAll('.tipo-option').forEach(option => {
            option.addEventListener('click', function() {
                // Desmarcar todas las opciones
                document.querySelectorAll('.tipo-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                
                // Marcar la opción seleccionada
                this.classList.add('selected');
                
                // Marcar el radio button correspondiente
                const radio = this.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;
                }
            });
        });
        
        // Script para actualizar la visualización de capacidad
        const capacityInput = document.getElementById('capacidad');
        const capacityFill = document.getElementById('capacityFill');
        const capacityText = document.getElementById('capacityText');
        
        function updateCapacity() {
            const capacity = parseInt(capacityInput.value) || 30;
            const percentage = Math.min((capacity / 500) * 100, 100);
            
            capacityFill.style.width = percentage + '%';
            capacityText.textContent = capacity + ' estudiantes';
            
            // Cambiar color según la capacidad
            if (capacity > 100) {
                capacityFill.style.background = 'var(--success)';
            } else if (capacity > 50) {
                capacityFill.style.background = 'var(--primary)';
            } else {
                capacityFill.style.background = 'var(--warning)';
            }
        }
        
        capacityInput.addEventListener('input', updateCapacity);
        updateCapacity(); // Inicializar
        
        // Script para manejar la selección de programas
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
                
                // Verificar si todos los programas están seleccionados
                const allChecked = Array.from(otrosCheckboxes).every(cb => cb.checked);
                if (allChecked) {
                    todasCheckbox.checked = true;
                    otrasCheckboxes.forEach(cb => cb.disabled = true);
                }
            });
        });
        
        // Inicializar estado de programas
        if (todasCheckbox.checked) {
            otrosCheckboxes.forEach(cb => {
                cb.disabled = true;
            });
        }
        
        // Script para validar el formulario
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