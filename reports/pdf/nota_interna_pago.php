<?php
require_once('tcpdf/tcpdf.php'); 
session_start();

// Usa el archivo de conexión que ya definimos para Postgres
include("conexion.php"); 

// 1. Verificación de sesión
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// --- Funciones de Formato ---
function format_date_es($dateString) {
    if (empty($dateString)) return 'Fecha no disponible';
    $dateTime = new DateTime($dateString);
    $monthNames = [
        'January' => 'enero', 'February' => 'febrero', 'March' => 'marzo', 
        'April' => 'abril', 'May' => 'mayo', 'June' => 'junio', 
        'July' => 'julio', 'August' => 'agosto', 'September' => 'septiembre', 
        'October' => 'octubre', 'November' => 'noviembre', 'December' => 'diciembre'
    ];
    $date = $dateTime->format('d F Y');
    $date_es = str_replace(array_keys($monthNames), array_values($monthNames), $date);
    return str_replace(' ', ' de ', $date_es);
}

function title_case_es($string) {
    $string = mb_strtolower($string, 'UTF-8');
    $exceptions = ['de', 'la', 'del', 'los', 'las', 'un', 'una', 'y'];
    $words = explode(' ', $string);
    $result = [];
    foreach ($words as $word) {
        if (in_array($word, $exceptions) && count($result) > 0) {
            $result[] = $word;
        } else {
            $result[] = mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
        }
    }
    return implode(' ', $result);
}

$id_pre_defensa = $_GET['id_pre_defensa'] ?? null;
if (!is_numeric($id_pre_defensa)) {
    die("Error: ID de Pre-Defensa inválido.");
}

// 2. Consulta PostgreSQL (Usando PDO y nombres de tu base de datos)
// En tu dump la tabla es pre_defensas y personas
$stmt = $pdo->prepare("
    SELECT 
        TRIM(p.primer_nombre || ' ' || COALESCE(p.segundo_nombre,'') || ' ' || p.primer_apellido || ' ' || COALESCE(p.segundo_apellido,'')) AS nombre_estudiante,
        c.nombre_carrera, pd.fecha_pre, pd.hora_pre
    FROM public.pre_defensas pd
    JOIN public.personas p ON pd.id_estudiante = p.id_persona
    JOIN public.estudiantes e ON p.id_persona = e.id_persona
    JOIN public.carreras c ON e.id_carrera = c.id_carrera
    WHERE pd.id_pre_defensa = :id
");
$stmt->execute(['id' => $id_pre_defensa]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    die("Error: No se encontró la Pre-Defensa.");
}

// 3. Lógica de Correlativo (Tabla registro_cites según tu dump)
$tipo_documento = 'INTERNA';
$check = $pdo->prepare("SELECT numero_cite FROM public.registro_cites WHERE id_pre_defensa = :id AND tipo_documento = :tipo");
$check->execute(['id' => $id_pre_defensa, 'tipo' => $tipo_documento]);
$dir_tit = $check->fetchColumn();

if (!$dir_tit) {
    $count = $pdo->query("SELECT COUNT(*) FROM public.registro_cites")->fetchColumn();
    $dir_num = $count + 1;
    $dir_tit = "DIR. TIT. " . str_pad($dir_num, 3, "0", STR_PAD_LEFT) . "/" . date("Y"); 

    $insert = $pdo->prepare("INSERT INTO public.registro_cites (id_pre_defensa, tipo_documento, numero_cite) VALUES (:id, :tipo, :cite)");
    $insert->execute(['id' => $id_pre_defensa, 'tipo' => $tipo_documento, 'cite' => $dir_tit]);
}

// Preparar variables para el HTML
$fecha_defensa = format_date_es($data['fecha_pre']);
$hora_defensa = (new DateTime($data['hora_pre']))->format('H:i');
$fecha_documento = format_date_es(date('Y-m-d')); 
$nombre_estudiante_formateado = title_case_es($data['nombre_estudiante']);
$nombre_carrera_formateada = title_case_es($data['nombre_carrera']);

// 4. Generación del PDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetTitle('Nota Interna - UNIOR');
$pdf->SetMargins(18, 30, 18);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

if (file_exists('logo_unior.png')) {
    $pdf->Image('logo_unior.png', 18, 15, 20);
}

$pdf->SetY(20);
$pdf->SetFont('helvetica', 'B', 12); 
$pdf->Cell(0, 5, 'NOTA INTERNA', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 5, 'Ref.: ' . $dir_tit, 0, 1, 'C');
$pdf->Ln(15); 

// Encabezado
$lh = 5; $tab = 45;
$pdf->SetFont('helvetica', '', 11);

// A:
$pdf->Cell(15, $lh, 'A', 0, 0); $pdf->Cell(5, $lh, ':', 0, 0); 
$pdf->SetX($tab); $pdf->SetFont('helvetica', 'B', 11); $pdf->Cell(0, $lh, 'Lic. Wendy Ponce', 0, 1);
$pdf->SetX($tab); $pdf->SetFont('helvetica', '', 11); $pdf->Cell(0, $lh, 'DIRECTORA ADMINISTRATIVA FINANCIERA', 0, 1);
$pdf->Ln(3);

// VIA:
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(15, $lh, 'VIA', 0, 0); $pdf->Cell(5, $lh, ':', 0, 0); 
$pdf->SetX($tab); $pdf->SetFont('helvetica', 'B', 11); $pdf->Cell(0, $lh, 'M.Sc. Nancy Cortés', 0, 1);
$pdf->SetX($tab); $pdf->SetFont('helvetica', '', 11); $pdf->Cell(0, $lh, 'VICERRECTORA ACADÉMICA', 0, 1);
$pdf->Ln(3);

// DE:
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(15, $lh, 'DE', 0, 0); $pdf->Cell(5, $lh, ':', 0, 0); 
$pdf->SetX($tab); $pdf->SetFont('helvetica', 'B', 11); $pdf->Cell(0, $lh, 'Lic. Alex Pantoja Montán.', 0, 1);
$pdf->SetX($tab); $pdf->SetFont('helvetica', '', 11); $pdf->Cell(0, $lh, 'DIRECTOR DE TITULACIÓN', 0, 1);
$pdf->Ln(3);

// REF:
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(15, $lh, 'REF.', 0, 0); $pdf->Cell(5, $lh, ':', 0, 0); 
$pdf->SetX($tab); $pdf->SetFont('helvetica', 'B', 10); 
$pdf->MultiCell(0, $lh, 'Solicitud de Pago a Tribunal Externo de la Universidad Técnica de Oruro (UTO) por Defensa Pública de Grado del Estudiante '.$nombre_estudiante_formateado . ', Carrera de ' . $nombre_carrera_formateada, 0, 'L');
$pdf->Ln(3);

// FECHA:
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(15, $lh, 'FECHA', 0, 0); $pdf->Cell(5, $lh, ':', 0, 0); 
$pdf->SetX($tab); $pdf->Cell(0, $lh, $fecha_documento, 0, 1);

$pdf->Line(18, $pdf->GetY()+5, 192, $pdf->GetY()+5);
$pdf->Ln(10);

// Cuerpo HTML con tu texto exacto
$html = '
<style>
    p { text-align: justify; line-height: 1.5; }
</style>
<p>Estimada Licenciada Ponce:</p>
<p>Mediante la presente, me dirijo a usted con la finalidad de solicitar la gestión de pago correspondiente a los miembros del Tribunal Externo de la <strong>Universidad Técnica de Oruro (UTO)</strong> designados para la defensa pública de grado del estudiante <strong>'.$nombre_estudiante_formateado.'</strong>, de la carrera de <strong>'.$nombre_carrera_formateada.'</strong>.</p>
<p>La defensa está programada para el día <strong>'.$fecha_defensa.'</strong> a horas <strong>'.$hora_defensa.'</strong>, de conformidad con el calendario académico establecido. Para tal efecto, se adjunta al presente los siguientes documentos:</p>
<ol>
    <li>Solicitud formal del estudiante.</li>
    <li>Original del comprobante de pago por el proceso de defensa pública de grado.</li>
    <li>Nómina de Tribunales Externos (UTO).</li>
</ol>
<p>Se solicita a su despacho que, de acuerdo con los procedimientos administrativos y financieros de nuestra casa de estudios, se proceda con la emisión del cheque o el mecanismo de pago correspondiente a los profesionales externos de la <strong>UTO</strong> que conformarán el tribunal examinador.</p>
<p>Sin otro particular, me despido con las consideraciones más distinguidas.</p>';

$pdf->writeHTML($html, true, false, true, false, '');

$pdf->Ln(20); 
$pdf->SetFont('helvetica', 'B', 10); 
$pdf->Cell(0, 5, 'Lic. Alex Pantoja Montán', 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 5, 'DIRECTOR DE TITULACIÓN', 0, 1, 'C');
$pdf->Cell(0, 5, 'UNIVERSIDAD PRIVADA DE ORURO - UNIOR', 0, 1, 'C');

$pdf->Output('nota_interna_'.$id_pre_defensa.'.pdf', 'I');