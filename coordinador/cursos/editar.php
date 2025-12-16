<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

$errores = [];

// Obtener ID del curso
$id_curso = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id_curso) {
    Funciones::redireccionar('index.php', 'ID de curso no válido', 'error');
}

// Obtener datos del curso
$stmt = $db->prepare("
    SELECT c.*, p.nombre_programa 
    FROM cursos c 
    JOIN programas_estudio p ON c.id_programa = p.id_programa 
    WHERE c.id_curso = ?
");
$stmt->execute([$id_curso]);
$curso = $stmt->fetch();

if (!$curso) {
    Funciones::redireccionar('index.php', 'Curso no encontrado', 'error');
}

// Obtener programas
$programas = $db->query("SELECT * FROM programas_estudio WHERE activo = 1")->fetchAll();

// Obtener cursos para prerrequisitos - CORREGIDO
$stmt = $db->prepare("
    SELECT c.*, p.nombre_programa 
    FROM cursos c 
    JOIN programas_estudio p ON c.id_programa = p.id_programa 
    WHERE c.activo = 1 AND c.id_curso != ?
    ORDER BY c.semestre, c.nombre_curso
");
$stmt->execute([$id_curso]);
$cursos_disponibles = $stmt->fetchAll();

// Obtener prerrequisitos actuales
$prerrequisitos_actuales = [];
if ($curso['prerrequisitos']) {
    $ids_prerrequisitos = explode(',', $curso['prerrequisitos']);
    if (!empty($ids_prerrequisitos)) {
        $placeholders = str_repeat('?,', count($ids_prerrequisitos) - 1) . '?';
        $stmt = $db->prepare("
            SELECT c.*, p.nombre_programa 
            FROM cursos c 
            JOIN programas_estudio p ON c.id_programa = p.id_programa 
            WHERE c.id_curso IN ($placeholders)
            ORDER BY c.semestre, c.nombre_curso
        ");
        $stmt->execute($ids_prerrequisitos);
        $prerrequisitos_actuales = $stmt->fetchAll();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $codigo_curso = Funciones::sanitizar($_POST['codigo_curso']);
    $nombre_curso = Funciones::sanitizar($_POST['nombre_curso']);
    $id_programa = intval($_POST['id_programa']);
    $descripcion = Funciones::sanitizar($_POST['descripcion']);
    $creditos = intval($_POST['creditos']);
    $horas_semanales = intval($_POST['horas_semanales']);
    $semestre = intval($_POST['semestre']);
    $tipo_curso = Funciones::sanitizar($_POST['tipo_curso']);
    $activo = isset($_POST['activo']) ? 1 : 0;
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
        $errores[] = 'Las horas semanales deben estar entre 1 y 20';
    }
    
    if ($semestre < 1 || $semestre > 6) {
        $errores[] = 'El semestre debe estar entre 1 y 6';
    }
    
    // Verificar si el código del curso ya existe para este programa (excluyendo el actual)
    $stmt = $db->prepare("SELECT id_curso FROM cursos WHERE codigo_curso = ? AND id_programa = ? AND id_curso != ?");
    $stmt->execute([$codigo_curso, $id_programa, $id_curso]);
    if ($stmt->fetch()) {
        $errores[] = 'El código del curso ya existe para este programa';
    }
    
    if (empty($errores)) {
        try {
            // Actualizar curso
            $stmt = $db->prepare("UPDATE cursos SET 
                codigo_curso = ?, nombre_curso = ?, id_programa = ?, descripcion = ?, 
                creditos = ?, horas_semanales = ?, semestre = ?, tipo_curso = ?, 
                activo = ?, prerrequisitos = ?
                WHERE id_curso = ?");
            
            $stmt->execute([
                $codigo_curso, $nombre_curso, $id_programa, $descripcion, 
                $creditos, $horas_semanales, $semestre, $tipo_curso, 
                $activo, $prerrequisitos, $id_curso
            ]);
            
            Funciones::redireccionar('index.php', 'Curso actualizado exitosamente');
            
        } catch (Exception $e) {
            $errores[] = 'Error al actualizar el curso: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Curso - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-edit"></i> Editar Curso</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-book"></i> Cursos</a>
                <a href="editar.php?id=<?php echo $id_curso; ?>" class="active"><i class="fas fa-edit"></i> Editar Curso</a>
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
                               value="<?php echo htmlspecialchars($curso['codigo_curso']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="nombre_curso">Nombre del Curso *</label>
                        <input type="text" id="nombre_curso" name="nombre_curso" class="form-control" required
                               value="<?php echo htmlspecialchars($curso['nombre_curso']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="id_programa">Programa *</label>
                        <select id="id_programa" name="id_programa" class="form-control" required>
                            <option value="">Seleccionar programa</option>
                            <?php foreach ($programas as $programa): ?>
                                <option value="<?php echo $programa['id_programa']; ?>"
                                    <?php echo $curso['id_programa'] == $programa['id_programa'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($programa['nombre_programa']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="tipo_curso">Tipo de Curso *</label>
                        <select id="tipo_curso" name="tipo_curso" class="form-control" required>
                            <option value="">Seleccionar tipo</option>
                            <option value="obligatorio" <?php echo $curso['tipo_curso'] == 'obligatorio' ? 'selected' : ''; ?>>Obligatorio</option>
                            <option value="electivo" <?php echo $curso['tipo_curso'] == 'electivo' ? 'selected' : ''; ?>>Electivo</option>
                            <option value="taller" <?php echo $curso['tipo_curso'] == 'taller' ? 'selected' : ''; ?>>Taller</option>
                            <option value="laboratorio" <?php echo $curso['tipo_curso'] == 'laboratorio' ? 'selected' : ''; ?>>Laboratorio</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="semestre">Semestre *</label>
                        <select id="semestre" name="semestre" class="form-control" required>
                            <option value="">Seleccionar semestre</option>
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <option value="<?php echo $i; ?>"
                                    <?php echo $curso['semestre'] == $i ? 'selected' : ''; ?>>
                                    Semestre <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label for="creditos">Créditos *</label>
                        <input type="number" id="creditos" name="creditos" class="form-control" required
                               min="1" max="10" value="<?php echo $curso['creditos']; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="horas_semanales">Horas Semanales *</label>
                        <input type="number" id="horas_semanales" name="horas_semanales" class="form-control" required
                               min="1" max="20" value="<?php echo $curso['horas_semanales']; ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="activo">
                        <input type="checkbox" id="activo" name="activo" value="1" 
                               <?php echo $curso['activo'] ? 'checked' : ''; ?>>
                        Curso Activo
                    </label>
                </div>
                
                <div class="form-group">
                    <label for="descripcion">Descripción</label>
                    <textarea id="descripcion" name="descripcion" class="form-control" rows="4"><?php echo htmlspecialchars($curso['descripcion']); ?></textarea>
                </div>
                
                <h3 style="margin: 30px 0 20px 0; color: var(--dark);">Prerrequisitos</h3>
                <div class="form-group">
                    <p style="color: var(--gray-600); margin-bottom: 10px;">
                        Seleccione los cursos que deben aprobarse antes de poder tomar este curso:
                    </p>
                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid var(--gray-300); 
                                border-radius: var(--radius); padding: 15px;">
                        <?php if (count($cursos_disponibles) > 0): ?>
                            <?php foreach ($cursos_disponibles as $curso_item): ?>
                                <div style="margin-bottom: 10px;">
                                    <label style="display: flex; align-items: center; gap: 8px;">
                                        <input type="checkbox" name="prerrequisitos[]" value="<?php echo $curso_item['id_curso']; ?>"
                                            <?php 
                                            // Verificar si este curso está en los prerrequisitos actuales
                                            $prerrequisitos_array = $curso['prerrequisitos'] ? explode(',', $curso['prerrequisitos']) : [];
                                            echo in_array($curso_item['id_curso'], $prerrequisitos_array) ? 'checked' : ''; 
                                            ?>>
                                        <span>
                                            <?php echo htmlspecialchars($curso_item['nombre_curso']); ?> 
                                            (<?php echo $curso_item['codigo_curso']; ?> - Sem <?php echo $curso_item['semestre']; ?>)
                                            <small style="color: var(--gray-500);">
                                                - <?php echo htmlspecialchars($curso_item['nombre_programa']); ?>
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
                        <i class="fas fa-save"></i> Actualizar Curso
                    </button>
                    <a href="index.php" class="btn">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <script src="../../assets/js/main.js"></script>
</body>
</html>