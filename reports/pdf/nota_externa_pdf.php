<?php
require_once('tcpdf/tcpdf.php'); 
session_start();
include("conexion.php"); // Tu conexión PDO

if (!isset($_SESSION['user_id']) && !isset($_SESSION['usuario'])) { header("Location: login.php"); exit(); }

$id_pre = $_GET['id_pre_defensa'] ?? null;
if (!$id_pre) die("Falta ID");

// 1. Obtener Siguiente Número de Nota
$num_id = $pdo->query("SELECT nextval('public.secuencia_documentos_titulacion')")->fetchColumn();
$correlativo = str_pad($num_id, 4, "0", STR_PAD_LEFT);

// 2. Datos del Estudiante
$stmt = $pdo->prepare("SELECT pd.*, (p.primer_nombre || ' ' || p.primer_apellido) as estudiante, c.nombre_carrera 
                       FROM public.pre_defensas pd 
                       JOIN public.personas p ON pd.id_estudiante = p.id_persona
                       JOIN public.estudiantes e ON p.id_persona = e.id_persona
                       JOIN public.carreras c ON e.id_carrera = c.id_carrera
                       WHERE pd.id_pre_defensa = :id");
$stmt->execute(['id' => $id_pre]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

// 3. Validación Modalidad
$tipo = (strpos(strtoupper($data['tema_proyecto']), 'EXAMEN') !== false) ? "EXAMEN DE GRADO" : "DEFENSA DE TESIS";

// 4. Crear PDF
$pdf = new TCPDF('P', 'mm', 'A4');
$pdf->setPrintHeader(false);
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 10, 'NOTA INTERNA #' . $correlativo, 0, 1, 'C');
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 10, 'REF: UNIOR/TIT/' . $data['gestion'] . '/' . $correlativo, 0, 1, 'C');
$pdf->Ln(5);

$html = "
<p><b>A:</b> Lic. Wendy Ponce - DIRECTORA ADMINISTRATIVA</p>
<p><b>DE:</b> Lic. Alex Pantoja Montán - DIRECTOR DE TITULACIÓN</p>
<p><b>REF:</b> SOLICITUD DE PAGO TRIBUNAL - $tipo - {$data['estudiante']}</p>
<hr>
<p style='text-align:justify;'>Distinguida Directora, mediante la presente se solicita el pago de tribunales para el estudiante <b>{$data['estudiante']}</b> de la carrera de {$data['nombre_carrera']}...</p>";

$pdf->writeHTML($html);
$pdf->Output('Nota_Interna.pdf', 'I');