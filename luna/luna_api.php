<?php
/**
 * ═══════════════════════════════════════════════════════
 * LUNA API — Medicare with Isabel
 * ═══════════════════════════════════════════════════════
 * Endpoints específicos para LUNA (asistente IA del equipo).
 * Reusa config.php (auth, db, isAdmin) — NO toca api.php existente.
 *
 * Convención: todas las acciones empiezan con `luna_`
 * Auth: sesión PHP (igual que index.php y api.php)
 * Permisos: admin (Isabel) ve todo. Agents (Skarleth/Arlette/Samia)
 *   ven solo lo suyo o lo permitido explícitamente.
 *
 * Ubicación esperada: public_html/luna/luna_api.php
 * ═══════════════════════════════════════════════════════
 */
require_once '../config.php';   // ← LUNA vive en /luna/, config en raíz
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'No autorizado. Inicia sesión en el CRM primero.']);
    exit;
}

$user  = $_SESSION['user'];
$admin = ($user['rol'] ?? '') === 'admin';
$uid   = (int)$user['id'];
$pdo   = db();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ── Helpers ─────────────────────────────────────────────
function ok($data = []) { echo json_encode(['ok'=>true, 'data'=>$data]); exit; }
function err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok'=>false, 'error'=>$msg]);
    exit;
}
function requireAdmin() {
    global $admin;
    if (!$admin) err('Esta acción la ejecuta Isabel directamente.', 403);
}
function requirePost() {
    global $method;
    if ($method !== 'POST') err('Esta acción requiere POST.', 405);
}
function logActivity(PDO $pdo, int $agente_id, ?int $miembro_id, string $tipo, string $desc) {
    try {
        $pdo->prepare("INSERT INTO actividad (agente_id, miembro_id, tipo, descripcion) VALUES (?,?,?,?)")
            ->execute([$agente_id, $miembro_id, $tipo, $desc]);
    } catch(Exception $e) { /* silent */ }
}
function intOrNull($v) { return ($v === '' || $v === null) ? null : (int)$v; }
function strOrNull($v) { $v = trim((string)$v); return $v === '' ? null : $v; }

// ═══════════════════════════════════════════════════════
// ROUTING
// ═══════════════════════════════════════════════════════
try {
switch ($action) {

// ── WHOAMI — quién está logueado ─────────────────────────
case 'luna_whoami':
    ok([
        'id'        => $uid,
        'username'  => $user['username'],
        'nombre'    => $user['nombre'],
        'rol'       => $user['rol'],
        'iniciales' => $user['iniciales'] ?? '',
        'color'     => $user['color'] ?? '#1B4A6B',
        'is_admin'  => $admin,
    ]);
    break;

// ── PIPELINE SUMMARY ────────────────────────────────────
case 'luna_pipeline_summary':
    $rows = $pdo->query("
        SELECT estado, COUNT(*) AS total
        FROM miembros
        WHERE estado IS NOT NULL AND estado != ''
        GROUP BY estado
        ORDER BY total DESC
    ")->fetchAll();

    $totals = ['PROSPECTO'=>0,'T65'=>0,'HOT LEAD'=>0,'FOLLOW-UP'=>0,
               'PENDIENTE'=>0,'ACTIVO'=>0,'CANCELADO'=>0];
    foreach ($rows as $r) $totals[$r['estado']] = (int)$r['total'];

    $apps_proceso = (int)$pdo->query("
        SELECT COUNT(*) FROM miembros
        WHERE app_fecha IS NOT NULL
          AND app_estado_cms NOT IN ('RECIBIDO','APROBADO','CONFIRMADO')
    ")->fetchColumn();

    $efectivos_mes = (int)$pdo->query("
        SELECT COUNT(*) FROM miembros
        WHERE estado='ACTIVO'
          AND fecha_efectiva LIKE CONCAT(DATE_FORMAT(CURDATE(),'%Y-%m'),'%')
    ")->fetchColumn();

    ok([
        'estados'        => $totals,
        'apps_proceso'   => $apps_proceso,
        'efectivos_mes'  => $efectivos_mes,
        'total_miembros' => array_sum($totals),
    ]);
    break;

// ── T65 ALERTS — próximos 90 días ───────────────────────
case 'luna_t65_alerts':
    $days = max(7, min(180, (int)($_GET['days'] ?? 90)));
    $stmt = $pdo->prepare("
        SELECT id, nombre, apellido, dob, telefono, ciudad, agente_id,
               DATE_ADD(dob, INTERVAL 65 YEAR) AS fecha_65,
               DATEDIFF(DATE_ADD(dob, INTERVAL 65 YEAR), CURDATE()) AS dias_para_65
        FROM miembros
        WHERE estado != 'ACTIVO'
          AND DATE_ADD(dob, INTERVAL 65 YEAR) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ORDER BY fecha_65 ASC
        LIMIT 50
    ");
    $stmt->execute([$days]);
    ok(['days'=>$days, 'alerts'=>$stmt->fetchAll()]);
    break;

// ── RETENTION ALERTS — miembros que necesitan llamada hoy ─
case 'luna_retention_alerts':
    $sql = "
        SELECT m.id, m.nombre, m.apellido, m.telefono, m.carrier, m.fecha_efectiva,
               u.iniciales AS agente_ini, u.nombre AS agente_nombre,
               DATEDIFF(CURDATE(), m.fecha_efectiva) AS dias_activo,
               m.llam_bienvenida, m.llam_30, m.llam_60, m.llam_90
        FROM miembros m
        LEFT JOIN usuarios u ON m.agente_id = u.id
        WHERE m.estado = 'ACTIVO'
          AND (
            m.fecha_efectiva = DATE_SUB(CURDATE(), INTERVAL 7 DAY) OR
            m.fecha_efectiva = DATE_SUB(CURDATE(), INTERVAL 30 DAY) OR
            m.fecha_efectiva = DATE_SUB(CURDATE(), INTERVAL 60 DAY) OR
            m.fecha_efectiva = DATE_SUB(CURDATE(), INTERVAL 90 DAY)
          )
        ORDER BY m.fecha_efectiva DESC
    ";
    ok(['alerts'=>$pdo->query($sql)->fetchAll()]);
    break;

// ── HOT LEADS ───────────────────────────────────────────
case 'luna_hot_leads':
    $stmt = $pdo->query("
        SELECT m.id, m.nombre, m.apellido, m.telefono, m.dob, m.ciudad, m.fuente,
               u.iniciales AS agente_ini, u.nombre AS agente_nombre,
               m.created_at, m.updated_at
        FROM miembros m
        LEFT JOIN usuarios u ON m.agente_id = u.id
        WHERE m.estado = 'HOT LEAD'
        ORDER BY m.updated_at DESC
        LIMIT 50
    ");
    ok(['leads'=>$stmt->fetchAll()]);
    break;

// ── SEARCH MEMBER ───────────────────────────────────────
case 'luna_search_member':
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) err('Búsqueda muy corta (mínimo 2 caracteres).');

    $like = '%' . $q . '%';
    $stmt = $pdo->prepare("
        SELECT id, nombre, apellido, telefono, telefono2, mbi, ciudad, estado, carrier, plan,
               agente_id
        FROM miembros
        WHERE nombre LIKE ? OR apellido LIKE ? OR telefono LIKE ?
           OR telefono2 LIKE ? OR mbi LIKE ?
           OR CONCAT(nombre,' ',apellido) LIKE ?
        ORDER BY apellido, nombre
        LIMIT 25
    ");
    $stmt->execute([$like, $like, $like, $like, $like, $like]);
    ok(['query'=>$q, 'results'=>$stmt->fetchAll()]);
    break;

// ── MEMBER DETAIL ───────────────────────────────────────
case 'luna_member_detail':
    $id = intOrNull($_GET['id'] ?? null);
    if (!$id) err('Falta ID del miembro.');

    $m = $pdo->prepare("
        SELECT m.*, u.nombre AS agente_nombre, u.iniciales AS agente_ini, u.color AS agente_color
        FROM miembros m
        LEFT JOIN usuarios u ON m.agente_id = u.id
        WHERE m.id = ?
    ");
    $m->execute([$id]);
    $member = $m->fetch();
    if (!$member) err('Miembro no encontrado.', 404);

    // Notas
    $n = $pdo->prepare("
        SELECT n.nota, n.created_at, u.nombre AS agente_nombre
        FROM notas_miembro n
        LEFT JOIN usuarios u ON n.agente_id = u.id
        WHERE n.miembro_id = ?
        ORDER BY n.created_at DESC LIMIT 20
    ");
    $n->execute([$id]);
    $notas = $n->fetchAll();

    // Pólizas
    $p = $pdo->prepare("SELECT * FROM polizas WHERE miembro_id = ? ORDER BY fecha_efectiva DESC");
    $p->execute([$id]);
    $polizas = $p->fetchAll();

    // SOA
    $s = $pdo->prepare("SELECT * FROM soa WHERE miembro_id = ? ORDER BY fecha_firma DESC LIMIT 5");
    $s->execute([$id]);
    $soas = $s->fetchAll();

    // Actividad reciente
    $a = $pdo->prepare("
        SELECT a.tipo, a.descripcion, a.fecha_hora, u.nombre AS agente_nombre
        FROM actividad a LEFT JOIN usuarios u ON a.agente_id = u.id
        WHERE a.miembro_id = ?
        ORDER BY a.fecha_hora DESC LIMIT 15
    ");
    $a->execute([$id]);
    $actividad = $a->fetchAll();

    // Tickets
    $t = $pdo->prepare("
        SELECT id, tipo, prioridad, estado, descripcion, created_at
        FROM tickets WHERE miembro_id = ?
        ORDER BY created_at DESC LIMIT 10
    ");
    $t->execute([$id]);
    $tickets = $t->fetchAll();

    // Citas
    $c = $pdo->prepare("
        SELECT id, tipo, modalidad, fecha, hora, estado
        FROM citas WHERE miembro_id = ?
        ORDER BY fecha DESC, hora DESC LIMIT 10
    ");
    $c->execute([$id]);
    $citas = $c->fetchAll();

    ok([
        'member'    => $member,
        'notas'     => $notas,
        'polizas'   => $polizas,
        'soas'      => $soas,
        'actividad' => $actividad,
        'tickets'   => $tickets,
        'citas'     => $citas,
    ]);
    break;

// ── PENDING SOA — miembros activos sin SOA firmado ──────
case 'luna_pending_soa':
    $sql = "
        SELECT m.id, m.nombre, m.apellido, m.telefono, m.carrier, m.plan, m.fecha_efectiva,
               u.iniciales AS agente_ini,
               (SELECT COUNT(*) FROM soa s WHERE s.miembro_id = m.id AND s.estado='FIRMADO') AS soa_firmados,
               (SELECT MAX(fecha_firma) FROM soa s WHERE s.miembro_id = m.id) AS ultima_firma
        FROM miembros m
        LEFT JOIN usuarios u ON m.agente_id = u.id
        WHERE m.estado IN ('ACTIVO','PENDIENTE','HOT LEAD')
        HAVING soa_firmados = 0
        ORDER BY m.estado, m.apellido
        LIMIT 50
    ";
    ok(['pending'=>$pdo->query($sql)->fetchAll()]);
    break;

// ── OPEN TICKETS ────────────────────────────────────────
case 'luna_open_tickets':
    $priority = strtoupper(trim($_GET['priority'] ?? ''));
    $where = "t.estado != 'CERRADO'";
    $params = [];
    if (in_array($priority, ['ALTA','MEDIA','BAJA'])) {
        $where .= " AND t.prioridad = ?";
        $params[] = $priority;
    }
    // Agents only see their own tickets (assigned or created by them)
    if (!$admin) {
        $where .= " AND (t.agente_id = ? OR t.asignado_a = ?
                    OR t.id IN (SELECT ticket_id FROM ticket_responsables WHERE user_id = ?))";
        $params[] = $uid; $params[] = $uid; $params[] = $uid;
    }
    $sql = "
        SELECT t.id, t.tipo, t.prioridad, t.estado, t.descripcion,
               t.fecha_creacion, t.fecha_seguimiento,
               u.iniciales AS agente_ini, u.nombre AS agente_nombre,
               CONCAT(m.apellido,', ',m.nombre) AS miembro_nombre
        FROM tickets t
        LEFT JOIN usuarios u ON t.agente_id = u.id
        LEFT JOIN miembros m ON t.miembro_id = m.id
        WHERE $where
        ORDER BY FIELD(t.prioridad,'ALTA','MEDIA','BAJA'), t.created_at DESC
        LIMIT 50
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    ok(['tickets'=>$stmt->fetchAll()]);
    break;

// ── MY TICKETS — for the logged-in user ─────────────────
case 'luna_my_tickets':
    $stmt = $pdo->prepare("
        SELECT t.id, t.tipo, t.prioridad, t.estado, t.descripcion,
               t.fecha_creacion, t.fecha_seguimiento,
               CONCAT(m.apellido,', ',m.nombre) AS miembro_nombre
        FROM tickets t
        LEFT JOIN miembros m ON t.miembro_id = m.id
        WHERE t.estado != 'CERRADO'
          AND (t.agente_id = ? OR t.asignado_a = ?
               OR t.id IN (SELECT ticket_id FROM ticket_responsables WHERE user_id = ?))
        ORDER BY FIELD(t.prioridad,'ALTA','MEDIA','BAJA'), t.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$uid, $uid, $uid]);
    ok(['tickets'=>$stmt->fetchAll()]);
    break;

// ── TODAY APPOINTMENTS ──────────────────────────────────
case 'luna_today_appointments':
    $sql = "
        SELECT c.id, c.tipo, c.modalidad, c.fecha, c.hora, c.estado, c.notas,
               u.iniciales AS agente_ini, u.nombre AS agente_nombre,
               CONCAT(m.apellido,', ',m.nombre) AS miembro_nombre, m.telefono
        FROM citas c
        LEFT JOIN usuarios u ON c.agente_id = u.id
        LEFT JOIN miembros m ON c.miembro_id = m.id
        WHERE c.fecha = CURDATE()
        ORDER BY c.hora ASC
    ";
    ok(['date'=>date('Y-m-d'), 'appointments'=>$pdo->query($sql)->fetchAll()]);
    break;

// ── ATTENDANCE TODAY ────────────────────────────────────
case 'luna_attendance_today':
    requireAdmin();   // privacy: only Isabel sees the team's check-ins
    $sql = "
        SELECT a.agente_id, u.nombre, u.iniciales, u.color,
               a.check_in, a.lunch_out, a.lunch_in, a.break_out, a.break_in, a.check_out,
               a.polizas_escritas
        FROM asistencia a
        LEFT JOIN usuarios u ON a.agente_id = u.id
        WHERE a.fecha = CURDATE()
        ORDER BY a.check_in ASC
    ";
    ok(['date'=>date('Y-m-d'), 'attendance'=>$pdo->query($sql)->fetchAll()]);
    break;

// ── MY DAILY REPORT ─────────────────────────────────────
case 'luna_my_daily_report':
    $stmt = $pdo->prepare("
        SELECT * FROM reporte_diario WHERE agente_id=? AND fecha=CURDATE() LIMIT 1
    ");
    $stmt->execute([$uid]);
    $report = $stmt->fetch() ?: null;

    // Yesterday's report for comparison
    $y = $pdo->prepare("
        SELECT * FROM reporte_diario WHERE agente_id=? AND fecha=DATE_SUB(CURDATE(),INTERVAL 1 DAY) LIMIT 1
    ");
    $y->execute([$uid]);
    $yesterday = $y->fetch() ?: null;

    ok(['today'=>$report, 'yesterday'=>$yesterday]);
    break;

// ── MY GOALS ────────────────────────────────────────────
case 'luna_my_goals':
    $mes  = (int)date('n');
    $anio = (int)date('Y');
    $stmt = $pdo->prepare("SELECT * FROM metas WHERE agente_id=? AND mes=? AND anio=? LIMIT 1");
    $stmt->execute([$uid, $mes, $anio]);
    $goals = $stmt->fetch() ?: ['llamadas_dia'=>20,'citas_mes'=>8,'apps_mes'=>4,'polizas_mes'=>4];

    // Progress this month: count from reporte_diario
    $progress = $pdo->prepare("
        SELECT
          COALESCE(SUM(llamadas_prospectos + llamadas_servicio),0) AS llamadas,
          COALESCE(SUM(citas_confirmadas),0) AS citas,
          COALESCE(SUM(apps_enviadas),0) AS apps,
          COALESCE(SUM(polizas_escritas),0) AS polizas
        FROM reporte_diario
        WHERE agente_id=? AND MONTH(fecha)=? AND YEAR(fecha)=?
    ");
    $progress->execute([$uid, $mes, $anio]);
    ok(['goals'=>$goals, 'progress'=>$progress->fetch(), 'mes'=>$mes, 'anio'=>$anio]);
    break;

// ── PENDING CALLBACKS — llamadas perdidas ───────────────
case 'luna_pending_callbacks':
    $sql = "
        SELECT l.id, l.numero, l.nombre_posible, l.fecha, l.hora, l.origen, l.estado,
               u.iniciales AS agente_ini
        FROM llamadas_perdidas l
        LEFT JOIN usuarios u ON l.agente_id = u.id
        WHERE l.estado = 'PENDIENTE'
        ORDER BY l.fecha DESC, l.hora DESC
        LIMIT 30
    ";
    ok(['callbacks'=>$pdo->query($sql)->fetchAll()]);
    break;

// ── RECENT ACTIVITY ─────────────────────────────────────
case 'luna_recent_activity':
    $limit = max(5, min(50, (int)($_GET['limit'] ?? 20)));
    $sql = "
        SELECT a.tipo, a.descripcion, a.fecha_hora,
               u.nombre AS agente_nombre, u.iniciales AS agente_ini,
               CONCAT(m.apellido,', ',m.nombre) AS miembro_nombre
        FROM actividad a
        LEFT JOIN usuarios u ON a.agente_id = u.id
        LEFT JOIN miembros m ON a.miembro_id = m.id
        ORDER BY a.fecha_hora DESC
        LIMIT $limit
    ";
    ok(['activity'=>$pdo->query($sql)->fetchAll()]);
    break;

// ── COMMISSIONS SUMMARY ─────────────────────────────────
case 'luna_commissions_summary':
    requireAdmin();   // only Isabel sees commissions across the team
    $mes  = intOrNull($_GET['mes']  ?? null) ?: (int)date('n');
    $anio = intOrNull($_GET['anio'] ?? null) ?: (int)date('Y');

    $stmt = $pdo->prepare("
        SELECT
          c.agente_id, u.nombre AS agente_nombre, u.iniciales,
          COUNT(*) AS total_polizas,
          COALESCE(SUM(c.monto),0) AS total_monto,
          COUNT(CASE WHEN c.estado='PAGADO'    THEN 1 END) AS pagadas,
          COUNT(CASE WHEN c.estado='PENDIENTE' THEN 1 END) AS pendientes
        FROM comisiones c
        LEFT JOIN usuarios u ON c.agente_id = u.id
        WHERE c.anio = ? AND (c.mes = ? OR c.mes = DATE_FORMAT(STR_TO_DATE(?, '%c'), '%M'))
        GROUP BY c.agente_id, u.nombre, u.iniciales
        ORDER BY total_monto DESC
    ");
    $stmt->execute([$anio, $mes, $mes]);

    ok(['mes'=>$mes, 'anio'=>$anio, 'agents'=>$stmt->fetchAll()]);
    break;

// ── COMMISSIONS ADVANCED — Isabel solo ──────────────────
case 'luna_commissions_advanced':
    requireAdmin(); // 🔒 Solo Isabel

    $anio = intOrNull($_GET['anio'] ?? null) ?: (int)date('Y');
    $mes  = intOrNull($_GET['mes']  ?? null) ?: (int)date('n');

    // Por carrier este mes
    $byCarrier = $pdo->prepare("
        SELECT m.carrier,
               COUNT(*) AS total_polizas,
               COALESCE(SUM(c.monto),0) AS monto,
               COUNT(CASE WHEN c.estado='PAGADO' THEN 1 END) AS pagadas,
               COUNT(CASE WHEN c.estado='PENDIENTE' THEN 1 END) AS pendientes
        FROM comisiones c
        LEFT JOIN miembros m ON c.miembro_id=m.id
        WHERE c.anio=? AND c.mes=?
        GROUP BY m.carrier ORDER BY monto DESC
    ");
    $byCarrier->execute([$anio, $mes]);

    // Pendientes de pago con días
    $pending = $pdo->prepare("
        SELECT c.id, c.monto, c.carrier, c.tipo_plan,
               CONCAT(m.nombre,' ',m.apellido) AS miembro,
               DATE_FORMAT(c.fecha_emision,'%d %b %Y') AS fecha,
               DATEDIFF(CURDATE(), c.fecha_emision) AS dias_pendiente
        FROM comisiones c
        LEFT JOIN miembros m ON c.miembro_id=m.id
        WHERE c.estado='PENDIENTE'
        ORDER BY dias_pendiente DESC LIMIT 30
    ");
    $pending->execute();

    // YTD acumulado por mes
    $ytd = $pdo->prepare("
        SELECT c.mes, COALESCE(SUM(c.monto),0) AS monto,
               COUNT(*) AS total
        FROM comisiones c
        WHERE c.anio=?
        GROUP BY c.mes ORDER BY c.mes
    ");
    $ytd->execute([$anio]);

    // Proyección: promedio últimos 3 meses × meses restantes
    $avgStmt = $pdo->prepare("
        SELECT COALESCE(AVG(mensual),0) AS avg_3m FROM (
          SELECT SUM(monto) AS mensual FROM comisiones
          WHERE anio=? AND mes IN (?,?,?) GROUP BY mes
        ) t
    ");
    $m3 = $mes - 1; $m2 = $mes - 2; $m1 = $mes - 3;
    $avgStmt->execute([$anio, max(1,$m3), max(1,$m2), max(1,$m1)]);
    $avg3m = (float)$avgStmt->fetchColumn();
    $mesesRestantes = 12 - $mes;
    $proyeccion = round($avg3m * $mesesRestantes);

    ok([
        'mes'         => $mes,
        'anio'        => $anio,
        'by_carrier'  => $byCarrier->fetchAll(),
        'pending'     => $pending->fetchAll(),
        'ytd'         => $ytd->fetchAll(),
        'proyeccion'  => $proyeccion,
        'meses_rest'  => $mesesRestantes,
        'avg_3m'      => round($avg3m),
    ]);
    break;

// ── COMMISSIONS AUTO-CALC (when app approved) ───────────
case 'luna_commission_calc':
    requireAdmin(); // 🔒 Solo Isabel
    requirePost();

    $miembroId = intOrNull($_POST['miembro_id'] ?? null);
    $carrier   = strOrNull($_POST['carrier'] ?? '');
    $tipoPlan  = strOrNull($_POST['tipo_plan'] ?? 'MAPD');
    $esNuevo   = ($_POST['es_nuevo'] ?? '1') === '1';

    if (!$miembroId || !$carrier) err('Faltan miembro_id o carrier.');

    // Commission rates by plan type and year (approximate CMS FMV for LA County)
    $rates = [
        'MAPD'  => ['new' => 750, 'renewal' => 375],
        'PDP'   => ['new' => 150, 'renewal' =>  75],
        'SUPP'  => ['new' => 450, 'renewal' => 225],
        'DSNP'  => ['new' => 800, 'renewal' => 400],
        'default' => ['new' => 700, 'renewal' => 350],
    ];

    $rate = $rates[$tipoPlan] ?? $rates['default'];
    $monto = $esNuevo ? $rate['new'] : $rate['renewal'];
    $mes   = (int)date('n');
    $anio  = (int)date('Y');

    // Insert commission record
    $pdo->prepare("
        INSERT INTO comisiones (miembro_id, agente_id, carrier, tipo_plan, monto, estado, mes, anio, fecha_emision)
        VALUES (?, 6, ?, ?, ?, 'PENDIENTE', ?, ?, CURDATE())
        ON DUPLICATE KEY UPDATE monto=VALUES(monto), updated_at=NOW()
    ")->execute([$miembroId, $carrier, $tipoPlan, $monto, $mes, $anio]);

    ok(['calculated' => true, 'monto' => $monto, 'tipo' => $esNuevo ? 'nueva' : 'renovación', 'carrier' => $carrier]);
    break;

// ── CARRIERS BREAKDOWN ──────────────────────────────────
case 'luna_carriers_breakdown':
    $sql = "
        SELECT
          COALESCE(carrier,'(sin carrier)') AS carrier,
          COUNT(*) AS total,
          COUNT(CASE WHEN estado='ACTIVO'    THEN 1 END) AS activos,
          COUNT(CASE WHEN estado='PENDIENTE' THEN 1 END) AS pendientes,
          COUNT(CASE WHEN estado='CANCELADO' THEN 1 END) AS cancelados
        FROM miembros
        WHERE carrier IS NOT NULL AND carrier != ''
        GROUP BY carrier
        ORDER BY activos DESC, total DESC
    ";
    ok(['carriers'=>$pdo->query($sql)->fetchAll()]);
    break;

// ─────────────────────────────────────────────────────────
// ESCRITURA
// ─────────────────────────────────────────────────────────

// ── LOG ACTIVITY ────────────────────────────────────────
case 'luna_log_activity':
    requirePost();
    $tipo  = strOrNull($_POST['tipo'] ?? '');
    $desc  = strOrNull($_POST['descripcion'] ?? '');
    $mid   = intOrNull($_POST['miembro_id'] ?? null);
    if (!$tipo || !$desc) err('Faltan tipo o descripción.');
    if (mb_strlen($tipo) > 30)  err('Tipo demasiado largo (max 30).');
    if (mb_strlen($desc) > 1000) err('Descripción demasiado larga (max 1000).');

    logActivity($pdo, $uid, $mid, $tipo, $desc . ' [LUNA]');
    ok(['logged'=>true]);
    break;

// ── ADD MEMBER NOTE ─────────────────────────────────────
case 'luna_add_member_note':
    requirePost();
    $mid  = intOrNull($_POST['miembro_id'] ?? null);
    $nota = strOrNull($_POST['nota'] ?? '');
    if (!$mid || !$nota) err('Faltan miembro_id o nota.');
    if (mb_strlen($nota) > 2000) err('Nota demasiado larga (max 2000).');

    $exists = $pdo->prepare("SELECT id FROM miembros WHERE id=?");
    $exists->execute([$mid]);
    if (!$exists->fetchColumn()) err('Miembro no existe.', 404);

    $pdo->prepare("INSERT INTO notas_miembro (miembro_id, agente_id, nota) VALUES (?,?,?)")
        ->execute([$mid, $uid, $nota . ' [LUNA]']);
    logActivity($pdo, $uid, $mid, 'NOTA', 'Nota agregada vía LUNA');
    ok(['added'=>true]);
    break;

// ── CREATE MEMBER ───────────────────────────────────────
case 'luna_create_member':
    requirePost();
    $nombre   = strOrNull($_POST['nombre'] ?? '');
    $apellido = strOrNull($_POST['apellido'] ?? '');
    $telefono = strOrNull($_POST['telefono'] ?? '');
    $dob      = strOrNull($_POST['dob'] ?? '');
    $estado   = strtoupper(strOrNull($_POST['estado'] ?? 'PROSPECTO'));
    $fuente   = strOrNull($_POST['fuente'] ?? 'LUNA');
    $ciudad   = strOrNull($_POST['ciudad'] ?? '');
    $email    = strOrNull($_POST['email'] ?? '');
    $idioma   = strtoupper(strOrNull($_POST['idioma'] ?? 'ESP'));

    if (!$nombre || !$apellido) err('Nombre y apellido son requeridos.');
    if (!in_array($estado, ['PROSPECTO','T65','HOT LEAD','FOLLOW-UP','PENDIENTE','ACTIVO','CANCELADO'])) {
        err('Estado inválido.');
    }
    if ($dob && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) err('Formato de DOB inválido (YYYY-MM-DD).');

    // Duplicate check: same phone OR (nombre+apellido+dob)
    if ($telefono) {
        $dup = $pdo->prepare("SELECT id FROM miembros WHERE telefono=? OR telefono2=? LIMIT 1");
        $dup->execute([$telefono, $telefono]);
        if ($id = $dup->fetchColumn()) err("Ya existe un miembro con ese teléfono (id=$id).");
    }

    $stmt = $pdo->prepare("
        INSERT INTO miembros (nombre, apellido, telefono, dob, estado, fuente, ciudad, email, idioma,
                              agente_id, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([$nombre, $apellido, $telefono, $dob ?: null, $estado, $fuente, $ciudad, $email, $idioma, $uid, $uid]);
    $newId = (int)$pdo->lastInsertId();

    logActivity($pdo, $uid, $newId, 'NUEVO', "Miembro creado vía LUNA: $nombre $apellido (estado: $estado)");
    ok(['id'=>$newId, 'message'=>"Miembro $nombre $apellido creado (id=$newId)."]);
    break;

// ── UPDATE MEMBER STATUS ────────────────────────────────
case 'luna_update_member_status':
    requirePost();
    requireAdmin();   // ⚠ solo Isabel
    $mid    = intOrNull($_POST['miembro_id'] ?? null);
    $estado = strtoupper(strOrNull($_POST['estado'] ?? ''));
    if (!$mid || !$estado) err('Faltan miembro_id o estado.');
    if (!in_array($estado, ['PROSPECTO','T65','HOT LEAD','FOLLOW-UP','PENDIENTE','ACTIVO','CANCELADO'])) {
        err('Estado inválido.');
    }
    $cur = $pdo->prepare("SELECT estado, nombre, apellido FROM miembros WHERE id=?");
    $cur->execute([$mid]);
    $m = $cur->fetch();
    if (!$m) err('Miembro no existe.', 404);

    $pdo->prepare("UPDATE miembros SET estado=?, updated_at=NOW() WHERE id=?")
        ->execute([$estado, $mid]);
    logActivity($pdo, $uid, $mid, 'ESTADO',
        "Estado cambiado: {$m['estado']} → $estado [LUNA]");
    ok(['updated'=>true, 'from'=>$m['estado'], 'to'=>$estado]);
    break;

// ── CREATE TICKET ───────────────────────────────────────
case 'luna_create_ticket':
    requirePost();
    $tipo      = strtoupper(strOrNull($_POST['tipo'] ?? 'OTRO'));
    $prioridad = strtoupper(strOrNull($_POST['prioridad'] ?? 'MEDIA'));
    $desc      = strOrNull($_POST['descripcion'] ?? '');
    $mid       = intOrNull($_POST['miembro_id'] ?? null);
    $asig      = intOrNull($_POST['asignado_a'] ?? $uid);

    if (!$desc) err('Descripción requerida.');
    $tipos_validos = ['SERVICIO','LLAMADA','LLAMADA PERDIDA','APLICACION','CITA','SEGUIMIENTO',
                      'TAREA','PROSPECTO','QUEJA','INCENTIVO','SOPORTE','MARKETING','DENTAL','URGENTE','OTRO'];
    if (!in_array($tipo, $tipos_validos)) $tipo = 'OTRO';
    if (!in_array($prioridad, ['ALTA','MEDIA','BAJA'])) $prioridad = 'MEDIA';

    // Non-admin can only assign to themselves
    if (!$admin && $asig !== $uid) $asig = $uid;

    $stmt = $pdo->prepare("
        INSERT INTO tickets (miembro_id, agente_id, asignado_a, tipo, prioridad, estado,
                             descripcion, fuente, fecha_creacion)
        VALUES (?,?,?,?,?,'ABIERTO',?,'CRM',CURDATE())
    ");
    $stmt->execute([$mid, $uid, $asig, $tipo, $prioridad, $desc]);
    $tid = (int)$pdo->lastInsertId();
    logActivity($pdo, $uid, $mid, 'TICKET', "Ticket #$tid creado [$tipo/$prioridad] vía LUNA");
    ok(['id'=>$tid, 'message'=>"Ticket #$tid creado."]);
    break;

// ── CLOSE TICKET ────────────────────────────────────────
case 'luna_close_ticket':
    requirePost();
    $tid = intOrNull($_POST['ticket_id'] ?? null);
    $resultado = strOrNull($_POST['resultado'] ?? 'Cerrado vía LUNA');
    if (!$tid) err('Falta ticket_id.');

    $cur = $pdo->prepare("SELECT agente_id, asignado_a FROM tickets WHERE id=?");
    $cur->execute([$tid]);
    $t = $cur->fetch();
    if (!$t) err('Ticket no existe.', 404);
    if (!$admin && $t['agente_id'] != $uid && $t['asignado_a'] != $uid) {
        err('Esta acción la ejecuta Isabel o el agente asignado al ticket.', 403);
    }

    $pdo->prepare("UPDATE tickets SET estado='CERRADO', fecha_cierre=CURDATE(), resultado=? WHERE id=?")
        ->execute([$resultado, $tid]);
    logActivity($pdo, $uid, null, 'TICKET', "Ticket #$tid cerrado vía LUNA");
    ok(['closed'=>true]);
    break;

// ── CREATE APPOINTMENT ──────────────────────────────────
case 'luna_create_appointment':
    requirePost();
    $mid       = intOrNull($_POST['miembro_id'] ?? null);
    $agente    = intOrNull($_POST['agente_id'] ?? $uid);
    $tipo      = strOrNull($_POST['tipo'] ?? 'CONSULTA');
    $modalidad = strtoupper(strOrNull($_POST['modalidad'] ?? 'TELÉFONO'));
    $fecha     = strOrNull($_POST['fecha'] ?? '');
    $hora      = strOrNull($_POST['hora'] ?? '');
    $notas     = strOrNull($_POST['notas'] ?? '');

    if (!$mid)   err('miembro_id requerido.');
    if (!$fecha) err('fecha requerida.');
    if (!$hora)  err('hora requerida.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) err('Formato fecha inválido (YYYY-MM-DD).');
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $hora)) err('Formato hora inválido (HH:MM).');

    // Non-admin can only book themselves; admin (Isabel) can book any agent
    if (!$admin) $agente = $uid;

    $stmt = $pdo->prepare("
        INSERT INTO citas (miembro_id, agente_id, tipo, modalidad, fecha, hora, estado, notas)
        VALUES (?,?,?,?,?,?,'PENDIENTE',?)
    ");
    $stmt->execute([$mid, $agente, $tipo, $modalidad, $fecha, $hora, $notas]);
    $cid = (int)$pdo->lastInsertId();
    logActivity($pdo, $uid, $mid, 'CITA', "Cita agendada $fecha $hora vía LUNA");
    ok(['id'=>$cid, 'message'=>"Cita #$cid agendada para $fecha $hora."]);
    break;

// ── SEND INTERNAL NOTIFICATION ──────────────────────────
case 'luna_send_internal_notif':
    requirePost();
    requireAdmin();   // solo Isabel envía notificaciones internas vía LUNA
    $to_user = intOrNull($_POST['user_id'] ?? null);
    $tipo    = strtoupper(strOrNull($_POST['tipo'] ?? 'GENERAL'));
    $mensaje = strOrNull($_POST['mensaje'] ?? '');
    if (!$to_user || !$mensaje) err('Faltan user_id o mensaje.');
    if (!in_array($tipo, ['SISTEMA','GENERAL','OBSERVACION','CALIDAD','RETENCION'])) $tipo = 'GENERAL';

    $pdo->prepare("INSERT INTO notificaciones (user_id, remitente_id, tipo, mensaje) VALUES (?,?,?,?)")
        ->execute([$to_user, $uid, $tipo, $mensaje]);
    ok(['sent'=>true]);
    break;

// ── MARK CALLBACK DONE ──────────────────────────────────
case 'luna_mark_callback_done':
    requirePost();
    $cid = intOrNull($_POST['callback_id'] ?? null);
    $notas = strOrNull($_POST['notas'] ?? 'Devuelta vía LUNA');
    if (!$cid) err('Falta callback_id.');

    $pdo->prepare("UPDATE llamadas_perdidas SET estado='DEVUELTA', agente_id=?, notas=? WHERE id=?")
        ->execute([$uid, $notas, $cid]);
    logActivity($pdo, $uid, null, 'LLAMADA', "Llamada perdida #$cid devuelta [LUNA]");
    ok(['done'=>true]);
    break;

// ── BATCH RETENTION TICKETS — crea tickets para todas las alertas del día ──
case 'luna_batch_retention_tickets':
    requirePost();
    $assign_to = intOrNull($_POST['assign_to'] ?? null) ?: $uid;
    // Only admin or the assigned agent can do this
    if (!$admin && $assign_to !== $uid) $assign_to = $uid;

    $sql = "SELECT m.id, m.nombre, m.apellido, m.telefono, m.carrier,
                   m.fecha_efectiva,
                   DATEDIFF(CURDATE(), m.fecha_efectiva) AS dias_activo
            FROM miembros m
            WHERE m.estado = 'ACTIVO'
              AND (
                m.fecha_efectiva = DATE_SUB(CURDATE(), INTERVAL 7 DAY) OR
                m.fecha_efectiva = DATE_SUB(CURDATE(), INTERVAL 30 DAY) OR
                m.fecha_efectiva = DATE_SUB(CURDATE(), INTERVAL 60 DAY) OR
                m.fecha_efectiva = DATE_SUB(CURDATE(), INTERVAL 90 DAY)
              )";
    $members = $pdo->query($sql)->fetchAll();

    if (empty($members)) {
        ok(['created' => 0, 'tickets' => [], 'message' => 'No hay alertas de retención para hoy.']);
        break;
    }

    $created = [];
    foreach ($members as $m) {
        $dias = (int)$m['dias_activo'];
        $tipo_llamada = match(true) {
            $dias <= 8   => 'Day 7',
            $dias <= 31  => 'Day 30',
            $dias <= 61  => 'Day 60',
            default      => 'Day 90',
        };

        // Check if ticket already exists today for this member
        $dup = $pdo->prepare("
            SELECT id FROM tickets
            WHERE miembro_id=? AND asignado_a=?
              AND tipo='SEGUIMIENTO'
              AND DATE(created_at)=CURDATE()
              AND descripcion LIKE ?
        ");
        $dup->execute([$m['id'], $assign_to, "%$tipo_llamada%"]);
        if ($dup->fetchColumn()) continue; // skip duplicate

        $desc = "📞 Llamada de retención $tipo_llamada — {$m['nombre']} {$m['apellido']}"
              . ($m['carrier'] ? " ({$m['carrier']})" : '')
              . " | Tel: " . ($m['telefono'] ?: 'N/D');

        $pdo->prepare("
            INSERT INTO tickets (miembro_id, agente_id, asignado_a, tipo, prioridad, estado,
                                 descripcion, fuente, fecha_creacion, fecha_seguimiento)
            VALUES (?,?,?,'SEGUIMIENTO','MEDIA','ABIERTO',?,  'CRM', CURDATE(), CURDATE())
        ")->execute([$m['id'], $uid, $assign_to, $desc]);

        $tid = (int)$pdo->lastInsertId();
        logActivity($pdo, $uid, $m['id'], 'TICKET',
            "Ticket retención $tipo_llamada #$tid creado vía LUNA briefing");
        $created[] = ['ticket_id'=>$tid, 'miembro'=>"{$m['nombre']} {$m['apellido']}", 'tipo'=>$tipo_llamada];
    }

    ok([
        'created'  => count($created),
        'skipped'  => count($members) - count($created),
        'tickets'  => $created,
        'message'  => count($created) > 0
            ? count($created) . ' ticket(s) de retención creados para hoy.'
            : 'Todos los tickets de retención ya existían para hoy.',
    ]);
    break;

// ── FULL BRIEFING DATA — composite pull for daily briefing ──
case 'luna_full_briefing':
    $data = [];

    // Pipeline summary
    $data['pipeline'] = [];
    foreach($pdo->query("SELECT estado, COUNT(*) AS total FROM miembros WHERE estado IS NOT NULL GROUP BY estado")->fetchAll() as $r)
        $data['pipeline'][$r['estado']] = (int)$r['total'];

    // Today's appointments
    $data['citas_hoy'] = $pdo->query("
        SELECT c.tipo, c.hora, c.modalidad, CONCAT(m.apellido,', ',m.nombre) AS miembro, u.iniciales
        FROM citas c LEFT JOIN miembros m ON c.miembro_id=m.id LEFT JOIN usuarios u ON c.agente_id=u.id
        WHERE c.fecha=CURDATE() AND c.estado='PENDIENTE' ORDER BY c.hora
    ")->fetchAll();

    // Hot leads older than 48h without activity
    $data['hot_leads_frios'] = $pdo->query("
        SELECT m.id, m.nombre, m.apellido, m.telefono,
               DATEDIFF(CURDATE(), COALESCE(
                 (SELECT MAX(DATE(a.fecha_hora)) FROM actividad a WHERE a.miembro_id=m.id),
                 m.created_at
               )) AS dias_sin_contacto
        FROM miembros m WHERE m.estado='HOT LEAD'
        HAVING dias_sin_contacto >= 2 ORDER BY dias_sin_contacto DESC LIMIT 10
    ")->fetchAll();

    // T65 urgent (< 30 days)
    $data['t65_urgentes'] = $pdo->query("
        SELECT id, nombre, apellido, dob,
               DATEDIFF(DATE_ADD(dob, INTERVAL 65 YEAR), CURDATE()) AS dias_para_65
        FROM miembros WHERE estado != 'ACTIVO'
          AND DATE_ADD(dob, INTERVAL 65 YEAR) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY dias_para_65 ASC
    ")->fetchAll();

    // Retention alerts today
    $data['retencion_hoy'] = $pdo->query("
        SELECT m.id, m.nombre, m.apellido, m.telefono, m.carrier,
               DATEDIFF(CURDATE(), m.fecha_efectiva) AS dias_activo
        FROM miembros m WHERE m.estado='ACTIVO'
          AND (m.fecha_efectiva=DATE_SUB(CURDATE(),INTERVAL 7 DAY) OR
               m.fecha_efectiva=DATE_SUB(CURDATE(),INTERVAL 30 DAY) OR
               m.fecha_efectiva=DATE_SUB(CURDATE(),INTERVAL 60 DAY) OR
               m.fecha_efectiva=DATE_SUB(CURDATE(),INTERVAL 90 DAY))
    ")->fetchAll();

    // Open tickets ALTA
    $data['tickets_urgentes'] = $pdo->query("
        SELECT t.id, t.tipo, t.descripcion, u.iniciales,
               CONCAT(m.apellido,', ',m.nombre) AS miembro
        FROM tickets t LEFT JOIN miembros m ON t.miembro_id=m.id LEFT JOIN usuarios u ON t.asignado_a=u.id
        WHERE t.estado!='CERRADO' AND t.prioridad='ALTA' ORDER BY t.created_at DESC LIMIT 10
    ")->fetchAll();

    // Pending SOA
    $data['soa_pendiente'] = (int)$pdo->query("
        SELECT COUNT(*) FROM miembros m
        WHERE m.estado IN('ACTIVO','PENDIENTE')
          AND (SELECT COUNT(*) FROM soa s WHERE s.miembro_id=m.id AND s.estado='FIRMADO') = 0
    ")->fetchColumn();

    // Pending callbacks
    $data['callbacks'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM llamadas_perdidas WHERE estado='PENDIENTE'"
    )->fetchColumn();

    // Apps in process
    $data['apps_proceso'] = (int)$pdo->query("
        SELECT COUNT(*) FROM miembros
        WHERE app_fecha IS NOT NULL
          AND app_estado_cms NOT IN('RECIBIDO','APROBADO','CONFIRMADO')
    ")->fetchColumn();

    // If admin: team attendance today
    if ($admin) {
        $data['asistencia'] = $pdo->query("
            SELECT u.nombre, u.iniciales, a.check_in, a.check_out
            FROM asistencia a LEFT JOIN usuarios u ON a.agente_id=u.id
            WHERE a.fecha=CURDATE()
        ")->fetchAll();
    }

    // Today's date context
    $data['fecha'] = date('Y-m-d');
    $data['dia_semana'] = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'][date('w')];

    ok($data);
    break;

// ── GET ALL GOALS — for goals editor ────────────────────
case 'luna_get_all_goals':
    requireAdmin();
    $mes  = (int)date('n');
    $anio = (int)date('Y');

    $users = $pdo->query("SELECT id, nombre, iniciales, rol FROM usuarios WHERE activo=1 ORDER BY rol DESC, nombre")->fetchAll();
    $result = [];
    foreach ($users as $u) {
        $g = $pdo->prepare("SELECT * FROM metas WHERE agente_id=? AND mes=? AND anio=? LIMIT 1");
        $g->execute([$u['id'], $mes, $anio]);
        $goals = $g->fetch() ?: ['llamadas_dia'=>20,'citas_mes'=>8,'apps_mes'=>4,'polizas_mes'=>4];

        // Progress this month
        $p = $pdo->prepare("
            SELECT COALESCE(SUM(llamadas_prospectos+llamadas_servicio),0) AS llamadas,
                   COALESCE(SUM(citas_confirmadas),0) AS citas,
                   COALESCE(SUM(apps_enviadas),0) AS apps,
                   COALESCE(SUM(polizas_escritas),0) AS polizas
            FROM reporte_diario WHERE agente_id=? AND MONTH(fecha)=? AND YEAR(fecha)=?
        ");
        $p->execute([$u['id'], $mes, $anio]);

        $result[] = [
            'user'     => $u,
            'goals'    => $goals,
            'progress' => $p->fetch(),
            'mes'      => $mes,
            'anio'     => $anio,
        ];
    }
    ok(['team' => $result]);
    break;

// ── UPDATE GOAL ─────────────────────────────────────────
case 'luna_update_goal':
    requirePost();
    requireAdmin();
    $agente_id   = intOrNull($_POST['agente_id'] ?? null);
    $mes         = intOrNull($_POST['mes'] ?? date('n'));
    $anio        = intOrNull($_POST['anio'] ?? date('Y'));
    $llamadas    = intOrNull($_POST['llamadas_dia'] ?? 20) ?: 20;
    $citas       = intOrNull($_POST['citas_mes'] ?? 8) ?: 8;
    $apps        = intOrNull($_POST['apps_mes'] ?? 4) ?: 4;
    $polizas     = intOrNull($_POST['polizas_mes'] ?? 4) ?: 4;

    if (!$agente_id) err('Falta agente_id.');

    // Upsert
    $check = $pdo->prepare("SELECT id FROM metas WHERE agente_id=? AND mes=? AND anio=?");
    $check->execute([$agente_id, $mes, $anio]);
    if ($check->fetchColumn()) {
        $pdo->prepare("UPDATE metas SET llamadas_dia=?,citas_mes=?,apps_mes=?,polizas_mes=? WHERE agente_id=? AND mes=? AND anio=?")
            ->execute([$llamadas, $citas, $apps, $polizas, $agente_id, $mes, $anio]);
    } else {
        $pdo->prepare("INSERT INTO metas (agente_id,mes,anio,llamadas_dia,citas_mes,apps_mes,polizas_mes) VALUES (?,?,?,?,?,?,?)")
            ->execute([$agente_id, $mes, $anio, $llamadas, $citas, $apps, $polizas]);
    }
    ok(['updated'=>true]);
    break;

// ════════════════════════════════════════════════════════
// LUNA MEMORY SYSTEM — Persistent across devices
// Auto-creates table on first use
// ════════════════════════════════════════════════════════

case 'luna_memory_init':
    // Auto-creates table if not exists. Safe to call multiple times.
    $pdo->exec("CREATE TABLE IF NOT EXISTS luna_memory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mem_type VARCHAR(40) NOT NULL,
        mem_key VARCHAR(120) DEFAULT NULL,
        mem_value LONGTEXT,
        owner_user_id INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_type (mem_type),
        INDEX idx_owner (owner_user_id),
        UNIQUE KEY uniq_type_key (mem_type, mem_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    ok(['ready' => true]);
    break;

case 'luna_memory_get':
    // Returns all memory in 5 categorized buckets
    $pdo->exec("CREATE TABLE IF NOT EXISTS luna_memory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mem_type VARCHAR(40) NOT NULL,
        mem_key VARCHAR(120) DEFAULT NULL,
        mem_value LONGTEXT,
        owner_user_id INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_type (mem_type),
        INDEX idx_owner (owner_user_id),
        UNIQUE KEY uniq_type_key (mem_type, mem_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $rows = $pdo->query("SELECT mem_type, mem_key, mem_value, updated_at FROM luna_memory ORDER BY updated_at DESC")->fetchAll();

    $result = [
        'business_goals'    => [],
        'remember'          => [],
        'monthly_snapshots' => [],
        'session_summaries' => [],
        'lessons'           => [],
        'team_config'       => [],
        'dismissed_alerts'  => [],
    ];

    foreach ($rows as $r) {
        $type = $r['mem_type'];
        $key  = $r['mem_key'];
        $val  = $r['mem_value'];

        // Try to decode JSON; fall back to raw string
        $decoded = json_decode($val, true);
        $value   = ($decoded !== null) ? $decoded : $val;

        switch ($type) {
            case 'business_goals':
                if ($key) $result['business_goals'][$key] = $value;
                break;
            case 'remember':
                $result['remember'][] = $value;
                break;
            case 'monthly_snapshot':
                if ($key) $result['monthly_snapshots'][$key] = $value;
                break;
            case 'session_summary':
                if ($key) $result['session_summaries'][$key] = $value;
                break;
            case 'lesson':
                $result['lessons'][] = $value;
                break;
            case 'team_config':
                if ($key) $result['team_config'][$key] = $value;
                break;
            case 'dismissed_alert':
                if ($key) $result['dismissed_alerts'][$key] = $value;
                break;
        }
    }

    // Sort remember and lessons by date desc (assuming objects have id timestamps)
    usort($result['remember'], function($a, $b) {
        return ($b['id'] ?? 0) - ($a['id'] ?? 0);
    });
    usort($result['lessons'], function($a, $b) {
        return ($b['id'] ?? 0) - ($a['id'] ?? 0);
    });

    ok($result);
    break;

case 'luna_memory_set':
    requirePost();
    $pdo->exec("CREATE TABLE IF NOT EXISTS luna_memory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mem_type VARCHAR(40) NOT NULL,
        mem_key VARCHAR(120) DEFAULT NULL,
        mem_value LONGTEXT,
        owner_user_id INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_type (mem_type),
        INDEX idx_owner (owner_user_id),
        UNIQUE KEY uniq_type_key (mem_type, mem_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $type  = trim($_POST['type'] ?? '');
    $key   = $_POST['key'] ?? null;
    $value = $_POST['value'] ?? null;

    $valid_types = ['business_goals','remember','monthly_snapshot','session_summary','lesson','team_config','dismissed_alert'];
    if (!in_array($type, $valid_types)) {
        err('Tipo inválido. Tipos válidos: ' . implode(', ', $valid_types));
    }

    // Encode value as JSON if it's not a simple string
    if (is_string($value)) {
        $stored = $value;
    } else {
        $stored = json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    // For types that have a key (goals, snapshots, sessions, team, alerts) — UPSERT
    // For types that are arrays/lists (remember, lesson) — INSERT new with auto-key
    if (in_array($type, ['business_goals','monthly_snapshot','session_summary','team_config','dismissed_alert'])) {
        if (!$key) err('Falta key para tipo ' . $type);
        $stmt = $pdo->prepare("INSERT INTO luna_memory (mem_type, mem_key, mem_value, owner_user_id)
                              VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE mem_value=VALUES(mem_value), updated_at=NOW()");
        $stmt->execute([$type, $key, $stored, $uid]);
        ok(['stored' => true, 'type' => $type, 'key' => $key]);
    } else {
        // remember, lesson — append
        $auto_key = $type . '_' . time() . '_' . rand(100,999);
        $pdo->prepare("INSERT INTO luna_memory (mem_type, mem_key, mem_value, owner_user_id) VALUES (?, ?, ?, ?)")
            ->execute([$type, $auto_key, $stored, $uid]);
        ok(['stored' => true, 'type' => $type, 'auto_key' => $auto_key]);
    }
    break;

case 'luna_memory_delete':
    requirePost();
    $type = trim($_POST['type'] ?? '');
    $key  = $_POST['key'] ?? null;

    if (!$type) err('Falta type');

    if ($key) {
        $pdo->prepare("DELETE FROM luna_memory WHERE mem_type=? AND mem_key=?")
            ->execute([$type, $key]);
    } else {
        // Delete all of this type — careful, requires admin
        if (!$admin) err('Solo admin puede borrar todo de un tipo');
        $pdo->prepare("DELETE FROM luna_memory WHERE mem_type=?")->execute([$type]);
    }
    ok(['deleted' => true]);
    break;

case 'luna_memory_bulk_import':
    // Migration endpoint: accepts full localStorage JSON dump and imports it
    requirePost();
    $pdo->exec("CREATE TABLE IF NOT EXISTS luna_memory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mem_type VARCHAR(40) NOT NULL,
        mem_key VARCHAR(120) DEFAULT NULL,
        mem_value LONGTEXT,
        owner_user_id INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_type (mem_type),
        INDEX idx_owner (owner_user_id),
        UNIQUE KEY uniq_type_key (mem_type, mem_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $payload = $_POST['payload'] ?? '';
    $data = json_decode($payload, true);
    if (!is_array($data)) err('Payload JSON inválido');

    $imported = 0;
    $upsert = $pdo->prepare("INSERT INTO luna_memory (mem_type, mem_key, mem_value, owner_user_id)
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE mem_value=VALUES(mem_value), updated_at=NOW()");

    // business_goals: object {key: value}
    foreach (($data['business_goals'] ?? []) as $k => $v) {
        $upsert->execute(['business_goals', $k, is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE), $uid]);
        $imported++;
    }
    // remember: array
    foreach (($data['remember'] ?? []) as $note) {
        $key = 'remember_' . ($note['id'] ?? (time() . rand(100,999)));
        $upsert->execute(['remember', $key, json_encode($note, JSON_UNESCAPED_UNICODE), $uid]);
        $imported++;
    }
    // monthly_snapshots: object {YYYY-MM: data}
    foreach (($data['monthly_snapshots'] ?? []) as $k => $v) {
        $upsert->execute(['monthly_snapshot', $k, json_encode($v, JSON_UNESCAPED_UNICODE), $uid]);
        $imported++;
    }
    // session_summaries: object {agentId: data}
    foreach (($data['session_summaries'] ?? []) as $k => $v) {
        $upsert->execute(['session_summary', $k, json_encode($v, JSON_UNESCAPED_UNICODE), $uid]);
        $imported++;
    }
    // lessons: array
    foreach (($data['lessons'] ?? []) as $lesson) {
        $key = 'lesson_' . ($lesson['id'] ?? (time() . rand(100,999)));
        $upsert->execute(['lesson', $key, json_encode($lesson, JSON_UNESCAPED_UNICODE), $uid]);
        $imported++;
    }
    // team_config: object {memberKey: config}
    foreach (($data['team_config'] ?? []) as $k => $v) {
        $upsert->execute(['team_config', $k, json_encode($v, JSON_UNESCAPED_UNICODE), $uid]);
        $imported++;
    }

    ok(['imported' => $imported]);
    break;

// ─────────────────────────────────────────────────────────
default:
    err('Acción desconocida: ' . $action, 404);
}

} catch (PDOException $e) {
    error_log('[luna_api] PDO: ' . $e->getMessage());
    err('Error de base de datos. Revisa el log del servidor.', 500);
} catch (Exception $e) {
    error_log('[luna_api] ' . $e->getMessage());
    err('Error inesperado: ' . $e->getMessage(), 500);
}
