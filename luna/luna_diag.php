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

// Prueba REAL a Anthropic (mínima): confirma si la llave de IA y la red funcionan.
// Esto es lo que el chat usa para "pensar" — si falla aquí, el chat queda en blanco.
$at = ['probado' => false];
$ak = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : (getenv('ANTHROPIC_API_KEY') ?: '');
if ($ak) {
    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 16,
        'messages'   => [['role' => 'user', 'content' => 'hola']],
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $ak,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $r    = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    $at = ['probado' => true, 'http_code' => $code, 'curl_error' => ($cerr ?: null)];
    if ($r === false)        { $at['resultado'] = 'NO conecta a Anthropic (red bloqueada en el servidor)'; }
    elseif ($code === 200)   { $at['resultado'] = '✅ Anthropic responde OK'; }
    elseif ($code >= 400)    { $j = json_decode($r, true); $at['resultado'] = '❌ Error de Anthropic'; $at['mensaje'] = $j['error']['message'] ?? mb_substr((string)$r, 0, 220); }
    else                     { $at['resultado'] = 'Respuesta inesperada (' . $code . ')'; $at['cuerpo'] = mb_substr((string)$r, 0, 220); }
} else {
    $at['resultado'] = 'No hay ANTHROPIC_API_KEY definida';
}
$out['anthropic_test'] = $at;

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
