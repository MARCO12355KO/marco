<?php
session_start();
require_once "conexion.php";

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['usuario'])) {
    $response['message'] = "Sesión no iniciada.";
    echo json_encode($response);
    exit();
}

$id_pre_defensa = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id_pre_defensa) {
    $response['message'] = "ID de pre-defensa inválido.";
    echo json_encode($response);
    exit();
}

// Consulta para obtener fecha, hora, id_aula y nombre del estudiante
$sql = "SELECT 
            d.fecha, d.hora, d.id_aula,
            CONCAT_WS(' ', e.primer_nombre, e.segundo_nombre, e.primer_apellido, e.segundo_apellido) AS nombre_estudiante
        FROM pre_defensas d
        JOIN estudiantes e ON d.id_estudiante = e.id_estudiante
        WHERE d.id_pre_defensa = ?";
        
$stmt = $conexion->prepare($sql);

if ($stmt === false) {
    $response['message'] = "Error al preparar la consulta: " . $conexion->error;
} else {
    $stmt->bind_param("i", $id_pre_defensa);
    
    if ($stmt->execute()) {
        $resultado = $stmt->get_result();
        $data = $resultado->fetch_assoc();
        
        if ($data) {
            $response['success'] = true;
            $response['data'] = $data;
        } else {
            $response['message'] = "Pre-defensa no encontrada.";
        }
    } else {
        $response['message'] = "Error al ejecutar la consulta: " . $stmt->error;
    }
    $stmt->close();
}
$conexion->close();

echo json_encode($response);
?>