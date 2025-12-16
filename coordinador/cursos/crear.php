<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

$errores = [];

// Obtener programas
$programas = $db->query("SELECT * FROM programas_estudio WHERE activo = 1")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $codigo_curso = Funciones::sanitizar($_POST['codigo_curso']);
    $nombre_curso = Funciones::sanitizar($_POST['nombre_curso']);
    $id_programa = intval($_POST['id_programa']);
    $descripcion = Funciones::sanitizar($_POST['descripcion']);
    $creditos = intval($_POST['creditos']);
    $horas_semanales = intval($_POST['horas_semanales']);
    $semestre = intval($_POST['semestre']);
    $tipo_curso = Funciones::sanitizar($_POST['tipo_curso']);
    $prerrequisitos = isset($_POST['prerrequisitos']) ? implode(',', $_POST['prerrequisitos']) : '';
    
    // Validaciones
    if (empty($codigo_curso)) $errores[] = 'El código del curso es requerido';
    if (empty($nombre_curso)) $errores[] = 'El nombre del curso es requerido';
    if (empty($id_programa)) $errores[] = 'El programa es requerido';
    if (empty($semestre)) $errores[] = 'El semestre es requerido';
    if (empty($tipo_curso)) $errores[] = 'El tipo de curso es requerido';
    
    if ($creditos < 1 || $creditos > 10) {
        $errores[] = 'Los créditos deben estar entre 1 y 10';
    }
    if ($horas_semanales < 1 || $horas_semanales > 20) {
        $errores[] = 'Las horas semanales deben estar entre 1 y 10';
    }
    
    if ($semestre < 1 || $semestre > 6) {
        $errores[] = 'El semestre debe estar entre 1 y 2';
    }
    
    // Verificar si el código del curso ya existe para este programa
    $stmt = $db->prepare("SELECT id_curso FROM cursos WHERE codigo_curso = ? AND id_programa = ?");
    $stmt->execute([$codigo_curso, $id_programa]);
    if ($stmt->fetch()) {
        $errores[] = 'El código del curso ya existe para este programa';
    }
    
    if (empty($errores)) {
        try {
            // Crear curso
            $stmt = $db->prepare("INSERT INTO cursos 
                (id_programa, codigo_curso, nombre_curso, descripcion, creditos, horas_semanales, 
                 semestre, tipo_curso, prerrequisitos) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $id_programa, $codigo_curso, $nombre_curso, $descripcion, $creditos, $horas_semanales,
                $semestre, $tipo_curso, $prerrequisitos
            ]);
            
            $id_curso = $db->lastInsertId();
            
            Funciones::redireccionar('index.php', 'Curso creado exitosamente');
            
        } catch (Exception $e) {
            $errores[] = 'Error al crear el curso: ' . $e->getMessage();
        }
    }
}

// Obtener cursos para prerrequisitos
$cursos_disponibles = $db->query("
    SELECT c.*, p.nombre_programa 
    FROM cursos c 
    JOIN programas_estudio p ON c.id_programa = p.id_programa 
    WHERE c.activo = 1 
    ORDER BY c.semestre, c.nombre_curso
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Curso - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-book-medical"></i> Crear Nuevo Curso</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-book"></i> Cursos</a>
                <a href="crear.php" class="active"><i class="fas fa-book-medical"></i> Nuevo Curso</a>
            </div>
        </div>
        
        <?php if (!empty($errores)): ?>
            <div class="alert alert-error">
                <?php foreach ($errores as $error): ?>
                    <p><?php echo $error; ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <form method="POST" action="" id="formCurso">
                <h3 style="margin-bottom: 20px; color: var(--dark);">Información Básica</h3>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="codigo_curso">Código del Curso *</label>
                        <input type="text" id="codigo_curso" name="codigo_curso" class="form-control" required
                               placeholder="Ej: MAT-101">
                    </div>
                    
                    <div class="form-group">
                        <label for="nombre_curso">Nombre del Curso *</label>
                        <input type="text" id="nombre_curso" name="nombre_curso" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="id_programa">Programa *</label>
                        <select id="id_programa" name="id_programa" class="form-control" required>
                            <option value="">Seleccionar programa</option>
                            <?php foreach ($programas as $programa): ?>
                                <option value="<?php echo $programa['id_programa']; ?>">
                                    <?php echo htmlspecialchars($programa['nombre_programa']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="tipo_curso">Tipo de Curso *</label>
                        <select id="tipo_curso" name="tipo_curso" class="form-control" required>
                            <option value="">Seleccionar tipo</option>
                            <option value="obligatorio">Obligatorio</option>
                            <option value="electivo">Electivo</option>
                            <option value="taller">Taller</option>
                            <option value="laboratorio">Laboratorio</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="semestre">Semestre *</label>
                        <select id="semestre" name="semestre" class="form-control" required>
                            <option value="">Seleccionar semestre</option>
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <option value="<?php echo $i; ?>">Semestre <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label for="creditos">Créditos *</label>
                        <input type="number" id="creditos" name="creditos" class="form-control" required
                               min="1" max="10" value="3">
                    </div>
                    
                    <div class="form-group">
                        <label for="horas_semanales">Horas Semanales *</label>
                        <input type="number" id="horas_semanales" name="horas_semanales" class="form-control" required
                               min="1" max="20" value="4">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="descripcion">Descripción</label>
                    <textarea id="descripcion" name="descripcion" class="form-control" rows="4"></textarea>
                </div>
                
                <h3 style="margin: 30px 0 20px 0; color: var(--dark);">Prerrequisitos</h3>
                <div class="form-group">
                    <p style="color: var(--gray-600); margin-bottom: 10px;">
                        Seleccione los cursos que deben aprobarse antes de poder tomar este curso:
                    </p>
                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid var(--gray-300); 
                                border-radius: var(--radius); padding: 15px;">
                        <?php if (count($cursos_disponibles) > 0): ?>
                            <?php foreach ($cursos_disponibles as $curso): ?>
                                <div style="margin-bottom: 10px;">
                                    <label style="display: flex; align-items: center; gap: 8px;">
                                        <input type="checkbox" name="prerrequisitos[]" value="<?php echo $curso['id_curso']; ?>">
                                        <span>
                                            <?php echo htmlspecialchars($curso['nombre_curso']); ?> 
                                            (<?php echo $curso['codigo_curso']; ?> - Sem <?php echo $curso['semestre']; ?>)
                                            <small style="color: var(--gray-500);">
                                                - <?php echo htmlspecialchars($curso['nombre_programa']); ?>
                                            </small>
                                        </span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="color: var(--gray-500); text-align: center; padding: 10px;">
                                No hay cursos disponibles para seleccionar como prerrequisitos.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div style="margin-top: 30px; display: flex; gap: 15px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Curso
                    </button>
                    <a href="index.php" class="btn">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <script src="../../assets/js/main.js"></script>
    <script>
    // Filtrar cursos por programa seleccionado para prerrequisitos
    document.getElementById('id_programa').addEventListener('change', function() {
        const programaId = this.value;
        const checkboxes = document.querySelectorAll('input[name="prerrequisitos[]"]');
        
        checkboxes.forEach(checkbox => {
            const cursoId = checkbox.value;
            // En un sistema real, deberías obtener el programa del curso desde un data attribute
            // Por ahora, solo mostramos todos los cursos
        });
    });
    </script>
</body>
</html>