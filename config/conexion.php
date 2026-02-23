<?php
// Configuración para conectar a Render
$host     = 'dpg-d5g13m95pdvs73cc3u0g-a.oregon-postgres.render.com';
$port     = '5432'; 
$dbname   = 'sistema_titulacion'; 
$user     = 'marco_admin';
$password = 'M1uKfdB41kv3RGZUQcBTEhRRtVuHjAMu'; 

try {
    // Añadimos sslmode=require porque Render lo exige obligatoriamente
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>