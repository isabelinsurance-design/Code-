<?php
/* luna_diag.php — Diagnóstico de LUNA.
   🔒 DOS MODOS:
   • PÚBLICO (sin llave): solo sí/no — ¿hay config? ¿hay llaves? ¿conecta la BD?
     Sin longitudes, sin caracteres de las llaves, sin errores crudos, sin log.
   • COMPLETO (con la llave de servicio en el header X-LUNA-Key, o sesión de
     admin del CRM): el detalle de siempre — enmascarado, prueba real a
     Anthropic y último registro del chat. Es lo que usan Isabel y Athena
     para depurar el puente. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cfg = null;
foreach ([__DIR__.'/luna_config.php', __DIR__.'/../luna_config.php', __DIR__.'/../config.php'] as $c) {
    if (is_file($c)) { $cfg = $c; break; }
}
$out = ['config_cargado' => $cfg ? basename($cfg) : 'NINGUNO'];
if ($cfg) { require_once $cfg; }

// ── ¿Quién pregunta? ────────────────────────────────────────────
$svcEsperada = trim((string)(getenv('LUNA_SERVICE_KEY')
    ?: (defined('LUNA_SERVICE_KEY')  ? LUNA_SERVICE_KEY  : '')
    ?: getenv('LUNA_INTERNAL_KEY')
    ?: (defined('LUNA_INTERNAL_KEY') ? LUNA_INTERNAL_KEY : '')));
$svcRecibida = trim((string)($_SERVER['HTTP_X_LUNA_KEY'] ?? $_SERVER['HTTP_X_ATHENA_KEY'] ?? ''));
$esServicio  = ($svcEsperada !== '' && $svcRecibida !== '' && hash_equals($svcEsperada, $svcRecibida));

$esAdmin = false;
if (!$esServicio) {
    @session_start();
    $esAdmin = (($_SESSION['user']['rol'] ?? '') === 'admin');
}
$detalle = $esServicio || $esAdmin;
$out['modo'] = $detalle ? 'completo' : 'publico';

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
function _esPlaceholder($k) {
    return (stripos($k,'PON_AQUI') !== false || stripos($k,'CAMBIA') !== false || stripos($k,'TU_') !== false);
}

// Llave de servicio (puente con Athena)
$svcVal = defined('LUNA_SERVICE_KEY') ? LUNA_SERVICE_KEY
        : (defined('LUNA_INTERNAL_KEY') ? LUNA_INTERNAL_KEY : null);
if ($svcVal === null) {
    $out['llave_servicio'] = ['definida' => false];
} elseif ($detalle) {
    $out['llave_servicio'] = _mask($svcVal) + ['nombre' => defined('LUNA_SERVICE_KEY') ? 'LUNA_SERVICE_KEY' : 'LUNA_INTERNAL_KEY'];
} else {
    $out['llave_servicio'] = ['definida' => true, 'es_placeholder' => _esPlaceholder($svcVal)];
}

// API key de IA (chat)
if (!defined('ANTHROPIC_API_KEY')) {
    $out['anthropic_api_key'] = ['definida' => (bool)getenv('ANTHROPIC_API_KEY')];
} else {
    $out['anthropic_api_key'] = $detalle ? _mask(ANTHROPIC_API_KEY) : ['definida' => true];
}

// Base de datos
$db = ['conecta' => false];
try {
    if (function_exists('db')) { $pdo = db(); $pdo->query('SELECT 1'); $db['conecta'] = true; }
    elseif ($detalle) { $db['error'] = 'no existe la función db()'; }
} catch (\Throwable $e) {
    if ($detalle) $db['error'] = $e->getMessage();   // detalle solo autenticado
}
$out['base_datos'] = $db;

// ── Lo de abajo SOLO en modo completo ──────────────────────────
if ($detalle) {
    // Prueba REAL a Anthropic (mínima): confirma si la llave de IA y la red funcionan.
    $at = ['probado' => false];
    $ak = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : (getenv('ANTHROPIC_API_KEY') ?: '');
    if ($ak) {
        $payload = json_encode([
            'model'      => trim((string)(getenv('LUNA_AI_MODEL')
                ?: (defined('LUNA_AI_MODEL') ? LUNA_AI_MODEL : ''))) ?: 'claude-sonnet-4-6',
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

    // Último registro del chat (puede contener datos de miembros → solo autenticado).
    $log = __DIR__ . '/luna_chat_last.log';
    $out['ultimo_chat'] = is_file($log) ? trim(mb_substr((string) @file_get_contents($log), -900)) : 'sin registros del chat todavía';
} else {
    $out['nota'] = 'Detalle completo: manda la llave de servicio en el header X-LUNA-Key, o entra como admin del CRM.';
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
