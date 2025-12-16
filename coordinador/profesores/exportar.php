<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

// Obtener filtros
$busqueda = isset($_GET['busqueda']) ? Funciones::sanitizar($_GET['busqueda']) : '';
$estado = isset($_GET['estado']) ? Funciones::sanitizar($_GET['estado']) : '';
$programa = isset($_GET['programa']) ? Funciones::sanitizar($_GET['programa']) : '';
$formato = isset($_GET['formato']) ? Funciones::sanitizar($_GET['formato']) : 'csv';

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

// Obtener profesores
$sql = "SELECT p.*, u.email, u.username, u.fecha_creacion,
               CASE WHEN p.activo = 1 THEN 'Activo' ELSE 'Inactivo' END as estado_texto
        FROM profesores p
        LEFT JOIN usuarios u ON p.id_usuario = u.id_usuario
        $where_clause
        ORDER BY p.id_profesor DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$profesores = $stmt->fetchAll();

// Generar exportación según formato
switch ($formato) {
    case 'csv':
        exportarCSV($profesores);
        break;
        
    case 'excel':
        exportarExcel($profesores);
        break;
        
    case 'pdf':
        exportarPDF($profesores);
        break;
        
    default:
        exportarCSV($profesores);
}

function exportarCSV($profesores) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=profesores_' . date('Y-m-d_H-i') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // BOM para UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // Encabezados
    fputcsv($output, [
        'Código',
        'Documento',
        'Nombres',
        'Apellidos',
        'Título Académico',
        'Especialidad',
        'Email Institucional',
        'Email Personal',
        'Teléfono',
        'Programas Dictados',
        'Estado',
        'Usuario',
        'Fecha Registro'
    ], ';');
    
    // Datos
    foreach ($profesores as $prof) {
        fputcsv($output, [
            $prof['codigo_profesor'],
            $prof['documento_identidad'],
            $prof['nombres'],
            $prof['apellidos'],
            $prof['titulo_academico'],
            $prof['especialidad'],
            $prof['email_institucional'],
            $prof['email'],
            $prof['telefono'] ?? '',
            $prof['programas_dictados'],
            $prof['estado_texto'],
            $prof['username'],
            $prof['fecha_creacion']
        ], ';');
    }
    
    fclose($output);
    exit;
}

function exportarExcel($profesores) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename=profesores_' . date('Y-m-d_H-i') . '.xls');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Profesores</title>
        <style>
            table { border-collapse: collapse; width: 100%; }
            th { background-color: #4CAF50; color: white; font-weight: bold; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            tr:nth-child(even) { background-color: #f2f2f2; }
            .header { background-color: #1e40af; color: white; padding: 20px; text-align: center; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Listado de Profesores</h1>
            <p>Generado: ' . date('d/m/Y H:i') . '</p>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Documento</th>
                    <th>Nombres</th>
                    <th>Apellidos</th>
                    <th>Título</th>
                    <th>Especialidad</th>
                    <th>Email Institucional</th>
                    <th>Email Personal</th>
                    <th>Teléfono</th>
                    <th>Programas</th>
                    <th>Estado</th>
                    <th>Usuario</th>
                    <th>Fecha Registro</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($profesores as $prof) {
        echo '<tr>
                <td>' . htmlspecialchars($prof['codigo_profesor']) . '</td>
                <td>' . htmlspecialchars($prof['documento_identidad']) . '</td>
                <td>' . htmlspecialchars($prof['nombres']) . '</td>
                <td>' . htmlspecialchars($prof['apellidos']) . '</td>
                <td>' . htmlspecialchars($prof['titulo_academico']) . '</td>
                <td>' . htmlspecialchars($prof['especialidad']) . '</td>
                <td>' . htmlspecialchars($prof['email_institucional']) . '</td>
                <td>' . htmlspecialchars($prof['email']) . '</td>
                <td>' . htmlspecialchars($prof['telefono'] ?? '') . '</td>
                <td>' . htmlspecialchars($prof['programas_dictados']) . '</td>
                <td>' . $prof['estado_texto'] . '</td>
                <td>' . htmlspecialchars($prof['username']) . '</td>
                <td>' . $prof['fecha_creacion'] . '</td>
              </tr>';
    }
    
    echo '</tbody>
        </table>
        
        <div style="margin-top: 20px; text-align: center; color: #666;">
            <p>Total de profesores: ' . count($profesores) . '</p>
            <p>Sistema de Horarios - Instituto</p>
        </div>
    </body>
    </html>';
    
    exit;
}

function exportarPDF($profesores) {
    // Requiere TCPDF o similar, aquí un ejemplo básico
    // En un entorno real, instalaría TCPDF: composer require tecnickcom/tcpdf
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>PDF - Profesores</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #1e40af; padding-bottom: 20px; }
            .header h1 { color: #1e40af; margin: 0; }
            .header p { color: #666; margin: 5px 0; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th { background-color: #1e40af; color: white; padding: 10px; text-align: left; }
            td { padding: 8px; border-bottom: 1px solid #ddd; }
            .footer { margin-top: 30px; text-align: center; color: #666; font-size: 12px; }
            @media print {
                body { margin: 0; }
                .no-print { display: none; }
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Listado de Profesores</h1>
            <p>Instituto - Sistema de Horarios</p>
            <p>Generado: ' . date('d/m/Y H:i') . ' | Total: ' . count($profesores) . ' profesores</p>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Documento</th>
                    <th>Nombre Completo</th>
                    <th>Título</th>
                    <th>Especialidad</th>
                    <th>Email</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($profesores as $prof) {
        echo '<tr>
                <td>' . htmlspecialchars($prof['codigo_profesor']) . '</td>
                <td>' . htmlspecialchars($prof['documento_identidad']) . '</td>
                <td>' . htmlspecialchars($prof['nombres'] . ' ' . $prof['apellidos']) . '</td>
                <td>' . htmlspecialchars($prof['titulo_academico']) . '</td>
                <td>' . htmlspecialchars($prof['especialidad']) . '</td>
                <td>' . htmlspecialchars($prof['email_institucional']) . '</td>
                <td>' . $prof['estado_texto'] . '</td>
              </tr>';
    }
    
    echo '</tbody>
        </table>
        
        <div class="footer">
            <p>Página 1 de 1</p>
            <p>Sistema de Gestión de Horarios - Todos los derechos reservados</p>
            <button class="no-print" onclick="window.print()">Imprimir PDF</button>
        </div>
        
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                // Auto-print para PDF
                setTimeout(function() {
                    window.print();
                }, 500);
            });
        </script>
    </body>
    </html>';
    
    exit;
}