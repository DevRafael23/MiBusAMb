<?php
// cron_gps.php — Solo GPS, ultra-rápido, se ejecuta cada minuto
// MERGE: actualiza buses sin borrar los que no llegaron este ciclo

set_time_limit(120);
ignore_user_abort(true);

define('API_RECORRIDO', 'https://logirastreo.com/ws_app/serviciosTransMecar/recorrido.php');
define('ARCHIVO_CACHE', __DIR__ . '/../data/telemetria_data.json');
define('RUTAS_CACHE',   __DIR__ . '/../data/rutas_cache.json');
define('LOG_FILE',      __DIR__ . '/../data/sync.log');
define('MAX_EDAD_BUS',  180);  // segundos: si un bus no reporta en 3 min, se elimina

$vehiculos = [
    "WHP081","WHP086","WHP089","WHP083","WHP088","WHP082","WHP079","WHP080","WHP078","WHP084",
    "WHP073","WHP087","WHP085","TDV478","STN538","UYR984","UZD754","UYS112","UYS304","UYQ838",
    "UYT130","UYS526","UYZ993","UYX592","UYO036","UYS048","UYX591","UYO850","STN250","SXQ334",
    "SDU159","SDU153","SDU155","TZK019","TZK020","TZK018","TZK022","TZK023","TZK017","UZD123",
    "UZD121","UYZ618","WGA723","WGA219","WGA223","WGA217","WGA224","WGA218","WGA220","WGB990",
    "WGB989","UYZ084","STM075","WPX307","WGA158","WPW497","UYY776","UYW197","UYQ231","UZD690",
    "UYS748","UYV206","UYV350","UYT333","UYR312","LJO151","TDW054","TDW075","STM178","UYX753",
    "STM194","STM199","WFZ518","WFZ519","WFZ520","UYU080","UYP842","UYQ150","STM189","STM191",
    "UYS037","STM078","STM079","STM076","STM077","STM161","STM162","STM168","STM167","STM166",
    "UZC410","UYQ394","TDV496","TDV475","UZC985","STM190","STM188","STM192","STM187","UZC540",
    "STM176","UYV588","TDU538","TDU820","TDV154","UYR921","TDV479","TDV476","TDV473","TDV474",
    "TDW221","TDW565","TDW564","TDW563","TDV480","WPV000","WPV110","WPV112","SDV766","SDV778",
    "SDV779","WPW787","UZC042","WPX258","WPX306","WPW116","UZD585","STM092","UYR841","STN924",
    "UYO671","STM144","STM145","UYS652"
];

function logMsg($msg) {
    @file_put_contents(LOG_FILE, date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
}

function obtenerSentidoCardinal($angulo) {
    if ($angulo === null || $angulo === '' || (!is_numeric($angulo) && $angulo !== 0)) return "DESC";
    $ang = (float)$angulo;
    $dirs = ["N","NE","E","SE","S","SW","W","NW"];
    $idx = (int)round($ang / 45) % 8;
    if ($idx < 0) $idx += 8;
    return $dirs[$idx] ?? "DESC";
}

function cargarRutasCache() {
    if (!file_exists(RUTAS_CACHE)) return [];
    $d = json_decode(@file_get_contents(RUTAS_CACHE), true);
    return (is_array($d) && isset($d['rutas']) && is_array($d['rutas'])) ? $d['rutas'] : [];
}

function cargarJSONPrevio() {
    if (!file_exists(ARCHIVO_CACHE)) return [];
    $j = json_decode(@file_get_contents(ARCHIVO_CACHE), true);
    if (!is_array($j) || !isset($j['data']) || !is_array($j['data'])) return [];
    // Indexar por id
    $porId = [];
    foreach ($j['data'] as $bus) {
        if (isset($bus['id'])) $porId[$bus['id']] = $bus;
    }
    return $porId;
}

function ejecutarSincronizacion($vehiculos, $rutas) {
    date_default_timezone_set('America/Bogota');
    $fechaInicio = date("Y-m-d 00:00:00");
    $fechaFin    = date("Y-m-d 23:59:00");
    $ts          = (int)(microtime(true) * 1000);

    // ---------------- FASE 1: GPS en paralelo ----------------
    $mc = curl_multi_init();
    $channels = [];

    foreach ($vehiculos as $placa) {
        $url = API_RECORRIDO
             . "?placa="        . urlencode($placa)
             . "&fecha_inicio=" . urlencode($fechaInicio)
             . "&fecha_fin="    . urlencode($fechaFin)
             . "&_t="           . $ts;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_multi_add_handle($mc, $ch);
        $channels[$placa] = $ch;
    }

    $active = null;
    do { $mrc = curl_multi_exec($mc, $active); } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($mc, 1.0) != -1) {
            do { $mrc = curl_multi_exec($mc, $active); } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
    }

    // ---------------- FASE 2: parsear respuestas ----------------
    $nuevasPosiciones = [];   // solo los que llegaron en ESTE ciclo

    foreach ($channels as $placa => $ch) {
        $response = curl_multi_getcontent($ch);
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['recorrido']) && is_array($data['recorrido']) && count($data['recorrido']) > 0) {
                $ultimo = end($data['recorrido']);
                if (is_array($ultimo) && isset($ultimo[0])) {
                    $punto = $ultimo[0];
                    $lat = isset($punto['latitud'])  ? (float)$punto['latitud']  : 0;
                    $lng = isset($punto['longitud']) ? (float)$punto['longitud'] : 0;

                    if ($lat != 0 && $lng != 0) {
                        $ang = $punto['curso'] ?? $punto['rumbo'] ?? $punto['heading'] ?? null;
                        $nro = (!empty($punto['nroMovil']) && $punto['nroMovil'] !== "DESC")
                               ? $punto['nroMovil']
                               : "000";

                        $rutaFinal = isset($rutas[$placa]) ? $rutas[$placa] : null;

                        $nuevasPosiciones[$placa] = [
                            "id"       => $placa,
                            "placa"    => strtoupper($placa),
                            "nroMovil" => $nro,
                            "lat"      => $lat,
                            "lng"      => $lng,
                            "speed"    => isset($punto['velocidad']) ? (float)$punto['velocidad'] : 0,
                            "heading"  => $ang,
                            "sentido"  => obtenerSentidoCardinal($ang),
                            "ruta"     => $rutaFinal,
                            "_ts"      => time()   // timestamp interno para saber qué tan fresco es
                        ];
                    }
                }
            }
        }
        curl_multi_remove_handle($mc, $ch);
        curl_close($ch);
    }
    curl_multi_close($mc);

    // ---------------- FASE 3: MERGE con lo previo ----------------
    $previos = cargarJSONPrevio();
    $ahora = time();
    $resultado = [];

    // 3.1 — Meter todos los previos, excepto los muy viejos (> MAX_EDAD_BUS)
    foreach ($previos as $id => $bus) {
        $tsBus = isset($bus['_ts']) ? (int)$bus['_ts'] : 0;

        // Los buses sin _ts (de JSONs viejos) los damos por buenos por ahora
        if ($tsBus === 0) {
            $resultado[$id] = $bus;
            continue;
        }

        // Si el bus previo es más nuevo que MAX_EDAD_BUS, mantenerlo
        if (($ahora - $tsBus) < MAX_EDAD_BUS) {
            $resultado[$id] = $bus;
        }
        // Si no, se descarta (bus "fantasma")
    }

    // 3.2 — Sobrescribir/insertar los que vinieron en este ciclo
    $actualizados = 0;
    $nuevos = 0;
    foreach ($nuevasPosiciones as $id => $bus) {
        if (isset($resultado[$id])) {
            $actualizados++;
        } else {
            $nuevos++;
        }
        $resultado[$id] = $bus;
    }

    $totalFinal = count($resultado);

    // ---------------- FASE 4: escritura atómica ----------------
    $payload = [
        "status"    => "success",
        "timestamp" => time(),
        "total"     => $totalFinal,
        "data"      => array_values($resultado)
    ];

    $tmp = ARCHIVO_CACHE . '.tmp.' . getmypid();
    if (file_put_contents($tmp, json_encode($payload), LOCK_EX) !== false) {
        rename($tmp, ARCHIVO_CACHE);
    }

    logMsg("Ciclo OK: {$actualizados} actualizados, {$nuevos} nuevos, {$totalFinal} total en JSON.");
    return $totalFinal;
}

// ---------------- Bucle controlado por tiempo ----------------
$inicio = time();
$duracionMax = 50;
$rutas = cargarRutasCache();
$ciclos = 0;

while ((time() - $inicio) < $duracionMax) {
    ejecutarSincronizacion($vehiculos, $rutas);
    $ciclos++;

    if (((time() - $inicio) % 10) === 0) {
        $rutas = cargarRutasCache();
    }

    if ((time() - $inicio) < $duracionMax) {
        sleep(2);
    }
}

logMsg("Ejecución terminada: $ciclos ciclos en " . (time() - $inicio) . "s");