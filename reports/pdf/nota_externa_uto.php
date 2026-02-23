<?php
require_once('tcpdf/tcpdf.php');
session_start();
include("conexion.php");

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

// --- FUNCIONES DE APOYO ---
function format_date_es($dateString) {
    if (empty($dateString)) return 'Fecha no disponible';
    $dateTime = new DateTime($dateString);
    $monthNames = ['January'=>'enero','February'=>'febrero','March'=>'marzo','April'=>'abril','May'=>'mayo','June'=>'junio','July'=>'julio','August'=>'agosto','September'=>'septiembre','October'=>'octubre','November'=>'noviembre','December'=>'diciembre'];
    return $dateTime->format('d') . ' de ' . $monthNames[$dateTime->format('F')] . ' de ' . $dateTime->format('Y');
}

function title_case_es($string) {
    return mb_convert_case(mb_strtolower($string, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

$id_pre_defensa = $_GET['id_pre_defensa'] ?? null;
$tipo_carta = $_GET['tipo'] ?? 'UTO'; // 'UTO' o 'COLEGIO'

// --- 1. CONSULTA DATOS ESTUDIANTE ---
$stmt = $pdo->prepare("
    SELECT 
        TRIM(p.primer_nombre || ' ' || COALESCE(p.segundo_nombre,'') || ' ' || p.primer_apellido || ' ' || COALESCE(p.segundo_apellido,'')) AS estudiante,
        c.nombre_carrera, pd.fecha_pre, pd.hora_pre, au.nombre_aula
    FROM public.pre_defensas pd
    JOIN public.personas p ON pd.id_estudiante = p.id_persona
    JOIN public.estudiantes e ON p.id_persona = e.id_persona
    JOIN public.carreras c ON e.id_carrera = c.id_carrera
    LEFT JOIN public.aulas au ON pd.id_aula = au.id_aula
    WHERE pd.id_pre_defensa = :id
");
$stmt->execute(['id' => $id_pre_defensa]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) die("Registro no encontrado.");

// --- 2. GESTIÓN DE CITES (Diferenciados por tipo de carta) ---
$identificador_cite = "REC_" . $tipo_carta; 
$check = $pdo->prepare("SELECT numero_cite FROM public.registro_cites WHERE id_pre_defensa = :id AND tipo_documento = :tipo");
$check->execute(['id' => $id_pre_defensa, 'tipo' => $identificador_cite]);
$cite_final = $check->fetchColumn();

if (!$cite_final) {
    $count = $pdo->query("SELECT COUNT(*) FROM public.registro_cites")->fetchColumn();
    $cite_final = "REC. " . str_pad($count + 1, 3, "0", STR_PAD_LEFT) . "/" . date("Y");
    $ins = $pdo->prepare("INSERT INTO public.registro_cites (id_pre_defensa, tipo_documento, numero_cite) VALUES (:id, :tipo, :cite)");
    $ins->execute(['id' => $id_pre_defensa, 'tipo' => $identificador_cite, 'cite' => $cite_final]);
}

// --- 3. CONFIGURACIÓN SEGÚN DESTINATARIO ---
if ($tipo_carta == 'UTO') {
    $destinatario = "Ing. Augusto Medinaceli Ortiz\nRECTOR\nUNIVERSIDAD TÉCNICA DE ORURO";
    $cantidad_profesionales = "dos profesionales";
    $ref_profesional = "profesionales";
} else {
    // Lógica Colegio (puedes parametrizar esto según la carrera)
    $nombre_colegio = "COLEGIO DEPARTAMENTAL DE " . strtoupper($data['nombre_carrera']);
    $destinatario = "Dr. David Gareca Antequera\nPRESIDENTE\n$nombre_colegio";
    $cantidad_profesionales = "un profesional cualificado";
    $ref_profesional = "profesional";
}

// --- 4. GENERACIÓN DE PDF ---
$pdf = new TCPDF('P', 'mm', 'A4');
$pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
$pdf->SetMargins(25, 25, 25);
$pdf->AddPage();

$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 5, 'Oruro, ' . format_date_es(date('Y-m-d')), 0, 1, 'L');
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 5, 'CITE: ' . $cite_final, 0, 1, 'L');

$pdf->Ln(10);
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 5, 'Señor:', 0, 1, 'L');
$pdf->SetFont('helvetica', 'B', 11);
$pdf->MultiCell(0, 5, $destinatario, 0, 'L');
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 5, 'Presente. -', 0, 1, 'L');

$pdf->Ln(5);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 5, 'Ref.: Solicitud de designación de ' . $ref_profesional . ' para Tribunal Examinador', 0, 1, 'R');

$pdf->Ln(5);
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 5, 'De mi más alta y distinguida consideración:', 0, 1, 'L');

$pdf->Ln(5);
$parrafo1 = "Mediante la presente, y en el ejercicio de mis funciones como Rectora de la Universidad Privada de Oruro, me dirijo a su autoridad con el debido respeto para solicitar la designación de $cantidad_profesionales para que actúen como miembros del Tribunal Examinador en el examen de grado de nuestra egresada " . "<strong>" . title_case_es($data['estudiante']) . "</strong>" . ", de la carrera de " . "<strong>" . title_case_es($data['nombre_carrera']) . "</strong>.";

$pdf->writeHTMLCell(0, 0, '', '', $parrafo1, 0, 1, 0, true, 'J', true);

$pdf->Ln(5);
$pdf->Cell(0, 5, 'La defensa pública está programada conforme al siguiente detalle:', 0, 1, 'L');

// TABLA DE DETALLE
$pdf->Ln(2);
$tbl = '
<table border="1" cellpadding="4">
    <tr style="background-color:#f2f2f2; text-align:center; font-weight:bold;">
        <th width="30%">POSTULANTE</th>
        <th width="25%">FECHA</th>
        <th width="15%">HORA</th>
        <th width="30%">LUGAR</th>
    </tr>
    <tr style="text-align:center;">
        <td>' . title_case_es($data['estudiante']) . '</td>
        <td>' . format_date_es($data['fecha_pre']) . '</td>
        <td>' . (new DateTime($data['hora_pre']))->format('H:i') . '</td>
        <td>' . ($data['nombre_aula'] ?? 'Instalaciones UNIOR') . '</td>
    </tr>
</table>';
$pdf->writeHTML($tbl, true, false, false, false, '');

$pdf->Ln(5);
$legal = "Esta solicitud se fundamenta en la Constitución Política del Estado en su Art. 94 parágrafo III; la Ley Avelino Siñani y Elizardo Pérez en su Art. 55 y siguientes; y el Reglamento de Universidades Privadas en su Art. 59 del D.S. 1433, de 12 de diciembre de 2012.";
$pdf->MultiCell(0, 5, $legal, 0, 'J');

$pdf->Ln(5);
$pdf->Cell(0, 5, 'Con este motivo, le reitero mis consideraciones más distinguidas y aprovecho la oportunidad para saludarlo cordialmente.', 0, 1, 'L');

$pdf->Ln(15);
$pdf->Cell(0, 5, 'Atentamente,', 0, 1, 'L');

$pdf->Output('Carta_Designacion.pdf', 'I');