<?php
require_once("../conexion.php");
header('Content-Type: application/json');

$id_aula = $_POST['id_aula'] ?? null;
$fecha = $_POST['fecha_pre'] ?? null;
$hora = $_POST['hora_pre'] ?? null;

try {
    // Verificar si el aula está ocupada en esa fecha y hora exacta
    $sql = "SELECT COUNT(*) FROM public.pre_defensas 
            WHERE id_aula = ? AND fecha = ? AND hora = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_aula, $fecha, $hora]);
    $count = $stmt->fetchColumn();

    echo json_encode(['disponible' => ($count == 0)]);

} catch (PDOException $e) {
    echo json_encode(['disponible' => false, 'mensaje' => $e->getMessage()]);
}