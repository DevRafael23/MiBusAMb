<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Ruta relativa al nuevo directorio data/
$archivo = __DIR__ . '/../data/telemetria_data.json';

if (file_exists($archivo)) {
    echo file_get_contents($archivo);
} else {
    echo json_encode(["status" => "error", "message" => "Archivo no encontrado"]);
}
?>