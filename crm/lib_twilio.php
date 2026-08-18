<?php
/* ═══════════════════════════════════════════════════════════════════
 *  LIB_TWILIO.PHP — enviar SMS por Twilio (llamada directa a su API,
 *  sin necesitar el SDK/Composer — solo cURL).
 *  ─────────────────────────────────────────────────────────────────
 *  Requiere que config.php defina estas 3 constantes:
 *    TWILIO_SID          → el "Account SID" de Twilio
 *    TWILIO_AUTH_TOKEN   → el "Auth Token" de Twilio
 *    TWILIO_FROM_NUMBER  → el número de Twilio desde el que se envía,
 *                           en formato +1XXXXXXXXXX
 *  Si no están definidas, twilio_enviar_sms() regresa ok=false con un
 *  mensaje claro en vez de tronar — así el resto del CRM sigue
 *  funcionando aunque Twilio todavía no esté configurado.
 * ═══════════════════════════════════════════════════════════════════ */
function twilio_configurado(): bool {
    return defined('TWILIO_SID') && TWILIO_SID
        && defined('TWILIO_AUTH_TOKEN') && TWILIO_AUTH_TOKEN
        && defined('TWILIO_FROM_NUMBER') && TWILIO_FROM_NUMBER;
}

function twilio_enviar_sms(string $to, string $body): array {
    if (!twilio_configurado()) {
        return ['ok' => false, 'error' => 'Twilio no está configurado (faltan TWILIO_SID/TWILIO_AUTH_TOKEN/TWILIO_FROM_NUMBER en config.php)'];
    }
    $to = normalizar_tel($to);
    if ($to === '') return ['ok' => false, 'error' => 'Número de destino inválido'];
    if (trim($body) === '') return ['ok' => false, 'error' => 'Mensaje vacío'];

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Messages.json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => TWILIO_SID . ':' . TWILIO_AUTH_TOKEN,
        CURLOPT_POSTFIELDS     => http_build_query([
            'From' => TWILIO_FROM_NUMBER,
            'To'   => $to,
            'Body' => $body,
        ]),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'Error de conexión con Twilio: ' . $err];
    }
    $data = json_decode($resp, true);
    if ($code >= 200 && $code < 300 && !empty($data['sid'])) {
        return ['ok' => true, 'sid' => $data['sid'], 'estado' => $data['status'] ?? 'queued'];
    }
    return ['ok' => false, 'error' => $data['message'] ?? ('Twilio respondió con error ' . $code)];
}

// Valida que un webhook (SMS o de voz/SIP) realmente venga de Twilio —
// mismo cálculo que ya se usa en sms_webhook.php (HMAC-SHA1 de la URL
// completa + los parámetros del POST, con el Auth Token como llave).
// Sin esto, cualquiera podría llamar a la URL del webhook e inventar
// llamadas/mensajes falsos (y, en el caso de voz, hacer que tu cuenta de
// Twilio marque números por su cuenta con tu tarjeta).
function twilio_firma_valida(): bool {
    if (!defined('TWILIO_AUTH_TOKEN') || !TWILIO_AUTH_TOKEN) return false;
    $firma_recibida = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
    if (!$firma_recibida) return false;
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host  = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '');
    $url   = $proto . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '');
    $datos = $url;
    $params = $_POST;
    ksort($params);
    foreach ($params as $k => $v) { $datos .= $k . $v; }
    $firma_esperada = base64_encode(hash_hmac('sha1', $datos, TWILIO_AUTH_TOKEN, true));
    return hash_equals($firma_esperada, $firma_recibida);
}
