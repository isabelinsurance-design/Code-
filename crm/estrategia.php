<?php
/**
 * estrategia.php — Estrategia & Embudo Diario (portado de PG, nativo en el CRM)
 * ──────────────────────────────────────────────────────────────────────────
 * Muestra la meta global (Rumbo 500) y el embudo de metas diarias
 * (50 llamadas → 15 efectivas → 5 interesados → 2 citas → 1.5 inscritos),
 * comparando META vs. REAL con los datos de reporte_diario y miembros.
 */
require_once 'config.php';
$user  = auth();
$admin = isAdmin();
$pdo   = db();

// ── Fecha ────────────────────────────────────────────────────────────────
$fecha = $_GET['f'] ?? today();
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = today();
$prev  = date('Y-m-d', strtotime($fecha.' -1 day'));
$next  = date('Y-m-d', strtotime($fecha.' +1 day'));

// ── Metas diarias POR AGENTE (las de PG) ─────────────────────────────────
$META = ['calls'=>50, 'effective'=>15, 'interested'=>5, 'appts'=>2, 'enrolled'=>1.5];

// ── Meta global: Rumbo 500 ───────────────────────────────────────────────
$START = 250; $TARGET = 500;
$activos = (int) $pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='ACTIVE'")->fetchColumn();
$pct_goal = $TARGET > 0 ? min(100, round($activos / $TARGET * 100, 1)) : 0;

// ── Agentes activos (para escalar metas a nivel equipo) ──────────────────
$n_agentes = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE activo=1 AND rol='agent'")->fetchColumn();
$n_agentes = max(1, $n_agentes);

// ── Reales del día (suma de todo el equipo) ──────────────────────────────
// ¿Existe la columna 'interesados'? (se agrega al enviar el 1er reporte)
$tieneInteresados = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM reporte_diario")->fetchAll(PDO::FETCH_COLUMN);
    $tieneInteresados = in_array('interesados', $cols);
} catch(Exception $e) {}
$selInteresados = $tieneInteresados ? "COALESCE(SUM(interesados),0)" : "0";

$st = $pdo->prepare("
    SELECT COALESCE(SUM(llamadas_prospectos),0) AS calls,
           COALESCE(SUM(contestaron),0)         AS effective,
           $selInteresados                       AS interested,
           COALESCE(SUM(citas_confirmadas),0)   AS appts,
           COALESCE(SUM(apps_enviadas),0)       AS enrolled
    FROM reporte_diario WHERE fecha = ?");
$st->execute([$fecha]);
$real = $st->fetch() ?: ['calls'=>0,'effective'=>0,'interested'=>0,'appts'=>0,'enrolled'=>0];

// ── Por agente (para la tabla de abajo) ──────────────────────────────────
$stA = $pdo->prepare("
    SELECT u.nombre, u.iniciales, u.color,
           COALESCE(rd.llamadas_prospectos,0) AS calls,
           COALESCE(rd.contestaron,0)         AS effective,
           COALESCE(rd.citas_confirmadas,0)   AS appts,
           COALESCE(rd.apps_enviadas,0)       AS enrolled
    FROM usuarios u
    LEFT JOIN reporte_diario rd ON rd.agente_id=u.id AND rd.fecha=?
    WHERE u.activo=1 AND u.rol='agent'
    ORDER BY u.nombre");
$stA->execute([$fecha]);
$porAgente = $stA->fetchAll();

// Embudo del equipo: META = meta_por_agente × n_agentes
$P1='#1B4A6B';$P2='#2876A8';$BG='#EBF4F9';$CB='#C8DFF0';$G='#1E7A5C';$R='#B83232';$A='#C07A1A';$MU='#7A90A4';$TX='#1B3A5C';

$stages = [
  ['key'=>'calls',     'lbl'=>'LLAMADAS',   'real'=>(int)$real['calls'],     'meta'=>$META['calls']*$n_agentes,     'has'=>true,  'ic'=>'📞'],
  ['key'=>'effective', 'lbl'=>'EFECTIVAS',  'real'=>(int)$real['effective'], 'meta'=>$META['effective']*$n_agentes, 'has'=>true,  'ic'=>'✓'],
  ['key'=>'interested','lbl'=>'INTERESADOS','real'=>(int)($real['interested']??0),'meta'=>$META['interested']*$n_agentes,'has'=>$tieneInteresados, 'ic'=>'★'],
  ['key'=>'appts',     'lbl'=>'CITAS',      'real'=>(int)$real['appts'],     'meta'=>$META['appts']*$n_agentes,     'has'=>true,  'ic'=>'◷'],
  ['key'=>'enrolled',  'lbl'=>'INSCRITOS',  'real'=>(int)$real['enrolled'],  'meta'=>round($META['enrolled']*$n_agentes,1),'has'=>true,'ic'=>'🎉'],
];
?><!DOCTYPE html>
<html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Estrategia & Embudo Diario</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:<?=$BG?>;font-family:'DM Sans',sans-serif;font-size:13px;color:<?=$P1?>;padding:20px}
.hd{background:<?=$P1?>;color:#fff;border-radius:14px;padding:16px 20px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.hd h1{font-size:14px;font-weight:900;letter-spacing:2px;text-transform:uppercase}
.hd .sub{font-size:9px;color:rgba(255,255,255,.6);letter-spacing:2px;text-transform:uppercase;margin-top:3px}
.btn{border:none;border-radius:9px;padding:8px 14px;font-size:10px;font-weight:900;cursor:pointer;font-family:inherit;letter-spacing:1px;text-transform:uppercase;text-decoration:none;display:inline-block}
.card{background:#fff;border:1px solid <?=$CB?>;border-radius:13px;padding:16px 18px;margin-bottom:16px}
.lbl{font-size:9px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px}
.bar{height:12px;background:<?=$CB?>;border-radius:10px;overflow:hidden}
.fill{height:100%;border-radius:10px}
.funnel{display:flex;gap:10px;flex-wrap:wrap}
.fstage{flex:1;min-width:120px;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:11px;padding:13px;text-align:center;border-top:3px solid var(--c)}
.fstage .ic{font-size:16px}
.fstage .v{font-size:24px;font-weight:900;line-height:1;margin:4px 0}
.fstage .m{font-size:9px;color:<?=$MU?>;font-weight:700}
.fstage .lab{font-size:8px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-top:4px}
table{width:100%;border-collapse:collapse;font-size:11px;margin-top:6px}
th{padding:7px 10px;text-align:left;font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;border-bottom:1px solid <?=$CB?>;background:<?=$BG?>}
td{padding:8px 10px;border-bottom:1px solid <?=$CB?>}
.av{width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:9px;font-weight:900;color:#fff;vertical-align:middle;margin-right:6px}
.ok{color:<?=$G?>;font-weight:900}.lo{color:<?=$A?>;font-weight:900}
.datef input{border:1.5px solid rgba(255,255,255,.3);background:rgba(255,255,255,.12);color:#fff;border-radius:8px;padding:6px 10px;font-family:inherit;font-weight:700}
</style></head><body>

<div class="hd">
  <div>
    <h1>◍ Estrategia & Embudo Diario</h1>
    <div class="sub"><?=date('d/m/Y', strtotime($fecha))?> · Equipo de <?=$n_agentes?> · Medicare with Isabel</div>
  </div>
  <div style="display:flex;gap:8px;align-items:center" class="datef">
    <a href="?f=<?=$prev?>" class="btn" style="background:rgba(255,255,255,.12);color:#fff">←</a>
    <form method="GET" style="display:inline"><input type="date" name="f" value="<?=$fecha?>" onchange="this.form.submit()"></form>
    <a href="?f=<?=$next?>" class="btn" style="background:rgba(255,255,255,.12);color:#fff">→</a>
    <a href="index.php" class="btn" style="background:rgba(255,255,255,.12);color:#fff">← CRM</a>
  </div>
</div>

<!-- META GLOBAL: RUMBO 500 -->
<div class="card" style="border-top:4px solid <?=$P1?>">
  <div class="lbl">🎯 META GLOBAL — RUMBO 500</div>
  <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:6px">
    <div style="font-size:30px;font-weight:900;color:<?=$P1?>"><?=$activos?> <span style="font-size:13px;color:<?=$MU?>">/ <?=$TARGET?> activos</span></div>
    <div style="font-size:13px;font-weight:900;color:<?=$pct_goal>=100?$G:$A?>"><?=$pct_goal?>%</div>
  </div>
  <div class="bar"><div class="fill" style="width:<?=$pct_goal?>%;background:<?=$P1?>"></div></div>
  <div style="font-size:9px;color:<?=$MU?>;margin-top:6px">Desde <?=$START?> · Faltan <b style="color:<?=$P1?>"><?=max(0,$TARGET-$activos)?></b> miembros activos para llegar a 500.</div>
</div>

<!-- EMBUDO DIARIO -->
<div class="card">
  <div class="lbl">📊 EMBUDO DE METAS DIARIAS — META (equipo) vs. REAL del día</div>
  <div class="funnel">
    <?php foreach($stages as $s):
      $meta=$s['meta']; $real_v=$s['real'];
      $pct = ($s['has'] && $meta>0) ? min(100, round($real_v/$meta*100)) : 0;
      $c = !$s['has'] ? $MU : ($pct>=100?$G:($pct>=60?$A:$R));
    ?>
    <div class="fstage" style="--c:<?=$c?>">
      <div class="ic"><?=$s['ic']?></div>
      <div class="v" style="color:<?=$c?>"><?= $s['has'] ? $real_v : '—' ?></div>
      <div class="m">meta <?=$meta?></div>
      <div class="bar" style="height:6px;margin-top:6px"><div class="fill" style="width:<?=$pct?>%;background:<?=$c?>"></div></div>
      <div class="lab"><?=$s['lbl']?></div>
      <?php if(!$s['has']):?><div style="font-size:7px;color:<?=$MU?>;margin-top:3px">no se registra aún</div><?php endif;?>
    </div>
    <?php endforeach;?>
  </div>
  <div style="font-size:8px;color:<?=$MU?>;margin-top:10px;text-transform:uppercase;letter-spacing:1px">
    Meta por agente/día: 50 llamadas → 15 efectivas → 5 interesados → 2 citas → 1.5 inscritos
  </div>
</div>

<!-- TASAS DE CONVERSIÓN -->
<?php
$pct = fn($n,$d) => $d>0 ? round($n/$d*100) : null;
$cv = (int)$real['calls']; $ef=(int)$real['effective']; $it=(int)($real['interested']??0); $ap=(int)$real['appts']; $en=(int)$real['enrolled'];
$convs = [
  ['📞→✓','Efectividad', $pct($ef,$cv)],
  ['✓→★','Interés', $tieneInteresados ? $pct($it,$ef) : null],
  ['★→◷','Agenda', $tieneInteresados ? $pct($ap,$it) : $pct($ap,$ef)],
  ['◷→🎉','Cierre cita', $pct($en,$ap)],
  ['📞→🎉','Cierre total', $pct($en,$cv)],
];
?>
<div class="card">
  <div class="lbl">📈 TASAS DE CONVERSIÓN — HOY</div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <?php foreach($convs as [$ar,$lbl,$p]): ?>
    <div style="flex:1;min-width:90px;text-align:center;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:11px;padding:11px">
      <div style="font-size:11px;color:<?=$MU?>"><?=$ar?></div>
      <div style="font-size:22px;font-weight:900;color:<?= $p===null ? $MU : ($p>=100?$G:$P1) ?>;line-height:1.1"><?= $p===null ? '—' : $p.'%' ?></div>
      <div style="font-size:8px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:.5px;margin-top:3px"><?=$lbl?></div>
    </div>
    <?php endforeach;?>
  </div>
</div>

<!-- POR AGENTE -->
<div class="card">
  <div class="lbl">👥 DETALLE POR AGENTE — <?=date('d/m/Y',strtotime($fecha))?></div>
  <table>
    <tr><th>Agente</th><th>Llamadas</th><th>Efectivas</th><th>Citas</th><th>Inscritos</th></tr>
    <?php foreach($porAgente as $a):
      $cmp = function($val,$meta){ global $G,$A; return $val>=$meta ? "ok" : "lo"; };
    ?>
    <tr>
      <td><span class="av" style="background:<?=h($a['color']?:'#2876A8')?>"><?=h($a['iniciales']?:'?')?></span><?=h($a['nombre'])?></td>
      <td class="<?=$cmp($a['calls'],$META['calls'])?>"><?=$a['calls']?> <span style="color:<?=$MU?>;font-weight:400">/<?=$META['calls']?></span></td>
      <td class="<?=$cmp($a['effective'],$META['effective'])?>"><?=$a['effective']?> <span style="color:<?=$MU?>;font-weight:400">/<?=$META['effective']?></span></td>
      <td class="<?=$cmp($a['appts'],$META['appts'])?>"><?=$a['appts']?> <span style="color:<?=$MU?>;font-weight:400">/<?=$META['appts']?></span></td>
      <td class="<?=$cmp($a['enrolled'],$META['enrolled'])?>"><?=$a['enrolled']?> <span style="color:<?=$MU?>;font-weight:400">/<?=$META['enrolled']?></span></td>
    </tr>
    <?php endforeach;?>
    <?php if(!$porAgente):?><tr><td colspan="5" style="text-align:center;color:<?=$MU?>;padding:16px">Sin agentes activos</td></tr><?php endif;?>
  </table>
</div>

<div style="font-size:8px;color:<?=$MU?>;text-align:center;letter-spacing:1px;text-transform:uppercase">
  Embudo basado en los reportes diarios del equipo · <?= $tieneInteresados ? '"Interesados" se captura en el reporte diario' : '"Interesados" aparecerá cuando se envíe el primer reporte con ese campo' ?>
</div>

</body></html>
