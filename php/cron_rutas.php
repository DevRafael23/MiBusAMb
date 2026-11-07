<?php
// cron_rutas.php — Actualiza SOLO las rutas cada 15 min
set_time_limit(90);
ignore_user_abort(true);

define('API_VIAJES',   'https://logirastreo.com/ws_app/serviciosqr/viajes.php');
define('RUTAS_CACHE',  __DIR__ . '/../data/rutas_cache.json');
define('LOG_FILE',     __DIR__ . '/../data/rutas.log');

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

function normalizarNombreRuta($strRuta) {
    if (!$strRuta) return null;
    $r = strtoupper(trim($strRuta));
    $rLimpia = str_replace(['_','-',' '], '', $r);

    if (strpos($rLimpia, 'GRANA') !== false || strpos($rLimpia, 'ABASTO') !== false) return 'GRANABASTOS';
    if (strpos($rLimpia, 'CARACOL') !== false) return 'CARACOLI';
    if (strpos($rLimpia, '17') !== false) return 'CALLE_17';
    if (strpos($rLimpia, '30') !== false) return 'CALLE_30';
    return str_replace(' ', '_', $r);
}

function consultarRuta($placa) {
    $url = API_VIAJES . '?placa=' . urlencode($placa);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json, text/plain, */*',
        'Referer: https://logirastreo.com/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$res || $http < 200 || $http >= 300) return null;

    $json = json_decode($res, true);
    if (!is_array($json)) return null;

    if (!empty($json['viajes'][0]['ruta'])) {
        return $json['viajes'][0]['ruta'];
    }

    if (!empty($json['viajesAnterior']) && is_array($json['viajesAnterior'])) {
        foreach (array_reverse($json['viajesAnterior']) as $vAnt) {
            $item = (is_array($vAnt) && isset($vAnt[0])) ? $vAnt[0] : $vAnt;
            if (is_array($item) && !empty($item['ruta'])) {
                return $item['ruta'];
            }
        }
    }
    return null;
}

$inicio = time();
$rutas = [];
$errores = 0;
$sinRuta = 0;

foreach ($vehiculos as $placa) {
    $rutaRaw = consultarRuta($placa);
    if ($rutaRaw === null) {
        $errores++;
    } else {
        $rutaNorm = normalizarNombreRuta($rutaRaw);
        if ($rutaNorm === null) {
            $sinRuta++;
        } else {
            $rutas[$placa] = $rutaNorm;
        }
    }
    usleep(120000);
}

$payload = [
    'timestamp' => time(),
    'total'     => count($rutas),
    'rutas'     => $rutas
];

$tmp = RUTAS_CACHE . '.tmp.' . getmypid();
if (file_put_contents($tmp, json_encode($payload), LOCK_EX) !== false) {
    rename($tmp, RUTAS_CACHE);
}

$dur = time() - $inicio;
logMsg("Rutas actualizadas: " . count($rutas) . " con ruta, $sinRuta sin ruta, $errores errores. Duración: {$dur}s");