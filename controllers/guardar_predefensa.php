<?php
declare(strict_types=1);
session_start();
require_once '../config/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION["user_id"])) {
    echo json_encode(['status' => 'error', 'message' => 'Sesión no válida']);
    exit();
}

try {
    // ==================== RECIBIR Y VALIDAR DATOS ====================
    $id_estudiante = filter_input(INPUT_POST, 'id_estudiante', FILTER_VALIDATE_INT);
    $id_presidente = filter_input(INPUT_POST, 'id_presidente', FILTER_VALIDATE_INT);
    $id_secretario = filter_input(INPUT_POST, 'id_secretario', FILTER_VALIDATE_INT);
    $modalidad = $_POST['modalidad_titulacion'] ?? null;
    $tema = trim($_POST['tema'] ?? '');
    $fecha = $_POST['fecha'] ?? null;
    $hora = $_POST['hora'] ?? null;
    $id_aula = filter_input(INPUT_POST, 'id_aula', FILTER_VALIDATE_INT);
    $observaciones = trim($_POST['observaciones'] ?? '');

    // Validar campos requeridos
    if (!$id_estudiante || !$id_presidente || !$id_secretario || !$modalidad || !$fecha || !$hora || !$id_aula) {
        throw new Exception('Todos los campos requeridos deben estar completos');
    }

    // ==================== VALIDACIONES DE NEGOCIO ====================
    
    // RF-13: Presidente y Secretario deben ser diferentes
    if ($id_presidente === $id_secretario) {
        throw new Exception('RF-13: El Presidente y el Secretario NO pueden ser la misma persona');
    }

    // Validar modalidad
    $modalidades_validas = ['EXAMEN_GRADO', 'PROYECTO_GRADO', 'TESIS', 'TRABAJO_DIRIGIDO'];
    if (!in_array($modalidad, $modalidades_validas)) {
        throw new Exception('Modalidad de titulación inválida');
    }

    // Si NO es EXAMEN_GRADO, el tema es obligatorio
    if ($modalidad !== 'EXAMEN_GRADO' && empty($tema)) {
        throw new Exception('El tema es obligatorio para ' . str_replace('_', ' ', $modalidad));
    }

    // Si es EXAMEN_GRADO, no debe tener tema
    if ($modalidad === 'EXAMEN_GRADO' && !empty($tema)) {
        $tema = null; // Limpiar tema si es examen de grado
    }

    // VALIDACIÓN: Solo lunes a viernes
    $fecha_obj = new DateTime($fecha);
    $dia_semana = (int)$fecha_obj->format('N'); // 1=Lunes, 7=Domingo
    
    if ($dia_semana > 5) {
        $nombres_dias = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
        throw new Exception('RF-15: Las pre-defensas solo pueden programarse de lunes a viernes. Seleccionó: ' . $nombres_dias[$dia_semana]);
    }

    // VALIDACIÓN: No fechas pasadas
    $hoy = new DateTime();
    $hoy->setTime(0, 0, 0);
    if ($fecha_obj < $hoy) {
        throw new Exception('No se puede programar una pre-defensa en una fecha pasada');
    }

    // ==================== INICIAR TRANSACCIÓN ====================
    $pdo->beginTransaction();

    // Configurar usuario para auditoría
    try {
        $pdo->exec("SET app.current_user_id = " . intval($_SESSION['user_id']));
    } catch (PDOException $e) {
        // Continuar si no existe la configuración
    }

    // ==================== OBTENER ID DEL TUTOR ====================
    $sql_tutor = "SELECT id_docente FROM asignaciones_tutor 
                  WHERE id_estudiante = ? AND estado = 'ACTIVO' LIMIT 1";
    $stmt_tutor = $pdo->prepare($sql_tutor);
    $stmt_tutor->execute([$id_estudiante]);
    $id_tutor = $stmt_tutor->fetchColumn();

    if (!$id_tutor) {
        throw new Exception('El estudiante no tiene un tutor asignado');
    }

    // RF-14: El tutor NO puede ser parte del tribunal
    if ($id_tutor == $id_presidente || $id_tutor == $id_secretario) {
        throw new Exception('RF-14: El tutor NO puede formar parte del tribunal evaluador');
    }

    // ==================== VALIDAR DISPONIBILIDAD DEL AULA ====================
    // RF-15: No puede haber dos pre-defensas en la misma aula a la misma hora
    $sql_aula = "SELECT COUNT(*) FROM pre_defensas 
                 WHERE id_aula = ? 
                 AND fecha = ? 
                 AND hora = ?
                 AND estado != 'CANCELADA'";
    $stmt_aula = $pdo->prepare($sql_aula);
    $stmt_aula->execute([$id_aula, $fecha, $hora]);
    
    if ($stmt_aula->fetchColumn() > 0) {
        // Obtener nombre del aula para el mensaje
        $stmt_nombre_aula = $pdo->prepare("SELECT nombre_aula FROM aulas WHERE id_aula = ?");
        $stmt_nombre_aula->execute([$id_aula]);
        $nombre_aula = $stmt_nombre_aula->fetchColumn();
        
        throw new Exception("RF-15: El aula '$nombre_aula' ya está ocupada el $fecha a las $hora. Por favor seleccione otra aula u horario.");
    }

    // ==================== OBTENER O CREAR PROYECTO ====================
    $sql_proyecto = "SELECT id_proyecto FROM proyectos WHERE id_estudiante = ? LIMIT 1";
    $stmt_proyecto = $pdo->prepare($sql_proyecto);
    $stmt_proyecto->execute([$id_estudiante]);
    $id_proyecto = $stmt_proyecto->fetchColumn();

    // Si no existe proyecto, crear uno
    if (!$id_proyecto) {
        $sql_crear_proyecto = "INSERT INTO proyectos (id_estudiante, id_tutor, estado) 
                               VALUES (?, ?, 'EN_PROCESO') 
                               RETURNING id_proyecto";
        $stmt_crear = $pdo->prepare($sql_crear_proyecto);
        $stmt_crear->execute([$id_estudiante, $id_tutor]);
        $id_proyecto = $stmt_crear->fetchColumn();
    }

    // ==================== OBTENER GESTIÓN ACTUAL ====================
    $gestion_actual = date('Y');
    $mes_actual = (int)date('n');
    
    // Si estamos en agosto o después, es gestión del próximo año académico
    if ($mes_actual >= 8) {
        $gestion_actual = (string)((int)$gestion_actual + 1);
    }

    // ==================== INSERTAR PRE-DEFENSA ====================
    $sql_pre = "INSERT INTO pre_defensas (
                    id_estudiante, 
                    id_tutor, 
                    id_proyecto, 
                    gestion, 
                    modalidad_titulacion,
                    tema,
                    fecha, 
                    hora, 
                    id_aula, 
                    estado,
                    observaciones
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDIENTE', ?)
                RETURNING id_pre_defensa";
    
    $stmt_pre = $pdo->prepare($sql_pre);
    $stmt_pre->execute([
        $id_estudiante,
        $id_tutor,
        $id_proyecto,
        $gestion_actual,
        $modalidad,
        $tema,
        $fecha,
        $hora,
        $id_aula,
        $observaciones
    ]);
    
    $id_pre_defensa = $stmt_pre->fetchColumn();

    if (!$id_pre_defensa) {
        throw new Exception('Error al crear el registro de pre-defensa');
    }

    // ==================== INSERTAR TRIBUNAL (2 REGISTROS) ====================
    
    // 1. Insertar PRESIDENTE
    $sql_tribunal = "INSERT INTO tribunales_asignados (id_pre_defensa, id_docente, rol_tribunal) 
                     VALUES (?, ?, ?)";
    $stmt_tribunal = $pdo->prepare($sql_tribunal);
    
    $stmt_tribunal->execute([$id_pre_defensa, $id_presidente, 'PRESIDENTE']);
    
    // 2. Insertar SECRETARIO
    $stmt_tribunal->execute([$id_pre_defensa, $id_secretario, 'SECRETARIO']);

    // ==================== COMMIT TRANSACCIÓN ====================
    $pdo->commit();

    // Log exitoso (opcional)
    error_log("Pre-defensa creada exitosamente: ID=$id_pre_defensa, Estudiante=$id_estudiante, Modalidad=$modalidad");

    echo json_encode([
        'status' => 'success',
        'message' => 'Pre-defensa registrada exitosamente',
        'data' => [
            'id_pre_defensa' => $id_pre_defensa,
            'gestion' => $gestion_actual,
            'modalidad' => $modalidad
        ]
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Log del error
    error_log("Error PDO en guardar_predefensa: " . $e->getMessage());
    
    // Mensajes específicos según el error
    $mensaje_error = 'Error al registrar la pre-defensa';
    
    if (strpos($e->getMessage(), 'foreign key') !== false) {
        $mensaje_error = 'Error de integridad: verifique que todos los datos sean válidos';
    } elseif (strpos($e->getMessage(), 'duplicate') !== false) {
        $mensaje_error = 'Ya existe un registro similar. Por favor verifique los datos.';
    }
    
    echo json_encode([
        'status' => 'error',
        'message' => $mensaje_error,
        'debug' => $e->getMessage() // Quitar en producción
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Error en guardar_predefensa: " . $e->getMessage());
    
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>