<?php
/* ═══════════════════════════════════════════════════════════════════
 *  LIB_ROW_RENDER.PHP — piezas chiquitas de HTML (avatar, badge de
 *  estado) compartidas por los endpoints de "una sola fila" (ej.
 *  member_row.php, ticket_row.php) que refrescan un renglón de una
 *  tabla sin recargar todo el CRM. Duplica a propósito badge()/av() de
 *  index.php en vez de incluir ese archivo completo — así estos
 *  endpoints se quedan chiquitos y rápidos.
 * ═══════════════════════════════════════════════════════════════════ */
function row_badge(?string $s, bool $sm = false): string {
    $s = $s ?? '';
    $map = [
        'ACTIVE'=>['#1E7A5C','#EAF5F0','#8DCFBA'],'IN PROCESS'=>['#1B5E8C','#EBF5FB','#A9D0E8'],
        'PLAN CHANGE'=>['#5B3FAF','#F3F0FB','#C2B0E8'],'SIN HACER'=>['#C07A1A','#FEF8EE','#F5D5A0'],
        'SIN FIRMAR'=>['#C05C1A','#FEF2EB','#F5C4A0'],'CANCELED'=>['#B83232','#FDF0EE','#EFA09A'],
        'DENIED'=>['#B83232','#FDF0EE','#EFA09A'],'DISENROLLED'=>['#993C1D','#FAECE7','#F0997B'],
        'PROSPECT'=>['#1E7A8C','#EAF4F6','#8DC8D0'],'ABIERTO'=>['#B83232','#FDF0EE','#EFA09A'],
        'PENDIENTE'=>['#1B5E8C','#EBF5FB','#A9D0E8'],'EN PROCESO'=>['#C07A1A','#FEF8EE','#F5D5A0'],
        'CERRADO'=>['#1E7A5C','#EAF5F0','#8DCFBA'],'ALTA'=>['#B83232','#FDF0EE','#EFA09A'],
        'MEDIA'=>['#C07A1A','#FEF8EE','#F5D5A0'],'BAJA'=>['#1E7A8C','#EAF4F6','#8DC8D0'],
    ];
    $c = $map[$s] ?? ['#7A90A4','#F4F8FC','#C8DFF0'];
    $p = $sm ? '2px 8px' : '3px 10px'; $f = $sm ? '9px' : '10px';
    return "<span style=\"padding:$p;border-radius:20px;font-size:$f;font-weight:800;background:{$c[1]};color:{$c[0]};border:1px solid {$c[2]};white-space:nowrap;letter-spacing:.5px;text-transform:uppercase\">".h($s)."</span>";
}
function row_av(string $i, string $c, int $z = 24): string {
    return "<div style=\"width:{$z}px;height:{$z}px;border-radius:50%;background:$c;display:flex;align-items:center;justify-content:center;font-size:".round($z*.32)."px;font-weight:900;color:#fff;flex-shrink:0;font-family:'DM Sans',sans-serif\">$i</div>";
}

const TICKET_TIPO_MIEMBRO  = ['FOLLOW UP','QUEJA','CAMBIO DE DOCTOR','CLIENTE','CITA','APLICACION',
                  'SERVICIO AL CLIENTE','LLAMADA','LLAMADA PERDIDA','CITA DENTAL','URGENTE'];
const TICKET_TIPO_PROBLEMA = ['PROBLEMA'];

/* La consulta base de un ticket (con los joins de agente/asignado/miembro)
 * — la usan tanto render_ticket_row_html (un solo id) como
 * render_tickets_table_html (todos los tickets visibles de una vez). */
function ticket_row_select(): string {
    return "SELECT t.*,
                   u.nombre   as agente_nombre,    u.color   as agente_color,    u.iniciales   as agente_ini,
                   a.nombre   as asignado_nombre,  a.color   as asignado_color,  a.iniciales   as asignado_ini,
                   TRIM(CONCAT(COALESCE(m.nombre,''),' ',COALESCE(m.apellido,''))) as miembro_nombre,
                   m.telefono as miembro_telefono, m.estado  as miembro_estado
            FROM tickets t
            LEFT JOIN usuarios u ON t.agente_id  = u.id
            LEFT JOIN usuarios a ON t.asignado_a = a.id
            LEFT JOIN miembros m ON t.miembro_id = m.id";
}

/* Arma el <tr> de UN ticket a partir de datos YA cargados (sin consultar la
 * base de datos) — usado tanto para pintar una sola fila como para pintar la
 * tabla completa (ahí se llama una vez por ticket, con los next steps ya
 * agrupados de antemano, para no repetir una consulta por fila). */
function render_ticket_row_from_data(array $t, array $ns_list, bool $admin): string {
    $P1='#1B4A6B'; $P2='#2876A8'; $BG='#EBF4F9'; $CB='#C8DFF0'; $MU='#7A90A4'; $TX='#1B3A5C';

    $TIPO_MIEMBRO  = TICKET_TIPO_MIEMBRO;
    $TIPO_PROBLEMA = TICKET_TIPO_PROBLEMA;

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
    ob_start();
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
    <?php
    return ob_get_clean();
}

/* Trae UN ticket + sus next steps y arma su <tr> — usado por ticket_row.php
 * (refresco vía fetch aparte) Y directamente por api.php (para devolver la
 * fila ya lista en la MISMA respuesta de guardar/cambiar estado, sin
 * necesitar un segundo viaje al servidor). $uid puede venir como string u
 * int, por eso las comparaciones con != / == en vez de estrictas. Devuelve
 * null si el ticket no existe o si el usuario (no-admin) no tiene permiso. */
function render_ticket_row_html(PDO $pdo, int $id, bool $admin, $uid): ?string {
    $stm = $pdo->prepare(ticket_row_select()." WHERE t.id=?");
    $stm->execute([$id]);
    $t = $stm->fetch();
    if (!$t) return null;
    if (!$admin && $t['asignado_a'] != $uid && !(empty($t['asignado_a']) && $t['agente_id'] == $uid)) {
        return null;
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

    return render_ticket_row_from_data($t, $ns_list, $admin);
}

/* Arma el <tbody> completo de la tabla de Tickets — todos los tickets que
 * ese usuario puede ver (mismo criterio que usaba antes index.php: admin ve
 * todos, agente solo donde es responsable/creador), con los next steps de
 * TODOS esos tickets pre-cargados en una sola consulta (WHERE...IN) en vez
 * de una consulta por ticket. Se pide aparte (al abrir la pestaña Tickets)
 * en vez de venir ya armada en cada carga de la página completa. */
function render_tickets_table_html(PDO $pdo, bool $admin, $uid): string {
    $sql = ticket_row_select();
    if ($admin) {
        $stm = $pdo->query("$sql
            ORDER BY FIELD(t.estado,'ABIERTO','EN PROCESO','PENDIENTE','CERRADO'),
                     IF(t.estado='CERRADO', 0, FIELD(t.prioridad,'ALTA','MEDIA','BAJA')),
                     IF(t.estado='CERRADO', t.fecha_cierre, t.fecha_creacion) DESC, t.id DESC");
        $tickets = $stm->fetchAll();
    } else {
        $stm = $pdo->prepare("$sql
            WHERE t.asignado_a = ? OR (t.asignado_a IS NULL AND t.agente_id = ?)
            ORDER BY FIELD(t.estado,'ABIERTO','EN PROCESO','PENDIENTE','CERRADO'),
                     IF(t.estado='CERRADO', 0, FIELD(t.prioridad,'ALTA','MEDIA','BAJA')),
                     IF(t.estado='CERRADO', t.fecha_cierre, t.fecha_creacion) DESC, t.id DESC");
        $stm->execute([$uid, $uid]);
        $tickets = $stm->fetchAll();
    }

    $ns_por_ticket = [];
    try {
        $ids = array_column($tickets, 'id');
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $ns_st = $pdo->prepare("SELECT ns.*, u.nombre as agente_nombre, u.iniciales as agente_ini, u.color as agente_color
                                    FROM ticket_next_steps ns
                                    LEFT JOIN usuarios u ON ns.agente_id = u.id
                                    WHERE ns.ticket_id IN ($ph)
                                    ORDER BY ns.completado ASC,
                                             CASE WHEN ns.fecha_programada IS NULL THEN 1 ELSE 0 END,
                                             ns.fecha_programada ASC, ns.id ASC");
            $ns_st->execute($ids);
            foreach ($ns_st->fetchAll() as $ns) { $ns_por_ticket[$ns['ticket_id']][] = $ns; }
        }
    } catch (Exception $e) { /* tabla aún no existe */ }

    $html = '';
    foreach ($tickets as $t) {
        $html .= render_ticket_row_from_data($t, $ns_por_ticket[$t['id']] ?? [], $admin);
    }
    return $html;
}
