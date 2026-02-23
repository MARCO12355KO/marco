<?php
declare(strict_types=1);
session_start();

require_once('../tcpdf/tcpdf.php');
require_once('../config/conexion.php');

// ==================== VERIFICACIÓN DE SESIÓN ====================
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Datos del usuario conectado para la firma 
$nombre_firmante = $_SESSION['nombre_completo'] ?? 'Usuario del Sistema'; 
$cargo_firmante = strtoupper($_SESSION['role'] ?? 'Técnico en Titulación'); 
$iniciales_usuario = $_SESSION['iniciales'] ?? 'SLAC'; // Para el pie de página 

// ==================== OBTENCIÓN DE DATOS DE DEFENSA ====================
$id_pre = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id_pre) {
    die("ID de pre-defensa no válido.");
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            pd.id_pre_defensa, pd.gestion, pd.fecha, pd.hora, pd.modalidad_titulacion,
            TRIM(p.primer_nombre || ' ' || COALESCE(p.segundo_nombre || ' ', '') || p.primer_apellido || ' ' || COALESCE(p.segundo_apellido, '')) as estudiante_nombre,
            c.nombre_carrera,
            au.nombre_aula
        FROM public.pre_defensas pd
        JOIN public.personas p ON pd.id_estudiante = p.id_persona
        JOIN public.estudiantes e ON p.id_persona = e.id_persona
        JOIN public.carreras c ON e.id_carrera = c.id_carrera
        LEFT JOIN public.aulas au ON pd.id_aula = au.id_aula
        WHERE pd.id_pre_defensa = ?
    ");
    $stmt->execute([$id_pre]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) die("Datos no encontrados.");

    // ==================== GENERACIÓN DEL PDF ====================
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(25, 20, 25);
    $pdf->AddPage();

    // Logotipo (ajustar ruta si es necesario)
    // $pdf->Image('../assets/img/logo_unior.png', 25, 10, 25);

    // Título y Cite [cite: 1, 2]
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 5, 'NOTA INTERNA', 0, 1, 'C');
    $pdf->Cell(0, 5, 'DIR. TIT. 001/' . $data['gestion'], 0, 1, 'C');
    $pdf->Ln(10);

    // Encabezado VIA / A / DE [cite: 3, 7, 11]
    $meses = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
    $fecha_actual = date('d') . ' de ' . $meses[date('n')-1] . ' de ' . date('Y');

    $html_header = '
    <table cellpadding="2">
        <tr><td width="12%">VIA</td><td width="3%">:</td><td width="85%">Ph.D. María del Carmen Böhrt Cortés<br><b>RECTORA UNIOR</b></td></tr>
        <tr><td></td><td>:</td><td>M.Sc. Nancy Cortés<br><b>VICERRECTORA ACADÉMICA</b></td></tr>
        <tr><td>A</td><td>:</td><td>Lic. Wendy Ponce<br><b>DIRECTORA ADMINISTRATIVA FINANCIERA</b></td></tr>
        <tr><td>VIA</td><td>:</td><td>Lic. Alex Pantoja Montán<br><b>DIRECTOR DE TITULACIÓN</b></td></tr>
        <tr><td>DE</td><td>:</td><td>' . $nombre_firmante . '<br><b>' . $cargo_firmante . '</b></td></tr>
        <tr><td>REF.</td><td>:</td><td><b>SOLICITUD DE PAGO A TRIBUNAL EXTERNO - UTO</b></td></tr>
        <tr><td>FECHA:</td><td>:</td><td>' . $fecha_actual . '</td></tr>
    </table>';

    $pdf->SetFont('helvetica', '', 10);
    $pdf->writeHTML($html_header, true, false, true, false, '');
    $pdf->Ln(2);
    $pdf->Line(25, $pdf->GetY(), 185, $pdf->GetY()); // Línea divisoria de la imagen
    $pdf->Ln(5);

    // Cuerpo de la nota [cite: 15, 16]
    $pdf->Cell(0, 10, 'Estimada Licenciada Ponce,', 0, 1, 'L');
    
    $modalidad = str_replace('_', ' ', $data['modalidad_titulacion']);
    $fecha_defensa = date('d', strtotime($data['fecha'])) . ' de ' . $meses[date('n', strtotime($data['fecha']))-1] . ' ' . date('Y', strtotime($data['fecha']));

    $html_cuerpo = '
    <p style="text-align:justify;">Mediante la presente, me dirijo a usted con la finalidad de solicitar la gestión de pago correspondiente a los miembros del Tribunal Externo de la <b>Universidad Técnica de Oruro (UTO)</b> designados para la defensa pública de grado del estudiante <b>' . $data['estudiante_nombre'] . '</b>, de la carrera de <b>' . $data['nombre_carrera'] . '</b>, en la modalidad de titulación por <b>' . $modalidad . '</b>.</p>
    
    <p style="text-align:justify;">La defensa está programada para el día <b>' . $fecha_defensa . ', a horas ' . substr($data['hora'], 0, 5) . ' en ambientes del ' . ($data['nombre_aula'] ?? 'Auditorio de la Universidad') . '</b>, en conformidad con el calendario académico establecido. Para tal efecto, se adjunta al presente los siguientes documentos:</p>
    
    <ol>
        <li>Solicitud formal del estudiante.</li>
        <li>Original del comprobante de pago por el proceso de defensa pública de grado.</li>
    </ol>
    
    <p style="text-align:justify;">Se solicita a su despacho que, de acuerdo con los procedimientos administrativos y financieros de nuestra Superior Casa de Estudios, se proceda con la emisión del cheque o el mecanismo de pago correspondiente a los profesionales externos de la <b>UTO</b> que conformarán el tribunal examinador.</p>';

    $pdf->writeHTML($html_cuerpo, true, false, true, false, '');
    $pdf->Ln(5);

    $pdf->Cell(0, 10, 'Sin otro particular, me despido con las consideraciones más distinguidas.', 0, 1, 'L');

    // Pie de página [cite: 23, 24]
    $pdf->Ln(15);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(0, 4, 'C.c. Arch', 0, 1, 'L');
    $pdf->Cell(0, 4, $iniciales_usuario, 0, 1, 'L');

    ob_end_clean();
    $pdf->Output('Nota_Interna_' . $id_pre . '.pdf', 'I');

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}