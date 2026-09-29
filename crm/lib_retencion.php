<?php
/* ═══════════════════════════════════════════════════════════════════
 *  LIB_RETENCION.PHP — pestaña RETENCIÓN, cargada aparte.
 *  ─────────────────────────────────────────────────────────────────
 *  Antes esta pestaña armaba, en CADA carga completa de la página
 *  (la veas o no), la tabla completa de miembros activos con su
 *  seguimiento de retención (bienvenida/30/60/90 días + cuestionario) —
 *  pedido de Isabel: eso hacía sentir TODO el CRM lento apenas entrabas,
 *  no solo esta pestaña. Ahora se pide aparte, igual que ya se hizo con
 *  Tickets/Citas/Follow Ups/Today Live (ver loadRetencionPanel() en
 *  index.php y api.php?action=get_retencion_panel).
 * ═══════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/lib_row_render.php'; // row_origen_badge()

function render_retencion_panel(PDO $pdo): array {
    $nombres = [];

    ob_start();
    try {
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS retencion_llamadas (id INT AUTO_INCREMENT PRIMARY KEY, miembro_id INT NOT NULL, tipo ENUM('BIENVENIDA','30','60','90') NOT NULL, resultado ENUM('COMPLETADA','NO CONTESTÓ','BUZÓN') NOT NULL, notas TEXT, completada_por INT, completada_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_ret (miembro_id, tipo))"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS retencion_cuestionario_30 (id INT AUTO_INCREMENT PRIMARY KEY, miembro_id INT NOT NULL, puede_sms TINYINT(1) DEFAULT NULL, usa_whatsapp TINYINT(1) DEFAULT NULL, usa_facebook TINYINT(1) DEFAULT NULL, nos_siguio TINYINT(1) DEFAULT NULL, link_enviado TINYINT(1) DEFAULT NULL, usa_insulina TINYINT(1) DEFAULT NULL, ayudas_movilidad VARCHAR(500) DEFAULT NULL, necesita_delivery TINYINT(1) DEFAULT NULL, llego_tarjeta TINYINT(1) DEFAULT NULL, explicaste_tarjeta TINYINT(1) DEFAULT NULL, direccion_correcta TINYINT(1) DEFAULT NULL, esta_casado TINYINT(1) DEFAULT NULL, doctor_correcto TINYINT(1) DEFAULT NULL, ha_ido_citas TINYINT(1) DEFAULT NULL, satisfecho_doctor TINYINT(1) DEFAULT NULL, cambiar_doctor TINYINT(1) DEFAULT NULL, va_dentista TINYINT(1) DEFAULT NULL, necesita_dentista TINYINT(1) DEFAULT NULL, usa_anteojos TINYINT(1) DEFAULT NULL, explicaste_uber TINYINT(1) DEFAULT NULL, explicaste_gym TINYINT(1) DEFAULT NULL, beneficios_repasados TEXT DEFAULT NULL, explicaste_no_dar_info TINYINT(1) DEFAULT NULL, referido_nuevo VARCHAR(255) DEFAULT NULL, donde_conocio_isabel VARCHAR(255) DEFAULT NULL, notas_generales TEXT DEFAULT NULL, completada_por INT DEFAULT NULL, completada_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_q30 (miembro_id))"); } catch (Throwable $e) {}

        $_ret_calls = [];
        try { $rs = $pdo->query("SELECT rl.*, u.nombre AS por_nombre FROM retencion_llamadas rl LEFT JOIN usuarios u ON rl.completada_por=u.id"); foreach ($rs as $rl) { $_ret_calls[$rl['miembro_id']][$rl['tipo']] = $rl; } } catch (Throwable $e) {}

        $_ret_bienvenidas = [];
        try { foreach ($pdo->query("SELECT miembro_id, created_at FROM efectivos_checks WHERE tipo='llam_bienvenida' AND done=1") as $ec) { $_ret_bienvenidas[$ec['miembro_id']] = $ec['created_at']; } } catch (Throwable $e) {}

        $_ret_q30_ids = [];
        try { foreach ($pdo->query("SELECT miembro_id, completada_at FROM retencion_cuestionario_30") as $q) { $_ret_q30_ids[$q['miembro_id']] = $q['completada_at']; } } catch (Throwable $e) {}

        // Solo miembros ACTIVOS con fecha efectiva — no hace falta traer los
        // ~500 miembros completos (con su JOIN de agente + subconsulta de SOA)
        // que ya carga la página para otras cosas; esta pantalla no los usa.
        $membersQ = $pdo->query("SELECT id,nombre,apellido,telefono,carrier,fecha_efectiva,campana_origen_id,referido_por_miembro_id,referido_por_texto,fuente
                                  FROM miembros WHERE estado='ACTIVE' AND fecha_efectiva IS NOT NULL")->fetchAll();

        $_origen_campanas = [];
        try { foreach ($pdo->query("SELECT id,nombre FROM campanas") as $_oc) $_origen_campanas[$_oc['id']] = $_oc['nombre']; } catch (Throwable $e) {}
        $_origen_miembros_nombre = [];
        try { foreach ($pdo->query("SELECT id,nombre,apellido FROM miembros") as $_om) $_origen_miembros_nombre[$_om['id']] = trim($_om['nombre'] . ' ' . $_om['apellido']); } catch (Throwable $e) {}

        $_today_ts = strtotime(date('Y-m-d'));
        $_ret_list = [];
        foreach ($membersQ as $m) {
            if (empty($m['fecha_efectiva'])) continue;
            $dias = (int)round(($_today_ts - strtotime($m['fecha_efectiva'])) / 86400);
            if ($dias < 0) continue;
            $bienvenida_done = isset($_ret_bienvenidas[$m['id']]);
            $callB  = $_ret_calls[$m['id']]['BIENVENIDA'] ?? null;
            $call30 = $_ret_calls[$m['id']]['30'] ?? null;
            $call60 = $_ret_calls[$m['id']]['60'] ?? null;
            $call90 = $_ret_calls[$m['id']]['90'] ?? null;
            $bienv_ok = $bienvenida_done || $callB;
            $urgente = false;
            if (!$bienv_ok && $dias <= 14) $urgente = true;
            if (!$bienv_ok && $dias > 14)  $urgente = true;
            if (!$call30 && $dias >= 25) $urgente = true;
            if (!$call60 && $dias >= 55) $urgente = true;
            if (!$call90 && $dias >= 85) $urgente = true;
            $_ret_list[] = ['id'=>$m['id'],'nombre'=>$m['nombre'],'apellido'=>$m['apellido'],'telefono'=>$m['telefono']??'','carrier'=>$m['carrier']??'','fecha_efe'=>$m['fecha_efectiva'],'dias'=>$dias,'bienvenida'=>$bienvenida_done?($_ret_bienvenidas[$m['id']]??''):null,'callB'=>$callB,'call30'=>$call30,'call60'=>$call60,'call90'=>$call90,'q30'=>isset($_ret_q30_ids[$m['id']])?$_ret_q30_ids[$m['id']]:null,'urgente'=>$urgente,
                'campana_origen_id'=>$m['campana_origen_id']??null,'referido_por_miembro_id'=>$m['referido_por_miembro_id']??null,'referido_por_texto'=>$m['referido_por_texto']??null,'fuente'=>$m['fuente']??null];
        }
        usort($_ret_list, function ($a, $b) { return $b['urgente'] <=> $a['urgente'] ?: $a['dias'] <=> $b['dias']; });
        $_st_total   = count($_ret_list);
        $_st_urgente = count(array_filter($_ret_list, function ($m) { return $m['urgente']; }));
        $_st_bienok  = count(array_filter($_ret_list, function ($m) { return $m['bienvenida'] || $m['callB']; }));
        $_st_30ok    = count(array_filter($_ret_list, function ($m) { return $m['call30']; }));
        $_st_60ok    = count(array_filter($_ret_list, function ($m) { return $m['call60']; }));
        $_st_90ok    = count(array_filter($_ret_list, function ($m) { return $m['call90']; }));

        foreach ($_ret_list as $_rm) $nombres[(int)$_rm['id']] = $_rm['nombre'] . ' ' . $_rm['apellido'];
        ?>
<div style="padding:0 0 10px">

<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:9px;margin-bottom:18px">
<?php
$_ret_stats = [['ACTIVOS',$_st_total,'#1B4A6B'],['URGENTES',$_st_urgente,'#B83232'],['BIENVENIDA',$_st_bienok,'#1E7A5C'],['30 DÍAS',$_st_30ok,'#1E7A5C'],['60 DÍAS',$_st_60ok,'#1E7A8C'],['90 DÍAS',$_st_90ok,'#5B3FAF']];
foreach ($_ret_stats as $_rs) {
    echo "<div style='background:#fff;border:1px solid #C8DFF0;border-top:3px solid {$_rs[2]};border-radius:10px;padding:10px 13px;text-align:center'><div style='font-size:7px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:.7px;margin-bottom:3px'>" . htmlspecialchars($_rs[0]) . "</div><div style='font-size:20px;font-weight:900;color:{$_rs[2]}'>{$_rs[1]}</div></div>";
}
?>
</div>

<div style="display:flex;gap:7px;margin-bottom:13px;align-items:center;flex-wrap:wrap">
<span style="font-size:9px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:.7px">FILTRAR:</span>
<?php foreach (['TODOS','✓ CON CUESTIONARIO','○ SIN CUESTIONARIO'] as $_rf): ?>
<button class="btn btn-gh btn-sm ret-fb" data-rf="<?=htmlspecialchars($_rf)?>" onclick="retFilter('<?=htmlspecialchars($_rf)?>')" style="<?=$_rf==='TODOS'?'background:#1B4A6B;color:#fff':''?>"><?=htmlspecialchars($_rf)?></button>
<?php endforeach; ?>
<input id="ret-search" type="text" placeholder="Buscar miembro..." class="form-input" style="max-width:200px;margin-left:auto" oninput="retFilter()">
</div>

<div style="overflow-x:auto">
<table>
<tr><th>MIEMBRO</th><th>CARRIER</th><th>F. EFECTIVA</th><th style="text-align:center">DÍAS</th><th style="text-align:center">BIENVENIDA</th><th style="text-align:center">30 DÍAS</th><th style="text-align:center">60 DÍAS</th><th style="text-align:center">90 DÍAS</th><th style="text-align:center">CUEST.</th><th></th></tr>
<?php foreach ($_ret_list as $_rm):
    $_dias = $_rm['dias'];
    $_mid  = (int)$_rm['id'];
    $_ur   = $_rm['urgente'] ? '1' : '0';
    $_srch = strtolower($_rm['apellido'].' '.$_rm['nombre'].' '.$_rm['carrier'].' '.$_rm['telefono']);

    $_cBts = $_rm['callB'] ? $_rm['callB']['completada_at'] : null;
    if ($_cBts) {
        $_r = $_rm['callB']['resultado'] ?? 'COMPLETADA';
        $_st = $_r==='NO CONTESTÓ'?['#FDF0EE','#B83232','#EFA09A','✕ NO CONT.']:($_r==='BUZÓN'?['#FEF8EE','#C07A1A','#F5D5A0','📬 BUZÓN']:['#EAF5F0','#1E7A5C','#8DCFBA','✓ CONTESTÓ']);
        $_chip_b = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"BIENVENIDA\")' title='Cambiar resultado' style='background:{$_st[0]};color:{$_st[1]};border:1.5px solid {$_st[2]};font-size:8px;font-weight:900;padding:3px 8px'>{$_st[3]}</button><div style='font-size:7px;color:#7A90A4'>".date('d/m/y',strtotime($_cBts))."</div></div>";
    } elseif ($_rm['bienvenida']) {
        $_chip_b = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"BIENVENIDA\")' title='Registrar resultado' style='background:#EAF5F0;color:#1E7A5C;border:1.5px solid #8DCFBA;font-size:8px;font-weight:900;padding:3px 8px'>✓ OK</button><div style='font-size:7px;color:#7A90A4'>".date('d/m/y',strtotime($_rm['bienvenida']))."</div></div>";
    } elseif ($_dias <= 14) {
        $_chip_b = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"BIENVENIDA\")' style='background:#FEF8EE;color:#C07A1A;border:1.5px solid #F5D5A0;font-size:8px;font-weight:900;padding:3px 8px'>📞 HOY</button></div>";
    } else {
        $_chip_b = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"BIENVENIDA\")' style='background:#FDF0EE;color:#B83232;border:1.5px solid #EFA09A;font-size:8px;font-weight:900;padding:3px 8px'>🚨 VENCIDA</button></div>";
    }

    $_c30ts = $_rm['call30'] ? $_rm['call30']['completada_at'] : null;
    if ($_c30ts) {
        $_r = $_rm['call30']['resultado'] ?? 'COMPLETADA';
        $_st = $_r==='NO CONTESTÓ'?['#FDF0EE','#B83232','#EFA09A','✕ NO CONT.']:($_r==='BUZÓN'?['#FEF8EE','#C07A1A','#F5D5A0','📬 BUZÓN']:['#EAF5F0','#1E7A5C','#8DCFBA','✓ CONTESTÓ']);
        $_chip_30 = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"30\")' title='Cambiar resultado' style='background:{$_st[0]};color:{$_st[1]};border:1.5px solid {$_st[2]};font-size:8px;font-weight:900;padding:3px 8px'>{$_st[3]}</button><div style='font-size:7px;color:#7A90A4'>".date('d/m/y',strtotime($_c30ts))."</div></div>";
    } elseif ($_dias < 25) {
        $_chip_30 = "<div style='text-align:center;font-size:8px;color:#94A3B8'>en ".(25-$_dias)."d</div>";
    } elseif ($_dias <= 40) {
        $_chip_30 = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"30\")' style='background:#FEF8EE;color:#C07A1A;border:1.5px solid #F5D5A0;font-size:8px;font-weight:900;padding:3px 8px'>📞 HOY</button></div>";
    } else {
        $_chip_30 = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"30\")' style='background:#FDF0EE;color:#B83232;border:1.5px solid #EFA09A;font-size:8px;font-weight:900;padding:3px 8px'>🚨 VENCIDA</button></div>";
    }

    $_c60ts = $_rm['call60'] ? $_rm['call60']['completada_at'] : null;
    if ($_c60ts) {
        $_r = $_rm['call60']['resultado'] ?? 'COMPLETADA';
        $_st = $_r==='NO CONTESTÓ'?['#FDF0EE','#B83232','#EFA09A','✕ NO CONT.']:($_r==='BUZÓN'?['#FEF8EE','#C07A1A','#F5D5A0','📬 BUZÓN']:['#EAF5F0','#1E7A5C','#8DCFBA','✓ CONTESTÓ']);
        $_chip_60 = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"60\")' title='Cambiar resultado' style='background:{$_st[0]};color:{$_st[1]};border:1.5px solid {$_st[2]};font-size:8px;font-weight:900;padding:3px 8px'>{$_st[3]}</button><div style='font-size:7px;color:#7A90A4'>".date('d/m/y',strtotime($_c60ts))."</div></div>";
    } elseif ($_dias < 55) {
        $_chip_60 = "<div style='text-align:center;font-size:8px;color:#94A3B8'>en ".(55-$_dias)."d</div>";
    } elseif ($_dias <= 70) {
        $_chip_60 = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"60\")' style='background:#FEF8EE;color:#C07A1A;border:1.5px solid #F5D5A0;font-size:8px;font-weight:900;padding:3px 8px'>📞 HOY</button></div>";
    } else {
        $_chip_60 = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"60\")' style='background:#FDF0EE;color:#B83232;border:1.5px solid #EFA09A;font-size:8px;font-weight:900;padding:3px 8px'>🚨 VENCIDA</button></div>";
    }

    $_c90ts = $_rm['call90'] ? $_rm['call90']['completada_at'] : null;
    if ($_c90ts) {
        $_r = $_rm['call90']['resultado'] ?? 'COMPLETADA';
        $_st = $_r==='NO CONTESTÓ'?['#FDF0EE','#B83232','#EFA09A','✕ NO CONT.']:($_r==='BUZÓN'?['#FEF8EE','#C07A1A','#F5D5A0','📬 BUZÓN']:['#EAF5F0','#1E7A5C','#8DCFBA','✓ CONTESTÓ']);
        $_chip_90 = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"90\")' title='Cambiar resultado' style='background:{$_st[0]};color:{$_st[1]};border:1.5px solid {$_st[2]};font-size:8px;font-weight:900;padding:3px 8px'>{$_st[3]}</button><div style='font-size:7px;color:#7A90A4'>".date('d/m/y',strtotime($_c90ts))."</div></div>";
    } elseif ($_dias < 85) {
        $_chip_90 = "<div style='text-align:center;font-size:8px;color:#94A3B8'>en ".(85-$_dias)."d</div>";
    } elseif ($_dias <= 100) {
        $_chip_90 = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"90\")' style='background:#FEF8EE;color:#C07A1A;border:1.5px solid #F5D5A0;font-size:8px;font-weight:900;padding:3px 8px'>📞 HOY</button></div>";
    } else {
        $_chip_90 = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"90\")' style='background:#FDF0EE;color:#B83232;border:1.5px solid #EFA09A;font-size:8px;font-weight:900;padding:3px 8px'>🚨 VENCIDA</button></div>";
    }

    $_dc = $_dias<=14?'#1E7A5C':($_dias<=40?'#C07A1A':($_dias<=70?'#1E7A8C':($_dias<=100?'#5B3FAF':'#1B4A6B')));
?>
<tr class="ret-row"
 data-ur="<?=$_ur?>"
 data-dias="<?=$_dias?>"
 data-search="<?=htmlspecialchars($_srch)?>"
 data-pend-b="<?=(($_rm['bienvenida']||$_rm['callB'])?'0':'1')?>"
 data-pend-30="<?=(!$_rm['call30']&&$_dias>=25?'1':'0')?>"
 data-pend-60="<?=(!$_rm['call60']&&$_dias>=55?'1':'0')?>"
 data-pend-90="<?=(!$_rm['call90']&&$_dias>=85?'1':'0')?>"
 data-q30="<?=$_rm['q30']?'1':'0'?>"
 style="<?=$_rm['urgente']?'background:#FFFBF2':''?>">
<td><div style="font-weight:900;font-size:9px;color:#1B4A6B;cursor:pointer" onclick="openProfile(<?=$_mid?>)"><?=htmlspecialchars($_rm['apellido'].', '.$_rm['nombre'])?><?=row_origen_badge($_rm,$_origen_campanas,$_origen_miembros_nombre)?></div><div style="font-size:8px;color:#7A90A4"><?=htmlspecialchars($_rm['telefono']??'—')?></div></td>
<td><?php if($_rm['carrier']): ?><span style="background:#EBF5FB;color:#1B5E8C;border:1px solid #A9D0E8;border-radius:20px;padding:1px 7px;font-size:8px;font-weight:900"><?=htmlspecialchars($_rm['carrier'])?></span><?php else: ?>—<?php endif; ?></td>
<td style="font-size:8px;color:#7A90A4"><?=$_rm['fecha_efe']?></td>
<td style="text-align:center"><span style="font-weight:900;font-size:12px;color:<?=$_dc?>"><?=$_dias?>d</span></td>
<td id="ret-chip-<?=$_mid?>-BIENVENIDA"><?=$_chip_b?></td>
<td id="ret-chip-<?=$_mid?>-30"><?=$_chip_30?></td>
<td id="ret-chip-<?=$_mid?>-60"><?=$_chip_60?></td>
<td id="ret-chip-<?=$_mid?>-90"><?=$_chip_90?></td>
<td style="text-align:center">
<?php if($_rm['q30']): ?>
<span style="background:#EAF5F0;color:#1E7A5C;border:1px solid #8DCFBA;border-radius:20px;padding:2px 8px;font-size:8px;font-weight:900">✓</span>
<?php elseif($_dias >= 25): ?>
<button class="btn btn-sm" onclick="openRetQ30(<?=$_mid?>)" style="background:#EBF5FB;color:#1B5E8C;border:1.5px solid #A9D0E8;font-size:8px;font-weight:900">📋</button>
<?php else: ?>
<span style="color:#94A3B8;font-size:8px">—</span>
<?php endif; ?>
</td>
<td><button class="btn btn-b btn-sm" onclick="openProfile(<?=$_mid?>)">◉</button></td>
</tr>
<?php endforeach; ?>
</table>
</div>
</div>
<?php
    } catch (Throwable $e) {
        ob_end_clean();
        return ['html' => '<div style="padding:30px;text-align:center;color:#B83232;font-size:9px;text-transform:uppercase">No se pudo armar RETENCIÓN — intenta de nuevo en un momento</div>', 'nombres' => []];
    }
    $html = ob_get_clean();

    return ['html' => $html, 'nombres' => $nombres];
}
