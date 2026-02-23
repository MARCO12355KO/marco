<?php
header('Content-Type: application/json');
require_once("conexion.php");

$nombre = trim($_POST['nombre']);
if($nombre != "") {

    $stmt = $pdo->prepare("INSERT INTO aulas (nombre_aula) VALUES (?)");
    $stmt->execute([strtoupper($nombre)]);
    $id = $pdo->lastInsertId();
    
    echo json_encode(['id_aula' => $id]);
}