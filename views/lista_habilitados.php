<?php
declare(strict_types=1);
session_start();
require_once '../config/conexion.php';

// ==================== VERIFICACIÓN DE SESIÓN ====================
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$nombre_usuario = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$inicial = strtoupper(mb_substr($nombre_usuario, 0, 1, 'UTF-8'));
$rol = htmlspecialchars((string)($_SESSION["role"] ?? 'Registro'), ENT_QUOTES, 'UTF-8');
$ruta_logo = "../assets/img/logo_unior1.png";

// ==================== MANEJO DE ACCIONES AJAX ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        switch ($_POST['ajax_action']) {

            // ===== HABILITAR AL MINISTERIO =====
            case 'habilitar_ministerio':
                $ru = trim($_POST['ru_estudiante'] ?? '');
                if (empty($ru)) { echo json_encode(['success' => false, 'message' => 'RU requerido']); exit; }
                $stmt = $pdo->prepare("SELECT pd.estado FROM pre_defensas pd JOIN estudiantes e ON pd.id_estudiante = e.id_persona WHERE e.ru = ? AND pd.estado = 'APROBADA' LIMIT 1");
                $stmt->execute([$ru]);
                if (!$stmt->fetch()) { echo json_encode(['success' => false, 'message' => 'No tiene pre-defensa aprobada']); exit; }
                $stmt = $pdo->prepare("INSERT INTO habilitacion_ministerio (ru_estudiante, esta_habilitado, fecha_validacion) VALUES (?, true, NOW()) ON CONFLICT (ru_estudiante) DO UPDATE SET esta_habilitado = true, fecha_validacion = NOW()");
                $stmt->execute([$ru]);
                echo json_encode(['success' => true, 'message' => 'Estudiante habilitado exitosamente']);
                exit;

            // ===== REGISTRAR PRE-DEFENSA =====
            case 'registrar_predefensa':
                $id_estudiante = (int)($_POST['id_estudiante'] ?? 0);
                $id_proyecto   = (int)($_POST['id_proyecto'] ?? 0);
                $modalidad     = trim($_POST['modalidad_titulacion'] ?? '');
                $tema          = trim($_POST['tema'] ?? '');
                $fecha         = trim($_POST['fecha'] ?? '');
                $hora          = trim($_POST['hora'] ?? '');
                $id_aula       = (int)($_POST['id_aula'] ?? 0);
                $gestion       = trim($_POST['gestion'] ?? date('Y'));
                $id_presidente = (int)($_POST['id_presidente'] ?? 0);
                $id_secretario = (int)($_POST['id_secretario'] ?? 0);

                $errores = [];
                if ($id_estudiante <= 0) $errores[] = 'Estudiante no válido';
                if (empty($modalidad)) $errores[] = 'Modalidad requerida';
                if ($modalidad !== 'EXAMEN_GRADO' && empty($tema)) $errores[] = 'El tema es requerido';
                if (empty($fecha)) $errores[] = 'Fecha requerida';
                if (empty($hora)) $errores[] = 'Hora requerida';
                if ($id_aula <= 0) $errores[] = 'Aula requerida';
                if ($id_presidente <= 0) $errores[] = 'Presidente del tribunal requerido';
                if ($id_secretario <= 0) $errores[] = 'Secretario del tribunal requerido';
                if (!empty($fecha) && strtotime($fecha) < strtotime(date('Y-m-d'))) $errores[] = 'No se pueden registrar fechas pasadas';
                if ($id_presidente === $id_secretario && $id_presidente > 0) $errores[] = 'Presidente y Secretario deben ser diferentes';

                $stmt = $pdo->prepare("SELECT id_tutor FROM proyectos WHERE id_proyecto = ?");
                $stmt->execute([$id_proyecto]);
                $tutor_row = $stmt->fetch(PDO::FETCH_ASSOC);
                $id_tutor = $tutor_row ? (int)$tutor_row['id_tutor'] : 0;
                if ($id_tutor > 0 && ($id_presidente === $id_tutor || $id_secretario === $id_tutor)) $errores[] = 'Los miembros del tribunal no pueden ser el tutor';

                $stmt = $pdo->prepare("SELECT id_pre_defensa FROM pre_defensas WHERE id_estudiante = ? AND gestion = ?");
                $stmt->execute([$id_estudiante, $gestion]);
                if ($stmt->fetch()) $errores[] = 'Ya existe pre-defensa en esta gestión';

                if (!empty($errores)) { echo json_encode(['success' => false, 'message' => implode('. ', $errores)]); exit; }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO pre_defensas (id_estudiante, gestion, fecha, hora, estado, id_tutor, id_proyecto, id_aula, modalidad_titulacion, tema, id_presidente, id_secretario) VALUES (?, ?, ?, ?, 'PENDIENTE', ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$id_estudiante, $gestion, $fecha, $hora, $id_tutor, $id_proyecto, $id_aula, $modalidad, $modalidad === 'EXAMEN_GRADO' ? null : $tema, $id_presidente, $id_secretario]);
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Pre-defensa registrada exitosamente']);
                exit;

            // ===== PROGRAMAR / EDITAR DEFENSA FORMAL =====
            case 'programar_defensa':
            case 'editar_defensa':
                $id_pre_defensa = (int)($_POST['id_pre_defensa'] ?? 0);
                $fecha_defensa  = trim($_POST['fecha_defensa'] ?? '');
                $hora_defensa   = trim($_POST['hora_defensa'] ?? '');
                $id_aula        = (int)($_POST['id_aula_defensa'] ?? 0);
                $es_edicion     = ($_POST['ajax_action'] === 'editar_defensa');

                $errores = [];
                if (empty($fecha_defensa)) $errores[] = 'Fecha requerida';
                if (empty($hora_defensa)) $errores[] = 'Hora requerida';
                if ($id_aula <= 0) $errores[] = 'Aula requerida';
                if (!empty($fecha_defensa) && strtotime($fecha_defensa) < strtotime(date('Y-m-d'))) $errores[] = 'No se pueden programar fechas pasadas';

                $stmt = $pdo->prepare("SELECT pd.id_estudiante, pd.id_proyecto, e.ru, hm.fecha_validacion, hm.esta_habilitado FROM pre_defensas pd JOIN estudiantes e ON pd.id_estudiante = e.id_persona LEFT JOIN habilitacion_ministerio hm ON e.ru = hm.ru_estudiante WHERE pd.id_pre_defensa = ? AND pd.estado = 'APROBADA'");
                $stmt->execute([$id_pre_defensa]);
                $pd_data = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$pd_data) { $errores[] = 'Pre-defensa no encontrada o no aprobada'; }
                elseif (!$pd_data['esta_habilitado']) { $errores[] = 'Estudiante no habilitado por el Ministerio'; }
                else {
                    $fecha_hab = new DateTime($pd_data['fecha_validacion']);
                    $fecha_def = new DateTime($fecha_defensa);
                    $diff = $fecha_hab->diff($fecha_def);
                    if ($diff->days < 30) { $errores[] = "Deben pasar 30 días desde la habilitación. Faltan " . (30 - $diff->days) . " días"; }
                }

                if (!empty($errores)) { echo json_encode(['success' => false, 'message' => implode('. ', $errores)]); exit; }

                $pdo->beginTransaction();
                if ($es_edicion) {
                    $stmt = $pdo->prepare("UPDATE defensa_formal SET fecha_defensa = ?, hora = ?, id_aula = ? WHERE id_pre_defensa = ?");
                    $stmt->execute([$fecha_defensa, $hora_defensa, $id_aula, $id_pre_defensa]);
                    $msg = 'Defensa reprogramada exitosamente';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO defensa_formal (id_pre_defensa, id_estudiante, id_proyecto, fecha_defensa, hora, id_aula, estado) VALUES (?, ?, ?, ?, ?, ?, 'PROGRAMADA')");
                    $stmt->execute([$id_pre_defensa, $pd_data['id_estudiante'], $pd_data['id_proyecto'], $fecha_defensa, $hora_defensa, $id_aula]);
                    $msg = 'Defensa programada exitosamente';
                }
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => $msg]);
                exit;

            // ===== MARCAR NOTA GENERADA (sin código) =====
            case 'marcar_nota_generada':
                $id_pre_defensa = (int)($_POST['id_pre_defensa'] ?? 0);
                $tipo = trim($_POST['tipo_nota'] ?? '');
                // Solo registrar que fue generada, sin código
                $stmt = $pdo->prepare("UPDATE defensa_formal SET nota_generada_en = NOW() WHERE id_pre_defensa = ? AND nota_generada_en IS NULL");
                $stmt->execute([$id_pre_defensa]);
                echo json_encode(['success' => true]);
                exit;

            default:
                echo json_encode(['success' => false, 'message' => 'Acción no válida']);
                exit;
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Error AJAX gestion_estudiantes: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error de base de datos']);
        exit;
    }
}

// ==================== CONFIGURACIÓN PESTAÑA ====================
$tab           = $_GET['tab']    ?? 'listado';
$filtro_carrera = $_GET['carrera'] ?? '';
$filtro_estado  = $_GET['estado']  ?? '';
$busqueda       = trim($_GET['q']  ?? '');

$mod_labels = [
    'EXAMEN_GRADO'    => 'Examen de Grado',
    'PROYECTO_GRADO'  => 'Proyecto de Grado',
    'TESIS'           => 'Tesis de Grado',
    'TRABAJO_DIRIGIDO'=> 'Trabajo Dirigido',
];

try {
    $carreras        = $pdo->query("SELECT id_carrera, nombre_carrera FROM carreras ORDER BY nombre_carrera")->fetchAll(PDO::FETCH_ASSOC);
    $aulas           = $pdo->query("SELECT id_aula, nombre_aula FROM aulas ORDER BY nombre_aula")->fetchAll(PDO::FETCH_ASSOC);
    $docentes_tribunal = $pdo->query("SELECT d.id_persona, p.primer_nombre||' '||COALESCE(p.segundo_nombre||' ','')||p.primer_apellido||' '||COALESCE(p.segundo_apellido,'') as nombre_completo FROM docentes d JOIN personas p ON d.id_persona=p.id_persona WHERE d.es_tribunal=true ORDER BY p.primer_apellido, p.primer_nombre")->fetchAll(PDO::FETCH_ASSOC);

    // ── LISTADO GENERAL ──
    $where_parts = []; $params_q = [];
    if (!empty($filtro_carrera)) { $where_parts[] = "c.id_carrera = ?"; $params_q[] = (int)$filtro_carrera; }
    if (!empty($filtro_estado))  { $where_parts[] = "COALESCE(pd.estado,'SIN_PREDEFENSA') = ?"; $params_q[] = $filtro_estado; }
    if (!empty($busqueda)) {
        $where_parts[] = "(LOWER(p.primer_nombre||' '||p.primer_apellido) LIKE LOWER(?) OR p.ci LIKE ? OR e.ru LIKE ?)";
        $params_q[] = "%$busqueda%"; $params_q[] = "%$busqueda%"; $params_q[] = "%$busqueda%";
    }
    $where_sql = !empty($where_parts) ? 'WHERE '.implode(' AND ', $where_parts) : '';

    $stmt = $pdo->prepare("
        SELECT e.id_persona as id_estudiante, p.ci, e.ru,
               p.primer_nombre, COALESCE(p.segundo_nombre,'') as segundo_nombre,
               p.primer_apellido, COALESCE(p.segundo_apellido,'') as segundo_apellido,
               c.nombre_carrera, c.id_carrera,
               pd.id_pre_defensa, pd.estado as estado_predefensa, pd.nota,
               pd.modalidad_titulacion, pd.tema, pd.fecha as fecha_predefensa, pd.hora as hora_predefensa,
               COALESCE(pd.estado,'SIN_PREDEFENSA') as estado_display,
               hm.esta_habilitado, hm.fecha_validacion,
               pt.primer_nombre||' '||pt.primer_apellido as tutor_nombre,
               pp.primer_nombre||' '||pp.primer_apellido as presidente_nombre,
               ps.primer_nombre||' '||ps.primer_apellido as secretario_nombre,
               df.id_defensa, df.fecha_defensa, df.hora as hora_defensa,
               a.nombre_aula as aula_defensa, df.estado as estado_defensa
        FROM estudiantes e
        JOIN personas p ON e.id_persona = p.id_persona
        LEFT JOIN carreras c ON e.id_carrera = c.id_carrera
        LEFT JOIN pre_defensas pd ON e.id_persona = pd.id_estudiante
        LEFT JOIN habilitacion_ministerio hm ON e.ru = hm.ru_estudiante
        LEFT JOIN personas pt ON pd.id_tutor = pt.id_persona
        LEFT JOIN personas pp ON pd.id_presidente = pp.id_persona
        LEFT JOIN personas ps ON pd.id_secretario = ps.id_persona
        LEFT JOIN defensa_formal df ON pd.id_pre_defensa = df.id_pre_defensa
        LEFT JOIN aulas a ON df.id_aula = a.id_aula
        $where_sql
        ORDER BY p.primer_apellido ASC, p.primer_nombre ASC
    ");
    $stmt->execute($params_q);
    $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── HABILITACIÓN ──
    $stmt = $pdo->query("
        SELECT e.id_persona as id_estudiante, p.ci, e.ru,
               p.primer_nombre||' '||COALESCE(p.segundo_nombre||' ','')||p.primer_apellido||' '||COALESCE(p.segundo_apellido,'') as nombre_completo,
               c.nombre_carrera, pd.nota, pd.fecha as fecha_predefensa, pd.modalidad_titulacion, pd.tema, pd.id_pre_defensa,
               hm.esta_habilitado, hm.fecha_validacion,
               pt.primer_nombre||' '||pt.primer_apellido as tutor_nombre
        FROM estudiantes e
        JOIN personas p ON e.id_persona = p.id_persona
        JOIN pre_defensas pd ON e.id_persona = pd.id_estudiante
        LEFT JOIN carreras c ON e.id_carrera = c.id_carrera
        LEFT JOIN habilitacion_ministerio hm ON e.ru = hm.ru_estudiante
        LEFT JOIN personas pt ON pd.id_tutor = pt.id_persona
        WHERE pd.estado = 'APROBADA'
        ORDER BY COALESCE(hm.esta_habilitado,false) ASC, pd.fecha DESC
    ");
    $habilitaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── DEFENSA FORMAL ──
    $stmt = $pdo->query("
        SELECT e.id_persona as id_estudiante, p.ci, e.ru,
               p.primer_nombre||' '||COALESCE(p.segundo_nombre||' ','')||p.primer_apellido||' '||COALESCE(p.segundo_apellido,'') as nombre_completo,
               c.nombre_carrera, pd.id_pre_defensa, pd.nota as nota_predefensa, pd.modalidad_titulacion, pd.tema,
               hm.fecha_validacion,
               pt.primer_nombre||' '||COALESCE(pt.segundo_nombre||' ','')||pt.primer_apellido||' '||COALESCE(pt.segundo_apellido,'') as tutor_completo,
               pp.primer_nombre||' '||COALESCE(pp.segundo_nombre||' ','')||pp.primer_apellido||' '||COALESCE(pp.segundo_apellido,'') as presidente_completo,
               ps.primer_nombre||' '||COALESCE(ps.segundo_nombre||' ','')||ps.primer_apellido||' '||COALESCE(ps.segundo_apellido,'') as secretario_completo,
               df.id_defensa, df.fecha_defensa, df.hora as hora_defensa, df.estado as estado_defensa, df.nota_final,
               a.nombre_aula as aula_defensa,
               CASE WHEN hm.fecha_validacion IS NOT NULL THEN (CURRENT_DATE - hm.fecha_validacion::date) >= 30 ELSE false END as puede_programar,
               CASE WHEN hm.fecha_validacion IS NOT NULL THEN 30 - (CURRENT_DATE - hm.fecha_validacion::date) ELSE 30 END as dias_restantes
        FROM estudiantes e
        JOIN personas p ON e.id_persona = p.id_persona
        JOIN pre_defensas pd ON e.id_persona = pd.id_estudiante
        JOIN habilitacion_ministerio hm ON e.ru = hm.ru_estudiante AND hm.esta_habilitado = true
        LEFT JOIN carreras c ON e.id_carrera = c.id_carrera
        LEFT JOIN personas pt ON pd.id_tutor = pt.id_persona
        LEFT JOIN personas pp ON pd.id_presidente = pp.id_persona
        LEFT JOIN personas ps ON pd.id_secretario = ps.id_persona
        LEFT JOIN defensa_formal df ON pd.id_pre_defensa = df.id_pre_defensa
        LEFT JOIN aulas a ON df.id_aula = a.id_aula
        WHERE pd.estado = 'APROBADA'
        ORDER BY df.fecha_defensa DESC NULLS FIRST, p.primer_apellido ASC
    ");
    $defensas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── NUEVO REGISTRO ──
    $stmt = $pdo->query("
        SELECT e.id_persona as id_estudiante, p.ci, e.ru,
               p.primer_nombre||' '||COALESCE(p.segundo_nombre||' ','')||p.primer_apellido||' '||COALESCE(p.segundo_apellido,'') as nombre_completo,
               c.nombre_carrera, c.id_carrera, pr.id_proyecto, pr.titulo_proyecto,
               pt.primer_nombre||' '||COALESCE(pt.segundo_nombre||' ','')||pt.primer_apellido||' '||COALESCE(pt.segundo_apellido,'') as tutor_completo,
               pr.id_tutor
        FROM estudiantes e
        JOIN personas p ON e.id_persona = p.id_persona
        JOIN proyectos pr ON e.id_persona = pr.id_estudiante AND pr.id_tutor IS NOT NULL
        JOIN personas pt ON pr.id_tutor = pt.id_persona
        LEFT JOIN carreras c ON e.id_carrera = c.id_carrera
        LEFT JOIN pre_defensas pd ON e.id_persona = pd.id_estudiante
        WHERE pd.id_pre_defensa IS NULL
        ORDER BY p.primer_apellido ASC, p.primer_nombre ASC
    ");
    $sin_predefensa = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Contadores
    $count_listado     = count($estudiantes);
    $count_habilitacion = count($habilitaciones);
    $count_defensa     = count($defensas);
    $count_registro    = count($sin_predefensa);

} catch (PDOException $e) {
    error_log("Error gestion_estudiantes: " . $e->getMessage());
    die("Error al cargar datos.");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestión de Estudiantes | UNIOR</title>
<link rel="icon" type="image/png" href="<?= $ruta_logo ?>">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
/* ============================
   VARIABLES & RESET
============================= */
:root {
  --ink:     #0b0f1a;
  --ink-2:   #1e2535;
  --ink-3:   #2e3850;
  --muted:   #6b7a99;
  --muted-2: #94a3b8;
  --border:  #e2e8f4;
  --surface: #f7f9fd;
  --white:   #ffffff;

  --gold:    #e8a33a;
  --gold-2:  #f0bc5e;
  --gold-3:  rgba(232,163,58,.12);
  --blue:    #3b5bdb;
  --blue-2:  #4c6ef5;
  --blue-3:  rgba(59,91,219,.10);
  --green:   #0ca678;
  --green-3: rgba(12,166,120,.10);
  --red:     #e03131;
  --red-3:   rgba(224,49,49,.10);
  --cyan:    #0891b2;
  --cyan-3:  rgba(8,145,178,.10);
  --violet:  #7c3aed;
  --violet-3:rgba(124,58,237,.10);

  --sidebar-w: 72px;
  --sidebar-open: 248px;
  --radius: 18px;
  --radius-sm: 10px;
  --shadow: 0 4px 24px rgba(11,15,26,.07);
  --shadow-md: 0 8px 40px rgba(11,15,26,.10);
  --ease: cubic-bezier(.4,0,.2,1);
  --font: 'Sora', sans-serif;
  --mono: 'DM Mono', monospace;
}
*{margin:0;padding:0;box-sizing:border-box}
body{
  font-family:var(--font);
  background:var(--surface);
  color:var(--ink);
  min-height:100vh;
  display:flex;
  overflow-x:hidden;
}

/* ============================
   SIDEBAR
============================= */
.sidebar{
  width:var(--sidebar-w);
  min-height:100vh;
  background:var(--ink);
  display:flex;
  flex-direction:column;
  align-items:center;
  padding:28px 0;
  position:fixed;
  left:0;top:0;bottom:0;
  z-index:900;
  transition:width .35s var(--ease);
  overflow:hidden;
}
.sidebar:hover{ width:var(--sidebar-open); align-items:flex-start; padding:28px 0; }
.sidebar:hover .nav-link span{ opacity:1; transform:translateX(0); }
.sidebar:hover .nav-link{ justify-content:flex-start; padding:0 22px; }
.sidebar:hover .logo-wrap{ padding:0 20px; justify-content:flex-start; }
.sidebar:hover .logo-txt{ opacity:1; max-width:200px; }
.sidebar:hover .sidebar-footer{ padding:0 20px; width:100%; }
.sidebar:hover .sidebar-footer a{ justify-content:flex-start; }
.sidebar:hover .sidebar-footer a span{ opacity:1; transform:translateX(0); }

.logo-wrap{
  display:flex;align-items:center;gap:12px;
  margin-bottom:36px;
  padding:0;
  width:100%;justify-content:center;
  transition:.3s;
}
.logo-txt{
  font-size:1.1rem;font-weight:800;
  color:var(--gold);
  opacity:0;max-width:0;overflow:hidden;
  white-space:nowrap;
  transition:.3s var(--ease);
}

.nav-link{
  display:flex;align-items:center;
  width:100%;height:52px;
  padding:0;justify-content:center;
  color:var(--muted-2);
  text-decoration:none;
  font-weight:600;font-size:.875rem;
  transition:.25s;
  border-radius:0;
  position:relative;
  margin-bottom:4px;
}
.nav-link i{ font-size:1.1rem;min-width:72px;text-align:center;flex-shrink:0; }
.nav-link span{ opacity:0;transform:translateX(-8px);transition:.25s var(--ease);white-space:nowrap; }
.nav-link:hover{ color:var(--white); }
.nav-link.active{
  color:var(--gold);
  background:rgba(232,163,58,.08);
}
.nav-link.active::before{
  content:'';position:absolute;left:0;top:0;bottom:0;
  width:3px;background:var(--gold);border-radius:0 2px 2px 0;
}

.sidebar-footer{ margin-top:auto;width:100%;display:flex;flex-direction:column;align-items:center; }
.sidebar-footer a{ justify-content:center;color:#e55 !important; }

/* ============================
   MAIN
============================= */
.main{
  margin-left:var(--sidebar-w);
  flex:1;
  min-width:0;
  padding:36px 44px 60px;
  transition:margin .35s var(--ease);
}

/* ============================
   TOPBAR
============================= */
.topbar{
  display:flex;align-items:center;justify-content:space-between;
  margin-bottom:36px;
}
.page-title{
  font-size:clamp(1.6rem,3vw,2.4rem);
  font-weight:800;letter-spacing:-.03em;
  line-height:1.1;
}
.page-title span{ color:var(--gold); }
.page-sub{ font-size:.82rem;color:var(--muted);margin-top:5px;font-weight:500; }

.user-pill{
  display:flex;align-items:center;gap:12px;
  background:var(--white);
  border:1.5px solid var(--border);
  border-radius:100px;
  padding:7px 16px 7px 7px;
  box-shadow:var(--shadow);
}
.user-avatar{
  width:36px;height:36px;border-radius:50%;
  background:var(--ink);color:var(--gold);
  display:flex;align-items:center;justify-content:center;
  font-weight:800;font-size:.95rem;
  flex-shrink:0;
}
.user-name{ font-weight:700;font-size:.82rem;color:var(--ink); }
.user-role{ font-size:.68rem;color:var(--muted);letter-spacing:.8px;text-transform:uppercase;font-weight:600; }

/* ============================
   STATS ROW
============================= */
.stats-row{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:16px;
  margin-bottom:28px;
}
@media(max-width:900px){ .stats-row{ grid-template-columns:repeat(2,1fr); } }
.stat-card{
  background:var(--white);
  border:1.5px solid var(--border);
  border-radius:var(--radius);
  padding:20px 22px;
  box-shadow:var(--shadow);
  display:flex;align-items:center;gap:16px;
  cursor:pointer;transition:.25s var(--ease);
  text-decoration:none;color:inherit;
}
.stat-card:hover{ border-color:var(--gold);transform:translateY(-2px);box-shadow:var(--shadow-md); }
.stat-card.active-tab{ border-color:var(--gold);background:linear-gradient(135deg,rgba(232,163,58,.06),rgba(240,188,94,.03)); }
.stat-icon{
  width:46px;height:46px;border-radius:12px;
  display:flex;align-items:center;justify-content:center;
  font-size:1.1rem;flex-shrink:0;
}
.stat-num{ font-size:1.8rem;font-weight:800;line-height:1; }
.stat-lbl{ font-size:.72rem;color:var(--muted);font-weight:600;letter-spacing:.5px;text-transform:uppercase;margin-top:3px; }

/* ============================
   PANEL / CARD
============================= */
.panel{
  background:var(--white);
  border:1.5px solid var(--border);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  overflow:hidden;
}
.panel-head{
  display:flex;align-items:center;justify-content:space-between;
  padding:20px 24px;
  border-bottom:1.5px solid var(--border);
  background:var(--surface);
}
.panel-title{ font-weight:700;font-size:1rem; }
.panel-body{ padding:24px; }

/* ============================
   FILTER BAR
============================= */
.filter-bar{
  display:flex;gap:10px;flex-wrap:wrap;align-items:center;
  padding:16px 24px;
  border-bottom:1.5px solid var(--border);
  background:var(--surface);
}
.f-select,.f-input{
  background:var(--white);
  border:1.5px solid var(--border);
  border-radius:var(--radius-sm);
  padding:9px 14px;
  font-size:.82rem;font-weight:500;
  font-family:var(--font);
  color:var(--ink);
  outline:none;
  transition:.2s;
}
.f-select:focus,.f-input:focus{ border-color:var(--gold);box-shadow:0 0 0 3px rgba(232,163,58,.12); }
.f-input{ min-width:220px; }

/* ============================
   TABLE
============================= */
.tbl-wrap{ overflow-x:auto;max-height:580px;overflow-y:auto; }
.tbl-wrap::-webkit-scrollbar{ width:5px;height:5px; }
.tbl-wrap::-webkit-scrollbar-thumb{ background:var(--border);border-radius:10px; }

table.tbl{
  width:100%;border-collapse:separate;border-spacing:0;
  font-size:.82rem;
}
table.tbl thead th{
  background:var(--ink);color:rgba(255,255,255,.7);
  padding:11px 14px;
  font-size:.68rem;text-transform:uppercase;letter-spacing:1px;font-weight:700;
  position:sticky;top:0;z-index:5;white-space:nowrap;
}
table.tbl thead th:first-child{ border-radius:0; }
table.tbl thead th:last-child{ border-radius:0; }
table.tbl tbody td{
  padding:13px 14px;
  border-bottom:1px solid var(--border);
  vertical-align:middle;
}
table.tbl tbody tr:last-child td{ border-bottom:none; }
table.tbl tbody tr{ transition:.15s; }
table.tbl tbody tr:hover td{ background:rgba(232,163,58,.03); }

.cell-name{ font-weight:700;color:var(--ink); }
.cell-sub{ font-size:.73rem;color:var(--muted);margin-top:2px; }
.mono{ font-family:var(--mono);font-size:.78rem; }

/* ============================
   BADGES
============================= */
.badge{
  display:inline-flex;align-items:center;gap:5px;
  padding:4px 11px;border-radius:100px;
  font-size:.68rem;font-weight:700;letter-spacing:.3px;
  white-space:nowrap;
}
.b-aprobada { background:var(--green-3); color:var(--green); }
.b-reprobada{ background:var(--red-3);   color:var(--red); }
.b-pendiente{ background:rgba(245,158,11,.12); color:#b45309; }
.b-sin      { background:rgba(107,122,153,.12);color:var(--muted); }
.b-habilitado{ background:var(--cyan-3);  color:var(--cyan); }
.b-programada{ background:var(--blue-3);  color:var(--blue); }
.b-disponible{ background:var(--green-3); color:var(--green); }

/* ============================
   BUTTONS
============================= */
.btn{
  display:inline-flex;align-items:center;gap:7px;
  border:none;cursor:pointer;font-family:var(--font);
  font-weight:700;transition:.2s var(--ease);
  text-decoration:none;white-space:nowrap;
}
.btn:hover{ transform:translateY(-1px); }
.btn:active{ transform:translateY(0); }

.btn-sm{ padding:7px 14px;font-size:.75rem;border-radius:var(--radius-sm); }
.btn-md{ padding:10px 20px;font-size:.82rem;border-radius:var(--radius-sm); }
.btn-lg{ padding:12px 26px;font-size:.9rem;border-radius:var(--radius); }
.btn-full{ width:100%;justify-content:center; }

.btn-gold  { background:var(--gold);  color:var(--ink);  box-shadow:0 4px 14px rgba(232,163,58,.3); }
.btn-gold:hover{ box-shadow:0 6px 20px rgba(232,163,58,.45);color:var(--ink); }
.btn-dark  { background:var(--ink);   color:var(--white); }
.btn-green { background:var(--green); color:var(--white); box-shadow:0 4px 14px var(--green-3); }
.btn-green:hover{ box-shadow:0 6px 20px rgba(12,166,120,.35); }
.btn-cyan  { background:var(--cyan);  color:var(--white); }
.btn-blue  { background:var(--blue);  color:var(--white); }
.btn-red   { background:var(--red);   color:var(--white); }
.btn-violet{ background:var(--violet);color:var(--white); }
.btn-ghost {
  background:transparent;color:var(--muted);
  border:1.5px solid var(--border);
}
.btn-ghost:hover{ border-color:var(--ink);color:var(--ink); }

.btn-group-actions{ display:flex;flex-direction:column;gap:6px;min-width:140px; }

/* ============================
   EMPTY STATE
============================= */
.empty{
  text-align:center;padding:60px 20px;
  color:var(--muted);
}
.empty i{ font-size:2.5rem;opacity:.25;display:block;margin-bottom:14px; }
.empty p{ font-size:.9rem;font-weight:600; }

/* ============================
   MODAL
============================= */
.modal-content{
  border:none;border-radius:22px;
  box-shadow:0 25px 80px rgba(0,0,0,.18);
  overflow:hidden;font-family:var(--font);
}
.modal-header{
  background:var(--ink);color:var(--white);
  border:none;padding:22px 26px;
}
.modal-header.green{ background:linear-gradient(135deg,var(--green),#059669); }
.modal-header.cyan { background:linear-gradient(135deg,var(--cyan),#0891b2); }
.modal-title{ font-weight:800;font-size:1rem; }
.modal-header .btn-close{ filter:brightness(0) invert(1); }
.modal-body{ padding:26px;font-family:var(--font); }
.modal-footer{ border:none;padding:16px 26px 22px; }

.form-lbl{
  font-size:.72rem;font-weight:700;color:var(--muted);
  text-transform:uppercase;letter-spacing:.6px;
  display:block;margin-bottom:6px;
}
.form-ctrl{
  width:100%;border:1.5px solid var(--border);border-radius:var(--radius-sm);
  padding:11px 14px;font-size:.88rem;font-family:var(--font);
  color:var(--ink);outline:none;
  transition:.2s;background:var(--white);
}
.form-ctrl:focus{ border-color:var(--gold);box-shadow:0 0 0 3px rgba(232,163,58,.12); }

.checklist{ display:flex;flex-direction:column;gap:8px; }
.check-row{
  display:flex;align-items:center;gap:12px;
  padding:12px 16px;border-radius:var(--radius-sm);
  border:1.5px solid var(--border);
  transition:.2s;cursor:pointer;
}
.check-row:hover{ border-color:var(--gold);background:var(--gold-3); }
.check-row input{ width:18px;height:18px;accent-color:var(--gold);cursor:pointer;flex-shrink:0; }
.check-row label{ cursor:pointer;font-size:.875rem;font-weight:500; }

/* ============================
   INFO CARD (dentro modal)
============================= */
.info-card{
  background:var(--surface);
  border:1.5px solid var(--border);
  border-radius:var(--radius-sm);
  padding:14px 18px;
}
.info-card-lbl{ font-size:.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px; }
.info-card-val{ font-size:.9rem;font-weight:700;color:var(--ink);margin-top:2px; }
.info-card-sub{ font-size:.75rem;color:var(--muted);margin-top:2px; }

/* ============================
   TOAST
============================= */
.toast-wrap{
  position:fixed;top:20px;right:20px;z-index:9999;
  display:flex;flex-direction:column;gap:8px;
}
.toast-msg{
  display:flex;align-items:center;gap:10px;
  padding:14px 20px;border-radius:14px;
  color:var(--white);font-weight:600;font-size:.88rem;
  font-family:var(--font);
  box-shadow:0 8px 30px rgba(0,0,0,.18);
  transform:translateX(120%);
  transition:.4s var(--ease);
  max-width:380px;
}
.toast-msg.show{ transform:translateX(0); }
.toast-success{ background:linear-gradient(135deg,var(--green),#059669); }
.toast-error  { background:linear-gradient(135deg,var(--red),#c62828); }

/* ============================
   UTILITY
============================= */
.text-gold{ color:var(--gold); }
.text-muted2{ color:var(--muted); }
.fw7{ font-weight:700; }
.fw8{ font-weight:800; }
.reveal{ opacity:0;transform:translateY(20px);animation:rise .6s var(--ease) forwards; }
@keyframes rise{ to{ opacity:1;transform:none; } }
.r0{ animation-delay:0s; }
.r1{ animation-delay:.08s; }
.r2{ animation-delay:.16s; }
.r3{ animation-delay:.22s; }
</style>
</head>
<body>

<!-- ===========================
     TOAST
=========================== -->
<div class="toast-wrap" id="toastWrap">
  <div class="toast-msg" id="toastEl">
    <i class="fas fa-circle-check" id="toastIcon"></i>
    <span id="toastMsg"></span>
  </div>
</div>

<!-- ===========================
     SIDEBAR
=========================== -->
<aside class="sidebar">
  <div class="logo-wrap">
    <img src="<?= $ruta_logo ?>" width="36" alt="Logo">
    <span class="logo-txt">UNIOR</span>
  </div>
  <nav style="width:100%">
    <a href="menu.php" class="nav-link"><i class="fas fa-home-alt"></i><span>Menú Principal</span></a>
    <a href="gestion_estudiantes.php" class="nav-link active"><i class="fas fa-users-rays"></i><span>Estudiantes</span></a>
    <a href="predefensas.php" class="nav-link"><i class="fas fa-file-signature"></i><span>Predefensas</span></a>
    <a href="reportes.php" class="nav-link"><i class="fas fa-chart-line"></i><span>Reportes</span></a>
    <a href="logs.php" class="nav-link"><i class="fas fa-clipboard-list"></i><span>Registros</span></a>
  </nav>
  <div class="sidebar-footer">
    <a href="../controllers/logout.php" class="nav-link"><i class="fas fa-power-off"></i><span>Cerrar Sesión</span></a>
  </div>
</aside>

<!-- ===========================
     MAIN
=========================== -->
<main class="main">

  <!-- Topbar -->
  <div class="topbar reveal r0">
    <div>
      <div class="page-title">Gestión de <span>Estudiantes</span></div>
      <div class="page-sub"><i class="fas fa-graduation-cap me-1"></i> Habilitaciones · Defensa Formal · Titulación</div>
    </div>
    <div class="user-pill">
      <div class="user-avatar"><?= $inicial ?></div>
      <div>
        <div class="user-name"><?= $nombre_usuario ?></div>
        <div class="user-role"><?= $rol ?></div>
      </div>
    </div>
  </div>

  <!-- Stats / Tab Nav -->
  <div class="stats-row reveal r1">
    <?php
    $tabs_cfg = [
      'listado'     => ['icon'=>'fa-list-ul',        'color'=>'#3b5bdb','bg'=>'rgba(59,91,219,.1)',  'label'=>'Listado General',        'count'=>$count_listado],
      'habilitacion'=> ['icon'=>'fa-clipboard-check', 'color'=>'#0891b2','bg'=>'rgba(8,145,178,.1)', 'label'=>'Habilitación Ministerio','count'=>$count_habilitacion],
      'defensa'     => ['icon'=>'fa-award',            'color'=>'#e8a33a','bg'=>'rgba(232,163,58,.1)','label'=>'Defensa Formal',         'count'=>$count_defensa],
      'registro'    => ['icon'=>'fa-user-plus',        'color'=>'#0ca678','bg'=>'rgba(12,166,120,.1)','label'=>'Nuevo Registro',         'count'=>$count_registro],
    ];
    foreach($tabs_cfg as $key=>$cfg):
    ?>
    <a href="?tab=<?= $key ?>" class="stat-card <?= $tab === $key ? 'active-tab' : '' ?>" style="<?= $tab===  $key ? 'border-color:'.$cfg['color'] : '' ?>">
      <div class="stat-icon" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>">
        <i class="fas <?= $cfg['icon'] ?>"></i>
      </div>
      <div>
        <div class="stat-num" style="<?= $tab===$key ? 'color:'.$cfg['color'] : '' ?>"><?= $cfg['count'] ?></div>
        <div class="stat-lbl"><?= $cfg['label'] ?></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- ══════════════════════════════
       TAB 1 — LISTADO GENERAL
  ══════════════════════════════ -->
  <?php if($tab === 'listado'): ?>
  <div class="panel reveal r2">
    <!-- Filtros -->
    <form method="GET" class="filter-bar">
      <input type="hidden" name="tab" value="listado">
      <select name="carrera" class="f-select">
        <option value="">Todas las carreras</option>
        <?php foreach($carreras as $c): ?>
        <option value="<?= $c['id_carrera'] ?>" <?= $filtro_carrera==$c['id_carrera']?'selected':''?>><?= htmlspecialchars($c['nombre_carrera']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="estado" class="f-select">
        <option value="">Todos los estados</option>
        <option value="APROBADA"       <?= $filtro_estado==='APROBADA'?'selected':'' ?>>Aprobada</option>
        <option value="REPROBADA"      <?= $filtro_estado==='REPROBADA'?'selected':'' ?>>Reprobada</option>
        <option value="PENDIENTE"      <?= $filtro_estado==='PENDIENTE'?'selected':'' ?>>Pendiente</option>
        <option value="SIN_PREDEFENSA" <?= $filtro_estado==='SIN_PREDEFENSA'?'selected':'' ?>>Sin Pre-defensa</option>
      </select>
      <input type="text" name="q" class="f-input" placeholder="Buscar por nombre, CI o RU…" value="<?= htmlspecialchars($busqueda) ?>">
      <button type="submit" class="btn btn-md btn-gold"><i class="fas fa-search"></i> Filtrar</button>
      <?php if($filtro_carrera || $filtro_estado || $busqueda): ?>
        <a href="?tab=listado" class="btn btn-md btn-ghost" style="color:var(--red);border-color:var(--red)"><i class="fas fa-times"></i> Limpiar</a>
      <?php endif; ?>
      <span style="margin-left:auto;font-size:.75rem;color:var(--muted);font-weight:600;"><?= count($estudiantes) ?> registro(s)</span>
    </form>

    <div class="tbl-wrap">
      <?php if(empty($estudiantes)): ?>
      <div class="empty"><i class="fas fa-search"></i><p>Sin resultados para los filtros aplicados</p></div>
      <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th>#</th>
            <th>Apellido(s)</th>
            <th>Nombre(s)</th>
            <th>CI</th>
            <th>RU</th>
            <th>Carrera</th>
            <th>Modalidad</th>
            <th>Tema</th>
            <th>Fecha Pre-def.</th>
            <th>Nota</th>
            <th>Estado Pre-def.</th>
            <th>Tutor</th>
            <th>Presidente</th>
            <th>Secretario</th>
            <th>Habilitado</th>
            <th>Fecha Defensa</th>
            <th>Aula</th>
            <th>Est. Defensa</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($estudiantes as $i => $e): ?>
          <tr>
            <td class="mono" style="color:var(--muted)"><?= $i+1 ?></td>
            <td class="fw7"><?= htmlspecialchars(trim($e['primer_apellido'].' '.$e['segundo_apellido'])) ?></td>
            <td><?= htmlspecialchars(trim($e['primer_nombre'].' '.$e['segundo_nombre'])) ?></td>
            <td class="mono"><?= htmlspecialchars($e['ci']) ?></td>
            <td class="mono fw7"><?= htmlspecialchars($e['ru']) ?></td>
            <td style="font-size:.78rem;max-width:130px"><?= htmlspecialchars($e['nombre_carrera'] ?? '—') ?></td>
            <td style="font-size:.77rem"><?= $mod_labels[$e['modalidad_titulacion'] ?? ''] ?? '<span style="color:var(--muted)">—</span>' ?></td>
            <td style="font-size:.77rem;max-width:160px;white-space:normal">
              <?= $e['tema'] ? htmlspecialchars(mb_strimwidth($e['tema'],0,60,'…')) : '<span style="color:var(--muted)">—</span>' ?>
            </td>
            <td class="mono" style="font-size:.77rem"><?= $e['fecha_predefensa'] ? date('d/m/Y', strtotime($e['fecha_predefensa'])) : '—' ?></td>
            <td>
              <?php if($e['nota'] !== null): ?>
                <span style="font-weight:800;font-size:1.05rem;color:<?= (float)$e['nota']>=41?'var(--green)':'var(--red)' ?>"><?= $e['nota'] ?></span>
              <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
            </td>
            <td>
              <?php
              $cls = match($e['estado_display']){
                'APROBADA'=>'b-aprobada','REPROBADA'=>'b-reprobada','PENDIENTE'=>'b-pendiente',default=>'b-sin'
              };
              $lbl = match($e['estado_display']){
                'APROBADA'=>'APROBADA','REPROBADA'=>'REPROBADA','PENDIENTE'=>'PENDIENTE',default=>'SIN PRE-DEF.'
              };
              ?>
              <span class="badge <?= $cls ?>"><?= $lbl ?></span>
            </td>
            <td style="font-size:.78rem;font-weight:600"><?= htmlspecialchars($e['tutor_nombre'] ?? '—') ?></td>
            <td style="font-size:.78rem"><?= htmlspecialchars($e['presidente_nombre'] ?? '—') ?></td>
            <td style="font-size:.78rem"><?= htmlspecialchars($e['secretario_nombre'] ?? '—') ?></td>
            <td>
              <?php if($e['esta_habilitado']): ?>
                <span class="badge b-habilitado"><i class="fas fa-check-circle"></i> SÍ</span>
              <?php else: ?>
                <span class="badge b-sin">NO</span>
              <?php endif; ?>
            </td>
            <td class="mono" style="font-size:.77rem">
              <?= $e['fecha_defensa'] ? date('d/m/Y', strtotime($e['fecha_defensa'])) : '<span style="color:var(--muted)">—</span>' ?>
              <?php if($e['hora_defensa']): ?>
                <div style="color:var(--muted);font-size:.7rem"><?= substr($e['hora_defensa'],0,5) ?></div>
              <?php endif; ?>
            </td>
            <td style="font-size:.77rem"><?= htmlspecialchars($e['aula_defensa'] ?? '—') ?></td>
            <td>
              <?php if($e['estado_defensa']): ?>
                <span class="badge b-programada"><?= $e['estado_defensa'] ?></span>
              <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
            </td>
            <td>
              <div class="btn-group-actions">
                <?php if($e['estado_display'] === 'APROBADA' && !$e['esta_habilitado']): ?>
                  <button class="btn btn-sm btn-cyan" onclick="abrirHabilitar('<?= $e['ru'] ?>','<?= htmlspecialchars(addslashes(trim($e['primer_apellido'].' '.$e['primer_nombre']))) ?>')">
                    <i class="fas fa-user-check"></i> Habilitar
                  </button>
                <?php endif; ?>
                <?php if($e['id_defensa'] && $e['fecha_defensa']): ?>
                  <button class="btn btn-sm btn-ghost" style="font-size:.72rem" onclick="abrirEditar(<?= $e['id_pre_defensa'] ?>,'<?= htmlspecialchars(addslashes(trim($e['primer_apellido'].' '.$e['primer_nombre']))) ?>','<?= $e['fecha_validacion'] ?>','<?= $e['fecha_defensa'] ?>','<?= substr($e['hora_defensa']??'',0,5) ?>','<?= $e['aula_defensa']??'' ?>')">
                    <i class="fas fa-calendar-pen"></i> Editar Fecha
                  </button>
                  <?php if(stripos($e['nombre_carrera']??'','GASTRONOM') === false): ?>
                    <button class="btn btn-sm btn-ghost" style="font-size:.72rem;color:var(--gold);border-color:var(--gold)" onclick="abrirDocumento(<?= $e['id_pre_defensa'] ?>,'interna','<?= htmlspecialchars(addslashes(trim($e['primer_apellido'].' '.$e['primer_nombre']))) ?>')">
                      <i class="fas fa-file-alt"></i> Nota Int.
                    </button>
                  <?php endif; ?>
                <?php elseif($e['esta_habilitado'] && !$e['id_defensa']): ?>
                  <button class="btn btn-sm btn-gold" onclick="abrirProgramar(<?= $e['id_pre_defensa'] ?>,'<?= htmlspecialchars(addslashes(trim($e['primer_apellido'].' '.$e['primer_nombre']))) ?>','<?= $e['fecha_validacion'] ?>')">
                    <i class="fas fa-calendar-plus"></i> Programar
                  </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════
       TAB 2 — HABILITACIÓN
  ══════════════════════════════ -->
  <?php if($tab === 'habilitacion'): ?>
  <div class="panel reveal r2">
    <div class="panel-head">
      <span class="panel-title"><i class="fas fa-clipboard-check me-2 text-gold"></i>Estudiantes Aprobados — Habilitación al Ministerio</span>
    </div>
    <div class="tbl-wrap">
      <?php if(empty($habilitaciones)): ?>
      <div class="empty"><i class="fas fa-inbox"></i><p>No hay estudiantes con pre-defensa aprobada</p></div>
      <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th>#</th><th>Estudiante</th><th>CI</th><th>RU</th>
            <th>Carrera</th><th>Modalidad</th><th>Tema</th>
            <th>Nota</th><th>Fecha Pre-def.</th><th>Tutor</th>
            <th>Estado Ministerio</th><th>Fecha Habilitación</th><th>Acción</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($habilitaciones as $i => $h): ?>
          <tr>
            <td class="mono" style="color:var(--muted)"><?= $i+1 ?></td>
            <td class="fw7"><?= htmlspecialchars($h['nombre_completo']) ?></td>
            <td class="mono"><?= htmlspecialchars($h['ci']) ?></td>
            <td class="mono fw7"><?= htmlspecialchars($h['ru']) ?></td>
            <td style="font-size:.78rem"><?= htmlspecialchars($h['nombre_carrera'] ?? '—') ?></td>
            <td style="font-size:.77rem"><?= $mod_labels[$h['modalidad_titulacion'] ?? ''] ?? '—' ?></td>
            <td style="font-size:.77rem;max-width:160px;white-space:normal"><?= $h['tema'] ? htmlspecialchars(mb_strimwidth($h['tema'],0,55,'…')) : '<span style="color:var(--muted)">—</span>' ?></td>
            <td><span style="font-weight:800;font-size:1.05rem;color:var(--green)"><?= $h['nota'] ?></span></td>
            <td class="mono" style="font-size:.77rem"><?= $h['fecha_predefensa'] ? date('d/m/Y',strtotime($h['fecha_predefensa'])) : '—' ?></td>
            <td style="font-size:.78rem;font-weight:600"><?= htmlspecialchars($h['tutor_nombre'] ?? '—') ?></td>
            <td>
              <?php if($h['esta_habilitado']): ?>
                <span class="badge b-habilitado"><i class="fas fa-check-circle"></i> HABILITADO</span>
              <?php else: ?>
                <span class="badge b-pendiente">PENDIENTE</span>
              <?php endif; ?>
            </td>
            <td class="mono" style="font-size:.77rem"><?= $h['fecha_validacion'] ? date('d/m/Y H:i',strtotime($h['fecha_validacion'])) : '—' ?></td>
            <td>
              <?php if(!$h['esta_habilitado']): ?>
                <button class="btn btn-sm btn-green" onclick="abrirHabilitar('<?= $h['ru'] ?>','<?= htmlspecialchars(addslashes($h['nombre_completo'])) ?>')">
                  <i class="fas fa-user-check"></i> Habilitar
                </button>
              <?php else: ?>
                <span style="color:var(--green);font-weight:700;font-size:.8rem"><i class="fas fa-check-double me-1"></i>Completado</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════
       TAB 3 — DEFENSA FORMAL
  ══════════════════════════════ -->
  <?php if($tab === 'defensa'): ?>
  <div class="panel reveal r2">
    <div class="panel-head">
      <span class="panel-title"><i class="fas fa-award me-2 text-gold"></i>Defensa Formal — Estudiantes Habilitados</span>
    </div>
    <div class="tbl-wrap">
      <?php if(empty($defensas)): ?>
      <div class="empty"><i class="fas fa-inbox"></i><p>No hay estudiantes habilitados para defensa formal</p></div>
      <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th>#</th><th>Estudiante</th><th>RU</th><th>Carrera</th>
            <th>Modalidad</th><th>Nota Pre-def.</th>
            <th>Tutor</th><th>Presidente</th><th>Secretario</th>
            <th>Fecha Def.</th><th>Hora</th><th>Aula</th>
            <th>Estado</th><th>Nota Final</th>
            <th>Días Rest.</th><th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($defensas as $i => $d): ?>
          <?php $dias = max(0,(int)$d['dias_restantes']); ?>
          <tr>
            <td class="mono" style="color:var(--muted)"><?= $i+1 ?></td>
            <td class="fw7"><?= htmlspecialchars($d['nombre_completo']) ?></td>
            <td class="mono fw7"><?= htmlspecialchars($d['ru']) ?></td>
            <td style="font-size:.78rem"><?= htmlspecialchars($d['nombre_carrera'] ?? '—') ?></td>
            <td style="font-size:.77rem"><?= $mod_labels[$d['modalidad_titulacion'] ?? ''] ?? '—' ?></td>
            <td><span style="font-weight:800;font-size:1.05rem;color:var(--green)"><?= $d['nota_predefensa'] ?></span></td>
            <td style="font-size:.78rem;font-weight:600"><?= htmlspecialchars($d['tutor_completo'] ?? '—') ?></td>
            <td style="font-size:.78rem"><?= htmlspecialchars($d['presidente_completo'] ?? '—') ?></td>
            <td style="font-size:.78rem"><?= htmlspecialchars($d['secretario_completo'] ?? '—') ?></td>
            <td class="mono" style="font-size:.78rem">
              <?= $d['fecha_defensa'] ? date('d/m/Y',strtotime($d['fecha_defensa'])) : '<span style="color:var(--muted)">Sin fecha</span>' ?>
            </td>
            <td class="mono" style="font-size:.78rem"><?= $d['hora_defensa'] ? substr($d['hora_defensa'],0,5) : '—' ?></td>
            <td style="font-size:.78rem"><?= htmlspecialchars($d['aula_defensa'] ?? '—') ?></td>
            <td>
              <?php if($d['estado_defensa']): ?>
                <span class="badge b-programada"><?= $d['estado_defensa'] ?></span>
              <?php elseif($d['puede_programar']): ?>
                <span class="badge b-disponible">DISPONIBLE</span>
              <?php else: ?>
                <span class="badge b-pendiente">EN ESPERA</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if($d['nota_final'] !== null): ?>
                <span style="font-weight:800;color:var(--blue)"><?= $d['nota_final'] ?></span>
              <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
            </td>
            <td>
              <?php if($d['puede_programar'] || $d['id_defensa']): ?>
                <span style="color:var(--green);font-weight:700;font-size:.78rem"><i class="fas fa-check"></i> Listo</span>
              <?php else: ?>
                <span class="badge b-pendiente"><i class="fas fa-hourglass-half"></i> <?= $dias ?> días</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="btn-group-actions">
                <?php
                $es_gastro = stripos($d['nombre_carrera'] ?? '', 'GASTRONOM') !== false;
                $nombre_js = htmlspecialchars(addslashes($d['nombre_completo']));
                $id_pre    = $d['id_pre_defensa'];
                ?>
                <?php if($d['id_defensa']): ?>
                  <!-- EDITAR FECHA -->
                  <button class="btn btn-sm btn-ghost" style="font-size:.72rem" onclick="abrirEditar(<?= $id_pre ?>,'<?= $nombre_js ?>','<?= $d['fecha_validacion'] ?>','<?= $d['fecha_defensa'] ?>','<?= substr($d['hora_defensa']??'',0,5) ?>','<?= htmlspecialchars(addslashes($d['aula_defensa'] ?? '')) ?>')">
                    <i class="fas fa-calendar-pen"></i> Editar Fecha
                  </button>
                  <?php if(!$es_gastro): ?>
                    <button class="btn btn-sm btn-ghost" style="font-size:.72rem;color:#b45309;border-color:var(--gold)" onclick="abrirDocumento(<?= $id_pre ?>,'interna','<?= $nombre_js ?>')">
                      <i class="fas fa-file-alt"></i> Nota Interna
                    </button>
                    <button class="btn btn-sm btn-ghost" style="font-size:.72rem;color:var(--cyan);border-color:var(--cyan)" onclick="abrirDocumento(<?= $id_pre ?>,'uto','<?= $nombre_js ?>')">
                      <i class="fas fa-university"></i> Nota UTO
                    </button>
                  <?php endif; ?>
                  <button class="btn btn-sm btn-ghost" style="font-size:.72rem;color:var(--violet);border-color:var(--violet)" onclick="abrirDocumento(<?= $id_pre ?>,'federacion','<?= $nombre_js ?>')">
                    <i class="fas fa-building-columns"></i> Nota Fed.
                  </button>
                <?php elseif($d['puede_programar']): ?>
                  <button class="btn btn-sm btn-gold" onclick="abrirProgramar(<?= $id_pre ?>,'<?= $nombre_js ?>','<?= $d['fecha_validacion'] ?>')">
                    <i class="fas fa-calendar-plus"></i> Programar
                  </button>
                <?php else: ?>
                  <span style="font-size:.75rem;color:var(--muted)"><i class="fas fa-lock"></i> Esperar <?= $dias ?> días</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════
       TAB 4 — NUEVO REGISTRO
  ══════════════════════════════ -->
  <?php if($tab === 'registro'): ?>
  <div class="panel reveal r2">
    <div class="panel-head">
      <span class="panel-title"><i class="fas fa-user-plus me-2" style="color:var(--green)"></i>Nuevo Registro de Pre-Defensa</span>
      <span style="font-size:.75rem;color:var(--muted)">Solo estudiantes con tutor asignado y sin pre-defensa</span>
    </div>
    <div class="tbl-wrap">
      <?php if(empty($sin_predefensa)): ?>
      <div class="empty"><i class="fas fa-check-circle" style="color:var(--green);opacity:.4"></i><p>Todos los estudiantes con tutor ya tienen pre-defensa registrada</p></div>
      <?php else: ?>
      <table class="tbl">
        <thead>
          <tr><th>#</th><th>Estudiante</th><th>CI</th><th>RU</th><th>Carrera</th><th>Título del Proyecto</th><th>Tutor</th><th>Acción</th></tr>
        </thead>
        <tbody>
        <?php foreach($sin_predefensa as $i => $sp): ?>
          <tr>
            <td class="mono" style="color:var(--muted)"><?= $i+1 ?></td>
            <td class="fw7"><?= htmlspecialchars($sp['nombre_completo']) ?></td>
            <td class="mono"><?= htmlspecialchars($sp['ci']) ?></td>
            <td class="mono fw7"><?= htmlspecialchars($sp['ru']) ?></td>
            <td style="font-size:.78rem"><?= htmlspecialchars($sp['nombre_carrera'] ?? '—') ?></td>
            <td style="font-size:.78rem;max-width:200px;white-space:normal" title="<?= htmlspecialchars($sp['titulo_proyecto']) ?>">
              <?= htmlspecialchars(mb_strimwidth($sp['titulo_proyecto'],0,60,'…')) ?>
            </td>
            <td style="font-size:.78rem;font-weight:600"><?= htmlspecialchars($sp['tutor_completo']) ?></td>
            <td>
              <button class="btn btn-sm btn-green" onclick='abrirRegistro(<?= json_encode([
                "id_estudiante"=>$sp["id_estudiante"],
                "id_proyecto"=>$sp["id_proyecto"],
                "nombre"=>$sp["nombre_completo"],
                "carrera"=>$sp["nombre_carrera"] ?? "",
                "proyecto"=>$sp["titulo_proyecto"],
                "tutor"=>$sp["tutor_completo"],
                "id_tutor"=>$sp["id_tutor"],
              ],JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                <i class="fas fa-plus"></i> Registrar
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

</main>

<!-- ══════════════════════════════
     MODAL — HABILITACIÓN
══════════════════════════════ -->
<div class="modal fade" id="modalHabilitar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header cyan">
        <h5 class="modal-title"><i class="fas fa-clipboard-check me-2"></i>Habilitación al Ministerio</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="info-card mb-4" id="hab-info">
          <div class="info-card-lbl">Estudiante</div>
          <div class="info-card-val" id="hab-nombre"></div>
          <div class="info-card-sub">Se registrará la habilitación con fecha y hora actual</div>
        </div>
        <p style="font-size:.85rem;color:var(--muted);margin-bottom:14px">Verifique los requisitos antes de confirmar:</p>
        <div class="checklist">
          <div class="check-row"><input type="checkbox" id="chk1" onchange="chkVerify()"><label for="chk1">Pre-defensa aprobada con nota ≥ 41 puntos</label></div>
          <div class="check-row"><input type="checkbox" id="chk2" onchange="chkVerify()"><label for="chk2">Documentación completa y verificada</label></div>
          <div class="check-row"><input type="checkbox" id="chk3" onchange="chkVerify()"><label for="chk3">Comprobante de pago del proceso de titulación</label></div>
          <div class="check-row"><input type="checkbox" id="chk4" onchange="chkVerify()"><label for="chk4">Solicitud formal del estudiante presentada</label></div>
          <div class="check-row"><input type="checkbox" id="chk5" onchange="chkVerify()"><label for="chk5">Datos del estudiante validados (CI y RU correctos)</label></div>
        </div>
        <input type="hidden" id="hab-ru">
      </div>
      <div class="modal-footer gap-2">
        <button class="btn btn-md btn-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-md btn-green" id="btnHab" disabled onclick="confirmarHabilitar()">
          <i class="fas fa-check-circle"></i> Confirmar Habilitación
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════
     MODAL — PROGRAMAR DEFENSA
══════════════════════════════ -->
<div class="modal fade" id="modalProgramar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>Programar Defensa Formal</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="info-card mb-4">
          <div class="info-card-lbl">Estudiante</div>
          <div class="info-card-val" id="prog-nombre"></div>
        </div>
        <input type="hidden" id="prog-id">
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <label class="form-lbl">Fecha de Defensa *</label>
            <input type="date" id="prog-fecha" class="form-ctrl">
            <small id="prog-hint" style="font-size:.73rem;color:var(--muted);margin-top:4px;display:block"></small>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-lbl">Hora *</label>
            <input type="time" id="prog-hora" class="form-ctrl">
          </div>
          <div class="col-12">
            <label class="form-lbl">Aula / Ambiente *</label>
            <select id="prog-aula" class="form-ctrl">
              <option value="">Seleccione aula…</option>
              <?php foreach($aulas as $a): ?>
              <option value="<?= $a['id_aula'] ?>"><?= htmlspecialchars($a['nombre_aula']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer gap-2">
        <button class="btn btn-md btn-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-md btn-gold" onclick="guardarProgramar(false)">
          <i class="fas fa-calendar-check"></i> Programar Defensa
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════
     MODAL — EDITAR DEFENSA
══════════════════════════════ -->
<div class="modal fade" id="modalEditar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,var(--blue),var(--blue-2))">
        <h5 class="modal-title"><i class="fas fa-calendar-pen me-2"></i>Editar Fecha de Defensa</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="info-card mb-4">
          <div class="info-card-lbl">Estudiante</div>
          <div class="info-card-val" id="edit-nombre"></div>
          <div class="info-card-sub">Se actualizarán los datos de la defensa formal existente</div>
        </div>
        <input type="hidden" id="edit-id">
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <label class="form-lbl">Nueva Fecha *</label>
            <input type="date" id="edit-fecha" class="form-ctrl">
            <small id="edit-hint" style="font-size:.73rem;color:var(--muted);margin-top:4px;display:block"></small>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-lbl">Hora *</label>
            <input type="time" id="edit-hora" class="form-ctrl">
          </div>
          <div class="col-12">
            <label class="form-lbl">Aula / Ambiente *</label>
            <select id="edit-aula" class="form-ctrl">
              <option value="">Seleccione aula…</option>
              <?php foreach($aulas as $a): ?>
              <option value="<?= $a['id_aula'] ?>"><?= htmlspecialchars($a['nombre_aula']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer gap-2">
        <button class="btn btn-md btn-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-md btn-blue" onclick="guardarEdicion()">
          <i class="fas fa-save"></i> Guardar Cambios
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════
     MODAL — REGISTRO PRE-DEFENSA
══════════════════════════════ -->
<div class="modal fade" id="modalRegistro" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header green">
        <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Registrar Pre-Defensa</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <div class="info-card">
              <div class="info-card-lbl">Estudiante</div>
              <div class="info-card-val" id="reg-nombre"></div>
              <div class="info-card-sub" id="reg-carrera"></div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="info-card" style="border-color:rgba(59,91,219,.2);background:var(--blue-3)">
              <div class="info-card-lbl">Tutor</div>
              <div class="info-card-val" id="reg-tutor"></div>
              <div class="info-card-sub" id="reg-proyecto"></div>
            </div>
          </div>
        </div>
        <input type="hidden" id="reg-id-est">
        <input type="hidden" id="reg-id-proy">
        <input type="hidden" id="reg-id-tutor">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-lbl">Modalidad de Titulación *</label>
            <select id="reg-modalidad" class="form-ctrl" onchange="toggleTema()">
              <option value="">Seleccione…</option>
              <option value="EXAMEN_GRADO">Examen de Grado</option>
              <option value="PROYECTO_GRADO">Proyecto de Grado</option>
              <option value="TESIS">Tesis de Grado</option>
              <option value="TRABAJO_DIRIGIDO">Trabajo Dirigido</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-lbl">Gestión</label>
            <input type="text" id="reg-gestion" class="form-ctrl" value="<?= date('Y') ?>" readonly>
          </div>
          <div class="col-12" id="tema-wrap">
            <label class="form-lbl">Tema *</label>
            <textarea id="reg-tema" class="form-ctrl" rows="2" placeholder="Ingrese el tema de titulación…"></textarea>
          </div>
          <div class="col-md-4">
            <label class="form-lbl">Fecha *</label>
            <input type="date" id="reg-fecha" class="form-ctrl" min="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-lbl">Hora *</label>
            <input type="time" id="reg-hora" class="form-ctrl">
          </div>
          <div class="col-md-4">
            <label class="form-lbl">Aula *</label>
            <select id="reg-aula" class="form-ctrl">
              <option value="">Seleccione…</option>
              <?php foreach($aulas as $a): ?>
              <option value="<?= $a['id_aula'] ?>"><?= htmlspecialchars($a['nombre_aula']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-lbl">Presidente del Tribunal *</label>
            <select id="reg-pres" class="form-ctrl">
              <option value="">Seleccione presidente…</option>
              <?php foreach($docentes_tribunal as $dt): ?>
              <option value="<?= $dt['id_persona'] ?>"><?= htmlspecialchars($dt['nombre_completo']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-lbl">Secretario del Tribunal *</label>
            <select id="reg-sec" class="form-ctrl">
              <option value="">Seleccione secretario…</option>
              <?php foreach($docentes_tribunal as $dt): ?>
              <option value="<?= $dt['id_persona'] ?>"><?= htmlspecialchars($dt['nombre_completo']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer gap-2">
        <button class="btn btn-md btn-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-md btn-green" onclick="guardarPreDefensa()">
          <i class="fas fa-save"></i> Registrar Pre-Defensa
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════
     MODAL — GENERAR DOCUMENTO
══════════════════════════════ -->
<div class="modal fade" id="modalDoc" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" id="doc-hdr">
        <h5 class="modal-title" id="doc-title"><i class="fas fa-file-alt me-2"></i> Generar Nota</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center" style="padding:32px 26px">
        <div id="doc-icon" style="width:64px;height:64px;border-radius:16px;margin:0 auto 18px;display:flex;align-items:center;justify-content:center;font-size:1.8rem"></div>
        <h6 class="fw8 mb-1" id="doc-subtitle"></h6>
        <p style="font-size:.82rem;color:var(--muted);margin-bottom:6px" id="doc-estudiante"></p>
        <p style="font-size:.85rem;color:var(--ink-3)" id="doc-desc"></p>
        <input type="hidden" id="doc-id">
        <input type="hidden" id="doc-tipo">
      </div>
      <div class="modal-footer justify-content-center gap-2">
        <button class="btn btn-md btn-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-md" id="doc-btn" onclick="ejecutarDocumento()">
          <i class="fas fa-file-download"></i> Generar
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ======================== TOAST ========================
function toast(msg, type='success'){
  const el = document.getElementById('toastEl');
  const ic = document.getElementById('toastIcon');
  document.getElementById('toastMsg').textContent = msg;
  el.className = 'toast-msg toast-'+type+' show';
  ic.className = type==='success' ? 'fas fa-circle-check' : 'fas fa-circle-exclamation';
  setTimeout(()=> el.classList.remove('show'), 4000);
}

// ======================== AJAX ========================
function ajax(data){
  const fd = new FormData();
  for(const [k,v] of Object.entries(data)) fd.append(k,v);
  return fetch(location.pathname, {method:'POST', body:fd}).then(r=>r.json());
}

// ======================== HABILITACIÓN ========================
function abrirHabilitar(ru, nombre){
  document.getElementById('hab-ru').value = ru;
  document.getElementById('hab-nombre').textContent = nombre;
  for(let i=1;i<=5;i++) document.getElementById('chk'+i).checked = false;
  document.getElementById('btnHab').disabled = true;
  new bootstrap.Modal(document.getElementById('modalHabilitar')).show();
}
function chkVerify(){
  let ok = true;
  for(let i=1;i<=5;i++) if(!document.getElementById('chk'+i).checked){ ok=false;break; }
  document.getElementById('btnHab').disabled = !ok;
}
function confirmarHabilitar(){
  const btn = document.getElementById('btnHab');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando…';
  ajax({ajax_action:'habilitar_ministerio', ru_estudiante:document.getElementById('hab-ru').value})
    .then(d=>{
      if(d.success){ toast(d.message); bootstrap.Modal.getInstance(document.getElementById('modalHabilitar')).hide(); setTimeout(()=>location.reload(),1200); }
      else{ toast(d.message,'error'); btn.disabled=false; btn.innerHTML='<i class="fas fa-check-circle"></i> Confirmar Habilitación'; }
    }).catch(()=>{ toast('Error de conexión','error'); btn.disabled=false; btn.innerHTML='<i class="fas fa-check-circle"></i> Confirmar Habilitación'; });
}

// ======================== PROGRAMAR DEFENSA ========================
function fechaMinModal(fechaValidacion){
  const fh = new Date(fechaValidacion);
  fh.setDate(fh.getDate()+30);
  const hoy = new Date();
  const fm = fh > hoy ? fh : hoy;
  return fm.toISOString().split('T')[0];
}
function abrirProgramar(id, nombre, fechaValidacion){
  document.getElementById('prog-id').value = id;
  document.getElementById('prog-nombre').textContent = nombre;
  const min = fechaMinModal(fechaValidacion);
  document.getElementById('prog-fecha').min = min;
  document.getElementById('prog-fecha').value = '';
  document.getElementById('prog-hora').value = '';
  document.getElementById('prog-aula').value = '';
  document.getElementById('prog-hint').textContent = 'Fecha mínima: '+min.split('-').reverse().join('/');
  new bootstrap.Modal(document.getElementById('modalProgramar')).show();
}
function guardarProgramar(){
  ajax({
    ajax_action:'programar_defensa',
    id_pre_defensa:document.getElementById('prog-id').value,
    fecha_defensa:document.getElementById('prog-fecha').value,
    hora_defensa:document.getElementById('prog-hora').value,
    id_aula_defensa:document.getElementById('prog-aula').value,
  }).then(d=>{
    if(d.success){ toast(d.message); bootstrap.Modal.getInstance(document.getElementById('modalProgramar')).hide(); setTimeout(()=>location.reload(),1200); }
    else toast(d.message,'error');
  }).catch(()=>toast('Error de conexión','error'));
}

// ======================== EDITAR FECHA DEFENSA ========================
function abrirEditar(id, nombre, fechaValidacion, fechaActual, horaActual, aulaActual){
  document.getElementById('edit-id').value = id;
  document.getElementById('edit-nombre').textContent = nombre;
  const min = fechaMinModal(fechaValidacion);
  const editFecha = document.getElementById('edit-fecha');
  editFecha.min = min;
  editFecha.value = fechaActual || '';
  document.getElementById('edit-hora').value = horaActual || '';
  // Buscar aula por nombre
  const selAula = document.getElementById('edit-aula');
  selAula.value = '';
  for(let opt of selAula.options){ if(opt.text === aulaActual){ selAula.value = opt.value; break; } }
  document.getElementById('edit-hint').textContent = 'Fecha mínima: '+min.split('-').reverse().join('/');
  new bootstrap.Modal(document.getElementById('modalEditar')).show();
}
function guardarEdicion(){
  ajax({
    ajax_action:'editar_defensa',
    id_pre_defensa:document.getElementById('edit-id').value,
    fecha_defensa:document.getElementById('edit-fecha').value,
    hora_defensa:document.getElementById('edit-hora').value,
    id_aula_defensa:document.getElementById('edit-aula').value,
  }).then(d=>{
    if(d.success){ toast(d.message); bootstrap.Modal.getInstance(document.getElementById('modalEditar')).hide(); setTimeout(()=>location.reload(),1200); }
    else toast(d.message,'error');
  }).catch(()=>toast('Error de conexión','error'));
}

// ======================== REGISTRO PRE-DEFENSA ========================
function abrirRegistro(data){
  document.getElementById('reg-id-est').value   = data.id_estudiante;
  document.getElementById('reg-id-proy').value  = data.id_proyecto;
  document.getElementById('reg-id-tutor').value = data.id_tutor;
  document.getElementById('reg-nombre').textContent  = data.nombre;
  document.getElementById('reg-carrera').textContent = data.carrera;
  document.getElementById('reg-tutor').textContent   = data.tutor;
  document.getElementById('reg-proyecto').textContent = data.proyecto.length>60 ? data.proyecto.substring(0,60)+'…' : data.proyecto;
  ['reg-modalidad','reg-tema','reg-fecha','reg-hora','reg-aula','reg-pres','reg-sec'].forEach(id=>{ const el=document.getElementById(id); el.value=''; });
  toggleTema();
  new bootstrap.Modal(document.getElementById('modalRegistro')).show();
}
function toggleTema(){
  document.getElementById('tema-wrap').style.display = document.getElementById('reg-modalidad').value==='EXAMEN_GRADO' ? 'none' : '';
}
function guardarPreDefensa(){
  const pres = document.getElementById('reg-pres').value;
  const sec  = document.getElementById('reg-sec').value;
  const tutor = document.getElementById('reg-id-tutor').value;
  if((pres && pres===tutor)||(sec && sec===tutor)){ toast('Los miembros del tribunal no pueden ser el tutor del estudiante','error'); return; }
  if(pres && sec && pres===sec){ toast('Presidente y Secretario deben ser personas diferentes','error'); return; }
  ajax({
    ajax_action:'registrar_predefensa',
    id_estudiante:document.getElementById('reg-id-est').value,
    id_proyecto:document.getElementById('reg-id-proy').value,
    modalidad_titulacion:document.getElementById('reg-modalidad').value,
    tema:document.getElementById('reg-tema').value,
    fecha:document.getElementById('reg-fecha').value,
    hora:document.getElementById('reg-hora').value,
    id_aula:document.getElementById('reg-aula').value,
    gestion:document.getElementById('reg-gestion').value,
    id_presidente:pres,
    id_secretario:sec,
  }).then(d=>{
    if(d.success){ toast(d.message); bootstrap.Modal.getInstance(document.getElementById('modalRegistro')).hide(); setTimeout(()=>location.reload(),1200); }
    else toast(d.message,'error');
  }).catch(()=>toast('Error de conexión','error'));
}
document.addEventListener('change',e=>{
  if(e.target.id==='reg-pres'||e.target.id==='reg-sec'){
    const p=document.getElementById('reg-pres').value, s=document.getElementById('reg-sec').value;
    if(p&&s&&p===s){ toast('Presidente y Secretario deben ser diferentes','error'); e.target.value=''; }
  }
});

// ======================== GENERAR DOCUMENTOS ========================
const DOC_CFG = {
  interna:   { title:'Nota Interna',              subtitle:'¿Generar la Nota Interna?',                    desc:'Solicitud de pago a Tribunal Externo — UTO. Se generará el documento con los datos de la defensa.',          icon:'fas fa-file-alt',      color:'#b45309', bg:'rgba(245,158,11,.1)', btnCls:'btn-ghost', btnStyle:'color:#b45309;border-color:var(--gold)', url:'../controllers/generar_nota_interna.php?id=' },
  uto:       { title:'Nota Externa — UTO',        subtitle:'¿Generar la Nota Externa para la UTO?',        desc:'Solicitud de designación de Tribunal Externo a la Universidad Técnica de Oruro.',                           icon:'fas fa-university',    color:'var(--cyan)', bg:'var(--cyan-3)', btnCls:'btn-cyan', btnStyle:'', url:'../controllers/generar_nota_externa.php?tipo=UTO&id=' },
  federacion:{ title:'Nota Externa — Federación', subtitle:'¿Generar la Nota para la Federación?',         desc:'Solicitud de designación de Veedor/Revisor a la Federación Departamental de Profesionales de Oruro.',       icon:'fas fa-building-columns',color:'var(--violet)', bg:'var(--violet-3)', btnCls:'btn-violet', btnStyle:'', url:'../controllers/generar_nota_externa.php?tipo=FEDERACION&id=' },
};
function abrirDocumento(idPre, tipo, nombreEst){
  const cfg = DOC_CFG[tipo]; if(!cfg) return;
  document.getElementById('doc-id').value   = idPre;
  document.getElementById('doc-tipo').value = tipo;
  document.getElementById('doc-title').innerHTML     = '<i class="'+cfg.icon+' me-2"></i>'+cfg.title;
  document.getElementById('doc-hdr').style.background = cfg.bg.startsWith('var')
    ? (tipo==='uto' ? 'linear-gradient(135deg,var(--cyan),#0891b2)' : 'linear-gradient(135deg,var(--violet),#6d28d9)')
    : 'linear-gradient(135deg,#d97706,#b45309)';
  document.getElementById('doc-subtitle').textContent = cfg.subtitle;
  document.getElementById('doc-estudiante').textContent = nombreEst;
  document.getElementById('doc-desc').textContent = cfg.desc;
  document.getElementById('doc-icon').style.background = cfg.bg;
  document.getElementById('doc-icon').style.color = cfg.color;
  document.getElementById('doc-icon').innerHTML = '<i class="'+cfg.icon+'"></i>';
  const btn = document.getElementById('doc-btn');
  btn.className = 'btn btn-md '+cfg.btnCls;
  if(cfg.btnStyle) btn.style.cssText = cfg.btnStyle;
  btn.innerHTML = '<i class="fas fa-file-download"></i> Generar '+cfg.title;
  new bootstrap.Modal(document.getElementById('modalDoc')).show();
}
function ejecutarDocumento(){
  const id   = document.getElementById('doc-id').value;
  const tipo = document.getElementById('doc-tipo').value;
  const cfg  = DOC_CFG[tipo]; if(!cfg) return;
  // Notificar al backend que se generó (sin modificar código)
  ajax({ajax_action:'marcar_nota_generada', id_pre_defensa:id, tipo_nota:tipo});
  bootstrap.Modal.getInstance(document.getElementById('modalDoc')).hide();
  toast('Generando documento — la descarga iniciará en breve', 'success');
  setTimeout(()=>{ window.location.href = cfg.url+id; }, 600);
}
</script>
</body>
</html>