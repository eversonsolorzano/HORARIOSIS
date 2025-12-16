<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

$id_inscripcion = isset($_GET['id']) ? intval($_GET['id']) : 0;
$editar = isset($_GET['editar']) ? true : false;

if (!$id_inscripcion) {
    Funciones::redireccionar('index.php', 'ID de inscripción no válido', 'error');
}

// Obtener datos de la inscripción
$stmt = $db->prepare("
    SELECT i.*, 
           e.codigo_estudiante, e.nombres as estudiante_nombres, e.apellidos as estudiante_apellidos,
           c.nombre_curso, c.codigo_curso, c.creditos,
           h.dia_semana, h.hora_inicio, h.hora_fin,
           s.nombre_semestre,
           CONCAT(p.nombres, ' ', p.apellidos) as profesor_nombre
    FROM inscripciones i
    JOIN estudiantes e ON i.id_estudiante = e.id_estudiante
    JOIN horarios h ON i.id_horario = h.id_horario
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
    JOIN profesores p ON h.id_profesor = p.id_profesor
    WHERE i.id_inscripcion = ?
");

$stmt->execute([$id_inscripcion]);
$inscripcion = $stmt->fetch();

if (!$inscripcion) {
    Funciones::redireccionar('index.php', 'Inscripción no encontrada', 'error');
}

// Verificar que el curso esté en estado de calificación
if ($inscripcion['estado'] != 'inscrito' && !$editar) {
    Funciones::redireccionar('ver.php?id=' . $id_inscripcion, 'Esta inscripción no está disponible para calificación', 'error');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nota_final = isset($_POST['nota_final']) ? floatval(str_replace(',', '.', $_POST['nota_final'])) : 0;
    $comentarios = isset($_POST['comentarios']) ? Funciones::sanitizar($_POST['comentarios']) : '';
    
    // Validar nota
    if ($nota_final < 0 || $nota_final > 5) {
        Session::setFlash('La nota debe estar entre 0 y 5', 'error');
        Funciones::redireccionar('calificar.php?id=' . $id_inscripcion);
    }
    
    try {
        $db->beginTransaction();
        
        // Determinar estado según la nota
        $estado = $nota_final >= 3.0 ? 'aprobado' : 'reprobado';
        
        // Actualizar inscripción
        $stmt = $db->prepare("
            UPDATE inscripciones 
            SET nota_final = ?, estado = ?, comentarios_calificacion = ?
            WHERE id_inscripcion = ?
        ");
        $stmt->execute([$nota_final, $estado, $comentarios, $id_inscripcion]);
        
        // Registrar en cambios_horario
        $info_inscripcion = "Estudiante: {$inscripcion['estudiante_nombres']} {$inscripcion['estudiante_apellidos']}, " .
                           "Curso: {$inscripcion['nombre_curso']}, " .
                           "Nota: $nota_final, Estado: $estado";
        
        $stmt = $db->prepare("
            INSERT INTO cambios_horario 
            (id_horario, tipo_cambio, valor_anterior, valor_nuevo, realizado_por, motivo) 
            VALUES (?, 'calificacion', ?, ?, ?, ?)
        ");
        
        $tipo_accion = $editar ? 'Edición de calificación' : 'Calificación final';
        $motivo = "$tipo_accion - $comentarios";
        
        $stmt->execute([
            $inscripcion['id_horario'],
            $inscripcion['estado'] . (isset($inscripcion['nota_final']) ? " - Nota: {$inscripcion['nota_final']}" : ''),
            "$estado - Nota: $nota_final",
            $user['id'],
            $motivo
        ]);
        
        // Notificar al estudiante
        $stmt = $db->prepare("
            SELECT u.id_usuario 
            FROM estudiantes e
            JOIN usuarios u ON e.id_usuario = u.id_usuario
            WHERE e.id_estudiante = ?
        ");
        $stmt->execute([$inscripcion['id_estudiante']]);
        $estudiante_usuario = $stmt->fetch();
        
        if ($estudiante_usuario) {
            $mensaje = "Su calificación en el curso <strong>{$inscripcion['nombre_curso']}</strong> ha sido registrada.<br>";
            $mensaje .= "Nota Final: <strong>$nota_final</strong><br>";
            $mensaje .= "Estado: " . ucfirst($estado) . "<br>";
            if ($comentarios) {
                $mensaje .= "Comentarios: $comentarios<br>";
            }
            $mensaje .= "Fecha: " . date('d/m/Y');
            
            $stmt = $db->prepare("
                INSERT INTO notificaciones (id_usuario, tipo_notificacion, titulo, mensaje)
                VALUES (?, 'recordatorio', 'Calificación Registrada', ?)
            ");
            $stmt->execute([$estudiante_usuario['id_usuario'], $mensaje]);
        }
        
        $db->commit();
        
        $mensaje_flash = $editar ? 'Calificación actualizada exitosamente' : 'Calificación registrada exitosamente';
        Session::setFlash($mensaje_flash);
        Funciones::redireccionar('ver.php?id=' . $id_inscripcion);
        
    } catch (Exception $e) {
        $db->rollBack();
        Session::setFlash('Error al registrar la calificación: ' . $e->getMessage(), 'error');
        Funciones::redireccionar('calificar.php?id=' . $id_inscripcion);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $editar ? 'Editar' : 'Registrar'; ?> Calificación - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .info-box {
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            border-left: 6px solid var(--primary);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .info-box h4 {
            color: var(--dark);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-item {
            display: flex;
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: var(--gray-700);
            width: 150px;
            flex-shrink: 0;
        }
        
        .info-value {
            color: var(--dark);
            flex: 1;
        }
        
        .calificacion-form {
            max-width: 600px;
            margin: 0 auto;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 8px;
            display: block;
        }
        
        .nota-preview {
            text-align: center;
            padding: 20px;
            margin: 20px 0;
            border-radius: var(--radius);
            font-size: 24px;
            font-weight: 700;
            transition: all 0.3s ease;
            border: 3px solid transparent;
        }
        
        .nota-excelente { 
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border-color: #c3e6cb;
        }
        
        .nota-buena { 
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            color: #0c5460;
            border-color: #bee5eb;
        }
        
        .nota-regular { 
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
            border-color: #ffeaa7;
        }
        
        .nota-mala { 
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-color: #f5c6cb;
        }
        
        .range-input {
            width: 100%;
            margin: 15px 0;
            -webkit-appearance: none;
            height: 10px;
            border-radius: 5px;
            background: var(--gray-300);
            outline: none;
        }
        
        .range-input::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--primary);
            cursor: pointer;
            border: 3px solid white;
            box-shadow: var(--shadow);
        }
        
        .range-labels {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            font-size: 12px;
            color: var(--gray-600);
        }
        
        .estado-calificacion {
            text-align: center;
            margin: 20px 0;
            padding: 15px;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 18px;
        }
        
        .estado-aprobado {
            background: linear-gradient(135deg, var(--success-light) 0%, var(--success) 100%);
            color: white;
        }
        
        .estado-reprobado {
            background: linear-gradient(135deg, var(--danger-light) 0%, var(--danger) 100%);
            color: white;
        }
        
        .comentarios-box textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid var(--gray-200);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-graduation-cap"></i> <?php echo $editar ? 'Editar' : 'Registrar'; ?> Calificación</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php"><i class="fas fa-clipboard-check"></i> Inscripciones</a>
                <a href="ver.php?id=<?php echo $id_inscripcion; ?>"><i class="fas fa-eye"></i> Ver Detalles</a>
                <a href="calificar.php?id=<?php echo $id_inscripcion; ?><?php echo $editar ? '&editar=1' : ''; ?>" class="active">
                    <i class="fas fa-graduation-cap"></i> <?php echo $editar ? 'Editar Calificación' : 'Calificar'; ?>
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
        
        <div class="info-box">
            <h4><i class="fas fa-info-circle"></i> Información de la Inscripción</h4>
            
            <div class="info-item">
                <div class="info-label">Estudiante:</div>
                <div class="info-value">
                    <strong><?php echo htmlspecialchars($inscripcion['estudiante_nombres'] . ' ' . $inscripcion['estudiante_apellidos']); ?></strong>
                    <span style="margin-left: 10px; color: var(--gray-600);">
                        (<?php echo $inscripcion['codigo_estudiante']; ?>)
                    </span>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Curso:</div>
                <div class="info-value">
                    <strong><?php echo htmlspecialchars($inscripcion['nombre_curso']); ?></strong>
                    <span style="margin-left: 10px; color: var(--gray-600);">
                        (<?php echo $inscripcion['codigo_curso']; ?>)
                    </span>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Créditos:</div>
                <div class="info-value">
                    <?php echo $inscripcion['creditos']; ?>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-label">Estado Actual:</div>
                <div class="info-value">
                    <span class="badge badge-<?php echo $inscripcion['estado']; ?>">
                        <?php echo ucfirst($inscripcion['estado']); ?>
                    </span>
                    <?php if ($inscripcion['nota_final'] !== null): ?>
                        <span style="margin-left: 10px;">
                            Nota Actual: 
                            <strong style="color: <?php echo $inscripcion['nota_final'] >= 3.0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                                <?php echo number_format($inscripcion['nota_final'], 1); ?>
                            </strong>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <form method="POST" class="card calificacion-form">
            <div class="card-header">
                <h2><i class="fas fa-edit"></i> <?php echo $editar ? 'Editar' : 'Registrar'; ?> Calificación Final</h2>
            </div>
            
            <div class="card-body">
                <div class="form-group">
                    <label for="nota_final">
                        <i class="fas fa-star"></i> Nota Final (0 - 5)
                        <span class="required">*</span>
                    </label>
                    
                    <input type="range" id="nota_range" name="nota_range" 
                           class="range-input" min="0" max="5" step="0.1"
                           value="<?php echo $editar ? number_format($inscripcion['nota_final'], 1) : '3.0'; ?>"
                           oninput="actualizarNota()">
                    
                    <div class="range-labels">
                        <span>0.0</span>
                        <span>1.0</span>
                        <span>2.0</span>
                        <span>3.0</span>
                        <span>4.0</span>
                        <span>5.0</span>
                    </div>
                    
                    <input type="number" id="nota_final" name="nota_final" 
                           class="form-control" min="0" max="5" step="0.1"
                           value="<?php echo $editar ? number_format($inscripcion['nota_final'], 1) : '3.0'; ?>"
                           onchange="actualizarDesdeInput()"
                           style="display: none;">
                    
                    <div id="nota_preview" class="nota-preview nota-regular">
                        <?php echo $editar ? number_format($inscripcion['nota_final'], 1) : '3.0'; ?>
                    </div>
                    
                    <div id="estado_calificacion" class="estado-calificacion estado-aprobado">
                        Estado: Aprobado
                    </div>
                </div>
                
                <div class="form-group comentarios-box">
                    <label for="comentarios">
                        <i class="fas fa-comment"></i> Comentarios / Observaciones
                    </label>
                    <textarea id="comentarios" name="comentarios" class="form-control"
                              placeholder="Observaciones sobre la calificación, desempeño del estudiante, etc."><?php 
                        echo $editar && isset($inscripcion['comentarios_calificacion']) 
                            ? htmlspecialchars($inscripcion['comentarios_calificacion']) 
                            : ''; 
                    ?></textarea>
                    <div class="help-text">
                        Estos comentarios se registrarán en el historial y podrán ser vistos por el estudiante.
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check-circle"></i> 
                        <?php echo $editar ? 'Actualizar Calificación' : 'Registrar Calificación'; ?>
                    </button>
                    <a href="ver.php?id=<?php echo $id_inscripcion; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
            </div>
        </form>
    </div>
    
    <script>
    function actualizarNota() {
        const range = document.getElementById('nota_range');
        const nota = parseFloat(range.value);
        const input = document.getElementById('nota_final');
        const preview = document.getElementById('nota_preview');
        const estado = document.getElementById('estado_calificacion');
        
        // Actualizar input oculto
        input.value = nota.toFixed(1);
        
        // Actualizar preview
        preview.textContent = nota.toFixed(1);
        
        // Determinar clase CSS según la nota
        let claseNota = 'nota-mala';
        let claseEstado = 'estado-reprobado';
        let textoEstado = 'Estado: Reprobado';
        
        if (nota >= 4.5) {
            claseNota = 'nota-excelente';
            claseEstado = 'estado-aprobado';
        } else if (nota >= 4.0) {
            claseNota = 'nota-buena';
            claseEstado = 'estado-aprobado';
        } else if (nota >= 3.0) {
            claseNota = 'nota-regular';
            claseEstado = 'estado-aprobado';
        } else if (nota >= 2.0) {
            claseNota = 'nota-mala';
            claseEstado = 'estado-reprobado';
        } else {
            claseNota = 'nota-mala';
            claseEstado = 'estado-reprobado';
        }
        
        if (nota >= 3.0) {
            textoEstado = 'Estado: Aprobado';
        }
        
        // Aplicar clases
        preview.className = 'nota-preview ' + claseNota;
        estado.className = 'estado-calificacion ' + claseEstado;
        estado.textContent = textoEstado;
    }
    
    function actualizarDesdeInput() {
        const input = document.getElementById('nota_final');
        const range = document.getElementById('nota_range');
        const nota = parseFloat(input.value);
        
        if (nota >= 0 && nota <= 5) {
            range.value = nota;
            actualizarNota();
        }
    }
    
    // Inicializar
    document.addEventListener('DOMContentLoaded', function() {
        actualizarNota();
        
        // Validar formulario
        document.querySelector('form').addEventListener('submit', function(e) {
            const nota = parseFloat(document.getElementById('nota_final').value);
            const comentarios = document.getElementById('comentarios').value.trim();
            
            if (isNaN(nota) || nota < 0 || nota > 5) {
                e.preventDefault();
                alert('La nota debe ser un número entre 0 y 5');
                return false;
            }
            
            const accion = <?php echo $editar ? "'editar'" : "'registrar'"; ?>;
            const mensaje = accion === 'editar' 
                ? '¿Está seguro de actualizar la calificación?' 
                : '¿Está seguro de registrar la calificación final?';
            
            if (!confirm(mensaje)) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });
    });
    </script>
</body>
</html>