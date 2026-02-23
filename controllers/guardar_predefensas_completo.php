<?php
require_once '../config/conexion.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['exito' => false, 'mensaje' => 'Método no permitido']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Recoger datos del formulario
    $id_estudiante = $_POST['id_estudiante'] ?? null;
    $id_asignacion = $_POST['id_asignacion'] ?? null; 
    $id_proyecto   = $_POST['id_proyecto'] ?? null;
    $id_modalidad  = $_POST['id_modalidad'] ?? null;
    $tema          = $_POST['tema_proyecto'] ?? '';
    $id_aula       = $_POST['id_aula'] ?? null;
    $fecha         = $_POST['fecha'] ?? null;
    $hora          = $_POST['hora'] ?? null;
    $id_presi      = $_POST['id_presidente'] ?? null;
    $id_secre      = $_POST['id_secretario'] ?? null;
    $gestion       = date('Y'); 

    if (!$id_estudiante || !$id_aula || !$fecha) {
        throw new Exception("Faltan datos obligatorios (Estudiante, Aula o Fecha).");
    }

    // 2. Actualizar el Proyecto (Título y Modalidad)
    $stmtUpPro = $pdo->prepare("UPDATE proyectos SET id_modalidad = ?, titulo_proyecto = ? WHERE id_proyecto = ?");
    $stmtUpPro->execute([$id_modalidad, $tema, $id_proyecto]);

    // 3. Gestionar el Tribunal (Tabla: tribunales)
    // Se asocia a la asignación de tutor actual
    $stmtCheckTri = $pdo->prepare("SELECT id_tribunal FROM tribunales WHERE id_asignacion = ? AND estado = 'ACTIVO'");
    $stmtCheckTri->execute([$id_asignacion]);
    $tribunal = $stmtCheckTri->fetch();

    if (!$tribunal) {
        $stmtTri = $pdo->prepare("INSERT INTO tribunales (id_asignacion, id_presidente, id_secretario, estado) VALUES (?, ?, ?, 'ACTIVO')");
        $stmtTri->execute([$id_asignacion, $id_presi, $id_secre]);
    } else {
        $stmtTriUp = $pdo->prepare("UPDATE tribunales SET id_presidente = ?, id_secretario = ? WHERE id_tribunal = ?");
        $stmtTriUp->execute([$id_presi, $id_secre, $tribunal['id_tribunal']]);
    }

    // 4. Insertar la Predefensa (Tabla: pre_defensas)
    // Nota: El trigger fn_evitar_cruce_aulas validará el aula
    $sqlPre = "INSERT INTO pre_defensas (id_estudiante, fecha, hora, id_aula, tema, estado, gestion) 
               VALUES (?, ?, ?, ?, ?, 'PENDIENTE', ?)";
    $stmtPre = $pdo->prepare($sqlPre);
    $stmtPre->execute([$id_estudiante, $fecha, $hora, $id_aula, $tema, $gestion]);

    $pdo->commit();
    echo json_encode(['exito' => true, 'mensaje' => 'Predefensa y Tribunal registrados con éxito.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = $e->getMessage();
    
    // Captura de errores específicos de la DB
    if (strpos($msg, 'unique_estudiante_gestion') !== false) {
        $msg = "El estudiante ya tiene una predefensa este año ($gestion).";
    } elseif (strpos($msg, 'fn_evitar_cruce_aulas') !== false) {
        $msg = "Cruce de horario: El aula seleccionada ya está ocupada.";
    }
    
    echo json_encode(['exito' => false, 'mensaje' => $msg]);
}