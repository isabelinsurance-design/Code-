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

// Convierte una ruta relativa dentro del CRM (ej. "uploads/flyers/x.jpg",
// tal como se guarda en la base de datos) en una URL pública completa —
// para un MMS, Twilio necesita poder DESCARGAR la imagen desde internet,
// no le sirve una ruta de archivo del servidor.
function twilio_url_publica(string $rutaRelativa): string {
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host  = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '');
    $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $proto . '://' . $host . $dir . '/' . ltrim($rutaRelativa, '/');
}

// $mediaUrl (opcional) manda un MMS con imagen (ej. un flyer) en vez de un
// SMS de solo texto — debe ser una URL pública (https) donde Twilio pueda
// descargar la imagen; $body puede ir vacío si solo se manda la imagen.
function twilio_enviar_sms(string $to, string $body, ?string $mediaUrl = null): array {
    if (!twilio_configurado()) {
        return ['ok' => false, 'error' => 'Twilio no está configurado (faltan TWILIO_SID/TWILIO_AUTH_TOKEN/TWILIO_FROM_NUMBER en config.php)'];
    }
    $to = normalizar_tel($to);
    if ($to === '') return ['ok' => false, 'error' => 'Número de destino inválido'];
    if (trim($body) === '' && !$mediaUrl) return ['ok' => false, 'error' => 'Mensaje vacío'];

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Messages.json';
    $campos = [
        'From' => TWILIO_FROM_NUMBER,
        'To'   => $to,
        'Body' => $body,
    ];
    if ($mediaUrl) $campos['MediaUrl'] = $mediaUrl;
    // Aceptar un mensaje para enviarlo no es lo mismo que ENTREGARLO — Twilio
    // cobra por intentarlo aunque nunca llegue. Sin este StatusCallback, el
    // CRM nunca se entera de que un número está muerto y le sigue intentando
    // mandar (dinero perdido) en cada envío masivo futuro.
    try { $campos['StatusCallback'] = twilio_url_publica('sms_status_webhook.php'); } catch (Exception $e) {}
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => TWILIO_SID . ':' . TWILIO_AUTH_TOKEN,
        CURLOPT_POSTFIELDS     => http_build_query($campos),
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
    return ['ok' => false, 'error' => $data['message'] ?? ('Twilio respondió con error ' . $code), 'codigo' => isset($data['code']) ? (string)$data['code'] : null];
}

// ═══════════════════════════════════════════════════════════════════
//  OPT-OUT (STOP) — cuando alguien responde STOP no se le debe volver a
//  escribir. Se guarda por TELÉFONO (no por miembro_id) porque muchos
//  contactos de campaña todavía no son miembros del CRM.
// ═══════════════════════════════════════════════════════════════════
function asegurarTablaSmsOptOut(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sms_opt_out (
            telefono   VARCHAR(20) NOT NULL PRIMARY KEY,
            motivo     VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Exception $e) {}
}

// Igual que hace Twilio: se compara el mensaje COMPLETO contra la palabra,
// no como substring — así "cancelar mi cita" no dispara un opt-out.
const SMS_PALABRAS_STOP  = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'ALTO', 'BAJA'];
const SMS_PALABRAS_START = ['START', 'UNSTOP', 'YES'];

function sms_es_palabra_stop(string $cuerpo): bool {
    return in_array(strtoupper(trim($cuerpo)), SMS_PALABRAS_STOP, true);
}
function sms_es_palabra_start(string $cuerpo): bool {
    return in_array(strtoupper(trim($cuerpo)), SMS_PALABRAS_START, true);
}

// ¿Este teléfono ya nos pidió que no le mandemos más mensajes?
function sms_esta_optout(PDO $pdo, string $telefono): bool {
    asegurarTablaSmsOptOut($pdo);
    $telefono = normalizar_tel($telefono);
    if ($telefono === '') return false;
    $q = $pdo->prepare("SELECT 1 FROM sms_opt_out WHERE telefono = ?");
    $q->execute([$telefono]);
    return (bool) $q->fetchColumn();
}

// ═══════════════════════════════════════════════════════════════════
//  NÚMEROS QUE NUNCA ENTREGAN (dinero perdido) — Twilio cobra por
//  INTENTAR mandar el SMS, no por que de verdad llegue. Un número
//  desconectado, mal escrito, o que bloqueó los mensajes de Twilio va a
//  fallar SIEMPRE — sin esto, cada envío masivo futuro le vuelve a
//  intentar (y a cobrar) sin que nadie se dé cuenta de por qué el número
//  de fallidos no baja.
// ═══════════════════════════════════════════════════════════════════
function asegurarTablaSmsFallos(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sms_fallos (
            telefono      VARCHAR(20) NOT NULL PRIMARY KEY,
            veces         INT NOT NULL DEFAULT 0,
            ultimo_codigo VARCHAR(20) DEFAULT NULL,
            ultimo_error  VARCHAR(255) DEFAULT NULL,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
    } catch (Exception $e) {}
}

// Códigos de error de Twilio que SIEMPRE significan que ese número nunca
// va a poder recibir un SMS — no hace falta esperar un segundo fallo para
// bloquearlo (número mal formado, no es celular/no soporta SMS, etc.).
const SMS_CODIGOS_PERMANENTES = ['21211', '21214', '21614', '30006'];
// A partir de cuántos fallos (con cualquier otro código, ej. "buzón no
// disponible" o "bloqueado por el carrier") se asume que ya no vale la
// pena seguir intentándole — puede ser temporal la primera vez, pero no
// dos veces seguidas.
const SMS_FALLOS_PARA_BLOQUEAR = 2;

// Se llama tanto si Twilio rechaza el envío al instante (número mal
// formado — se sabe en el momento) como cuando avisa DESPUÉS, por el
// status callback, que no se pudo entregar (número desconectado,
// bloqueado...) — un solo lugar para decidir cuándo ya es un número
// "perdido" y agregarlo a la misma lista que ya usa el STOP.
function sms_registrar_fallo_envio(PDO $pdo, string $telefono, ?string $codigo, ?string $error): void {
    $telefono = normalizar_tel($telefono);
    if ($telefono === '') return;
    asegurarTablaSmsFallos($pdo);
    try {
        $pdo->prepare("INSERT INTO sms_fallos (telefono, veces, ultimo_codigo, ultimo_error) VALUES (?, 1, ?, ?)
                       ON DUPLICATE KEY UPDATE veces = veces + 1, ultimo_codigo = VALUES(ultimo_codigo), ultimo_error = VALUES(ultimo_error)")
            ->execute([$telefono, $codigo, $error ? mb_substr($error, 0, 255) : null]);
        $q = $pdo->prepare("SELECT veces FROM sms_fallos WHERE telefono = ?");
        $q->execute([$telefono]);
        $veces = (int) $q->fetchColumn();
    } catch (Exception $e) { $veces = 1; }

    $esPermanente = $codigo && in_array((string) $codigo, SMS_CODIGOS_PERMANENTES, true);
    if ($esPermanente || $veces >= SMS_FALLOS_PARA_BLOQUEAR) {
        asegurarTablaSmsOptOut($pdo);
        $motivo = $esPermanente
            ? ('Número inválido (Twilio ' . $codigo . ')')
            : ('Falló ' . $veces . ' veces al enviar — no se le vuelve a intentar');
        try {
            $pdo->prepare("INSERT INTO sms_opt_out (telefono, motivo) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE motivo = VALUES(motivo)")->execute([$telefono, $motivo]);
        } catch (Exception $e) {}
    }
}

// Cuando SÍ se confirma la entrega, el historial de fallos viejo ya no
// sirve de nada (el número resultó estar bien después de todo).
function sms_limpiar_fallos_envio(PDO $pdo, string $telefono): void {
    $telefono = normalizar_tel($telefono);
    if ($telefono === '') return;
    asegurarTablaSmsFallos($pdo);
    try { $pdo->prepare("DELETE FROM sms_fallos WHERE telefono = ?")->execute([$telefono]); } catch (Exception $e) {}
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
