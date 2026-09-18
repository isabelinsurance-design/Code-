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
require_once __DIR__ . '/lib_twilio.php';

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
// Misma función que usa sms_status_webhook.php (twilio_firma_valida(), en
// lib_twilio.php) — antes cada webhook traía su propia copia de este
// cálculo; con dos copias, arreglar o mejorar la validación en una y
// olvidar la otra dejaría ese webhook vulnerable sin que se notara (y
// esto protege, entre otras cosas, contra que alguien invente entregas o
// fallos falsos que inflarían o esconderían gastos reales).
if (!twilio_firma_valida()) { _sms_log($pdo, 'firma_invalida', null, false); _twilio_responder_vacio(403); }

if (!$pdo) { _sms_log($pdo, 'sin_conexion_bd', null, true); _twilio_responder_vacio(500); }

// ─── Asegurar que la tabla exista ─────────────────────────────────────────
try {
    asegurarTablaSmsMensajes($pdo);
} catch (Exception $e) { _sms_log($pdo, 'error_creando_tabla', null, true); _twilio_responder_vacio(500); }

$telefono = normalizar_tel($_POST['From'] ?? '');
$cuerpo   = trim($_POST['Body'] ?? '');
$sid      = trim($_POST['MessageSid'] ?? '');
$esMms    = ((int)($_POST['NumMedia'] ?? 0)) > 0;
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
    // Twilio también cobra por RECIBIR, no solo por mandar — se guarda el
    // costo estimado de este mensaje entrante igual que se hace con los
    // salientes, para el reporte de GASTOS de Campañas.
    //
    // INSERT IGNORE (no "busca-si-existe-y-si-no-inserta"): Twilio a veces
    // REENVÍA el mismo webhook (timeout, red) casi al mismo tiempo que la
    // primera entrega todavía se está guardando — un simple SELECT antes
    // del INSERT deja una rendija de tiempo donde las dos peticiones
    // pueden no ver la fila de la otra todavía y duplicar el mensaje (y su
    // costo) en GASTOS. Con la llave única de twilio_sid + INSERT IGNORE,
    // MySQL rechaza sola la segunda inserción, sin ninguna rendija.
    $costo = sms_calcular_costo($cuerpo, $esMms, false);
    $ins = $pdo->prepare("INSERT IGNORE INTO sms_mensajes (telefono, miembro_id, direccion, cuerpo, estado, twilio_sid, leido, es_mms, costo_estimado)
                   VALUES (?, ?, 'ENTRANTE', ?, 'recibido', ?, 0, ?, ?)");
    $ins->execute([$telefono, $miembro_id, $cuerpo, $sid ?: null, $esMms ? 1 : 0, $costo]);
    if ($ins->rowCount() === 0 && $sid !== '') {
        // No se insertó nada — ese twilio_sid ya estaba guardado (reenvío).
        _sms_log($pdo, 'duplicado_ignorado', $telefono, true);
        _twilio_responder_vacio();
    }
    _sms_log($pdo, 'ok', $telefono, true);
    // Avisa a los navegadores conectados (si el relay de avisos en vivo está
    // configurado) para que la pestaña de SMS se refresque casi al instante,
    // en vez de esperar el refresco automático de hasta 8 segundos.
    if (function_exists('notify_relay')) notify_relay('COMUNICACION');
} catch (Exception $e) { _sms_log($pdo, 'error_insertando: ' . $e->getMessage(), $telefono, true); }

// ─── STOP / START — que quede marcado de una vez, sin que nadie tenga
// que revisar el hilo del SMS a mano para saber que ya no se le puede
// volver a escribir a este número (los envíos masivos de Campañas ya
// filtran contra esta tabla).
try {
    if (sms_es_palabra_stop($cuerpo)) {
        asegurarTablaSmsOptOut($pdo);
        $pdo->prepare("INSERT INTO sms_opt_out (telefono, motivo) VALUES (?, 'Respondió STOP')
                       ON DUPLICATE KEY UPDATE motivo = VALUES(motivo)")->execute([$telefono]);
        _sms_log($pdo, 'opt_out_registrado', $telefono, true);
    } elseif (sms_es_palabra_start($cuerpo)) {
        asegurarTablaSmsOptOut($pdo);
        $pdo->prepare("DELETE FROM sms_opt_out WHERE telefono = ?")->execute([$telefono]);
        _sms_log($pdo, 'opt_out_removido', $telefono, true);
    }
} catch (Exception $e) { _sms_log($pdo, 'error_optout: ' . $e->getMessage(), $telefono, true); }

_twilio_responder_vacio();
