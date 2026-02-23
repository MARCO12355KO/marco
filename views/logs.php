<?php
declare(strict_types=1);
session_start();
require_once '../config/conexion.php';

// 1. Verificación de Sesión
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

// 2. Configuración de Roles - SOLO ADMIN PUEDE VER LOGS
$rol = strtolower($_SESSION["role"] ?? 'registro');
$es_admin = ($rol === 'administrador');

if (!$es_admin) {
    header("Location: menu.php");
    exit();
}

$nombre_usuario = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$inicial = strtoupper(substr($nombre_usuario, 0, 1));

// 3. Captura de Filtros con sanitización mejorada
$tabla_filtro = trim($_GET['tabla'] ?? '');
$accion_filtro = trim($_GET['accion'] ?? '');
$usuario_filtro = trim($_GET['usuario'] ?? '');
$fecha_desde = trim($_GET['fecha_desde'] ?? '');
$fecha_hasta = trim($_GET['fecha_hasta'] ?? '');
$limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 50; // Límite entre 1 y 500

$params = [];
$condiciones = [];

// Construir condiciones de filtrado
if (!empty($tabla_filtro)) {
    $condiciones[] = "l.tabla_afectada = ?";
    $params[] = $tabla_filtro;
}

if (!empty($accion_filtro) && in_array($accion_filtro, ['INSERT', 'UPDATE', 'DELETE'])) {
    $condiciones[] = "l.accion = ?";
    $params[] = $accion_filtro;
}

if (!empty($usuario_filtro) && is_numeric($usuario_filtro)) {
    $condiciones[] = "l.usuario_id = ?";
    $params[] = (int)$usuario_filtro;
}

if (!empty($fecha_desde) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)) {
    $condiciones[] = "l.fecha_hora >= ?";
    $params[] = $fecha_desde . ' 00:00:00';
}

if (!empty($fecha_hasta) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) {
    $condiciones[] = "l.fecha_hora <= ?";
    $params[] = $fecha_hasta . ' 23:59:59';
}

// 4. Consultas a la Base de Datos
try {
    // Obtener lista de tablas únicas (con conteo)
    $tablas_query = $pdo->query("
        SELECT tabla_afectada, COUNT(*) as total
        FROM logs_sistema 
        GROUP BY tabla_afectada
        ORDER BY tabla_afectada ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Obtener lista de acciones con conteo
    $acciones_query = $pdo->query("
        SELECT accion, COUNT(*) as total
        FROM logs_sistema 
        GROUP BY accion
        ORDER BY accion ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Obtener lista de usuarios que han realizado cambios
    $usuarios_query = $pdo->query("
        SELECT DISTINCT p.id_persona, p.primer_nombre, p.primer_apellido, COUNT(l.id_log) as total_acciones
        FROM logs_sistema l
        LEFT JOIN personas p ON l.usuario_id = p.id_persona
        WHERE l.usuario_id IS NOT NULL
        GROUP BY p.id_persona, p.primer_nombre, p.primer_apellido
        ORDER BY p.primer_apellido ASC, p.primer_nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Construir consulta principal con filtros
    $sql = "
        SELECT 
            l.*,
            p.primer_nombre,
            p.primer_apellido,
            p.ci
        FROM logs_sistema l
        LEFT JOIN personas p ON l.usuario_id = p.id_persona
    ";

    if (!empty($condiciones)) {
        $sql .= " WHERE " . implode(" AND ", $condiciones);
    }
    
    $sql .= " ORDER BY l.fecha_hora DESC LIMIT ?";
    $params[] = $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Estadísticas globales o filtradas
    $stats_sql = "SELECT 
        COUNT(*) as total,
        COUNT(DISTINCT tabla_afectada) as total_tablas,
        COUNT(DISTINCT usuario_id) as total_usuarios,
        SUM(CASE WHEN accion = 'INSERT' THEN 1 ELSE 0 END) as total_inserts,
        SUM(CASE WHEN accion = 'UPDATE' THEN 1 ELSE 0 END) as total_updates,
        SUM(CASE WHEN accion = 'DELETE' THEN 1 ELSE 0 END) as total_deletes
    FROM logs_sistema";
    
    if (!empty($condiciones)) {
        $stats_sql .= " WHERE " . implode(" AND ", $condiciones);
    }

    $stmt_stats = $pdo->prepare($stats_sql);
    $stmt_stats->execute(array_slice($params, 0, -1)); // Sin el limit
    $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error en logs.php: " . $e->getMessage());
    die("Error al cargar los logs del sistema. Por favor, contacte al administrador.");
}

// Función para formatear JSON de manera legible
function formatearCambios($valor_anterior, $valor_nuevo, $accion) {
    if ($accion === 'INSERT') {
        return json_decode($valor_nuevo, true);
    } elseif ($accion === 'DELETE') {
        return json_decode($valor_anterior, true);
    } else { // UPDATE
        $anterior = json_decode($valor_anterior, true);
        $nuevo = json_decode($valor_nuevo, true);
        
        if (!$anterior || !$nuevo) return null;
        
        $cambios = [];
        foreach ($nuevo as $key => $value) {
            if (!isset($anterior[$key]) || $anterior[$key] !== $value) {
                $cambios[$key] = [
                    'anterior' => $anterior[$key] ?? 'N/A',
                    'nuevo' => $value
                ];
            }
        }
        return $cambios;
    }
}

// Función para exportar logs a CSV
function exportarCSV($logs, $pdo) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="logs_sistema_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM para UTF-8
    
    // Encabezados
    fputcsv($output, ['ID', 'Fecha/Hora', 'Tabla', 'Acción', 'ID Registro', 'Usuario', 'CI', 'Cambios']);
    
    // Datos
    foreach ($logs as $log) {
        $usuario = $log['primer_apellido'] && $log['primer_nombre'] 
            ? $log['primer_apellido'] . ' ' . $log['primer_nombre']
            : 'Sistema';
        
        $cambios = '';
        if ($log['accion'] === 'UPDATE') {
            $anterior = json_decode($log['valor_anterior'], true);
            $nuevo = json_decode($log['valor_nuevo'], true);
            if ($anterior && $nuevo) {
                $cambios_arr = [];
                foreach ($nuevo as $key => $value) {
                    if (!isset($anterior[$key]) || $anterior[$key] !== $value) {
                        $cambios_arr[] = "$key: {$anterior[$key]} → $value";
                    }
                }
                $cambios = implode('; ', $cambios_arr);
            }
        }
        
        fputcsv($output, [
            $log['id_log'],
            $log['fecha_hora'],
            $log['tabla_afectada'],
            $log['accion'],
            $log['id_registro'],
            $usuario,
            $log['ci'] ?? '',
            $cambios
        ]);
    }
    
    fclose($output);
    exit;
}

// Manejar exportación
if (isset($_GET['exportar']) && $_GET['exportar'] === 'csv') {
    exportarCSV($logs, $pdo);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs del Sistema | UNIOR</title>
    
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
            --info: #06b6d4;
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

        /* ============== HEADER ============== */
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

        /* ============== STATS CARDS ============== */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border: 1.5px solid var(--glass-border);
            border-radius: 24px;
            padding: 24px;
            box-shadow: var(--shadow-md);
            transition: all 0.3s var(--ease);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            margin-bottom: 16px;
        }

        .stat-icon.total {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(129, 140, 248, 0.15));
            color: var(--accent);
        }

        .stat-icon.insert {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.15));
            color: var(--success);
        }

        .stat-icon.update {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.15));
            color: var(--warning);
        }

        .stat-icon.delete {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.15));
            color: var(--danger);
        }

        .stat-icon.users {
            background: linear-gradient(135deg, rgba(6, 182, 212, 0.1), rgba(6, 182, 212, 0.15));
            color: var(--info);
        }

        .stat-number {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1;
            color: var(--text-dark);
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 600;
        }

        /* ============== FILTROS ============== */
        .filter-section {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border: 1.5px solid var(--glass-border);
            border-radius: 24px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-md);
        }

        .search-input, .form-select {
            padding: 14px 20px;
            border-radius: 16px;
            border: 2px solid rgba(0, 0, 0, 0.08);
            background: white;
            transition: all 0.3s var(--ease);
            font-weight: 500;
        }

        .search-input:focus, .form-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            outline: none;
        }

        .btn-search {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 16px;
            font-weight: 700;
            transition: all 0.3s var(--ease);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .btn-search:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(99, 102, 241, 0.4);
        }

        .btn-reset {
            background: white;
            color: var(--text-muted);
            border: 2px solid rgba(0, 0, 0, 0.08);
            padding: 14px 28px;
            border-radius: 16px;
            font-weight: 700;
            transition: all 0.3s var(--ease);
        }

        .btn-reset:hover {
            border-color: var(--danger);
            color: var(--danger);
            transform: translateY(-2px);
        }

        .btn-export {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 16px;
            font-weight: 700;
            transition: all 0.3s var(--ease);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
        }

        /* ============== TABLA DE LOGS ============== */
        .cloud-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border: 1.5px solid var(--glass-border);
            border-radius: 28px;
            padding: 28px;
            box-shadow: var(--shadow-md);
        }

        .log-item {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 16px;
            border: 2px solid rgba(0, 0, 0, 0.04);
            transition: all 0.3s var(--ease);
        }

        .log-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.08);
            border-color: var(--accent);
        }

        .log-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 2px solid rgba(0, 0, 0, 0.04);
        }

        .log-action-badge {
            padding: 8px 20px;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .action-insert {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
        }

        .action-update {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #92400e;
        }

        .action-delete {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
        }

        .log-meta {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .log-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .log-meta-item i {
            font-size: 0.9rem;
            color: var(--accent);
        }

        .log-meta-item strong {
            color: var(--text-dark);
            font-weight: 600;
        }

        .log-changes {
            background: rgba(99, 102, 241, 0.03);
            border-radius: 12px;
            padding: 16px;
            margin-top: 12px;
        }

        .log-changes-title {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            font-weight: 700;
            margin-bottom: 12px;
        }

        .change-item {
            background: white;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 8px;
            border-left: 3px solid var(--accent);
        }

        .change-item:last-child {
            margin-bottom: 0;
        }

        .change-field {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--accent);
            font-weight: 700;
            margin-bottom: 6px;
        }

        .change-value {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.9rem;
        }

        .change-old {
            color: var(--danger);
            text-decoration: line-through;
            opacity: 0.7;
        }

        .change-new {
            color: var(--success);
            font-weight: 600;
        }

        .change-arrow {
            color: var(--text-muted);
            font-size: 0.8rem;
        }

        .json-view {
            background: #1e293b;
            color: #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            overflow-x: auto;
            max-height: 300px;
            overflow-y: auto;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-icon {
            font-size: 4rem;
            color: var(--text-muted);
            opacity: 0.3;
            margin-bottom: 20px;
        }

        .empty-text {
            color: var(--text-muted);
            font-size: 1.1rem;
            font-weight: 500;
        }

        /* ============== ANIMACIONES ============== */
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

        /* Quick Filters */
        .quick-filter {
            display: inline-block;
            padding: 8px 16px;
            background: white;
            border: 2px solid rgba(0, 0, 0, 0.08);
            border-radius: 100px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.3s var(--ease);
            margin: 4px;
        }

        .quick-filter:hover {
            border-color: var(--accent);
            color: var(--accent);
            transform: translateY(-2px);
        }

        .quick-filter.active {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            border-color: transparent;
        }

        /* ============== RESPONSIVE ============== */
        @media (max-width: 768px) {
            .section-title {
                font-size: 2.5rem;
            }

            .stats-container {
                grid-template-columns: 1fr 1fr;
            }

            .log-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .log-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .change-value {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }

            .main-stage {
                padding: 90px 15px 120px 15px !important;
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
                <i class="fas fa-user-tie"></i> <span>Tutores</span>
            </a>
            <a href="lista_tutores.php" class="nav-item-ae">
                <i class="fas fa-fingerprint"></i> <span>Lista Tutores</span>
            </a>
            <a href="predefensas.php" class="nav-item-ae">
                <i class="fas fa-signature"></i> <span>Predefensas</span>
            </a>
            <a href="logs.php" class="nav-item-ae active">
                <i class="bi bi-backpack4-fill"></i> <span>Logs</span>
            </a>
        </nav>
        <a href="../controllers/logout.php" class="nav-item-ae text-danger mt-auto d-none d-lg-flex">
            <i class="fas fa-power-off"></i> <span>Salir</span>
        </a>
    </aside>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-stage">
        <!-- USER BADGE DESKTOP -->
        <div class="d-none d-lg-flex justify-content-end mb-4 reveal">
            <div class="user-badge">
                <div class="text-end">
                    <div class="fw-bold" style="font-size: 0.9rem;"><?= htmlspecialchars($nombre_usuario) ?></div>
                    <div style="font-size: 0.7rem; color: var(--text-muted); letter-spacing: 1px; text-transform: uppercase; font-weight: 600;">
                        ADMINISTRADOR
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
                <span>Logs del Sistema</span>
            </nav>
        </div>

        <!-- HEADER -->
        <header class="reveal" style="animation-delay: 0.1s;">
            <div class="mb-4">
                <p class="text-muted fw-600 mb-2" style="letter-spacing: 2px; font-size: 0.7rem; text-transform: uppercase;">
                    <i class="fas fa-shield-halved me-2"></i>Auditoría y Seguridad
                </p>
                <h1 class="section-title">
                    Logs del <span class="section-accent">Sistema.</span>
                </h1>
                <p class="text-muted mt-3" style="font-size: 1rem;">
                    Registro completo de todas las operaciones realizadas en la base de datos
                </p>
            </div>
        </header>

        <!-- ESTADÍSTICAS -->
        <div class="stats-container reveal" style="animation-delay: 0.2s;">
            <div class="stat-card">
                <div class="stat-icon total">
                    <i class="fas fa-database"></i>
                </div>
                <div class="stat-number"><?= number_format($stats['total']) ?></div>
                <div class="stat-label">Total Operaciones</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon insert">
                    <i class="fas fa-plus"></i>
                </div>
                <div class="stat-number"><?= number_format($stats['total_inserts']) ?></div>
                <div class="stat-label">Inserciones</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon update">
                    <i class="fas fa-pen"></i>
                </div>
                <div class="stat-number"><?= number_format($stats['total_updates']) ?></div>
                <div class="stat-label">Actualizaciones</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon delete">
                    <i class="fas fa-trash"></i>
                </div>
                <div class="stat-number"><?= number_format($stats['total_deletes']) ?></div>
                <div class="stat-label">Eliminaciones</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon users">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-number"><?= $stats['total_usuarios'] ?></div>
                <div class="stat-label">Usuarios Activos</div>
            </div>
        </div>

        <!-- FILTROS RÁPIDOS -->
        <div class="reveal mb-3" style="animation-delay: 0.25s;">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="text-muted fw-600" style="font-size: 0.85rem;">
                    <i class="fas fa-filter me-1"></i> Filtros Rápidos:
                </span>
                <a href="?accion=INSERT" class="quick-filter <?= $accion_filtro === 'INSERT' ? 'active' : '' ?>">
                    <i class="fas fa-plus me-1"></i> Inserciones
                </a>
                <a href="?accion=UPDATE" class="quick-filter <?= $accion_filtro === 'UPDATE' ? 'active' : '' ?>">
                    <i class="fas fa-pen me-1"></i> Actualizaciones
                </a>
                <a href="?accion=DELETE" class="quick-filter <?= $accion_filtro === 'DELETE' ? 'active' : '' ?>">
                    <i class="fas fa-trash me-1"></i> Eliminaciones
                </a>
                <a href="?fecha_desde=<?= date('Y-m-d') ?>&fecha_hasta=<?= date('Y-m-d') ?>" class="quick-filter">
                    <i class="fas fa-calendar-day me-1"></i> Hoy
                </a>
            </div>
        </div>

        <!-- FILTROS AVANZADOS -->
        <div class="filter-section reveal" style="animation-delay: 0.3s;">
            <form method="GET" class="row g-3">
                <div class="col-12 mb-2">
                    <h5 style="font-weight: 700; color: var(--text-dark);">
                        <i class="fas fa-sliders me-2"></i>
                        Búsqueda Avanzada
                    </h5>
                </div>
                
                <div class="col-12 col-md-3">
                    <label class="form-label fw-600" style="font-size: 0.85rem; color: var(--text-muted);">
                        <i class="fas fa-table me-1"></i> Tabla Afectada
                    </label>
                    <select name="tabla" class="form-select">
                        <option value="">Todas las tablas</option>
                        <?php foreach($tablas_query as $tabla_info): ?>
                            <option value="<?= htmlspecialchars((string)$tabla_info['tabla_afectada']) ?>" 
                                    <?= $tabla_filtro === $tabla_info['tabla_afectada'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)$tabla_info['tabla_afectada']) ?> 
                                (<?= number_format($tabla_info['total']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-6 col-md-2">
                    <label class="form-label fw-600" style="font-size: 0.85rem; color: var(--text-muted);">
                        <i class="fas fa-bolt me-1"></i> Acción
                    </label>
                    <select name="accion" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach($acciones_query as $accion_info): ?>
                            <option value="<?= htmlspecialchars((string)$accion_info['accion']) ?>" 
                                    <?= $accion_filtro === $accion_info['accion'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)$accion_info['accion']) ?> 
                                (<?= number_format($accion_info['total']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label fw-600" style="font-size: 0.85rem; color: var(--text-muted);">
                        <i class="fas fa-user me-1"></i> Usuario
                    </label>
                    <select name="usuario" class="form-select">
                        <option value="">Todos los usuarios</option>
                        <?php foreach($usuarios_query as $usr): ?>
                            <option value="<?= $usr['id_persona'] ?>" 
                                    <?= $usuario_filtro == $usr['id_persona'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)($usr['primer_apellido'] . ' ' . $usr['primer_nombre'])) ?>
                                (<?= number_format($usr['total_acciones']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label fw-600" style="font-size: 0.85rem; color: var(--text-muted);">
                        <i class="fas fa-calendar me-1"></i> Desde
                    </label>
                    <input type="date" name="fecha_desde" class="form-control search-input" 
                           value="<?= htmlspecialchars((string)$fecha_desde) ?>"
                           max="<?= date('Y-m-d') ?>">
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label fw-600" style="font-size: 0.85rem; color: var(--text-muted);">
                        <i class="fas fa-calendar me-1"></i> Hasta
                    </label>
                    <input type="date" name="fecha_hasta" class="form-control search-input" 
                           value="<?= htmlspecialchars((string)$fecha_hasta) ?>"
                           max="<?= date('Y-m-d') ?>">
                </div>

                <div class="col-12">
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-search">
                            <i class="fas fa-search me-2"></i>
                            Aplicar Filtros
                        </button>
                        <a href="logs.php" class="btn btn-reset">
                            <i class="fas fa-times me-2"></i>
                            Limpiar Filtros
                        </a>
                        <?php if (!empty($logs)): ?>
                        <a href="?exportar=csv&<?= http_build_query($_GET) ?>" class="btn btn-export">
                            <i class="fas fa-download me-2"></i>
                            Exportar CSV
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <!-- LISTA DE LOGS -->
        <div class="cloud-card reveal" style="animation-delay: 0.4s;">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                <h3 style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 1.5rem; margin: 0;">
                    <i class="fas fa-list-ul me-2" style="color: var(--accent);"></i>
                    Registro de Actividad
                </h3>
                <div class="d-flex align-items-center gap-3">
                    <span class="badge" style="background: var(--accent); font-size: 0.9rem; padding: 8px 16px;">
                        Mostrando <?= count($logs) ?> de <?= number_format($stats['total']) ?>
                    </span>
                    
                    <!-- Selector de límite -->
                    <form method="GET" class="d-inline-flex">
                        <?php foreach(['tabla', 'accion', 'usuario', 'fecha_desde', 'fecha_hasta'] as $param): ?>
                            <?php if (!empty($_GET[$param])): ?>
                                <input type="hidden" name="<?= $param ?>" value="<?= htmlspecialchars((string)$_GET[$param]) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        
                        <select name="limit" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                            <option value="25" <?= $limit === 25 ? 'selected' : '' ?>>25</option>
                            <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
                            <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
                            <option value="200" <?= $limit === 200 ? 'selected' : '' ?>>200</option>
                            <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>500</option>
                        </select>
                    </form>
                </div>
            </div>

            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-inbox"></i>
                    </div>
                    <div class="empty-text">
                        No se encontraron logs con los filtros aplicados
                    </div>
                    <a href="logs.php" class="btn btn-search mt-4">
                        <i class="fas fa-redo me-2"></i>
                        Ver Todos los Logs
                    </a>
                </div>
            <?php else: ?>
                <?php foreach($logs as $log): 
                    $cambios = formatearCambios($log['valor_anterior'], $log['valor_nuevo'], $log['accion']);
                    $usuario_nombre = $log['primer_nombre'] && $log['primer_apellido'] 
                        ? htmlspecialchars($log['primer_apellido'] . ' ' . $log['primer_nombre']) 
                        : 'Sistema';
                    
                    $accion_class = strtolower($log['accion']);
                    $icono_accion = match($log['accion']) {
                        'INSERT' => 'fa-plus',
                        'UPDATE' => 'fa-pen',
                        'DELETE' => 'fa-trash',
                        default => 'fa-database'
                    };
                ?>
                <div class="log-item">
                    <div class="log-header">
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <span class="log-action-badge action-<?= $accion_class ?>">
                                <i class="fas <?= $icono_accion ?> me-2"></i>
                                <?= htmlspecialchars((string)$log['accion']) ?>
                            </span>
                            <span style="font-weight: 700; font-size: 1rem; color: var(--text-dark);">
                                <?= htmlspecialchars((string)$log['tabla_afectada']) ?>
                            </span>
                        </div>
                        <div class="log-meta">
                            <div class="log-meta-item">
                                <i class="fas fa-user"></i>
                                <strong><?= $usuario_nombre ?></strong>
                            </div>
                            <div class="log-meta-item">
                                <i class="fas fa-clock"></i>
                                <strong><?= date('d/m/Y H:i:s', strtotime($log['fecha_hora'])) ?></strong>
                            </div>
                            <div class="log-meta-item">
                                <i class="fas fa-hashtag"></i>
                                ID: <strong><?= htmlspecialchars((string)$log['id_registro']) ?></strong>
                            </div>
                        </div>
                    </div>

                    <?php if ($log['accion'] === 'UPDATE' && is_array($cambios) && !empty($cambios)): ?>
                        <div class="log-changes">
                            <div class="log-changes-title">
                                <i class="fas fa-exchange-alt me-2"></i>
                                Campos Modificados (<?= count($cambios) ?>)
                            </div>
                            <?php foreach($cambios as $campo => $valores): ?>
                                <div class="change-item">
                                    <div class="change-field">
                                        <i class="fas fa-tag me-1"></i>
                                        <?= htmlspecialchars((string)$campo) ?>
                                    </div>
                                    <div class="change-value">
                                        <span class="change-old">
                                            <?php 
                                                $valor_ant = $valores['anterior'];
                                                if (is_null($valor_ant)) {
                                                    echo 'NULL';
                                                } elseif (is_array($valor_ant)) {
                                                    echo htmlspecialchars(json_encode($valor_ant));
                                                } else {
                                                    $str_ant = (string)$valor_ant;
                                                    echo strlen($str_ant) > 100 ? htmlspecialchars(substr($str_ant, 0, 100)) . '...' : htmlspecialchars($str_ant);
                                                }
                                            ?>
                                        </span>
                                        <span class="change-arrow">
                                            <i class="fas fa-arrow-right"></i>
                                        </span>
                                        <span class="change-new">
                                            <?php 
                                                $valor_nvo = $valores['nuevo'];
                                                if (is_null($valor_nvo)) {
                                                    echo 'NULL';
                                                } elseif (is_array($valor_nvo)) {
                                                    echo htmlspecialchars(json_encode($valor_nvo));
                                                } else {
                                                    $str_nvo = (string)$valor_nvo;
                                                    echo strlen($str_nvo) > 100 ? htmlspecialchars(substr($str_nvo, 0, 100)) . '...' : htmlspecialchars($str_nvo);
                                                }
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($log['accion'] === 'INSERT'): ?>
                        <div class="log-changes">
                            <div class="log-changes-title">
                                <i class="fas fa-plus-circle me-2"></i>
                                Datos Insertados
                            </div>
                            <div class="json-view">
                                <?= htmlspecialchars(json_encode($cambios, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?>
                            </div>
                        </div>
                    <?php elseif ($log['accion'] === 'DELETE'): ?>
                        <div class="log-changes">
                            <div class="log-changes-title">
                                <i class="fas fa-trash-alt me-2"></i>
                                Datos Eliminados
                            </div>
                            <div class="json-view">
                                <?= htmlspecialchars(json_encode($cambios, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-submit en cambios de fecha para mejor UX
        document.querySelectorAll('input[type="date"]').forEach(input => {
            input.addEventListener('change', function() {
                const form = this.closest('form');
                const otherDate = this.name === 'fecha_desde' 
                    ? form.querySelector('input[name="fecha_hasta"]')
                    : form.querySelector('input[name="fecha_desde"]');
                
                if (otherDate && otherDate.value) {
                    form.submit();
                }
            });
        });

        // Validación de rango de fechas
        const fechaDesde = document.querySelector('input[name="fecha_desde"]');
        const fechaHasta = document.querySelector('input[name="fecha_hasta"]');

        if (fechaDesde && fechaHasta) {
            fechaDesde.addEventListener('change', function() {
                fechaHasta.min = this.value;
            });

            fechaHasta.addEventListener('change', function() {
                fechaDesde.max = this.value;
            });
        }
    </script>
</body>
</html>