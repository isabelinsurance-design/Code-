<?php
/* ═══════════════════════════════════════════════════════════════════
 *  MEMBER_ROW.PHP — devuelve el HTML de UNA sola fila de la tabla de
 *  Miembros (el mismo <tr> que ya arma index.php), para poder
 *  actualizarla después de guardar sin tener que recargar TODA la
 *  página (que de paso vuelve a preparar todas las demás pestañas).
 *  ─────────────────────────────────────────────────────────────────
 *  GET id=<miembro_id>. Requiere sesión iniciada (cualquier rol).
 * ═══════════════════════════════════════════════════════════════════ */
require_once 'session_boot.php';
require_once 'config.php';
$user = auth();
if (empty($user)) { http_response_code(403); exit; }

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit; }

$pdo = db();
$stm = $pdo->prepare("SELECT m.*, u.nombre as agente_nombre, u.color as agente_color, u.iniciales as agente_ini,
    (SELECT COUNT(*) FROM soa WHERE miembro_id=m.id AND estado='FIRMADO') as has_soa
    FROM miembros m LEFT JOIN usuarios u ON m.agente_id=u.id WHERE m.id=?");
$stm->execute([$id]);
$m = $stm->fetch();
if (!$m) { http_response_code(404); exit; }

$mtks_st = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE miembro_id=? AND estado!='CERRADO'");
$mtks_st->execute([$id]);
$mtks = (int)$mtks_st->fetchColumn();

// Origen (campaña / referido) — mismo criterio que origen_badge_html() en
// index.php, pero resolviendo solo lo que hace falta para ESTE miembro en
// vez de precargar el mapa completo de todos los miembros y campañas.
$origen_campana = null;
if (!empty($m['campana_origen_id'])) {
    $oc = $pdo->prepare("SELECT nombre FROM campanas WHERE id=?");
    $oc->execute([$m['campana_origen_id']]);
    $origen_campana = $oc->fetchColumn() ?: null;
}
$origen_referente = null;
if (!empty($m['referido_por_miembro_id'])) {
    $orf = $pdo->prepare("SELECT nombre, apellido FROM miembros WHERE id=?");
    $orf->execute([$m['referido_por_miembro_id']]);
    if ($r = $orf->fetch()) $origen_referente = trim($r['nombre'].' '.$r['apellido']);
}

function _mr_badge(?string $s, bool $sm = false): string {
    $s = $s ?? ''; $map=['ACTIVE'=>['#1E7A5C','#EAF5F0','#8DCFBA'],'IN PROCESS'=>['#1B5E8C','#EBF5FB','#A9D0E8'],'PLAN CHANGE'=>['#5B3FAF','#F3F0FB','#C2B0E8'],'SIN HACER'=>['#C07A1A','#FEF8EE','#F5D5A0'],'SIN FIRMAR'=>['#C05C1A','#FEF2EB','#F5C4A0'],'CANCELED'=>['#B83232','#FDF0EE','#EFA09A'],'DENIED'=>['#B83232','#FDF0EE','#EFA09A'],'CERRADO'=>['#1E7A5C','#EAF5F0','#8DCFBA'],'DISENROLLED'=>['#993C1D','#FAECE7','#F0997B'],'PROSPECT'=>['#1E7A8C','#EAF4F6','#8DC8D0']];
    $c=$map[$s]??['#7A90A4','#F4F8FC','#C8DFF0']; $p=$sm?'2px 8px':'3px 10px'; $f=$sm?'9px':'10px';
    return "<span style=\"padding:$p;border-radius:20px;font-size:$f;font-weight:800;background:{$c[1]};color:{$c[0]};border:1px solid {$c[2]};white-space:nowrap;letter-spacing:.5px;text-transform:uppercase\">".h($s)."</span>";
}
function _mr_av(string $i, string $c, int $z = 24): string {
    return "<div style=\"width:{$z}px;height:{$z}px;border-radius:50%;background:$c;display:flex;align-items:center;justify-content:center;font-size:".round($z*.32)."px;font-weight:900;color:#fff;flex-shrink:0;font-family:'DM Sans',sans-serif\">$i</div>";
}
function _mr_origen_badge($m, ?string $origen_campana, ?string $origen_referente): string {
    $bg='#F1EFE8'; $col='#7A90A4'; $bc='#D8D4C8'; $texto=null; $tip='';
    if ($origen_campana) {
        $bg='#FEF8EE'; $col='#C07A1A'; $bc='#F5D5A0';
        $texto = '📣 '.h(mb_strimwidth($origen_campana, 0, 16, '…'));
        $tip = 'Campaña: '.$origen_campana;
    } elseif ($origen_referente) {
        $bg='#EAF5F0'; $col='#1E7A5C'; $bc='#8DCFBA';
        $texto = '🤝 REF: '.h(mb_strimwidth($origen_referente, 0, 14, '…'));
        $tip = 'Referido por: '.$origen_referente;
    } elseif (!empty($m['referido_por_texto'])) {
        $bg='#EAF5F0'; $col='#1E7A5C'; $bc='#8DCFBA';
        $texto = '🤝 REF: '.h(mb_strimwidth($m['referido_por_texto'], 0, 14, '…'));
        $tip = 'Referido por: '.$m['referido_por_texto'];
    } elseif (!empty($m['fuente'])) {
        $texto = h($m['fuente']);
        $tip = 'Origen: '.$m['fuente'];
    }
    if ($texto === null) return '';
    return '<span title="'.h($tip).'" style="background:'.$bg.';color:'.$col.';border:1px solid '.$bc.';border-radius:4px;padding:1px 6px;font-size:7px;font-weight:900;margin-left:4px;white-space:nowrap">'.$texto.'</span>';
}

$P1='#1B4A6B'; $P2='#2876A8'; $MU='#7A90A4'; $TX='#1B3A5C';
$m_nombre_completo = trim($m['nombre'].' '.($m['middle_name']??''));
?><tr class="member-row" data-id="<?=$m['id']?>" data-estado="<?=h($m['estado'])?>" data-fecha="<?=h($m['fecha_efectiva'])?>" data-subestado="<?=h($m['subestado']??'')?>" data-mes="<?=h(substr($m['fecha_efectiva']??'',0,7))?>" data-agente="<?=h($m['agente_id'])?>" data-campana-origen="<?=h($m['campana_origen_id']??'')?>" data-search="<?=h(strtolower($m['apellido'].' '.$m_nombre_completo.' '.$m['telefono'].' '.$m['mbi'].' '.$m['carrier'].' '.$m['zip'].' '.($m['direccion_calle']??'').' '.($m['ciudad']??'')))?>" style="cursor:pointer" onclick="openProfile(<?=$m['id']?>)">
<td><div style="display:flex;gap:7px;align-items:center"><?=_mr_av(h($m['agente_ini']??'?'),h($m['agente_color']??$P2),24)?><div><div style="font-weight:900;font-size:10px;color:<?=$P1?>"><?=h($m['apellido'].', '.$m_nombre_completo)?><?=(!empty($m['has_soa'])&&$m['has_soa']==0)?'<span style="color:#B83232;font-size:9px" title="SOA PENDIENTE"> </span>':''?><?=(!empty($m['sales_allegation']))?'<span style="background:#B83232;color:#fff;border-radius:4px;padding:1px 5px;font-size:7px;font-weight:900;margin-left:4px" title="SALES ALLEGATION">⚠ ALLEG.</span>':''?><?=(($m['subestado']??'')==='DECEASED')?'<span style="background:#3A3A3A;color:#fff;border-radius:4px;padding:1px 5px;font-size:7px;font-weight:900;margin-left:4px" title="FALLECIDO/A">🕊 FALLECIDO</span>':''?><?=_mr_origen_badge($m,$origen_campana,$origen_referente)?></div><div style="font-size:8px;color:<?=$MU?>"><?=$m['dob']?(date('Y')-date('Y',strtotime($m['dob']))).' AÑOS':''?></div></div></div></td>
<td style="font-size:9px;color:<?=$MU?>"><?=h($m['telefono'])?></td>
<td style="font-size:8px;color:<?=$MU?>"><?=h($m['ciudad'])?></td>
<td><?php if($m['plan']):?><div style="font-size:9px;font-weight:800;color:<?=$TX?>"><?=h($m['plan'])?></div><div style="font-size:8px;color:<?=$P2?>"><?=h($m['carrier'])?></div><?php else:?><span style="color:<?=$MU?>;font-size:8px">—</span><?php endif;?></td>
<td><?=_mr_badge($m['estado'])?><?php if($m['estado']==='IN PROCESS'):?><br><button class="btn btn-gr btn-sm" style="margin-top:4px;font-size:7px;padding:3px 8px" onclick="event.stopPropagation();abrirActivarMiembro(<?=$m['id']?>,'<?=h(addslashes($m['apellido'].', '.$m_nombre_completo))?>')">✓ ACTIVAR</button><?php endif;?></td>
<td style="font-size:8px;color:<?=$MU?>"><?=h($m['mbi']??'—')?></td>
<td><?php if($mtks>0):?><span style="background:#FDF0EE;color:#B83232;border:1px solid #EFA09A;border-radius:20px;padding:2px 7px;font-size:8px;font-weight:900"><?=$mtks?></span><?php else:?>—<?php endif;?></td>
<td onclick="event.stopPropagation()"><button class="btn btn-b btn-sm" onclick="openProfile(<?=$m['id']?>)">◉</button></td>
</tr>
