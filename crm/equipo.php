<?php
/**
 * equipo.php — Wins, Rachas & Mood (portado de PG, nativo en el CRM)
 * ──────────────────────────────────────────────────────────────────────────
 * Energía del equipo:
 *   • RACHAS  → días consecutivos con llamadas (derivado de reporte_diario)
 *   • WINS    → feed de logros del equipo (tabla wins)
 *   • MOOD    → ánimo diario 1-5 por agente + promedio (tabla mood_diario)
 */
require_once 'config.php';
$user = auth();
$pdo  = db();
$uid  = (int)$user['id'];

// ── Tablas (auto-crear, estilo del resto del CRM) ────────────────────────
try { $pdo->exec("CREATE TABLE IF NOT EXISTS wins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agente_id INT,
    texto VARCHAR(255),
    fecha DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)"); } catch(Exception $e) {}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS mood_diario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agente_id INT,
    fecha DATE,
    valor TINYINT,
    UNIQUE KEY uniq_agente_fecha (agente_id, fecha)
)"); } catch(Exception $e) {}

// ── Escritura (PRG: Post → Redirect → Get) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'win') {
        $texto = trim($_POST['texto'] ?? '');
        if ($texto !== '') {
            $st = $pdo->prepare("INSERT INTO wins (agente_id,texto,fecha) VALUES (?,?,?)");
            $st->execute([$uid, mb_substr($texto,0,255), today()]);
        }
    } elseif ($action === 'mood') {
        $v = (int)($_POST['valor'] ?? 0);
        if ($v >= 1 && $v <= 5) {
            $st = $pdo->prepare("INSERT INTO mood_diario (agente_id,fecha,valor) VALUES (?,?,?)
                                 ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
            $st->execute([$uid, today(), $v]);
        }
    } elseif ($action === 'del_win') {
        // sólo el dueño o admin puede borrar
        $wid = (int)($_POST['wid'] ?? 0);
        if (isAdmin()) {
            $pdo->prepare("DELETE FROM wins WHERE id=?")->execute([$wid]);
        } else {
            $pdo->prepare("DELETE FROM wins WHERE id=? AND agente_id=?")->execute([$wid,$uid]);
        }
    }
    header('Location: equipo.php'); exit;
}

$hoy = today();
$MOODS = ['', '😰', '😐', '🙂', '😊', '🤩'];

// ── Agentes activos ──────────────────────────────────────────────────────
$agentes = $pdo->query("SELECT id,nombre,iniciales,color FROM usuarios
                        WHERE activo=1 AND rol IN ('agent','admin') ORDER BY nombre")->fetchAll();

// ── RACHAS: días consecutivos con llamadas (termina hoy o ayer) ──────────
function calcStreak(PDO $pdo, int $agenteId): int {
    $st = $pdo->prepare("SELECT DISTINCT fecha FROM reporte_diario
                         WHERE agente_id=? AND llamadas_prospectos>0 ORDER BY fecha DESC LIMIT 120");
    $st->execute([$agenteId]);
    $set = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $f) $set[$f] = true;
    if (!$set) return 0;
    $cur = new DateTime(today());
    if (!isset($set[$cur->format('Y-m-d')])) $cur->modify('-1 day'); // día en curso aún sin reporte
    $streak = 0;
    while (isset($set[$cur->format('Y-m-d')])) { $streak++; $cur->modify('-1 day'); }
    return $streak;
}
$rachas = [];
foreach ($agentes as $a) $rachas[] = ['a'=>$a, 'streak'=>calcStreak($pdo, (int)$a['id'])];
usort($rachas, fn($x,$y)=> $y['streak'] <=> $x['streak']);

// ── MOOD de hoy ──────────────────────────────────────────────────────────
$st = $pdo->prepare("SELECT agente_id,valor FROM mood_diario WHERE fecha=?");
$st->execute([$hoy]);
$moodHoy = [];
foreach ($st->fetchAll() as $m) $moodHoy[(int)$m['agente_id']] = (int)$m['valor'];
$moodVals = array_values($moodHoy);
$moodAvg  = $moodVals ? round(array_sum($moodVals)/count($moodVals), 1) : 0;
$miMood   = $moodHoy[$uid] ?? 0;

// ── WINS recientes ───────────────────────────────────────────────────────
$wins = $pdo->query("SELECT w.id,w.texto,w.fecha,u.nombre,u.iniciales,u.color,w.agente_id
                     FROM wins w LEFT JOIN usuarios u ON u.id=w.agente_id
                     ORDER BY w.created_at DESC LIMIT 30")->fetchAll();

$P1='#1B4A6B';$P2='#2876A8';$BG='#EBF4F9';$CB='#C8DFF0';$G='#1E7A5C';$R='#B83232';$A='#C07A1A';$MU='#7A90A4';$FIRE='#E67E22';
?><!DOCTYPE html>
<html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Wins · Rachas · Mood</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:<?=$BG?>;font-family:'DM Sans',sans-serif;font-size:13px;color:<?=$P1?>;padding:20px}
.hd{background:<?=$P1?>;color:#fff;border-radius:14px;padding:16px 20px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.hd h1{font-size:14px;font-weight:900;letter-spacing:2px;text-transform:uppercase}
.hd .sub{font-size:9px;color:rgba(255,255,255,.6);letter-spacing:2px;text-transform:uppercase;margin-top:3px}
.btn{border:none;border-radius:9px;padding:8px 14px;font-size:10px;font-weight:900;cursor:pointer;font-family:inherit;letter-spacing:1px;text-transform:uppercase;text-decoration:none;display:inline-block}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:760px){.grid{grid-template-columns:1fr}}
.card{background:#fff;border:1px solid <?=$CB?>;border-radius:13px;padding:16px 18px;margin-bottom:16px}
.lbl{font-size:9px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:12px}
.av{width:26px;height:26px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:9px;font-weight:900;color:#fff;flex-shrink:0}
.row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid <?=$BG?>}
.row:last-child{border-bottom:none}
.moodbtn{font-size:26px;background:none;border:2px solid transparent;border-radius:11px;padding:5px 7px;cursor:pointer;line-height:1;transition:.1s}
.moodbtn:hover{background:<?=$BG?>}
.moodbtn.sel{border-color:<?=$P2?>;background:<?=$BG?>}
input[type=text]{width:100%;border:1.5px solid <?=$CB?>;border-radius:9px;padding:10px 12px;font-family:inherit;font-size:13px;color:<?=$P1?>}
input[type=text]:focus{outline:none;border-color:<?=$P2?>}
.fire{font-size:12px;font-weight:900}
.del{background:none;border:none;color:<?=$MU?>;cursor:pointer;font-size:13px;opacity:.5}
.del:hover{opacity:1;color:<?=$R?>}
</style></head><body>

<div class="hd">
  <div>
    <h1>🔥 Wins · Rachas · Mood</h1>
    <div class="sub"><?=date('d/m/Y',strtotime($hoy))?> · Energía del equipo · Medicare with Isabel</div>
  </div>
  <a href="index.php" class="btn" style="background:rgba(255,255,255,.12);color:#fff">← CRM</a>
</div>

<!-- MOOD -->
<div class="card" style="border-top:4px solid <?=$P2?>">
  <div class="lbl">😊 ÁNIMO DEL EQUIPO — HOY</div>
  <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:14px">
    <div style="text-align:center">
      <div style="font-size:42px;line-height:1"><?= $moodAvg>0 ? $MOODS[(int)round($moodAvg)] : '—' ?></div>
      <div style="font-size:11px;font-weight:900;color:<?=$P1?>;margin-top:4px"><?= $moodAvg>0 ? $moodAvg.'/5' : 'sin datos' ?></div>
      <div style="font-size:8px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px"><?=count($moodVals)?> de <?=count($agentes)?> registraron</div>
    </div>
    <div style="flex:1;min-width:200px;display:flex;flex-wrap:wrap;gap:8px">
      <?php foreach($agentes as $a): $mv=$moodHoy[(int)$a['id']]??0; ?>
        <div style="display:flex;align-items:center;gap:6px;background:<?=$BG?>;border-radius:9px;padding:5px 10px">
          <span class="av" style="background:<?=h($a['color']?:'#2876A8')?>"><?=h($a['iniciales']?:'?')?></span>
          <span style="font-size:18px"><?= $mv>0 ? $MOODS[$mv] : '·' ?></span>
        </div>
      <?php endforeach;?>
    </div>
  </div>
  <div style="border-top:1px dashed <?=$CB?>;padding-top:12px">
    <div style="font-size:9px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">¿Cómo te sientes hoy, <?=h($user['nombre'])?>?</div>
    <form method="POST" style="display:flex;gap:4px">
      <input type="hidden" name="action" value="mood">
      <?php for($v=1;$v<=5;$v++): ?>
        <button class="moodbtn <?=$miMood===$v?'sel':''?>" name="valor" value="<?=$v?>" type="submit" title="<?=$v?>/5"><?=$MOODS[$v]?></button>
      <?php endfor;?>
    </form>
  </div>
</div>

<div class="grid">
  <!-- RACHAS -->
  <div class="card" style="border-top:4px solid <?=$FIRE?>">
    <div class="lbl">🔥 RACHAS — DÍAS SEGUIDOS LLAMANDO</div>
    <?php foreach($rachas as $i=>$r): $s=$r['streak']; $a=$r['a'];
      $color = $s>=3 ? $FIRE : ($s>0 ? $P2 : $MU);
      $medal = $i===0 && $s>0 ? '🥇' : ($i===1 && $s>0 ? '🥈' : ($i===2 && $s>0 ? '🥉' : ''));
    ?>
    <div class="row">
      <span class="av" style="background:<?=h($a['color']?:'#2876A8')?>"><?=h($a['iniciales']?:'?')?></span>
      <span style="flex:1;font-weight:700;font-size:12px"><?=h($a['nombre'])?> <?=$medal?></span>
      <span class="fire" style="color:<?=$color?>"><?= $s>=3 ? '🔥 '.$s.' días' : ($s>0 ? $s.'d' : '—') ?></span>
    </div>
    <?php endforeach;?>
    <?php if(!$rachas):?><div style="color:<?=$MU?>;font-size:11px;text-align:center;padding:14px">Sin agentes activos</div><?php endif;?>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:10px;text-transform:uppercase;letter-spacing:1px">🔥 = 3+ días consecutivos con llamadas registradas</div>
  </div>

  <!-- WINS -->
  <div class="card" style="border-top:4px solid <?=$G?>">
    <div class="lbl">🎉 WINS DEL EQUIPO</div>
    <form method="POST" style="display:flex;gap:8px;margin-bottom:14px">
      <input type="hidden" name="action" value="win">
      <input type="text" name="texto" placeholder="¿Qué logramos hoy? (ej. 3 inscritos, cita difícil cerrada…)" maxlength="255" required>
      <button class="btn" type="submit" style="background:<?=$G?>;color:#fff;white-space:nowrap">+ WIN</button>
    </form>
    <?php if(!$wins):?>
      <div style="color:<?=$MU?>;font-size:11px;text-align:center;padding:14px">Aún no hay wins. ¡Registra el primero! 🚀</div>
    <?php endif;?>
    <?php foreach($wins as $w): ?>
    <div class="row" style="align-items:flex-start">
      <span class="av" style="background:<?=h($w['color']?:'#2876A8')?>;margin-top:2px"><?=h($w['iniciales']?:'?')?></span>
      <div style="flex:1;min-width:0">
        <div style="font-size:12px;font-weight:600;color:<?=$P1?>">🎉 <?=h($w['texto'])?></div>
        <div style="font-size:8px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:.5px;margin-top:2px"><?=h($w['nombre']?:'—')?> · <?=date('d/m',strtotime($w['fecha']))?></div>
      </div>
      <?php if(isAdmin() || (int)$w['agente_id']===$uid): ?>
      <form method="POST" onsubmit="return confirm('¿Borrar este win?')"><input type="hidden" name="action" value="del_win"><input type="hidden" name="wid" value="<?=$w['id']?>"><button class="del" type="submit" title="Borrar">✕</button></form>
      <?php endif;?>
    </div>
    <?php endforeach;?>
  </div>
</div>

</body></html>
