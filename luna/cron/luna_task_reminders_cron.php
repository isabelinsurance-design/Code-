<?php
/* ════════════════════════════════════════════════════════════════
   LUNA TASK REMINDERS CRON — Recordatorios de tareas de la junta
   Medicare with Isabel

   Cada mañana, LUNA avisa a cada responsable (Skarleth/Samia/Isabel/…)
   las tareas de la junta que vencen hoy/mañana o que ya están vencidas,
   y le manda a Isabel un resumen de todo. Una vez por día por tarea
   (nudge diario hasta que se marque hecha o cancelada).

   USO (Bluehost crontab):
     0 8 * * *  php /path/to/public_html/luna/cron/luna_task_reminders_cron.php

   IMPORTANTE: completa los correos del equipo en $REMIND['team'] abajo.
   Si un nombre no tiene correo, su tarea igual aparece en el resumen de
   Isabel (marcada "sin correo") y se reintenta al día siguiente.
════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../luna_meetings.php';

date_default_timezone_set('America/Los_Angeles');
$LOG = __DIR__ . '/luna_task_reminders_log.txt';
function logRem($m) { global $LOG; @file_put_contents($LOG, '['.date('Y-m-d H:i:s').'] '.$m."\n", FILE_APPEND); }

$REMIND = [
  'within_days' => 1,                              // vence hoy/mañana o ya vencida
  'from'        => 'luna@withisabelfuentes.com',
  'from_name'   => 'LUNA',
  'admin_to'    => 'info@withisabelfuentes.com',   // resumen para Isabel
  // Nombre como se escribe en la tarea  =>  correo. Completa los que falten.
  'team' => [
    'Isabel'   => 'info@withisabelfuentes.com',
    'Skarleth' => '',
    'Samia'    => '',
    'Arlette'  => '',
  ],
];

try {
  $pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch (Exception $e) {
  logRem('FATAL: DB - ' . $e->getMessage());
  exit(1);
}

// Resuelve el correo de un responsable: primero el mapa, luego usuarios.email.
function remEmail(PDO $pdo, string $name, array $team): string {
  $name = trim($name);
  if ($name === '') return '';
  foreach ($team as $k => $v) { if ($v && strcasecmp($k, $name) === 0) return $v; }
  try {
    $st = $pdo->prepare("SELECT email FROM usuarios WHERE nombre LIKE ? AND activo=1 LIMIT 1");
    $st->execute([$name . '%']);
    $e = (string)$st->fetchColumn();
    if (strpos($e, '@') !== false) return $e;
  } catch (Exception $e) { /* usuarios sin columna email */ }
  return '';
}

function remSendHTML(string $to, string $subject, string $html, array $cfg): bool {
  $headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    'From: ' . $cfg['from_name'] . ' <' . $cfg['from'] . '>',
  ];
  return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, implode("\r\n", $headers));
}

function remTaskLine(array $a): array {
  $today = date('Y-m-d');
  $overdue = $a['due_date'] < $today;
  $when = $overdue ? 'VENCIDA' : ($a['due_date'] === $today ? 'vence HOY' : 'vence ' . $a['due_date']);
  return [$overdue, $when];
}

$due = meetingActionsDue($pdo, $REMIND['within_days']);
if (!$due) {
  logRem('Sin tareas por recordar hoy.');
  exit(0);
}

// Agrupa por responsable.
$byResp = [];
foreach ($due as $a) {
  $r = trim((string)($a['responsable'] ?? '')) ?: '—';
  $byResp[$r][] = $a;
}

$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$sentTo = []; $noEmail = [];

foreach ($byResp as $resp => $tasks) {
  $email = $resp === '—' ? '' : remEmail($pdo, $resp, $REMIND['team']);
  if ($email === '') { $noEmail[] = $resp; continue; }

  $rows = '';
  foreach ($tasks as $a) {
    [$overdue, $when] = remTaskLine($a);
    $color = $overdue ? '#dc2626' : '#d97706';
    $rows .= '<tr><td style="padding:9px 0;border-top:1px solid #eee;font-size:14px;color:#1a2730;">'
           . $esc($a['accion']) . '<br><span style="font-size:12px;color:' . $color . ';font-weight:700;">'
           . $esc($when) . '</span> <span style="font-size:11px;color:#8a97a3;">· junta ' . $esc($a['meeting_date']) . '</span></td></tr>';
  }
  $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#1a2730;">'
        . '<div style="background:#0f2233;color:#fff;padding:18px 22px;border-radius:12px 12px 0 0;">'
        . '<div style="font-size:12px;letter-spacing:1px;color:#8fb3c9;">LUNA · RECORDATORIO</div>'
        . '<div style="font-size:19px;font-weight:800;margin-top:3px;">Hola ' . $esc($resp) . ' 👋</div></div>'
        . '<div style="border:1px solid #e2e8ee;border-top:none;border-radius:0 0 12px 12px;padding:20px 22px;">'
        . '<div style="font-size:14px;margin-bottom:8px;">Tienes ' . count($tasks) . ' tarea(s) de la junta por atender:</div>'
        . '<table style="width:100%;border-collapse:collapse;">' . $rows . '</table>'
        . '<div style="margin-top:18px;font-size:12px;color:#8a97a3;">Cuando la completes, márcala en withisabelfuentes.com/luna/ → 🗓️ Junta.</div>'
        . '</div></div>';

  $subj = '⏰ ' . count($tasks) . ' tarea(s) de la junta — ' . $resp;
  $ok = remSendHTML($email, $subj, $html, $REMIND);
  if ($ok) { foreach ($tasks as $a) $sentTo[] = $a['id']; }
  logRem("Responsable $resp <$email>: " . count($tasks) . ' tareas — ' . ($ok ? 'OK' : 'FAILED'));
}

// Resumen para Isabel (todas las tareas por vencer/vencidas).
$adminRows = '';
foreach ($due as $a) {
  [$overdue, $when] = remTaskLine($a);
  $color = $overdue ? '#dc2626' : '#d97706';
  $resp  = trim((string)($a['responsable'] ?? '')) ?: '—';
  $tag   = (in_array($resp, $noEmail, true) || $resp === '—') ? ' <span style="font-size:10px;color:#dc2626;">(sin correo)</span>' : '';
  $adminRows .= '<tr><td style="padding:8px 0;border-top:1px solid #eee;font-size:13px;color:#1a2730;">'
              . $esc($a['accion']) . $tag . '<br><span style="font-size:11px;color:' . $color . ';font-weight:700;">'
              . $esc($when) . '</span> · <b>' . $esc($resp) . '</b> <span style="font-size:11px;color:#8a97a3;">· junta ' . $esc($a['meeting_date']) . '</span></td></tr>';
}
$adminHtml = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#1a2730;">'
           . '<div style="background:#0f2233;color:#fff;padding:18px 22px;border-radius:12px 12px 0 0;">'
           . '<div style="font-size:12px;letter-spacing:1px;color:#8fb3c9;">LUNA · SEGUIMIENTO DE TAREAS</div>'
           . '<div style="font-size:19px;font-weight:800;margin-top:3px;">📌 ' . count($due) . ' tarea(s) por vencer/vencidas</div></div>'
           . '<div style="border:1px solid #e2e8ee;border-top:none;border-radius:0 0 12px 12px;padding:20px 22px;">'
           . '<table style="width:100%;border-collapse:collapse;">' . $adminRows . '</table>'
           . ($noEmail ? '<div style="margin-top:14px;font-size:12px;color:#dc2626;">⚠️ Sin correo configurado: ' . $esc(implode(', ', array_unique($noEmail))) . '. Agrégalo en el cron para que reciban su aviso.</div>' : '')
           . '<div style="margin-top:14px;font-size:12px;color:#8a97a3;">Gestiónalas en withisabelfuentes.com/luna/ → 🗓️ Junta.</div>'
           . '</div></div>';
$okAdmin = remSendHTML($REMIND['admin_to'], '📌 Seguimiento: ' . count($due) . ' tareas de la junta', $adminHtml, $REMIND);
logRem('Resumen a Isabel <' . $REMIND['admin_to'] . '>: ' . ($okAdmin ? 'OK' : 'FAILED'));

// Marca TODO lo procesado como recordado hoy (re-aparece mañana si sigue abierto).
meetingMarkReminded($pdo, array_map(fn($a) => $a['id'], $due));
logRem('Marcadas ' . count($due) . ' tareas como recordadas hoy. Enviados individuales: ' . count(array_unique($sentTo)) . '.');

if (php_sapi_name() !== 'cli') {
  header('Content-Type: application/json');
  echo json_encode(['ok' => true, 'due' => count($due), 'emailed' => count(array_unique($sentTo))], JSON_PRETTY_PRINT);
}
