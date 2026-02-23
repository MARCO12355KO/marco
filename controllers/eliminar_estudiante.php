<?php
session_start();
include_once '../config/conexion.php';


if (!isset($_SESSION["user_id"])) {
    header("Location: ../views/login.php");
    exit();
}

if (isset($_GET['id']) && isset($_GET['estado'])) {
    $id = $_GET['id'];
    $nuevo_estado = $_GET['estado']; 

    try {
        $pdo->beginTransaction();

     
        $pdo->exec("SET app.current_user_id = " . intval($_SESSION['user_id']));
        $sql = "UPDATE public.personas SET estado = ? WHERE id_persona = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nuevo_estado, $id]);

        $pdo->commit();
        header("Location: ../views/lisra_estudiantes.php?msg=success");
        exit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Error en cambio de estado: " . $e->getMessage());
        header("Location: ../views/lisra_estudiantes.php?msg=error");
        exit();
    }
}