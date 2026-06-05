<?php
/* ════════════════════════════════════════════════════════════════
   LUNA REFERRAL SYSTEM — Programa de Referidos Automatizado
   Medicare with Isabel

   USO:
   - Cron: 0 9 * * 3 php /path/to/luna_referral_cron.php
   - Cada miércoles a las 9:00 AM

   LO QUE HACE:
   1. Detecta miembros listos para pedir referido (candidatos)
   2. Genera mensaje personalizado para cada uno
   3. Manda lista a Samia por Telegram con botones de acción
   4. Trackea en CRM quién fue contactado y cuándo
   5. Reporta a Isabel el estado del programa

   CANDIDATOS IDEALES:
   - Miembros activos Day 30+ sin quejas abiertas
   - Miembros que acaban de renovar (AEP post-enero)
   - Miembros que ya refirieron antes (volver a pedir = aceptable)
   - No se les ha pedido referido en los últimos 90 días
════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../luna_config.php';  // ← config propio de LUNA
require_once __DIR__ . '/../luna_ai.php'; // cerebro IA (degradación elegante si no hay key)

$CONFIG = [
  'send_email'    => true,
  'send_telegram' => true,

  'email_to'    => 'info@withisabelfuentes.com',
  'email_from'  => 'luna@withisabelfuentes.com',
  'email_name'  => 'LUNA Referidos',

  'telegram_token'         => 'XXXX:YYYY',
  'telegram_chat_id'       => '0',
  'telegram_samia_chat_id' => '0',  // Chat ID de Samia — si es el mismo que Isabel, usar el mismo

  'timezone' => 'America/Los_Angeles',
  'log_file' => __DIR__ . '/luna_referral_log.txt',

  // Thresholds
  'min_days_active'       => 30,     // mínimo activo para pedir
  'referral_cooldown_days'=> 90,     // no repetir antes de 90 días
  'max_candidates_weekly' => 10,     // max candidatos por semana (no saturar)
];

date_default_timezone_set($CONFIG['timezone']);

function logReferral($msg, $config) {
  @file_put_contents($config['log_file'], '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

// ─── DB ────────────────────────────────────────────────────
try {
  $pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch (Exception $e) {
  logReferral("FATAL: DB - " . $e->getMessage(), $CONFIG);
  exit(1);
}

// ─── AUTO-CREATE TABLES ────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS luna_referrals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  referrer_id INT NOT NULL COMMENT 'miembro que refiere',
  referred_id INT DEFAULT NULL COMMENT 'miembro referido (si se inscribió)',
  referred_nombre VARCHAR(120) DEFAULT NULL,
  referred_telefono VARCHAR(20) DEFAULT NULL,
  ask_sent_at DATETIME DEFAULT NULL COMMENT 'cuándo se pidió el referido',
  ask_sent_by INT DEFAULT NULL COMMENT 'usuario que pidió (Samia=10)',
  referral_received_at DATETIME DEFAULT NULL,
  enrolled_at DATETIME DEFAULT NULL,
  status ENUM('ASKED','RECEIVED','ENROLLED','DECLINED') DEFAULT 'ASKED',
  notes TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_referrer (referrer_id),
  INDEX idx_referred (referred_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ─── FIND REFERRAL CANDIDATES ──────────────────────────────
function findCandidates($pdo, $config) {
  // Cast as int explicitly — SQL injection-proof even if config is mistakenly a string
  $minDays    = (int)$config['min_days_active'];
  $cooldown   = (int)$config['referral_cooldown_days'];
  $maxResults = (int)$config['max_candidates_weekly'];

  // Members who:
  // 1. Are ACTIVO for $minDays+ days
  // 2. Have no open complaint tickets
  // 3. Haven't been asked for a referral in $cooldown days
  // 4. Ordered by: previous referrers first, then longest active
  $candidates = $pdo->query("
    SELECT
      m.id, m.nombre, m.apellido, m.telefono,
      m.carrier, m.plan_nombre,
      DATEDIFF(CURDATE(), m.fecha_efectiva) AS dias_activo,
      (SELECT COUNT(*) FROM luna_referrals r WHERE r.referrer_id = m.id) AS total_referrals,
      (SELECT COUNT(*) FROM luna_referrals r WHERE r.referrer_id = m.id AND r.status='ENROLLED') AS referrals_enrolled,
      (SELECT MAX(r.ask_sent_at) FROM luna_referrals r WHERE r.referrer_id = m.id) AS last_asked
    FROM miembros m
    WHERE m.estado = 'ACTIVO'
      AND DATEDIFF(CURDATE(), m.fecha_efectiva) >= {$minDays}
      AND (SELECT COUNT(*) FROM tickets t
           WHERE t.miembro_id = m.id
             AND t.estado NOT IN ('CERRADO','RESUELTO')
             AND t.tipo IN ('QUEJA','PROBLEMA','RECLAMO')) = 0
      AND (
        (SELECT MAX(r.ask_sent_at) FROM luna_referrals r WHERE r.referrer_id = m.id) IS NULL
        OR DATEDIFF(CURDATE(), (SELECT MAX(r.ask_sent_at) FROM luna_referrals r WHERE r.referrer_id = m.id)) >= {$cooldown}
      )
    ORDER BY
      referrals_enrolled DESC,   -- previous referrers first
      dias_activo DESC           -- then longest active
    LIMIT {$maxResults}
  ")->fetchAll(PDO::FETCH_ASSOC);

  return $candidates;
}

// ─── GENERATE PERSONALIZED MESSAGE ────────────────────────
function generateReferralMessage($member) {
  $nombre = $member['nombre'];
  $carrier = $member['carrier'] ?? 'su plan';
  $dias = (int)$member['dias_activo'];
  $meses = round($dias / 30);
  $isVip = (int)$member['referrals_enrolled'] > 0;

  // Personalize based on tenure and history
  if ($isVip) {
    $apertura = "Hola {$nombre}, soy Samia del equipo de Isabel. ¡Gracias por los referidos anteriores! Su apoyo ha sido increíble.";
  } elseif ($meses >= 12) {
    $apertura = "Hola {$nombre}, soy Samia del equipo de la agente Isabel. Ya lleva más de {$meses} meses con {$carrier} y quería asegurarme de que todo siga yendo bien.";
  } else {
    $apertura = "Hola {$nombre}, soy Samia del equipo de la agente Isabel. Espero que todo esté yendo bien con {$carrier}.";
  }

  $cuerpo = "Isabel me pidió que la contactara porque usted es uno de nuestros clientes más valorados. " .
    "¿Conoce a algún familiar o amigo mayor de 65 años que pudiera beneficiarse de un plan Medicare? " .
    "Con gusto les ayudamos igual que le ayudamos a usted, sin ningún costo para ellos.";

  $cierre = "Si me puede dar su nombre y teléfono, Isabel los llama personalmente. ¡Muchas gracias!";

  return "{$apertura}\n\n{$cuerpo}\n\n{$cierre}";
}

// ─── MENSAJE INTELIGENTE (IA con fallback a la plantilla) ──
// Redacta un mensaje único y personalizado por miembro con Claude. Si no
// hay key o la IA falla, cae a generateReferralMessage() (sin romper nada).
function smartReferralMessage($member) {
  if (lunaAIEnabled()) {
    $meses = round((int)$member['dias_activo'] / 30);
    $isVip = (int)$member['referrals_enrolled'] > 0;

    $system =
      "Eres Samia, del equipo de la agente de Medicare Isabel Fuentes (Sur de "
    . "California). Redactas un mensaje breve de WhatsApp/SMS para pedirle, con calidez "
    . "y SIN presión, a un cliente ACTIVO que refiera a familiares o amigos mayores de "
    . "65 años. REGLAS: español natural y cercano, trato de usted; máximo 3 frases; "
    . "menciona el carrier solo si te lo doy; recuérdale que el servicio NO tiene costo "
    . "para el referido; pide nombre y teléfono. CUMPLIMIENTO CMS: no menciones planes "
    . "específicos, primas ni beneficios, y no hagas promesas de cobertura. NUNCA "
    . "inventes datos. Devuelve SOLO el mensaje, sin comillas ni encabezados.";

    $user =
      "Cliente: {$member['nombre']} {$member['apellido']}\n"
    . "Carrier: " . ($member['carrier'] ?: 'no especificado') . "\n"
    . "Meses activo: {$meses}\n"
    . "¿Refirió antes y se inscribieron?: " . ($isVip ? 'sí, es un referidor estrella' : 'no') . "\n\n"
    . "Escribe el mensaje para pedirle un referido.";

    $ai = lunaAI($system, $user, 400);
    if ($ai !== null) return $ai;
  }
  return generateReferralMessage($member); // fallback determinista
}

// ─── TELEGRAM HELPER ───────────────────────────────────────
function tgSendReferral($token, $chatId, $text, $buttons = []) {
  if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 3950) . '...';
  $payload = [
    'chat_id'    => $chatId,
    'text'       => $text,
    'parse_mode' => 'Markdown',
    'disable_web_page_preview' => true,
  ];
  if (!empty($buttons)) {
    $payload['reply_markup'] = json_encode(['inline_keyboard' => $buttons]);
  }
  $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);
  curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return $code === 200;
}

// ─── MARK AS ASKED IN CRM ──────────────────────────────────
function markReferralAsked($pdo, $memberId, $askedByUserId = 10) {
  $pdo->prepare("
    INSERT INTO luna_referrals (referrer_id, ask_sent_at, ask_sent_by, status)
    VALUES (?, NOW(), ?, 'ASKED')
  ")->execute([$memberId, $askedByUserId]);
  return $pdo->lastInsertId();
}

// ─── REFERRAL STATS ────────────────────────────────────────
function getReferralStats($pdo) {
  $stats = [];

  $stats['total_asked']    = (int)$pdo->query("SELECT COUNT(*) FROM luna_referrals")->fetchColumn();
  $stats['total_received'] = (int)$pdo->query("SELECT COUNT(*) FROM luna_referrals WHERE status IN ('RECEIVED','ENROLLED')")->fetchColumn();
  $stats['total_enrolled'] = (int)$pdo->query("SELECT COUNT(*) FROM luna_referrals WHERE status='ENROLLED'")->fetchColumn();
  $stats['this_month']     = (int)$pdo->query("SELECT COUNT(*) FROM luna_referrals WHERE MONTH(ask_sent_at)=MONTH(CURDATE()) AND YEAR(ask_sent_at)=YEAR(CURDATE())")->fetchColumn();

  // Conversion rate
  $stats['conversion_pct'] = $stats['total_received'] > 0
    ? round(($stats['total_enrolled'] / $stats['total_received']) * 100)
    : 0;

  // Top referrers
  $stats['top_referrers'] = $pdo->query("
    SELECT m.nombre, m.apellido, COUNT(*) AS total,
           SUM(CASE WHEN r.status='ENROLLED' THEN 1 ELSE 0 END) AS enrolled
    FROM luna_referrals r
    JOIN miembros m ON r.referrer_id=m.id
    GROUP BY r.referrer_id
    ORDER BY enrolled DESC, total DESC
    LIMIT 5
  ")->fetchAll(PDO::FETCH_ASSOC);

  return $stats;
}

// ─── BUILD EMAIL REPORT ────────────────────────────────────
function buildReferralEmailReport($candidates, $stats) {
  $count = count($candidates);
  $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head>
  <body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#f5f5f7;padding:20px;">
    <div style="max-width:640px;margin:0 auto;background:#fff;border-radius:14px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
      <div style="background:linear-gradient(135deg,#00875a,#0097a7);color:#fff;padding:20px 24px;border-radius:11px;margin-bottom:18px;">
        <div style="font-size:11px;font-weight:700;letter-spacing:2px;opacity:.8;text-transform:uppercase;">🤝 LUNA Referidos</div>
        <div style="font-size:22px;font-weight:700;font-family:Georgia,serif;margin-top:4px;">' . $count . ' candidatos esta semana</div>
      </div>';

  // Stats
  $html .= '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px;">';
  $statBoxes = [
    ['Total pedidos', $stats['total_asked']],
    ['Referidos recibidos', $stats['total_received']],
    ['Se inscribieron', $stats['total_enrolled']],
  ];
  foreach ($statBoxes as [$label, $val]) {
    $html .= "<div style='background:#f5f5f7;border-radius:10px;padding:14px;text-align:center;'>
      <div style='font-size:24px;font-weight:700;color:#00875a;'>{$val}</div>
      <div style='font-size:11px;color:#52606d;margin-top:3px;'>{$label}</div>
    </div>";
  }
  $html .= '</div>';

  // Candidates list
  if ($count > 0) {
    $html .= '<h3 style="font-size:14px;font-weight:700;margin-bottom:12px;">📋 Candidatos para esta semana (Samia):</h3>';
    foreach ($candidates as $m) {
      $meses = round((int)$m['dias_activo'] / 30);
      $vip = (int)$m['referrals_enrolled'] > 0 ? ' ⭐ VIP' : '';
      $html .= "<div style='border:1px solid #e5e5ea;border-radius:10px;padding:14px;margin-bottom:10px;'>
        <div style='font-weight:700;font-size:13px;'>{$m['nombre']} {$m['apellido']}{$vip}</div>
        <div style='font-size:11px;color:#52606d;margin:3px 0;'>{$m['carrier']} · {$meses} meses activo · ☎ {$m['telefono']}</div>
        <div style='font-size:12px;color:#3a3a3c;background:#f5f5f7;padding:10px;border-radius:8px;margin-top:8px;font-style:italic;'>"
          . nl2br(htmlspecialchars($m['mensaje'] ?? generateReferralMessage($m))) . '
        </div>
      </div>';
    }
  } else {
    $html .= '<p style="color:#52606d;font-style:italic;">Sin candidatos nuevos esta semana. Los candidatos disponibles ya fueron contactados recientemente.</p>';
  }

  $html .= '<div style="margin-top:24px;padding-top:14px;border-top:1px solid #e5e5ea;text-align:center;font-size:11px;color:#8e8e93;">
    LUNA Referidos · Automático cada miércoles · Medicare with Isabel<br>
    <a href="https://withisabelfuentes.com/luna/" style="color:#00875a;text-decoration:none;font-weight:600;">Abrir LUNA →</a>
  </div></div></body></html>';

  return $html;
}

// ─── MAIN ──────────────────────────────────────────────────
$startTime = microtime(true);
logReferral("=== LUNA Referral System started ===", $CONFIG);

$candidates = findCandidates($pdo, $CONFIG);
$stats      = getReferralStats($pdo);
$results    = [];

// Redacta el mensaje (IA con fallback) UNA sola vez por candidato → mismo
// texto en el email a Isabel y en Telegram a Samia, sin doble costo de API.
$aiUsed = 0;
foreach ($candidates as $i => $m) {
  $candidates[$i]['mensaje'] = smartReferralMessage($m);
  if (lunaAIEnabled()) $aiUsed++;
}

logReferral("Found " . count($candidates) . " candidates (IA: " . (lunaAIEnabled() ? "on, {$aiUsed} msgs" : 'off, plantilla') . ")", $CONFIG);

// ── Send email report to Isabel ──────────────────────────
if ($CONFIG['send_email']) {
  $subject = "🤝 LUNA Referidos — " . count($candidates) . " candidatos esta semana";
  $html = buildReferralEmailReport($candidates, $stats);
  $headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    'From: ' . $CONFIG['email_name'] . ' <' . $CONFIG['email_from'] . '>',
    'X-Mailer: LUNA-Referrals',
  ];
  $r = @mail($CONFIG['email_to'], $subject, $html, implode("\r\n", $headers));
  $results['email'] = $r ? 'OK' : 'FAILED';
  logReferral("Email: " . $results['email'], $CONFIG);
}

// ── Send action messages to Samia via Telegram ──────────
if ($CONFIG['send_telegram'] && !empty($CONFIG['telegram_token']) && $CONFIG['telegram_token'] !== 'XXXX:YYYY') {
  $samiaChat = !empty($CONFIG['telegram_samia_chat_id']) && $CONFIG['telegram_samia_chat_id'] !== '0'
    ? $CONFIG['telegram_samia_chat_id']
    : $CONFIG['telegram_chat_id'];

  if (empty($candidates)) {
    tgSendReferral(
      $CONFIG['telegram_token'], $samiaChat,
      "🤝 *Referidos — esta semana*\n\n_Sin candidatos nuevos. Todos los miembros disponibles ya fueron contactados recientemente._"
    );
  } else {
    // Header
    tgSendReferral(
      $CONFIG['telegram_token'], $samiaChat,
      "🤝 *LUNA Referidos — " . count($candidates) . " candidatos esta semana*\n\n_Samia: a continuación el mensaje personalizado para cada miembro. Tapa ✅ cuando hayas enviado el mensaje._"
    );

    // One message per candidate with action buttons
    foreach ($candidates as $m) {
      $meses = round((int)$m['dias_activo'] / 30);
      $vip = (int)$m['referrals_enrolled'] > 0 ? ' ⭐' : '';
      $msg = "*{$m['nombre']} {$m['apellido']}{$vip}*\n";
      $msg .= "_{$m['carrier']} · {$meses} meses · {$m['telefono']}_\n\n";
      $msg .= "📝 *Mensaje sugerido:*\n";
      $msg .= "_" . ($m['mensaje'] ?? generateReferralMessage($m)) . "_";

      $buttons = [[
        ['text' => '✅ Mensaje enviado', 'callback_data' => 'referral_sent_' . $m['id']],
        ['text' => '⏭️ Saltar',          'callback_data' => 'referral_skip_' . $m['id']],
      ]];

      tgSendReferral($CONFIG['telegram_token'], $samiaChat, $msg, $buttons);
      // Small pause to avoid Telegram rate limits
      usleep(300000); // 0.3 seconds
    }
  }

  // Stats summary to Isabel
  $statsMsg = "📊 *Programa de Referidos — Resumen:*\n\n";
  $statsMsg .= "• Pedidos totales: {$stats['total_asked']}\n";
  $statsMsg .= "• Referidos recibidos: {$stats['total_received']}\n";
  $statsMsg .= "• Inscripciones: {$stats['total_enrolled']}\n";
  $statsMsg .= "• Conversión: {$stats['conversion_pct']}%\n";
  if (!empty($stats['top_referrers'])) {
    $statsMsg .= "\n⭐ *Top referidores:*\n";
    foreach (array_slice($stats['top_referrers'], 0, 3) as $r) {
      $statsMsg .= "• {$r['nombre']} {$r['apellido']}: {$r['enrolled']} inscritos\n";
    }
  }
  tgSendReferral($CONFIG['telegram_token'], $CONFIG['telegram_chat_id'], $statsMsg);

  $results['telegram'] = 'OK';
  logReferral("Telegram: OK — sent " . count($candidates) . " candidate messages", $CONFIG);
}

$elapsed = round((microtime(true) - $startTime) * 1000);
logReferral("=== Done in {$elapsed}ms — " . json_encode($results) . " ===", $CONFIG);

if (php_sapi_name() !== 'cli') {
  header('Content-Type: application/json');
  echo json_encode([
    'ok'           => true,
    'candidates'   => count($candidates),
    'stats'        => $stats,
    'results'      => $results,
    'elapsed_ms'   => $elapsed,
  ], JSON_PRETTY_PRINT);
}
