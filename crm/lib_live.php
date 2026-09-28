<?php
/* ═══════════════════════════════════════════════════════════════════
 *  LIB_LIVE.PHP — "TODAY LIVE": qué está haciendo cada persona hoy,
 *  de un vistazo (pedido de Isabel: citas, tickets abiertos/cerrados,
 *  apps pendientes, llamadas desglosadas por tipo — todo por persona —
 *  más miembros activos/en proceso/por hacer como total GENERAL de la
 *  cartera, no por persona).
 *
 *  Todo son SELECTs de solo lectura, agrupados por agente en una sola
 *  consulta por tabla (nunca una consulta por empleado) — igual que
 *  ya hace extra_breaks_batch()/next_steps en Tickets. Cada bloque va
 *  en su propio try/catch para que, si una tabla no existe todavía o
 *  falla, el resto del panel se siga viendo (nunca "ERROR DE RED" por
 *  una sola pieza).
 * ═══════════════════════════════════════════════════════════════════ */

function render_live_panel(PDO $pdo): array {
    $P1='#1B4A6B'; $P2='#2876A8'; $BG='#EBF4F9'; $CB='#C8DFF0';
    $MU='#7A90A4'; $TX='#1B3A5C'; $G='#1E7A5C'; $R='#B83232'; $A='#C07A1A';
    $hoy = date('Y-m-d');

    try {
        $usuarios = $pdo->query("SELECT id,nombre,rol,color,iniciales FROM usuarios WHERE activo=1 ORDER BY rol DESC, nombre")->fetchAll();
    } catch (Throwable $e) { $usuarios = []; }

    // Asistencia de hoy — quién está trabajando/en break/salió ahora mismo
    $asis = [];
    try {
        $q = $pdo->prepare("SELECT agente_id, check_in, break_out, break_in, check_out FROM asistencia WHERE fecha=?");
        $q->execute([$hoy]);
        foreach ($q->fetchAll() as $r) $asis[(int)$r['agente_id']] = $r;
    } catch (Throwable $e) {}

    // Citas de hoy, por agente
    $citas = [];
    try {
        $q = $pdo->prepare("SELECT agente_id, COUNT(*) total, SUM(estado='COMPLETADA') completadas
                             FROM citas WHERE fecha=? GROUP BY agente_id");
        $q->execute([$hoy]);
        foreach ($q->fetchAll() as $r) $citas[(int)$r['agente_id']] = $r;
    } catch (Throwable $e) {}

    // Tickets abiertos ahora mismo (+ urgentes + tipo APLICACION), por dueño real
    // (asignado_a si existe, si no el agente_id original — mismo criterio que
    // ya usa el resto del CRM para "de quién es este ticket").
    $tkAbiertos = [];
    try {
        $q = $pdo->query("SELECT COALESCE(asignado_a, agente_id) owner_id, COUNT(*) total,
                                  SUM(tipo='APLICACION') apps, SUM(prioridad='ALTA') urgentes
                           FROM tickets WHERE estado != 'CERRADO' GROUP BY owner_id");
        foreach ($q->fetchAll() as $r) $tkAbiertos[(int)$r['owner_id']] = $r;
    } catch (Throwable $e) {}

    // Tickets cerrados HOY, por dueño real
    $tkCerradosHoy = [];
    try {
        $q = $pdo->prepare("SELECT COALESCE(asignado_a, agente_id) owner_id, COUNT(*) total
                             FROM tickets WHERE estado='CERRADO' AND DATE(fecha_cierre)=? GROUP BY owner_id");
        $q->execute([$hoy]);
        foreach ($q->fetchAll() as $r) $tkCerradosHoy[(int)$r['owner_id']] = (int)$r['total'];
    } catch (Throwable $e) {}

    // Miembros por estado — pedido de Isabel: esto es GENERAL de toda la
    // cartera, no por persona (a diferencia de citas/tickets/llamadas, que
    // sí son por agente). "POR HACER" es SIN HACER/SIN FIRMAR (papeleo sin
    // terminar) — PROSPECT es otra cosa (leads nuevos) y NO cuenta aquí,
    // aclarado por Isabel.
    $miembrosTot = ['activos'=>0,'proceso'=>0,'por_hacer'=>0];
    try {
        $q = $pdo->query("SELECT estado, COUNT(*) n FROM miembros GROUP BY estado");
        foreach ($q->fetchAll() as $r) {
            if ($r['estado'] === 'ACTIVE') {
                $miembrosTot['activos'] += (int)$r['n'];
            } elseif (in_array($r['estado'], ['IN PROCESS','READY TO ENROLL','PLAN CHANGE','PENDING'], true)) {
                $miembrosTot['proceso'] += (int)$r['n'];
            } elseif (in_array($r['estado'], ['SIN HACER','SIN FIRMAR'], true)) {
                $miembrosTot['por_hacer'] += (int)$r['n'];
            }
        }
    } catch (Throwable $e) {}

    // Llamadas a prospectos HOY, por agente
    $llProspHoy = [];
    try {
        $q = $pdo->prepare("SELECT agente_id, COUNT(*) n FROM llamadas_prospectos WHERE DATE(created_at)=? GROUP BY agente_id");
        $q->execute([$hoy]);
        foreach ($q->fetchAll() as $r) $llProspHoy[(int)$r['agente_id']] = (int)$r['n'];
    } catch (Throwable $e) {}

    // Llamadas de retención (bienvenida/30/60/90) HOY, por quien la completó
    $llRetHoy = [];
    try {
        $q = $pdo->prepare("SELECT completada_por, COUNT(*) n FROM retencion_llamadas WHERE DATE(completada_at)=? GROUP BY completada_por");
        $q->execute([$hoy]);
        foreach ($q->fetchAll() as $r) $llRetHoy[(int)$r['completada_por']] = (int)$r['n'];
    } catch (Throwable $e) {}

    // Llamadas perdidas HOY, por agente (esta tabla no se auto-crea en ningún
    // lado — puede no existir en instalaciones nuevas, por eso el try/catch).
    $llPerdHoy = [];
    $llPerdPendientes = 0;
    try {
        $q = $pdo->prepare("SELECT agente_id, COUNT(*) n FROM llamadas_perdidas WHERE fecha=? GROUP BY agente_id");
        $q->execute([$hoy]);
        foreach ($q->fetchAll() as $r) $llPerdHoy[(int)$r['agente_id']] = (int)$r['n'];
        $llPerdPendientes = (int)$pdo->query("SELECT COUNT(*) FROM llamadas_perdidas WHERE estado IS NULL OR estado != 'DEVUELTA'")->fetchColumn();
    } catch (Throwable $e) {}

    // Follow ups pendientes (de hoy + atrasados), por agente
    $fuPend = [];
    try {
        $q = $pdo->prepare("SELECT agente_id, COUNT(*) n FROM follow_ups WHERE estado='PENDIENTE' AND fecha<=? GROUP BY agente_id");
        $q->execute([$hoy]);
        foreach ($q->fetchAll() as $r) $fuPend[(int)$r['agente_id']] = (int)$r['n'];
    } catch (Throwable $e) {}

    // ── Estado de asistencia "ahora mismo" ──────────────────────────
    $estadoAhora = function (?array $a) use ($G, $A, $MU) {
        if (!$a || empty($a['check_in'])) return ['⚪ SIN CHECK-IN', $MU];
        if (!empty($a['check_out']))      return ['◗ SALIÓ · ' . substr($a['check_out'], 0, 5), $MU];
        if (!empty($a['break_out']) && empty($a['break_in'])) return ['◐ EN BREAK', $A];
        return ['● TRABAJANDO · desde ' . substr($a['check_in'], 0, 5), $G];
    };

    // ── Totales de la empresa (tarjetas de arriba) ──────────────────
    $totCitasHoy   = array_sum(array_column($citas, 'total'));
    $totTkAbiertos = array_sum(array_column($tkAbiertos, 'total'));
    $totTkCerrHoy  = array_sum($tkCerradosHoy);
    $totApps       = array_sum(array_column($tkAbiertos, 'apps'));
    $totUrgentes   = array_sum(array_column($tkAbiertos, 'urgentes'));
    $totLlamadas   = array_sum($llProspHoy) + array_sum($llRetHoy) + array_sum($llPerdHoy);
    $totFuPend     = array_sum($fuPend);
    $totTrabajando = 0;
    foreach ($asis as $a) { if (!empty($a['check_in']) && empty($a['check_out'])) $totTrabajando++; }

    // Todo lo de aquí hasta ob_get_clean() va dentro de un try — si algo
    // truena A MITAD de armar el HTML (ej. un dato inesperado en un bucle),
    // el buffer de ob_start() se queda abierto con HTML a medias adentro, y
    // ESE HTML termina pegado por delante del JSON de error de api.php →
    // respuesta que ya no es JSON válido → "ERROR DE RED" en vez de un
    // error legible, aunque el try/catch general de api.php sí atrapó el
    // error. Por eso el catch de aquí abajo bota el buffer a propósito.
    ob_start();
    try {
    ?>
    <div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:13px">
      <?php
      $kpi = function (string $label, $val, string $color) use ($CB): void {
          echo '<div style="background:#fff;border:1px solid ' . $CB . ';border-left:4px solid ' . $color . ';border-radius:9px;padding:7px 12px;min-width:88px">'
             . '<div style="font-size:7px;color:#7A90A4;font-weight:900;text-transform:uppercase">' . h($label) . '</div>'
             . '<div style="font-size:18px;font-weight:900;color:' . $color . '">' . h((string)$val) . '</div>'
             . '</div>';
      };
      $kpi('● TRABAJANDO AHORA', $totTrabajando . '/' . count($usuarios), $G);
      $kpi('CITAS HOY', $totCitasHoy, $P1);
      $kpi('TICKETS ABIERTOS', $totTkAbiertos, $A);
      $kpi('⚠ URGENTES', $totUrgentes, $R);
      $kpi('CERRADOS HOY', $totTkCerrHoy, $G);
      $kpi('APPS PENDIENTES', $totApps, $P2);
      $kpi('LLAMADAS HOY', $totLlamadas, $P1);
      // Miembros — general de toda la cartera, no por persona (a propósito
      // no van en la tabla de abajo).
      $kpi('◉ MIEMBROS ACTIVOS', $miembrosTot['activos'], $G);
      $kpi('◉ MIEMBROS EN PROCESO', $miembrosTot['proceso'], $P2);
      $kpi('◉ MIEMBROS POR HACER', $miembrosTot['por_hacer'], $A);
      if ($totFuPend > 0) $kpi('☑ FOLLOW UPS PEND.', $totFuPend, $A);
      if ($llPerdPendientes > 0) $kpi('☏ PERDIDAS SIN DEVOLVER', $llPerdPendientes, $R);
      ?>
    </div>

    <div style="overflow-x:auto;background:#fff;border:1px solid <?=$CB?>;border-radius:11px">
    <table style="width:100%;border-collapse:collapse;font-size:9px;white-space:nowrap">
      <thead>
        <tr style="background:<?=$BG?>">
          <?php foreach (['EMPLEADO','AHORA','CITAS HOY','TICKETS ABIERTOS','CERRADOS HOY','APPS PEND.','LLAMADAS HOY (PROSP · RETEN · PERD.)','FOLLOW UPS PEND.'] as $col):?>
          <th style="padding:8px 10px;text-align:left;font-size:8px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid <?=$CB?>"><?=$col?></th>
          <?php endforeach;?>
        </tr>
      </thead>
      <tbody>
        <?php if (!count($usuarios)):?>
        <tr><td colspan="8" style="padding:20px;text-align:center;color:<?=$MU?>;text-transform:uppercase">SIN EMPLEADOS ACTIVOS</td></tr>
        <?php endif;?>
        <?php foreach ($usuarios as $u):
            $aid = (int)$u['id'];
            [$estLabel, $estColor] = $estadoAhora($asis[$aid] ?? null);
            $c  = $citas[$aid]      ?? ['total'=>0,'completadas'=>0];
            $tk = $tkAbiertos[$aid] ?? ['total'=>0,'apps'=>0,'urgentes'=>0];
            $tkCerr = $tkCerradosHoy[$aid] ?? 0;
            $lp = $llProspHoy[$aid] ?? 0;
            $lr = $llRetHoy[$aid]   ?? 0;
            $lm = $llPerdHoy[$aid]  ?? 0;
            $fu = $fuPend[$aid]     ?? 0;
        ?>
        <tr style="border-bottom:1px solid <?=$BG?>">
          <td style="padding:8px 10px">
            <div style="display:flex;align-items:center;gap:7px">
              <span style="display:inline-flex;width:22px;height:22px;border-radius:50%;background:<?=h($u['color']??$P2)?>;color:#fff;font-size:8px;font-weight:900;align-items:center;justify-content:center;flex-shrink:0"><?=h($u['iniciales']??'?')?></span>
              <span style="font-weight:900;color:<?=$P1?>"><?=h($u['nombre'])?></span>
            </div>
          </td>
          <td style="padding:8px 10px;color:<?=$estColor?>;font-weight:800"><?=$estLabel?></td>
          <td style="padding:8px 10px"><?=(int)$c['total']?> <span style="color:<?=$MU?>">(<?=(int)$c['completadas']?> ✓)</span></td>
          <td style="padding:8px 10px">
            <?=(int)$tk['total']?>
            <?php if ((int)$tk['urgentes'] > 0):?><span style="color:<?=$R?>;font-weight:900"> · <?=(int)$tk['urgentes']?> ⚠</span><?php endif;?>
          </td>
          <td style="padding:8px 10px;color:<?=$tkCerr>0?$G:$MU?>;font-weight:<?=$tkCerr>0?'800':'400'?>"><?=$tkCerr?></td>
          <td style="padding:8px 10px;color:<?=((int)$tk['apps'])>0?$P2:$MU?>;font-weight:<?=((int)$tk['apps'])>0?'800':'400'?>"><?=(int)$tk['apps']?></td>
          <td style="padding:8px 10px"><?=$lp?> · <?=$lr?> · <?=$lm?></td>
          <td style="padding:8px 10px;color:<?=$fu>0?$A:$MU?>;font-weight:<?=$fu>0?'800':'400'?>"><?=$fu?></td>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table>
    </div>
    <div style="font-size:8px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:.5px;margin-top:8px">
      ↻ Se actualiza sola cada 45 segundos · Última actualización: <?=date('h:i:s A')?>
    </div>
    <?php
    } catch (Throwable $e) {
        ob_end_clean();
        return ['html' => '<div style="padding:30px;text-align:center;color:#B83232;font-size:9px;text-transform:uppercase">No se pudo armar TODAY LIVE — intenta de nuevo en un momento</div>'];
    }
    $html = ob_get_clean();

    return ['html' => $html];
}
