<?php
include_once '../config/conexion.php';
header('Content-Type: application/json');

// Evita que cualquier error de PHP ensucie la respuesta JSON
error_reporting(0); 

$action = $_GET['action'] ?? '';

if ($action == 'list') {
    $stmt = $pdo->query("SELECT id_carrera, nombre_carrera FROM carreras ORDER BY nombre_carrera ASC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action == 'add' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = strtoupper(trim($_POST['nombre'] ?? ''));
    
    if (empty($nombre)) {
        echo json_encode(['exito' => false, 'mensaje' => 'El nombre es obligatorio']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO carreras (nombre_carrera) VALUES (?)");
    $exito = $stmt->execute([$nombre]);
    echo json_encode(['exito' => $exito]);
    exit;
}

if ($action == 'delete') {
    $id = $_GET['id'] ?? 0;
    
    try {
        // 1. Verificamos si hay estudiantes usando esta carrera
        $check = $pdo->prepare("SELECT COUNT(*) FROM estudiantes WHERE id_carrera = ?");
        $check->execute([$id]);
        
        if ($check->fetchColumn() > 0) {
            echo json_encode([
                'exito' => false, 
                'mensaje' => 'No se puede eliminar: Hay estudiantes registrados en esta carrera.'
            ]);
            exit;
        }

        // 2. Si no hay alumnos, procedemos a eliminar
        $stmt = $pdo->prepare("DELETE FROM carreras WHERE id_carrera = ?");
        $exito = $stmt->execute([$id]);
        
        echo json_encode(['exito' => $exito]);

    } catch (PDOException $e) {
        echo json_encode([
            'exito' => false, 
            'mensaje' => 'Error en la base de datos al intentar eliminar.'
        ]);
    }
    exit;
}