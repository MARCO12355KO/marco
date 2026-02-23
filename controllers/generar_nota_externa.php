<?php
declare(strict_types=1);
session_start();

require_once('../tcpdf/tcpdf.php');
require_once('../config/conexion.php');

// ==================== SEGURIDAD DE SESIÓN ====================
if (!isset($_SESSION['user_id'])) {
    die("Sesión no iniciada.");
}

// ==================== FUNCIONES AUXILIARES ====================
function fecha_es($fecha) {
    $nombres_meses = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
    $d = new DateTime($fecha);
    return $d->format('d') . ' de ' . $nombres_meses[$d->format('n')-1] . ' de ' . $d->format('Y');
}

// ==================== OBTENCIÓN DE DATOS ====================
$id_pre = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$tipo = $_GET['tipo'] ?? 'UTO'; // UTO o COLEGIO

if (!$id_pre) die("ID de estudiante no válido.");

try {
    // Consulta unificada: Datos de la defensa formal y el estudiante
    $stmt = $pdo->prepare("
        SELECT 
            TRIM(p.primer_nombre || ' ' || COALESCE(p.segundo_nombre || ' ', '') || p.primer_apellido || ' ' || COALESCE(p.segundo_apellido, '')) as estudiante,
            c.nombre_carrera, df.fecha_defensa, df.hora, au.nombre_aula, pd.gestion
        FROM public.pre_defensas pd
        JOIN public.personas p ON pd.id_estudiante = p.id_persona
        JOIN public.estudiantes e ON p.id_persona = e.id_persona
        JOIN public.carreras c ON e.id_carrera = c.id_carrera
        JOIN public.defensa_formal df ON pd.id_pre_defensa = df.id_pre_defensa
        LEFT JOIN public.aulas au ON df.id_aula = au.id_aula
        WHERE pd.id_pre_defensa = ?
    ");
    $stmt->execute([$id_pre]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) die("Debe registrar la fecha de defensa formal primero.");

    // ==================== GESTIÓN DE CITE (CORRELATIVO) ====================
    $identificador = "EXT_" . $tipo;
    $stmtCite = $pdo->prepare("SELECT numero_cite FROM public.registro_cites WHERE id_pre_defensa = ? AND tipo_documento = ?");
    $stmtCite->execute([$id_pre, $identificador]);
    $cite_final = $stmtCite->fetchColumn();

    if (!$cite_final) {
        $count = $pdo->query("SELECT COUNT(*) FROM public.registro_cites WHERE tipo_documento = '$identificador'")->fetchColumn();
        $cite_final = "REC. " . str_pad((string)($count + 1), 3, "0", STR_PAD_LEFT) . "/" . date("m/Y");
        $ins = $pdo->prepare("INSERT INTO public.registro_cites (id_pre_defensa, tipo_documento, numero_cite) VALUES (?, ?, ?)");
        $ins->execute([$id_pre, $identificador, $cite_final]);
    }

    // ==================== CONFIGURACIÓN DE DESTINATARIO ====================
    if ($tipo === 'UTO') {
        $destinatario = "Ing. Augusto Medinaceli Ortiz\n<b>RECTOR</b>\nUNIVERSIDAD TÉCNICA DE ORURO";
        $profesionales = "dos profesionales";
    } else {
        $destinatario = "PRESIDENTE\n<b>COLEGIO DEPARTAMENTAL DE " . strtoupper($data['nombre_carrera']) . "</b>";
        $profesionales = "un profesional cualificado";
    }

    // ==================== CREACIÓN DEL PDF ====================
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
    $pdf->SetMargins(25, 25, 25);
    $pdf->AddPage();

    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 5, 'Oruro, ' . fecha_es(date('Y-m-d')), 0, 1, 'R');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 5, 'CITE: ' . $cite_final, 0, 1, 'L');
    $pdf->Ln(10);

    // Bloque destinatario
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 5, 'Señor:', 0, 1, 'L');
    $pdf->writeHTMLCell(0, 0, '', '', $destinatario, 0, 1, 0, true, 'L');
    $pdf->Cell(0, 5, 'Presente. -', 0, 1, 'L');
    $pdf->Ln(5);

    // Referencia
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 5, 'Ref.: Solicitud de designación de ' . ($tipo === 'UTO' ? 'profesionales' : 'profesional') . ' para Tribunal Examinador', 0, 1, 'R');
    $pdf->Ln(10);

    // Cuerpo
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 5, 'De mi más alta y distinguida consideración:', 0, 1, 'L');
    $pdf->Ln(5);

    $html_cuerpo = '<p style="text-align:justify;">En mi calidad de Rectora de la Universidad Privada de Oruro, me dirijo a su autoridad para solicitar la designación de ' . $profesionales . ' para que actúen como miembros del Tribunal Examinador en el examen de grado de nuestra egresada <b>' . $data['estudiante'] . '</b>, de la carrera de <b>' . $data['nombre_carrera'] . '</b>.</p>';
    $pdf->writeHTML($html_cuerpo, true, false, true, false, 'J');

    $pdf->Ln(2);
    $pdf->Cell(0, 5, 'La defensa pública está programada conforme al siguiente detalle:', 0, 1, 'L');
    $pdf->Ln(2);

    // Tabla de detalle
    $tabla = '
    <table border="1" cellpadding="5" style="border-collapse:collapse; text-align:center;">
        <tr style="background-color:#f2f2f2; font-weight:bold;">
            <th width="40%">POSTULANTE</th>
            <th width="25%">FECHA</th>
            <th width="15%">HORA</th>
            <th width="20%">LUGAR</th>
        </tr>
        <tr>
            <td>' . $data['estudiante'] . '</td>
            <td>' . fecha_es($data['fecha_defensa']) . '</td>
            <td>' . substr($data['hora'], 0, 5) . '</td>
            <td>' . ($data['nombre_aula'] ?? 'UNIOR') . '</td>
        </tr>
    </table>';
    $pdf->writeHTML($tabla, true, false, false, false, '');

    // Base legal
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', '', 10);
    $legal = "La presente solicitud se enmarca en lo dispuesto por la Constitución Política del Estado en su Art. 94 parágrafo III; la Ley Avelino Siñani y Elizardo Pérez en su Art. 55 y siguientes; y el Reglamento de Universidades Privadas en su Art. 59 del D.S. 1433.";
    $pdf->MultiCell(0, 5, $legal, 0, 'J');

    $pdf->Ln(5);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 5, 'Con este motivo, le reitero mis consideraciones más distinguidas.', 0, 1, 'L');

    // Firma
    $pdf->Ln(25);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 5, 'Atentamente,', 0, 1, 'C');
    $pdf->Ln(20);
    $pdf->Cell(0, 5, '__________________________', 0, 1, 'C');
    $pdf->Cell(0, 5, 'Ph.D. María del Carmen Böhrt Cortés', 0, 1, 'C');
    $pdf->Cell(0, 5, 'RECTORA UNIOR', 0, 1, 'C');

    ob_end_clean();
    $pdf->Output('Nota_Externa_' . $tipo . '.pdf', 'I');

} catch (Exception $e) {
    die("Error al generar PDF: " . $e->getMessage());
}