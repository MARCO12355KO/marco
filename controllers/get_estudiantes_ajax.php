<?php
require_once '../config/conexion.php';
$id_carrera = (int)$_GET['id_carrera'];

// Buscamos personas que sean estudiantes, de la misma carrera, 
// activos y que NO estén en la tabla de asignaciones con estado ACTIVO
$sql = "SELECT p.id_persona, p.primer_nombre, p.primer_apellido, p.ci 
        FROM public.personas p 
        JOIN public.estudiantes e ON p.id_persona = e.id_persona 
        WHERE e.id_carrera = ? 
        AND p.estado = 'activo'
        AND p.id_persona NOT IN (
            SELECT id_estudiante FROM public.asignaciones_tutor WHERE estado = 'ACTIVO'
        )
        ORDER BY p.primer_apellido ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$id_carrera]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));