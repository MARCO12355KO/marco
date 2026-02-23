<?php
include_once '../config/conexion.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$nombre = trim($data['nombre'] ?? '');

if ($nombre) {
    try {
        // Tabla public.carreras
        $stmt = $pdo->prepare("INSERT INTO public.carreras (nombre_carrera) VALUES (?) RETURNING id_carrera");
        $stmt->execute([$nombre]);
        $id = $stmt->fetchColumn();
        echo json_encode(['exito' => true, 'id' => $id]);
    } catch (Exception $e) {
        echo json_encode(['exito' => false, 'mensaje' => $e->getMessage()]);
    }
}