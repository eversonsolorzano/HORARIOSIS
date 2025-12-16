<?php
require_once '../../includes/auth.php';
require_once '../../includes/funciones.php';
Auth::requireRole('coordinador');

$db = Database::getConnection();

// Obtener parámetros de filtro
$formato = isset($_GET['formato']) ? Funciones::sanitizar($_GET['formato']) : 'csv';
$busqueda = isset($_GET['busqueda']) ? Funciones::sanitizar($_GET['busqueda']) : '';
$estado = isset($_GET['estado']) ? Funciones::sanitizar($_GET['estado']) : '';
$programa = isset($_GET['programa']) ? intval($_GET['programa']) : 0;
$semestre = isset($_GET['semestre']) ? intval($_GET['semestre']) : 0;
$curso = isset($_GET['curso']) ? intval($_GET['curso']) : 0;

// Construir consulta con filtros
$where = [];
$params = [];

if (!empty($busqueda)) {
    $where[] = "(e.nombres LIKE ? OR e.apellidos LIKE ? OR e.codigo_estudiante LIKE ?)";
    $like = "%$busqueda%";
    $params = array_merge($params, [$like, $like, $like]);
}

if (!empty($estado)) {
    $where[] = "i.estado = ?";
    $params[] = $estado;
}

if ($programa > 0) {
    $where[] = "p.id_programa = ?";
    $params[] = $programa;
}

if ($semestre > 0) {
    $where[] = "s.id_semestre = ?";
    $params[] = $semestre;
}

if ($curso > 0) {
    $where[] = "c.id_curso = ?";
    $params[] = $curso;
}

$where_clause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

// Obtener datos
$sql = "SELECT i.*, 
               e.codigo_estudiante, e.nombres as estudiante_nombres, e.apellidos as estudiante_apellidos,
               e.documento_identidad, e.semestre_actual,
               c.codigo_curso, c.nombre_curso, c.semestre as curso_semestre, c.creditos,
               p.nombre_programa, p.codigo_programa,
               s.codigo_semestre, s.nombre_semestre,
               h.dia_semana, h.hora_inicio, h.hora_fin, h.grupo,
               CONCAT(pr.nombres, ' ', pr.apellidos) as profesor_nombre,
               pr.codigo_profesor,
               a.codigo_aula, a.nombre_aula
        FROM inscripciones i
        JOIN estudiantes e ON i.id_estudiante = e.id_estudiante
        JOIN horarios h ON i.id_horario = h.id_horario
        JOIN cursos c ON h.id_curso = c.id_curso
        JOIN programas_estudio p ON c.id_programa = p.id_programa
        JOIN semestres_academicos s ON h.id_semestre = s.id_semestre
        JOIN profesores pr ON h.id_profesor = pr.id_profesor
        JOIN aulas a ON h.id_aula = a.id_aula
        $where_clause
        ORDER BY i.fecha_inscripcion DESC, e.apellidos, e.nombres";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$inscripciones = $stmt->fetchAll();

// Determinar nombre del archivo
$fecha = date('Y-m-d_H-i-s');
$nombre_archivo = "inscripciones_{$fecha}";

switch ($formato) {
    case 'csv':
        exportarCSV($inscripciones, $nombre_archivo);
        break;
        
    case 'excel':
        exportarExcel($inscripciones, $nombre_archivo);
        break;
        
    case 'pdf':
        exportarPDF($inscripciones, $nombre_archivo);
        break;
        
    default:
        Funciones::redireccionar('index.php', 'Formato no válido', 'error');
}

function exportarCSV($datos, $nombre_archivo) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombre_archivo . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Cabeceras
    fputcsv($output, [
        'ID Inscripción',
        'Código Estudiante',
        'Estudiante',
        'Documento',
        'Programa',
        'Semestre Estudiante',
        'Código Curso',
        'Curso',
        'Semestre Curso',
        'Créditos',
        'Semestre Académico',
        'Día',
        'Hora Inicio',
        'Hora Fin',
        'Grupo',
        'Profesor',
        'Código Profesor',
        'Aula',
        'Fecha Inscripción',
        'Estado',
        'Nota Final'
    ]);
    
    // Datos
    foreach ($datos as $row) {
        fputcsv($output, [
            $row['id_inscripcion'],
            $row['codigo_estudiante'],
            $row['estudiante_nombres'] . ' ' . $row['estudiante_apellidos'],
            $row['documento_identidad'],
            $row['nombre_programa'] . ' (' . $row['codigo_programa'] . ')',
            $row['semestre_actual'],
            $row['codigo_curso'],
            $row['nombre_curso'],
            $row['curso_semestre'],
            $row['creditos'],
            $row['nombre_semestre'] . ' (' . $row['codigo_semestre'] . ')',
            $row['dia_semana'],
            $row['hora_inicio'],
            $row['hora_fin'],
            $row['grupo'] ?: 'N/A',
            $row['profesor_nombre'],
            $row['codigo_profesor'],
            $row['nombre_aula'] . ' (' . $row['codigo_aula'] . ')',
            $row['fecha_inscripcion'],
            $row['estado'],
            $row['nota_final'] ? number_format($row['nota_final'], 1) : 'N/A'
        ]);
    }
    
    fclose($output);
    exit;
}

function exportarExcel($datos, $nombre_archivo) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $nombre_archivo . '.xls"');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<!--[if gte mso 9]>';
    echo '<xml>';
    echo '<x:ExcelWorkbook>';
    echo '<x:ExcelWorksheets>';
    echo '<x:ExcelWorksheet>';
    echo '<x:Name>Inscripciones</x:Name>';
    echo '<x:WorksheetOptions>';
    echo '<x:DisplayGridlines/>';
    echo '</x:WorksheetOptions>';
    echo '</x:ExcelWorksheet>';
    echo '</x:ExcelWorksheets>';
    echo '</x:ExcelWorkbook>';
    echo '</xml>';
    echo '<![endif]-->';
    echo '<style>';
    echo 'td { border: 1px solid #ddd; padding: 5px; }';
    echo 'th { background-color: #f2f2f2; font-weight: bold; border: 1px solid #ddd; padding: 5px; }';
    echo '.aprobado { background-color: #d4edda; }';
    echo '.reprobado { background-color: #f8d7da; }';
    echo '.inscrito { background-color: #d1ecf1; }';
    echo '.retirado { background-color: #fff3cd; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    echo '<table>';
    
    // Cabeceras
    echo '<tr>';
    echo '<th>ID</th>';
    echo '<th>Código Estudiante</th>';
    echo '<th>Estudiante</th>';
    echo '<th>Documento</th>';
    echo '<th>Programa</th>';
    echo '<th>Sem. Est.</th>';
    echo '<th>Código Curso</th>';
    echo '<th>Curso</th>';
    echo '<th>Sem. Curso</th>';
    echo '<th>Créditos</th>';
    echo '<th>Sem. Académico</th>';
    echo '<th>Día</th>';
    echo '<th>Hora Inicio</th>';
    echo '<th>Hora Fin</th>';
    echo '<th>Grupo</th>';
    echo '<th>Profesor</th>';
    echo '<th>Aula</th>';
    echo '<th>Fecha Inscripción</th>';
    echo '<th>Estado</th>';
    echo '<th>Nota Final</th>';
    echo '</tr>';
    
    // Datos
    foreach ($datos as $row) {
        $clase_estado = strtolower($row['estado']);
        echo '<tr>';
        echo '<td>' . $row['id_inscripcion'] . '</td>';
        echo '<td>' . $row['codigo_estudiante'] . '</td>';
        echo '<td>' . htmlspecialchars($row['estudiante_nombres'] . ' ' . $row['estudiante_apellidos']) . '</td>';
        echo '<td>' . $row['documento_identidad'] . '</td>';
        echo '<td>' . htmlspecialchars($row['nombre_programa']) . '</td>';
        echo '<td>' . $row['semestre_actual'] . '</td>';
        echo '<td>' . $row['codigo_curso'] . '</td>';
        echo '<td>' . htmlspecialchars($row['nombre_curso']) . '</td>';
        echo '<td>' . $row['curso_semestre'] . '</td>';
        echo '<td>' . $row['creditos'] . '</td>';
        echo '<td>' . htmlspecialchars($row['nombre_semestre']) . '</td>';
        echo '<td>' . $row['dia_semana'] . '</td>';
        echo '<td>' . $row['hora_inicio'] . '</td>';
        echo '<td>' . $row['hora_fin'] . '</td>';
        echo '<td>' . ($row['grupo'] ?: 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($row['profesor_nombre']) . '</td>';
        echo '<td>' . htmlspecialchars($row['nombre_aula']) . '</td>';
        echo '<td>' . $row['fecha_inscripcion'] . '</td>';
        echo '<td class="' . $clase_estado . '">' . $row['estado'] . '</td>';
        echo '<td>' . ($row['nota_final'] ? number_format($row['nota_final'], 1) : 'N/A') . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    echo '</body></html>';
    exit;
}

function exportarPDF($datos, $nombre_archivo) {
    // Para PDF necesitarías una librería como TCPDF o FPDF
    // Esta es una implementación básica usando FPDF
    
    require_once('../../lib/fpdf/fpdf.php');
    
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    
    // Título
    $pdf->Cell(0, 10, 'Reporte de Inscripciones', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 10, 'Generado: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
    $pdf->Ln(10);
    
    // Cabeceras de tabla
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(200, 220, 255);
    
    $headers = ['ID', 'Estudiante', 'Curso', 'Programa', 'Día', 'Hora', 'Profesor', 'Aula', 'Estado', 'Nota'];
    $widths = [10, 40, 50, 30, 20, 25, 40, 30, 20, 15];
    
    for ($i = 0; $i < count($headers); $i++) {
        $pdf->Cell($widths[$i], 7, $headers[$i], 1, 0, 'C', true);
    }
    $pdf->Ln();
    
    // Datos
    $pdf->SetFont('Arial', '', 8);
    $fill = false;
    
    foreach ($datos as $row) {
        $pdf->Cell($widths[0], 6, $row['id_inscripcion'], 1, 0, 'C', $fill);
        $pdf->Cell($widths[1], 6, substr($row['estudiante_nombres'] . ' ' . $row['estudiante_apellidos'], 0, 25), 1, 0, 'L', $fill);
        $pdf->Cell($widths[2], 6, substr($row['nombre_curso'], 0, 30), 1, 0, 'L', $fill);
        $pdf->Cell($widths[3], 6, substr($row['nombre_programa'], 0, 20), 1, 0, 'L', $fill);
        $pdf->Cell($widths[4], 6, substr($row['dia_semana'], 0, 3), 1, 0, 'C', $fill);
        $pdf->Cell($widths[5], 6, substr($row['hora_inicio'], 0, 5), 1, 0, 'C', $fill);
        $pdf->Cell($widths[6], 6, substr($row['profesor_nombre'], 0, 25), 1, 0, 'L', $fill);
        $pdf->Cell($widths[7], 6, substr($row['nombre_aula'], 0, 15), 1, 0, 'C', $fill);
        $pdf->Cell($widths[8], 6, $row['estado'], 1, 0, 'C', $fill);
        $pdf->Cell($widths[9], 6, $row['nota_final'] ? number_format($row['nota_final'], 1) : '-', 1, 0, 'C', $fill);
        $pdf->Ln();
        
        $fill = !$fill;
    }
    
    // Pie de página
    $pdf->SetY(-15);
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Cell(0, 10, 'Página ' . $pdf->PageNo(), 0, 0, 'C');
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $nombre_archivo . '.pdf"');
    
    $pdf->Output('I', $nombre_archivo . '.pdf');
    exit;
}
?>