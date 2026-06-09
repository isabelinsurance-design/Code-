<?php
require_once 'config.php';
$user = auth();
$admin = isAdmin();
$uid = $user['id'];

$id = intval($_GET['id'] ?? 0);
$m = [];
$pdo = db();

if ($id) {
    $s = $pdo->prepare("SELECT * FROM miembros WHERE id=?"); $s->execute([$id]); $m=$s->fetch()??[];
    // All employees can see all members — members belong to the agency
}

$agents   = $pdo->query("SELECT id,nombre FROM usuarios WHERE activo=1 AND rol IN ('admin','agent') ORDER BY nombre")->fetchAll();
$cuentas  = $pdo->query("SELECT id,nombre,tipo FROM cuentas WHERE activo=1 ORDER BY nombre")->fetchAll();
$P1='#1B4A6B';$P2='#2876A8';$CB='#C8DFF0';$BG='#EBF4F9';$MU='#7A90A4';$TX='#1B3A5C';
?>
<form onsubmit="submitMemberForm(event)" enctype="multipart/form-data" style="font-family:'DM Sans',sans-serif">
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="modal-header">
    <div class="modal-title"><?= $id?'EDITAR MIEMBRO':'NUEVO MIEMBRO' ?></div>
    <button type="button" class="modal-close" onclick="closeModal('member-form-modal')">✕</button>
  </div>

  <div style="max-height:65vh;overflow-y:auto;padding-right:4px">
    <div class="section-divider">INFORMACIÓN PERSONAL</div>
    <div class="grid-3">
      <div class="form-group"><label class="form-label">NOMBRE *</label><input type="text" name="nombre" class="form-input" value="<?= h($m['nombre']??'') ?>" required></div>
      <div class="form-group"><label class="form-label">MIDDLE NAME</label><input type="text" name="middle_name" class="form-input" value="<?= h($m['middle_name']??'') ?>" placeholder="SEGUNDO NOMBRE"></div>
      <div class="form-group"><label class="form-label">APELLIDO *</label><input type="text" name="apellido" class="form-input" value="<?= h($m['apellido']??'') ?>" required></div>
    </div>
    <div class="grid-3">
      <div class="form-group"><label class="form-label">FECHA NAC.</label><input type="date" name="dob" class="form-input" value="<?= $m['dob']??'' ?>"></div>
      <div class="form-group"><label class="form-label">TELÉFONO</label><input type="text" name="telefono" class="form-input" value="<?= h($m['telefono']??'') ?>" placeholder="(818) 555-0000"></div>
      <div class="form-group"><label class="form-label">TELÉFONO 2</label><input type="text" name="telefono2" class="form-input" value="<?= h($m['telefono2']??'') ?>"></div>
      <div class="form-group"><label class="form-label">EMAIL</label><input type="email" name="email" class="form-input" value="<?= h($m['email']??'') ?>"></div>
    </div>
    <div class="grid-3">
      <div class="form-group"><label class="form-label">SEXO</label><select name="sexo" class="form-input"><option value="">—</option><option<?= ($m['sexo']??'')==='M'?' selected':'' ?>>M</option><option<?= ($m['sexo']??'')==='F'?' selected':'' ?>>F</option></select></div>
      <div class="form-group"><label class="form-label">IDIOMA</label><select name="idioma" class="form-input"><?php foreach (['ESP','ENG','BILINGÜE'] as $o): ?><option<?= ($m['idioma']??'ESP')===$o?' selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label class="form-label">ESTADO CIVIL</label><select name="estado_civil" class="form-input"><option value="">—</option><?php foreach (['SOLTERO/A','CASADO/A','DIVORCIADO/A','VIUDO/A'] as $o): ?><option<?= ($m['estado_civil']??'')===$o?' selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></div>
    </div>

    <?php
    // Pareja/esposo(a) — buscador (mpick). Prellenar nombre si ya está vinculada.
    $pareja_sel = (string)($m['pareja_id'] ?? '');
    $pareja_label = '';
    if ($pareja_sel !== '') {
        $pq = $pdo->prepare("SELECT nombre,apellido,telefono FROM miembros WHERE id=?");
        $pq->execute([(int)$pareja_sel]);
        if ($pr = $pq->fetch()) $pareja_label = $pr['apellido'].', '.$pr['nombre'].(!empty($pr['telefono'])?' · '.$pr['telefono']:'');
    }
    ?>
    <div class="grid-2">
      <div class="form-group">
        <label class="form-label">PAREJA / ESPOSO(A) — SI ES MIEMBRO</label>
        <div class="mpick-wrap">
          <input type="hidden" name="pareja_id" id="mf-pareja-id" value="<?=h($pareja_sel)?>">
          <input type="text" id="mf-pareja-input" class="form-input" placeholder="Escribe nombre o teléfono para buscar..." autocomplete="off" value="<?=h($pareja_label)?>" oninput="mpickSearch('mf-pareja-input','mf-pareja-id','mf-pareja-drop',this.value,false)">
          <button type="button" class="mpick-clear" onclick="mpickClear('mf-pareja-input','mf-pareja-id','mf-pareja-drop')" title="Limpiar">×</button>
          <div id="mf-pareja-drop" class="mpick-drop"></div>
        </div>
        <div style="font-size:7px;color:#7A90A4;margin-top:3px;letter-spacing:1px;text-transform:uppercase">★ VINCULA A SU PAREJA SI TAMBIÉN ES MIEMBRO DE LA AGENCIA</div>
      </div>
    </div>

    <div class="section-divider">DIRECCIÓN</div>
    <div class="grid-2">
      <div class="form-group">
        <label class="form-label">CALLE <span style="color:#7A90A4;font-weight:400;font-size:7px">(BUSCAR CON MAPS)</span></label>
        <div style="position:relative" id="mf-calle-wrap">
          <input type="text" id="mf-calle" name="direccion_calle" class="form-input"
                 value="<?= h($m['direccion_calle']??'') ?>"
                 autocomplete="off"
                 autocorrect="off"
                 autocapitalize="off"
                 spellcheck="false"
                 placeholder="EMPIEZA A ESCRIBIR LA DIRECCIÓN..."
                 role="combobox"
                 aria-autocomplete="list"
                 aria-expanded="false">
          <span style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:13px;pointer-events:none">📍</span>
          <!-- Spinner de búsqueda -->
          <span id="mf-calle-spinner" style="display:none;position:absolute;right:34px;top:50%;transform:translateY(-50%);font-size:10px;color:#7A90A4">⏳</span>
          <!-- Dropdown de sugerencias -->
          <div id="mf-calle-drop" style="
            display:none;
            position:absolute;
            top:calc(100% + 3px);
            left:0; right:0;
            background:#fff;
            border:1.5px solid #2876A8;
            border-radius:10px;
            z-index:9999;
            box-shadow:0 8px 24px rgba(27,74,107,.18);
            max-height:220px;
            overflow-y:auto;
          "></div>
        </div>
      </div>
      <div class="form-group"><label class="form-label">APT / SUITE</label><input type="text" name="direccion_apto" class="form-input" value="<?= h($m['direccion_apto']??'') ?>" autocomplete="new-password"></div>
    </div>
    <div class="grid-3">
      <div class="form-group"><label class="form-label">CIUDAD</label><input type="text" id="mf-ciudad" name="ciudad" class="form-input" value="<?= h($m['ciudad']??'') ?>" autocomplete="new-password"></div>
      <div class="form-group"><label class="form-label">COUNTY</label><select id="mf-county" name="county" class="form-input"><?php foreach (['LOS ANGELES','ORANGE','SAN BERNARDINO','RIVERSIDE','VENTURA','SAN DIEGO','OTRO'] as $o): ?><option<?= ($m['county']??'LOS ANGELES')===$o?' selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label class="form-label">ZIP</label><input type="text" id="mf-zip" name="zip" class="form-input" value="<?= h($m['zip']??'') ?>" autocomplete="new-password"></div>
    </div>

    <div class="section-divider">MEDICARE</div>
    <div class="grid-3">
      <div class="form-group"><label class="form-label">MBI</label><input type="text" name="mbi" class="form-input" value="<?= h($m['mbi']??'') ?>" placeholder="1EG4-TE5-MK72"></div>
      <div class="form-group"><label class="form-label">MEMBER ID</label><input type="text" name="member_id" class="form-input" value="<?= h($m['member_id']??'') ?>" placeholder="ID DEL PLAN"></div>
      <div class="form-group"><label class="form-label">SOCIAL SECURITY (SS)</label><input type="text" name="ss" class="form-input" value="<?= h($m['ss']??'') ?>" placeholder="XXX-XX-XXXX" autocomplete="off"></div>
    </div>
    <div class="grid-3">
      <div class="form-group"><label class="form-label">PARTE A</label><input type="date" name="parte_a" class="form-input" value="<?= $m['parte_a']??'' ?>"></div>
      <div class="form-group"><label class="form-label">PARTE B</label><input type="date" name="parte_b" class="form-input" value="<?= $m['parte_b']??'' ?>"></div>
      <div class="form-group"><label class="form-label">ELEGIBILIDAD</label><select name="elegibilidad" class="form-input"><option value="">—</option><?php foreach (['MEDICARE A+B','SOLO PART A','SOLO PART B','DUAL','LIS/EXTRA HELP','PACE','OTRO'] as $o): ?><option<?= ($m['elegibilidad']??'') === $o?' selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="grid-3">
      <div class="form-group"><label class="form-label">MEDI-CAL</label><select name="medical" class="form-input"><option<?= ($m['medical']??'NO')==='NO'?' selected':'' ?>>NO</option><option<?= ($m['medical']??'')==='SÍ'?' selected':'' ?>>SÍ</option></select></div>
      <div class="form-group"><label class="form-label">NIVEL MEDI-CAL</label><input type="text" name="medical_nivel" class="form-input" value="<?= h($m['medical_nivel']??'') ?>" placeholder="1, 2…"></div>
      <div class="form-group"></div>
    </div>

    <div class="section-divider">PLAN ACTUAL</div>
    <div class="grid-3">
      <div class="form-group"><label class="form-label">CARRIER</label><select name="carrier" class="form-input"><option value="">—</option><?php foreach (['SCAN','ANTHEM','HUMANA','ALIGNMENT','LA CARE','HEALTH NET','MOLINA','UNITED HEALTHCARE','BLUE SHIELD','KAISER','OTRO'] as $o): ?><option<?= ($m['carrier']??'')===$o?' selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label class="form-label">PLAN</label><input type="text" name="plan" class="form-input" value="<?= h($m['plan']??'') ?>"></div>
      <div class="form-group"><label class="form-label">TIPO DE PLAN</label><select name="tipo_plan" class="form-input"><option value="">—</option><?php foreach (['MEDICARE ADVANTAGE','MEDICARE SUPPLEMENT','PART D','DENTAL','SEGURO DE VIDA','VISIÓN','OTRO'] as $o): ?><option<?= ($m['tipo_plan']??'')===$o?' selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="grid-3">
      <div class="form-group"><label class="form-label">PLAN SECUNDARIO</label><input type="text" name="plan_secundario" class="form-input" value="<?= h($m['plan_secundario']??'') ?>" placeholder="EJ: PART D, DENTAL…"></div>
      <div class="form-group"><label class="form-label">PLAN ANTERIOR</label><input type="text" name="plan_anterior" class="form-input" value="<?= h($m['plan_anterior']??'') ?>" placeholder="PLAN QUE TENÍA ANTES"></div>
      <div class="form-group"><label class="form-label">F. EFECTIVA</label><input type="date" name="fecha_efectiva" class="form-input" value="<?= $m['fecha_efectiva']??'' ?>"></div>
    </div>
    <div class="grid-3">
      <div class="form-group"><label class="form-label">DOCTOR PCP</label><input type="text" name="pcp" class="form-input" value="<?= h($m['pcp']??'') ?>"></div>
      <div class="form-group"><label class="form-label">GRUPO MÉDICO / IPA</label><input type="text" name="pcp_group" class="form-input" value="<?= h($m['pcp_group']??'') ?>" placeholder="ALTAMED, APOLLO MED…"></div>
      <div class="form-group"><label class="form-label">TELÉFONO DEL PCP</label><input type="text" name="pcp_phone" class="form-input" value="<?= h($m['pcp_phone']??'') ?>" placeholder="(818) 000-0000"></div>
    </div>
    <div class="grid-3">
      <div class="form-group"><label class="form-label">DIRECCIÓN DEL PCP</label><input type="text" name="pcp_address" class="form-input" value="<?= h($m['pcp_address']??'') ?>"></div>
      <div class="form-group"><label class="form-label">CIUDAD DEL PCP</label><input type="text" name="pcp_city" class="form-input" value="<?= h($m['pcp_city']??'') ?>"></div>
      <div class="form-group" style="display:grid;grid-template-columns:1fr 1fr;gap:7px">
        <div><label class="form-label">ESTADO</label><input type="text" name="pcp_state" class="form-input" value="<?= h($m['pcp_state']??'CA') ?>" placeholder="CA"></div>
        <div><label class="form-label">ZIP</label><input type="text" name="pcp_zip" class="form-input" value="<?= h($m['pcp_zip']??'') ?>"></div>
      </div>
    </div>
    <div class="grid-3">
      <div class="form-group"><label class="form-label">DENTISTA</label><input type="text" name="dentista" class="form-input" value="<?= h($m['dentista']??'') ?>"></div>
      <div class="form-group"></div><div class="form-group"></div>
    </div>

    <div class="section-divider">CONTROL DE APLICACIÓN</div>
    <div class="grid-3">
      <div class="form-group"><label class="form-label">TIPO</label><select name="app_tipo" class="form-input"><option value="">—</option><?php foreach (['NEW ENROLLMENT','RE-SIGNED','SEP','DISENROLL'] as $o): ?><option<?= ($m['app_tipo']??'')===$o?' selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label class="form-label">PERÍODO</label><select name="app_periodo" class="form-input"><option value="">—</option><?php foreach (['AEP','OEP','SEP','IEP'] as $o): ?><option<?= ($m['app_periodo']??'')===$o?' selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label class="form-label">FECHA APP</label><input type="date" name="app_fecha" class="form-input" value="<?= $m['app_fecha']??'' ?>"></div>
    </div>
    <div class="grid-3">
      <div class="form-group"><label class="form-label">ESTADO CMS</label><input type="text" name="app_estado_cms" class="form-input" value="<?= h($m['app_estado_cms']??'') ?>" placeholder="ENROLLED / SENT TO CMS"></div>
      <div class="form-group"><label class="form-label">CARRIER ESTADO</label><input type="text" name="app_carrier_estado" class="form-input" value="<?= h($m['app_carrier_estado']??'') ?>"></div>
      <div class="form-group"><label class="form-label">HRA</label><select name="hra" class="form-input"><option value="">—</option><?php foreach (['N/A','COMPLETADO','PENDIENTE'] as $o): ?><option<?= ($m['hra']??'')===$o?' selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></div>
    </div>

    <!-- ============ GESTIÓN ============ -->
    <div class="section-divider">GESTIÓN</div>
    <div class="grid-3">
      <!-- ESTADO PRINCIPAL -->
      <div class="form-group">
        <label class="form-label">ESTADO *</label>
        <select name="estado" id="mf-estado" class="form-input" required>
    <?php
    $estados_map = [
        'PROSPECT'        => 'PROSPECT',
        'READY TO ENROLL' => 'READY TO ENROLL',
        'IN PROCESS'      => 'IN PROCESS',
        'ACTIVE'          => 'ACTIVE',
        'CANCELED'        => 'CANCELED',
        'DENIED'          => 'DENIED',
        'PENDING'         => 'PENDING'
    ];
    $est_actual = $m['estado'] ?? 'PROSPECT';
    foreach ($estados_map as $val => $label): ?>
        <option value="<?= $val ?>"<?= $est_actual === $val ? ' selected' : '' ?>><?= $label ?></option>
    <?php endforeach; ?>
</select>
      </div>

      <!-- SUBESTADO -->
      <div class="form-group">
        <label class="form-label">TIPO / MOTIVO</label>
        <label class="form-label">Subestado</label>
<select name="subestado" id="mf-subestado" class="form-input">
    <option value="">-- Sin subestado --</option>
    <option value="PROSPECTO" <?= ($m['subestado'] ?? '') == 'PROSPECTO' ? 'selected' : '' ?>>👥 PROSPECTO / SEGUIMIENTO</option>
    <option value="NEW ENROLLMENT" <?= ($m['subestado'] ?? '') == 'NEW ENROLLMENT' ? 'selected' : '' ?>>📝 NEW ENROLLMENT</option>
    <option value="RE-SIGNED" <?= ($m['subestado'] ?? '') == 'RE-SIGNED' ? 'selected' : '' ?>>🔄 RE-SIGNED</option>
    <option value="NEVER EFFECTIVE" <?= ($m['subestado'] ?? '') == 'NEVER EFFECTIVE' ? 'selected' : '' ?>>🚫 NEVER EFFECTIVE</option>
    <option value="CHANGED INSURANCE" <?= ($m['subestado'] ?? '') == 'CHANGED INSURANCE' ? 'selected' : '' ?>>🔀 CHANGED INSURANCE</option>
    <option value="DECEASED" <?= ($m['subestado'] ?? '') == 'DECEASED' ? 'selected' : '' ?>>🕊️ DECEASED</option>
</select>
      </div>

      <!-- AGENTE RESPONSABLE — visible para todos los usuarios -->
      <?php $agente_sel = ($m['agente_id'] ?? '') ?: $uid; ?>
      <div class="form-group">
        <label class="form-label">AGENTE RESPONSABLE</label>
        <select name="agente_id" id="mf-agente-id" class="form-input">
          <?php foreach ($agents as $ag): ?>
          <option value="<?=$ag['id']?>"<?=$agente_sel==$ag['id']?' selected':''?>><?=h($ag['nombre'])?></option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:7px;color:#7A90A4;margin-top:3px;letter-spacing:1px;text-transform:uppercase">★ APARECERÁ EN EL PIPELINE Y REPORTES DE ESTE AGENTE</div>
      </div>
    </div>

    <!-- FECHA DE CANCELACIÓN / BROKER MWI (solo visible si aplica) -->
    <div class="grid-3" id="mf-baja-row" style="display:none">
      <div class="form-group">
        <label class="form-label">FECHA DE BAJA</label>
        <input type="date" name="fecha_cancelacion" class="form-input" value="<?= h($m['fecha_cancelacion']??'') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">BROKER</label>
        <select name="broker_mwi" class="form-input">
          <option value="">—</option>
          <option<?= ($m['broker_mwi']??'')==='ISABEL FUENTES'?' selected':'' ?>>ISABEL FUENTES</option>
          <option<?= ($m['broker_mwi']??'')==='OTRO BROKER'?' selected':'' ?>>OTRO BROKER</option>
        </select>
      </div>
      <div class="form-group"><label class="form-label">RAZÓN DE CANCELACIÓN</label><input type="text" name="razon_cancelacion" class="form-input" value="<?= h($m['razon_cancelacion']??'') ?>" placeholder="MUDANZA, FALLECIÓ, CAMBIÓ DE SEGURO…"></div>
    </div>
    <div class="grid-3" id="mf-fuente-row" style="display:none"></div>

    <script>
    const MF_SUBS = {
      'ACTIVE':      [{v:'NEW ENROLLMENT',l:'New enrollment — primera vez'},{v:'RE-SIGNED',l:'Re-signed — cambio de plan'}],
      'IN PROCESS':  [{v:'NEW ENROLLMENT',l:'New enrollment'},{v:'RE-SIGNED',l:'Re-signed'}],
      'PLAN CHANGE': [{v:'RE-SIGNED',l:'Re-signed — cambiando plan/carrier'}],
      'SIN HACER':   [],
      'SIN FIRMAR':  [],
      'CANCELED':    [{v:'NEVER EFFECTIVE',l:'Never effective — antes de ser efectivo'},{v:'CHANGED INSURANCE',l:'Changed insurance'}],
      'DENIED':      [{v:'NEVER EFFECTIVE',l:'Never effective'}],
      'CERRADO':     [{v:'NEVER EFFECTIVE',l:'Never effective'}],
      'DISENROLLED': [{v:'CHANGED INSURANCE',l:'Changed insurance'},{v:'DECEASED',l:'Deceased — fallecido/a'},{v:'NEVER EFFECTIVE',l:'Never effective'}],
    };
    const MF_SUB_CURRENT = <?= json_encode($m['subestado'] ?? '') ?>;
    const MF_BAJA_ESTADOS = ['CANCELED','DENIED','CERRADO','DISENROLLED'];

    function mfActualizarSub() {
      const est = document.getElementById('mf-estado').value;
      const sel = document.getElementById('mf-subestado');
      const bajaRow = document.getElementById('mf-baja-row');
      const fuenteRow = document.getElementById('mf-fuente-row');

      const opts = MF_SUBS[est] || [];
      sel.innerHTML = '<option value="">— seleccionar —</option>';
      opts.forEach(o => {
        const op = document.createElement('option');
        op.value = o.v; op.textContent = o.l;
        if (o.v === MF_SUB_CURRENT) op.selected = true;
        sel.appendChild(op);
      });
      if (opts.length === 1) sel.value = opts[0].v;

      const esBaja = MF_BAJA_ESTADOS.includes(est);
      bajaRow.style.display = esBaja ? '' : 'none';
      fuenteRow.style.display = esBaja ? 'none' : '';
    }
    document.addEventListener('DOMContentLoaded', mfActualizarSub);
    mfActualizarSub();

    // ── Auto-rellenar plan_anterior cuando subestado = RE-SIGNED ─────────────
    document.getElementById('mf-subestado')?.addEventListener('change', function() {
      if (this.value === 'RE-SIGNED') {
        const planEl    = document.querySelector('[name="plan"]');
        const carrierEl = document.querySelector('[name="carrier"]');
        const anteriorEl= document.querySelector('[name="plan_anterior"]');
        if (anteriorEl && planEl && !anteriorEl.value) {
          const carrier = carrierEl?.value || '';
          const plan    = planEl?.value    || '';
          if (plan) anteriorEl.value = (carrier ? carrier+' — ' : '') + plan;
        }
        // Sugerir app_tipo = RE-SIGNED
        const appTipoEl = document.querySelector('[name="app_tipo"]');
        if (appTipoEl && !appTipoEl.value) appTipoEl.value = 'RE-SIGNED';
      }
    });
    </script>


    <!-- ════ ORIGEN / REFERENTE ════ -->
    <div class="section-divider">ORIGEN DEL MIEMBRO</div>

    <!-- Selector de cuenta — siempre visible -->
    <div class="grid-2" style="margin-bottom:0">
      <div class="form-group">
        <label class="form-label">CUENTA / CONTACTO REFERENTE</label>
        <select name="referido_por" id="mf-referido-por" class="form-input"
                onchange="_mfCuentaChange(this.value)">
          <option value="">— NINGUNA —</option>
          <?php foreach ($cuentas as $cu):
            $sel = (string)($m['referido_por'] ?? '') === (string)$cu['id'] ? ' selected' : '';
          ?>
          <option value="<?= (int)$cu['id'] ?>"<?= $sel ?>>
            <?= h($cu['nombre']) ?><?= $cu['tipo'] ? ' — '.h($cu['tipo']) : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:7px;color:#7A90A4;margin-top:3px;letter-spacing:1px;
                    text-transform:uppercase">★ APARECERÁ EN EL CONTEO DE ESA CUENTA EN CONTACTOS</div>
      </div>
      <!-- Contacto específico de la cuenta — se carga dinámicamente -->
      <div class="form-group" id="mf-contacto-wrap" style="display:none">
        <label class="form-label">PERSONA ESPECÍFICA DE LA CUENTA</label>
        <select name="contacto_referido_id" id="mf-contacto-sel" class="form-input">
          <option value="">— SELECCIONAR CONTACTO —</option>
        </select>
        <div style="font-size:7px;color:#7A90A4;margin-top:3px;letter-spacing:1px;
                    text-transform:uppercase">OPCIONAL — QUIÉN DENTRO DE LA CUENTA LO REFIRIÓ</div>
      </div>
    </div>

    <!-- Toggle + Fuente — solo visible cuando hay cuenta seleccionada -->
    <div id="mf-origen-extra" style="display:none;margin-top:12px">
      <div style="font-size:8px;font-weight:900;color:#7A90A4;letter-spacing:1px;
                  text-transform:uppercase;margin-bottom:6px">¿CÓMO LLEGÓ ESTE MIEMBRO?</div>
      <div style="display:flex;gap:0;border-radius:10px;overflow:hidden;
                  border:1.5px solid #C8DFF0;margin-bottom:12px">
        <button type="button" id="mf-tipo-entrante"
          onclick="_mfSetTipo('ENTRANTE')"
          style="flex:1;padding:10px;font-size:10px;font-weight:900;cursor:pointer;border:none;
                 background:#fff;color:#7A90A4;font-family:'DM Sans',sans-serif;letter-spacing:.5px">
          📥 NOS LO ENVIARON
        </button>
        <button type="button" id="mf-tipo-saliente"
          onclick="_mfSetTipo('SALIENTE')"
          style="flex:1;padding:10px;font-size:10px;font-weight:900;cursor:pointer;border:none;
                 background:#fff;color:#7A90A4;font-family:'DM Sans',sans-serif;
                 letter-spacing:.5px;border-left:1.5px solid #C8DFF0">
          📤 NOSOTROS LO ENVIAMOS
        </button>
      </div>
      <div class="form-group">
        <label class="form-label">FUENTE</label>
        <select name="fuente" class="form-input" id="mf-fuente-orig">
          <option value="">—</option>
          <?php foreach (['REFERIDO CUENTA','REFERIDO MIEMBRO','FACEBOOK LEAD','EVENTO COMUNIDAD','DIRECTA','IGLESIA','GOOGLE','OTRO'] as $o): ?>
          <option<?= ($m['fuente']??'')===$o?' selected':'' ?>><?= $o ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <input type="hidden" name="tipo_referido" id="mf-tipo-referido"
           value="<?= h($m['tipo_referido'] ?? '') ?>">

    <script>
    // ── Al cambiar la cuenta ────────────────────────────────────────────────
    function _mfCuentaChange(cid) {
      const extra   = document.getElementById('mf-origen-extra');
      const ctcWrap = document.getElementById('mf-contacto-wrap');
      const ctcSel  = document.getElementById('mf-contacto-sel');
      const hid     = document.getElementById('mf-tipo-referido');

      if (!cid) {
        // Sin cuenta → ocultar todo y limpiar
        extra.style.display   = 'none';
        ctcWrap.style.display = 'none';
        ctcSel.innerHTML = '<option value="">— SELECCIONAR CONTACTO —</option>';
        hid.value = '';
        _mfSetTipo('');
        return;
      }

      // Mostrar toggle + fuente
      extra.style.display = '';
      // Auto-seleccionar ENTRANTE si no tiene valor aún
      if (!hid.value) _mfSetTipo('ENTRANTE');

      // Cargar contactos de la cuenta via AJAX
      ctcWrap.style.display = 'none';
      ctcSel.innerHTML = '<option value="">⏳ CARGANDO…</option>';
      const fd = new FormData();
      fd.append('cue_ajax','1'); fd.append('action','get_contactos_cuenta'); fd.append('cid',cid);
      fetch('index.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(d=>{
          ctcSel.innerHTML = '<option value="">— SELECCIONAR CONTACTO —</option>';
          if (d.ok && d.contactos.length) {
            d.contactos.forEach(c=>{
              const op = document.createElement('option');
              op.value = c.id;
              op.textContent = c.nombre + (c.cargo?' — '+c.cargo:'');
              ctcSel.appendChild(op);
            });
            ctcWrap.style.display = '';  // mostrar solo si hay contactos
          }
        })
        .catch(()=>{ ctcSel.innerHTML='<option value="">— SELECCIONAR CONTACTO —</option>'; });
    }

    // ── Estilo del toggle ───────────────────────────────────────────────────
    function _mfSetTipo(tipo) {
      const hid = document.getElementById('mf-tipo-referido');
      const e   = document.getElementById('mf-tipo-entrante');
      const s   = document.getElementById('mf-tipo-saliente');
      if (!hid) return;
      hid.value = tipo;
      if (!e||!s) return;
      if (tipo === 'ENTRANTE') {
        e.style.background='#1B4A6B'; e.style.color='#fff';
        s.style.background='#fff';    s.style.color='#7A90A4';
      } else if (tipo === 'SALIENTE') {
        s.style.background='#1B5E8C'; s.style.color='#fff';
        e.style.background='#fff';    e.style.color='#7A90A4';
      } else {
        e.style.background='#fff'; e.style.color='#7A90A4';
        s.style.background='#fff'; s.style.color='#7A90A4';
      }
    }

    // ── Inicializar al cargar (si ya tiene cuenta guardada) ─────────────────
    (function(){
      const sel = document.getElementById('mf-referido-por');
      if (sel && sel.value) _mfCuentaChange(sel.value);
      // Restaurar tipo guardado
      const tipoVal = document.getElementById('mf-tipo-referido')?.value;
      if (tipoVal) _mfSetTipo(tipoVal);
    })();
    </script>

    <div class="section-divider">SALUD</div>
    <div class="grid-2">
      <div class="form-group"><label class="form-label">PRESCRIPCIONES</label><textarea name="prescripciones" class="form-input" rows="2"><?= h($m['prescripciones']??'') ?></textarea></div>
      <div class="form-group"><label class="form-label">CONDICIONES CRÓNICAS</label><textarea name="condiciones_cronicas" class="form-input" rows="2"><?= h($m['condiciones_cronicas']??'') ?></textarea></div>
    </div>
    <div class="form-group"><label class="form-label">ESPECIALISTAS</label><input type="text" name="especialistas" class="form-input" value="<?= h($m['especialistas']??'') ?>" placeholder="DRA. TORRES (CARDIOLOGÍA), DR. HERRERA (NEUROLOGÍA)…"></div>
    <div class="form-group"><label class="form-label">EXTRAS / NOTAS</label><textarea name="extras" class="form-input" rows="2"><?= h($m['extras']??'') ?></textarea></div>

    <div class="section-divider">OTROS</div>
    <div class="grid-3">
      <div class="form-group"><label class="form-label">ESTATUS LEGAL</label><select name="estatus_legal" class="form-input"><option value="">—</option><?php foreach (['CIUDADANO/A','RESIDENTE PERMANENTE','DACA','VISA DE TRABAJO','OTRO'] as $o): ?><option<?= ($m['estatus_legal']??'')===$o?' selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label class="form-label">PROFESIÓN</label><input type="text" name="profesion" class="form-input" value="<?= h($m['profesion']??'') ?>"></div>
      <div class="form-group"><label class="form-label">EMPRESA / EMPLEADOR</label><input type="text" name="empresa" class="form-input" value="<?= h($m['empresa']??'') ?>"></div>
    </div>
    <div class="grid-3">
      <div class="form-group"><label class="form-label">EVENTO DE CAPTACIÓN</label><input type="text" name="evento" class="form-input" value="<?= h($m['evento']??'') ?>" placeholder="EJ: FERIA SALUD INGLEWOOD 2025"></div>
      <div class="form-group"><label class="form-label">CAMPAÑA / FUENTE ADICIONAL</label><input type="text" name="fuente_campana" class="form-input" value="<?= h($m['fuente_campana']??'') ?>" placeholder="EJ: META ADS Q1, EMAIL BLAST…"></div>
      <div class="form-group"><label class="form-label">CARPETA DRIVE</label><input type="url" name="carpeta_drive" class="form-input" value="<?= h($m['carpeta_drive']??'') ?>" placeholder="https://drive.google.com/…" style="text-transform:none"></div>
    </div>
    <div style="display:flex;gap:14px;margin-top:5px;flex-wrap:wrap">
      <?php foreach ([['opt_in','OPT-IN SMS'],['opt_out','OPT-OUT'],['info_verificada','INFO VERIFICADA']] as [$n,$l]): ?>
      <label style="display:flex;align-items:center;gap:6px;font-size:9px;cursor:pointer;font-weight:800;color:<?= $TX ?>;letter-spacing:.5px;text-transform:uppercase">
        <input type="checkbox" name="<?= $n ?>" value="1"<?= !empty($m[$n])?' checked':'' ?>><?= $l ?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="form-group" style="background:#F8FAFC;padding:11px;border-radius:9px;border:1px dashed #CBD5E1;margin-bottom:9px">
    <label class="form-label">📇 TARJETA MEDICARE / DOCUMENTOS</label>
    <?php if(!empty($m['doc_path'])):?>
    <div style="background:#EAF5F0;border:1px solid #8DCFBA;border-radius:7px;padding:7px 10px;margin-bottom:7px;font-size:8px;font-weight:900;color:#1E7A5C;text-transform:uppercase">
      ✓ DOCUMENTO GUARDADO — <a href="<?=h($m['doc_path']??'')?>" target="_blank" style="color:#2876A8">VER →</a>
    </div>
    <?php endif;?>
    <input type="file" name="medicare_card" id="medicare_card" class="form-input" accept="image/*,.pdf" style="background:transparent;border:none;padding:5px 0">
    <div style="font-size:7px;color:#7A90A4;margin-top:4px;letter-spacing:1.5px;text-transform:uppercase">PDF, JPG O PNG — SE GUARDA EN CARPETA DEL MIEMBRO</div>
  </div>
  <div style="display:flex;justify-content:flex-end;gap:7px;margin-top:13px;padding-top:11px;border-top:1px solid <?= $CB ?>">
    <button type="button" class="btn btn-gh btn-sm" onclick="closeModal('member-form-modal')">CANCELAR</button>
    <button type="submit" class="btn btn-b btn-sm">◎ GUARDAR MIEMBRO</button>
  </div>
</form>
<script>
function submitMemberForm(e) {
  e.preventDefault();
  const form = e.target;
  const btn = form.querySelector('[type=submit]');
  if(btn){ btn.disabled=true; btn.textContent='GUARDANDO...'; }
  
  // Send as real FormData (multipart) — URLSearchParams drops files & can corrupt special chars
  const fd = new FormData(form);
  fd.append('action','save_member');
  // Normalize checkboxes (unchecked boxes aren't included in FormData)
  ['opt_in','opt_out','info_verificada'].forEach(n=>{
    if(!form.querySelector('[name="'+n+'"]')?.checked) fd.set(n,'0'); else fd.set(n,'1');
  });

  fetch('api.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(d=>{
      if(d.ok){
        toast('✓ MIEMBRO GUARDADO');
        closeModal('member-form-modal');
        // Si el detalle de una cuenta está abierto, refrescarlo (ej: cambió tipo_referido)
        if (typeof cueCurrentId !== 'undefined' && cueCurrentId) {
          const cueModal = document.getElementById('modal-cue-detalle');
          if (cueModal && cueModal.classList.contains('open')) {
            setTimeout(() => openCueDetalle(cueCurrentId, 'MIEMBROS'), 300);
          }
        }
        // Reload profile if it's open, otherwise do a soft table refresh
        const profileContent = document.getElementById('profile-content');
        const memberId = fd.get('id');
        if(profileContent && profileContent.innerHTML && memberId){
          fetch('profile.php?id='+memberId).then(r=>r.text()).then(html=>{
            profileContent.innerHTML=html;
          }).catch(()=>{});
        }
        // Refresh member list rows without full reload
        refreshMemberRow(fd.get('id'));
      } else {
        toast('⚠ ERROR: '+(d.error||'No se pudo guardar'));
        if(btn){ btn.disabled=false; btn.textContent='◎ GUARDAR MIEMBRO'; }
      }
    })
    .catch(()=>{
      toast('⚠ ERROR DE RED — INTENTA DE NUEVO');
      if(btn){ btn.disabled=false; btn.textContent='◎ GUARDAR MIEMBRO'; }
    });
}

function refreshMemberRow(id){
  const membersPane = document.getElementById('tab-MIEMBROS');
  if(membersPane && membersPane.style.display !== 'none'){
    const savedTab = sessionStorage.getItem('activeTab') || 'MIEMBROS';
    sessionStorage.setItem('activeTab', savedTab);
    setTimeout(()=>location.reload(),800);
  }
}

// ── ADDRESS AUTOCOMPLETE — OpenStreetMap Nominatim (GRATUITO, sin API key) ──
(function() {
  const input   = document.getElementById('mf-calle');
  const drop    = document.getElementById('mf-calle-drop');
  const spinner = document.getElementById('mf-calle-spinner');
  if (!input || !drop) return;

  let _timer = null;
  let _activeIdx = -1;
  let _results = [];

  // ── Helpers de UI ────────────────────────────────────────────
  function showDrop(items) {
    _results = items;
    _activeIdx = -1;
    if (!items.length) { drop.style.display = 'none'; return; }

    drop.innerHTML = items.map((item, i) => {
      const main  = item.display_name.split(',')[0].trim();
      const resto = item.display_name.split(',').slice(1).join(',').trim();
      return `<div class="mf-sug-item"
                   data-idx="${i}"
                   style="padding:10px 14px;cursor:pointer;font-size:10px;
                          border-bottom:1px solid #EBF4F9;font-family:'DM Sans',sans-serif;
                          color:#1B3A5C;line-height:1.4;transition:background .1s"
                   onmouseenter="this.style.background='#EBF4F9'"
                   onmouseleave="this.style.background=''"
                   onmousedown="event.preventDefault()"
                   onclick="window._mfPickAddr(${i})">
               <div style="font-weight:800">${main}</div>
               <div style="font-size:9px;color:#7A90A4;margin-top:2px">${resto}</div>
             </div>`;
    }).join('');
    drop.style.display = 'block';
    input.setAttribute('aria-expanded', 'true');
  }

  function hideDrop() {
    drop.style.display = 'none';
    input.setAttribute('aria-expanded', 'false');
    _activeIdx = -1;
  }

  function highlight(idx) {
    const items = drop.querySelectorAll('.mf-sug-item');
    items.forEach((el, i) => { el.style.background = i === idx ? '#EBF4F9' : ''; });
    _activeIdx = idx;
  }

  // ── Seleccionar dirección ─────────────────────────────────────
  window._mfPickAddr = function(idx) {
    const item = _results[idx];
    if (!item) return;

    // Rellenar calle
    const addr   = item.address || {};
    const num    = addr.house_number || '';
    const road   = addr.road || addr.pedestrian || addr.footway || '';
    const calle  = (num + ' ' + road).trim() || item.display_name.split(',')[0].trim();

    const ciudad = (addr.city || addr.town || addr.village || addr.municipality || '').toUpperCase();
    const zip    = addr.postcode || '';
    const county = (addr.county || '').replace(' County','').toUpperCase();

    const calleEl  = document.getElementById('mf-calle');
    const ciudadEl = document.getElementById('mf-ciudad');
    const zipEl    = document.getElementById('mf-zip');
    const countyEl = document.getElementById('mf-county');

    if (calleEl)  calleEl.value  = calle.toUpperCase();
    if (ciudadEl) ciudadEl.value = ciudad;
    if (zipEl)    zipEl.value    = zip;
    if (countyEl) {
      const known = ['LOS ANGELES','ORANGE','SAN BERNARDINO','RIVERSIDE','VENTURA','SAN DIEGO'];
      const match = known.find(c => county.includes(c));
      countyEl.value = match || (county || 'LOS ANGELES');
    }
    hideDrop();
    if (calleEl) calleEl.blur();
  };

  // ── Búsqueda con Nominatim ────────────────────────────────────
  function buscar(q) {
    if (!q || q.length < 4) { hideDrop(); return; }
    if (spinner) spinner.style.display = '';
    const url = 'https://nominatim.openstreetmap.org/search?'
      + new URLSearchParams({
          q: q + ', California, USA',
          format: 'json',
          addressdetails: 1,
          limit: 7,
          countrycodes: 'us',
          'accept-language': 'es'
        });
    fetch(url, { headers: { 'Accept-Language': 'es' } })
      .then(r => r.json())
      .then(data => {
        if (spinner) spinner.style.display = 'none';
        // Filtrar solo resultados con número de calle o road
        const filtered = data.filter(d =>
          d.address && (d.address.road || d.address.pedestrian || d.address.house_number)
        );
        showDrop(filtered.length ? filtered : data.slice(0, 5));
      })
      .catch(() => { if (spinner) spinner.style.display = 'none'; hideDrop(); });
  }

  // ── Eventos ───────────────────────────────────────────────────
  input.addEventListener('input', function() {
    clearTimeout(_timer);
    _timer = setTimeout(() => buscar(this.value.trim()), 400);
  });

  input.addEventListener('keydown', function(e) {
    const items = drop.querySelectorAll('.mf-sug-item');
    if (!items.length || drop.style.display === 'none') return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      highlight(Math.min(_activeIdx + 1, items.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      highlight(Math.max(_activeIdx - 1, 0));
    } else if (e.key === 'Enter' && _activeIdx >= 0) {
      e.preventDefault();
      window._mfPickAddr(_activeIdx);
    } else if (e.key === 'Escape') {
      hideDrop();
    }
  });

  document.addEventListener('click', function(e) {
    if (!e.target.closest('#mf-calle-wrap')) hideDrop();
  });

  input.addEventListener('blur', function() {
    setTimeout(hideDrop, 200);
  });

})();
</script>
<!-- Sin API key de Google Maps — usando OpenStreetMap Nominatim gratuito -->