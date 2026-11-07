<?php
// C:\wamp64\www\tracker\transmecar-pasajero\cron_sync.php
set_time_limit(0);
ignore_user_abort(true);

define('API_RECORRIDO', 'https://logirastreo.com/ws_app/serviciosTransMecar/recorrido.php');
define('API_VIAJES',    'https://logirastreo.com/ws_app/serviciosqr/viajes.php');
define('ARCHIVO_CACHE', __DIR__ . '/data/telemetria_data.json');

// ---------------------------------------------------------------
// Caché de rutas en memoria del daemon (placa => ['ruta', 'ts'])
// ---------------------------------------------------------------
$GLOBALS['RUTA_CACHE']     = [];
$GLOBALS['RUTA_CACHE_TTL'] = 300; // 5 minutos

// ---------------------------------------------------------------
// Lista de vehículos
// ---------------------------------------------------------------
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

// ---------------------------------------------------------------
// Utilidades
// ---------------------------------------------------------------
function obtenerSentidoCardinal($angulo) {
    if ($angulo === null || $angulo === '' || (!is_numeric($angulo) && $angulo !== 0)) return "DESC";
    $ang = (float)$angulo;
    $direcciones = ["N", "NE", "E", "SE", "S", "SW", "W", "NW"];
    $idx = (int)round($ang / 45) % 8;
    if ($idx < 0) $idx += 8;
    return $direcciones[$idx] ?? "DESC";
}

/**
 * Normaliza el nombre de la ruta al estándar del sistema.
 * IMPORTANTE: los nombres específicos (GRANABASTOS, CARACOLI) se evalúan
 * ANTES que los numéricos, para evitar que un "30" perdido en el string
 * haga que todo caiga a CALLE_30.
 */
function normalizarNombreRuta($strRuta) {
    if (!$strRuta) return "DESCONOCIDA";
    $r = strtoupper(trim($strRuta));
    $rLimpia = str_replace(['_', '-', ' '], '', $r);

    // 1) Nombres únicos / específicos
    if (strpos($rLimpia, 'GRANA') !== false || strpos($rLimpia, 'ABASTO') !== false) return 'GRANABASTOS';
    if (strpos($rLimpia, 'CARACOL') !== false) return 'CARACOLI';

    // 2) Rutas numéricas (más genéricas, al final)
    if (strpos($rLimpia, '17') !== false) return 'CALLE_17';
    if (strpos($rLimpia, '30') !== false) return 'CALLE_30';

    return str_replace(' ', '_', $r);
}

/**
 * Throttle global: garantiza un mínimo de separación entre peticiones
 * consecutivas al servidor de Logirastreo para evitar rate-limiting.
 */
function esperarTurnoPeticion() {
    static $lastReq = 0.0;
    $minGap = 0.15; // 150 ms entre peticiones
    $ahora  = microtime(true);
    $delta  = $ahora - $lastReq;
    if ($delta < $minGap) {
        usleep((int)(($minGap - $delta) * 1_000_000));
    }
    $GLOBALS['__last_req'] = microtime(true);
}

/**
 * Obtiene la ruta real desde viajes.php, con caché de 5 minutos.
 * Extrae viajes[0].ruta (viaje activo) y, si no hay, el viaje MÁS
 * RECIENTE de viajesAnterior (array_reverse).
 */
function obtenerRutaPlaca($placa) {
    $cache = &$GLOBALS['RUTA_CACHE'];
    $ttl   =  $GLOBALS['RUTA_CACHE_TTL'];

    // Cache hit
    if (isset($cache[$placa]) && (time() - $cache[$placa]['ts']) < $ttl) {
        return $cache[$placa]['ruta'];
    }

    esperarTurnoPeticion();

    $url = API_VIAJES . '?placa=' . urlencode($placa);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json, text/plain, */*',
        'Referer: https://logirastreo.com/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);

    $res      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $rutaRaw = null;

    if ($res && $httpCode >= 200 && $httpCode < 300) {
        $json = json_decode($res, true);

        if (is_array($json)) {
            // 1) Viaje activo
            if (!empty($json['viajes'][0]['ruta'])) {
                $rutaRaw = $json['viajes'][0]['ruta'];
            }
            // 2) Último despacho del día (el MÁS RECIENTE)
            elseif (!empty($json['viajesAnterior']) && is_array($json['viajesAnterior'])) {
                foreach (array_reverse($json['viajesAnterior']) as $vAnt) {
                    $item = (is_array($vAnt) && isset($vAnt[0])) ? $vAnt[0] : $vAnt;
                    if (is_array($item) && !empty($item['ruta'])) {
                        $rutaRaw = $item['ruta'];
                        break;
                    }
                }
            }
        }
    }

    // Guardar en caché (incluso si fue null, para no martillar el server)
    $cache[$placa] = ['ruta' => $rutaRaw, 'ts' => time()];

    return $rutaRaw;
}

// ---------------------------------------------------------------
// Ciclo principal de sincronización
// ---------------------------------------------------------------
function ejecutarSincronizacion($vehiculos) {
    $fechaInicio = date("Y-m-d 00:00:00");
    $fechaFin    = date("Y-m-d 23:59:00");
    $timestamp   = (int)(microtime(true) * 1000);

    // ---------- FASE 1: GPS en paralelo (recorrido.php) ----------
    $multiCurl = curl_multi_init();
    $channels  = [];

    foreach ($vehiculos as $placa) {
        $url = API_RECORRIDO
             . "?placa="        . urlencode($placa)
             . "&fecha_inicio=" . urlencode($fechaInicio)
             . "&fecha_fin="    . urlencode($fechaFin)
             . "&_t="           . $timestamp;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_multi_add_handle($multiCurl, $ch);
        $channels[$placa] = $ch;
    }

    $active = null;
    do { $mrc = curl_multi_exec($multiCurl, $active); } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($multiCurl) != -1) {
            do { $mrc = curl_multi_exec($multiCurl, $active); } while ($mrc == CURLM_CALL_MULTI_PERFORM);
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
                    $lat   = isset($punto['latitud'])  ? (float)$punto['latitud']  : 0;
                    $lng   = isset($punto['longitud']) ? (float)$punto['longitud'] : 0;

                    if ($lat != 0 && $lng != 0) {
                        $ang = $punto['curso'] ?? $punto['rumbo'] ?? $punto['heading'] ?? null;
                        $nroMovil = (!empty($punto['nroMovil']) && $punto['nroMovil'] !== "DESC")
                                    ? $punto['nroMovil']
                                    : "000";

                        // Ruta real (con caché de 5 min para no saturar Logirastreo)
                        $rutaRaw   = obtenerRutaPlaca($placa);
                        $rutaFinal = normalizarNombreRuta($rutaRaw);

                        $posicionesValidas[$placa] = [
                            "id"       => $placa,
                            "placa"    => strtoupper($placa),
                            "nroMovil" => $nroMovil,
                            "lat"      => $lat,
                            "lng"      => $lng,
                            "speed"    => isset($punto['velocidad']) ? (float)$punto['velocidad'] : 0,
                            "heading"  => $ang,
                            "sentido"  => obtenerSentidoCardinal($ang),
                            "ruta"     => $rutaFinal
                        ];
                    }
                }
            }
        }

        curl_multi_remove_handle($multiCurl, $ch);
        curl_close($ch);
    }
    curl_multi_close($multiCurl);

    // ---------- FASE 2: Escritura ATÓMICA del JSON ----------
    $dirData = dirname(ARCHIVO_CACHE);
    if (!is_dir($dirData)) {
        @mkdir($dirData, 0777, true);
    }

    $payload = [
        "status"    => "success",
        "timestamp" => time(),
        "total"     => count($posicionesValidas),
        "data"      => array_values($posicionesValidas)
    ];

    $tmp = ARCHIVO_CACHE . '.tmp';
    if (file_put_contents($tmp, json_encode($payload), LOCK_EX) !== false) {
        rename($tmp, ARCHIVO_CACHE);
    }

    echo "[" . date("H:i:s") . "] Cache actualizado con " . count($posicionesValidas) . " buses.\n";
}

// ---------------------------------------------------------------
// Bucle continuo (daemon)
// ---------------------------------------------------------------
while (true) {
    ejecutarSincronizacion($vehiculos);
    sleep(4);
}