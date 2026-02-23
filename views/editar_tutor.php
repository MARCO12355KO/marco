<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

require_once '../config/conexion.php';

$id_persona = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id_persona === 0) {
    header("Location: lista_tutores.php");
    exit();
}

// --- LÓGICA DE ACTUALIZACIÓN (PHP) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update'])) {
    header('Content-Type: application/json');
    try {
        $pdo->beginTransaction();

        // 1. Actualizar tabla personas
        $sqlPersona = "UPDATE public.personas 
                       SET ci = :ci, 
                           primer_nombre = :nom, 
                           primer_apellido = :ape1, 
                           segundo_apellido = :ape2, 
                           celular = :cel 
                       WHERE id_persona = :id";
        
        $stmtP = $pdo->prepare($sqlPersona);
        $stmtP->execute([
            ':ci'   => $_POST['ci'],
            ':nom'  => $_POST['nombres'],
            ':ape1' => $_POST['apellido_p'],
            ':ape2' => $_POST['apellido_m'] ?: null,
            ':cel'  => $_POST['celular'],
            ':id'   => $_POST['id_persona']
        ]);

        // 2. Actualizar tabla docentes
        $sqlDocente = "UPDATE public.docentes 
                       SET id_carrera = :carrera, 
                           especialidad = :esp 
                       WHERE id_persona = :id";
        
        $stmtD = $pdo->prepare($sqlDocente);
        $stmtD->execute([
            ':carrera' => $_POST['id_carrera'],
            ':esp'     => $_POST['especialidad'] ?? 'General',
            ':id'      => $_POST['id_persona']
        ]);

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Tutor actualizado correctamente']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// --- CARGA DE DATOS ---
$rol_usuario = $_SESSION["role"] ?? 'Invitado';
$nombre_usuario = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$inicial = strtoupper(substr($nombre_usuario, 0, 1));

try {
    // Obtener datos del tutor
    $sql = "SELECT p.*, d.id_carrera, d.especialidad 
            FROM public.personas p 
            INNER JOIN public.docentes d ON p.id_persona = d.id_persona 
            WHERE p.id_persona = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_persona]);
    $tutor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tutor) {
        header("Location: lista_tutores.php");
        exit();
    }

    // Obtener lista de carreras
    $stmtCarreras = $pdo->query("SELECT id_carrera, nombre_carrera FROM public.carreras ORDER BY nombre_carrera ASC");
    $carreras = $stmtCarreras->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error crítico: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Tutor - UNIOR</title>
    <link rel="icon" type="image/png" href="../assets/img/logo_unior1.png">
    
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@300;500;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --glass: rgba(255, 255, 255, 0.75);
            --glass-border: rgba(255, 255, 255, 0.9);
            --accent: #6366f1;
            --accent-light: #818cf8;
            --accent-dark: #4f46e5;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --bg-main: #f8fafc;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.04);
            --shadow-md: 0 8px 24px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 20px 40px rgba(0, 0, 0, 0.08);
            --ease: cubic-bezier(0.4, 0, 0.2, 1);
            --sidebar-closed: 85px;
            --sidebar-open: 280px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--bg-main);
            background-image: 
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.1) 0, transparent 50%),
                radial-gradient(at 100% 100%, rgba(129, 140, 248, 0.08) 0, transparent 50%);
            min-height: 100vh;
            color: var(--text-dark);
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
        }

        /* ============== SIDEBAR ============== */
        .sidebar {
            background: var(--glass);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1.5px solid var(--glass-border);
            z-index: 2000;
            transition: all 0.5s var(--ease);
            box-shadow: var(--shadow-lg);
        }

        @media (min-width: 992px) {
            .sidebar {
                width: var(--sidebar-closed);
                height: 95vh;
                position: fixed;
                left: 24px;
                top: 2.5vh;
                border-radius: 32px;
                display: flex;
                flex-direction: column;
                align-items: center;
                padding: 32px 0;
            }
            .sidebar:hover { 
                width: var(--sidebar-open); 
                align-items: flex-start; 
                padding: 32px 24px;
                background: rgba(255, 255, 255, 0.85);
            }
            .sidebar:hover .nav-item-ae span { 
                opacity: 1; 
                display: inline; 
                transform: translateX(0);
            }
            .sidebar:hover .logo-text {
                opacity: 1;
                width: auto;
                margin-left: 12px;
            }
            .main-stage { 
                margin-left: calc(var(--sidebar-closed) + 48px); 
                padding: 48px 80px 48px 48px;
                width: calc(100% - var(--sidebar-closed) - 48px);
            }
            .mobile-top-bar { display: none; }
        }

        @media (max-width: 991px) {
            .sidebar {
                position: fixed;
                bottom: 20px;
                left: 20px;
                right: 20px;
                height: 75px;
                width: auto;
                border-radius: 28px;
                flex-direction: row;
                justify-content: space-around;
                display: flex;
                align-items: center;
                padding: 0 20px;
            }
            .sidebar .logo-aesthetic, 
            .sidebar .nav-item-ae span, 
            .sidebar .mt-auto,
            .sidebar .logo-text { 
                display: none; 
            }
            .sidebar nav { 
                display: flex; 
                width: 100%; 
                justify-content: space-around;
                align-items: center;
            }
            .nav-item-ae { 
                width: auto; 
                padding: 14px; 
                margin-bottom: 0;
            }
            .main-stage { 
                padding: 90px 20px 120px 20px; 
            }
            .mobile-top-bar {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                height: 70px;
                background: var(--glass);
                backdrop-filter: blur(15px);
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 0 20px;
                z-index: 1500;
                border-bottom: 1.5px solid var(--glass-border);
                box-shadow: var(--shadow-md);
            }
        }

        .logo-aesthetic {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 40px;
            padding: 0 12px;
        }

        .logo-text {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-weight: 800;
            font-size: 1.4rem;
            color: var(--accent);
            opacity: 0;
            width: 0;
            overflow: hidden;
            white-space: nowrap;
            transition: all 0.4s var(--ease);
        }

        .nav-item-ae {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px 18px;
            margin-bottom: 6px;
            border-radius: 20px;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.3s var(--ease);
            position: relative;
        }

        @media (min-width: 992px) {
            .sidebar:hover .nav-item-ae { 
                justify-content: flex-start; 
            }
        }

        .nav-item-ae i { 
            font-size: 1.25rem; 
            min-width: 50px; 
            text-align: center;
            transition: all 0.3s var(--ease);
        }

        .nav-item-ae span { 
            display: none; 
            opacity: 0; 
            font-weight: 600; 
            font-size: 0.95rem;
            margin-left: 10px; 
            transition: all 0.3s var(--ease); 
            white-space: nowrap;
            transform: translateX(-10px);
        }

        .nav-item-ae:hover { 
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(129, 140, 248, 0.05));
            color: var(--accent);
            transform: translateX(4px);
        }

        .nav-item-ae.active { 
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            box-shadow: 0 8px 16px rgba(99, 102, 241, 0.3);
        }

        .nav-item-ae.text-danger:hover { 
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05));
            color: var(--danger);
        }

        /* ============== USER BADGE ============== */
        .user-badge {
            background: var(--glass);
            backdrop-filter: blur(10px);
            padding: 8px 10px 8px 24px;
            border-radius: 100px;
            border: 1.5px solid var(--glass-border);
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: var(--shadow-md);
            transition: all 0.3s var(--ease);
        }

        .user-badge:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        /* ============== CONTENT ============== */
        .section-title {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: clamp(2.5rem, 8vw, 4.5rem);
            font-weight: 300;
            letter-spacing: -2px;
            margin-bottom: 12px;
            line-height: 0.95;
            background: linear-gradient(135deg, var(--text-dark) 0%, var(--text-muted) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .section-accent {
            font-weight: 800;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .breadcrumb-custom {
            background: rgba(99, 102, 241, 0.05);
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 32px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .breadcrumb-custom a {
            color: var(--accent);
            text-decoration: none;
        }

        .cloud-card {
            background: var(--glass);
            backdrop-filter: blur(15px);
            border: 1.5px solid var(--glass-border);
            border-radius: 35px;
            padding: clamp(30px, 5vw, 50px);
            box-shadow: var(--shadow-lg);
            max-width: 1000px;
            margin: 0 auto;
        }

        /* ============== FORM ============== */
        .form-section {
            margin-bottom: 32px;
            padding-bottom: 32px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        }

        .form-section:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .form-section-title {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-section-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(129, 140, 248, 0.15));
            color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .form-label {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin-bottom: 8px;
            margin-left: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .required-mark {
            color: var(--danger);
            font-size: 0.7rem;
        }

        .form-control, .form-select {
            padding: 14px 18px 14px 45px;
            border-radius: 16px;
            border: 2px solid rgba(0, 0, 0, 0.08);
            background: rgba(255, 255, 255, 0.95);
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--text-dark);
            transition: all 0.3s var(--ease);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            background: white;
            outline: none;
        }

        .form-control.is-invalid, .form-select.is-invalid {
            border-color: var(--danger);
            background: rgba(239, 68, 68, 0.05);
        }

        .form-control.is-valid, .form-select.is-valid {
            border-color: var(--success);
            background: rgba(16, 185, 129, 0.05);
        }

        .invalid-feedback, .valid-feedback {
            font-size: 0.8rem;
            font-weight: 500;
            margin-top: 8px;
            margin-left: 4px;
            display: none;
        }

        .form-control.is-invalid ~ .invalid-feedback,
        .form-select.is-invalid ~ .invalid-feedback {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--danger);
        }

        .form-control.is-valid ~ .valid-feedback,
        .form-select.is-valid ~ .valid-feedback {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--success);
        }

        .input-group-icon {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.1rem;
            pointer-events: none;
            z-index: 10;
        }

        .form-hint {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 6px;
            margin-left: 4px;
            display: flex;
            align-items: start;
            gap: 6px;
        }

        .btn-ae {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            border: none;
            padding: 18px 32px;
            border-radius: 16px;
            font-weight: 700;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s var(--ease);
            width: 100%;
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-ae:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 12px 28px rgba(99, 102, 241, 0.4);
        }

        .btn-ae:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-secondary-ae {
            background: white;
            color: var(--text-dark);
            border: 2px solid rgba(0, 0, 0, 0.1);
            box-shadow: var(--shadow-sm);
        }

        .btn-secondary-ae:hover {
            background: var(--bg-main);
            border-color: var(--accent);
            color: var(--accent);
        }

        .reveal {
            animation: fadeInUp 0.8s var(--ease) forwards;
            opacity: 0;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 768px) {
            .cloud-card {
                padding: 24px;
            }
            .section-title {
                font-size: 2.5rem;
            }
        }
    </style>
</head>
<body>

    <!-- MOBILE TOP BAR -->
    <div class="mobile-top-bar">
        <div class="d-flex align-items-center">
            <img src="../assets/img/logo_unior1.png" height="40" alt="Logo">
            <span class="ms-2 fw-800" style="font-family: 'Bricolage Grotesque'; color: var(--accent);">UNIOR</span>
        </div>
        <div class="user-badge">
            <div class="user-avatar">
                <?= $inicial ?>
            </div>
        </div>
    </div>

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="logo-aesthetic d-none d-lg-flex">
            <img src="../assets/img/logo_unior1.png" width="48" alt="Logo">
            <span class="logo-text">UNIOR</span>
        </div>
        <nav>
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

    <!-- MAIN CONTENT -->
    <main class="main-stage">
        <!-- USER BADGE DESKTOP -->
        <div class="d-none d-lg-flex justify-content-end mb-4 reveal">
            <div class="user-badge">
                <div class="text-end">
                    <div class="fw-bold" style="font-size: 0.9rem;"><?= htmlspecialchars($nombre_usuario) ?></div>
                    <div style="font-size: 0.7rem; color: var(--text-muted); letter-spacing: 1px; text-transform: uppercase; font-weight: 600;">
                        <?= strtoupper($rol_usuario) ?>
                    </div>
                </div>
                <div class="user-avatar">
                    <?= $inicial ?>
                </div>
            </div>
        </div>

        <!-- BREADCRUMB -->
        <div class="reveal">
            <nav class="breadcrumb-custom">
                <a href="menu.php">
                    <i class="fas fa-home"></i> Inicio
                </a>
                <i class="fas fa-chevron-right" style="font-size: 0.7rem; color: var(--text-muted);"></i>
                <a href="lista_tutores.php">Tutores</a>
                <i class="fas fa-chevron-right" style="font-size: 0.7rem; color: var(--text-muted);"></i>
                <span>Editar</span>
            </nav>
        </div>

        <!-- HEADER -->
        <header class="reveal" style="animation-delay: 0.1s;">
            <p class="text-muted fw-600 mb-2" style="letter-spacing: 2px; font-size: 0.7rem; text-transform: uppercase;">
                <i class="fas fa-user-edit me-2"></i>Actualizar Información
            </p>
            <h1 class="section-title">
                Editar <span class="section-accent">Tutor Académico.</span>
            </h1>
        </header>

        <!-- FORM -->
        <div class="cloud-card reveal" style="animation-delay: 0.2s;">
            <form id="formEditarTutor" novalidate>
                <input type="hidden" name="ajax_update" value="1">
                <input type="hidden" name="id_persona" value="<?= $tutor['id_persona'] ?>">

                <!-- SECCIÓN: DATOS PERSONALES -->
                <div class="form-section">
                    <div class="form-section-title">
                        <div class="form-section-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        Datos Personales
                    </div>
                    
                    <div class="row g-4">
                        <div class="col-md-4">
                            <label class="form-label">
                                Apellido Paterno <span class="required-mark">*</span>
                            </label>
                            <div class="input-group-icon">
                                <input 
                                    type="text" 
                                    name="apellido_p" 
                                    id="apellido_p"
                                    class="form-control" 
                                    placeholder="Ej. Pérez"
                                    value="<?= htmlspecialchars($tutor['primer_apellido']) ?>"
                                    required
                                    minlength="2"
                                    maxlength="50"
                                    pattern="^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$"
                                >
                                <i class="fas fa-user-tag input-icon"></i>
                                <div class="invalid-feedback">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Por favor ingrese un apellido válido (solo letras)</span>
                                </div>
                                <div class="valid-feedback">
                                    <i class="fas fa-check-circle"></i>
                                    Apellido válido
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                Apellido Materno
                            </label>
                            <div class="input-group-icon">
                                <input 
                                    type="text" 
                                    name="apellido_m" 
                                    id="apellido_m"
                                    class="form-control" 
                                    placeholder="Ej. González"
                                    value="<?= htmlspecialchars($tutor['segundo_apellido']) ?>"
                                    pattern="^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$"
                                    maxlength="50"
                                >
                                <i class="fas fa-user-tag input-icon"></i>
                                <div class="invalid-feedback">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Formato de apellido no válido</span>
                                </div>
                                <div class="valid-feedback">
                                    <i class="fas fa-check-circle"></i>
                                    Apellido válido
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                Nombres <span class="required-mark">*</span>
                            </label>
                            <div class="input-group-icon">
                                <input 
                                    type="text" 
                                    name="nombres" 
                                    id="nombres"
                                    class="form-control" 
                                    placeholder="Ej. Juan Carlos"
                                    value="<?= htmlspecialchars($tutor['primer_nombre']) ?>"
                                    required
                                    minlength="3"
                                    maxlength="50"
                                    pattern="^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$"
                                >
                                <i class="fas fa-user input-icon"></i>
                                <div class="invalid-feedback">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Nombre obligatorio (solo letras, mín. 3)</span>
                                </div>
                                <div class="valid-feedback">
                                    <i class="fas fa-check-circle"></i>
                                    Nombre válido
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECCIÓN: IDENTIFICACIÓN Y CONTACTO -->
                <div class="form-section">
                    <div class="form-section-title">
                        <div class="form-section-icon">
                            <i class="fas fa-id-card"></i>
                        </div>
                        Identificación y Contacto
                    </div>
                    
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label">
                                Cédula de Identidad (CI) <span class="required-mark">*</span>
                            </label>
                            <div class="input-group-icon">
                                <input 
                                    type="text" 
                                    name="ci" 
                                    id="ci"
                                    class="form-control" 
                                    placeholder="Ej. 12345678"
                                    value="<?= htmlspecialchars($tutor['ci']) ?>"
                                    required
                                    pattern="^[0-9]{5,10}$"
                                    maxlength="10"
                                >
                                <i class="fas fa-id-badge input-icon"></i>
                                <div class="form-hint">
                                    <i class="fas fa-info-circle"></i>
                                    <span>Ingrese solo números, entre 5 y 10 dígitos</span>
                                </div>
                                <div class="invalid-feedback">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>CI debe tener entre 5 y 10 dígitos numéricos</span>
                                </div>
                                <div class="valid-feedback">
                                    <i class="fas fa-check-circle"></i>
                                    CI válida
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                Número de Celular <span class="required-mark">*</span>
                            </label>
                            <div class="input-group-icon">
                                <input 
                                    type="tel" 
                                    name="celular" 
                                    id="celular"
                                    class="form-control" 
                                    placeholder="Ej. 70000000"
                                    value="<?= htmlspecialchars($tutor['celular']) ?>"
                                    required
                                    pattern="^[67][0-9]{7}$"
                                    maxlength="8"
                                >
                                <i class="fas fa-mobile-alt input-icon"></i>
                                <div class="form-hint">
                                    <i class="fas fa-info-circle"></i>
                                    <span>8 dígitos, debe comenzar con 6 o 7</span>
                                </div>
                                <div class="invalid-feedback">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Número de celular boliviano inválido</span>
                                </div>
                                <div class="valid-feedback">
                                    <i class="fas fa-check-circle"></i>
                                    Celular válido
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECCIÓN: INFORMACIÓN ACADÉMICA -->
                <div class="form-section">
                    <div class="form-section-title">
                        <div class="form-section-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        Información Académica
                    </div>
                    
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label">
                                Carrera <span class="required-mark">*</span>
                            </label>
                            <div class="input-group-icon">
                                <select name="id_carrera" id="id_carrera" class="form-select" required style="padding-left: 45px;">
                                    <option value="" disabled>Seleccione una carrera...</option>
                                    <?php foreach($carreras as $c): ?>
                                        <option value="<?= $c['id_carrera'] ?>" <?= $c['id_carrera'] == $tutor['id_carrera'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['nombre_carrera']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fas fa-book input-icon"></i>
                                <div class="invalid-feedback">
                                    <i class="fas fa-exclamation-circle"></i>
                                    Por favor seleccione una carrera
                                </div>
                                <div class="valid-feedback">
                                    <i class="fas fa-check-circle"></i>
                                    Carrera seleccionada
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6" id="div_especialidad" style="display:none;">
                            <label class="form-label">
                                Área de Especialidad <span class="required-mark">*</span>
                            </label>
                            <div class="input-group-icon">
                                <select name="especialidad" id="especialidad" class="form-select" style="padding-left: 45px;">
                                    <option value="">Seleccione área...</option>
                                    <option value="CIVIL" <?= $tutor['especialidad'] == 'CIVIL' ? 'selected' : '' ?>>Derecho Civil</option>
                                    <option value="PENAL" <?= $tutor['especialidad'] == 'PENAL' ? 'selected' : '' ?>>Derecho Penal</option>
                                    <option value="LABORAL" <?= $tutor['especialidad'] == 'LABORAL' ? 'selected' : '' ?>>Derecho Laboral</option>
                                    <option value="CONSTITUCIONAL" <?= $tutor['especialidad'] == 'CONSTITUCIONAL' ? 'selected' : '' ?>>Derecho Constitucional</option>
                                    <option value="ADMINISTRATIVO" <?= $tutor['especialidad'] == 'ADMINISTRATIVO' ? 'selected' : '' ?>>Derecho Administrativo</option>
                                </select>
                                <i class="fas fa-balance-scale input-icon"></i>
                                <div class="invalid-feedback">
                                    <i class="fas fa-exclamation-circle"></i>
                                    Por favor especifique el área legal
                                </div>
                                <div class="valid-feedback">
                                    <i class="fas fa-check-circle"></i>
                                    Especialidad seleccionada
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- BOTONES -->
                <div class="row g-3 mt-4">
                    <div class="col-md-6">
                        <button type="button" class="btn-ae btn-secondary-ae" onclick="window.location.href='lista_tutores.php'">
                            <i class="fas fa-arrow-left"></i>
                            Cancelar
                        </button>
                    </div>
                    <div class="col-md-6">
                        <button type="submit" class="btn-ae" id="btnActualizar">
                            <i class="fas fa-sync-alt"></i>
                            <span>Actualizar Tutor</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        $(document).ready(function() {
            const form = $('#formEditarTutor');
            const inputs = form.find('input[required], select[required]');

            // ============== FORMATEO AUTOMÁTICO ==============
            $('#ci').on('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });

            $('#celular').on('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });

            // Prevenir caracteres no permitidos en nombres
            $('#nombres, #apellido_p, #apellido_m').on('keypress', function(e) {
                if (!/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]$/.test(e.key)) e.preventDefault();
            });

            // Capitalizar nombres
            $('#nombres, #apellido_p, #apellido_m').on('blur', function() {
                const words = this.value.toLowerCase().split(' ');
                const capitalized = words.map(word => {
                    return word.charAt(0).toUpperCase() + word.slice(1);
                }).join(' ');
                this.value = capitalized;
            });

            // ============== MOSTRAR ESPECIALIDAD SOLO SI ES DERECHO ==============
            function checkEspecialidad() {
                const carreraText = $('#id_carrera').find('option:selected').text().toUpperCase();
                if (carreraText.includes('DERECHO')) {
                    $('#div_especialidad').fadeIn().find('select').attr('required', true);
                } else {
                    $('#div_especialidad').fadeOut().find('select').attr('required', false).val('');
                }
            }
            
            // Ejecutar al cargar
            checkEspecialidad();
            
            $('#id_carrera').on('change', checkEspecialidad);

            // ============== VALIDACIÓN SOLO AL ESCRIBIR ==============
            inputs.each(function() {
                $(this).on('input', function() {
                    if (this.value.trim().length > 0) {
                        clearTimeout(this.validationTimer);
                        this.validationTimer = setTimeout(() => {
                            validateField(this);
                        }, 300);
                    } else {
                        this.classList.remove('is-valid', 'is-invalid');
                    }
                });
            });

            function validateField(field) {
                field.classList.remove('is-valid', 'is-invalid');
                
                if (field.value.trim().length === 0) {
                    return true;
                }
                
                if (!field.validity.valid) {
                    field.classList.add('is-invalid');
                    return false;
                } else {
                    field.classList.add('is-valid');
                    return true;
                }
            }

            // ============== ENVÍO DEL FORMULARIO ==============
            form.on('submit', function(e) {
                e.preventDefault();
                
                // Validar todos los campos
                let isValid = true;
                inputs.each(function() {
                    if (!this.validity.valid) {
                        this.classList.add('is-invalid');
                        isValid = false;
                    } else if (this.value.trim().length > 0) {
                        this.classList.add('is-valid');
                    }
                });

                if (!isValid) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Formulario Incompleto',
                        text: 'Por favor complete todos los campos correctamente',
                        confirmButtonColor: '#6366f1',
                        confirmButtonText: 'Entendido'
                    });
                    
                    const firstInvalid = form.find('.is-invalid').first();
                    if (firstInvalid.length) {
                        firstInvalid[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                        firstInvalid.focus();
                    }
                    return;
                }

                // Confirmar actualización
                Swal.fire({
                    title: '¿Actualizar datos del tutor?',
                    text: 'Se modificará la información del tutor en el sistema',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#6366f1',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: 'Sí, actualizar',
                    cancelButtonText: 'Cancelar',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Deshabilitar botón
                        const btnActualizar = $('#btnActualizar');
                        const originalContent = btnActualizar.html();
                        btnActualizar.prop('disabled', true);
                        btnActualizar.html('<span class="spinner-border spinner-border-sm"></span> <span>Actualizando...</span>');

                        // Enviar por AJAX
                        $.ajax({
                            url: 'editar_tutor.php?id=<?= $id_persona ?>',
                            type: 'POST',
                            data: form.serialize(),
                            dataType: 'json',
                            success: function(response) {
                                if (response.status === 'success') {
                                    Swal.fire({
                                        icon: 'success',
                                        title: '¡Actualización Exitosa!',
                                        text: response.message,
                                        confirmButtonColor: '#10b981',
                                        confirmButtonText: 'Continuar',
                                        timer: 3000
                                    }).then(() => {
                                        window.location.href = 'lista_tutores.php';
                                    });
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error al Actualizar',
                                        text: response.message,
                                        confirmButtonColor: '#ef4444',
                                        confirmButtonText: 'Entendido'
                                    });
                                    btnActualizar.prop('disabled', false);
                                    btnActualizar.html(originalContent);
                                }
                            },
                            error: function() {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error de Conexión',
                                    text: 'No se pudo conectar con el servidor',
                                    confirmButtonColor: '#ef4444',
                                    confirmButtonText: 'Entendido'
                                });
                                btnActualizar.prop('disabled', false);
                                btnActualizar.html(originalContent);
                            }
                        });
                    }
                });
            });
        });
    </script>
</body>
</html>