<?php
/* ════════════════════════════════════════════════════════════════
   LUNA SIGNALS — Reflexión nocturna (roadmap #7)
   Medicare with Isabel

   USO:
   - Cron en Bluehost: 0 2 * * * php /path/to/luna_signals_cron.php
   - Cada noche a las 2:00 AM (hora LA)

   QUÉ HACE:
   Recalcula las "señales" del negocio desde el CRM y las guarda en
   luna_senales para que el briefing matutino y la plataforma LUNA las
   muestren ya pre-computadas (threshold / pattern / state / calendar).

   NOTA: standalone (igual que los demás crons). Mantén la lógica de
   señales en sync con computeSignals() de luna_api.php.
════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../../config.php';

$TZ = 'America/Los_Angeles';
date_default_timezone_set($TZ);
$LOG = __DIR__ . '/luna_signals_log.txt';
function logSignals($m) { global $LOG; @file_put_contents($LOG, '['.date('Y-m-d H:i:s').'] '.$m."\n", FILE_APPEND); }

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    logSignals('FATAL: DB - ' . $e->getMessage());
    exit(1);
}

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

$sig = [];

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

$today = new DateTime('now', new DateTimeZone($TZ));
$aep = new DateTime($today->format('Y') . '-10-15', new DateTimeZone($TZ));
if ($today > $aep) $aep->modify('+1 year');
$dToAep = (int)$today->diff($aep)->days;
if ($dToAep <= 45 && $dToAep >= 0) $sig['aep_proximo'] = ['calendar','high',"AEP en $dToAep días",'Prepara revisiones anuales con clientes activos.',$dToAep];

$pdo->exec("DELETE FROM luna_senales WHERE auto=1");
$ins = $pdo->prepare("INSERT INTO luna_senales (skey,tipo,severity,titulo,detalle,valor,status,auto)
                      VALUES (?,?,?,?,?,?, 'open', 1)
                      ON DUPLICATE KEY UPDATE tipo=VALUES(tipo),severity=VALUES(severity),
                          titulo=VALUES(titulo),detalle=VALUES(detalle),valor=VALUES(valor),status='open'");
foreach ($sig as $k => $v) $ins->execute([$k, $v[0], $v[1], $v[2], $v[3], $v[4]]);

logSignals('Computed ' . count($sig) . ' signals: ' . implode(',', array_keys($sig)));

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true, 'computed'=>count($sig), 'signals'=>array_keys($sig)], JSON_PRETTY_PRINT);
}
