<?php
declare(strict_types=1);
session_start();
require_once("../config/conexion.php");

if (!isset($_SESSION["user_id"])) { 
    header("Location: login.php");
    exit();
}

try {
    // 1. Obtener Estudiantes que TIENEN TUTOR pero NO tienen TRIBUNAL
    $sql_est = "SELECT 
                    a.id_asignacion, 
                    p.primer_nombre, 
                    p.primer_apellido, 
                    e.ru 
                FROM public.asignaciones_tutor a
                JOIN public.estudiantes e ON a.id_estudiante = e.id_persona
                JOIN public.personas p ON e.id_persona = p.id_persona
                LEFT JOIN public.tribunales t ON a.id_asignacion = t.id_asignacion
                WHERE p.estado ILIKE 'activo' 
                AND t.id_tribunal IS NULL 
                ORDER BY p.primer_apellido ASC";
    $estudiantes = $pdo->query($sql_est)->fetchAll(PDO::FETCH_ASSOC);

    // 2. Obtener Docentes para ser Tribunales
    $sql_doc = "SELECT d.id_persona, p.primer_nombre, p.primer_apellido 
                FROM public.docentes d 
                JOIN public.personas p ON d.id_persona = p.id_persona 
                WHERE p.estado ILIKE 'activo'
                ORDER BY p.primer_apellido ASC";
    $docentes = $pdo->query($sql_doc)->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Asignar Tribunal | UNIOR</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    <style>
        body { background: #f4f7f6; padding: 20px; }
        .card-tribunal { background: white; border-radius: 15px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .form-label { font-weight: bold; color: #444; }
    </style>
</head>
<body>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8 card-tribunal">
            <h2 class="mb-4 text-primary">Asignar Tribunal Académico</h2>
            <form id="formTribunal">
                <div class="mb-4">
                    <label class="form-label">Estudiante (Solo con tutor asignado)</label>
                    <select name="id_asignacion" class="form-select select2" required>
                        <option value="">Seleccione al estudiante...</option>
                        <?php foreach($estudiantes as $est): ?>
                            <option value="<?= $est['id_asignacion'] ?>">
                                <?= $est['primer_apellido'] ?> <?= $est['primer_nombre'] ?> (RU: <?= $est['ru'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-4">
                        <label class="form-label">Presidente del Tribunal</label>
                        <select name="id_presidente" class="form-select select2" required>
                            <option value="">Seleccionar Docente...</option>
                            <?php foreach($docentes as $doc): ?>
                                <option value="<?= $doc['id_persona'] ?>"><?= $doc['primer_apellido'] ?> <?= $doc['primer_nombre'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-4">
                        <label class="form-label">Secretario del Tribunal</label>
                        <select name="id_secretario" class="form-select select2" required>
                            <option value="">Seleccionar Docente...</option>
                            <?php foreach($docentes as $doc): ?>
                                <option value="<?= $doc['id_persona'] ?>"><?= $doc['primer_apellido'] ?> <?= $doc['primer_nombre'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label">Fecha de Designación</label>
                    <input type="date" name="fecha_designacion" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">REGISTRAR TRIBUNAL</button>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    $(document).ready(function() {
        $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

        $('#formTribunal').on('submit', function(e) {
            e.preventDefault();
            
            // Validación: No pueden ser el mismo docente
            if($('select[name="id_presidente"]').val() === $('select[name="id_secretario"]').val()){
                return Swal.fire('Error', 'El Presidente y Secretario no pueden ser la misma persona', 'error');
            }

            $.ajax({
                url: 'guardar_tribunal.php',
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function(res) {
                    if(res.status === 'success') {
                        Swal.fire('¡Éxito!', res.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        });
    });
</script>
</body>
</html>