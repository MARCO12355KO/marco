<?php
declare(strict_types=1);
session_start();
require_once '../config/conexion.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: ../views/login.php");
    exit();
}

$id_persona   = $_GET['id'] ?? null;
$nuevo_estado = strtoupper($_GET['estado'] ?? '');

if (!$id_persona || !in_array($nuevo_estado, ['ACTIVO', 'INACTIVO'])) {
    header("Location: ../views/lista_tutores.php?error=datos_insuficientes");
    exit();
}

try {
    // Actualizamos el estado en la tabla personas (donde reside el estado general del usuario/docente)
    $sql = "UPDATE public.personas SET estado = ? WHERE id_persona = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$nuevo_estado, $id_persona]);

    header("Location: ../views/lista_tutores.php?msg=docente_actualizado");
} catch (PDOException $e) {
    error_log("Error en cambiar_estado: " . $e->getMessage());
    die("Error al procesar el cambio de estado del docente.");
}