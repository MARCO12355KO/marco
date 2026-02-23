<?php
declare(strict_types=1);
require_once 'tcpdf/tcpdf.php';
require_once 'config/conexion.php';
$id = $_GET['id'] ?? null;
if (!$id) {
    die("Error: ID de estudiante no proporcionado.");
}
$sql = "SELECT p.*, e.ru, c.nombre_carrera 
        FROM personas p 
        JOIN estudiantes e ON p.id_persona = e.id_persona 
        JOIN carreras c ON e.id_carrera = c.id_carrera 
        WHERE p.id_persona = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$est = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$est) {
    die("Error: Estudiante no encontrado.");
}

class MYPDF extends TCPDF {
    public function Header() {
        $image_file = 'assets/img/logo_unior1.png';
        if (file_exists($image_file)) {
            $this->Image($image_file, 15, 10, 30, '', 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }
        
        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(0, 15, 'UNIVERSIDAD PRIVADA DE ORURO', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Ln(7);
        $this->SetFont('helvetica', 'I', 10);
        $this->Cell(0, 15, 'Kárdex de Registro Estudiantil', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        
        // Línea decorativa
        $this->Line(15, 35, 195, 35);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Página '.$this->getAliasNumPage().'/'.$this->getAliasNbPages().' - Sistema UNIOR 2026', 0, false, 'C');
    }
}

// Crear instancia
$pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Metadatos
$pdf->SetCreator('Sistema UNIOR');
$pdf->SetTitle('Kárdex_' . $est['ru']);

// Margenes
$pdf->SetMargins(20, 45, 20);
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

$pdf->AddPage();
$pdf->SetFont('helvetica', '', 11);

// Contenido HTML para el diseño
$nombre_completo = mb_strtoupper($est['primer_nombre'] . ' ' . $est['primer_apellido']);

$html = '
<style>
    .titulo { background-color: #f2f2f2; font-weight: bold; }
    .label { font-weight: bold; color: #444; width: 150px; }
    .valor { color: #000; }
    table { border-collapse: collapse; width: 100%; }
    td { padding: 8px; border-bottom: 0.5px solid #ccc; }
</style>

<br><br>
<h2 style="text-align:center; color:#1e293b;">INFORMACIÓN DEL ESTUDIANTE</h2>
<br><br>

<table>
    <tr>
        <td class="label">NOMBRE COMPLETO:</td>
        <td class="valor">'.$nombre_completo.'</td>
    </tr>
    <tr>
        <td class="label">NÚMERO DE R.U.:</td>
        <td class="valor"><b>'.$est['ru'].'</b></td>
    </tr>
    <tr>
        <td class="label">CÉDULA DE IDENTIDAD:</td>
        <td class="valor">'.$est['ci'].'</td>
    </tr>
    <tr>
        <td class="label">CARRERA:</td>
        <td class="valor">'.$est['nombre_carrera'].'</td>
    </tr>
</table>

<br><br><br>
<p style="text-align: justify; font-size: 10pt; color: #555;">
    El presente documento certifica que el estudiante se encuentra registrado en la base de datos de la Universidad Privada de Oruro. Este reporte es de uso interno y administrativo.
</p>

<br><br><br><br>
<table border="0">
    <tr>
        <td style="text-align:center;">__________________________<br>Firma Estudiante</td>
        <td style="text-align:center;">__________________________<br>Sello y Firma Registro</td>
    </tr>
</table>
';

$pdf->writeHTML($html, true, false, true, false, '');

// 4. Salida del PDF
$pdf->Output('Kardex_'.$est['ru'].'.pdf', 'I');