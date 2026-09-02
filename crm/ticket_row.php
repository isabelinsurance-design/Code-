<?php
/* ═══════════════════════════════════════════════════════════════════
 *  TICKET_ROW.PHP — devuelve el HTML de UN solo <tr> de la tabla de
 *  Tickets (el mismo que ya arma index.php), para poder actualizarlo
 *  después de guardar/cambiar estado sin recargar TODA la página.
 *  ─────────────────────────────────────────────────────────────────
 *  GET id=<ticket_id>. Requiere sesión iniciada. Un agente (no admin)
 *  solo puede refrescar tickets asignados a él (o sin asignar, creados
 *  por él) — el mismo criterio que usa el listado principal.
 * ═══════════════════════════════════════════════════════════════════ */
require_once 'session_boot.php';
require_once 'config.php';
require_once 'lib_row_render.php';
$user = auth();
if (empty($user)) { http_response_code(403); exit; }
$admin = isAdmin();
$uid   = $user['id'];

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit; }

$pdo = db();
$tkt_select = "SELECT t.*,
                      u.nombre   as agente_nombre,    u.color   as agente_color,    u.iniciales   as agente_ini,
                      a.nombre   as asignado_nombre,  a.color   as asignado_color,  a.iniciales   as asignado_ini,
                      TRIM(CONCAT(COALESCE(m.nombre,''),' ',COALESCE(m.apellido,''))) as miembro_nombre,
                      m.telefono as miembro_telefono, m.estado  as miembro_estado
               FROM tickets t
               LEFT JOIN usuarios u ON t.agente_id  = u.id
               LEFT JOIN usuarios a ON t.asignado_a = a.id
               LEFT JOIN miembros m ON t.miembro_id = m.id
               WHERE t.id=?";
$stm = $pdo->prepare($tkt_select);
$stm->execute([$id]);
$t = $stm->fetch();
if (!$t) { http_response_code(404); exit; }
if (!$admin && $t['asignado_a'] != $uid && !(empty($t['asignado_a']) && $t['agente_id'] == $uid)) {
    http_response_code(403); exit;
}

$ns_list = [];
try {
    $ns_st = $pdo->prepare("SELECT ns.*, u.nombre as agente_nombre, u.iniciales as agente_ini, u.color as agente_color
                            FROM ticket_next_steps ns
                            LEFT JOIN usuarios u ON ns.agente_id = u.id
                            WHERE ns.ticket_id=?
                            ORDER BY ns.completado ASC,
                                     CASE WHEN ns.fecha_programada IS NULL THEN 1 ELSE 0 END,
                                     ns.fecha_programada ASC, ns.id ASC");
    $ns_st->execute([$id]);
    $ns_list = $ns_st->fetchAll();
} catch (Exception $e) { /* tabla aún no existe */ }

$P1='#1B4A6B'; $P2='#2876A8'; $BG='#EBF4F9'; $CB='#C8DFF0'; $MU='#7A90A4'; $TX='#1B3A5C';

$TIPO_MIEMBRO  = ['FOLLOW UP','QUEJA','CAMBIO DE DOCTOR','CLIENTE','CITA','APLICACION',
                  'SERVICIO AL CLIENTE','LLAMADA','LLAMADA PERDIDA','CITA DENTAL','URGENTE'];
$TIPO_PROBLEMA = ['PROBLEMA'];

$sla_vence  = $t['sla_fecha'] ?? null;
$_no_vence  = in_array($t['estado'], ['CERRADO','EN PROCESO'], true);
$sla_alert  = $sla_vence && $sla_vence <= date('Y-m-d', strtotime('+1 day')) && !$_no_vence;
$resp_id    = !empty($t['asignado_a']) ? $t['asignado_a'] : $t['agente_id'];
$is_closed  = $t['estado']==='CERRADO';
$prio       = $t['prioridad'] ?? 'MEDIA';
$left_color = ['ALTA'=>'#B83232','MEDIA'=>'#C07A1A','BAJA'=>'#2876A8'][$prio] ?? '#2876A8';

$cli = trim($t['miembro_nombre'] ?? '');
if ($cli === '') $cli = trim($t['cliente'] ?? '');
if ($cli === '') $cli = trim($t['nombre_referencia'] ?? '');
if ($cli === '') $cli = '—';
$display_name = h(mb_substr($cli, 0, 28));

if (!empty($t['asignado_nombre'])) {
    $resp_nombre = $t['asignado_nombre'];
    $resp_ini    = $t['asignado_ini']   ?? '?';
    $resp_color  = $t['asignado_color'] ?? $P2;
} else {
    $resp_nombre = $t['agente_nombre'] ?? null;
    $resp_ini    = $t['agente_ini']    ?? '?';
    $resp_color  = $t['agente_color']  ?? $P2;
}
?><tr class="ticket-row<?=$is_closed?' tkt-cerrada':''?>"
    data-id="<?=(int)$t['id']?>"
    style="border-left:3px solid <?=$left_color?>;<?=$is_closed?'opacity:.6':''?>"
    data-vista="<?=in_array($t['tipo'],$TIPO_MIEMBRO,true)?'miembro':(in_array($t['tipo'],$TIPO_PROBLEMA,true)?'problema':'tarea')?>"
    data-prio="<?=h($prio)?>"
    data-estado="<?=h($t['estado']??'')?>"
    data-tipo="<?=h($t['tipo']??'')?>"
    data-resp="<?=h($resp_id??'')?>"
    data-fecha="<?=h($t['fecha_creacion']??'')?>"
    data-sla="<?=h($sla_vence??'')?>"
    data-search="<?=strtolower(h(implode(' ',[$t['miembro_nombre']??'',$t['cliente']??'',$t['descripcion']??'',$t['tipo']??'',$t['fuente']??'',$t['resultado']??'',$t['nombre_referencia']??''])))?>">

  <td style="padding:0;width:4px;background:<?=$left_color?>"></td>

  <td style="padding:10px 14px;white-space:nowrap">
    <div style="font-size:10px;font-weight:900;color:<?=$P1?>;<?=!empty($t['miembro_id'])?'cursor:pointer':''?>"
         <?php if(!empty($t['miembro_id'])):?>onclick="openProfile(<?=$t['miembro_id']?>)"<?php endif;?>><?=$display_name?></div>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:1px">#<?=$t['id']?><?=$t['fuente']?' · '.h(mb_substr($t['fuente'],0,12)):'';?></div>
  </td>

  <td style="padding:10px 14px;max-width:280px">
    <div style="font-size:10px;color:<?=$TX?>;line-height:1.4"><?=h(mb_substr($t['descripcion']??'',0,90))?><?=mb_strlen($t['descripcion']??'')>90?'…':''?></div>
    <?php if(!empty($t['notas'])):?>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:3px">💬 <?=h(mb_substr($t['notas'],0,55))?><?=mb_strlen($t['notas'])>55?'…':''?></div>
    <?php endif;?>
    <?php
      $ns_pend  = array_values(array_filter($ns_list, fn($n)=>!$n['completado']));
      $ns_total_pend = count($ns_pend);
      if ($ns_total_pend > 0):
        $ns_proximo = $ns_pend[0];
        $ns_vencido = !empty($ns_proximo['fecha_programada']) && $ns_proximo['fecha_programada'] < date('Y-m-d') && !$_no_vence;
    ?>
    <div style="margin-top:4px;background:<?=$ns_vencido?'#FDF0EE':$BG?>;border:1px solid <?=$ns_vencido?'#EFA09A':$CB?>;border-radius:7px;padding:4px 7px;display:flex;align-items:center;gap:6px">
      <span style="font-size:9px;color:<?=$ns_vencido?'#B83232':$P2?>;font-weight:900">→</span>
      <div style="flex:1;min-width:0">
        <div style="font-size:8px;font-weight:800;color:<?=$ns_vencido?'#B83232':$TX?>;line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h(mb_substr($ns_proximo['descripcion'],0,60))?></div>
        <?php if(!empty($ns_proximo['fecha_programada'])):?>
        <div style="font-size:7px;color:<?=$ns_vencido?'#B83232':$MU?>;font-weight:700">
          <?=$ns_vencido?'⚠ VENCIDO ':'📅 '?><?=date('m/d/Y',strtotime($ns_proximo['fecha_programada']))?>
          <?php if($ns_total_pend>1):?> · +<?=$ns_total_pend-1?> más<?php endif;?>
        </div>
        <?php elseif($ns_total_pend>1):?>
        <div style="font-size:7px;color:<?=$MU?>;font-weight:700">+<?=$ns_total_pend-1?> más pendientes</div>
        <?php endif;?>
      </div>
    </div>
    <?php endif;?>
    <?php if($sla_alert):?><div style="margin-top:3px"><span style="background:#FDF0EE;color:#B83232;border:1px solid #EFA09A;border-radius:20px;padding:1px 6px;font-size:7px;font-weight:900">⚠ SLA VENCIDO</span></div><?php endif;?>
  </td>

  <td style="padding:10px 14px;white-space:nowrap">
    <span style="font-size:8px;color:<?=$MU?>;font-weight:700"><?=h($t['tipo']??'OTRO')?></span>
  </td>

  <td style="padding:10px 14px"><?=row_badge($prio,true)?></td>

  <td style="padding:10px 14px"><?=row_badge($t['estado']??'ABIERTO',true)?></td>

  <td style="padding:10px 14px;white-space:nowrap">
    <?php if($resp_nombre): ?>
    <div style="display:flex;gap:5px;align-items:center">
      <?=row_av(h($resp_ini),h($resp_color),20)?>
      <span style="font-size:9px;font-weight:900;color:<?=$P1?>"><?=h(explode(' ',$resp_nombre)[0])?></span>
    </div>
    <?php else:?><span style="font-size:8px;color:<?=$MU?>">—</span><?php endif;?>
  </td>

    <td style="padding:10px 14px;white-space:nowrap;font-size:9px">
        <?php if($sla_vence && !$is_closed):?>
          <div style="font-weight:900;<?=$sla_alert?'color:#B83232':'color:'.$MU?>"><?=date('m/d/Y',strtotime($sla_vence))?></div>
        <?php elseif($t['fecha_seguimiento']??null):?>
          <div style="color:<?=$MU?>"><?=date('m/d/Y',strtotime($t['fecha_seguimiento']))?></div>
        <?php else:?><span style="color:<?=$MU?>">—</span><?php endif;?>
      </td>

    <td style="padding:10px 14px;white-space:nowrap;font-size:9px;color:<?=$MU?>">
        <?=!empty($t['fecha_creacion']) ? date('m/d/Y',strtotime($t['fecha_creacion'])) : '—'?>
      </td>

  <td style="padding:10px 14px;white-space:nowrap">
    <?php if(!$is_closed):?>
    <div style="display:flex;gap:3px">
      <button class="btn btn-gh btn-sm" onclick="updateTicket(<?=$t['id']?>)" title="Editar" style="padding:5px 10px">✎</button>
      <button class="btn btn-bl btn-sm" onclick="quickTktStatus(<?=$t['id']?>,'EN PROCESO')" title="En Proceso" style="padding:5px 10px">▶</button>
      <button class="btn btn-gr btn-sm" onclick="closeTicket(<?=$t['id']?>)" title="Cerrar" style="padding:5px 10px">✓</button>
    </div>
    <?php else:?>
    <div style="display:flex;gap:3px;align-items:center">
      <span style="font-size:8px;color:#1E7A5C;font-weight:900">✓</span>
      <button class="btn btn-gh btn-sm" onclick="verTicketCerrado(<?=$t['id']?>)" title="Ver detalle" style="padding:5px 10px;font-size:9px">👁 VER</button>
      <?php if($admin):?>
      <button class="btn btn-sky btn-sm" onclick="updateTicket(<?=$t['id']?>)" title="Reabrir/Editar" style="padding:5px 10px;font-size:9px">✎</button>
      <?php endif;?>
    </div>
    <?php endif;?>
  </td>
</tr>
