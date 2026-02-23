<?php
declare(strict_types=1);
session_start();
require_once '../config/conexion.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

// =========================================================================
// MOTOR AJAX: PROCESAR EDICIÓN DE ESTUDIANTE
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar') {
    header('Content-Type: application/json');
    try {
        $pdo->beginTransaction();
        
        // 1. Actualizar tabla personas
        $stmtPersona = $pdo->prepare("UPDATE public.personas SET primer_nombre = ?, primer_apellido = ?, ci = ?, celular = ? WHERE id_persona = ?");
        $stmtPersona->execute([
            trim($_POST['nombre']),
            trim($_POST['apellido']),
            trim($_POST['ci']),
            trim($_POST['celular']),
            $_POST['id_persona']
        ]);
        
        // 2. Actualizar tabla estudiantes (RU y Carrera)
        $stmtEstudiante = $pdo->prepare("UPDATE public.estudiantes SET ru = ?, id_carrera = ? WHERE id_persona = ?");
        $stmtEstudiante->execute([
            trim($_POST['ru']),
            $_POST['id_carrera'],
            $_POST['id_persona']
        ]);
        
        $pdo->commit();
        echo json_encode(['exito' => true, 'mensaje' => 'Expediente actualizado correctamente.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        // Si hay error de llave duplicada (ej: CI o RU repetido)
        if ($e->getCode() == 23505) {
            echo json_encode(['exito' => false, 'mensaje' => 'El CI o el RU ya están registrados en otro estudiante.']);
        } else {
            echo json_encode(['exito' => false, 'mensaje' => 'Error al guardar: ' . $e->getMessage()]);
        }
    }
    exit;
}

// =========================================================================
// 1. CONFIGURACIÓN DE IDENTIDAD
// =========================================================================
$rol = strtolower($_SESSION["role"] ?? 'registro');
$es_admin = ($rol === 'administrador');
$nombre_usuario = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'));
$rol_display = htmlspecialchars((string)($_SESSION["role"] ?? 'Postgrado'));
$inicial_nav = strtoupper(mb_substr($nombre_usuario, 0, 1, 'UTF-8'));
$favicon_url = "https://unior.edu.bo/favicon.svg";
$ruta_logo = "https://unior.edu.bo/favicon.svg";
// BANNER: URL DIRECTA DE LA IMAGEN
$banner_url = "https://th.bing.com/th/id/OIP.fGbv34hHN0EA_eJ2Mm9NqwHaCv?w=331&h=129&c=7&r=0&o=7&dpr=1.3&pid=1.7&rm=3";

// =========================================================================
// 2. LÓGICA DE FILTROS Y BÚSQUEDA
// =========================================================================
$search = $_GET['search'] ?? '';
$carrera_filtro = $_GET['carrera'] ?? '';
$estado_filtro = $_GET['estado'] ?? 'ACTIVO'; 

$params = [];
$condiciones = [];
$condiciones[] = "p.estado = ?";
$params[] = strtoupper($estado_filtro);

if (!empty($carrera_filtro)) { 
    $condiciones[] = "c.id_carrera = ?"; 
    $params[] = (int)$carrera_filtro; 
}

if (!empty($search)) {
    $condiciones[] = "(p.primer_nombre ILIKE ? OR p.primer_apellido ILIKE ? OR p.ci ILIKE ? OR e.ru ILIKE ? OR p.celular ILIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s, $s);
}

try {
    $carreras_btn = $pdo->query("SELECT id_carrera, nombre_carrera FROM public.carreras ORDER BY nombre_carrera ASC")->fetchAll(PDO::FETCH_ASSOC);
    $sql = "SELECT p.*, e.ru, e.id_carrera, c.nombre_carrera 
            FROM public.personas p 
            JOIN public.estudiantes e ON p.id_persona = e.id_persona 
            JOIN public.carreras c ON e.id_carrera = c.id_carrera";
    
    if (!empty($condiciones)) { $sql .= " WHERE " . implode(" AND ", $condiciones); }
    $sql .= " ORDER BY c.nombre_carrera ASC, p.primer_apellido ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grupos = [];
    foreach ($estudiantes as $est) {
        $grupos[$est['nombre_carrera']][] = $est;
    }
} catch (PDOException $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>UNIOR | Gestión Elite</title>
    <link rel="icon" type="image/svg+xml" href="<?= $favicon_url ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        :root { --primary: #4f46e5; --sidebar-w: 280px; --sidebar-c: 85px; --bg: #f8fafc; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); display: flex; min-height: 100vh; margin: 0; overflow-x: hidden; }

        /* SIDEBAR ELITE */
        .sidebar {
            width: var(--sidebar-c); background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(25px);
            border-right: 1px solid rgba(0,0,0,0.05); height: 100vh; position: fixed; z-index: 2000; transition: 0.4s;
            display: flex; flex-direction: column; padding: 25px 15px;
        }
        @media (min-width: 992px) {
            .sidebar:hover { width: var(--sidebar-w); box-shadow: 20px 0 60px rgba(0,0,0,0.06); }
            .sidebar:hover .logo-text, .sidebar:hover .nav-item-ae span { opacity: 1; margin-left: 12px; display: inline-block; }
        }
        .logo-aesthetic { display: flex; align-items: center; gap: 15px; padding: 10px; margin-bottom: 40px; text-decoration: none; }
        .logo-text { font-weight: 800; font-size: 1.6rem; color: var(--primary); opacity: 0; transition: 0.3s; white-space: nowrap; }
        .nav-item-ae { display: flex; align-items: center; padding: 15px; border-radius: 20px; color: #64748b; text-decoration: none; font-weight: 600; transition: 0.3s; margin-bottom: 5px; }
        .nav-item-ae span { opacity: 0; display: none; }
        .nav-item-ae.active { background: var(--primary) !important; color: white !important; }

        .main-wrapper { flex: 1; margin-left: var(--sidebar-c); padding: 40px; width: 100%; }

        /* BANNER (SOLO LA IMAGEN) */
        .hero-banner {
            width: 100%; height: 260px; border-radius: 45px;
            background: url('<?= $banner_url ?>') center/cover no-repeat;
            margin-bottom: 40px; box-shadow: 0 20px 50px rgba(0,0,0,0.05);
        }

        /* FILAS */
        .student-list-item {
            background: white; border-radius: 25px; padding: 18px 25px; margin-bottom: 12px;
            display: flex; align-items: center; gap: 20px; border: 1px solid #f1f5f9; transition: 0.3s;
        }
        .student-list-item:hover { transform: translateX(10px); border-left: 8px solid var(--primary); }
        .avatar-sq { width: 55px; height: 55px; border-radius: 16px; background: #f1f5f9; color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.3rem; }
        .wa-link { color: #10b981; text-decoration: none; font-weight: 800; }

        /* BOTONES */
        .btn-circle-elite { width: 42px; height: 42px; border-radius: 14px; display: flex; align-items: center; justify-content: center; background: #f1f5f9; color: #64748b; border: none; transition: 0.3s; }
        .btn-circle-elite:hover { background: var(--primary); color: white; transform: scale(1.1); }

        /* MODALES ELITE */
        .modal-elite .modal-content { border-radius: 40px; border: none; overflow: hidden; box-shadow: 0 30px 80px rgba(0,0,0,0.2); }
        .modal-banner-real { height: 160px; background-size: cover !important; background-position: center !important; position: relative; }
        .modal-avatar-box { position: absolute; bottom: -35px; left: 35px; width: 90px; height: 90px; background: var(--primary); color: white; border-radius: 25px; display: flex; align-items: center; justify-content: center; font-size: 2.2rem; font-weight: 800; border: 6px solid white; box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .modal-body { padding: 50px 40px 40px; }
        .info-glass { background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 20px; margin-bottom: 10px; display: flex; align-items: center; gap: 15px; }
        .carrera-divider { margin-top: 30px; margin-bottom: 15px; font-weight: 800; color: #475569; font-size: 1.1rem; text-transform: uppercase; letter-spacing: 1px; display: flex; align-items: center; gap: 10px; }

        @media (max-width: 991px) {
            .sidebar { width: 100%; height: 75px; top: auto; bottom: 0; flex-direction: row; justify-content: space-around; border-radius: 25px 25px 0 0; padding: 10px; }
            .nav-item-ae { margin-bottom: 0; padding: 10px; }
            .main-wrapper { margin-left: 0; padding: 20px; padding-bottom: 100px; }
            .student-list-item { flex-direction: column; text-align: center; }
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
        <div class="d-flex justify-content-end mb-4">
            <div style="background: white; padding: 8px 15px; border-radius: 100px; display: flex; align-items: center; gap: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.03);">
                <div class="text-end d-none d-sm-block">
                    <div class="fw-bold small"><?= $nombre_usuario ?></div>
                    <div class="text-muted fw-bold" style="font-size: 9px;"><?= $rol_display ?></div>
                </div>
                <div style="width: 38px; height: 38px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800;"><?= $inicial_nav ?></div>
            </div>
        </div>

        <div class="hero-banner"></div>

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div style="background: #e2e8f0; padding: 5px; border-radius: 20px;">
                <a href="?estado=ACTIVO" class="btn <?= $estado_filtro === 'ACTIVO' ? 'btn-primary' : '' ?> rounded-pill px-4 fw-bold">ACTIVOS</a>
                <a href="?estado=INACTIVO" class="btn <?= $estado_filtro === 'INACTIVO' ? 'btn-primary' : '' ?> rounded-pill px-4 fw-bold">INACTIVOS</a>
            </div>
            <form method="GET" class="d-flex gap-2 flex-grow-1" style="max-width: 600px;">
                <input type="text" name="search" class="form-control rounded-pill px-4" placeholder="Nombre, RU o CI..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn btn-primary rounded-circle"><i class="fas fa-search"></i></button>
            </form>
        </div>

        <div class="list-container">
            <?php if(empty($grupos)): ?>
                <div class="text-center p-5 text-muted">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <h5 class="fw-bold">No se encontraron estudiantes</h5>
                </div>
            <?php else: ?>
                <?php foreach($grupos as $carrera => $lista): ?>
                    <div class="carrera-divider"><i class="fas fa-university"></i> <?= $carrera ?></div>
                    <?php foreach($lista as $e): ?>
                    <div class="student-list-item shadow-sm">
                        <div class="avatar-sq"><?= strtoupper(substr($e['primer_nombre'],0,1)) ?></div>
                        <div style="flex: 2;">
                            <h6 class="mb-0 fw-800"><?= htmlspecialchars($e['primer_apellido']) ?> <?= htmlspecialchars($e['primer_nombre']) ?></h6>
                            <small class="text-primary fw-bold">RU: <?= htmlspecialchars($e['ru']) ?></small>
                        </div>
                        <div style="flex: 1;" class="d-none d-md-block">
                            <small class="d-block text-muted fw-bold">CI</small>
                            <b class="small"><?= htmlspecialchars($e['ci']) ?></b>
                        </div>
                        <div style="flex: 1;">
                            <small class="d-block text-muted fw-bold">WHATSAPP</small>
                            <a href="https://wa.me/591<?= htmlspecialchars($e['celular']) ?>" target="_blank" class="wa-link small"><?= htmlspecialchars($e['celular']) ?: 'S/N' ?></a>
                        </div>
                        <div class="d-flex gap-2">
                            <button onclick='verPerfil(<?= json_encode($e) ?>)' class="btn-circle-elite"><i class="fas fa-eye"></i></button>
                            <button onclick='abrirEditar(<?= json_encode($e) ?>)' class="btn-circle-elite"><i class="fas fa-pen"></i></button>
                            <?php if($es_admin): ?>
                            <button onclick="confirmarCambio(<?= $e['id_persona'] ?>, '<?= $e['estado'] ?>')" class="btn-circle-elite text-danger"><i class="fas fa-power-off"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <div class="modal fade modal-elite" id="modalVer" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-banner-real" style="background: url('<?= $banner_url ?>');">
                    <div class="modal-avatar-box" id="v_ini"></div>
                </div>
                <div class="modal-body">
                    <h4 class="fw-800 text-center mb-4" id="v_nombre"></h4>
                    <div class="info-glass"><i class="fas fa-id-card"></i> <div><small class="d-block fw-bold text-muted">CI</small><b id="v_ci"></b></div></div>
                    <div class="info-glass"><i class="fas fa-university"></i> <div><small class="d-block fw-bold text-muted">CARRERA</small><b id="v_carrera"></b></div></div>
                    <div class="info-glass"><i class="fab fa-whatsapp"></i> <div><small class="d-block fw-bold text-muted">CELULAR</small><b id="v_cel"></b></div></div>
                    <button class="btn btn-primary w-100 py-3 rounded-4 fw-800 mt-3" data-bs-dismiss="modal">CERRAR</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade modal-elite" id="modalEditar" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-banner-real" style="background: url('<?= $banner_url ?>'); height: 120px;"></div>
                <div class="modal-body">
                    <h4 class="fw-800 mb-4">Editar Expediente</h4>
                    <form id="formEdit">
                        <input type="hidden" name="id_persona" id="e_id">
                        <input type="hidden" name="accion" value="actualizar">
                        
                        <div class="row g-3">
                            <div class="col-md-6"><label class="small fw-bold">NOMBRES</label><input type="text" name="nombre" id="e_nombre" class="form-control rounded-3" required></div>
                            <div class="col-md-6"><label class="small fw-bold">APELLIDOS</label><input type="text" name="apellido" id="e_apellido" class="form-control rounded-3" required></div>
                            <div class="col-md-6"><label class="small fw-bold">CI</label><input type="text" name="ci" id="e_ci" class="form-control rounded-3" required></div>
                            <div class="col-md-6"><label class="small fw-bold">RU</label><input type="text" name="ru" id="e_ru" class="form-control rounded-3" required></div>
                            <div class="col-md-6"><label class="small fw-bold">WHATSAPP</label><input type="text" name="celular" id="e_cel" class="form-control rounded-3" required></div>
                            <div class="col-md-6">
                                <label class="small fw-bold">CARRERA</label>
                                <select name="id_carrera" id="e_carrera" class="form-select rounded-3">
                                    <?php foreach($carreras_btn as $c): ?><option value="<?= $c['id_carrera'] ?>"><?= $c['nombre_carrera'] ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-4">
                            <button type="button" class="btn btn-light w-100 fw-bold py-3 rounded-4" data-bs-dismiss="modal">CANCELAR</button>
                            <button type="submit" class="btn btn-primary w-100 fw-bold py-3 rounded-4" id="btnSave">GUARDAR CAMBIOS</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function verPerfil(e) {
            document.getElementById('v_nombre').innerText = e.primer_apellido + ' ' + e.primer_nombre;
            document.getElementById('v_ci').innerText = e.ci;
            document.getElementById('v_carrera').innerText = e.nombre_carrera;
            document.getElementById('v_cel').innerText = e.celular || 'S/N';
            document.getElementById('v_ini').innerText = e.primer_nombre.charAt(0).toUpperCase();
            new bootstrap.Modal(document.getElementById('modalVer')).show();
        }

        function abrirEditar(e) {
            document.getElementById('e_id').value = e.id_persona;
            document.getElementById('e_nombre').value = e.primer_nombre;
            document.getElementById('e_apellido').value = e.primer_apellido;
            document.getElementById('e_ci').value = e.ci;
            document.getElementById('e_ru').value = e.ru;
            document.getElementById('e_cel').value = e.celular;
            document.getElementById('e_carrera').value = e.id_carrera;
            new bootstrap.Modal(document.getElementById('modalEditar')).show();
        }

        document.getElementById('formEdit').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSave');
            btn.disabled = true; btn.innerText = 'Guardando...';
            try {
                // AQUÍ ESTABA EL ERROR: AHORA ENVÍA LA PETICIÓN A ESTE MISMO ARCHIVO ('')
                const response = await fetch('', { method: 'POST', body: new FormData(this) });
                const data = await response.json();
                
                if(data.exito) { 
                    Swal.fire({ icon:'success', title:'¡Éxito!', text: data.mensaje, showConfirmButton:false, timer:1500 });
                    setTimeout(() => location.reload(), 1500);
                } else { 
                    Swal.fire('Error', data.mensaje, 'error'); 
                    btn.disabled = false; btn.innerText = 'GUARDAR CAMBIOS'; 
                }
            } catch(err) { 
                Swal.fire('Error', 'No se pudo conectar con el servidor', 'error'); 
                btn.disabled = false; btn.innerText = 'GUARDAR CAMBIOS';
            }
        });

        function confirmarCambio(id, actual) {
            const nuevo = actual === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO';
            Swal.fire({
                title: `¿Marcar como ${nuevo}?`,
                icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, cambiar', borderRadius: '25px'
            }).then((result) => {
                if (result.isConfirmed) window.location.href = `../controllers/eliminar_estudiante.php?id=${id}&estado=${nuevo}`;
            });
        }
    </script>
</body>
</html>