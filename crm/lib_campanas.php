<?php
/* ═══════════════════════════════════════════════════════════════════
 *  LIB_CAMPANAS.PHP — pestaña CAMPAÑAS, cargada aparte.
 *  ─────────────────────────────────────────────────────────────────
 *  Antes esta pestaña armaba, en CADA carga completa de la página (la
 *  veas o no), TODAS las campañas con TODOS sus contactos, las listas
 *  de evento y el reporte de gastos de SMS — casi 1800 líneas entre PHP
 *  y HTML. Eso hacía sentir TODO el CRM lento apenas entrabas (hasta un
 *  clic en un campo de Citas se sentía tardado), no solo esta pestaña.
 *  Ahora se pide aparte, igual que ya se hizo con Tickets/Citas/Follow
 *  Ups/Today Live/Retención (ver loadCampanasPanel() en index.php y
 *  api.php?action=get_campanas_panel).
 *
 *  Los ~860 renglones de funciones JS de esta pestaña (abrir modales,
 *  filtros, importar CSV, envío masivo de SMS...) NO se movieron —
 *  siguen en index.php tal cual, porque son solo definiciones de
 *  función, no datos por-página. Lo único que se movió es la parte
 *  pesada: la tabla armada con datos reales de la base de datos.
 * ═══════════════════════════════════════════════════════════════════ */

if (!function_exists('av')) {
    function av(string $i, string $c, int $z = 28): string {
        return "<div style=\"width:{$z}px;height:{$z}px;border-radius:50%;background:$c;display:flex;align-items:center;justify-content:center;font-size:".round($z*.32)."px;font-weight:900;color:#fff;flex-shrink:0;font-family:'DM Sans',sans-serif\">$i</div>";
    }
}

function render_campanas_panel(PDO $pdo, int $uid, bool $admin): array {
    $P1='#1B4A6B';$P2='#2876A8';$BG='#EBF4F9';$CB='#C8DFF0';$G='#1E7A5C';$R='#B83232';$A='#C07A1A';$MU='#7A90A4';$TX='#1B3A5C';

    ob_start();
    try {
$CANAL_COL=['FACEBOOK'=>['#1B5E8C','#EBF5FB'],'INSTAGRAM'=>['#5B3FAF','#F3F0FB'],'EVENTO'=>['#1E7A5C','#EAF5F0'],'REFERIDO'=>['#C07A1A','#FEF8EE'],'GOOGLE'=>['#B83232','#FDF0EE'],'OTRO'=>['#7A90A4','#F1F1F1']];
$CC_EST=['ACTIVO'=>['#1B5E8C','#EBF5FB','ACTIVO'],'INTERESADO'=>['#1E7A5C','#EAF5F0','INTERESADO'],'CITA'=>['#5B3FAF','#F3F0FB','CITA AGENDADA'],'INSCRITO'=>['#1E7A5C','#EAF5F0','INSCRITO'],'NO_INTERESADO'=>['#B83232','#FDF0EE','NO INTERESADO'],'DESCARTADO'=>['#7A90A4','#F1F1F1','DESCARTADO'],'EN PIPELINE'=>['#C07A1A','#FEF8EE','EN PIPELINE']];
$campanas=[];$cc_by_camp=[];$clog_by_contacto=[];$cc_optout_set=[];
try{
 $campanas=$pdo->query("SELECT c.*, u.iniciales as agente_ini, u.color as agente_color FROM campanas c LEFT JOIN usuarios u ON c.agente_id=u.id ORDER BY FIELD(c.estado,'ACTIVA','PAUSADA','CERRADA'), c.created_at DESC")->fetchAll();
 foreach($pdo->query("SELECT cc.*, u.nombre as agente_nombre, u.iniciales as agente_ini, u.color as agente_color
                       FROM campana_contactos cc LEFT JOIN usuarios u ON cc.agente_id=u.id
                       ORDER BY cc.promovido ASC, cc.id DESC") as $ct)$cc_by_camp[$ct['campana_id']][]=$ct;
 foreach($pdo->query("SELECT * FROM campana_logs ORDER BY id DESC") as $lg)$clog_by_contacto[$lg['contacto_id']][]=$lg;
 // Números que respondieron STOP — para mostrar el aviso en su tarjeta y
 // que quede claro por qué el envío masivo ya no los va a incluir.
 asegurarTablaSmsOptOut($pdo);
 foreach($pdo->query("SELECT telefono, motivo FROM sms_opt_out") as $oo) $cc_optout_set[$oo['telefono']]=$oo['motivo'];
}catch(Exception $e){}
$camp_total=count($campanas);
$camp_activas=count(array_filter($campanas,fn($c)=>$c['estado']==='ACTIVA'));
$cc_total=0;$cc_pipe=0; foreach($cc_by_camp as $list){foreach($list as $ct){$cc_total++; if($ct['promovido'])$cc_pipe++;}}
$cc_all=[]; foreach($cc_by_camp as $list){foreach($list as $ct){$cc_all[$ct['id']]=$ct;}}

// ── FILTROS DE CONTACTOS — se arman aquí, por campaña, para no tocar la
// consulta de arriba: resultados de llamada/canal ya usados (para no listar
// opciones vacías), agentes que han reclamado contactos, y las columnas
// extra que traía el Excel/CSV subido (cada una se vuelve su propio filtro).
$cc_resultados_por_camp = []; // [camp_id] => [resultado, ...]
$cc_agentes_por_camp    = []; // [camp_id] => [nombre_agente, ...]
$cc_extra_por_camp      = []; // [camp_id] => [clave => [valor, ...]]
foreach ($cc_by_camp as $cid => $lista) {
    $resultados = []; $agentes = []; $extra = [];
    foreach ($lista as $ct) {
        if (!empty($clog_by_contacto[$ct['id']])) {
            foreach ($clog_by_contacto[$ct['id']] as $lg) {
                if (!empty($lg['resultado'])) $resultados[$lg['resultado']] = true;
            }
        }
        if (!empty($ct['agente_nombre'])) $agentes[$ct['agente_nombre']] = true;
        if (!empty($ct['datos_extra'])) {
            $ed = json_decode($ct['datos_extra'], true);
            if (is_array($ed)) {
                foreach ($ed as $k => $v) {
                    $v = trim((string)$v);
                    if ($k === '' || $v === '') continue;
                    $extra[$k][$v] = true;
                }
            }
        }
    }
    $cc_resultados_por_camp[$cid] = array_keys($resultados);
    sort($cc_resultados_por_camp[$cid]);
    $cc_agentes_por_camp[$cid] = array_keys($agentes);
    sort($cc_agentes_por_camp[$cid]);
    foreach ($extra as $k => $vals) { $extra[$k] = array_keys($vals); sort($extra[$k]); }
    $cc_extra_por_camp[$cid] = $extra;
}
// Mismos grupos que usa el reporte diario para decidir qué cuenta como
// "contestó" — así el filtro CONTESTÓ/NO CONTESTÓ dice lo mismo que el reporte.
$CC_RESULTADOS_NO_CONTESTO = ['No contestó','Dejó buzón','Teléfono desconectado','Número equivocado'];

// ── EMBUDO HACIA EL CRM — a diferencia de campana_contactos.estado (que se
// queda congelado en "EN PIPELINE" para siempre en cuanto se promueve), esto
// mira el estado REAL y ACTUAL del miembro ya en el CRM (campana_origen_id),
// para saber si de verdad avanzó a EN PROCESO, se activó, o se canceló.
$cc_miembros_por_camp = []; // [camp_id] => ['total'=>n,'en_proceso'=>n,'activos'=>n,'cancelados'=>n,'prospecto'=>n]
// No hace falta el arreglo $members completo (con su JOIN de agente + SOA)
// que ya carga la página para otras cosas — aquí solo hacen falta estas 2 columnas.
$_camp_mb_rows = [];
try { $_camp_mb_rows = $pdo->query("SELECT campana_origen_id, estado FROM miembros WHERE campana_origen_id IS NOT NULL")->fetchAll(); } catch (Throwable $e) {}
foreach ($_camp_mb_rows as $mb) {
    if (empty($mb['campana_origen_id'])) continue;
    $cid = (int)$mb['campana_origen_id'];
    if (!isset($cc_miembros_por_camp[$cid])) $cc_miembros_por_camp[$cid] = ['total'=>0,'en_proceso'=>0,'activos'=>0,'cancelados'=>0,'prospecto'=>0];
    $cc_miembros_por_camp[$cid]['total']++;
    $est = $mb['estado'];
    if (in_array($est, ['IN PROCESS','READY TO ENROLL','PLAN CHANGE','PENDING'], true)) $cc_miembros_por_camp[$cid]['en_proceso']++;
    elseif ($est === 'ACTIVE') $cc_miembros_por_camp[$cid]['activos']++;
    elseif (in_array($est, ['CANCELED','DENIED','CERRADO','DISENROLLED'], true)) $cc_miembros_por_camp[$cid]['cancelados']++;
    else $cc_miembros_por_camp[$cid]['prospecto']++;
}

// ── REPORTE POR CAMPAÑA — resumen de resultados para saber cómo le fue a
// cada lista subida: cuántos contestaron, en qué quedó cada quién, y el
// desempeño de cada agente que trabajó esos contactos.
$cc_reporte_por_camp = [];
foreach ($cc_by_camp as $cid => $lista) {
    $rep = [
        'total' => count($lista), 'contestados' => 0, 'no_contestados' => 0, 'sin_contactar' => 0,
        'habla_ingles' => 0, 'reclamados' => 0, 'sin_reclamar' => 0, 'total_intentos' => 0,
        'por_estado' => [], 'por_resultado' => [], 'por_agente' => [],
    ];
    foreach ($lista as $ct) {
        $logs = $clog_by_contacto[$ct['id']] ?? [];
        $ultimo = $logs[0] ?? null;
        // Esto cuenta CADA intento registrado (llamadas repetidas a la misma
        // persona incluidas) — distinto a "contestados"/"por_resultado" de
        // abajo, que solo miran el ÚLTIMO resultado de cada persona (en qué
        // quedó cada quién ahora, no cuántas veces se le marcó).
        $rep['total_intentos'] += count($logs);
        if ($ultimo) {
            $esNoContesto = in_array($ultimo['resultado'], $CC_RESULTADOS_NO_CONTESTO, true);
            $rep[$esNoContesto ? 'no_contestados' : 'contestados']++;
            $r = $ultimo['resultado'] ?: '(SIN RESULTADO)';
            $rep['por_resultado'][$r] = ($rep['por_resultado'][$r] ?? 0) + 1;
        } else {
            $rep['sin_contactar']++;
        }
        if (!empty($ct['habla_ingles'])) $rep['habla_ingles']++;
        $est = $ct['estado'] ?: '(SIN ESTADO)';
        $rep['por_estado'][$est] = ($rep['por_estado'][$est] ?? 0) + 1;
        if (!empty($ct['agente_id'])) {
            $rep['reclamados']++;
            $an = $ct['agente_nombre'] ?: '(AGENTE #'.$ct['agente_id'].')';
            if (!isset($rep['por_agente'][$an])) $rep['por_agente'][$an] = ['total'=>0,'contestados'=>0,'inscritos'=>0];
            $rep['por_agente'][$an]['total']++;
            if ($ultimo && !in_array($ultimo['resultado'], $CC_RESULTADOS_NO_CONTESTO, true)) $rep['por_agente'][$an]['contestados']++;
            if ($est === 'INSCRITO') $rep['por_agente'][$an]['inscritos']++;
        } else {
            $rep['sin_reclamar']++;
        }
    }
    arsort($rep['por_resultado']);
    uasort($rep['por_agente'], fn($a,$b) => $b['total'] <=> $a['total']);
    $rep['miembros'] = $cc_miembros_por_camp[$cid] ?? ['total'=>0,'en_proceso'=>0,'activos'=>0,'cancelados'=>0,'prospecto'=>0];
    $cc_reporte_por_camp[$cid] = $rep;
}

// ── LISTAS DE EVENTO (confirmaciones/asistencia de miembros ya existentes) ──
$LEM_ESTADOS = ['PENDIENTE'=>['#7A90A4','#F1F1F1'],'CONFIRMADO'=>['#1E7A5C','#EAF5F0'],'NO CONFIRMADO'=>['#B83232','#FDF0EE'],'ASISTIÓ'=>['#1B5E8C','#EBF5FB'],'NO ASISTIÓ'=>['#993C1D','#FAECE7']];
$listas_evento=[]; $lem_by_lista=[]; $lec_by_lista=[]; $lev_by_ml=[];
try{
 $listas_evento=$pdo->query("SELECT le.*, u.iniciales as agente_ini, u.color as agente_color FROM listas_evento le LEFT JOIN usuarios u ON le.agente_id=u.id ORDER BY le.fecha DESC, le.created_at DESC")->fetchAll();
 foreach($pdo->query("SELECT lem.*, m.nombre, m.apellido, m.telefono,
                       ue.nombre as editor_nombre, ue.iniciales as editor_ini, ue.color as editor_color
                       FROM lista_evento_miembros lem JOIN miembros m ON m.id=lem.miembro_id
                       LEFT JOIN usuarios ue ON ue.id=lem.ultimo_editor_id
                       ORDER BY m.apellido, m.nombre") as $lm) $lem_by_lista[$lm['lista_id']][]=$lm;
 // Columnas extra que cada lista se armó a su gusto, y el valor guardado
 // de cada una por cada miembro agregado.
 foreach($pdo->query("SELECT * FROM lista_evento_columnas ORDER BY orden, id") as $lc) $lec_by_lista[$lc['lista_id']][]=$lc;
 foreach($pdo->query("SELECT * FROM lista_evento_valores") as $lv) $lev_by_ml[$lv['miembro_lista_id']][$lv['columna_id']]=$lv['valor'];
}catch(Exception $e){}
$le_total=count($listas_evento);
$le_miembros_total=0; foreach($lem_by_lista as $l) $le_miembros_total+=count($l);

// ── GASTOS DE SMS/MMS (Twilio) — historial ESTIMADO para Campañas ──────
// "Estimado" porque Twilio no manda el precio real en el status callback
// (solo consultando cada mensaje por separado en su API) — se calcula con
// las tarifas típicas de EEUU. Para el cobro exacto, la consola de Twilio
// (Billing) siempre es la fuente real.
$gastos_total=0.0; $gastos_mes=0.0;
$gastos_sms_out=['n'=>0,'costo'=>0.0]; $gastos_mms_out=['n'=>0,'costo'=>0.0]; $gastos_in=['n'=>0,'costo'=>0.0];
$gastos_por_campana=[]; $gastos_por_mes=[];
try{
    asegurarTablaSmsMensajes($pdo);
    // Rellena de una vez el costo de los mensajes de COMUNICACIÓN que ya
    // existían antes de este reporte — así el historial no empieza en
    // ceros. Solo hace trabajo real la primera vez; después no encuentra
    // nada pendiente y no vuelve a tocar la tabla.
    sms_backfill_costos_historicos($pdo);
    $gastos_total = (float)$pdo->query("SELECT COALESCE(SUM(costo_estimado),0) FROM sms_mensajes")->fetchColumn();
    $gmq = $pdo->prepare("SELECT COALESCE(SUM(costo_estimado),0) FROM sms_mensajes WHERE created_at >= ?");
    $gmq->execute([date('Y-m-01')]);
    $gastos_mes = (float)$gmq->fetchColumn();

    // "Enviados" NO cuenta los que Twilio rechazó de plano (esos nunca se
    // mandaron de verdad, cuestan $0) — si no, el número de "enviados" se
    // ve inflado con intentos fallidos aunque el monto en dólares ya salía
    // bien (un fallido siempre suma $0, así que filtrarlo del conteo no
    // cambia ningún total, solo lo hace más honesto).
    foreach($pdo->query("SELECT direccion, es_mms, COUNT(*) n, COALESCE(SUM(costo_estimado),0) costo
                          FROM sms_mensajes
                          WHERE NOT (direccion='SALIENTE' AND COALESCE(estado,'')='error')
                          GROUP BY direccion, es_mms") as $gr){
        if($gr['direccion']==='SALIENTE' && !$gr['es_mms'])      $gastos_sms_out=['n'=>(int)$gr['n'],'costo'=>(float)$gr['costo']];
        elseif($gr['direccion']==='SALIENTE' && $gr['es_mms'])   $gastos_mms_out=['n'=>(int)$gr['n'],'costo'=>(float)$gr['costo']];
        elseif($gr['direccion']==='ENTRANTE'){ $gastos_in['n']+=(int)$gr['n']; $gastos_in['costo']+=(float)$gr['costo']; }
    }

    // LEFT JOIN (no INNER): si una campaña se borra, sus mensajes ya
    // enviados (y su costo) NO deben desaparecer de este reporte — con
    // INNER JOIN se esfumaban en cuanto se eliminaba la campaña, aunque el
    // dinero sí se haya gastado de verdad. Se agrupa por campana_id (no
    // por nombre) para no mezclar dos campañas distintas que ya no existen
    // bajo la misma etiqueta genérica.
    $gastos_por_campana = $pdo->query("SELECT s.campana_id AS id, COALESCE(c.nombre,'CAMPAÑA ELIMINADA') AS nombre, COUNT(s.id) n, COALESCE(SUM(s.costo_estimado),0) costo
                                        FROM sms_mensajes s LEFT JOIN campanas c ON s.campana_id=c.id
                                        WHERE s.direccion='SALIENTE' AND COALESCE(s.estado,'')!='error' AND s.campana_id IS NOT NULL
                                        GROUP BY s.campana_id, c.nombre ORDER BY costo DESC")->fetchAll(PDO::FETCH_ASSOC);

    $gastos_por_mes = $pdo->query("SELECT DATE_FORMAT(created_at,'%Y-%m') mes,
                                           SUM(CASE WHEN direccion='SALIENTE' AND COALESCE(estado,'')!='error' THEN 1 ELSE 0 END) enviados,
                                           SUM(CASE WHEN direccion='ENTRANTE' THEN 1 ELSE 0 END) recibidos,
                                           COALESCE(SUM(costo_estimado),0) costo
                                    FROM sms_mensajes GROUP BY mes ORDER BY mes DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){}
?>
<div style="display:flex;gap:0;margin-bottom:13px;background:#fff;border:1px solid <?=$CB?>;border-radius:13px;overflow:hidden">
  <button id="cvtab-campanas" onclick="setCampVista('campanas')"
    style="flex:1;padding:12px 16px;border:none;cursor:pointer;font-size:9px;font-weight:900;letter-spacing:2px;text-transform:uppercase;font-family:'DM Sans',sans-serif;background:<?=$P1?>;color:#fff;border-right:1px solid <?=$CB?>;display:flex;align-items:center;justify-content:center;gap:6px">
    📣 CAMPAÑAS <span id="cvtab-campanas-cnt" style="background:rgba(255,255,255,.25);border-radius:20px;padding:1px 8px;font-size:8px"><?=$camp_total?></span>
  </button>
  <button id="cvtab-listas" onclick="setCampVista('listas')"
    style="flex:1;padding:12px 16px;border:none;cursor:pointer;font-size:9px;font-weight:900;letter-spacing:2px;text-transform:uppercase;font-family:'DM Sans',sans-serif;background:#fff;color:<?=$MU?>;border-right:1px solid <?=$CB?>;display:flex;align-items:center;justify-content:center;gap:6px">
    🎉 LISTAS DE EVENTO <span id="cvtab-listas-cnt" style="background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:20px;padding:1px 8px;font-size:8px"><?=$le_total?></span>
  </button>
  <button id="cvtab-gastos" onclick="setCampVista('gastos')"
    style="flex:1;padding:12px 16px;border:none;cursor:pointer;font-size:9px;font-weight:900;letter-spacing:2px;text-transform:uppercase;font-family:'DM Sans',sans-serif;background:#fff;color:<?=$MU?>;display:flex;align-items:center;justify-content:center;gap:6px">
    💰 GASTOS SMS <span id="cvtab-gastos-cnt" style="background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:20px;padding:1px 8px;font-size:8px">$<?=number_format($gastos_total,2)?></span>
  </button>
</div>
<div id="camp-view-campanas">
<div class="card" style="border-top:3px solid <?=$P1?>;margin-bottom:14px;padding:13px 16px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:9px">
    <div>
      <div class="card-title" style="font-size:11px">📣 CAMPAÑAS</div>
      <div style="font-size:8px;color:<?=$MU?>;letter-spacing:1px;text-transform:uppercase;margin-top:3px"><?=$camp_total?> CAMPAÑAS · <?=$camp_activas?> ACTIVAS · <?=$cc_total?> CONTACTOS · <?=$cc_pipe?> EN PIPELINE</div>
    </div>
    <button class="btn btn-p btn-sm" onclick="openCampForm()">+ NUEVA CAMPAÑA</button>
  </div>
  <div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:11px;align-items:center">
    <div class="form-group" style="max-width:380px;flex:1;min-width:220px;margin-bottom:0">
      <input type="text" id="camp-global-search" class="form-input" placeholder="🔎 Buscar en TODAS las campañas — nombre, teléfono, notas, cualquier dato..." autocomplete="off" oninput="_debouncedCall('campGlobal',filterCampGlobal)">
    </div>
    <button class="btn btn-p btn-sm camp-filter" data-active="1" data-filtro="todas" onclick="filterCamp('todas',this)">TODAS</button>
    <button class="btn btn-gh btn-sm camp-filter" data-filtro="ACTIVA" onclick="filterCamp('ACTIVA',this)">ACTIVAS</button>
    <button class="btn btn-gh btn-sm camp-filter" data-filtro="CERRADA" onclick="filterCamp('CERRADA',this)">CERRADAS</button>
  </div>
  <div id="camp-global-cnt" style="font-size:8px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-top:7px"></div>
</div>
<div style="background:#EBF5FB;border:1px solid #A9D0E8;border-left:4px solid #1B5E8C;border-radius:10px;padding:9px 14px;margin-bottom:14px;font-size:8px;color:#1B5E8C;letter-spacing:.5px;text-transform:uppercase;line-height:1.6">
  ℹ️ Los contactos de campaña viven aquí en su propio pipeline. Solo entran al PIPELINE real del CRM cuando presionas <b>▲ PIPELINE</b> — ahí se crea el miembro como prospecto.
</div>
<?php if(empty($campanas)):?>
<div class="card" style="padding:30px;text-align:center;font-size:9px;color:<?=$MU?>;text-transform:uppercase">📣 NO HAY CAMPAÑAS — CREA UNA CON "NUEVA CAMPAÑA"</div>
<?php endif;?>
<?php foreach($campanas as $c):
  $cl=$CANAL_COL[$c['canal']??'OTRO']??['#7A90A4','#F1F1F1'];
  $contactos=$cc_by_camp[$c['id']]??[];
  $n_ct=count($contactos);
  $n_pipe=count(array_filter($contactos,fn($x)=>$x['promovido']));
  $camp_costo=(float)($c['costo']??0);
  $cpl=($camp_costo>0 && $n_ct>0)?$camp_costo/$n_ct:null;
  $est_c=$c['estado']; $estb=$est_c==='ACTIVA'?['#1E7A5C','#EAF5F0']:($est_c==='PAUSADA'?['#C07A1A','#FEF8EE']:['#7A90A4','#F1F1F1']);
?>
<div class="card camp-card" data-estado="<?=h($c['estado'])?>" data-search="<?=h(strtolower($c['nombre'].' '.($c['canal']??'').' '.($c['descripcion']??'')))?>" style="margin-bottom:10px;border-left:4px solid <?=$cl[0]?>">
  <div class="card-header" style="cursor:pointer;flex-wrap:wrap;gap:9px" onclick="campToggleCard(<?=$c['id']?>)">
    <div style="display:flex;align-items:center;gap:10px;min-width:0;flex:1">
      <div style="min-width:0">
        <div class="card-title" style="font-size:10px;white-space:normal"><?=h($c['nombre'])?></div>
        <div style="display:flex;gap:5px;flex-wrap:wrap;align-items:center;margin-top:4px">
          <span style="background:<?=$cl[1]?>;color:<?=$cl[0]?>;border-radius:20px;padding:1px 8px;font-size:8px;font-weight:900"><?=h($c['canal']??'OTRO')?></span>
          <span style="background:<?=$estb[1]?>;color:<?=$estb[0]?>;border-radius:20px;padding:1px 8px;font-size:8px;font-weight:900"><?=h($c['estado'])?></span>
          <span style="font-size:8px;color:<?=$MU?>">👥 <?=$n_ct?> CONTACTOS</span>
          <?php if($n_pipe>0):?><span style="font-size:8px;font-weight:900;color:#C07A1A">▲ <?=$n_pipe?> EN PIPELINE</span><?php endif;?>
          <?php if($cpl!==null):?><span style="font-size:8px;font-weight:900;color:<?=$cpl<=25?'#1E7A5C':'#B83232'?>">$<?=number_format($cpl,2)?>/LEAD</span><?php endif;?>
        </div>
      </div>
    </div>
    <span style="font-size:13px;color:<?=$MU?>;flex-shrink:0">▾</span>
  </div>
  <div id="camp-body-<?=$c['id']?>" style="display:none;padding:13px 17px;border-top:1px solid <?=$CB?>">
    <?php if(!empty($c['descripcion'])):?><div style="font-size:9px;color:<?=$TX?>;line-height:1.6;margin-bottom:11px"><?=h($c['descripcion'])?></div><?php endif;?>
    <div style="display:flex;gap:7px;margin-bottom:13px;flex-wrap:wrap">
      <button class="btn btn-p btn-sm" onclick="openCcForm(<?=$c['id']?>)">+ NUEVO CONTACTO</button>
      <button class="btn btn-sky btn-sm" onclick="openCcImport(<?=$c['id']?>)">⤒ SUBIR LISTA (CSV)</button>
      <button class="btn btn-gh btn-sm" onclick="vaciarContactosCampana(<?=$c['id']?>,'<?=h(addslashes($c['nombre']))?>')" title="Borra todos los contactos de esta campaña — úsalo si una lista se subió mal, antes de volver a subirla">🗑 VACIAR CONTACTOS</button>
      <button class="btn btn-sky btn-sm" onclick="abrirEnvioMasivo(<?=$c['id']?>,'<?=h(addslashes($c['nombre']))?>')">📤 ENVIAR SMS / FLYER</button>
      <button class="btn btn-gh btn-sm" onclick="toggleCcReporte(<?=$c['id']?>,this)">📊 REPORTE</button>
      <button class="btn btn-gh btn-sm" onclick="openCampForm(<?=$c['id']?>)">✎ EDITAR CAMPAÑA</button>
      <button class="btn btn-re btn-sm" onclick="deleteCampana(<?=$c['id']?>)">✕ ELIMINAR</button>
    </div>
    <?php $rep = $cc_reporte_por_camp[$c['id']] ?? null; ?>
    <div id="cc-reporte-<?=$c['id']?>" style="display:none;background:#fff;border:1px solid <?=$CB?>;border-radius:10px;padding:14px 16px;margin-bottom:13px">
      <?php if(!$rep || $rep['total']===0):?>
      <div style="font-size:9px;color:<?=$MU?>;text-transform:uppercase">SIN CONTACTOS TODAVÍA — NO HAY NADA QUE REPORTAR</div>
      <?php else:
        $pct = fn($n) => $rep['total'] ? round($n*100/$rep['total']) : 0;
      ?>
      <div style="font-size:10px;font-weight:900;color:<?=$P1?>;letter-spacing:1px;text-transform:uppercase;margin-bottom:2px">📊 RESULTADOS DE ESTA LISTA — <?=$rep['total']?> CONTACTOS</div>
      <div style="font-size:8px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">☎ <?=$rep['total_intentos']?> LLAMADAS/INTENTOS REGISTRADOS EN TOTAL <?php if($rep['total_intentos']>$rep['contestados']+$rep['no_contestados']):?>(a veces se marcó más de una vez a la misma persona)<?php endif;?></div>
      <div style="font-size:7px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">👆 TOCA CUALQUIER CIFRA PARA VER A ESAS PERSONAS EN LA LISTA</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;margin-bottom:16px">
        <?php foreach([
          ['CONTESTARON', $rep['contestados'], $G, '__contestado', 'SI'],
          ['NO CONTESTARON', $rep['no_contestados'], $R, '__contestado', 'NO'],
          ['SIN CONTACTAR', $rep['sin_contactar'], $MU, '__contestado', '__SIN_CONTACTAR__'],
          ['HABLA INGLÉS', $rep['habla_ingles'], '#1B5E8C', '__habla_ingles', '1'],
          ['RECLAMADOS', $rep['reclamados'], $A, '__agente', '__CUALQUIERA__'],
          ['SIN RECLAMAR', $rep['sin_reclamar'], $MU, '__agente', '__SIN_RECLAMAR__'],
        ] as [$lbl,$val,$col,$fkey,$fval]):?>
        <div onclick="ccVerEnLista(this)" data-cc-camp="<?=$c['id']?>" data-cc-key="<?=$fkey?>" data-cc-val="<?=h($fval)?>" style="cursor:pointer;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:9px;padding:9px 11px">
          <div style="font-size:16px;font-weight:900;color:<?=$col?>"><?=$val?></div>
          <div style="font-size:7px;font-weight:800;color:<?=$MU?>;letter-spacing:.5px;text-transform:uppercase;margin-top:1px"><?=$lbl?> (<?=$pct($val)?>%)</div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if($rep['por_resultado']):?>
      <div style="font-size:8px;font-weight:900;color:<?=$MU?>;letter-spacing:1px;text-transform:uppercase;margin-bottom:6px">POR RESULTADO</div>
      <div style="margin-bottom:16px">
        <?php foreach($rep['por_resultado'] as $r=>$n):?>
        <div onclick="ccVerEnLista(this)" data-cc-camp="<?=$c['id']?>" data-cc-key="__resultado" data-cc-val="<?=h($r)?>" style="cursor:pointer;display:flex;align-items:center;gap:8px;margin-bottom:4px">
          <div style="width:150px;font-size:9px;color:<?=$TX?>;flex-shrink:0"><?=h($r)?></div>
          <div style="flex:1;background:<?=$BG?>;border-radius:5px;overflow:hidden;height:14px"><div style="width:<?=$pct($n)?>%;background:<?=$P2?>;height:100%"></div></div>
          <div style="width:50px;font-size:9px;font-weight:800;color:<?=$TX?>;text-align:right;flex-shrink:0"><?=$n?> (<?=$pct($n)?>%)</div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if($rep['por_agente']):?>
      <div style="font-size:8px;font-weight:900;color:<?=$MU?>;letter-spacing:1px;text-transform:uppercase;margin-bottom:6px">POR AGENTE</div>
      <table style="width:100%;font-size:9px"><tr><th style="text-align:left;color:<?=$MU?>;font-size:7px;text-transform:uppercase;padding-bottom:4px">AGENTE</th><th style="text-align:right;color:<?=$MU?>;font-size:7px;text-transform:uppercase;padding-bottom:4px">RECLAMADOS</th><th style="text-align:right;color:<?=$MU?>;font-size:7px;text-transform:uppercase;padding-bottom:4px">CONTESTARON</th><th style="text-align:right;color:<?=$MU?>;font-size:7px;text-transform:uppercase;padding-bottom:4px">INSCRITOS</th></tr>
        <?php foreach($rep['por_agente'] as $an=>$st):?>
        <tr onclick="ccVerEnLista(this)" data-cc-camp="<?=$c['id']?>" data-cc-key="__agente" data-cc-val="<?=h($an)?>" style="cursor:pointer">
          <td style="padding:3px 0;color:<?=$TX?>;font-weight:700"><?=h($an)?></td><td style="text-align:right;color:<?=$TX?>"><?=$st['total']?></td><td style="text-align:right;color:<?=$TX?>"><?=$st['contestados']?></td><td style="text-align:right;color:<?=$G?>;font-weight:800"><?=$st['inscritos']?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
      <?php $rm = $rep['miembros']; if($rm['total']>0):?>
      <div style="font-size:8px;font-weight:900;color:<?=$MU?>;letter-spacing:1px;text-transform:uppercase;margin:16px 0 6px;border-top:1px solid <?=$CB?>;padding-top:14px">EMBUDO HACIA EL CRM — QUÉ PASÓ DESPUÉS DE PASARLOS AL PIPELINE</div>
      <div style="font-size:7px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">A diferencia de las tarjetas de arriba, esto mira el estado ACTUAL del miembro ya en el CRM — si avanzó, se activó, o se canceló.</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px">
        <?php foreach([
          ['EN PIPELINE (TOTAL)', $rm['total'], $A, ''],
          ['PROSPECTO', $rm['prospecto'], $MU, 'PROSPECTO'],
          ['EN PROCESO', $rm['en_proceso'], '#1B5E8C', 'PROCESO'],
          ['ACTIVOS/APROBADOS', $rm['activos'], $G, 'ACTIVOS'],
          ['CANCELADOS', $rm['cancelados'], $R, 'CANCELADOS'],
        ] as [$lbl,$val,$col,$grupo]):?>
        <div onclick="verMiembrosCampana(<?=$c['id']?>,'<?=$grupo?>','<?=h(addslashes($c['nombre']))?>')" style="cursor:pointer;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:9px;padding:9px 11px">
          <div style="font-size:16px;font-weight:900;color:<?=$col?>"><?=$val?></div>
          <div style="font-size:7px;font-weight:800;color:<?=$MU?>;letter-spacing:.5px;text-transform:uppercase;margin-top:1px"><?=$lbl?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php if(empty($contactos)):?>
    <div style="font-size:9px;color:<?=$MU?>;text-transform:uppercase;padding:8px 0">SIN CONTACTOS — AGREGA EL PRIMERO</div>
    <?php else:?>
    <?php $cc_num_extra = count($cc_extra_por_camp[$c['id']] ?? []); ?>
    <div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
      <div class="form-group" style="max-width:320px;flex:1;margin-bottom:0">
        <input type="text" class="form-input cc-search-input" placeholder="🔎 Buscar por nombre o teléfono..." autocomplete="off" oninput="_debouncedCall('cc-<?=$c['id']?>',filterCc.bind(null,this,<?=$c['id']?>))">
      </div>
      <button type="button" class="btn btn-gh btn-sm" onclick="toggleCcFiltros(<?=$c['id']?>,this)">▾ FILTROS<?=$cc_num_extra?' (+'.$cc_num_extra.' DEL ARCHIVO)':''?></button>
      <button type="button" class="btn btn-gh btn-sm" onclick="limpiarCcFiltros(<?=$c['id']?>)" style="color:#B83232;border-color:#EFA09A">✕ LIMPIAR FILTROS</button>
    </div>
    <div id="cc-filtros-<?=$c['id']?>" style="display:none;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:10px;padding:11px 13px;margin-bottom:10px">
      <div style="font-size:7px;font-weight:900;color:<?=$MU?>;letter-spacing:1px;text-transform:uppercase;margin-bottom:6px">FILTROS GENERALES</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:7px;margin-bottom:<?=$cc_num_extra?'12px':'0'?>">
        <select class="form-input cc-filter-sel" data-key="__resultado" onchange="filterCc(null,<?=$c['id']?>)" style="font-size:9px;padding:6px 9px">
          <option value="">RESULTADO: TODOS</option>
          <option value="__SIN_CONTACTAR__">SIN CONTACTAR</option>
          <?php foreach ($cc_resultados_por_camp[$c['id']] ?? [] as $r): ?>
          <option value="<?=h($r)?>"><?=h($r)?></option>
          <?php endforeach; ?>
        </select>
        <select class="form-input cc-filter-sel" data-key="__contestado" onchange="filterCc(null,<?=$c['id']?>)" style="font-size:9px;padding:6px 9px">
          <option value="">CONTESTÓ: TODOS</option>
          <option value="SI">SÍ CONTESTÓ</option>
          <option value="NO">NO CONTESTÓ</option>
          <option value="__SIN_CONTACTAR__">SIN CONTACTAR</option>
        </select>
        <select class="form-input cc-filter-sel" data-key="__agente" onchange="filterCc(null,<?=$c['id']?>)" style="font-size:9px;padding:6px 9px">
          <option value="">RECLAMADO POR: TODOS</option>
          <option value="__SIN_RECLAMAR__">SIN RECLAMAR</option>
          <?php foreach ($cc_agentes_por_camp[$c['id']] ?? [] as $an): ?>
          <option value="<?=h($an)?>"><?=h($an)?></option>
          <?php endforeach; ?>
        </select>
        <select class="form-input cc-filter-sel" data-key="__habla_ingles" onchange="filterCc(null,<?=$c['id']?>)" style="font-size:9px;padding:6px 9px">
          <option value="">🇬🇧 HABLA INGLÉS: TODOS</option>
          <option value="1">SÍ HABLA INGLÉS</option>
          <option value="0">NO / SIN MARCAR</option>
        </select>
      </div>
      <?php if ($cc_num_extra): ?>
      <div style="font-size:7px;font-weight:900;color:<?=$MU?>;letter-spacing:1px;text-transform:uppercase;margin-bottom:6px;border-top:1px solid <?=$CB?>;padding-top:10px">COLUMNAS DEL ARCHIVO SUBIDO</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:7px">
        <?php foreach ($cc_extra_por_camp[$c['id']] ?? [] as $ekey => $evals): ?>
        <select class="form-input cc-filter-sel" data-key="<?=h($ekey)?>" onchange="filterCc(null,<?=$c['id']?>)" style="font-size:9px;padding:6px 9px">
          <option value=""><?=h(mb_strtoupper($ekey))?>: TODOS</option>
          <?php foreach ($evals as $ev): ?>
          <option value="<?=h($ev)?>"><?=h($ev)?></option>
          <?php endforeach; ?>
        </select>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="cc-empty-filter" style="display:none;font-size:9px;color:<?=$MU?>;text-transform:uppercase;padding:8px 0">NINGÚN CONTACTO COINCIDE CON LA BÚSQUEDA</div>
    <?php foreach($contactos as $ct):
      $ce=$CC_EST[$ct['estado']]??['#7A90A4','#F1F1F1',$ct['estado']];
      $ph=preg_replace('/[^0-9]/','',$ct['telefono']??'');
      // wa.me necesita el código de país. Si el teléfono ya lo trae (formato
      // nuevo +1XXXXXXXXXX → 11 dígitos), no se le agrega otro "1" al frente.
      $ph_wa=(strlen($ph)===10)?('1'.$ph):$ph;
      $logs=$clog_by_contacto[$ct['id']]??[]; $lastlog=$logs[0]??null;
      $nm=trim($ct['nombre'].' '.($ct['apellido']??''));
      // Datos extra del archivo importado (columnas que no eran nombre/
      // teléfono/email/notas) — se guardan aparte para mostrarlas como
      // etiquetas en vez de mezcladas con las notas reales.
      $extraChips=[]; $notasReales=$ct['notas']??'';
      if(!empty($ct['datos_extra'])){
        $dec=json_decode($ct['datos_extra'],true);
        if(is_array($dec)) $extraChips=$dec;
      } elseif(!empty($notasReales) && str_contains($notasReales,' · ')){
        // Compatibilidad con contactos importados antes de este cambio,
        // cuando los datos extra venían mezclados dentro de las notas.
        $partes=explode("\n",$notasReales,2);
        $blobExtras=count($partes)>1?$partes[1]:$partes[0];
        $notasPrevias=count($partes)>1?$partes[0]:'';
        $tmp=[]; $parsedOk=true;
        foreach(explode(' · ',$blobExtras) as $seg){
          if(preg_match('/^([^:]{1,60}):\s?(.*)$/s',trim($seg),$m)) $tmp[trim($m[1])]=trim($m[2]);
          else { $parsedOk=false; break; }
        }
        if($parsedOk && $tmp){ $extraChips=$tmp; $notasReales=$notasPrevias; }
      }
      // Para los filtros: mismo criterio "contestó" que usa el reporte diario.
      $ct_contestado = $lastlog ? (in_array($lastlog['resultado'], $CC_RESULTADOS_NO_CONTESTO, true) ? 'NO' : 'SI') : '';
      $extraParaFiltro = [];
      foreach ($extraChips as $ek => $ev) { $extraParaFiltro[$ek] = trim((string)$ev); }
    ?>
    <?php
      // Búsqueda "por cualquier dato" — no solo nombre/teléfono: también notas
      // reales y todas las columnas extra que trajo el CSV subido (ciudad,
      // idioma, aseguranza actual, lo que sea que haya traído la lista).
      $ct_search_extra = implode(' ', array_map('strval', $extraChips));
      $ct_search = strtolower(trim($nm.' '.($ct['telefono']??'').' '.($ct['email']??'').' '.$notasReales.' '.($ct['agente_nombre']??'').' '.$ct_search_extra));
    ?>
    <div class="cc-contact-card" id="cc-card-<?=$ct['id']?>" data-search="<?=h($ct_search)?>" data-estado="<?=h($ct['estado']??'')?>" data-agente="<?=h($ct['agente_nombre']??'')?>" data-ultimo-resultado="<?=h($lastlog['resultado']??'')?>" data-contestado="<?=h($ct_contestado)?>" data-habla-ingles="<?=!empty($ct['habla_ingles'])?'1':'0'?>" data-extra="<?=h(json_encode($extraParaFiltro,JSON_UNESCAPED_UNICODE))?>" style="background:#fff;border:1px solid <?=$CB?>;border-radius:10px;padding:10px 13px;margin-bottom:7px">
      <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap">
        <div style="flex:1;min-width:0">
          <div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap">
            <span onclick="verPerfilContacto(<?=$ct['id']?>)" style="font-weight:900;font-size:10px;color:<?=$P1?>;cursor:pointer;text-decoration:underline;text-decoration-color:transparent" onmouseover="this.style.textDecorationColor='<?=$P1?>'" onmouseout="this.style.textDecorationColor='transparent'" title="Ver perfil completo"><?=h($nm)?></span>
            <span id="cc-estado-<?=$ct['id']?>">
            <?php if($ct['promovido']):?>
              <span style="background:<?=$ce[1]?>;color:<?=$ce[0]?>;border-radius:20px;padding:1px 8px;font-size:8px;font-weight:900"><?=$ce[2]?></span>
            <?php else:?>
              <select class="form-input" style="font-size:8px;padding:3px 6px;width:auto;display:inline-block;text-transform:none" onchange="ccEstado(<?=$ct['id']?>,this.value)">
                <?php foreach(['ACTIVO','INTERESADO','CITA','INSCRITO','NO_INTERESADO','DESCARTADO'] as $es):?><option value="<?=$es?>"<?=$ct['estado']===$es?' selected':''?>><?=str_replace('_',' ',$es)?></option><?php endforeach;?>
              </select>
            <?php endif;?>
            </span>
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:3px;align-items:center">
            <?php if($ct['telefono']):?><span style="font-size:8px;color:<?=$MU?>">📞 <?=h($ct['telefono'])?></span><?php endif;?>
            <?php if(!empty($ct['telefono']) && isset($cc_optout_set[$ct['telefono']])):?><span style="background:#FDF0EE;color:#B83232;border:1px solid #EFA09A;border-radius:20px;padding:1px 8px;font-size:8px;font-weight:900" title="<?=h($cc_optout_set[$ct['telefono']] ?: 'No se le puede volver a escribir')?> — no se le incluye en el envío masivo">🚫 NO CONTACTAR</span><?php endif;?>
            <span id="cc-ultimo-<?=$ct['id']?>" style="font-size:8px;color:<?=$MU?><?=$lastlog?'':';display:none'?>">ÚLTIMO: <?=$lastlog?h($lastlog['canal']).' — '.h($lastlog['resultado']):''?></span>
            <?php $hi=!empty($ct['habla_ingles']);?>
            <button type="button" class="btn btn-sm" data-on="<?=$hi?'1':'0'?>" onclick="toggleHablaIngles(<?=$ct['id']?>,this)" style="font-size:7px;padding:2px 8px;background:<?=$hi?'#1B5E8C':'#fff'?>;color:<?=$hi?'#fff':'#7A90A4'?>;border:1px solid <?=$hi?'#1B5E8C':'#C8DFF0'?>" title="Marca si esta persona habla inglés">🇬🇧 HABLA INGLÉS</button>
            <span id="cc-reclamo-<?=$ct['id']?>">
            <?php if(!empty($ct['agente_id'])):?>
              <span style="display:inline-flex;align-items:center;gap:4px;background:#F3F0FB;color:#5B3FAF;border:1px solid #C2B0E8;border-radius:20px;padding:1px 8px 1px 3px;font-size:8px;font-weight:900">
                <?=av(h($ct['agente_ini']??'?'),h($ct['agente_color']??$P2),14)?> 🙋 <?=h(explode(' ',$ct['agente_nombre']??'?')[0])?>
                <?php if((int)$ct['agente_id']===(int)$uid || $admin):?><a href="javascript:void(0)" onclick="liberarContacto(<?=$ct['id']?>)" style="color:#5B3FAF;text-decoration:underline;margin-left:2px">liberar</a><?php endif;?>
              </span>
            <?php elseif(!$ct['promovido']):?>
              <button class="btn btn-gh btn-sm" style="font-size:7px;padding:2px 8px" onclick="reclamarContacto(<?=$ct['id']?>)">🙋 RECLAMAR</button>
            <?php endif;?>
            </span>
          </div>
        </div>
        <div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center">
          <?php if($ct['telefono']):?>
          <a href="tel:<?=h($ct['telefono'])?>" class="btn btn-bl btn-sm" style="font-size:8px">LLAMAR</a>
          <a href="https://wa.me/<?=$ph_wa?>" target="_blank" class="btn btn-gr btn-sm" style="font-size:8px">WA</a>
          <?php endif;?>
          <span id="cc-acciones-<?=$ct['id']?>">
          <?php if($ct['promovido']):?>
            <button class="btn btn-am btn-sm" style="font-size:8px" onclick="openProfile(<?=$ct['miembro_id']?>)">◉ VER PERFIL</button>
          <?php else:?>
            <button class="btn btn-gh btn-sm" style="font-size:8px" onclick="openCcLog(<?=$ct['campana_id']?>,<?=$ct['id']?>,'<?=h(addslashes($nm))?>')">📋 REGISTRAR</button>
            <button class="btn btn-p btn-sm" style="font-size:8px" onclick="promoverContacto(<?=$ct['id']?>,'<?=h(addslashes($nm))?>')">▲ PIPELINE</button>
            <button class="btn btn-gh btn-sm" style="font-size:8px" onclick="openCcForm(<?=$ct['campana_id']?>,<?=$ct['id']?>)">✎</button>
            <button class="btn btn-re btn-sm" style="font-size:8px" onclick="deleteContacto(<?=$ct['id']?>)">✕</button>
          <?php endif;?>
          </span>
          <button class="btn btn-am btn-sm" style="font-size:8px" title="Agregar follow up" onclick="abrirFollowUpForm('CAMPANA',<?=(int)$ct['id']?>,<?=(int)($ct['miembro_id']??0)?>,'<?=h(addslashes($nm))?>','<?=h(addslashes($ct['telefono']??''))?>',<?=(int)$ct['campana_id']?>,'Seguimiento de campaña')">☑ FU</button>
        </div>
      </div>
      <?php if($notasReales):?><div style="font-size:9px;color:<?=$TX?>;margin-top:6px;white-space:pre-wrap"><?=h($notasReales)?></div><?php endif;?>
      <?php if($extraChips):?>
      <div style="margin-top:6px">
        <button type="button" onclick="var d=this.nextElementSibling;d.style.display=d.style.display==='none'?'flex':'none'" style="background:none;border:none;color:<?=$MU?>;font-size:7px;font-weight:900;text-transform:uppercase;cursor:pointer;padding:0;letter-spacing:.5px">▾ MÁS DATOS (<?=count($extraChips)?>)</button>
        <div style="display:none;flex-wrap:wrap;gap:4px;margin-top:5px">
          <?php foreach($extraChips as $ek=>$ev): if($ev===''||$ev===null) continue; ?>
          <span style="background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:6px;padding:2px 7px;font-size:7px;color:<?=$TX?>"><b style="color:<?=$MU?>"><?=h($ek)?>:</b> <?=h(mb_strimwidth((string)$ev,0,60,'…'))?></span>
          <?php endforeach;?>
        </div>
      </div>
      <?php endif;?>
      <div id="cc-hist-<?=$ct['id']?>" style="display:none">
        <?php if(empty($logs)):?><div style="font-size:9px;color:<?=$MU?>;padding:8px;text-transform:uppercase">SIN ACTIVIDAD REGISTRADA</div><?php else: foreach($logs as $lg):?>
        <div style="display:flex;gap:8px;padding:6px 0;border-bottom:1px solid <?=$CB?>">
          <span style="font-size:8px;font-weight:900;color:<?=$P2?>;min-width:54px"><?=date('d/m/y',strtotime($lg['created_at']))?></span>
          <div style="flex:1"><div style="font-size:9px;font-weight:700;color:<?=$TX?>"><?=h($lg['canal'])?> — <?=h($lg['resultado'])?></div><?php if($lg['notas']):?><div style="font-size:8px;color:<?=$MU?>"><?=h($lg['notas'])?></div><?php endif;?></div>
        </div>
        <?php endforeach; endif;?>
      </div>
      <!-- Perfil completo del contacto — se copia a modal-cc-perfil al hacer
           clic en el nombre, para verlo mejor organizado en vez de la tarjeta
           compacta de la lista. -->
      <div id="cc-perfil-<?=$ct['id']?>" style="display:none">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
          <div style="width:44px;height:44px;border-radius:50%;background:<?=$P2?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:900;flex-shrink:0"><?=h(mb_strtoupper(mb_substr($nm,0,1)))?></div>
          <div><div style="font-size:15px;font-weight:900;color:<?=$P1?>"><?=h($nm)?></div>
            <?php if($ct['telefono']):?><div style="font-size:9px;color:<?=$MU?>">📞 <?=h($ct['telefono'])?><?php if($ct['email']):?> · <?=h($ct['email'])?><?php endif;?></div><?php endif;?>
          </div>
        </div>
        <div style="display:flex;gap:5px;flex-wrap:wrap;margin:10px 0 14px">
          <?php if($ct['promovido']):?><span style="background:<?=$ce[1]?>;color:<?=$ce[0]?>;border-radius:20px;padding:2px 9px;font-size:8px;font-weight:900"><?=$ce[2]?></span>
          <?php else:?><span style="background:<?=($CC_EST[$ct['estado']]??['#7A90A4','#F1F1F1'])[1]?>;color:<?=($CC_EST[$ct['estado']]??['#7A90A4','#F1F1F1'])[0]?>;border-radius:20px;padding:2px 9px;font-size:8px;font-weight:900"><?=h(str_replace('_',' ',$ct['estado']))?></span><?php endif;?>
          <?php if($hi):?><span style="background:#1B5E8C;color:#fff;border-radius:20px;padding:2px 9px;font-size:8px;font-weight:900">🇬🇧 HABLA INGLÉS</span><?php endif;?>
          <?php if(!empty($ct['agente_id'])):?><span style="background:#F3F0FB;color:#5B3FAF;border-radius:20px;padding:2px 9px;font-size:8px;font-weight:900">🙋 RECLAMADO POR <?=h($ct['agente_nombre']??'?')?></span>
          <?php else:?><span style="background:<?=$BG?>;color:<?=$MU?>;border-radius:20px;padding:2px 9px;font-size:8px;font-weight:900">SIN RECLAMAR</span><?php endif;?>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid <?=$CB?>">
          <?php if($ct['telefono']):?>
          <a href="tel:<?=h($ct['telefono'])?>" class="btn btn-bl btn-sm">📞 LLAMAR</a>
          <a href="https://wa.me/<?=$ph_wa?>" target="_blank" class="btn btn-gr btn-sm">WHATSAPP</a>
          <?php endif;?>
          <?php if($ct['promovido']):?>
          <button class="btn btn-am btn-sm" onclick="closeModal('modal-cc-perfil');openProfile(<?=(int)$ct['miembro_id']?>)">◉ VER PERFIL DE MIEMBRO</button>
          <?php else:?>
          <button class="btn btn-p btn-sm" onclick="closeModal('modal-cc-perfil');openCcLog(<?=$ct['campana_id']?>,<?=$ct['id']?>,'<?=h(addslashes($nm))?>')">📋 REGISTRAR</button>
          <button class="btn btn-gh btn-sm" onclick="closeModal('modal-cc-perfil');promoverContacto(<?=$ct['id']?>,'<?=h(addslashes($nm))?>')">▲ PIPELINE</button>
          <?php if(!empty($ct['agente_id'])):?>
            <?php if((int)$ct['agente_id']===(int)$uid || $admin):?><button class="btn btn-gh btn-sm" onclick="liberarContacto(<?=$ct['id']?>)">LIBERAR</button><?php endif;?>
          <?php else:?><button class="btn btn-gh btn-sm" onclick="reclamarContacto(<?=$ct['id']?>)">🙋 RECLAMAR</button><?php endif;?>
          <button class="btn btn-gh btn-sm" onclick="closeModal('modal-cc-perfil');openCcForm(<?=$ct['campana_id']?>,<?=$ct['id']?>)">✎ EDITAR</button>
          <?php endif;?>
          <button class="btn btn-am btn-sm" title="Agregar follow up" onclick="closeModal('modal-cc-perfil');abrirFollowUpForm('CAMPANA',<?=(int)$ct['id']?>,<?=(int)($ct['miembro_id']??0)?>,'<?=h(addslashes($nm))?>','<?=h(addslashes($ct['telefono']??''))?>',<?=(int)$ct['campana_id']?>,'Seguimiento de campaña')">☑ FOLLOW UP</button>
        </div>
        <?php if($extraChips):?>
        <div style="font-size:8px;font-weight:900;color:<?=$MU?>;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px">DATOS DEL ARCHIVO SUBIDO</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;margin-bottom:16px">
          <?php foreach($extraChips as $ek=>$ev): if($ev===''||$ev===null) continue; ?>
          <div><div style="font-size:7px;font-weight:800;color:<?=$MU?>;letter-spacing:.5px;text-transform:uppercase"><?=h($ek)?></div><div style="font-size:9px;color:<?=$TX?>;margin-top:1px"><?=h((string)$ev)?></div></div>
          <?php endforeach;?>
        </div>
        <?php endif;?>
        <?php if($notasReales):?>
        <div style="font-size:8px;font-weight:900;color:<?=$MU?>;letter-spacing:1px;text-transform:uppercase;margin-bottom:6px">NOTAS</div>
        <div style="font-size:9px;color:<?=$TX?>;white-space:pre-wrap;background:<?=$BG?>;border-radius:8px;padding:10px;margin-bottom:16px"><?=h($notasReales)?></div>
        <?php endif;?>
        <div style="font-size:8px;font-weight:900;color:<?=$MU?>;letter-spacing:1px;text-transform:uppercase;margin-bottom:6px">HISTORIAL DE CONTACTO (<?=count($logs)?>)</div>
        <?php if(empty($logs)):?>
        <div style="font-size:9px;color:<?=$MU?>;text-transform:uppercase">SIN ACTIVIDAD REGISTRADA TODAVÍA</div>
        <?php else: foreach($logs as $lg):?>
        <div style="display:flex;gap:8px;padding:7px 0;border-bottom:1px solid <?=$CB?>">
          <span style="font-size:8px;font-weight:900;color:<?=$P2?>;min-width:56px"><?=date('d/m/y',strtotime($lg['created_at']))?></span>
          <div style="flex:1"><div style="font-size:9px;font-weight:700;color:<?=$TX?>"><?=h($lg['canal'])?> — <?=h($lg['resultado'])?></div><?php if($lg['notas']):?><div style="font-size:8px;color:<?=$MU?>;margin-top:2px"><?=h($lg['notas'])?></div><?php endif;?></div>
        </div>
        <?php endforeach; endif;?>
      </div>
    </div>
    <?php endforeach; endif;?>
  </div>
</div>
<?php endforeach;?>
</div>
<div id="camp-view-listas" style="display:none">
<div class="card" style="border-top:3px solid #D46A9A;margin-bottom:14px;padding:13px 16px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:9px">
    <div>
      <div class="card-title" style="font-size:11px">🎉 LISTAS DE EVENTO</div>
      <div style="font-size:8px;color:<?=$MU?>;letter-spacing:1px;text-transform:uppercase;margin-top:3px"><?=$le_total?> LISTAS · <?=$le_miembros_total?> MIEMBROS AGREGADOS</div>
    </div>
    <button class="btn btn-p btn-sm" onclick="openListaForm()">+ NUEVA LISTA</button>
  </div>
</div>
<div style="background:#F3F0FB;border:1px solid #C2B0E8;border-left:4px solid #5B3FAF;border-radius:10px;padding:9px 14px;margin-bottom:14px;font-size:8px;color:#5B3FAF;letter-spacing:.5px;text-transform:uppercase;line-height:1.6">
  ℹ️ Úsalo para confirmaciones de eventos/celebraciones — agrega miembros que ya están en el CRM, marca su estado y si asistieron.
</div>
<?php if(empty($listas_evento)):?>
<div class="card" style="padding:30px;text-align:center;font-size:9px;color:<?=$MU?>;text-transform:uppercase">🎉 NO HAY LISTAS — CREA UNA CON "NUEVA LISTA"</div>
<?php endif;?>
<?php foreach($listas_evento as $le):
  $lem = $lem_by_lista[$le['id']] ?? [];
  $n_total = count($lem);
  $n_conf  = count(array_filter($lem, fn($x)=>$x['estado']==='CONFIRMADO'));
  $n_asis  = count(array_filter($lem, fn($x)=>!empty($x['asistio'])));
?>
<div class="card" style="margin-bottom:10px;border-left:4px solid #D46A9A">
  <div class="card-header" style="cursor:pointer;flex-wrap:wrap;gap:9px" onclick="leToggleCard(<?=$le['id']?>)">
    <div style="display:flex;align-items:center;gap:10px;min-width:0;flex:1">
      <div style="min-width:0">
        <div class="card-title" style="font-size:10px;white-space:normal"><?=h($le['nombre'])?></div>
        <div style="display:flex;gap:5px;flex-wrap:wrap;align-items:center;margin-top:4px">
          <?php if($le['fecha']):?><span style="background:#F3F0FB;color:#5B3FAF;border-radius:20px;padding:1px 8px;font-size:8px;font-weight:900">📅 <?=date('d/m/Y',strtotime($le['fecha']))?></span><?php endif;?>
          <span style="font-size:8px;color:<?=$MU?>">👥 <?=$n_total?> AGREGADOS</span>
          <?php if($n_conf>0):?><span style="font-size:8px;font-weight:900;color:#1E7A5C">✓ <?=$n_conf?> CONFIRMADOS</span><?php endif;?>
          <?php if($n_asis>0):?><span style="font-size:8px;font-weight:900;color:#1B5E8C">🎉 <?=$n_asis?> ASISTIERON</span><?php endif;?>
        </div>
      </div>
    </div>
    <span style="font-size:13px;color:<?=$MU?>;flex-shrink:0">▾</span>
  </div>
  <div id="le-body-<?=$le['id']?>" style="display:none;padding:13px 17px;border-top:1px solid <?=$CB?>">
    <?php if(!empty($le['descripcion'])):?><div style="font-size:9px;color:<?=$TX?>;line-height:1.6;margin-bottom:11px"><?=h($le['descripcion'])?></div><?php endif;?>
    <div style="display:flex;gap:7px;margin-bottom:13px;flex-wrap:wrap">
      <button class="btn btn-gh btn-sm" onclick="openListaForm(<?=$le['id']?>)">✎ EDITAR LISTA</button>
      <button class="btn btn-re btn-sm" onclick="deleteLista(<?=$le['id']?>)">✕ ELIMINAR LISTA</button>
    </div>
    <div class="form-group" style="max-width:460px">
      <label class="form-label">AGREGAR MIEMBRO</label>
      <div style="display:flex;gap:6px;align-items:flex-start">
        <div class="mpick-wrap" style="flex:1">
          <input type="text" id="le-mpick-input-<?=$le['id']?>" class="form-input" placeholder="Escribe nombre o teléfono..." autocomplete="off"
                 oninput="mpickSearch('le-mpick-input-<?=$le['id']?>','le-mpick-hidden-<?=$le['id']?>','le-mpick-drop-<?=$le['id']?>',this.value,false)">
          <input type="hidden" id="le-mpick-hidden-<?=$le['id']?>" value="">
          <div id="le-mpick-drop-<?=$le['id']?>" class="mpick-drop"></div>
        </div>
        <button type="button" class="btn btn-p btn-sm" onclick="addMiembroLista(<?=$le['id']?>)">+ AGREGAR</button>
      </div>
    </div>
    <div class="form-group" style="max-width:560px;margin-top:12px">
      <label class="form-label">➕ AGREGAR POR FILTRO — todos los que coincidan de un jalón (ej. todos los de ANTHEM)</label>
      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <select id="le-bulk-carrier-<?=$le['id']?>" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:8px 10px;font-size:10px;font-family:'DM Sans',sans-serif;background:#fff;font-weight:700;min-width:170px">
          <option value="">CUALQUIER ASEGURANZA</option>
          <?php foreach(['SCAN','ANTHEM','HUMANA','ALIGNMENT','LA CARE','HEALTH NET','MOLINA','UNITED HEALTHCARE'] as $_bc):?>
          <option value="<?=$_bc?>"><?=$_bc?></option>
          <?php endforeach;?>
        </select>
        <select id="le-bulk-estado-<?=$le['id']?>" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:8px 10px;font-size:10px;font-family:'DM Sans',sans-serif;background:#fff;font-weight:700;min-width:170px">
          <option value="">CUALQUIER ESTADO</option>
          <?php foreach(['ACTIVE','READY TO ENROLL','IN PROCESS','PLAN CHANGE','PROSPECT','PENDING','CANCELED','DENIED','CERRADO','DISENROLLED'] as $_be):?>
          <option value="<?=$_be?>"><?=$_be?></option>
          <?php endforeach;?>
        </select>
        <button type="button" class="btn btn-p btn-sm" onclick="bulkAddMiembrosLista(<?=$le['id']?>)">+ AGREGAR TODOS LOS QUE COINCIDAN</button>
      </div>
    </div>
    <?php $_lecs = $lec_by_lista[$le['id']] ?? []; ?>
    <div class="form-group" style="max-width:600px;margin-top:12px">
      <label class="form-label">📋 COLUMNAS EXTRA DE ESTA LISTA — fecha, notas, número o dropdown a tu gusto (ej. FECHA DE LLAMADA, NOTAS, CANTIDAD DE LLAMADAS, o un dropdown "¿VA A IR?")</label>
      <?php $_LEC_ICONOS = ['fecha'=>'📅','dropdown'=>'▾','texto'=>'📝','numero'=>'#']; ?>
      <?php if($_lecs):?>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px">
        <?php foreach($_lecs as $_lc):?>
        <span style="display:inline-flex;align-items:center;gap:5px;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:20px;padding:3px 6px 3px 10px;font-size:8px;font-weight:800;color:<?=$TX?>">
          <?=$_LEC_ICONOS[$_lc['tipo']]??'▾'?> <?=h($_lc['nombre'])?>
          <a href="javascript:void(0)" onclick="eliminarColumnaLista(<?=$_lc['id']?>,<?=$le['id']?>)" style="color:#B83232;font-weight:900;padding:0 2px;text-decoration:none">✕</a>
        </span>
        <?php endforeach;?>
      </div>
      <?php endif;?>
      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <input type="text" id="le-col-nombre-<?=$le['id']?>" placeholder="Nombre de la columna (ej. NOTAS, CANTIDAD DE LLAMADAS)" style="flex:1;min-width:180px;border:1.5px solid <?=$CB?>;border-radius:9px;padding:8px 10px;font-size:10px;font-family:'DM Sans',sans-serif">
        <select id="le-col-tipo-<?=$le['id']?>" onchange="toggleColOpciones(<?=$le['id']?>)" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:8px 10px;font-size:10px;font-family:'DM Sans',sans-serif;background:#fff;font-weight:700">
          <option value="fecha">📅 FECHA</option>
          <option value="texto">📝 TEXTO / NOTAS</option>
          <option value="numero"># NÚMERO (EJ. CANTIDAD DE LLAMADAS)</option>
          <option value="dropdown">▾ DROPDOWN (TÚ PONES LAS OPCIONES)</option>
        </select>
        <input type="text" id="le-col-opciones-<?=$le['id']?>" placeholder="Opciones separadas por coma (ej. Sí va, No va, Tal vez)" style="display:none;flex:2;min-width:220px;border:1.5px solid <?=$CB?>;border-radius:9px;padding:8px 10px;font-size:10px;font-family:'DM Sans',sans-serif">
        <button type="button" class="btn btn-sky btn-sm" onclick="agregarColumnaLista(<?=$le['id']?>)">+ AGREGAR COLUMNA</button>
      </div>
    </div>
    <?php if(empty($lem)):?>
    <div class="le-empty" style="font-size:9px;color:<?=$MU?>;padding:12px 0;text-transform:uppercase">SIN MIEMBROS AGREGADOS TODAVÍA</div>
    <?php else:?>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px">
      <input type="text" id="le-buscar-<?=$le['id']?>" placeholder="🔎 Buscar por nombre o teléfono..." autocomplete="off" oninput="_debouncedCall('lelist-<?=$le['id']?>',function(){filtrarLista(<?=$le['id']?>)})" style="flex:1;min-width:180px;border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 10px;font-size:9px;font-family:'DM Sans',sans-serif">
      <label class="form-label" style="margin:0">ESTADO</label>
      <select id="le-filtro-estado-<?=$le['id']?>" onchange="filtrarLista(<?=$le['id']?>)" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:6px 9px;font-size:9px;font-family:'DM Sans',sans-serif;background:#fff;font-weight:700">
        <option value="">TODOS</option>
        <?php foreach(array_keys($LEM_ESTADOS) as $_est_op):?><option value="<?=h($_est_op)?>"><?=h($_est_op)?></option><?php endforeach;?>
      </select>
      <span id="le-count-<?=$le['id']?>" style="font-size:8px;color:<?=$MU?>;text-transform:uppercase"></span>
    </div>
    <div class="le-table-wrap" style="overflow-x:auto;margin-top:10px">
    <table class="le-table" style="width:100%;border-collapse:collapse">
      <tr>
        <th style="text-align:left;font-size:8px;color:<?=$MU?>;text-transform:uppercase;padding:5px 8px">MIEMBRO</th>
        <th style="text-align:left;font-size:8px;color:<?=$MU?>;text-transform:uppercase;padding:5px 8px">TELÉFONO</th>
        <?php foreach($_lecs as $_lc):?>
        <th style="text-align:left;font-size:8px;color:<?=$MU?>;text-transform:uppercase;padding:5px 8px;white-space:nowrap"><?=h($_lc['nombre'])?></th>
        <?php endforeach;?>
        <th style="text-align:left;font-size:8px;color:<?=$MU?>;text-transform:uppercase;padding:5px 8px">ESTADO</th>
        <th style="text-align:center;font-size:8px;color:<?=$MU?>;text-transform:uppercase;padding:5px 8px">ASISTIÓ</th>
        <th style="text-align:center;font-size:8px;color:<?=$MU?>;text-transform:uppercase;padding:5px 8px">QUIÉN TRABAJÓ</th>
        <th></th>
      </tr>
      <?php foreach($lem as $_lm): $_lm_nombre = trim($_lm['apellido'].', '.$_lm['nombre']); $_lm_search = h(strtolower($_lm_nombre.' '.($_lm['telefono']??''))); ?>
      <tr style="border-top:1px solid <?=$CB?>" data-le-row="<?=(int)$_lm['id']?>" data-search="<?=$_lm_search?>">
        <td style="padding:6px 8px;font-size:9px;font-weight:800;color:<?=$P1?>;cursor:pointer" onclick="openProfile(<?=(int)$_lm['miembro_id']?>)"><?=h($_lm_nombre)?></td>
        <td style="padding:6px 8px;font-size:9px;color:<?=$MU?>"><?=h($_lm['telefono']?:'—')?></td>
        <?php foreach($_lecs as $_lc): $_valGuardado = $lev_by_ml[$_lm['id']][$_lc['id']] ?? ''; ?>
        <td style="padding:6px 8px">
          <?php if($_lc['tipo']==='fecha'):?>
          <input type="date" value="<?=h($_valGuardado)?>" onchange="guardarValorColumna(<?=$_lc['id']?>,<?=(int)$_lm['id']?>,this.value)" style="border:1.5px solid <?=$CB?>;border-radius:7px;padding:4px 6px;font-size:9px;font-family:'DM Sans',sans-serif">
          <?php elseif($_lc['tipo']==='dropdown'): $_opciones = json_decode($_lc['opciones'] ?? '[]', true) ?: []; ?>
          <select onchange="guardarValorColumna(<?=$_lc['id']?>,<?=(int)$_lm['id']?>,this.value)" style="border:1.5px solid <?=$CB?>;border-radius:7px;padding:4px 6px;font-size:9px;font-family:'DM Sans',sans-serif;background:#fff">
            <option value="">—</option>
            <?php foreach($_opciones as $_op):?><option value="<?=h($_op)?>"<?=$_valGuardado===$_op?' selected':''?>><?=h($_op)?></option><?php endforeach;?>
          </select>
          <?php elseif($_lc['tipo']==='numero'):?>
          <div style="display:flex;align-items:center;gap:4px">
            <input type="number" id="le-val-<?=$_lc['id']?>-<?=(int)$_lm['id']?>" value="<?=h($_valGuardado)?>" onchange="guardarValorColumna(<?=$_lc['id']?>,<?=(int)$_lm['id']?>,this.value)" style="width:55px;border:1.5px solid <?=$CB?>;border-radius:7px;padding:4px 6px;font-size:9px;font-family:'DM Sans',sans-serif">
            <button type="button" class="btn btn-gh btn-sm" style="padding:2px 7px;font-size:9px" onclick="incrementarNumeroColumna(<?=$_lc['id']?>,<?=(int)$_lm['id']?>)">+1</button>
          </div>
          <?php else:?>
          <input type="text" value="<?=h($_valGuardado)?>" onchange="guardarValorColumna(<?=$_lc['id']?>,<?=(int)$_lm['id']?>,this.value)" style="width:100%;min-width:120px;border:1.5px solid <?=$CB?>;border-radius:7px;padding:4px 6px;font-size:9px;font-family:'DM Sans',sans-serif;text-transform:none">
          <?php endif;?>
        </td>
        <?php endforeach;?>
        <td style="padding:6px 8px">
          <select class="le-estado-sel" onchange="updateMiembroLista(<?=(int)$_lm['id']?>,{estado:this.value});filtrarLista(<?=$le['id']?>)" style="border:1.5px solid <?=$CB?>;border-radius:7px;padding:4px 7px;font-size:9px;font-family:'DM Sans',sans-serif;background:#fff">
            <?php foreach(array_keys($LEM_ESTADOS) as $_est_op):?><option value="<?=h($_est_op)?>"<?=($_lm['estado']??'PENDIENTE')===$_est_op?' selected':''?>><?=h($_est_op)?></option><?php endforeach;?>
          </select>
        </td>
        <td style="padding:6px 8px;text-align:center"><input type="checkbox" onchange="updateMiembroLista(<?=(int)$_lm['id']?>,{asistio:this.checked?1:0})"<?=!empty($_lm['asistio'])?' checked':''?> style="width:16px;height:16px;cursor:pointer"></td>
        <td class="le-editor-cell" style="padding:6px 8px;text-align:center">
          <?php if(!empty($_lm['ultimo_editor_id'])):?>
          <span title="<?=h($_lm['editor_nombre']??'?')?> · <?=h($_lm['ultimo_editor_at']?date('d/m/Y g:i A', strtotime($_lm['ultimo_editor_at'])):'')?>"><?=av(h($_lm['editor_ini']??'?'),h($_lm['editor_color']??$P2),20)?></span>
          <?php else:?>
          <span style="color:<?=$MU?>;font-size:9px">—</span>
          <?php endif;?>
        </td>
        <td style="padding:6px 8px;text-align:right;white-space:nowrap">
          <button class="btn btn-am btn-sm" style="font-size:8px" title="Agregar follow up" onclick="abrirFollowUpForm('LISTA',<?=(int)$_lm['id']?>,<?=(int)$_lm['miembro_id']?>,'<?=h(addslashes($_lm_nombre))?>','<?=h(addslashes($_lm['telefono']??''))?>',null,'Seguimiento de lista: <?=h(addslashes($le['nombre']))?>')">☑</button>
          <button class="btn btn-re btn-sm" style="font-size:8px" onclick="removeMiembroLista(<?=(int)$_lm['id']?>)">✕</button>
        </td>
      </tr>
      <?php endforeach;?>
    </table>
    </div>
    <?php endif;?>
  </div>
</div>
<?php endforeach;?>
</div>
</div>

<!-- GASTOS DE SMS/MMS (estimado) -->
<div id="camp-view-gastos" style="display:none">
  <div class="card" style="border-top:3px solid <?=$P2?>;margin-bottom:14px;padding:13px 16px">
    <div class="card-title" style="font-size:11px">💰 GASTOS DE SMS / MMS (TWILIO)</div>
    <div style="font-size:8px;color:<?=$MU?>;letter-spacing:.5px;text-transform:uppercase;margin-top:5px;line-height:1.7">
      ⚠ COSTO ESTIMADO según tarifa típica de Twilio en EEUU — no es el cobro exacto (varía según tu tipo de número y el país del destinatario). Para el monto real que te cobra Twilio, revisa tu consola de Twilio → Billing.
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:14px">
    <?php foreach([
      ['💰', '$'.number_format($gastos_total,2), 'GASTADO EN TOTAL', $P1],
      ['📅', '$'.number_format($gastos_mes,2), 'GASTADO ESTE MES', $P2],
      ['📤', $gastos_sms_out['n'].' · $'.number_format($gastos_sms_out['costo'],2), 'SMS ENVIADOS', '#1E7A5C'],
      ['🖼', $gastos_mms_out['n'].' · $'.number_format($gastos_mms_out['costo'],2), 'FLYERS (MMS) ENVIADOS', '#C07A1A'],
      ['📥', $gastos_in['n'].' · $'.number_format($gastos_in['costo'],2), 'RECIBIDOS', '#5B3FAF'],
    ] as [$ic,$v,$lb,$c]):?>
    <div style="background:#fff;border:1px solid <?=$CB?>;border-radius:11px;padding:12px 14px;text-align:center">
      <div style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px"><?=$ic?> <?=$lb?></div>
      <div style="font-size:15px;font-weight:900;color:<?=$c?>"><?=$v?></div>
    </div>
    <?php endforeach;?>
  </div>

  <div class="card" style="margin-bottom:14px;overflow-x:auto">
    <div class="card-header"><div class="card-title" style="font-size:10px">POR CAMPAÑA — ENVÍOS MASIVOS</div></div>
    <table>
    <tr><th>CAMPAÑA</th><th style="text-align:center">MENSAJES ENVIADOS</th><th style="text-align:right">COSTO ESTIMADO</th></tr>
    <?php if(empty($gastos_por_campana)):?>
    <tr><td colspan="3" style="text-align:center;padding:18px;font-size:9px;color:<?=$MU?>;text-transform:uppercase">TODAVÍA NO HAY ENVÍOS MASIVOS REGISTRADOS</td></tr>
    <?php else: foreach($gastos_por_campana as $gc):?>
    <tr>
      <td style="font-weight:900;font-size:9px;color:<?=$P1?>"><?=h($gc['nombre'])?></td>
      <td style="text-align:center;font-size:10px;font-weight:900;color:<?=$TX?>"><?=$gc['n']?></td>
      <td style="text-align:right;font-size:10px;font-weight:900;color:#B83232">$<?=number_format($gc['costo'],2)?></td>
    </tr>
    <?php endforeach; endif;?>
    </table>
  </div>

  <div class="card" style="margin-bottom:18px;overflow-x:auto">
    <div class="card-header"><div class="card-title" style="font-size:10px">HISTORIAL POR MES</div></div>
    <table>
    <tr><th>MES</th><th style="text-align:center">ENVIADOS</th><th style="text-align:center">RECIBIDOS</th><th style="text-align:right">COSTO</th></tr>
    <?php if(empty($gastos_por_mes)):?>
    <tr><td colspan="4" style="text-align:center;padding:18px;font-size:9px;color:<?=$MU?>;text-transform:uppercase">SIN DATOS TODAVÍA</td></tr>
    <?php else: foreach($gastos_por_mes as $gm):?>
    <tr>
      <?php $_gm_meses=['01'=>'ENE','02'=>'FEB','03'=>'MAR','04'=>'ABR','05'=>'MAY','06'=>'JUN','07'=>'JUL','08'=>'AGO','09'=>'SEP','10'=>'OCT','11'=>'NOV','12'=>'DIC']; [$_gm_y,$_gm_m]=explode('-',$gm['mes']); ?>
      <td style="font-weight:900;font-size:9px;color:<?=$P1?>"><?=($_gm_meses[$_gm_m]??$_gm_m).' '.$_gm_y?></td>
      <td style="text-align:center;font-size:9px;color:<?=$TX?>"><?=$gm['enviados']?></td>
      <td style="text-align:center;font-size:9px;color:<?=$TX?>"><?=$gm['recibidos']?></td>
      <td style="text-align:right;font-size:10px;font-weight:900;color:#B83232">$<?=number_format($gm['costo'],2)?></td>
    </tr>
    <?php endforeach; endif;?>
    </table>
  </div>
</div>
<?php
    } catch (Throwable $e) {
        ob_end_clean();
        return ['html' => '<div style="padding:30px;text-align:center;color:#B83232;font-size:9px;text-transform:uppercase">No se pudo armar CAMPAÑAS — intenta de nuevo en un momento</div>', 'campanas' => [], 'cc_all' => [], 'listas_evento' => [], 'le_columnas_count' => []];
    }
    $html = ob_get_clean();

    return [
        'html' => $html,
        'campanas' => $campanas,
        'cc_all' => $cc_all,
        'listas_evento' => $listas_evento,
        'le_columnas_count' => array_map('count', $lec_by_lista),
    ];
}
