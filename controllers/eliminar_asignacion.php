<?php
declare(strict_types=1);
session_start();
require_once '../config/conexion.php';

if (!isset($_SESSION["user_id"])) { exit("Acceso denegado"); }

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$nuevo_estado = $_GET['estado'] ?? 'INACTIVO'; 

if ($id) {
    try {

        $stmt = $pdo->prepare("UPDATE public.asignaciones_tutor SET estado = :estado WHERE id_asignacion = :id");
        $stmt->execute([':estado' => $nuevo_estado, ':id' => $id]);
        
        header("Location: ../views/lista_tutores.php?res=estado_cambiado");
    } catch (PDOException $e) {
        die("Error: " . $e->getMessage());
    }
}