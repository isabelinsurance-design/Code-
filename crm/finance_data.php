<?php
require_once 'session_boot.php';
require_once 'config.php';
$user = auth();
if (!isAdmin()) { echo '<div style="color:#B83232;padding:10px">Sin acceso</div>'; exit; }
if (empty($_SESSION['finance_ok'])) { echo '<div style="color:#FCA5A5;padding:10px">Autenticación requerida</div>'; exit; }

$tab = $_GET['tab'] ?? 'RESUMEN';
$pdo = db();
$gold = '#E8C354';

if ($tab === 'KPIS') {
    $total  = $pdo->query("SELECT COALESCE(SUM(monto),0) FROM comisiones")->fetchColumn();
    $recib  = $pdo->query("SELECT COALESCE(SUM(monto),0) FROM comisiones WHERE estado='RECIBIDO'")->fetchColumn();
    $discs  = $pdo->query("SELECT COUNT(*) FROM comisiones WHERE estado='FALTANTE'")->fetchColumn();
    $items  = [['◎',number_format($total,0),'TOTAL ESPERADO','#E8C354'],['✓','$'.number_format($recib,0),'RECIBIDO','#6EE7B7'],['⚠','$'.number_format($total-$recib,0),'FALTANTE','#FCA5A5'],['◈',$discs,'DISCREPANCIAS','#FDBA74']];
    foreach ($items as [$ic,$v,$l,$c]):
    echo "<div style='background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:13px;padding:13px 15px;flex:1;min-width:110px;border-top:3px solid $c'><div style='font-size:8px;font-weight:900;color:rgba(255,255,255,.4);letter-spacing:2px;text-transform:uppercase;margin-bottom:4px'>$ic $l</div><div style='font-size:20px;font-weight:900;color:$c'>$v</div></div>";
    endforeach;
    exit;
}

$comisiones = $pdo->query("SELECT c.*,CONCAT(m.apellido,', ',m.nombre) as miembro_nombre,u.nombre as agente_nombre,u.iniciales,u.color FROM comisiones c LEFT JOIN miembros m ON c.miembro_id=m.id LEFT JOIN usuarios u ON c.agente_id=u.id ORDER BY c.anio DESC,c.mes DESC,c.created_at DESC")->fetchAll();

if ($tab === 'RESUMEN'):
    echo "<table style='width:100%;border-collapse:collapse'>";
    echo "<thead><tr>";
    foreach (['MIEMBRO','CARRIER','MES','AGENTE','MONTO','ESTADO',''] as $h)
        echo "<th style='padding:8px 14px;text-align:left;font-size:8px;font-weight:900;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:1.5px;background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.08)'>$h</th>";
    echo "</tr></thead><tbody>";
    foreach ($comisiones as $c):
        $ec = ['RECIBIDO'=>['#6EE7B7','rgba(30,122,92,.15)','rgba(30,122,92,.3)'],'PENDIENTE'=>['#FDBA74','rgba(192,122,26,.15)','rgba(192,122,26,.3)'],'FALTANTE'=>['#FCA5A5','rgba(184,50,50,.15)','rgba(184,50,50,.3)']];
        $es = $ec[$c['estado']] ?? ['rgba(255,255,255,.5)','rgba(255,255,255,.05)','rgba(255,255,255,.1)'];
        echo "<tr style='border-bottom:1px solid rgba(255,255,255,.05)'>";
        echo "<td style='padding:10px 14px;font-weight:900;color:rgba(255,255,255,.85);font-size:9px'>".htmlspecialchars($c['miembro_nombre']??'—')."</td>";
        echo "<td style='padding:10px 14px'><span style='background:rgba(40,118,168,.2);color:#93C5FD;border:1px solid rgba(40,118,168,.3);border-radius:20px;padding:2px 8px;font-size:8px;font-weight:900'>".htmlspecialchars($c['carrier'])."</span></td>";
        echo "<td style='padding:10px 14px;font-size:8px;color:rgba(255,255,255,.4)'>".htmlspecialchars($c['mes'].' '.$c['anio'])."</td>";
        echo "<td style='padding:10px 14px'><span style='font-size:8px;font-weight:900;color:rgba(255,255,255,.7)'>".htmlspecialchars($c['agente_nombre']??'—')."</span></td>";
        echo "<td style='padding:10px 14px;font-weight:900;color:#E8C354;font-size:13px'>\$".number_format($c['monto'],0)."</td>";
        echo "<td style='padding:10px 14px'><span style='background:{$es[1]};color:{$es[0]};border:1px solid {$es[2]};border-radius:20px;padding:2px 9px;font-size:8px;font-weight:900;text-transform:uppercase'>".htmlspecialchars($c['estado'])."</span></td>";
        echo "<td style='padding:10px 14px'>";
        if (in_array($c['estado'],['FALTANTE','PENDIENTE'])) echo "<button style='background:rgba(192,122,26,.2);color:#FDBA74;border:1px solid rgba(192,122,26,.3);border-radius:7px;padding:4px 10px;font-size:8px;font-weight:900;cursor:pointer;font-family:\"DM Sans\",sans-serif;letter-spacing:1px;text-transform:uppercase'>◈ RECLAMAR</button>";
        echo "</td></tr>";
    endforeach;
    echo "</tbody></table>";

elseif ($tab === 'POR CARRIER'):
    $carriers = [];
    foreach ($comisiones as $c) {
        $k = $c['carrier'];
        if (!isset($carriers[$k])) $carriers[$k]=['total'=>0,'recib'=>0,'pend'=>0,'falt'=>0];
        $carriers[$k]['total']+=$c['monto'];
        if ($c['estado']==='RECIBIDO') $carriers[$k]['recib']+=$c['monto'];
        if ($c['estado']==='PENDIENTE') $carriers[$k]['pend']+=$c['monto'];
        if ($c['estado']==='FALTANTE') $carriers[$k]['falt']+=$c['monto'];
    }
    echo "<div style='display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:11px'>";
    foreach ($carriers as $name=>$d):
        $pct = $d['total']>0 ? ($d['recib']/$d['total'])*100 : 0;
        echo "<div style='background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:13px;padding:15px 17px'>";
        echo "<div style='font-size:12px;font-weight:900;color:#E8C354;letter-spacing:2px;text-transform:uppercase;margin-bottom:11px'>".htmlspecialchars($name)."</div>";
        echo "<div style='font-size:9px;color:rgba(255,255,255,.4);margin-bottom:3px'>TOTAL: <b style=\"color:#E8C354;font-size:13px\">\$".number_format($d['total'],0)."</b></div>";
        echo "<div style='font-size:9px;color:rgba(255,255,255,.4);margin-bottom:3px'>REC: <b style=\"color:#6EE7B7\">\$".number_format($d['recib'],0)."</b></div>";
        echo "<div style='font-size:9px;color:rgba(255,255,255,.4);margin-bottom:11px'>PEND/FALT: <b style=\"color:#FCA5A5\">\$".number_format($d['pend']+$d['falt'],0)."</b></div>";
        echo "<div style='height:4px;background:rgba(255,255,255,.1);border-radius:10px;overflow:hidden'><div style='height:100%;width:$pct%;background:#6EE7B7;border-radius:10px'></div></div>";
        echo "</div>";
    endforeach;
    echo "</div>";

elseif ($tab === 'POR AGENTE'):
    $agents_all = $pdo->query("SELECT * FROM usuarios WHERE activo=1 AND rol='agent'")->fetchAll();
    echo "<div style='background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:13px;overflow:hidden'>";
    foreach ($agents_all as $ag):
        $ac = array_filter($comisiones, fn($c)=>$c['agente_id']==$ag['id']);
        $tot = array_sum(array_column($ac,'monto'));
        $rec = array_sum(array_column(array_filter($ac,fn($c)=>$c['estado']==='RECIBIDO'),'monto'));
        echo "<div style='padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.06);display:flex;gap:13px;align-items:center'>";
        echo "<div style='width:34px;height:34px;border-radius:50%;background:{$ag['color']};display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:900;color:#fff;flex-shrink:0'>{$ag['iniciales']}</div>";
        echo "<div style='flex:1'><div style='font-weight:900;font-size:10px;color:rgba(255,255,255,.85);letter-spacing:1.5px;text-transform:uppercase'>".htmlspecialchars(explode(' ',$ag['nombre'])[0])."</div><div style='font-size:8px;color:rgba(255,255,255,.35);margin-top:2px'>".count($ac)." REGISTROS</div></div>";
        echo "<div style='text-align:right'><div style='font-size:18px;font-weight:900;color:#E8C354'>\$".number_format($tot,0)."</div><div style='font-size:8px;color:#6EE7B7;margin-top:2px'>\$".number_format($rec,0)." REC.</div></div>";
        echo "</div>";
    endforeach;
    echo "</div>";

elseif ($tab === 'DISCREPANCIAS'):
    $discs = array_filter($comisiones, fn($c)=>in_array($c['estado'],['FALTANTE','PENDIENTE']));
    $total_disc = array_sum(array_column($discs,'monto'));
    echo "<div style='background:rgba(184,50,50,.1);border:1px solid rgba(184,50,50,.25);border-radius:11px;padding:11px 16px;margin-bottom:14px;font-size:9px;color:#FCA5A5;font-weight:800;letter-spacing:1px;text-transform:uppercase'>⚠ ".count($discs)." DISCREPANCIAS · \$".number_format($total_disc,0)." REQUIEREN ACCIÓN</div>";
    foreach ($discs as $c):
        $lc = $c['estado']==='FALTANTE' ? ['#FCA5A5','rgba(184,50,50,.2)','rgba(184,50,50,.4)','rgba(184,50,50,.15)'] : ['#FDBA74','rgba(192,122,26,.2)','rgba(192,122,26,.4)','rgba(192,122,26,.15)'];
        echo "<div style='background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:11px;padding:14px 16px;margin-bottom:9px;border-left:3px solid {$lc[0]}'>";
        echo "<div style='display:flex;justify-content:space-between;margin-bottom:6px'><div style='font-weight:900;font-size:11px;color:rgba(255,255,255,.85)'>".htmlspecialchars($c['miembro_nombre']??'—')."</div><span style='background:{$lc[1]};color:{$lc[0]};border:1px solid {$lc[2]};border-radius:20px;padding:2px 9px;font-size:8px;font-weight:900;text-transform:uppercase'>".htmlspecialchars($c['estado'])."</span></div>";
        echo "<div style='font-size:9px;color:rgba(255,255,255,.4);margin-bottom:10px'>".htmlspecialchars($c['carrier'])." · ".htmlspecialchars($c['mes'].' '.$c['anio'])." · ".htmlspecialchars($c['agente_nombre']??'—')." · <b style=\"color:#E8C354\">\$".number_format($c['monto'],0)."</b></div>";
        echo "<button style='background:rgba(192,122,26,.2);color:#FDBA74;border:1px solid rgba(192,122,26,.3);border-radius:8px;padding:5px 12px;font-size:8px;font-weight:900;cursor:pointer;font-family:\"DM Sans\",sans-serif;letter-spacing:2px;text-transform:uppercase'>◈ RECLAMAR A ".htmlspecialchars($c['carrier'])."</button>";
        echo "</div>";
    endforeach;
endif;
