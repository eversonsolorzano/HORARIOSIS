<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

// Paginación
$pagina = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
$por_pagina = 20;
$offset = ($pagina - 1) * $por_pagina;

// Filtros
$filtro_codigo = isset($_GET['codigo']) ? Funciones::sanitizar($_GET['codigo']) : '';
$filtro_tipo = isset($_GET['tipo']) ? Funciones::sanitizar($_GET['tipo']) : '';
$filtro_programa = isset($_GET['programa']) ? Funciones::sanitizar($_GET['programa']) : '';
$filtro_disponible = isset($_GET['disponible']) ? $_GET['disponible'] : '';

// Construir consulta con filtros
$where = "1=1";
$params = [];

if (!empty($filtro_codigo)) {
    $where .= " AND codigo_aula LIKE :codigo";
    $params[':codigo'] = "%$filtro_codigo%";
}

if (!empty($filtro_tipo)) {
    $where .= " AND tipo_aula = :tipo";
    $params[':tipo'] = $filtro_tipo;
}

if (!empty($filtro_programa)) {
    $where .= " AND programas_permitidos LIKE :programa";
    $params[':programa'] = "%$filtro_programa%";
}

if ($filtro_disponible !== '') {
    $where .= " AND disponible = :disponible";
    $params[':disponible'] = $filtro_disponible;
}

// Obtener total de registros
$sql_total = "SELECT COUNT(*) as total FROM aulas WHERE $where";
$stmt = $db->prepare($sql_total);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_registros = $stmt->fetchColumn();
$total_paginas = ceil($total_registros / $por_pagina);

// Obtener aulas con paginación
$sql = "SELECT * FROM aulas WHERE $where ORDER BY codigo_aula LIMIT :limit OFFSET :offset";
$params[':limit'] = $por_pagina;
$params[':offset'] = $offset;

$stmt = $db->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$aulas = $stmt->fetchAll();

// Obtener tipos de aula únicos para el filtro
$tipos_aula = $db->query("SELECT DISTINCT tipo_aula FROM aulas ORDER BY tipo_aula")->fetchAll();

// Obtener estadísticas
$stats = [];
$stats['total'] = $db->query("SELECT COUNT(*) FROM aulas")->fetchColumn();
$stats['disponibles'] = $db->query("SELECT COUNT(*) FROM aulas WHERE disponible = 1")->fetchColumn();
$stats['capacidad'] = $db->query("SELECT SUM(capacidad) FROM aulas WHERE disponible = 1")->fetchColumn() ?? 0;
$stats['laboratorios'] = $db->query("SELECT COUNT(*) FROM aulas WHERE tipo_aula = 'laboratorio'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aulas - Sistema de Horarios</title>
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
        
        .badge-tipo {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .tipo-normal { background: #e3f2fd; color: #1976d2; }
        .tipo-laboratorio { background: #f3e5f5; color: #7b1fa2; }
        .tipo-taller { background: #e8f5e9; color: #388e3c; }
        .tipo-clinica { background: #fff3e0; color: #f57c00; }
        .tipo-aula_especial { background: #fce4ec; color: #c2185b; }
        
        .disponible-si { color: var(--success); }
        .disponible-no { color: var(--danger); }
        
        .text-muted { color: #6c757d; }
        .text-center { text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-school"></i> Gestión de Aulas</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php" class="active"><i class="fas fa-school"></i> Aulas</a>
                <a href="crear.php"><i class="fas fa-plus-circle"></i> Nueva Aula</a>
                <a href="disponibilidad.php"><i class="fas fa-calendar-check"></i> Disponibilidad</a>
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
                <h3>Total Aulas</h3>
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-desc">Registradas en el sistema</div>
            </div>
            
            <div class="stat-card">
                <h3>Disponibles</h3>
                <div class="stat-number"><?php echo $stats['disponibles']; ?></div>
                <div class="stat-desc">Aulas activas para uso</div>
            </div>
            
            <div class="stat-card">
                <h3>Capacidad Total</h3>
                <div class="stat-number"><?php echo number_format($stats['capacidad']); ?></div>
                <div class="stat-desc">Estudiantes que pueden alojar</div>
            </div>
            
            <div class="stat-card">
                <h3>Laboratorios</h3>
                <div class="stat-number"><?php echo $stats['laboratorios']; ?></div>
                <div class="stat-desc">Espacios especializados</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filtros-box">
            <h3><i class="fas fa-filter"></i> Filtros de Búsqueda</h3>
            <form method="GET" class="filtros-grid">
                <div>
                    <label for="codigo">Código:</label>
                    <input type="text" name="codigo" id="codigo" 
                           value="<?php echo htmlspecialchars($filtro_codigo); ?>"
                           placeholder="Ej: A-101" class="form-control">
                </div>
                
                <div>
                    <label for="tipo">Tipo de Aula:</label>
                    <select name="tipo" id="tipo" class="form-control">
                        <option value="">Todos los tipos</option>
                        <?php foreach ($tipos_aula as $tipo): ?>
                            <option value="<?php echo htmlspecialchars($tipo['tipo_aula']); ?>"
                                <?php echo $filtro_tipo == $tipo['tipo_aula'] ? 'selected' : ''; ?>>
                                <?php echo ucfirst($tipo['tipo_aula']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="programa">Programa:</label>
                    <select name="programa" id="programa" class="form-control">
                        <option value="">Todos los programas</option>
                        <option value="Topografía" <?php echo $filtro_programa == 'Topografía' ? 'selected' : ''; ?>>Topografía</option>
                        <option value="Arquitectura" <?php echo $filtro_programa == 'Arquitectura' ? 'selected' : ''; ?>>Arquitectura</option>
                        <option value="Enfermería" <?php echo $filtro_programa == 'Enfermería' ? 'selected' : ''; ?>>Enfermería</option>
                    </select>
                </div>
                
                <div>
                    <label for="disponible">Disponibilidad:</label>
                    <select name="disponible" id="disponible" class="form-control">
                        <option value="">Todas</option>
                        <option value="1" <?php echo $filtro_disponible === '1' ? 'selected' : ''; ?>>Disponible</option>
                        <option value="0" <?php echo $filtro_disponible === '0' ? 'selected' : ''; ?>>No Disponible</option>
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
        
        <!-- Tabla de Aulas -->
        <div class="card">
            <div class="card-header">
                <h3>Lista de Aulas (<?php echo $total_registros; ?>)</h3>
                <a href="crear.php" class="btn btn-success">
                    <i class="fas fa-plus"></i> Nueva Aula
                </a>
            </div>
            
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Tipo</th>
                            <th>Capacidad</th>
                            <th>Ubicación</th>
                            <th>Programas</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($aulas)): ?>
                            <tr>
                                <td colspan="8" class="text-center">
                                    <div style="padding: 40px; color: var(--gray-600);">
                                        <i class="fas fa-school fa-3x" style="margin-bottom: 15px;"></i>
                                        <p>No se encontraron aulas con los filtros aplicados</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($aulas as $aula): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($aula['codigo_aula']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($aula['nombre_aula']); ?></td>
                                <td>
                                    <span class="badge-tipo tipo-<?php echo $aula['tipo_aula']; ?>">
                                        <?php echo ucfirst($aula['tipo_aula']); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo $aula['capacidad']; ?></strong>
                                    <small class="text-muted">estudiantes</small>
                                </td>
                                <td>
                                    <?php if ($aula['edificio'] || $aula['piso']): ?>
                                        <?php echo htmlspecialchars($aula['edificio'] ?? 'Sin edificio'); ?> - 
                                        Piso <?php echo $aula['piso'] ?? 'N/A'; ?>
                                    <?php else: ?>
                                        <span class="text-muted">No especificado</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($aula['programas_permitidos']): ?>
                                        <?php 
                                        $programas = explode(',', $aula['programas_permitidos']);
                                        foreach ($programas as $programa): 
                                            if (!empty(trim($programa))):
                                        ?>
                                            <span class="badge badge-primary" style="margin: 2px;">
                                                <?php echo trim($programa); ?>
                                            </span>
                                        <?php 
                                            endif;
                                        endforeach; 
                                        ?>
                                    <?php else: ?>
                                        <span class="text-muted">Todos</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($aula['disponible']): ?>
                                        <span class="disponible-si">
                                            <i class="fas fa-check-circle"></i> Disponible
                                        </span>
                                    <?php else: ?>
                                        <span class="disponible-no">
                                            <i class="fas fa-times-circle"></i> No Disponible
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="ver.php?id=<?php echo $aula['id_aula']; ?>" 
                                           class="btn btn-sm btn-info" title="Ver">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="editar.php?id=<?php echo $aula['id_aula']; ?>" 
                                           class="btn btn-sm btn-warning" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="disponibilidad.php?aula=<?php echo $aula['id_aula']; ?>" 
                                           class="btn btn-sm btn-primary" title="Disponibilidad">
                                            <i class="fas fa-calendar-alt"></i>
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
                        <a href="?pagina=1<?php echo !empty($filtro_codigo) ? '&codigo=' . urlencode($filtro_codigo) : ''; ?><?php echo !empty($filtro_tipo) ? '&tipo=' . urlencode($filtro_tipo) : ''; ?><?php echo !empty($filtro_programa) ? '&programa=' . urlencode($filtro_programa) : ''; ?><?php echo $filtro_disponible !== '' ? '&disponible=' . urlencode($filtro_disponible) : ''; ?>"
                           class="page-link">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?pagina=<?php echo $pagina - 1; ?><?php echo !empty($filtro_codigo) ? '&codigo=' . urlencode($filtro_codigo) : ''; ?><?php echo !empty($filtro_tipo) ? '&tipo=' . urlencode($filtro_tipo) : ''; ?><?php echo !empty($filtro_programa) ? '&programa=' . urlencode($filtro_programa) : ''; ?><?php echo $filtro_disponible !== '' ? '&disponible=' . urlencode($filtro_disponible) : ''; ?>"
                           class="page-link">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php 
                    $inicio = max(1, $pagina - 2);
                    $fin = min($total_paginas, $pagina + 2);
                    
                    for ($i = $inicio; $i <= $fin; $i++): 
                    ?>
                        <a href="?pagina=<?php echo $i; ?><?php echo !empty($filtro_codigo) ? '&codigo=' . urlencode($filtro_codigo) : ''; ?><?php echo !empty($filtro_tipo) ? '&tipo=' . urlencode($filtro_tipo) : ''; ?><?php echo !empty($filtro_programa) ? '&programa=' . urlencode($filtro_programa) : ''; ?><?php echo $filtro_disponible !== '' ? '&disponible=' . urlencode($filtro_disponible) : ''; ?>"
                           class="page-link <?php echo $i == $pagina ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($pagina < $total_paginas): ?>
                        <a href="?pagina=<?php echo $pagina + 1; ?><?php echo !empty($filtro_codigo) ? '&codigo=' . urlencode($filtro_codigo) : ''; ?><?php echo !empty($filtro_tipo) ? '&tipo=' . urlencode($filtro_tipo) : ''; ?><?php echo !empty($filtro_programa) ? '&programa=' . urlencode($filtro_programa) : ''; ?><?php echo $filtro_disponible !== '' ? '&disponible=' . urlencode($filtro_disponible) : ''; ?>"
                           class="page-link">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?pagina=<?php echo $total_paginas; ?><?php echo !empty($filtro_codigo) ? '&codigo=' . urlencode($filtro_codigo) : ''; ?><?php echo !empty($filtro_tipo) ? '&tipo=' . urlencode($filtro_tipo) : ''; ?><?php echo !empty($filtro_programa) ? '&programa=' . urlencode($filtro_programa) : ''; ?><?php echo $filtro_disponible !== '' ? '&disponible=' . urlencode($filtro_disponible) : ''; ?>"
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
    </script>
</body>
</html>