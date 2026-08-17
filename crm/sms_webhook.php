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
 *
 *  DIAGNÓSTICO: cada intento (exitoso o no) queda registrado en la
 *  tabla sms_webhook_log — revísala con sms_webhook_log.php si un SMS
 *  no aparece en el CRM, para ver exactamente por qué se rechazó.
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

$pdo = null;
try { $pdo = db(); } catch (Exception $e) {}

function _sms_log(?PDO $pdo, string $motivo, ?string $telefono = null, ?bool $firmaValida = null) {
    if (!$pdo) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sms_webhook_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            motivo VARCHAR(60) NOT NULL,
            telefono VARCHAR(20) DEFAULT NULL,
            firma_valida TINYINT(1) DEFAULT NULL,
            post_raw TEXT,
            ip VARCHAR(60) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->prepare("INSERT INTO sms_webhook_log (motivo, telefono, firma_valida, post_raw, ip) VALUES (?,?,?,?,?)")
            ->execute([$motivo, $telefono, $firmaValida === null ? null : ($firmaValida ? 1 : 0), json_encode($_POST), $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Exception $e) {}
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { _sms_log($pdo, 'metodo_no_post'); _twilio_responder_vacio(405); }
if (!defined('TWILIO_AUTH_TOKEN') || !TWILIO_AUTH_TOKEN) { _sms_log($pdo, 'sin_auth_token_configurado'); _twilio_responder_vacio(503); }

// ─── Validar firma de Twilio ──────────────────────────────────────────────
// Twilio firma cada petición con HMAC-SHA1 (URL completa + parámetros del
// POST ordenados alfabéticamente y concatenados), usando el Auth Token como
// llave. Si no coincide, la petición no vino de Twilio de verdad.
//
// Se usan los headers X-Forwarded-Proto/Host cuando existen (Bluehost y la
// mayoría de hostings compartidos ponen el sitio detrás de un proxy/balanceador
// de SSL — sin esto, $_SERVER['HTTPS'] a veces no queda marcado aunque el
// visitante sí entró por https, y la URL reconstruida (http:// en vez de
// https://) nunca iba a coincidir con la firma que calculó Twilio).
$firma_recibida = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
$proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
$host  = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '');
$url_completa = $proto . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '');
$datos = $url_completa;
$params = $_POST;
ksort($params);
foreach ($params as $k => $v) { $datos .= $k . $v; }
$firma_esperada = base64_encode(hash_hmac('sha1', $datos, TWILIO_AUTH_TOKEN, true));
$firma_ok = $firma_recibida && hash_equals($firma_esperada, $firma_recibida);
if (!$firma_ok) { _sms_log($pdo, 'firma_invalida', null, false); _twilio_responder_vacio(403); }

if (!$pdo) { _sms_log($pdo, 'sin_conexion_bd', null, true); _twilio_responder_vacio(500); }

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
} catch (Exception $e) { _sms_log($pdo, 'error_creando_tabla', null, true); _twilio_responder_vacio(500); }

$telefono = normalizar_tel($_POST['From'] ?? '');
$cuerpo   = trim($_POST['Body'] ?? '');
$sid      = trim($_POST['MessageSid'] ?? '');
if ($telefono === '') { _sms_log($pdo, 'sin_telefono_from', null, true); _twilio_responder_vacio(); }

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
    _sms_log($pdo, 'ok', $telefono, true);
    // Avisa a los navegadores conectados (si el relay de avisos en vivo está
    // configurado) para que la pestaña de SMS se refresque casi al instante,
    // en vez de esperar el refresco automático de hasta 8 segundos.
    if (function_exists('notify_relay')) notify_relay('COMUNICACION');
} catch (Exception $e) { _sms_log($pdo, 'error_insertando: ' . $e->getMessage(), $telefono, true); }

_twilio_responder_vacio();
