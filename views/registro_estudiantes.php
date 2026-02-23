<?php
declare(strict_types=1);
session_start();

// 1. SEGURIDAD Y CONEXIÓN
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

require_once '../config/conexion.php';

// --- 2. LÓGICA DE CARRERAS INTEGRADA (AJAX) ---
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

$nombre_usuario = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$rol = htmlspecialchars((string)($_SESSION["role"] ?? 'Postgrado'), ENT_QUOTES, 'UTF-8');
$inicial = strtoupper(mb_substr($nombre_usuario, 0, 1, 'UTF-8'));
$favicon_url = "https://unior.edu.bo/favicon.svg";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>UNIOR | Registro Elite</title>
    
    <link rel="icon" type="image/svg+xml" href="<?= $favicon_url ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        :root {
            --primary: #4f46e5;
            --sidebar-w: 280px;
            --sidebar-c: 85px;
            --success: #10b981;
            --error: #ef4444;
        }

        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f1f5f9; display: flex; min-height: 100vh; margin: 0; }

        /* ============== SIDEBAR COMPLETO Y RESPONSIVO ============== */
        .sidebar {
            width: var(--sidebar-c); background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px);
            border-right: 1px solid rgba(0,0,0,0.05); height: 100vh; position: fixed;
            left: 0; top: 0; display: flex; flex-direction: column; padding: 25px 15px;
            transition: 0.4s cubic-bezier(0.4, 0, 0.2, 1); z-index: 2000;
        }

        @media (min-width: 992px) {
            .sidebar:hover { width: var(--sidebar-w); box-shadow: 20px 0 60px rgba(0,0,0,0.06); }
            .sidebar:hover .logo-text, .sidebar:hover .nav-item-ae span { opacity: 1; margin-left: 12px; }
        }

        .logo-aesthetic { display: flex; align-items: center; gap: 15px; padding: 10px; margin-bottom: 40px; text-decoration: none; }
        .logo-aesthetic img { width: 45px; height: 45px; }
        .logo-text { font-weight: 800; font-size: 1.5rem; color: var(--primary); opacity: 0; transition: 0.3s; white-space: nowrap; }

        nav { display: flex; flex-direction: column; gap: 10px; flex: 1; }
        .nav-item-ae { display: flex; align-items: center; padding: 15px; border-radius: 20px; color: #64748b; text-decoration: none; font-weight: 600; transition: 0.3s; }
        .nav-item-ae i { font-size: 1.3rem; min-width: 45px; text-align: center; }
        .nav-item-ae span { opacity: 0; transition: 0.3s; white-space: nowrap; }
        .nav-item-ae:hover, .nav-item-ae.active { background: #f1f5f9; color: var(--primary); }
        .nav-item-ae.active { background: var(--primary) !important; color: white !important; box-shadow: 0 10px 25px rgba(79, 70, 229, 0.2); }

        /* ============== CONTENIDO ============== */
        .main-wrapper { flex: 1; margin-left: var(--sidebar-c); padding: 40px; transition: 0.4s; width: 100%; }
        .glass-card { background: white; border-radius: 40px; padding: 50px; box-shadow: 0 20px 50px rgba(0,0,0,0.04); max-width: 950px; margin: 0 auto; }

        .field-group { margin-bottom: 20px; }
        .form-control-elite { 
            border-radius: 20px; padding: 16px 20px 16px 55px; border: 2px solid #e2e8f0; 
            font-weight: 600; width: 100%; transition: 0.3s; 
        }
        .form-control-elite:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 5px rgba(79, 70, 229, 0.1); }
        
        .input-container { position: relative; }
        .input-container i { position: absolute; left: 20px; top: 50%; transform: translateY(-50%); color: #94a3b8; }

        .is-valid-elite { border-color: var(--success) !important; background: #f0fdf4; }
        .is-invalid-elite { border-color: var(--error) !important; background: #fef2f2; animation: shake 0.3s; }

        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        .btn-submit { background: var(--primary); color: white; border: none; padding: 20px; border-radius: 22px; font-weight: 800; width: 100%; transition: 0.3s; box-shadow: 0 10px 30px rgba(79, 70, 229, 0.3); }

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
        <a href="menu.php" class="logo-aesthetic">
            <img src="<?= $favicon_url ?>" alt="UNIOR">
            <span class="logo-text">UNIOR</span>
        </a>
        <nav>
            <a href="menu.php" class="nav-item-ae"><i class="fas fa-home-alt"></i> <span>Menú</span></a>
            <a href="lisra_estudiantes.php" class="nav-item-ae active"><i class="fas fa-users-rays"></i> <span>Estudiantes</span></a>
            <a href="registro_tutores.php" class="nav-item-ae"><i class="fas fa-user-tie"></i> <span>Tutores</span></a>
            <a href="predefensas.php" class="nav-item-ae"><i class="fas fa-signature"></i> <span>Predefensas</span></a>
        </nav>
    </aside>

    <main class="main-wrapper">
        <div class="glass-card">
            <div class="text-center mb-5">
                <img src="<?= $favicon_url ?>" width="75" class="mb-3">
                <h2 class="fw-800">Registro Estudiantil</h2>
                <p class="text-muted fw-bold">Universidad Privada de Oruro</p>
            </div>

            <form id="registroForm" novalidate>
                <div class="row">
                    <div class="col-md-6 field-group">
                        <label class="fw-bold small mb-2 ms-2">PRIMER NOMBRE *</label>
                        <div class="input-container">
                            <i class="fas fa-user"></i>
                            <input type="text" name="primer_nombre" class="form-control-elite capitalize" data-type="alpha" placeholder="Ej: Juan" required>
                        </div>
                    </div>
                    <div class="col-md-6 field-group">
                        <label class="fw-bold small mb-2 ms-2">SEGUNDO NOMBRE</label>
                        <div class="input-container">
                            <i class="fas fa-user-tag"></i>
                            <input type="text" name="segundo_nombre" class="form-control-elite capitalize" data-type="alpha" placeholder="Opcional">
                        </div>
                    </div>

                    <div class="col-md-6 field-group">
                        <label class="fw-bold small mb-2 ms-2">PRIMER APELLIDO *</label>
                        <div class="input-container">
                            <i class="fas fa-signature"></i>
                            <input type="text" name="primer_apellido" class="form-control-elite capitalize" data-type="alpha" placeholder="Ej: Pérez" required>
                        </div>
                    </div>
                    <div class="col-md-6 field-group">
                        <label class="fw-bold small mb-2 ms-2">SEGUNDO APELLIDO</label>
                        <div class="input-container">
                            <i class="fas fa-signature"></i>
                            <input type="text" name="segundo_apellido" class="form-control-elite capitalize" data-type="alpha" placeholder="Opcional">
                        </div>
                    </div>

                    <div class="col-md-4 field-group">
                        <label class="fw-bold small mb-2 ms-2">CI *</label>
                        <div class="input-container">
                            <i class="fas fa-id-card"></i>
                            <input type="text" name="ci" class="form-control-elite" data-type="numeric" maxlength="10" required>
                        </div>
                    </div>
                    <div class="col-md-4 field-group">
                        <label class="fw-bold small mb-2 ms-2">RU *</label>
                        <div class="input-container">
                            <i class="fas fa-hashtag"></i>
                            <input type="text" name="ru" class="form-control-elite" data-type="numeric" required>
                        </div>
                    </div>
                    <div class="col-md-4 field-group">
                        <label class="fw-bold small mb-2 ms-2">CELULAR *</label>
                        <div class="input-container">
                            <i class="fas fa-phone"></i>
                            <input type="text" name="celular" class="form-control-elite" data-type="numeric" maxlength="8" required>
                        </div>
                    </div>

                    <div class="col-md-12 field-group">
                        <div class="d-flex justify-content-between align-items-center mb-2 ms-2">
                            <label class="fw-bold small m-0">CARRERA *</label>
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#modalCarrera">Gestionar</button>
                        </div>
                        <div class="input-container">
                            <i class="fas fa-graduation-cap"></i>
                            <select name="id_carrera" id="carreraSelect" class="form-control-elite" required>
                                <option value="">Seleccione carrera...</option>
                            </select>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-submit mt-4">REGISTRAR ESTUDIANTE</button>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        const form = document.getElementById('registroForm');
        const inputs = Array.from(form.querySelectorAll('input, select'));

        // VALIDACIONES Y MAYÚSCULAS
        inputs.forEach((input, index) => {
            input.addEventListener('input', function() {
                // Capitalizar primera letra de cada palabra
                if(this.classList.contains('capitalize')) {
                    this.value = this.value.replace(/\b\w/g, l => l.toUpperCase());
                }
                // Bloqueo de caracteres
                if(this.dataset.type === 'numeric') this.value = this.value.replace(/[^0-9]/g, '');
                if(this.dataset.type === 'alpha') this.value = this.value.replace(/[0-9]/g, '');
                
                this.classList.toggle('is-valid-elite', this.value.trim() !== "");
            });

            // SALTO CON ENTER
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const next = inputs[index + 1];
                    if (next) next.focus();
                    else form.requestSubmit();
                }
            });
        });

        // GESTIÓN DE CARRERAS AJAX
        async function loadCarreras() {
            const res = await fetch('?action=listar');
            const data = await res.json();
            const select = document.getElementById('carreraSelect');
            const list = document.getElementById('carreraListContainer');
            
            let options = '<option value="">Seleccione carrera...</option>';
            let listHtml = '';
            data.forEach(c => {
                options += `<option value="${c.id_carrera}">${c.nombre_carrera}</option>`;
                listHtml += `<div class="d-flex justify-content-between p-3 mb-2 bg-light rounded-4">
                                <span class="fw-bold">${c.nombre_carrera}</span>
                                <button class="btn btn-sm text-danger" onclick="deleteCarrera(${c.id_carrera})"><i class="fas fa-trash"></i></button>
                             </div>`;
            });
            select.innerHTML = options;
            list.innerHTML = listHtml;
        }

        document.getElementById('addCarreraBtn').onclick = async () => {
            const nombre = document.getElementById('newCarreraInput').value;
            if(!nombre) return;
            await fetch('?action=guardar', { method: 'POST', body: JSON.stringify({ nombre }) });
            document.getElementById('newCarreraInput').value = '';
            loadCarreras();
        };

        window.deleteCarrera = async (id) => {
            const confirm = await Swal.fire({ title: '¿Eliminar?', icon: 'warning', showCancelButton: true });
            if (confirm.isConfirmed) {
                await fetch(`?action=eliminar&id=${id}`);
                loadCarreras();
            }
        };

        // ENVÍO DEL FORMULARIO
        form.onsubmit = async (e) => {
            e.preventDefault();
            const formData = new FormData(form);
            const res = await fetch('../controllers/procesar_estudiante.php', { method: 'POST', body: formData });
            const data = await res.json();
            if(data.exito) {
                Swal.fire('¡Éxito!', data.mensaje, 'success').then(() => location.href='lisra_estudiantes.php');
            } else {
                Swal.fire('Error', data.mensaje, 'error');
            }
        };

        window.onload = loadCarreras;
    </script>
</body>
</html>