<?php
require_once("../config/conexion.php");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();

        // 1. Insertar en public.personas (Basado en tu SQL)
        // Agregamos un CI genérico o podrías añadir un campo CI al modal
        $ci_provisorio = 'TUT-' . time(); 
        
        $sqlPersona = "INSERT INTO public.personas (ci, primer_nombre, primer_apellido, estado) 
                       VALUES (:ci, :nom, :ape, 'activo') RETURNING id_persona";
        $stmtP = $pdo->prepare($sqlPersona);
        $stmtP->execute([
            ':ci'  => $ci_provisorio,
            ':nom' => $_POST['nombres'],
            ':ape' => $_POST['apellido_p']
        ]);
        $id_persona = $stmtP->fetchColumn();

        // 2. Insertar en public.docentes (Tu tabla real para tutores)
        $sqlDocente = "INSERT INTO public.docentes (id_persona, id_carrera, especialidad, es_tutor, es_tribunal) 
                       VALUES (:id, :car, :esp, true, false)";
        $stmtD = $pdo->prepare($sqlDocente);
        $stmtD->execute([
            ':id'  => $id_persona,
            ':car' => $_POST['id_carrera'],
            ':esp' => $_POST['especialidad'] ?? null
        ]);

        $pdo->commit();

        echo json_encode([
            "status" => "success",
            "id_persona" => $id_persona,
            "nombre_completo" => $_POST['apellido_p'] . " " . $_POST['nombres']
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}