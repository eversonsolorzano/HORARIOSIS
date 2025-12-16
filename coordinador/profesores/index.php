<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

// Paginación
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$limite = 20;
$offset = ($pagina - 1) * $limite;

// Filtros
$busqueda = isset($_GET['busqueda']) ? Funciones::sanitizar($_GET['busqueda']) : '';
$estado = isset($_GET['estado']) ? Funciones::sanitizar($_GET['estado']) : '';
$programa = isset($_GET['programa']) ? Funciones::sanitizar($_GET['programa']) : '';

// Construir consulta
$where = [];
$params = [];

if (!empty($busqueda)) {
    $where[] = "(p.nombres LIKE ? OR p.apellidos LIKE ? OR p.codigo_profesor LIKE ? OR p.documento_identidad LIKE ?)";
    $like = "%$busqueda%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

if (!empty($estado) && in_array($estado, ['activo', 'inactivo'])) {
    $where[] = "p.activo = ?";
    $params[] = ($estado === 'activo') ? 1 : 0;
}

if (!empty($programa)) {
    $where[] = "p.programas_dictados LIKE ?";
    $params[] = "%$programa%";
}

$where_clause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

// Contar total
$count_sql = "SELECT COUNT(*) as total FROM profesores p $where_clause";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total = $stmt->fetch()['total'];
$total_paginas = ceil($total / $limite);

// Obtener profesores
$sql = "SELECT p.*, u.email, u.username
        FROM profesores p
        LEFT JOIN usuarios u ON p.id_usuario = u.id_usuario
        $where_clause
        ORDER BY p.id_profesor DESC
        LIMIT ? OFFSET ?";

$params[] = $limite;
$params[] = $offset;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$profesores = $stmt->fetchAll();

// Obtener programas para filtro
$programas = Funciones::obtenerProgramas();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Profesores - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .filters {
            background: var(--gray-50);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--gray-700);
            font-size: 14px;
        }
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            text-align: center;
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-card h3 {
            font-size: 32px;
            margin: 10px 0;
            color: var(--primary);
        }
        
        .stat-card p {
            color: var(--gray-600);
            font-size: 14px;
        }
        
        .stat-card i {
            font-size: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .programas-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 5px;
        }
        
        .programa-tag {
            background: linear-gradient(135deg, var(--gray-100) 0%, var(--gray-200) 100%);
            color: var(--gray-700);
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid var(--gray-300);
        }
        
        .table-responsive {
            overflow-x: auto;
            margin-top: 24px;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
        }
        
        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .btn-sm {
            padding: 8px 12px;
            font-size: 12px;
            min-width: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .text-muted {
            color: var(--gray-500);
            font-size: 13px;
        }
        
        .search-filter {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .search-filter input, .search-filter select {
            padding: 8px 12px;
            border: 2px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 14px;
        }
        
        .search-filter input:focus, .search-filter select:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            padding: 20px;
            flex-wrap: wrap;
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
            <h1><i class="fas fa-chalkboard-teacher"></i> Gestión de Profesores</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php" class="active"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
                <a href="crear.php" class="btn-primary">
                    <i class="fas fa-plus"></i> Nuevo Profesor
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
        
        <!-- Estadísticas -->
        <div class="stats-cards">
            <div class="stat-card">
                <i class="fas fa-chalkboard-teacher"></i>
                <h3><?php echo $total; ?></h3>
                <p>Total Profesores</p>
            </div>
            
            <?php
            $stmt = $db->query("SELECT COUNT(*) as activos FROM profesores WHERE activo = 1");
            $activos = $stmt->fetch()['activos'];
            ?>
            <div class="stat-card">
                <i class="fas fa-check-circle"></i>
                <h3><?php echo $activos; ?></h3>
                <p>Profesores Activos</p>
            </div>
            
            <?php
            $stmt = $db->query("SELECT COUNT(*) as inactivos FROM profesores WHERE activo = 0");
            $inactivos = $stmt->fetch()['inactivos'];
            ?>
            <div class="stat-card">
                <i class="fas fa-times-circle"></i>
                <h3><?php echo $inactivos; ?></h3>
                <p>Profesores Inactivos</p>
            </div>
        </div>
        
        <!-- Filtros -->
        <form method="GET" class="filters">
            <div class="filter-group">
                <label for="busqueda"><i class="fas fa-search"></i> Buscar</label>
                <input type="text" id="busqueda" name="busqueda" class="form-control"
                       value="<?php echo htmlspecialchars($busqueda); ?>"
                       placeholder="Nombre, código, documento...">
            </div>
            
            <div class="filter-group">
                <label for="estado"><i class="fas fa-circle"></i> Estado</label>
                <select id="estado" name="estado" class="form-control">
                    <option value="">Todos</option>
                    <option value="activo" <?php echo $estado === 'activo' ? 'selected' : ''; ?>>Activo</option>
                    <option value="inactivo" <?php echo $estado === 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="programa"><i class="fas fa-graduation-cap"></i> Programa</label>
                <select id="programa" name="programa" class="form-control">
                    <option value="">Todos los programas</option>
                    <?php foreach ($programas as $p): ?>
                        <option value="<?php echo htmlspecialchars($p['nombre_programa']); ?>"
                            <?php echo $programa === $p['nombre_programa'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['nombre_programa']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Filtrar
                </button>
                <a href="index.php" class="btn btn-secondary" style="margin-top: 5px;">
                    <i class="fas fa-redo"></i> Limpiar
                </a>
            </div>
        </form>
        
        <!-- Tabla de profesores -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-list"></i> Lista de Profesores</h2>
                <div class="actions">
                    <a href="crear.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Nuevo Profesor
                    </a>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre Completo</th>
                            <th>Documento</th>
                            <th>Especialidad</th>
                            <th>Programas</th>
                            <th>Contacto</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($profesores)): ?>
                            <tr>
                                <td colspan="8" class="text-center">
                                    <div class="empty-state">
                                        <i class="fas fa-user-slash fa-3x"></i>
                                        <h3>No hay profesores registrados</h3>
                                        <a href="crear.php" class="btn btn-primary">
                                            <i class="fas fa-plus"></i> Agregar primer profesor
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($profesores as $prof): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($prof['codigo_profesor']); ?></strong>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($prof['nombres'] . ' ' . $prof['apellidos']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($prof['titulo_academico']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($prof['documento_identidad']); ?></td>
                                    <td><?php echo htmlspecialchars($prof['especialidad']); ?></td>
                                    <td>
                                        <?php if ($prof['programas_dictados']): ?>
                                            <div class="programas-list">
                                                <?php 
                                                $programas_arr = explode(',', $prof['programas_dictados']);
                                                foreach ($programas_arr as $p):
                                                    if (!empty(trim($p))):
                                                ?>
                                                    <span class="programa-tag"><?php echo trim($p); ?></span>
                                                <?php 
                                                    endif;
                                                endforeach; 
                                                ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">Sin asignar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small>
                                            <?php if ($prof['telefono']): ?>
                                                <i class="fas fa-phone"></i> <?php echo htmlspecialchars($prof['telefono']); ?><br>
                                            <?php endif; ?>
                                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($prof['email_institucional']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $prof['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                                            <?php echo $prof['activo'] ? 'Activo' : 'Inactivo'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <a href="ver.php?id=<?php echo $prof['id_profesor']; ?>" 
                                               class="btn btn-sm btn-info" title="Ver">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="editar.php?id=<?php echo $prof['id_profesor']; ?>" 
                                               class="btn btn-sm btn-warning" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="asignar_programas.php?id=<?php echo $prof['id_profesor']; ?>" 
                                               class="btn btn-sm btn-secondary" title="Asignar Programas">
                                                <i class="fas fa-graduation-cap"></i>
                                            </a>
                                            <a href="cambiar_estado.php?id=<?php echo $prof['id_profesor']; ?>" 
                                               class="btn btn-sm <?php echo $prof['activo'] ? 'btn-danger' : 'btn-success'; ?>" 
                                               title="<?php echo $prof['activo'] ? 'Desactivar' : 'Activar'; ?>">
                                                <i class="fas fa-<?php echo $prof['activo'] ? 'times' : 'check'; ?>"></i>
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
                <div class="pagination">
                    <?php if ($pagina > 1): ?>
                        <a href="?pagina=1<?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?><?php echo !empty($estado) ? '&estado=' . urlencode($estado) : ''; ?><?php echo !empty($programa) ? '&programa=' . urlencode($programa) : ''; ?>" 
                           class="btn btn-sm btn-secondary">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?pagina=<?php echo $pagina - 1; ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?><?php echo !empty($estado) ? '&estado=' . urlencode($estado) : ''; ?><?php echo !empty($programa) ? '&programa=' . urlencode($programa) : ''; ?>" 
                           class="btn btn-sm btn-secondary">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $inicio = max(1, $pagina - 2);
                    $fin = min($total_paginas, $pagina + 2);
                    
                    for ($i = $inicio; $i <= $fin; $i++):
                    ?>
                        <a href="?pagina=<?php echo $i; ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?><?php echo !empty($estado) ? '&estado=' . urlencode($estado) : ''; ?><?php echo !empty($programa) ? '&programa=' . urlencode($programa) : ''; ?>" 
                           class="btn btn-sm <?php echo $i == $pagina ? 'btn-primary' : 'btn-secondary'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($pagina < $total_paginas): ?>
                        <a href="?pagina=<?php echo $pagina + 1; ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?><?php echo !empty($estado) ? '&estado=' . urlencode($estado) : ''; ?><?php echo !empty($programa) ? '&programa=' . urlencode($programa) : ''; ?>" 
                           class="btn btn-sm btn-secondary">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?pagina=<?php echo $total_paginas; ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?><?php echo !empty($estado) ? '&estado=' . urlencode($estado) : ''; ?><?php echo !empty($programa) ? '&programa=' . urlencode($programa) : ''; ?>" 
                           class="btn btn-sm btn-secondary">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Exportar -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-download"></i> Exportar Datos</h3>
            </div>
            <div class="card-body">
                <div class="actions">
                    <a href="exportar.php?formato=csv&busqueda=<?php echo urlencode($busqueda); ?>&estado=<?php echo urlencode($estado); ?>&programa=<?php echo urlencode($programa); ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-file-csv"></i> CSV
                    </a>
                    <a href="exportar.php?formato=pdf&busqueda=<?php echo urlencode($busqueda); ?>&estado=<?php echo urlencode($estado); ?>&programa=<?php echo urlencode($programa); ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-file-pdf"></i> PDF
                    </a>
                    <a href="exportar.php?formato=excel&busqueda=<?php echo urlencode($busqueda); ?>&estado=<?php echo urlencode($estado); ?>&programa=<?php echo urlencode($programa); ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-file-excel"></i> Excel
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    // Para animaciones y efectos adicionales
    document.addEventListener('DOMContentLoaded', function() {
        // Efecto hover en tarjetas de estadísticas
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
                this.style.boxShadow = 'var(--shadow-lg)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = 'var(--shadow)';
            });
        });
        
        // Confirmación para desactivar/activar profesor
        const estadoBtns = document.querySelectorAll('a[href*="cambiar_estado"]');
        estadoBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                const accion = this.title;
                if (!confirm(`¿Está seguro de que desea ${accion.toLowerCase()} este profesor?`)) {
                    e.preventDefault();
                }
            });
        });
    });
    </script>
</body>
</html>