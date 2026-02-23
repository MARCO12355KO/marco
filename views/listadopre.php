<?php
session_start();
require_once '../config/conexion.php'; 

if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit(); }

$nombre_usuario = $_SESSION["nombre_completo"] ?? 'Usuario';
$rol_usuario    = $_SESSION["rol"] ?? 'administrador'; 
$inicial        = strtoupper(substr($nombre_usuario, 0, 1));

try {
    $sql = "SELECT pd.*, 
            (p.primer_nombre || ' ' || p.primer_apellido) AS estudiante,
            c.nombre_carrera, a.nombre_aula, df.id_defensa_final
            FROM public.pre_defensas pd
            JOIN public.personas p ON pd.id_estudiante = p.id_persona
            JOIN public.estudiantes e ON p.id_persona = e.id_persona
            JOIN public.carreras c ON e.id_carrera = c.id_carrera
            LEFT JOIN public.aulas a ON pd.id_aula = a.id_aula
            LEFT JOIN public.defensas_finales df ON pd.id_pre_defensa = df.id_pre_defensa
            ORDER BY pd.fecha_pre DESC";
    $predefensas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // Lista de carreras para el filtro
    $carreras = $pdo->query("SELECT nombre_carrera FROM public.carreras ORDER BY nombre_carrera")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { die("Error: " . $e->getMessage()); }

// Contadores para los Badges
$c_pen = 0; $c_apr = 0; $c_rep = 0;
foreach($predefensas as $p) {
    if($p['nota_pre_defensa'] === null) $c_pen++;
    elseif($p['nota_pre_defensa'] >= 51) $c_apr++; // Ajusté a 51 que suele ser el aprobado
    else $c_rep++;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pre-Defensas Aesthetic - UNIOR</title>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@300;500;800&family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        :root {
            --glass: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.8);
            --accent: #818cf8;
            --text-dark: #1e293b;
            --bg-main: #f8fafc;
            --shadow-aest: 0 20px 40px rgba(0, 0, 0, 0.03);
            --ease: cubic-bezier(0.4, 0, 0.2, 1);
        }

        body { 
            font-family: 'Inter', sans-serif; background-color: var(--bg-main); color: var(--text-dark);
            margin: 0; min-height: 100vh; display: flex;
            background-image: radial-gradient(at 0% 0%, rgba(129, 140, 248, 0.08) 0, transparent 40%);
        }

        /* --- SIDEBAR IGUAL AL MENU --- */
        .sidebar {
            width: 80px; height: 94vh; margin: 3vh 0 3vh 20px;
            background: var(--glass); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border-radius: 30px; border: 1px solid var(--glass-border);
            display: flex; flex-direction: column; align-items: center; padding: 30px 0;
            position: sticky; top: 3vh; transition: width 0.4s var(--ease);
            box-shadow: var(--shadow-aest); z-index: 1000;
        }
        @media (min-width: 992px) { .sidebar:hover { width: 260px; align-items: flex-start; padding: 30px 20px; } }
        
        .nav-item-ae {
            width: 100%; display: flex; align-items: center; padding: 14px; margin-bottom: 8px;
            border-radius: 18px; color: #64748b; text-decoration: none; transition: 0.3s;
        }
        .nav-item-ae i { font-size: 1.2rem; min-width: 50px; text-align: center; }
        .nav-item-ae span { opacity: 0; font-weight: 600; white-space: nowrap; transition: 0.2s; position: absolute; }
        .sidebar:hover .nav-item-ae span { opacity: 1; position: relative; margin-left: 10px; }
        .nav-item-ae:hover, .nav-item-ae.active { background: white; color: var(--accent); box-shadow: 0 10px 20px rgba(0,0,0,0.03); }

        /* --- CONTENIDO --- */
        .main-wrapper { flex: 1; padding: 40px; max-width: 1600px; margin: 0 auto; width: 100%; }
        
        .hero-title {
            font-family: 'Bricolage Grotesque', sans-serif; font-size: 3.5rem;
            font-weight: 300; letter-spacing: -2px; line-height: 0.9; margin-bottom: 30px;
        }

        .cloud-card {
            background: var(--glass); border: 1px solid var(--glass-border);
            border-radius: 35px; padding: 30px; box-shadow: var(--shadow-aest);
            backdrop-filter: blur(15px); transition: 0.3s var(--ease);
        }

        /* --- TABS ESTILO PILDORA --- */
        .nav-pills-ae .nav-link {
            border-radius: 100px; color: #64748b; font-weight: 600; padding: 10px 20px; 
            font-size: 0.85rem; border: 1px solid transparent; transition: 0.3s;
        }
        .nav-pills-ae .nav-link.active { background: white !important; color: var(--accent) !important; border-color: var(--glass-border); box-shadow: 0 5px 15px rgba(0,0,0,0.05); }

        /* --- TABLA --- */
        .table-ae { border-radius: 25px; overflow: hidden; background: rgba(255,255,255,0.4); }
        .table-ae thead th { background: transparent; border: none; color: #94a3b8; font-size: 0.75rem; text-transform: uppercase; padding: 20px; }
        .table-ae tbody td { border: none; padding: 15px 20px; vertical-align: middle; }
        .tr-hover { transition: 0.2s; border-radius: 15px; }
        .tr-hover:hover { background: white; transform: scale(1.01); box-shadow: 0 10px 20px rgba(0,0,0,0.02); }

        .btn-calificar {
            background: var(--dark); color: white; border-radius: 100px;
            padding: 8px 20px; font-weight: 600; font-size: 0.8rem; border: none; transition: 0.3s;
        }
        .btn-calificar:hover { background: var(--accent); transform: translateY(-2px); }

        .search-box-ae {
            background: white; border-radius: 100px; border: 1px solid #e2e8f0;
            padding: 10px 20px; display: flex; align-items: center; gap: 10px; margin-bottom: 20px;
        }
        .search-box-ae input, .search-box-ae select { border: none; outline: none; width: 100%; font-size: 0.9rem; background: transparent; }

        .reveal { animation: fadeIn 0.8s var(--ease) forwards; opacity: 0; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

    <aside class="sidebar">
        <div style="color: var(--accent); font-size: 1.5rem; margin-bottom: 30px;"><i class="fas fa-wind"></i></div>
        <nav style="width: 100%;">
            <a href="menu.php" class="nav-item-ae"><i class="fas fa-home-alt"></i> <span>Inicio</span></a>
            <a href="lisra_estudiantes.php" class="nav-item-ae"><i class="fas fa-users-rays"></i> <span>Estudiantes</span></a>
            <a href="predefensas.php" class="nav-item-ae active"><i class="fas fa-file-signature"></i> <span>Pre-Defensas</span></a>
        </nav>
    </aside>

    <main class="main-wrapper">
        <header class="reveal">
            <p class="text-muted fw-600 mb-0" style="letter-spacing: 3px; font-size: 0.8rem;">CONTROL DE NOTAS</p>
            <h1 class="hero-title">Actas de<br><span style="font-weight: 800; color: var(--accent);">Pre-Defensa.</span></h1>
        </header>

        <div class="cloud-card reveal" style="animation-delay: 0.1s;">
            <div class="row g-3 mb-4">
                <div class="col-md-7">
                    <div class="search-box-ae">
                        <i class="fas fa-search text-muted"></i>
                        <input type="text" id="busquedaGlobal" placeholder="Buscar por nombre de estudiante...">
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="search-box-ae">
                        <i class="fas fa-filter text-muted"></i>
                        <select id="filtroCarrera">
                            <option value="">Todas las Carreras</option>
                            <?php foreach($carreras as $c): ?> <option value="<?= $c ?>"><?= $c ?></option> <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <ul class="nav nav-pills nav-pills-ae mb-4 gap-2 justify-content-center" id="pills-tab" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#pen">
                        PENDIENTES <span class="badge rounded-pill bg-light text-dark ms-2"><?= $c_pen ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#apr">
                        APROBADOS <span class="badge rounded-pill bg-light text-dark ms-2"><?= $c_apr ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#rep">
                        REPROBADOS <span class="badge rounded-pill bg-light text-dark ms-2"><?= $c_rep ?></span>
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="pen">
                    <div class="table-ae">
                        <table class="table align-middle mb-0" id="tablaPendientes">
                            <thead>
                                <tr>
                                    <th>Estudiante / Carrera</th>
                                    <th>Programación</th>
                                    <th class="text-center">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($predefensas as $pd): if($pd['nota_pre_defensa'] === null): ?>
                                <tr class="tr-hover fila-dato" data-carrera="<?= $pd['nombre_carrera'] ?>">
                                    <td class="col-nombre">
                                        <div class="fw-bold"><?= htmlspecialchars($pd['estudiante']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($pd['nombre_carrera']) ?></div>
                                    </td>
                                    <td>
                                        <div class="small fw-600"><i class="far fa-calendar text-accent me-2"></i><?= $pd['fecha_pre'] ?></div>
                                        <div class="text-muted small"><i class="fas fa-door-open me-2"></i><?= $pd['nombre_aula'] ?? 'Sin Aula' ?></div>
                                    </td>
                                    <td class="text-center">
                                        <button onclick="calificar(<?= $pd['id_pre_defensa'] ?>)" class="btn-calificar">
                                            Calificar
                                        </button>
                                    </td>
                                </tr>
                                <?php endif; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="apr">
                    <div class="table-ae">
                        <table class="table align-middle mb-0" id="tablaAprobados">
                            <thead>
                                <tr>
                                    <th>Estudiante</th>
                                    <th>Nota</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($predefensas as $pd): if($pd['nota_pre_defensa'] >= 51): ?>
                                <tr class="tr-hover fila-dato" data-carrera="<?= $pd['nombre_carrera'] ?>">
                                    <td class="col-nombre">
                                        <div class="fw-bold"><?= htmlspecialchars($pd['estudiante']) ?></div>
                                        <div class="text-muted small"><?= $pd['nombre_carrera'] ?></div>
                                    </td>
                                    <td><span class="fw-800 text-success"><?= $pd['nota_pre_defensa'] ?></span></td>
                                    <td><span class="badge bg-success-subtle text-success rounded-pill px-3">Habilitado</span></td>
                                </tr>
                                <?php endif; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="rep">
                     <p class="text-center py-5 text-muted">No hay registros de reprobados actualmente.</p>
                </div>
            </div>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Lógica de Filtros Aesthetic
        $(document).ready(function(){
            $("#busquedaGlobal, #filtroCarrera").on("keyup change", function() {
                const nombre = $("#busquedaGlobal").val().toLowerCase();
                const carrera = $("#filtroCarrera").val();

                $(".fila-dato").each(function() {
                    const textoFila = $(this).find(".col-nombre").text().toLowerCase();
                    const carreraFila = $(this).data("carrera");
                    
                    const coincideNombre = textoFila.includes(nombre);
                    const coincideCarrera = carrera === "" || carreraFila === carrera;

                    $(this).toggle(coincideNombre && coincideCarrera);
                });
            });
        });

        function calificar(id) {
            Swal.fire({
                title: 'Ingresar Calificación',
                text: 'Evaluación de Pre-Defensa',
                input: 'number',
                inputAttributes: { min: 0, max: 100 },
                showCancelButton: true,
                confirmButtonText: 'Guardar Nota',
                confirmButtonColor: '#818cf8',
                cancelButtonText: 'Cancelar',
                customClass: { popup: 'rounded-4' }
            }).then((result) => {
                if (result.isConfirmed && result.value !== "") {
                    window.location.href = `guardar_nota.php?id=${id}&nota=${result.value}`;
                }
            });
        }
    </script>
</body>
</html>