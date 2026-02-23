<?php
require_once("../config/conexion.php");

if (isset($_POST['nombre'])) {
    $nombre = strtoupper(trim($_POST['nombre']));
    $codigo = substr($nombre, 0, 3) . rand(100, 999); // Genera un código automático

    try {
        $stmt = $pdo->prepare("INSERT INTO public.carreras (nombre_carrera, codigo_carrera) VALUES (?, ?) RETURNING id_carrera");
        $stmt->execute([$nombre, $codigo]);
        $id = $stmt->fetchColumn();

        echo json_encode(['status' => 'success', 'id' => $id]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}