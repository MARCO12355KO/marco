<?php
require_once("../config/conexion.php");

$id_estudiante = $_GET['id_estudiante'] ?? null;

if ($id_estudiante) {
    try {
        // Obtenemos la carrera del estudiante y buscamos docentes de esa misma carrera
        $sql = "SELECT p.id_persona, p.primer_nombre, p.primer_apellido 
                FROM public.personas p
                INNER JOIN public.docentes d ON p.id_persona = d.id_persona
                WHERE d.id_carrera = (SELECT id_carrera FROM public.estudiantes WHERE id_persona = ?)
                AND p.estado = 'ACTIVO'
                ORDER BY p.primer_apellido ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_estudiante]);
        $docentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($docentes);
    } catch (PDOException $e) {
        echo json_encode([]);
    }
}