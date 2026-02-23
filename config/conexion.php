<?php

$host     = 'localhost';
$port     = '5432'; 
$dbname   = 'sistema_titulacion'; 
$user     = 'postgres'; 
$password = 'marco'; 
try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("Error de conexión local: " . $e->getMessage());
}

?>