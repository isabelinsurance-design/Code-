<?php
/* ═══════════════════════════════════════════════════════════════════
 *  LIB_FOLLOWUPS.PHP — FOLLOW UPS: recordatorios de seguimiento que se
 *  pueden crear desde Tickets, Campañas, Listas de Evento, Citas o el
 *  perfil de un miembro (pedido de Isabel: "como de tickets se puedan
 *  completar pero también agregar un follow up... que se muestre solo
 *  lo del día... también follow ups automáticos días después según lo
 *  que se ponga en la respuesta, tipo lo que hace Salesforce").
 *
 *  Una sola tabla genérica (follow_ups) en vez de una tabla por sección
 *  — origen_tipo/origen_id dicen de dónde salió (solo para mostrarlo,
 *  no hace falta para nada más), y así todos aparecen juntos en una
 *  sola vista "FOLLOW UPS" que se puede filtrar por HOY/ATRASADOS/
 *  PRÓXIMOS. Al completar uno, se puede encadenar el siguiente
 *  automáticamente X días después (igual que Salesforce).
 * ═══════════════════════════════════════════════════════════════════ */

const FOLLOWUP_ORIGENES = [
    'TICKET'  => ['◈', 'Ticket'],
    'CAMPANA' => ['📣', 'Campaña'],
    'LISTA'   => ['📋', 'Lista'],
    'CITA'    => ['◷', 'Cita'],
    'MIEMBRO' => ['◉', 'Perfil'],
    'MANUAL'  => ['✎', 'Manual'],
];

function asegurarTablaFollowUps(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS follow_ups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            miembro_id INT DEFAULT NULL,
            nombre_libre VARCHAR(150) DEFAULT NULL,
            telefono_libre VARCHAR(30) DEFAULT NULL,
            origen_tipo VARCHAR(20) NOT NULL DEFAULT 'MANUAL',
            origen_id INT DEFAULT NULL,
            campana_id INT DEFAULT NULL,
            titulo VARCHAR(255) NOT NULL,
            notas TEXT,
            fecha DATE NOT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
            agente_id INT DEFAULT NULL,
            creado_por INT DEFAULT NULL,
            completado_por INT DEFAULT NULL,
            completado_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fu_fecha (fecha),
            INDEX idx_fu_estado (estado),
            INDEX idx_fu_miembro (miembro_id),
            INDEX idx_fu_agente (agente_id)
        )");
    } catch (Exception $e) {}
}

/* "Hoy + N días" en formato YYYY-MM-DD — usado tanto para crear un follow
 * up con el botón rápido "+7 DÍAS" como para encadenar el siguiente al
 * completar uno (el equivalente a un "follow-up task" automático de
 * Salesforce). $dias puede ser 0 (hoy) o negativo si algún día hiciera
 * falta (no se usa por ahora, pero no hay razón para prohibirlo aquí). */
function followup_fecha_mas_dias(int $dias): string {
    return date('Y-m-d', strtotime("+{$dias} days"));
}

/* Arma las sub-pestañas de FOLLOW UPS (tarjetas + KPIs + conteos), igual
 * de "pedido aparte vía AJAX al abrir la pestaña" que render_citas_panel
 * — así no se recalcula esto en cada carga de index.php aunque nadie haya
 * abierto FOLLOW UPS. */
function render_followups_panel(PDO $pdo): array {
    asegurarTablaFollowUps($pdo);
    $P1='#1B4A6B'; $P2='#2876A8'; $BG='#EBF4F9'; $CB='#C8DFF0'; $MU='#7A90A4'; $TX='#1B3A5C'; $G='#1E7A5C'; $R='#B83232'; $A='#C07A1A';

    $fu = $pdo->query("SELECT f.*,
                        CONCAT(m.apellido,', ',m.nombre) as miembro_nombre, m.telefono as miembro_telefono,
                        u.nombre as agente_nombre, u.color as agente_color, u.iniciales as agente_ini,
                        cu.nombre as completado_nombre, cu.iniciales as completado_ini,
                        c.nombre as campana_nombre
                        FROM follow_ups f
                        LEFT JOIN miembros m  ON f.miembro_id     = m.id
                        LEFT JOIN usuarios u  ON f.agente_id      = u.id
                        LEFT JOIN usuarios cu ON f.completado_por = cu.id
                        LEFT JOIN campanas c  ON f.campana_id     = c.id
                        ORDER BY f.fecha ASC, f.id ASC")->fetchAll();

    $today_d  = date('Y-m-d');
    $week_end = date('Y-m-d', strtotime('+7 days'));

    $fu_pendientes = array_values(array_filter($fu, fn($f)=>$f['estado']==='PENDIENTE'));
    $fu_completados= array_values(array_filter($fu, fn($f)=>$f['estado']==='COMPLETADO'));
    $fu_cancelados = array_values(array_filter($fu, fn($f)=>$f['estado']==='CANCELADO'));

    $fu_atrasados = array_values(array_filter($fu_pendientes, fn($f)=>$f['fecha']<$today_d));
    $fu_hoy       = array_values(array_filter($fu_pendientes, fn($f)=>$f['fecha']==$today_d));
    $fu_proximos  = array_values(array_filter($fu_pendientes, fn($f)=>$f['fecha']>$today_d));

    usort($fu_completados, fn($a,$b)=>strcmp($b['fecha'], $a['fecha']));
    usort($fu_cancelados,  fn($a,$b)=>strcmp($b['fecha'], $a['fecha']));

    $fu_hoy_n       = count($fu_hoy);
    $fu_atrasados_n = count($fu_atrasados);
    $fu_semana_n    = count(array_filter($fu_pendientes, fn($f)=>$f['fecha']>=$today_d && $f['fecha']<=$week_end));

    $render_fu = function($f) use ($P1,$P2,$MU,$BG,$CB,$TX,$G,$R,$A,$today_d) {
      $is_pendiente = $f['estado']==='PENDIENTE';
      $is_done      = $f['estado']==='COMPLETADO';
      $is_cancel    = $f['estado']==='CANCELADO';
      $is_past      = $is_pendiente && $f['fecha']<$today_d;
      $is_today     = $is_pendiente && $f['fecha']==$today_d;
      $border_color = $is_cancel ? '#999' : ($is_done ? $G : ($is_past ? $R : ($is_today ? $A : $P1)));
      $cli = trim($f['miembro_nombre']??'');
      if ($cli===', '||$cli==='') $cli = trim($f['nombre_libre']??'') ?: '— SIN NOMBRE —';
      $tel = $f['miembro_telefono'] ?? $f['telefono_libre'] ?? '';
      [$oIcono,$oLabel] = FOLLOWUP_ORIGENES[$f['origen_tipo']] ?? ['✎','Manual'];
      $origenTxt = $oLabel . (!empty($f['campana_nombre']) ? ' · '.$f['campana_nombre'] : '');
      ?>
      <div class="fu-card" data-id="<?=(int)$f['id']?>" data-fecha="<?=h($f['fecha'])?>" data-agente="<?=h($f['agente_id']??'')?>" data-origen="<?=h($f['origen_tipo']??'')?>" data-search="<?=strtolower(h(($cli.' '.$tel.' '.$f['titulo'].' '.($f['notas']??'').' '.($f['campana_nombre']??''))))?>" style="background:#fff;border:1px solid <?=$CB?>;border-left:4px solid <?=$border_color?>;border-radius:10px;padding:11px 13px;<?=$is_done||$is_cancel?'opacity:.65':''?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:6px">
          <div style="flex:1;min-width:0">
            <div style="font-size:11px;font-weight:900;color:<?=$P1?>;<?=!empty($f['miembro_id'])?'cursor:pointer':''?>;line-height:1.2"
                 <?php if(!empty($f['miembro_id'])):?>onclick="openProfile(<?=(int)$f['miembro_id']?>)"<?php endif;?>><?=h($cli)?></div>
            <?php if($tel):?><div style="font-size:8px;color:<?=$MU?>;margin-top:2px">📞 <?=h($tel)?></div><?php endif;?>
          </div>
          <div style="text-align:right;white-space:nowrap">
            <div style="font-size:7px;color:<?=$MU?>;font-weight:800;text-transform:uppercase">
              <?php if($is_today):?>HOY<?php elseif($is_past):?>ATRASADO<?php elseif($is_pendiente):?><?=date('m/d/Y',strtotime($f['fecha']))?><?php else:?><?=date('m/d/Y',strtotime($f['fecha']))?><?php endif;?>
            </div>
          </div>
        </div>
        <div style="font-size:10px;font-weight:800;color:<?=$TX?>;line-height:1.35;margin-bottom:5px"><?=h($f['titulo'])?></div>
        <?php if(!empty($f['notas'])):?>
          <div style="background:<?=$BG?>;border-radius:7px;padding:6px 8px;font-size:8px;color:<?=$MU?>;margin-bottom:7px;text-transform:none;line-height:1.35"><?=h(mb_substr($f['notas'],0,140))?><?=mb_strlen($f['notas']??'')>140?'…':''?></div>
        <?php endif;?>
        <div style="display:flex;gap:5px;flex-wrap:wrap;margin-bottom:7px">
          <span style="background:<?=$BG?>;color:<?=$P1?>;border:1px solid <?=$CB?>;border-radius:9px;padding:2px 7px;font-size:7px;font-weight:900;text-transform:uppercase"><?=$oIcono?> <?=h($origenTxt)?></span>
          <?php if(!empty($f['agente_nombre'])):?>
          <span style="display:inline-flex;align-items:center;gap:3px;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:9px;padding:2px 7px;font-size:7px;font-weight:900;text-transform:uppercase;color:<?=$MU?>">
            <span style="display:inline-block;width:11px;height:11px;border-radius:50%;background:<?=h($f['agente_color']??$P2)?>;color:#fff;font-size:6px;text-align:center;line-height:11px;font-weight:900"><?=h($f['agente_ini']??'?')?></span>
            <?=h(explode(' ',$f['agente_nombre'])[0])?>
          </span>
          <?php endif;?>
        </div>
        <?php if($is_pendiente):?>
        <div style="display:flex;gap:4px;flex-wrap:wrap">
          <button class="btn btn-gr btn-sm" onclick="completarFollowUp(<?=(int)$f['id']?>)" title="Completar" style="flex:1;padding:5px 8px;font-size:8px">✓ COMPLETAR</button>
          <button class="btn btn-gh btn-sm" onclick="reagendarFollowUp(<?=(int)$f['id']?>)" title="Reagendar" style="padding:5px 8px;font-size:8px">↻</button>
          <?php if(!empty($f['miembro_id'])):?><button class="btn btn-p btn-sm" onclick="openProfile(<?=(int)$f['miembro_id']?>)" title="Ver perfil" style="padding:5px 8px;font-size:8px">◉</button><?php endif;?>
          <button class="btn btn-r btn-sm" onclick="cancelarFollowUp(<?=(int)$f['id']?>)" title="Cancelar" style="padding:5px 8px;font-size:8px">✕</button>
        </div>
        <?php elseif($is_done):?>
          <div style="font-size:7px;color:<?=$G?>;font-weight:900;text-transform:uppercase">✓ COMPLETADO <?=!empty($f['completado_at'])?date('m/d/Y',strtotime($f['completado_at'])):''?><?=$f['completado_nombre']?' · '.h(explode(' ',$f['completado_nombre'])[0]):''?></div>
        <?php elseif($is_cancel):?>
          <div style="font-size:7px;color:<?=$MU?>;font-weight:900;text-transform:uppercase">✕ CANCELADO</div>
        <?php endif;?>
      </div>
      <?php
    };

    $render_grupo = function($titulo, $color, $arr) use ($render_fu) {
      if (!count($arr)) return;
      ?>
      <div class="fu-grupo" style="margin-bottom:18px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;padding:6px 0;border-bottom:2px solid <?=$color?>">
          <span style="font-size:10px;font-weight:900;color:<?=$color?>;text-transform:uppercase;letter-spacing:1px"><?=$titulo?></span>
          <span style="background:<?=$color?>;color:#fff;border-radius:20px;padding:1px 8px;font-size:8px;font-weight:900"><?=count($arr)?></span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:9px">
          <?php foreach($arr as $f) $render_fu($f); ?>
        </div>
      </div>
      <?php
    };

    ob_start();
    ?>
<div id="fsub-hoy" class="fsub-pane">
  <?php $render_grupo('● HOY · '.date('m/d/Y'), $A, $fu_hoy);
  if (!count($fu_hoy)):?>
    <div style="padding:40px;text-align:center;color:<?=$MU?>;background:#fff;border:1px solid <?=$CB?>;border-radius:11px">
      <div style="font-size:32px;margin-bottom:9px">☑</div>
      <div style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1px">SIN FOLLOW UPS PARA HOY</div>
    </div>
  <?php endif;?>
</div>
<div id="fsub-atrasados" class="fsub-pane" style="display:none">
  <?php $render_grupo('⚠ ATRASADOS', $R, $fu_atrasados);
  if (!count($fu_atrasados)):?>
    <div style="padding:40px;text-align:center;color:<?=$MU?>;background:#fff;border:1px solid <?=$CB?>;border-radius:11px;font-size:10px;font-weight:900;text-transform:uppercase">SIN FOLLOW UPS ATRASADOS</div>
  <?php endif;?>
</div>
<div id="fsub-proximos" class="fsub-pane" style="display:none">
  <?php $render_grupo('► PRÓXIMOS', $P1, $fu_proximos);
  if (!count($fu_proximos)):?>
    <div style="padding:40px;text-align:center;color:<?=$MU?>;background:#fff;border:1px solid <?=$CB?>;border-radius:11px;font-size:10px;font-weight:900;text-transform:uppercase">SIN FOLLOW UPS PRÓXIMOS</div>
  <?php endif;?>
</div>
<div id="fsub-completados" class="fsub-pane" style="display:none">
  <?php if(!count($fu_completados)):?>
    <div style="padding:40px;text-align:center;color:<?=$MU?>;background:#fff;border:1px solid <?=$CB?>;border-radius:11px;font-size:10px;font-weight:900;text-transform:uppercase">SIN FOLLOW UPS COMPLETADOS AÚN</div>
  <?php else:?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:9px">
    <?php foreach($fu_completados as $f) $render_fu($f); ?>
  </div>
  <?php endif;?>
</div>
<div id="fsub-cancelados" class="fsub-pane" style="display:none">
  <?php if(!count($fu_cancelados)):?>
    <div style="padding:40px;text-align:center;color:<?=$MU?>;background:#fff;border:1px solid <?=$CB?>;border-radius:11px;font-size:10px;font-weight:900;text-transform:uppercase">SIN FOLLOW UPS CANCELADOS</div>
  <?php else:?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:9px">
    <?php foreach($fu_cancelados as $f) $render_fu($f); ?>
  </div>
  <?php endif;?>
</div>
<div id="fsub-todos" class="fsub-pane" style="display:none">
  <?php
  $fu_todos = $fu;
  usort($fu_todos, fn($a,$b)=>strcmp($b['fecha'], $a['fecha']));
  if (!count($fu_todos)):?>
    <div style="padding:40px;text-align:center;color:<?=$MU?>;background:#fff;border:1px solid <?=$CB?>;border-radius:11px;font-size:10px;font-weight:900;text-transform:uppercase">SIN FOLLOW UPS TODAVÍA</div>
  <?php else:?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:9px">
    <?php foreach($fu_todos as $f) $render_fu($f); ?>
  </div>
  <?php endif;?>
</div>
    <?php
    $html = ob_get_clean();

    return [
        'html' => $html,
        'kpis' => [
            'hoy' => $fu_hoy_n, 'semana' => $fu_semana_n, 'atrasados' => $fu_atrasados_n,
        ],
        'counts' => [
            'hoy' => $fu_hoy_n, 'atrasados' => $fu_atrasados_n, 'proximos' => count($fu_proximos),
            'completados' => count($fu_completados), 'cancelados' => count($fu_cancelados), 'todos' => count($fu),
        ],
    ];
}
