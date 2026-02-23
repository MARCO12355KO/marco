<?php
// controllers/actualizar_tutor.php
session_start();
require_once("../config/conexion.php");

// 1. Configurar cabecera para responder JSON
header('Content-Type: application/json');

// 2. Verificar si es una petición POST y si hay sesión
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION["user_id"])) {
    echo json_encode(['status' => 'error', 'message' => 'Acceso no autorizado']);
    exit();
}

// 3. Recibir y limpiar datos
$id_persona   = isset($_POST['id_persona']) ? (int)$_POST['id_persona'] : 0;
$nombres      = trim($_POST['nombres'] ?? '');
$apellido_p   = trim($_POST['apellido_p'] ?? '');
$apellido_m   = trim($_POST['apellido_m'] ?? '');
$ci           = trim($_POST['ci'] ?? '');
$celular      = trim($_POST['celular'] ?? '');
$id_carrera   = isset($_POST['id_carrera']) ? (int)$_POST['id_carrera'] : 0;
$especialidad = trim($_POST['especialidad'] ?? '');

// 4. Validación básica
if ($id_persona === 0 || empty($nombres) || empty($apellido_p) || empty($ci) || $id_carrera === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Todos los campos obligatorios deben ser llenados']);
    exit();
}

try {
    // Iniciar Transacción (Para asegurar que se actualicen ambas tablas o ninguna)
    $pdo->beginTransaction();

    // A. Actualizar tabla PERSONAS
    $sql_p = "UPDATE public.personas 
              SET primer_nombre = ?, primer_apellido = ?, segundo_apellido = ?, ci = ?, celular = ? 
              WHERE id_persona = ?";
    $stmt_p = $pdo->prepare($sql_p);
    $stmt_p->execute([$nombres, $apellido_p, $apellido_m, $ci, $celular, $id_persona]);

    // B. Actualizar tabla DOCENTES
    $sql_d = "UPDATE public.docentes 
              SET id_carrera = ?, especialidad = ? 
              WHERE id_persona = ?";
    $stmt_d = $pdo->prepare($sql_d);
    $stmt_d->execute([$id_carrera, $especialidad, $id_persona]);

    // Confirmar cambios
    $pdo->commit();

    echo json_encode([
        'status' => 'success', 
        'message' => 'Los datos del tutor han sido actualizados correctamente.'
    ]);

} catch (PDOException $e) {
    // Si algo falla, deshacer cambios
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode([
        'status' => 'error', 
        'message' => 'Error en la base de datos: ' . $e->getMessage()
    ]);
}