<?php
session_start(); 
require_once '../config/conexion.php';
header('Content-Type: application/json');

if (!isset($_SESSION["user_id"])) {
    echo json_encode(['exito' => false, 'mensaje' => 'No autorizado']);
    exit();
}

try {
    $pdo->beginTransaction();
    
    // Configurar usuario para auditoría
    try {
        $pdo->exec("SET app.current_user_id = " . intval($_SESSION['user_id']));
    } catch (PDOException $e) {
        // Si no existe la configuración, continuar
    }

    // Preparar datos
    $ci = trim($_POST['ci']);
    $primer_nombre = trim($_POST['primer_nombre']);
    $segundo_nombre = !empty($_POST['segundo_nombre']) ? trim($_POST['segundo_nombre']) : null;
    $primer_apellido = trim($_POST['primer_apellido']);
    $segundo_apellido = !empty($_POST['segundo_apellido']) ? trim($_POST['segundo_apellido']) : null;
    $celular = trim($_POST['celular']);
    $id_carrera = intval($_POST['id_carrera']);
    $ru = trim($_POST['ru']);

    // Insertar en personas
    $sqlP = "INSERT INTO public.personas (ci, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido, celular, estado) 
             VALUES (:ci, :p_nom, :s_nom, :p_ape, :s_ape, :cel, 'ACTIVO') 
             RETURNING id_persona";
    
    $stmtP = $pdo->prepare($sqlP);
    $stmtP->execute([
        ':ci'    => $ci, 
        ':p_nom' => $primer_nombre, 
        ':s_nom' => $segundo_nombre,
        ':p_ape' => $primer_apellido, 
        ':s_ape' => $segundo_apellido, 
        ':cel'   => $celular
    ]);
    
    $id_persona = $stmtP->fetchColumn();

    if (!$id_persona) {
        throw new Exception("Error al generar ID de persona");
    }

    // Insertar en estudiantes
    $stmtE = $pdo->prepare("INSERT INTO public.estudiantes (id_persona, id_carrera, ru) VALUES (?, ?, ?)");
    $stmtE->execute([$id_persona, $id_carrera, $ru]);

    // Insertar en habilitacion_ministerio (CORREGIDO: usa ru_estudiante en lugar de id_persona)
    $stmtH = $pdo->prepare("INSERT INTO public.habilitacion_ministerio (ru_estudiante, esta_habilitado) VALUES (?, false)");
    $stmtH->execute([$ru]);

    $pdo->commit();
    echo json_encode(['exito' => true, 'mensaje' => 'Estudiante registrado correctamente']);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    
    // Errores específicos
    if ($e->getCode() == '23505') { 
        // Duplicado
        if (strpos($e->getMessage(), 'ci') !== false) {
            echo json_encode(['exito' => false, 'mensaje' => 'La C.I. ya está registrada']);
        } elseif (strpos($e->getMessage(), 'ru') !== false) {
            echo json_encode(['exito' => false, 'mensaje' => 'El R.U. ya está registrado']);
        } else {
            echo json_encode(['exito' => false, 'mensaje' => 'C.I. o R.U. ya registrado']);
        }
    } else {
        echo json_encode(['exito' => false, 'mensaje' => 'Error en la base de datos: ' . $e->getMessage()]);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['exito' => false, 'mensaje' => $e->getMessage()]);
}
?>