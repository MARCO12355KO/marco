<?php
/**
 * Conexión a PostgreSQL - Render
 */

$host     = 'dpg-d654iunfte5s73d87d00-a.oregon-postgres.render.com';
$port     = '5432';
$dbname   = 'sistema_defensas_y43v';
$user     = 'sistema_defensas_y43v_user';
$password = '4Vz9E9is06QFcJPUGrndoEYSo4brziFZ';

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Opcional: mensaje de prueba
    // echo "✅ Conexión exitosa a PostgreSQL (Render)";

} catch (PDOException $e) {
    die('❌ Error de conexión a PostgreSQL: ' . $e->getMessage());
}