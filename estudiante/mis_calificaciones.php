<?php
require_once '../includes/auth.php';
require_once '../includes/funciones.php';
Auth::requireRole('estudiante');

$db = Database::getConnection();
$user = Auth::getUserData();

// Obtener datos del estudiante
$stmt = $db->prepare("
    SELECT e.*, p.nombre_programa 
    FROM estudiantes e 
    JOIN programas_estudio p ON e.id_programa = p.id_programa 
    WHERE e.id_usuario = ?
");
$stmt->execute([$user['id']]);
$estudiante = $stmt->fetch();

// Obtener todas las calificaciones del estudiante
$stmt = $db->prepare("
    SELECT 
        c.id_curso,
        c.codigo_curso,
        c.nombre_curso,
        c.creditos,
        c.semestre as nivel_curso,
        i.nota_final,
        i.estado,
        i.fecha_inscripcion,
        s.codigo_semestre,
        s.nombre_semestre,
        s.fecha_inicio as fecha_inicio_semestre,
        s.fecha_fin as fecha_fin_semestre
    FROM inscripciones i
    JOIN horarios h ON i.id_horario = h.id_horario
    JOIN cursos c ON h.id_curso = c.id_curso
    JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
    WHERE i.id_estudiante = ?
    AND i.nota_final IS NOT NULL
    ORDER BY s.fecha_inicio DESC, c.nombre_curso
");
$stmt->execute([$estudiante['id_estudiante']]);
$calificaciones = $stmt->fetchAll();

// Calcular estadísticas
$total_cursos = count($calificaciones);
$total_creditos = 0;
$promedio_ponderado = 0;
$suma_ponderada = 0;
$cursos_aprobados = 0;
$cursos_reprobados = 0;

foreach ($calificaciones as $calif) {
    $total_creditos += $calif['creditos'];
    $suma_ponderada += ($calif['nota_final'] * $calif['creditos']);
    
    if ($calif['estado'] == 'aprobado') {
        $cursos_aprobados++;
    } else {
        $cursos_reprobados++;
    }
}

if ($total_creditos > 0) {
    $promedio_ponderado = $suma_ponderada / $total_creditos;
}

// Agrupar por semestre
$calificaciones_por_semestre = [];
foreach ($calificaciones as $calif) {
    $semestre_key = $calif['codigo_semestre'];
    if (!isset($calificaciones_por_semestre[$semestre_key])) {
        $calificaciones_por_semestre[$semestre_key] = [
            'nombre_semestre' => $calif['nombre_semestre'],
            'fecha_inicio' => $calif['fecha_inicio_semestre'],
            'fecha_fin' => $calif['fecha_fin_semestre'],
            'calificaciones' => []
        ];
    }
    $calificaciones_por_semestre[$semestre_key]['calificaciones'][] = $calif;
}

// Obtener el progreso académico
$stmt = $db->prepare("
    SELECT 
        SUM(CASE WHEN i.estado = 'aprobado' THEN c.creditos ELSE 0 END) as creditos_aprobados,
        (SELECT SUM(creditos) FROM cursos WHERE id_programa = ?) as creditos_totales_programa
    FROM inscripciones i
    JOIN horarios h ON i.id_horario = h.id_horario
    JOIN cursos c ON h.id_curso = c.id_curso
    WHERE i.id_estudiante = ?
");
$stmt->execute([$estudiante['id_programa'], $estudiante['id_estudiante']]);
$progreso = $stmt->fetch();

$creditos_aprobados = $progreso['creditos_aprobados'] ?? 0;
$creditos_totales_programa = $progreso['creditos_totales_programa'] ?? 1; // Evitar división por cero

$porcentaje_completado = ($creditos_aprobados / $creditos_totales_programa) * 100;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Calificaciones - Sistema de Horarios</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .estadisticas-calificaciones {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 768px) {
            .estadisticas-calificaciones {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 480px) {
            .estadisticas-calificaciones {
                grid-template-columns: 1fr;
            }
        }
        
        .estadistica-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            text-align: center;
            box-shadow: var(--shadow);
        }
        
        .estadistica-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            margin: 0 auto 15px;
        }
        
        .estadistica-info h3 {
            font-size: 14px;
            color: var(--gray-600);
            margin-bottom: 5px;
        }
        
        .estadistica-numero {
            font-size: 28px;
            font-weight: 700;
            color: var(--dark);
        }
        
        .progreso-academico {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            padding: 25px;
            border-radius: var(--radius);
            margin-bottom: 30px;
            border-left: 5px solid var(--primary);
        }
        
        .progreso-bar {
            height: 20px;
            background: var(--gray-200);
            border-radius: 10px;
            overflow: hidden;
            margin: 15px 0;
            position: relative;
        }
        
        .progreso-fill {
            height: 100%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: 10px;
            transition: width 1s ease-in-out;
            position: relative;
            min-width: 30px;
        }
        
        .progreso-text {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: white;
            font-size: 12px;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
        }
        
        .semestre-calificaciones {
            background: white;
            border-radius: var(--radius);
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
            border: 2px solid #e5e7eb;
        }
        
        .semestre-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--gray-100);
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .semestre-info h3 {
            color: var(--dark);
            margin-bottom: 5px;
            font-size: 18px;
        }
        
        .semestre-periodo {
            font-size: 14px;
            color: var(--gray-600);
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .semestre-promedio {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 16px;
            text-align: center;
        }
        
        .calificaciones-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .calificaciones-table th {
            background: var(--gray-50);
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: var(--gray-700);
            border-bottom: 2px solid var(--gray-200);
        }
        
        .calificaciones-table td {
            padding: 15px;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
        }
        
        .calificaciones-table tr:hover {
            background: var(--gray-50);
        }
        
        .nota-cell {
            font-weight: 600;
            font-size: 16px;
            text-align: center;
        }
        
        .nota-aprobatoria {
            color: #10b981;
        }
        
        .nota-reprobatoria {
            color: #ef4444;
        }
        
        .estado-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        
        .badge-aprobado {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-reprobado {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .curso-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .curso-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }
        
        .curso-details h4 {
            color: var(--dark);
            margin-bottom: 3px;
            font-size: 16px;
        }
        
        .curso-codigo {
            font-size: 13px;
            color: var(--gray-600);
        }
        
        .empty-calificaciones {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-calificaciones i {
            font-size: 48px;
            color: var(--gray-300);
            margin-bottom: 20px;
        }
        
        .export-options {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            justify-content: flex-end;
        }
        
        @media (max-width: 768px) {
            .calificaciones-table {
                display: block;
                overflow-x: auto;
            }
            
            .semestre-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .export-options {
                flex-direction: column;
                align-items: flex-end;
            }
        }
        
        .filters-container {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-label {
            font-size: 13px;
            color: var(--gray-600);
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fas fa-star"></i> Mis Calificaciones</h1>
                <div class="nav">
                    <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    <a href="mi_horario.php"><i class="fas fa-calendar-alt"></i> Mi Horario</a>
                    <a href="mis_cursos.php"><i class="fas fa-book"></i> Mis Cursos</a>
                    <a href="inscribir_curso.php"><i class="fas fa-plus-circle"></i> Inscribir Curso</a>
                    <a href="mis_calificaciones.php" class="active"><i class="fas fa-star"></i> Mis Calificaciones</a>
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
        
        <!-- Estadísticas -->
        <div class="estadisticas-calificaciones">
            <div class="estadistica-card">
                <div class="estadistica-icon" style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);">
                    <i class="fas fa-book"></i>
                </div>
                <div class="estadistica-info">
                    <h3>Cursos Evaluados</h3>
                    <div class="estadistica-numero"><?php echo $total_cursos; ?></div>
                </div>
            </div>
            
            <div class="estadistica-card">
                <div class="estadistica-icon" style="background: linear-gradient(135deg, var(--success) 0%, #34d399 100%);">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="estadistica-info">
                    <h3>Cursos Aprobados</h3>
                    <div class="estadistica-numero"><?php echo $cursos_aprobados; ?></div>
                </div>
            </div>
            
            <div class="estadistica-card">
                <div class="estadistica-icon" style="background: linear-gradient(135deg, var(--danger) 0%, #f87171 100%);">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="estadistica-info">
                    <h3>Cursos Reprobados</h3>
                    <div class="estadistica-numero"><?php echo $cursos_reprobados; ?></div>
                </div>
            </div>
            
            <div class="estadistica-card">
                <div class="estadistica-icon" style="background: linear-gradient(135deg, var(--secondary) 0%, #a78bfa 100%);">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="estadistica-info">
                    <h3>Promedio Ponderado</h3>
                    <div class="estadistica-numero"><?php echo number_format($promedio_ponderado, 2); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Progreso Académico -->
        <div class="progreso-academico">
            <h2><i class="fas fa-chart-bar"></i> Progreso Académico</h2>
            <div style="display: flex; justify-content: space-between; margin-top: 15px; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h4 style="color: var(--gray-700); margin-bottom: 5px;">Créditos Aprobados</h4>
                    <div style="font-size: 32px; font-weight: 700; color: var(--dark);">
                        <?php echo $creditos_aprobados; ?> / <?php echo $creditos_totales_programa; ?>
                    </div>
                    <div style="font-size: 14px; color: var(--gray-600); margin-top: 5px;">
                        <?php echo $creditos_totales_programa - $creditos_aprobados; ?> créditos pendientes
                    </div>
                </div>
                
                <div>
                    <h4 style="color: var(--gray-700); margin-bottom: 5px;">Porcentaje Completado</h4>
                    <div style="font-size: 32px; font-weight: 700; color: var(--dark);">
                        <?php echo number_format($porcentaje_completado, 1); ?>%
                    </div>
                    <div style="font-size: 14px; color: var(--gray-600); margin-top: 5px;">
                        Progreso del programa
                    </div>
                </div>
            </div>
            
            <div class="progreso-bar">
                <div class="progreso-fill" style="width: <?php echo min(100, $porcentaje_completado); ?>%;">
                    <div class="progreso-text">
                        <?php echo number_format($porcentaje_completado, 1); ?>%
                    </div>
                </div>
            </div>
            
            <div style="display: flex; justify-content: space-between; font-size: 12px; color: var(--gray-600);">
                <span>0%</span>
                <span>50%</span>
                <span>100%</span>
            </div>
        </div>
        
        <!-- Exportación -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2><i class="fas fa-file-export"></i> Exportar Calificaciones</h2>
                <div class="export-options">
                    <button onclick="exportarPDF()" class="btn btn-danger">
                        <i class="fas fa-file-pdf"></i> Exportar a PDF
                    </button>
                    <button onclick="exportarExcel()" class="btn btn-success">
                        <i class="fas fa-file-excel"></i> Exportar a Excel
                    </button>
                    <button onclick="imprimirCalificaciones()" class="btn btn-secondary">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
            
            <p style="color: var(--gray-600); margin-bottom: 15px;">
                Exporta tus calificaciones en diferentes formatos para tu portafolio académico.
            </p>
        </div>
        
        <!-- Calificaciones por Semestre -->
        <div class="card">
            <h2><i class="fas fa-graduation-cap"></i> Historial de Calificaciones</h2>
            
            <?php if (count($calificaciones_por_semestre) > 0): ?>
                <?php foreach ($calificaciones_por_semestre as $codigo_semestre => $semestre): ?>
                    <div class="semestre-calificaciones">
                        <div class="semestre-header">
                            <div class="semestre-info">
                                <h3><?php echo htmlspecialchars($semestre['nombre_semestre']); ?></h3>
                                <div class="semestre-periodo">
                                    <i class="fas fa-calendar"></i>
                                    <?php echo date('d/m/Y', strtotime($semestre['fecha_inicio'])); ?> - 
                                    <?php echo date('d/m/Y', strtotime($semestre['fecha_fin'])); ?>
                                </div>
                            </div>
                            
                            <?php
                            // Calcular promedio del semestre
                            $suma_notas = 0;
                            $suma_creditos = 0;
                            
                            foreach ($semestre['calificaciones'] as $calif) {
                                $suma_notas += $calif['nota_final'] * $calif['creditos'];
                                $suma_creditos += $calif['creditos'];
                            }
                            
                            $promedio_semestre = $suma_creditos > 0 ? $suma_notas / $suma_creditos : 0;
                            ?>
                            
                            <div class="semestre-promedio">
                                Promedio: <?php echo number_format($promedio_semestre, 2); ?>
                            </div>
                        </div>
                        
                        <table class="calificaciones-table">
                            <thead>
                                <tr>
                                    <th>Curso</th>
                                    <th>Nivel</th>
                                    <th>Créditos</th>
                                    <th>Nota Final</th>
                                    <th>Estado</th>
                                    <th>Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($semestre['calificaciones'] as $calif): 
                                    // Color para el icono del curso
                                    $colores = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6'];
                                    $color_index = $calif['id_curso'] % count($colores);
                                    $color_curso = $colores[$color_index];
                                ?>
                                <tr>
                                    <td>
                                        <div class="curso-info">
                                            <div class="curso-icon" style="background: <?php echo $color_curso; ?>;">
                                                <i class="fas fa-book"></i>
                                            </div>
                                            <div class="curso-details">
                                                <h4><?php echo htmlspecialchars($calif['nombre_curso']); ?></h4>
                                                <div class="curso-codigo"><?php echo $calif['codigo_curso']; ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo $calif['nivel_curso']; ?>° Semestre
                                    </td>
                                    <td>
                                        <strong><?php echo $calif['creditos']; ?></strong>
                                    </td>
                                    <td class="nota-cell <?php echo $calif['nota_final'] >= 10.5 ? 'nota-aprobatoria' : 'nota-reprobatoria'; ?>">
                                        <?php echo number_format($calif['nota_final'], 1); ?>
                                    </td>
                                    <td>
                                        <span class="estado-badge badge-<?php echo $calif['estado']; ?>">
                                            <?php echo ucfirst($calif['estado']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo date('d/m/Y', strtotime($calif['fecha_inscripcion'])); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-calificaciones">
                    <i class="fas fa-star"></i>
                    <h3>No hay calificaciones registradas</h3>
                    <p>No tienes calificaciones registradas en el sistema.</p>
                    <p style="color: var(--gray-600); margin-top: 10px;">
                        Las calificaciones aparecerán aquí una vez que sean ingresadas por los profesores.
                    </p>
                    <a href="mis_cursos.php" class="btn btn-primary mt-3">
                        <i class="fas fa-arrow-left"></i> Volver a Mis Cursos
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Leyenda -->
        <div class="card" style="background: var(--gray-50);">
            <h3><i class="fas fa-info-circle"></i> Leyenda</h3>
            <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 15px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 12px; height: 12px; border-radius: 3px; background: #10b981;"></div>
                    <span>Nota aprobatoria (≥ 10.5)</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 12px; height: 12px; border-radius: 3px; background: #ef4444;"></div>
                    <span>Nota reprobatoria (< 10.5)</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span class="estado-badge badge-aprobado">Aprobado</span>
                    <span>Curso aprobado</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span class="estado-badge badge-reprobado">Reprobado</span>
                    <span>Curso reprobado</span>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../assets/js/main.js"></script>
    <script>
        // Función para exportar a PDF (simulada)
        function exportarPDF() {
            alert('Exportando a PDF...\n\nEn una implementación real, esto generaría un archivo PDF con todas tus calificaciones.');
            // Aquí iría el código real para generar PDF
        }
        
        // Función para exportar a Excel (simulada)
        function exportarExcel() {
            alert('Exportando a Excel...\n\nEn una implementación real, esto generaría un archivo Excel con todas tus calificaciones.');
            // Aquí iría el código real para generar Excel
        }
        
        // Función para imprimir
        function imprimirCalificaciones() {
            window.print();
        }
        
        // Animar barras de progreso
        document.addEventListener('DOMContentLoaded', function() {
            const barras = document.querySelectorAll('.progreso-fill');
            barras.forEach(barra => {
                const width = barra.style.width;
                barra.style.width = '0';
                setTimeout(() => {
                    barra.style.width = width;
                }, 100);
            });
        });
        
        // Filtrar por semestre (si se implementan filtros)
        function filtrarPorSemestre(semestre) {
            const semestres = document.querySelectorAll('.semestre-calificaciones');
            
            semestres.forEach(sem => {
                if (semestre === 'todos') {
                    sem.style.display = 'block';
                } else {
                    const titulo = sem.querySelector('h3').textContent;
                    sem.style.display = titulo.includes(semestre) ? 'block' : 'none';
                }
            });
        }
    </script>
</body>
</html>