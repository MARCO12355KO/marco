<?php
require_once("conexion.php");
header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id_persona = $_POST['id_persona'];
    $nombre     = $_POST['nombre'];
    $apellido   = $_POST['apellido'];
    $ci         = $_POST['ci'];
    $celular    = $_POST['celular'];
    $ru         = $_POST['ru'];
    $id_carrera = $_POST['id_carrera'];

    try {
        $pdo->beginTransaction();

        // 1. Actualizar tabla Personas
        $sqlP = "UPDATE personas SET ci = ?, primer_nombre = ?, primer_apellido = ?, celular = ? WHERE id_persona = ?";
        $pdo->prepare($sqlP)->execute([$ci, $nombre, $apellido, $celular, $id_persona]);

        // 2. Actualizar tabla Estudiantes
        $sqlE = "UPDATE estudiantes SET id_carrera = ?, ru = ? WHERE id_persona = ?";
        $pdo->prepare($sqlE)->execute([$id_carrera, $ru, $id_persona]);

        $pdo->commit();
        echo json_encode(["exito" => true]);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(["exito" => false, "mensaje" => $e->getMessage()]);
    }
}