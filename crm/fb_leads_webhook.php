<?php
/**
 * ============================================================
 *  FACEBOOK LEADS → CRM  |  Medicare with Isabel
 * ============================================================
 *  Coloca este archivo en la misma carpeta que index.php y config.php
 *
 *  CONFIGURACIÓN:
 *    Cambia WEBHOOK_SECRET por una clave larga — la misma que
 *    usas en google_apps_script.js
 * ============================================================
 */

require_once __DIR__ . '/config.php';
// El secreto del webhook vive en config.php (constante WEBHOOK_SECRET_FB,
// fuera de Git). Antes se leía solo getenv(), que en cPanel suele venir
// vacío → el webhook aceptaba un secreto VACÍO. Ahora: constante primero,
// y si no hay secreto configurado, el webhook se NIEGA a operar.
if (!defined('WEBHOOK_SECRET')) {
    $ws = (defined('WEBHOOK_SECRET_FB') ? WEBHOOK_SECRET_FB : '') ?: (getenv('WEBHOOK_SECRET_FB') ?: '');
    define('WEBHOOK_SECRET', $ws);
}

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']); exit;
}

// ─── Autenticación ───────────────────────────────────────────────────────────
if (WEBHOOK_SECRET === '' || WEBHOOK_SECRET === 'CAMBIA_ESTE_SECRETO') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Webhook sin secreto configurado (WEBHOOK_SECRET_FB en config.php)']); exit;
}
$raw       = file_get_contents('php://input');
$json_body = json_decode($raw, true);
$secret    = $_SERVER['HTTP_X_WEBHOOK_SECRET']
          ?? ($_POST['secret']         ?? '')
          ?: ($json_body['secret']     ?? '');

if (!is_string($secret) || !hash_equals(WEBHOOK_SECRET, $secret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']); exit;
}

require_once __DIR__ . '/config.php';
try { $pdo = db(); }
catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB no disponible']); exit;
}

// ─── Crear tablas auxiliares si no existen ───────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fb_leads_log (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        lead_id     VARCHAR(100)  DEFAULT NULL,
        nombre      VARCHAR(200),
        telefono    VARCHAR(50),
        email       VARCHAR(200),
        campana     VARCHAR(200),
        agente_id   INT,
        miembro_id  INT,
        estado      ENUM('OK','DUPLICADO','ERROR') DEFAULT 'OK',
        mensaje     TEXT,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lead_id (lead_id),
        INDEX idx_created (created_at)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fb_leads_turno (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        agente_id  INT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Columnas extra en miembros si no existen
    foreach ([
        'email'          => "VARCHAR(200) DEFAULT NULL AFTER telefono",
        'fuente_campana' => "VARCHAR(255) DEFAULT NULL AFTER fuente",
    ] as $col => $def) {
        if (!$pdo->query("SHOW COLUMNS FROM miembros LIKE '$col'")->fetch()) {
            $pdo->exec("ALTER TABLE miembros ADD COLUMN $col $def");
        }
    }
} catch (Exception $e) {}

// ─── Parsear datos ───────────────────────────────────────────────────────────
$data = is_array($json_body) ? $json_body : $_POST;

$lead_id               = trim($data['lead_id']               ?? '');
$nombre_raw            = trim($data['nombre']                ?? $data['full_name'] ?? '');
$email                 = strtolower(trim($data['email']      ?? ''));
$campana               = trim($data['campana']               ?? '');
$formulario            = trim($data['formulario']            ?? '');
$idioma                = strtoupper(trim($data['idioma']     ?? 'ESP'));
$notas_raw             = trim($data['notas']                 ?? '');
$agente_nombre_sugerido= strtoupper(trim($data['agente_nombre_sugerido'] ?? ''));

// Limpiar teléfono — Facebook lo manda con prefijo "p:"
$telefono = trim($data['telefono'] ?? $data['phone_number'] ?? '');
$telefono = preg_replace('/^p:/i', '', $telefono);
$telefono = preg_replace('/[^\d+\-() ]/', '', $telefono);

// ─── Ignorar leads de prueba de Facebook ────────────────────────────────────
if (stripos($nombre_raw, '<test lead') !== false || stripos($telefono, '<test lead') !== false) {
    echo json_encode(['ok' => true, 'test_lead' => true, 'message' => 'Lead de prueba ignorado']); exit;
}

// ─── Validación mínima ───────────────────────────────────────────────────────
if (empty($nombre_raw) && empty($telefono)) {
    log_lead($pdo, $lead_id, '-', '', $email, $campana, 0, 0, 'ERROR', 'Lead sin nombre ni teléfono');
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Lead incompleto']); exit;
}

// ─── Separar nombre y apellido ───────────────────────────────────────────────
$partes   = explode(' ', $nombre_raw, 2);
$nombre   = strtoupper(trim($partes[0] ?? ''));
$apellido = strtoupper(trim($partes[1] ?? ''));

// ─── Verificar duplicado por teléfono ────────────────────────────────────────
if (!empty($telefono)) {
    $dup = $pdo->prepare("SELECT id FROM miembros WHERE telefono = ? LIMIT 1");
    $dup->execute([$telefono]);
    if ($row = $dup->fetch()) {
        log_lead($pdo, $lead_id, $nombre_raw, $telefono, $email, $campana, 0, $row['id'], 'DUPLICADO', 'Teléfono ya existe');
        echo json_encode(['ok' => true, 'duplicado' => true, 'miembro_id' => $row['id']]); exit;
    }
}

// ─── Verificar duplicado por lead_id ─────────────────────────────────────────
if (!empty($lead_id)) {
    $dup2 = $pdo->prepare("SELECT miembro_id FROM fb_leads_log WHERE lead_id = ? AND estado != 'ERROR' LIMIT 1");
    $dup2->execute([$lead_id]);
    if ($row2 = $dup2->fetch()) {
        echo json_encode(['ok' => true, 'duplicado' => true, 'miembro_id' => $row2['miembro_id']]); exit;
    }
}

// ─── Asignar agente ──────────────────────────────────────────────────────────
// Prioridad 1: agente sugerido desde el Sheet (SKARLETH, ARLETTE, SAMIA...)
// Prioridad 2: round-robin automático
$agente_id = 0;
if (!empty($agente_nombre_sugerido)) {
    $agente_id = buscar_agente_por_nombre($pdo, $agente_nombre_sugerido);
}
if (!$agente_id) {
    $agente_id = siguiente_agente($pdo);
}

if (!$agente_id) {
    log_lead($pdo, $lead_id, $nombre_raw, $telefono, $email, $campana, 0, 0, 'ERROR', 'Sin agentes disponibles');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No hay agentes disponibles']); exit;
}

// ─── Construir notas ─────────────────────────────────────────────────────────
$notas_parts = ["📣 CAMPAÑA: $campana"];
if ($formulario)  $notas_parts[] = "📋 FORMULARIO: $formulario";
if ($notas_raw)   $notas_parts[] = $notas_raw;
$notas_parts[] = "🤖 Importado automáticamente desde Facebook Ads — " . date('d/m/Y H:i');
$notas_final = implode("\n", array_filter($notas_parts));

// ─── Insertar miembro ────────────────────────────────────────────────────────
try {
    $ins = $pdo->prepare("
        INSERT INTO miembros
            (nombre, apellido, telefono, email, idioma, estado, fuente, fuente_campana,
             agente_id, created_by, notas)
        VALUES (?, ?, ?, ?, ?, 'PROSPECT', 'FB ADS', ?, ?, ?, ?)
    ");
    $ins->execute([
        $nombre, $apellido, $telefono,
        $email ?: null, $idioma,
        $campana ?: null,
        $agente_id, $agente_id,
        $notas_final,
    ]);
    $miembro_id = (int)$pdo->lastInsertId();
} catch (Exception $e) {
    log_lead($pdo, $lead_id, $nombre_raw, $telefono, $email, $campana, $agente_id, 0, 'ERROR', $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
}

// ─── Crear ticket FOLLOW UP ───────────────────────────────────────────────────
$ticket_id = 0;
try {
    $desc = "🔵 LEAD DE FACEBOOK ADS\n"
          . "👤 $nombre $apellido\n"
          . ($telefono ? "📞 $telefono\n" : '')
          . ($email    ? "📧 $email\n"    : '')
          . ($campana  ? "📣 Campaña: $campana\n" : '')
          . "\n$notas_raw\n"
          . "\n⚡ Contactar lo antes posible.";

    $ins_tkt = $pdo->prepare("
        INSERT INTO tickets
            (miembro_id, agente_id, asignado_a, tipo, prioridad, estado,
             descripcion, fuente, fecha_creacion, sla_fecha)
        VALUES (?, ?, ?, 'FOLLOW UP', 'ALTA', 'ABIERTO', ?, 'FB ADS', NOW(), CURDATE())
    ");
    $ins_tkt->execute([$miembro_id, $agente_id, $agente_id, $desc]);
    $ticket_id = (int)$pdo->lastInsertId();
} catch (Exception $e) {}

// ─── Notificación interna ────────────────────────────────────────────────────
try {
    $msg = "🔵 NUEVO LEAD FB: $nombre $apellido"
         . ($telefono ? " — $telefono" : '')
         . ($campana  ? " ($campana)"  : '');
    $pdo->prepare("INSERT INTO notificaciones (user_id, tipo, mensaje) VALUES (?, 'LEAD', ?)")
        ->execute([$agente_id, $msg]);
} catch (Exception $e) {}

// ─── Log ────────────────────────────────────────────────────────────────────
log_lead($pdo, $lead_id, $nombre_raw, $telefono, $email, $campana, $agente_id, $miembro_id, 'OK', '');

// ─── Nombre del agente para la respuesta ─────────────────────────────────────
$agente_nombre = '';
try {
    $qa = $pdo->prepare("SELECT nombre FROM usuarios WHERE id = ?");
    $qa->execute([$agente_id]);
    $agente_nombre = $qa->fetchColumn() ?: '';
} catch (Exception $e) {}

echo json_encode([
    'ok'            => true,
    'miembro_id'    => $miembro_id,
    'ticket_id'     => $ticket_id,
    'agente_id'     => $agente_id,
    'agente_nombre' => $agente_nombre,
    'message'       => "Lead '$nombre $apellido' creado y asignado a $agente_nombre",
]);

// ════════════════════════════════════════════════════════════════════════════
//  FUNCIONES AUXILIARES
// ════════════════════════════════════════════════════════════════════════════

/**
 * Busca un agente por nombre (o parte del nombre).
 * Sirve para respetar las asignaciones manuales del Sheet (SKARLETH, ARLETTE, SAMIA...).
 */
function buscar_agente_por_nombre(PDO $pdo, string $nombre): int
{
    if (empty($nombre)) return 0;
    // Buscar coincidencia exacta primero
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE activo = 1 AND UPPER(nombre) LIKE ? LIMIT 1");
    $stmt->execute(['%' . $nombre . '%']);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : 0;
}

/**
 * Round-robin: devuelve el siguiente agente activo en turno rotativo.
 */
function siguiente_agente(PDO $pdo): int
{
    $agentes = $pdo->query(
        "SELECT id FROM usuarios WHERE activo = 1 AND rol IN ('agent','admin') ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (empty($agentes)) return 0;
    if (count($agentes) === 1) return (int)$agentes[0];

    $row    = $pdo->query("SELECT agente_id FROM fb_leads_turno ORDER BY updated_at DESC LIMIT 1")->fetch();
    $ultimo = $row ? (int)$row['agente_id'] : 0;
    $pos    = array_search($ultimo, $agentes);
    $siguiente = ($pos === false)
        ? $agentes[0]
        : $agentes[($pos + 1) % count($agentes)];

    $existe = (int)$pdo->query("SELECT COUNT(*) FROM fb_leads_turno")->fetchColumn();
    if ($existe) {
        $pdo->prepare("UPDATE fb_leads_turno SET agente_id = ?, updated_at = NOW() ORDER BY id LIMIT 1")
            ->execute([(int)$siguiente]);
    } else {
        $pdo->prepare("INSERT INTO fb_leads_turno (agente_id) VALUES (?)")
            ->execute([(int)$siguiente]);
    }
    return (int)$siguiente;
}

/**
 * Registra el intento en fb_leads_log.
 */
function log_lead(PDO $pdo, string $lead_id, string $nombre, string $telefono,
                  string $email, string $campana, int $agente_id, int $miembro_id,
                  string $estado, string $mensaje): void
{
    try {
        $pdo->prepare("
            INSERT INTO fb_leads_log
                (lead_id, nombre, telefono, email, campana, agente_id, miembro_id, estado, mensaje)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $lead_id    ?: null,
            mb_substr($nombre,   0, 200),
            mb_substr($telefono, 0, 50),
            mb_substr($email,    0, 200),
            mb_substr($campana,  0, 200),
            $agente_id  ?: null,
            $miembro_id ?: null,
            $estado,
            mb_substr($mensaje,  0, 500),
        ]);
    } catch (Exception $e) {}
}
