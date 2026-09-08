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

/* Badge de origen de un miembro (campaña / referido / fuente) — texto
 * siempre visible, no un icono con tooltip. $camps_by_id: [campana_id =>
 * nombre]; $names_by_id: [miembro_id => "nombre apellido"] (para resolver
 * "referido por" otro miembro ya cargado). Misma lógica que origen_badge_html()
 * en index.php, para poder pintar la tabla completa de Miembros sin
 * consultar la campaña/el referente UNO POR UNO. */
function row_origen_badge(array $m, array $camps_by_id, array $names_by_id): string {
    $bg = '#F1EFE8'; $col = '#7A90A4'; $bc = '#D8D4C8'; $texto = null; $tip = '';
    if (!empty($m['campana_origen_id']) && isset($camps_by_id[$m['campana_origen_id']])) {
        $nombreCamp = $camps_by_id[$m['campana_origen_id']];
        $bg = '#FEF8EE'; $col = '#C07A1A'; $bc = '#F5D5A0';
        $texto = '📣 '.h(mb_strimwidth($nombreCamp, 0, 16, '…'));
        $tip = 'Campaña: '.$nombreCamp;
    } elseif (!empty($m['referido_por_miembro_id']) && isset($names_by_id[$m['referido_por_miembro_id']])) {
        $nombreRef = $names_by_id[$m['referido_por_miembro_id']];
        $bg = '#EAF5F0'; $col = '#1E7A5C'; $bc = '#8DCFBA';
        $texto = '🤝 REF: '.h(mb_strimwidth($nombreRef, 0, 14, '…'));
        $tip = 'Referido por: '.$nombreRef;
    } elseif (!empty($m['referido_por_texto'])) {
        $bg = '#EAF5F0'; $col = '#1E7A5C'; $bc = '#8DCFBA';
        $texto = '🤝 REF: '.h(mb_strimwidth($m['referido_por_texto'], 0, 14, '…'));
        $tip = 'Referido por: '.$m['referido_por_texto'];
    } elseif (!empty($m['fuente'])) {
        $texto = h($m['fuente']);
        $tip = 'Origen: '.$m['fuente'];
    }
    if ($texto === null) return '';
    return '<span title="'.h($tip).'" style="background:'.$bg.';color:'.$col.';border:1px solid '.$bc.';border-radius:4px;padding:1px 6px;font-size:7px;font-weight:900;margin-left:4px;white-space:nowrap">'.$texto.'</span>';
}

/* Arma el <tr> de UN miembro a partir de datos YA cargados — usado por
 * render_members_table_html para pintar la tabla completa sin repetir
 * consultas por fila (los mapas de origen/tickets ya vienen precargados). */
function render_member_row_from_data(array $m, int $mtks, array $camps_by_id, array $names_by_id): string {
    $P1='#1B4A6B'; $P2='#2876A8'; $MU='#7A90A4'; $TX='#1B3A5C';
    $m_nombre_completo = trim($m['nombre'].' '.($m['middle_name']??''));
    ob_start();
    ?><tr class="member-row" data-id="<?=$m['id']?>" data-estado="<?=h($m['estado'])?>" data-fecha="<?=h($m['fecha_efectiva'])?>" data-subestado="<?=h($m['subestado']??'')?>" data-mes="<?=h(substr($m['fecha_efectiva']??'',0,7))?>" data-agente="<?=h($m['agente_id'])?>" data-campana-origen="<?=h($m['campana_origen_id']??'')?>" data-search="<?=h(strtolower($m['apellido'].' '.$m_nombre_completo.' '.$m['telefono'].' '.$m['mbi'].' '.$m['carrier'].' '.$m['zip'].' '.($m['direccion_calle']??'').' '.($m['ciudad']??'')))?>" style="cursor:pointer" onclick="openProfile(<?=$m['id']?>)">
<td><div style="display:flex;gap:7px;align-items:center"><?=row_av(h($m['agente_ini']??'?'),h($m['agente_color']??$P2),24)?><div><div style="font-weight:900;font-size:10px;color:<?=$P1?>"><?=h($m['apellido'].', '.$m_nombre_completo)?><?=(!empty($m['has_soa'])&&$m['has_soa']==0)?'<span style="color:#B83232;font-size:9px" title="SOA PENDIENTE"> </span>':''?><?=(!empty($m['sales_allegation']))?'<span style="background:#B83232;color:#fff;border-radius:4px;padding:1px 5px;font-size:7px;font-weight:900;margin-left:4px" title="SALES ALLEGATION">⚠ ALLEG.</span>':''?><?=(($m['subestado']??'')==='DECEASED')?'<span style="background:#3A3A3A;color:#fff;border-radius:4px;padding:1px 5px;font-size:7px;font-weight:900;margin-left:4px" title="FALLECIDO/A">🕊 FALLECIDO</span>':''?><?=row_origen_badge($m,$camps_by_id,$names_by_id)?></div><div style="font-size:8px;color:<?=$MU?>"><?=$m['dob']?(date('Y')-date('Y',strtotime($m['dob']))).' AÑOS':''?></div></div></div></td>
<td style="font-size:9px;color:<?=$MU?>"><?=h($m['telefono'])?></td>
<td style="font-size:8px;color:<?=$MU?>"><?=h($m['ciudad'])?></td>
<td><?php if($m['plan']):?><div style="font-size:9px;font-weight:800;color:<?=$TX?>"><?=h($m['plan'])?></div><div style="font-size:8px;color:<?=$P2?>"><?=h($m['carrier'])?></div><?php else:?><span style="color:<?=$MU?>;font-size:8px">—</span><?php endif;?></td>
<td><?=row_badge($m['estado'])?><?php if($m['estado']==='IN PROCESS'):?><br><button class="btn btn-gr btn-sm" style="margin-top:4px;font-size:7px;padding:3px 8px" onclick="event.stopPropagation();abrirActivarMiembro(<?=$m['id']?>,'<?=h(addslashes($m['apellido'].', '.$m_nombre_completo))?>')">✓ ACTIVAR</button><?php endif;?></td>
<td style="font-size:8px;color:<?=$MU?>"><?=h($m['mbi']??'—')?></td>
<td><?php if($mtks>0):?><span style="background:#FDF0EE;color:#B83232;border:1px solid #EFA09A;border-radius:20px;padding:2px 7px;font-size:8px;font-weight:900"><?=$mtks?></span><?php else:?>—<?php endif;?></td>
<td onclick="event.stopPropagation()"><button class="btn btn-b btn-sm" onclick="openProfile(<?=$m['id']?>)">◉</button></td>
</tr>
    <?php
    return ob_get_clean();
}

/* Arma el <tbody> completo de la tabla de Miembros — se pide aparte (al
 * abrir la pestaña Miembros) en vez de venir ya armada en cada carga de la
 * página completa. Mismo criterio/orden que usaba antes index.php. */
function render_members_table_html(PDO $pdo): string {
    $members = $pdo->query("SELECT m.*,u.nombre as agente_nombre,u.color as agente_color,u.iniciales as agente_ini,
        (SELECT COUNT(*) FROM soa WHERE miembro_id=m.id AND estado='FIRMADO') as has_soa
        FROM miembros m LEFT JOIN usuarios u ON m.agente_id=u.id ORDER BY m.apellido,m.nombre")->fetchAll();

    $camps_by_id = [];
    try { foreach ($pdo->query("SELECT id,nombre FROM campanas") as $c) { $camps_by_id[$c['id']] = $c['nombre']; } } catch (Exception $e) {}
    $names_by_id = [];
    foreach ($members as $m) { $names_by_id[$m['id']] = trim($m['nombre'].' '.$m['apellido']); }

    // Tickets abiertos por miembro — una sola consulta agrupada en vez de
    // una por miembro.
    $tks_por_miembro = [];
    try {
        foreach ($pdo->query("SELECT miembro_id, COUNT(*) as n FROM tickets WHERE estado!='CERRADO' AND miembro_id IS NOT NULL GROUP BY miembro_id") as $r) {
            $tks_por_miembro[$r['miembro_id']] = (int)$r['n'];
        }
    } catch (Exception $e) {}

    $html = '';
    foreach ($members as $m) {
        $html .= render_member_row_from_data($m, $tks_por_miembro[$m['id']] ?? 0, $camps_by_id, $names_by_id);
    }
    return $html;
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

/* Convierte "YYYY-MM" a "Mes YYYY" en español, sin depender de strftime/
 * locale del servidor (varía entre hostings). Usada por el agrupado de
 * citas completadas por mes. */
function strftime_es(string $ym): string {
    $meses = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    [$y,$m] = array_map('intval', explode('-', $ym));
    return ($meses[$m] ?? '?').' '.$y;
}

/* Arma las 6 sub-pestañas de CITAS (con las tarjetas de cada cita) más los
 * conteos para los KPIs y los números de cada sub-pestaña. Antes esto se
 * armaba completo en CADA carga de index.php/softReload — con cientos de
 * citas eso es mucho HTML repetido aunque el usuario nunca haya abierto esa
 * pestaña. Ahora se pide aparte (al abrir CITAS, o después de guardar/
 * completar/cancelar/reagendar una cita), igual que ya hacen
 * render_tickets_table_html/render_members_table_html. El KPI "HOY/MAÑANA/
 * 7 DÍAS" en sí (los números chiquitos de arriba) sigue viniendo con la
 * página normal porque es barato de calcular — lo caro es la lista de
 * tarjetas, que es lo que aquí se difiere. */
function render_citas_panel(PDO $pdo): array {
    $P1='#1B4A6B';$P2='#2876A8';$BG='#EBF4F9';$CB='#C8DFF0';$MU='#7A90A4';

    $citas = $pdo->query("SELECT c.*,
                           u.nombre as agente_nombre, u.color as agente_color, u.iniciales as agente_ini,
                           cu.nombre as completada_nombre, cu.iniciales as completada_ini,
                           CONCAT(m.apellido,', ',m.nombre) as miembro_nombre,
                           m.telefono as miembro_telefono, m.estado as miembro_estado
                    FROM citas c
                    LEFT JOIN usuarios u  ON c.agente_id      = u.id
                    LEFT JOIN usuarios cu ON c.completada_por = cu.id
                    LEFT JOIN miembros m  ON c.miembro_id     = m.id
                    ORDER BY c.fecha DESC, c.hora ASC")->fetchAll();

    $today_d    = date('Y-m-d');
    $tomorrow_d = date('Y-m-d', strtotime('+1 day'));
    $week_end   = date('Y-m-d', strtotime('+7 days'));

    $citas_view = $citas;
    $citas_pendientes = array_values(array_filter($citas_view, fn($c)=>!in_array($c['estado'], ['COMPLETADA','CANCELADA','REAGENDAR'], true)));
    $citas_completadas= array_values(array_filter($citas_view, fn($c)=>$c['estado']==='COMPLETADA'));
    $citas_canceladas = array_values(array_filter($citas_view, fn($c)=>$c['estado']==='CANCELADA'));
    $citas_reagendar  = array_values(array_filter($citas_view, fn($c)=>$c['estado']==='REAGENDAR'));

    usort($citas_pendientes, fn($a,$b)=>strcmp($a['fecha'].($a['hora']??''), $b['fecha'].($b['hora']??'')));
    usort($citas_completadas, fn($a,$b)=>strcmp($b['fecha'].($b['hora']??''), $a['fecha'].($a['hora']??'')));
    usort($citas_reagendar, fn($a,$b)=>strcmp($b['fecha'].($b['hora']??''), $a['fecha'].($a['hora']??'')));

    $citas_hoy_n       = count(array_filter($citas_pendientes, fn($c)=>$c['fecha']==$today_d));
    $citas_manana_n    = count(array_filter($citas_pendientes, fn($c)=>$c['fecha']==$tomorrow_d));
    $citas_semana_n    = count(array_filter($citas_pendientes, fn($c)=>$c['fecha']>=$today_d && $c['fecha']<=$week_end));
    $citas_atrasadas_n = count(array_filter($citas_pendientes, fn($c)=>$c['fecha']<$today_d));
    $citas_proximas_n  = count(array_filter($citas_pendientes, fn($c)=>$c['fecha']>=$today_d));

    $render_cita = function($c) use ($P1,$P2,$MU,$BG,$CB,$today_d,$tomorrow_d) {
      $is_today    = $c['fecha']==$today_d;
      $is_tomorrow = $c['fecha']==$tomorrow_d;
      $is_past     = $c['fecha']<$today_d && $c['estado']!=='COMPLETADA';
      $is_done     = $c['estado']==='COMPLETADA';
      $is_canceled = $c['estado']==='CANCELADA';
      $is_reagendar= $c['estado']==='REAGENDAR';
      $border_color = $is_canceled ? '#999' : ($is_reagendar ? '#8A5CB8' : ($is_done ? '#1E7A5C' : ($is_past ? '#B83232' : ($is_today ? '#C07A1A' : ($is_tomorrow ? '#2876A8' : $P1)))));
      $cli = trim($c['miembro_nombre']??'');
      if ($cli === ', ' || $cli === '') $cli = trim($c['cliente']??'') ?: '— SIN NOMBRE —';
      $hora_disp = !empty($c['hora']) ? substr($c['hora'],0,5) : '--:--';
      $agente_color = $c['agente_color'] ?? $P2;
      $agente_ini   = $c['agente_ini']   ?? '?';
      ?>
      <div class="cita-card" data-fecha="<?=h($c['fecha'])?>" data-agente="<?=h($c['agente_id'])?>" data-tipo="<?=h($c['tipo']??'')?>" data-modalidad="<?=h($c['modalidad']??'')?>" data-search="<?=strtolower(h(($cli.' '.($c['tipo']??'').' '.($c['modalidad']??'').' '.($c['notas']??''))))?>" style="background:#fff;border:1px solid <?=$CB?>;border-left:4px solid <?=$border_color?>;border-radius:10px;padding:11px 13px;<?=$is_done||$is_canceled?'opacity:.65':''?>">
        <?php if($is_reagendar):?><div style="display:inline-block;background:#F3EBFA;color:#6B3FA0;border:1px solid #D6BCE8;border-radius:20px;padding:2px 9px;font-size:7px;font-weight:900;text-transform:uppercase;margin-bottom:6px">↺ POSIBLE PARA REAGENDAR</div><?php endif;?>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:6px">
          <div style="flex:1;min-width:0">
            <div style="font-size:11px;font-weight:900;color:<?=$P1?>;<?=!empty($c['miembro_id'])?'cursor:pointer':''?>;line-height:1.2"
                 <?php if(!empty($c['miembro_id'])):?>onclick="openProfile(<?=$c['miembro_id']?>)"<?php endif;?>>
              <?=h($cli)?>
            </div>
            <?php if(!empty($c['miembro_telefono'])):?>
            <div style="font-size:8px;color:<?=$MU?>;margin-top:2px">📞 <?=h($c['miembro_telefono'])?></div>
            <?php endif;?>
          </div>
          <div style="text-align:right;white-space:nowrap">
            <div style="font-size:14px;font-weight:900;color:<?=$border_color?>"><?=$hora_disp?></div>
            <div style="font-size:7px;color:<?=$MU?>;font-weight:800;text-transform:uppercase">
              <?php if($is_today):?>HOY · <?php elseif($is_tomorrow):?>MAÑANA · <?php elseif($is_past&&!$is_done):?>ATRASADA · <?php endif;?><?=date('m/d/Y',strtotime($c['fecha']))?>
            </div>
          </div>
        </div>
        <div style="display:flex;gap:5px;flex-wrap:wrap;margin-bottom:7px">
          <span style="background:<?=$BG?>;color:<?=$P1?>;border:1px solid <?=$CB?>;border-radius:9px;padding:2px 7px;font-size:7px;font-weight:900;text-transform:uppercase"><?=h($c['tipo']??'?')?></span>
          <span style="background:<?=$BG?>;color:<?=$P2?>;border:1px solid <?=$CB?>;border-radius:9px;padding:2px 7px;font-size:7px;font-weight:900;text-transform:uppercase">
            <?=['TELÉFONO'=>'📞','VIDEO'=>'📹','EN CASA'=>'🏠','EN RESTAURANTE'=>'🍽️'][$c['modalidad']??'']??'🏢'?> <?=h($c['modalidad']??'?')?>
          </span>
          <span style="display:inline-flex;align-items:center;gap:3px;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:9px;padding:2px 7px;font-size:7px;font-weight:900;text-transform:uppercase;color:<?=$MU?>">
            <span style="display:inline-block;width:11px;height:11px;border-radius:50%;background:<?=h($agente_color)?>;color:#fff;font-size:6px;text-align:center;line-height:11px;font-weight:900"><?=h($agente_ini)?></span>
            <?=h(explode(' ',$c['agente_nombre']??'?')[0])?>
          </span>
        </div>
        <?php if(!empty($c['notas'])):?>
          <div style="background:<?=$BG?>;border-radius:7px;padding:6px 8px;font-size:8px;color:<?=$MU?>;margin-bottom:7px;max-height:30px;overflow:hidden;text-transform:none;line-height:1.35"><?=h(mb_substr($c['notas'],0,120))?><?=mb_strlen($c['notas']??'')>120?'…':''?></div>
        <?php endif;?>
        <div style="display:flex;gap:4px;flex-wrap:wrap">
          <?php if($is_reagendar):?>
            <button class="btn btn-p btn-sm" onclick="editarCita(<?=$c['id']?>, true)" title="Poner nueva fecha/hora" style="flex:1;padding:5px 8px;font-size:8px">📅 REAGENDAR AHORA</button>
          <?php endif;?>
          <?php if(!$is_done && !$is_canceled && !$is_reagendar):?>
            <button class="btn btn-gr btn-sm" onclick="completarCitaOpciones(<?=$c['id']?>)" title="Completar" style="flex:1;padding:5px 8px;font-size:8px">✓ COMPLETAR</button>
          <?php endif;?>
          <button class="btn btn-gh btn-sm" onclick="editarCita(<?=$c['id']?>)" title="Editar" style="padding:5px 8px;font-size:8px">✎</button>
          <button class="btn btn-bl btn-sm" onclick="crearTicketDesdeCita(<?=$c['id']?>)" title="Crear ticket" style="padding:5px 8px;font-size:8px">◈ TICKET</button>
          <?php if(!empty($c['miembro_id'])):?>
            <button class="btn btn-p btn-sm" onclick="openProfile(<?=$c['miembro_id']?>)" title="Ver perfil" style="padding:5px 8px;font-size:8px">◉</button>
          <?php endif;?>
          <?php if(!$is_done && !$is_canceled):?>
            <button class="btn btn-r btn-sm" onclick="cancelarCita(<?=$c['id']?>)" title="Cancelar" style="padding:5px 8px;font-size:8px">✕</button>
          <?php endif;?>
        </div>
        <?php if($is_done && !empty($c['completada_at'])):?>
          <div style="font-size:7px;color:#1E7A5C;font-weight:900;margin-top:5px;text-transform:uppercase">✓ COMPLETADA <?=date('m/d/Y H:i',strtotime($c['completada_at']))?><?=$c['completada_nombre']?' · '.h(explode(' ',$c['completada_nombre'])[0]):''?></div>
        <?php endif;?>
      </div>
      <?php
    };

    $render_grupo = function($titulo, $color, $citas_arr) use ($render_cita) {
      if (!count($citas_arr)) return;
      ?>
      <div class="cita-grupo" style="margin-bottom:18px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;padding:6px 0;border-bottom:2px solid <?=$color?>">
          <span style="font-size:10px;font-weight:900;color:<?=$color?>;text-transform:uppercase;letter-spacing:1px"><?=$titulo?></span>
          <span style="background:<?=$color?>;color:#fff;border-radius:20px;padding:1px 8px;font-size:8px;font-weight:900"><?=count($citas_arr)?></span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:9px">
          <?php foreach($citas_arr as $c) $render_cita($c); ?>
        </div>
      </div>
      <?php
    };

    ob_start();
    ?>
<!-- ─── PENDIENTES ─── -->
<div id="csub-pendientes" class="csub-pane" style="display:none">
  <?php
  $g_atrasadas = []; $g_hoy = []; $g_manana = []; $g_semana = []; $g_futuro = [];
  foreach($citas_pendientes as $c) {
    if      ($c['fecha'] < $today_d)                                  $g_atrasadas[] = $c;
    elseif  ($c['fecha'] == $today_d)                                 $g_hoy[]       = $c;
    elseif  ($c['fecha'] == $tomorrow_d)                              $g_manana[]    = $c;
    elseif  ($c['fecha'] <= $week_end)                                $g_semana[]    = $c;
    else                                                              $g_futuro[]    = $c;
  }
  $render_grupo('⚠ ATRASADAS — REQUIEREN ATENCIÓN', '#B83232', $g_atrasadas);
  if (!count($g_atrasadas)):?>
    <div style="padding:40px;text-align:center;color:<?=$MU?>;background:#fff;border:1px solid <?=$CB?>;border-radius:11px">
      <div style="font-size:32px;margin-bottom:9px">◷</div>
      <div style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1px">SIN CITAS ATRASADAS</div>
      <div style="font-size:8px;color:<?=$MU?>;margin-top:5px">Todo al día. Las de hoy en adelante están en la pestaña PRÓXIMAS.</div>
    </div>
  <?php endif;?>
</div>

<!-- ─── PRÓXIMAS (todas las no completadas, sin ATRASADAS) ─── -->
<div id="csub-proximas" class="csub-pane">
  <?php
  $render_grupo('● HOY · '.date('m/d/Y'),            '#C07A1A', $g_hoy);
  $render_grupo('► MAÑANA · '.date('m/d/Y',strtotime('+1 day')), '#2876A8', $g_manana);
  $render_grupo('ESTA SEMANA',                       $P1, $g_semana);
  $render_grupo('PRÓXIMAS',                          $P2, $g_futuro);
  if (!count($g_hoy)+count($g_manana)+count($g_semana)+count($g_futuro)):?>
    <div style="padding:40px;text-align:center;color:<?=$MU?>;background:#fff;border:1px solid <?=$CB?>;border-radius:11px">
      <div style="font-size:32px;margin-bottom:9px">►</div>
      <div style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1px">SIN CITAS PRÓXIMAS</div>
    </div>
  <?php endif;?>
</div>

<!-- ─── POSIBLE PARA REAGENDAR ─── -->
<div id="csub-reagendar" class="csub-pane" style="display:none">
  <div style="background:#F3EBFA;border:1px solid #D6BCE8;border-radius:9px;padding:9px 12px;font-size:9px;color:#6B3FA0;margin-bottom:11px">↺ Recordatorio para volver a llamar y agendar una nueva cita — la mayoría son prospectos.</div>
  <?php if(!count($citas_reagendar)):?>
    <div style="padding:40px;text-align:center;color:<?=$MU?>;background:#fff;border:1px solid <?=$CB?>;border-radius:11px">
      <div style="font-size:32px;margin-bottom:9px">↺</div>
      <div style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1px">SIN CITAS POR AHORA</div>
    </div>
  <?php else:?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:9px">
    <?php foreach($citas_reagendar as $c) $render_cita($c); ?>
  </div>
  <?php endif;?>
</div>

<!-- ─── COMPLETADAS ─── -->
<div id="csub-completadas" class="csub-pane" style="display:none">
  <?php
  $por_mes = [];
  foreach($citas_completadas as $c) {
    $mes = date('Y-m', strtotime($c['fecha']));
    $por_mes[$mes][] = $c;
  }
  foreach($por_mes as $mes=>$arr) {
    $titulo = strtoupper(strftime_es($mes));
    $render_grupo($titulo, '#1E7A5C', $arr);
  }
  if (!count($citas_completadas)):?>
    <div style="padding:40px;text-align:center;color:<?=$MU?>;background:#fff;border:1px solid <?=$CB?>;border-radius:11px;font-size:10px;font-weight:900;text-transform:uppercase">SIN CITAS COMPLETADAS AÚN</div>
  <?php endif;?>
</div>

<!-- ─── CANCELADAS ─── -->
<div id="csub-canceladas" class="csub-pane" style="display:none">
  <?php if(!count($citas_canceladas)):?>
    <div style="padding:40px;text-align:center;color:<?=$MU?>;background:#fff;border:1px solid <?=$CB?>;border-radius:11px;font-size:10px;font-weight:900;text-transform:uppercase">SIN CITAS CANCELADAS</div>
  <?php else:?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:9px">
    <?php foreach($citas_canceladas as $c) $render_cita($c); ?>
  </div>
  <?php endif;?>
</div>

<!-- ─── TODAS (sin importar el estado) ─── -->
<div id="csub-todas" class="csub-pane" style="display:none">
  <?php
  $citas_todas = $citas_view;
  usort($citas_todas, fn($a,$b)=>strcmp($b['fecha'].($b['hora']??''), $a['fecha'].($a['hora']??'')));
  ?>
  <?php if(!count($citas_todas)):?>
    <div style="padding:40px;text-align:center;color:<?=$MU?>;background:#fff;border:1px solid <?=$CB?>;border-radius:11px;font-size:10px;font-weight:900;text-transform:uppercase">SIN CITAS TODAVÍA</div>
  <?php else:?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:9px">
    <?php foreach($citas_todas as $c) $render_cita($c); ?>
  </div>
  <?php endif;?>
</div>
    <?php
    $html = ob_get_clean();

    return [
        'html' => $html,
        'kpis' => [
            'hoy' => $citas_hoy_n, 'manana' => $citas_manana_n, 'semana' => $citas_semana_n, 'atrasadas' => $citas_atrasadas_n,
        ],
        'counts' => [
            'proximas' => $citas_proximas_n, 'reagendar' => count($citas_reagendar), 'pendientes' => $citas_atrasadas_n,
            'completadas' => count($citas_completadas), 'canceladas' => count($citas_canceladas), 'todas' => count($citas_view),
        ],
    ];
}
