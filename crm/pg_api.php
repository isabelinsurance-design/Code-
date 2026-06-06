<?php
/**
 * PG / Marketing API — versión SEGURA para el CRM (LUNA)
 * ─────────────────────────────────────────────────────────────
 * Diferencias con el pg_api.php original:
 *   • Sin credenciales en el código (usa config.php → db()).
 *   • Sin "Access-Control-Allow-Origin: *": solo mismo dominio.
 *   • Exige sesión iniciada del CRM (responde 401 si no hay login).
 * Guarda/lee campañas, contactos, planes y reportes en la MISMA
 * base de datos del CRM (tablas compartidas: usuarios, reporte_diario).
 */
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado — inicia sesión en el CRM']);
    exit;
}

$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'load';

// ── HELPER: nombre de agente → id en usuarios ─────────────────
function agentId(PDO $pdo, string $name): ?int {
    static $cache = [];
    $name = trim($name);
    if (!$name) return null;
    if (isset($cache[$name])) return $cache[$name];
    $st = $pdo->prepare("SELECT id FROM usuarios WHERE nombre LIKE ? LIMIT 1");
    $st->execute(["%$name%"]);
    $row = $st->fetch();
    $cache[$name] = $row ? (int)$row['id'] : null;
    return $cache[$name];
}

// ── HELPER: escapar pg_id para queries inline ─────────────────
function esc(PDO $pdo, string $val): string {
    return $pdo->quote($val);
}

// ══════════════════════════════════════════════════════════════
// GUARDAR — POST ?action=save
// ══════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'save') {

    $S = json_decode(file_get_contents('php://input'), true);
    if (!$S) {
        echo json_encode(['error' => 'JSON inválido']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // ── 1. LOGS → reporte_diario ──────────────────────────
        if (!empty($S['logs'])) {
            $st = $pdo->prepare("
                INSERT INTO reporte_diario
                  (agente_id, fecha, llamadas_prospectos, contestaron, buzon,
                   interesados, soas, citas_confirmadas, apps_enviadas, nota)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  llamadas_prospectos = VALUES(llamadas_prospectos),
                  contestaron         = VALUES(contestaron),
                  buzon               = VALUES(buzon),
                  interesados         = VALUES(interesados),
                  soas                = VALUES(soas),
                  citas_confirmadas   = VALUES(citas_confirmadas),
                  apps_enviadas       = VALUES(apps_enviadas),
                  nota                = VALUES(nota)
            ");
            foreach ($S['logs'] as $log) {
                $aid = agentId($pdo, $log['agent'] ?? '');
                if (!$aid) continue;
                $st->execute([
                    $aid,
                    $log['date'],
                    (int)($log['calls']      ?? 0),
                    (int)($log['answered']   ?? 0),
                    (int)($log['voicemail']  ?? 0),
                    (int)($log['interested'] ?? 0),
                    (int)($log['soas']       ?? 0),
                    (int)($log['appts']      ?? 0),
                    (int)($log['enrolled']   ?? 0),
                    $log['notes'] ?? ''
                ]);
            }
        }

        // ── 2. CAMPAÑAS ───────────────────────────────────────
        if (!empty($S['campaigns'])) {

            $stCamp = $pdo->prepare("
                INSERT INTO campanas
                  (pg_id, nombre, plantilla, agente_id, fecha_inicio, fecha_fin,
                   meta_inscritos, tamano_lista, notas, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  nombre         = VALUES(nombre),
                  estado         = VALUES(estado),
                  notas          = VALUES(notas),
                  meta_inscritos = VALUES(meta_inscritos),
                  tamano_lista   = VALUES(tamano_lista)
            ");

            $stCt = $pdo->prepare("
                INSERT INTO campana_contactos
                  (pg_id, campana_id, nombre, telefono, notas,
                   paso_actual, estado, fecha_agregado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  nombre      = VALUES(nombre),
                  telefono    = VALUES(telefono),
                  notas       = VALUES(notas),
                  paso_actual = VALUES(paso_actual),
                  estado      = VALUES(estado)
            ");

            $stH = $pdo->prepare("
                INSERT INTO campana_historial
                  (pg_id, contacto_id, campana_id, paso_numero, paso_label,
                   fecha, tipo_actividad, resultado, notas, avanzo_paso, agente_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  resultado = VALUES(resultado),
                  notas     = VALUES(notas)
            ");

            $statusMap = [
                'active'    => 'ACTIVO',
                'enrolled'  => 'INSCRITO',
                'dropped'   => 'DESCARTADO',
                'interested'=> 'INTERESADO',
                'appointed' => 'CON_CITA',
            ];

            foreach ($S['campaigns'] as $camp) {
                $aid    = agentId($pdo, $camp['agent'] ?? '');
                $estado = ($camp['status'] ?? '') === 'active' ? 'ACTIVA' : 'ARCHIVADA';
                $pgId   = $camp['id'];

                $stCamp->execute([
                    $pgId,
                    $camp['name'],
                    $camp['template']       ?? '',
                    $aid,
                    ($camp['startDate']     ?: null),
                    ($camp['endDate']       ?: null),
                    ($camp['enrollmentGoal']?: null),
                    ($camp['listSize']      ?: null),
                    $camp['notes']          ?? '',
                    $estado
                ]);

                // ID de la campaña en BD
                $campDbId = $pdo->query(
                    "SELECT id FROM campanas WHERE pg_id=" . esc($pdo, $pgId) . " LIMIT 1"
                )->fetchColumn();

                foreach ($camp['contacts'] ?? [] as $ct) {
                    $ctEstado = $statusMap[$ct['status'] ?? 'active'] ?? 'ACTIVO';
                    $ctPgId   = $ct['id'];

                    $stCt->execute([
                        $ctPgId,
                        $campDbId,
                        $ct['name'],
                        $ct['phone']       ?? '',
                        $ct['notes']       ?? '',
                        (int)($ct['currentStep'] ?? 0),
                        $ctEstado,
                        ($ct['addedDate']  ?: null)
                    ]);

                    $ctDbId = $pdo->query(
                        "SELECT id FROM campana_contactos WHERE pg_id=" . esc($pdo, $ctPgId) . " LIMIT 1"
                    )->fetchColumn();

                    foreach ($ct['history'] ?? [] as $h) {
                        $stH->execute([
                            $h['id']        ?? '',
                            $ctDbId,
                            $campDbId,
                            (int)($h['step'] ?? 0),
                            $h['stepLabel'] ?? '',
                            $h['date'],
                            $h['actType']   ?? 'call',
                            $h['outcome']   ?? 'no_answer',
                            $h['notes']     ?? '',
                            $h['advanced']  ? 1 : 0,
                            $aid
                        ]);
                    }
                }
            }
        }

        // ── 3. PLANES DEL DÍA ────────────────────────────────
        if (!empty($S['monthPlans'])) {
            $stPlan = $pdo->prepare("
                INSERT INTO plan_diario
                  (agente_id, fecha, campana_tipo, descripcion, meta_dia, notas)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  campana_tipo = VALUES(campana_tipo),
                  meta_dia     = VALUES(meta_dia),
                  notas        = VALUES(notas)
            ");
            $stChk = $pdo->prepare("
                INSERT INTO checklist_diario
                  (agente_id, fecha, item_key, item_texto, completado)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  item_texto = VALUES(item_texto),
                  completado = VALUES(completado)
            ");

            foreach ($S['monthPlans'] as $key => $plan) {
                // key = "2026-05-19_Isabel"
                $parts  = explode('_', $key, 2);
                $fecha  = $parts[0];
                $agente = $parts[1] ?? '';
                $aid    = agentId($pdo, $agente);
                if (!$aid) continue;

                $stPlan->execute([
                    $aid, $fecha,
                    $plan['campaign'] ?? '',
                    $plan['list']     ?? '',
                    $plan['goal']     ?? '',
                    $plan['notes']    ?? ''
                ]);

                foreach ($plan['tasks'] ?? [] as $i => $task) {
                    $stChk->execute([
                        $aid, $fecha,
                        "pg_{$key}_{$i}",
                        $task['text'] ?? '',
                        $task['done'] ? 1 : 0
                    ]);
                }
            }
        }

        // ── 4. ESTADO EXTRA (referrals, strategy, etc.) ───────
        $extra = $S;
        unset($extra['logs'], $extra['campaigns'], $extra['monthPlans']);
        $pdo->prepare("
            INSERT INTO pg_state_extra (clave, valor)
            VALUES ('main', ?)
            ON DUPLICATE KEY UPDATE valor=VALUES(valor)
        ")->execute([json_encode($extra, JSON_UNESCAPED_UNICODE)]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'ts' => date('c')]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
// CARGAR — GET ?action=load
// ══════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'load') {
    try {
        // Base: estado extra
        $row = $pdo->query("SELECT valor FROM pg_state_extra WHERE clave='main' LIMIT 1")->fetch();
        $S   = $row ? (json_decode($row['valor'], true) ?? []) : [];

        // ── Reconstruir logs ──────────────────────────────────
        $logs = $pdo->query("
            SELECT rd.*, u.nombre AS agent_name
            FROM reporte_diario rd
            JOIN usuarios u ON u.id = rd.agente_id
            ORDER BY rd.fecha DESC
            LIMIT 500
        ")->fetchAll();

        $S['logs'] = [];
        foreach ($logs as $l) {
            $key = $l['fecha'] . '_' . $l['agent_name'];
            $S['logs'][$key] = [
                'date'       => $l['fecha'],
                'agent'      => $l['agent_name'],
                'calls'      => (int)($l['llamadas_prospectos'] ?? 0),
                'answered'   => (int)($l['contestaron']         ?? 0),
                'voicemail'  => (int)($l['buzon']               ?? 0),
                'interested' => (int)($l['interesados']         ?? 0),
                'soas'       => (int)($l['soas']                ?? 0),
                'appts'      => (int)($l['citas_confirmadas']   ?? 0),
                'enrolled'   => (int)($l['apps_enviadas']       ?? 0),
                'notes'      => $l['nota'] ?? ''
            ];
        }

        // ── Reconstruir campañas ──────────────────────────────
        $camps = $pdo->query("
            SELECT c.*, u.nombre AS agent_name
            FROM campanas c
            LEFT JOIN usuarios u ON u.id = c.agente_id
            ORDER BY c.id
        ")->fetchAll();

        $S['campaigns'] = [];
        $statusRev = [
            'ACTIVO'     => 'active',
            'INSCRITO'   => 'enrolled',
            'DESCARTADO' => 'dropped',
            'INTERESADO' => 'interested',
            'CON_CITA'   => 'appointed',
        ];

        foreach ($camps as $camp) {
            // Contactos
            $stCt = $pdo->prepare("SELECT * FROM campana_contactos WHERE campana_id=? ORDER BY id");
            $stCt->execute([$camp['id']]);
            $ctRows = $stCt->fetchAll();

            $ctList = [];
            foreach ($ctRows as $ct) {
                // Historial del contacto
                $stH = $pdo->prepare("SELECT * FROM campana_historial WHERE contacto_id=? ORDER BY fecha, id");
                $stH->execute([$ct['id']]);
                $hList = [];
                foreach ($stH->fetchAll() as $h) {
                    $hList[] = [
                        'id'        => $h['pg_id'] ?: ('db_' . $h['id']),
                        'step'      => (int)$h['paso_numero'],
                        'stepLabel' => $h['paso_label'] ?? '',
                        'date'      => $h['fecha'],
                        'actType'   => $h['tipo_actividad'],
                        'outcome'   => $h['resultado'],
                        'notes'     => $h['notas'] ?? '',
                        'advanced'  => (bool)$h['avanzo_paso']
                    ];
                }

                $ctList[] = [
                    'id'          => $ct['pg_id'] ?: ('db_' . $ct['id']),
                    'name'        => $ct['nombre'],
                    'phone'       => $ct['telefono'] ?? '',
                    'notes'       => $ct['notas']    ?? '',
                    'currentStep' => (int)$ct['paso_actual'],
                    'status'      => $statusRev[$ct['estado']] ?? 'active',
                    'addedDate'   => $ct['fecha_agregado']     ?? '',
                    'history'     => $hList
                ];
            }

            $S['campaigns'][] = [
                'id'             => $camp['pg_id'] ?: ('db_' . $camp['id']),
                'name'           => $camp['nombre'],
                'template'       => $camp['plantilla']      ?? '',
                'agent'          => $camp['agent_name']     ?? '',
                'startDate'      => $camp['fecha_inicio']   ?? '',
                'endDate'        => $camp['fecha_fin']      ?? '',
                'enrollmentGoal' => $camp['meta_inscritos'] ?? '',
                'listSize'       => $camp['tamano_lista']   ?? '',
                'notes'          => $camp['notas']          ?? '',
                'status'         => $camp['estado'] === 'ACTIVA' ? 'active' : 'archived',
                'contacts'       => $ctList
            ];
        }

        // ── Reconstruir planes del día ────────────────────────
        $plans = $pdo->query("
            SELECT pd.*, u.nombre AS agent_name
            FROM plan_diario pd
            JOIN usuarios u ON u.id = pd.agente_id
            ORDER BY pd.fecha DESC
            LIMIT 200
        ")->fetchAll();

        $S['monthPlans'] = [];
        foreach ($plans as $plan) {
            $key = $plan['fecha'] . '_' . $plan['agent_name'];

            $stChk = $pdo->prepare("
                SELECT item_texto, completado
                FROM checklist_diario
                WHERE agente_id=? AND fecha=? AND item_key LIKE 'pg_%'
                ORDER BY id
            ");
            $stChk->execute([$plan['agente_id'], $plan['fecha']]);

            $tasks = [];
            foreach ($stChk->fetchAll() as $i => $t) {
                $tasks[] = [
                    'id'   => 't' . $i . '_db',
                    'text' => $t['item_texto'],
                    'done' => (bool)$t['completado']
                ];
            }

            $S['monthPlans'][$key] = [
                'campaign' => $plan['campana_tipo'] ?? '',
                'list'     => $plan['descripcion']  ?? '',
                'goal'     => $plan['meta_dia']     ?? '',
                'notes'    => $plan['notas']        ?? '',
                'tasks'    => $tasks
            ];
        }

        echo json_encode($S, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── PING — GET ?action=ping (para verificar que funciona) ──────
if ($action === 'ping') {
    echo json_encode(['ok' => true, 'db' => DB_NAME, 'ts' => date('c')]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción desconocida']);
