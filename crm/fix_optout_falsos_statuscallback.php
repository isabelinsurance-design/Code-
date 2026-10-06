<?php
/**
 * ============================================================
 *  HERRAMIENTA DE UNA SOLA VEZ — desbloquear números que se
 *  marcaron como "opt-out" por error (bug de StatusCallback)
 * ============================================================
 *  Motivo: antes de que config.php tuviera APP_BASE_URL, el cron (al
 *  correr por línea de comandos) armaba una URL de StatusCallback rota
 *  ("http:///sms_status_webhook.php") — Twilio rechazaba el SMS COMPLETO
 *  con el error 21609. Como el sistema bloquea un número después de 2
 *  fallos seguidos (para no gastar dinero en números muertos), varios
 *  números buenos quedaron bloqueados por error, no porque el teléfono
 *  esté mal.
 *
 *  Este script busca SOLO los números cuyo último fallo registrado es el
 *  código 21609 / menciona "StatusCallback" (nunca un código de número
 *  inválido de verdad), los quita de la lista de opt-out, y limpia su
 *  contador de fallos — para que vuelvan a recibir recordatorios normal.
 *
 *  OJO: corre esto DESPUÉS de haber agregado APP_BASE_URL a config.php —
 *  si no, el cron va a volver a fallar igual y los va a re-bloquear.
 *
 *  Visita una sola vez:
 *    https://TUDOMINIO.com/crm/fix_optout_falsos_statuscallback.php?secret=TU_SECRETO
 *  Se puede borrar este archivo del servidor después de usarlo.
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib_telefono.php';
require_once __DIR__ . '/lib_twilio.php';

header('Content-Type: application/json; charset=utf-8');

function _fixoo_responder(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$secretoEsperado = defined('CRON_SECRET_RECORDATORIOS') ? CRON_SECRET_RECORDATORIOS : '';
$secretoRecibido = $_GET['secret'] ?? '';
if ($secretoEsperado === '' || $secretoEsperado === 'CAMBIA_ESTE_SECRETO') {
    _fixoo_responder(503, ['ok' => false, 'error' => 'Falta configurar CRON_SECRET_RECORDATORIOS en config.php']);
}
if (!is_string($secretoRecibido) || !hash_equals($secretoEsperado, $secretoRecibido)) {
    _fixoo_responder(401, ['ok' => false, 'error' => 'No autorizado']);
}

try {
    $pdo = db();
    asegurarTablaSmsOptOut($pdo);
    asegurarTablaSmsFallos($pdo);

    $sel = $pdo->query("SELECT telefono, veces, ultimo_codigo, ultimo_error FROM sms_fallos
                         WHERE ultimo_codigo = '21609' OR ultimo_error LIKE '%StatusCallback%'");
    $afectados = $sel->fetchAll(PDO::FETCH_ASSOC);

    $desbloqueados = [];
    foreach ($afectados as $f) {
        $tel = $f['telefono'];
        // Solo se quita de opt-out si el motivo guardado es el genérico de
        // "falló X veces" (lo que pone este bug) — nunca toca un bloqueo
        // marcado como "Número inválido" (los códigos permanentes de
        // verdad, ej. 21211/21214/21614/30006, que no tienen nada que ver
        // con este bug).
        $chk = $pdo->prepare("SELECT motivo FROM sms_opt_out WHERE telefono = ?");
        $chk->execute([$tel]);
        $motivo = $chk->fetchColumn();
        if ($motivo === false) continue; // no estaba en opt-out, no hay nada que desbloquear
        if (stripos($motivo, 'Falló') === false) continue; // es un bloqueo de otro tipo, no se toca

        $pdo->prepare("DELETE FROM sms_opt_out WHERE telefono = ?")->execute([$tel]);
        sms_limpiar_fallos_envio($pdo, $tel);
        $desbloqueados[] = ['telefono' => $tel, 'veces_que_habia_fallado' => $f['veces'], 'motivo_anterior' => $motivo];
    }

    _fixoo_responder(200, [
        'ok' => true,
        'total_desbloqueados' => count($desbloqueados),
        'numeros_desbloqueados' => $desbloqueados,
        'nota' => 'Estos números ya pueden volver a recibir recordatorios y confirmaciones. Si todavía no agregaste APP_BASE_URL a config.php, hazlo ANTES de que vuelva a correr el cron, o se van a volver a bloquear por el mismo motivo.',
    ]);
} catch (Exception $e) {
    _fixoo_responder(500, ['ok' => false, 'error' => $e->getMessage()]);
}
