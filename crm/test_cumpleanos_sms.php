<?php
/**
 * ============================================================
 *  PRUEBA MANUAL — mandar el SMS/MMS de cumpleaños a un número
 *  específico, sin esperar a una fecha real ni tocar ningún miembro
 * ============================================================
 *  Visita con el mismo secreto de siempre:
 *    https://TUDOMINIO.com/crm/test_cumpleanos_sms.php?secret=TU_SECRETO&telefono=3234024145&nombre=Isabel
 *
 *  &nombre= es opcional (por default "Prueba"). &idioma=ENG manda la
 *  versión en inglés (por default manda en español).
 *
 *  Esto NO toca la base de datos — no cuenta como el envío real del año
 *  para nadie, se puede correr las veces que haga falta. Se puede borrar
 *  este archivo del servidor cuando ya no haga falta probar más.
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib_telefono.php';
require_once __DIR__ . '/lib_twilio.php';
require_once __DIR__ . '/lib_cumpleanos_sms.php';

header('Content-Type: application/json; charset=utf-8');

function _test_cumple_responder(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$secretoEsperado = defined('CRON_SECRET_RECORDATORIOS') ? CRON_SECRET_RECORDATORIOS : '';
$secretoRecibido = $_GET['secret'] ?? '';
if ($secretoEsperado === '' || $secretoEsperado === 'CAMBIA_ESTE_SECRETO') {
    _test_cumple_responder(503, ['ok' => false, 'error' => 'Falta configurar CRON_SECRET_RECORDATORIOS en config.php']);
}
if (!is_string($secretoRecibido) || !hash_equals($secretoEsperado, $secretoRecibido)) {
    _test_cumple_responder(401, ['ok' => false, 'error' => 'No autorizado']);
}

$telefono = normalizar_tel($_GET['telefono'] ?? '');
if ($telefono === '') {
    _test_cumple_responder(400, ['ok' => false, 'error' => 'Falta el parámetro telefono, ej. &telefono=3234024145']);
}
$nombre = trim($_GET['nombre'] ?? '') ?: 'Prueba';
$esIngles = strtoupper(trim($_GET['idioma'] ?? 'ESP')) === 'ENG';

$mensaje = cumpleanos_texto_mensaje($nombre, $esIngles);
$mediaUrl = twilio_url_publica('assets/cumpleanos.jpg');
$res = twilio_enviar_sms($telefono, $mensaje, $mediaUrl);

_test_cumple_responder($res['ok'] ? 200 : 500, [
    'ok' => $res['ok'],
    'telefono_normalizado' => $telefono,
    'mensaje_mandado' => $mensaje,
    'imagen_usada' => $mediaUrl,
    'respuesta_twilio' => $res,
]);
