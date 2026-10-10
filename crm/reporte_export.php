<?php
require_once 'session_boot.php';
require_once 'config.php';
require_once 'nomina_calc.php';
$user = auth();
if (empty($user)) { echo 'Sin acceso'; exit; }

$pdo = db();
$fmt  = $_GET['fmt'] ?? 'txt';   // txt or csv
$from = $_GET['from'] ?? today();
$to   = $_GET['to']   ?? today();
$ag   = intval($_GET['agente'] ?? 0);
// Cada empleado solo exporta lo suyo; el admin, lo que elija.
if (($user['rol'] ?? '') !== 'admin') $ag = (int)$user['id'];

// Sanitize dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = today();
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = today();

$agents = $pdo->query("SELECT * FROM usuarios WHERE activo=1 ORDER BY rol DESC,nombre")->fetchAll();

// Build queries
$agWhere = $ag ? " AND r.agente_id = :agente_id " : '';
$rq = "SELECT r.*,u.nombre,u.iniciales FROM reporte_diario r LEFT JOIN usuarios u ON r.agente_id=u.id WHERE r.fecha BETWEEN :from AND :to $agWhere ORDER BY r.fecha DESC,u.nombre";
$stmt = $pdo->prepare($rq);
$stmt->bindValue(':from', $from);
$stmt->bindValue(':to', $to);
if ($ag) $stmt->bindValue(':agente_id', $ag, PDO::PARAM_INT);
$stmt->execute();
$reportes = $stmt->fetchAll();

$cq = "SELECT a.*,u.nombre,u.iniciales FROM asistencia a LEFT JOIN usuarios u ON a.agente_id=u.id WHERE a.fecha BETWEEN ? AND ? ".($ag?" AND a.agente_id=? ":'')." ORDER BY a.fecha DESC,u.nombre";
$cst = $pdo->prepare($cq);
$cst->execute($ag ? [$from, $to, $ag] : [$from, $to]);
$ckins = $cst->fetchAll();
// Breaks adicionales (2º, 3º...) de todos los días de una sola vez — antes el
// export los ignoraba y las horas salían de más.
$_xb_export = asistencia_breaks_batch($pdo, array_column($ckins, 'id'));
function horas_reg(array $c, array $xb): string {
    if (empty($c['check_in']) || empty($c['check_out'])) return '—';
    $t = segundos_trabajados($c, $xb[(int)($c['id'] ?? 0)] ?? []);
    return $t > 0 ? floor($t/3600).'H '.floor(($t%3600)/60).'M' : '—';
}
function dur_almuerzo(array $c): string {
    if (empty($c['lunch_out']) || empty($c['lunch_in'])) return '—';
    $t = strtotime('1970-01-01 '.$c['lunch_in']) - strtotime('1970-01-01 '.$c['lunch_out']);
    return $t > 0 ? floor($t/3600).'H '.floor(($t%3600)/60).'M' : '—';
}

function horas_net($ci,$lo,$li,$co,$bo=null,$bi=null) {
    if(!$ci||!$co) return '—';
    $s=strtotime("1970-01-01 $ci"); $e=strtotime("1970-01-01 $co"); $t=$e-$s;
    if($lo&&$li){$ls=strtotime("1970-01-01 $lo");$le=strtotime("1970-01-01 $li");$t-=($le-$ls);}
    if($bo&&$bi){$bs=strtotime("1970-01-01 $bo");$be=strtotime("1970-01-01 $bi");$t-=($be-$bs);}
    return $t>0?floor($t/3600).'H '.floor(($t%3600)/60).'M':'—';
}

if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_'.$from.'_'.$to.'.csv"');
    echo "\xEF\xBB\xBF"; // BOM for Excel
    $out = fopen('php://output','w');
    fputcsv($out,['FECHA','EMPLEADO','INICIALES','LLAMADAS PROSP.','LLAMADAS SERV.','TOTAL LLAMADAS',
        'CITAS','TICKETS RESUELT.','APPS ENVIADAS','PÓLIZAS','NOTA','CHECK-IN','CHECK-OUT','HORAS NETAS','ALMUERZO','BREAK']);
    foreach ($reportes as $r) {
        // Find matching checkin
        $ck = null;
        foreach ($ckins as $c) { if ($c['agente_id']==$r['agente_id'] && $c['fecha']==$r['fecha']) { $ck=$c; break; } }
        fputcsv($out,[
            $r['fecha'], $r['nombre'], $r['iniciales'],
            $r['llamadas_prospectos'], $r['llamadas_servicio'],
            $r['llamadas_prospectos']+$r['llamadas_servicio'],
            $r['citas_confirmadas'], $r['tickets_resueltos'],
            $r['apps_enviadas'], $r['polizas_escritas'], $r['nota']??'',
            $ck['check_in']??'', $ck['check_out']??'',
            $ck ? horas_reg($ck, $_xb_export) : '—',
            $ck ? dur_almuerzo($ck) : '—',
            ($ck&&!empty($ck['break_out'])&&!empty($ck['break_in']))?'SÍ':'—',
        ]);
    }
    // Also add checkin-only rows (no reporte)
    foreach ($ckins as $c) {
        $hasRep = false;
        foreach ($reportes as $r) { if ($r['agente_id']==$c['agente_id'] && $r['fecha']==$c['fecha']) { $hasRep=true; break; } }
        if (!$hasRep) {
            fputcsv($out,[
                $c['fecha'],$c['nombre'],$c['iniciales']??'',
                '','','','','','','','',
                $c['check_in']??'',$c['check_out']??'',
                horas_reg($c, $_xb_export),
                dur_almuerzo($c), (!empty($c['break_out'])&&!empty($c['break_in']))?'SÍ':'—',
            ]);
        }
    }
    fclose($out);
    exit;
}

// TXT format
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="reporte_'.$from.'_'.$to.'.txt"');

$agNombre = $ag ? ($pdo->query("SELECT nombre FROM usuarios WHERE id=$ag")->fetchColumn()) : 'TODOS LOS EMPLEADOS';
$tot_ll = array_sum(array_map(fn($r)=>$r['llamadas_prospectos']+$r['llamadas_servicio'],$reportes));
$tot_apps = array_sum(array_column($reportes,'apps_enviadas'));
$open_tks = $pdo->query("SELECT COUNT(*) FROM tickets WHERE estado!='CERRADO'")->fetchColumn();
$activos  = $pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='ACTIVE'")->fetchColumn();

echo "REPORTE DE OPERACIONES — MEDICARE WITH ISABEL\n";
echo "withisabelfuentes.com · CA Lic #0D96598\n";
echo "Período: $from al $to | Empleado: $agNombre\n";
echo "Generado: ".date('Y-m-d H:i:s')."\n";
echo str_repeat('=',60)."\n\n";
echo "TOTALES DEL PERÍODO\n";
echo "  Llamadas: $tot_ll\n";
echo "  Apps enviadas: $tot_apps\n";
echo "  Tickets abiertos: $open_tks\n";
echo "  Miembros activos: $activos\n\n";
echo str_repeat('=',60)."\n\n";
echo "ASISTENCIA\n";
foreach ($ckins as $c) {
    $bo = $c['break_out']??null; $bi = $c['break_in']??null;
    $w = horas_reg($c, $_xb_export);
    echo "{$c['fecha']} · {$c['nombre']}: CI={$c['check_in']} CO={$c['check_out']} HORAS=$w";
    if ($bo&&$bi) echo " BREAK={$bo}-{$bi}";
    echo "\n";
}
echo "\n".str_repeat('=',60)."\n\n";
echo "REPORTES POR EMPLEADO\n";
foreach ($reportes as $r) {
    echo "\n{$r['fecha']} — {$r['nombre']}:\n";
    echo "  Llamadas prospectos: {$r['llamadas_prospectos']}\n";
    echo "  Llamadas servicio:   {$r['llamadas_servicio']}\n";
    echo "  Citas confirmadas:   {$r['citas_confirmadas']}\n";
    echo "  Tickets resueltos:   {$r['tickets_resueltos']}\n";
    echo "  Apps enviadas:       {$r['apps_enviadas']}\n";
    echo "  Pólizas escritas:    {$r['polizas_escritas']}\n";
    if ($r['nota']) echo "  Nota: {$r['nota']}\n";
}
