<?php 
declare(strict_types=1);
session_start();

// 1. Control de Seguridad
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

require_once("../config/conexion.php");

$nombre_usuario = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$inicial        = strtoupper(substr($nombre_usuario, 0, 1));

// 2. LÓGICA DE ACTUALIZACIÓN (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $id_asignacion = filter_input(INPUT_POST, 'id_asignacion', FILTER_VALIDATE_INT);
    $id_docente = filter_input(INPUT_POST, 'id_tutor', FILTER_VALIDATE_INT); // ID del nuevo docente/tutor

    if (!$id_asignacion || !$id_docente) {
        echo json_encode(['status' => 'error', 'message' => 'Faltan datos obligatorios.']);
        exit;
    }

    try {
        // Actualizamos en asignaciones_tutor usando los nombres de columna de tu DB
        $stmt = $pdo->prepare("UPDATE public.asignaciones_tutor 
                               SET id_docente = :id_docente
                               WHERE id_asignacion = :id");
        $stmt->execute([':id_docente' => $id_docente, ':id' => $id_asignacion]);
        
        echo json_encode(['status' => 'success', 'message' => 'Asignación de tutor actualizada con éxito']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error de BD: ' . $e->getMessage()]);
    }
    exit; 
}

// 3. LÓGICA DE CARGA (GET)
$id_url = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id_url) { header("Location: lista_tutores.php"); exit(); }

try {
    // Consulta corregida para traer datos de la asignación y del estudiante
    $stmt = $pdo->prepare("SELECT at.id_asignacion, at.id_docente, p.primer_nombre, p.primer_apellido, e.ru 
                            FROM public.asignaciones_tutor at
                            INNER JOIN public.estudiantes e ON at.id_estudiante = e.id_persona
                            INNER JOIN public.personas p ON e.id_persona = p.id_persona
                            WHERE at.id_asignacion = ?");
    $stmt->execute([$id_url]);
    $datos = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$datos) {
        die("<h3>Error: Registro no encontrado.</h3><a href='lista_tutores.php'>Volver</a>");
    }

    // Listar todos los docentes activos para el select
    $tutores = $pdo->query("SELECT p.id_persona, p.primer_nombre, p.primer_apellido 
                            FROM public.personas p 
                            INNER JOIN public.docentes d ON p.id_persona = d.id_persona 
                            WHERE p.estado ILIKE 'activo' 
                            ORDER BY p.primer_apellido ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error crítico: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Editar Tutoría - UNIOR</title>
    <link rel="icon" type="image/png" href="../assets/img/logo_unior1.png">
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@300;500;800&family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        :root {
            --glass: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.8);
            --accent: #818cf8;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --bg-main: #f8fafc;
            --shadow-aest: 0 20px 40px rgba(0, 0, 0, 0.03);
            --ease: cubic-bezier(0.4, 0, 0.2, 1);
        }
        body { 
            font-family: 'Inter', sans-serif; background-color: var(--bg-main); 
            color: var(--text-dark); margin: 0; min-height: 100vh; display: flex;
            background-image: radial-gradient(at 0% 0%, rgba(129, 140, 248, 0.08) 0, transparent 40%);
        }
        
        .sidebar {
            width: 80px; height: 94vh; margin: 3vh 0 3vh 20px;
            background: var(--glass); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border-radius: 30px; border: 1px solid var(--glass-border);
            display: flex; flex-direction: column; align-items: center;
            padding: 30px 0; position: sticky; top: 3vh;
            transition: width 0.4s var(--ease); box-shadow: var(--shadow-aest); z-index: 1000;
        }
        @media (min-width: 992px) {
            .sidebar:hover { width: 260px; align-items: flex-start; padding: 30px 20px; }
        }
        .nav-item-ae {
            width: 100%; display: flex; align-items: center; padding: 14px;
            margin-bottom: 8px; border-radius: 18px; color: var(--text-muted);
            text-decoration: none; transition: 0.3s;
        }
        .nav-item-ae i { font-size: 1.2rem; min-width: 50px; text-align: center; }
        .nav-item-ae span { opacity: 0; font-weight: 600; white-space: nowrap; transition: 0.2s; position: absolute; }
        .sidebar:hover .nav-item-ae span { opacity: 1; position: relative; margin-left: 10px; }
        .nav-item-ae:hover, .nav-item-ae.active { background: white; color: var(--accent); box-shadow: 0 10px 20px rgba(0,0,0,0.03); }

        .main-wrapper { flex: 1; padding: 40px; max-width: 1000px; margin: 0 auto; width: 100%; }
        .hero-title { font-family: 'Bricolage Grotesque'; font-size: clamp(2.5rem, 5vw, 4rem); font-weight: 300; letter-spacing: -2px; line-height: 0.9; margin: 20px 0 40px 0; }
        
        .cloud-card {
            background: var(--glass); border: 1px solid var(--glass-border); border-radius: 40px;
            padding: 40px; box-shadow: var(--shadow-aest);
        }

        .user-header { display: flex; justify-content: flex-end; align-items: center; gap: 15px; margin-bottom: 30px; }
        .user-pill { background: var(--glass); padding: 6px 6px 6px 20px; border-radius: 100px; border: 1px solid var(--glass-border); display: flex; align-items: center; gap: 12px; }

        .student-box { background: white; padding: 25px; border-radius: 25px; margin-bottom: 30px; border-left: 6px solid var(--accent); }
        .form-select-ae { 
            border: 2px solid transparent; padding: 18px; border-radius: 20px; 
            width: 100%; font-weight: 600; outline: none; background: white; transition: 0.3s;
        }
        .form-select-ae:focus { border-color: var(--accent); }
        .btn-ae {
            background: var(--accent); color: white; border: none; padding: 18px;
            border-radius: 100px; font-weight: 700; width: 100%; margin-top: 20px;
            transition: 0.3s; box-shadow: 0 10px 20px rgba(129, 140, 248, 0.2);
        }
        .btn-ae:hover { transform: translateY(-3px); filter: brightness(1.1); }

        @media (max-width: 991px) {
            body { flex-direction: column; }
            .sidebar { position: fixed; bottom: 15px; top: auto; left: 15px; right: 15px; width: auto; height: 70px; margin: 0; flex-direction: row; justify-content: space-around; padding: 0 15px; border-radius: 25px; }
            .sidebar .logo-aesthetic, .sidebar .mt-auto, .sidebar span { display: none; }
            .sidebar nav { display: flex; width: 100%; justify-content: space-around; }
            .main-wrapper { padding: 20px 20px 100px 20px; }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="logo-aesthetic d-none d-lg-flex" style="color: var(--accent); font-size: 1.5rem; margin-bottom: 30px;">
            <img src="../assets/img/logo_unior1.png" width="45" alt="logo">
        </div>
        <nav style="width: 100%;">
            <a href="menu.php" class="nav-item-ae">
                <i class="fas fa-home-alt"></i> <span>Menú</span>
            </a>
            <a href="lisra_estudiantes.php" class="nav-item-ae">
                <i class="fas fa-users-rays"></i> <span>Estudiantes</span>
            </a>
            <a href="registro_tutores.php" class="nav-item-ae">
                <i class="fas fa-user-tie"></i> <span>Registrar Tutor</span>
            </a>
            <a href="lista_tutores.php" class="nav-item-ae active">
                <i class="fas fa-fingerprint"></i> <span>Lista Tutores</span>
            </a>
            <a href="predefensas.php" class="nav-item-ae">
                <i class="fas fa-signature"></i> <span>Predefensas</span>
            </a>
        </nav>
        <a href="../controllers/logout.php" class="nav-item-ae text-danger mt-auto d-none d-lg-flex">
            <i class="fas fa-power-off"></i> <span>Salir</span>
        </a>
    </aside>

    <main class="main-wrapper">
        <div class="user-header">
            <div class="user-pill">
                <div class="text-end d-none d-sm-block">
                    <div class="fw-bold" style="font-size: 12px;"><?= $nombre_usuario ?></div>
                    <div class="text-muted" style="font-size: 9px; letter-spacing: 1px;">ADMINISTRADOR</div>
                </div>
                <div style="width: 35px; height: 35px; border-radius: 50%; background: white; color: var(--accent); display: flex; align-items: center; justify-content: center; font-weight: 800; border: 2px solid var(--accent);">
                    <?= $inicial ?>
                </div>
            </div>
        </div>

        <header>
            <p class="text-muted fw-600 mb-0" style="letter-spacing: 3px; font-size: 0.8rem;">ACTUALIZAR DATOS</p>
            <h1 class="hero-title">Editar<br><span style="font-weight: 800; color: var(--accent);">Asignación.</span></h1>
        </header>

        <div class="cloud-card">
            <div class="student-box">
                <small class="text-uppercase fw-bold text-muted d-block mb-1" style="font-size: 10px;">Estudiante</small>
                <h4 class="mb-0 fw-bold"><?= htmlspecialchars($datos['primer_apellido'] . " " . $datos['primer_nombre']) ?></h4>
                <span class="badge bg-light text-dark mt-2" style="border-radius: 8px;">R.U. <?= htmlspecialchars($datos['ru']) ?></span>
            </div>

            <form id="formUpdate">
                <input type="hidden" name="id_asignacion" value="<?= $datos['id_asignacion'] ?>">
                
                <label class="fw-bold text-muted mb-3 ms-2" style="font-size: 11px; letter-spacing: 1px;">CAMBIAR TUTOR ACADÉMICO</label>
                <select name="id_tutor" class="form-select-ae mb-4 shadow-sm" required>
                    <?php foreach($tutores as $t): ?>
                        <option value="<?= $t['id_persona'] ?>" 
                            <?= ($t['id_persona'] == $datos['id_docente']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['primer_apellido'] . " " . $t['primer_nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" id="btnSubmit" class="btn-ae">
                    <i class="fas fa-save me-2"></i> GUARDAR CAMBIOS
                </button>
                <div class="text-center mt-3">
                    <a href="lista_tutores.php" class="text-muted small fw-600 text-decoration-none">Cancelar y regresar</a>
                </div>
            </form>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        $('#formUpdate').on('submit', function(e) {
            e.preventDefault();
            const btn = $('#btnSubmit');
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Guardando...');

            $.post('', $(this).serialize(), function(res) {
                if(res.status === 'success') {
                    Swal.fire({ 
                        icon: 'success', 
                        title: '¡Hecho!', 
                        text: res.message, 
                        confirmButtonColor: '#818cf8',
                        confirmButtonText: 'Genial'
                    }).then(() => { 
                        window.location.href = 'lista_tutores.php'; 
                    });
                } else {
                    Swal.fire('Error', res.message, 'error');
                    btn.prop('disabled', false).html('<i class="fas fa-save me-2"></i> GUARDAR CAMBIOS');
                }
            }, 'json');
        });
    </script>
</body>
</html>