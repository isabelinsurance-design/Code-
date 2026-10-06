<?php
/**
 * ============================================================
 *  HERRAMIENTA DE UNA SOLA VEZ — silenciar el atraso de
 *  recordatorios de citas futuras (NO de hoy)
 * ============================================================
 *  Motivo: el cron de recordatorios (cron_recordatorios_citas.php)
 *  nunca funcionó desde que se configuró (el wget fallaba en
 *  silencio), así que TODAS las citas pendientes con teléfono
 *  tienen recordatorio_48h_enviado_at = NULL — incluyendo las de
 *  mañana y pasado mañana, que ya están a menos de 48 horas.
 *
 *  Isabel pidió mandar HOY solo los recordatorios de las citas de
 *  HOY, no el "atraso" completo de 48h de los próximos días (eso
 *  mandaría de golpe ~20 SMS a la vez). Este script marca como "ya
 *  tratado" (sin mandar ningún SMS) el recordatorio de 48h de toda
 *  cita futura (fecha > hoy) que todavía no lo tenga — así, cuando
 *  se corra cron_recordatorios_citas.php de verdad, esas citas NO
 *  reciben el aviso de 48h atrasado, pero SÍ van a recibir su aviso
 *  normal de 2h antes cuando les toque el día de su cita (ese campo
 *  no se toca aquí).
 *
 *  Correr UNA sola vez, visitando esta URL con el mismo secreto que
 *  CRON_SECRET_RECORDATORIOS. Después de usarlo, se puede borrar
 *  este archivo del servidor (no hace falta dejarlo).
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib_telefono.php';
require_once __DIR__ . '/lib_twilio.php';
require_once __DIR__ . '/lib_recordatorios_citas.php';

header('Content-Type: application/json; charset=utf-8');

function _fix_responder(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$secretoEsperado = defined('CRON_SECRET_RECORDATORIOS') ? CRON_SECRET_RECORDATORIOS : '';
$secretoRecibido = $_GET['secret'] ?? (isset($argv[1]) ? $argv[1] : '');

if ($secretoEsperado === '' || $secretoEsperado === 'CAMBIA_ESTE_SECRETO') {
    _fix_responder(503, ['ok' => false, 'error' => 'Falta configurar CRON_SECRET_RECORDATORIOS en config.php']);
}
if (!is_string($secretoRecibido) || !hash_equals($secretoEsperado, $secretoRecibido)) {
    _fix_responder(401, ['ok' => false, 'error' => 'No autorizado']);
}

try {
    $pdo = db();
} catch (Exception $e) {
    _fix_responder(500, ['ok' => false, 'error' => 'No se pudo conectar a la base de datos']);
}

try {
    asegurarColumnasRecordatorioCitas($pdo);

    // Primero se lee cuáles son, para dejar constancia de qué se silenció.
    $sel = $pdo->prepare("SELECT c.id, c.fecha, c.hora, m.nombre, m.apellido
                           FROM citas c
                           INNER JOIN miembros m ON c.miembro_id = m.id
                           WHERE c.estado = 'PENDIENTE'
                             AND c.miembro_id IS NOT NULL
                             AND c.fecha > CURDATE()
                             AND c.recordatorio_48h_enviado_at IS NULL
                           ORDER BY c.fecha, c.hora");
    $sel->execute();
    $citas = $sel->fetchAll(PDO::FETCH_ASSOC);

    $upd = $pdo->prepare("UPDATE citas SET recordatorio_48h_enviado_at = NOW()
                           WHERE estado = 'PENDIENTE'
                             AND miembro_id IS NOT NULL
                             AND fecha > CURDATE()
                             AND recordatorio_48h_enviado_at IS NULL");
    $upd->execute();

    _fix_responder(200, [
        'ok' => true,
        'citas_silenciadas' => count($citas),
        'detalle' => $citas,
        'nota' => 'A estas citas NO se les mandó ningún SMS — solo se marcó su aviso de 48h como "ya tratado" para que el cron no las bombardee de atraso. Su aviso de 2 horas antes sigue funcionando normal cuando les toque.',
    ]);
} catch (Exception $e) {
    _fix_responder(500, ['ok' => false, 'error' => 'Error al silenciar el atraso: ' . $e->getMessage()]);
}
