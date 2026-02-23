<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

require_once '../config/conexion.php';
require_once '../models/menumodelo.php'; 

$nombre_usuario = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$rol = htmlspecialchars((string)($_SESSION["role"] ?? 'Invitado'), ENT_QUOTES, 'UTF-8');
$inicial = strtoupper(mb_substr($nombre_usuario, 0, 1, 'UTF-8'));
$es_admin = (isset($_SESSION["role"]) && strtoupper($_SESSION["role"]) === 'ADMINISTRADOR');

// IMAGEN DEL BANNER (Alta Nitidez)
$banner_url = "https://th.bing.com/th/id/OIP.fGbv34hHN0EA_eJ2Mm9NqwHaCv?w=331&h=129&c=7&r=0&o=7&dpr=1.3&pid=1.7&rm=3";
// LOGO UNIOR (Favicon SVG para máxima calidad)
$ruta_logo = "https://unior.edu.bo/favicon.svg";

try {
    $dashboard = new DashboardModel($pdo); 
    $stats = $dashboard->getCounters(); 
    $totalEst  = $stats['estudiantes'];
    $totalTut  = $stats['tutores'];
    $totalAsig = $stats['asignaciones'];
} catch (Exception $e) {
    $totalEst = $totalTut = $totalAsig = "0";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>UNIOR | Elite Management</title>
    
    <link rel="icon" type="image/svg+xml" href="<?= $ruta_logo ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        :root {
            --primary: #4f46e5;
            --accent: #6366f1;
            --sidebar-w: 280px;
            --sidebar-c: 85px;
            --bg-body: #f8fafc;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-body);
            margin: 0; display: flex; min-height: 100vh; overflow-x: hidden;
        }

        /* ============== SIDEBAR ELITE RESPONSIVO ============== */
        .sidebar {
            width: var(--sidebar-c);
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border-right: 1px solid rgba(0,0,0,0.05);
            height: 100vh; position: fixed; left: 0; top: 0;
            display: flex; flex-direction: column; padding: 25px 15px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 2000;
        }

        @media (min-width: 992px) {
            .sidebar:hover { width: var(--sidebar-w); box-shadow: 20px 0 60px rgba(0,0,0,0.06); }
            .sidebar:hover .logo-text, .sidebar:hover .nav-item-ae span { opacity: 1; margin-left: 12px; }
            .sidebar:hover .logo-aesthetic img { transform: rotate(360deg); }
        }

        .logo-aesthetic {
            display: flex; align-items: center; gap: 15px; padding: 10px; margin-bottom: 40px; text-decoration: none;
        }

        .logo-aesthetic img { width: 48px; height: 48px; object-fit: contain; transition: 0.6s; }
        .logo-text { font-weight: 800; font-size: 1.6rem; color: var(--primary); opacity: 0; transition: 0.3s; white-space: nowrap; }

        nav { display: flex; flex-direction: column; gap: 10px; flex: 1; }

        .nav-item-ae {
            display: flex; align-items: center; padding: 15px; border-radius: 20px;
            color: #64748b; text-decoration: none; font-weight: 600; transition: 0.3s;
        }
        .nav-item-ae i { font-size: 1.3rem; min-width: 45px; text-align: center; }
        .nav-item-ae span { opacity: 0; transition: 0.3s; white-space: nowrap; }

        .nav-item-ae:hover, .nav-item-ae.active { background: white; color: var(--primary); transform: translateX(5px); }
        .nav-item-ae.active { background: var(--primary) !important; color: white !important; box-shadow: 0 10px 25px rgba(79, 70, 229, 0.2); }

        /* ============== CONTENIDO ============== */
        .main-wrapper { 
            flex: 1; margin-left: var(--sidebar-c); 
            padding: 40px; transition: 0.4s; width: 100%; 
        }

        /* BANNER NITIDO (CSS OPTIMIZADO) */
        .hero-banner {
            width: 100%; height: 260px; border-radius: 45px;
            background: linear-gradient(to right, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0.1) 100%), 
                        url('<?= $banner_url ?>');
            background-size: cover; /* Garantiza nitidez total */
            background-position: center;
            background-repeat: no-repeat;
            margin-bottom: 45px; box-shadow: 0 30px 60px rgba(0,0,0,0.12);
            display: flex; align-items: center; padding: 50px; color: white;
            border: 1px solid rgba(255,255,255,0.2);
        }

        /* TARJETAS ELITE */
        .stat-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 30px;
        }

        .glass-card {
            background: white; border-radius: 40px; padding: 50px 30px; text-align: center;
            border: 1px solid #f1f5f9; transition: 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .glass-card:hover { transform: translateY(-15px); box-shadow: 0 30px 60px rgba(0,0,0,0.06); border-color: var(--primary-light); }
        
        .stat-icon {
            font-size: 2rem; color: var(--primary); margin-bottom: 20px; 
            background: #f1f5f9; width: 70px; height: 70px; border-radius: 20px;
            display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;
        }

        .stat-num { font-size: 4.5rem; font-weight: 800; color: #1e293b; margin: 0; letter-spacing: -3px; line-height: 1; }
        .stat-label { font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 2px; font-size: 0.8rem; margin-top: 10px; }

        /* ============== MOBILE RESPONSIVE ============== */
        @media (max-width: 991px) {
            .sidebar {
                width: 100%; height: 75px; top: auto; bottom: 0;
                flex-direction: row; padding: 0 10px; border-right: none;
                border-top: 1px solid #e2e8f0; border-radius: 25px 25px 0 0;
            }
            .logo-aesthetic, .nav-item-ae span { display: none; }
            nav { flex-direction: row; justify-content: space-around; width: 100%; align-items: center; }
            .nav-item-ae { padding: 12px; margin: 0; }
            .main-wrapper { margin-left: 0; padding: 20px 20px 100px; }
            .hero-banner { height: 180px; padding: 25px; border-radius: 30px; }
            .stat-num { font-size: 3rem; }
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
                    <div class="fw-bold small" style="color: #1e293b;"><?= $nombre_usuario ?></div>
                    <div class="text-muted fw-bold" style="font-size: 9px; text-transform: uppercase;"><?= $rol ?></div>
                </div>
                <div style="width: 38px; height: 38px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1rem;"><?= $inicial ?></div>
            </div>
        </div>

        <div class="hero-banner">
            <div>
                <h1 class="fw-800" style="font-size: clamp(1.8rem, 5vw, 3.8rem); letter-spacing: -2px; margin: 0; line-height: 1;">Universidad Privada de Oruro</h1>
                <p class="m-0 fs-5 mt-2 opacity-75 fw-600">Gestión de Titulación y Postgrado • UNIOR</p>
            </div>
        </div>

        <div class="stat-grid">
            <div class="glass-card">
                <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                <p class="stat-num counter"><?= $totalEst ?></p>
                <span class="stat-label">Comunidad Estudiantil</span>
            </div>
            <div class="glass-card">
                <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                <p class="stat-num counter"><?= $totalTut ?></p>
                <span class="stat-label">Cuerpo de Tutores</span>
            </div>
            <div class="glass-card">
                <div class="stat-icon"><i class="fas fa-tasks"></i></div>
                <p class="stat-num counter"><?= $totalAsig ?></p>
                <span class="stat-label">Asignaciones Activas</span>
            </div>
        </div>

        <div class="text-center mt-5">
            <a href="registro_estudiantes.php" class="btn btn-primary btn-lg rounded-pill px-5 fw-bold shadow-lg border-0" style="background: var(--primary); padding: 20px 50px; font-size: 1.1rem;">
                <i class="fas fa-plus-circle me-2"></i> Nuevo Estudiante
            </a>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Animación de números suave
        document.querySelectorAll('.counter').forEach(counter => {
            const target = +counter.innerText;
            if (isNaN(target) || target === 0) return;
            let count = 0;
            const updateCount = () => {
                const inc = target / 70;
                if (count < target) {
                    count += inc;
                    counter.innerText = Math.ceil(count);
                    setTimeout(updateCount, 15);
                } else { counter.innerText = target; }
            };
            updateCount();
        });
    </script>
</body>
</html>