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
 *
 * Acciones recientes: luna_birthdays_today — cumpleaños de hoy (o ?dias=N).
 * ═══════════════════════════════════════════════════════
 */
// Carga el config de LUNA buscándolo en las ubicaciones posibles (a prueba de
// balas: funciona esté dentro de luna/ o un nivel arriba). El primero que exista.
$__luna_cfg = null;
foreach ([__DIR__ . '/luna_config.php', __DIR__ . '/../luna_config.php', __DIR__ . '/../config.php'] as $__c) {
    if (is_file($__c)) { $__luna_cfg = $__c; break; }
}
if ($__luna_cfg === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['ok'=>false,'error'=>'LUNA: no se encontró luna_config.php. Súbelo a la carpeta luna/ con las credenciales de la base de datos.']));
}
require_once $__luna_cfg;
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ═══════════════════════════════════════════════════════
// CUENTA DE SERVICIO — Athena / Pilar (máquina-a-máquina)
// ═══════════════════════════════════════════════════════
// Permite que OTRO sistema (Athena/Pilar en Railway) llame a LUNA sin
// sesión humana, usando una llave de servicio secreta.
//
//   • La llave vive SOLO en el servidor:  env var LUNA_SERVICE_KEY
//     o constante LUNA_SERVICE_KEY en config.php. Nunca en el código.
//   • Se manda en el header  X-LUNA-Key: <llave>  (o ?service_key=).
//   • Permisos LIMITADOS por allowlist explícita (abajo):
//       LEER todo + CREAR tickets (para el equipo). Nada más escribe.
//   • SIN acceso a: cambiar estado, comisiones, editar/cerrar/borrar tickets,
//     crear otra cosa, config, memoria, outbound, chat LLM. (No están en la lista.)
//   • Cada llamada queda en luna_audit_log igual que las humanas.
//
// Athena NO recibe rol 'admin', así que aunque alguna acción se colara,
// los requireAdmin() la seguirían bloqueando (doble candado).
// ───────────────────────────────────────────────────────
$IS_SERVICE = false;
// Athena puede mandar la llave en cualquiera de estos headers (o por GET/POST).
// TODOS se validan contra la MISMA LUNA_SERVICE_KEY y quedan limitados por el
// allowlist de abajo (leer + crear tickets). NO hay bypass de admin.
$svcKey = $_SERVER['HTTP_X_LUNA_KEY']
       ?? $_SERVER['HTTP_X_ATHENA_KEY']
       ?? $_GET['service_key'] ?? $_POST['service_key'] ?? '';
if ($svcKey === '' && !empty($_SERVER['HTTP_AUTHORIZATION'])
    && preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
    $svcKey = trim($m[1]);   // Authorization: Bearer <llave>
}
if ($svcKey !== '') {
    // Acepta la llave bajo CUALQUIER nombre (LUNA_SERVICE_KEY del deploy nuevo,
    // o LUNA_INTERNAL_KEY del bypass viejo de Athena) por env var o constante.
    // trim() limpia espacios/saltos de línea invisibles (Railway a veces agrega \n).
    $expectedSvcKey = trim((string)(getenv('LUNA_SERVICE_KEY')
        ?: (defined('LUNA_SERVICE_KEY')  ? LUNA_SERVICE_KEY  : '')
        ?: getenv('LUNA_INTERNAL_KEY')
        ?: (defined('LUNA_INTERNAL_KEY') ? LUNA_INTERNAL_KEY : '')));
    $svcKey = trim((string)$svcKey);
    if ($expectedSvcKey === '' || !hash_equals($expectedSvcKey, $svcKey)) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Llave de servicio inválida.']);
        exit;
    }

    // Allowlist: SOLO estas acciones puede ejecutar la cuenta de servicio.
    // Todo lo demás → 403, aunque la llave sea válida.
    // LECTURA + crear TICKETS: Athena/Pilar lee el CRM e informa a LUNA, y
    // puede crear tickets para el equipo (flujo de Isabel). NO puede editar,
    // cerrar, borrar, cambiar estado/comisiones ni crear otra cosa.
    $SERVICE_ALLOWED = [
        // ── LEER ──────────────────────────────────────────
        'luna_whoami','luna_pipeline_summary','luna_t65_alerts',
        'luna_retention_alerts','luna_hot_leads','luna_search_member',
        'luna_member_detail','luna_pending_soa','luna_open_tickets',
        'luna_tickets_by_agent',
        'luna_today_appointments','luna_birthdays_today',
        'luna_pending_callbacks','luna_recent_activity','luna_full_briefing',
        'luna_entity_search','luna_signals_list',
        'luna_skill_list','luna_gaps_overview','luna_business_health',
        // ── ESCRIBIR (aditivo, un registro a la vez — nunca editar/cerrar/borrar) ──
        'luna_create_ticket',
        'luna_add_member_note',
        'luna_log_activity',
        'luna_create_appointment',
        'luna_create_member',   // entra como lead marcado "origen ATHENA" (candado abajo)
    ];
    // Lecturas de comisiones: sensibles para un bot de cara al cliente.
    // OFF por defecto. Para habilitarlas: define LUNA_SERVICE_ALLOW_COMMISSIONS=1
    // (env var o constante) en config.php.
    $svcAllowComm = getenv('LUNA_SERVICE_ALLOW_COMMISSIONS')
        ?: (defined('LUNA_SERVICE_ALLOW_COMMISSIONS') ? LUNA_SERVICE_ALLOW_COMMISSIONS : '');
    if ($svcAllowComm) {
        array_push($SERVICE_ALLOWED, 'luna_commissions_summary',
            'luna_commissions_advanced','luna_commission_calc','luna_carriers_breakdown');
    }

    $reqAction = $_GET['action'] ?? $_POST['action'] ?? '';
    if (!in_array($reqAction, $SERVICE_ALLOWED, true)) {
        http_response_code(403);
        echo json_encode([
            'ok'    => false,
            'error' => 'Acción no permitida para la cuenta de servicio (Athena).',
            'action'=> $reqAction,
            'hint'  => 'Athena/Pilar puede LEER el CRM y CREAR tickets, nada más. No puede editar, cerrar, borrar, cambiar estado/comisiones ni crear otra cosa.',
        ]);
        exit;
    }

    // Contexto de la cuenta de servicio. El agente_id debe existir como
    // agente real (FK en miembros/tickets/citas/actividad). Configúralo con
    // LUNA_SERVICE_AGENT_ID (env var o constante); default 1 = Isabel/admin.
    $svcAgentId = (int)(getenv('LUNA_SERVICE_AGENT_ID')
        ?: (defined('LUNA_SERVICE_AGENT_ID') ? LUNA_SERVICE_AGENT_ID : 1));
    $_SESSION['user'] = [
        'id'        => $svcAgentId,
        'username'  => 'athena',
        'nombre'    => 'Athena (Pilar)',
        'rol'       => 'service',          // ← NO es 'admin'
        'iniciales' => 'AP',
        'color'     => '#7C3AED',
    ];
    $IS_SERVICE = true;
}

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'No autorizado. Inicia sesión en el CRM primero.']);
    exit;
}

$user  = $_SESSION['user'];
$admin = ($user['rol'] ?? '') === 'admin';
$uid   = (int)$user['id'];
// No tumbar TODO si la base no conecta (p.ej. credenciales en blanco): si falla,
// $pdo = null y las acciones que SÍ necesitan base devuelven un error limpio (no un
// 500 que mata todo). Auth, luna_whoami y el diagnóstico responden SIN base de datos.
try { $pdo = db(); } catch (\Throwable $e) { $pdo = null; error_log('[luna_api] DB no conecta: ' . $e->getMessage()); }

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
    // Audit centralizado (con PII redactado). Nunca rompe la operación.
    lunaAudit($pdo, $agente_id, 'WRITE:' . $tipo, $desc);
}
function intOrNull($v) { return ($v === '' || $v === null) ? null : (int)$v; }
function strOrNull($v) { $v = trim((string)$v); return $v === '' ? null : $v; }

// ── Esquema adaptable: evita "Data truncated" (error 1265) ───
// Lee del INFORMATION_SCHEMA qué valores acepta REALMENTE una columna
// (ENUM/SET) o su largo (VARCHAR), y ajusta el valor antes de insertar.
// Así el código no depende de adivinar el ENUM exacto de la tabla.
function dbColumnSpec(PDO $pdo, string $table, string $column): array {
    static $cache = [];
    $key = "$table.$column";
    if (isset($cache[$key])) return $cache[$key];
    $spec = ['type' => 'other', 'values' => [], 'len' => null];
    try {
        $st = $pdo->prepare("SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH
                             FROM INFORMATION_SCHEMA.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$table, $column]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $dt = strtolower((string)$row['DATA_TYPE']);
            if ($dt === 'enum' || $dt === 'set') {
                preg_match_all("/'((?:[^']|'')*)'/", (string)$row['COLUMN_TYPE'], $mm);
                $spec['type']   = $dt;
                $spec['values'] = array_map(fn($v) => str_replace("''", "'", $v), $mm[1]);
            } elseif ($dt === 'char' || $dt === 'varchar') {
                $spec['type'] = 'char';
                $spec['len']  = $row['CHARACTER_MAXIMUM_LENGTH'] !== null ? (int)$row['CHARACTER_MAXIMUM_LENGTH'] : null;
            }
        }
    } catch (Exception $e) { /* sin acceso a INFORMATION_SCHEMA: degrada a no-op */ }
    $cache[$key] = $spec;
    return $spec;
}

// Ajusta $value a lo que la columna acepta. Para ENUM/SET: devuelve el miembro
// que coincide (sin importar mayúsculas); si no, $fallback (si existe en el set)
// o el primer valor del ENUM. Para VARCHAR: trunca al largo permitido.
function dbCoerce(PDO $pdo, string $table, string $column, string $value, ?string $fallback = null): string {
    $spec = dbColumnSpec($pdo, $table, $column);
    if ($spec['type'] === 'enum' || $spec['type'] === 'set') {
        foreach ($spec['values'] as $opt) if (strcasecmp($opt, $value) === 0) return $opt;
        if ($fallback !== null) foreach ($spec['values'] as $opt) if (strcasecmp($opt, $fallback) === 0) return $opt;
        return $spec['values'][0] ?? $value;
    }
    if ($spec['type'] === 'char' && $spec['len']) return mb_substr($value, 0, $spec['len']);
    return $value;
}

// ── Miembro "catch-all" para tickets sin cliente ─────────
// El CRM web lista los tickets POR cliente, así que un ticket sin miembro
// queda invisible en la web. Esta función devuelve el id de un miembro
// "OTRO/General" donde colgar esos tickets para que SÍ se vean.
// Prioridad: 1) constante LUNA_DEFAULT_TICKET_MEMBER  2) autodetección por
// nombre (OTRO/GENERAL/OFICINA/TAREAS). Devuelve null si no hay ninguno.
function defaultTicketMember(PDO $pdo): ?int {
    static $resolved = false; static $val = null;
    if ($resolved) return $val;
    $resolved = true;
    $id = (int)(getenv('LUNA_DEFAULT_TICKET_MEMBER')
        ?: (defined('LUNA_DEFAULT_TICKET_MEMBER') ? LUNA_DEFAULT_TICKET_MEMBER : 0));
    if ($id > 0) {
        try {
            $ck = $pdo->prepare("SELECT id FROM miembros WHERE id=?"); $ck->execute([$id]);
            if ($ck->fetchColumn()) { $val = $id; return $val; }
        } catch (Exception $e) { /* sigue a autodetección */ }
    }
    try {
        $r = $pdo->query("SELECT id FROM miembros
                          WHERE UPPER(nombre) IN ('OTRO','OTROS','GENERAL','OFICINA','TAREAS','TAREA')
                          ORDER BY id ASC LIMIT 1")->fetchColumn();
        $val = $r ? (int)$r : null;
    } catch (Exception $e) { $val = null; }
    return $val;
}

// ── Compliance & audit helpers (capa de confianza) ───────
// Redacta PII antes de guardar en logs: teléfono, email, MBI.
function redactPII($s) {
    $s = (string)$s;
    $s = preg_replace('/\b[1-9][A-Za-z0-9]{2}-?[A-Za-z0-9]{2}-?[A-Za-z0-9]{4}\b/', '[MBI]', $s);
    $s = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $s);
    $s = preg_replace('/\b(\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/', '[tel]', $s);
    return $s;
}
// Tabla de audit: se crea sola la primera vez por request.
function lunaAudit(PDO $pdo, ?int $uid, string $action, string $detail) {
    static $ready = false;
    try {
        if (!$ready) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS luna_audit_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT DEFAULT NULL,
                action VARCHAR(60) NOT NULL,
                detail TEXT,
                ip VARCHAR(45) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id), INDEX idx_action (action), INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $ready = true;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        // Marca el origen: si es la cuenta de servicio (Athena/Pilar) lo
        // anteponemos para poder auditar quién hizo qué.
        global $IS_SERVICE;
        if (!empty($IS_SERVICE)) $action = 'ATHENA:' . $action;
        $pdo->prepare("INSERT INTO luna_audit_log (user_id, action, detail, ip) VALUES (?,?,?,?)")
            ->execute([$uid, mb_substr($action,0,60), mb_substr(redactPII($detail),0,2000), $ip]);
    } catch (Exception $e) { /* audit nunca rompe la operación */ }
}

// ── ACTORES AUTORIZADOS — quién puede ordenar ACCIONES a LUNA ──
// Además de Isabel (admin), solo los user_id guardados en
// luna_authorized_actors pueden ejecutar acciones de escritura
// (crear / cambiar / cerrar / enviar). El resto solo puede LEER/consultar.
function ensureActorsTable(PDO $pdo) {
    static $done = false; if ($done) return; $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS luna_authorized_actors (
            user_id    INT PRIMARY KEY,
            added_by   INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { /* si falla, userCanAct cae a 'solo admin' */ }
}
function authorizedActorIds(PDO $pdo) {
    static $ids = null;
    if ($ids !== null) return $ids;
    try {
        ensureActorsTable($pdo);
        $ids = array_map('intval',
            $pdo->query("SELECT user_id FROM luna_authorized_actors")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) { $ids = []; }
    return $ids;
}
// ¿El usuario actual puede ejecutar ACCIONES (no solo leer)?
function userCanAct() {
    global $admin, $uid, $pdo, $IS_SERVICE;
    if (!empty($IS_SERVICE)) return true;        // Athena ya está limitada por su allowlist
    if (!empty($admin))      return true;        // Isabel siempre puede
    return in_array((int)$uid, authorizedActorIds($pdo), true);
}
// Candado para acciones de escritura abiertas a "actores aprobados".
function requireActor() {
    if (!userCanAct()) {
        global $pdo, $uid;
        lunaAudit($pdo, $uid, 'DENEGADO', 'Intento de acción sin permiso (' . ($_GET['action'] ?? $_POST['action'] ?? '?') . ')');
        err('🔒 No tienes permiso para ordenarle ACCIONES a LUNA. Pídele acceso a Isabel. (Consultar/leer sí puedes.)', 403);
    }
}
// Alerta para Isabel: registra una acción sensible para que ella la revise.
// No alerta de las acciones de la propia Isabel (ella ya sabe lo que hizo).
function notifyAdmin(PDO $pdo, ?int $uid, string $summary) {
    global $admin;
    if (!empty($admin)) return;
    lunaAudit($pdo, $uid, 'ALERTA', $summary);
}

// Review-hooks deterministas antes de mandar algo a un cliente (CMS).
function reviewOutbound($body, $subject = '') {
    $flags = [];
    $t = mb_strtolower($subject . ' ' . $body);
    if (preg_match('/\$\s?\d|\bcopago\b|\bdeducible\b/u', $t))
        $flags[] = ['sev'=>'alto','msg'=>'Posible precio/costo específico — requiere SOA y contexto.'];
    if (preg_match('/\b(diagn[oó]stico|receta médica|dosis|tratamiento|s[ií]ntoma)\b/u', $t))
        $flags[] = ['sev'=>'alto','msg'=>'Posible consejo médico — fuera del scope del agente.'];
    if (preg_match('/\b(mejor plan|garantiz|el m[aá]s barato|sin costo alguno)\b/u', $t))
        $flags[] = ['sev'=>'alto','msg'=>'Claim potencialmente engañoso (reglas de marketing CMS).'];
    if (preg_match('/\bgratis\b/u', $t))
        $flags[] = ['sev'=>'aviso','msg'=>'Uso de "gratis" — solo si es literalmente cierto.'];
    foreach (['anthem','scan','la care','alignment','humana','molina','health net','unitedhealthcare','uhc','blue shield'] as $c)
        if (strpos($t,$c) !== false) { $flags[]=['sev'=>'aviso','msg'=>"Menciona carrier ($c) — confirmar permiso/contexto."]; break; }
    if (mb_strlen($body) > 400 && stripos($body,'not connected with') === false && stripos($body,'no está afiliado') === false)
        $flags[] = ['sev'=>'aviso','msg'=>'Falta disclaimer CMS en pieza larga.'];
    if (mb_strlen($body) > 5000)
        $flags[] = ['sev'=>'aviso','msg'=>'Mensaje muy largo (>5000 caracteres).'];
    $blocked = (bool)array_filter($flags, fn($f) => $f['sev'] === 'alto');
    return ['flags'=>$flags, 'blocked'=>$blocked];
}
// Horas de silencio: 9pm–7am hora de Los Angeles.
function withinQuietHours() {
    try { $h = (int)(new DateTime('now', new DateTimeZone('America/Los_Angeles')))->format('G'); }
    catch (Exception $e) { $h = (int)date('G'); }
    return ($h >= 21 || $h < 7);
}
// Crea la tabla de cola outbound (una vez por request).
function ensureOutboundTable(PDO $pdo) {
    static $done = false; if ($done) return; $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS luna_outbound_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        miembro_id INT DEFAULT NULL,
        channel ENUM('EMAIL','SMS','WHATSAPP') DEFAULT 'EMAIL',
        recipient VARCHAR(160) DEFAULT NULL,
        subject VARCHAR(200) DEFAULT NULL,
        body TEXT,
        status ENUM('DRAFT','APPROVED','SENT','REJECTED') DEFAULT 'DRAFT',
        review_flags TEXT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        approved_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME DEFAULT NULL,
        INDEX idx_status (status), INDEX idx_miembro (miembro_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

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

// ── LUNA CHAT — proxy a Anthropic (la API key vive en el servidor) ──
// El browser ya NO lleva la API key. Llama aquí; reenviamos a Anthropic
// con la key del servidor y devolvemos el mismo stream SSE tal cual,
// para que el parser del frontend (content_block_delta) no cambie.
// La key se toma de la env var ANTHROPIC_API_KEY o de una constante
// ANTHROPIC_API_KEY definida en config.php.
case 'luna_chat':
    requirePost();

    // ── Chat de IA: SOLO Isabel (admin) ──────────────────────
    // Decisión de Isabel: el chat con IA queda solo para ella; el equipo usa
    // la plataforma (datos, junta, tareas) y recibe los reportes automáticos,
    // pero no consume el chat (que es el costo variable). Para habilitar a
    // alguien más en el futuro: agrega su user_id a $CHAT_EXTRA_UIDS.
    // 🔓 Candado QUITADO (decisión de Isabel, "por ahora"): el chat queda abierto
    // a cualquier usuario con sesión en el CRM. Para volver a restringirlo solo a
    // Isabel en el futuro, descomenta el bloque de abajo.
    // $CHAT_EXTRA_UIDS = [];   // ej. [5] para permitir a Skarleth
    // if (!$admin && !in_array($uid, $CHAT_EXTRA_UIDS, true)) {
    //     lunaAudit($pdo, $uid, 'CHAT_DENEGADO', 'Intento de usar el chat de IA (restringido a Isabel)');
    //     err('💬 El chat con LUNA está disponible solo para Isabel.', 403);
    // }

    $apiKey = getenv('ANTHROPIC_API_KEY')
        ?: (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '');
    if (!$apiKey) {
        err('Falta ANTHROPIC_API_KEY en el servidor (env var o constante en config.php).', 500);
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || empty($body['messages']) || !is_array($body['messages'])) {
        err('Body inválido: se requiere messages[].');
    }
    $system   = (string)($body['system'] ?? '');
    $messages = $body['messages'];
    $maxTok   = min(4096, max(256, (int)($body['max_tokens'] ?? 1800)));
    $useWeb   = !empty($body['web_search']);
    // Tool-calling nativo: el frontend manda las definiciones de tools de LUNA.
    // El loop agéntico vive en el browser (ejecuta cada tool contra el CRM y
    // re-alimenta el resultado). Aquí solo las pasamos a Anthropic tal cual.
    $clientTools = (isset($body['tools']) && is_array($body['tools'])) ? $body['tools'] : [];

    // Audit ligero: registramos QUE hubo una consulta IA (sin guardar el contenido/PII).
    logActivity($pdo, $uid, null, 'LUNA_CHAT', $useWeb ? 'Consulta IA vía LUNA (web_search)' : 'Consulta IA vía LUNA');

    // Registro de conversación: guardamos lo que escribió el usuario en ESTE
    // turno (PII redactado, truncado) para que Isabel pueda revisar qué se le
    // pidió a LUNA si sospecha de algo. Solo miramos el ÚLTIMO mensaje: si es
    // una re-alimentación de tool_result (el loop agéntico), lo saltamos para
    // no duplicar el mismo prompt humano en cada ronda de tools.
    $agentLabel = preg_replace('/[^a-zA-Z0-9_\- :]/', '', (string)($body['agent'] ?? ''));
    $last = end($messages);
    $humanMsg = '';
    if (is_array($last) && ($last['role'] ?? '') === 'user') {
        $c = $last['content'] ?? '';
        if (is_string($c)) {
            $humanMsg = $c;
        } elseif (is_array($c)) {
            $isToolRefeed = false; $txt = '';
            foreach ($c as $part) {
                if (!is_array($part)) continue;
                if (($part['type'] ?? '') === 'tool_result') { $isToolRefeed = true; break; }
                if (($part['type'] ?? '') === 'text' && $txt === '') $txt = (string)($part['text'] ?? '');
            }
            if (!$isToolRefeed) $humanMsg = $txt;
        }
    }
    if (trim($humanMsg) !== '') {
        lunaAudit($pdo, $uid, 'CHAT', ($agentLabel ? "[$agentLabel] " : '') . mb_substr(trim($humanMsg), 0, 600));
    }

    // Mismo problema {} -> [] de PHP, pero en el HISTORIAL: cuando una herramienta
    // se usó SIN parámetros, su tool_use.input queda como [] y Anthropic exige objeto.
    // Recorremos los mensajes y forzamos objeto en cualquier tool_use.input vacío.
    foreach ($messages as &$_m) {
        if (!is_array($_m) || !isset($_m['content']) || !is_array($_m['content'])) continue;
        foreach ($_m['content'] as &$_b) {
            if (is_array($_b) && ($_b['type'] ?? '') === 'tool_use' && empty($_b['input'])) {
                $_b['input'] = new stdClass();
            }
        }
        unset($_b);
    }
    unset($_m);

    $reqBody = [
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => $maxTok,
        'system'     => $system,
        'stream'     => true,
        'messages'   => $messages,
    ];
    // Tools = las nativas de LUNA (del frontend) + web search opcional de Anthropic.
    $tools = [];
    foreach ($clientTools as $t) {
        if (!is_array($t)) continue;
        // PHP convierte un objeto vacío {} en un array vacío []. Anthropic EXIGE que
        // input_schema.properties sea un OBJETO. Si quedó vacío, lo forzamos a objeto.
        if (isset($t['input_schema']) && is_array($t['input_schema']) && empty($t['input_schema']['properties'])) {
            $t['input_schema']['properties'] = new stdClass();
        }
        $tools[] = $t;
    }
    // #20 Web search nativo de Anthropic (solo si el agente lo pide).
    if ($useWeb) {
        $tools[] = [
            'type'     => 'web_search_20250305',
            'name'     => 'web_search',
            'max_uses' => 5,
        ];
    }
    if ($tools) $reqBody['tools'] = $tools;
    $payload = json_encode($reqBody, JSON_UNESCAPED_UNICODE);

    // Cambiamos la respuesta a streaming SSE (sobrescribe el Content-Type JSON de arriba).
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // evita buffering en proxies tipo nginx

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_RETURNTRANSFER => true,   // bufferizamos para poder DETECTAR errores HTTP
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    // Registro de diagnóstico del chat (visible en luna_diag.php): qué devolvió Anthropic.
    @file_put_contents(__DIR__ . '/luna_chat_last.log',
        date('c') . " | http=$code | curl_err=" . ($cerr ?: '-') . " | len=" . strlen((string)$resp)
        . " | head=" . str_replace(["\r", "\n"], ' ', mb_substr((string)$resp, 0, 600)) . "\n",
        FILE_APPEND);

    if ($resp === false) {
        echo "event: error\ndata: " . json_encode(['error' => 'No se pudo conectar a Anthropic: ' . $cerr]) . "\n\n";
    } elseif ($code >= 400) {
        // Anthropic devolvió un error (NO es stream SSE). Lo mostramos para NO quedar en "(sin respuesta)".
        $msg = $resp; $j = json_decode($resp, true);
        if (isset($j['error']['message'])) $msg = $j['error']['message'];
        echo "event: error\ndata: " . json_encode(['error' => 'Anthropic HTTP ' . $code . ': ' . mb_substr((string)$msg, 0, 300)]) . "\n\n";
    } else {
        echo $resp;   // éxito: reenviamos el stream SSE tal cual (el frontend lo parsea)
    }
    exit;

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

// ── BUSINESS HEALTH SCORE — "¿el negocio rueda solo?" (patrón Athena Sec.10) ──
// Número 0-100 compuesto por 4 áreas. Cada componente va en su propio try/catch:
// si una consulta falla (columna/tabla distinta), ese componente se NEUTRALIZA
// (marca completa) en vez de romper la respuesta o falsear el número.
case 'luna_business_health':
    $comp = [];
    $score = 0;

    // 1) PIPELINE FLOW (30) — ¿hay leads calientes y apps cerrando?
    $cPipe = 30;
    try {
        $hot  = (int)$pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='HOT LEAD'")->fetchColumn();
        $apps = (int)$pdo->query("SELECT COUNT(*) FROM miembros WHERE app_fecha IS NOT NULL AND app_estado_cms NOT IN ('RECIBIDO','APROBADO','CONFIRMADO')")->fetchColumn();
        $cPipe = 30; $nota = 'Pipeline con movimiento.';
        if ($hot === 0)  { $cPipe -= 15; $nota = 'No hay HOT LEADs — pipeline frío.'; }
        if ($apps === 0) { $cPipe -= 10; $nota = ($hot===0 ? 'Sin hot leads ni apps en proceso.' : 'Nada en proceso de aplicación.'); }
        $cPipe = max(0, $cPipe);
        $comp['pipeline'] = ['score'=>$cPipe,'max'=>30,'hot_leads'=>$hot,'apps_proceso'=>$apps,'nota'=>$nota];
    } catch (Exception $e) { $cPipe = 30; $comp['pipeline'] = ['score'=>30,'max'=>30,'nota'=>'n/d']; }
    $score += $cPipe;

    // 2) TICKETS (30) — carga de alta prioridad o vencida
    $cTick = 30;
    try {
        $alta = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE estado!='CERRADO' AND prioridad='ALTA'")->fetchColumn();
        $venc = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE estado!='CERRADO' AND fecha_seguimiento IS NOT NULL AND fecha_seguimiento < CURDATE()")->fetchColumn();
        $cTick = 30 - min(30, $alta*3 + $venc*3);
        $comp['tickets'] = ['score'=>$cTick,'max'=>30,'alta_abiertos'=>$alta,'vencidos'=>$venc,
            'nota'=>($alta+$venc>0 ? "$alta ALTA abiertos, $venc vencidos." : 'Tickets bajo control.')];
    } catch (Exception $e) { $cTick = 30; $comp['tickets'] = ['score'=>30,'max'=>30,'nota'=>'n/d']; }
    $score += $cTick;

    // 3) SOA / COMPLIANCE (25) — activos/pendientes/hot sin SOA firmada
    $cSoa = 25;
    try {
        $pend = (int)$pdo->query("
            SELECT COUNT(*) FROM miembros m
            WHERE m.estado IN ('ACTIVO','PENDIENTE','HOT LEAD')
              AND (SELECT COUNT(*) FROM soa s WHERE s.miembro_id=m.id AND s.estado='FIRMADO')=0
        ")->fetchColumn();
        $cSoa = 25 - min(25, $pend*4);
        $comp['compliance'] = ['score'=>$cSoa,'max'=>25,'sin_soa'=>$pend,
            'nota'=>($pend>0 ? "$pend miembro(s) sin SOA firmada." : 'SOAs al día.')];
    } catch (Exception $e) { $cSoa = 25; $comp['compliance'] = ['score'=>25,'max'=>25,'nota'=>'n/d']; }
    $score += $cSoa;

    // 4) RETENCIÓN (15) — llamadas que tocan hoy (Day 7/30/60/90)
    $cRet = 15;
    try {
        $due = (int)$pdo->query("
            SELECT COUNT(*) FROM miembros
            WHERE estado='ACTIVO' AND fecha_efectiva IN (
              DATE_SUB(CURDATE(),INTERVAL 7 DAY), DATE_SUB(CURDATE(),INTERVAL 30 DAY),
              DATE_SUB(CURDATE(),INTERVAL 60 DAY), DATE_SUB(CURDATE(),INTERVAL 90 DAY))
        ")->fetchColumn();
        $cRet = 15 - min(15, $due*3);
        $comp['retencion'] = ['score'=>$cRet,'max'=>15,'tocan_hoy'=>$due,
            'nota'=>($due>0 ? "$due llamada(s) de retención tocan hoy." : 'Sin retención pendiente hoy.')];
    } catch (Exception $e) { $cRet = 15; $comp['retencion'] = ['score'=>15,'max'=>15,'nota'=>'n/d']; }
    $score += $cRet;

    $score = max(0, min(100, (int)round($score)));
    if      ($score >= 80) { $band='autopilot'; $msg='El negocio rueda solo. Tu día es tuyo — solo te aviso si surge algo crítico.'; }
    else if ($score >= 50) { $band='revisa';    $msg='Hay 2-3 cosas concretas que mirar hoy.'; }
    else                   { $band='necesita';  $msg='Hoy sí necesitas meter mano — hay tensión que solo tú destrabas.'; }

    // El "foco de hoy": el área que más puntos perdió vs su máximo
    $worst = null; $worstLoss = 0;
    foreach ($comp as $k=>$c) {
        $loss = ($c['max'] ?? 0) - ($c['score'] ?? 0);
        if ($loss > $worstLoss) { $worstLoss = $loss; $worst = ['area'=>$k, 'nota'=>$c['nota'] ?? '']; }
    }

    ok([
        'score'       => $score,
        'band'        => $band,
        'mensaje'     => $msg,
        'componentes' => $comp,
        'foco_hoy'    => $worst,   // dónde meter mano primero (null si todo bien)
        'fecha'       => date('Y-m-d'),
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

// ── CUMPLEAÑOS — quién cumple hoy (o en los próximos N días) ─
// ?dias=0 (default) → solo HOY.  ?dias=7 → próximos 7 días, incl. hoy.
// Lectura pura; la usa Athena para traer la lista diaria / calendario.
case 'luna_birthdays_today':
    // ?mes=1..12 → todos los cumpleaños de ese mes (default: mes actual si mes=0 y se pide).
    $mes = (int)($_GET['mes'] ?? 0);
    if ($mes >= 1 && $mes <= 12) {
        $stmt = $pdo->prepare("
            SELECT m.id, m.nombre, m.apellido, m.telefono, m.email, m.ciudad,
                   m.estado, m.carrier, m.plan, m.dob,
                   DAY(m.dob) AS dia,
                   YEAR(CURDATE()) - YEAR(m.dob) AS edad_cumple,
                   u.iniciales AS agente_ini, u.nombre AS agente_nombre
            FROM miembros m
            LEFT JOIN usuarios u ON m.agente_id = u.id
            WHERE m.dob IS NOT NULL AND MONTH(m.dob) = ?
            ORDER BY DAY(m.dob), m.apellido, m.nombre
        ");
        $stmt->execute([$mes]);
        $rows = $stmt->fetchAll();
        ok([
            'fecha'       => date('Y-m-d'),
            'mes'         => $mes,
            'total'       => count($rows),
            'cumpleaneros'=> $rows,
        ]);
        break;
    }
    $dias = max(0, min(60, (int)($_GET['dias'] ?? 0)));
    if ($dias === 0) {
        // Solo cumpleaños de HOY.
        $stmt = $pdo->query("
            SELECT m.id, m.nombre, m.apellido, m.telefono, m.email, m.ciudad,
                   m.estado, m.carrier, m.plan, m.dob,
                   TIMESTAMPDIFF(YEAR, m.dob, CURDATE()) AS edad,
                   0 AS dias_para_cumple,
                   u.iniciales AS agente_ini, u.nombre AS agente_nombre
            FROM miembros m
            LEFT JOIN usuarios u ON m.agente_id = u.id
            WHERE m.dob IS NOT NULL
              AND DATE_FORMAT(m.dob, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
            ORDER BY FIELD(m.estado,'ACTIVO','PENDIENTE','HOT LEAD'), m.apellido, m.nombre
        ");
        $rows = $stmt->fetchAll();
    } else {
        // Hoy + próximos N días. dias_para_cumple usa día-del-año (aprox. ±1 en años bisiestos).
        $stmt = $pdo->prepare("
            SELECT m.id, m.nombre, m.apellido, m.telefono, m.email, m.ciudad,
                   m.estado, m.carrier, m.plan, m.dob,
                   TIMESTAMPDIFF(YEAR, m.dob, CURDATE()) AS edad,
                   (DAYOFYEAR(m.dob) - DAYOFYEAR(CURDATE()) + 366) % 366 AS dias_para_cumple,
                   u.iniciales AS agente_ini, u.nombre AS agente_nombre
            FROM miembros m
            LEFT JOIN usuarios u ON m.agente_id = u.id
            WHERE m.dob IS NOT NULL
            HAVING dias_para_cumple <= ?
            ORDER BY dias_para_cumple, FIELD(m.estado,'ACTIVO','PENDIENTE','HOT LEAD'), m.apellido
        ");
        $stmt->execute([$dias]);
        $rows = $stmt->fetchAll();
    }
    ok([
        'fecha'       => date('Y-m-d'),
        'dias'        => $dias,
        'total'       => count($rows),
        'cumpleaneros'=> $rows,
    ]);
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

// ── TICKETS POR AGENTE — desglose de todo el equipo ─────
// Responde "cuántos tickets abiertos/cerrados tiene cada agente".
// Disponible para Isabel (admin) y para Athena (cuenta de servicio).
// Un agente normal NO ve el desglose del equipo.
case 'luna_tickets_by_agent':
    if (!$admin && !$IS_SERVICE) {
        err('El desglose de tickets por agente lo ve Isabel (o Athena).', 403);
    }
    // Agrupa por el responsable del ticket: asignado_a si existe, si no agente_id.
    $rows = $pdo->query("
        SELECT u.id            AS agente_id,
               u.nombre        AS agente_nombre,
               u.iniciales     AS agente_ini,
               COUNT(*)                                                   AS total,
               SUM(t.estado <> 'CERRADO')                                 AS abiertos,
               SUM(t.estado =  'CERRADO')                                 AS cerrados,
               SUM(t.estado <> 'CERRADO' AND t.prioridad = 'ALTA')        AS abiertos_alta,
               SUM(t.estado <> 'CERRADO'
                   AND t.fecha_seguimiento IS NOT NULL
                   AND t.fecha_seguimiento < CURDATE())                   AS vencidos
        FROM tickets t
        LEFT JOIN usuarios u ON u.id = COALESCE(t.asignado_a, t.agente_id)
        GROUP BY u.id, u.nombre, u.iniciales
        ORDER BY abiertos DESC, cerrados DESC
    ")->fetchAll();
    // Totales del equipo (para cuadrar con el briefing).
    $tot = $pdo->query("
        SELECT COUNT(*) AS total,
               SUM(estado <> 'CERRADO')                          AS abiertos,
               SUM(estado =  'CERRADO')                          AS cerrados,
               SUM(estado <> 'CERRADO' AND prioridad = 'ALTA')   AS abiertos_alta
        FROM tickets
    ")->fetch();
    ok(['por_agente' => $rows, 'totales' => $tot]);
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
    requireActor();
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
    requireActor();
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
    requireActor();
    $nombre   = strOrNull($_POST['nombre'] ?? '');
    $apellido = strOrNull($_POST['apellido'] ?? '');
    $telefono = strOrNull($_POST['telefono'] ?? '');
    $dob      = strOrNull($_POST['dob'] ?? '');
    $estado   = strtoupper(strOrNull($_POST['estado'] ?? 'PROSPECTO'));
    $fuente   = strOrNull($_POST['fuente'] ?? 'LUNA');
    $ciudad   = strOrNull($_POST['ciudad'] ?? '');
    $email    = strOrNull($_POST['email'] ?? '');
    $idioma   = strtoupper(strOrNull($_POST['idioma'] ?? 'ESP'));

    // 🔒 Candado Athena: un lead creado por el bot queda marcado (fuente ATHENA)
    // para que Isabel lo revise, y SIEMPRE entra como lead — el bot nunca crea
    // un miembro ACTIVO directo.
    if (!empty($IS_SERVICE)) {
        $fuente = 'ATHENA';
        if (!in_array($estado, ['PROSPECTO','HOT LEAD','FOLLOW-UP'])) $estado = 'PROSPECTO';
    }

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
    notifyAdmin($pdo, $uid, "Creó un nuevo miembro/lead vía LUNA (estado: $estado)");
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
// Reglas (pedido de Isabel): NADA de tickets sueltos.
//   • Siempre con RESPONSABLE (persona real). Si no se indica, va al dueño
//     por defecto (LUNA_SERVICE_DEFAULT_ASSIGNEE o admin), nunca al bot.
//   • Si es de un CLIENTE → vinculado a miembro_id (clase=miembro).
//   • TAREA / PROYECTO → sin cliente, se crean distinto (clase=tarea|proyecto).
//   • Si lo crea Athena/Pilar → se marca el origen (fuente=ATHENA + etiqueta).
case 'luna_create_ticket':
    requirePost();
    requireActor();
    $IS_SVC = !empty($IS_SERVICE);

    $tipo      = strtoupper(strOrNull($_POST['tipo'] ?? 'OTRO'));
    $prioridad = strtoupper(strOrNull($_POST['prioridad'] ?? 'MEDIA'));
    $desc      = strOrNull($_POST['descripcion'] ?? '');
    $mid       = intOrNull($_POST['miembro_id'] ?? null);
    $asig      = intOrNull($_POST['asignado_a'] ?? null);
    if (!$desc) err('Descripción requerida.');

    // CLASE: ticket de MIEMBRO (de un cliente) vs TAREA / PROYECTO (sin cliente).
    // Explícita (clase=miembro|tarea|proyecto) o inferida por si hay miembro_id.
    $clase = strtolower((string)(strOrNull($_POST['clase'] ?? '') ?? ''));
    if (!in_array($clase, ['miembro','tarea','proyecto'], true)) $clase = $mid ? 'miembro' : 'tarea';

    if ($clase === 'miembro') {
        // De un cliente: OBLIGA miembro_id y que exista (no suelto).
        if (!$mid) err('Ticket de miembro: falta miembro_id (de qué cliente es). Para algo sin cliente usa clase=tarea o clase=proyecto.');
        $ck = $pdo->prepare("SELECT 1 FROM miembros WHERE id=?"); $ck->execute([$mid]);
        if (!$ck->fetchColumn()) err("El miembro_id $mid NO existe en el CRM. No inventes clientes: busca el real con luna_search_member y usa su id. Si la persona aún no es cliente, créala primero en el CRM, o manda clase=tarea (sin cliente).");
    } else {
        // Sin cliente real (tarea/proyecto/general). El CRM web lista los tickets
        // POR cliente, así que uno sin miembro queda INVISIBLE en la web. Solución:
        // colgarlo de un miembro catch-all "OTRO" para que sí se muestre.
        $mid = defaultTicketMember($pdo);   // null si no hay un miembro "OTRO" configurado
        if ($tipo === '') $tipo = 'OTRO';
        if ($clase === 'tarea' && $tipo === 'OTRO') $tipo = 'TAREA';
        if ($clase === 'proyecto' && stripos($desc, 'proyecto') === false) $desc = 'PROYECTO: ' . $desc;
    }

    $tipos_validos = ['SERVICIO','LLAMADA','LLAMADA PERDIDA','APLICACION','CITA','SEGUIMIENTO',
                      'TAREA','PROSPECTO','QUEJA','INCENTIVO','SOPORTE','MARKETING','DENTAL','URGENTE','OTRO'];
    if (!in_array($tipo, $tipos_validos)) $tipo = ($clase === 'miembro') ? 'OTRO' : 'TAREA';
    if (!in_array($prioridad, ['ALTA','MEDIA','BAJA'])) $prioridad = 'MEDIA';

    // RESPONSABLE: nunca suelto. Sin asignación explícita → dueño por defecto.
    $defaultOwner = (int)(getenv('LUNA_SERVICE_DEFAULT_ASSIGNEE')
        ?: (defined('LUNA_SERVICE_DEFAULT_ASSIGNEE') ? LUNA_SERVICE_DEFAULT_ASSIGNEE : 1));
    if (!$asig) $asig = $IS_SVC ? $defaultOwner : $uid;
    // Agente humano no-admin solo se asigna a sí mismo. Athena (servicio) y
    // admin pueden asignar a cualquiera del equipo.
    if (!$admin && !$IS_SVC && $asig !== $uid) $asig = $uid;
    // El responsable debe existir y estar activo; si no, al dueño por defecto.
    $ck = $pdo->prepare("SELECT 1 FROM usuarios WHERE id=? AND activo=1"); $ck->execute([$asig]);
    if (!$ck->fetchColumn()) $asig = $defaultOwner;

    // Ajusta a lo que la tabla `tickets` REALMENTE acepta (evita 1265).
    $tipo      = dbCoerce($pdo, 'tickets', 'tipo', $tipo, ($clase === 'miembro' ? 'OTRO' : 'TAREA'));
    $prioridad = dbCoerce($pdo, 'tickets', 'prioridad', $prioridad, 'MEDIA');
    $estado    = dbCoerce($pdo, 'tickets', 'estado', 'ABIERTO', 'ABIERTO');

    // ORIGEN: si lo crea Athena/Pilar, márcalo (fuente=ATHENA para filtrar +
    // etiqueta en la descripción que SIEMPRE se ve, pase lo que pase el ENUM).
    if ($IS_SVC) {
        $fuente = dbCoerce($pdo, 'tickets', 'fuente', 'ATHENA', 'CRM');
        if (stripos($desc, 'athena') === false) $desc = '[Athena] ' . $desc;
    } else {
        $fuente = dbCoerce($pdo, 'tickets', 'fuente', 'CRM', 'CRM');
    }

    $stmt = $pdo->prepare("
        INSERT INTO tickets (miembro_id, agente_id, asignado_a, tipo, prioridad, estado,
                             descripcion, fuente, fecha_creacion)
        VALUES (?,?,?,?,?,?,?,?,CURDATE())
    ");
    $stmt->execute([$mid, $uid, $asig, $tipo, $prioridad, $estado, $desc, $fuente]);
    $tid = (int)$pdo->lastInsertId();
    logActivity($pdo, $uid, $mid, 'TICKET', "Ticket #$tid [$clase/$tipo/$prioridad] → resp #$asig" . ($IS_SVC ? ' vía Athena' : ' vía LUNA'));
    // Aviso si un ticket sin cliente quedó SIN miembro catch-all: el CRM web
    // (que lista por cliente) probablemente no lo mostrará.
    $aviso = ($clase !== 'miembro' && !$mid)
        ? 'OJO: ticket sin cliente y sin miembro "OTRO" configurado → puede no verse en el CRM web. Crea un miembro "OTRO" o define LUNA_DEFAULT_TICKET_MEMBER.'
        : null;
    ok([
        'id'          => $tid,
        'clase'       => $clase,
        'miembro_id'  => $mid,
        'asignado_a'  => $asig,
        'fuente'      => $fuente,
        'aviso'       => $aviso,
        'message'     => "Ticket #$tid creado ($clase), asignado a #$asig.",
    ]);
    break;

// ── CLOSE TICKET ────────────────────────────────────────
case 'luna_close_ticket':
    requirePost();
    requireActor();
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
    requireActor();
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
    requireActor();
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
    requireActor();
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

    if (count($created) > 0) notifyAdmin($pdo, $uid, 'Creó ' . count($created) . ' ticket(s) de retención en lote vía LUNA');
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
    requireActor();
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
    requireActor();
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
    requireActor();
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

// ════════════════════════════════════════════════════════
// CAPA DE CONFIANZA — audit log + cola de outbound + review
// ════════════════════════════════════════════════════════

// ── AUDIT LOG — solo Isabel ──────────────────────────────
case 'luna_audit_view':
    requireAdmin();
    lunaAudit($pdo, $uid, 'READ', 'audit_view'); // asegura tabla + registra el acceso
    $limit  = max(10, min(200, (int)($_GET['limit'] ?? 100)));
    $action_f = strOrNull($_GET['action'] ?? '');
    $sql = "SELECT a.id, a.user_id, u.nombre AS usuario, a.action, a.detail, a.ip, a.created_at
            FROM luna_audit_log a LEFT JOIN usuarios u ON a.user_id = u.id";
    $params = [];
    if ($action_f) { $sql .= " WHERE a.action LIKE ?"; $params[] = '%'.$action_f.'%'; }
    $sql .= " ORDER BY a.id DESC LIMIT $limit";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    ok(['entries' => $stmt->fetchAll()]);
    break;

// ── ALERTAS — acciones sensibles + intentos denegados (solo Isabel) ──
// Feed para el "campanita" del Centro de Seguridad.
case 'luna_alerts_view':
    requireAdmin();
    ensureActorsTable($pdo);
    $limit = max(10, min(100, (int)($_GET['limit'] ?? 50)));
    $sql = "SELECT a.id, a.user_id, u.nombre AS usuario, a.action, a.detail, a.ip, a.created_at
            FROM luna_audit_log a LEFT JOIN usuarios u ON a.user_id = u.id
            WHERE a.action LIKE '%ALERTA%' OR a.action LIKE '%DENEGADO%'
            ORDER BY a.id DESC LIMIT $limit";
    $rows = $pdo->query($sql)->fetchAll();
    ok(['alerts' => $rows]);
    break;

// ── ACTORES — listar quién puede ordenar ACCIONES (solo Isabel) ──
case 'luna_actors_list':
    requireAdmin();
    ensureActorsTable($pdo);
    $auth  = authorizedActorIds($pdo);
    $users = $pdo->query("SELECT id, nombre, iniciales, rol FROM usuarios WHERE activo=1 ORDER BY rol DESC, nombre")->fetchAll();
    $out = [];
    foreach ($users as $u) {
        $isAdmin = ($u['rol'] === 'admin');
        $out[] = [
            'id'         => (int)$u['id'],
            'nombre'     => $u['nombre'],
            'iniciales'  => $u['iniciales'],
            'rol'        => $u['rol'],
            'is_admin'   => $isAdmin,
            'authorized' => in_array((int)$u['id'], $auth, true),
            'can_act'    => $isAdmin || in_array((int)$u['id'], $auth, true),
        ];
    }
    ok(['actors' => $out]);
    break;

// ── ACTORES — autorizar / revocar a un agente (solo Isabel) ──
case 'luna_actor_set':
    requirePost();
    requireAdmin();
    ensureActorsTable($pdo);
    $targetId = intOrNull($_POST['user_id'] ?? null);
    $allow    = (int)($_POST['allow'] ?? 0) === 1;
    if (!$targetId) err('Falta user_id.');
    $chk = $pdo->prepare("SELECT nombre, rol FROM usuarios WHERE id=? AND activo=1");
    $chk->execute([$targetId]);
    $tu = $chk->fetch();
    if (!$tu) err('Usuario no encontrado o inactivo.');
    if ($tu['rol'] === 'admin') err('Isabel (admin) ya puede ordenar acciones; no necesita estar en la lista.');
    if ($allow) {
        $pdo->prepare("INSERT IGNORE INTO luna_authorized_actors (user_id, added_by) VALUES (?,?)")
            ->execute([$targetId, $uid]);
        lunaAudit($pdo, $uid, 'ACTOR_ADD', "Autorizó a {$tu['nombre']} (#$targetId) a ordenar acciones a LUNA");
    } else {
        $pdo->prepare("DELETE FROM luna_authorized_actors WHERE user_id=?")->execute([$targetId]);
        lunaAudit($pdo, $uid, 'ACTOR_REMOVE', "Revocó el permiso de acciones a {$tu['nombre']} (#$targetId)");
    }
    ok(['user_id' => $targetId, 'authorized' => $allow]);
    break;

// ════════════════════════════════════════════════════════
// RADAR DE TENDENCIAS — investigación automática (web search)
// ════════════════════════════════════════════════════════

// ── RADAR: último reporte (lo ve todo el equipo) ─────────
case 'luna_radar_latest':
    require_once __DIR__ . '/luna_radar.php';
    $modo = strOrNull($_GET['modo'] ?? '');
    $run  = radarLatest($pdo, $modo);
    if (!$run) ok(['run' => null]);
    ok(['run' => [
        'id'         => (int)$run['id'],
        'modo'       => $run['modo'],
        'resumen'    => $run['resumen'],
        'item_count' => (int)$run['item_count'],
        'created_at' => $run['created_at'],
        'items'      => $run['items'],
    ]]);
    break;

// ── RADAR: correr ahora (solo Isabel) — tarda ~30-90s ────
case 'luna_radar_run':
    requirePost();
    requireAdmin();
    require_once __DIR__ . '/luna_radar.php';
    @set_time_limit(180);
    $modo = (($_POST['modo'] ?? '') === 'weekly') ? 'weekly' : 'daily';
    lunaAudit($pdo, $uid, 'RADAR_RUN', "Corrió el Radar de tendencias ($modo) manualmente");
    $run = radarRun($pdo, $modo);
    if (empty($run['ok'])) err('El radar no se pudo generar ahora (revisa ANTHROPIC_API_KEY o reintenta).', 502);
    ok(['run' => $run]);
    break;

// ════════════════════════════════════════════════════════
// JUNTA DE EQUIPO — notas, acuerdos y seguimiento (sábado)
// ════════════════════════════════════════════════════════

// ── JUNTA: lista de juntas + pendientes (todo el equipo) ──
case 'luna_meeting_list':
    require_once __DIR__ . '/luna_meetings.php';
    ok([
        'meetings'     => meetingList($pdo, intOrNull($_GET['limit'] ?? null) ?? 12),
        'open_actions' => meetingOpenActions($pdo),
    ]);
    break;

// ── JUNTA: registrar una junta con sus acuerdos/tareas ───
case 'luna_meeting_save':
    requirePost();
    requireActor();
    require_once __DIR__ . '/luna_meetings.php';
    $mDate   = strOrNull($_POST['meeting_date'] ?? null) ?? date('Y-m-d');
    $resumen = strOrNull($_POST['resumen'] ?? null);
    $actions = json_decode((string)($_POST['actions'] ?? '[]'), true);
    if (!is_array($actions)) $actions = [];
    if ($resumen === null && !$actions) err('Agrega al menos un acuerdo o una tarea.');
    $mid = meetingSave($pdo, $mDate, $resumen, $actions, $uid);
    lunaAudit($pdo, $uid, 'MEETING_SAVE', "Registró la junta del $mDate con " . count($actions) . " tareas");
    ok(['meeting_id' => $mid]);
    break;

// ── JUNTA: marcar una tarea hecha/pendiente/cancelada ────
case 'luna_meeting_action':
    requirePost();
    requireActor();
    require_once __DIR__ . '/luna_meetings.php';
    $aid    = intOrNull($_POST['action_id'] ?? null);
    $estado = strOrNull($_POST['estado'] ?? null) ?? 'hecho';
    if (!$aid) err('Falta action_id.');
    if (!meetingToggleAction($pdo, $aid, $estado)) err('No se pudo actualizar la tarea.');
    lunaAudit($pdo, $uid, 'MEETING_ACTION', "Tarea #$aid → $estado");
    ok(['action_id' => $aid, 'estado' => $estado]);
    break;

// ── REVIEW OUTBOUND — corre los hooks sin guardar nada ───
// Útil para el botón "copiar" de Estudio Creativo: revisa antes de mostrar.
case 'luna_review_outbound':
    requirePost();
    $body    = (string)($_POST['body'] ?? '');
    $subject = (string)($_POST['subject'] ?? '');
    if ($body === '') err('Falta body.');
    ok(reviewOutbound($body, $subject));
    break;

// ── OUTBOUND ENQUEUE — guarda un borrador (no envía) ─────
case 'luna_outbound_enqueue':
    requirePost();
    requireActor();
    ensureOutboundTable($pdo);
    $channel   = strtoupper(strOrNull($_POST['channel'] ?? 'EMAIL'));
    if (!in_array($channel, ['EMAIL','SMS','WHATSAPP'])) $channel = 'EMAIL';
    $miembroId = intOrNull($_POST['miembro_id'] ?? null);
    $recipient = strOrNull($_POST['recipient'] ?? '');
    $subject   = strOrNull($_POST['subject'] ?? '');
    $body      = strOrNull($_POST['body'] ?? '');
    if (!$body) err('Falta body del mensaje.');

    // Si no dan recipient pero sí miembro_id, lo resolvemos del CRM
    if (!$recipient && $miembroId) {
        $r = $pdo->prepare("SELECT email, telefono FROM miembros WHERE id=?");
        $r->execute([$miembroId]);
        $m = $r->fetch();
        if ($m) $recipient = ($channel === 'EMAIL') ? ($m['email'] ?? '') : ($m['telefono'] ?? '');
    }

    $review = reviewOutbound($body, (string)$subject);
    $pdo->prepare("INSERT INTO luna_outbound_queue
        (miembro_id, channel, recipient, subject, body, status, review_flags, created_by)
        VALUES (?,?,?,?,?,'DRAFT',?,?)")
        ->execute([$miembroId, $channel, $recipient, $subject, $body,
                   json_encode($review['flags'], JSON_UNESCAPED_UNICODE), $uid]);
    $id = (int)$pdo->lastInsertId();
    logActivity($pdo, $uid, $miembroId, 'OUTBOUND', "Borrador #$id encolado [$channel] vía LUNA");
    notifyAdmin($pdo, $uid, "Encoló un mensaje $channel para enviar a un miembro (borrador #$id, pendiente de tu aprobación)");
    ok(['id'=>$id, 'review'=>$review, 'status'=>'DRAFT']);
    break;

// ── OUTBOUND LIST ────────────────────────────────────────
case 'luna_outbound_list':
    ensureOutboundTable($pdo);
    $status = strtoupper(strOrNull($_GET['status'] ?? ''));
    $where = "1=1"; $params = [];
    if (in_array($status, ['DRAFT','APPROVED','SENT','REJECTED'])) { $where .= " AND q.status=?"; $params[]=$status; }
    if (!$admin) { $where .= " AND q.created_by=?"; $params[]=$uid; } // agentes ven solo lo suyo
    $sql = "SELECT q.id, q.miembro_id, q.channel, q.recipient, q.subject, q.body, q.status,
                   q.review_flags, q.created_by, q.approved_by, q.created_at, q.sent_at,
                   CONCAT(m.nombre,' ',m.apellido) AS miembro
            FROM luna_outbound_queue q LEFT JOIN miembros m ON q.miembro_id=m.id
            WHERE $where ORDER BY q.id DESC LIMIT 100";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    ok(['queue'=>$stmt->fetchAll()]);
    break;

// ── OUTBOUND APPROVE — solo Isabel; envía si es EMAIL ────
case 'luna_outbound_approve':
    requirePost();
    requireAdmin();
    ensureOutboundTable($pdo);
    $id    = intOrNull($_POST['id'] ?? null);
    $force = ($_POST['force'] ?? '') === '1';   // forzar envío en horas de silencio
    if (!$id) err('Falta id.');

    $stmt = $pdo->prepare("SELECT * FROM luna_outbound_queue WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) err('Borrador no encontrado.', 404);
    if ($row['status'] === 'SENT') err('Ya fue enviado.');

    // Re-evaluamos los hooks: si hay flag 'alto' no se aprueba sin override explícito.
    $review = reviewOutbound($row['body'], (string)$row['subject']);
    if ($review['blocked'] && !$force) {
        err('Bloqueado por compliance (flags ALTO). Revisa y reescribe, o usa force=1 si es intencional.', 422);
    }

    // Horas de silencio (no enviar de noche salvo force)
    if (withinQuietHours() && !$force) {
        $pdo->prepare("UPDATE luna_outbound_queue SET status='APPROVED', approved_by=? WHERE id=?")
            ->execute([$uid, $id]);
        logActivity($pdo, $uid, $row['miembro_id'], 'OUTBOUND', "Borrador #$id aprobado (en espera por horas de silencio)");
        ok(['status'=>'APPROVED','queued'=>true,'reason'=>'horas de silencio (9pm–7am); se enviará fuera de ese rango o usa force=1']);
    }

    // Cap diario de seguridad (anti-runaway)
    $sentToday = (int)$pdo->query("SELECT COUNT(*) FROM luna_outbound_queue WHERE status='SENT' AND DATE(sent_at)=CURDATE()")->fetchColumn();
    if ($sentToday >= 200 && !$force) err('Cap diario de envíos alcanzado (200). Usa force=1 para excepción.', 429);

    $sent = false;
    if ($row['channel'] === 'EMAIL' && $row['recipient']) {
        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: Isabel Fuentes · Medicare <isabel@withisabelfuentes.com>',
            'Reply-To: info@withisabelfuentes.com',
            'X-Mailer: LUNA-Outbound',
        ]);
        $sent = @mail($row['recipient'], $row['subject'] ?: 'Mensaje de Medicare with Isabel', $row['body'], $headers);
    }

    if ($sent) {
        $pdo->prepare("UPDATE luna_outbound_queue SET status='SENT', approved_by=?, sent_at=NOW() WHERE id=?")
            ->execute([$uid, $id]);
        logActivity($pdo, $uid, $row['miembro_id'], 'OUTBOUND', "Borrador #$id ENVIADO [{$row['channel']}] vía LUNA");
        ok(['status'=>'SENT']);
    } else {
        // SMS/WhatsApp o email sin mailer: queda APROBADO para envío manual/externo.
        $pdo->prepare("UPDATE luna_outbound_queue SET status='APPROVED', approved_by=? WHERE id=?")
            ->execute([$uid, $id]);
        logActivity($pdo, $uid, $row['miembro_id'], 'OUTBOUND', "Borrador #$id aprobado (envío {$row['channel']} manual/externo)");
        ok(['status'=>'APPROVED','sent'=>false,'note'=>'Aprobado. Canal sin envío automático en el servidor — enviar manualmente.']);
    }
    break;

// ── OUTBOUND REJECT — solo Isabel ────────────────────────
case 'luna_outbound_reject':
    requirePost();
    requireAdmin();
    ensureOutboundTable($pdo);
    $id = intOrNull($_POST['id'] ?? null);
    if (!$id) err('Falta id.');
    $pdo->prepare("UPDATE luna_outbound_queue SET status='REJECTED', approved_by=? WHERE id=? AND status!='SENT'")
        ->execute([$uid, $id]);
    logActivity($pdo, $uid, null, 'OUTBOUND', "Borrador #$id rechazado");
    ok(['status'=>'REJECTED']);
    break;

// ════════════════════════════════════════════════════════
// PLAN DE CONTENIDO — reemplaza PLAN_STORE (localStorage) del
// viejo "Sistema Maestro". Sincroniza el plan semanal en MySQL.
// ════════════════════════════════════════════════════════
function ensurePlanTable(PDO $pdo) {
    static $done = false; if ($done) return; $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS plan_contenido (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_key VARCHAR(80) NOT NULL,
        dia VARCHAR(20) DEFAULT NULL,
        tipo VARCHAR(20) DEFAULT 'post',
        texto VARCHAR(1000) DEFAULT NULL,
        done TINYINT(1) DEFAULT 0,
        owner_user_id INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_item (item_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

case 'luna_plan_get':
    ensurePlanTable($pdo);
    ok(['plan' => $pdo->query("SELECT item_key, dia, tipo, texto, done, updated_at
                               FROM plan_contenido ORDER BY dia, item_key")->fetchAll()]);
    break;

case 'luna_plan_set':
    requirePost();
    requireActor();
    ensurePlanTable($pdo);
    $itemKey = strOrNull($_POST['item_key'] ?? '');
    if (!$itemKey) err('Falta item_key.');
    $dia   = strOrNull($_POST['dia'] ?? '');
    $tipo  = strOrNull($_POST['tipo'] ?? 'post');
    $texto = strOrNull($_POST['texto'] ?? '');
    $done  = (int)(($_POST['done'] ?? '0') === '1' || ($_POST['done'] ?? '') === 'true');
    $pdo->prepare("INSERT INTO plan_contenido (item_key, dia, tipo, texto, done, owner_user_id)
                   VALUES (?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE dia=VALUES(dia), tipo=VALUES(tipo),
                       texto=VALUES(texto), done=VALUES(done), updated_at=NOW()")
        ->execute([$itemKey, $dia, $tipo, $texto, $done, $uid]);
    ok(['saved' => true, 'item_key' => $itemKey]);
    break;

// ════════════════════════════════════════════════════════
// MEMORIA POR CAPAS — entidades, señales, skills, gaps
// (roadmap #6–10). Tablas se autocrean la primera vez.
// ════════════════════════════════════════════════════════

function ensureEntityTable(PDO $pdo) {
    static $d=false; if($d) return; $d=true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS luna_entidades (
        id INT AUTO_INCREMENT PRIMARY KEY,
        canonical VARCHAR(160) NOT NULL,
        tipo VARCHAR(20) DEFAULT 'persona',
        aliases TEXT DEFAULT NULL,
        miembro_id INT DEFAULT NULL,
        salience INT DEFAULT 1,
        notas TEXT DEFAULT NULL,
        last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_canon (canonical),
        INDEX idx_salience (salience), INDEX idx_miembro (miembro_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function ensureSignalTable(PDO $pdo) {
    static $d=false; if($d) return; $d=true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS luna_senales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        skey VARCHAR(80) DEFAULT NULL,
        tipo VARCHAR(20) DEFAULT 'state',
        severity VARCHAR(10) DEFAULT 'medium',
        titulo VARCHAR(200) NOT NULL,
        detalle TEXT DEFAULT NULL,
        valor INT DEFAULT NULL,
        status VARCHAR(12) DEFAULT 'open',
        auto TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_skey (skey),
        INDEX idx_status (status), INDEX idx_sev (severity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function ensureSkillTable(PDO $pdo) {
    static $d=false; if($d) return; $d=true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS luna_skills (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(60) NOT NULL,
        nombre VARCHAR(120) NOT NULL,
        descripcion TEXT DEFAULT NULL,
        pasos TEXT DEFAULT NULL,
        aprobado TINYINT(1) DEFAULT 0,
        created_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Recalcula señales desde el CRM. Reemplaza las señales auto-abiertas.
// Reutilizable por el cron nocturno (luna_signals_cron.php).
function computeSignals(PDO $pdo) {
    ensureSignalTable($pdo);
    $sig = []; // [skey => [tipo, severity, titulo, detalle, valor]]

    $hotCold = (int)$pdo->query("SELECT COUNT(*) FROM miembros m WHERE m.estado='HOT LEAD'
        AND DATEDIFF(CURDATE(), COALESCE((SELECT MAX(DATE(a.fecha_hora)) FROM actividad a WHERE a.miembro_id=m.id), m.created_at)) >= 3")->fetchColumn();
    if ($hotCold > 0) $sig['hot_cold'] = ['pattern','critical',"$hotCold hot leads sin contacto +3 días",'Riesgo de perder leads calificados.',$hotCold];

    $ret = (int)$pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='ACTIVO' AND fecha_efectiva IN
        (DATE_SUB(CURDATE(),INTERVAL 7 DAY),DATE_SUB(CURDATE(),INTERVAL 30 DAY),DATE_SUB(CURDATE(),INTERVAL 60 DAY),DATE_SUB(CURDATE(),INTERVAL 90 DAY))")->fetchColumn();
    if ($ret > 0) $sig['retencion_hoy'] = ['calendar','critical',"$ret miembro(s) para llamada de retención HOY",'Day 7/30/60/90 — Samia ejecuta.',$ret];

    $soa = (int)$pdo->query("SELECT COUNT(*) FROM miembros m WHERE m.estado IN('ACTIVO','PENDIENTE')
        AND (SELECT COUNT(*) FROM soa s WHERE s.miembro_id=m.id AND s.estado='FIRMADO')=0")->fetchColumn();
    if ($soa >= 3) $sig['soa_riesgo'] = ['threshold','critical',"$soa miembros activos SIN SOA firmado",'Riesgo de auditoría CMS.',$soa];

    $t65 = (int)$pdo->query("SELECT COUNT(*) FROM miembros WHERE estado!='ACTIVO'
        AND DATE_ADD(dob,INTERVAL 65 YEAR) BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)")->fetchColumn();
    if ($t65 > 0) $sig['t65_urgente'] = ['calendar','high',"$t65 T65 cumplen 65 en <30 días",'Ventana IEP cerrándose.',$t65];

    $cb = (int)$pdo->query("SELECT COUNT(*) FROM llamadas_perdidas WHERE estado='PENDIENTE'")->fetchColumn();
    if ($cb >= 2) $sig['callbacks'] = ['state','medium',"$cb llamadas perdidas sin devolver",'Regla de 60 minutos en riesgo.',$cb];

    // Calendar: cuenta regresiva al AEP (Oct 15)
    $today = new DateTime('now', new DateTimeZone('America/Los_Angeles'));
    $aep = new DateTime($today->format('Y') . '-10-15', new DateTimeZone('America/Los_Angeles'));
    if ($today > $aep) $aep->modify('+1 year');
    $dToAep = (int)$today->diff($aep)->days;
    if ($dToAep <= 45 && $dToAep >= 0) $sig['aep_proximo'] = ['calendar','high',"AEP en $dToAep días",'Prepara revisiones anuales con clientes activos.',$dToAep];

    // Reemplaza señales auto-abiertas con el set fresco
    $pdo->exec("DELETE FROM luna_senales WHERE auto=1");
    $ins = $pdo->prepare("INSERT INTO luna_senales (skey,tipo,severity,titulo,detalle,valor,status,auto)
                          VALUES (?,?,?,?,?,?, 'open', 1)
                          ON DUPLICATE KEY UPDATE tipo=VALUES(tipo),severity=VALUES(severity),
                              titulo=VALUES(titulo),detalle=VALUES(detalle),valor=VALUES(valor),status='open'");
    foreach ($sig as $k => $v) $ins->execute([$k, $v[0], $v[1], $v[2], $v[3], $v[4]]);
    return count($sig);
}

// ── ENTIDADES ────────────────────────────────────────────
case 'luna_entity_upsert':
    requirePost();
    requireActor();
    ensureEntityTable($pdo);
    $name = strOrNull($_POST['name'] ?? '');
    if (!$name) err('Falta name.');
    $tipo  = strtolower(strOrNull($_POST['tipo'] ?? 'persona'));
    if (!in_array($tipo, ['persona','doctor','clinica','org','lugar','otro'])) $tipo = 'otro';
    $alias = strOrNull($_POST['alias'] ?? '');
    $nota  = strOrNull($_POST['nota'] ?? '');
    $miembroId = intOrNull($_POST['miembro_id'] ?? null);
    $needle = mb_strtolower($name);

    // Resolver: por canonical o por alias (case-insensitive)
    $found = null;
    foreach ($pdo->query("SELECT * FROM luna_entidades")->fetchAll() as $e) {
        $al = json_decode($e['aliases'] ?? '[]', true) ?: [];
        $al = array_map('mb_strtolower', array_map('strval', $al));
        if (mb_strtolower($e['canonical']) === $needle || in_array($needle, $al, true)) { $found = $e; break; }
    }

    if ($found) {
        $al = json_decode($found['aliases'] ?? '[]', true) ?: [];
        if ($alias && !in_array($alias, $al, true)) $al[] = $alias;
        $notas = trim(($found['notas'] ? $found['notas']."\n" : '') . ($nota ? '['.date('d M').'] '.$nota : ''));
        $pdo->prepare("UPDATE luna_entidades SET salience=salience+1, last_seen=NOW(),
                       aliases=?, notas=?, miembro_id=COALESCE(?, miembro_id), tipo=? WHERE id=?")
            ->execute([json_encode(array_values($al), JSON_UNESCAPED_UNICODE), mb_substr($notas,0,4000), $miembroId, $tipo, $found['id']]);
        ok(['id'=>(int)$found['id'], 'resolved'=>true, 'canonical'=>$found['canonical']]);
    } else {
        $al = $alias ? [$alias] : [];
        $pdo->prepare("INSERT INTO luna_entidades (canonical, tipo, aliases, miembro_id, notas, created_by)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$name, $tipo, json_encode($al, JSON_UNESCAPED_UNICODE), $miembroId,
                       $nota ? '['.date('d M').'] '.$nota : null, $uid]);
        ok(['id'=>(int)$pdo->lastInsertId(), 'resolved'=>false, 'canonical'=>$name]);
    }
    break;

case 'luna_entity_search':
    ensureEntityTable($pdo);
    $q = mb_strtolower(trim($_GET['q'] ?? ''));
    $rows = $pdo->query("SELECT id, canonical, tipo, aliases, miembro_id, salience, notas, last_seen
                         FROM luna_entidades ORDER BY salience DESC LIMIT 200")->fetchAll();
    if ($q !== '') {
        $rows = array_values(array_filter($rows, function($e) use ($q) {
            $al = json_decode($e['aliases'] ?? '[]', true) ?: [];
            $hay = mb_strtolower($e['canonical'].' '.implode(' ',$al).' '.($e['notas']??''));
            return strpos($hay, $q) !== false;
        }));
    }
    ok(['entities' => array_slice($rows, 0, 30)]);
    break;

// ── SEÑALES ──────────────────────────────────────────────
case 'luna_signals_list':
    ensureSignalTable($pdo);
    $rows = $pdo->query("SELECT id, skey, tipo, severity, titulo, detalle, valor, created_at
                         FROM luna_senales WHERE status='open'
                         ORDER BY FIELD(severity,'critical','high','medium','low'), valor DESC")->fetchAll();
    ok(['signals' => $rows]);
    break;

case 'luna_signals_compute':
    requireAdmin();
    $n = computeSignals($pdo);
    lunaAudit($pdo, $uid, 'COMPUTE', "signals=$n");
    ok(['computed' => $n]);
    break;

case 'luna_signal_dismiss':
    requirePost();
    requireAdmin();
    ensureSignalTable($pdo);
    $id = intOrNull($_POST['id'] ?? null);
    $skey = strOrNull($_POST['skey'] ?? '');
    if ($id)        $pdo->prepare("UPDATE luna_senales SET status='dismissed' WHERE id=?")->execute([$id]);
    elseif ($skey)  $pdo->prepare("UPDATE luna_senales SET status='dismissed' WHERE skey=?")->execute([$skey]);
    else            err('Falta id o skey.');
    ok(['dismissed'=>true]);
    break;

// ── SKILLS (playbooks aprobados) ─────────────────────────
case 'luna_skill_list':
    ensureSkillTable($pdo);
    $onlyApproved = ($_GET['approved'] ?? '') === '1';
    $sql = "SELECT id, slug, nombre, descripcion, pasos, aprobado, updated_at FROM luna_skills";
    if ($onlyApproved) $sql .= " WHERE aprobado=1";
    $sql .= " ORDER BY nombre";
    ok(['skills' => $pdo->query($sql)->fetchAll()]);
    break;

case 'luna_skill_save':
    requirePost();
    requireAdmin();   // solo Isabel aprueba/edita playbooks
    ensureSkillTable($pdo);
    $slug = strtolower(preg_replace('/[^a-z0-9_\-]/i','', (string)($_POST['slug'] ?? '')));
    $nombre = strOrNull($_POST['nombre'] ?? '');
    if (!$slug || !$nombre) err('Faltan slug o nombre.');
    $desc  = strOrNull($_POST['descripcion'] ?? '');
    $pasos = strOrNull($_POST['pasos'] ?? '');
    $aprob = (int)(($_POST['aprobado'] ?? '0') === '1' || ($_POST['aprobado'] ?? '') === 'true');
    $pdo->prepare("INSERT INTO luna_skills (slug,nombre,descripcion,pasos,aprobado,created_by)
                   VALUES (?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),descripcion=VALUES(descripcion),
                       pasos=VALUES(pasos),aprobado=VALUES(aprobado),updated_at=NOW()")
        ->execute([$slug,$nombre,$desc,$pasos,$aprob,$uid]);
    ok(['saved'=>true, 'slug'=>$slug]);
    break;

// ── GAPS OVERVIEW — qué le falta a los datos del CRM ─────
case 'luna_gaps_overview':
    $gaps = [];
    $gaps['activos_sin_email'] = (int)$pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='ACTIVO' AND (email IS NULL OR email='')")->fetchColumn();
    $gaps['activos_sin_telefono'] = (int)$pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='ACTIVO' AND (telefono IS NULL OR telefono='')")->fetchColumn();
    $gaps['activos_sin_dob'] = (int)$pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='ACTIVO' AND dob IS NULL")->fetchColumn();
    $gaps['leads_sin_fuente'] = (int)$pdo->query("SELECT COUNT(*) FROM miembros WHERE estado IN('PROSPECTO','HOT LEAD','T65','FOLLOW-UP') AND (fuente IS NULL OR fuente='')")->fetchColumn();
    $gaps['activos_sin_carrier'] = (int)$pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='ACTIVO' AND (carrier IS NULL OR carrier='')")->fetchColumn();
    $gaps['activos_sin_soa'] = (int)$pdo->query("SELECT COUNT(*) FROM miembros m WHERE m.estado='ACTIVO' AND (SELECT COUNT(*) FROM soa s WHERE s.miembro_id=m.id AND s.estado='FIRMADO')=0")->fetchColumn();
    $total = array_sum($gaps);
    ok(['gaps' => $gaps, 'total' => $total]);
    break;

// ── STRUCTURAL AUDIT — errores de integridad en la base (#17) ──
// Distinto del compliance cron (riesgo CMS): esto busca DATOS ROTOS.
// Cada check va en try/catch para tolerar diferencias de esquema.
case 'luna_structural_audit':
    requireAdmin();
    $findings = [];
    $add = function($sev, $titulo, $detalle, $rows) use (&$findings) {
        $n = is_array($rows) ? count($rows) : (int)$rows;
        if ($n > 0) $findings[] = ['sev'=>$sev, 'titulo'=>$titulo, 'detalle'=>$detalle,
                                   'count'=>$n, 'sample'=>is_array($rows) ? array_slice($rows,0,5) : []];
    };
    $try = function($fn) { try { return $fn(); } catch (Exception $e) { return null; } };

    // 1. Teléfonos duplicados
    $r = $try(fn() => $pdo->query("SELECT telefono, COUNT(*) c, GROUP_CONCAT(id) ids
        FROM miembros WHERE telefono IS NOT NULL AND telefono!='' GROUP BY telefono HAVING c>1 LIMIT 50")->fetchAll());
    if ($r !== null) $add('alto','Teléfonos duplicados','Mismo teléfono en varios miembros — posible duplicado.',
        array_map(fn($x)=>"tel {$x['telefono']} → ids {$x['ids']}", $r));

    // 2. Emails duplicados
    $r = $try(fn() => $pdo->query("SELECT email, COUNT(*) c, GROUP_CONCAT(id) ids
        FROM miembros WHERE email IS NOT NULL AND email!='' GROUP BY email HAVING c>1 LIMIT 50")->fetchAll());
    if ($r !== null) $add('medio','Emails duplicados','Mismo email en varios miembros.',
        array_map(fn($x)=>"{$x['email']} → ids {$x['ids']}", $r));

    // 3. MBI duplicados
    $r = $try(fn() => $pdo->query("SELECT mbi, COUNT(*) c, GROUP_CONCAT(id) ids
        FROM miembros WHERE mbi IS NOT NULL AND mbi!='' GROUP BY mbi HAVING c>1 LIMIT 50")->fetchAll());
    if ($r !== null) $add('alto','MBI duplicados','Mismo MBI en varios registros — error grave de datos.',
        array_map(fn($x)=>"MBI {$x['mbi']} → ids {$x['ids']}", $r));

    // 4. ACTIVO sin póliza registrada
    $r = $try(fn() => $pdo->query("SELECT m.id, m.nombre, m.apellido FROM miembros m
        WHERE m.estado='ACTIVO' AND NOT EXISTS (SELECT 1 FROM polizas p WHERE p.miembro_id=m.id) LIMIT 50")->fetchAll());
    if ($r !== null) $add('alto','Activos sin póliza','Miembro ACTIVO sin ninguna póliza registrada.',
        array_map(fn($x)=>"#{$x['id']} {$x['nombre']} {$x['apellido']}", $r));

    // 5. ACTIVO sin forma de contacto (ni tel ni email)
    $r = $try(fn() => $pdo->query("SELECT id, nombre, apellido FROM miembros
        WHERE estado='ACTIVO' AND (telefono IS NULL OR telefono='') AND (email IS NULL OR email='') LIMIT 50")->fetchAll());
    if ($r !== null) $add('alto','Activos no contactables','ACTIVO sin teléfono ni email.',
        array_map(fn($x)=>"#{$x['id']} {$x['nombre']} {$x['apellido']}", $r));

    // 6. ACTIVO sin carrier
    $r = $try(fn() => $pdo->query("SELECT id, nombre, apellido FROM miembros
        WHERE estado='ACTIVO' AND (carrier IS NULL OR carrier='') LIMIT 50")->fetchAll());
    if ($r !== null) $add('medio','Activos sin carrier','ACTIVO sin carrier asignado.',
        array_map(fn($x)=>"#{$x['id']} {$x['nombre']} {$x['apellido']}", $r));

    // 7. Citas huérfanas (miembro_id que no existe)
    $r = $try(fn() => $pdo->query("SELECT c.id FROM citas c LEFT JOIN miembros m ON c.miembro_id=m.id
        WHERE c.miembro_id IS NOT NULL AND m.id IS NULL LIMIT 50")->fetchAll());
    if ($r !== null) $add('medio','Citas huérfanas','Citas que apuntan a un miembro inexistente.',
        array_map(fn($x)=>"cita #{$x['id']}", $r));

    // 8. Tickets huérfanos
    $r = $try(fn() => $pdo->query("SELECT t.id FROM tickets t LEFT JOIN miembros m ON t.miembro_id=m.id
        WHERE t.miembro_id IS NOT NULL AND m.id IS NULL LIMIT 50")->fetchAll());
    if ($r !== null) $add('bajo','Tickets huérfanos','Tickets que apuntan a un miembro inexistente.',
        array_map(fn($x)=>"ticket #{$x['id']}", $r));

    // 9. DOB inválido / futuro
    $r = $try(fn() => $pdo->query("SELECT id, nombre, apellido, dob FROM miembros
        WHERE dob IS NOT NULL AND (dob > CURDATE() OR dob < '1900-01-01') LIMIT 50")->fetchAll());
    if ($r !== null) $add('medio','Fechas de nacimiento inválidas','DOB en el futuro o anterior a 1900.',
        array_map(fn($x)=>"#{$x['id']} {$x['nombre']} {$x['apellido']} ({$x['dob']})", $r));

    $order = ['alto'=>0,'medio'=>1,'bajo'=>2];
    usort($findings, fn($a,$b) => ($order[$a['sev']]??9) <=> ($order[$b['sev']]??9));
    lunaAudit($pdo, $uid, 'AUDIT', 'structural_audit findings=' . count($findings));
    ok(['findings'=>$findings, 'total'=>count($findings)]);
    break;

// ── SELF-TEST — verifica que todo quedó bien instalado (#deploy) ──
// Admin abre luna_api.php?action=luna_selftest tras desplegar.
// Crea/verifica tablas nuevas y hace una lectura trivial de cada feature.
case 'luna_selftest':
    requireAdmin();
    $checks = [];
    $check = function($name, $fn) use (&$checks) {
        try { $fn(); $checks[$name] = 'OK'; }
        catch (Exception $e) { $checks[$name] = 'FALLÓ: ' . $e->getMessage(); }
    };

    // Tablas nuevas se autocrean y se leen
    $check('audit_log',        function() use ($pdo,$uid){ lunaAudit($pdo,$uid,'SELFTEST','ping'); $pdo->query("SELECT 1 FROM luna_audit_log LIMIT 1"); });
    $check('outbound_queue',   function() use ($pdo){ ensureOutboundTable($pdo); $pdo->query("SELECT 1 FROM luna_outbound_queue LIMIT 1"); });
    $check('plan_contenido',   function() use ($pdo){ ensurePlanTable($pdo); $pdo->query("SELECT 1 FROM plan_contenido LIMIT 1"); });
    $check('entidades',        function() use ($pdo){ ensureEntityTable($pdo); $pdo->query("SELECT 1 FROM luna_entidades LIMIT 1"); });
    $check('senales',          function() use ($pdo){ ensureSignalTable($pdo); $pdo->query("SELECT 1 FROM luna_senales LIMIT 1"); });
    $check('skills',           function() use ($pdo){ ensureSkillTable($pdo); $pdo->query("SELECT 1 FROM luna_skills LIMIT 1"); });
    // Lecturas base contra el CRM real
    $check('crm_miembros',     function() use ($pdo){ $pdo->query("SELECT COUNT(*) FROM miembros"); });
    $check('crm_soa',          function() use ($pdo){ $pdo->query("SELECT COUNT(*) FROM soa LIMIT 1"); });
    $check('compute_signals',  function() use ($pdo){ computeSignals($pdo); });
    $check('gaps',             function() use ($pdo){ $pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='ACTIVO'"); });

    // API key del chat (no la revelamos, solo si existe)
    $hasKey = (bool)(getenv('ANTHROPIC_API_KEY') ?: (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ''));
    $checks['anthropic_api_key'] = $hasKey ? 'OK (configurada)' : 'FALTA — el chat no funcionará';

    $allOk = !in_array(false, array_map(fn($v) => strpos($v,'FALL')===false && strpos($v,'FALTA')===false, $checks), true);
    ok(['all_ok'=>$allOk, 'checks'=>$checks, 'version'=>'trust+memory+ads+web']);
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
} catch (\Throwable $e) {
    error_log('[luna_api] Throwable: ' . $e->getMessage());
    err((isset($pdo) && $pdo === null)
        ? 'La base de datos de LUNA no está conectada (faltan credenciales reales en luna_config.php).'
        : 'Error inesperado: ' . $e->getMessage(), 500);
}
