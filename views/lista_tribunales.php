<?php
session_start();
include_once '../config/conexion.php';

if (!isset($_SESSION["user_id"])) { 
    header("Location: login.php");
    exit();
}

$rol_usuario = $_SESSION["role"] ?? 'Invitado'; 
$nombre_usuario = $_SESSION["nombre_completo"] ?? ($_SESSION["username"] ?? 'Usuario'); 
$inicial = strtoupper(substr($nombre_usuario, 0, 1));

// 1. CONSULTA DE DATOS (Estudiantes + Tutores + Tribunales)
try {
    $sql = "SELECT a.id_asignacion, 
                   p_est.primer_nombre as est_nom, p_est.primer_apellido as est_ape, 
                   p_doc.primer_nombre as tut_nom, p_doc.primer_apellido as tut_ape,
                   c.nombre_carrera,
                   p_tri1.primer_nombre || ' ' || p_tri1.primer_apellido as pres_nom,
                   p_tri2.primer_nombre || ' ' || p_tri2.primer_apellido as sec_nom,
                   COALESCE(tri.estado, 'PENDIENTE') as tri_estado
            FROM public.asignaciones_tutor a
            INNER JOIN public.personas p_est ON a.id_estudiante = p_est.id_persona
            INNER JOIN public.estudiantes e ON p_est.id_persona = e.id_persona
            INNER JOIN public.carreras c ON e.id_carrera = c.id_carrera
            INNER JOIN public.personas p_doc ON a.id_docente = p_doc.id_persona
            LEFT JOIN public.tribunales tri ON a.id_asignacion = tri.id_asignacion AND tri.estado = 'ACTIVO'
            LEFT JOIN public.personas p_tri1 ON tri.id_presidente = p_tri1.id_persona
            LEFT JOIN public.personas p_tri2 ON tri.id_secretario = p_tri2.id_persona
            ORDER BY a.id_asignacion DESC";
    $asignaciones = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // 2. LISTA DE DOCENTES (Filtrado estricto por la tabla docentes)
    $docentes = $pdo->query("SELECT d.id_persona, p.primer_nombre || ' ' || p.primer_apellido as nombre, d.especialidad 
                             FROM public.docentes d 
                             JOIN public.personas p ON d.id_persona = p.id_persona 
                             WHERE p.estado = 'ACTIVO' ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $err) {
    die("Error: " . $err->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tribunales | UNIOR</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    
    <style>
        :root {
            --sb-collapsed: 80px; --sb-expanded: 280px;
            --primary-dark: #0f172a; --accent-blue: #3b82f6;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; margin: 0; overflow-x: hidden; }

        /* --- SIDEBAR RESPONSIVO --- */
        .sidebar {
            width: var(--sb-collapsed); height: 100vh; background: var(--primary-dark);
            position: fixed; left: 0; top: 0; z-index: 1100; transition: var(--transition);
            overflow: hidden; display: flex; flex-direction: column;
        }
        .sidebar.active { width: var(--sb-expanded); }
        .sidebar-brand { padding: 1.5rem; display: flex; align-items: center; text-decoration: none; color: white; height: 70px; }
        .sidebar-brand i { min-width: 45px; font-size: 1.5rem; color: var(--accent-blue); }
        .sidebar-brand span { opacity: 0; transition: 0.2s; font-weight: 800; white-space: nowrap; }
        .sidebar.active .sidebar-brand span { opacity: 1; }

        .menu-group { padding: 0 0.75rem; margin-bottom: 5px; }
        .menu-btn {
            width: 100%; padding: 12px 18px; background: transparent; border: none; color: #94a3b8;
            display: flex; align-items: center; border-radius: 12px; cursor: pointer; transition: 0.2s;
        }
        .menu-btn i { min-width: 35px; font-size: 1.2rem; }
        .menu-btn span { opacity: 0; white-space: nowrap; transition: 0.2s; margin-left: 10px; }
        .sidebar.active .menu-btn span { opacity: 1; }
        .menu-btn:hover { background: rgba(255,255,255,0.05); color: white; }

        /* --- MAIN CONTENT --- */
        .main-wrapper { margin-left: var(--sb-collapsed); transition: var(--transition); min-height: 100vh; }
        .sidebar.active ~ .main-wrapper { margin-left: var(--sb-expanded); }

        .top-navbar {
            background: white; height: 70px; padding: 0 1.5rem; display: flex;
            justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: sticky; top: 0; z-index: 1000;
        }
        .content-card { background: white; border-radius: 15px; padding: 1.5rem; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .tri-info { font-size: 0.75rem; color: #64748b; line-height: 1.2; }
        .tri-label { font-weight: 800; color: var(--accent-blue); font-size: 0.65rem; }

        @media (max-width: 768px) {
            .sidebar { left: -80px; }
            .sidebar.active { left: 0; }
            .main-wrapper { margin-left: 0; }
        }
    </style>
</head>
<body>

    <aside class="sidebar" id="sidebar">
        <a href="menu.php" class="sidebar-brand">
            <i class="fas fa-shield-halved"></i>
            <span>UNIOR TITULACIÓN</span>
        </a>
        <div class="mt-2">
            <div class="menu-group">
                <button class="menu-btn" onclick="location.href='lista_tribunales.php'">
                    <i class="fas fa-gavel"></i>
                    <span>Tribunales</span>
                </button>
            </div>
            </div>
    </aside>

    <div class="main-wrapper">
        <header class="top-navbar">
            <button class="btn btn-light" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
            <div class="d-flex align-items-center gap-3">
                <div class="text-end d-none d-sm-block">
                    <div class="fw-bold small"><?= htmlspecialchars($nombre_usuario) ?></div>
                    <span class="text-primary fw-bold" style="font-size: 0.6rem;"><?= strtoupper($rol_usuario) ?></span>
                </div>
                <div style="width:35px; height:35px; background:var(--accent-blue); color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold;"><?= $inicial ?></div>
            </div>
        </header>

        <div class="p-4">
            <h4 class="fw-bold mb-4 text-dark">Tribunales Examinadores</h4>

            <div class="content-card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Estudiante</th>
                                <th>Tutor</th>
                                <th>Jurado Asignado</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($asignaciones as $as): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold small"><?= $as['est_ape'].' '.$as['est_nom'] ?></div>
                                    <div class="text-muted" style="font-size: 0.7rem;"><?= $as['nombre_carrera'] ?></div>
                                </td>
                                <td class="small text-muted"><?= $as['tut_ape'].' '.$as['tut_nom'] ?></td>
                                <td>
                                    <?php if($as['tri_estado'] == 'ACTIVO'): ?>
                                        <div class="tri-info">
                                            <div><span class="tri-label">PRES:</span> <?= $as['pres_nom'] ?></div>
                                            <div><span class="tri-label">SECR:</span> <?= $as['sec_nom'] ?></div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small italic">Pendiente de jurados</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?= $as['tri_estado'] == 'ACTIVO' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' ?> rounded-pill">
                                        <?= $as['tri_estado'] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <button onclick="abrirModal(<?= $as['id_asignacion'] ?>, '<?= $as['est_ape'].' '.$as['est_nom'] ?>')" class="btn btn-sm btn-outline-primary border-0 shadow-sm">
                                        <i class="fas fa-user-plus"></i>
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

    <div class="modal fade" id="modalTribunal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg border-0" style="border-radius:15px;">
                <div class="modal-header bg-primary text-white border-0">
                    <h5 class="modal-title fw-bold">Configurar Jurados</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="formGuardarTribunal">
                    <div class="modal-body p-4">
                        <p class="small">Estudiante: <b id="nombreEstModal" class="text-primary"></b></p>
                        <input type="hidden" name="id_asignacion" id="modal_id_asig">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">PRESIDENTE DE TRIBUNAL</label>
                            <select name="id_presidente" class="form-select shadow-sm" required>
                                <option value="">Seleccione al docente...</option>
                                <?php foreach($docentes as $d): ?>
                                    <option value="<?= $d['id_persona'] ?>"><?= $d['nombre'] ?> (<?= $d['especialidad'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">SECRETARIO DE ACTA</label>
                            <select name="id_secretario" class="form-select shadow-sm" required>
                                <option value="">Seleccione al docente...</option>
                                <?php foreach($docentes as $d): ?>
                                    <option value="<?= $d['id_persona'] ?>"><?= $d['nombre'] ?> (<?= $d['especialidad'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="submit" class="btn btn-primary w-100 fw-bold">GUARDAR ASIGNACIÓN</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const modal = new bootstrap.Modal(document.getElementById('modalTribunal'));
        function toggleSidebar() { document.getElementById('sidebar').classList.toggle('active'); }

        function abrirModal(id, nombre) {
            document.getElementById('modal_id_asig').value = id;
            document.getElementById('nombreEstModal').innerText = nombre;
            modal.show();
        }

        document.getElementById('formGuardarTribunal').onsubmit = function(e) {
            e.preventDefault();
            const data = new FormData(this);
            if(data.get('id_presidente') === data.get('id_secretario')) {
                return Swal.fire('Error', 'El Presidente y Secretario deben ser docentes distintos.', 'error');
            }
            fetch('../controllers/guardar_tribunal.php', { method: 'POST', body: data })
            .then(res => res.json())
            .then(res => {
                if(res.status === 'success') {
                    Swal.fire('¡Éxito!', 'Tribunal asignado correctamente', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            });
        }
    </script>
</body>
</html>