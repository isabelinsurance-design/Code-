<?php
/**
 * ============================================================
 *  LLAMADAS SALIENTES DESDE EL TELÉFONO SIP (Grandstream)  |  Medicare with Isabel
 * ============================================================
 *  Coloca este archivo en la misma carpeta que index.php y config.php.
 *
 *  CONFIGURACIÓN EN TWILIO:
 *  Voice → SIP Domains → tu dominio → "Call Control Configuration" →
 *  "A call comes in" → Webhook → pega la URL pública de este archivo:
 *    https://tudominio.com/crm/sip_voice_webhook.php
 *  Método: HTTP POST (cámbialo del GET que trae por default).
 *
 *  Esto es para cuando el teléfono YA REGISTRADO (Grandstream) marca
 *  hacia afuera — Twilio le pregunta a este archivo qué hacer, y aquí
 *  se responde "marca ese número, usando nuestro número de Twilio como
 *  identificador de llamada".
 *
 *  Para que las llamadas ENTRANTES (alguien llama a tu número de
 *  Twilio) suenen en el teléfono, eso se configura por separado en el
 *  webhook de VOZ del número de teléfono (no aquí) con:
 *    <Response><Dial><Sip>sip:TU_USUARIO@TU_DOMINIO.sip.twilio.com</Sip></Dial></Response>
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib_twilio.php';
require_once __DIR__ . '/lib_telefono.php';

header('Content-Type: text/xml; charset=utf-8');

function _sip_responder(string $xml, int $code = 200) {
    http_response_code($code);
    echo '<?xml version="1.0" encoding="UTF-8"?>' . $xml;
    exit;
}

// DIAGNÓSTICO: cada llamada saliente queda registrada en la tabla
// sip_webhook_log — revísala con sip_voice_webhook_log.php si una llamada
// timbra y se cuelga, para ver EXACTAMENTE qué número mandó el teléfono.
$pdo = null;
try { $pdo = db(); } catch (Exception $e) {}
function _sip_log(?PDO $pdo, string $motivo, ?string $to_marcado = null) {
    if (!$pdo) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sip_webhook_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            motivo VARCHAR(60) NOT NULL,
            to_marcado VARCHAR(60) DEFAULT NULL,
            post_raw TEXT,
            ip VARCHAR(60) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->prepare("INSERT INTO sip_webhook_log (motivo, to_marcado, post_raw, ip) VALUES (?,?,?,?)")
            ->execute([$motivo, $to_marcado, json_encode($_POST), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Exception $e) {}
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { _sip_log($pdo, 'metodo_no_post'); _sip_responder('<Response></Response>', 405); }
if (!twilio_firma_valida()) { _sip_log($pdo, 'firma_invalida'); _sip_responder('<Response></Response>', 403); }

// "To" es el número que la persona marcó en el teléfono Grandstream.
// Se usa normalizar_tel() (la misma función que usa todo el CRM) para
// asegurar formato +1XXXXXXXXXX — sin esto, un número de 10 dígitos sin
// el "1" al inicio puede llegar mal formado a Twilio y la llamada falla.
$to_crudo = $_POST['To'] ?? '';

// Si el teléfono envió 11 dígitos y el '1' quedó al final (ej. 32340241451),
// movemos el '1' del final al principio.
$solo_numeros = preg_replace('/\D/', '', $to_crudo);
if (strlen($solo_numeros) === 11 && substr($solo_numeros, -1) === '1' && substr($solo_numeros, 0, 1) !== '1') {
    $solo_numeros = '1' . substr($solo_numeros, 0, 10);
    $to_crudo = '+' . $solo_numeros;
}

$to = normalizar_tel($to_crudo);

if ($to === '') {
    _sip_log($pdo, 'sin_numero', $to_crudo);
    _sip_responder('<Response><Say language="es-MX" voice="Polly.Mia">No se reconoció el número marcado.</Say></Response>');
}

// Se marca con nuestro número de Twilio como identificador — así a quien
// le contesten le va a aparecer el número de la oficina, no el del teléfono.
$callerId = defined('TWILIO_FROM_NUMBER') && TWILIO_FROM_NUMBER ? TWILIO_FROM_NUMBER : '';
$dialAttrs = $callerId !== '' ? ' callerId="' . htmlspecialchars($callerId, ENT_XML1) . '"' : '';

_sip_log($pdo, 'ok', $to_crudo . ' → ' . $to);
_sip_responder('<Response><Dial' . $dialAttrs . '>' . htmlspecialchars($to, ENT_XML1) . '</Dial></Response>');
