<?php
/**
 * ============================================================
 *  TWILIO SMS ENTRANTE → CRM  |  Medicare with Isabel
 * ============================================================
 *  Coloca este archivo en la misma carpeta que index.php y config.php.
 *
 *  CONFIGURACIÓN EN TWILIO:
 *  En la consola de Twilio → Phone Numbers → tu número → "Messaging" →
 *  "A message comes in" → pega la URL pública de este archivo, ej.:
 *    https://tudominio.com/crm/sms_webhook.php
 *  Método: HTTP POST.
 *
 *  Este archivo valida que la petición realmente venga de Twilio
 *  (usando la firma X-Twilio-Signature + tu Auth Token), para que
 *  nadie pueda inventar mensajes falsos llamando a esta URL.
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib_telefono.php';

header('Content-Type: text/xml; charset=utf-8');

function _twilio_responder_vacio(int $code = 200) {
    http_response_code($code);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { _twilio_responder_vacio(405); }
if (!defined('TWILIO_AUTH_TOKEN') || !TWILIO_AUTH_TOKEN) { _twilio_responder_vacio(503); }

// ─── Validar firma de Twilio ──────────────────────────────────────────────
// Twilio firma cada petición con HMAC-SHA1 (URL completa + parámetros del
// POST ordenados alfabéticamente y concatenados), usando el Auth Token como
// llave. Si no coincide, la petición no vino de Twilio de verdad.
$firma_recibida = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
$url_completa = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
    . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
$datos = $url_completa;
$params = $_POST;
ksort($params);
foreach ($params as $k => $v) { $datos .= $k . $v; }
$firma_esperada = base64_encode(hash_hmac('sha1', $datos, TWILIO_AUTH_TOKEN, true));
if (!$firma_recibida || !hash_equals($firma_esperada, $firma_recibida)) {
    _twilio_responder_vacio(403);
}

try { $pdo = db(); } catch (Exception $e) { _twilio_responder_vacio(500); }

// ─── Asegurar que la tabla exista ─────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_mensajes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telefono VARCHAR(20) NOT NULL,
        miembro_id INT NULL,
        direccion VARCHAR(10) NOT NULL,
        cuerpo TEXT,
        estado VARCHAR(30) DEFAULT NULL,
        twilio_sid VARCHAR(64) DEFAULT NULL,
        agente_id INT NULL,
        leido TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_telefono (telefono),
        INDEX idx_miembro (miembro_id)
    )");
} catch (Exception $e) { _twilio_responder_vacio(500); }

$telefono = normalizar_tel($_POST['From'] ?? '');
$cuerpo   = trim($_POST['Body'] ?? '');
$sid      = trim($_POST['MessageSid'] ?? '');
if ($telefono === '') { _twilio_responder_vacio(); }

// ─── Enlazar con un miembro existente si el teléfono coincide ────────────
$miembro_id = null;
try {
    $q = $pdo->prepare("SELECT id FROM miembros WHERE telefono = ? OR telefono2 = ? LIMIT 1");
    $q->execute([$telefono, $telefono]);
    $mrow = $q->fetch(PDO::FETCH_ASSOC);
    if ($mrow) $miembro_id = (int)$mrow['id'];
} catch (Exception $e) {}

try {
    $pdo->prepare("INSERT INTO sms_mensajes (telefono, miembro_id, direccion, cuerpo, estado, twilio_sid, leido)
                   VALUES (?, ?, 'ENTRANTE', ?, 'recibido', ?, 0)")
        ->execute([$telefono, $miembro_id, $cuerpo, $sid ?: null]);
    // Avisa a los navegadores conectados (si el relay de avisos en vivo está
    // configurado) para que la pestaña de SMS se refresque casi al instante,
    // en vez de esperar el refresco automático de hasta 8 segundos.
    if (function_exists('notify_relay')) notify_relay('COMUNICACION');
} catch (Exception $e) {}

_twilio_responder_vacio();
