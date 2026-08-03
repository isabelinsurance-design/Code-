<?php
/**
 * reporte_pagos.php
 * Recibo de pago por empleado — Medicare with Isabel CRM
 * Cada empleado arma su propio recibo (horas de la quincena + gastos
 * pendientes de reembolso + bonos pendientes) y lo manda a aprobación.
 * El admin aprueba/rechaza y, al marcarlo pagado, los gastos y bonos
 * incluidos quedan automáticamente como pagados.
 */
require_once 'session_boot.php';
require_once 'config.php';
require_once 'nomina_calc.php';
$user  = auth();
$admin = isAdmin();
$pdo   = db();

// ── TABLA DE RECIBOS DE PAGO (auto-crear, estilo del resto del CRM) ────────
try { $pdo->exec("CREATE TABLE IF NOT EXISTS recibos_pago (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agente_id INT NOT NULL,
    anio INT NOT NULL,
    mes TINYINT NOT NULL,
    quincena TINYINT NOT NULL,
    monto_horas DECIMAL(10,2) DEFAULT 0,
    monto_gastos DECIMAL(10,2) DEFAULT 0,
    monto_bonos DECIMAL(10,2) DEFAULT 0,
    total DECIMAL(10,2) DEFAULT 0,
    detalle_json TEXT,
    estado ENUM('PENDIENTE','APROBADO','RECHAZADO','PAGADO') DEFAULT 'PENDIENTE',
    notas VARCHAR(500),
    creado_por INT,
    creado_por_nombre VARCHAR(100),
    decidido_por INT NULL,
    decidido_por_nombre VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    decided_at TIMESTAMP NULL,
    pagado_at TIMESTAMP NULL,
    INDEX idx_agente (agente_id),
    INDEX idx_estado (estado)
)"); } catch (Exception $e) {}

// ── PARÁMETROS ──────────────────────────────────────────────────────────────
$year  = (int)($_GET['y'] ?? date('Y'));
$month = (int)($_GET['m'] ?? date('n'));
$q     = (int)($_GET['q'] ?? (date('j') <= 15 ? 1 : 2));

if ($admin) {
    $primer_agente = $pdo->query("SELECT id FROM usuarios WHERE activo=1 AND rol='agent' ORDER BY nombre LIMIT 1")->fetchColumn();
    $agente_id     = (int)($_GET['a'] ?? $primer_agente ?? 0);
} else {
    // Un empleado solo puede ver/armar su propio recibo, nunca el de otro.
    $agente_id = (int)$user['id'];
}

// ── ESCRITURA (PRG: Post → Redirect → Get) ──────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $p_year  = (int)($_POST['y'] ?? $year);
    $p_month = (int)($_POST['m'] ?? $month);
    $p_q     = (int)($_POST['q'] ?? $q);
    $p_a     = (int)($_POST['a'] ?? $agente_id);
    $action  = $_POST['action'] ?? '';

    if ($action === 'crear_recibo') {
        // Un empleado solo puede armar un recibo para sí mismo — el admin
        // puede armarlo en nombre de cualquier empleado si hace falta.
        $post_agente_id = $admin ? (int)($_POST['agente_id'] ?? 0) : (int)$user['id'];
        $incluye_horas  = !empty($_POST['incluye_horas']);
        $gasto_ids      = array_values(array_filter(array_map('intval', $_POST['gasto_ids'] ?? [])));
        $bono_ids       = array_values(array_filter(array_map('intval', $_POST['bono_ids'] ?? [])));
        $notas          = trim($_POST['notas'] ?? '');

        $ag = $pdo->prepare("SELECT * FROM usuarios WHERE id=? AND rol='agent'");
        $ag->execute([$post_agente_id]);
        $ag = $ag->fetch();

        if ($ag) {
            $monto_horas = 0;
            $horas_info  = ['incluido' => false];
            if ($incluye_horas && $ag['salario_quincenal'] !== null) {
                [$p_inicio, $p_fin] = quincena_rango($p_year, $p_month, $p_q);
                $calc = calcular_nomina_agente($pdo, $ag, $p_year, $p_month, $p_q, $p_inicio, $p_fin);
                $monto_horas = $calc['pago_calculado'];
                $horas_info  = [
                    'incluido'         => true,
                    'monto'            => $monto_horas,
                    'horas_trabajadas' => $calc['horas_trabajadas_total'],
                    'horas_esperadas'  => $calc['horas_esperadas'],
                    'pago_base'        => $calc['pago_base'],
                    'pago_extra'       => $calc['pago_extra'],
                ];
            }

            // Sólo se aceptan gastos/bonos del propio agente que sigan pendientes
            // (protege contra selecciones obsoletas si algo cambió entre pintar el
            // formulario y enviarlo).
            $gastos_detalle = [];
            $monto_gastos   = 0;
            if ($gasto_ids) {
                $ph = implode(',', array_fill(0, count($gasto_ids), '?'));
                $gq = $pdo->prepare("SELECT id,descripcion,monto,fecha FROM gastos WHERE id IN ($ph) AND reembolsar_a=? AND reembolsado=0 AND estado != 'RECHAZADO'");
                $gq->execute(array_merge($gasto_ids, [$post_agente_id]));
                foreach ($gq->fetchAll() as $g) {
                    $gastos_detalle[] = ['id' => (int)$g['id'], 'descripcion' => $g['descripcion'], 'monto' => (float)$g['monto'], 'fecha' => $g['fecha']];
                    $monto_gastos += (float)$g['monto'];
                }
            }

            $bonos_detalle = [];
            $monto_bonos   = 0;
            if ($bono_ids) {
                $ph = implode(',', array_fill(0, count($bono_ids), '?'));
                $bq = $pdo->prepare("SELECT id,cliente,total,fecha FROM pago_bonos WHERE id IN ($ph) AND agente_id=? AND pagado=0");
                $bq->execute(array_merge($bono_ids, [$post_agente_id]));
                foreach ($bq->fetchAll() as $b) {
                    $bonos_detalle[] = ['id' => (int)$b['id'], 'cliente' => $b['cliente'], 'monto' => (float)$b['total'], 'fecha' => $b['fecha']];
                    $monto_bonos += (float)$b['total'];
                }
            }

            $total = round($monto_horas + $monto_gastos + $monto_bonos, 2);

            if ($total > 0) {
                $detalle_json = json_encode(['horas' => $horas_info, 'gastos' => $gastos_detalle, 'bonos' => $bonos_detalle]);
                $pdo->prepare(
                    "INSERT INTO recibos_pago
                        (agente_id, anio, mes, quincena, monto_horas, monto_gastos, monto_bonos, total, detalle_json, estado, notas, creado_por, creado_por_nombre)
                     VALUES (?,?,?,?,?,?,?,?,?, 'PENDIENTE', ?,?,?)"
                )->execute([
                    $post_agente_id, $p_year, $p_month, $p_q,
                    $monto_horas, $monto_gastos, $monto_bonos, $total, $detalle_json,
                    mb_substr($notas, 0, 500), (int)$user['id'], $user['nombre'] ?? '',
                ]);
            }
        }
        $p_a = $post_agente_id;
    } elseif ($action === 'decidir_recibo' && $admin) {
        // Aprobar / rechazar es exclusivo del admin.
        $rid      = (int)($_POST['recibo_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        if ($rid && in_array($decision, ['APROBADO', 'RECHAZADO'], true)) {
            $pdo->prepare(
                "UPDATE recibos_pago SET estado=?, decidido_por=?, decidido_por_nombre=?, decided_at=NOW()
                 WHERE id=? AND estado='PENDIENTE'"
            )->execute([$decision, (int)$user['id'], $user['nombre'] ?? '', $rid]);
        }
    } elseif ($action === 'marcar_pagado' && $admin) {
        // Marcar como pagado es exclusivo del admin — cascada a gastos y bonos.
        $rid = (int)($_POST['recibo_id'] ?? 0);
        $rr  = $pdo->prepare("SELECT * FROM recibos_pago WHERE id=? AND estado='APROBADO'");
        $rr->execute([$rid]);
        $recibo = $rr->fetch();
        if ($recibo) {
            $det = json_decode($recibo['detalle_json'] ?? '{}', true) ?: [];
            foreach ($det['gastos'] ?? [] as $g) {
                $pdo->prepare("UPDATE gastos SET reembolsado=1, reembolsado_at=NOW(), estado='APROBADO', aprobado_por=? WHERE id=?")
                    ->execute([(int)$user['id'], (int)$g['id']]);
            }
            foreach ($det['bonos'] ?? [] as $b) {
                $pdo->prepare("UPDATE pago_bonos SET pagado=1 WHERE id=?")->execute([(int)$b['id']]);
            }
            $pdo->prepare("UPDATE recibos_pago SET estado='PAGADO', pagado_at=NOW() WHERE id=?")->execute([$rid]);
            try {
                $pdo->prepare("INSERT INTO actividad (agente_id,tipo,descripcion) VALUES (?,?,?)")
                    ->execute([$recibo['agente_id'], 'BONOS', 'RECIBO DE PAGO #' . $rid . ' marcado como PAGADO ($' . number_format((float)$recibo['total'], 2) . ')']);
            } catch (Exception $e) {}
        }
    } elseif ($action === 'eliminar_recibo') {
        // El admin puede borrar cualquier recibo pendiente; el empleado solo el suyo.
        $rid = (int)($_POST['recibo_id'] ?? 0);
        if ($admin) {
            $pdo->prepare("DELETE FROM recibos_pago WHERE id=? AND estado='PENDIENTE'")->execute([$rid]);
        } else {
            $pdo->prepare("DELETE FROM recibos_pago WHERE id=? AND estado='PENDIENTE' AND agente_id=?")->execute([$rid, (int)$user['id']]);
        }
    }

    if (!$admin) $p_a = (int)$user['id'];
    header('Location: reporte_pagos.php?a=' . $p_a . '&y=' . $p_year . '&m=' . $p_month . '&q=' . $p_q);
    exit;
}

// ── AGENTES (para el selector) ──────────────────────────────────────────────
// Un empleado solo puede ver/traer sus propios datos, nunca los de otro.
if ($admin) {
    $agents = $pdo->query("SELECT * FROM usuarios WHERE activo=1 AND rol='agent' ORDER BY nombre")->fetchAll();
} else {
    $st = $pdo->prepare("SELECT * FROM usuarios WHERE id=? AND rol='agent'");
    $st->execute([$agente_id]);
    $agents = $st->fetchAll();
}
$ag_sel = null;
foreach ($agents as $a) if ((int)$a['id'] === $agente_id) { $ag_sel = $a; break; }

// ── DATOS DEL AGENTE SELECCIONADO ───────────────────────────────────────────
$nomina_sel = null;
if ($ag_sel && $ag_sel['salario_quincenal'] !== null) {
    [$fecha_inicio, $fecha_fin] = quincena_rango($year, $month, $q);
    $nomina_sel = calcular_nomina_agente($pdo, $ag_sel, $year, $month, $q, $fecha_inicio, $fecha_fin);
}

// ── IDs ya reservados por recibos activos (PENDIENTE / APROBADO) de este agente ─
$gastos_reservados = [];
$bonos_reservados   = [];
if ($ag_sel) {
    $rr = $pdo->prepare("SELECT detalle_json FROM recibos_pago WHERE agente_id=? AND estado IN ('PENDIENTE','APROBADO')");
    $rr->execute([$ag_sel['id']]);
    foreach ($rr->fetchAll(PDO::FETCH_COLUMN) as $dj) {
        $d = json_decode($dj ?: '{}', true) ?: [];
        foreach ($d['gastos'] ?? [] as $g) $gastos_reservados[] = (int)$g['id'];
        foreach ($d['bonos']  ?? [] as $b) $bonos_reservados[]  = (int)$b['id'];
    }
}

// ── GASTOS Y BONOS PENDIENTES DISPONIBLES PARA ARMAR EL RECIBO ─────────────
$gastos_disponibles = [];
$bonos_disponibles  = [];
if ($ag_sel) {
    $gd = $pdo->prepare("SELECT * FROM gastos WHERE reembolsar_a=? AND reembolsado=0 AND estado != 'RECHAZADO' ORDER BY fecha DESC");
    $gd->execute([$ag_sel['id']]);
    foreach ($gd->fetchAll() as $g) {
        if (!in_array((int)$g['id'], $gastos_reservados, true)) $gastos_disponibles[] = $g;
    }

    $bd = $pdo->prepare("SELECT * FROM pago_bonos WHERE agente_id=? AND pagado=0 AND (venta_cancelada=0 OR venta_cancelada IS NULL) ORDER BY fecha DESC");
    $bd->execute([$ag_sel['id']]);
    foreach ($bd->fetchAll() as $b) {
        if (!in_array((int)$b['id'], $bonos_reservados, true)) $bonos_disponibles[] = $b;
    }
}

// ── HISTORIAL DE RECIBOS DEL AGENTE SELECCIONADO ────────────────────────────
$recibos_agente = [];
if ($ag_sel) {
    $rh = $pdo->prepare("SELECT * FROM recibos_pago WHERE agente_id=? ORDER BY created_at DESC LIMIT 30");
    $rh->execute([$ag_sel['id']]);
    $recibos_agente = $rh->fetchAll();
}

// ── COLA GLOBAL DE APROBACIÓN (solo admin, todos los agentes) ───────────────
$cola_aprobacion = [];
if ($admin) {
    $cola_aprobacion = $pdo->query(
        "SELECT r.*, u.nombre as agente_nombre, u.iniciales, u.color
         FROM recibos_pago r LEFT JOIN usuarios u ON r.agente_id = u.id
         WHERE r.estado IN ('PENDIENTE','APROBADO')
         ORDER BY r.estado='APROBADO', r.created_at"
    )->fetchAll();
}

// Meses en español
$meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
          'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$mes_label = $meses[$month] . ' ' . $year;
$q_label   = $q === 1 ? "1ª QUINCENA (1-15)" : "2ª QUINCENA (16-fin)";

$ESTADO_COLOR = ['PENDIENTE' => '#C07A1A', 'APROBADO' => '#2876A8', 'RECHAZADO' => '#B83232', 'PAGADO' => '#1E7A5C'];
$ESTADO_BG    = ['PENDIENTE' => '#FEF8EE', 'APROBADO' => '#EBF5FB', 'RECHAZADO' => '#FDF0EE', 'PAGADO' => '#EAF5F0'];

// Paleta
$P1='#1B4A6B';$P2='#2876A8';$BG='#EBF4F9';$CB='#C8DFF0';$G='#1E7A5C';$R='#B83232';$A='#C07A1A';$MU='#7A90A4';
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recibos de Pago — <?=h($mes_label)?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:<?=$BG?>;font-family:'DM Sans',sans-serif;font-size:13px;color:<?=$P1?>;padding:20px}
.page-header{background:<?=$P1?>;color:#fff;border-radius:14px;padding:18px 22px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.page-header h1{font-size:14px;font-weight:900;letter-spacing:3px;text-transform:uppercase}
.page-header .sub{font-size:9px;color:rgba(255,255,255,.6);letter-spacing:2px;text-transform:uppercase;margin-top:3px}
.controls{background:#fff;border:1px solid <?=$CB?>;border-radius:12px;padding:13px 16px;margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.controls label{font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px;display:block;margin-bottom:3px}
.controls select,.controls input,.controls textarea{border:1.5px solid <?=$CB?>;border-radius:8px;padding:7px 11px;font-size:11px;font-family:'DM Sans',sans-serif;background:<?=$BG?>;color:<?=$P1?>;font-weight:700}
.btn{border:none;border-radius:9px;padding:8px 18px;font-size:10px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif;letter-spacing:1px;text-transform:uppercase}
.btn-p{background:<?=$P1?>;color:#fff}
.btn-gr{background:#EAF5F0;color:<?=$G?>;border:1px solid #8DCFBA}
.btn-r{background:#FDF0EE;color:<?=$R?>;border:1px solid #EFA09A}
.btn-sm{padding:5px 11px;font-size:8px}
.card{background:#fff;border:1px solid <?=$CB?>;border-radius:14px;padding:16px;margin-bottom:16px}
.card h2{font-size:11px;font-weight:900;letter-spacing:1.5px;text-transform:uppercase;color:<?=$P1?>;margin-bottom:12px}
.sel-table{width:100%;border-collapse:collapse;font-size:10px;margin-bottom:6px}
.sel-table th{padding:5px 8px;text-align:left;font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;background:<?=$BG?>;border-bottom:1px solid <?=$CB?>}
.sel-table td{padding:7px 8px;border-bottom:1px solid <?=$CB?>40;color:<?=$P1?>}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:8px;font-weight:900;text-transform:uppercase;letter-spacing:.5px}
.total-bar{background:<?=$P1?>;color:#fff;border-radius:10px;padding:13px 16px;display:flex;justify-content:space-between;align-items:center;margin-top:10px}
.recibo-row{border:1px solid <?=$CB?>;border-radius:10px;padding:11px 14px;margin-bottom:9px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
.av{border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;flex-shrink:0;font-family:'DM Sans',sans-serif;width:32px;height:32px;font-size:11px}
@media print{ .controls,.no-print{display:none} }
</style>
</head>
<body>

<!-- ENCABEZADO -->
<div class="page-header">
  <div>
    <div class="page-header h1">🧾 <?=$admin?'RECIBOS DE PAGO':'MI RECIBO DE PAGO'?></div>
    <div class="sub"><?=$admin?'HORAS · GASTOS · BONOS · MEDICARE WITH ISABEL':'ENVÍA TU RECIBO PARA APROBACIÓN · MEDICARE WITH ISABEL'?></div>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <?php if($admin): ?>
    <a href="reporte_nomina.php?y=<?=$year?>&m=<?=$month?>&q=<?=$q?>" target="_blank" class="btn" style="background:rgba(255,255,255,.12);color:#fff;text-decoration:none">$ VER NÓMINA</a>
    <?php endif; ?>
    <a href="index.php" class="btn" style="background:rgba(255,255,255,.12);color:#fff;text-decoration:none">← CRM</a>
  </div>
</div>

<!-- COLA DE APROBACIÓN (todos los agentes) -->
<?php if(count($cola_aprobacion) > 0): ?>
<div class="card" style="border-top:4px solid <?=$A?>">
  <h2>⏳ PENDIENTES DE APROBACIÓN / PAGO (<?=count($cola_aprobacion)?>)</h2>
  <?php foreach($cola_aprobacion as $r):
      $det = json_decode($r['detalle_json'] ?: '{}', true) ?: [];
      $per = $meses[(int)$r['mes']] . ' ' . $r['anio'] . ' · Q' . $r['quincena'];
  ?>
  <div class="recibo-row">
    <div style="display:flex;gap:10px;align-items:center;flex:1;min-width:220px">
      <div class="av" style="background:<?=h($r['color']??$P2)?>"><?=h($r['iniciales']??'?')?></div>
      <div>
        <div style="font-weight:900;font-size:11px;color:<?=$P1?>"><?=h($r['agente_nombre']??'—')?></div>
        <div style="font-size:8px;color:<?=$MU?>;text-transform:uppercase">
          <?=h($per)?>
          <?php if(!empty($det['horas']['incluido'])): ?>· <?=$det['horas']['horas_trabajadas']?>h<?php endif; ?>
          <?php if(count($det['gastos']??[])): ?>· <?=count($det['gastos'])?> gasto(s)<?php endif; ?>
          <?php if(count($det['bonos']??[])): ?>· <?=count($det['bonos'])?> bono(s)<?php endif; ?>
        </div>
      </div>
    </div>
    <div style="font-weight:900;font-size:16px;color:<?=$G?>">$<?=number_format((float)$r['total'],2)?></div>
    <span class="badge" style="background:<?=$ESTADO_BG[$r['estado']]?>;color:<?=$ESTADO_COLOR[$r['estado']]?>"><?=h($r['estado'])?></span>
    <div style="display:flex;gap:6px">
      <?php if($r['estado']==='PENDIENTE'): ?>
      <form method="POST"><input type="hidden" name="action" value="decidir_recibo"><input type="hidden" name="decision" value="APROBADO"><input type="hidden" name="recibo_id" value="<?=(int)$r['id']?>"><input type="hidden" name="a" value="<?=$agente_id?>"><input type="hidden" name="y" value="<?=$year?>"><input type="hidden" name="m" value="<?=$month?>"><input type="hidden" name="q" value="<?=$q?>"><button type="submit" class="btn btn-gr btn-sm">✓ APROBAR</button></form>
      <form method="POST" onsubmit="return confirm('¿Rechazar este recibo?')"><input type="hidden" name="action" value="decidir_recibo"><input type="hidden" name="decision" value="RECHAZADO"><input type="hidden" name="recibo_id" value="<?=(int)$r['id']?>"><input type="hidden" name="a" value="<?=$agente_id?>"><input type="hidden" name="y" value="<?=$year?>"><input type="hidden" name="m" value="<?=$month?>"><input type="hidden" name="q" value="<?=$q?>"><button type="submit" class="btn btn-r btn-sm">✕ RECHAZAR</button></form>
      <?php else: ?>
      <form method="POST" onsubmit="return confirm('¿Marcar este recibo como PAGADO? Esto marcará los gastos y bonos incluidos como pagados.')"><input type="hidden" name="action" value="marcar_pagado"><input type="hidden" name="recibo_id" value="<?=(int)$r['id']?>"><input type="hidden" name="a" value="<?=$agente_id?>"><input type="hidden" name="y" value="<?=$year?>"><input type="hidden" name="m" value="<?=$month?>"><input type="hidden" name="q" value="<?=$q?>"><button type="submit" class="btn btn-p btn-sm">$ MARCAR PAGADO</button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- SELECTOR DE EMPLEADO Y PERÍODO -->
<form method="GET" class="controls">
  <?php if($admin): ?>
  <div>
    <label>EMPLEADO</label>
    <select name="a">
      <?php foreach($agents as $a): ?>
      <option value="<?=$a['id']?>" <?=$a['id']==$agente_id?'selected':''?>><?=h($a['nombre'])?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php else: ?>
  <input type="hidden" name="a" value="<?=$agente_id?>">
  <?php endif; ?>
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
  <button type="submit" class="btn btn-p">VER</button>
</form>

<?php if(!$ag_sel): ?>
<div class="card">No hay empleados activos configurados.</div>
<?php else: ?>

<!-- ARMAR NUEVO RECIBO -->
<div class="card" style="border-top:4px solid <?=h($ag_sel['color']??$P1)?>">
  <h2>🧾 ARMAR RECIBO — <?=h($ag_sel['nombre'])?> · <?=h($q_label)?> <?=h($mes_label)?></h2>
  <form method="POST" id="form-recibo" onsubmit="return validarRecibo()">
    <input type="hidden" name="action" value="crear_recibo">
    <input type="hidden" name="agente_id" value="<?=(int)$ag_sel['id']?>">
    <input type="hidden" name="a" value="<?=$agente_id?>">
    <input type="hidden" name="y" value="<?=$year?>">
    <input type="hidden" name="m" value="<?=$month?>">
    <input type="hidden" name="q" value="<?=$q?>">

    <!-- HORAS -->
    <?php if($nomina_sel): ?>
    <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:9px;margin-bottom:10px;cursor:pointer">
      <input type="checkbox" name="incluye_horas" value="1" class="chk-monto" data-monto="<?=$nomina_sel['pago_calculado']?>" checked style="width:16px;height:16px">
      <span style="flex:1">
        <b>Horas de la quincena</b> —
        <?=$nomina_sel['horas_trabajadas_total']?>h de <?=$nomina_sel['horas_esperadas']?>h esperadas
        <?php if($nomina_sel['horas_extra']>0): ?><span style="color:<?=$A?>"> (+<?=$nomina_sel['horas_extra']?>h extra)</span><?php endif; ?>
      </span>
      <b style="color:<?=$G?>">$<?=number_format($nomina_sel['pago_calculado'],2)?></b>
    </label>
    <?php else: ?>
    <div style="font-size:9px;color:<?=$MU?>;margin-bottom:10px">Este empleado no tiene salario quincenal configurado — no se incluyen horas.</div>
    <?php endif; ?>

    <!-- GASTOS PENDIENTES -->
    <div style="font-size:9px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin:12px 0 6px">GASTOS PENDIENTES DE REEMBOLSO</div>
    <?php if(count($gastos_disponibles)===0): ?>
    <div style="font-size:9px;color:<?=$MU?>;margin-bottom:10px">No hay gastos pendientes de reembolso para <?=h($ag_sel['nombre'])?>.</div>
    <?php else: ?>
    <table class="sel-table">
      <?php foreach($gastos_disponibles as $g): ?>
      <tr>
        <td style="width:24px"><input type="checkbox" name="gasto_ids[]" value="<?=(int)$g['id']?>" class="chk-monto" data-monto="<?=(float)$g['monto']?>" style="width:15px;height:15px"></td>
        <td style="white-space:nowrap;color:<?=$MU?>"><?=date('d/m/Y',strtotime($g['fecha']))?></td>
        <td><?=h($g['descripcion'])?> <span style="color:<?=$MU?>">(<?=h($g['categoria'])?>)</span></td>
        <td style="text-align:right;font-weight:900">$<?=number_format((float)$g['monto'],2)?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <!-- BONOS PENDIENTES -->
    <div style="font-size:9px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin:12px 0 6px">BONOS PENDIENTES DE PAGO</div>
    <?php if(count($bonos_disponibles)===0): ?>
    <div style="font-size:9px;color:<?=$MU?>;margin-bottom:10px">No hay bonos pendientes de pago para <?=h($ag_sel['nombre'])?>.</div>
    <?php else: ?>
    <table class="sel-table">
      <?php foreach($bonos_disponibles as $b): ?>
      <tr>
        <td style="width:24px"><input type="checkbox" name="bono_ids[]" value="<?=(int)$b['id']?>" class="chk-monto" data-monto="<?=(float)$b['total']?>" style="width:15px;height:15px"></td>
        <td style="white-space:nowrap;color:<?=$MU?>"><?=date('d/m/Y',strtotime($b['fecha']))?></td>
        <td><?=h($b['tipo'])?> — <?=h($b['cliente'])?></td>
        <td style="text-align:right;font-weight:900">$<?=number_format((float)$b['total'],2)?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <div>
      <label style="font-size:8px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;display:block;margin:10px 0 3px">NOTAS (OPCIONAL)</label>
      <input type="text" name="notas" maxlength="500" placeholder="ej. Quincena completa + reembolso de gasolina" style="width:100%;border:1.5px solid <?=$CB?>;border-radius:8px;padding:8px 11px;font-size:10px;font-family:'DM Sans',sans-serif;color:<?=$P1?>;font-weight:700">
    </div>

    <div class="total-bar">
      <div style="font-size:9px;font-weight:900;letter-spacing:2px;text-transform:uppercase;opacity:.7">TOTAL DEL RECIBO</div>
      <div style="font-size:22px;font-weight:900" id="recibo-total">$0.00</div>
    </div>

    <div style="margin-top:12px;text-align:right">
      <button type="submit" class="btn btn-p">ENVIAR RECIBO A APROBACIÓN</button>
    </div>
  </form>
</div>

<!-- HISTORIAL DEL EMPLEADO -->
<div class="card">
  <h2>HISTORIAL — <?=h($ag_sel['nombre'])?></h2>
  <?php if(count($recibos_agente)===0): ?>
  <div style="font-size:9px;color:<?=$MU?>">Sin recibos todavía.</div>
  <?php else: foreach($recibos_agente as $r):
      $det = json_decode($r['detalle_json'] ?: '{}', true) ?: [];
      $per = $meses[(int)$r['mes']] . ' ' . $r['anio'] . ' · Q' . $r['quincena'];
  ?>
  <div class="recibo-row">
    <div style="flex:1;min-width:200px">
      <div style="font-weight:900;font-size:10px"><?=h($per)?></div>
      <div style="font-size:8px;color:<?=$MU?>">
        <?php if(!empty($det['horas']['incluido'])): ?>Horas: $<?=number_format((float)$det['horas']['monto'],2)?> · <?php endif; ?>
        Gastos: $<?=number_format((float)$r['monto_gastos'],2)?> · Bonos: $<?=number_format((float)$r['monto_bonos'],2)?>
        <br>Creado por <?=h($r['creado_por_nombre'])?> el <?=date('d/m/Y',strtotime($r['created_at']))?>
        <?php if($r['decidido_por_nombre']): ?> · <?=h($r['estado'])?> por <?=h($r['decidido_por_nombre'])?><?php endif; ?>
      </div>
    </div>
    <div style="font-weight:900;font-size:14px;color:<?=$G?>">$<?=number_format((float)$r['total'],2)?></div>
    <span class="badge" style="background:<?=$ESTADO_BG[$r['estado']]?>;color:<?=$ESTADO_COLOR[$r['estado']]?>"><?=h($r['estado'])?></span>
    <?php if($r['estado']==='PENDIENTE'): ?>
    <form method="POST" onsubmit="return confirm('¿Eliminar este recibo pendiente?')">
      <input type="hidden" name="action" value="eliminar_recibo">
      <input type="hidden" name="recibo_id" value="<?=(int)$r['id']?>">
      <input type="hidden" name="a" value="<?=$agente_id?>"><input type="hidden" name="y" value="<?=$year?>"><input type="hidden" name="m" value="<?=$month?>"><input type="hidden" name="q" value="<?=$q?>">
      <button type="submit" class="btn btn-r btn-sm">✕ ELIMINAR</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>
</div>

<?php endif; ?>

<script>
function recalcTotal(){
  var chks = document.querySelectorAll('#form-recibo .chk-monto');
  var total = 0;
  chks.forEach(function(c){ if(c.checked) total += parseFloat(c.dataset.monto || '0'); });
  var el = document.getElementById('recibo-total');
  if (el) el.textContent = '$' + total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
  return total;
}
function validarRecibo(){
  if (recalcTotal() <= 0) {
    alert('Selecciona al menos un concepto (horas, gasto o bono) antes de enviar el recibo.');
    return false;
  }
  return confirm('¿Enviar este recibo a aprobación?');
}
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('#form-recibo .chk-monto').forEach(function(c){ c.addEventListener('change', recalcTotal); });
  recalcTotal();
});
</script>

</body>
</html>
