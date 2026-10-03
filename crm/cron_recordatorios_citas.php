<?php
/**
 * ============================================================
 *  RECORDATORIOS DE CITAS POR SMS (Twilio)  |  Medicare with Isabel
 * ============================================================
 *  Coloca este archivo en la misma carpeta que index.php y config.php.
 *
 *  Este CRM corre en hosting compartido (cPanel) — no existe ningún
 *  mecanismo de "tarea programada" dentro del propio CRM, así que este
 *  archivo necesita que Isabel configure un Cron Job REAL en cPanel que
 *  lo llame cada 15-30 minutos. Dos formas de hacerlo (cualquiera sirve):
 *
 *    A) Comando de cPanel con wget (lo más fácil de copiar/pegar):
 *       wget -q -O /dev/null "https://TUDOMINIO.com/crm/cron_recordatorios_citas.php?secret=TU_SECRETO"
 *
 *    B) Comando de cPanel con curl:
 *       curl -s "https://TUDOMINIO.com/crm/cron_recordatorios_citas.php?secret=TU_SECRETO" > /dev/null
 *
 *  TU_SECRETO debe ser EXACTAMENTE el mismo valor que pongas en config.php
 *  en la constante CRON_SECRET_RECORDATORIOS (ver config.example.php) —
 *  sin este secreto, cualquiera que adivinara esta URL podría disparar
 *  envíos de SMS a nombre tuyo, por eso el archivo se niega a hacer nada
 *  si el secreto no coincide (o si se dejó el valor de ejemplo).
 *
 *  CONFIGURACIÓN:
 *    1. En config.php agrega (o cambia) la línea:
 *       define('CRON_SECRET_RECORDATORIOS', 'un-secreto-largo-y-dificil-de-adivinar');
 *    2. En cPanel → Cron Jobs, crea uno nuevo cada 15 o 30 minutos con el
 *       comando de arriba (opción A o B), reemplazando TUDOMINIO.com y
 *       TU_SECRETO por los tuyos reales.
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib_telefono.php';
require_once __DIR__ . '/lib_twilio.php';
require_once __DIR__ . '/lib_recordatorios_citas.php';

header('Content-Type: application/json; charset=utf-8');

function _cron_responder(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// El secreto puede venir por la URL (wget/curl, lo más fácil de configurar
// en cPanel) o como primer argumento si algún día se corre por línea de
// comandos (php cron_recordatorios_citas.php TU_SECRETO).
$secretoEsperado = defined('CRON_SECRET_RECORDATORIOS') ? CRON_SECRET_RECORDATORIOS : '';
$secretoRecibido = $_GET['secret'] ?? (isset($argv[1]) ? $argv[1] : '');

if ($secretoEsperado === '' || $secretoEsperado === 'CAMBIA_ESTE_SECRETO') {
    _cron_responder(503, ['ok' => false, 'error' => 'Falta configurar CRON_SECRET_RECORDATORIOS en config.php']);
}
if (!is_string($secretoRecibido) || !hash_equals($secretoEsperado, $secretoRecibido)) {
    _cron_responder(401, ['ok' => false, 'error' => 'No autorizado']);
}

try {
    $pdo = db();
} catch (Exception $e) {
    _cron_responder(500, ['ok' => false, 'error' => 'No se pudo conectar a la base de datos']);
}

// Modo diagnóstico temporal (?debug=1 además del secreto) — para revisar si
// la hora de MySQL coincide con la hora real, sin mandar ningún SMS. Quitar
// este bloque (y la función recordatorios_citas_diagnostico) una vez que se
// confirme que los recordatorios ya están llegando bien.
if (!empty($_GET['debug'])) {
    try {
        _cron_responder(200, ['ok' => true, 'diagnostico' => recordatorios_citas_diagnostico($pdo)]);
    } catch (Exception $e) {
        _cron_responder(500, ['ok' => false, 'error' => 'Error al diagnosticar: ' . $e->getMessage()]);
    }
}

try {
    $resumen = recordatorios_citas_procesar($pdo);
    _cron_responder(200, ['ok' => true, 'resumen' => $resumen]);
} catch (Exception $e) {
    _cron_responder(500, ['ok' => false, 'error' => 'Error al procesar recordatorios: ' . $e->getMessage()]);
}
