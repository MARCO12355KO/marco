<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

require_once '../config/conexion.php';

// --- 1. LÓGICA DE CARRERAS (AJAX) ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        if ($_GET['action'] === 'listar') {
            $stmt = $pdo->query("SELECT id_carrera, nombre_carrera FROM public.carreras ORDER BY nombre_carrera ASC");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }
        if ($_GET['action'] === 'guardar') {
            $data = json_decode(file_get_contents('php://input'), true);
            $nombre = mb_convert_case(trim($data['nombre']), MB_CASE_TITLE, "UTF-8");
            $stmt = $pdo->prepare("INSERT INTO public.carreras (nombre_carrera) VALUES (?)");
            $stmt->execute([$nombre]);
            echo json_encode(['exito' => true]);
            exit;
        }
        if ($_GET['action'] === 'eliminar') {
            $id = $_GET['id'];
            $stmt = $pdo->prepare("DELETE FROM public.carreras WHERE id_carrera = ?");
            $stmt->execute([$id]);
            echo json_encode(['exito' => true]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['exito' => false, 'mensaje' => $e->getMessage()]);
        exit;
    }
}

// --- 2. LÓGICA DE GUARDADO DE TUTOR ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');
    try {
        $pdo->beginTransaction();
        $sqlP = "INSERT INTO public.personas (ci, primer_nombre, primer_apellido, segundo_apellido, celular, estado) 
                 VALUES (:ci, :nom, :ape1, :ape2, :cel, 'ACTIVO') RETURNING id_persona";
        $stmtP = $pdo->prepare($sqlP);
        $stmtP->execute([
            ':ci'   => trim($_POST['ci']),
            ':nom'  => trim($_POST['nombres']),
            ':ape1' => trim($_POST['apellido_p']),
            ':ape2' => trim($_POST['apellido_m']) ?: null,
            ':cel'  => trim($_POST['celular'])
        ]);
        $id_persona = $stmtP->fetchColumn();

        $sqlD = "INSERT INTO public.docentes (id_persona, id_carrera, especialidad, es_tutor, es_tribunal) 
                 VALUES (:id, :carrera, :esp, true, false)";
        $stmtD = $pdo->prepare($sqlD);
        $stmtD->execute([
            ':id'      => $id_persona,
            ':carrera' => $_POST['id_carrera'],
            ':esp'     => $_POST['especialidad'] ?: 'General'
        ]);
        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Tutor registrado correctamente']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
$es_admin = (isset($_SESSION["role"]) && strtoupper($_SESSION["role"]) === 'ADMINISTRADOR');
$nombre_usuario = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'));
$rol_usuario = $_SESSION["role"] ?? 'Invitado';
$inicial = strtoupper(mb_substr($nombre_usuario, 0, 1, 'UTF-8'));
$ruta_logo = "https://unior.edu.bo/favicon.svg";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>UNIOR | Registro Elite</title>
    <link rel="icon" type="image/svg+xml" href="<?= $ruta_logo ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        :root { --primary: #4f46e5; --sidebar-w: 280px; --sidebar-c: 85px; --bg: #f8fafc; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); display: flex; min-height: 100vh; margin: 0; }

        /* SIDEBAR ELITE (IGUAL AL MENU) */
        .sidebar {
            width: var(--sidebar-c); background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(25px);
            border-right: 1px solid rgba(0,0,0,0.05); height: 100vh; position: fixed; left: 0; top: 0;
            display: flex; flex-direction: column; padding: 25px 15px; transition: 0.4s; z-index: 2000;
        }
        @media (min-width: 992px) {
            .sidebar:hover { width: var(--sidebar-w); }
            .sidebar:hover .logo-text, .sidebar:hover .nav-item-ae span { opacity: 1; margin-left: 12px; display: inline-block; }
        }
        .logo-aesthetic { display: flex; align-items: center; gap: 15px; padding: 10px; margin-bottom: 40px; text-decoration: none; }
        .logo-text { font-weight: 800; font-size: 1.6rem; color: var(--primary); opacity: 0; transition: 0.3s; white-space: nowrap; }

        nav { display: flex; flex-direction: column; gap: 10px; flex: 1; }
        .nav-item-ae { display: flex; align-items: center; padding: 15px; border-radius: 20px; color: #64748b; text-decoration: none; font-weight: 600; transition: 0.3s; }
        .nav-item-ae i { font-size: 1.3rem; min-width: 45px; text-align: center; }
        .nav-item-ae span { opacity: 0; display: none; }
        .nav-item-ae:hover, .nav-item-ae.active { background: white; color: var(--primary); }
        .nav-item-ae.active { background: var(--primary) !important; color: white !important; }

        .main-wrapper { flex: 1; margin-left: var(--sidebar-c); padding: 40px; width: 100%; transition: 0.4s; }

        /* FORM STYLE */
        .glass-card { background: white; border-radius: 40px; padding: 50px; box-shadow: 0 20px 50px rgba(0,0,0,0.04); max-width: 950px; margin: 0 auto; }
        .form-control-elite { border-radius: 18px; padding: 15px 20px 15px 50px; border: 2px solid #e2e8f0; font-weight: 600; width: 100%; transition: 0.3s; background: #f8fafc; }
        .form-control-elite:focus { border-color: var(--primary); outline: none; background: white; }
        .input-container { position: relative; margin-bottom: 5px; }
        .input-container i { position: absolute; left: 20px; top: 50%; transform: translateY(-50%); color: #94a3b8; }

        .btn-submit { background: var(--primary); color: white; border: none; padding: 18px; border-radius: 20px; font-weight: 800; width: 100%; transition: 0.3s; box-shadow: 0 10px 30px rgba(79, 70, 229, 0.2); }

        @media (max-width: 991px) {
            .sidebar { width: 100%; height: 75px; top: auto; bottom: 0; flex-direction: row; border-radius: 25px 25px 0 0; padding: 0 10px; }
            .logo-aesthetic, .nav-item-ae span { display: none; }
            nav { flex-direction: row; justify-content: space-around; width: 100%; align-items: center; }
            .main-wrapper { margin-left: 0; padding: 20px 20px 100px; }
        }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="logo-aesthetic d-none d-lg-flex">
            <img src="<?= $ruta_logo ?>" alt="UNIOR Logo">
            <span class="logo-text">UNIOR</span>
        </div>
        <nav>
            <a href="menu.php" class="nav-item-ae active"><i class="fas fa-home-alt"></i> <span>Menú</span></a>
            <a href="lisra_estudiantes.php" class="nav-item-ae"><i class="fas fa-users-rays"></i> <span>Estudiantes</span></a>
            <a href="registro_tutores.php" class="nav-item-ae"><i class="fas fa-user-tie"></i> <span>Registrar Tutor</span></a>
            <a href="lista_tutores.php" class="nav-item-ae"><i class="fas fa-fingerprint"></i> <span>Lista Tutores</span></a>
            <a href="predefensas.php" class="nav-item-ae"><i class="fas fa-signature"></i> <span>Predefensas</span></a>
            <?php if($es_admin): ?>
            <a href="logs.php" class="nav-item-ae"><i class="fas fa-clipboard-list"></i> <span>Logs</span></a>
            <?php endif; ?>
        </nav>
        <a href="../controllers/logout.php" class="nav-item-ae text-danger mt-auto d-none d-lg-flex">
            <i class="fas fa-power-off"></i> <span>Salir</span>
        </a>
    </aside>

    <main class="main-wrapper">
        <div class="glass-card">
            <h1 class="fw-800 mb-4" style="color: #1e293b; letter-spacing: -1.5px;">Nuevo <span style="color: var(--primary);">Tutor Académico.</span></h1>
            
            <form id="formTutor" novalidate>
                <input type="hidden" name="ajax_save" value="1">
                <div class="row g-4">
                    <div class="col-md-4">
                        <label class="fw-bold small text-muted ms-2">APELLIDO PATERNO *</label>
                        <div class="input-container">
                            <input type="text" name="apellido_p" class="form-control-elite capitalize" data-type="alpha" required>
                            <i class="fas fa-signature"></i>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold small text-muted ms-2">APELLIDO MATERNO</label>
                        <div class="input-container">
                            <input type="text" name="apellido_m" class="form-control-elite capitalize" data-type="alpha">
                            <i class="fas fa-signature"></i>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold small text-muted ms-2">NOMBRES *</label>
                        <div class="input-container">
                            <input type="text" name="nombres" class="form-control-elite capitalize" data-type="alpha" required>
                            <i class="fas fa-user"></i>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold small text-muted ms-2">CI *</label>
                        <div class="input-container">
                            <input type="text" name="ci" class="form-control-elite" data-type="numeric" maxlength="10" required>
                            <i class="fas fa-id-card"></i>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold small text-muted ms-2">CELULAR *</label>
                        <div class="input-container">
                            <input type="text" name="celular" class="form-control-elite" data-type="numeric" maxlength="8" required>
                            <i class="fas fa-phone"></i>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="d-flex justify-content-between align-items-center mb-1 ms-2">
                            <label class="fw-bold small text-muted">CARRERA DESIGNADA *</label>
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#modalCarrera">Gestionar</button>
                        </div>
                        <div class="input-container">
                            <select name="id_carrera" id="carreraSelect" class="form-control-elite" required></select>
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                    </div>
                    <div class="col-md-12" id="div_especialidad" style="display:none;">
                        <label class="fw-bold small text-muted ms-2">ÁREA DE ESPECIALIDAD (SOLO DERECHO)</label>
                        <div class="input-container">
                            <input type="text" name="especialidad" class="form-control-elite capitalize" placeholder="Ej. Derecho Civil">
                            <i class="fas fa-award"></i>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn-submit mt-5">REGISTRAR DOCENTE TUTOR</button>
            </form>
        </div>
    </main>

    <div class="modal fade" id="modalCarrera" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg" style="border-radius: 30px; border: none; padding: 20px;">
                <div class="modal-header border-0">
                    <h5 class="fw-800">Panel de Carreras</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="input-group mb-3">
                        <input type="text" id="newCarreraInput" class="form-control rounded-pill px-3" placeholder="Nueva carrera...">
                        <button class="btn btn-primary rounded-pill ms-2 px-4 fw-bold" id="addCarreraBtn">Añadir</button>
                    </div>
                    <div id="carreraListContainer" style="max-height: 250px; overflow-y: auto;"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        $(document).ready(function() {
            const inputs = $('.form-control-elite');

            // 1. CAPITALIZACIÓN Y VALIDACIÓN (SOLO LETRAS O NÚMEROS)
            inputs.on('input', function() {
                let val = $(this).val();
                if ($(this).data('type') === 'numeric') $(this).val(val.replace(/[^0-9]/g, ''));
                if ($(this).data('type') === 'alpha') $(this).val(val.replace(/[0-9]/g, ''));
                if ($(this).hasClass('capitalize')) $(this).val($(this).val().replace(/\b\w/g, l => l.toUpperCase()));
            });

            // 2. ENTER PASA AL SIGUIENTE CAMPO
            inputs.on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    let index = inputs.index(this);
                    if (index + 1 < inputs.length) inputs.eq(index + 1).focus();
                    else $('#formTutor').submit();
                }
            });

            // 3. LÓGICA DE ESPECIALIDAD (SOLO DERECHO)
            $('#carreraSelect').on('change', function() {
                const text = $(this).find('option:selected').text().toUpperCase();
                if (text.includes('DERECHO')) {
                    $('#div_especialidad').fadeIn();
                } else {
                    $('#div_especialidad').fadeOut().find('input').val('');
                }
            });

            // 4. GESTIÓN DE CARRERAS (AJAX)
            async function loadCarreras() {
                const res = await fetch('?action=listar');
                const data = await res.json();
                let options = '<option value="">Seleccione carrera...</option>';
                let listHtml = '';
                data.forEach(c => {
                    options += `<option value="${c.id_carrera}">${c.nombre_carrera}</option>`;
                    listHtml += `<div class="d-flex justify-content-between p-3 mb-2 bg-light rounded-4">
                        <span class="fw-bold">${c.nombre_carrera}</span>
                        <button class="btn btn-sm text-danger" onclick="deleteCarrera(${c.id_carrera})"><i class="fas fa-trash"></i></button>
                    </div>`;
                });
                $('#carreraSelect').html(options);
                $('#carreraListContainer').html(listHtml);
            }

            $('#addCarreraBtn').click(async () => {
                const nombre = $('#newCarreraInput').val();
                if(!nombre) return;
                await fetch('?action=guardar', { method: 'POST', body: JSON.stringify({ nombre }) });
                $('#newCarreraInput').val('');
                loadCarreras();
            });

            window.deleteCarrera = async (id) => {
                if((await Swal.fire({title:'¿Eliminar?', showCancelButton:true})).isConfirmed) {
                    await fetch(`?action=eliminar&id=${id}`);
                    loadCarreras();
                }
            };

            // 5. ENVÍO FINAL AJAX
            $('#formTutor').on('submit', async function(e) {
                e.preventDefault();
                const res = await fetch('registro_tutores.php', { method: 'POST', body: new FormData(this) });
                const data = await res.json();
                if(data.status === 'success') {
                    Swal.fire('¡Éxito!', data.message, 'success').then(() => window.location.href='lista_tutores.php');
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            });

            loadCarreras();
        });
    </script>
</body>
</html>