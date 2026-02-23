<?php
session_start();
require_once("conexion.php");

if (!isset($_SESSION["user_id"])) { 
    header("Location: login.php");
    exit();
}

$id = $_GET['id'] ?? null;
$nuevo_estado = $_GET['nuevo_estado'] ?? 'inactivo';

if ($id) {
    try {
        // ACTUALIZAMOS EL ESTADO EN LUGAR DE ELIMINAR
        $sql = "UPDATE personas SET estado = ? WHERE id_persona = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nuevo_estado, $id]);

        header("Location: lista_tutores.php?msj=estado_cambiado");
    } catch (PDOException $e) {
        header("Location: lista_tutores.php?error=" . urlencode($e->getMessage()));
    }
} else {
    header("Location: lista_tutores.php");
}