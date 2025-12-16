<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

// Paginación
$pagina = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
$por_pagina = 15;
$offset = ($pagina - 1) * $por_pagina;

// Filtros
$filtro_codigo = isset($_GET['codigo']) ? Funciones::sanitizar($_GET['codigo']) : '';
$filtro_estado = isset($_GET['estado']) ? Funciones::sanitizar($_GET['estado']) : '';
$filtro_anio = isset($_GET['anio']) ? intval($_GET['anio']) : '';

// Construir consulta con filtros
$where = "1=1";
$params = [];

if (!empty($filtro_codigo)) {
    $where .= " AND codigo_semestre LIKE :codigo";
    $params[':codigo'] = "%$filtro_codigo%";
}

if (!empty($filtro_estado)) {
    $where .= " AND estado = :estado";
    $params[':estado'] = $filtro_estado;
}

if (!empty($filtro_anio)) {
    $where .= " AND YEAR(fecha_inicio) = :anio";
    $params[':anio'] = $filtro_anio;
}

// Obtener total de registros
$sql_total = "SELECT COUNT(*) as total FROM semestres_academicos WHERE $where";
$stmt = $db->prepare($sql_total);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_registros = $stmt->fetchColumn();
$total_paginas = ceil($total_registros / $por_pagina);

// Obtener semestres con paginación
$sql = "SELECT * FROM semestres_academicos WHERE $where ORDER BY fecha_inicio DESC LIMIT :limit OFFSET :offset";
$params[':limit'] = $por_pagina;
$params[':offset'] = $offset;

$stmt = $db->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$semestres = $stmt->fetchAll();

// Obtener años únicos para el filtro
$anios = $db->query("SELECT DISTINCT YEAR(fecha_inicio) as anio FROM semestres_academicos ORDER BY anio DESC")->fetchAll();

// Obtener estadísticas
$stats = [];
$stats['total'] = $db->query("SELECT COUNT(*) FROM semestres_academicos")->fetchColumn();
$stats['en_curso'] = $db->query("SELECT COUNT(*) FROM semestres_academicos WHERE estado = 'en_curso'")->fetchColumn();
$stats['planificacion'] = $db->query("SELECT COUNT(*) FROM semestres_academicos WHERE estado = 'planificación'")->fetchColumn();
$stats['finalizados'] = $db->query("SELECT COUNT(*) FROM semestres_academicos WHERE estado = 'finalizado'")->fetchColumn();

// Obtener semestre actual
$semestre_actual = $db->query("SELECT * FROM semestres_academicos WHERE estado = 'en_curso' LIMIT 1")->fetch();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semestres Académicos - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--radius);
            border-left: 4px solid var(--primary);
            box-shadow: var(--shadow);
        }
        
        .stat-card h3 {
            color: var(--dark);
            font-size: 14px;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .stat-desc {
            font-size: 12px;
            color: var(--gray-600);
            margin-top: 5px;
        }
        
        .current-semester {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-left: 6px solid var(--primary);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .current-semester h3 {
            color: var(--dark);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .semester-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .filtros-box {
            background: var(--gray-50);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }
        
        .filtros-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .table-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        
        .badge-estado {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .estado-planificacion { background: #fff3e0; color: #ef6c00; }
        .estado-en_curso { background: #e8f5e9; color: #2e7d32; }
        .estado-finalizado { background: #f5f5f5; color: #616161; }
        
        .duration-badge {
            background: var(--gray-100);
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            color: var(--gray-700);
        }
        
        .progress-bar {
            height: 8px;
            background: var(--gray-300);
            border-radius: 4px;
            margin-top: 5px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 4px;
        }
        
        .text-muted { color: #6c757d; }
        .text-center { text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-calendar-alt"></i> Semestres Académicos</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php" class="active"><i class="fas fa-calendar-alt"></i> Semestres</a>
                <a href="crear.php"><i class="fas fa-plus-circle"></i> Nuevo Semestre</a>
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
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Semestres</h3>
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-desc">Registrados en el sistema</div>
            </div>
            
            <div class="stat-card">
                <h3>En Curso</h3>
                <div class="stat-number"><?php echo $stats['en_curso']; ?></div>
                <div class="stat-desc">Semestres activos</div>
            </div>
            
            <div class="stat-card">
                <h3>Planificación</h3>
                <div class="stat-number"><?php echo $stats['planificacion']; ?></div>
                <div class="stat-desc">En preparación</div>
            </div>
            
            <div class="stat-card">
                <h3>Finalizados</h3>
                <div class="stat-number"><?php echo $stats['finalizados']; ?></div>
                <div class="stat-desc">Semestres completados</div>
            </div>
        </div>
        
        <!-- Semestre Actual -->
        <?php if ($semestre_actual): ?>
        <div class="current-semester">
            <h3><i class="fas fa-star"></i> Semestre Actualmente en Curso</h3>
            <div class="semester-info">
                <div>
                    <strong><?php echo htmlspecialchars($semestre_actual['codigo_semestre']); ?></strong><br>
                    <span class="text-muted"><?php echo htmlspecialchars($semestre_actual['nombre_semestre']); ?></span>
                </div>
                <div>
                    <i class="fas fa-calendar-day"></i> Inicio: <?php echo Funciones::formatearFecha($semestre_actual['fecha_inicio']); ?><br>
                    <i class="fas fa-calendar-check"></i> Fin: <?php echo Funciones::formatearFecha($semestre_actual['fecha_fin']); ?>
                </div>
                <div>
                    <?php 
                    $hoy = new DateTime();
                    $inicio = new DateTime($semestre_actual['fecha_inicio']);
                    $fin = new DateTime($semestre_actual['fecha_fin']);
                    
                    $total_dias = $inicio->diff($fin)->days;
                    $dias_transcurridos = $inicio->diff($hoy)->days;
                    
                    if ($hoy > $fin) {
                        $porcentaje = 100;
                    } elseif ($hoy < $inicio) {
                        $porcentaje = 0;
                    } else {
                        $porcentaje = ($dias_transcurridos / $total_dias) * 100;
                    }
                    ?>
                    <div>Progreso: <?php echo number_format($porcentaje, 1); ?>%</div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo min(100, $porcentaje); ?>%;"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="filtros-box">
            <h3><i class="fas fa-filter"></i> Filtros de Búsqueda</h3>
            <form method="GET" class="filtros-grid">
                <div>
                    <label for="codigo">Código:</label>
                    <input type="text" name="codigo" id="codigo" 
                           value="<?php echo htmlspecialchars($filtro_codigo); ?>"
                           placeholder="Ej: 2024-1, 2024-2" class="form-control">
                </div>
                
                <div>
                    <label for="estado">Estado:</label>
                    <select name="estado" id="estado" class="form-control">
                        <option value="">Todos los estados</option>
                        <option value="planificación" <?php echo $filtro_estado == 'planificación' ? 'selected' : ''; ?>>Planificación</option>
                        <option value="en_curso" <?php echo $filtro_estado == 'en_curso' ? 'selected' : ''; ?>>En Curso</option>
                        <option value="finalizado" <?php echo $filtro_estado == 'finalizado' ? 'selected' : ''; ?>>Finalizado</option>
                    </select>
                </div>
                
                <div>
                    <label for="anio">Año:</label>
                    <select name="anio" id="anio" class="form-control">
                        <option value="">Todos los años</option>
                        <?php foreach ($anios as $anio): ?>
                            <option value="<?php echo $anio['anio']; ?>"
                                <?php echo $filtro_anio == $anio['anio'] ? 'selected' : ''; ?>>
                                <?php echo $anio['anio']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="grid-column: span 2; display: flex; gap: 10px; align-items: flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Buscar
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Limpiar
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Tabla de Semestres -->
        <div class="card">
            <div class="card-header">
                <h3>Lista de Semestres (<?php echo $total_registros; ?>)</h3>
                <a href="crear.php" class="btn btn-success">
                    <i class="fas fa-plus"></i> Nuevo Semestre
                </a>
            </div>
            
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Período</th>
                            <th>Estado</th>
                            <th>Progreso</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($semestres)): ?>
                            <tr>
                                <td colspan="6" class="text-center">
                                    <div style="padding: 40px; color: var(--gray-600);">
                                        <i class="fas fa-calendar-times fa-3x" style="margin-bottom: 15px;"></i>
                                        <p>No se encontraron semestres con los filtros aplicados</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($semestres as $semestre): 
                                $hoy = new DateTime();
                                $inicio = new DateTime($semestre['fecha_inicio']);
                                $fin = new DateTime($semestre['fecha_fin']);
                                
                                $total_dias = $inicio->diff($fin)->days;
                                $dias_transcurridos = $inicio->diff($hoy)->days;
                                
                                if ($hoy > $fin) {
                                    $porcentaje = 100;
                                } elseif ($hoy < $inicio) {
                                    $porcentaje = 0;
                                } else {
                                    $porcentaje = ($dias_transcurridos / $total_dias) * 100;
                                }
                                
                                $dias_restantes = $hoy->diff($fin)->days;
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($semestre['codigo_semestre']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($semestre['nombre_semestre']); ?></td>
                                <td>
                                    <div>
                                        <small>Inicio: <?php echo Funciones::formatearFecha($semestre['fecha_inicio']); ?></small>
                                    </div>
                                    <div>
                                        <small>Fin: <?php echo Funciones::formatearFecha($semestre['fecha_fin']); ?></small>
                                    </div>
                                    <div class="duration-badge">
                                        <?php echo $total_dias; ?> días
                                    </div>
                                </td>
                                <td>
                                    <span class="badge-estado estado-<?php echo $semestre['estado']; ?>">
                                        <?php 
                                        $estado_text = [
                                            'planificación' => 'Planificación',
                                            'en_curso' => 'En Curso',
                                            'finalizado' => 'Finalizado'
                                        ];
                                        echo $estado_text[$semestre['estado']];
                                        ?>
                                    </span>
                                    <?php if ($semestre['estado'] == 'en_curso'): ?>
                                        <div style="font-size: 11px; color: var(--success); margin-top: 3px;">
                                            <i class="fas fa-clock"></i> <?php echo $dias_restantes; ?> días restantes
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="min-width: 150px;">
                                        <div style="display: flex; justify-content: space-between; font-size: 12px;">
                                            <span><?php echo number_format($porcentaje, 1); ?>%</span>
                                            <span><?php echo $dias_transcurridos; ?>/<?php echo $total_dias; ?> días</span>
                                        </div>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo min(100, $porcentaje); ?>%; 
                                                background: <?php 
                                                if ($semestre['estado'] == 'finalizado') echo 'var(--gray-500)';
                                                elseif ($semestre['estado'] == 'en_curso') echo 'var(--success)';
                                                else echo 'var(--warning)';
                                                ?>;">
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="ver.php?id=<?php echo $semestre['id_semestre']; ?>" 
                                           class="btn btn-sm btn-info" title="Ver">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="editar.php?id=<?php echo $semestre['id_semestre']; ?>" 
                                           class="btn btn-sm btn-warning" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="cambiar_estado.php?id=<?php echo $semestre['id_semestre']; ?>" 
                                           class="btn btn-sm btn-primary" title="Cambiar Estado">
                                            <i class="fas fa-sync-alt"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Paginación -->
            <?php if ($total_paginas > 1): ?>
            <div class="card-footer">
                <div class="pagination">
                    <?php if ($pagina > 1): ?>
                        <a href="?pagina=1<?php echo !empty($filtro_codigo) ? '&codigo=' . urlencode($filtro_codigo) : ''; ?><?php echo !empty($filtro_estado) ? '&estado=' . urlencode($filtro_estado) : ''; ?><?php echo !empty($filtro_anio) ? '&anio=' . urlencode($filtro_anio) : ''; ?>"
                           class="page-link">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?pagina=<?php echo $pagina - 1; ?><?php echo !empty($filtro_codigo) ? '&codigo=' . urlencode($filtro_codigo) : ''; ?><?php echo !empty($filtro_estado) ? '&estado=' . urlencode($filtro_estado) : ''; ?><?php echo !empty($filtro_anio) ? '&anio=' . urlencode($filtro_anio) : ''; ?>"
                           class="page-link">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php 
                    $inicio = max(1, $pagina - 2);
                    $fin = min($total_paginas, $pagina + 2);
                    
                    for ($i = $inicio; $i <= $fin; $i++): 
                    ?>
                        <a href="?pagina=<?php echo $i; ?><?php echo !empty($filtro_codigo) ? '&codigo=' . urlencode($filtro_codigo) : ''; ?><?php echo !empty($filtro_estado) ? '&estado=' . urlencode($filtro_estado) : ''; ?><?php echo !empty($filtro_anio) ? '&anio=' . urlencode($filtro_anio) : ''; ?>"
                           class="page-link <?php echo $i == $pagina ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($pagina < $total_paginas): ?>
                        <a href="?pagina=<?php echo $pagina + 1; ?><?php echo !empty($filtro_codigo) ? '&codigo=' . urlencode($filtro_codigo) : ''; ?><?php echo !empty($filtro_estado) ? '&estado=' . urlencode($filtro_estado) : ''; ?><?php echo !empty($filtro_anio) ? '&anio=' . urlencode($filtro_anio) : ''; ?>"
                           class="page-link">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?pagina=<?php echo $total_paginas; ?><?php echo !empty($filtro_codigo) ? '&codigo=' . urlencode($filtro_codigo) : ''; ?><?php echo !empty($filtro_estado) ? '&estado=' . urlencode($filtro_estado) : ''; ?><?php echo !empty($filtro_anio) ? '&anio=' . urlencode($filtro_anio) : ''; ?>"
                           class="page-link">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Filtrar por enter en los campos de texto
        document.getElementById('codigo').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.form.submit();
            }
        });
        
        // Actualizar progreso en tiempo real
        function updateProgress() {
            const hoy = new Date();
            
            document.querySelectorAll('tr').forEach(row => {
                const inicioText = row.querySelector('td:nth-child(3) small:first-child')?.textContent;
                const finText = row.querySelector('td:nth-child(3) small:nth-child(2)')?.textContent;
                
                if (inicioText && finText) {
                    const inicio = new Date(inicioText.replace('Inicio: ', '').trim());
                    const fin = new Date(finText.replace('Fin: ', '').trim());
                    
                    if (inicio && fin) {
                        const totalDias = Math.ceil((fin - inicio) / (1000 * 60 * 60 * 24));
                        const diasTranscurridos = Math.ceil((hoy - inicio) / (1000 * 60 * 60 * 24));
                        const porcentaje = Math.min(100, Math.max(0, (diasTranscurridos / totalDias) * 100));
                        
                        const diasRestantes = Math.ceil((fin - hoy) / (1000 * 60 * 60 * 24));
                        
                        // Actualizar progreso visual
                        const progressFill = row.querySelector('.progress-fill');
                        if (progressFill) {
                            progressFill.style.width = porcentaje + '%';
                        }
                        
                        // Actualizar texto
                        const porcentajeSpan = row.querySelector('td:nth-child(5) div:first-child span:first-child');
                        const diasSpan = row.querySelector('td:nth-child(5) div:first-child span:nth-child(2)');
                        
                        if (porcentajeSpan) {
                            porcentajeSpan.textContent = porcentaje.toFixed(1) + '%';
                        }
                        if (diasSpan) {
                            diasSpan.textContent = diasTranscurridos + '/' + totalDias + ' días';
                        }
                        
                        // Actualizar días restantes si está en curso
                        const estadoBadge = row.querySelector('.badge-estado');
                        if (estadoBadge && estadoBadge.textContent.includes('En Curso')) {
                            const diasRestantesDiv = row.querySelector('td:nth-child(4) div');
                            if (diasRestantesDiv) {
                                if (diasRestantes > 0) {
                                    diasRestantesDiv.innerHTML = '<i class="fas fa-clock"></i> ' + diasRestantes + ' días restantes';
                                } else {
                                    diasRestantesDiv.innerHTML = '<i class="fas fa-flag-checkered"></i> Finaliza hoy';
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Actualizar cada hora
        updateProgress();
        setInterval(updateProgress, 3600000); // Cada hora
    </script>
</body>
</html>