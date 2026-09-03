<?php
/**
 * ============================================================
 *  LLAMADAS ENTRANTES — timbran en VARIOS teléfonos SIP a la vez
 *  (Medicare with Isabel)
 * ============================================================
 *  Coloca este archivo en la misma carpeta que index.php y config.php.
 *
 *  CONFIGURACIÓN EN TWILIO:
 *  Phone Numbers → tu número de Twilio → "Voice Configuration" →
 *  "A call comes in" → Webhook → pega la URL pública de este archivo:
 *    https://tudominio.com/crm/sip_voice_webhook_in.php
 *  Método: HTTP POST.
 *
 *  Antes esto se configuraba pegando el TwiML directo en Twilio (un
 *  <Dial><Sip>...</Sip></Dial> fijo, para UN solo teléfono). Este
 *  archivo hace lo mismo pero timbra en TODOS los usuarios SIP de la
 *  lista de abajo al mismo tiempo — el primero que conteste se queda
 *  con la llamada, los demás dejan de timbrar solos.
 *
 *  PARA AGREGAR UN TELÉFONO NUEVO: agrégalo a $SIP_USUARIOS más abajo
 *  (el mismo usuario SIP que le pusiste al registrar el teléfono en
 *  Twilio → Voice → SIP Domains → Credential Lists) y ya — no hace
 *  falta tocar nada más en Twilio.
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib_twilio.php';

header('Content-Type: text/xml; charset=utf-8');

// ── DOMINIO SIP — el mismo que ya usa el teléfono actual.
// Se ve en Twilio: Voice → SIP Domains → tu dominio (termina en .sip.twilio.com).
$SIP_DOMINIO = 'TU_DOMINIO.sip.twilio.com';

// ── USUARIOS SIP que deben timbrar cuando alguien llama al número
// principal — el usuario de CADA teléfono (el mismo que se configuró
// dentro del teléfono Grandstream al registrarlo). Agrega uno por línea.
$SIP_USUARIOS = [
    'TU_USUARIO',
];

function _sipin_responder(string $xml, int $code = 200) {
    http_response_code($code);
    echo '<?xml version="1.0" encoding="UTF-8"?>' . $xml;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { _sipin_responder('<Response></Response>', 405); }
if (!twilio_firma_valida()) { _sipin_responder('<Response></Response>', 403); }

$usuarios = array_values(array_filter(array_map('trim', $SIP_USUARIOS)));
if (!$usuarios || $SIP_DOMINIO === 'TU_DOMINIO.sip.twilio.com') {
    // Todavía no se configuró — evita que las llamadas entrantes se
    // caigan en silencio mientras alguien termina de llenar los datos.
    _sipin_responder('<Response><Say language="es-MX" voice="Polly.Mia">Los teléfonos todavía no están configurados. Por favor avise a Isabel.</Say></Response>');
}

$sips = '';
foreach ($usuarios as $u) {
    $sips .= '<Sip>sip:' . htmlspecialchars($u, ENT_XML1) . '@' . htmlspecialchars($SIP_DOMINIO, ENT_XML1) . '</Sip>';
}

_sipin_responder('<Response><Dial>' . $sips . '</Dial></Response>');
