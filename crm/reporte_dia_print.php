<?php
/**
 * reporte_dia_print.php
 * Versión imprimible/PDF del "Reporte del día — Aseguranzas" que se ve en
 * la pestaña Reportes del CRM. Misma lógica, sin el resto de la página.
 */
require_once 'session_boot.php';
require_once 'config.php';
$user = auth();
if (empty($user)) { http_response_code(403); exit; }
$pdo = db();

$RDA_GRUPO_PROCESO = ['IN PROCESS','READY TO ENROLL','PENDING','PLAN CHANGE'];
$rda_hoy = date('Y-m-d');

$members = $pdo->query("SELECT id,nombre,apellido,estado,carrier,fecha_cancelacion FROM miembros")->fetchAll();

$rda_por_carrier = [];
foreach ($members as $m) {
    $car = trim($m['carrier'] ?? '');
    if ($car === '') continue;
    if (!isset($rda_por_carrier[$car])) $rda_por_carrier[$car] = ['activos'=>0,'en_proceso'=>0,'cancelados_hoy'=>0];
    if ($m['estado'] === 'ACTIVE') {
        $rda_por_carrier[$car]['activos']++;
    } elseif (in_array($m['estado'], $RDA_GRUPO_PROCESO, true)) {
        $rda_por_carrier[$car]['en_proceso']++;
    }
    if ($m['estado'] === 'CANCELED' && ($m['fecha_cancelacion'] ?? '') === $rda_hoy) {
        $rda_por_carrier[$car]['cancelados_hoy']++;
    }
}
ksort($rda_por_carrier);

$rda_cancelados_hoy = array_values(array_filter($members, fn($m) => $m['estado']==='CANCELED' && ($m['fecha_cancelacion']??'')===$rda_hoy));
usort($rda_cancelados_hoy, fn($a,$b)=>strcmp($a['apellido'].$a['nombre'], $b['apellido'].$b['nombre']));

$rda_activos_total = count(array_filter($members, fn($m)=>$m['estado']==='ACTIVE'));

// Mismo criterio que usa el Dashboard para "próximo mes": ACTIVE con
// fecha_efectiva O app_fecha (lo que se haya llenado) en el mes que sigue.
$next_month_str = date('Y-m', strtotime('first day of next month'));
$rda_nuevos_prox_mes = 0;
try {
    $stm = $pdo->prepare("SELECT COUNT(*) FROM miembros WHERE estado='ACTIVE' AND (fecha_efectiva LIKE ? OR app_fecha LIKE ?)");
    $stm->execute([$next_month_str.'%', $next_month_str.'%']);
    $rda_nuevos_prox_mes = (int)$stm->fetchColumn();
} catch (Exception $e) {}

$rda_apps_por_hacer_tot = 0;
try {
    $rda_apps_por_hacer_tot = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE tipo='APLICACION' AND estado!='CERRADO'")->fetchColumn();
} catch (Exception $e) {}

$P1='#1B4A6B';$P2='#2876A8';$BG='#EBF4F9';$CB='#C8DFF0';$G='#1E7A5C';$R='#B83232';$MU='#7A90A4';
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reporte del día — <?=date('d/m/Y',strtotime($rda_hoy))?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:<?=$BG?>;font-family:'DM Sans',sans-serif;font-size:13px;color:<?=$P1?>;padding:20px}
.page-header{background:<?=$P1?>;color:#fff;border-radius:14px;padding:18px 22px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.page-header h1{font-size:14px;font-weight:900;letter-spacing:3px;text-transform:uppercase}
.page-header .sub{font-size:9px;color:rgba(255,255,255,.6);letter-spacing:2px;text-transform:uppercase;margin-top:3px}
.btn{border:none;border-radius:9px;padding:8px 18px;font-size:10px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif;letter-spacing:1px;text-transform:uppercase;text-decoration:none;display:inline-block}
.btn-gr{background:#EAF5F0;color:<?=$G?>;border:1px solid #8DCFBA}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:18px}
.kpi{background:#fff;border:1px solid <?=$CB?>;border-radius:11px;padding:12px 14px;text-align:center}
.kpi-lbl{font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px}
.kpi-val{font-size:20px;font-weight:900}
.card{background:#fff;border:1px solid <?=$CB?>;border-radius:12px;overflow:hidden;margin-bottom:16px}
.card-title{padding:12px 16px;font-size:10px;font-weight:900;color:<?=$P1?>;text-transform:uppercase;letter-spacing:1.5px;border-bottom:1px solid <?=$CB?>;background:<?=$BG?>}
table{width:100%;border-collapse:collapse}
th{padding:8px 14px;text-align:left;font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;background:<?=$BG?>;border-bottom:1px solid <?=$CB?>}
td{padding:8px 14px;font-size:10px;border-bottom:1px solid <?=$CB?>66}
.tot-row td{font-weight:900;background:<?=$BG?>}
@media print{
  body{background:#fff;padding:0}
  .btn-print{display:none}
  .page-header{border-radius:0}
}
</style>
</head>
<body>

<div class="page-header">
  <div>
    <div class="page-header h1">📋 REPORTE DEL DÍA — ASEGURANZAS</div>
    <div class="sub"><?=date('d/m/Y',strtotime($rda_hoy))?> · MEDICARE WITH ISABEL</div>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <button class="btn btn-gr btn-print" onclick="window.print()">🖨 IMPRIMIR / GUARDAR PDF</button>
    <a href="index.php" class="btn" style="background:rgba(255,255,255,.12);color:#fff">← CRM</a>
  </div>
</div>

<div class="kpis">
  <div class="kpi"><div class="kpi-lbl">◉ TOTAL ACTIVOS</div><div class="kpi-val" style="color:<?=$G?>"><?=$rda_activos_total?></div></div>
  <div class="kpi"><div class="kpi-lbl">📅 NUEVOS PRÓXIMO MES</div><div class="kpi-val" style="color:<?=$P2?>"><?=$rda_nuevos_prox_mes?></div></div>
  <div class="kpi"><div class="kpi-lbl">📋 APLICACIONES POR HACER</div><div class="kpi-val" style="color:#5B3FAF"><?=$rda_apps_por_hacer_tot?></div></div>
  <div class="kpi"><div class="kpi-lbl">⚠ CANCELADOS HOY</div><div class="kpi-val" style="color:<?=count($rda_cancelados_hoy)>0?$R:$MU?>"><?=count($rda_cancelados_hoy)?></div></div>
</div>

<div class="card">
  <div class="card-title">POR ASEGURANZA</div>
  <table>
    <tr><th>ASEGURANZA</th><th>ACTIVOS</th><th>EN PROCESO</th><th>CANCELADOS HOY</th></tr>
    <?php if(empty($rda_por_carrier)):?>
    <tr><td colspan="4" style="text-align:center;color:<?=$MU?>">SIN MIEMBROS CON ASEGURANZA ASIGNADA</td></tr>
    <?php else:?>
    <?php foreach($rda_por_carrier as $car=>$d):?>
    <tr>
      <td style="font-weight:900"><?=h($car)?></td>
      <td style="color:<?=$G?>;font-weight:900"><?=$d['activos']?></td>
      <td style="color:<?=$P2?>;font-weight:900"><?=$d['en_proceso']?></td>
      <td style="<?=$d['cancelados_hoy']>0?'color:'.$R.';font-weight:900':''?>"><?=$d['cancelados_hoy']?></td>
    </tr>
    <?php endforeach;?>
    <tr class="tot-row">
      <td>TOTAL</td>
      <td style="color:<?=$G?>"><?=array_sum(array_column($rda_por_carrier,'activos'))?></td>
      <td style="color:<?=$P2?>"><?=array_sum(array_column($rda_por_carrier,'en_proceso'))?></td>
      <td style="color:<?=$R?>"><?=array_sum(array_column($rda_por_carrier,'cancelados_hoy'))?></td>
    </tr>
    <?php endif;?>
  </table>
</div>

<div class="card">
  <div class="card-title">⚠ CANCELADOS HOY</div>
  <table>
    <?php if(empty($rda_cancelados_hoy)):?>
    <tr><td style="text-align:center;color:<?=$MU?>">SIN CANCELACIONES HOY</td></tr>
    <?php else:?>
    <tr><th>MIEMBRO</th><th>ASEGURANZA</th><th>FECHA</th></tr>
    <?php foreach($rda_cancelados_hoy as $m):?>
    <tr>
      <td style="font-weight:900"><?=h($m['apellido'].', '.$m['nombre'])?></td>
      <td><?=h($m['carrier']??'—')?></td>
      <td><?=date('m/d/Y',strtotime($m['fecha_cancelacion']))?></td>
    </tr>
    <?php endforeach;?>
    <?php endif;?>
  </table>
</div>

</body>
</html>
