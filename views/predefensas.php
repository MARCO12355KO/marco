<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit(); }
require_once '../config/conexion.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$nombre_usuario = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$rol = htmlspecialchars((string)($_SESSION["role"] ?? 'Invitado'), ENT_QUOTES, 'UTF-8');

// ===================== MOTOR AJAX POST =====================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // ---------------------------------------------------------
    // GENERADOR DE REPORTE TCPDF (6 POR HOJA Y LOGO DESDE URL)
    // ---------------------------------------------------------
    if ($_POST['action'] == 'generar_pdf') {
        require_once '../tcpdf/tcpdf.php'; 

        $tipo_reporte = $_POST['tipo_reporte'] ?? 'pendientes'; 
        $f_fecha = $_POST['f_fecha'] ?? '';
        $f_carrera = $_POST['f_carrera'] ?? '';
        $f_est = $_POST['f_estudiante'] ?? '';

        $sql = "SELECT pd.*, 
                   (pe.primer_apellido || ' ' || pe.primer_nombre) AS estudiante, 
                   c.nombre_carrera,
                   (pt.primer_nombre || ' ' || pt.primer_apellido) AS tutor, 
                   (pp.primer_nombre || ' ' || pp.primer_apellido) AS presidente, 
                   (ps.primer_nombre || ' ' || ps.primer_apellido) AS secretario, 
                   au.nombre_aula
            FROM public.pre_defensas pd
            JOIN public.estudiantes e ON pd.id_estudiante = e.id_persona
            JOIN public.personas pe ON e.id_persona = pe.id_persona
            JOIN public.carreras c ON e.id_carrera = c.id_carrera
            LEFT JOIN public.personas pt ON pd.id_tutor = pt.id_persona
            LEFT JOIN public.personas pp ON pd.id_presidente = pp.id_persona
            LEFT JOIN public.personas ps ON pd.id_secretario = ps.id_persona
            LEFT JOIN public.aulas au ON pd.id_aula = au.id_aula
            WHERE 1=1";

        if ($tipo_reporte === 'evaluadas') {
            $sql .= " AND pd.estado IN ('APROBADA', 'REPROBADA')";
            $titulo_documento = "REGISTRO ACADÉMICO DE NOTAS DE PREDEFENSAS";
        } else {
            $sql .= " AND pd.estado = 'PENDIENTE'";
            $titulo_documento = "CRONOGRAMA OFICIAL DE PREDEFENSAS PROGRAMADAS";
        }

        $params = [];
        if (!empty($f_carrera)) { $sql .= " AND c.id_carrera = ?"; $params[] = $f_carrera; }
        if (!empty($f_fecha)) { $sql .= " AND pd.fecha = ?"; $params[] = $f_fecha; }
        if (!empty($f_est)) { 
            $sql .= " AND (pe.primer_nombre ILIKE ? OR pe.primer_apellido ILIKE ?)"; 
            $params[] = "%$f_est%"; $params[] = "%$f_est%";
        }
        $sql .= " ORDER BY pd.fecha ASC, pd.hora ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Sistema UNIOR');
        $pdf->SetAuthor($nombre_usuario);
        $pdf->SetTitle('Reporte - UNIOR');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 15, 10);

        // --- SOLUCIÓN DEL LOGO DESDE INTERNET ---
        // Se lee la imagen de la URL que pasaste y se codifica a Base64
        $logo_url = 'https://th.bing.com/th/id/OIP.gn1XsEkwAMjuwNkKWCqWPAAAAA?w=119&h=180&c=7&r=0&o=7&dpr=1.3&pid=1.7&rm=3';
        $img_data = @file_get_contents($logo_url);
        
        $logo_html = '';
        if ($img_data !== false) {
            $img_base64 = base64_encode($img_data);
            $logo_html = '<img src="@'.$img_base64.'" width="60">'; // Ajustado a 60px para que se vea elegante
        } else {
            $logo_html = '<b style="color:red; font-size:10px;">Sin Internet<br>para el Logo</b>';
        }

        // --- DIVIDIR EN BLOQUES DE 6 (Para que no se amontonen) ---
        $chunks = array_chunk($datos, 6);
        if (count($chunks) == 0) $chunks = [[]]; 

        foreach ($chunks as $pagina_actual => $bloque) {
            $pdf->AddPage();

            // ENCABEZADO HERMOSO Y PROFESIONAL EN CADA HOJA
            $html = '
            <table width="100%">
                <tr>
                    <td width="15%" align="center">'.$logo_html.'</td>
                    <td width="85%" align="center">
                        <h1 style="color:#1e293b; font-size:16pt; margin:0; font-family:helvetica;">UNIVERSIDAD PRIVADA DE ORURO - UNIOR</h1>
                        <h2 style="color:#0f172a; font-size:12pt; margin:0; font-family:helvetica;">ÁREA ACADÉMICA Y DE TITULACIÓN</h2>
                        <br>
                        <h3 style="color:#b91c1c; font-size:13pt; margin:0; font-family:helvetica; text-decoration:underline;">'.$titulo_documento.'</h3>
                    </td>
                </tr>
            </table>
            <hr style="color:#cbd5e1;">';
            
            if($f_fecha) $html .= '<p style="font-size:10px; color:#475569; text-align:right;"><b>Fecha del Filtro:</b> '.date('d/m/Y', strtotime($f_fecha)).'</p>';
            else $html .= '<br>';

            // DISEÑO DE TABLA SEGÚN EL REPORTE
            if ($tipo_reporte === 'evaluadas') {
                $html .= '<table border="1" cellpadding="6" style="border-collapse:collapse; width:100%; font-size:10px; font-family:helvetica;">
                    <tr style="background-color:#e2e8f0; font-weight:bold; text-align:center;">
                        <th width="35%">ESTUDIANTE / CARRERA</th>
                        <th width="35%">MODALIDAD / TEMA</th>
                        <th width="10%">FECHA</th>
                        <th width="10%">NOTA FINAL</th>
                        <th width="10%">ESTADO</th>
                    </tr>';

                if (count($bloque) > 0) {
                    foreach($bloque as $r) {
                        $color_nota = $r['nota'] >= 41 ? '#16a34a' : '#dc2626'; 
                        $color_bg = $r['estado'] == 'APROBADA' ? '#f0fdf4' : '#fef2f2';

                        $html .= '<tr style="background-color:'.$color_bg.';">
                            <td><b>'.$r['estudiante'].'</b><br><span style="color:#64748b;">'.$r['nombre_carrera'].'</span></td>
                            <td><b>'.$r['modalidad_titulacion'].'</b><br><i>'.htmlspecialchars($r['tema'] ?? 'Sin tema asignado').'</i></td>
                            <td align="center">'.date('d/m/Y', strtotime($r['fecha'])).'</td>
                            <td align="center"><b style="font-size:14px; color:'.$color_nota.';">'.$r['nota'].'</b></td>
                            <td align="center"><b style="color:'.$color_nota.';">'.$r['estado'].'</b></td>
                        </tr>';
                    }
                } else {
                    $html .= '<tr><td colspan="5" align="center">No hay registros con estos filtros.</td></tr>';
                }
            } else {
                $html .= '<table border="1" cellpadding="6" style="border-collapse:collapse; width:100%; font-size:10px; font-family:helvetica;">
                    <tr style="background-color:#f1f5f9; font-weight:bold; text-align:center;">
                        <th width="25%">ESTUDIANTE / CARRERA</th>
                        <th width="30%">MODALIDAD / TEMA / TUTOR</th>
                        <th width="25%">TRIBUNAL CALIFICADOR</th>
                        <th width="20%">FECHA, HORA Y AULA</th>
                    </tr>';

                if (count($bloque) > 0) {
                    foreach($bloque as $r) {
                        $html .= '<tr>
                            <td><b>'.$r['estudiante'].'</b><br><span style="color:#555;">'.$r['nombre_carrera'].'</span></td>
                            <td><b style="color:#0f172a;">'.$r['modalidad_titulacion'].'</b><br><i>Tema: '.htmlspecialchars($r['tema'] ?? 'Sin tema asignado').'</i><br><span style="color:#4f46e5;">Tutor: '.($r['tutor'] ?? 'S/N').'</span></td>
                            <td><b>Presi:</b> '.$r['presidente'].'<br><b>Secre:</b> '.$r['secretario'].'</td>
                            <td align="center"><b style="font-size:12px;">'.date('d/m/Y', strtotime($r['fecha'])).'</b><br>Hora: '.$r['hora'].'<br><b style="color:#e11d48; font-size:11px;">Aula: '.$r['nombre_aula'].'</b></td>
                        </tr>';
                    }
                } else {
                    $html .= '<tr><td colspan="4" align="center">No se encontraron predefensas.</td></tr>';
                }
            }
            
            $html .= '</table>';
            
            // Pie de página con numeración
            $html .= '<br><table width="100%"><tr>
                <td width="33%" style="font-size:8px; color:#94a3b8;">Sistema Académico UNIOR</td>
                <td width="34%" align="center" style="font-size:8px; color:#94a3b8;">Página '.($pagina_actual+1).' de '.count($chunks).'</td>
                <td width="33%" align="right" style="font-size:8px; color:#94a3b8;">Generado por: '.$nombre_usuario.'</td>
            </tr></table>';

            $pdf->writeHTML($html, true, false, true, false, '');
        }

        $pdf->Output('Reporte_Predefensas_UNIOR.pdf', 'I');
        exit;
    }

    // ---------------------------------------------------------
    // OTRAS PETICIONES AJAX
    // ---------------------------------------------------------
    header('Content-Type: application/json');

    try {
        if ($_POST['action'] == 'get_datos_estudiante') {
            $id_est = (int)$_POST['id_estudiante'];
            $stmtEst = $pdo->prepare("SELECT e.id_carrera, c.nombre_carrera FROM public.estudiantes e JOIN public.carreras c ON e.id_carrera = c.id_carrera WHERE e.id_persona = ?");
            $stmtEst->execute([$id_est]);
            $estudiante = $stmtEst->fetch(PDO::FETCH_ASSOC);

            $stmtTutor = $pdo->prepare("SELECT a.id_docente, (p.primer_nombre || ' ' || p.primer_apellido) as tutor_nombre, a.id_proyecto, pro.titulo_proyecto FROM public.asignaciones_tutor a JOIN public.personas p ON a.id_docente = p.id_persona LEFT JOIN public.proyectos pro ON a.id_proyecto = pro.id_proyecto WHERE a.id_estudiante = ? AND UPPER(a.estado) = 'ACTIVO' ORDER BY a.id_asignacion DESC LIMIT 1");
            $stmtTutor->execute([$id_est]);
            echo json_encode(['exito' => true, 'carrera' => $estudiante, 'tutor' => $stmtTutor->fetch(PDO::FETCH_ASSOC) ?: null]);
            exit;
        }

        if ($_POST['action'] == 'get_tribunal') {
            $id_tutor = isset($_POST['id_tutor']) ? (int)$_POST['id_tutor'] : 0;
            $stmt = $pdo->prepare("SELECT d.id_persona, (p.primer_apellido || ' ' || p.primer_nombre) as nombre FROM public.docentes d JOIN public.personas p ON d.id_persona = p.id_persona WHERE UPPER(p.estado) = 'ACTIVO' AND d.id_persona != ? ORDER BY p.primer_apellido ASC");
            $stmt->execute([$id_tutor]);
            echo json_encode(['exito' => true, 'docentes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // --- LÓGICA DE REPROBADOS E INTENTOS AL GUARDAR ---
        if ($_POST['action'] == 'guardar_predefensa') {
            $pdo->beginTransaction();
            $id_est = $_POST['id_estudiante']; $id_tutor = !empty($_POST['id_tutor']) ? $_POST['id_tutor'] : null; $id_proyecto = !empty($_POST['id_proyecto']) ? $_POST['id_proyecto'] : null;
            $id_presi = $_POST['id_presidente']; $id_secre = $_POST['id_secretario']; $mod = $_POST['modalidad_titulacion']; $gestion = date('Y');

            if (empty($id_est) || empty($id_presi) || empty($id_secre)) throw new Exception("Faltan datos obligatorios.");
            if ($id_presi == $id_secre) throw new Exception("Presidente y Secretario deben ser distintos.");

            $checkAula = $pdo->prepare("SELECT id_pre_defensa FROM public.pre_defensas WHERE fecha = ? AND hora = ? AND id_aula = ? AND estado = 'PENDIENTE'");
            $checkAula->execute([$_POST['fecha'], $_POST['hora'], $_POST['id_aula']]);
            if ($checkAula->rowCount() > 0) throw new Exception("El Aula seleccionada ya está ocupada a esa fecha y hora.");

            // Si es un nuevo intento, modificamos el campo "gestion" internamente y sumamos 1 al "intento"
            $stmtIntentos = $pdo->prepare("SELECT COUNT(*) FROM public.pre_defensas WHERE id_estudiante = ? AND gestion LIKE ?");
            $stmtIntentos->execute([$id_est, $gestion.'%']);
            $nro_intentos = $stmtIntentos->fetchColumn() + 1;
            $gestion_final = ($nro_intentos > 1) ? $gestion . '-' . $nro_intentos : $gestion;

            if (!$id_proyecto) {
                $id_mod = $pdo->query("SELECT id_modalidad FROM public.modalidades LIMIT 1")->fetchColumn() ?: 1;
                $stmtP = $pdo->prepare("INSERT INTO public.proyectos (id_estudiante, id_modalidad, titulo_proyecto, id_tutor) VALUES (?, ?, ?, ?) RETURNING id_proyecto");
                $stmtP->execute([$id_est, $id_mod, ($mod === 'EXAMEN_GRADO' ? 'Examen de Grado' : 'Proyecto Base'), $id_tutor]);
                $id_proyecto = $stmtP->fetchColumn();
            }

            $sql = "INSERT INTO public.pre_defensas (id_estudiante, gestion, fecha, hora, id_tutor, id_proyecto, id_aula, modalidad_titulacion, tema, id_presidente, id_secretario, intento) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$id_est, $gestion_final, $_POST['fecha'], $_POST['hora'], $id_tutor, $id_proyecto, $_POST['id_aula'], $mod, $mod === 'EXAMEN_GRADO' ? null : $_POST['tema'], $id_presi, $id_secre, $nro_intentos]);

            $pdo->commit(); echo json_encode(['exito' => true, 'mensaje' => 'Registrado correctamente (Intento #'.$nro_intentos.').']); exit;
        }

        if ($_POST['action'] == 'editar_predefensa') {
            $id_pre = $_POST['id_predefensa']; $id_presi = $_POST['edit_presidente']; $id_secre = $_POST['edit_secretario']; $tema = $_POST['edit_tema'];
            if ($id_presi == $id_secre) throw new Exception("Presidente y Secretario deben ser distintos.");

            $checkAula = $pdo->prepare("SELECT id_pre_defensa FROM public.pre_defensas WHERE fecha = ? AND hora = ? AND id_aula = ? AND id_pre_defensa != ? AND estado = 'PENDIENTE'");
            $checkAula->execute([$_POST['edit_fecha'], $_POST['edit_hora'], $_POST['edit_aula'], $id_pre]);
            if ($checkAula->rowCount() > 0) throw new Exception("Choque de horarios: El aula ya está ocupada.");

            $pdo->prepare("UPDATE public.pre_defensas SET fecha=?, hora=?, id_aula=?, id_presidente=?, id_secretario=?, tema=? WHERE id_pre_defensa=?")->execute([$_POST['edit_fecha'], $_POST['edit_hora'], $_POST['edit_aula'], $id_presi, $id_secre, $tema, $id_pre]);
            echo json_encode(['exito' => true, 'mensaje' => 'Actualizado correctamente.']); exit;
        }

        if ($_POST['action'] == 'calificar_predefensa') {
            $pdo->beginTransaction();
            $id_pre = $_POST['id_predefensa']; $nota = (float)$_POST['nota']; $obs = $_POST['observaciones']; $estado = $nota >= 41 ? 'APROBADA' : 'REPROBADA';
            $pdo->prepare("INSERT INTO public.calificaciones_predefensa (id_pre_defensa, nota, observaciones) VALUES (?, ?, ?) ON CONFLICT (id_pre_defensa) DO UPDATE SET nota = EXCLUDED.nota, observaciones = EXCLUDED.observaciones")->execute([$id_pre, $nota, $obs]);
            $pdo->prepare("UPDATE public.pre_defensas SET estado = ?, nota = ?, fecha_calificacion = CURRENT_TIMESTAMP WHERE id_pre_defensa = ?")->execute([$estado, $nota, $id_pre]);
            $pdo->commit(); echo json_encode(['exito' => true, 'mensaje' => "Estudiante calificado ($estado)"]); exit;
        }

        if ($_POST['action'] == 'eliminar_predefensa') {
            $pdo->prepare("DELETE FROM public.pre_defensas WHERE id_pre_defensa = ?")->execute([$_POST['id_predefensa']]);
            echo json_encode(['exito' => true, 'mensaje' => 'Registro eliminado.']); exit;
        }

    } catch(Exception $e) { 
        if($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['exito' => false, 'error' => $e->getMessage()]); exit;
    }
}

// ===================== CARGA DE DATOS PARA INTERFAZ =====================
try {
    $aulas = $pdo->query("SELECT id_aula, nombre_aula FROM public.aulas ORDER BY nombre_aula")->fetchAll(PDO::FETCH_ASSOC);
    $carreras = $pdo->query("SELECT id_carrera, nombre_carrera FROM public.carreras")->fetchAll(PDO::FETCH_ASSOC);
    $todos_docentes = $pdo->query("SELECT d.id_persona, (p.primer_apellido || ' ' || p.primer_nombre) as nombre FROM public.docentes d JOIN public.personas p ON d.id_persona = p.id_persona WHERE UPPER(p.estado) = 'ACTIVO' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

    // Los estudiantes reprobados vuelven a aparecer aquí automáticamente
    $est_data = $pdo->query("SELECT e.id_persona, (p.primer_apellido || ' ' || p.primer_nombre) AS nombre_completo, c.nombre_carrera FROM public.estudiantes e JOIN public.personas p ON e.id_persona = p.id_persona JOIN public.carreras c ON e.id_carrera = c.id_carrera WHERE UPPER(p.estado) = 'ACTIVO' AND e.id_persona NOT IN (SELECT id_estudiante FROM public.pre_defensas WHERE estado IN ('PENDIENTE', 'APROBADA')) ORDER BY p.primer_apellido")->fetchAll(PDO::FETCH_ASSOC);

    $lista_predefensas = $pdo->query("
        SELECT pd.*, (pe.primer_apellido || ' ' || pe.primer_nombre) AS estudiante, pe.celular as est_cel, c.id_carrera, c.nombre_carrera,
               (pt.primer_nombre || ' ' || pt.primer_apellido) AS tutor, pt.celular as tut_cel,
               (pp.primer_nombre || ' ' || pp.primer_apellido) AS presidente, pp.celular as pre_cel,
               (ps.primer_nombre || ' ' || ps.primer_apellido) AS secretario, ps.celular as sec_cel, au.nombre_aula
        FROM public.pre_defensas pd JOIN public.estudiantes e ON pd.id_estudiante = e.id_persona JOIN public.personas pe ON e.id_persona = pe.id_persona JOIN public.carreras c ON e.id_carrera = c.id_carrera LEFT JOIN public.personas pt ON pd.id_tutor = pt.id_persona LEFT JOIN public.personas pp ON pd.id_presidente = pp.id_persona LEFT JOIN public.personas ps ON pd.id_secretario = ps.id_persona LEFT JOIN public.aulas au ON pd.id_aula = au.id_aula ORDER BY pd.fecha ASC, pd.hora ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $alertas = array_filter($lista_predefensas, function($item) {
        $hoy = strtotime(date('Y-m-d')); $fecha_def = strtotime($item['fecha']);
        return ($item['estado'] === 'PENDIENTE' && $fecha_def >= $hoy && $fecha_def <= strtotime('+2 days', $hoy));
    });

} catch(PDOException $e) { die("Error de BD: ".$e->getMessage()); }

function linkWA($num) { return $num ? "<a href='https://wa.me/591{$num}' target='_blank' class='wa-icon' title='Chat WhatsApp'><i class='fab fa-whatsapp'></i></a>" : ""; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Predefensas Elite - UNIOR</title>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@300;500;800&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/es.js'></script>

    <style>
        :root{--accent:#4f46e5; --success:#10b981; --wa:#25D366; --bg-main:#f8fafc;}
        body{font-family:'Inter',sans-serif; background:var(--bg-main); display:flex; overflow-x:hidden;}
        
        .sidebar { width: 85px; background: white; height: 100vh; position: fixed; border-right: 1.5px solid #e2e8f0; transition: 0.4s; z-index: 1000; overflow-y:auto; }
        .sidebar:hover { width: 280px; box-shadow: 20px 0 60px rgba(0,0,0,0.05); }
        .nav-item-ae { display: flex; align-items: center; padding: 15px; color: #64748b; text-decoration: none; font-weight: 600; white-space: nowrap; transition:0.3s;}
        .nav-item-ae i { min-width: 40px; font-size: 1.25rem; }
        .nav-item-ae span { opacity: 0; transition: 0.3s; }
        .sidebar:hover .nav-item-ae span { opacity: 1; margin-left:10px; }
        .nav-item-ae:hover, .nav-item-ae.active { background: var(--accent); color: white; border-radius:12px; margin: 0 10px;}
        
        .main-stage { flex: 1; margin-left: 85px; padding: 30px; width: 100%; transition: 0.4s; }
        
        @media (max-width: 768px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; height: 70px; top: auto; bottom: 0; border-right: none; border-top: 1.5px solid #e2e8f0; display: flex; flex-direction: row; justify-content: space-around; overflow: visible; }
            .sidebar:hover { width: 100%; box-shadow: none; }
            .sidebar .logo-container { display: none !important; }
            .sidebar nav { flex-direction: row !important; width: 100%; justify-content: space-around; }
            .nav-item-ae { flex-direction: column; padding: 10px; margin:0 !important; font-size: 0.65rem; }
            .nav-item-ae i { min-width: auto; font-size: 1.2rem; margin-bottom:3px; }
            .nav-item-ae span { opacity: 1; margin:0; display: block; }
            .main-stage { margin-left: 0; padding: 15px; padding-bottom: 90px; }
            .tab-btn { padding: 8px 12px; font-size: 0.85rem; }
            .gc { padding: 15px; }
            h1 { font-size: 2rem !important; }
        }

        .gc { background:white; border-radius:20px; border:1px solid #e2e8f0; margin-bottom:20px; padding:20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);}
        .form-control, .form-select { border-radius: 12px; padding: 10px 14px; background: #f8fafc;}
        .form-control:focus, .form-select:focus { border-color: var(--accent); background:white; box-shadow: 0 0 0 3px rgba(79,70,229,0.15);}
        
        .tab-btn { background: transparent; border:none; padding:12px 20px; border-radius:12px; font-weight:600; color:#64748b; margin-right:5px; transition:0.3s;}
        .tab-btn.active { background: var(--accent); color: white; box-shadow:0 4px 10px rgba(79,70,229,0.3);}
        .tab-pane { display:none; } .tab-pane.active { display:block; animation: fadeIn 0.4s;}
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .wa-icon { color: var(--wa); font-size: 1.1rem; margin-left: 5px; text-decoration: none; transition: 0.2s; }
        .wa-icon:hover { transform: scale(1.2); color: #1da851; }
        .btn-action { width: 32px; height: 32px; border-radius: 8px; border: none; display: inline-flex; align-items: center; justify-content: center; color: white; margin: 2px; transition: 0.2s;}
        .btn-action:hover { transform: translateY(-2px); }
        .btn-edit { background: #f59e0b; } .btn-del { background: #ef4444; } .btn-grade { background: #10b981; }

        .pulse-alert { animation: pulseBg 1.5s infinite; border: 2px solid #fde68a; border-radius:15px; padding:15px;}
        @keyframes pulseBg { 0% { box-shadow: 0 0 0 0 rgba(245,158,11,0.4); } 70% { box-shadow: 0 0 0 15px rgba(245,158,11,0); } 100% { box-shadow: 0 0 0 0 rgba(245,158,11,0); } }
        #calendar { max-width: 100%; background: white; padding: 15px; border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .fc-event { cursor: pointer; border-radius: 6px; padding: 2px 5px; }
    </style>
</head>
<body>

<audio id="alarmSound" src="https://actions.google.com/sounds/v1/alarms/digital_watch_alarm_long.ogg" preload="auto"></audio>

<aside class="sidebar d-flex flex-column">
    <div class="logo-container" style="padding:20px 10px; display:flex; align-items:center; gap:15px; margin-bottom:20px;">
        <img src="https://th.bing.com/th/id/OIP.gn1XsEkwAMjuwNkKWCqWPAAAAA?w=119&h=180&c=7&r=0&o=7&dpr=1.3&pid=1.7&rm=3" width="45" style="border-radius:6px;"> 
        <b style="font-family:'Bricolage Grotesque'; font-size:1.5rem; color:var(--accent); opacity:0" class="logo-text">UNIOR</b>
    </div>
    <nav class="flex-grow-1 d-flex flex-column">
        <a href="menu.php" class="nav-item-ae"><i class="fas fa-home-alt"></i> <span>Menú</span></a>
        <a href="lista_estudiantes.php" class="nav-item-ae"><i class="fas fa-users-rays"></i> <span>Estudiantes</span></a>
        <a href="lista_tutores.php" class="nav-item-ae"><i class="fas fa-fingerprint"></i> <span>Tutores</span></a>
        <a href="predefensas.php" class="nav-item-ae active"><i class="fas fa-signature"></i> <span>Predefensas</span></a>
        <a href="#" class="nav-item-ae active"><i class="fas fa-user-check"></i> <span>Habilitación Final</span></a>
    </nav>
</aside>

<main class="main-stage">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <h1 style="font-family:'Bricolage Grotesque'; font-weight:800; font-size:2.5rem; margin-bottom:0;">Panel Predefensas</h1>
        <?php if(!empty($alertas)): ?>
            <button id="btnActivarAlarma" class="btn btn-warning fw-bold pulse-alert w-100 w-md-auto" onclick="activarAlarma()"><i class="fas fa-bell"></i> ¡Atención! Activar Alarma</button>
        <?php endif; ?>
    </div>

    <div class="bg-white p-2 rounded-4 mb-4 d-flex shadow-sm border overflow-auto" style="white-space:nowrap; gap:5px;">
        <button class="tab-btn active" onclick="switchTab('pendientes')"><i class="fas fa-clock"></i> Pendientes</button>
        <button class="tab-btn" onclick="switchTab('evaluadas')"><i class="fas fa-check-double"></i> Evaluadas</button>
        <button class="tab-btn" onclick="switchTab('registro')"><i class="fas fa-plus-circle"></i> Nueva</button>
        <button class="tab-btn" onclick="switchTab('calendario')"><i class="fas fa-calendar-alt"></i> Calendario</button>
    </div>

    <div id="tab-pendientes" class="tab-pane active">
        <div class="gc mb-3 p-3 bg-light d-flex flex-column flex-md-row gap-2 align-items-md-center justify-content-between">
            <div class="d-flex flex-column flex-md-row gap-2 flex-grow-1">
                <input type="text" id="f_est1" class="form-control" placeholder="🔍 Buscar Estudiante..." onkeyup="filtrar('pendientes')">
                <select id="f_car1" class="form-select" onchange="filtrar('pendientes')">
                    <option value="">Todas las Carreras</option>
                    <?php foreach($carreras as $c): ?><option value="<?=$c['id_carrera']?>"><?=$c['nombre_carrera']?></option><?php endforeach; ?>
                </select>
                <input type="date" id="f_fec1" class="form-control" onchange="filtrar('pendientes')">
            </div>
            <button class="btn btn-primary fw-bold rounded-pill px-4 shadow-sm py-2" onclick="generarReporte('pendientes')"><i class="fas fa-file-pdf"></i> Imprimir Cronograma</button>
        </div>
        
        <div class="gc table-responsive p-0">
            <table class="table table-hover align-middle mb-0" id="tb_pendientes" style="min-width: 800px;">
                <thead class="table-light"><tr><th>Estudiante / Carrera</th><th>Modalidad / Tutor</th><th>Título/Tema</th><th>Tribunal</th><th>Programación</th><th class="text-center">Acciones</th></tr></thead>
                <tbody>
                    <?php foreach($lista_predefensas as $r): if($r['estado'] !== 'PENDIENTE') continue; ?>
                    <tr data-carrera="<?=$r['id_carrera']?>" data-fecha="<?=$r['fecha']?>">
                        <td><b><?=htmlspecialchars($r['estudiante'])?></b> <?=linkWA($r['est_cel'])?><br><span class="badge bg-secondary"><?=htmlspecialchars($r['nombre_carrera'])?></span></td>
                        <td><b class="text-primary"><?=htmlspecialchars($r['modalidad_titulacion'])?></b><br><small>Tutor: <?=htmlspecialchars($r['tutor']??'S/N')?> <?=linkWA($r['tut_cel'])?></small></td>
                        <td style="max-width:200px; white-space:normal;"><small class="fw-bold text-muted"><?=htmlspecialchars($r['tema'] ?? 'Sin tema')?></small></td>
                        <td style="font-size:0.85rem"><b>P:</b> <?=htmlspecialchars($r['presidente'])?> <?=linkWA($r['pre_cel'])?><br><b>S:</b> <?=htmlspecialchars($r['secretario'])?> <?=linkWA($r['sec_cel'])?></td>
                        <td><b><?=date('d/m/Y',strtotime($r['fecha']))?></b> - <?=$r['hora']?><br><small><i class="fas fa-door-open text-danger"></i> <?=htmlspecialchars($r['nombre_aula'])?></small></td>
                        <td class="text-center" style="white-space:nowrap;">
                            <button class="btn-action btn-grade" title="Calificar" onclick="modalCalificar(<?=$r['id_pre_defensa']?>)"><i class="fas fa-star"></i></button>
                            <button class="btn-action btn-edit" title="Editar" onclick='modalEditar(<?=json_encode($r)?>)'><i class="fas fa-pen"></i></button>
                            <button class="btn-action btn-del" title="Eliminar" onclick="eliminar(<?=$r['id_pre_defensa']?>)"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="tab-evaluadas" class="tab-pane">
        <div class="gc mb-3 p-3 bg-light d-flex justify-content-end">
             <button class="btn btn-success fw-bold rounded-pill px-4 shadow-sm py-2" onclick="generarReporte('evaluadas')"><i class="fas fa-file-pdf"></i> Imprimir Actas y Notas</button>
        </div>
        <div class="gc table-responsive p-0">
            <table class="table table-hover align-middle mb-0" id="tb_evaluadas" style="min-width: 700px;">
                <thead class="table-light"><tr><th>Estudiante</th><th>Modalidad</th><th>Tema</th><th>Fecha Defensa</th><th>Nota</th><th>Estado</th><th class="text-center">Acciones</th></tr></thead>
                <tbody>
                    <?php foreach($lista_predefensas as $r): if($r['estado'] === 'PENDIENTE') continue; 
                        $b = $r['estado']=='APROBADA'?'success':'danger'; 
                    ?>
                    <tr>
                        <td><b><?=htmlspecialchars($r['estudiante'])?></b> <?=linkWA($r['est_cel'])?><br><small class="text-muted"><?=htmlspecialchars($r['nombre_carrera'])?></small></td>
                        <td><?=htmlspecialchars($r['modalidad_titulacion'])?></td>
                        <td style="max-width:200px; white-space:normal;"><small><?=htmlspecialchars($r['tema'] ?? 'N/A')?></small></td>
                        <td><?=date('d/m/Y',strtotime($r['fecha']))?></td>
                        <td><b style="font-size:1.2rem; color:var(--<?=$b?>)"><?=$r['nota']?></b></td>
                        <td><span class="badge bg-<?=$b?>"><?=$r['estado']?></span></td>
                        <td class="text-center">
                            <button class="btn-action btn-del" title="Eliminar" onclick="eliminar(<?=$r['id_pre_defensa']?>)"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="tab-registro" class="tab-pane">
        <form id="formNueva">
            <input type="hidden" name="action" value="guardar_predefensa">
            <input type="hidden" name="id_tutor" id="h_tutor">
            <input type="hidden" name="id_proyecto" id="h_proyecto">
            
            <div class="gc row g-3">
                <h5 class="fw-bold col-12 mb-0 text-primary">1. Postulante</h5>
                <div class="col-md-6">
                    <label class="small text-muted mb-1">Estudiante</label>
                    <select name="id_estudiante" id="sel_estudiante" class="form-select" required>
                        <option value="">-- Buscar --</option>
                        <?php foreach($est_data as $e): ?><option value="<?=$e['id_persona']?>"><?=htmlspecialchars($e['nombre_completo'])?> (<?=$e['nombre_carrera']?>)</option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="small text-muted mb-1">Modalidad</label>
                    <select name="modalidad_titulacion" id="sel_mod" class="form-select" required>
                        <option value="PROYECTO_GRADO">Proyecto de Grado</option>
                        <option value="TESIS">Tesis</option>
                        <option value="TRABAJO_DIRIGIDO">Trabajo Dirigido</option>
                        <option value="EXAMEN_GRADO">Examen de Grado</option>
                    </select>
                </div>
                <div class="col-12" id="box_tema_new">
                    <label class="small text-muted mb-1">Título de Investigación / Tema</label>
                    <input type="text" name="tema" id="txt_tema_new" class="form-control" placeholder="Escribe el título...">
                </div>
            </div>

            <div class="gc row g-3">
                <h5 class="fw-bold col-12 mb-0 text-warning">2. Tribunal (Misma Carrera)</h5>
                <div class="col-md-6">
                    <label class="small text-muted mb-1">Presidente</label>
                    <select name="id_presidente" id="sel_presi" class="form-select" required disabled><option value="">Esperando...</option></select>
                </div>
                <div class="col-md-6">
                    <label class="small text-muted mb-1">Secretario</label>
                    <select name="id_secretario" id="sel_secre" class="form-select" required disabled><option value="">Esperando...</option></select>
                </div>
            </div>

            <div class="gc row g-3">
                <h5 class="fw-bold col-12 mb-0 text-success">3. Programación</h5>
                <div class="col-md-4">
                    <label class="small text-muted mb-1">Aula</label>
                    <select name="id_aula" class="form-select" required>
                        <option value="">-- Aula --</option>
                        <?php foreach($aulas as $a): ?><option value="<?=$a['id_aula']?>"><?=$a['nombre_aula']?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4"><label class="small text-muted mb-1">Fecha</label><input type="date" name="fecha" class="form-control" required min="<?=date('Y-m-d')?>"></div>
                <div class="col-md-4"><label class="small text-muted mb-1">Hora</label><input type="time" name="hora" class="form-control" required></div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold mt-2" style="font-size:1.1rem"><i class="fas fa-save"></i> GUARDAR PREDEFENSA</button>
        </form>
    </div>

    <div id="tab-calendario" class="tab-pane">
        <div id="calendar"></div>
    </div>
</main>

<div class="modal fade" id="modalEdit" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 rounded-4 shadow-lg p-3">
            <h4 class="fw-bold mb-3"><i class="fas fa-pen text-warning"></i> Editar Predefensa</h4>
            <form id="formEdit">
                <input type="hidden" name="action" value="editar_predefensa">
                <input type="hidden" name="id_predefensa" id="ed_id">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="small text-muted mb-1">Estudiante</label>
                        <input type="text" id="ed_est_name" class="form-control" disabled>
                    </div>
                    <div class="col-md-12">
                        <label class="small text-muted mb-1">Título/Tema</label>
                        <input type="text" name="edit_tema" id="ed_tema" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="small text-muted mb-1">Nuevo Presidente</label>
                        <select name="edit_presidente" id="ed_presi" class="form-select" required>
                            <?php foreach($todos_docentes as $d): ?><option value="<?=$d['id_persona']?>"><?=$d['nombre']?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="small text-muted mb-1">Nuevo Secretario</label>
                        <select name="edit_secretario" id="ed_secre" class="form-select" required>
                            <?php foreach($todos_docentes as $d): ?><option value="<?=$d['id_persona']?>"><?=$d['nombre']?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="small text-muted mb-1">Aula</label>
                        <select name="edit_aula" id="ed_aula" class="form-select" required>
                            <?php foreach($aulas as $a): ?><option value="<?=$a['id_aula']?>"><?=$a['nombre_aula']?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="small text-muted mb-1">Fecha</label>
                        <input type="date" name="edit_fecha" id="ed_fecha" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="small text-muted mb-1">Hora</label>
                        <input type="time" name="edit_hora" id="ed_hora" class="form-control" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-warning w-100 mt-4 py-3 fw-bold text-dark rounded-pill shadow-sm">ACTUALIZAR DATOS</button>
            </form>
        </div>
    </div>
</div>

<script>
let calendar;

document.addEventListener('DOMContentLoaded', function() {
    let calEl = document.getElementById('calendar');
    calendar = new FullCalendar.Calendar(calEl, {
        initialView: window.innerWidth < 768 ? 'timeGridDay' : 'timeGridWeek',
        locale: 'es',
        headerToolbar: {
            left: 'prev,next',
            center: 'title',
            right: window.innerWidth < 768 ? 'dayGridMonth,timeGridDay' : 'dayGridMonth,timeGridWeek'
        },
        slotMinTime: "07:00:00",
        slotMaxTime: "22:00:00",
        events: [
            <?php foreach($lista_predefensas as $r): ?>
            {
                title: '<?=addslashes($r['estudiante'])?> (<?=$r['nombre_aula']?>)',
                start: '<?=$r['fecha']?>T<?=$r['hora']?>',
                color: '<?=$r['estado']=='PENDIENTE' ? '#f59e0b' : '#10b981'?>'
            },
            <?php endforeach; ?>
        ]
    });
});

function switchTab(t) {
    $('.tab-pane').removeClass('active'); $('.tab-btn').removeClass('active');
    $('#tab-'+t).addClass('active'); event.currentTarget.classList.add('active');
    if(t === 'calendario') setTimeout(() => { calendar.render(); }, 150);
}

function filtrar(tab) {
    let e = $('#f_est1').val().toLowerCase(), c = $('#f_car1').val(), f = $('#f_fec1').val();
    $(`#tb_${tab} tbody tr`).each(function(){
        let rowC = $(this).data('carrera'), rowF = $(this).data('fecha'), txt = $(this).text().toLowerCase();
        $(this).toggle( (c==='' || rowC==c) && (f==='' || rowF==f) && (e==='' || txt.includes(e)) );
    });
}

function generarReporte(tipo) {
    let f = $('#f_fec1').val(), c = $('#f_car1').val(), e = $('#f_est1').val();
    let form = $('<form>', { 'action': '', 'method': 'POST', 'target': '_blank' })
        .append($('<input>', { 'type': 'hidden', 'name': 'action', 'value': 'generar_pdf' }))
        .append($('<input>', { 'type': 'hidden', 'name': 'tipo_reporte', 'value': tipo }))
        .append($('<input>', { 'type': 'hidden', 'name': 'f_fecha', 'value': f }))
        .append($('<input>', { 'type': 'hidden', 'name': 'f_carrera', 'value': c }))
        .append($('<input>', { 'type': 'hidden', 'name': 'f_estudiante', 'value': e }));
    $(document.body).append(form); form.submit(); form.remove();
}

function activarAlarma() {
    document.getElementById('alarmSound').play();
    Swal.fire('¡Alarma Activada!', 'Tienes predefensas programadas en las próximas 48 horas.', 'warning');
    $('#btnActivarAlarma').fadeOut();
}

$('#sel_mod').change(function() {
    if($(this).val() === 'EXAMEN_GRADO') { $('#box_tema_new').slideUp(); $('#txt_tema_new').val(''); } 
    else { $('#box_tema_new').slideDown(); }
});

$('#sel_estudiante').change(function() {
    let id_est = $(this).val();
    if(!id_est) { $('#sel_presi, #sel_secre').prop('disabled', true); return; }
    Swal.fire({title:'Cargando...', didOpen:()=>{Swal.showLoading()}});
    $.post('', {action:'get_datos_estudiante', id_estudiante:id_est}, res=>{
        if(res.exito) {
            let tut = res.tutor ? res.tutor.id_docente : 0;
            $('#h_tutor').val(tut); $('#h_proyecto').val(res.tutor ? res.tutor.id_proyecto : '');
            if(res.tutor && res.tutor.titulo_proyecto) $('#txt_tema_new').val(res.tutor.titulo_proyecto);

            $.post('', {action:'get_tribunal', id_tutor:tut}, docs=>{
                Swal.close();
                let opts = '<option value="">-- Docente --</option>';
                docs.docentes.forEach(d => opts+=`<option value="${d.id_persona}">${d.nombre}</option>`);
                $('#sel_presi, #sel_secre').prop('disabled', false).html(opts);
            }, 'json');
        } else Swal.fire('Error', res.error, 'error');
    }, 'json');
});

$('#formNueva, #formEdit').submit(function(e){ 
    e.preventDefault(); 
    Swal.fire({title:'Procesando...', allowOutsideClick:false, didOpen:()=>{Swal.showLoading()}});
    $.post('', $(this).serialize(), r=>{
        if(r.exito) Swal.fire('Éxito', r.mensaje, 'success').then(()=>location.reload());
        else Swal.fire('Error', r.error, 'error');
    }, 'json');
});

function modalEditar(data) {
    $('#ed_id').val(data.id_pre_defensa); $('#ed_est_name').val(data.estudiante); $('#ed_tema').val(data.tema);
    $('#ed_presi').val(data.id_presidente); $('#ed_secre').val(data.id_secretario); $('#ed_aula').val(data.id_aula);
    $('#ed_fecha').val(data.fecha); $('#ed_hora').val(data.hora);
    new bootstrap.Modal('#modalEdit').show();
}

function modalCalificar(id) {
    Swal.fire({
        title: 'Calificar Defensa',
        html: `<input type="number" id="swal-nota" class="form-control mb-3" placeholder="Nota (0-100)" min="0" max="100">
               <textarea id="swal-obs" class="form-control" placeholder="Observaciones"></textarea>`,
        showCancelButton: true, confirmButtonText: 'Guardar', confirmButtonColor: '#10b981',
        preConfirm: () => {
            let n = $('#swal-nota').val(); if(!n || n<0 || n>100) return Swal.showValidationMessage('Nota inválida.');
            return {nota:n, obs:$('#swal-obs').val()};
        }
    }).then(r=>{
        if(r.isConfirmed) {
            $.post('', {action:'calificar_predefensa', id_predefensa:id, nota:r.value.nota, observaciones:r.value.obs}, res=>{
                if(res.exito) Swal.fire('¡Listo!', res.mensaje, 'success').then(()=>location.reload());
            }, 'json');
        }
    });
}

function eliminar(id) {
    Swal.fire({ title:'¿Estás seguro?', text:"Esta acción no se puede deshacer.", icon:'warning', showCancelButton:true, confirmButtonColor:'#ef4444' }).then(r=>{
        if(r.isConfirmed) $.post('', {action:'eliminar_predefensa', id_predefensa:id}, res=>{
            if(res.exito) location.reload();
        }, 'json');
    });
}
</script>
</body>
</html>