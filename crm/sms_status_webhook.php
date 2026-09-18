<?php
/**
 * ============================================================
 *  TWILIO — CONFIRMACIÓN DE ENTREGA DE SMS/MMS  |  Medicare with Isabel
 * ============================================================
 *  Coloca este archivo en la misma carpeta que index.php y config.php.
 *
 *  A diferencia de sms_webhook.php (mensajes que ENTRAN), este archivo no
 *  hay que configurarlo a mano en la consola de Twilio — se manda solo
 *  como parámetro "StatusCallback" en cada SMS/MMS que el CRM envía (ver
 *  twilio_enviar_sms() en lib_twilio.php).
 *
 *  Por qué importa: que Twilio ACEPTE un mensaje para enviarlo no es lo
 *  mismo que ENTREGARLO — y Twilio cobra por intentarlo aunque nunca
 *  llegue (número desconectado, bloqueado por el carrier, etc.). Sin
 *  esto, el CRM no tenía forma de enterarse de que un número está
 *  "muerto", y le seguía intentando mandar en cada campaña futura —
 *  dinero perdido en cada intento.
 *
 *  Con este archivo: cuando Twilio avisa que un mensaje falló o no se
 *  pudo entregar, el número queda marcado para no volver a intentarle
 *  (misma lista que usa el "STOP" — ver sms_opt_out en lib_twilio.php).
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib_telefono.php';
require_once __DIR__ . '/lib_twilio.php';

function _status_responder(int $code = 200) {
    http_response_code($code);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') _status_responder(405);
if (!twilio_firma_valida()) _status_responder(403);

$pdo = null;
try { $pdo = db(); } catch (Exception $e) {}
if (!$pdo) _status_responder(500);

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
} catch (Exception $e) {}

$sid      = trim($_POST['MessageSid'] ?? $_POST['SmsSid'] ?? '');
$status   = strtolower(trim($_POST['MessageStatus'] ?? $_POST['SmsStatus'] ?? ''));
$telefono = normalizar_tel($_POST['To'] ?? '');
$codigo   = trim($_POST['ErrorCode'] ?? '') ?: null;
$errorMsg = trim($_POST['ErrorMessage'] ?? '') ?: null;

if ($sid === '' || $status === '') _status_responder();

// Reflejar el estado final en el hilo de SMS de COMUNICACIÓN — esto solo
// necesita el SID, sin importar si "To" viene vacío por algo raro.
try {
    $pdo->prepare("UPDATE sms_mensajes SET estado = ? WHERE twilio_sid = ?")->execute([$status, $sid]);
} catch (Exception $e) {}

if ($telefono !== '') {
    if (in_array($status, ['failed', 'undelivered'], true)) {
        try { sms_registrar_fallo_envio($pdo, $telefono, $codigo, $errorMsg); } catch (Exception $e) {}
    } elseif ($status === 'delivered') {
        try { sms_limpiar_fallos_envio($pdo, $telefono); } catch (Exception $e) {}
    }
}

// Avisa a los navegadores conectados para que el hilo de SMS se refresque
// solo si algo cambió de verdad (falló/se entregó), no en cada paso
// intermedio (queued/sending/sent) para no generar ruido de refrescos.
if (in_array($status, ['delivered', 'failed', 'undelivered'], true) && function_exists('notify_relay')) {
    try { notify_relay('COMUNICACION'); } catch (Exception $e) {}
}

_status_responder();
