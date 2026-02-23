<?php
require_once("conexion.php");
header('Content-Type: application/json');

$id_est = $_GET['id'] ?? 0;

if($id_est > 0) {
    try {
    
        $sql = "SELECT 
                    a.id_docente, 
                    (p.primer_nombre || ' ' || p.primer_apellido) AS nombre_tutor,
                    a.gestion
                FROM public.asignaciones_tutor a
                JOIN public.personas p ON a.id_docente = p.id_persona
                WHERE a.id_estudiante = ? AND a.estado = 'ACTIVO'
                LIMIT 1";
                
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_est]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

        if($resultado) {
            echo json_encode($resultado);
        } else {

            echo json_encode(['error' => 'No se encontró un tutor asignado para este estudiante.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Error de base de datos: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['error' => 'ID de estudiante no válido.']);
}