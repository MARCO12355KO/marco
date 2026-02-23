<?php
declare(strict_types=1);
session_start();
require_once("../config/conexion.php");

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recibimos los IDs del formulario
    $id_estudiante = $_POST['id_estudiante'] ?? null;
    $id_tutor = $_POST['id_tutor'] ?? null; // Este es el ID del docente
    $gestion = date("Y"); // Año actual (2026)

    if (!$id_estudiante || !$id_tutor) {
        echo json_encode(['status' => 'error', 'message' => 'Faltan datos obligatorios.']);
        exit;
    }

    try {
        // 1. VALIDACIÓN: Verificar que el estudiante no tenga ya un tutor asignado
        // Ahora consultamos la tabla asignaciones_tutor directamente
        $check = $pdo->prepare("SELECT COUNT(*) FROM public.asignaciones_tutor WHERE id_estudiante = ? AND estado = 'ACTIVO'");
        $check->execute([$id_estudiante]);
        
        if ((int)$check->fetchColumn() > 0) {
            echo json_encode([
                'status' => 'error', 
                'message' => 'Este estudiante ya tiene una asignación de tutor activa.'
            ]);
            exit;
        }

        // 2. INSERCIÓN: Guardamos en la tabla asignaciones_tutor
        // Nota: En tu SQL la columna se llama id_docente, no id_tutor
        $sql = "INSERT INTO public.asignaciones_tutor (
                    id_estudiante, 
                    id_docente, 
                    gestion, 
                    fecha_asignacion, 
                    estado
                ) VALUES (?, ?, ?, CURRENT_DATE, 'ACTIVO')";
        
        $stmt = $pdo->prepare($sql);
        $resultado = $stmt->execute([
            $id_estudiante, 
            $id_tutor, 
            $gestion
        ]);

        if ($resultado) {
            echo json_encode([
                'status' => 'success', 
                'message' => '¡Asignación guardada! Se registró en la tabla de asignaciones correctamente.'
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No se pudo completar el registro de asignación.']);
        }

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error de BD: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido.']);
}