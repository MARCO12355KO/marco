<?php
header('Content-Type: application/json');
include_once '../config/conexion.php';

if (!empty($_POST['nombre_aula'])) {
    try {
        $nombre = strtoupper($_POST['nombre_aula']);
        $stmt = $pdo->prepare("INSERT INTO public.aulas (nombre_aula) VALUES (?) RETURNING id_aula");
        $stmt->execute([$nombre]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['exito' => true, 'id' => $res['id_aula'], 'nombre' => $nombre]);
    } catch (Exception $e) {
        echo json_encode(['exito' => false, 'mensaje' => $e->getMessage()]);
    }
}