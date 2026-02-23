<?php
session_start();
// Asegúrate de que este archivo contenga los datos de conexión a la BD.
require_once "conexion.php"; 

if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit();
}

$rol = strtolower($_SESSION["rol"] ?? 'usuario');
$usuario = htmlspecialchars($_SESSION["usuario"] ?? 'Usuario');
$menu_item_agendar = in_array($rol, ["admin","docente","coordinador"]) ? 
    '<li class="nav-item"><a class="nav-link" href="predefensas.php"><i class="fa-solid fa-file-signature me-1"></i> Agendar Pre-Defensa</a></li>' : '';

$error = "";
$resultados = [];

// Valores de filtro del formulario (POST se usa para aplicar filtros)
$fecha_inicio = $_POST['fecha_inicio'] ?? date('Y-m-01'); // Por defecto: inicio del mes actual
$fecha_fin = $_POST['fecha_fin'] ?? date('Y-m-d'); // Por defecto: hoy
$gestion_filtro = $_POST['gestion_filtro'] ?? ''; // Filtro por año/gestión
$carrera_filtro = $_POST['carrera_filtro'] ?? ''; // Nuevo filtro por Carrera

// 1. Obtener la lista de Carreras para el SELECT
$carreras = [];
if (isset($conexion) && !$conexion->connect_error) {
    $result_carreras = $conexion->query("SELECT id_carrera, nombre_carrera FROM carreras ORDER BY nombre_carrera ASC");
    if ($result_carreras) {
        while ($row = $result_carreras->fetch_assoc()) {
            $carreras[] = $row;
        }
    }
}

// --- 2. Lógica de la Consulta Dinámica ---

$sql = "SELECT
    CONCAT_WS(' ', e.primer_nombre, e.segundo_nombre, e.primer_apellido, e.segundo_apellido) AS nombre_estudiante,
    c.nombre_carrera,
    d.fecha,
    d.estado,
    d.nota_pre_defensa,
    YEAR(d.fecha) AS gestion_anio,
    -- Definición de la gestión 1 o 2 (Enero-Junio = G1, Julio-Diciembre = G2)
    CASE 
        WHEN MONTH(d.fecha) BETWEEN 1 AND 6 THEN 'G1'
        WHEN MONTH(d.fecha) BETWEEN 7 AND 12 THEN 'G2'
        ELSE ''
    END AS gestion_semestre
FROM pre_defensas d
JOIN estudiantes e ON d.id_estudiante = e.id_estudiante
JOIN carreras c ON e.id_carrera = c.id_carrera
WHERE d.fecha BETWEEN ? AND ? "; // Siempre filtramos por rango de fechas

// 3. Preparar parámetros y tipos
$tipos = "ss";
$params = [$fecha_inicio, $fecha_fin];

// Añadir filtro por Gestión (Año) si se proporciona
if (!empty($gestion_filtro)) {
    $sql .= " AND YEAR(d.fecha) = ? ";
    $tipos .= "i"; // 'i' para entero (año)
    $params[] = (int)$gestion_filtro;
}

// Añadir filtro por Carrera si se proporciona
if (!empty($carrera_filtro)) {
    $sql .= " AND c.id_carrera = ? ";
    $tipos .= "i"; // 'i' para entero (id_carrera)
    $params[] = (int)$carrera_filtro;
}

$sql .= " ORDER BY d.fecha DESC, gestion_anio DESC;";

// Ejecución de la consulta usando sentencias preparadas (más seguro)
if (!isset($conexion) || $conexion->connect_error) {
    $error = "Error de conexión a la base de datos: " . ($conexion->connect_error ?? "Conexión no establecida.");
} else {
    // 4. Preparar y Ejecutar la sentencia
    $stmt = $conexion->prepare($sql);

    if ($stmt) {
        if (!empty($params)) {
            // Pasamos los parámetros a bind_param
            $stmt->bind_param($tipos, ...$params);
        }

        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $resultados[] = $row;
            }
            $result->free();
        } else {
            $error = "Error al ejecutar la consulta: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error = "Error al preparar la consulta: " . $conexion->error;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reporte Filtrable por Fechas y Gestión</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="favicon.png">

<style>
/* --- ESTILOS MEJORADOS --- */
:root { 
    --color-principal: #7b1113; 
    --color-acento: #ffc107; 
    --color-claro: #fcfcfc;
}
body{ 
    font-family:'Poppins',sans-serif; 
    background-color: #f5f5f5; /* Fondo más suave */
    padding-top: 70px; 
}
.navbar { 
    background: linear-gradient(90deg, var(--color-principal), #a51417); 
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3); 
}
main{ 
    max-width: 98%; 
    margin: 20px auto 80px; 
    background: var(--color-claro); 
    border-radius: 18px; 
    padding: 30px; 
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15); /* Sombra más pronunciada */
}
h3.page-title { 
    color: var(--color-principal); 
    font-weight: 700; 
    border-bottom: 4px solid var(--color-acento); /* Borde más grueso */
    padding-bottom: 12px; 
    margin-bottom: 25px;
}
.form-card { 
    background-color: #ffffff; /* Fondo blanco para el formulario */
    border: 1px solid #ddd;
    border-radius: 10px;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
}
.btn-primary-custom { 
    background-color: var(--color-principal); 
    border-color: var(--color-principal); 
    transition: background-color 0.3s ease;
}
.btn-primary-custom:hover { 
    background-color: #a51417; 
    border-color: #a51417; 
    transform: translateY(-1px);
}
.table-dark, .table-dark th { 
    background-color: var(--color-principal) !important; 
    color: white; 
}
.table-striped>tbody>tr:nth-of-type(odd)>*{ 
    --bs-table-bg-type: #fff0f0; 
}
.badge-aprobado { background-color: #28a745; }
.badge-reprobado { background-color: #dc3545; }
.badge-info { 
    background-color: var(--color-acento) !important; 
    color: #333 !important; 
    font-weight: 600;
}
footer { 
    background: var(--color-principal); 
    color: #f0f0f0; 
    padding: 14px; 
    position: fixed; 
    bottom: 0; 
    width: 100%; 
}
/* Estilo para el botón de impresión */
.btn-print-custom {
    background-color: #007bff; /* Azul estándar */
    border-color: #007bff;
    color: white;
    transition: all 0.3s ease;
}
.btn-print-custom:hover {
    background-color: #0056b3;
    border-color: #0056b3;
    transform: translateY(-1px);
}
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
<div class="container-fluid">
    <a class="navbar-brand" href="menu.php">Sistema de Titulación</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menuSuperior"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="menuSuperior">
        <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
            <?= $menu_item_agendar ?>
            <li class="nav-item"><a class="nav-link" href="listadopre.php"><i class="fa-solid fa-shield-halved me-1"></i> Listado</a></li>
            <li class="nav-item"><a class="nav-link active" aria-current="page" href="reportes.php"><i class="fa-solid fa-chart-column me-1"></i> Reportes</a></li>
             <li class="nav-item dropdown ms-lg-2">
                 <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"><i class="bi bi-person-circle"></i> <?= $usuario ?></a>
                 <ul class="dropdown-menu dropdown-menu-end">
                     <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i> Cerrar sesión</a></li>
                 </ul>
             </li>
        </ul>
    </div>
</div>
</nav>

<main class="container my-4">
    <h3 class="text-center page-title"><i class="bi bi-funnel-fill me-2"></i> Reporte Detallado de Pre-Defensas</h3>

    <div class="card p-4 mb-4 form-card shadow-sm">
        <form method="POST" id="formFiltros" action="reportes_filtrables.php">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="fecha_inicio" class="form-label fw-bold">Fecha de Inicio</label>
                    <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>" required>
                </div>
                <div class="col-md-3">
                    <label for="fecha_fin" class="form-label fw-bold">Fecha de Fin</label>
                    <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>" required>
                </div>
                <div class="col-md-3">
                    <label for="gestion_filtro" class="form-label fw-bold">Gestión (Año)</label>
                    <input type="number" class="form-control" id="gestion_filtro" name="gestion_filtro" placeholder="Ej. 2024" value="<?= htmlspecialchars($gestion_filtro) ?>">
                </div>
                <div class="col-md-3">
                    <label for="carrera_filtro" class="form-label fw-bold">Carrera</label>
                    <select class="form-select" id="carrera_filtro" name="carrera_filtro">
                        <option value="">Todas las Carreras</option>
                        <?php foreach ($carreras as $c): ?>
                            <option value="<?= $c['id_carrera'] ?>" <?= ((string)$carrera_filtro === (string)$c['id_carrera']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['nombre_carrera']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 mt-4 d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-primary-custom">
                        <i class="bi bi-search me-1"></i> Aplicar Filtros
                    </button>
                    <button type="button" id="btnImprimir" class="btn btn-print-custom">
                        <i class="bi bi-printer-fill me-1"></i> Imprimir Reporte (PDF)
                    </button>
                </div>
            </div>
        </form>
    </div>

    <?php if($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Error:</strong> <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if(count($resultados) > 0): ?>
    
    <p class="text-muted text-center mb-4">Mostrando **<?= count($resultados) ?>** pre-defensas realizadas entre <?= htmlspecialchars($fecha_inicio) ?> y <?= htmlspecialchars($fecha_fin) ?>.</p>

    <div class="table-responsive shadow-lg rounded-3">
        <table class="table table-striped table-hover table-bordered align-middle" id="tablaReporteFiltro">
            <thead class="table-dark">
                <tr>
                    <th>Estudiante</th>
                    <th>Carrera</th>
                    <th class="text-center">Fecha</th>
                    <th class="text-center">Gestión (Año)</th>
                    <th class="text-center">G. Semestre</th>
                    <th class="text-center">Estado</th>
                    <th class="text-center">Nota</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resultados as $row): ?>
                <tr>
                    <td class="fw-bold"><?= htmlspecialchars($row['nombre_estudiante']) ?></td>
                    <td><?= htmlspecialchars($row['nombre_carrera']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($row['fecha']) ?></td>
                    <td class="text-center">
                        <span class="badge bg-secondary fs-6 py-1 px-3"><?= htmlspecialchars($row['gestion_anio']) ?></span>
                    </td>
                    <td class="text-center">
                        <span class="badge badge-info fs-6 py-1 px-3"><?= htmlspecialchars($row['gestion_semestre']) ?></span>
                    </td>
                    <td class="text-center">
                        <?php 
                            $estado = htmlspecialchars($row['estado']);
                            $clase = ($estado == 'Aprobado') ? 'badge-aprobado' : 'badge-reprobado';
                        ?>
                        <span class="badge <?= $clase ?> fs-6 py-1 px-2"><?= $estado ?></span>
                    </td>
                    <td class="text-center"><?= htmlspecialchars($row['nota_pre_defensa']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php else: ?>
        <div class="alert alert-warning text-center p-4 rounded-3 border-0 shadow-sm">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-2"></i>
            <strong>Aviso:</strong> No se encontraron pre-defensas con los filtros aplicados. Intenta con un rango de fechas diferente.
        </div>
    <?php endif; ?>

</main>

<footer>
    <div class="container">
        © 2025 Universidad - Sistema de Titulación | Reportes Filtrables
    </div>
</footer>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function(){
    
    $('#tablaReporteFiltro').DataTable({
        paging: true,
        ordering: true, 
        searching: true,
        info: true,
        responsive: true,
        lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Todos"]],
        language:{ 
            url:'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
        },
        order: [[2, 'desc']] 
    });

  
    $('#btnImprimir').on('click', function() {
      
        const fecha_inicio = $('#fecha_inicio').val();
        const fecha_fin = $('#fecha_fin').val();
        const gestion_filtro = $('#gestion_filtro').val();
        const carrera_filtro = $('#carrera_filtro').val(); 

        const view_type = 'predefensas_filtradas'; 

    
        const printUrl = `generar_reporte_pdf.php?view=${view_type}&fecha_inicio=${fecha_inicio}&fecha_fin=${fecha_fin}&gestion_filtro=${gestion_filtro}&carrera_filtro=${carrera_filtro}`;

        window.open(printUrl, '_blank');
    });
});
</script>

</body>
</html>