<?php
declare(strict_types=1); // ESTO DEBE IR PRIMERO SIEMPRE
ob_start(); // Inicia el buffer de salida para TCPDF
session_start();
require_once '../config/conexion.php';

if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit(); }

// =========================================================================
// VARIABLES DE IDENTIDAD VISUAL (ESTILO ELITE)
// =========================================================================
$favicon_url = "https://unior.edu.bo/favicon.svg";
$ruta_logo = "https://unior.edu.bo/favicon.svg";
$banner_url = "https://th.bing.com/th/id/OIP.fGbv34hHN0EA_eJ2Mm9NqwHaCv?w=331&h=129&c=7&r=0&o=7&dpr=1.3&pid=1.7&rm=3";
// Logo en formato compatible para la librería TCPDF (JPG/PNG)
$logo_pdf = "https://th.bing.com/th/id/OIP.gn1XsEkwAMjuwNkKWCqWPAAAAA?w=119&h=180&c=7&r=0&o=7&dpr=1.3&pid=1.7&rm=3";

// =========================================================================
// 0. GENERADOR DE REPORTES PDF (TCPDF)
// =========================================================================
if (isset($_GET['imprimir_reporte']) && isset($_GET['id'])) {
    require_once('../tcpdf/tcpdf.php'); 

    $id_asig = (int)$_GET['id'];

    $sql = "SELECT at.*, 
            pe.primer_nombre as e_nom, pe.primer_apellido as e_ape, pe.ci as e_ci, est.ru,
            pd.primer_nombre as t_nom, pd.primer_apellido as t_ape, pd.ci as t_ci,
            c.nombre_carrera 
            FROM public.asignaciones_tutor at
            JOIN public.personas pe ON at.id_estudiante = pe.id_persona
            JOIN public.estudiantes est ON pe.id_persona = est.id_persona
            JOIN public.personas pd ON at.id_docente = pd.id_persona
            JOIN public.carreras c ON est.id_carrera = c.id_carrera
            WHERE at.id_asignacion = ?";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_asig]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) { die("No se encontraron datos de la asignación."); }

    class MYPDF extends TCPDF {
        public function Header() {
            global $logo_pdf;
            // Usamos el logo JPG/PNG para no romper TCPDF
            $this->Image($logo_pdf, 15, 10, 15, '', 'JPG', '', 'T', false, 300, '', false, false, 0, false, false, false);
            $this->SetFont('helvetica', 'B', 14);
            $this->Cell(0, 15, 'CONSTANCIA DE ASIGNACION DE TUTOR', 0, 1, 'C');
            $this->SetFont('helvetica', '', 10);
            $this->Cell(0, 5, 'UNIVERSIDAD DE ORURO - UNIOR', 0, 1, 'C');
            $this->Ln(5);
        }
    }

    $pdf = new MYPDF('P', 'mm', 'Letter', true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('Sistema UNIOR');
    $pdf->SetTitle('Reporte de Asignacion');
    $pdf->SetMargins(15, 40, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);

    $fecha_emision = date('d/m/Y', strtotime($data['fecha_asignacion']));
    $nro = str_pad((string)$data['id_asignacion'], 6, '0', STR_PAD_LEFT);
    $estudiante = htmlspecialchars($data['e_ape'] . ' ' . $data['e_nom']);
    $tutor = htmlspecialchars($data['t_ape'] . ' ' . $data['t_nom']);
    $carrera = htmlspecialchars($data['nombre_carrera']);
    $estado = $data['estado'];
    $gestion = $data['gestion'];

    $html = <<<EOD
    <style>
        table { width: 100%; border-collapse: collapse; }
        td, th { border: 1px solid #000; padding: 8px; font-size: 11pt; }
        th { background-color: #f2f2f2; font-weight: bold; text-align: left; }
        .no-border { border: none !important; }
        .bold { font-weight: bold; }
    </style>

    <table style="border:none;">
    <tr>
        <td class="no-border" width="70%">
            <span class="bold">Institucion:</span> Universidad de Oruro (UNIOR)<br>
            <span class="bold">Departamento:</span> Jefatura de Carrera
        </td>
        <td class="no-border text-right" width="30%">
            <span class="bold">N° Asignacion:</span> $nro<br>
            <span class="bold">Fecha:</span> $fecha_emision
        </td>
    </tr>
    </table>
    <br><br>

    <table>
        <tr><th colspan="2" style="text-align:center;">DATOS DEL ESTUDIANTE</th></tr>
        <tr><td width="30%" class="bold">Nombre Completo:</td><td width="70%">$estudiante</td></tr>
        <tr><td class="bold">C.I. / R.U.:</td><td>{$data['e_ci']} / {$data['ru']}</td></tr>
        <tr><td class="bold">Carrera:</td><td>$carrera</td></tr>
    </table>
    <br><br>

    <table>
        <tr><th colspan="2" style="text-align:center;">DATOS DEL TUTOR ASIGNADO</th></tr>
        <tr><td width="30%" class="bold">Tutor(a):</td><td width="70%">$tutor</td></tr>
        <tr><td class="bold">C.I.:</td><td>{$data['t_ci']}</td></tr>
        <tr><td class="bold">Gestion:</td><td>$gestion</td></tr>
        <tr><td class="bold">Estado:</td><td>$estado</td></tr>
    </table>

    <br><br><br><br>

    <table style="border:none; text-align:center;">
    <tr>
        <td class="no-border" width="50%">
            _______________________________<br>
            <span class="bold">Firma del Estudiante</span><br>
            $estudiante
        </td>
        <td class="no-border" width="50%">
            _______________________________<br>
            <span class="bold">Firma del Tutor</span><br>
            $tutor
        </td>
    </tr>
    </table>
EOD;

    $pdf->writeHTML($html, true, false, true, false, '');
    ob_end_clean(); // Limpia el buffer
    $pdf->Output('Asignacion_'.$nro.'.pdf', 'I');
    exit;
}

// =========================================================================
// 1. MOTOR AJAX
// =========================================================================
if (isset($_POST['accion'])) {
    header('Content-Type: application/json');
    try {
        if ($_POST['accion'] === 'buscar_est_libres') {
            $id_car = (int)$_POST['id_carrera'];
            $sql = "SELECT p.id_persona, p.primer_nombre, p.primer_apellido, p.ci 
                    FROM public.personas p JOIN public.estudiantes e ON p.id_persona = e.id_persona 
                    WHERE (e.id_carrera = :id_car OR :id_car = 0) AND UPPER(p.estado) = 'ACTIVO'
                    AND p.id_persona NOT IN (SELECT id_estudiante FROM public.asignaciones_tutor WHERE UPPER(estado) = 'ACTIVO')
                    ORDER BY p.primer_apellido ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id_car' => $id_car]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }
        elseif ($_POST['accion'] === 'asignar_estudiante') {
            $check = $pdo->prepare("SELECT id_asignacion FROM public.asignaciones_tutor WHERE id_estudiante = ? AND UPPER(estado) = 'ACTIVO'");
            $check->execute([$_POST['id_est']]);
            if($check->rowCount() > 0) {
                echo json_encode(['exito' => false, 'error' => 'Este estudiante ya tiene un tutor activo asignado.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO public.asignaciones_tutor (id_estudiante, id_docente, gestion, estado) VALUES (?, ?, ?, 'ACTIVO')");
            $stmt->execute([$_POST['id_est'], $_POST['id_doc'], date("Y")]);
            echo json_encode(['exito' => true]);
            exit;
        }
        elseif ($_POST['accion'] === 'cambiar_estado') {
            $tabla = $_POST['tipo'] === 'tutor' ? 'personas' : 'asignaciones_tutor';
            $pk = $_POST['tipo'] === 'tutor' ? 'id_persona' : 'id_asignacion';
            $estado_nuevo = $_POST['nuevo_estado'] == 'ACTIVO' ? 'activo' : 'inactivo';
            if($_POST['tipo'] === 'asig') $estado_nuevo = strtoupper($_POST['nuevo_estado']);
            
            $stmt = $pdo->prepare("UPDATE public.$tabla SET estado = ? WHERE $pk = ?");
            $stmt->execute([$estado_nuevo, $_POST['id']]);
            echo json_encode(['exito' => true]);
            exit;
        }
        elseif ($_POST['accion'] === 'editar_tutor') {
            $pdo->beginTransaction();
            $stmt1 = $pdo->prepare("UPDATE public.personas SET primer_nombre=?, primer_apellido=?, celular=?, ci=? WHERE id_persona=?");
            $stmt1->execute([$_POST['nom'], $_POST['ape'], $_POST['celular'], $_POST['ci'], $_POST['id']]);
            $stmt2 = $pdo->prepare("UPDATE public.docentes SET especialidad=? WHERE id_persona=?");
            $stmt2->execute([$_POST['esp'], $_POST['id']]);
            $pdo->commit();
            echo json_encode(['exito' => true]);
            exit;
        }
        elseif ($_POST['accion'] === 'obtener_tutores_carrera') {
            $sql = "SELECT p.id_persona, p.primer_nombre, p.primer_apellido FROM public.personas p JOIN public.docentes d ON p.id_persona = d.id_persona WHERE (d.id_carrera = :id_car OR :id_car = 0) AND UPPER(p.estado) = 'ACTIVO' ORDER BY p.primer_apellido ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id_car' => (int)$_POST['id_carrera']]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }
        elseif ($_POST['accion'] === 're-asignar_tutor') {
            $stmt = $pdo->prepare("UPDATE public.asignaciones_tutor SET id_docente = ? WHERE id_asignacion = ?");
            $stmt->execute([$_POST['id_nuevo_tutor'], $_POST['id_asig']]);
            echo json_encode(['exito' => true]);
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['exito' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// =========================================================================
// 2. CARGA DE DATOS PARA LA INTERFAZ
// =========================================================================
$nombre_user = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'));
$rol_user = htmlspecialchars((string)($_SESSION["role"] ?? 'Administrador'));
$es_admin = (strtolower($rol_user) === 'administrador');
$inicial = strtoupper(mb_substr($nombre_user, 0, 1, 'UTF-8'));

try {
    $carreras_list = $pdo->query("SELECT * FROM public.carreras ORDER BY nombre_carrera")->fetchAll(PDO::FETCH_ASSOC);

    $tutores = $pdo->query("SELECT p.*, d.especialidad, d.id_carrera, c.nombre_carrera,
               (SELECT COUNT(*) FROM public.asignaciones_tutor at WHERE at.id_docente = d.id_persona AND UPPER(at.estado) = 'ACTIVO') as total_asig
               FROM public.personas p JOIN public.docentes d ON p.id_persona = d.id_persona
               LEFT JOIN public.carreras c ON d.id_carrera = c.id_carrera
               ORDER BY p.primer_apellido ASC")->fetchAll(PDO::FETCH_ASSOC);

    $asignaciones = $pdo->query("SELECT at.id_asignacion, at.estado as asig_est, at.gestion, 
                pe.primer_nombre as e_nom, pe.primer_apellido as e_ape,
                pd.id_persona as id_tut, pd.primer_nombre as tut_nom, pd.primer_apellido as tut_ape, 
                c.nombre_carrera, c.id_carrera
                FROM public.asignaciones_tutor at 
                JOIN public.personas pe ON at.id_estudiante = pe.id_persona
                JOIN public.personas pd ON at.id_docente = pd.id_persona
                JOIN public.estudiantes est ON pe.id_persona = est.id_persona
                JOIN public.carreras c ON est.id_carrera = c.id_carrera
                ORDER BY at.id_asignacion DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { die("Error de Base de Datos: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>UNIOR | Lista Tutores</title>
    <link rel="icon" type="image/svg+xml" href="<?= $favicon_url ?>">
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { --primary: #4f46e5; --accent: #6366f1; --bg: #f8fafc; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); display: flex; margin: 0; overflow-x: hidden; }
        
        .sidebar { width: 85px; background: rgba(255,255,255,0.95); backdrop-filter: blur(25px); height: 100vh; position: fixed; z-index: 2000; transition: 0.4s; padding: 20px 10px; border-right: 1.5px solid #e2e8f0; box-shadow: 5px 0 25px rgba(0,0,0,0.02); overflow: hidden; }
        .sidebar:hover { width: 280px; }
        .logo-aesthetic { display: flex; align-items: center; gap: 15px; margin-bottom: 40px; padding: 0 10px; white-space: nowrap; }
        .logo-aesthetic img { width: 45px; height: 45px; border-radius: 10px; object-fit: contain; }
        .logo-aesthetic span { font-family: 'Bricolage Grotesque'; font-size: 1.5rem; color: var(--primary); opacity: 0; transition: 0.3s; }
        .sidebar:hover .logo-aesthetic span { opacity: 1; }

        .nav-item-ae { display: flex; align-items: center; padding: 14px 15px; border-radius: 16px; color: #64748b; text-decoration: none; font-weight: 600; margin-bottom: 8px; transition: 0.3s; white-space: nowrap; }
        .nav-item-ae i { min-width: 40px; font-size: 1.25rem; }
        .nav-item-ae span { opacity: 0; transition: 0.3s; }
        .sidebar:hover .nav-item-ae span { opacity: 1; }
        .nav-item-ae:hover, .nav-item-ae.active { background: var(--primary); color: white; box-shadow: 0 8px 15px rgba(79, 70, 229, 0.2); transform: translateX(5px); }

        .main-stage { flex: 1; margin-left: 85px; padding: 40px; width: 100%; transition: 0.4s; }
        .section-title { font-family: 'Bricolage Grotesque'; font-size: 3.2rem; letter-spacing: -2px; line-height: 1; background: linear-gradient(135deg, #0f172a, var(--primary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        
        .cloud-card { background: rgba(255,255,255,0.8); backdrop-filter: blur(15px); border-radius: 35px; padding: 35px; box-shadow: 0 20px 50px rgba(0,0,0,0.04); border: 1.5px solid white; }
        .table-elite { width: 100%; border-collapse: separate; border-spacing: 0 10px; }
        .table-elite tr { background: white; transition: 0.3s; border-radius: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.01); }
        .table-elite tr:hover { transform: translateY(-3px); box-shadow: 0 15px 30px rgba(0,0,0,0.05); }
        .table-elite td { padding: 20px 15px; border: none; vertical-align: middle; }
        .table-elite td:first-child { border-radius: 20px 0 0 20px; padding-left: 25px; }
        .table-elite td:last-child { border-radius: 0 20px 20px 0; padding-right: 25px; }

        .btn-action { width: 42px; height: 42px; border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; border: none; transition: 0.3s; font-size: 1rem; text-decoration: none; }
        .btn-action:hover { transform: scale(1.1); color: inherit; }
        .btn-action.profile { background: #e0e7ff; color: var(--primary); }
        .btn-action.edit { background: #fef9c3; color: #a16207; }
        .btn-action.assign { background: #dcfce7; color: #15803d; }
        .btn-action.print { background: #dbeafe; color: #1e3a8a; }
        .btn-action.power-on { background: #fee2e2; color: #b91c1c; }
        .btn-action.power-off { background: #f1f5f9; color: #64748b; }

        .ci-link { cursor: pointer; color: var(--primary); text-decoration: underline; font-weight: 700; transition: 0.3s; }
        
        /* ESTILOS DE MODALES ELITE CON BANNER */
        .modal-elite .modal-content { border-radius: 40px; border: none; overflow: hidden; box-shadow: 0 30px 60px rgba(0,0,0,0.15); }
        .modal-banner-real { height: 160px; background-size: cover !important; background-position: center !important; position: relative; }
        .modal-avatar-box-center { position: absolute; bottom: -45px; left: 50%; transform: translateX(-50%); width: 90px; height: 90px; background: white; color: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; font-weight: 800; border: 6px solid white; box-shadow: 0 10px 20px rgba(0,0,0,0.1); z-index: 10; }
        .modal-icon-box { position: absolute; bottom: -35px; left: 50%; transform: translateX(-50%); width: 70px; height: 70px; background: white; border-radius: 20px; display: flex; align-items: center; justify-content: center; font-size: 2rem; border: 5px solid white; box-shadow: 0 10px 20px rgba(0,0,0,0.1); z-index: 10; }

        .form-control-elite { border-radius: 16px; padding: 14px 20px; border: 2px solid #e2e8f0; font-weight: 500; background: #f8fafc; transition: 0.3s; }
        .form-control-elite:focus { border-color: var(--primary); background: white; box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); outline: none; }
    </style>
</head>
<body>

    <aside class="sidebar d-flex flex-column">
        <div class="logo-aesthetic d-none d-lg-flex">
            <img src="<?= $ruta_logo ?>" alt="UNIOR Logo">
            <span class="logo-text">UNIOR</span>
        </div>
        <nav class="flex-grow-1">
            <a href="menu.php" class="nav-item-ae"><i class="fas fa-home-alt"></i> <span>Menú</span></a>
            <a href="lista_estudiantes.php" class="nav-item-ae"><i class="fas fa-users-rays"></i> <span>Estudiantes</span></a>
            <a href="registro_tutores.php" class="nav-item-ae"><i class="fas fa-user-tie"></i> <span>Registrar Tutor</span></a>
            <a href="lista_tutores.php" class="nav-item-ae active"><i class="fas fa-fingerprint"></i> <span>Lista Tutores</span></a>
            <a href="predefensas.php" class="nav-item-ae"><i class="fas fa-signature"></i> <span>Predefensas</span></a>
            <?php if($es_admin): ?>
            <a href="logs.php" class="nav-item-ae"><i class="fas fa-clipboard-list"></i> <span>Logs</span></a>
            <?php endif; ?>
        </nav>
        <a href="../controllers/logout.php" class="nav-item-ae text-danger mt-auto d-none d-lg-flex">
            <i class="fas fa-power-off"></i> <span>Salir</span>
        </a>
    </aside>

    <main class="main-stage">
        <div class="d-flex justify-content-between align-items-center mb-5">
            <div>
                <h1 class="section-title mb-2">Plantel de <span style="font-weight: 300">Tutores.</span></h1>
                <p class="text-muted fw-600 mb-0 d-flex align-items-center gap-2"><i class="fas fa-shield-alt text-primary"></i> Panel de Administración Académica</p>
            </div>
            <div class="bg-white px-3 py-2 rounded-pill shadow-sm d-flex align-items-center gap-3 border border-light">
                <div class="text-end lh-1">
                    <div class="fw-bold text-dark" style="font-size: 0.9rem;"><?= $nombre_user ?></div>
                    <small class="text-primary fw-bold" style="font-size: 0.7rem; text-transform: uppercase;"><?= $rol_user ?></small>
                </div>
                <div style="width:40px; height:40px; background:linear-gradient(135deg, var(--primary), var(--accent)); color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:1.1rem;"><?= $inicial ?></div>
            </div>
        </div>

        <div class="cloud-card">
            <ul class="nav nav-pills mb-4 gap-2 border-bottom pb-3">
                <li class="nav-item"><button class="nav-link active rounded-pill px-4 py-2 fw-bold" data-bs-toggle="pill" data-bs-target="#tab-doc"><i class="fas fa-users me-2"></i>Plantel Docente</button></li>
                <li class="nav-item"><button class="nav-link rounded-pill px-4 py-2 fw-bold" data-bs-toggle="pill" data-bs-target="#tab-asig"><i class="fas fa-link me-2"></i>Asignaciones</button></li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="tab-doc">
                    <div class="row g-3 mb-4">
                        <div class="col-md-5">
                            <div class="position-relative">
                                <i class="fas fa-search position-absolute text-muted" style="top:50%; left:20px; transform:translateY(-50%);"></i>
                                <input type="text" id="busDoc" class="form-control-elite w-100" style="padding-left: 50px;" placeholder="Buscar por Nombre, Apellido o CI..." onkeyup="filtrarTabla()">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <select id="filCarDoc" class="form-select form-control-elite w-100" onchange="filtrarTabla()">
                                <option value="">Todas las Carreras</option>
                                <?php foreach($carreras_list as $c): ?><option value="<?= htmlspecialchars($c['nombre_carrera']) ?>"><?= htmlspecialchars($c['nombre_carrera']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select id="filEstDoc" class="form-select form-control-elite w-100" onchange="filtrarTabla()">
                                <option value="">Todos los Estados</option>
                                <option value="activo" selected>Activos</option>
                                <option value="inactivo">Inactivos</option>
                            </select>
                        </div>
                    </div>

                    <div class="table-responsive" style="min-height: 400px;">
                        <table class="table-elite" id="tablaDoc">
                            <thead><tr class="text-muted small text-uppercase">
                                <th>Carrera</th>
                                <th>Nombre Completo</th>
                                <th>Teléfono</th>
                                <th>Carnet CI</th>
                                <th class="text-center">Carga</th>
                                <th class="text-end">Acciones</th>
                            </tr></thead>
                            <tbody>
                                <?php foreach($tutores as $t): 
                                    $est = strtolower($t['estado']);
                                ?>
                                <tr class="fila-busqueda" data-carrera="<?= htmlspecialchars($t['nombre_carrera'] ?? '') ?>" data-estado="<?= $est ?>">
                                    <td><span class="badge bg-light text-primary border px-3 py-2 rounded-pill"><?= htmlspecialchars($t['nombre_carrera'] ?? 'General') ?></span></td>
                                    <td>
                                        <div class="fw-bold text-dark" style="font-size: 1.05rem;"><?= htmlspecialchars($t['primer_apellido']) ?> <?= htmlspecialchars($t['primer_nombre']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($t['especialidad'] ?: 'Docente Regular') ?></small>
                                    </td>
                                    <td>
                                        <?php if($t['celular']): ?>
                                            <a href="tel:<?= $t['celular'] ?>" class="text-decoration-none fw-bold text-dark d-flex align-items-center gap-2"><div style="width:30px; height:30px; border-radius:10px; background:#dcfce7; color:#15803d; display:flex; align-items:center; justify-content:center;"><i class="fas fa-phone-alt"></i></div> <?= htmlspecialchars($t['celular']) ?></a>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic">S/N</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="ci-link" onclick='verPerfil(<?= json_encode($t) ?>)'><i class="fas fa-id-card me-1"></i> <?= htmlspecialchars($t['ci']) ?></span></td>
                                    <td class="text-center">
                                        <span class="badge rounded-pill px-3 py-2 <?= $t['total_asig'] > 0 ? 'bg-primary text-white' : 'bg-light text-muted border' ?>">
                                            <?= $t['total_asig'] ?> Est.
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn-action profile" onclick='verPerfil(<?= json_encode($t) ?>)' title="Ver Perfil"><i class="fas fa-eye"></i></button>
                                        <button class="btn-action edit" onclick='abrirEditar(<?= json_encode($t) ?>)' title="Editar Tutor"><i class="fas fa-pen"></i></button>
                                        <button class="btn-action assign" onclick="abrirAsignar(<?= (int)$t['id_persona'] ?>, <?= (int)($t['id_carrera'] ?? 0) ?>, '<?= htmlspecialchars($t['nombre_carrera'] ?? 'Todas las Carreras', ENT_QUOTES) ?>')" title="Vincular Estudiante"><i class="fas fa-user-plus"></i></button>
                                        <button class="btn-action <?= $est=='activo' ? 'power-on' : 'power-off' ?>" onclick="cambiarEstado('tutor', <?= $t['id_persona'] ?>, '<?= $est ?>')" title="Estado">
                                            <i class="fas <?= $est=='activo' ? 'fa-power-off' : 'fa-check' ?>"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-asig">
                    <div class="row g-3 mb-4">
                        <div class="col-md-5">
                            <input type="text" id="busAsig" class="form-control-elite w-100" placeholder="Buscar Estudiante o Tutor..." onkeyup="filtrarAsignaciones()">
                        </div>
                        <div class="col-md-4">
                            <select id="filCarAsig" class="form-select form-control-elite w-100" onchange="filtrarAsignaciones()">
                                <option value="">Todas las Carreras</option>
                                <?php foreach($carreras_list as $c): ?><option value="<?= htmlspecialchars($c['nombre_carrera']) ?>"><?= htmlspecialchars($c['nombre_carrera']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table-elite" id="tablaAsig">
                            <thead><tr class="text-muted small text-uppercase">
                                <th>Estudiante</th>
                                <th>Tutor Asignado</th>
                                <th>Carrera</th>
                                <th class="text-center">Estado</th>
                                <th class="text-end">Opciones</th>
                            </tr></thead>
                            <tbody>
                                <?php foreach($asignaciones as $as): ?>
                                <tr class="fila-asig" data-carrera="<?= htmlspecialchars($as['nombre_carrera']) ?>">
                                    <td>
                                        <div class="fw-bold text-dark"><i class="fas fa-user-graduate text-primary me-2"></i><?= htmlspecialchars($as['e_ape']) ?> <?= htmlspecialchars($as['e_nom']) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><i class="fas fa-chalkboard-teacher text-warning me-2"></i><?= htmlspecialchars($as['tut_ape']) ?> <?= htmlspecialchars($as['tut_nom']) ?></div>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($as['nombre_carrera']) ?></span></td>
                                    <td class="text-center"><span class="badge rounded-pill px-3 py-2 <?= $as['asig_est']=='ACTIVO'?'bg-success':'bg-danger' ?>"><?= $as['asig_est'] ?></span></td>
                                    <td class="text-end">
                                        <a href="lista_tutores.php?imprimir_reporte=1&id=<?= $as['id_asignacion'] ?>" target="_blank" class="btn-action print me-1" title="Imprimir Reporte"><i class="fas fa-print"></i></a>
                                        
                                        <button class="btn btn-outline-primary btn-sm rounded-pill fw-bold px-3 me-1" onclick="abrirReasignar(<?= $as['id_asignacion'] ?>, <?= $as['id_carrera'] ?>, '<?= htmlspecialchars($as['e_nom'] . ' ' . $as['e_ape'], ENT_QUOTES) ?>')" title="Cambiar Tutor"><i class="fas fa-exchange-alt"></i></button>
                                        
                                        <button class="btn <?= $as['asig_est']=='ACTIVO'?'btn-danger':'btn-success' ?> btn-sm rounded-pill fw-bold px-3 shadow-sm" onclick="cambiarEstado('asig', <?= $as['id_asignacion'] ?>, '<?= $as['asig_est'] ?>')">
                                            <?= $as['asig_est']=='ACTIVO'?'Baja':'Alta' ?>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade modal-elite" id="modalPerfil" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-banner-real" style="background: url('<?= $banner_url ?>');">
                    <div class="modal-avatar-box-center" id="p-ini">?</div>
                </div>
                <div class="p-4 text-center mt-4">
                    <h3 class="fw-bold text-dark mb-0" id="p-nom">Nombre</h3>
                    <p class="text-muted mb-4" id="p-car">Carrera</p>
                    
                    <div class="row g-3 text-start">
                        <div class="col-6"><div class="bg-light p-3 rounded-4 border"><small class="text-muted d-block fw-bold mb-1">Carnet CI</small><b id="p-ci" class="text-dark"></b></div></div>
                        <div class="col-6"><div class="bg-light p-3 rounded-4 border"><small class="text-muted d-block fw-bold mb-1">Teléfono</small><b id="p-cel" class="text-dark"></b></div></div>
                        <div class="col-12"><div class="bg-light p-3 rounded-4 border"><small class="text-muted d-block fw-bold mb-1">Especialidad Académica</small><b id="p-esp" class="text-dark"></b></div></div>
                    </div>
                    <button class="btn btn-primary w-100 rounded-pill py-3 fw-bold mt-4 shadow" data-bs-dismiss="modal">CERRAR</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade modal-elite" id="modalEditar" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-banner-real" style="background: url('<?= $banner_url ?>'); height: 120px;"></div>
                <div class="p-5 pt-4">
                    <h3 class="fw-800 mb-4">Editar Datos del Tutor</h3>
                    <form id="formEditar">
                        <input type="hidden" name="accion" value="editar_tutor">
                        <input type="hidden" name="id" id="e-id">
                        <div class="row g-4 mt-2">
                            <div class="col-md-6">
                                <label class="fw-bold small text-muted mb-2">Nombres</label>
                                <input type="text" name="nom" id="e-nom" class="form-control-elite w-100" required>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold small text-muted mb-2">Apellidos</label>
                                <input type="text" name="ape" id="e-ape" class="form-control-elite w-100" required>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold small text-muted mb-2">Carnet de Identidad</label>
                                <input type="text" name="ci" id="e-ci" class="form-control-elite w-100" required>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold small text-muted mb-2">Número de Celular</label>
                                <input type="text" name="celular" id="e-cel" class="form-control-elite w-100">
                            </div>
                            <div class="col-12">
                                <label class="fw-bold small text-muted mb-2">Especialidad</label>
                                <input type="text" name="esp" id="e-esp" class="form-control-elite w-100">
                            </div>
                        </div>
                        <div class="d-flex gap-3 mt-5">
                            <button type="button" class="btn btn-light w-50 rounded-pill py-3 fw-bold" data-bs-dismiss="modal">CANCELAR</button>
                            <button type="button" onclick="ejecutarEdicion()" class="btn btn-primary w-50 rounded-pill py-3 fw-bold shadow">GUARDAR CAMBIOS</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade modal-elite" id="modalAsignar" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-banner-real" style="background: url('<?= $banner_url ?>'); height: 120px;">
                    <div class="modal-icon-box" style="color:var(--primary); background:#e0e7ff;"><i class="fas fa-user-plus"></i></div>
                </div>
                <div class="p-5 pt-5 text-center">
                    <h3 class="fw-800 mt-2">Vincular Estudiante</h3>
                    <p class="text-muted fw-bold">Alumnos libres de: <b id="a-carrera" class="text-primary"></b>.</p>
                    <form id="formAsignar" class="mt-4">
                        <input type="hidden" name="accion" value="asignar_estudiante">
                        <input type="hidden" name="id_doc" id="a-id-doc">
                        <div class="mb-5 text-start">
                            <select name="id_est" id="a-sel-est" class="form-select form-control-elite w-100" required></select>
                        </div>
                        <button type="button" onclick="ejecutarAsignacion()" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow">CONFIRMAR VINCULACIÓN</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade modal-elite" id="modalReasignar" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-banner-real" style="background: url('<?= $banner_url ?>'); height: 120px;">
                    <div class="modal-icon-box" style="color:#a16207; background:#fef9c3;"><i class="fas fa-exchange-alt"></i></div>
                </div>
                <div class="p-5 pt-5 text-center">
                    <h3 class="fw-800 mt-2">Cambiar Tutor</h3>
                    <p class="text-muted fw-bold">Tutoría para: <b id="r-est-nom" class="text-dark"></b></p>
                    <form id="formReasignar" class="mt-4">
                        <input type="hidden" name="accion" value="re-asignar_tutor">
                        <input type="hidden" name="id_asig" id="r-id-asig">
                        <div class="mb-5 text-start">
                            <label class="fw-bold small text-muted mb-2">SELECCIONA EL NUEVO DOCENTE</label>
                            <select name="id_nuevo_tutor" id="r-sel-tut" class="form-select form-control-elite w-100" required></select>
                        </div>
                        <button type="button" onclick="ejecutarReasignacion()" class="btn btn-warning w-100 rounded-pill py-3 fw-bold shadow text-dark">ACTUALIZAR TUTOR</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        function filtrarTabla() {
            let bus = $('#busDoc').val().toLowerCase();
            let car = $('#filCarDoc').val();
            let est = $('#filEstDoc').val();
            
            $('#tablaDoc tbody .fila-busqueda').each(function() {
                let txt = $(this).text().toLowerCase();
                let vCar = $(this).data('carrera');
                let vEst = $(this).data('estado');
                let matchBus = txt.includes(bus);
                let matchCar = (car === "" || vCar === car);
                let matchEst = (est === "" || vEst === est);
                $(this).toggle(matchBus && matchCar && matchEst);
            });
        }

        function filtrarAsignaciones() {
            let bus = $('#busAsig').val().toLowerCase();
            let car = $('#filCarAsig').val();
            
            $('#tablaAsig tbody .fila-asig').each(function() {
                let txt = $(this).text().toLowerCase();
                let vCar = $(this).data('carrera');
                let matchBus = txt.includes(bus);
                let matchCar = (car === "" || vCar === car);
                $(this).toggle(matchBus && matchCar);
            });
        }

        function verPerfil(data) {
            $('#p-nom').text(`${data.primer_apellido} ${data.primer_nombre}`);
            $('#p-car').text(data.nombre_carrera || 'Docente General');
            $('#p-ci').text(data.ci);
            $('#p-cel').text(data.celular || 'No registrado');
            $('#p-esp').text(data.especialidad || 'General');
            $('#p-ini').text(data.primer_nombre.charAt(0).toUpperCase());
            new bootstrap.Modal('#modalPerfil').show();
        }

        function abrirEditar(data) {
            $('#e-id').val(data.id_persona);
            $('#e-nom').val(data.primer_nombre);
            $('#e-ape').val(data.primer_apellido);
            $('#e-ci').val(data.ci);
            $('#e-cel').val(data.celular);
            $('#e-esp').val(data.especialidad);
            new bootstrap.Modal('#modalEditar').show();
        }

        function ejecutarEdicion() {
            if(!$('#e-nom').val() || !$('#e-ci').val()) return Swal.fire('Atención','Completa los campos requeridos','warning');
            $.post('lista_tutores.php', $('#formEditar').serialize(), function(r) {
                if(r.exito) Swal.fire('Actualizado','Datos guardados correctamente.','success').then(()=>location.reload());
                else Swal.fire('Error', r.error, 'error');
            }, 'json');
        }

        function abrirAsignar(idDoc, idCar, nomCar) {
            $('#a-id-doc').val(idDoc);
            $('#a-carrera').text(nomCar);
            $('#a-sel-est').html('<option>Buscando alumnos...</option>');
            
            $.post('lista_tutores.php', { accion: 'buscar_est_libres', id_carrera: idCar }, function(data) {
                let html = '<option value="">-- Seleccionar Estudiante --</option>';
                if(!data || data.length === 0) {
                    html = '<option value="">No hay estudiantes libres.</option>';
                } else {
                    data.forEach(e => { html += `<option value="${e.id_persona}">${e.primer_apellido} ${e.primer_nombre} (CI: ${e.ci})</option>`; });
                }
                $('#a-sel-est').html(html);
            }, 'json');
            new bootstrap.Modal('#modalAsignar').show();
        }

        function ejecutarAsignacion() {
            if (!$('#a-sel-est').val()) return Swal.fire('Error', 'Selecciona un estudiante.', 'error');
            $.post('lista_tutores.php', $('#formAsignar').serialize(), function(r) {
                if(r.exito) Swal.fire('Asignado', 'El estudiante fue vinculado al tutor.', 'success').then(()=>location.reload());
                else Swal.fire('Error', r.error, 'error');
            }, 'json');
        }

        function abrirReasignar(idAsig, idCar, nomEst) {
            $('#r-id-asig').val(idAsig);
            $('#r-est-nom').text(nomEst);
            $('#r-sel-tut').html('<option>Cargando docentes...</option>');
            
            $.post('lista_tutores.php', { accion: 'obtener_tutores_carrera', id_carrera: idCar }, function(data) {
                let html = '<option value="">-- Seleccionar Nuevo Tutor --</option>';
                data.forEach(t => { html += `<option value="${t.id_persona}">${t.primer_apellido} ${t.primer_nombre}</option>`; });
                $('#r-sel-tut').html(html);
            }, 'json');
            new bootstrap.Modal('#modalReasignar').show();
        }

        function ejecutarReasignacion() {
            if (!$('#r-sel-tut').val()) return Swal.fire('Error', 'Selecciona un tutor.', 'error');
            $.post('lista_tutores.php', $('#formReasignar').serialize(), function(r) {
                if(r.exito) Swal.fire('Actualizado', 'Se cambió el tutor.', 'success').then(()=>location.reload());
            }, 'json');
        }

        function cambiarEstado(tipo, id, estadoActual) {
            let actNormalizado = estadoActual.toUpperCase();
            let nuevo = actNormalizado === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO';
            let txt = nuevo === 'INACTIVO' ? 'dar de baja' : 'reactivar';
            
            Swal.fire({
                title: `¿Confirmar acción?`,
                text: `Vas a ${txt} este registro.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: nuevo === 'INACTIVO' ? '#d33' : '#15803d',
                confirmButtonText: 'Sí, confirmar',
                cancelButtonText: 'Cancelar'
            }).then((r) => {
                if (r.isConfirmed) {
                    $.post('lista_tutores.php', { accion: 'cambiar_estado', tipo: tipo, id: id, nuevo_estado: nuevo }, function(res) {
                        if(res.exito) location.reload();
                    }, 'json');
                }
            });
        }
        
        $(document).ready(function() { filtrarTabla(); });
    </script>
</body>
</html>