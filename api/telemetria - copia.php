<?php
// C:\wamp64\www\tracker\transmecar-pasajero\api\telemetria.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

define('API_RECORRIDO', 'https://logirastreo.com/ws_app/serviciosTransMecar/recorrido.php');

$vehiculos = [
    "WHP081", "WHP086", "WHP089", "WHP083", "WHP088", "WHP082", "WHP079", "WHP080", "WHP078", "WHP084",
    "WHP073", "WHP087", "WHP085", "TDV478", "STN538", "UYR984", "UZD754", "UYS112", "UYS304", "UYQ838",
    "UYT130", "UYS526", "UYZ993", "UYX592", "UYO036", "UYS048", "UYX591", "UYO850", "STN250", "SXQ334",
    "SDU159", "SDU153", "SDU155", "TZK019", "TZK020", "TZK018", "TZK022", "TZK023", "TZK017", "UZD123",
    "UZD121", "UYZ618", "WGA723", "WGA219", "WGA223", "WGA217", "WGA224", "WGA218", "WGA220", "WGB990",
    "WGB989", "UYZ084", "STM075", "WPX307", "WGA158", "WPW497", "UYY776", "UYW197", "UYQ231", "UZD690",
    "UYS748", "UYV206", "UYV350", "UYT333", "UYR312", "LJO151", "TDW054", "TDW075", "STM178", "UYX753",
    "STM194", "STM199", "WFZ518", "WFZ519", "WFZ520", "UYU080", "UYP842", "UYQ150", "STM189", "STM191",
    "UYS037", "STM078", "STM079", "STM076", "STM077", "STM161", "STM162", "STM168", "STM167", "STM166",
    "UZC410", "UYQ394", "TDV496", "TDV475", "UZC985", "STM190", "STM188", "STM192", "STM187", "UZC540",
    "STM176", "UYV588", "TDU538", "TDU820", "TDV154", "UYR921", "TDV479", "TDV476", "TDV473", "TDV474",
    "TDW221", "TDW565", "TDW564", "TDW563", "TDV480", "WPV000", "WPV110", "WPV112", "SDV766", "SDV778",
    "SDV779", "WPW787", "UZC042", "WPX258", "WPX306", "WPW116", "UZD585", "STM092", "UYR841", "STN924",
    "UYO671", "STM144", "STM145", "UYS652"
];

function obtenerSentidoCardinal($angulo) {
    if ($angulo === null || $angulo === '') {
        return "DESC";
    }
    $ang = (float)$angulo;
    $direcciones = ["N", "NE", "E", "SE", "S", "SW", "W", "NW"];
    $idx = (int)round($ang / 45) % 8;
    return $direcciones[$idx] ?? "DESC";
}

$fechaInicio = date("Y-m-d 00:00:00");
$fechaFin = date("Y-m-d 23:59:00");
$timestamp = (int)(microtime(true) * 1000);

$multiCurl = curl_multi_init();
$channels = [];

foreach ($vehiculos as $placa) {
    $url = API_RECORRIDO . "?placa=" . urlencode($placa) . "&fecha_inicio=" . urlencode($fechaInicio) . "&fecha_fin=" . urlencode($fechaFin) . "&_t=" . $timestamp;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    curl_multi_add_handle($multiCurl, $ch);
    $channels[$placa] = $ch;
}

$active = null;
do {
    $mrc = curl_multi_exec($multiCurl, $active);
} while ($mrc == CURLM_CALL_MULTI_PERFORM);

while ($active && $mrc == CURLM_OK) {
    if (curl_multi_select($multiCurl) != -1) {
        do {
            $mrc = curl_multi_exec($multiCurl, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    }
}

$posicionesValidas = [];

foreach ($channels as $placa => $ch) {
    $response = curl_multi_getcontent($ch);
    if ($response) {
        $data = json_decode($response, true);
        if (isset($data['recorrido']) && is_array($data['recorrido']) && count($data['recorrido']) > 0) {
            $ultimoPunto = end($data['recorrido']);
            if (is_array($ultimoPunto) && isset($ultimoPunto[0])) {
                $punto = $ultimoPunto[0];
                
                $lat = isset($punto['latitud']) ? (float)$punto['latitud'] : 0;
                $lng = isset($punto['longitud']) ? (float)$punto['longitud'] : 0;
                
                if ($lat != 0 && $lng != 0) {
                    $ang = $punto['curso'] ?? $punto['rumbo'] ?? $punto['heading'] ?? null;
                    $nroMovil = !empty($punto['nroMovil']) && $punto['nroMovil'] !== "DESC" ? $punto['nroMovil'] : "000";
                    
                    $posicionesValidas[] = [
                        "id" => $placa,
                        "placa" => strtoupper($placa),
                        "nroMovil" => $nroMovil,
                        "lat" => $lat,
                        "lng" => $lng,
                        "speed" => isset($punto['velocidad']) ? (float)$punto['velocidad'] : 0,
                        "heading" => $ang,
                        "sentido" => obtenerSentidoCardinal($ang)
                    ];
                }
            }
        }
    }
    curl_multi_remove_handle($multiCurl, $ch);
    curl_close($ch);
}

curl_multi_close($multiCurl);

echo json_encode([
    "status" => "success",
    "timestamp" => time(),
    "total" => count($posicionesValidas),
    "data" => $posicionesValidas
]);