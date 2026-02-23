<?php
declare(strict_types=1);
session_start();
require_once '../config/conexion.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$nombre_usuario = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$inicial = strtoupper(mb_substr($nombre_usuario, 0, 1, 'UTF-8'));
$rol = htmlspecialchars((string)($_SESSION["role"] ?? 'Invitado'), ENT_QUOTES, 'UTF-8');
$es_admin = (strtoupper($_SESSION["role"] ?? '') === 'ADMINISTRADOR');
$ruta_logo = "https://unior.edu.bo/favicon.svg";
$banner_url = "https://th.bing.com/th/id/OIP.fGbv34hHN0EA_eJ2Mm9NqwHaCv?w=331&h=129&c=7&r=0&o=7&dpr=1.3&pid=1.7&rm=3";

// ==================== AJAX ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        switch ($_POST['ajax_action']) {

            case 'habilitar_ministerio':
                $ru = trim($_POST['ru_estudiante'] ?? '');
                if (empty($ru)) { echo json_encode(['success'=>false,'message'=>'RU requerido']); exit; }
                $stmt = $pdo->prepare("SELECT pd.estado FROM pre_defensas pd JOIN estudiantes e ON pd.id_estudiante = e.id_persona WHERE e.ru = ? AND pd.estado = 'APROBADA' LIMIT 1");
                $stmt->execute([$ru]);
                if (!$stmt->fetch()) { echo json_encode(['success'=>false,'message'=>'No tiene pre-defensa aprobada']); exit; }
                $stmt = $pdo->prepare("INSERT INTO habilitacion_ministerio (ru_estudiante,esta_habilitado,fecha_validacion) VALUES (?,true,NOW()) ON CONFLICT (ru_estudiante) DO UPDATE SET esta_habilitado=true,fecha_validacion=NOW()");
                $stmt->execute([$ru]);
                echo json_encode(['success'=>true,'message'=>'Estudiante habilitado exitosamente']);
                exit;

            case 'registrar_predefensa':
                $id_est  = (int)($_POST['id_estudiante'] ?? 0);
                $id_proy = (int)($_POST['id_proyecto'] ?? 0);
                $modal   = trim($_POST['modalidad_titulacion'] ?? '');
                $tema    = trim($_POST['tema'] ?? '');
                $fecha   = trim($_POST['fecha'] ?? '');
                $hora    = trim($_POST['hora'] ?? '');
                $id_aula = (int)($_POST['id_aula'] ?? 0);
                $gestion = trim($_POST['gestion'] ?? date('Y'));
                $id_pres = (int)($_POST['id_presidente'] ?? 0);
                $id_sec  = (int)($_POST['id_secretario'] ?? 0);
                $err = [];
                if ($id_est<=0) $err[]='Estudiante no válido';
                if (empty($modal)) $err[]='Modalidad requerida';
                if ($modal!=='EXAMEN_GRADO' && empty($tema)) $err[]='El tema es requerido';
                if (empty($fecha)) $err[]='Fecha requerida';
                if (empty($hora)) $err[]='Hora requerida';
                if ($id_aula<=0) $err[]='Aula requerida';
                if ($id_pres<=0) $err[]='Presidente requerido';
                if ($id_sec<=0) $err[]='Secretario requerido';
                if (!empty($fecha)&&strtotime($fecha)<strtotime(date('Y-m-d'))) $err[]='No se permiten fechas pasadas';
                if ($id_pres===$id_sec && $id_pres>0) $err[]='Presidente y Secretario deben ser diferentes';
                $stmt = $pdo->prepare("SELECT id_tutor FROM proyectos WHERE id_proyecto=?");
                $stmt->execute([$id_proy]); $tr = $stmt->fetch(PDO::FETCH_ASSOC);
                $id_tutor = $tr ? (int)$tr['id_tutor'] : 0;
                if ($id_tutor>0&&($id_pres===$id_tutor||$id_sec===$id_tutor)) $err[]='El tribunal no puede incluir al tutor';
                $stmt = $pdo->prepare("SELECT id_pre_defensa FROM pre_defensas WHERE id_estudiante=? AND gestion=?");
                $stmt->execute([$id_est,$gestion]);
                if ($stmt->fetch()) $err[]='Ya existe pre-defensa en esta gestión';
                if (!empty($err)) { echo json_encode(['success'=>false,'message'=>implode('. ',$err)]); exit; }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO pre_defensas (id_estudiante,gestion,fecha,hora,estado,id_tutor,id_proyecto,id_aula,modalidad_titulacion,tema,id_presidente,id_secretario) VALUES (?,?,?,?,'PENDIENTE',?,?,?,?,?,?,?)");
                $stmt->execute([$id_est,$gestion,$fecha,$hora,$id_tutor,$id_proy,$id_aula,$modal,$modal==='EXAMEN_GRADO'?null:$tema,$id_pres,$id_sec]);
                $pdo->commit();
                echo json_encode(['success'=>true,'message'=>'Pre-defensa registrada exitosamente']);
                exit;

            case 'programar_defensa':
            case 'editar_defensa':
                $id_pre  = (int)($_POST['id_pre_defensa'] ?? 0);
                $f_def   = trim($_POST['fecha_defensa'] ?? '');
                $h_def   = trim($_POST['hora_defensa'] ?? '');
                $id_aula = (int)($_POST['id_aula_defensa'] ?? 0);
                $es_ed   = ($_POST['ajax_action'] === 'editar_defensa');
                $err = [];
                if (empty($f_def)) $err[]='Fecha requerida';
                if (empty($h_def)) $err[]='Hora requerida';
                if ($id_aula<=0) $err[]='Aula requerida';
                if (!empty($f_def)&&strtotime($f_def)<strtotime(date('Y-m-d'))) $err[]='No se permiten fechas pasadas';
                $stmt = $pdo->prepare("SELECT pd.id_estudiante,pd.id_proyecto,e.ru,hm.fecha_validacion,hm.esta_habilitado FROM pre_defensas pd JOIN estudiantes e ON pd.id_estudiante=e.id_persona LEFT JOIN habilitacion_ministerio hm ON e.ru=hm.ru_estudiante WHERE pd.id_pre_defensa=? AND pd.estado='APROBADA'");
                $stmt->execute([$id_pre]); $pd = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$pd) $err[]='Pre-defensa no encontrada o no aprobada';
                elseif (!$pd['esta_habilitado']) $err[]='Estudiante no habilitado';
                else { $dh=new DateTime($pd['fecha_validacion']); $dd=new DateTime($f_def); if($dh->diff($dd)->days<30) $err[]='Deben pasar 30 días desde la habilitación. Faltan '.(30-$dh->diff($dd)->days).' días'; }
                if (!empty($err)) { echo json_encode(['success'=>false,'message'=>implode('. ',$err)]); exit; }
                $pdo->beginTransaction();
                if ($es_ed) {
                    $stmt=$pdo->prepare("UPDATE defensa_formal SET fecha_defensa=?,hora=?,id_aula=? WHERE id_pre_defensa=?");
                    $stmt->execute([$f_def,$h_def,$id_aula,$id_pre]); $msg='Defensa reprogramada exitosamente';
                } else {
                    $stmt=$pdo->prepare("INSERT INTO defensa_formal (id_pre_defensa,id_estudiante,id_proyecto,fecha_defensa,hora,id_aula,estado) VALUES (?,?,?,?,?,?,'PROGRAMADA')");
                    $stmt->execute([$id_pre,$pd['id_estudiante'],$pd['id_proyecto'],$f_def,$h_def,$id_aula]); $msg='Defensa programada exitosamente';
                }
                $pdo->commit();
                echo json_encode(['success'=>true,'message'=>$msg]);
                exit;

            case 'marcar_nota_generada':
                $id_pre = (int)($_POST['id_pre_defensa'] ?? 0);
                $stmt = $pdo->prepare("UPDATE defensa_formal SET nota_generada_en=NOW() WHERE id_pre_defensa=? AND nota_generada_en IS NULL");
                $stmt->execute([$id_pre]);
                echo json_encode(['success'=>true]);
                exit;

            default:
                echo json_encode(['success'=>false,'message'=>'Acción no válida']); exit;
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        echo json_encode(['success'=>false,'message'=>'Error de base de datos']);
        exit;
    }
}

// ==================== DATOS ====================
$tab            = $_GET['tab']    ?? 'listado';
$filtro_carrera = $_GET['carrera'] ?? '';
$filtro_estado  = $_GET['estado']  ?? '';
$busqueda       = trim($_GET['q'] ?? '');

$mod_labels = ['EXAMEN_GRADO'=>'Examen de Grado','PROYECTO_GRADO'=>'Proyecto de Grado','TESIS'=>'Tesis de Grado','TRABAJO_DIRIGIDO'=>'Trabajo Dirigido'];

try {
    $carreras         = $pdo->query("SELECT id_carrera,nombre_carrera FROM carreras ORDER BY nombre_carrera")->fetchAll(PDO::FETCH_ASSOC);
    $aulas            = $pdo->query("SELECT id_aula,nombre_aula FROM aulas ORDER BY nombre_aula")->fetchAll(PDO::FETCH_ASSOC);
    $docentes_trib    = $pdo->query("SELECT d.id_persona, p.primer_nombre||' '||COALESCE(p.segundo_nombre||' ','')||p.primer_apellido||' '||COALESCE(p.segundo_apellido,'') as nombre_completo FROM docentes d JOIN personas p ON d.id_persona=p.id_persona WHERE d.es_tribunal=true ORDER BY p.primer_apellido,p.primer_nombre")->fetchAll(PDO::FETCH_ASSOC);

    // ── LISTADO ──
    $wp=[]; $pr=[];
    if(!empty($filtro_carrera)){$wp[]="c.id_carrera=?";$pr[]=(int)$filtro_carrera;}
    if(!empty($filtro_estado)){$wp[]="COALESCE(pd.estado,'SIN_PREDEFENSA')=?";$pr[]=$filtro_estado;}
    if(!empty($busqueda)){$wp[]="(LOWER(p.primer_nombre||' '||p.primer_apellido) LIKE LOWER(?) OR p.ci LIKE ? OR e.ru LIKE ?)";$pr[]="%$busqueda%";$pr[]="%$busqueda%";$pr[]="%$busqueda%";}
    $wsql=!empty($wp)?'WHERE '.implode(' AND ',$wp):'';
    $stmt=$pdo->prepare("SELECT e.id_persona as id_estudiante,p.ci,e.ru,p.primer_nombre,COALESCE(p.segundo_nombre,'') as segundo_nombre,p.primer_apellido,COALESCE(p.segundo_apellido,'') as segundo_apellido,c.nombre_carrera,c.id_carrera,pd.id_pre_defensa,pd.estado as estado_predefensa,pd.nota,pd.modalidad_titulacion,pd.tema,pd.fecha as fecha_predefensa,pd.hora as hora_predefensa,COALESCE(pd.estado,'SIN_PREDEFENSA') as estado_display,hm.esta_habilitado,hm.fecha_validacion,pt.primer_nombre||' '||pt.primer_apellido as tutor_nombre,pp.primer_nombre||' '||pp.primer_apellido as presidente_nombre,ps.primer_nombre||' '||ps.primer_apellido as secretario_nombre,df.id_defensa,df.fecha_defensa,df.hora as hora_defensa,a.nombre_aula as aula_defensa,df.estado as estado_defensa FROM estudiantes e JOIN personas p ON e.id_persona=p.id_persona LEFT JOIN carreras c ON e.id_carrera=c.id_carrera LEFT JOIN pre_defensas pd ON e.id_persona=pd.id_estudiante LEFT JOIN habilitacion_ministerio hm ON e.ru=hm.ru_estudiante LEFT JOIN personas pt ON pd.id_tutor=pt.id_persona LEFT JOIN personas pp ON pd.id_presidente=pp.id_persona LEFT JOIN personas ps ON pd.id_secretario=ps.id_persona LEFT JOIN defensa_formal df ON pd.id_pre_defensa=df.id_pre_defensa LEFT JOIN aulas a ON df.id_aula=a.id_aula $wsql ORDER BY p.primer_apellido ASC,p.primer_nombre ASC");
    $stmt->execute($pr); $estudiantes=$stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── HABILITACIÓN ──
    $stmt=$pdo->query("SELECT e.id_persona as id_estudiante,p.ci,e.ru,p.primer_nombre||' '||COALESCE(p.segundo_nombre||' ','')||p.primer_apellido||' '||COALESCE(p.segundo_apellido,'') as nombre_completo,c.nombre_carrera,pd.nota,pd.fecha as fecha_predefensa,pd.modalidad_titulacion,pd.tema,pd.id_pre_defensa,hm.esta_habilitado,hm.fecha_validacion,pt.primer_nombre||' '||pt.primer_apellido as tutor_nombre FROM estudiantes e JOIN personas p ON e.id_persona=p.id_persona JOIN pre_defensas pd ON e.id_persona=pd.id_estudiante LEFT JOIN carreras c ON e.id_carrera=c.id_carrera LEFT JOIN habilitacion_ministerio hm ON e.ru=hm.ru_estudiante LEFT JOIN personas pt ON pd.id_tutor=pt.id_persona WHERE pd.estado='APROBADA' ORDER BY COALESCE(hm.esta_habilitado,false) ASC,pd.fecha DESC");
    $habilitaciones=$stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── DEFENSA FORMAL ──
    $stmt=$pdo->query("SELECT e.id_persona as id_estudiante,p.ci,e.ru,p.primer_nombre||' '||COALESCE(p.segundo_nombre||' ','')||p.primer_apellido||' '||COALESCE(p.segundo_apellido,'') as nombre_completo,c.nombre_carrera,pd.id_pre_defensa,pd.nota as nota_predefensa,pd.modalidad_titulacion,pd.tema,hm.fecha_validacion,pt.primer_nombre||' '||COALESCE(pt.segundo_nombre||' ','')||pt.primer_apellido||' '||COALESCE(pt.segundo_apellido,'') as tutor_completo,pp.primer_nombre||' '||COALESCE(pp.segundo_nombre||' ','')||pp.primer_apellido||' '||COALESCE(pp.segundo_apellido,'') as presidente_completo,ps.primer_nombre||' '||COALESCE(ps.segundo_nombre||' ','')||ps.primer_apellido||' '||COALESCE(ps.segundo_apellido,'') as secretario_completo,df.id_defensa,df.fecha_defensa,df.hora as hora_defensa,df.estado as estado_defensa,df.nota_final,a.nombre_aula as aula_defensa,CASE WHEN hm.fecha_validacion IS NOT NULL THEN (CURRENT_DATE-hm.fecha_validacion::date)>=30 ELSE false END as puede_programar,CASE WHEN hm.fecha_validacion IS NOT NULL THEN 30-(CURRENT_DATE-hm.fecha_validacion::date) ELSE 30 END as dias_restantes FROM estudiantes e JOIN personas p ON e.id_persona=p.id_persona JOIN pre_defensas pd ON e.id_persona=pd.id_estudiante JOIN habilitacion_ministerio hm ON e.ru=hm.ru_estudiante AND hm.esta_habilitado=true LEFT JOIN carreras c ON e.id_carrera=c.id_carrera LEFT JOIN personas pt ON pd.id_tutor=pt.id_persona LEFT JOIN personas pp ON pd.id_presidente=pp.id_persona LEFT JOIN personas ps ON pd.id_secretario=ps.id_persona LEFT JOIN defensa_formal df ON pd.id_pre_defensa=df.id_pre_defensa LEFT JOIN aulas a ON df.id_aula=a.id_aula WHERE pd.estado='APROBADA' ORDER BY df.fecha_defensa DESC NULLS FIRST,p.primer_apellido ASC");
    $defensas=$stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── NUEVO REGISTRO ──
    $stmt=$pdo->query("SELECT e.id_persona as id_estudiante,p.ci,e.ru,p.primer_nombre||' '||COALESCE(p.segundo_nombre||' ','')||p.primer_apellido||' '||COALESCE(p.segundo_apellido,'') as nombre_completo,c.nombre_carrera,c.id_carrera,pr.id_proyecto,pr.titulo_proyecto,pt.primer_nombre||' '||COALESCE(pt.segundo_nombre||' ','')||pt.primer_apellido||' '||COALESCE(pt.segundo_apellido,'') as tutor_completo,pr.id_tutor FROM estudiantes e JOIN personas p ON e.id_persona=p.id_persona JOIN proyectos pr ON e.id_persona=pr.id_estudiante AND pr.id_tutor IS NOT NULL JOIN personas pt ON pr.id_tutor=pt.id_persona LEFT JOIN carreras c ON e.id_carrera=c.id_carrera LEFT JOIN pre_defensas pd ON e.id_persona=pd.id_estudiante WHERE pd.id_pre_defensa IS NULL ORDER BY p.primer_apellido ASC,p.primer_nombre ASC");
    $sin_predefensa=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $count_l = count($estudiantes);
    $count_h = count($habilitaciones);
    $count_d = count($defensas);
    $count_r = count($sin_predefensa);

} catch (PDOException $e) {
    error_log($e->getMessage()); die("Error al cargar datos.");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Gestión de Estudiantes | UNIOR</title>
<link rel="icon" type="image/svg+xml" href="<?= $ruta_logo ?>">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
:root {
    --primary: #4f46e5;
    --accent:  #6366f1;
    --sidebar-w: 280px;
    --sidebar-c: 85px;
    --bg-body:  #f8fafc;
}

body {
    font-family: 'Plus Jakarta Sans', sans-serif;
    background-color: var(--bg-body);
    margin: 0; display: flex; min-height: 100vh; overflow-x: hidden;
}

/* ============================================================
   SIDEBAR — idéntico al menu.php
============================================================ */
.sidebar {
    width: var(--sidebar-c);
    background: rgba(255,255,255,0.9);
    backdrop-filter: blur(25px);
    -webkit-backdrop-filter: blur(25px);
    border-right: 1px solid rgba(0,0,0,0.05);
    height: 100vh; position: fixed; left: 0; top: 0;
    display: flex; flex-direction: column; padding: 25px 15px;
    transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
    z-index: 2000;
}
@media (min-width: 992px) {
    .sidebar:hover { width: var(--sidebar-w); box-shadow: 20px 0 60px rgba(0,0,0,0.06); }
    .sidebar:hover .logo-text,
    .sidebar:hover .nav-item-ae span { opacity: 1; margin-left: 12px; }
    .sidebar:hover .logo-aesthetic img { transform: rotate(360deg); }
}
.logo-aesthetic {
    display: flex; align-items: center; gap: 15px;
    padding: 10px; margin-bottom: 40px; text-decoration: none;
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
.nav-item-ae:hover,
.nav-item-ae.active { background: white; color: var(--primary); transform: translateX(5px); }
.nav-item-ae.active { background: var(--primary) !important; color: white !important; box-shadow: 0 10px 25px rgba(79,70,229,0.2); }

/* ============================================================
   MAIN WRAPPER
============================================================ */
.main-wrapper { flex: 1; margin-left: var(--sidebar-c); padding: 40px; transition: 0.4s; width: 100%; }

/* HERO BANNER — idéntico al menu.php */
.hero-banner {
    width: 100%; height: 200px; border-radius: 45px;
    background: linear-gradient(to right, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0.1) 100%),
                url('<?= $banner_url ?>');
    background-size: cover; background-position: center; background-repeat: no-repeat;
    margin-bottom: 40px; box-shadow: 0 30px 60px rgba(0,0,0,0.12);
    display: flex; align-items: center; padding: 50px; color: white;
    border: 1px solid rgba(255,255,255,0.2);
}

/* USER PILL */
.user-pill {
    background: white; padding: 8px 15px; border-radius: 100px;
    display: inline-flex; align-items: center; gap: 12px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.03);
}
.user-avatar {
    width: 38px; height: 38px; background: var(--primary); color: white;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 1rem;
}

/* ============================================================
   STAT / TAB CARDS — estilo glass-card del menu
============================================================ */
.tab-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 24px;
    margin-bottom: 36px;
}
.tab-card {
    background: white; border-radius: 30px; padding: 28px 20px; text-align: center;
    border: 2px solid #f1f5f9; text-decoration: none; color: inherit;
    transition: 0.4s cubic-bezier(0.175,0.885,0.32,1.275);
    display: flex; flex-direction: column; align-items: center; gap: 10px;
}
.tab-card:hover { transform: translateY(-8px); box-shadow: 0 20px 40px rgba(0,0,0,0.07); border-color: rgba(79,70,229,0.2); color: inherit; }
.tab-card.active { border-color: var(--primary); box-shadow: 0 15px 35px rgba(79,70,229,0.15); }
.tab-card .tab-icon {
    font-size: 1.5rem; color: var(--primary); background: #eef2ff;
    width: 58px; height: 58px; border-radius: 18px;
    display: flex; align-items: center; justify-content: center;
}
.tab-card.active .tab-icon { background: var(--primary); color: white; }
.tab-num { font-size: 2.8rem; font-weight: 800; color: #1e293b; letter-spacing: -2px; line-height: 1; }
.tab-card.active .tab-num { color: var(--primary); }
.tab-lbl { font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 1.5px; font-size: 0.72rem; }

/* ============================================================
   GLASS PANEL (contenedor de tabla)
============================================================ */
.glass-panel {
    background: white; border-radius: 30px;
    border: 1px solid #f1f5f9;
    box-shadow: 0 10px 30px rgba(0,0,0,0.03);
    overflow: hidden;
}
.panel-head {
    padding: 22px 28px;
    border-bottom: 1px solid #f1f5f9;
    display: flex; align-items: center; justify-content: space-between;
    background: #fafbff;
}
.panel-title { font-weight: 800; font-size: 1rem; color: #1e293b; }

/* FILTROS */
.filter-bar {
    padding: 18px 28px; border-bottom: 1px solid #f1f5f9;
    display: flex; gap: 12px; flex-wrap: wrap; align-items: center;
    background: #fafbff;
}
.f-ctrl {
    background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 14px;
    padding: 9px 16px; font-size: 0.82rem; font-weight: 600;
    font-family: 'Plus Jakarta Sans', sans-serif;
    color: #1e293b; outline: none; transition: 0.2s;
}
.f-ctrl:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79,70,229,0.08); }

/* ============================================================
   TABLA
============================================================ */
.tbl-wrap { overflow-x: auto; max-height: 560px; overflow-y: auto; }
.tbl-wrap::-webkit-scrollbar { width: 4px; height: 4px; }
.tbl-wrap::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }

table.t {
    width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.82rem;
}
table.t thead th {
    background: var(--primary); color: rgba(255,255,255,0.85);
    padding: 12px 16px; font-size: 0.7rem; text-transform: uppercase;
    letter-spacing: 1px; font-weight: 700; position: sticky; top: 0; z-index: 5; white-space: nowrap;
}
table.t tbody td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
table.t tbody tr:last-child td { border-bottom: none; }
table.t tbody tr { transition: 0.15s; }
table.t tbody tr:hover td { background: #fafbff; }

.cell-main  { font-weight: 700; color: #1e293b; }
.cell-sub   { font-size: 0.73rem; color: #94a3b8; margin-top: 2px; }
.mono       { font-family: 'Courier New', monospace; font-size: 0.78rem; }

/* ============================================================
   BADGES
============================================================ */
.badge-ae {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 12px; border-radius: 100px;
    font-size: 0.68rem; font-weight: 700; letter-spacing: 0.3px;
}
.b-ap  { background: rgba(16,185,129,0.1); color: #059669; }
.b-re  { background: rgba(239,68,68,0.1);  color: #dc2626; }
.b-pe  { background: rgba(245,158,11,0.1); color: #d97706; }
.b-si  { background: rgba(100,116,139,0.1);color: #475569; }
.b-ha  { background: rgba(6,182,212,0.1);  color: #0891b2; }
.b-pr  { background: rgba(79,70,229,0.1);  color: var(--primary); }

/* ============================================================
   BOTONES DE ACCIÓN
============================================================ */
.btn-ae {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 12px; border: none;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-weight: 700; font-size: 0.78rem; cursor: pointer; transition: 0.25s;
    text-decoration: none;
}
.btn-ae:hover { transform: translateY(-1px); }
.btn-pri  { background: var(--primary); color: white; box-shadow: 0 4px 12px rgba(79,70,229,0.25); }
.btn-pri:hover { box-shadow: 0 6px 18px rgba(79,70,229,0.35); color: white; }
.btn-suc  { background: #10b981; color: white; box-shadow: 0 4px 12px rgba(16,185,129,0.2); }
.btn-suc:hover { color: white; }
.btn-war  { background: #f59e0b; color: white; }
.btn-war:hover { color: white; }
.btn-inf  { background: #06b6d4; color: white; }
.btn-inf:hover { color: white; }
.btn-vio  { background: #7c3aed; color: white; }
.btn-vio:hover { color: white; }
.btn-out  { background: transparent; border: 1.5px solid #e2e8f0; color: #64748b; }
.btn-out:hover { border-color: var(--primary); color: var(--primary); }
.btn-group-col { display: flex; flex-direction: column; gap: 5px; }

/* ============================================================
   EMPTY STATE
============================================================ */
.empty { text-align: center; padding: 60px 20px; color: #94a3b8; }
.empty i { font-size: 2.5rem; opacity: 0.3; display: block; margin-bottom: 14px; }
.empty p { font-size: 0.9rem; font-weight: 600; }

/* ============================================================
   MODAL
============================================================ */
.modal-content {
    border: none; border-radius: 28px;
    box-shadow: 0 25px 60px rgba(0,0,0,0.15);
    overflow: hidden; font-family: 'Plus Jakarta Sans', sans-serif;
}
.modal-header {
    background: var(--primary); color: white;
    border: none; padding: 22px 26px;
}
.modal-header.green  { background: linear-gradient(135deg,#10b981,#059669); }
.modal-header.cyan   { background: linear-gradient(135deg,#06b6d4,#0891b2); }
.modal-header.blue   { background: linear-gradient(135deg,var(--accent),var(--primary)); }
.modal-title { font-weight: 800; font-size: 1rem; }
.modal-header .btn-close { filter: brightness(0) invert(1); }
.modal-body { padding: 24px; }
.modal-footer { border: none; padding: 16px 24px 22px; }

.f-lbl {
    font-size: 0.72rem; font-weight: 700; color: #94a3b8;
    text-transform: uppercase; letter-spacing: 0.6px;
    display: block; margin-bottom: 6px;
}
.f-input {
    width: 100%; border: 1.5px solid #e2e8f0; border-radius: 14px;
    padding: 11px 14px; font-size: 0.88rem;
    font-family: 'Plus Jakarta Sans', sans-serif;
    color: #1e293b; outline: none; transition: 0.2s;
    background: white;
}
.f-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79,70,229,0.08); }

/* Info card dentro del modal */
.inf-card {
    background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 14px;
    padding: 14px 16px;
}
.inf-card-lbl { font-size: 0.68rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.6px; }
.inf-card-val { font-weight: 800; color: #1e293b; margin-top: 2px; }
.inf-card-sub { font-size: 0.75rem; color: #94a3b8; margin-top: 2px; }

/* Checklist */
.chk-row {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px; border-radius: 12px;
    border: 1.5px solid #e2e8f0; margin-bottom: 8px; transition: 0.2s; cursor: pointer;
}
.chk-row:hover { border-color: var(--primary); background: #eef2ff; }
.chk-row input { width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer; flex-shrink: 0; }
.chk-row label { cursor: pointer; font-size: 0.875rem; font-weight: 500; }

/* TOAST */
.toast-cnt {
    position: fixed; top: 20px; right: 20px; z-index: 9999;
}
.toast-ae {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 20px; border-radius: 16px; color: white;
    font-weight: 600; font-size: 0.88rem;
    font-family: 'Plus Jakarta Sans', sans-serif;
    box-shadow: 0 8px 30px rgba(0,0,0,0.15);
    transform: translateX(120%); transition: 0.4s cubic-bezier(0.4,0,0.2,1);
    max-width: 380px;
}
.toast-ae.show  { transform: translateX(0); }
.t-success      { background: linear-gradient(135deg,#10b981,#059669); }
.t-error        { background: linear-gradient(135deg,#ef4444,#dc2626); }

/* ============================================================
   RESPONSIVE
============================================================ */
@media (max-width: 991px) {
    .sidebar {
        width: 100%; height: 75px; top: auto; bottom: 0;
        flex-direction: row; padding: 0 10px; border-right: none;
        border-top: 1px solid #e2e8f0; border-radius: 25px 25px 0 0;
    }
    .logo-aesthetic { display: none; }
    nav { flex-direction: row; justify-content: space-around; width: 100%; align-items: center; }
    .nav-item-ae { padding: 12px; margin: 0; }
    .nav-item-ae span { display: none; }
    .main-wrapper { margin-left: 0; padding: 20px 20px 100px; }
    .hero-banner { height: 160px; padding: 25px; border-radius: 25px; }
    .tab-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
</head>
<body>

<!-- TOAST -->
<div class="toast-cnt">
  <div class="toast-ae" id="toastEl">
    <i id="toastIco" class="fas fa-circle-check"></i>
    <span id="toastMsg"></span>
  </div>
</div>

<!-- ============================================================
     SIDEBAR
============================================================ -->
<aside class="sidebar">
    <div class="logo-aesthetic d-none d-lg-flex">
        <img src="<?= $ruta_logo ?>" alt="UNIOR">
        <span class="logo-text">UNIOR</span>
    </div>
    <nav>
        <a href="menu.php"                 class="nav-item-ae"><i class="fas fa-home-alt"></i>       <span>Menú</span></a>
        <a href="gestion_estudiantes.php"  class="nav-item-ae active"><i class="fas fa-users-rays"></i>    <span>Estudiantes</span></a>
        <a href="registro_tutores.php"     class="nav-item-ae"><i class="fas fa-user-tie"></i>       <span>Registrar Tutor</span></a>
        <a href="lista_tutores.php"        class="nav-item-ae"><i class="fas fa-fingerprint"></i>    <span>Lista Tutores</span></a>
        <a href="predefensas.php"          class="nav-item-ae"><i class="fas fa-file-signature"></i> <span>Predefensas</span></a>
        <?php if($es_admin): ?>
        <a href="logs.php"                 class="nav-item-ae"><i class="fas fa-clipboard-list"></i> <span>Logs</span></a>
        <?php endif; ?>
    </nav>
    <a href="../controllers/logout.php" class="nav-item-ae text-danger mt-auto d-none d-lg-flex">
        <i class="fas fa-power-off"></i> <span>Salir</span>
    </a>
</aside>

<!-- ============================================================
     CONTENIDO PRINCIPAL
============================================================ -->
<main class="main-wrapper">

    <!-- User pill -->
    <div class="d-flex justify-content-end mb-4">
        <div class="user-pill">
            <div class="text-end d-none d-sm-block">
                <div class="fw-bold small" style="color:#1e293b"><?= $nombre_usuario ?></div>
                <div class="text-muted fw-bold" style="font-size:9px;text-transform:uppercase"><?= strtoupper($rol) ?></div>
            </div>
            <div class="user-avatar"><?= $inicial ?></div>
        </div>
    </div>

    <!-- Hero Banner -->
    <div class="hero-banner mb-4">
        <div>
            <h1 class="fw-800 m-0" style="font-size:clamp(1.6rem,4vw,3rem);letter-spacing:-1.5px;line-height:1">
                Gestión de Estudiantes
            </h1>
            <p class="m-0 mt-2 opacity-75 fw-600" style="font-size:1rem">
                Habilitaciones · Defensa Formal · Titulación • UNIOR
            </p>
        </div>
    </div>

    <!-- ── TABS (estilo stat-grid del menu) ─── -->
    <div class="tab-grid">
        <?php
        $tabs = [
            'listado'      => ['fas fa-list-ul',       'Listado General',         $count_l],
            'habilitacion' => ['fas fa-clipboard-check','Habilitación Ministerio', $count_h],
            'defensa'      => ['fas fa-award',          'Defensa Formal',          $count_d],
            'registro'     => ['fas fa-user-plus',      'Nuevo Registro',          $count_r],
        ];
        foreach($tabs as $key=>[$ico,$lbl,$cnt]):
        ?>
        <a href="?tab=<?= $key ?>" class="tab-card <?= $tab===$key?'active':'' ?>">
            <div class="tab-icon"><i class="fas <?= $ico ?>"></i></div>
            <p class="tab-num counter"><?= $cnt ?></p>
            <span class="tab-lbl"><?= $lbl ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- ════════════════════════════════════════
         TAB 1 — LISTADO GENERAL
    ════════════════════════════════════════ -->
    <?php if($tab==='listado'): ?>
    <div class="glass-panel">
        <form method="GET" class="filter-bar">
            <input type="hidden" name="tab" value="listado">
            <select name="carrera" class="f-ctrl">
                <option value="">Todas las carreras</option>
                <?php foreach($carreras as $c): ?>
                <option value="<?= $c['id_carrera'] ?>" <?= $filtro_carrera==$c['id_carrera']?'selected':'' ?>><?= htmlspecialchars($c['nombre_carrera']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="estado" class="f-ctrl">
                <option value="">Todos los estados</option>
                <option value="APROBADA"       <?= $filtro_estado==='APROBADA'?'selected':'' ?>>Aprobada</option>
                <option value="REPROBADA"      <?= $filtro_estado==='REPROBADA'?'selected':'' ?>>Reprobada</option>
                <option value="PENDIENTE"      <?= $filtro_estado==='PENDIENTE'?'selected':'' ?>>Pendiente</option>
                <option value="SIN_PREDEFENSA" <?= $filtro_estado==='SIN_PREDEFENSA'?'selected':'' ?>>Sin Pre-defensa</option>
            </select>
            <input type="text" name="q" class="f-ctrl" style="min-width:220px"
                   placeholder="Buscar por nombre, CI o RU…" value="<?= htmlspecialchars($busqueda) ?>">
            <button type="submit" class="btn-ae btn-pri"><i class="fas fa-search"></i> Filtrar</button>
            <?php if($filtro_carrera||$filtro_estado||$busqueda): ?>
            <a href="?tab=listado" class="btn-ae btn-out" style="color:#ef4444;border-color:#ef4444"><i class="fas fa-times"></i> Limpiar</a>
            <?php endif; ?>
            <span class="ms-auto" style="font-size:.75rem;color:#94a3b8;font-weight:600"><?= count($estudiantes) ?> registro(s)</span>
        </form>
        <div class="tbl-wrap">
            <?php if(empty($estudiantes)): ?>
            <div class="empty"><i class="fas fa-search"></i><p>Sin resultados</p></div>
            <?php else: ?>
            <table class="t">
                <thead>
                    <tr>
                        <th>#</th><th>Apellido(s)</th><th>Nombre(s)</th><th>CI</th><th>RU</th>
                        <th>Carrera</th><th>Modalidad</th><th>Tema</th><th>Fecha Pre-def.</th>
                        <th>Nota</th><th>Estado Pre-def.</th><th>Tutor</th>
                        <th>Presidente</th><th>Secretario</th><th>Habilitado</th>
                        <th>Fecha Defensa</th><th>Hora</th><th>Aula</th><th>Est. Defensa</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($estudiantes as $i=>$e): ?>
                <tr>
                    <td class="mono" style="color:#94a3b8"><?= $i+1 ?></td>
                    <td><div class="cell-main"><?= htmlspecialchars(trim($e['primer_apellido'].' '.$e['segundo_apellido'])) ?></div></td>
                    <td><?= htmlspecialchars(trim($e['primer_nombre'].' '.$e['segundo_nombre'])) ?></td>
                    <td class="mono"><?= htmlspecialchars($e['ci']) ?></td>
                    <td class="mono" style="font-weight:700"><?= htmlspecialchars($e['ru']) ?></td>
                    <td style="font-size:.78rem"><?= htmlspecialchars($e['nombre_carrera']??'—') ?></td>
                    <td style="font-size:.77rem"><?= $mod_labels[$e['modalidad_titulacion']??'']??'<span style="color:#94a3b8">—</span>' ?></td>
                    <td style="font-size:.77rem;max-width:150px;white-space:normal"><?= $e['tema']?htmlspecialchars(mb_strimwidth($e['tema'],0,55,'…')):'<span style="color:#94a3b8">—</span>' ?></td>
                    <td class="mono" style="font-size:.77rem"><?= $e['fecha_predefensa']?date('d/m/Y',strtotime($e['fecha_predefensa'])):'—' ?></td>
                    <td>
                        <?php if($e['nota']!==null): ?>
                        <span style="font-weight:800;font-size:1rem;color:<?= (float)$e['nota']>=41?'#059669':'#dc2626' ?>"><?= $e['nota'] ?></span>
                        <?php else: ?><span style="color:#94a3b8">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php $cls=match($e['estado_display']){'APROBADA'=>'b-ap','REPROBADA'=>'b-re','PENDIENTE'=>'b-pe',default=>'b-si'};
                              $lbl=match($e['estado_display']){'APROBADA'=>'APROBADA','REPROBADA'=>'REPROBADA','PENDIENTE'=>'PENDIENTE',default=>'SIN PRE-DEF.'};?>
                        <span class="badge-ae <?= $cls ?>"><?= $lbl ?></span>
                    </td>
                    <td style="font-size:.78rem;font-weight:600"><?= htmlspecialchars($e['tutor_nombre']??'—') ?></td>
                    <td style="font-size:.77rem"><?= htmlspecialchars($e['presidente_nombre']??'—') ?></td>
                    <td style="font-size:.77rem"><?= htmlspecialchars($e['secretario_nombre']??'—') ?></td>
                    <td>
                        <?php if($e['esta_habilitado']): ?>
                        <span class="badge-ae b-ha"><i class="fas fa-check-circle"></i> SÍ</span>
                        <?php else: ?><span class="badge-ae b-si">NO</span><?php endif; ?>
                    </td>
                    <td class="mono" style="font-size:.77rem"><?= $e['fecha_defensa']?date('d/m/Y',strtotime($e['fecha_defensa'])):'—' ?></td>
                    <td class="mono" style="font-size:.77rem"><?= $e['hora_defensa']?substr($e['hora_defensa'],0,5):'—' ?></td>
                    <td style="font-size:.77rem"><?= htmlspecialchars($e['aula_defensa']??'—') ?></td>
                    <td><?php if($e['estado_defensa']): ?><span class="badge-ae b-pr"><?= $e['estado_defensa'] ?></span><?php else: ?>—<?php endif; ?></td>
                    <td>
                        <div class="btn-group-col">
                        <?php if($e['estado_display']==='APROBADA' && !$e['esta_habilitado']): ?>
                            <button class="btn-ae btn-inf" onclick="abrirHabilitar('<?= $e['ru'] ?>','<?= htmlspecialchars(addslashes(trim($e['primer_apellido'].' '.$e['primer_nombre']))) ?>')">
                                <i class="fas fa-user-check"></i> Habilitar
                            </button>
                        <?php endif; ?>
                        <?php if($e['id_defensa']): ?>
                            <button class="btn-ae btn-out" onclick="abrirEditar(<?= $e['id_pre_defensa'] ?>,'<?= htmlspecialchars(addslashes(trim($e['primer_apellido'].' '.$e['primer_nombre']))) ?>','<?= $e['fecha_validacion'] ?>','<?= $e['fecha_defensa'] ?>','<?= substr($e['hora_defensa']??'',0,5) ?>','<?= htmlspecialchars(addslashes($e['aula_defensa']??'')) ?>')">
                                <i class="fas fa-calendar-pen"></i> Editar Fecha
                            </button>
                            <?php if(stripos($e['nombre_carrera']??'','GASTRONOM')===false): ?>
                            <button class="btn-ae btn-war" style="font-size:.72rem" onclick="abrirDocumento(<?= $e['id_pre_defensa'] ?>,'interna','<?= htmlspecialchars(addslashes(trim($e['primer_apellido'].' '.$e['primer_nombre']))) ?>')">
                                <i class="fas fa-file-alt"></i> Nota Int.
                            </button>
                            <?php endif; ?>
                        <?php elseif($e['esta_habilitado'] && !$e['id_defensa']): ?>
                            <button class="btn-ae btn-pri" onclick="abrirProgramar(<?= $e['id_pre_defensa'] ?>,'<?= htmlspecialchars(addslashes(trim($e['primer_apellido'].' '.$e['primer_nombre']))) ?>','<?= $e['fecha_validacion'] ?>')">
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

    <!-- ════════════════════════════════════════
         TAB 2 — HABILITACIÓN
    ════════════════════════════════════════ -->
    <?php if($tab==='habilitacion'): ?>
    <div class="glass-panel">
        <div class="panel-head">
            <span class="panel-title"><i class="fas fa-clipboard-check me-2" style="color:var(--primary)"></i>Estudiantes Aprobados — Habilitación al Ministerio</span>
        </div>
        <div class="tbl-wrap">
            <?php if(empty($habilitaciones)): ?>
            <div class="empty"><i class="fas fa-inbox"></i><p>No hay estudiantes con pre-defensa aprobada</p></div>
            <?php else: ?>
            <table class="t">
                <thead>
                    <tr><th>#</th><th>Estudiante</th><th>CI</th><th>RU</th><th>Carrera</th><th>Modalidad</th><th>Tema</th><th>Nota</th><th>Fecha Pre-def.</th><th>Tutor</th><th>Estado Ministerio</th><th>Fecha Habilitación</th><th>Acción</th></tr>
                </thead>
                <tbody>
                <?php foreach($habilitaciones as $i=>$h): ?>
                <tr>
                    <td class="mono" style="color:#94a3b8"><?= $i+1 ?></td>
                    <td class="cell-main"><?= htmlspecialchars($h['nombre_completo']) ?></td>
                    <td class="mono"><?= htmlspecialchars($h['ci']) ?></td>
                    <td class="mono" style="font-weight:700"><?= htmlspecialchars($h['ru']) ?></td>
                    <td style="font-size:.78rem"><?= htmlspecialchars($h['nombre_carrera']??'—') ?></td>
                    <td style="font-size:.77rem"><?= $mod_labels[$h['modalidad_titulacion']??'']??'—' ?></td>
                    <td style="font-size:.77rem;max-width:150px;white-space:normal"><?= $h['tema']?htmlspecialchars(mb_strimwidth($h['tema'],0,55,'…')):'<span style="color:#94a3b8">—</span>' ?></td>
                    <td><span style="font-weight:800;font-size:1rem;color:#059669"><?= $h['nota'] ?></span></td>
                    <td class="mono" style="font-size:.77rem"><?= $h['fecha_predefensa']?date('d/m/Y',strtotime($h['fecha_predefensa'])):'—' ?></td>
                    <td style="font-size:.78rem;font-weight:600"><?= htmlspecialchars($h['tutor_nombre']??'—') ?></td>
                    <td>
                        <?php if($h['esta_habilitado']): ?><span class="badge-ae b-ha"><i class="fas fa-check-circle"></i> HABILITADO</span>
                        <?php else: ?><span class="badge-ae b-pe">PENDIENTE</span><?php endif; ?>
                    </td>
                    <td class="mono" style="font-size:.77rem"><?= $h['fecha_validacion']?date('d/m/Y H:i',strtotime($h['fecha_validacion'])):'—' ?></td>
                    <td>
                        <?php if(!$h['esta_habilitado']): ?>
                        <button class="btn-ae btn-suc" onclick="abrirHabilitar('<?= $h['ru'] ?>','<?= htmlspecialchars(addslashes($h['nombre_completo'])) ?>')">
                            <i class="fas fa-user-check"></i> Habilitar
                        </button>
                        <?php else: ?>
                        <span style="color:#059669;font-weight:700;font-size:.8rem"><i class="fas fa-check-double me-1"></i>Completado</span>
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

    <!-- ════════════════════════════════════════
         TAB 3 — DEFENSA FORMAL
    ════════════════════════════════════════ -->
    <?php if($tab==='defensa'): ?>
    <div class="glass-panel">
        <div class="panel-head">
            <span class="panel-title"><i class="fas fa-award me-2" style="color:var(--primary)"></i>Defensa Formal — Estudiantes Habilitados</span>
        </div>
        <div class="tbl-wrap">
            <?php if(empty($defensas)): ?>
            <div class="empty"><i class="fas fa-inbox"></i><p>No hay estudiantes habilitados para defensa formal</p></div>
            <?php else: ?>
            <table class="t">
                <thead>
                    <tr><th>#</th><th>Estudiante</th><th>RU</th><th>Carrera</th><th>Modalidad</th><th>Nota Pre-def.</th><th>Tutor</th><th>Presidente</th><th>Secretario</th><th>Fecha Def.</th><th>Hora</th><th>Aula</th><th>Estado Def.</th><th>Nota Final</th><th>Días Rest.</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                <?php foreach($defensas as $i=>$d): $dias=max(0,(int)$d['dias_restantes']); ?>
                <tr>
                    <td class="mono" style="color:#94a3b8"><?= $i+1 ?></td>
                    <td><div class="cell-main"><?= htmlspecialchars($d['nombre_completo']) ?></div></td>
                    <td class="mono" style="font-weight:700"><?= htmlspecialchars($d['ru']) ?></td>
                    <td style="font-size:.78rem"><?= htmlspecialchars($d['nombre_carrera']??'—') ?></td>
                    <td style="font-size:.77rem"><?= $mod_labels[$d['modalidad_titulacion']??'']??'—' ?></td>
                    <td><span style="font-weight:800;font-size:1rem;color:#059669"><?= $d['nota_predefensa'] ?></span></td>
                    <td style="font-size:.78rem;font-weight:600"><?= htmlspecialchars($d['tutor_completo']??'—') ?></td>
                    <td style="font-size:.78rem"><?= htmlspecialchars($d['presidente_completo']??'—') ?></td>
                    <td style="font-size:.78rem"><?= htmlspecialchars($d['secretario_completo']??'—') ?></td>
                    <td class="mono" style="font-size:.78rem"><?= $d['fecha_defensa']?date('d/m/Y',strtotime($d['fecha_defensa'])):'<span style="color:#94a3b8">Sin fecha</span>' ?></td>
                    <td class="mono" style="font-size:.78rem"><?= $d['hora_defensa']?substr($d['hora_defensa'],0,5):'—' ?></td>
                    <td style="font-size:.78rem"><?= htmlspecialchars($d['aula_defensa']??'—') ?></td>
                    <td>
                        <?php if($d['estado_defensa']): ?><span class="badge-ae b-pr"><?= $d['estado_defensa'] ?></span>
                        elseif($d['puede_programar']): ?><span class="badge-ae b-ap">DISPONIBLE</span>
                        <?php else: ?><span class="badge-ae b-pe">EN ESPERA</span><?php endif; ?>
                    </td>
                    <td><?= $d['nota_final']!==null?"<span style='font-weight:800;color:var(--primary)'>{$d['nota_final']}</span>":'<span style="color:#94a3b8">—</span>' ?></td>
                    <td>
                        <?php if($d['puede_programar']||$d['id_defensa']): ?>
                        <span style="color:#059669;font-weight:700;font-size:.78rem"><i class="fas fa-check"></i> Listo</span>
                        <?php else: ?><span class="badge-ae b-pe"><i class="fas fa-hourglass-half"></i> <?= $dias ?> días</span><?php endif; ?>
                    </td>
                    <td>
                        <?php $es_g=stripos($d['nombre_carrera']??'','GASTRONOM')!==false; $njs=htmlspecialchars(addslashes($d['nombre_completo'])); $idp=$d['id_pre_defensa']; ?>
                        <div class="btn-group-col">
                        <?php if($d['id_defensa']): ?>
                            <button class="btn-ae btn-out" style="font-size:.72rem" onclick="abrirEditar(<?= $idp ?>,'<?= $njs ?>','<?= $d['fecha_validacion'] ?>','<?= $d['fecha_defensa'] ?>','<?= substr($d['hora_defensa']??'',0,5) ?>','<?= htmlspecialchars(addslashes($d['aula_defensa']??'')) ?>')">
                                <i class="fas fa-calendar-pen"></i> Editar Fecha
                            </button>
                            <?php if(!$es_g): ?>
                            <button class="btn-ae btn-war" style="font-size:.72rem" onclick="abrirDocumento(<?= $idp ?>,'interna','<?= $njs ?>')"><i class="fas fa-file-alt"></i> Nota Interna</button>
                            <button class="btn-ae btn-inf" style="font-size:.72rem" onclick="abrirDocumento(<?= $idp ?>,'uto','<?= $njs ?>')"><i class="fas fa-university"></i> Nota UTO</button>
                            <?php endif; ?>
                            <button class="btn-ae btn-vio" style="font-size:.72rem" onclick="abrirDocumento(<?= $idp ?>,'federacion','<?= $njs ?>')"><i class="fas fa-building-columns"></i> Nota Fed.</button>
                        <?php elseif($d['puede_programar']): ?>
                            <button class="btn-ae btn-pri" onclick="abrirProgramar(<?= $idp ?>,'<?= $njs ?>','<?= $d['fecha_validacion'] ?>')"><i class="fas fa-calendar-plus"></i> Programar</button>
                        <?php else: ?>
                            <span style="font-size:.75rem;color:#94a3b8"><i class="fas fa-lock"></i> Esperar <?= $dias ?> días</span>
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

    <!-- ════════════════════════════════════════
         TAB 4 — NUEVO REGISTRO
    ════════════════════════════════════════ -->
    <?php if($tab==='registro'): ?>
    <div class="glass-panel">
        <div class="panel-head">
            <span class="panel-title"><i class="fas fa-user-plus me-2" style="color:#10b981"></i>Nuevo Registro de Pre-Defensa</span>
            <span style="font-size:.75rem;color:#94a3b8">Solo estudiantes con tutor y sin pre-defensa registrada</span>
        </div>
        <div class="tbl-wrap">
            <?php if(empty($sin_predefensa)): ?>
            <div class="empty"><i class="fas fa-check-circle" style="color:#10b981;opacity:.4"></i><p>Todos los estudiantes con tutor ya tienen pre-defensa</p></div>
            <?php else: ?>
            <table class="t">
                <thead><tr><th>#</th><th>Estudiante</th><th>CI</th><th>RU</th><th>Carrera</th><th>Título del Proyecto</th><th>Tutor</th><th>Acción</th></tr></thead>
                <tbody>
                <?php foreach($sin_predefensa as $i=>$sp): ?>
                <tr>
                    <td class="mono" style="color:#94a3b8"><?= $i+1 ?></td>
                    <td class="cell-main"><?= htmlspecialchars($sp['nombre_completo']) ?></td>
                    <td class="mono"><?= htmlspecialchars($sp['ci']) ?></td>
                    <td class="mono" style="font-weight:700"><?= htmlspecialchars($sp['ru']) ?></td>
                    <td style="font-size:.78rem"><?= htmlspecialchars($sp['nombre_carrera']??'—') ?></td>
                    <td style="font-size:.78rem;max-width:180px;white-space:normal" title="<?= htmlspecialchars($sp['titulo_proyecto']) ?>"><?= htmlspecialchars(mb_strimwidth($sp['titulo_proyecto'],0,55,'…')) ?></td>
                    <td style="font-size:.78rem;font-weight:600"><?= htmlspecialchars($sp['tutor_completo']) ?></td>
                    <td>
                        <button class="btn-ae btn-suc" onclick='abrirRegistro(<?= json_encode(["id_estudiante"=>$sp["id_estudiante"],"id_proyecto"=>$sp["id_proyecto"],"nombre"=>$sp["nombre_completo"],"carrera"=>$sp["nombre_carrera"]??"",'proyecto'=>$sp["titulo_proyecto"],"tutor"=>$sp["tutor_completo"],"id_tutor"=>$sp["id_tutor"]],JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
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

<!-- ════════ MODAL HABILITACIÓN ════════ -->
<div class="modal fade" id="mHab" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header cyan"><h5 class="modal-title"><i class="fas fa-clipboard-check me-2"></i>Habilitación al Ministerio</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="inf-card mb-4"><div class="inf-card-lbl">Estudiante</div><div class="inf-card-val" id="hab-nom"></div><div class="inf-card-sub">Se registrará la habilitación con fecha y hora actual</div></div>
        <p style="font-size:.83rem;color:#94a3b8;margin-bottom:12px">Verifique los requisitos antes de confirmar:</p>
        <div class="chk-row"><input type="checkbox" id="c1" onchange="chkV()"><label for="c1">Pre-defensa aprobada con nota ≥ 41 puntos</label></div>
        <div class="chk-row"><input type="checkbox" id="c2" onchange="chkV()"><label for="c2">Documentación completa y verificada</label></div>
        <div class="chk-row"><input type="checkbox" id="c3" onchange="chkV()"><label for="c3">Comprobante de pago del proceso de titulación</label></div>
        <div class="chk-row"><input type="checkbox" id="c4" onchange="chkV()"><label for="c4">Solicitud formal del estudiante presentada</label></div>
        <div class="chk-row"><input type="checkbox" id="c5" onchange="chkV()"><label for="c5">Datos del estudiante validados (CI y RU correctos)</label></div>
        <input type="hidden" id="hab-ru">
      </div>
      <div class="modal-footer gap-2">
        <button class="btn-ae btn-out" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn-ae btn-suc" id="btnHab" disabled onclick="confirmarHabilitar()"><i class="fas fa-check-circle"></i> Confirmar Habilitación</button>
      </div>
    </div>
  </div>
</div>

<!-- ════════ MODAL PROGRAMAR ════════ -->
<div class="modal fade" id="mProg" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>Programar Defensa Formal</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="inf-card mb-4"><div class="inf-card-lbl">Estudiante</div><div class="inf-card-val" id="pr-nom"></div></div>
        <input type="hidden" id="pr-id">
        <div class="row g-3">
          <div class="col-6"><label class="f-lbl">Fecha *</label><input type="date" id="pr-fecha" class="f-input"><small id="pr-hint" style="font-size:.72rem;color:#94a3b8;margin-top:4px;display:block"></small></div>
          <div class="col-6"><label class="f-lbl">Hora *</label><input type="time" id="pr-hora" class="f-input"></div>
          <div class="col-12"><label class="f-lbl">Aula *</label>
          <select id="pr-aula" class="f-input"><option value="">Seleccione…</option><?php foreach($aulas as $a): ?><option value="<?= $a['id_aula'] ?>"><?= htmlspecialchars($a['nombre_aula']) ?></option><?php endforeach; ?></select></div>
        </div>
      </div>
      <div class="modal-footer gap-2">
        <button class="btn-ae btn-out" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn-ae btn-pri" onclick="guardarProgramar()"><i class="fas fa-calendar-check"></i> Programar</button>
      </div>
    </div>
  </div>
</div>

<!-- ════════ MODAL EDITAR FECHA ════════ -->
<div class="modal fade" id="mEdit" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header blue"><h5 class="modal-title"><i class="fas fa-calendar-pen me-2"></i>Editar Fecha de Defensa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="inf-card mb-4"><div class="inf-card-lbl">Estudiante</div><div class="inf-card-val" id="ed-nom"></div><div class="inf-card-sub">Se actualizará la defensa formal existente</div></div>
        <input type="hidden" id="ed-id">
        <div class="row g-3">
          <div class="col-6"><label class="f-lbl">Nueva Fecha *</label><input type="date" id="ed-fecha" class="f-input"><small id="ed-hint" style="font-size:.72rem;color:#94a3b8;margin-top:4px;display:block"></small></div>
          <div class="col-6"><label class="f-lbl">Hora *</label><input type="time" id="ed-hora" class="f-input"></div>
          <div class="col-12"><label class="f-lbl">Aula *</label>
          <select id="ed-aula" class="f-input"><option value="">Seleccione…</option><?php foreach($aulas as $a): ?><option value="<?= $a['id_aula'] ?>"><?= htmlspecialchars($a['nombre_aula']) ?></option><?php endforeach; ?></select></div>
        </div>
      </div>
      <div class="modal-footer gap-2">
        <button class="btn-ae btn-out" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn-ae btn-pri" onclick="guardarEdicion()"><i class="fas fa-save"></i> Guardar Cambios</button>
      </div>
    </div>
  </div>
</div>

<!-- ════════ MODAL REGISTRO PRE-DEFENSA ════════ -->
<div class="modal fade" id="mReg" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header green"><h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Registrar Pre-Defensa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-md-6"><div class="inf-card"><div class="inf-card-lbl">Estudiante</div><div class="inf-card-val" id="rg-nom"></div><div class="inf-card-sub" id="rg-car"></div></div></div>
          <div class="col-md-6"><div class="inf-card" style="border-color:rgba(79,70,229,.2);background:#eef2ff"><div class="inf-card-lbl">Tutor</div><div class="inf-card-val" id="rg-tut"></div><div class="inf-card-sub" id="rg-pro"></div></div></div>
        </div>
        <input type="hidden" id="rg-ie"><input type="hidden" id="rg-ip"><input type="hidden" id="rg-it">
        <div class="row g-3">
          <div class="col-md-6"><label class="f-lbl">Modalidad *</label><select id="rg-mod" class="f-input" onchange="togTema()"><option value="">Seleccione…</option><option value="EXAMEN_GRADO">Examen de Grado</option><option value="PROYECTO_GRADO">Proyecto de Grado</option><option value="TESIS">Tesis de Grado</option><option value="TRABAJO_DIRIGIDO">Trabajo Dirigido</option></select></div>
          <div class="col-md-6"><label class="f-lbl">Gestión</label><input type="text" id="rg-ges" class="f-input" value="<?= date('Y') ?>" readonly></div>
          <div class="col-12" id="temaWrap"><label class="f-lbl">Tema *</label><textarea id="rg-tema" class="f-input" rows="2" placeholder="Ingrese el tema de titulación…"></textarea></div>
          <div class="col-md-4"><label class="f-lbl">Fecha *</label><input type="date" id="rg-fecha" class="f-input" min="<?= date('Y-m-d') ?>"></div>
          <div class="col-md-4"><label class="f-lbl">Hora *</label><input type="time" id="rg-hora" class="f-input"></div>
          <div class="col-md-4"><label class="f-lbl">Aula *</label><select id="rg-aula" class="f-input"><option value="">Seleccione…</option><?php foreach($aulas as $a): ?><option value="<?= $a['id_aula'] ?>"><?= htmlspecialchars($a['nombre_aula']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="f-lbl">Presidente *</label><select id="rg-pres" class="f-input"><option value="">Seleccione…</option><?php foreach($docentes_trib as $dt): ?><option value="<?= $dt['id_persona'] ?>"><?= htmlspecialchars($dt['nombre_completo']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="f-lbl">Secretario *</label><select id="rg-sec" class="f-input"><option value="">Seleccione…</option><?php foreach($docentes_trib as $dt): ?><option value="<?= $dt['id_persona'] ?>"><?= htmlspecialchars($dt['nombre_completo']) ?></option><?php endforeach; ?></select></div>
        </div>
      </div>
      <div class="modal-footer gap-2">
        <button class="btn-ae btn-out" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn-ae btn-suc" onclick="guardarPreDefensa()"><i class="fas fa-save"></i> Registrar Pre-Defensa</button>
      </div>
    </div>
  </div>
</div>

<!-- ════════ MODAL GENERAR DOCUMENTO ════════ -->
<div class="modal fade" id="mDoc" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" id="doc-hdr"><h5 class="modal-title" id="doc-title"><i class="fas fa-file-alt me-2"></i>Generar Nota</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body text-center" style="padding:30px 24px">
        <div id="doc-ico" style="width:62px;height:62px;border-radius:18px;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;font-size:1.7rem"></div>
        <h6 class="fw-800 mb-1" id="doc-sub"></h6>
        <p style="font-size:.8rem;color:#94a3b8;margin-bottom:5px" id="doc-est"></p>
        <p style="font-size:.85rem;color:#475569" id="doc-desc"></p>
        <input type="hidden" id="doc-id"><input type="hidden" id="doc-tipo">
      </div>
      <div class="modal-footer justify-content-center gap-2">
        <button class="btn-ae btn-out" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn-ae" id="doc-btn" onclick="ejecutarDoc()"><i class="fas fa-file-download"></i> Generar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── TOAST ──
function toast(msg,type='success'){
  const el=document.getElementById('toastEl');
  document.getElementById('toastMsg').textContent=msg;
  document.getElementById('toastIco').className=type==='success'?'fas fa-circle-check':'fas fa-circle-exclamation';
  el.className='toast-ae '+(type==='success'?'t-success':'t-error')+' show';
  setTimeout(()=>el.classList.remove('show'),4000);
}

// ── AJAX ──
function ajax(data){
  const fd=new FormData();
  for(const[k,v]of Object.entries(data)) fd.append(k,v);
  return fetch(location.pathname,{method:'POST',body:fd}).then(r=>r.json());
}

// ── CONTADORES animados (igual que menu.php) ──
document.querySelectorAll('.counter').forEach(c=>{
  const t=+c.innerText; if(isNaN(t)||t===0) return;
  let n=0; const u=()=>{ const i=t/70; if(n<t){n+=i;c.innerText=Math.ceil(n);setTimeout(u,15);} else c.innerText=t; }; u();
});

// ── HABILITACIÓN ──
function abrirHabilitar(ru,nombre){
  document.getElementById('hab-ru').value=ru;
  document.getElementById('hab-nom').textContent=nombre;
  for(let i=1;i<=5;i++) document.getElementById('c'+i).checked=false;
  document.getElementById('btnHab').disabled=true;
  new bootstrap.Modal(document.getElementById('mHab')).show();
}
function chkV(){ let ok=true; for(let i=1;i<=5;i++) if(!document.getElementById('c'+i).checked){ok=false;break;} document.getElementById('btnHab').disabled=!ok; }
function confirmarHabilitar(){
  const btn=document.getElementById('btnHab');
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Procesando…';
  ajax({ajax_action:'habilitar_ministerio',ru_estudiante:document.getElementById('hab-ru').value})
    .then(d=>{ if(d.success){toast(d.message);bootstrap.Modal.getInstance(document.getElementById('mHab')).hide();setTimeout(()=>location.reload(),1200);}
               else{toast(d.message,'error');btn.disabled=false;btn.innerHTML='<i class="fas fa-check-circle"></i> Confirmar Habilitación';} })
    .catch(()=>{toast('Error de conexión','error');btn.disabled=false;btn.innerHTML='<i class="fas fa-check-circle"></i> Confirmar Habilitación';});
}

// ── FECHA MÍNIMA ──
function fechaMin(fv){ const d=new Date(fv); d.setDate(d.getDate()+30); const h=new Date(); return(d>h?d:h).toISOString().split('T')[0]; }

// ── PROGRAMAR ──
function abrirProgramar(id,nombre,fv){
  document.getElementById('pr-id').value=id;
  document.getElementById('pr-nom').textContent=nombre;
  const m=fechaMin(fv);
  document.getElementById('pr-fecha').min=m; document.getElementById('pr-fecha').value='';
  document.getElementById('pr-hora').value=''; document.getElementById('pr-aula').value='';
  document.getElementById('pr-hint').textContent='Fecha mínima: '+m.split('-').reverse().join('/');
  new bootstrap.Modal(document.getElementById('mProg')).show();
}
function guardarProgramar(){
  ajax({ajax_action:'programar_defensa',id_pre_defensa:document.getElementById('pr-id').value,fecha_defensa:document.getElementById('pr-fecha').value,hora_defensa:document.getElementById('pr-hora').value,id_aula_defensa:document.getElementById('pr-aula').value})
    .then(d=>{ if(d.success){toast(d.message);bootstrap.Modal.getInstance(document.getElementById('mProg')).hide();setTimeout(()=>location.reload(),1200);} else toast(d.message,'error'); })
    .catch(()=>toast('Error de conexión','error'));
}

// ── EDITAR FECHA ──
function abrirEditar(id,nombre,fv,fechaActual,horaActual,aulaActual){
  document.getElementById('ed-id').value=id;
  document.getElementById('ed-nom').textContent=nombre;
  const m=fechaMin(fv);
  const ef=document.getElementById('ed-fecha'); ef.min=m; ef.value=fechaActual||'';
  document.getElementById('ed-hora').value=horaActual||'';
  const sa=document.getElementById('ed-aula'); sa.value='';
  for(let o of sa.options){ if(o.text===aulaActual){sa.value=o.value;break;} }
  document.getElementById('ed-hint').textContent='Fecha mínima: '+m.split('-').reverse().join('/');
  new bootstrap.Modal(document.getElementById('mEdit')).show();
}
function guardarEdicion(){
  ajax({ajax_action:'editar_defensa',id_pre_defensa:document.getElementById('ed-id').value,fecha_defensa:document.getElementById('ed-fecha').value,hora_defensa:document.getElementById('ed-hora').value,id_aula_defensa:document.getElementById('ed-aula').value})
    .then(d=>{ if(d.success){toast(d.message);bootstrap.Modal.getInstance(document.getElementById('mEdit')).hide();setTimeout(()=>location.reload(),1200);} else toast(d.message,'error'); })
    .catch(()=>toast('Error de conexión','error'));
}

// ── REGISTRO PRE-DEFENSA ──
function abrirRegistro(data){
  document.getElementById('rg-ie').value=data.id_estudiante;
  document.getElementById('rg-ip').value=data.id_proyecto;
  document.getElementById('rg-it').value=data.id_tutor;
  document.getElementById('rg-nom').textContent=data.nombre;
  document.getElementById('rg-car').textContent=data.carrera;
  document.getElementById('rg-tut').textContent=data.tutor;
  document.getElementById('rg-pro').textContent=data.proyecto.length>55?data.proyecto.substring(0,55)+'…':data.proyecto;
  ['rg-mod','rg-tema','rg-fecha','rg-hora','rg-aula','rg-pres','rg-sec'].forEach(id=>{document.getElementById(id).value='';});
  togTema();
  new bootstrap.Modal(document.getElementById('mReg')).show();
}
function togTema(){ document.getElementById('temaWrap').style.display=document.getElementById('rg-mod').value==='EXAMEN_GRADO'?'none':''; }
function guardarPreDefensa(){
  const p=document.getElementById('rg-pres').value, s=document.getElementById('rg-sec').value, t=document.getElementById('rg-it').value;
  if((p&&p===t)||(s&&s===t)){toast('El tribunal no puede incluir al tutor','error');return;}
  if(p&&s&&p===s){toast('Presidente y Secretario deben ser diferentes','error');return;}
  ajax({ajax_action:'registrar_predefensa',id_estudiante:document.getElementById('rg-ie').value,id_proyecto:document.getElementById('rg-ip').value,modalidad_titulacion:document.getElementById('rg-mod').value,tema:document.getElementById('rg-tema').value,fecha:document.getElementById('rg-fecha').value,hora:document.getElementById('rg-hora').value,id_aula:document.getElementById('rg-aula').value,gestion:document.getElementById('rg-ges').value,id_presidente:p,id_secretario:s})
    .then(d=>{ if(d.success){toast(d.message);bootstrap.Modal.getInstance(document.getElementById('mReg')).hide();setTimeout(()=>location.reload(),1200);} else toast(d.message,'error'); })
    .catch(()=>toast('Error de conexión','error'));
}
document.addEventListener('change',e=>{ if(e.target.id==='rg-pres'||e.target.id==='rg-sec'){ const p=document.getElementById('rg-pres').value,s=document.getElementById('rg-sec').value; if(p&&s&&p===s){toast('Deben ser personas diferentes','error');e.target.value='';} } });

// ── DOCUMENTOS ──
const DOCS={
  interna:{title:'Nota Interna',sub:'¿Generar la Nota Interna?',desc:'Solicitud de pago a Tribunal Externo — UTO.',ico:'fas fa-file-alt',icoColor:'#d97706',icoBg:'rgba(245,158,11,.1)',hdr:'linear-gradient(135deg,#f59e0b,#d97706)',btnCls:'btn-war',url:'../controllers/generar_nota_interna.php?id='},
  uto:{title:'Nota Externa — UTO',sub:'¿Generar la Nota Externa para la UTO?',desc:'Solicitud de designación de Tribunal Externo a la Universidad Técnica de Oruro.',ico:'fas fa-university',icoColor:'#0891b2',icoBg:'rgba(6,182,212,.1)',hdr:'linear-gradient(135deg,#06b6d4,#0891b2)',btnCls:'btn-inf',url:'../controllers/generar_nota_externa.php?tipo=UTO&id='},
  federacion:{title:'Nota — Federación',sub:'¿Generar Nota para la Federación?',desc:'Solicitud de designación de Veedor a la Federación Departamental de Profesionales de Oruro.',ico:'fas fa-building-columns',icoColor:'#7c3aed',icoBg:'rgba(124,58,237,.1)',hdr:'linear-gradient(135deg,#8b5cf6,#7c3aed)',btnCls:'btn-vio',url:'../controllers/generar_nota_externa.php?tipo=FEDERACION&id='}
};
function abrirDocumento(id,tipo,nombre){
  const c=DOCS[tipo]; if(!c) return;
  document.getElementById('doc-id').value=id; document.getElementById('doc-tipo').value=tipo;
  document.getElementById('doc-title').innerHTML='<i class="'+c.ico+' me-2"></i>'+c.title;
  document.getElementById('doc-hdr').style.background=c.hdr;
  document.getElementById('doc-sub').textContent=c.sub;
  document.getElementById('doc-est').textContent=nombre;
  document.getElementById('doc-desc').textContent=c.desc;
  document.getElementById('doc-ico').style.cssText='width:62px;height:62px;border-radius:18px;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;font-size:1.7rem;background:'+c.icoBg+';color:'+c.icoColor;
  document.getElementById('doc-ico').innerHTML='<i class="'+c.ico+'"></i>';
  const btn=document.getElementById('doc-btn'); btn.className='btn-ae '+c.btnCls;
  btn.innerHTML='<i class="fas fa-file-download"></i> Generar '+c.title;
  new bootstrap.Modal(document.getElementById('mDoc')).show();
}
function ejecutarDoc(){
  const id=document.getElementById('doc-id').value, tipo=document.getElementById('doc-tipo').value, c=DOCS[tipo]; if(!c) return;
  ajax({ajax_action:'marcar_nota_generada',id_pre_defensa:id,tipo_nota:tipo});
  bootstrap.Modal.getInstance(document.getElementById('mDoc')).hide();
  toast('Generando documento — la descarga iniciará en breve');
  setTimeout(()=>{ window.location.href=c.url+id; },600);
}
</script>
</body>
</html>