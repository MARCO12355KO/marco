<?php
header('Content-Type: application/json');
require_once("conexion.php");

$id_estudiante = isset($_POST['id_estudiante']) ? (int)$_POST['id_estudiante'] : 0;
$id_tutor      = isset($_POST['id_tutor']) ? (int)$_POST['id_tutor'] : 0;
$gestion       = trim($_POST['gestion'] ?? '');
$fecha_limite  = $_POST['fecha_limite'] ?? null;

if ($id_estudiante === 0 || $id_tutor === 0 || empty($gestion)) {
    echo json_encode(['exito' => false, 'mensaje' => 'Error: Debe seleccionar un estudiante, un tutor y la gestión.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $check = $pdo->prepare("SELECT id_asignacion FROM asignaciones_tutor WHERE id_estudiante = ? AND estado = 'ACTIVO'");
    $check->execute([$id_estudiante]);
    
    if ($check->fetch()) {
        echo json_encode(['exito' => false, 'mensaje' => 'Este estudiante ya tiene un tutor activo asignado.']);
        $pdo->rollBack();
        exit;
    }

    $sql = "INSERT INTO public.asignaciones_tutor (
                id_estudiante, 
                id_docente, 
                gestion, 
                fecha_asignacion, 
                estado
            ) VALUES (?, ?, ?, CURRENT_DATE, 'ACTIVO')";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_estudiante, $id_tutor, $gestion]);

    $pdo->commit();
    echo json_encode(['exito' => true, 'mensaje' => 'Asignación de tutor registrada exitosamente.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['exito' => false, 'mensaje' => 'Error de Base de Datos: ' . $e->getMessage()]);
}