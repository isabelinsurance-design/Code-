<?php
/**
 * reporte_nomina.php
 * Reporte de nómina por quincena — Medicare with Isabel CRM
 * Admin-only · calcula horas trabajadas vs esperadas y pago proporcional
 */
require_once 'session_boot.php';
require_once 'config.php';
require_once 'nomina_calc.php';
$user = auth();
$admin = isAdmin();
if (!$admin) { http_response_code(403); die('Acceso denegado'); }
$pdo = db();

// ── TABLA DE AJUSTES MANUALES (auto-crear, estilo del resto del CRM) ───────
// Permite corregir horas que no quedaron registradas por check-in/check-out
// (olvidos, fallas del reloj, etc.) dejando constancia de quién y por qué.
try { $pdo->exec("CREATE TABLE IF NOT EXISTS nomina_ajustes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agente_id INT NOT NULL,
    anio INT NOT NULL,
    mes TINYINT NOT NULL,
    quincena TINYINT NOT NULL,
    horas DECIMAL(6,2) NOT NULL,
    motivo VARCHAR(255) NOT NULL,
    creado_por INT,
    creado_por_nombre VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_periodo (agente_id, anio, mes, quincena)
)"); } catch (Exception $e) {}

// ── PARÁMETROS ─────────────────────────────────────────────────────────────
$year  = (int)($_GET['y'] ?? date('Y'));
$month = (int)($_GET['m'] ?? date('n'));
// q=1 → días 1-15  |  q=2 → días 16-fin
$q     = (int)($_GET['q'] ?? (date('j') <= 15 ? 1 : 2));

// ── ESCRITURA: agregar / borrar ajuste manual (PRG: Post → Redirect → Get) ─
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $p_year  = (int)($_POST['y'] ?? $year);
    $p_month = (int)($_POST['m'] ?? $month);
    $p_q     = (int)($_POST['q'] ?? $q);
    $action  = $_POST['action'] ?? '';

    if ($action === 'add_ajuste') {
        $agente_id = (int)($_POST['agente_id'] ?? 0);
        $horas     = (float)str_replace(',', '.', $_POST['horas'] ?? '0');
        $motivo    = trim($_POST['motivo'] ?? '');
        if ($agente_id > 0 && $horas != 0 && $motivo !== '') {
            $st = $pdo->prepare(
                "INSERT INTO nomina_ajustes
                    (agente_id, anio, mes, quincena, horas, motivo, creado_por, creado_por_nombre)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            $st->execute([
                $agente_id, $p_year, $p_month, $p_q,
                round($horas, 2), mb_substr($motivo, 0, 255),
                (int)$user['id'], $user['nombre'] ?? '',
            ]);
        }
    } elseif ($action === 'del_ajuste') {
        $ajuste_id = (int)($_POST['ajuste_id'] ?? 0);
        if ($ajuste_id > 0) {
            $pdo->prepare("DELETE FROM nomina_ajustes WHERE id=?")->execute([$ajuste_id]);
        }
    }

    header('Location: reporte_nomina.php?y=' . $p_year . '&m=' . $p_month . '&q=' . $p_q);
    exit;
}

// Límites de la quincena
[$fecha_inicio, $fecha_fin] = quincena_rango($year, $month, $q);

// ── AGENTES CON HORARIO DEFINIDO ───────────────────────────────────────────
$agents = $pdo->query(
    "SELECT * FROM usuarios
     WHERE activo=1 AND rol='agent'
       AND salario_quincenal IS NOT NULL
     ORDER BY nombre"
)->fetchAll();

// ── CONSTRUIR DATOS POR AGENTE ─────────────────────────────────────────────
$nomina = [];
foreach ($agents as $ag) {
    $nomina[] = calcular_nomina_agente($pdo, $ag, $year, $month, $q, $fecha_inicio, $fecha_fin);
}

// Meses en español
$meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
          'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$mes_label = $meses[$month] . ' ' . $year;
$q_label   = $q === 1 ? "1ª QUINCENA (1-15)" : "2ª QUINCENA (16-fin)";
$total_pago = array_sum(array_column($nomina, 'pago_calculado'));

// Paleta
$P1='#1B4A6B';$P2='#2876A8';$BG='#EBF4F9';$CB='#C8DFF0';$G='#1E7A5C';$R='#B83232';$A='#C07A1A';$MU='#7A90A4';
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Nómina <?=$q_label?> — <?=$mes_label?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:<?=$BG?>;font-family:'DM Sans',sans-serif;font-size:13px;color:<?=$P1?>;padding:20px}
.page-header{background:<?=$P1?>;color:#fff;border-radius:14px;padding:18px 22px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.page-header h1{font-size:14px;font-weight:900;letter-spacing:3px;text-transform:uppercase}
.page-header .sub{font-size:9px;color:rgba(255,255,255,.6);letter-spacing:2px;text-transform:uppercase;margin-top:3px}
.controls{background:#fff;border:1px solid <?=$CB?>;border-radius:12px;padding:13px 16px;margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.controls label{font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px;display:block;margin-bottom:3px}
.controls select,.controls input{border:1.5px solid <?=$CB?>;border-radius:8px;padding:7px 11px;font-size:11px;font-family:'DM Sans',sans-serif;background:<?=$BG?>;color:<?=$P1?>;font-weight:700}
.btn{border:none;border-radius:9px;padding:8px 18px;font-size:10px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif;letter-spacing:1px;text-transform:uppercase}
.btn-p{background:<?=$P1?>;color:#fff}
.btn-gr{background:#EAF5F0;color:<?=$G?>;border:1px solid #8DCFBA}
.totales{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.tot-card{background:#fff;border:1px solid <?=$CB?>;border-radius:12px;padding:13px 16px;flex:1;min-width:120px;border-top:3px solid currentColor}
.tot-icon{font-size:8px;font-weight:900;color:<?=$MU?>;letter-spacing:2px;text-transform:uppercase;margin-bottom:4px}
.tot-val{font-size:22px;font-weight:900;line-height:1}
.agent-card{background:#fff;border:1px solid <?=$CB?>;border-radius:14px;overflow:hidden;margin-bottom:14px;border-top:4px solid var(--color)}
.agent-header{padding:13px 16px;border-bottom:1px solid <?=$CB?>;background:<?=$BG?>;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.av{border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;flex-shrink:0;font-family:'DM Sans',sans-serif}
.agent-body{padding:14px 16px}
.kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:9px;margin-bottom:13px}
.kpi{background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:9px;padding:9px 11px;text-align:center}
.kpi-lbl{font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px}
.kpi-val{font-size:18px;font-weight:900}
.progress-bar{height:10px;background:<?=$CB?>;border-radius:10px;overflow:hidden;margin:4px 0 2px}
.progress-fill{height:100%;border-radius:10px;transition:width .4s}
.detail-table{width:100%;border-collapse:collapse;font-size:9px;margin-top:10px}
.detail-table th{padding:5px 10px;text-align:left;font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;background:<?=$BG?>;border-bottom:1px solid <?=$CB?>}
.detail-table td{padding:7px 10px;border-bottom:1px solid <?=$CB?>40;color:<?=$P1?>}
.detail-table tr:hover td{background:<?=$BG?>}
.pago-final{background:<?=$P1?>;color:#fff;border-radius:10px;padding:13px 16px;display:flex;justify-content:space-between;align-items:center;margin-top:12px}
.pago-label{font-size:9px;font-weight:900;letter-spacing:2px;text-transform:uppercase;opacity:.7}
.pago-monto{font-size:22px;font-weight:900}
.dow-names{1:Dom,2:Lun,3:Mar,4:Mié,5:Jue,6:Vie,7:Sáb}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:8px;font-weight:900;text-transform:uppercase;letter-spacing:.5px}
@media print{
  .controls,.btn-print{display:none}
  .agent-card{break-inside:avoid}
}
</style>
</head>
<body>

<!-- ENCABEZADO -->
<div class="page-header">
  <div>
    <div class="page-header h1">◐ NÓMINA — <?=$q_label?></div>
    <div class="sub"><?=strtoupper($mes_label)?> · MEDICARE WITH ISABEL</div>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <button class="btn btn-gr btn-print" onclick="window.print()"> IMPRIMIR</button>
    <a href="index.php" class="btn" style="background:rgba(255,255,255,.12);color:#fff;text-decoration:none">← CRM</a>
  </div>
</div>

<!-- FILTROS -->
<form method="GET" class="controls">
  <div>
    <label>MES</label>
    <select name="m">
      <?php foreach($meses as $i=>$mn): if(!$i) continue; ?>
      <option value="<?=$i?>" <?=$i==$month?'selected':''?>><?=$mn?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>AÑO</label>
    <input type="number" name="y" value="<?=$year?>" min="2024" max="2030" style="width:90px">
  </div>
  <div>
    <label>QUINCENA</label>
    <select name="q">
      <option value="1" <?=$q==1?'selected':''?>>1ª QUINCENA (1 – 15)</option>
      <option value="2" <?=$q==2?'selected':''?>>2ª QUINCENA (16 – fin de mes)</option>
    </select>
  </div>
  <button type="submit" class="btn btn-p">VER NÓMINA</button>
</form>

<!-- PERÍODO -->
<div style="background:#fff;border:1px solid <?=$CB?>;border-radius:10px;padding:10px 16px;margin-bottom:14px;font-size:9px;font-weight:800;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px">
  PERÍODO: <?=date('d/m/Y',strtotime($fecha_inicio))?> — <?=date('d/m/Y',strtotime($fecha_fin))?>
  &nbsp;·&nbsp; <?=count($agents)?> EMPLEADAS
</div>

<!-- TOTALES GLOBALES -->
<div class="totales">
  <div class="tot-card" style="color:<?=$P1?>">
    <div class="tot-icon">◉ EMPLEADAS</div>
    <div class="tot-val" style="color:<?=$P1?>"><?=count($nomina)?></div>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:2px">EN NÓMINA</div>
  </div>
  <div class="tot-card" style="color:<?=$G?>">
    <div class="tot-icon"> TOTAL A PAGAR</div>
    <div class="tot-val" style="color:<?=$G?>">$<?=number_format($total_pago,2)?></div>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:2px">ESTA QUINCENA</div>
  </div>
  <div class="tot-card" style="color:<?=$A?>">
    <div class="tot-icon">◐ HRS TOTALES</div>
    <div class="tot-val" style="color:<?=$A?>"><?=array_sum(array_column($nomina,'horas_trabajadas_total'))?>h</div>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:2px">TRABAJADAS</div>
  </div>
</div>

<!-- TARJETAS POR AGENTE -->
<?php foreach($nomina as $n):
  $ag   = $n['ag'];
  $color = htmlspecialchars($ag['color']);
  $pc    = $n['porcentaje'];
  $bar_color = $pc >= 95 ? '#1E7A5C' : ($pc >= 75 ? '#C07A1A' : '#B83232');
  $dias_semana = ['','Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
?>
<div class="agent-card" style="--color:<?=$color?>">
  <div class="agent-header">
    <!-- Avatar -->
    <div class="av" style="width:42px;height:42px;font-size:14px;background:<?=$color?>"><?=htmlspecialchars($ag['iniciales']??'?')?></div>
    <div style="flex:1">
      <div style="font-weight:900;font-size:13px;color:<?=$P1?>"><?=htmlspecialchars($ag['nombre'])?></div>
      <div style="font-size:8px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-top:2px">
        <?=$ag['horario_entrada']?substr($ag['horario_entrada'],0,5):'—'?>
        –
        <?=$ag['horario_salida']?substr($ag['horario_salida'],0,5):'—'?>
        &nbsp;·&nbsp;
        <?=$ag['horas_semana']?>h/día (Lun-Vie)
        <?php if($ag['horas_sabado'] > 0): ?>
          · <?=$ag['horas_sabado']?>h Sáb
        <?php endif; ?>
      </div>
    </div>
    <!-- Salario base -->
    <div style="text-align:right">
      <div style="font-size:8px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px">Salario base</div>
      <div style="font-size:16px;font-weight:900;color:<?=$P1?>">$<?=number_format($n['salario_base'],0)?></div>
    </div>
  </div>

  <div class="agent-body">
    <!-- KPIs -->
    <div class="kpis">
      <div class="kpi">
        <div class="kpi-lbl"> DÍAS ESPERADOS</div>
        <div class="kpi-val" style="color:<?=$P1?>"><?=$n['dias_esperados']?></div>
      </div>
      <div class="kpi">
        <div class="kpi-lbl">✓ DÍAS PRESENTES</div>
        <div class="kpi-val" style="color:<?=$G?>"><?=$n['dias_presentes']?></div>
      </div>
      <div class="kpi">
        <div class="kpi-lbl">✗ DÍAS AUSENTES</div>
        <div class="kpi-val" style="color:<?=$n['dias_ausentes']>0?$R:$MU?>"><?=$n['dias_ausentes']?></div>
      </div>
      <div class="kpi">
        <div class="kpi-lbl">◐ HRS ESPERADAS</div>
        <div class="kpi-val" style="color:<?=$P2?>"><?=$n['horas_esperadas']?>h</div>
      </div>
      <div class="kpi">
        <div class="kpi-lbl">◐ HRS TRABAJADAS</div>
        <div class="kpi-val" style="color:<?=$bar_color?>"><?=$n['horas_trabajadas_total']?>h</div>
        <?php if($n['horas_ajuste'] != 0): ?>
        <div style="font-size:7px;font-weight:800;color:<?=$n['horas_ajuste']>0?$G:$R?>;margin-top:2px"><?=$n['horas_ajuste']>0?'+':''?><?=$n['horas_ajuste']?>h ajuste</div>
        <?php endif; ?>
      </div>
      <div class="kpi">
        <div class="kpi-lbl">⚡ HRS EXTRA</div>
        <div class="kpi-val" style="color:<?=$n['horas_extra']>0?'#C07A1A':$MU?>"><?=$n['horas_extra'] > 0 ? '+'.$n['horas_extra'].'h' : '0h'?></div>
      </div>
      <div class="kpi">
        <div class="kpi-lbl"> CUMPLIMIENTO</div>
        <div class="kpi-val" style="color:<?=$bar_color?>"><?=$pc?>%</div>
      </div>
    </div>

    <!-- Barra de progreso -->
    <div style="font-size:8px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">CUMPLIMIENTO DE HORAS</div>
    <div class="progress-bar" style="position:relative">
      <div class="progress-fill" style="width:<?=min(100,$pc)?>%;background:<?=$bar_color?>"></div>
      <?php if($n['horas_extra'] > 0): ?>
      <div style="position:absolute;top:0;right:0;height:100%;width:4px;background:#C07A1A;border-radius:0 4px 4px 0" title="Horas extra"></div>
      <?php endif; ?>
    </div>
    <div style="font-size:8px;color:<?=$MU?>;margin-bottom:12px">
      <?=$n['horas_trabajadas']?>h de check-in/out
      <?php if($n['horas_ajuste'] != 0): ?>
        <?=$n['horas_ajuste']>0?'+':''?><?=$n['horas_ajuste']?>h de ajuste manual =
      <?php endif; ?>
      <?=$n['horas_trabajadas_total']?>h de <?=$n['horas_esperadas']?>h esperadas
    </div>

    <!-- Ajustes manuales de horas -->
    <div style="background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:9px;padding:10px 12px;margin-bottom:13px">
      <div style="font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px"> AJUSTES MANUALES DE HORAS</div>
      <?php if(count($n['ajustes']) > 0): ?>
      <table style="width:100%;border-collapse:collapse;font-size:9px;margin-bottom:9px">
        <?php foreach($n['ajustes'] as $aj): ?>
        <tr>
          <td style="padding:4px 6px 4px 0;font-weight:900;color:<?=(float)$aj['horas']>=0?$G:$R?>;white-space:nowrap"><?=(float)$aj['horas']>=0?'+':''?><?=rtrim(rtrim(number_format((float)$aj['horas'],2),'0'),'.')?>h</td>
          <td style="padding:4px 6px;color:<?=$P1?>"><?=htmlspecialchars($aj['motivo'])?></td>
          <td style="padding:4px 6px;color:<?=$MU?>;white-space:nowrap;text-align:right">
            <?=htmlspecialchars($aj['creado_por_nombre']?:'—')?> · <?=date('d/m/Y',strtotime($aj['created_at']))?>
          </td>
          <td style="padding:4px 0 4px 6px;text-align:right">
            <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar este ajuste de horas?')">
              <input type="hidden" name="action" value="del_ajuste">
              <input type="hidden" name="ajuste_id" value="<?=(int)$aj['id']?>">
              <input type="hidden" name="y" value="<?=$year?>">
              <input type="hidden" name="m" value="<?=$month?>">
              <input type="hidden" name="q" value="<?=$q?>">
              <button type="submit" style="border:none;background:none;color:<?=$R?>;font-size:9px;font-weight:900;cursor:pointer">✕</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
      <form method="POST" style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="action" value="add_ajuste">
        <input type="hidden" name="agente_id" value="<?=(int)$ag['id']?>">
        <input type="hidden" name="y" value="<?=$year?>">
        <input type="hidden" name="m" value="<?=$month?>">
        <input type="hidden" name="q" value="<?=$q?>">
        <div>
          <label style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;display:block;margin-bottom:2px">HORAS</label>
          <input type="number" name="horas" step="0.25" required placeholder="ej. 3 o -1.5" style="border:1.5px solid <?=$CB?>;border-radius:7px;padding:6px 8px;font-size:10px;font-family:'DM Sans',sans-serif;background:#fff;color:<?=$P1?>;font-weight:700;width:100px">
        </div>
        <div style="flex:1;min-width:160px">
          <label style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;display:block;margin-bottom:2px">MOTIVO</label>
          <input type="text" name="motivo" required maxlength="255" placeholder="ej. Se olvidó marcar salida el 5/8" style="border:1.5px solid <?=$CB?>;border-radius:7px;padding:6px 8px;font-size:10px;font-family:'DM Sans',sans-serif;background:#fff;color:<?=$P1?>;font-weight:700;width:100%">
        </div>
        <button type="submit" class="btn btn-p" style="padding:7px 14px">+ AGREGAR AJUSTE</button>
      </form>
    </div>

    <!-- Detalle diario (colapsable) -->
    <?php if(count($n['detalle']) > 0): ?>
    <details>
      <summary style="cursor:pointer;font-size:9px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;padding:6px 0;border-bottom:1px solid <?=$CB?>">
        VER DETALLE DIARIO (<?=count($n['detalle'])?> registros)
      </summary>
      <table class="detail-table" style="margin-top:8px">
        <tr>
          <th>FECHA</th><th>DÍA</th><th>CHECK-IN</th><th>CHECK-OUT</th><th>HORAS</th><th>ESTADO</th>
        </tr>
        <?php foreach($n['detalle'] as $d): 
          $dow_n = $dias_semana[(int)$d['dow']] ?? '—';
          $h     = $d['horas'];
          $ok    = $d['check_in'] && $d['check_out'] && $h > 0;
          $ci_str = $d['check_in'] ? substr($d['check_in'],0,5) : '—';
          $co_str = $d['check_out'] ? substr($d['check_out'],0,5) : '—';
        ?>
        <tr>
          <td style="font-weight:800;color:<?=$P1?>"><?=date('d/m/Y',strtotime($d['fecha']))?></td>
          <td><?=$dow_n?></td>
          <td style="color:<?=$G?>;font-weight:800"><?=$ci_str?></td>
          <td style="color:<?=$R?>;font-weight:800"><?=$co_str?></td>
          <td style="font-weight:900;color:<?=$h>0?$P1:$MU?>"><?=$h > 0 ? $h.'h' : '—'?></td>
          <td>
            <?php if($ok && $h >= (float)$ag['horas_semana'] - 0.25): ?>
              <span class="badge" style="background:#EAF5F0;color:<?=$G?>;border:1px solid #8DCFBA">COMPLETO</span>
            <?php elseif($ok): ?>
              <span class="badge" style="background:#FEF8EE;color:<?=$A?>;border:1px solid #F5D5A0">PARCIAL</span>
            <?php elseif($d['check_in'] && !$d['check_out']): ?>
              <span class="badge" style="background:#EBF5FB;color:<?=$P2?>;border:1px solid #A9D0E8">ACTIVO</span>
            <?php else: ?>
              <span class="badge" style="background:#FDF0EE;color:<?=$R?>;border:1px solid #EFA09A">AUSENTE</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </details>
    <?php else: ?>
    <div style="text-align:center;padding:12px;font-size:8px;color:<?=$MU?>;text-transform:uppercase;background:<?=$BG?>;border-radius:8px;border:1px solid <?=$CB?>">
      SIN REGISTROS DE ASISTENCIA EN ESTE PERÍODO
    </div>
    <?php endif; ?>

    <!-- PAGO CALCULADO -->
    <div class="pago-final">
      <div style="flex:1">
        <div class="pago-label">PAGO ESTA QUINCENA</div>
        <div style="font-size:8px;color:rgba(255,255,255,.5);margin-top:3px;line-height:1.6">
          Base: $<?=number_format($n['pago_base'],2)?> (<?=$pc?>% de $<?=number_format($n['salario_base'],0)?>)
          <?php if($n['horas_ajuste'] != 0): ?>
            <br> Ajuste manual: <?=$n['horas_ajuste']>0?'+':''?><?=$n['horas_ajuste']?>h incluidas en el cálculo
          <?php endif; ?>
          <?php if($n['horas_extra'] > 0): ?>
            <br>⚡ Tiempo extra: <?=$n['horas_extra']?>h × $<?=number_format($n['valor_hora'],2)?>/h × 1 = <b style="color:#FFE066">+$<?=number_format($n['pago_extra'],2)?></b>
          <?php endif; ?>
          <?php if($n['dias_ausentes'] > 0): ?>
            <br>✗ <?=$n['dias_ausentes']?> DÍA<?=$n['dias_ausentes']>1?'S':''?> AUSENTE<?=$n['dias_ausentes']>1?'S':''?>
          <?php endif; ?>
        </div>
      </div>
      <div>
        <?php if($n['horas_extra'] > 0): ?>
        <div style="font-size:8px;color:#FFE066;font-weight:900;text-align:right;margin-bottom:3px;letter-spacing:1px;text-transform:uppercase">⚡ INCLUYE HORAS EXTRA</div>
        <?php endif; ?>
        <div class="pago-monto">$<?=number_format($n['pago_calculado'],2)?></div>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- RESUMEN FINAL -->
<div style="background:<?=$P1?>;color:#fff;border-radius:14px;padding:18px 22px;margin-top:4px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
  <div>
    <div style="font-size:9px;font-weight:900;letter-spacing:3px;text-transform:uppercase;opacity:.6">TOTAL NÓMINA</div>
    <div style="font-size:11px;font-weight:900;letter-spacing:2px;text-transform:uppercase;margin-top:3px"><?=$q_label?> · <?=strtoupper($mes_label)?></div>
  </div>
  <div style="text-align:right">
    <div style="font-size:32px;font-weight:900">$<?=number_format($total_pago,2)?></div>
    <div style="font-size:8px;opacity:.5;margin-top:2px"><?=count($nomina)?> EMPLEADAS</div>
  </div>
</div>

</body>
</html>
