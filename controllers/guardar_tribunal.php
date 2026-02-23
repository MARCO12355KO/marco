<?php
header('Content-Type: application/json');
require_once("../config/conexion.php");
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_asignacion = $_POST['id_asignacion'] ?? null;
    $id_presidente = $_POST['id_presidente'] ?? null;
    $id_secretario = $_POST['id_secretario'] ?? null;
    $fecha = $_POST['fecha_designacion'] ?? date('Y-m-d');

    if (!$id_asignacion || !$id_presidente || !$id_secretario) {
        echo json_encode(['status' => 'error', 'message' => 'Faltan datos obligatorios']);
        exit;
    }

    try {
        $sql = "INSERT INTO public.tribunales (id_asignacion, id_presidente, id_secretario, fecha_designacion) 
                VALUES (:id_asig, :id_pres, :id_sec, :fecha)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id_asig' => $id_asignacion,
            ':id_pres' => $id_presidente,
            ':id_sec'  => $id_secretario,
            ':fecha'   => $fecha
        ]);

        echo json_encode(['status' => 'success', 'message' => 'Tribunal asignado correctamente']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error en BD: ' . $e->getMessage()]);
    }
}