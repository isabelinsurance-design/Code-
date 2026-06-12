<?php
/* ════════════════════════════════════════════════════════════════
   LUNA WEEKLY REPORT — Reporte Semanal de Viernes
   Medicare with Isabel
   
   USO:
   - Configurar cron en Bluehost: 0 17 * * 5 php /path/to/luna_weekly_cron.php
   - Cada viernes 5:00 PM
   - Mismo sistema de canales que el briefing diario
   
   COMPARA: esta semana vs semana anterior + acumulado mensual
════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/_cron_guard.php';  // 🔒 bloquea disparo por HTTP sin LUNA_CRON_TOKEN
require_once __DIR__ . '/../luna_config.php';  // ← config propio de LUNA
require_once __DIR__ . '/../luna_ai.php';       // cerebro IA (degradación elegante si no hay key)
require_once __DIR__ . '/../luna_radar.php';    // Radar de tendencias / Chief of Staff
require_once __DIR__ . '/../luna_meetings.php';  // Acuerdos y seguimiento de la junta

$CONFIG = [
  'send_email'    => true,
  'send_telegram' => true,
  'send_whatsapp' => false,

  'email_to'      => 'info@withisabelfuentes.com',
  'email_from'    => 'luna@withisabelfuentes.com',
  'email_name'    => 'LUNA Weekly Report',

  'telegram_token'   => 'XXXX:YYYY',
  'telegram_chat_id' => '0',

  'whatsapp_provider' => 'twilio',
  'whatsapp_to'       => '+13102700626',
  'whatsapp_creds'    => [
    'sid'   => '',
    'token' => '',
    'from'  => 'whatsapp:+14155238886',
  ],

  'timezone' => 'America/Los_Angeles',
  'log_file' => __DIR__ . '/luna_weekly_log.txt',
];

date_default_timezone_set($CONFIG['timezone']);

// ─── LOGGING ─────────────────────────────────────────────────
function logWeekly($msg, $config) {
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
  logWeekly("FATAL: DB connection failed - " . $e->getMessage(), $CONFIG);
  exit(1);
}

// ─── COLLECT WEEKLY DATA ─────────────────────────────────────
function collectWeeklyData($pdo) {
  $data = [];

  // Date ranges
  $today = new DateTime();
  $thisFriday = clone $today;
  // If today is not Friday, go to most recent Friday
  if ($thisFriday->format('w') != 5) {
    $thisFriday->modify('last friday');
  }
  $thisMonday = clone $thisFriday; $thisMonday->modify('-4 days');
  $lastFriday = clone $thisMonday; $lastFriday->modify('-3 days');
  $lastMonday = clone $lastFriday; $lastMonday->modify('-4 days');

  $thisStart = $thisMonday->format('Y-m-d');
  $thisEnd   = $thisFriday->format('Y-m-d');
  $lastStart = $lastMonday->format('Y-m-d');
  $lastEnd   = $lastFriday->format('Y-m-d');

  $data['week_start'] = $thisStart;
  $data['week_end']   = $thisEnd;
  $data['last_start'] = $lastStart;
  $data['last_end']   = $lastEnd;

  $data['week_label'] = $thisMonday->format('j ') . _spanishMonth($thisMonday->format('n')) .
                        ' al ' . $thisFriday->format('j ') . _spanishMonth($thisFriday->format('n'));

  // ──── ENROLLMENTS ────
  // This week
  $stmt = $pdo->prepare("
    SELECT COUNT(*) FROM miembros
    WHERE estado='ACTIVO' AND fecha_efectiva BETWEEN ? AND ?
  ");
  $stmt->execute([$thisStart, $thisEnd]);
  $data['enrollments_week'] = (int)$stmt->fetchColumn();

  $stmt->execute([$lastStart, $lastEnd]);
  $data['enrollments_last_week'] = (int)$stmt->fetchColumn();

  // This month total
  $thisMonthStart = $today->format('Y-m-01');
  $thisMonthEnd = $today->format('Y-m-t');
  $stmt = $pdo->prepare("
    SELECT COUNT(*) FROM miembros
    WHERE estado='ACTIVO' AND fecha_efectiva BETWEEN ? AND ?
  ");
  $stmt->execute([$thisMonthStart, $thisMonthEnd]);
  $data['enrollments_month'] = (int)$stmt->fetchColumn();

  // ──── CITAS ────
  $stmt = $pdo->prepare("
    SELECT COUNT(*) FROM citas
    WHERE estado='COMPLETADA' AND fecha BETWEEN ? AND ?
  ");
  $stmt->execute([$thisStart, $thisEnd]);
  $data['citas_week'] = (int)$stmt->fetchColumn();

  $stmt->execute([$lastStart, $lastEnd]);
  $data['citas_last_week'] = (int)$stmt->fetchColumn();

  // ──── TICKETS CERRADOS ────
  $stmt = $pdo->prepare("
    SELECT COUNT(*) FROM tickets
    WHERE estado='CERRADO' AND DATE(updated_at) BETWEEN ? AND ?
  ");
  try {
    $stmt->execute([$thisStart, $thisEnd]);
    $data['tickets_closed_week'] = (int)$stmt->fetchColumn();
    $stmt->execute([$lastStart, $lastEnd]);
    $data['tickets_closed_last_week'] = (int)$stmt->fetchColumn();
  } catch(Exception $e) {
    // Fallback if no updated_at
    $data['tickets_closed_week'] = 0;
    $data['tickets_closed_last_week'] = 0;
  }

  $data['tickets_open'] = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE estado!='CERRADO'")->fetchColumn();

  // ──── PIPELINE SUMMARY ────
  $data['pipeline'] = [];
  foreach ($pdo->query("SELECT estado, COUNT(*) AS total FROM miembros WHERE estado IS NOT NULL GROUP BY estado")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $data['pipeline'][$r['estado']] = (int)$r['total'];
  }

  // ──── HOT LEADS NOW vs 7 DAYS AGO ────
  $data['hot_leads_now'] = $data['pipeline']['HOT LEAD'] ?? 0;

  // ──── TEAM PERFORMANCE THIS WEEK ────
  // Activity by user (calls, contacts logged)
  $teamStmt = $pdo->prepare("
    SELECT u.id, u.nombre, u.iniciales, u.rol,
           (SELECT COUNT(*) FROM actividad a WHERE a.usuario_id = u.id
              AND DATE(a.fecha_hora) BETWEEN ? AND ?) AS actividades,
           (SELECT COUNT(*) FROM tickets t WHERE t.asignado_a = u.id
              AND t.estado = 'CERRADO'
              AND DATE(t.updated_at) BETWEEN ? AND ?) AS tickets_resueltos
    FROM usuarios u WHERE u.activo = 1
    ORDER BY u.rol DESC, u.nombre
  ");
  try {
    $teamStmt->execute([$thisStart, $thisEnd, $thisStart, $thisEnd]);
    $data['team'] = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
  } catch(Exception $e) {
    $data['team'] = [];
  }

  // ──── COMISIONES DEL MES ────
  try {
    $stmt = $pdo->prepare("
      SELECT
        COALESCE(SUM(monto), 0) AS total,
        COALESCE(SUM(CASE WHEN estado='PAGADA' THEN monto ELSE 0 END), 0) AS pagadas,
        COALESCE(SUM(CASE WHEN estado='PENDIENTE' THEN monto ELSE 0 END), 0) AS pendientes
      FROM comisiones
      WHERE DATE(fecha) BETWEEN ? AND ?
    ");
    $stmt->execute([$thisMonthStart, $thisMonthEnd]);
    $data['comisiones'] = $stmt->fetch(PDO::FETCH_ASSOC);
  } catch(Exception $e) {
    $data['comisiones'] = ['total' => 0, 'pagadas' => 0, 'pendientes' => 0];
  }

  // ──── COMPLIANCE ────
  $data['soa_pendiente'] = (int)$pdo->query("
    SELECT COUNT(*) FROM miembros m
    WHERE m.estado IN('ACTIVO','PENDIENTE')
      AND (SELECT COUNT(*) FROM soa s WHERE s.miembro_id=m.id AND s.estado='FIRMADO') = 0
  ")->fetchColumn();

  // ──── HOT LEADS COLD (this week's missed) ────
  $stmt = $pdo->prepare("
    SELECT COUNT(*) FROM miembros m
    WHERE m.estado='HOT LEAD'
      AND DATEDIFF(?, COALESCE(
        (SELECT MAX(DATE(a.fecha_hora)) FROM actividad a WHERE a.miembro_id=m.id),
        m.created_at
      )) >= 5
  ");
  $stmt->execute([$thisEnd]);
  $data['hot_leads_frios'] = (int)$stmt->fetchColumn();

  // ──── AEP PACE (only relevant Oct-Dec) ────
  $month = (int)$today->format('n');
  if ($month >= 10 && $month <= 12) {
    $aepStart = $today->format('Y') . '-10-15';
    $aepEnd   = $today->format('Y') . '-12-07';
    $aepDays  = (int)((strtotime($thisEnd) - strtotime($aepStart)) / 86400);
    $totalDays = 54;
    $expected_pace = ($aepDays / $totalDays) * 30; // assume 30 enrollments AEP target
    
    $stmt = $pdo->prepare("
      SELECT COUNT(*) FROM miembros
      WHERE estado='ACTIVO' AND fecha_efectiva BETWEEN ? AND ?
    ");
    $stmt->execute([$aepStart, $thisEnd]);
    $aep_actual = (int)$stmt->fetchColumn();

    $data['aep'] = [
      'days_elapsed' => $aepDays,
      'expected'     => round($expected_pace),
      'actual'       => $aep_actual,
      'pct'          => $expected_pace > 0 ? round(($aep_actual / $expected_pace) * 100) : 0,
    ];
  } else {
    $data['aep'] = null;
  }

  // ──── EVENTS ATTENDED THIS WEEK ────
  // (placeholder — depends on if you track events in CRM)
  $data['events_week'] = 0;

  // Date context
  $data['fecha_legible'] = $today->format('j') . ' de ' . _spanishMonth($today->format('n'));
  $data['dia_semana'] = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'][(int)$today->format('w')];

  return $data;
}

function _spanishMonth($n) {
  return ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'][(int)$n - 1];
}

// ─── ANÁLISIS IA (el "cerebro" del reporte semanal) ─────────
// Razona sobre los números reales (semana vs semana, comisiones, AEP) y
// entrega lectura estratégica + acciones. Devuelve texto o null si no hay IA.
function lunaWeeklyAnalysis($data) {
  if (!lunaAIEnabled()) return null;

  $system =
    "Eres LUNA, analista de negocio de Isabel Fuentes, agente de Medicare en el "
  . "Sur de California. Recibes los KPIs reales de la semana y entregas una lectura "
  . "estratégica para la dirección. REGLAS: usa SOLO los números que te doy (NUNCA "
  . "inventes cifras ni nombres); español, tono ejecutivo y claro. Estructura exacta: "
  . "primero 2-3 viñetas '•' interpretando qué pasó y por qué importa (tendencia vs "
  . "semana anterior, comisiones, ritmo AEP si aplica, riesgos de compliance); luego "
  . "una línea en blanco; después '🎯 Próxima semana:' con 3 acciones numeradas, "
  . "concretas y asignadas (Skarleth = ventas/hot leads, Samia = retención/servicio/"
  . "SOA, Isabel = T65/cierres/estrategia). Sé específica y accionable, no genérica.";

  $com = $data['comisiones'];
  $user =
    "KPIs de la semana del {$data['week_label']}:\n"
  . json_encode([
      'enrollments_week'        => $data['enrollments_week'],
      'enrollments_last_week'   => $data['enrollments_last_week'],
      'enrollments_month'       => $data['enrollments_month'],
      'citas_week'              => $data['citas_week'],
      'citas_last_week'         => $data['citas_last_week'],
      'tickets_closed_week'     => $data['tickets_closed_week'],
      'tickets_closed_last_week'=> $data['tickets_closed_last_week'],
      'tickets_open'            => $data['tickets_open'],
      'hot_leads_now'           => $data['hot_leads_now'],
      'hot_leads_frios'         => $data['hot_leads_frios'],
      'soa_pendiente'           => $data['soa_pendiente'],
      'comisiones_mes'          => ['total'=>$com['total'],'pagadas'=>$com['pagadas'],'pendientes'=>$com['pendientes']],
      'aep'                     => $data['aep'],
      'equipo'                  => array_map(fn($u) => "{$u['nombre']}: {$u['actividades']} actividades, {$u['tickets_resueltos']} tickets", $data['team'] ?? []),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
  . "\n\nDame la lectura estratégica y las acciones de la próxima semana.";

  return lunaAI($system, $user, 900);
}

// ─── AGENDA PARA LA JUNTA DE EQUIPO (SÁBADO) ─────────────────
// 3-4 puntos accionables sintetizando KPIs + radar. IA si hay key;
// si no, fallback determinista desde alertas/cambios/radar.
function lunaMeetingAgenda($data) {
  $radarTop = [];
  if (!empty($data['radar']['items'])) {
    foreach ($data['radar']['items'] as $it) {
      $radarTop[] = '[' . ($it['categoria'] ?? '') . '] ' . ($it['titulo'] ?? '');
      if (count($radarTop) >= 5) break;
    }
  }

  if (lunaAIEnabled()) {
    $system =
      "Eres LUNA, Chief of Staff de Isabel Fuentes (agencia de Medicare, Sur de California). "
      . "Prepara la AGENDA para la junta de equipo del SÁBADO. Devuelve 3-4 puntos NUMERADOS; "
      . "cada uno: tema corto + en 1 frase qué decidir o discutir y quién lo lleva "
      . "(Skarleth = ventas/hot leads, Samia = retención/servicio/SOA, Isabel = T65/cierres/"
      . "estrategia). Prioriza: alertas, cambios fuertes vs la semana pasada y el hallazgo #1 "
      . "del radar (mejora/Chief of Staff). Usa SOLO los datos dados, NUNCA inventes cifras. "
      . "Español, conciso y accionable. Formato exacto por línea: "
      . "'1. Tema — qué discutir (Responsable)'. NO uses asteriscos ni markdown.";
    $com = $data['comisiones'];
    $user =
      "Datos de la semana del {$data['week_label']}:\n"
      . json_encode([
          'enrollments_week'      => $data['enrollments_week'],
          'enrollments_last_week' => $data['enrollments_last_week'],
          'citas_week'            => $data['citas_week'],
          'tickets_open'          => $data['tickets_open'],
          'hot_leads_now'         => $data['hot_leads_now'],
          'hot_leads_frios'       => $data['hot_leads_frios'],
          'soa_pendiente'         => $data['soa_pendiente'],
          'comisiones_pendientes' => $com['pendientes'] ?? 0,
          'aep'                   => $data['aep'],
        ], JSON_UNESCAPED_UNICODE)
      . "\nResumen del radar (Chief of Staff): " . ($data['radar']['resumen'] ?? '—')
      . "\nHallazgos del radar: " . (implode(' | ', $radarTop) ?: '—')
      . "\nTareas pendientes de juntas anteriores: " . (
          !empty($data['open_actions'])
            ? implode(' | ', array_map(fn($a) => $a['accion'] . ' (' . ($a['responsable'] ?: 's/d') . ')', array_slice($data['open_actions'], 0, 6)))
            : 'ninguna'
        )
      . "\n\nDame la agenda (3-4 puntos) para la junta del sábado. Si hay pendientes sin cerrar, incluye un punto para revisarlos.";
    $out = lunaAI($system, $user, 500);
    if ($out) return trim($out);
  }

  // Fallback determinista (sin IA).
  $pts = [];
  if ($data['hot_leads_now'] >= 10)
    $pts[] = "Capacidad de cierre — {$data['hot_leads_now']} hot leads activos: repartir y agendar (Skarleth)";
  if ($data['hot_leads_frios'] > 0)
    $pts[] = "Recuperar {$data['hot_leads_frios']} hot leads que se enfriaron esta semana (Skarleth)";
  if ($data['soa_pendiente'] >= 3)
    $pts[] = "{$data['soa_pendiente']} SOAs sin firmar — plan para cerrarlos (Samia)";
  if ($data['aep'] && $data['aep']['pct'] < 80)
    $pts[] = "AEP por debajo del pace ({$data['aep']['pct']}%) — ajustar outreach (Isabel)";
  if (!empty($data['radar']['items'])) {
    foreach ($data['radar']['items'] as $it) {
      if (($it['categoria'] ?? '') === 'mejora') {
        $pts[] = "Mejora de la semana: " . ($it['titulo'] ?? '')
               . (!empty($it['accion']) ? ' — ' . $it['accion'] : '') . " (Isabel)";
        break;
      }
    }
  }
  if (empty($pts)) {
    $pts[] = "Repasar los números de la semana y celebrar lo que funcionó";
    $pts[] = "Definir el foco de la próxima semana (Isabel)";
    $pts[] = "Revisar el radar Chief of Staff y elegir 1 acción de mejora";
  }
  $pts = array_slice($pts, 0, 4);
  $txt = '';
  foreach ($pts as $k => $p) { $txt .= ($k + 1) . ". " . $p . "\n"; }
  return trim($txt);
}

// ─── ESCAPE para texto del modelo en email HTML ──────────────
function _wkEsc($s, $isHTML) {
  $s = (string)$s;
  return $isHTML ? htmlspecialchars($s, ENT_QUOTES, 'UTF-8') : $s;
}

// ─── COMPARE TWO NUMBERS ─────────────────────────────────────
function _compare($current, $previous) {
  if ($previous === 0) {
    if ($current === 0) return ['arrow' => '→', 'pct' => 0, 'word' => 'igual', 'color' => '#52606d'];
    return ['arrow' => '↑', 'pct' => 100, 'word' => 'nuevo', 'color' => '#16a34a'];
  }
  $diff = $current - $previous;
  $pct = round(($diff / $previous) * 100);
  if ($pct > 5) return ['arrow' => '↑', 'pct' => $pct, 'word' => 'sube', 'color' => '#16a34a'];
  if ($pct < -5) return ['arrow' => '↓', 'pct' => abs($pct), 'word' => 'baja', 'color' => '#dc2626'];
  return ['arrow' => '→', 'pct' => 0, 'word' => 'estable', 'color' => '#52606d'];
}

// ─── BUILD WEEKLY REPORT TEXT ────────────────────────────────
function buildWeeklyText($data, $format = 'text') {
  $isHTML = $format === 'html';
  $isMarkdown = $format === 'markdown';

  $br = $isHTML ? '<br>' : "\n";
  $b  = $isHTML ? ['<b>','</b>'] : ($isMarkdown ? ['*','*'] : ['','']);
  $i  = $isHTML ? ['<i>','</i>'] : ($isMarkdown ? ['_','_'] : ['','']);
  $hr = $isHTML ? '<hr style="border:none;border-top:1px solid #ddd;margin:14px 0">' : str_repeat('─', 30) . "\n";

  $out = '';

  // Header
  $out .= "{$b[0]}📊 REPORTE SEMANAL{$b[1]}{$br}";
  $out .= "{$i[0]}Semana del {$data['week_label']}{$i[1]}{$br}{$br}";

  // ═══ RESUMEN EJECUTIVO ═══
  $out .= "{$b[0]}═══ RESUMEN EJECUTIVO ═══{$b[1]}{$br}";
  $out .= "• {$b[0]}{$data['enrollments_week']}{$b[1]} enrollments nuevos{$br}";
  $out .= "• {$data['citas_week']} citas completadas{$br}";
  $out .= "• {$data['tickets_closed_week']} tickets cerrados{$br}";
  $out .= "• {$data['tickets_open']} tickets abiertos al cierre{$br}{$br}";

  // ═══ 🗓️ AGENDA — JUNTA DE EQUIPO (SÁBADO) ═══
  // Va arriba a propósito: es lo que Isabel usa para preparar la junta.
  if (!empty($data['meeting_agenda'])) {
    $out .= "{$b[0]}═══ 🗓️ AGENDA — JUNTA DE EQUIPO (SÁBADO) ═══{$b[1]}{$br}";
    $agenda = $isHTML ? _wkEsc($data['meeting_agenda'], true) : $data['meeting_agenda'];
    $out .= str_replace("\n", $br, $agenda) . $br . $br;
  }

  // ═══ 📌 SEGUIMIENTO — PENDIENTES DE LA JUNTA PASADA ═══
  if (!empty($data['open_actions'])) {
    $out .= "{$b[0]}═══ 📌 PENDIENTES DE LA JUNTA PASADA ═══{$b[1]}{$br}";
    foreach (array_slice($data['open_actions'], 0, 8) as $a) {
      $who = !empty($a['responsable']) ? ' (' . _wkEsc($a['responsable'], $isHTML) . ')' : '';
      $due = !empty($a['due_date']) ? ' · 📅 ' . _wkEsc($a['due_date'], $isHTML) . (($a['due_date'] < date('Y-m-d')) ? ' ⚠️ vencida' : '') : '';
      $out .= "• " . _wkEsc($a['accion'], $isHTML) . $who . $due . $br;
    }
    $out .= "{$i[0]}Márcalas en withisabelfuentes.com/luna/ → 🗓️ Junta{$i[1]}{$br}{$br}";
  }

  // ═══ COMPARACIÓN vs SEMANA ANTERIOR ═══
  $cmp_enroll = _compare($data['enrollments_week'], $data['enrollments_last_week']);
  $cmp_citas  = _compare($data['citas_week'], $data['citas_last_week']);
  $cmp_tix    = _compare($data['tickets_closed_week'], $data['tickets_closed_last_week']);

  $out .= "{$b[0]}═══ vs SEMANA ANTERIOR ═══{$b[1]}{$br}";
  $out .= "• Enrollments: {$data['enrollments_week']} vs {$data['enrollments_last_week']} ({$cmp_enroll['arrow']} {$cmp_enroll['pct']}%){$br}";
  $out .= "• Citas: {$data['citas_week']} vs {$data['citas_last_week']} ({$cmp_citas['arrow']} {$cmp_citas['pct']}%){$br}";
  $out .= "• Tickets: {$data['tickets_closed_week']} vs {$data['tickets_closed_last_week']} ({$cmp_tix['arrow']} {$cmp_tix['pct']}%){$br}{$br}";

  // ═══ EQUIPO ═══
  if (!empty($data['team'])) {
    $out .= "{$b[0]}═══ EQUIPO — esta semana ═══{$b[1]}{$br}";
    foreach ($data['team'] as $u) {
      $out .= "• {$b[0]}{$u['nombre']}{$b[1]}: {$u['actividades']} actividades, {$u['tickets_resueltos']} tickets resueltos{$br}";
    }
    $out .= $br;
  }

  // ═══ COMISIONES DEL MES ═══
  $com = $data['comisiones'];
  if ($com['total'] > 0 || $com['pagadas'] > 0 || $com['pendientes'] > 0) {
    $out .= "{$b[0]}═══ COMISIONES DEL MES ═══{$b[1]}{$br}";
    $out .= "• Total acumulado: \${$com['total']}{$br}";
    $out .= "• Pagadas: \${$com['pagadas']}{$br}";
    $out .= "• Pendientes de pago: \${$com['pendientes']}{$br}{$br}";
  }

  // ═══ AEP PACE (solo Oct-Dic) ═══
  if ($data['aep']) {
    $out .= "{$b[0]}═══ AEP PACE ═══{$b[1]}{$br}";
    $out .= "• Día {$data['aep']['days_elapsed']}/54 del AEP{$br}";
    $out .= "• Esperado a este punto: {$data['aep']['expected']} enrollments{$br}";
    $out .= "• Real: {$data['aep']['actual']} ({$data['aep']['pct']}% del pace){$br}";
    if ($data['aep']['pct'] < 80) {
      $out .= "{$i[0]}⚠️ Por debajo del pace — ajustar estrategia{$i[1]}{$br}";
    }
    $out .= $br;
  }

  // ═══ ALERTAS DE LA SEMANA ═══
  $out .= "{$b[0]}═══ ALERTAS ═══{$b[1]}{$br}";
  $hasAlerts = false;
  if ($data['soa_pendiente'] >= 3) {
    $out .= "• ⚠️ {$data['soa_pendiente']} SOAs sin firmar (riesgo auditoría){$br}";
    $hasAlerts = true;
  }
  if ($data['hot_leads_frios'] > 0) {
    $out .= "• 🥶 {$data['hot_leads_frios']} hot leads se enfriaron esta semana{$br}";
    $hasAlerts = true;
  }
  if ($data['hot_leads_now'] >= 10) {
    $out .= "• 🔥 {$data['hot_leads_now']} hot leads activos — capacidad de cierre{$br}";
    $hasAlerts = true;
  }
  if (!$hasAlerts) {
    $out .= "{$i[0]}Sin alertas críticas. Buen trabajo.{$i[1]}{$br}";
  }
  $out .= $br;

  // ═══ LECTURA ESTRATÉGICA + ACCIONES ═══
  // Si la IA está disponible, LUNA razona; si no, fallback determinista.
  if (!empty($data['ai_analysis'])) {
    $out .= "{$b[0]}═══ ANÁLISIS DE LUNA ═══{$b[1]}{$br}";
    $out .= str_replace("\n", $br, $data['ai_analysis']) . $br;
  } else {
    $out .= "{$b[0]}═══ ACCIONES PRÓXIMA SEMANA ═══{$b[1]}{$br}";
    $actions = [];
    if ($data['hot_leads_frios'] > 0) {
      $actions[] = "Reactivar {$data['hot_leads_frios']} hot leads fríos antes del miércoles";
    }
    if ($data['soa_pendiente'] >= 3) {
      $actions[] = "Cerrar SOAs pendientes — agenda 1 día solo para esto";
    }
    if ($cmp_enroll['word'] === 'baja') {
      $actions[] = "Enrollments cayeron {$cmp_enroll['pct']}% — revisar pipeline con Skarleth el lunes";
    }
    if ($data['aep'] && $data['aep']['pct'] < 80) {
      $actions[] = "AEP pace bajo — más outreach esta semana, especialmente martes y miércoles";
    }
    if (empty($actions)) {
      $actions[] = "Sostener el ritmo actual — todo va bien";
      $actions[] = "Buen momento para invertir en relaciones con doctores referidores";
      $actions[] = "Revisar memoria de LUNA y agregar lecciones aprendidas esta semana";
    }
    // Cap at 3 actions
    $actions = array_slice($actions, 0, 3);
    foreach ($actions as $idx => $a) {
      $out .= ($idx + 1) . ". {$a}{$br}";
    }
  }

  // ═══ 📡 RADAR — CHIEF OF STAFF ═══
  if (!empty($data['radar']) && !empty($data['radar']['items'])) {
    $rad = $data['radar'];
    $out .= "{$b[0]}═══ 📡 RADAR — CHIEF OF STAFF ═══{$b[1]}{$br}";
    if (!empty($rad['resumen'])) {
      $out .= "{$i[0]}" . _wkEsc($rad['resumen'], $isHTML) . "{$i[1]}{$br}{$br}";
    }
    // La lente "mejora" primero y completa (título + acción).
    $mejora = array_values(array_filter($rad['items'], fn($x) => ($x['categoria'] ?? '') === 'mejora'));
    $otros  = array_values(array_filter($rad['items'], fn($x) => ($x['categoria'] ?? '') !== 'mejora'));
    $indent = $isHTML ? '&nbsp;&nbsp;&nbsp;' : '   ';
    foreach ($mejora as $it) {
      $out .= "• {$b[0]}" . _wkEsc($it['titulo'] ?? '', $isHTML) . "{$b[1]}{$br}";
      if (!empty($it['accion'])) $out .= "{$indent}→ " . _wkEsc($it['accion'], $isHTML) . "{$br}";
    }
    // El resto del radar, solo titulares por lente.
    if ($otros) {
      $out .= $br . "{$i[0]}También en el radar:{$i[1]}{$br}";
      foreach ($otros as $it) {
        $cat = strtoupper((string)($it['categoria'] ?? ''));
        $out .= "• [{$cat}] " . _wkEsc($it['titulo'] ?? '', $isHTML) . "{$br}";
      }
    }
    $out .= "{$br}{$i[0]}Radar completo en withisabelfuentes.com/luna/ → 📡 Radar{$i[1]}{$br}{$br}";
  }

  $out .= $br . $hr;
  $out .= "{$i[0]}— LUNA · Reporte automático · viernes {$data['fecha_legible']}{$i[1]}{$br}";
  $out .= "{$i[0]}withisabelfuentes.com/luna/{$i[1]}";

  return $out;
}

// ─── EMAIL ──────────────────────────────────────────────────
function sendWeeklyEmail($config, $data) {
  $subject = "📊 LUNA Reporte Semanal — Semana {$data['week_label']}";
  $body = buildWeeklyText($data, 'html');

  $htmlBody = '<!DOCTYPE html><html><head><meta charset="utf-8"></head>
  <body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#f5f5f7;padding:20px;margin:0;color:#0d1117;">
    <div style="max-width:640px;margin:0 auto;background:#fff;border-radius:14px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
      <div style="background:linear-gradient(135deg,#0097a7,#1a56ff);color:#fff;padding:20px 24px;border-radius:11px;margin-bottom:18px;">
        <div style="font-size:11px;font-weight:700;letter-spacing:2px;opacity:.8;text-transform:uppercase;margin-bottom:4px;">📊 LUNA Reporte Semanal</div>
        <div style="font-size:22px;font-weight:700;font-family:Georgia,serif;">Semana ' . $data['week_label'] . '</div>
      </div>
      <div style="font-size:13px;line-height:1.8;color:#3a3a3c;">' . $body . '</div>
      <div style="margin-top:24px;padding-top:14px;border-top:1px solid #e5e5ea;text-align:center;font-size:11px;color:#8e8e93;">
        Generado automáticamente cada viernes · Medicare with Isabel<br>
        <a href="https://withisabelfuentes.com/luna/" style="color:#0097a7;text-decoration:none;font-weight:600;">Abrir LUNA →</a>
      </div>
    </div>
  </body></html>';

  $headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    'From: ' . $config['email_name'] . ' <' . $config['email_from'] . '>',
    'Reply-To: ' . $config['email_from'],
    'X-Mailer: LUNA-WeeklyReport',
  ];

  return @mail($config['email_to'], $subject, $htmlBody, implode("\r\n", $headers));
}

// ─── TELEGRAM ───────────────────────────────────────────────
function sendWeeklyTelegram($config, $data) {
  $token = $config['telegram_token'];
  $chatId = $config['telegram_chat_id'];

  if (empty($token) || $token === 'XXXX:YYYY' || empty($chatId) || $chatId === '0') {
    return false;
  }

  $body = buildWeeklyText($data, 'markdown');
  if (mb_strlen($body) > 4000) {
    $body = mb_substr($body, 0, 3950) . "\n\n_... mensaje truncado, abre LUNA para detalles_";
  }

  $url = "https://api.telegram.org/bot{$token}/sendMessage";
  $payload = http_build_query([
    'chat_id'    => $chatId,
    'text'       => $body,
    'parse_mode' => 'Markdown',
    'disable_web_page_preview' => true,
  ]);

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 15);
  curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  return $code === 200;
}

// ─── WHATSAPP ───────────────────────────────────────────────
function sendWeeklyWhatsApp($config, $data) {
  $creds = $config['whatsapp_creds'];
  if (empty($creds['sid']) || empty($creds['token'])) {
    return false;
  }

  $body = buildWeeklyText($data, 'text');
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
  curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  return $code >= 200 && $code < 300;
}

// ─── MAIN ──────────────────────────────────────────────────
$startTime = microtime(true);
logWeekly("=== LUNA Weekly Report started ===", $CONFIG);

$data = collectWeeklyData($pdo);
$data['ai_analysis'] = lunaWeeklyAnalysis($data); // null si no hay key/IA → usa fallback
logWeekly("AI analysis: " . ($data['ai_analysis'] ? 'OK' : 'skipped/unavailable'), $CONFIG);

// 📡 Radar Chief of Staff para el reporte. Como el reporte sale el viernes y
// la junta de equipo (Teams) es el sábado, el radar debe estar FRESCO ese día.
// Generamos uno nuevo el viernes; solo reusamos si ya se generó hoy (mismo día)
// para no duplicar trabajo si otro cron corrió esta mañana.
$data['radar'] = null;
try {
  $latest = radarLatest($pdo, 'weekly');
  $todayHB = $latest && !empty($latest['created_at']) && substr($latest['created_at'], 0, 10) === date('Y-m-d');
  $data['radar'] = $todayHB ? $latest : radarRun($pdo, 'weekly');
} catch (Exception $e) {
  logWeekly("Radar weekly: error - " . $e->getMessage(), $CONFIG);
}
logWeekly("Radar weekly: " . (!empty($data['radar']['items']) ? count($data['radar']['items']) . ' items' : 'none'), $CONFIG);

// Tareas pendientes de juntas anteriores (seguimiento).
try { $data['open_actions'] = meetingOpenActions($pdo); }
catch (Exception $e) { $data['open_actions'] = []; }
logWeekly("Open meeting actions: " . count($data['open_actions']), $CONFIG);

// Agenda para la junta del sábado (usa KPIs + radar ya cargados).
$data['meeting_agenda'] = lunaMeetingAgenda($data);
logWeekly("Meeting agenda: " . ($data['meeting_agenda'] ? 'OK' : 'none'), $CONFIG);

$results = [];

if ($CONFIG['send_email']) {
  $r = sendWeeklyEmail($CONFIG, $data);
  $results['email'] = $r ? 'OK' : 'FAILED';
  logWeekly("Email: " . $results['email'], $CONFIG);
}

if ($CONFIG['send_telegram']) {
  $r = sendWeeklyTelegram($CONFIG, $data);
  $results['telegram'] = $r ? 'OK' : 'FAILED/NOT_CONFIGURED';
  logWeekly("Telegram: " . $results['telegram'], $CONFIG);
}

if ($CONFIG['send_whatsapp']) {
  $r = sendWeeklyWhatsApp($CONFIG, $data);
  $results['whatsapp'] = $r ? 'OK' : 'FAILED/NOT_CONFIGURED';
  logWeekly("WhatsApp: " . $results['whatsapp'], $CONFIG);
}

$elapsed = round((microtime(true) - $startTime) * 1000);
logWeekly("=== Done in {$elapsed}ms — Results: " . json_encode($results) . " ===", $CONFIG);

if (php_sapi_name() !== 'cli') {
  header('Content-Type: application/json');
  echo json_encode([
    'ok' => true,
    'data' => $data,
    'results' => $results,
    'elapsed_ms' => $elapsed,
  ], JSON_PRETTY_PRINT);
}
