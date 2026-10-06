<?php
/**
 * ============================================================
 *  DIAGNÓSTICO — por qué no se mandó un SMS a un número exacto
 * ============================================================
 *  Visita esta URL con el mismo secreto de CRON_SECRET_RECORDATORIOS y el
 *  número de teléfono (con o sin +1, con o sin guiones):
 *    https://TUDOMINIO.com/crm/diagnostico_sms_numero.php?secret=TU_SECRETO&telefono=3234024145
 *
 *  Muestra si ese número está en la lista de "nunca volver a intentar"
 *  (opt-out), cuántas veces le ha fallado un envío, y los últimos mensajes
 *  de sms_mensajes relacionados a ese número — sin tener que entrar a
 *  phpMyAdmin. Se puede borrar este archivo cuando ya no haga falta.
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib_telefono.php';
require_once __DIR__ . '/lib_twilio.php';

header('Content-Type: application/json; charset=utf-8');

function _diag_responder(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$secretoEsperado = defined('CRON_SECRET_RECORDATORIOS') ? CRON_SECRET_RECORDATORIOS : '';
$secretoRecibido = $_GET['secret'] ?? '';
if ($secretoEsperado === '' || $secretoEsperado === 'CAMBIA_ESTE_SECRETO') {
    _diag_responder(503, ['ok' => false, 'error' => 'Falta configurar CRON_SECRET_RECORDATORIOS en config.php']);
}
if (!is_string($secretoRecibido) || !hash_equals($secretoEsperado, $secretoRecibido)) {
    _diag_responder(401, ['ok' => false, 'error' => 'No autorizado']);
}

$telefono = normalizar_tel($_GET['telefono'] ?? '');
if ($telefono === '') {
    _diag_responder(400, ['ok' => false, 'error' => 'Falta el parámetro telefono, ej. &telefono=3234024145']);
}

try {
    $pdo = db();
    asegurarTablaSmsOptOut($pdo);
    asegurarTablaSmsFallos($pdo);
    asegurarTablaSmsMensajes($pdo);

    $optOut = $pdo->prepare("SELECT * FROM sms_opt_out WHERE telefono = ?");
    $optOut->execute([$telefono]);
    $optOutRow = $optOut->fetch(PDO::FETCH_ASSOC);

    $fallos = $pdo->prepare("SELECT * FROM sms_fallos WHERE telefono = ?");
    $fallos->execute([$telefono]);
    $fallosRow = $fallos->fetch(PDO::FETCH_ASSOC);

    $mensajes = $pdo->prepare("SELECT id, cuerpo, estado, tipo, cita_id, created_at FROM sms_mensajes WHERE telefono = ? ORDER BY id DESC LIMIT 10");
    $mensajes->execute([$telefono]);
    $mensajesRows = $mensajes->fetchAll(PDO::FETCH_ASSOC);

    _diag_responder(200, [
        'ok' => true,
        'telefono_normalizado' => $telefono,
        'esta_en_opt_out' => $optOutRow !== false,
        'detalle_opt_out' => $optOutRow ?: null,
        'registro_de_fallos' => $fallosRow ?: null,
        'ultimos_10_mensajes_a_este_numero' => $mensajesRows,
        'twilio_configurado' => twilio_configurado(),
    ]);
} catch (Exception $e) {
    _diag_responder(500, ['ok' => false, 'error' => $e->getMessage()]);
}
