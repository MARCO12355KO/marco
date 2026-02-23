<?php declare(strict_types=1);
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

require_once '../config/conexion.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { 
    header("Location: lista_tutores.php"); 
    exit(); 
}

$rol_usuario = $_SESSION["role"] ?? 'Invitado';
$es_admin = (strtolower($rol_usuario) === 'administrador');

try {
    // Obtener datos del tutor/docente
    $sql = "SELECT p.*, d.especialidad, d.es_tutor, d.es_tribunal, d.es_apto_tutoria, c.nombre_carrera
            FROM personas p 
            JOIN docentes d ON p.id_persona = d.id_persona 
            LEFT JOIN carreras c ON d.id_carrera = c.id_carrera 
            WHERE p.id_persona = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $tutor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tutor) { 
        die("Tutor no encontrado."); 
    }

    // Contar estudiantes asignados
    $sqlEstudiantes = "SELECT COUNT(*) as total FROM asignaciones_tutor WHERE id_docente = ? AND estado = 'ACTIVO'";
    $stmtEst = $pdo->prepare($sqlEstudiantes);
    $stmtEst->execute([$id]);
    $totalEstudiantes = $stmtEst->fetchColumn();

    // Obtener estudiantes asignados
    $sqlListaEst = "SELECT p.primer_nombre, p.primer_apellido, c.nombre_carrera, at.gestion
                    FROM asignaciones_tutor at
                    JOIN personas p ON at.id_estudiante = p.id_persona
                    JOIN estudiantes e ON p.id_persona = e.id_persona
                    JOIN carreras c ON e.id_carrera = c.id_carrera
                    WHERE at.id_docente = ? AND at.estado = 'ACTIVO'
                    ORDER BY p.primer_apellido ASC";
    $stmtListaEst = $pdo->prepare($sqlListaEst);
    $stmtListaEst->execute([$id]);
    $estudiantesAsignados = $stmtListaEst->fetchAll(PDO::FETCH_ASSOC);

    $nombre_completo = mb_convert_case(($tutor['primer_nombre'] ?? '') . " " . ($tutor['primer_apellido'] ?? ''), MB_CASE_TITLE, "UTF-8");
    $iniciales = strtoupper(substr($tutor['primer_nombre'] ?? 'T', 0, 1) . substr($tutor['primer_apellido'] ?? 'U', 0, 1));
    $nombre_admin = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
    $inicial_admin = strtoupper(substr($nombre_admin, 0, 1));

} catch (PDOException $e) {
    die("Error en el sistema: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil Tutor | <?= $tutor['primer_apellido'] ?> - UNIOR</title>
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
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --bg-main: #f8fafc;
            --shadow-lg: 0 20px 40px rgba(0, 0, 0, 0.08);
            --ease: cubic-bezier(0.4, 0, 0.2, 1);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: var(--bg-main);
            min-height: 100vh;
            color: var(--text-dark);
        }
        .profile-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border: 1.5px solid var(--glass-border);
            border-radius: 32px;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            margin: 48px auto;
            max-width: 1000px;
        }
        .profile-banner {
            height: 200px;
            background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
            position: relative;
        }
        .profile-avatar {
            width: 140px;
            height: 140px;
            border-radius: 28px;
            background: white;
            border: 6px solid white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: 3.5rem;
            font-weight: 800;
            color: #f59e0b;
            position: absolute;
            bottom: -70px;
            left: 40px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        .profile-body { padding: 90px 40px 40px 40px; }
        .profile-name {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: 3rem;
            font-weight: 800;
            margin-bottom: 12px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 32px 0;
        }
        .info-card {
            background: white;
            border: 2px solid rgba(0, 0, 0, 0.05);
            border-radius: 24px;
            padding: 28px;
        }
        .info-card:hover { transform: translateY(-4px); }
        .btn-ae {
            padding: 14px 32px;
            border-radius: 16px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .btn-primary-ae {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
        }
        .btn-secondary-ae {
            background: white;
            color: var(--text-dark);
            border: 2px solid rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body>
    <div class="profile-card">
        <div class="profile-banner">
            <div class="profile-avatar"><?= $iniciales ?></div>
        </div>
        <div class="profile-body">
            <h1 class="profile-name"><?= $nombre_completo ?></h1>
            <p style="color: var(--text-muted); margin-bottom: 32px;">
                <i class="fas fa-university me-2"></i>Universidad Privada de Oruro
            </p>
            <div class="info-grid">
                <div class="info-card">
                    <div style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px;">CI</div>
                    <div style="font-size: 1.3rem; font-weight: 700;"><?= htmlspecialchars($tutor['ci']) ?></div>
                </div>
                <div class="info-card">
                    <div style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px;">Especialidad</div>
                    <div style="font-size: 1.3rem; font-weight: 700;"><?= htmlspecialchars($tutor['especialidad'] ?? 'No especificada') ?></div>
                </div>
                <div class="info-card">
                    <div style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px;">Carrera</div>
                    <div style="font-size: 1.3rem; font-weight: 700;"><?= htmlspecialchars($tutor['nombre_carrera'] ?? 'No asignada') ?></div>
                </div>
                <div class="info-card">
                    <div style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px;">Estudiantes</div>
                    <div style="font-size: 1.3rem; font-weight: 700;"><?= $totalEstudiantes ?></div>
                </div>
            </div>
            <div style="display: flex; gap: 16px; margin-top: 32px;">
                <a href="lista_tutores.php" class="btn-ae btn-secondary-ae">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
                <?php if($es_admin): ?>
                <a href="editar_tutor.php?id=<?= $id ?>" class="btn-ae btn-primary-ae">
                    <i class="fas fa-edit"></i> Editar
                </a>
                <?php endif; ?>
            </div>
            <?php if (!empty($estudiantesAsignados)): ?>
            <div style="margin-top: 48px; padding: 28px; background: white; border-radius: 24px;">
                <h3 style="margin-bottom: 20px;">
                    <i class="fas fa-users me-2"></i>Estudiantes Asignados (<?= count($estudiantesAsignados) ?>)
                </h3>
                <?php foreach($estudiantesAsignados as $est): ?>
                <div style="padding: 16px; margin-bottom: 12px; background: #f8fafc; border-radius: 12px;">
                    <div style="font-weight: 700; margin-bottom: 8px;">
                        <?= htmlspecialchars($est['primer_apellido'] . ' ' . $est['primer_nombre']) ?>
                    </div>
                    <div style="font-size: 0.85rem; color: var(--text-muted);">
                        <i class="fas fa-book me-1"></i><?= htmlspecialchars($est['nombre_carrera']) ?>
                        <i class="fas fa-calendar ms-3 me-1"></i><?= htmlspecialchars($est['gestion']) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
