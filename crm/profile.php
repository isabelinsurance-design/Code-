<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'session_boot.php';
require_once 'config.php';
$user = auth();
$admin = isAdmin();
$uid = $user['id'];

$id = intval($_GET['id'] ?? 0);
if (!$id) { echo '<div style="padding:20px;color:#B83232">ID inválido</div>'; exit; }

$pdo = db();
$m = $pdo->prepare("SELECT m.*,u.nombre as agente_nombre,u.color as agente_col,u.iniciales as agente_ini FROM miembros m LEFT JOIN usuarios u ON m.agente_id=u.id WHERE m.id=?");
$m->execute([$id]); $m = $m->fetch();

if (!$m) { echo '<div style="padding:20px;color:#B83232">MIEMBRO NO ENCONTRADO</div>'; exit; }

$polizas = $pdo->prepare("SELECT * FROM polizas WHERE miembro_id=? ORDER BY tipo"); $polizas->execute([$id]); $polizas=$polizas->fetchAll();
$tickets_m = $pdo->prepare("SELECT * FROM tickets WHERE miembro_id=? ORDER BY created_at DESC"); $tickets_m->execute([$id]); $tickets_m=$tickets_m->fetchAll();
// Post-cita questionnaire table + data
$postcita_list = [];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS citas_seguimiento (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cita_id INT NULL,
        miembro_id INT NOT NULL,
        grupo_medico VARCHAR(255) DEFAULT NULL,
        panel VARCHAR(255) DEFAULT NULL,
        carrier_elegido VARCHAR(100) DEFAULT NULL,
        plan_elegido VARCHAR(255) DEFAULT NULL,
        resultado ENUM('APLICACION','SOLO INFORMACION','REGRESARA','NO INTERESADO','OTRO') DEFAULT NULL,
        fecha_efectiva DATE DEFAULT NULL,
        notas TEXT,
        completada_por INT,
        completada_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_miembro (miembro_id),
        KEY idx_cita (cita_id)
    )");
    $sq = $pdo->prepare("SELECT cs.*, u.nombre as por_nombre FROM citas_seguimiento cs LEFT JOIN usuarios u ON cs.completada_por=u.id WHERE cs.miembro_id=? ORDER BY cs.completada_at DESC");
    $sq->execute([$id]);
    $postcita_list = $sq->fetchAll();
} catch (Exception $e) {}
$soas = $pdo->prepare("SELECT * FROM soa WHERE miembro_id=? ORDER BY created_at DESC"); $soas->execute([$id]); $soas=$soas->fetchAll();
// CMS Compliance: verificar SOA firmado
$soa_valido = false;
foreach($soas as $s) { if($s['estado'] === 'FIRMADO') { $soa_valido = true; break; } }

$actividad = $pdo->prepare("SELECT a.*,u.nombre,u.iniciales,u.color FROM actividad a LEFT JOIN usuarios u ON a.agente_id=u.id WHERE a.miembro_id=? ORDER BY a.fecha_hora DESC LIMIT 20"); $actividad->execute([$id]); $actividad=$actividad->fetchAll();
$notas = $pdo->prepare("SELECT n.*,u.nombre as autor,u.iniciales,u.color
                        FROM notas_miembro n
                        LEFT JOIN usuarios u ON n.agente_id=u.id
                        WHERE n.miembro_id=? ORDER BY n.created_at DESC");
$notas->execute([$id]);
$notas = $notas->fetchAll();
$fam = null;
if ($m['familiar_id']) {
    $st = $pdo->prepare("SELECT id,nombre,apellido,estado,telefono FROM miembros WHERE id=?");
    $st->execute([(int)$m['familiar_id']]);
    $fam = $st->fetch();
}
$pareja = null;
if (!empty($m['pareja_id'])) {
    $stp = $pdo->prepare("SELECT id,nombre,apellido,estado,telefono FROM miembros WHERE id=?");
    $stp->execute([(int)$m['pareja_id']]);
    $pareja = $stp->fetch();
}

// ── RETENCIÓN: llamadas y cuestionario ───────────────────────────
$_pr_bienvenida = null;
$_pr_calls      = [];
$_pr_q30        = null;
$_pr_dias       = null;
if ($m['estado'] === 'ACTIVE' && !empty($m['fecha_efectiva'])) {
    try {
        $s2 = $pdo->prepare("SELECT created_at FROM efectivos_checks WHERE miembro_id=? AND tipo='llam_bienvenida' AND done=1");
        $s2->execute([$id]);
        $_pr_bienvenida = $s2->fetchColumn() ?: null;

        $s3 = $pdo->prepare("SELECT tipo, resultado, completada_at FROM retencion_llamadas WHERE miembro_id=?");
        $s3->execute([$id]);
        foreach ($s3->fetchAll() as $rl) $_pr_calls[$rl['tipo']] = $rl;

        $s4 = $pdo->prepare("SELECT * FROM retencion_cuestionario_30 WHERE miembro_id=?");
        $s4->execute([$id]);
        $_pr_q30 = $s4->fetch() ?: null;
    } catch (Exception $e) {}
    $_pr_dias = (int) round((strtotime(date('Y-m-d')) - strtotime($m['fecha_efectiva'])) / 86400);
}

$P1='#1B4A6B';$P2='#2876A8';$BG='#EBF4F9';$CB='#C8DFF0';$MU='#7A90A4';$TX='#1B3A5C';
$G='#1E7A5C';$R='#B83232';$A='#C07A1A';

$age = $m['dob'] ? (date('Y')-date('Y',strtotime($m['dob']))) : '?';

function ib(string $l, ?string $v): string {
    $vv = htmlspecialchars(($v===null||$v==='')?'—':$v,ENT_QUOTES);
    return "<div style='background:#EBF4F9;border:1px solid #C8DFF0;border-radius:8px;padding:7px 10px'><div style='font-size:7px;font-weight:900;color:#2876A8;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:2px'>$l</div><div style='font-size:11px;font-weight:800;color:#1B3A5C;word-break:break-word'>$vv</div></div>";
}

// Formato bonito de fecha (devuelve — si no hay)
function fdate(?string $v): string {
    if (!$v || $v === '0000-00-00') return '—';
    $t = strtotime($v);
    return $t ? date('m/d/Y', $t) : $v;
}

// Sí/No para tinyint(1)
function siNo($v): string {
    return ((int)$v === 1) ? 'SÍ' : 'NO';
}

// Enmascara SS dejando solo últimos 4
function maskSS(?string $ss): string {
    if (!$ss) return '—';
    $s = preg_replace('/[^0-9]/','',$ss);
    if (strlen($s) < 4) return '***';
    return '***-**-' . substr($s, -4);
}

// Renderiza una sección del INFO COMPLETA
function seccion(string $titulo, array $items, string $P2='#2876A8', string $CB='#C8DFF0'): string {
    $html  = "<div style='margin-bottom:14px'>";
    $html .= "<div style='font-size:8px;font-weight:900;color:$P2;text-transform:uppercase;letter-spacing:2px;margin-bottom:7px;padding-bottom:4px;border-bottom:1px solid $CB'>$titulo</div>";
    $html .= "<div style='display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:6px'>";
    foreach ($items as [$l,$v]) $html .= ib($l,$v);
    $html .= "</div></div>";
    return $html;
}
?>

<style>
.profile-tabs {
display: flex;
gap: 5px;
margin-bottom: 15px;
border-bottom: 1px solid #C8DFF0;
padding-bottom: 5px;
overflow-x: auto;
}

.p-tab {
padding: 8px 15px;
font-size: 10px;
font-weight: 800;
color: #7A90A4;
text-transform: uppercase;
cursor: pointer;
border-radius: 8px;
transition: all 0.3s;
white-space: nowrap;
border: none;
background: transparent;
font-family: 'DM Sans', sans-serif;
}

.p-tab:hover {
background: #EBF4F9;
color: #1B4A6B;
}

.p-tab.active {
background: #1B4A6B;
color: #fff;
}

/* Ocultar las secciones que no están activas */
.tab-content {
display: none;
}

.tab-content.active {
display: block;
}

  </style>


<div style="font-family:'DM Sans',sans-serif">
  <?php if (!$soa_valido): ?>
  <div style="background:#FDF0EE;border:1px solid #EFA09A;border-left:4px solid #B83232;border-radius:9px;padding:12px;margin-bottom:15px;display:flex;align-items:center;gap:10px">
    <span style="font-size:20px">⚠️</span>
    <div>
      <div style="font-weight:900;font-size:10px;color:#B83232;text-transform:uppercase">⚠️ ALERTA DE CUMPLIMIENTO (CMS)</div>
      <div style="font-size:9px;color:#1B3A5C">Este miembro no tiene un SOA firmado. CMS exige que se firme <b>48 horas antes</b> de cualquier presentación de plan.</div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($m['alerta_activa']): ?>
  <div style="background:#FDF0EE;border:1px solid #EFA09A;border-left:4px solid #B83232;border-radius:9px;padding:9px 13px;margin-bottom:12px;display:flex;gap:8px">
    <span>⚡</span><div><div style="font-weight:900;font-size:9px;color:#B83232;letter-spacing:2px;text-transform:uppercase">ALERTA — LEER ANTES DE TRABAJAR</div>
    <div style="font-size:9px;color:<?= $TX ?>;margin-top:2px"><?= h($m['alerta_texto']??'') ?></div></div>
  </div>
  <?php endif; ?>

  <?php if (!empty($m['sales_allegation'] ?? 0)): ?>
  <div style="background:#B83232;border-radius:9px;padding:11px 15px;margin-bottom:13px;display:flex;align-items:center;gap:10px">
    <span style="font-size:18px">⚠️</span>
    <div style="flex:1"><div style="font-weight:900;font-size:10px;color:#fff;text-transform:uppercase;letter-spacing:2px">SALES ALLEGATION ACTIVA</div>
    <div style="font-size:9px;color:rgba(255,255,255,.8);margin-top:2px">Este miembro tiene una alegación de ventas registrada. Proceder con precaución.</div></div>
    <?php if ($admin): ?><button onclick="toggleAllegation(<?=$id?>,0)" style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);border-radius:7px;padding:5px 11px;font-size:8px;font-weight:900;color:#fff;cursor:pointer;font-family:'DM Sans',sans-serif;text-transform:uppercase">QUITAR</button><?php endif; ?>
  </div>
  <?php elseif ($admin): ?>
  <div style="display:flex;justify-content:flex-end;margin-bottom:8px">
    <button onclick="toggleAllegation(<?=$id?>,1)" style="background:#FDF0EE;border:1px solid #EFA09A;border-radius:7px;padding:4px 11px;font-size:8px;font-weight:900;color:#B83232;cursor:pointer;font-family:'DM Sans',sans-serif;text-transform:uppercase">⚠ MARCAR SALES ALLEGATION</button>
  </div>
  <?php endif; ?>

  <!-- Header -->
  <div style="background:linear-gradient(135deg,<?= $P1 ?>,<?= $P2 ?>);border-radius:13px;padding:16px 18px;margin-bottom:13px;display:flex;gap:13px;align-items:flex-start;flex-wrap:wrap">
    <div style="position:relative;flex-shrink:0">
      <?php if (!empty($m['foto_perfil'] ?? null)): ?>
        <img src="<?= h($m['foto_perfil']) ?>" style="width:54px;height:54px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.4);display:block">
      <?php else: ?>
        <div style="width:54px;height:54px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:900;color:#fff"><?= h($m['nombre'][0] ?? '?') ?></div>
      <?php endif; ?>
      <button onclick="document.getElementById('foto-inp-<?=$id?>').click()" title="CAMBIAR FOTO" style="position:absolute;bottom:-3px;right:-3px;width:20px;height:20px;background:#2876A8;border:2px solid #fff;border-radius:50%;cursor:pointer;font-size:10px;color:#fff;display:flex;align-items:center;justify-content:center;padding:0">📷</button>
      <input type="file" id="foto-inp-<?=$id?>" accept="image/*" style="display:none" onchange="subirFoto(<?=$id?>,this)">
    </div>
    <div style="flex:1;min-width:180px">
      <div style="font-size:14px;font-weight:900;color:#fff;letter-spacing:3px;text-transform:uppercase;line-height:1;margin-bottom:3px"><?= h(trim($m['nombre'].' '.($m['middle_name']??'').' '.$m['apellido'])) ?></div>
      <div style="font-size:8px;color:rgba(255,255,255,.65);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:7px"><?= h($m['telefono']) ?> · <?= h($m['ciudad']) ?> · <?= $age ?> AÑOS</div>
      <div style="display:flex;gap:4px;flex-wrap:wrap">
        <span style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:20px;padding:2px 9px;font-size:8px;font-weight:900;letter-spacing:.5px;text-transform:uppercase"><?= h($m['estado']) ?></span>
        <?php if ($admin): ?><span style="background:rgba(255,255,255,.1);color:rgba(255,255,255,.8);border:1px solid rgba(255,255,255,.2);border-radius:20px;padding:2px 9px;font-size:8px;font-weight:700"><?= h($m['agente_nombre']??'') ?></span><?php endif; ?>
        <?php if (!$m['info_verificada']): ?><span style="background:rgba(192,122,26,.3);color:#FDE68A;border:1px solid rgba(192,122,26,.4);border-radius:20px;padding:2px 9px;font-size:8px;font-weight:900">⚠ SIN VERIFICAR</span><?php endif; ?>
        <?php if ($m['carpeta_drive']): ?><a href="<?= h($m['carpeta_drive']) ?>" target="_blank" style="background:rgba(22,64,168,.25);color:#93C5FD;border:1px solid rgba(22,64,168,.35);border-radius:20px;padding:2px 9px;font-size:8px;font-weight:900;text-decoration:none">📁 DRIVE</a><?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:4px;flex-wrap:wrap;flex-shrink:0">
        <button onclick="openSmsModal('<?= h($m['nombre'].' '.$m['apellido']) ?>','<?= h($m['telefono']) ?>')"
            class="btn btn-gh btn-sm"
            style="background:rgba(255,255,255,.1);color:rgba(255,255,255,.9);border-color:rgba(255,255,255,.2)">◌ SMS</button>

        <button onclick="openTicketForm(<?= $id ?>)" class="btn btn-am btn-sm" style="background:rgba(192,122,26,.3);color:#FDE68A;border-color:rgba(192,122,26,.4)">◈ TICKET</button>
        <?php if ($m['estado'] === 'ACTIVE'): ?>
        <button onclick="openCambioPlanModal()" class="btn btn-gh btn-sm" style="background:rgba(30,122,92,.3);color:#6EE7C0;border-color:rgba(30,122,92,.4)">🔄 CAMBIO DE PLAN</button>
        <?php endif; ?>
        <button onclick="openMemberForm(<?= $id ?>)" class="btn btn-gh btn-sm" style="background:rgba(255,255,255,.1);color:rgba(255,255,255,.85);border-color:rgba(255,255,255,.2)">✎ EDITAR</button>

    </div>
  </div>

  <!-- Process Steps -->
  <div style="margin-bottom:12px">
    <div style="font-size:7px;font-weight:900;color:<?= $P2 ?>;text-transform:uppercase;letter-spacing:2px;margin-bottom:6px">PROCESO</div>
    <div style="display:flex;gap:3px;flex-wrap:wrap">
      <?php foreach (['CONTACTO','SOA FIRMADO','PLAN OK','APP ENVIADA','EN REVISIÓN','APROBADO','EFECTIVO'] as $i=>$s):
        $done = $m['estado']==='ACTIVO' && $i<5;
        $cur  = $m['estado']==='ACTIVO' && $i===4;
        $bg   = $done?'#EAF5F0':($cur?'#EBF5FB':'#EBF4F9');
        $bc   = $done?'#8DCFBA':($cur?'#A9D0E8':'#C8DFF0');
        $fc   = $done?'#1E7A5C':($cur?'#1B5E8C':'#7A90A4');
      ?>
      <div style="flex:1;min-width:60px;padding:6px 5px;border-radius:7px;text-align:center;font-size:7px;font-weight:900;text-transform:uppercase;letter-spacing:.5px;border:1px solid <?= $bc ?>;background:<?= $bg ?>;color:<?= $fc ?>">
        <?= $done?'✓ ':($cur?'◐ ':'') ?><?= $s ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Profile Tabs -->
  <div class="profile-tabs">
    <?php $ptabs=['RESUMEN','INFO COMPLETA','PÓLIZAS','PLAN','HISTORIAL','TICKETS ('.count($tickets_m).')','SOA','FAMILIA','NOTAS ('.count($notas).')','POST-CITA ('.count($postcita_list).')'];
    foreach ($ptabs as $i=>$pt): ?>
    <button class="p-tab<?= $i===0?' active':'' ?>" onclick="showPTab('<?= $pt ?>')" data-ptab="<?= $pt ?>"><?= $pt ?></button>
    <?php endforeach; ?>
  </div>

  <!-- =================== RESUMEN =================== -->
  <div class="tab-content active" id="ptab-RESUMEN">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:7px;margin-bottom:11px">
      <?php foreach ([
        ['MBI',$m['mbi']],
        ['MEMBER ID',$m['member_id']],
        ['CARRIER',$m['carrier']],
        ['PLAN',$m['plan']],
        ['F. EFECTIVA',fdate($m['fecha_efectiva'])],
        ['CIUDAD',($m['ciudad']??'').($m['ciudad']?', CA':'')],
        ['EDAD',$age.' AÑOS'],
        ['FUENTE',$m['fuente']],
        ['PARTE A',fdate($m['parte_a'])],
        ['PARTE B',fdate($m['parte_b'])],
        ['MEDI-CAL',$m['medical']],
        ['ELEGIBILIDAD',$m['elegibilidad']]
      ] as [$l,$v]): ?>
      <?= ib($l,$v) ?>
      <?php endforeach; ?>
    </div>
    <?php if ($m['email']): ?><div style="background:#EBF5FB;border:1px solid #A9D0E8;border-radius:8px;padding:7px 11px;margin-bottom:7px;font-size:9px">📧 <?= h($m['email']) ?></div><?php endif; ?>
    <?php if ($m['prescripciones']): ?><div style="background:#EAF4F6;border:1px solid #8DC8D0;border-radius:8px;padding:7px 11px;margin-bottom:7px;font-size:9px"><b style="color:#1E7A8C">◎ PRESCRIPCIONES:</b> <?= h($m['prescripciones']) ?></div><?php endif; ?>
    <?php if ($m['condiciones_cronicas']): ?><div style="background:#FDF0EE;border:1px solid #EFA09A;border-radius:8px;padding:7px 11px;margin-bottom:7px;font-size:9px"><b style="color:#B83232">◈ CONDICIONES:</b> <?= h($m['condiciones_cronicas']) ?></div><?php endif; ?>
    <?php if ($m['especialistas']): ?><div style="background:#F3F0FB;border:1px solid #C2B0E8;border-radius:8px;padding:7px 11px;margin-bottom:7px;font-size:9px"><b style="color:#5B3FAF">👨‍⚕️ ESPECIALISTAS:</b> <?= h($m['especialistas']) ?></div><?php endif; ?>
    <?php if ($m['extras']): ?><div style="background:#FEF8EE;border:1px solid #F5D5A0;border-radius:8px;padding:7px 11px;margin-bottom:7px;font-size:9px"><b style="color:#C07A1A">📌 EXTRAS:</b> <?= h($m['extras']) ?></div><?php endif; ?>

    <!-- Llamadas retención (nuevo sistema) -->
    <?php if ($m['estado'] === 'ACTIVE'): ?>
    <div style="background:<?= $BG ?>;border:1px solid <?= $CB ?>;border-left:3px solid #2876A8;border-radius:8px;padding:10px 13px;margin-bottom:7px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div style="font-size:8px;font-weight:900;color:<?= $P2 ?>;text-transform:uppercase;letter-spacing:2px">📞 RETENCIÓN</div>
        <?php if ($_pr_dias !== null): ?><div style="font-size:8px;color:<?= $MU ?>"><?=$_pr_dias?> días activo</div><?php endif; ?>
      </div>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:<?=$_pr_q30?'10':'0'?>px">
        <?php
        $pr_chips = [
          ['BIENVENIDA', $_pr_bienvenida, $_pr_bienvenida ? 'COMPLETADA' : null],
          ['30 DÍAS',    $_pr_calls['30']['completada_at'] ?? null, $_pr_calls['30']['resultado'] ?? null],
          ['60 DÍAS',    $_pr_calls['60']['completada_at'] ?? null, $_pr_calls['60']['resultado'] ?? null],
          ['90 DÍAS',    $_pr_calls['90']['completada_at'] ?? null, $_pr_calls['90']['resultado'] ?? null],
        ];
        foreach ($pr_chips as [$lbl, $cat, $res]):
          $color = $res === 'COMPLETADA' ? '#1E7A5C' : ($res === 'BUZÓN' ? '#C07A1A' : ($res ? '#B83232' : '#7A90A4'));
          $bg    = $res === 'COMPLETADA' ? '#EAF5F0' : ($res === 'BUZÓN' ? '#FEF8EE' : ($res ? '#FDF0EE' : '#fff'));
          $bc    = $res === 'COMPLETADA' ? '#8DCFBA' : ($res === 'BUZÓN' ? '#F5D5A0' : ($res ? '#EFA09A' : '#C8DFF0'));
        ?>
        <div style="text-align:center;padding:6px 4px;background:<?=$bg?>;border-radius:7px;border:1px solid <?=$bc?>">
          <div style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px"><?=$lbl?></div>
          <?php if ($cat): ?>
            <div style="font-size:8px;font-weight:900;color:<?=$color?>">✓ <?=$res?></div>
            <div style="font-size:7px;color:<?=$MU?>"><?=date('d/m/y',strtotime($cat))?></div>
          <?php else: ?>
            <div style="font-size:8px;color:<?=$MU?>">○ PENDIENTE</div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($_pr_q30): ?>
      <!-- Resultados del cuestionario 30 días -->
      <div style="background:#fff;border:1px solid #A9D0E8;border-radius:8px;padding:10px 12px;margin-top:8px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <div style="font-size:8px;font-weight:900;color:#1B4A6B;text-transform:uppercase;letter-spacing:1px">📋 Cuestionario 30 Días</div>
          <div style="font-size:7px;color:<?=$MU?>"><?=date('d/m/Y', strtotime($_pr_q30['completada_at']))?></div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:5px;font-size:8px;margin-bottom:7px">
          <?php
          $q30_items = [
            ['SMS',           $_pr_q30['puede_sms'] ?? null],
            ['WhatsApp',      $_pr_q30['usa_whatsapp'] ?? null],
            ['Facebook',      $_pr_q30['usa_facebook'] ?? null],
            ['Nos siguió',    $_pr_q30['nos_siguio'] ?? null],
            ['Insulina',      $_pr_q30['usa_insulina'] ?? null],
            ['Delivery med.', $_pr_q30['necesita_delivery'] ?? null],
            ['Llegó tarjeta', $_pr_q30['llego_tarjeta'] ?? null],
            ['Explic.tarjeta',$_pr_q30['explicaste_tarjeta'] ?? null],
            ['Dir. correcta', $_pr_q30['direccion_correcta'] ?? null],
            ['Doctor OK',     $_pr_q30['doctor_correcto'] ?? null],
            ['Fue a citas',   $_pr_q30['ha_ido_citas'] ?? null],
            ['Satisfecho dr.',$_pr_q30['satisfecho_doctor'] ?? null],
            ['Cambiar dr.',   $_pr_q30['cambiar_doctor'] ?? null],
            ['Va dentista',   $_pr_q30['va_dentista'] ?? null],
            ['Anteojos',      $_pr_q30['usa_anteojos'] ?? null],
            ['Expl. Uber',    $_pr_q30['explicaste_uber'] ?? null],
            ['Expl. Gym',     $_pr_q30['explicaste_gym'] ?? null],
            ['No dar info',   $_pr_q30['explicaste_no_dar_info'] ?? null],
          ];
          foreach ($q30_items as [$ql, $qv]):
            $qcolor = ($qv === null || $qv === '') ? '#94A3B8' : ((int)$qv ? '#1E7A5C' : '#B83232');
            $qtext  = ($qv === null || $qv === '') ? '—' : ((int)$qv ? 'SÍ' : 'NO');
          ?>
          <div style="background:#EBF4F9;border:1px solid #C8DFF0;border-radius:5px;padding:4px 7px">
            <div style="font-size:6.5px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:.3px"><?=$ql?></div>
            <div style="font-size:9px;font-weight:900;color:<?=$qcolor?>"><?=$qtext?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if (!empty($_pr_q30['ayudas_movilidad'])): ?>
        <div style="font-size:8px;padding:4px 8px;background:#EBF4F9;border-radius:5px;margin-bottom:5px">
          <b style="color:<?=$MU?>">Dispositivos:</b> <?=h($_pr_q30['ayudas_movilidad'])?>
        </div>
        <?php endif; ?>
        <?php if (!empty($_pr_q30['donde_conocio_isabel'])): ?>
        <div style="font-size:8px;padding:4px 8px;background:#EBF4F9;border-radius:5px;margin-bottom:5px">
          <b style="color:<?=$MU?>">Conoció a Isabel:</b> <?=h($_pr_q30['donde_conocio_isabel'])?>
        </div>
        <?php endif; ?>
        <?php if (!empty($_pr_q30['referido_nuevo'])): ?>
        <div style="font-size:8px;padding:4px 8px;background:#EAF5F0;border:1px solid #8DCFBA;border-radius:5px;margin-bottom:5px">
          <b style="color:#1E7A5C">🤝 Referido nuevo:</b> <?=h($_pr_q30['referido_nuevo'])?>
        </div>
        <?php endif; ?>
        <?php if (!empty($_pr_q30['beneficios_repasados'])): ?>
        <div style="font-size:8px;padding:4px 8px;background:#EBF4F9;border-radius:5px;margin-bottom:5px">
          <b style="color:<?=$MU?>">Beneficios explicados:</b> <?=h(str_replace(',', ' · ', $_pr_q30['beneficios_repasados']))?>
        </div>
        <?php endif; ?>
        <?php if (!empty($_pr_q30['notas_generales'])): ?>
        <div style="font-size:8px;padding:4px 8px;background:#FEF8EE;border:1px solid #F5D5A0;border-radius:5px;font-style:italic;color:#1B3A5C">
          "<?=h($_pr_q30['notas_generales'])?>"
        </div>
        <?php endif; ?>
      </div>
      <?php elseif ($_pr_dias !== null && $_pr_dias >= 25): ?>
      <div style="margin-top:8px;padding:6px 10px;background:#FEF8EE;border:1px solid #F5D5A0;border-radius:6px;font-size:8px;color:#C07A1A;font-weight:800">
        ⚠️ Cuestionario 30 días pendiente — completar en tab RETENCIÓN
      </div>
      <?php endif; ?>
    </div>
    <?php endif; // ACTIVE ?>

    <!-- App Control -->
    <?php if ($m['app_tipo']||$m['app_fecha']||$m['app_estado_cms']): ?>
    <div style="background:<?= $BG ?>;border:1px solid <?= $CB ?>;border-radius:8px;padding:10px 13px;margin-bottom:7px">
      <div style="font-size:8px;font-weight:900;color:<?= $P2 ?>;text-transform:uppercase;letter-spacing:2px;margin-bottom:8px">◎ CONTROL DE APLICACIÓN</div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px">
        <?php foreach ([['TIPO',$m['app_tipo']],['PERÍODO',$m['app_periodo']],['FECHA APP',fdate($m['app_fecha'])],['ESTADO CMS',$m['app_estado_cms']],['CARRIER ESTADO',$m['app_carrier_estado']],['HRA',$m['hra']]] as [$l,$v]): ?>
        <div style="background:#fff;border:1px solid <?= $CB ?>;border-radius:7px;padding:6px 9px">
          <div style="font-size:7px;font-weight:900;color:<?= $MU ?>;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px"><?= $l ?></div>
          <div style="font-size:9px;font-weight:800;color:<?= $TX ?>"><?= h(($v===null||$v==='')?'—':$v) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- =================== INFO COMPLETA =================== -->
  <div class="tab-content" id="ptab-INFO COMPLETA">

    <?= seccion('INFORMACIÓN PERSONAL', [
      ['NOMBRE',           $m['nombre']],
      ['MIDDLE NAME',      $m['middle_name'] ?? null],
      ['APELLIDO',         $m['apellido']],
      ['FECHA NAC.',       fdate($m['dob'])],
      ['EDAD',             $age.' AÑOS'],
      ['SEXO',             $m['sexo']],
      ['IDIOMA',           $m['idioma']],
      ['ESTADO CIVIL',     $m['estado_civil']],
      ['PAREJA/ESPOSO(A)', $pareja ? $pareja['apellido'].', '.$pareja['nombre'] : null],
      ['SOCIAL (SS)',      $admin ? maskSS($m['ss']) : '—'],
    ]) ?>

    <?= seccion('CONTACTO', [
      ['TELÉFONO',         $m['telefono']],
      ['TELÉFONO 2',       $m['telefono2']],
      ['EMAIL',            $m['email']],
      ['OPT-IN SMS',       siNo($m['opt_in'] ?? 0)],
      ['OPT-OUT',          siNo($m['opt_out'] ?? 0)],
      ['INFO VERIFICADA',  siNo($m['info_verificada'] ?? 0)],
    ]) ?>

    <?= seccion('DIRECCIÓN', [
      ['CALLE',            $m['direccion_calle']],
      ['APTO / SUITE',     $m['direccion_apto']],
      ['CIUDAD',           $m['ciudad']],
      ['COUNTY',           $m['county']],
      ['ZIP',              $m['zip']],
    ]) ?>

    <?= seccion('MEDICARE', [
      ['MBI',              $m['mbi']],
      ['MEMBER ID',        $m['member_id']],
      ['PARTE A',          fdate($m['parte_a'])],
      ['PARTE B',          fdate($m['parte_b'])],
      ['ELEGIBILIDAD',     $m['elegibilidad']],
      ['MEDI-CAL',         $m['medical']],
      ['NIVEL MEDI-CAL',   $m['medical_nivel']],
    ]) ?>

    <?= seccion('PLAN ACTUAL', [
      ['CARRIER',          $m['carrier']],
      ['PLAN',             $m['plan']],
      ['TIPO DE PLAN',     $m['tipo_plan']],
      ['F. EFECTIVA',      fdate($m['fecha_efectiva'])],
      ['PLAN ANTERIOR',    $m['plan_anterior']],
      ['PLAN SECUNDARIO',  $m['plan_secundario']],
    ]) ?>

    <?= seccion('DOCTOR PCP', [
      ['NOMBRE PCP',       $m['pcp']],
      ['GRUPO MÉDICO',     $m['pcp_group'] ?? null],
      ['TELÉFONO PCP',     $m['pcp_phone'] ?? null],
      ['DIRECCIÓN PCP',    $m['pcp_address'] ?? null],
      ['CIUDAD PCP',       $m['pcp_city'] ?? null],
      ['ESTADO PCP',       $m['pcp_state'] ?? null],
      ['ZIP PCP',          $m['pcp_zip'] ?? null],
      ['DENTISTA',         $m['dentista']],
    ]) ?>

    <?= seccion('SALUD', [
      ['PRESCRIPCIONES',   $m['prescripciones']],
      ['CONDICIONES',      $m['condiciones_cronicas']],
      ['ESPECIALISTAS',    $m['especialistas']],
    ]) ?>

    <?= seccion('CONTROL DE APLICACIÓN', [
      ['APP TIPO',         $m['app_tipo']],
      ['APP PERÍODO',      $m['app_periodo']],
      ['APP FECHA',        fdate($m['app_fecha'])],
      ['ESTADO CMS',       $m['app_estado_cms']],
      ['CARRIER ESTADO',   $m['app_carrier_estado']],
      ['HRA',              $m['hra']],
    ]) ?>

    <?= seccion('LLAMADAS DE SEGUIMIENTO', [
      ['BIENVENIDA',       $m['llam_bienvenida']],
      ['30 DÍAS',          $m['llam_30']],
      ['60 DÍAS',          $m['llam_60']],
      ['90 DÍAS',          $m['llam_90']],
      ['ESTADO LLAMADA',   $m['llamada_estado'] ?? null],
      ['LLAMADA NUEVA',    $m['llamada_nueva'] ?? null],
      ['ÚLT. CONTACTO',    $m['ultimo_contacto'] ?? null],
    ]) ?>

    <?= seccion('GESTIÓN', [
      ['ESTADO',           $m['estado']],
      ['SUBESTADO',        $m['subestado']],
      ['BROKER MWI',       $m['broker_mwi'] ?? null],
      ['AGENTE',           $m['agente_nombre'] ?? null],
      ['FUENTE',           $m['fuente']],
      ['REFERIDO POR',     $m['referido_por']],
      ['EVENTO',           $m['evento']],
      ['F. CANCELACIÓN',   fdate($m['fecha_cancelacion'] ?? null)],
      ['RAZÓN CANCEL.',    $m['razon_cancelacion']],
      ['COMMISSION PAID',  $m['commission_paid'] ?? null],
      ['FUENTE CSV',       $m['fuente_csv'] ?? null],
    ]) ?>

    <?= seccion('OTROS', [
      ['PROFESIÓN',        $m['profesion']],
      ['EMPRESA',          $m['empresa']],
      ['ESTATUS LEGAL',    $m['estatus_legal']],
      ['CARPETA DRIVE',    $m['carpeta_drive']],
      ['EXTRAS',           $m['extras']],
      ['ALERTA ACTIVA',    siNo($m['alerta_activa'] ?? 0)],
      ['TEXTO ALERTA',     $m['alerta_texto']],
      ['SALES ALLEGATION', (!empty($m['sales_allegation'] ?? 0)) ? '⚠ SÍ' : 'NO'],
    ]) ?>

    <?= seccion('METADATA', [
      ['CREADO',           fdate(substr($m['created_at']??'',0,10)).' '.substr($m['created_at']??'',11,5)],
      ['ACTUALIZADO',      fdate(substr($m['updated_at']??'',0,10)).' '.substr($m['updated_at']??'',11,5)],
      ['ID INTERNO',       (string)$m['id']],
    ]) ?>

    <div style="margin-top:14px;text-align:center">
      <button onclick="openMemberForm(<?= $id ?>)" class="btn btn-b btn-sm">✎ EDITAR INFORMACIÓN</button>
    </div>
  </div>

  <!-- =================== PÓLIZAS =================== -->
  <div class="tab-content" id="ptab-PÓLIZAS">
    <?php if (count($polizas)):
      foreach ($polizas as $p): ?>
      <div style="background:<?= $BG ?>;border:1px solid <?= $CB ?>;border-radius:9px;padding:11px 13px;margin-bottom:8px;border-left:3px solid <?= $P1 ?>">
        <div style="display:flex;justify-content:space-between;margin-bottom:5px">
          <div><span style="background:#EBF5FB;color:#1B5E8C;border:1px solid #A9D0E8;border-radius:20px;padding:2px 8px;font-size:8px;font-weight:900;margin-right:6px"><?= h($p['tipo']) ?></span><b style="font-size:10px;color:<?= $P1 ?>"><?= h($p['plan']??'—') ?></b></div>
          <span style="background:<?= $p['estado']==='ACTIVA'?'#EAF5F0':'#FDF0EE' ?>;color:<?= $p['estado']==='ACTIVA'?$G:$R ?>;border:1px solid <?= $p['estado']==='ACTIVA'?'#8DCFBA':'#EFA09A' ?>;border-radius:20px;padding:2px 8px;font-size:8px;font-weight:900"><?= h($p['estado']) ?></span>
        </div>
        <div style="font-size:8px;color:<?= $MU ?>;letter-spacing:.5px"><?= h($p['carrier']??'—') ?> · EF: <?= fdate($p['fecha_efectiva']) ?> · PRIMA: <?= $p['prima']>0?'$'.$p['prima'].'/MES':'$0 (INCLUIDA)' ?></div>
      </div>
    <?php endforeach;
    else: ?><div style="padding:16px 0;text-align:center;font-size:8px;color:<?= $MU ?>;letter-spacing:2px;text-transform:uppercase">SIN PÓLIZAS REGISTRADAS</div><?php endif; ?>
  </div>

  <!-- =================== PLAN =================== -->
  <div class="tab-content" id="ptab-PLAN">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:9px">
      <div style="background:#EBF5FB;border:1px solid #A9D0E8;border-radius:11px;padding:13px">
        <div style="font-size:7px;font-weight:900;color:<?= $P2 ?>;text-transform:uppercase;letter-spacing:2px;margin-bottom:7px">PLAN PRINCIPAL</div>
        <div style="font-size:13px;font-weight:900;color:<?= $P1 ?>;letter-spacing:1.5px;margin-bottom:2px"><?= h($m['plan']??'SIN PLAN') ?></div>
        <div style="font-size:9px;color:<?= $MU ?>;margin-bottom:6px"><?= h($m['carrier']??'—') ?> · <?= h($m['tipo_plan']??'—') ?></div>
        <?php if ($m['fecha_efectiva']): ?><div style="font-size:8px;letter-spacing:.5px;color:<?= $TX ?>">EFECTIVA: <b><?= fdate($m['fecha_efectiva']) ?></b></div><?php endif; ?>
        <?php if ($m['plan_anterior']): ?><div style="font-size:8px;color:<?= $MU ?>;margin-top:5px">ANTERIOR: <?= h($m['plan_anterior']) ?></div><?php endif; ?>
        <?php if ($m['plan_secundario']): ?><div style="font-size:8px;color:<?= $MU ?>;margin-top:3px">SECUNDARIO: <?= h($m['plan_secundario']) ?></div><?php endif; ?>
      </div>
      <div style="background:#EAF4F6;border:1px solid #8DC8D0;border-radius:11px;padding:13px">
        <div style="font-size:7px;font-weight:900;color:#1E7A8C;text-transform:uppercase;letter-spacing:2px;margin-bottom:7px">DOCTORES</div>
        <div style="font-size:12px;font-weight:900;color:<?= $P1 ?>;margin-bottom:5px"><?= h($m['pcp']??'SIN ASIGNAR') ?></div>
        <?php if (!empty($m['pcp_group'])): ?><div style="font-size:9px;color:<?= $MU ?>;margin-bottom:3px">🏥 <?= h($m['pcp_group']) ?></div><?php endif; ?>
        <?php if (!empty($m['pcp_phone'])): ?><div style="font-size:9px;color:<?= $MU ?>;margin-bottom:3px">📞 <?= h($m['pcp_phone']) ?></div><?php endif; ?>
        <?php if ($m['dentista']): ?><div style="font-size:9px;color:<?= $MU ?>;margin-bottom:3px">🦷 <?= h($m['dentista']) ?></div><?php endif; ?>
        <?php if ($m['especialistas']): ?><div style="font-size:8px;color:<?= $MU ?>">👨‍⚕️ <?= h($m['especialistas']) ?></div><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- =================== HISTORIAL =================== -->
  <div class="tab-content" id="ptab-HISTORIAL">
    <?php if (count($actividad)):
      foreach ($actividad as $a): ?>
      <div style="display:flex;gap:9px;padding:9px 0;border-bottom:1px solid <?= $CB ?>;align-items:flex-start">
        <div style="width:28px;height:28px;background:<?= $BG ?>;border:1px solid <?= $CB ?>;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0">
          <?= ['LLAMADA'=>'◌','TICKET'=>'◈','PÓLIZA'=>'◎','NOTA'=>'◉','CHECK-IN'=>'▣','SMS'=>'◌'][$a['tipo']]??'•' ?>
        </div>
        <div style="flex:1">
          <div style="font-weight:900;font-size:9px;color:<?= $P1 ?>;letter-spacing:1px;text-transform:uppercase;margin-bottom:2px"><?= h($a['tipo']) ?><?php if ($a['nombre']): ?> — <?= h(explode(' ',$a['nombre'])[0]) ?><?php endif; ?></div>
          <div style="font-size:9px;color:<?= $TX ?>;letter-spacing:.5px"><?= h($a['descripcion']??'') ?></div>
          <div style="font-size:8px;color:<?= $MU ?>;margin-top:2px"><?= $a['fecha_hora'] ?></div>
        </div>
      </div>
    <?php endforeach;
    else: ?><div style="padding:16px 0;text-align:center;font-size:8px;color:<?= $MU ?>;letter-spacing:2px;text-transform:uppercase">SIN HISTORIAL</div><?php endif; ?>
  </div>

  <!-- =================== TICKETS =================== -->
  <div class="tab-content" id="ptab-TICKETS (<?= count($tickets_m) ?>)">
    <?php if (count($tickets_m)):
      foreach ($tickets_m as $t): ?>
      <div style="background:<?= $BG ?>;border:1px solid <?= $CB ?>;border-radius:8px;padding:10px 12px;margin-bottom:7px;border-left:3px solid <?= $t['prioridad']==='ALTA'?$R:$A ?>">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
          <div style="font-weight:900;font-size:10px;color:<?= $P1 ?>;letter-spacing:.5px"><?= h($t['descripcion']) ?></div>
          <div style="display:flex;gap:5px">
            <span style="background:#fff;color:<?= $t['prioridad']==='ALTA'?$R:$A ?>;border-radius:20px;padding:1px 6px;font-size:8px;font-weight:900;border:1px solid <?= $t['prioridad']==='ALTA'?'#EFA09A':'#F5D5A0' ?>"><?= h($t['prioridad']) ?></span>
            <span style="background:<?= $t['estado']==='ABIERTO'?'#FDF0EE':($t['estado']==='CERRADO'?'#EAF5F0':'#FEF8EE') ?>;color:<?= $t['estado']==='ABIERTO'?$R:($t['estado']==='CERRADO'?$G:$A) ?>;border-radius:20px;padding:1px 6px;font-size:8px;font-weight:900;border:1px solid"><?= h($t['estado']) ?></span>
          </div>
        </div>
        <div style="font-size:8px;color:<?= $MU ?>;letter-spacing:.5px"><?= h($t['tipo']) ?> · F/U: <?= fdate($t['fecha_seguimiento']) ?></div>
      </div>
    <?php endforeach;
    else: ?><div style="padding:16px 0;text-align:center;font-size:8px;color:<?= $MU ?>;letter-spacing:2px;text-transform:uppercase">SIN TICKETS</div><?php endif; ?>
  </div>

  <!-- =================== SOA =================== -->
  <div class="tab-content" id="ptab-SOA">
    <div style="background:#EBF5FB;border:1px solid #A9D0E8;border-radius:8px;padding:8px 12px;margin-bottom:11px;font-size:8px;color:#1B5E8C;font-weight:800;letter-spacing:1px;text-transform:uppercase">
      CMS: SOA REQUERIDO 48H ANTES DE CITA · GUARDAR MÍNIMO 10 AÑOS
    </div>
    <?php if (count($soas)):
      foreach ($soas as $s): ?>
      <div style="background:<?= $BG ?>;border:1px solid <?= $s['estado']==='FIRMADO'?'#8DCFBA':$CB ?>;border-radius:8px;padding:11px 13px;margin-bottom:8px;border-left:3px solid <?= $s['estado']==='FIRMADO'?$G:$A ?>">
        <div style="display:flex;justify-content:space-between;margin-bottom:4px">
          <div style="font-weight:900;font-size:9px;color:<?= $P1 ?>;letter-spacing:1px;text-transform:uppercase"><?= h($s['tipo_plan']??'MEDICARE ADVANTAGE') ?></div>
          <span style="background:<?= $s['estado']==='FIRMADO'?'#EAF5F0':'#FEF8EE' ?>;color:<?= $s['estado']==='FIRMADO'?$G:$A ?>;border-radius:20px;padding:2px 8px;font-size:8px;font-weight:900;border:1px solid <?= $s['estado']==='FIRMADO'?'#8DCFBA':'#F5D5A0' ?>"><?= h($s['estado']) ?></span>
        </div>
        <div style="font-size:8px;color:<?= $MU ?>;letter-spacing:.5px"><?= h($s['metodo']) ?> · FIRMADO: <?= fdate($s['fecha_firma']) ?> · EXPIRA: <?= fdate($s['fecha_expiracion']) ?></div>
      </div>
    <?php endforeach;
    else: ?><div style="background:#FDF0EE;border:1px solid #EFA09A;border-radius:8px;padding:11px 13px;border-left:3px solid #B83232">
      <div style="font-weight:900;font-size:9px;color:#B83232;letter-spacing:2px;text-transform:uppercase;margin-bottom:7px">⚠ SOA PENDIENTE</div>
    </div><?php endif; ?>
  </div>

  <!-- =================== FAMILIA =================== -->
  <div class="tab-content" id="ptab-FAMILIA">
    <?php if ($pareja): ?>
    <div style="font-size:8px;font-weight:900;color:#5B3FAF;text-transform:uppercase;letter-spacing:2px;margin-bottom:5px">💞 PAREJA / ESPOSO(A)</div>
    <div style="display:flex;align-items:center;gap:8px;padding:9px 0;border-bottom:1px solid <?= $CB ?>">
      <div style="flex:1"><div style="font-weight:900;color:<?= $P2 ?>;font-size:10px;cursor:pointer;letter-spacing:1px" onclick="closeModal('profile-modal');openProfile(<?= $pareja['id'] ?>)"><?= h($pareja['apellido'].', '.$pareja['nombre']) ?></div><div style="font-size:8px;color:<?= $MU ?>;letter-spacing:.5px"><?= h($pareja['telefono']??'') ?></div></div>
      <span style="background:<?= $BG ?>;color:<?= $MU ?>;border:1px solid <?= $CB ?>;border-radius:20px;padding:2px 8px;font-size:8px;font-weight:900"><?= h($pareja['estado']) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($fam): ?>
    <div style="font-size:8px;font-weight:900;color:<?= $P2 ?>;text-transform:uppercase;letter-spacing:2px;margin:<?= $pareja?'12px':'0' ?> 0 5px">👨‍👩‍👧 FAMILIAR</div>
    <div style="display:flex;align-items:center;gap:8px;padding:9px 0;border-bottom:1px solid <?= $CB ?>">
      <div style="flex:1"><div style="font-weight:900;color:<?= $P2 ?>;font-size:10px;cursor:pointer;letter-spacing:1px" onclick="closeModal('profile-modal');openProfile(<?= $fam['id'] ?>)"><?= h($fam['apellido'].', '.$fam['nombre']) ?></div><div style="font-size:8px;color:<?= $MU ?>;letter-spacing:.5px"><?= h($fam['telefono']??'') ?></div></div>
      <span style="background:<?= $BG ?>;color:<?= $MU ?>;border:1px solid <?= $CB ?>;border-radius:20px;padding:2px 8px;font-size:8px;font-weight:900"><?= h($fam['estado']) ?></span>
    </div>
    <?php endif; ?>
    <?php if (!$pareja && !$fam): ?><div style="padding:16px 0;text-align:center;font-size:8px;color:<?= $MU ?>;letter-spacing:2px;text-transform:uppercase">SIN PAREJA NI FAMILIARES VINCULADOS</div><?php endif; ?>
  </div>

  <!-- =================== NOTAS =================== -->
  <div class="tab-content" id="ptab-NOTAS (<?= count($notas) ?>)">

  <!-- Formulario nueva nota -->
  <div style="background:#EBF4F9;border:1px solid #C8DFF0;border-radius:10px;padding:14px;margin-bottom:14px">
    <div style="font-size:9px;font-weight:900;color:#1B4A6B;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:8px">
      + AGREGAR NOTA
    </div>
    <textarea id="nota-text-<?= $id ?>"
              placeholder="Escribe una nota sobre este miembro…"
              style="width:100%;box-sizing:border-box;min-height:72px;padding:9px 11px;
                     border:1px solid #C8DFF0;border-radius:8px;font-size:12px;
                     font-family:'DM Sans',sans-serif;color:#1B3A5C;
                     background:#fff;resize:vertical;outline:none"
              onkeydown="if(event.ctrlKey&&event.key==='Enter')guardarNota(<?= $id ?>)"></textarea>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:7px">
      <span style="font-size:9px;color:#7A90A4">Ctrl+Enter para guardar</span>
      <button onclick="guardarNota(<?= $id ?>)" class="btn btn-p btn-sm">GUARDAR NOTA</button>
    </div>
  </div>

  <!-- Lista de notas -->
  <div id="notas-list-<?= $id ?>">
  <?php if (empty($notas)): ?>
    <div style="text-align:center;padding:24px;color:#7A90A4;font-size:12px">
      Sin notas todavía. Agrega la primera nota arriba.
    </div>
  <?php else: ?>
    <?php foreach ($notas as $n): ?>
    <div id="nota-<?= $n['id'] ?>"
         style="border:1px solid #C8DFF0;border-left:3px solid #2876A8;border-radius:9px;
                padding:11px 13px;margin-bottom:8px;background:#fff;position:relative">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:7px">
        <div style="width:26px;height:26px;border-radius:50%;
                    background:<?= h($n['color'] ?? '#2876A8') ?>;
                    display:flex;align-items:center;justify-content:center;
                    font-size:9px;font-weight:900;color:#fff;flex-shrink:0">
          <?= h($n['iniciales'] ?? '?') ?>
        </div>
        <div>
          <div style="font-size:10px;font-weight:700;color:#1B3A5C"><?= h($n['autor'] ?? 'Sistema') ?></div>
          <div style="font-size:9px;color:#7A90A4"><?= date('m/d/Y g:i a', strtotime($n['created_at'])) ?></div>
        </div>
        <?php if ($admin || ($n['agente_id'] ?? 0) == $uid): ?>
        <button onclick="eliminarNota(<?= $n['id'] ?>,<?= $id ?>)"
                style="position:absolute;top:8px;right:10px;background:none;border:none;
                       color:#7A90A4;cursor:pointer;font-size:13px;padding:2px 5px"
                title="Eliminar nota">✕</button>
        <?php endif; ?>
      </div>
      <div style="font-size:12px;color:#1B3A5C;line-height:1.6;white-space:pre-wrap"><?= h($n['nota']) ?></div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>

  </div>


  <!-- =================== POST-CITA =================== -->
  <div class="tab-content" id="ptab-POST-CITA (<?= count($postcita_list) ?>)">
    <!-- Formulario nuevo seguimiento -->
    <div style="background:#EBF4F9;border:1px solid #C8DFF0;border-radius:10px;padding:14px;margin-bottom:14px">
      <div style="font-size:9px;font-weight:900;color:#1B4A6B;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:10px">+ SEGUIMIENTO POST-CITA</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
        <div>
          <div style="font-size:7px;font-weight:900;color:#2876A8;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">RESULTADO</div>
          <select id="pc-resultado-<?=$id?>" style="width:100%;border:1.5px solid #C8DFF0;border-radius:8px;padding:7px 9px;font-size:11px;font-family:'DM Sans',sans-serif;background:#fff;text-transform:uppercase">
            <option value="">— SELECCIONA —</option>
            <option value="APLICACION">APLICACIÓN</option>
            <option value="SOLO INFORMACION">SOLO INFORMACIÓN</option>
            <option value="REGRESARA">REGRESARÁ</option>
            <option value="NO INTERESADO">NO INTERESADO</option>
            <option value="OTRO">OTRO</option>
          </select>
        </div>
        <div>
          <div style="font-size:7px;font-weight:900;color:#2876A8;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">CARRIER ELEGIDO</div>
          <select id="pc-carrier-<?=$id?>" style="width:100%;border:1.5px solid #C8DFF0;border-radius:8px;padding:7px 9px;font-size:11px;font-family:'DM Sans',sans-serif;background:#fff;text-transform:uppercase">
            <option value="">— SELECCIONA —</option>
            <option>AETNA</option><option>HUMANA</option><option>MOLINA</option><option>SCAN</option>
            <option>UNITED</option><option>KAISER</option><option>BLUE SHIELD</option><option>WELLCARE</option><option>OTRO</option>
          </select>
        </div>
        <div>
          <div style="font-size:7px;font-weight:900;color:#2876A8;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">PLAN ELEGIDO</div>
          <input type="text" id="pc-plan-<?=$id?>" placeholder="NOMBRE DEL PLAN..." style="width:100%;border:1.5px solid #C8DFF0;border-radius:8px;padding:7px 9px;font-size:11px;font-family:'DM Sans',sans-serif;background:#fff;text-transform:uppercase;outline:none">
        </div>
        <div>
          <div style="font-size:7px;font-weight:900;color:#2876A8;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">GRUPO MÉDICO</div>
          <input type="text" id="pc-grupo-<?=$id?>" placeholder="GRUPO MÉDICO..." style="width:100%;border:1.5px solid #C8DFF0;border-radius:8px;padding:7px 9px;font-size:11px;font-family:'DM Sans',sans-serif;background:#fff;text-transform:uppercase;outline:none">
        </div>
        <div>
          <div style="font-size:7px;font-weight:900;color:#2876A8;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">PANEL / PCP</div>
          <input type="text" id="pc-panel-<?=$id?>" placeholder="PANEL ASIGNADO..." style="width:100%;border:1.5px solid #C8DFF0;border-radius:8px;padding:7px 9px;font-size:11px;font-family:'DM Sans',sans-serif;background:#fff;text-transform:uppercase;outline:none">
        </div>
        <div>
          <div style="font-size:7px;font-weight:900;color:#2876A8;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">FECHA EFECTIVA</div>
          <input type="date" id="pc-fecha-<?=$id?>" style="width:100%;border:1.5px solid #C8DFF0;border-radius:8px;padding:7px 9px;font-size:11px;font-family:'DM Sans',sans-serif;background:#fff;outline:none">
        </div>
      </div>
      <div style="margin-bottom:8px">
        <div style="font-size:7px;font-weight:900;color:#2876A8;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">NOTAS</div>
        <textarea id="pc-notas-<?=$id?>" placeholder="NOTAS DE LA CITA..." style="width:100%;border:1.5px solid #C8DFF0;border-radius:8px;padding:8px 10px;font-size:11px;font-family:'DM Sans',sans-serif;background:#fff;min-height:60px;resize:vertical;outline:none;text-transform:uppercase"></textarea>
      </div>
      <div style="display:flex;justify-content:flex-end">
        <button onclick="guardarPostCita(<?=$id?>)" class="btn btn-p btn-sm">✓ GUARDAR SEGUIMIENTO</button>
      </div>
    </div>
    <!-- Historial de seguimientos -->
    <div id="postcita-list-<?=$id?>">
    <?php if (empty($postcita_list)): ?>
      <div style="text-align:center;padding:20px;color:#7A90A4;font-size:9px;text-transform:uppercase;letter-spacing:1px">SIN SEGUIMIENTOS REGISTRADOS AÚN</div>
    <?php else: ?>
      <?php foreach ($postcita_list as $pc): ?>
      <div style="background:#fff;border:1px solid #C8DFF0;border-left:3px solid #1B4A6B;border-radius:9px;padding:11px 13px;margin-bottom:8px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
          <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            <?php if ($pc['resultado']): ?><span style="background:#EBF5FB;color:#1B5E8C;border:1px solid #A9D0E8;border-radius:20px;padding:2px 9px;font-size:8px;font-weight:900"><?=h($pc['resultado'])?></span><?php endif; ?>
            <?php if ($pc['carrier_elegido']): ?><span style="background:#EAF5F0;color:#1E7A5C;border:1px solid #8DCFBA;border-radius:20px;padding:2px 9px;font-size:8px;font-weight:900"><?=h($pc['carrier_elegido'])?></span><?php endif; ?>
          </div>
          <div style="font-size:7px;color:#7A90A4"><?=date('m/d/Y', strtotime($pc['completada_at']))?> · <?=h($pc['por_nombre']??'—')?></div>
        </div>
        <?php if ($pc['plan_elegido'] || $pc['grupo_medico'] || $pc['panel']): ?>
        <div style="display:flex;gap:10px;font-size:8px;color:#1B3A5C;margin-bottom:4px;flex-wrap:wrap">
          <?php if ($pc['plan_elegido']): ?><span><b style="color:#7A90A4">PLAN:</b> <?=h($pc['plan_elegido'])?></span><?php endif; ?>
          <?php if ($pc['grupo_medico']): ?><span><b style="color:#7A90A4">GRUPO:</b> <?=h($pc['grupo_medico'])?></span><?php endif; ?>
          <?php if ($pc['panel']): ?><span><b style="color:#7A90A4">PANEL:</b> <?=h($pc['panel'])?></span><?php endif; ?>
          <?php if ($pc['fecha_efectiva']): ?><span><b style="color:#7A90A4">EFECTIVA:</b> <?=date('m/d/Y',strtotime($pc['fecha_efectiva']))?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($pc['notas']): ?><div style="font-size:9px;color:#1B3A5C;font-style:italic">"<?=h($pc['notas'])?>"</div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
    </div>
  </div>

  <!-- Footer -->
  <div style="display:flex;justify-content:space-between;margin-top:13px;padding-top:11px;border-top:1px solid <?= $CB ?>">
    <div style="display:flex;gap:5px">

<?php
// Historial de planes de este miembro
$hist_planes = [];
try {
    $hq = $pdo->prepare("SELECT * FROM historial_planes WHERE miembro_id=? ORDER BY fecha_inicio DESC");
    $hq->execute([$id]); $hist_planes = $hq->fetchAll();
} catch (Exception $e) {}
if (!empty($hist_planes)):
?>
<div style="background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:10px;padding:12px 15px;margin-bottom:10px">
  <div style="font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:2px;margin-bottom:10px">🗂 HISTORIAL DE PLANES</div>
  <?php foreach($hist_planes as $hp): ?>
  <div style="display:flex;gap:10px;align-items:center;padding:7px 0;border-bottom:1px solid <?=$CB?>">
    <div style="flex:1">
      <div style="font-size:10px;font-weight:900;color:<?=$TX?>"><?=h($hp['carrier']?$hp['carrier'].' — ':'')?><?=h($hp['plan']??'—')?></div>
      <div style="font-size:8px;color:<?=$MU?>;margin-top:2px">
        <?=date('M Y',strtotime($hp['fecha_inicio']))?>
        <?=$hp['fecha_fin']?' → '.date('M Y',strtotime($hp['fecha_fin'])).' <span style="color:#B83232">('.h($hp['motivo_fin']).')</span>':'<span style="color:#1E7A5C;font-weight:900"> → ACTIVO</span>'?>
      </div>
    </div>
    <?php if($hp['subestado']): ?>
    <span style="background:<?=$hp['subestado']==='NEW ENROLLMENT'?'#EAF5F0':'#EBF4F9'?>;color:<?=$hp['subestado']==='NEW ENROLLMENT'?'#1E7A5C':'#1B5E8C'?>;border-radius:20px;padding:2px 8px;font-size:7px;font-weight:900"><?=h($hp['subestado'])?></span>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

    </div>
  </div>
</div>

<script>
function showPTab(id) {
  document.querySelectorAll('.tab-content').forEach(e=>{ e.style.display='none'; e.classList.remove('active'); });
  document.querySelectorAll('.p-tab').forEach(b=>{ b.classList.remove('active'); });
  const el = document.getElementById('ptab-'+id);
  if(el) { el.style.display='block'; el.classList.add('active'); }
  document.querySelectorAll('.p-tab[data-ptab="'+id+'"]').forEach(b=>b.classList.add('active'));
}

function updateLlamada(mid,campo,valor) {
  fetch('api.php',{method:'POST',body:new URLSearchParams({action:'update_llam',miembro_id:mid,campo,valor})})
    .then(r=>r.json()).then(d=>{if(d.ok)toast('✓ '+campo.replace('llam_','').toUpperCase()+': '+valor);});
}

async function guardarNota(miembroId) {
  const ta = document.getElementById('nota-text-' + miembroId);
  const texto = ta.value.trim();
  if (!texto) { ta.focus(); return; }

  const fd = new FormData();
  fd.append('action', 'save_nota');
  fd.append('miembro_id', miembroId);
  fd.append('nota', texto);

  const r = await fetch('api.php', { method: 'POST', body: fd });
  const d = await r.json();
  if (d.ok) {
    ta.value = '';
    const lista = document.getElementById('notas-list-' + miembroId);
    const placeholder = lista.querySelector('div[style*="Sin notas"]');
    if (placeholder) placeholder.remove();

    const div = document.createElement('div');
    div.id = 'nota-' + d.data.id;
    div.style.cssText = 'border:1px solid #C8DFF0;border-left:3px solid #2876A8;border-radius:9px;padding:11px 13px;margin-bottom:8px;background:#fff;position:relative';
    div.innerHTML = `
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:7px">
        <div style="width:26px;height:26px;border-radius:50%;background:${d.data.color};display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:900;color:#fff;flex-shrink:0">${d.data.iniciales}</div>
        <div>
          <div style="font-size:10px;font-weight:700;color:#1B3A5C">${d.data.autor}</div>
          <div style="font-size:9px;color:#7A90A4">${d.data.fecha}</div>
        </div>
        <button onclick="eliminarNota(${d.data.id},${miembroId})" style="position:absolute;top:8px;right:10px;background:none;border:none;color:#7A90A4;cursor:pointer;font-size:13px;padding:2px 5px" title="Eliminar nota">✕</button>
      </div>
      <div style="font-size:12px;color:#1B3A5C;line-height:1.6;white-space:pre-wrap">${d.data.texto.replace(/</g,'&lt;')}</div>`;
    lista.insertBefore(div, lista.firstChild);

    if (typeof toast === 'function') toast('✓ NOTA GUARDADA');
  } else {
    alert('Error al guardar: ' + (d.error || 'desconocido'));
  }
}

async function eliminarNota(notaId, miembroId) {
  if (!confirm('¿Eliminar esta nota?')) return;
  const fd = new FormData();
  fd.append('action', 'delete_nota');
  fd.append('id', notaId);
  const r = await fetch('api.php', { method: 'POST', body: fd });
  const d = await r.json();
  if (d.ok) {
    const el = document.getElementById('nota-' + notaId);
    if (el) el.remove();
    if (typeof toast === 'function') toast('Nota eliminada');
  }
}

function toggleAllegation(mid, val) {
  if (!confirm(val ? '¿Marcar SALES ALLEGATION para este miembro?' : '¿Quitar la Sales Allegation?')) return;
  fetch('api.php', {method:'POST', body: new URLSearchParams({action:'toggle_sales_allegation', miembro_id:mid, valor:val})})
    .then(r=>r.json()).then(d=>{ if(d.ok) location.reload(); else alert(d.error||'Error'); });
}

async function subirFoto(mid, input) {
  if (!input.files[0]) return;
  const fd = new FormData();
  fd.append('action', 'upload_foto');
  fd.append('miembro_id', mid);
  fd.append('foto', input.files[0]);
  const r = await fetch('api.php', {method:'POST', body:fd});
  const d = await r.json();
  if (d.ok) { if(typeof toast==='function') toast('✓ FOTO ACTUALIZADA'); location.reload(); }
  else alert(d.error||'Error al subir foto');
}

async function guardarPostCita(mid) {
  const get = key => document.getElementById(key+'-'+mid)?.value||'';
  const fd = new FormData();
  fd.append('action', 'save_postcita_q');
  fd.append('miembro_id', mid);
  fd.append('resultado', get('pc-resultado'));
  fd.append('carrier_elegido', get('pc-carrier'));
  fd.append('plan_elegido', get('pc-plan'));
  fd.append('grupo_medico', get('pc-grupo'));
  fd.append('panel', get('pc-panel'));
  fd.append('fecha_efectiva', get('pc-fecha'));
  fd.append('notas', get('pc-notas'));
  const r = await fetch('api.php', {method:'POST', body:fd});
  const d = await r.json();
  if (d.ok) { if(typeof toast==='function') toast('✓ SEGUIMIENTO GUARDADO'); location.reload(); }
  else alert(d.error||'Error al guardar');
}
</script>

<!-- ══ MODAL: CAMBIO DE PLAN ══════════════════════════════════════════════ -->
<div id="cambio-plan-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:2000;display:none;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:24px;width:420px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
      <div style="font-size:13px;font-weight:900;color:#1B4A6B;letter-spacing:1px">🔄 INICIAR CAMBIO DE PLAN</div>
      <button onclick="closeCambioPlanModal()" style="background:none;border:none;font-size:18px;cursor:pointer;color:#7A90A4">✕</button>
    </div>
    <div style="background:#EBF4F9;border:1px solid #C8DFF0;border-radius:9px;padding:10px 13px;margin-bottom:16px;font-size:9px;color:#1B4A6B">
      <b>Plan actual:</b> <?= h(($m['carrier']?$m['carrier'].' — ':'').$m['plan']) ?><br>
      <b>Fecha efectiva:</b> <?= $m['fecha_efectiva'] ? date('M Y', strtotime($m['fecha_efectiva'])) : '—' ?>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
      <div>
        <label style="font-size:8px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:1px;display:block;margin-bottom:4px">NUEVO CARRIER</label>
        <select id="cp-carrier" style="width:100%;padding:8px 10px;border:1.5px solid #C8DFF0;border-radius:8px;font-size:10px;font-family:'DM Sans',sans-serif">
          <option value="">— MISMO —</option>
          <?php foreach(['SCAN','ANTHEM','HUMANA','ALIGNMENT','LA CARE','HEALTH NET','MOLINA','UNITED HEALTHCARE','BLUE SHIELD','KAISER','OTRO'] as $c): ?>
          <option value="<?=$c?>"<?=$m['carrier']===$c?' selected':''?>><?=$c?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-size:8px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:1px;display:block;margin-bottom:4px">NUEVO PLAN</label>
        <input id="cp-plan" type="text" value="<?=h($m['plan']??'')?>" style="width:100%;padding:8px 10px;border:1.5px solid #C8DFF0;border-radius:8px;font-size:10px;font-family:'DM Sans',sans-serif;box-sizing:border-box">
      </div>
    </div>
    <div style="margin-bottom:16px">
      <label style="font-size:8px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:1px;display:block;margin-bottom:4px">NUEVA FECHA EFECTIVA</label>
      <input id="cp-fecha" type="date" style="width:100%;padding:8px 10px;border:1.5px solid #C8DFF0;border-radius:8px;font-size:10px;font-family:'DM Sans',sans-serif;box-sizing:border-box">
    </div>
    <div style="background:#FEF8EE;border:1px solid #F5D5A0;border-radius:8px;padding:9px 12px;margin-bottom:16px;font-size:8px;color:#C07A1A">
      ⚠ El miembro pasará a <b>READY TO ENROLL</b> y deberá completar el proceso hasta ser <b>ACTIVE</b> de nuevo.
    </div>
    <div style="display:flex;gap:8px">
      <button onclick="closeCambioPlanModal()" style="flex:1;padding:10px;border:1.5px solid #C8DFF0;border-radius:9px;background:#fff;color:#7A90A4;font-size:10px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif">CANCELAR</button>
      <button id="cp-btn" onclick="confirmarCambioPlan()" style="flex:2;padding:10px;border:none;border-radius:9px;background:#1B4A6B;color:#fff;font-size:10px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif">🔄 CONFIRMAR CAMBIO</button>
    </div>
  </div>
</div>

<script>
function openCambioPlanModal() {
    document.getElementById('cambio-plan-modal').style.display='flex';
}
function closeCambioPlanModal() {
    document.getElementById('cambio-plan-modal').style.display='none';
}
function confirmarCambioPlan() {
    const btn = document.getElementById('cp-btn');
    btn.disabled=true; btn.textContent='GUARDANDO...';
    const fd = new FormData();
    fd.append('action','iniciar_cambio_plan');
    fd.append('miembro_id','<?= $id ?>');
    fd.append('nuevo_carrier', document.getElementById('cp-carrier').value);
    fd.append('nuevo_plan',    document.getElementById('cp-plan').value);
    fd.append('nueva_fecha',   document.getElementById('cp-fecha').value);
    fetch('api.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(d=>{
            if(d.ok){
                closeCambioPlanModal();
                if(typeof toast==='function') toast('✓ CAMBIO DE PLAN INICIADO — Estado: READY TO ENROLL');
                setTimeout(()=>location.reload(),800);
            } else {
                alert('Error: '+(d.error||'No se pudo procesar'));
                btn.disabled=false; btn.textContent='🔄 CONFIRMAR CAMBIO';
            }
        });
}
</script>