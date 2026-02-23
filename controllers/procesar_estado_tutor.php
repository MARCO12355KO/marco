<?php
session_start();
include_once '../config/conexion.php';
if (!isset($_SESSION["user_id"]) || !isset($_GET['id']) || !isset($_GET['estado'])) {
    header("Location: lista_tutores.php");
    exit();
}

$id = $_GET['id'];
$nuevo_estado = ($_GET['estado'] === 'activo') ? 'activo' : 'inactivo';

try {
    $stmtAudit = $pdo->prepare("SELECT set_config('app.current_user_id', :uid, false)");
    $stmtAudit->execute(['uid' => (string)$_SESSION['user_id']]);

    $sql = "UPDATE personas SET estado = :est WHERE id_persona = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['est' => $nuevo_estado, 'id' => $id]);

    header("Location: ../views/ lista_tutores.php?msj=estado_actualizado");
} catch (PDOException $e) {
    header("Location: ../views/ lista_tutores.php?error=no_se_pudo_cambiar");
}