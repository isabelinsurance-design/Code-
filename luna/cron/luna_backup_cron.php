<?php
/* ════════════════════════════════════════════════════════════════
   LUNA BACKUP — Respaldo automático de MySQL (roadmap #5)
   Medicare with Isabel

   USO:
   - Cron en Bluehost: 0 3 * * * php /path/to/luna_backup_cron.php
   - Cada noche a las 3:00 AM (hora LA)

   QUÉ HACE:
   1. mysqldump de toda la base (--single-transaction, seguro en InnoDB)
   2. Comprime con gzip
   3. Rota: borra respaldos más viejos que RETENTION_DAYS
   4. (Opcional) sube a almacenamiento externo (S3/R2/Drive) vía un
      comando configurable — define la constante BACKUP_OFFSITE_CMD en
      config.php para activarlo.
   5. Registra todo en un log y reporta por Telegram si está configurado.

   SEGURIDAD: los respaldos NO deben quedar accesibles por web. Por defecto
   se guardan en ../private_backups (fuera de public_html). Si tu hosting no
   lo permite, el script crea un .htaccess "Deny from all" como respaldo.
════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../config.php';

$TZ = 'America/Los_Angeles';
date_default_timezone_set($TZ);

$CONFIG = [
  // Directorio destino. Por defecto: hermano de public_html (fuera de la web).
  'backup_dir'     => defined('BACKUP_DIR') ? BACKUP_DIR : (__DIR__ . '/../../private_backups'),
  'retention_days' => 30,
  'log_file'       => __DIR__ . '/luna_backup_log.txt',
  // Telegram opcional (reusa las constantes si las tienes en config.php)
  'telegram_token'   => defined('TG_TOKEN') ? TG_TOKEN : 'XXXX:YYYY',
  'telegram_chat_id' => defined('TG_ISABEL_CHAT') ? TG_ISABEL_CHAT : '0',
  // Comando opcional para subir offsite. Recibe la ruta del archivo .gz.
  // Ej en config.php: define('BACKUP_OFFSITE_CMD', 'aws s3 cp {FILE} s3://mi-bucket/luna/');
  'offsite_cmd'    => defined('BACKUP_OFFSITE_CMD') ? BACKUP_OFFSITE_CMD : '',
];

function logBackup($m, $c) { @file_put_contents($c['log_file'], '['.date('Y-m-d H:i:s').'] '.$m."\n", FILE_APPEND); }

function tgNotifyBackup($c, $text) {
  if (empty($c['telegram_token']) || $c['telegram_token'] === 'XXXX:YYYY' || empty($c['telegram_chat_id']) || $c['telegram_chat_id'] === '0') return;
  $ch = curl_init("https://api.telegram.org/bot{$c['telegram_token']}/sendMessage");
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['chat_id'=>$c['telegram_chat_id'], 'text'=>$text, 'parse_mode'=>'Markdown'],
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
  ]);
  curl_exec($ch); curl_close($ch);
}

$startTime = microtime(true);
logBackup('=== LUNA Backup started ===', $CONFIG);

// ─── Asegura el directorio + protección web ────────────────
$dir = $CONFIG['backup_dir'];
if (!is_dir($dir)) @mkdir($dir, 0750, true);
if (!is_dir($dir) || !is_writable($dir)) {
  logBackup("FATAL: directorio no escribible: $dir", $CONFIG);
  tgNotifyBackup($CONFIG, "🛑 *Backup FALLÓ*: directorio no escribible.");
  exit(1);
}
// Si por necesidad quedó dentro de public_html, bloquea acceso web.
if (!file_exists("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");

// ─── mysqldump → gzip ──────────────────────────────────────
$stamp = date('Y-m-d_His');
$file  = rtrim($dir, '/') . "/luna_db_{$stamp}.sql.gz";

// Credenciales por archivo temporal (no en la línea de comandos → no expuestas en ps)
$cnf = tempnam(sys_get_temp_dir(), 'mycnf');
@file_put_contents($cnf, "[client]\nhost=" . DB_HOST . "\nuser=" . DB_USER . "\npassword=\"" . DB_PASS . "\"\n");
@chmod($cnf, 0600);

$cmd = 'mysqldump --defaults-extra-file=' . escapeshellarg($cnf)
     . ' --single-transaction --quick --routines --triggers --events '
     . escapeshellarg(DB_NAME)
     . ' 2>>' . escapeshellarg($CONFIG['log_file'])
     . ' | gzip > ' . escapeshellarg($file);

$rc = 0; $out = [];
exec($cmd, $out, $rc);
@unlink($cnf);

$size = file_exists($file) ? filesize($file) : 0;
// mysqldump devuelve 0 y el .gz debe pesar algo razonable (>1KB) para considerarse válido.
if ($rc !== 0 || $size < 1024) {
  logBackup("FATAL: mysqldump rc=$rc size=$size", $CONFIG);
  @unlink($file); // no dejes un respaldo corrupto
  tgNotifyBackup($CONFIG, "🛑 *Backup FALLÓ* (rc=$rc). Revisa el log del servidor.");
  exit(1);
}
$sizeMB = round($size / 1048576, 2);
logBackup("OK dump: $file ({$sizeMB} MB)", $CONFIG);

// ─── Offsite opcional ──────────────────────────────────────
$offsite = 'no configurado';
if (!empty($CONFIG['offsite_cmd'])) {
  $ocmd = str_replace('{FILE}', escapeshellarg($file), $CONFIG['offsite_cmd']);
  $orc = 0; $oout = [];
  exec($ocmd . ' 2>>' . escapeshellarg($CONFIG['log_file']), $oout, $orc);
  $offsite = $orc === 0 ? 'OK' : "FALLÓ (rc=$orc)";
  logBackup("Offsite: $offsite", $CONFIG);
}

// ─── Rotación: borra respaldos viejos ──────────────────────
$deleted = 0;
$cutoff = time() - ($CONFIG['retention_days'] * 86400);
foreach (glob(rtrim($dir,'/') . '/luna_db_*.sql.gz') as $f) {
  if (filemtime($f) < $cutoff) { @unlink($f); $deleted++; }
}
$remaining = count(glob(rtrim($dir,'/') . '/luna_db_*.sql.gz'));
logBackup("Rotación: borrados=$deleted, quedan=$remaining", $CONFIG);

$elapsed = round((microtime(true) - $startTime) * 1000);
logBackup("=== Done in {$elapsed}ms ===", $CONFIG);

tgNotifyBackup($CONFIG, "💾 *Backup LUNA OK*\n{$sizeMB} MB · offsite: {$offsite}\n{$remaining} respaldos guardados (retención {$CONFIG['retention_days']}d)");

if (php_sapi_name() !== 'cli') {
  header('Content-Type: application/json');
  echo json_encode([
    'ok' => true, 'file' => basename($file), 'size_mb' => $sizeMB,
    'offsite' => $offsite, 'deleted' => $deleted, 'remaining' => $remaining,
    'elapsed_ms' => $elapsed,
  ], JSON_PRETTY_PRINT);
}
