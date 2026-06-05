<?php
/* ════════════════════════════════════════════════════════════════
   LUNA RADAR CRON — Investigación automática de tendencias
   Medicare with Isabel

   USO (Bluehost crontab):
     # Escaneo diario rápido — 6:30 AM hora LA
     30 6 * * *  php /path/to/public_html/luna/cron/luna_radar_cron.php daily
     # Reporte semanal profundo — lunes 6:00 AM
     0  6 * * 1  php /path/to/public_html/luna/cron/luna_radar_cron.php weekly

   Si no se pasa argumento, usa 'daily'. Standalone (igual que los demás
   crons): config dos niveles arriba. Degradación elegante: si no hay
   ANTHROPIC_API_KEY o la búsqueda web falla, guarda un run marcado y sale.
════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../luna_config.php';  // ← config propio de LUNA
require_once __DIR__ . '/../luna_radar.php';

$TZ = 'America/Los_Angeles';
date_default_timezone_set($TZ);
$LOG = __DIR__ . '/luna_radar_log.txt';
function logRadar($m) { global $LOG; @file_put_contents($LOG, '['.date('Y-m-d H:i:s').'] '.$m."\n", FILE_APPEND); }

// Correo corto del lunes con SOLO el radar (Chief of Staff). Solo en weekly.
$RADAR_EMAIL = [
  'send' => true,
  'to'   => 'info@withisabelfuentes.com',
  'from' => 'luna@withisabelfuentes.com',
  'name' => 'LUNA Radar',
];

$mode = (isset($argv[1]) && $argv[1] === 'weekly') ? 'weekly'
      : (($_GET['mode'] ?? '') === 'weekly' ? 'weekly' : 'daily');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    logRadar('FATAL: DB - ' . $e->getMessage());
    exit(1);
}

if (!lunaAIEnabled()) {
    logRadar("Saltado ($mode): falta ANTHROPIC_API_KEY.");
    radarRun($pdo, $mode); // deja un run ok=0 visible en la plataforma
    exit(0);
}

$start = microtime(true);
$run   = radarRun($pdo, $mode);
$secs  = round(microtime(true) - $start, 1);

logRadar(sprintf('Radar %s: ok=%s, %d hallazgos en %ss (run #%d).',
    $mode, $run['ok'] ? '1' : '0', count($run['items'] ?? []), $secs, $run['run_id'] ?? 0));

// Correo del lunes: solo en weekly, si hubo hallazgos.
if ($mode === 'weekly' && !empty($run['ok']) && !empty($run['items']) && $RADAR_EMAIL['send']) {
    $subject = '🧭 Radar semanal — Chief of Staff (' . date('j M') . ')';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $RADAR_EMAIL['name'] . ' <' . $RADAR_EMAIL['from'] . '>',
    ];
    $sent = @mail(
        $RADAR_EMAIL['to'],
        '=?UTF-8?B?' . base64_encode($subject) . '?=',
        radarEmailHTML($run),
        implode("\r\n", $headers)
    );
    logRadar('Email semanal (Chief of Staff): ' . ($sent ? 'OK' : 'FAILED'));
}

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'    => (bool)$run['ok'],
        'modo'  => $mode,
        'items' => count($run['items'] ?? []),
        'run_id'=> $run['run_id'] ?? 0,
    ], JSON_PRETTY_PRINT);
}
