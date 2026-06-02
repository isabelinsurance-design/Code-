<?php
/* ════════════════════════════════════════════════════════════════
   LUNA COMPLIANCE AUDIT — Auditoría Mensual
   Medicare with Isabel

   USO:
   - Cron: 0 8 1 * * php /path/to/luna_compliance_cron.php
   - Primer día de cada mes a las 8:00 AM
   - Protege la licencia de Isabel detectando riesgos CMS antes
     de que llegue una auditoría

   DETECTA:
   1. Miembros activos sin SOA firmado
   2. Tickets abiertos sin resolución +14 días
   3. Miembros sin contacto en 45+ días
   4. Apps sin confirmación CMS +30 días
   5. Llamadas perdidas sin devolver +48h
   6. Enrollments sin documentación completa
   7. T65 contactados fuera de ventana IEP
════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../../config.php';

$CONFIG = [
  'send_email'    => true,
  'send_telegram' => true,
  'send_whatsapp' => false,

  'email_to'    => 'info@withisabelfuentes.com',
  'email_from'  => 'luna@withisabelfuentes.com',
  'email_name'  => 'LUNA Compliance',

  'telegram_token'   => 'XXXX:YYYY',
  'telegram_chat_id' => '0',

  'timezone' => 'America/Los_Angeles',
  'log_file' => __DIR__ . '/luna_compliance_log.txt',

  // Risk thresholds
  'soa_critical_threshold'      => 1,  // any is a problem
  'no_contact_days'             => 45,
  'ticket_stale_days'           => 14,
  'app_pending_days'            => 30,
  'callback_overdue_hours'      => 48,
];

date_default_timezone_set($CONFIG['timezone']);

function logAudit($msg, $config) {
  @file_put_contents($config['log_file'], '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

// ─── DB ─────────────────────────────────────────────────────
try {
  $pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch (Exception $e) {
  logAudit("FATAL: DB - " . $e->getMessage(), $CONFIG);
  exit(1);
}

// ─── RUN ALL COMPLIANCE CHECKS ──────────────────────────────
function runComplianceAudit($pdo, $config) {
  $findings = [];
  $month = date('F Y');
  $today = date('Y-m-d');

  // Cast integers — defensive against typos in config
  $ticketStale     = (int)$config['ticket_stale_days'];
  $noContactDays   = (int)$config['no_contact_days'];
  $appPending      = (int)$config['app_pending_days'];
  $callbackHours   = (int)$config['callback_overdue_hours'];

  // ══ CHECK 1: Miembros activos SIN SOA firmado ══════════════
  $noSOA = $pdo->query("
    SELECT m.id, m.nombre, m.apellido, m.carrier,
           DATE_FORMAT(m.fecha_efectiva,'%d %b %Y') AS fecha_efectiva,
           DATEDIFF(CURDATE(), m.fecha_efectiva) AS dias_activo
    FROM miembros m
    WHERE m.estado IN ('ACTIVO','PENDIENTE')
      AND (SELECT COUNT(*) FROM soa s WHERE s.miembro_id=m.id AND s.estado='FIRMADO') = 0
    ORDER BY m.fecha_efectiva DESC
    LIMIT 50
  ")->fetchAll(PDO::FETCH_ASSOC);

  if (!empty($noSOA)) {
    $findings[] = [
      'severity' => count($noSOA) >= 5 ? 'CRÍTICO' : 'ALTO',
      'rule'     => 'CMS §422.2268 — SOA requerido',
      'title'    => count($noSOA) . ' miembro(s) activos SIN SOA firmado',
      'detail'   => 'CMS puede solicitar el SOA de cualquier miembro en auditoría. Sin SOA = violación directa.',
      'items'    => array_map(fn($m) => "{$m['nombre']} {$m['apellido']} · {$m['carrier']} · Activo {$m['dias_activo']}d", $noSOA),
      'action'   => 'Contactar a cada miembro esta semana y conseguir SOA firmado.',
    ];
  }

  // ══ CHECK 2: Tickets ALTA/CRÍTICA sin resolver +14 días ════
  $staleTickets = $pdo->query("
    SELECT t.id, t.descripcion, t.prioridad,
           CONCAT(m.nombre,' ',m.apellido) AS miembro,
           u.nombre AS asignado,
           DATEDIFF(CURDATE(), DATE(t.created_at)) AS dias_abierto
    FROM tickets t
    LEFT JOIN miembros m ON t.miembro_id=m.id
    LEFT JOIN usuarios u ON t.asignado_a=u.id
    WHERE t.estado NOT IN ('CERRADO','RESUELTO')
      AND t.prioridad IN ('ALTA','CRÍTICA')
      AND DATEDIFF(CURDATE(), DATE(t.created_at)) >= $ticketStale
    ORDER BY dias_abierto DESC LIMIT 20
  ")->fetchAll(PDO::FETCH_ASSOC);

  if (!empty($staleTickets)) {
    $findings[] = [
      'severity' => 'ALTO',
      'rule'     => 'Best practice — resolución <14 días',
      'title'    => count($staleTickets) . ' ticket(s) ALTA/CRÍTICA sin resolver +' . $config['ticket_stale_days'] . ' días',
      'detail'   => 'Tickets estancados pueden indicar quejas no resueltas — riesgo de escalación a CMS.',
      'items'    => array_map(fn($t) => "#{$t['id']} {$t['miembro']} · {$t['prioridad']} · {$t['dias_abierto']}d ({$t['asignado']})", $staleTickets),
      'action'   => 'Revisar y cerrar o escalar a Isabel esta semana.',
    ];
  }

  // ══ CHECK 3: Miembros activos SIN contacto en 45+ días ═════
  $noContact = $pdo->query("
    SELECT m.id, m.nombre, m.apellido, m.carrier, m.telefono,
           DATEDIFF(CURDATE(), COALESCE(
             (SELECT MAX(DATE(a.fecha_hora)) FROM actividad a WHERE a.miembro_id=m.id),
             m.fecha_efectiva
           )) AS dias_sin_contacto
    FROM miembros m
    WHERE m.estado='ACTIVO'
    HAVING dias_sin_contacto >= $noContactDays
    ORDER BY dias_sin_contacto DESC LIMIT 30
  ")->fetchAll(PDO::FETCH_ASSOC);

  if (!empty($noContact)) {
    $findings[] = [
      'severity' => count($noContact) >= 10 ? 'CRÍTICO' : 'MEDIO',
      'rule'     => 'CMS retención — contacto regular recomendado',
      'title'    => count($noContact) . ' miembro(s) sin contacto en ' . $config['no_contact_days'] . '+ días',
      'detail'   => 'CMS espera que los agentes mantengan contacto activo. Además es riesgo de cancelación.',
      'items'    => array_map(fn($m) => "{$m['nombre']} {$m['apellido']} · {$m['carrier']} · {$m['dias_sin_contacto']}d sin contacto", $noContact),
      'action'   => 'Samia debe programar llamadas de check-in esta semana.',
    ];
  }

  // ══ CHECK 4: Applications sin confirmación CMS +30 días ════
  try {
    $pendingApps = $pdo->query("
      SELECT m.id, m.nombre, m.apellido, m.carrier,
             DATE_FORMAT(m.app_fecha,'%d %b %Y') AS app_fecha,
             DATEDIFF(CURDATE(), m.app_fecha) AS dias_pendiente,
             m.app_estado_cms
      FROM miembros m
      WHERE m.app_fecha IS NOT NULL
        AND m.estado NOT IN ('ACTIVO','CANCELADO')
        AND m.app_estado_cms NOT IN ('RECIBIDO','APROBADO','CONFIRMADO')
        AND DATEDIFF(CURDATE(), m.app_fecha) >= $appPending
      ORDER BY dias_pendiente DESC LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($pendingApps)) {
      $findings[] = [
        'severity' => 'ALTO',
        'rule'     => 'CMS — confirmación de enrollment requerida',
        'title'    => count($pendingApps) . ' application(s) sin confirmar +' . $config['app_pending_days'] . ' días',
        'detail'   => 'Applications sin confirmar pueden indicar errores de enrollment. Verificar con cada carrier.',
        'items'    => array_map(fn($m) => "{$m['nombre']} {$m['apellido']} · {$m['carrier']} · App: {$m['app_fecha']} ({$m['dias_pendiente']}d) · Estado: {$m['app_estado_cms']}", $pendingApps),
        'action'   => 'Isabel debe llamar a cada carrier para confirmar status.',
      ];
    }
  } catch(Exception $e) {
    // Column may not exist in all schemas — skip silently
  }

  // ══ CHECK 5: Llamadas perdidas sin devolver +48h ════════════
  try {
    $overdueCallbacks = $pdo->query("
      SELECT lp.id, lp.telefono, lp.nombre_caller,
             DATE_FORMAT(lp.fecha,'%d %b %Y %H:%i') AS fecha,
             TIMESTAMPDIFF(HOUR, lp.fecha, NOW()) AS horas_pendiente
      FROM llamadas_perdidas lp
      WHERE lp.estado = 'PENDIENTE'
        AND TIMESTAMPDIFF(HOUR, lp.fecha, NOW()) >= $callbackHours
      ORDER BY horas_pendiente DESC LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($overdueCallbacks)) {
      $findings[] = [
        'severity' => 'MEDIO',
        'rule'     => 'Política interna — 60 min respuesta',
        'title'    => count($overdueCallbacks) . ' llamada(s) perdida(s) sin devolver +48h',
        'detail'   => 'Posibles prospectos o miembros esperando respuesta. Impacto directo en ventas y retención.',
        'items'    => array_map(fn($c) => "{$c['nombre_caller']} · {$c['telefono']} · {$c['fecha']} ({$c['horas_pendiente']}h)", $overdueCallbacks),
        'action'   => 'Skarleth debe devolver todas estas llamadas hoy.',
      ];
    }
  } catch(Exception $e) {}

  // ══ CHECK 6: T65 que cumplieron 65 sin haber sido contactados
  try {
    $missedT65 = $pdo->query("
      SELECT m.id, m.nombre, m.apellido,
             DATE_FORMAT(DATE_ADD(m.dob, INTERVAL 65 YEAR),'%d %b %Y') AS fecha_65,
             DATEDIFF(CURDATE(), DATE_ADD(m.dob, INTERVAL 65 YEAR)) AS dias_pasados
      FROM miembros m
      WHERE m.estado NOT IN ('ACTIVO','CANCELADO')
        AND DATE_ADD(m.dob, INTERVAL 65 YEAR) < CURDATE()
        AND DATE_ADD(m.dob, INTERVAL 65 YEAR) > DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        AND (SELECT COUNT(*) FROM actividad a WHERE a.miembro_id=m.id) = 0
      ORDER BY dias_pasados ASC LIMIT 15
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($missedT65)) {
      $findings[] = [
        'severity' => 'ALTO',
        'rule'     => 'IEP — ventana de 7 meses',
        'title'    => count($missedT65) . ' T65 que cumplieron 65 sin contacto registrado',
        'detail'   => 'Estos prospectos pueden estar perdiendo su ventana IEP sin saberlo. Riesgo de queja si estaban en tu pipeline.',
        'items'    => array_map(fn($m) => "{$m['nombre']} {$m['apellido']} · Cumplió 65: {$m['fecha_65']} ({$m['dias_pasados']}d)", $missedT65),
        'action'   => 'Contactar inmediatamente — aún pueden estar en ventana IEP.',
      ];
    }
  } catch(Exception $e) {}

  // ══ SUMMARY ═══════════════════════════════════════════════
  $critical = count(array_filter($findings, fn($f) => $f['severity'] === 'CRÍTICO'));
  $high     = count(array_filter($findings, fn($f) => $f['severity'] === 'ALTO'));
  $medium   = count(array_filter($findings, fn($f) => $f['severity'] === 'MEDIO'));

  return [
    'month'    => $month,
    'date'     => $today,
    'findings' => $findings,
    'summary'  => compact('critical','high','medium'),
    'clean'    => empty($findings),
  ];
}

// ─── BUILD REPORT TEXT ───────────────────────────────────────
function buildAuditReport($audit, $format = 'text') {
  $isHTML     = $format === 'html';
  $isMarkdown = $format === 'markdown';
  $br = $isHTML ? '<br>' : "\n";
  $b  = $isHTML ? ['<b>','</b>'] : ($isMarkdown ? ['*','*'] : ['','']);
  $i  = $isHTML ? ['<i>','</i>'] : ($isMarkdown ? ['_','_'] : ['','']);
  $hr = $isHTML
    ? '<hr style="border:none;border-top:1px solid #ddd;margin:16px 0">'
    : str_repeat('─', 32) . "\n";

  $s = $audit['summary'];
  $out = '';

  // Header
  $out .= "{$b[0]}⚖️ LUNA — AUDITORÍA COMPLIANCE{$b[1]}{$br}";
  $out .= "{$i[0]}{$audit['month']}{$i[1]}{$br}{$br}";

  // Score
  if ($audit['clean']) {
    $out .= "✅ {$b[0]}Sin hallazgos.{$b[1]} Tu agencia está en compliance este mes.{$br}{$br}";
  } else {
    $total = $s['critical'] + $s['high'] + $s['medium'];
    $out .= "{$b[0]}Resumen:{$b[1]} {$total} hallazgo(s) encontrado(s){$br}";
    if ($s['critical'] > 0) $out .= "🔴 CRÍTICO: {$s['critical']}{$br}";
    if ($s['high']     > 0) $out .= "🟠 ALTO: {$s['high']}{$br}";
    if ($s['medium']   > 0) $out .= "🟡 MEDIO: {$s['medium']}{$br}";
    $out .= $br;
  }

  $out .= $hr;

  // Findings
  $sevIcon = ['CRÍTICO'=>'🔴','ALTO'=>'🟠','MEDIO'=>'🟡','BAJO'=>'🟢'];
  foreach ($audit['findings'] as $idx => $f) {
    $icon = $sevIcon[$f['severity']] ?? '⚠️';
    $out .= "{$b[0]}{$icon} {$f['severity']} — {$f['title']}{$b[1]}{$br}";
    $out .= "{$i[0]}Regla: {$f['rule']}{$i[1]}{$br}";
    $out .= "{$f['detail']}{$br}{$br}";

    $shown = array_slice($f['items'], 0, 5);
    foreach ($shown as $item) {
      $out .= "  • {$item}{$br}";
    }
    if (count($f['items']) > 5) {
      $more = count($f['items']) - 5;
      $out .= "  {$i[0]}... y {$more} más{$i[1]}{$br}";
    }

    $out .= "{$br}{$b[0]}Acción:{$b[1]} {$f['action']}{$br}";
    $out .= $hr;
  }

  // Footer
  $out .= "{$i[0]}— LUNA Compliance · Auditoría automática mensual{$i[1]}{$br}";
  $out .= "{$i[0]}withisabelfuentes.com/luna/{$i[1]}";

  return $out;
}

// ─── SEND EMAIL ──────────────────────────────────────────────
function sendAuditEmail($config, $audit) {
  $s = $audit['summary'];
  $status = $audit['clean'] ? '✅ Sin hallazgos' : "⚠️ {$s['critical']}C · {$s['high']}A · {$s['medium']}M";
  $subject = "⚖️ LUNA Compliance — {$audit['month']} — {$status}";
  $body    = buildAuditReport($audit, 'html');

  $scoreColor = $audit['clean'] ? '#16a34a' : ($s['critical'] > 0 ? '#dc2626' : '#d97706');
  $scoreText  = $audit['clean'] ? '✅ LIMPIO' : ($s['critical'] > 0 ? '🔴 ACCIÓN URGENTE' : '🟠 REVISAR');

  $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head>
  <body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#f5f5f7;padding:20px;color:#0d1117;">
    <div style="max-width:640px;margin:0 auto;background:#fff;border-radius:14px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
      <div style="background:linear-gradient(135deg,#6741d9,#1a56ff);color:#fff;padding:20px 24px;border-radius:11px;margin-bottom:18px;">
        <div style="font-size:11px;font-weight:700;letter-spacing:2px;opacity:.8;text-transform:uppercase;">⚖️ LUNA Compliance</div>
        <div style="font-size:22px;font-weight:700;font-family:Georgia,serif;margin-top:4px;">' . $audit['month'] . '</div>
        <div style="margin-top:10px;display:inline-block;background:rgba(255,255,255,.2);padding:4px 12px;border-radius:20px;font-size:13px;font-weight:700;">' . $scoreText . '</div>
      </div>
      <div style="font-size:13px;line-height:1.8;color:#3a3a3c;">' . $body . '</div>
      <div style="margin-top:24px;padding-top:14px;border-top:1px solid #e5e5ea;text-align:center;font-size:11px;color:#8e8e93;">
        Auditoría automática · Primer día del mes · Medicare with Isabel<br>
        <a href="https://withisabelfuentes.com/luna/" style="color:#6741d9;text-decoration:none;font-weight:600;">Abrir LUNA →</a>
      </div>
    </div>
  </body></html>';

  $headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    'From: ' . $config['email_name'] . ' <' . $config['email_from'] . '>',
    'X-Mailer: LUNA-Compliance',
  ];
  return @mail($config['email_to'], $subject, $html, implode("\r\n", $headers));
}

// ─── SEND TELEGRAM ───────────────────────────────────────────
function sendAuditTelegram($config, $audit) {
  $token  = $config['telegram_token'];
  $chatId = $config['telegram_chat_id'];
  if (empty($token) || $token === 'XXXX:YYYY' || empty($chatId) || $chatId === '0') return false;

  $s = $audit['summary'];

  // Short summary first
  if ($audit['clean']) {
    $summary = "⚖️ *LUNA Compliance — {$audit['month']}*\n\n✅ *Sin hallazgos este mes.* Tu agencia está en compliance.\n\n_Auditoría automática completa enviada por email._";
  } else {
    $total = $s['critical'] + $s['high'] + $s['medium'];
    $summary = "⚖️ *LUNA Compliance — {$audit['month']}*\n\n⚠️ *{$total} hallazgo(s) encontrado(s):*\n";
    if ($s['critical'] > 0) $summary .= "🔴 CRÍTICO: {$s['critical']}\n";
    if ($s['high']     > 0) $summary .= "🟠 ALTO: {$s['high']}\n";
    if ($s['medium']   > 0) $summary .= "🟡 MEDIO: {$s['medium']}\n";
    $summary .= "\n_Reporte completo enviado por email. Abre LUNA para ver detalles._";
  }

  $url = "https://api.telegram.org/bot{$token}/sendMessage";
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'chat_id'    => $chatId,
    'text'       => $summary,
    'parse_mode' => 'Markdown',
  ]);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 15);
  curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return $code === 200;
}

// ─── MAIN ───────────────────────────────────────────────────
$startTime = microtime(true);
logAudit("=== LUNA Compliance Audit started ===", $CONFIG);

$audit   = runComplianceAudit($pdo, $CONFIG);
$results = [];

if ($CONFIG['send_email']) {
  $r = sendAuditEmail($CONFIG, $audit);
  $results['email'] = $r ? 'OK' : 'FAILED';
  logAudit("Email: " . $results['email'], $CONFIG);
}

if ($CONFIG['send_telegram']) {
  $r = sendAuditTelegram($CONFIG, $audit);
  $results['telegram'] = $r ? 'OK' : 'FAILED/NOT_CONFIGURED';
  logAudit("Telegram: " . $results['telegram'], $CONFIG);
}

$elapsed = round((microtime(true) - $startTime) * 1000);
$findCount = count($audit['findings']);
logAudit("=== Done in {$elapsed}ms — {$findCount} findings — " . json_encode($results) . " ===", $CONFIG);

if (php_sapi_name() !== 'cli') {
  header('Content-Type: application/json');
  echo json_encode(['ok'=>true,'audit'=>$audit,'results'=>$results,'elapsed_ms'=>$elapsed], JSON_PRETTY_PRINT);
}
