<?php
/* luna_diag.php — Diagnóstico rápido de LUNA (público, pero ENMASCARA secretos).
   Reporta: qué config carga, estado de la llave de servicio, ANTHROPIC_API_KEY,
   y si la base de datos conecta. Sirve para depurar el puente sin adivinar. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cfg = null;
foreach ([__DIR__.'/luna_config.php', __DIR__.'/../luna_config.php', __DIR__.'/../config.php'] as $c) {
    if (is_file($c)) { $cfg = $c; break; }
}
$out = ['config_cargado' => $cfg ? basename($cfg) : 'NINGUNO'];
if ($cfg) { require_once $cfg; }

function _mask($k) {
    $k = (string)$k; $len = strlen($k);
    return [
        'definida'       => true,
        'longitud'       => $len,
        'tiene_espacios' => ($k !== trim($k)),
        'enmascarada'    => $len > 8 ? substr($k,0,4).'…'.substr($k,-4) : '****',
        'es_placeholder' => (stripos($k,'PON_AQUI') !== false || stripos($k,'CAMBIA') !== false || stripos($k,'TU_') !== false),
    ];
}

// Llave de servicio (puente con Athena)
if (defined('LUNA_SERVICE_KEY'))      { $svc = _mask(LUNA_SERVICE_KEY);  $svc['nombre'] = 'LUNA_SERVICE_KEY'; }
elseif (defined('LUNA_INTERNAL_KEY')) { $svc = _mask(LUNA_INTERNAL_KEY); $svc['nombre'] = 'LUNA_INTERNAL_KEY'; }
else { $svc = ['definida' => false]; }
$out['llave_servicio'] = $svc;

// API key de IA (chat)
$out['anthropic_api_key'] = defined('ANTHROPIC_API_KEY') ? _mask(ANTHROPIC_API_KEY) : ['definida' => false];

// Base de datos
$db = ['conecta' => false];
try {
    if (function_exists('db')) { $pdo = db(); $pdo->query('SELECT 1'); $db['conecta'] = true; }
    else { $db['error'] = 'no existe la función db()'; }
} catch (\Throwable $e) { $db['error'] = $e->getMessage(); }
$out['base_datos'] = $db;

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
