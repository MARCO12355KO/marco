<?php
session_start();
include_once '../config/conexion.php';

if (!isset($_GET['id'])) {
    header("Location: ../views/lista_tribunales.php");
    exit();
}

try {
    $id = $_GET['id'];

    // Realizamos un UPDATE para cambiar el estado a 'INACTIVO' (o 'PENDIENTE')
    // Esto quita a los docentes asignados pero mantiene el historial en la DB
    $sql = "UPDATE public.tribunales SET estado = 'INACTIVO' WHERE id_asignacion = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);

    // Redireccionamos con un mensaje de éxito
    header("Location: ../views/lista_tribunales.php?msj=eliminado");
} catch (PDOException $e) {
    die("Error al desactivar tribunal: " . $e->getMessage());
}