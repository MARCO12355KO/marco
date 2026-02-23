<?php
session_start();
include_once '../config/conexion.php'; 

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Captura de datos eliminando espacios accidentales
    $userInput = trim($_POST['usuario'] ?? '');
    $passInput = trim($_POST['contrasena'] ?? '');

    if ($userInput !== '' && $passInput !== '') {
        try {
            // 2. Consulta a la base de datos
            // Buscamos usuario y validamos que la persona esté 'activo'
            $sql = "SELECT u.id_persona, u.usuario, u.contrasena, u.rol, 
                           p.primer_nombre, p.primer_apellido 
                    FROM public.usuarios u
                    JOIN public.personas p ON u.id_persona = p.id_persona
                    WHERE u.usuario = :u AND p.estado = 'activo' 
                    LIMIT 1";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['u' => $userInput]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // 3. COMPARACIÓN DIRECTA (Sin Encriptación)
            // Se compara el texto ingresado contra el texto de la base de datos
            if ($row && $passInput === trim($row['contrasena'])) { 
                
                // 4. Configuración de Sesión
                $_SESSION['user_id'] = (int)$row['id_persona']; 
                $_SESSION['username'] = $row['usuario'];
                $_SESSION['nombre_completo'] = $row['primer_nombre'] . " " . $row['primer_apellido'];
                $_SESSION['role'] = trim($row['rol']);

                // Redirección al menú principal
                header("Location: ./menu.php");
                exit;
            } else {
                $error = "Usuario o contraseña incorrectos.";
            }
        } catch (PDOException $e) {
            $error = "Error de conexión: " . $e->getMessage();
        }
    } else {
        $error = "Por favor, complete todos los campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso | UNIOR</title>
    <link rel="icon" type="image/png" href="../assets/img/logo_unior1.pngrfv }}">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<div class="login-card">
    <div class="brand">
        <img src="https://cdn-icons-png.flaticon.com/512/2641/2641333.png" alt="Logo">
        <h1>Sistema de Titulación</h1>
    </div>

    <?php if ($error): ?>
        <div class="error-box" style="background: #fee2e2; color: #b91c1c; padding: 12px; border-radius: 6px; margin-bottom: 20px; text-align: center; border: 1px solid #f87171; font-size: 14px;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="field">
            <label for="usuario">Usuario</label>
            <input type="text" id="usuario" name="usuario" required autocomplete="off">
        </div>

        <div class="field">
            <label for="contrasena">Contraseña</label>
            <input type="password" id="contrasena" name="contrasena" required>
        </div>

        <button type="submit" class="btn-submit">Ingresar</button>
    </form>

    <div class="footer-note">
        &copy; 2026 Universidad Privada de Oruro <br>
        Todos los derechos reservados.
    </div>
</div>

</body>
</html>