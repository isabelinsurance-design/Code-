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

header('Content-Type: text/xml; charset=utf-8');

function _sip_responder(string $xml, int $code = 200) {
    http_response_code($code);
    echo '<?xml version="1.0" encoding="UTF-8"?>' . $xml;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { _sip_responder('<Response></Response>', 405); }
if (!twilio_firma_valida()) { _sip_responder('<Response></Response>', 403); }

// "To" es el número que la persona marcó en el teléfono Grandstream.
$to = trim($_POST['To'] ?? '');
$to = preg_replace('/[^0-9+]/', '', $to);

if ($to === '') {
    _sip_responder('<Response><Say language="es-MX" voice="Polly.Mia">No se reconoció el número marcado.</Say></Response>');
}

// Se marca con nuestro número de Twilio como identificador — así a quien
// le contesten le va a aparecer el número de la oficina, no el del teléfono.
$callerId = defined('TWILIO_FROM_NUMBER') && TWILIO_FROM_NUMBER ? TWILIO_FROM_NUMBER : '';
$dialAttrs = $callerId !== '' ? ' callerId="' . htmlspecialchars($callerId, ENT_XML1) . '"' : '';

_sip_responder('<Response><Dial' . $dialAttrs . '>' . htmlspecialchars($to, ENT_XML1) . '</Dial></Response>');
