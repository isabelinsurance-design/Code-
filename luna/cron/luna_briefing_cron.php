<?php
/* ════════════════════════════════════════════════════════════════
   LUNA AUTONOMOUS BRIEFING — Nivel 3
   Medicare with Isabel — Cron job para briefings automáticos

   USO:
   - Configurar cron en Bluehost: 0 7 * * * php /path/to/luna_briefing_cron.php
   - Recibe por Email + Telegram + WhatsApp (futuro)

   CONFIGURACIÓN: editar la sección CONFIG abajo
════════════════════════════════════════════════════════════════ */

// ─── CONFIG ───────────────────────────────────────────────────
require_once __DIR__ . '/_cron_guard.php';  // 🔒 bloquea disparo por HTTP sin LUNA_CRON_TOKEN
require_once __DIR__ . '/../luna_config.php';  // ← config propio de LUNA
require_once __DIR__ . '/../luna_ai.php'; // cerebro IA (degradación elegante si no hay key)

$CONFIG = [
  // Canales activos (true/false)
  'send_email'    => true,
  'send_telegram' => false,  // ← apagado: Isabel usa la app/correo, no Telegram
  'send_whatsapp' => false,  // activar cuando Meta apruebe

  // Email
  'email_to'      => 'info@withisabelfuentes.com',
  'email_from'    => 'luna@withisabelfuentes.com',
  'email_name'    => 'LUNA Briefing',

  // Telegram (configurar después)
  'telegram_token'   => 'XXXX:YYYY',  // del @BotFather
  'telegram_chat_id' => '0',           // chat ID de Isabel

  // WhatsApp (futuro — Twilio o 360dialog)
  'whatsapp_provider' => 'twilio',
  'whatsapp_to'       => '+13102700626',
  'whatsapp_creds'    => [
    'sid'   => '',
    'token' => '',
    'from'  => 'whatsapp:+14155238886',
  ],

  // Timezone
  'timezone' => 'America/Los_Angeles',

  // Logging
  'log_file' => __DIR__ . '/luna_briefing_log.txt',
];

date_default_timezone_set($CONFIG['timezone']);

// ─── LOGGING ─────────────────────────────────────────────────
function logBriefing($msg, $config) {
  $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
  @file_put_contents($config['log_file'], $line, FILE_APPEND);
}

// ─── DATABASE ────────────────────────────────────────────────
try {
  $pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch (Exception $e) {
  logBriefing("FATAL: DB connection failed - " . $e->getMessage(), $CONFIG);
  exit(1);
}

// ─── COLLECT BRIEFING DATA ───────────────────────────────────
function collectBriefingData($pdo) {
  $data = [];

  // Pipeline summary
  $data['pipeline'] = [];
  foreach ($pdo->query("SELECT estado, COUNT(*) AS total FROM miembros WHERE estado IS NOT NULL GROUP BY estado")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $data['pipeline'][$r['estado']] = (int)$r['total'];
  }

  // Today's appointments
  $data['citas_hoy'] = $pdo->query("
    SELECT c.tipo, c.hora, c.modalidad, CONCAT(m.apellido,', ',m.nombre) AS miembro, u.iniciales
    FROM citas c
    LEFT JOIN miembros m ON c.miembro_id=m.id
    LEFT JOIN usuarios u ON c.agente_id=u.id
    WHERE c.fecha=CURDATE() AND c.estado='PENDIENTE'
    ORDER BY c.hora
  ")->fetchAll(PDO::FETCH_ASSOC);

  // Hot leads cold (3+ days no contact)
  $data['hot_leads_frios'] = $pdo->query("
    SELECT m.id, m.nombre, m.apellido, m.telefono,
           DATEDIFF(CURDATE(), COALESCE(
             (SELECT MAX(DATE(a.fecha_hora)) FROM actividad a WHERE a.miembro_id=m.id),
             m.created_at
           )) AS dias_sin_contacto
    FROM miembros m WHERE m.estado='HOT LEAD'
    HAVING dias_sin_contacto >= 3
    ORDER BY dias_sin_contacto DESC LIMIT 10
  ")->fetchAll(PDO::FETCH_ASSOC);

  // T65 urgent (<= 30 days)
  $data['t65_urgentes'] = $pdo->query("
    SELECT id, nombre, apellido, dob,
           DATEDIFF(DATE_ADD(dob, INTERVAL 65 YEAR), CURDATE()) AS dias_para_65
    FROM miembros WHERE estado != 'ACTIVO'
      AND DATE_ADD(dob, INTERVAL 65 YEAR) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY dias_para_65 ASC LIMIT 10
  ")->fetchAll(PDO::FETCH_ASSOC);

  // Retention alerts today
  $data['retencion_hoy'] = $pdo->query("
    SELECT m.id, m.nombre, m.apellido, m.telefono, m.carrier,
           DATEDIFF(CURDATE(), m.fecha_efectiva) AS dias_activo
    FROM miembros m WHERE m.estado='ACTIVO'
      AND (m.fecha_efectiva=DATE_SUB(CURDATE(),INTERVAL 7 DAY) OR
           m.fecha_efectiva=DATE_SUB(CURDATE(),INTERVAL 30 DAY) OR
           m.fecha_efectiva=DATE_SUB(CURDATE(),INTERVAL 60 DAY) OR
           m.fecha_efectiva=DATE_SUB(CURDATE(),INTERVAL 90 DAY))
  ")->fetchAll(PDO::FETCH_ASSOC);

  // Open ALTA tickets
  $data['tickets_alta'] = $pdo->query("
    SELECT t.id, t.descripcion, u.iniciales, CONCAT(m.apellido,', ',m.nombre) AS miembro,
           DATEDIFF(CURDATE(), DATE(t.created_at)) AS dias_abierto
    FROM tickets t
    LEFT JOIN miembros m ON t.miembro_id=m.id
    LEFT JOIN usuarios u ON t.asignado_a=u.id
    WHERE t.estado!='CERRADO' AND t.prioridad='ALTA'
    ORDER BY t.created_at ASC LIMIT 5
  ")->fetchAll(PDO::FETCH_ASSOC);

  // Pending SOA count
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

  // Enrollments this month (current commissions tracking)
  $data['enrollments_mes'] = (int)$pdo->query("
    SELECT COUNT(*) FROM miembros
    WHERE estado = 'ACTIVO'
      AND MONTH(fecha_efectiva) = MONTH(CURDATE())
      AND YEAR(fecha_efectiva) = YEAR(CURDATE())
  ")->fetchColumn();

  // Date context
  $data['fecha'] = date('Y-m-d');
  $data['dia_semana'] = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'][(int)date('w')];
  $data['fecha_legible'] = date('j') . ' de ' . ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'][(int)date('n')-1];

  return $data;
}

// ─── ANÁLISIS IA (el "cerebro" de LUNA) ─────────────────────
// Toma los números reales del CRM y le pide a Claude un plan del día
// priorizado y razonado. Devuelve texto en viñetas o null (sin key/falla).
function lunaBriefingAnalysis($data) {
  if (!lunaAIEnabled()) return null;

  $system =
    "Eres LUNA, la jefa de gabinete (chief of staff) de Isabel Fuentes, agente "
  . "de Medicare en el Sur de California. Cada mañana analizas el tablero del CRM "
  . "y entregas un plan del día priorizado. REGLAS: usa SOLO los números y nombres "
  . "que te doy (NUNCA inventes datos); escribe en español, directo y cálido; "
  . "máximo 6 viñetas que empiecen con '•'; prioriza lo que protege ingresos y "
  . "cumplimiento (retención que vence hoy, hot leads enfriándose, T65 urgentes, "
  . "SOA/compliance, tickets ALTA estancados). Asigna responsable cuando aplique: "
  . "Samia = retención/servicio, Skarleth = ventas/hot leads, Isabel = T65 y cierres. "
  . "No repitas listas completas; sintetiza y decide. Cierra con UNA línea de enfoque "
  . "estratégico que empiece con '🎯'. Sin encabezados ni introducción, solo las viñetas.";

  $user =
    "Tablero de hoy (" . $data['dia_semana'] . ", " . $data['fecha_legible'] . "):\n"
  . json_encode([
      'pipeline'        => $data['pipeline'],
      'enrollments_mes' => $data['enrollments_mes'],
      'citas_hoy'       => count($data['citas_hoy']),
      'retencion_hoy'   => array_map(fn($m) => trim("{$m['nombre']} {$m['apellido']} (Day {$m['dias_activo']}" . ($m['carrier'] ? ", {$m['carrier']}" : '') . ")"), $data['retencion_hoy']),
      'hot_leads_frios' => array_map(fn($h) => "{$h['nombre']} {$h['apellido']} ({$h['dias_sin_contacto']}d sin contacto)", $data['hot_leads_frios']),
      't65_urgentes'    => array_map(fn($t) => "{$t['nombre']} {$t['apellido']} ({$t['dias_para_65']}d para 65)", $data['t65_urgentes']),
      'tickets_alta'    => count($data['tickets_alta']),
      'soa_pendiente'   => $data['soa_pendiente'],
      'callbacks'       => $data['callbacks'],
      'apps_proceso'    => $data['apps_proceso'],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
  . "\n\nDame el plan priorizado del día.";

  return lunaAI($system, $user, 700);
}

// ─── BUILD BRIEFING TEXT (formatos por canal) ───────────────
function buildBriefingText($data, $format = 'text') {
  $isHTML = $format === 'html';
  $isMarkdown = $format === 'markdown'; // Telegram

  $br = $isHTML ? '<br>' : "\n";
  $b  = $isHTML ? ['<b>','</b>'] : ($isMarkdown ? ['*','*'] : ['','']);
  $i  = $isHTML ? ['<i>','</i>'] : ($isMarkdown ? ['_','_'] : ['','']);
  $hr = $isHTML ? '<hr style="border:none;border-top:1px solid #ddd;margin:14px 0">' : str_repeat('─', 30) . "\n";

  $out = '';

  // Header
  $out .= "{$b[0]}🌅 Buenos días, Isabel{$b[1]}{$br}";
  $out .= "{$i[0]}{$data['dia_semana']}, {$data['fecha_legible']}{$i[1]}{$br}{$br}";

  // Pipeline summary
  $activos = $data['pipeline']['ACTIVO'] ?? 0;
  $hot     = $data['pipeline']['HOT LEAD'] ?? 0;
  $t65     = $data['pipeline']['T65'] ?? 0;
  $prospectos = $data['pipeline']['PROSPECTO'] ?? 0;
  $followup = $data['pipeline']['FOLLOW-UP'] ?? 0;

  $out .= "{$b[0]}📊 PIPELINE{$b[1]}{$br}";
  $out .= "• {$activos} ACTIVO · {$hot} HOT LEAD · {$t65} T65{$br}";
  $out .= "• {$prospectos} prospectos · {$followup} follow-up{$br}";
  $out .= "• {$data['enrollments_mes']} inscripciones este mes{$br}{$br}";

  // Citas hoy
  if (!empty($data['citas_hoy'])) {
    $out .= "{$b[0]}📅 CITAS DE HOY ({" . count($data['citas_hoy']) . "}){$b[1]}{$br}";
    foreach ($data['citas_hoy'] as $c) {
      $hora = substr($c['hora'] ?? '', 0, 5);
      $out .= "• {$hora} — {$c['miembro']} ({$c['tipo']}){$br}";
    }
    $out .= $br;
  }

  // Retention
  if (!empty($data['retencion_hoy'])) {
    $count = count($data['retencion_hoy']);
    $out .= "{$b[0]}🔴 RETENCIÓN HOY ({$count}){$b[1]}{$br}";
    foreach (array_slice($data['retencion_hoy'], 0, 5) as $m) {
      $day = "Day {$m['dias_activo']}";
      $out .= "• {$m['nombre']} {$m['apellido']} — {$day}";
      if ($m['carrier']) $out .= " · {$m['carrier']}";
      $out .= "{$br}";
    }
    $out .= "{$i[0]}¿Quieres que cree los tickets para Samia?{$i[1]}{$br}{$br}";
  }

  // Hot leads cold
  if (!empty($data['hot_leads_frios'])) {
    $count = count($data['hot_leads_frios']);
    $out .= "{$b[0]}🔥 HOT LEADS FRÍOS ({$count}){$b[1]}{$br}";
    foreach (array_slice($data['hot_leads_frios'], 0, 5) as $h) {
      $out .= "• {$h['nombre']} {$h['apellido']} ({$h['dias_sin_contacto']}d sin contacto){$br}";
    }
    $out .= "{$i[0]}¿Creo tickets de seguimiento para Skarleth?{$i[1]}{$br}{$br}";
  }

  // T65 urgent
  if (!empty($data['t65_urgentes'])) {
    $count = count($data['t65_urgentes']);
    $muyUrgentes = count(array_filter($data['t65_urgentes'], fn($t) => $t['dias_para_65'] <= 14));
    $out .= "{$b[0]}🎂 T65 URGENTES ({$count}){$b[1]}";
    if ($muyUrgentes > 0) $out .= " — {$muyUrgentes} en menos de 14 días";
    $out .= $br;
    foreach (array_slice($data['t65_urgentes'], 0, 5) as $t) {
      $out .= "• {$t['nombre']} {$t['apellido']} — {$t['dias_para_65']}d para 65{$br}";
    }
    $out .= $br;
  }

  // Tickets ALTA
  if (!empty($data['tickets_alta'])) {
    $count = count($data['tickets_alta']);
    $out .= "{$b[0]}🚨 TICKETS ALTA ({$count}){$b[1]}{$br}";
    foreach (array_slice($data['tickets_alta'], 0, 3) as $t) {
      $desc = mb_substr($t['descripcion'] ?? '', 0, 60);
      $out .= "• {$desc}";
      if ($t['dias_abierto'] > 1) $out .= " ({$t['dias_abierto']}d)";
      $out .= "{$br}";
    }
    $out .= $br;
  }

  // SOA risk
  if ($data['soa_pendiente'] >= 3) {
    $out .= "{$b[0]}⚠️ COMPLIANCE{$b[1]}{$br}";
    $out .= "• {$data['soa_pendiente']} miembros activos SIN SOA firmado{$br}{$br}";
  }

  // Callbacks
  if ($data['callbacks'] >= 2) {
    $out .= "{$b[0]}☎️ CALLBACKS{$b[1]}{$br}";
    $out .= "• {$data['callbacks']} llamadas perdidas pendientes (regla 60min){$br}{$br}";
  }

  // Apps en proceso
  if ($data['apps_proceso'] > 0) {
    $out .= "{$b[0]}📋 APPS EN PROCESO{$b[1]}{$br}";
    $out .= "• {$data['apps_proceso']} aplicaciones esperando confirmación CMS{$br}{$br}";
  }

  $out .= $hr;

  // Action prompts — plan razonado por IA si está disponible; si no, fallback determinista
  if (!empty($data['ai_analysis'])) {
    $out .= "{$b[0]}🧠 PLAN DE LUNA{$b[1]}{$br}";
    $out .= str_replace("\n", $br, $data['ai_analysis']) . $br;
  } else {
    $out .= "{$b[0]}🎯 ACCIONES PROPUESTAS{$b[1]}{$br}";
    $hasActions = false;
    if (!empty($data['retencion_hoy'])) {
      $out .= "1. Crear " . count($data['retencion_hoy']) . " tickets de retención (Samia){$br}";
      $hasActions = true;
    }
    if (!empty($data['hot_leads_frios'])) {
      $out .= "2. Crear tickets de seguimiento hot leads (Skarleth){$br}";
      $hasActions = true;
    }
    if (!empty($data['t65_urgentes'])) {
      $out .= "3. Contactar T65 urgentes esta semana{$br}";
      $hasActions = true;
    }
    if (!$hasActions) {
      $out .= "{$i[0]}Sin acciones urgentes hoy. Buen día para enfocarse en outreach.{$i[1]}{$br}";
    }
  }

  $out .= "{$br}{$i[0]}— LUNA · 🌙 abre la plataforma para aprobar acciones{$i[1]}{$br}";
  $out .= "{$i[0]}withisabelfuentes.com/luna/{$i[1]}";

  return $out;
}

// ─── EMAIL ──────────────────────────────────────────────────
function sendEmail($config, $data) {
  $subject = "🌅 LUNA Briefing — " . $data['fecha_legible'];
  $body = buildBriefingText($data, 'html');

  $htmlBody = '<!DOCTYPE html><html><head><meta charset="utf-8"></head>
  <body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#f5f5f7;padding:20px;margin:0;color:#0d1117;">
    <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:14px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
      <div style="background:linear-gradient(135deg,#1a56ff,#6741d9);color:#fff;padding:18px 22px;border-radius:11px;margin-bottom:18px;">
        <div style="font-size:11px;font-weight:700;letter-spacing:2px;opacity:.8;text-transform:uppercase;margin-bottom:4px;">🌙 LUNA Briefing</div>
        <div style="font-size:20px;font-weight:700;font-family:Georgia,serif;">Tu plan del día</div>
      </div>
      <div style="font-size:13px;line-height:1.7;color:#3a3a3c;">' . $body . '</div>
      <div style="margin-top:24px;padding-top:14px;border-top:1px solid #e5e5ea;text-align:center;font-size:11px;color:#8e8e93;">
        Generado automáticamente por LUNA · Medicare with Isabel<br>
        <a href="https://withisabelfuentes.com/luna/" style="color:#1a56ff;text-decoration:none;font-weight:600;">Abrir LUNA →</a>
      </div>
    </div>
  </body></html>';

  $headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    'From: ' . $config['email_name'] . ' <' . $config['email_from'] . '>',
    'Reply-To: ' . $config['email_from'],
    'X-Mailer: LUNA-AutoBriefing',
  ];

  $sent = @mail($config['email_to'], $subject, $htmlBody, implode("\r\n", $headers));
  return $sent;
}

// ─── TELEGRAM ───────────────────────────────────────────────
// ─── TELEGRAM HELPER: send one message with optional buttons ─
function tgSend($token, $chatId, $text, $buttons = []) {
  if (mb_strlen($text) > 4000) {
    $text = mb_substr($text, 0, 3950) . "\n\n_... abre LUNA para detalles_";
  }
  $payload = [
    'chat_id'    => $chatId,
    'text'       => $text,
    'parse_mode' => 'Markdown',
    'disable_web_page_preview' => true,
  ];
  // $buttons = [[['text'=>'Label','callback_data'=>'action:params'], ...], ...]
  if (!empty($buttons)) {
    $payload['reply_markup'] = json_encode(['inline_keyboard' => $buttons]);
  }
  $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 15);
  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return $code === 200;
}

function sendTelegram($config, $data) {
  $token  = $config['telegram_token'];
  $chatId = $config['telegram_chat_id'];

  if (empty($token) || $token === 'XXXX:YYYY' || empty($chatId) || $chatId === '0') {
    return false;
  }

  // ── Message 1: Main briefing (no buttons) ──────────────
  $body = buildBriefingText($data, 'markdown');
  tgSend($token, $chatId, $body);

  // ── Message 2: Action buttons — one section per alert ──
  // Only send if there are actionable items
  $sentActions = false;

  // Retención hoy
  if (!empty($data['retencion_hoy'])) {
    $count = count($data['retencion_hoy']);
    $names = implode(', ', array_map(fn($m) => "{$m['nombre']} {$m['apellido']}", array_slice($data['retencion_hoy'], 0, 3)));
    $msg = "📞 *Retención HOY ({$count} miembros)*\n_{$names}_\n\n¿Creo los tickets para Samia?";
    $buttons = [[
      ['text' => '✅ Crear tickets (Samia)', 'callback_data' => 'create_retencion_tickets'],
      ['text' => '⏭️ Después',               'callback_data' => 'skip_retencion'],
    ]];
    tgSend($token, $chatId, $msg, $buttons);
    $sentActions = true;
  }

  // Hot leads fríos
  if (!empty($data['hot_leads_frios'])) {
    $count = count($data['hot_leads_frios']);
    $names = implode(', ', array_map(fn($h) => "{$h['nombre']} ({$h['dias_sin_contacto']}d)", array_slice($data['hot_leads_frios'], 0, 3)));
    $msg = "🔥 *Hot leads sin contactar ({$count})*\n_{$names}_\n\n¿Creo tickets de seguimiento para Skarleth?";
    $buttons = [[
      ['text' => '✅ Crear tickets (Skarleth)', 'callback_data' => 'create_hotlead_tickets'],
      ['text' => '⏭️ Después',                  'callback_data' => 'skip_hotleads'],
    ]];
    tgSend($token, $chatId, $msg, $buttons);
    $sentActions = true;
  }

  // T65 urgentes
  if (!empty($data['t65_urgentes'])) {
    $urgentes = array_filter($data['t65_urgentes'], fn($t) => $t['dias_para_65'] <= 14);
    if (!empty($urgentes)) {
      $count = count($urgentes);
      $names = implode(', ', array_map(fn($t) => "{$t['nombre']} ({$t['dias_para_65']}d)", array_slice(array_values($urgentes), 0, 3)));
      $msg = "🎂 *T65 muy urgentes ({$count}) — menos de 14 días*\n_{$names}_\n\n¿Creo recordatorio de llamada para Isabel?";
      $buttons = [[
        ['text' => '✅ Crear recordatorio',  'callback_data' => 'create_t65_reminder'],
        ['text' => '⏭️ Después',              'callback_data' => 'skip_t65'],
      ]];
      tgSend($token, $chatId, $msg, $buttons);
      $sentActions = true;
    }
  }

  // Tickets ALTA estancados
  if (!empty($data['tickets_alta'])) {
    $count = count($data['tickets_alta']);
    $msg = "🚨 *{$count} ticket(s) ALTA abiertos*\n¿Quieres ver la lista?";
    $buttons = [[
      ['text' => '📋 Ver tickets ALTA', 'callback_data' => 'list_alta_tickets'],
      ['text' => '⏭️ Después',           'callback_data' => 'skip_tickets'],
    ]];
    tgSend($token, $chatId, $msg, $buttons);
    $sentActions = true;
  }

  if (!$sentActions) {
    tgSend($token, $chatId, "✅ *Sin acciones pendientes hoy.* Buen día para prospección.");
  }

  return true;
}

// ─── WHATSAPP (TWILIO) ──────────────────────────────────────
function sendWhatsApp($config, $data) {
  $creds = $config['whatsapp_creds'];
  if (empty($creds['sid']) || empty($creds['token'])) {
    return false;
  }

  $body = buildBriefingText($data, 'text');
  if (mb_strlen($body) > 1500) {
    $body = mb_substr($body, 0, 1450) . "\n\n... abre LUNA para ver más";
  }

  $url = "https://api.twilio.com/2010-04-01/Accounts/{$creds['sid']}/Messages.json";
  $payload = http_build_query([
    'From' => $creds['from'],
    'To'   => 'whatsapp:' . $config['whatsapp_to'],
    'Body' => $body,
  ]);

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_USERPWD, $creds['sid'] . ':' . $creds['token']);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  $response = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  return $code >= 200 && $code < 300;
}

// ─── MAIN EXECUTION ─────────────────────────────────────────
$startTime = microtime(true);
logBriefing("=== LUNA Briefing started ===", $CONFIG);

$data = collectBriefingData($pdo);
$data['ai_analysis'] = lunaBriefingAnalysis($data); // null si no hay key/IA → usa fallback
logBriefing("AI analysis: " . ($data['ai_analysis'] ? 'OK' : 'skipped/unavailable'), $CONFIG);
$results = [];

if ($CONFIG['send_email']) {
  $r = sendEmail($CONFIG, $data);
  $results['email'] = $r ? 'OK' : 'FAILED';
  logBriefing("Email: " . $results['email'], $CONFIG);
}

if ($CONFIG['send_telegram']) {
  $r = sendTelegram($CONFIG, $data);
  $results['telegram'] = $r ? 'OK' : 'FAILED/NOT_CONFIGURED';
  logBriefing("Telegram: " . $results['telegram'], $CONFIG);
}

if ($CONFIG['send_whatsapp']) {
  $r = sendWhatsApp($CONFIG, $data);
  $results['whatsapp'] = $r ? 'OK' : 'FAILED/NOT_CONFIGURED';
  logBriefing("WhatsApp: " . $results['whatsapp'], $CONFIG);
}

$elapsed = round((microtime(true) - $startTime) * 1000);
logBriefing("=== Done in {$elapsed}ms — Results: " . json_encode($results) . " ===", $CONFIG);

// Output for manual testing
if (php_sapi_name() !== 'cli') {
  header('Content-Type: application/json');
  echo json_encode([
    'ok' => true,
    'data' => $data,
    'results' => $results,
    'elapsed_ms' => $elapsed,
  ], JSON_PRETTY_PRINT);
}
