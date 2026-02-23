<?php
declare(strict_types=1);
session_start();
require_once '../config/conexion.php';

// 1. PROCESAMIENTO DE DATOS (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar') {
    header('Content-Type: application/json');
    try {
        if (!isset($_SESSION["user_id"])) throw new Exception("Sesión expirada");
        $pdo->beginTransaction();
        
        // Registrar usuario para auditoría en DB
        $pdo->exec("SET app.current_user_id = " . intval($_SESSION['user_id']));

        $sqlP = "UPDATE public.personas SET primer_nombre = ?, primer_apellido = ?, ci = ?, celular = ? WHERE id_persona = ?";
        $pdo->prepare($sqlP)->execute([
            trim($_POST['nombre']), trim($_POST['apellido']), 
            trim($_POST['ci']), trim($_POST['celular']), $_POST['id_persona']
        ]);

        $sqlE = "UPDATE public.estudiantes SET id_carrera = ?, ru = ? WHERE id_persona = ?";
        $pdo->prepare($sqlE)->execute([$_POST['id_carrera'], trim($_POST['ru']), $_POST['id_persona']]);

        $pdo->commit();
        echo json_encode(['exito' => true, 'mensaje' => '¡Expediente actualizado correctamente!']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = ($e->getCode() == '23505') ? "Error: El C.I. o R.U. ya está registrado." : $e->getMessage();
        echo json_encode(['exito' => false, 'mensaje' => $msg]);
    }
    exit;
}

// 2. CARGA DE DATOS INICIAL
if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit(); }
$id = $_GET['id'] ?? null;
if (!$id) { header("Location: lista_estudiantes.php"); exit(); }

$nombre_usuario = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'));
$rol_display = htmlspecialchars((string)($_SESSION["role"] ?? 'Postgrado'));
$inicial = strtoupper(mb_substr($nombre_usuario, 0, 1, 'UTF-8'));
$favicon_url = "https://unior.edu.bo/favicon.svg";
$banner_url = "https://th.bing.com/th/id/OIP.fGbv34hHN0EA_eJ2Mm9NqwHaCv?w=331&h=129&c=7&r=0&o=7&dpr=1.3&pid=1.7&rm=3";

try {
    $stmt = $pdo->prepare("SELECT p.*, e.id_carrera, e.ru FROM personas p 
                           JOIN estudiantes e ON p.id_persona = e.id_persona WHERE p.id_persona = ?");
    $stmt->execute([$id]);
    $est = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$est) die("Estudiante no encontrado.");
    $carreras = $pdo->query("SELECT id_carrera, nombre_carrera FROM carreras ORDER BY nombre_carrera ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { die("Error de conexión"); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Editar Estudiante | UNIOR</title>
    <link rel="icon" type="image/svg+xml" href="<?= $favicon_url ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        :root {
            --primary: #4f46e5;
            --sidebar-w: 280px;
            --sidebar-c: 85px;
            --bg-body: #f8fafc;
        }

        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: var(--bg-body); margin: 0; display: flex; min-height: 100vh; overflow-x: hidden; }

        /* ============== SIDEBAR ELITE (IDÉNTICO) ============== */
        .sidebar {
            width: var(--sidebar-c); background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px); border-right: 1px solid rgba(0,0,0,0.05);
            height: 100vh; position: fixed; left: 0; top: 0; display: flex; flex-direction: column; padding: 25px 15px;
            transition: 0.4s cubic-bezier(0.4, 0, 0.2, 1); z-index: 2000;
        }
        @media (min-width: 992px) {
            .sidebar:hover { width: var(--sidebar-w); box-shadow: 20px 0 60px rgba(0,0,0,0.06); }
            .sidebar:hover .logo-text, .sidebar:hover .nav-item-ae span { opacity: 1; margin-left: 12px; display: inline-block; }
            .sidebar:hover .logo-aesthetic img { transform: rotate(360deg); }
        }
        .logo-aesthetic { display: flex; align-items: center; gap: 15px; padding: 10px; margin-bottom: 40px; text-decoration: none; }
        .logo-aesthetic img { width: 48px; height: 48px; object-fit: contain; transition: 0.6s; }
        .logo-text { font-weight: 800; font-size: 1.6rem; color: var(--primary); opacity: 0; transition: 0.3s; white-space: nowrap; }

        nav { display: flex; flex-direction: column; gap: 8px; flex: 1; }
        .nav-item-ae { display: flex; align-items: center; padding: 14px 18px; border-radius: 18px; color: #64748b; text-decoration: none; font-weight: 600; transition: 0.3s; }
        .nav-item-ae i { font-size: 1.3rem; min-width: 35px; text-align: center; }
        .nav-item-ae span { opacity: 0; transition: 0.3s; display: none; }
        .nav-item-ae:hover, .nav-item-ae.active { background: white; color: var(--primary); transform: translateX(5px); }
        .nav-item-ae.active { background: var(--primary) !important; color: white !important; box-shadow: 0 10px 25px rgba(79, 70, 229, 0.2); }

        .main-wrapper { flex: 1; margin-left: var(--sidebar-c); padding: 40px; transition: 0.4s; width: 100%; }

        /* BANNER NÍTIDO */
        .hero-banner {
            width: 100%; height: 220px; border-radius: 40px;
            background-image: url('<?= $banner_url ?>');
            background-size: cover; background-position: center;
            margin-bottom: 40px; box-shadow: 0 20px 50px rgba(0,0,0,0.08);
            display: flex; align-items: center; padding: 50px;
        }

        /* CARD DE FORMULARIO */
        .glass-card {
            background: white; border-radius: 35px; padding: 40px;
            border: 1px solid #f1f5f9; box-shadow: 0 30px 60px rgba(0,0,0,0.05);
            max-width: 900px; margin: 0 auto;
        }

        .form-section-header { display: flex; align-items: center; gap: 15px; margin-bottom: 25px; margin-top: 10px; }
        .form-icon-box { width: 45px; height: 45px; background: rgba(79,70,229,0.1); color: var(--primary); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        
        .form-label { font-size: 0.75rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-left: 5px; }
        .form-control, .form-select { border-radius: 15px; padding: 12px 18px; border: 2px solid #f1f5f9; background: #f8fafc; font-weight: 600; transition: 0.3s; }
        .form-control:focus { border-color: var(--primary); background: white; box-shadow: none; }

        .btn-elite { padding: 15px 30px; border-radius: 18px; font-weight: 800; transition: 0.3s; display: flex; align-items: center; gap: 10px; border: none; }
        .btn-save { background: var(--primary); color: white; box-shadow: 0 10px 20px rgba(79,70,229,0.2); width: 100%; justify-content: center; }
        .btn-save:hover { transform: translateY(-3px); box-shadow: 0 15px 30px rgba(79,70,229,0.3); color: white; }

        /* MODAL ELITE */
        .modal-elite .modal-content { border-radius: 35px; border: none; overflow: hidden; }
        .modal-elite .modal-header { background: var(--primary); color: white; padding: 25px; border: none; }
        .modal-elite .btn-close { filter: brightness(0) invert(1); }

        @media (max-width: 991px) {
            .sidebar { width: 100%; height: 75px; bottom: 0; top: auto; flex-direction: row; border-radius: 20px 20px 0 0; padding: 0 10px; }
            .logo-aesthetic, .nav-item-ae span { display: none; }
            nav { flex-direction: row; justify-content: space-around; width: 100%; }
            .main-wrapper { margin-left: 0; padding: 20px 20px 100px; }
        }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="logo-aesthetic d-none d-lg-flex">
            <img src="<?= $favicon_url ?>" alt="UNIOR">
            <span class="logo-text">UNIOR</span>
        </div>
        <nav>
            <a href="menu.php" class="nav-item-ae"><i class="fas fa-home-alt"></i> <span>Menú</span></a>
            <a href="lista_estudiantes.php" class="nav-item-ae active"><i class="fas fa-users-rays"></i> <span>Estudiantes</span></a>
            <a href="registro_tutores.php" class="nav-item-ae"><i class="fas fa-user-tie"></i> <span>Tutores</span></a>
            <a href="lista_tutores.php" class="nav-item-ae"><i class="fas fa-fingerprint"></i> <span>Lista Tutores</span></a>
            <a href="predefensas.php" class="nav-item-ae"><i class="fas fa-signature"></i> <span>Predefensas</span></a>
        </nav>
        <a href="../controllers/logout.php" class="nav-item-ae text-danger mt-auto d-none d-lg-flex">
            <i class="fas fa-power-off"></i> <span>Cerrar Sesión</span>
        </a>
    </aside>

    <main class="main-wrapper">
        <div class="d-flex justify-content-end mb-4">
            <div style="background: white; padding: 8px 18px; border-radius: 100px; display: flex; align-items: center; gap: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
                <div class="text-end d-none d-sm-block">
                    <div class="fw-bold small"><?= $nombre_usuario ?></div>
                    <div class="text-muted fw-bold" style="font-size: 9px;"><?= $rol_display ?></div>
                </div>
                <div style="width: 36px; height: 36px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800;"><?= $inicial ?></div>
            </div>
        </div>

        <div class="hero-banner shadow-sm"></div>

        <div class="glass-card">
            <h2 class="fw-800 mb-4" style="color: #1e293b; letter-spacing: -1px;">Editar Expediente.</h2>
            
            <form id="formUpdate">
                <input type="hidden" name="id_persona" value="<?= $est['id_persona'] ?>">
                <input type="hidden" name="accion" value="actualizar">

                <div class="form-section-header">
                    <div class="form-icon-box"><i class="fas fa-user-edit"></i></div>
                    <h5 class="m-0 fw-800">Datos Personales</h5>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Nombre(s)</label>
                        <input type="text" name="nombre" class="form-control" value="<?= $est['primer_nombre'] ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Apellido(s)</label>
                        <input type="text" name="apellido" class="form-control" value="<?= $est['primer_apellido'] ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Cédula de Identidad</label>
                        <input type="text" name="ci" class="form-control" value="<?= $est['ci'] ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Número Celular</label>
                        <input type="text" name="celular" class="form-control" value="<?= $est['celular'] ?>" required>
                    </div>
                </div>

                <div class="form-section-header">
                    <div class="form-icon-box"><i class="fas fa-graduation-cap"></i></div>
                    <h5 class="m-0 fw-800">Información Académica</h5>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Registro Universitario (RU)</label>
                        <input type="text" name="ru" class="form-control" value="<?= $est['ru'] ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Carrera Actual</label>
                        <div class="d-flex gap-2">
                            <select name="id_carrera" id="id_carrera" class="form-select" required>
                                <?php foreach($carreras as $c): ?>
                                    <option value="<?= $c['id_carrera'] ?>" <?= $c['id_carrera'] == $est['id_carrera'] ? 'selected' : '' ?>>
                                        <?= $c['nombre_carrera'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalCarrera" style="border-radius: 12px; width: 50px;"><i class="fas fa-exchange-alt"></i></button>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-3 mt-5">
                    <a href="lista_estudiantes.php" class="btn btn-light fw-bold px-4" style="border-radius: 15px; padding: 15px;">Cancelar</a>
                    <button type="submit" class="btn-elite btn-save" id="btnSubmit">
                        <i class="fas fa-check-circle"></i> Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </main>

    <div class="modal fade modal-elite" id="modalCarrera" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg">
                <div class="modal-header">
                    <h5 class="modal-title fw-800">Cambio de Carrera</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small fw-bold">Seleccione la nueva carrera para transferir el expediente del estudiante.</p>
                    <select id="nuevaCarreraSelect" class="form-select mb-3">
                        <?php foreach($carreras as $c): ?>
                            <option value="<?= $c['id_carrera'] ?>" <?= $c['id_carrera'] == $est['id_carrera'] ? 'selected' : '' ?>>
                                <?= $c['nombre_carrera'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-primary w-100 fw-bold py-3" onclick="confirmarCambioModal()" style="border-radius: 15px;">Confirmar Cambio</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function confirmarCambioModal() {
            const nueva = document.getElementById('nuevaCarreraSelect').value;
            document.getElementById('id_carrera').value = nueva;
            bootstrap.Modal.getInstance(document.getElementById('modalCarrera')).hide();
            Swal.fire({ icon: 'info', title: 'Carrera seleccionada', text: 'Presione guardar para aplicar los cambios finales', timer: 2000, showConfirmButton: false });
        }

        document.getElementById('formUpdate').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmit');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Guardando...';

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: new FormData(this)
                });
                const data = await response.json();
                if (data.exito) {
                    Swal.fire({ icon: 'success', title: '¡Éxito!', text: data.mensaje, confirmButtonColor: '#4f46e5' })
                    .then(() => window.location.href = 'lista_estudiantes.php');
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.mensaje });
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check-circle"></i> Guardar Cambios';
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Error de conexión' });
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-circle"></i> Guardar Cambios';
            }
        });
    </script>
</body>
</html>