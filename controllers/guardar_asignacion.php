<?php
session_start();
require_once("../config/conexion.php");
header('Content-Type: application/json');

if (!isset($_SESSION["user_id"])) {
    echo json_encode(['status' => 'error', 'message' => 'Sesión no válida']);
    exit();
}

$id_estudiante = $_POST['id_estudiante'] ?? null;
$id_docente    = $_POST['id_docente']   ?? null; 
$gestion       = $_POST['gestion']      ?? null;

if (!$id_estudiante || !$id_docente || !$gestion) {
    echo json_encode(['status' => 'error', 'message' => 'Todos los campos son obligatorios']);
    exit();
}

try {
    // 1. Verificar si el estudiante ya tiene tutor activo
    $check = $pdo->prepare("SELECT id_asignacion FROM public.asignaciones_tutor WHERE id_estudiante = ? AND estado ILIKE 'Activo'");
    $check->execute([$id_estudiante]);
    
    if ($check->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'El estudiante ya tiene un tutor asignado.']);
        exit();
    }

    // 2. Inserción usando el nombre de columna 'id_docente' confirmado por pgAdmin
    $sql = "INSERT INTO public.asignaciones_tutor (id_estudiante, id_docente, gestion, fecha_asignacion, estado) 
            VALUES (?, ?, ?, CURRENT_DATE, 'Activo')";
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([$id_estudiante, $id_docente, $gestion]);

    if ($result) {
        echo json_encode(['status' => 'success', 'message' => 'Asignación realizada correctamente.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No se pudo completar el registro.']);
    }

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error de base de datos: ' . $e->getMessage()]);
}