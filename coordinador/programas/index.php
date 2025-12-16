<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();
$user = Auth::getUserData();

// Paginación
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$limite = 15;
$offset = ($pagina - 1) * $limite;

// Filtros
$busqueda = isset($_GET['busqueda']) ? Funciones::sanitizar($_GET['busqueda']) : '';
$estado = isset($_GET['estado']) ? Funciones::sanitizar($_GET['estado']) : '';

// Construir consulta
$where = [];
$params = [];

if (!empty($busqueda)) {
    $where[] = "(p.nombre_programa LIKE ? OR p.codigo_programa LIKE ? OR p.descripcion LIKE ?)";
    $like = "%$busqueda%";
    $params = array_merge($params, [$like, $like, $like]);
}

if (!empty($estado) && in_array($estado, ['activo', 'inactivo'])) {
    $where[] = "p.activo = ?";
    $params[] = ($estado === 'activo') ? 1 : 0;
}

$where_clause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

// Contar total
$count_sql = "SELECT COUNT(*) as total FROM programas_estudio p $where_clause";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total = $stmt->fetch()['total'];
$total_paginas = ceil($total / $limite);

// Obtener programas - CORREGIDO
$sql = "SELECT p.*, 
               u.username as coordinador_username,
               u.email as coordinador_email,
               COUNT(DISTINCT e.id_estudiante) as total_estudiantes,
               COUNT(DISTINCT pr.id_profesor) as total_profesores,
               COUNT(DISTINCT c.id_curso) as total_cursos
        FROM programas_estudio p
        LEFT JOIN usuarios u ON p.coordinador_id = u.id_usuario
        LEFT JOIN estudiantes e ON p.id_programa = e.id_programa AND e.estado = 'activo'
        LEFT JOIN profesores pr ON FIND_IN_SET(p.nombre_programa, pr.programas_dictados) > 0 AND pr.activo = 1
        LEFT JOIN cursos c ON p.id_programa = c.id_programa AND c.activo = 1
        $where_clause
        GROUP BY p.id_programa
        ORDER BY p.id_programa DESC
        LIMIT ? OFFSET ?";

$params[] = $limite;
$params[] = $offset;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$programas = $stmt->fetchAll();

// Obtener coordinadores disponibles (solo usuarios con rol coordinador)
$coordinadores = $db->query("
    SELECT id_usuario, username, email
    FROM usuarios 
    WHERE rol = 'coordinador' AND activo = 1
    ORDER BY username
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Programas - Sistema de Horarios</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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
        
        .filters {
            background: var(--gray-50);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
        
        .programa-card {
            background: white;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            padding: 25px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .programa-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }
        
        .programa-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary) 0%, var(--accent) 100%);
        }
        
        .programa-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .programa-title h3 {
            color: var(--dark);
            margin: 0 0 5px 0;
            font-size: 20px;
        }
        
        .programa-title .codigo {
            color: var(--primary);
            font-weight: 600;
            font-size: 14px;
            background: var(--gray-100);
            padding: 3px 10px;
            border-radius: 15px;
            display: inline-block;
        }
        
        .programa-stats {
            display: flex;
            gap: 20px;
            margin: 15px 0;
            flex-wrap: wrap;
        }
        
        .stat-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px 15px;
            background: var(--gray-50);
            border-radius: var(--radius);
            min-width: 80px;
        }
        
        .stat-number {
            font-size: 22px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--gray-600);
            text-align: center;
        }
        
        .programa-info {
            margin: 15px 0;
            color: var(--gray-700);
            line-height: 1.6;
            font-size: 14px;
        }
        
        .programa-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid var(--gray-200);
        }
        
        .coordinador-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .coordinador-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }
        
        .coordinador-details {
            font-size: 13px;
        }
        
        .coordinador-details strong {
            color: var(--dark);
        }
        
        .coordinador-details .text-muted {
            color: var(--gray-500);
            font-size: 12px;
        }
        
        .programa-actions {
            display: flex;
            gap: 10px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 40px;
            color: var(--gray-500);
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 24px;
            background: linear-gradient(135deg, var(--gray-300) 0%, var(--gray-400) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .empty-state p {
            font-size: 16px;
            margin-top: 12px;
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
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            padding: 20px;
            flex-wrap: wrap;
        }
        
        @media (max-width: 768px) {
            .programa-header {
                flex-direction: column;
                gap: 15px;
            }
            
            .programa-stats {
                justify-content: center;
            }
            
            .programa-footer {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .programa-actions {
                width: 100%;
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-graduation-cap"></i> Gestión de Programas de Estudio</h1>
            <div class="nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php" class="active"><i class="fas fa-graduation-cap"></i> Programas</a>
                <a href="crear.php" class="btn-primary">
                    <i class="fas fa-plus"></i> Nuevo Programa
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
        
        <!-- Estadísticas generales -->
        <div class="stats-cards">
            <?php
            $stmt = $db->query("SELECT COUNT(*) as total FROM programas_estudio");
            $total_programas = $stmt->fetch()['total'];
            
            $stmt = $db->query("SELECT COUNT(*) as activos FROM programas_estudio WHERE activo = 1");
            $activos = $stmt->fetch()['activos'];
            
            $stmt = $db->query("SELECT COUNT(DISTINCT id_estudiante) as total FROM estudiantes WHERE estado = 'activo'");
            $total_estudiantes = $stmt->fetch()['total'];
            
            $stmt = $db->query("SELECT COUNT(DISTINCT id_profesor) as total FROM profesores WHERE activo = 1");
            $total_profesores = $stmt->fetch()['total'];
            ?>
            
            <div class="stat-card">
                <i class="fas fa-graduation-cap"></i>
                <h3><?php echo $total_programas; ?></h3>
                <p>Total Programas</p>
            </div>
            
            <div class="stat-card">
                <i class="fas fa-check-circle"></i>
                <h3><?php echo $activos; ?></h3>
                <p>Programas Activos</p>
            </div>
            
            <div class="stat-card">
                <i class="fas fa-user-graduate"></i>
                <h3><?php echo $total_estudiantes; ?></h3>
                <p>Estudiantes Activos</p>
            </div>
            
            <div class="stat-card">
                <i class="fas fa-chalkboard-teacher"></i>
                <h3><?php echo $total_profesores; ?></h3>
                <p>Profesores Activos</p>
            </div>
        </div>
        
        <!-- Filtros -->
        <form method="GET" class="filters">
            <div class="filter-group">
                <label for="busqueda"><i class="fas fa-search"></i> Buscar</label>
                <input type="text" id="busqueda" name="busqueda" class="form-control"
                       value="<?php echo htmlspecialchars($busqueda); ?>"
                       placeholder="Nombre, código, descripción...">
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
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Filtrar
                </button>
                <a href="index.php" class="btn btn-secondary" style="margin-top: 5px;">
                    <i class="fas fa-redo"></i> Limpiar
                </a>
            </div>
        </form>
        
        <!-- Lista de programas -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-list"></i> Programas de Estudio</h2>
                <div class="actions">
                    <a href="crear.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Nuevo Programa
                    </a>
                </div>
            </div>
            
            <div class="card-body">
                <?php if (empty($programas)): ?>
                    <div class="empty-state">
                        <i class="fas fa-graduation-cap fa-3x"></i>
                        <h3>No hay programas registrados</h3>
                        <p>Comience creando el primer programa de estudio.</p>
                        <a href="crear.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Crear Primer Programa
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($programas as $programa): ?>
                        <div class="programa-card">
                            <div class="programa-header">
                                <div class="programa-title">
                                    <h3><?php echo htmlspecialchars($programa['nombre_programa']); ?></h3>
                                    <span class="codigo"><?php echo $programa['codigo_programa']; ?></span>
                                    <span class="badge <?php echo $programa['activo'] ? 'badge-active' : 'badge-inactive'; ?>" 
                                          style="margin-left: 10px;">
                                        <?php echo $programa['activo'] ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </div>
                                
                                <div class="programa-actions">
                                    <a href="ver.php?id=<?php echo $programa['id_programa']; ?>" 
                                       class="btn btn-sm btn-info" title="Ver">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="editar.php?id=<?php echo $programa['id_programa']; ?>" 
                                       class="btn btn-sm btn-warning" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="asignar_coordinador.php?id=<?php echo $programa['id_programa']; ?>" 
                                       class="btn btn-sm btn-secondary" title="Asignar Coordinador">
                                        <i class="fas fa-user-tie"></i>
                                    </a>
                                    <a href="cambiar_estado.php?id=<?php echo $programa['id_programa']; ?>" 
                                       class="btn btn-sm <?php echo $programa['activo'] ? 'btn-danger' : 'btn-success'; ?>" 
                                       title="<?php echo $programa['activo'] ? 'Desactivar' : 'Activar'; ?>">
                                        <i class="fas fa-<?php echo $programa['activo'] ? 'times' : 'check'; ?>"></i>
                                    </a>
                                </div>
                            </div>
                            
                            <?php if ($programa['descripcion']): ?>
                                <div class="programa-info">
                                    <?php echo nl2br(htmlspecialchars($programa['descripcion'])); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="programa-stats">
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo $programa['duracion_semestres']; ?></div>
                                    <div class="stat-label">Semestres</div>
                                </div>
                                
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo $programa['total_estudiantes']; ?></div>
                                    <div class="stat-label">Estudiantes</div>
                                </div>
                                
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo $programa['total_profesores']; ?></div>
                                    <div class="stat-label">Profesores</div>
                                </div>
                                
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo $programa['total_cursos']; ?></div>
                                    <div class="stat-label">Cursos</div>
                                </div>
                            </div>
                            
                            <div class="programa-footer">
                                <div class="coordinador-info">
                                    <?php if ($programa['coordinador_username']): ?>
                                        <div class="coordinador-avatar">
                                            <?php 
                                            $iniciales = '';
                                            if ($programa['coordinador_username']) {
                                                $iniciales = strtoupper(substr($programa['coordinador_username'], 0, 2));
                                            }
                                            echo $iniciales;
                                            ?>
                                        </div>
                                        <div class="coordinador-details">
                                            <strong>Coordinador:</strong>
                                            <div>
                                                <?php echo htmlspecialchars($programa['coordinador_username']); ?>
                                            </div>
                                            <div class="text-muted">
                                                <i class="fas fa-envelope"></i> <?php echo $programa['coordinador_email']; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="coordinador-avatar" style="background: var(--gray-400);">
                                            <i class="fas fa-user-slash"></i>
                                        </div>
                                        <div class="coordinador-details">
                                            <strong>Coordinador:</strong>
                                            <div style="color: var(--danger);">
                                                <i class="fas fa-exclamation-circle"></i> Sin asignar
                                            </div>
                                            <div class="text-muted">
                                                <a href="asignar_coordinador.php?id=<?php echo $programa['id_programa']; ?>" 
                                                   class="btn btn-sm btn-primary" style="padding: 3px 8px; font-size: 12px;">
                                                    Asignar coordinador
                                                </a>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="programa-actions">
                                    <a href="../cursos/index.php?programa=<?php echo $programa['id_programa']; ?>" 
                                       class="btn btn-sm btn-info">
                                        <i class="fas fa-book"></i> Cursos
                                    </a>
                                    <a href="../estudiantes/index.php?programa=<?php echo $programa['id_programa']; ?>" 
                                       class="btn btn-sm btn-success">
                                        <i class="fas fa-user-graduate"></i> Estudiantes
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Paginación -->
            <?php if ($total_paginas > 1): ?>
                <div class="pagination">
                    <?php if ($pagina > 1): ?>
                        <a href="?pagina=1<?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?><?php echo !empty($estado) ? '&estado=' . urlencode($estado) : ''; ?>" 
                           class="btn btn-sm btn-secondary">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?pagina=<?php echo $pagina - 1; ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?><?php echo !empty($estado) ? '&estado=' . urlencode($estado) : ''; ?>" 
                           class="btn btn-sm btn-secondary">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $inicio = max(1, $pagina - 2);
                    $fin = min($total_paginas, $pagina + 2);
                    
                    for ($i = $inicio; $i <= $fin; $i++):
                    ?>
                        <a href="?pagina=<?php echo $i; ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?><?php echo !empty($estado) ? '&estado=' . urlencode($estado) : ''; ?>" 
                           class="btn btn-sm <?php echo $i == $pagina ? 'btn-primary' : 'btn-secondary'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($pagina < $total_paginas): ?>
                        <a href="?pagina=<?php echo $pagina + 1; ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?><?php echo !empty($estado) ? '&estado=' . urlencode($estado) : ''; ?>" 
                           class="btn btn-sm btn-secondary">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?pagina=<?php echo $total_paginas; ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?><?php echo !empty($estado) ? '&estado=' . urlencode($estado) : ''; ?>" 
                           class="btn btn-sm btn-secondary">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Acciones adicionales -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-download"></i> Exportar Datos</h3>
            </div>
            <div class="card-body">
                <div class="actions">
                    <a href="exportar.php?formato=csv&busqueda=<?php echo urlencode($busqueda); ?>&estado=<?php echo urlencode($estado); ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-file-csv"></i> CSV
                    </a>
                    <a href="exportar.php?formato=pdf&busqueda=<?php echo urlencode($busqueda); ?>&estado=<?php echo urlencode($estado); ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-file-pdf"></i> PDF
                    </a>
                    <a href="exportar.php?formato=excel&busqueda=<?php echo urlencode($busqueda); ?>&estado=<?php echo urlencode($estado); ?>" 
                       class="btn btn-secondary">
                        <i class="fas fa-file-excel"></i> Excel
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Confirmación para cambiar estado
        const estadoBtns = document.querySelectorAll('a[href*="cambiar_estado"]');
        estadoBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                const accion = this.title;
                const programa = this.closest('.programa-card').querySelector('h3').textContent;
                
                if (!confirm(`¿Está seguro de que desea ${accion.toLowerCase()} el programa "${programa}"?`)) {
                    e.preventDefault();
                }
            });
        });
        
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
        
        // Efecto hover en programas
        const programaCards = document.querySelectorAll('.programa-card');
        programaCards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.boxShadow = 'var(--shadow-lg)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.boxShadow = 'var(--shadow)';
            });
        });
    });
    </script>
</body>
</html>