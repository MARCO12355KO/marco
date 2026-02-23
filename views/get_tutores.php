<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

// Verificar sesión
if (!isset($_SESSION["user_id"])) {
    echo json_encode([]);
    exit();
}

require_once("../config/conexion.php");

try {
    // Obtener el id_carrera desde GET
    $id_carrera = filter_input(INPUT_GET, 'id_carrera', FILTER_VALIDATE_INT);
    
    if (!$id_carrera) {
        echo json_encode([]);
        exit();
    }
    
    // Consulta: Obtener tutores de la carrera especificada
    // RF-09: Solo tutores de la misma carrera del estudiante
    $sql = "SELECT p.id_persona, p.primer_nombre, p.primer_apellido, d.especialidad
            FROM personas p
            INNER JOIN docentes d ON p.id_persona = d.id_persona
            WHERE d.id_carrera = ?
            AND d.es_tutor = true
            AND p.estado = 'ACTIVO'
            ORDER BY p.primer_apellido ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_carrera]);
    
    $tutores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($tutores);

} catch (PDOException $e) {
    error_log("Error en get_tutores_carrera.php: " . $e->getMessage());
    echo json_encode([]);
}
?>