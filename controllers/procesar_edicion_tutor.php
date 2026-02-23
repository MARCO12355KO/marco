<?php
header('Content-Type: application/json');
session_start();
require_once("conexion.php");

$response = ["exito" => false, "mensaje" => ""];

// Verificar si el usuario está logueado
if (!isset($_SESSION["user_id"])) {
    $response["mensaje"] = "Sesión no autorizada.";
    echo json_encode($response);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id_persona = $_POST['id_persona'] ?? null;
    $ci = $_POST['ci'] ?? null;
    $nombre = $_POST['nombre'] ?? null;
    $apellido = $_POST['apellido'] ?? null;
    $celular = $_POST['celular'] ?? null;

    if (!$id_persona || !$ci || !$nombre || !$apellido) {
        $response["mensaje"] = "Todos los campos obligatorios deben ser llenados.";
        echo json_encode($response);
        exit();
    }

    try {
        // Actualizamos solo la tabla personas (los docentes están ligados a este ID)
        $sql = "UPDATE public.personas 
                SET ci = :ci, 
                    primer_nombre = :nombre, 
                    primer_apellido = :apellido, 
                    celular = :celular 
                WHERE id_persona = :id";
        
        $stmt = $pdo->prepare($sql);
        $resultado = $stmt->execute([
            ':ci'      => $ci,
            ':nombre'  => $nombre,
            ':apellido'=> $apellido,
            ':celular' => $celular,
            ':id'      => $id_persona
        ]);

        if ($resultado) {
            $response["exito"] = true;
            $response["mensaje"] = "Datos actualizados correctamente.";
        } else {
            $response["mensaje"] = "No se realizaron cambios o el registro no existe.";
        }

    } catch (PDOException $e) {
        // Manejo de error por si intenta poner un CI que ya pertenece a otra persona
        if (strpos($e->getMessage(), 'personas_ci_key') !== false) {
            $response["mensaje"] = "Error: El CI ya está registrado por otro usuario.";
        } else {
            $response["mensaje"] = "Error en la base de datos: " . $e->getMessage();
        }
    }
} else {
    $response["mensaje"] = "Método de solicitud no válido.";
}

echo json_encode($response);