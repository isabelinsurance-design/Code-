<?php
/**
 * ============================================================
 *  DIAGNÓSTICO — lista exacta de llamadas que un agente registró
 *  (botón "REGISTRAR LLAMADA" o actividad de campaña con canal LLAMADA)
 * ============================================================
 *  Visita con el mismo secreto de siempre:
 *    https://TUDOMINIO.com/crm/diagnostico_llamadas_agente.php?secret=TU_SECRETO&agente=SURI
 *
 *  &agente= es el nombre (o parte del nombre) del agente/empleado, ej.
 *  "SURI". &fecha= es opcional, formato YYYY-MM-DD (por default hoy).
 *
 *  Esto es de SOLO LECTURA — no cambia nada. Se puede borrar este
 *  archivo del servidor cuando ya no haga falta.
 * ============================================================
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function _diag_llam_responder(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$secretoEsperado = defined('CRON_SECRET_RECORDATORIOS') ? CRON_SECRET_RECORDATORIOS : '';
$secretoRecibido = $_GET['secret'] ?? '';
if ($secretoEsperado === '' || $secretoEsperado === 'CAMBIA_ESTE_SECRETO') {
    _diag_llam_responder(503, ['ok' => false, 'error' => 'Falta configurar CRON_SECRET_RECORDATORIOS en config.php']);
}
if (!is_string($secretoRecibido) || !hash_equals($secretoEsperado, $secretoRecibido)) {
    _diag_llam_responder(401, ['ok' => false, 'error' => 'No autorizado']);
}

$agenteNombre = trim($_GET['agente'] ?? '');
$fecha = trim($_GET['fecha'] ?? '') ?: date('Y-m-d');
if ($agenteNombre === '') {
    _diag_llam_responder(400, ['ok' => false, 'error' => 'Falta el parámetro agente, ej. &agente=SURI']);
}

try {
    $pdo = db();

    $ag = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE nombre LIKE ?");
    $ag->execute(['%' . $agenteNombre . '%']);
    $agentes = $ag->fetchAll(PDO::FETCH_ASSOC);
    if (!$agentes) {
        _diag_llam_responder(404, ['ok' => false, 'error' => 'No se encontró ningún usuario/agente cuyo nombre contenga "' . $agenteNombre . '"']);
    }
    $agenteIds = array_column($agentes, 'id');
    $placeholders = implode(',', array_fill(0, count($agenteIds), '?'));

    $sql = "SELECT lp.id, lp.agente_id, u.nombre AS agente_nombre, lp.miembro_id,
                   CONCAT(m.nombre, ' ', m.apellido) AS miembro_nombre,
                   lp.nombre_libre, lp.telefono, lp.contesto, lp.resultado, lp.notas, lp.created_at
            FROM llamadas_prospectos lp
            LEFT JOIN usuarios u ON u.id = lp.agente_id
            LEFT JOIN miembros m ON m.id = lp.miembro_id
            WHERE lp.agente_id IN ($placeholders)
              AND DATE(lp.created_at) = ?
            ORDER BY lp.created_at ASC";
    $params = array_merge($agenteIds, [$fecha]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $llamadas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    _diag_llam_responder(200, [
        'ok' => true,
        'agentes_encontrados' => $agentes,
        'fecha_consultada' => $fecha,
        'total_llamadas' => count($llamadas),
        'contestaron' => count(array_filter($llamadas, fn($l) => (int)$l['contesto'] === 1)),
        'no_contestaron' => count(array_filter($llamadas, fn($l) => (int)$l['contesto'] === 0)),
        'llamadas' => $llamadas,
    ]);
} catch (Exception $e) {
    _diag_llam_responder(500, ['ok' => false, 'error' => $e->getMessage()]);
}
