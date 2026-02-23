<?php
require_once("conexion.php");
header('Content-Type: application/json');

// Recibir datos y limpiar
$p_nombre = trim($_POST['primer_nombre']);
$s_nombre = trim($_POST['segundo_nombre']);
$p_apellido = trim($_POST['primer_apellido']);
$s_apellido = trim($_POST['segundo_apellido']);
$ci = trim($_POST['ci']);
$celular = trim($_POST['celular']);

if(empty($p_nombre) || empty($p_apellido) || empty($ci)) {
    echo json_encode(["exito" => false, "mensaje" => "Faltan campos obligatorios."]);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Insertar en tabla PERSONAS
    $sqlPersona = "INSERT INTO public.personas (ci, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido, celular) 
                   VALUES (?, ?, ?, ?, ?, ?) RETURNING id_persona";
    
    $stmtP = $pdo->prepare($sqlPersona);
    $stmtP->execute([$ci, $p_nombre, $s_nombre, $p_apellido, $s_apellido, $celular]);
    
    $row = $stmtP->fetch(PDO::FETCH_ASSOC);
    $id_persona_nueva = $row['id_persona'];

    // 2. Insertar en tabla DOCENTES
    $sqlDocente = "INSERT INTO public.docentes (id_persona) VALUES (?)";
    $stmtD = $pdo->prepare($sqlDocente);
    $stmtD->execute([$id_persona_nueva]);

    $pdo->commit();
    echo json_encode(["exito" => true, "mensaje" => "Docente registrado con éxito."]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    
    // Manejo de error por CI duplicado (Constraint personas_ci_key)
    if ($e->getCode() == 23505) {
        echo json_encode(["exito" => false, "mensaje" => "Error: Ya existe una persona registrada con ese número de CI."]);
    } else {
        echo json_encode(["exito" => false, "mensaje" => "Error de BD: " . $e->getMessage()]);
    }
}