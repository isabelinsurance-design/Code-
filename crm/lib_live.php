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
 *
 *  REPORTES DE DÍAS ANTERIORES (pedido de Isabel, "como los reportes de
 *  los empleados"): $fecha deja ver cualquier día pasado. No se guarda
 *  nada aparte ni hace falta cron — los números de ACTIVIDAD de ese día
 *  (citas agendadas, llamadas, tickets cerrados) se pueden volver a
 *  calcular exactos para cualquier fecha, porque salen de registros con
 *  fecha/hora ya guardados. Lo que SÍ es "ahora mismo" (tickets abiertos,
 *  overdue, en proceso, apps pendientes, miembros activos) no se puede
 *  reconstruir para el pasado sin haberlo guardado ese día — por eso esas
 *  tarjetas, la fila de "trabajando ahora" y el carrusel NO aparecen al
 *  ver un día anterior, solo lo que sí se puede saber con certeza.
 * ═══════════════════════════════════════════════════════════════════ */

function render_live_panel(PDO $pdo, ?string $fecha = null): array {
    $P1='#1B4A6B'; $P2='#2876A8'; $BG='#EBF4F9'; $CB='#C8DFF0';
    $MU='#7A90A4'; $TX='#1B3A5C'; $G='#1E7A5C'; $R='#B83232'; $A='#C07A1A';
    $hoyReal = date('Y-m-d');
    // Fecha inválida (basura en el parámetro) → se ignora y cae a hoy.
    if ($fecha !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = null;
    $esHoy = ($fecha === null || $fecha === $hoyReal);
    $hoy = $esHoy ? $hoyReal : $fecha;

    try {
        $usuarios = $pdo->query("SELECT id,nombre,rol,color,iniciales FROM usuarios WHERE activo=1 ORDER BY rol DESC, nombre")->fetchAll();
    } catch (Throwable $e) { $usuarios = []; }

    // Empleados INACTIVOS — pedido de Isabel: que se sigan viendo en la
    // tabla (abajo de los activos) pero apagados/discretos, no llamativos.
    $usuariosInactivos = [];
    try {
        $usuariosInactivos = $pdo->query("SELECT id,nombre,rol,color,iniciales FROM usuarios WHERE activo=0 ORDER BY nombre")->fetchAll();
    } catch (Throwable $e) {}
    $idsInactivos = array_fill_keys(array_column($usuariosInactivos, 'id'), true);
    $usuarios = array_merge($usuarios, $usuariosInactivos);

    // Asistencia de hoy — quién está trabajando/en break/salió ahora mismo
    $asis = [];
    try {
        $q = $pdo->prepare("SELECT id, agente_id, check_in, break_out, break_in, check_out FROM asistencia WHERE fecha=?");
        $q->execute([$hoy]);
        foreach ($q->fetchAll() as $r) $asis[(int)$r['agente_id']] = $r;
    } catch (Throwable $e) {}

    // Breaks SEGUNDO EN ADELANTE que están abiertos ahora mismo (viven en
    // asistencia_breaks, aparte de asistencia.break_out/break_in que solo
    // guarda el PRIMER break del día) — sin esto, alguien en su 2do/3er
    // break del día salía como "TRABAJANDO" en vez de "EN BREAK" porque las
    // columnas del primer break ya estaban cerradas (break_in lleno).
    $breaksAbiertosAhora = [];
    try {
        if ($asis) {
            $ids = array_column($asis, 'id');
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $q = $pdo->prepare("SELECT DISTINCT asistencia_id FROM asistencia_breaks WHERE asistencia_id IN ($ph) AND break_in IS NULL");
            $q->execute($ids);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $aid) $breaksAbiertosAhora[(int)$aid] = true;
        }
    } catch (Throwable $e) {}

    // CITAS HOY = las que se AGENDARON (crearon) hoy — no las que son PARA
    // hoy. Aclaración de Isabel: son cosas distintas (alguien puede agendar
    // hoy una cita para la próxima semana, o agendar hace rato una para
    // hoy mismo — este cuadrito es de lo primero). Y SOLO de PROSPECTO, no
    // de miembro — aclaración de Isabel.
    $totCitasHoy = 0;
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM citas WHERE tipo_persona='PROSPECTO' AND created_at>=? AND created_at<DATE_ADD(?, INTERVAL 1 DAY)");
        $q->execute([$hoy, $hoy]);
        $totCitasHoy = (int)$q->fetchColumn();
    } catch (Throwable $e) {}

    // Citas de PROSPECTO agendadas (creadas) HOY, por agente — pedido de
    // Isabel para la tabla de abajo. tipo_persona es un campo nuevo (si
    // nadie ha guardado/editado una cita todavía después de este cambio,
    // la columna puede no existir aún — por eso el try/catch, igual que el
    // resto de este archivo).
    $citasProspectosHoy = [];
    try {
        $q = $pdo->prepare("SELECT agente_id, COUNT(*) n FROM citas
                             WHERE tipo_persona='PROSPECTO' AND created_at>=? AND created_at<DATE_ADD(?, INTERVAL 1 DAY)
                             GROUP BY agente_id");
        $q->execute([$hoy, $hoy]);
        foreach ($q->fetchAll() as $r) $citasProspectosHoy[(int)$r['agente_id']] = (int)$r['n'];
    } catch (Throwable $e) {}

    // Tickets abiertos ahora mismo (+ urgentes + tipo APLICACION), por dueño real
    // (asignado_a si existe, si no el agente_id original — mismo criterio que
    // ya usa el resto del CRM para "de quién es este ticket", ej. $resp_id en
    // lib_row_render.php: !empty($t['asignado_a']) ? asignado_a : agente_id).
    // OJO: NULLIF(asignado_a,0) es a propósito — asignado_a se guarda como 0
    // (no NULL) cuando no hay reasignación, y un COALESCE normal se hubiera
    // quedado con ese 0 en vez de caer al agente_id, dejando esos tickets
    // fuera de la cuenta de TODOS los empleados (por eso no aparecían apps
    // pendientes de Samia aunque sí las tenía).
    // Pedido de Isabel: que cuente solo los que YA toca atender — con SLA de
    // hoy o vencido (o sin SLA puesto todavía). Los que tienen SLA para más
    // adelante no cuentan aquí todavía (mismo criterio que ya usan las
    // alertas de prioridad en otras partes del CRM).
    $tkAbiertos = [];
    try {
        $q = $pdo->prepare("SELECT COALESCE(NULLIF(asignado_a,0), agente_id) owner_id, COUNT(*) total,
                                  SUM(prioridad='ALTA') urgentes
                           FROM tickets WHERE estado != 'CERRADO' AND (sla_fecha IS NULL OR sla_fecha <= ?) GROUP BY owner_id");
        $q->execute([$hoy]);
        foreach ($q->fetchAll() as $r) $tkAbiertos[(int)$r['owner_id']] = $r;
    } catch (Throwable $e) {}

    // APPS PENDIENTES por agente — aparte de lo de arriba a propósito
    // (aclaración de Isabel): una aplicación pendiente cuenta aunque su SLA
    // sea para más adelante, no solo las de hoy/vencidas.
    $appsAbiertas = [];
    try {
        $q = $pdo->query("SELECT COALESCE(NULLIF(asignado_a,0), agente_id) owner_id, COUNT(*) n
                           FROM tickets WHERE tipo='APLICACION' AND estado != 'CERRADO' GROUP BY owner_id");
        foreach ($q->fetchAll() as $r) $appsAbiertas[(int)$r['owner_id']] = (int)$r['n'];
    } catch (Throwable $e) {}

    // Tickets cerrados HOY, por dueño real
    // Se excluyen las LLAMADA/LLAMADA PERDIDA — esas ya se cuentan aparte en
    // LLAM. SERVICIO, y contarlas también aquí las duplicaba e inflaba
    // "cerrados hoy" con puras llamadas en vez de casos/tickets resueltos
    // de verdad. Mismo criterio que ya usa $mis_cerrados_hoy en el reporte
    // de MI DÍA (index.php).
    $tkCerradosHoy = [];
    try {
        $q = $pdo->prepare("SELECT COALESCE(NULLIF(asignado_a,0), agente_id) owner_id, COUNT(*) total
                             FROM tickets WHERE estado='CERRADO' AND DATE(fecha_cierre)=?
                               AND tipo NOT IN ('LLAMADA','LLAMADA PERDIDA')
                             GROUP BY owner_id");
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

    // Llamadas a prospectos HOY que SÍ contestaron — pedido de Isabel para
    // la tarjeta de LLAMADAS (total de la empresa, no por agente).
    $totLlamadasContestaron = 0;
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM llamadas_prospectos WHERE DATE(created_at)=? AND contesto=1");
        $q->execute([$hoy]);
        $totLlamadasContestaron = (int)$q->fetchColumn();
    } catch (Throwable $e) {}

    // Tickets OVERDUE (SLA ya vencido, antes de hoy — no cuenta el que vence
    // HOY mismo) y EN PROCESO — pedido de Isabel para la tarjeta de TICKETS
    // (total de la empresa, no por agente; reemplaza el conteo general de
    // "tickets abiertos" que había antes en esta fila de arriba). OVERDUE
    // es SOLO estado='ABIERTO' — o sea, los que nadie ha tocado todavía;
    // uno en PENDIENTE o EN PROCESO ya se está trabajando, no cuenta aquí.
    $totTkOverdue = 0;
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE estado='ABIERTO' AND sla_fecha IS NOT NULL AND sla_fecha < ?");
        $q->execute([$hoy]);
        $totTkOverdue = (int)$q->fetchColumn();
    } catch (Throwable $e) {}
    $totTkEnProceso = 0;
    try {
        $totTkEnProceso = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE estado='EN PROCESO'")->fetchColumn();
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

    // ── Para las tarjetas "cómo va cada quien hoy" (pedido de Isabel) ───
    // Llamadas de SERVICIO al cliente HOY (tickets tipo=LLAMADA creados hoy),
    // por agente — mismo criterio que usa el reporte de MI DÍA
    // ($mis_llamadas_servicio_hoy en index.php).
    $llServHoy = [];
    try {
        $q = $pdo->prepare("SELECT agente_id, COUNT(*) n FROM tickets WHERE tipo='LLAMADA' AND fecha_creacion>=? AND fecha_creacion<DATE_ADD(?, INTERVAL 1 DAY) GROUP BY agente_id");
        $q->execute([$hoy, $hoy]);
        foreach ($q->fetchAll() as $r) $llServHoy[(int)$r['agente_id']] = (int)$r['n'];
    } catch (Throwable $e) {}

    // Citas COMPLETADAS de esta fecha, con su tipo — pedido de Isabel para
    // una minilista aparte. No es "ahora mismo" (el estado COMPLETADA queda
    // guardado), así que esto sí se puede ver también en un día pasado.
    $citasCompletadas = [];
    try {
        $q = $pdo->prepare("SELECT c.hora, c.tipo, c.miembro_id, CONCAT(m.nombre,' ',m.apellido) AS miembro_nombre,
                                   COALESCE(NULLIF(c.tipo_persona,''),'MIEMBRO') AS tipo_persona
                            FROM citas c LEFT JOIN miembros m ON m.id = c.miembro_id
                            WHERE c.fecha=? AND c.estado='COMPLETADA'
                            ORDER BY c.hora ASC");
        $q->execute([$hoy]);
        $citasCompletadas = $q->fetchAll();
    } catch (Throwable $e) {}

    // De esas citas completadas, cuáles SÍ fueron venta — pedido de Isabel.
    // "Venta" = el miembro de esa cita ya tiene un bono de venta registrado
    // en pago_bonos (botón "ES VENTA → MANDAR A BONOS" del perfil), no
    // importa qué día se registró el bono.
    $miembrosConVenta = [];
    try {
        $midsCita = array_filter(array_unique(array_column($citasCompletadas, 'miembro_id')));
        if ($midsCita) {
            $ph = implode(',', array_fill(0, count($midsCita), '?'));
            $q = $pdo->prepare("SELECT DISTINCT miembro_id FROM pago_bonos WHERE miembro_id IN ($ph)");
            $q->execute(array_values($midsCita));
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $mvid) $miembrosConVenta[(int)$mvid] = true;
        }
    } catch (Throwable $e) {}

    // Total de VENTAS para la tarjetita — aclaración de Isabel: solo cuentan
    // las citas de PROSPECTO, las de miembro ya existente NO cuentan como venta.
    $totVentasHoy = 0;
    foreach ($citasCompletadas as $c) {
        $esProsp = ($c['tipo_persona'] ?? 'MIEMBRO') === 'PROSPECTO';
        if ($esProsp && !empty($c['miembro_id']) && !empty($miembrosConVenta[(int)$c['miembro_id']])) $totVentasHoy++;
    }

    // ── Estado de asistencia "ahora mismo" ──────────────────────────
    $estadoAhora = function (?array $a) use ($G, $A, $MU, $breaksAbiertosAhora) {
        if (!$a || empty($a['check_in'])) return ['⚪ SIN CHECK-IN', $MU];
        if (!empty($a['check_out']))      return ['◗ SALIÓ · ' . substr($a['check_out'], 0, 5), $MU];
        if (!empty($a['break_out']) && empty($a['break_in'])) return ['◐ EN BREAK', $A];
        if (!empty($a['id']) && !empty($breaksAbiertosAhora[(int)$a['id']])) return ['◐ EN BREAK', $A];
        return ['● TRABAJANDO · desde ' . substr($a['check_in'], 0, 5), $G];
    };

    // ── Totales de la empresa (tarjetas de arriba) ──────────────────
    $totTkCerrHoy  = array_sum($tkCerradosHoy);
    $totUrgentes   = array_sum(array_column($tkAbiertos, 'urgentes'));
    // APPS PENDIENTES = TODOS los tickets tipo APLICACION sin cerrar —
    // aclaración de Isabel. Aparte de $tkAbiertos a propósito: ese ya trae
    // solo SLA de hoy/vencido (para "tickets abiertos"/urgentes), pero una
    // aplicación pendiente cuenta aunque su SLA sea para más adelante.
    $totApps = 0;
    try {
        $totApps = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE tipo='APLICACION' AND estado != 'CERRADO'")->fetchColumn();
    } catch (Throwable $e) {}
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
    <?php if (!$esHoy):?>
    <div style="background:#F3F0FB;border:1px solid #C2B0E8;border-left:4px solid #5B3FAF;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:9px;color:#5B3FAF;letter-spacing:.3px;line-height:1.6">
      📋 REPORTE DEL <?=date('d/m/Y', strtotime($hoy))?> — esto es lo que pasó ESE día (actividad: citas agendadas, llamadas, tickets cerrados). No incluye cosas de "ahora mismo" como tickets pendientes/overdue o miembros activos, porque esos cambian todos los días y no se pueden ver en el pasado sin haberlos guardado ese mismo día.
    </div>
    <?php endif;?>
    <!-- ── TARJETAS "CÓMO VA CADA QUIEN HOY" — sección aparte, rotan solas
         (pedido de Isabel: de entrada una tarjeta grande de un empleado con
         citas agendadas/tickets cerrados/llamadas a prospectos/llamadas de
         servicio, y que vayan saliendo los demás uno por uno — pensado para
         dejarlo en una pantalla de la oficina). La tabla de abajo sigue
         igual, aparte, para ver a todos de un jalón. Solo aplica a HOY —
         no tiene sentido "rotar" un reporte de un día que ya pasó. -->
    <?php if ($esHoy && count($usuarios)):?>
    <div style="margin-bottom:18px">
      <div style="font-size:9px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">👤 CÓMO VA CADA QUIEN HOY</div>
      <div id="live-carrusel-wrap" style="position:relative">
        <?php foreach ($usuarios as $idx => $u):
            $aid = (int)$u['id'];
            // Mismo dato que la columna "CITAS PROSPECTO (AGENDADAS HOY)"
            // de la tabla de abajo — a propósito, para que coincidan
            // siempre (pedido de Isabel).
            $cAgend = $citasProspectosHoy[$aid] ?? 0;
            $tkCerr = $tkCerradosHoy[$aid] ?? 0;
            $lp = $llProspHoy[$aid] ?? 0;
            $ls = $llServHoy[$aid] ?? 0;
        ?>
        <div class="live-carrusel-card" style="<?=$idx===0?'':'display:none;'?>background:#fff;border:1px solid <?=$CB?>;border-radius:14px;padding:18px 22px">
          <div style="display:flex;align-items:center;gap:11px;margin-bottom:15px">
            <span style="display:inline-flex;width:40px;height:40px;border-radius:50%;background:<?=h($u['color']??$P2)?>;color:#fff;font-size:14px;font-weight:900;align-items:center;justify-content:center;flex-shrink:0"><?=h($u['iniciales']??'?')?></span>
            <div>
              <div style="font-size:15px;font-weight:900;color:<?=$P1?>"><?=h($u['nombre'])?></div>
              <div style="font-size:8px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:.5px">CÓMO VA HOY</div>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:9px">
            <div style="background:<?=$BG?>;border-radius:10px;padding:11px;text-align:center">
              <div style="font-size:24px;font-weight:900;color:<?=$P1?>"><?=$cAgend?></div>
              <div style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;margin-top:3px">CITAS PROSPECTO</div>
            </div>
            <div style="background:<?=$BG?>;border-radius:10px;padding:11px;text-align:center">
              <div style="font-size:24px;font-weight:900;color:<?=$G?>"><?=$tkCerr?></div>
              <div style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;margin-top:3px">TICKETS CERRADOS</div>
            </div>
            <div style="background:<?=$BG?>;border-radius:10px;padding:11px;text-align:center">
              <div style="font-size:24px;font-weight:900;color:<?=$P2?>"><?=$lp?></div>
              <div style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;margin-top:3px">LLAM. PROSPECTOS</div>
            </div>
            <div style="background:<?=$BG?>;border-radius:10px;padding:11px;text-align:center">
              <div style="font-size:24px;font-weight:900;color:<?=$A?>"><?=$ls?></div>
              <div style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;margin-top:3px">LLAM. SERVICIO</div>
            </div>
          </div>
        </div>
        <?php endforeach;?>
      </div>
      <?php if (count($usuarios) > 1):?>
      <div id="live-carrusel-dots" style="display:flex;justify-content:center;gap:5px;margin-top:9px">
        <?php foreach ($usuarios as $idx => $u):?>
        <span class="live-carrusel-dot" style="width:6px;height:6px;border-radius:50%;background:<?=$idx===0?$P1:$CB?>"></span>
        <?php endforeach;?>
      </div>
      <?php endif;?>
    </div>
    <?php endif;?>

    <?php
    // ── Fila 1: pulso del día, de un vistazo — tarjetitas chicas sueltas ──
    $kpi = function (string $label, $val, string $color) use ($CB, $MU): void {
        echo '<div style="background:#fff;border:1px solid ' . $CB . ';border-left:4px solid ' . $color . ';border-radius:9px;padding:8px 13px;min-width:92px;flex:1">'
           . '<div style="font-size:7px;color:' . $MU . ';font-weight:900;text-transform:uppercase;white-space:nowrap">' . h($label) . '</div>'
           . '<div style="font-size:19px;font-weight:900;color:' . $color . ';margin-top:2px">' . h((string)$val) . '</div>'
           . '</div>';
    };
    ?>
    <div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:11px">
      <?php
      if ($esHoy) {
          $kpi('● TRABAJANDO AHORA', $totTrabajando . '/' . count($usuarios), $G);
      } else {
          // "Ahora mismo" no aplica a un día que ya pasó — se cambia por
          // quién sí marcó asistencia ese día (eso sí se puede saber).
          $trabajaronEseDia = 0;
          foreach ($asis as $a) { if (!empty($a['check_in'])) $trabajaronEseDia++; }
          $kpi('TRABAJARON ESE DÍA', $trabajaronEseDia . '/' . count($usuarios), $G);
      }
      $kpi('CITAS HOY', $totCitasHoy, $P1);
      $kpi('💰 VENTAS', $totVentasHoy, $G);
      $kpi('CERRADOS HOY', $totTkCerrHoy, $G);
      if ($esHoy) {
          $kpi('APPS PENDIENTES', $totApps, $P2);
          $kpi('⚠ URGENTES', $totUrgentes, $R);
          if ($totFuPend > 0) $kpi('☑ FOLLOW UPS PEND.', $totFuPend, $A);
          if ($llPerdPendientes > 0) $kpi('☏ PERDIDAS SIN DEVOLVER', $llPerdPendientes, $R);
      }
      ?>
    </div>

    <?php
    // ── Fila 2: 3 tarjetas por categoría (pedido de Isabel) — mismo tamaño,
    // franja de color arriba, y los números separados por una rayita fina
    // en vez de amontonados, para que se lea ordenado.
    $kpiGroup = function (string $titulo, string $icono, string $accent, array $items) use ($CB, $MU): void {
        echo '<div style="background:#fff;border:1px solid ' . $CB . ';border-top:3px solid ' . $accent . ';border-radius:11px;padding:11px 10px 12px">'
           . '<div style="font-size:8px;color:' . $accent . ';font-weight:900;text-transform:uppercase;letter-spacing:1px;text-align:center;margin-bottom:10px">' . $icono . ' ' . h($titulo) . '</div>'
           . '<div style="display:flex;align-items:stretch">';
        foreach ($items as $i => [$label, $val, $color]) {
            if ($i > 0) echo '<div style="width:1px;background:' . $CB . ';margin:0 4px"></div>';
            echo '<div style="flex:1;min-width:0;text-align:center">'
               . '<div style="font-size:21px;font-weight:900;color:' . $color . '">' . h((string)$val) . '</div>'
               . '<div style="font-size:7px;color:' . $MU . ';font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-top:3px;line-height:1.3">' . h($label) . '</div>'
               . '</div>';
        }
        echo '</div></div>';
    };
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:9px;margin-bottom:13px">
      <?php
      // TICKETS y MIEMBROS son "estado ahora mismo" — no se pueden ver en
      // el pasado sin haberlos guardado ese día, así que solo aparecen hoy.
      if ($esHoy) {
          $kpiGroup('TICKETS', '◈', $A, [
              ['OVERDUE', $totTkOverdue, $totTkOverdue>0?$R:$MU],
              ['EN PROCESO', $totTkEnProceso, $P2],
          ]);
      }
      $kpiGroup('LLAMADAS', '☏', $P1, [
          ['PROSPECTO TOTAL', array_sum($llProspHoy), $P1],
          ['CONTESTARON', $totLlamadasContestaron, $G],
          ['SERVICIO AL CLIENTE', array_sum($llServHoy), $P2],
      ]);
      if ($esHoy) {
          // Miembros — general de toda la cartera, no por persona (a
          // propósito no van en la tabla de abajo).
          $kpiGroup('MIEMBROS', '◉', $G, [
              ['ACTIVOS', $miembrosTot['activos'], $G],
              ['EN PROCESO', $miembrosTot['proceso'], $P2],
              ['POR HACER', $miembrosTot['por_hacer'], $A],
          ]);
      }
      // Minilista de citas completadas + de qué tipo — pedido de Isabel.
      echo '<div style="background:#fff;border:1px solid ' . $CB . ';border-top:3px solid ' . $G . ';border-radius:11px;padding:11px 10px 12px">'
         . '<div style="font-size:8px;color:' . $G . ';font-weight:900;text-transform:uppercase;letter-spacing:1px;text-align:center;margin-bottom:9px">✓ CITAS COMPLETADAS (' . count($citasCompletadas) . ')</div>';
      if (count($citasCompletadas)) {
          echo '<div style="max-height:150px;overflow-y:auto">';
          foreach ($citasCompletadas as $c) {
              $esProsp = ($c['tipo_persona'] ?? 'MIEMBRO') === 'PROSPECTO';
              echo '<div style="display:flex;align-items:center;gap:6px;font-size:8px;padding:4px 2px;border-bottom:1px solid ' . $BG . '">'
                 . '<span style="color:' . $MU . ';font-weight:700;white-space:nowrap">' . h(substr($c['hora'] ?? '', 0, 5)) . '</span>'
                 . '<span style="color:' . $TX . ';font-weight:800;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . h(trim($c['miembro_nombre'] ?? '')) . '</span>'
                 . '<span style="color:#fff;background:' . ($esProsp ? $A : $G) . ';font-weight:900;text-transform:uppercase;white-space:nowrap;font-size:6px;padding:2px 4px;border-radius:4px">' . ($esProsp ? 'PROSPECTO' : 'MIEMBRO') . '</span>'
                 . '<span style="color:' . $P2 . ';font-weight:900;text-transform:uppercase;white-space:nowrap;font-size:7px">' . h($c['tipo'] ?? '') . '</span>'
                 . '</div>';
          }
          echo '</div>';
      } else {
          echo '<div style="text-align:center;color:' . $MU . ';font-size:9px;padding:6px 0">Ninguna todavía</div>';
      }
      echo '</div>';
      ?>
    </div>

    <?php
    // AHORA/TICKETS ABIERTOS/APPS PEND./FOLLOW UPS PEND. son "estado ahora
    // mismo" — se quitan al ver un día anterior (ver nota arriba). AHORA se
    // reemplaza por TRABAJÓ ESE DÍA, que sí se puede saber (asistencia).
    $cols = ['EMPLEADO'];
    $cols[] = $esHoy ? 'AHORA' : 'TRABAJÓ';
    $cols[] = 'CITAS PROSPECTO (AGENDADAS)';
    if ($esHoy) $cols[] = 'TICKETS ABIERTOS';
    $cols[] = 'CERRADOS';
    if ($esHoy) $cols[] = 'APPS PEND.';
    $cols[] = 'LLAM. PROSPECTOS';
    $cols[] = 'LLAM. SERVICIO';
    $cols[] = 'LLAM. RETENCIÓN';
    if ($esHoy) $cols[] = 'FOLLOW UPS PEND.';
    ?>
    <div style="overflow-x:auto;background:#fff;border:1px solid <?=$CB?>;border-radius:11px">
    <table style="width:100%;border-collapse:collapse;font-size:9px;white-space:nowrap">
      <thead>
        <tr style="background:<?=$BG?>">
          <?php foreach ($cols as $col):?>
          <th style="padding:8px 10px;text-align:left;font-size:8px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid <?=$CB?>"><?=$col?></th>
          <?php endforeach;?>
        </tr>
      </thead>
      <tbody>
        <?php if (!count($usuarios)):?>
        <tr><td colspan="<?=count($cols)?>" style="padding:20px;text-align:center;color:<?=$MU?>;text-transform:uppercase">SIN EMPLEADOS ACTIVOS</td></tr>
        <?php endif;?>
        <?php foreach ($usuarios as $u):
            $aid = (int)$u['id'];
            $esInactivo = !empty($idsInactivos[$aid]);
            [$estLabel, $estColor] = $estadoAhora($asis[$aid] ?? null);
            $trabajoEseDia = !empty($asis[$aid]['check_in']);
            $tk = $tkAbiertos[$aid] ?? ['total'=>0,'urgentes'=>0];
            $apps = $appsAbiertas[$aid] ?? 0;
            $tkCerr = $tkCerradosHoy[$aid] ?? 0;
            $citasProsp = $citasProspectosHoy[$aid] ?? 0;
            $lp = $llProspHoy[$aid] ?? 0;
            $ls = $llServHoy[$aid]  ?? 0;
            $lr = $llRetHoy[$aid]   ?? 0;
            $fu = $fuPend[$aid]     ?? 0;
        ?>
        <tr style="border-bottom:1px solid <?=$BG?><?=$esInactivo?';opacity:.45':''?>">
          <td style="padding:8px 10px">
            <div style="display:flex;align-items:center;gap:7px">
              <span style="display:inline-flex;width:22px;height:22px;border-radius:50%;background:<?=h($esInactivo?$MU:($u['color']??$P2))?>;color:#fff;font-size:8px;font-weight:900;align-items:center;justify-content:center;flex-shrink:0"><?=h($u['iniciales']??'?')?></span>
              <span style="font-weight:<?=$esInactivo?'400':'900'?>;color:<?=$esInactivo?$MU:$P1?>"><?=h($u['nombre'])?></span>
            </div>
          </td>
          <?php if ($esInactivo):?>
          <td style="padding:8px 10px;color:<?=$MU?>;font-weight:400;font-size:8px;letter-spacing:.3px">INACTIVO</td>
          <?php elseif ($esHoy):?>
          <td style="padding:8px 10px;color:<?=$estColor?>;font-weight:800"><?=$estLabel?></td>
          <?php else:?>
          <td style="padding:8px 10px;color:<?=$trabajoEseDia?$G:$MU?>;font-weight:<?=$trabajoEseDia?'800':'400'?>"><?=$trabajoEseDia?'✓ SÍ':'— NO'?></td>
          <?php endif;?>
          <td style="padding:8px 10px;color:<?=$citasProsp>0?$P1:$MU?>;font-weight:<?=$citasProsp>0?'800':'400'?>"><?=$citasProsp?></td>
          <?php if ($esHoy):?>
          <td style="padding:8px 10px">
            <?=(int)$tk['total']?>
            <?php if ((int)$tk['urgentes'] > 0):?><span style="color:<?=$R?>;font-weight:900"> · <?=(int)$tk['urgentes']?> ⚠</span><?php endif;?>
          </td>
          <?php endif;?>
          <td style="padding:8px 10px;color:<?=$tkCerr>0?$G:$MU?>;font-weight:<?=$tkCerr>0?'800':'400'?>"><?=$tkCerr?></td>
          <?php if ($esHoy):?>
          <td style="padding:8px 10px;color:<?=$apps>0?$P2:$MU?>;font-weight:<?=$apps>0?'800':'400'?>"><?=$apps?></td>
          <?php endif;?>
          <td style="padding:8px 10px;color:<?=$lp>0?$P2:$MU?>;font-weight:<?=$lp>0?'800':'400'?>"><?=$lp?></td>
          <td style="padding:8px 10px;color:<?=$ls>0?$P2:$MU?>;font-weight:<?=$ls>0?'800':'400'?>"><?=$ls?></td>
          <td style="padding:8px 10px;color:<?=$lr>0?$P2:$MU?>;font-weight:<?=$lr>0?'800':'400'?>"><?=$lr?></td>
          <?php if ($esHoy):?>
          <td style="padding:8px 10px;color:<?=$fu>0?$A:$MU?>;font-weight:<?=$fu>0?'800':'400'?>"><?=$fu?></td>
          <?php endif;?>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table>
    </div>
    <div style="font-size:8px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:.5px;margin-top:8px">
      <?php if ($esHoy):?>
      Última actualización: <?=date('h:i:s A')?> · dale a ACTUALIZAR AHORA para traer los datos más recientes
      <?php else:?>
      Reporte del <?=date('d/m/Y', strtotime($hoy))?>
      <?php endif;?>
    </div>
    <?php
    } catch (Throwable $e) {
        ob_end_clean();
        return ['html' => '<div style="padding:30px;text-align:center;color:#B83232;font-size:9px;text-transform:uppercase">No se pudo armar TODAY LIVE — intenta de nuevo en un momento</div>'];
    }
    $html = ob_get_clean();

    return ['html' => $html];
}
