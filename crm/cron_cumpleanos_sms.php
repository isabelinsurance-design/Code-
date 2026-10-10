<?php
/**
 * ============================================================
 *  SMS AUTOMÁTICO DE CUMPLEAÑOS (con tarjeta MMS)  |  Medicare with Isabel
 * ============================================================
 *  Coloca este archivo en la misma carpeta que index.php y config.php.
 *
 *  Igual que cron_recordatorios_citas.php, necesita un Cron Job de cPanel
 *  — pero este solo hace falta correrlo UNA vez al día (los cumpleaños no
 *  tienen prisa de minutos). Usa el MISMO secreto que ya tienes
 *  configurado en CRON_SECRET_RECORDATORIOS.
 *
 *  Comando sugerido para el Cron Job de cPanel (ejecuta el PHP
 *  directamente, no por wget — ver por qué en cron_recordatorios_citas.php):
 *    /usr/bin/php /ruta/completa/a/crm/cron_cumpleanos_sms.php TU_SECRETO >> /ruta/a/cumpleanos_sms.log 2>&1
 *  Sugerido: una vez al día en la mañana, ej. a las 9:00 am.
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib_telefono.php';
require_once __DIR__ . '/lib_twilio.php';
require_once __DIR__ . '/lib_cumpleanos_sms.php';

header('Content-Type: application/json; charset=utf-8');

function _cron_cumple_responder(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$secretoEsperado = defined('CRON_SECRET_RECORDATORIOS') ? CRON_SECRET_RECORDATORIOS : '';
$secretoRecibido = $_GET['secret'] ?? (isset($argv[1]) ? $argv[1] : '');

if ($secretoEsperado === '' || $secretoEsperado === 'CAMBIA_ESTE_SECRETO') {
    _cron_cumple_responder(503, ['ok' => false, 'error' => 'Falta configurar CRON_SECRET_RECORDATORIOS en config.php']);
}
if (!is_string($secretoRecibido) || !hash_equals($secretoEsperado, $secretoRecibido)) {
    _cron_cumple_responder(401, ['ok' => false, 'error' => 'No autorizado']);
}

try {
    $pdo = db();
} catch (Exception $e) {
    _cron_cumple_responder(500, ['ok' => false, 'error' => 'No se pudo conectar a la base de datos']);
}

try {
    $resumen = cumpleanos_sms_procesar($pdo);
    cron_registrar_ejecucion($pdo, 'cumpleanos_sms', true, json_encode($resumen));
    _cron_cumple_responder(200, ['ok' => true, 'resumen' => $resumen]);
} catch (Exception $e) {
    cron_registrar_ejecucion($pdo, 'cumpleanos_sms', false, $e->getMessage());
    _cron_cumple_responder(500, ['ok' => false, 'error' => 'Error al procesar cumpleaños: ' . $e->getMessage()]);
}
