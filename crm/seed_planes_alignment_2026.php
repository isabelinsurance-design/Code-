<?php
/**
 * seed_planes_alignment_2026.php
 * Inserta de una vez los 3 planes del Summary of Benefits 2026 de
 * Alignment Health (CalPlus 039, Access 044, Silicon 045) en la tabla
 * planes_comparacion — para no tener que llenar los ~55 campos a mano
 * por cada uno desde Recursos → Comparar Planes.
 *
 * USO: visita esta página una vez logueada, revisa la vista previa, y
 * presiona "Insertar". Es seguro recargarla — si un plan ya existe (por
 * nombre) no lo vuelve a insertar. Puedes borrar este archivo del
 * servidor después de usarlo, no hace falta dejarlo.
 */
require_once __DIR__ . '/session_boot.php';
require_once __DIR__ . '/config.php';
$user = auth();
if (empty($user)) { http_response_code(403); exit('Sin acceso — inicia sesión en el CRM primero.'); }
$pdo = db();

$pdo->exec("CREATE TABLE IF NOT EXISTS planes_comparacion (
    id INT AUTO_INCREMENT PRIMARY KEY, nombre_plan VARCHAR(200) NOT NULL,
    carrier VARCHAR(100), tipo VARCHAR(60), numero_plan VARCHAR(30), condados TEXT, anio INT,
    requisito_elegibilidad TEXT, prima_mensual TEXT, reembolso_parte_b TEXT,
    deducible TEXT, deducible_parte_d TEXT, moop TEXT,
    umbral_gastos_bolsillo_parte_d TEXT, hospital_internado TEXT, hospital_ambulatorio TEXT,
    centro_quirurgico_ambulatorio TEXT, medico_primario TEXT, especialistas TEXT,
    atencion_preventiva TEXT, atencion_emergencia TEXT, servicios_urgentes TEXT,
    emergencia_mundial TEXT, ambulancia TEXT, diagnostico_laboratorio TEXT,
    rayos_x TEXT, radiologia_terapeutica TEXT, examen_auditivo TEXT,
    audifonos TEXT, dental_preventivo TEXT, dental_integral TEXT, examen_vision TEXT,
    anteojos TEXT, salud_mental_internado TEXT, salud_mental_ambulatorio TEXT,
    enfermeria_especializada TEXT, terapia_fisica_habla TEXT, transporte TEXT,
    rx_deducible TEXT, rx_nivel1 TEXT, rx_nivel2 TEXT, rx_nivel3 TEXT,
    rx_nivel4 TEXT, rx_nivel5 TEXT, rx_nivel6 TEXT, rx_insulina TEXT,
    rx_vacunas TEXT, otc_mensual TEXT, gimnasio TEXT, pers TEXT,
    quiropractico_acupuntura TEXT, podologia TEXT, telesalud TEXT, dme TEXT,
    apoyo_hogar TEXT, comidas_post_hospital TEXT, extras_json TEXT, notas TEXT,
    activo TINYINT(1) DEFAULT 1, agregado_por INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_pc_carrier (carrier), KEY idx_pc_activo (activo)
)");
// Si la tabla ya existía con VARCHAR (del intento anterior), se ensancha a
// TEXT antes de intentar insertar de nuevo.
$_pc_check = $pdo->query("SHOW COLUMNS FROM planes_comparacion LIKE 'rx_insulina'")->fetch();
if ($_pc_check && stripos($_pc_check['Type'], 'text') === false) {
    foreach ([
        'prima_mensual','reembolso_parte_b','deducible','deducible_parte_d','moop','umbral_gastos_bolsillo_parte_d',
        'hospital_ambulatorio','centro_quirurgico_ambulatorio','medico_primario','especialistas','atencion_preventiva',
        'atencion_emergencia','servicios_urgentes','emergencia_mundial','ambulancia','diagnostico_laboratorio',
        'rayos_x','radiologia_terapeutica','examen_auditivo','audifonos','examen_vision','anteojos',
        'salud_mental_ambulatorio','terapia_fisica_habla','transporte','rx_deducible','rx_nivel1','rx_nivel2',
        'rx_nivel3','rx_nivel4','rx_nivel5','rx_nivel6','rx_insulina','rx_vacunas','otc_mensual','gimnasio','pers',
        'quiropractico_acupuntura','podologia','telesalud','dme','apoyo_hogar','comidas_post_hospital',
    ] as $_pcc) {
        try { $pdo->exec("ALTER TABLE planes_comparacion MODIFY COLUMN $_pcc TEXT"); } catch (Exception $e) {}
    }
}

// ── Campos compartidos por los 3 planes (idénticos en el documento) ─────────
$comun = [
    'carrier'                  => 'Alignment Health Plan',
    'tipo'                     => 'HMO C-SNP',
    'anio'                     => 2026,
    'deducible'                => '$0.00',
    'moop'                     => '$9,250.00 (no incluye medicamentos recetados)',
    'umbral_gastos_bolsillo_parte_d' => '$2,100.00',
    'centro_quirurgico_ambulatorio'  => '20% coseguro*',
    'medico_primario'          => '$0.00',
    'especialistas'            => '$0.00',
    'atencion_preventiva'      => '$0.00 (ej. vacuna contra la influenza, pruebas de detección de diabetes)',
    'atencion_emergencia'      => '20% coseguro* (exento si lo hospitalizan en un plazo de 48 horas)',
    'servicios_urgentes'       => '$0.00',
    'emergencia_mundial'       => '$75.00 · $25,000.00 límite de cobertura todos los años (exento si lo hospitalizan)',
    'ambulancia'               => '20% coseguro* (no exento si lo hospitalizan)',
    'diagnostico_laboratorio'  => '20% coseguro* (procedimientos, pruebas, servicios de laboratorio)',
    'rayos_x'                  => '$0.00 · Diagnóstico: $0.00',
    'radiologia_terapeutica'   => '20% coseguro* (ej. radioterapia para el cáncer)',
    'examen_auditivo'          => '$0.00 — beneficios cubiertos por Medicare y 1 examen/ajuste/evaluación todos los años',
    'audifonos'                => '$0.00 por audífono, 2 audífonos todos los años',
    'examen_vision'            => '$0.00 — exámenes cubiertos por Medicare / 1 examen de la visión de rutina todos los años',
    'anteojos'                 => '$500.00 límite de cobertura para anteojos/lentes de contacto cada dos años',
    'salud_mental_internado'   => "\$1,676.00 deducible por cada período de beneficios.\nDías 1-60: \$0.00.\nDías 61-90: \$419.00 por día.\nDías 91 y siguientes: \$838.00 por cada 'día de reserva de por vida' (hasta 60 días a lo largo de su vida).\nPasados los días de reserva de por vida: todos los costos.",
    'salud_mental_ambulatorio' => 'Especializados de salud mental (individual y grupal): 20% coseguro* · Servicios psiquiátricos (individual y grupal): 20% coseguro*',
    'enfermeria_especializada' => "\$0.00 al día, días 1-20.\n\$209.50 al día, días 21-100.\nDías 101 y siguientes: todos los costos.",
    'terapia_fisica_habla'     => '20% coseguro*',
    'rx_deducible'             => '$615.00 para Nivel 3, Nivel 4, y Nivel 5',
    'rx_nivel1'                => '$0.00 (minorista 30 días) · $0.00 (pedido por correo 100 días)',
    'rx_nivel2'                => '25% coseguro (minorista) · 25% coseguro (correo)',
    'rx_nivel3'                => '25% coseguro (minorista) · 25% coseguro (correo)',
    'rx_nivel4'                => '31% coseguro (minorista) · 31% coseguro (correo)',
    'rx_nivel5'                => '25% coseguro (minorista) · Descubierto (correo)',
    'rx_nivel6'                => '$0.00 (minorista) · $0.00 (correo)',
    'rx_insulina'              => 'No paga más de $35.00 por un suministro de un mes de cada producto de insulina cubierto, sin importar el nivel de costo compartido, incluso si no ha pagado su deducible.',
    'rx_vacunas'               => 'Cubre la mayoría de las vacunas de la Parte D para adultos sin costo alguno, incluso si no ha pagado su deducible.',
    'gimnasio'                 => '$0.00 (membresías en gimnasios participantes)',
    'pers'                     => '$0.00 (Sistema de Respuesta de Emergencia Personal)',
    'quiropractico_acupuntura' => '$0.00 cubiertos por Medicare · $0.00 por 12 visitas de rutina todos los años (quiropráctica y acupuntura combinadas)',
    'podologia'                => '$0.00 cubiertos por Medicare',
    'telesalud'                => '$0.00 para proveedores de atención primaria, especialidades de salud mental y servicios psiquiátricos',
    'dme'                      => '20% coseguro* (Equipo Médico Duradero)',
    'apoyo_hogar'              => '$0.00 — 12 horas cada tres meses, 48 horas al año — O Apoyo a cuidadores (el afiliado debe elegir por adelantado)',
    'comidas_post_hospital'    => '$0.00 copago para 28 comidas durante 14 días, tres veces al año (reingreso y comidas para pacientes crónicos)',
    'requisito_elegibilidad'   => 'Plan de necesidades especiales para afecciones crónicas (C-SNP) y de doble elegibilidad. La inscripción requiere verificar una afección crónica grave/incapacitante que califique (insuficiencia cardíaca congestiva, trastornos pulmonares crónicos, demencia, diabetes, accidente cerebrovascular u otras) y elegibilidad tanto para Medicare como para Medicaid.',
    'prima_mensual'            => '$12.00 o $0 si recibe "Ayuda Adicional" (Parte C y D)',
    'notas'                    => "Los costos de Hospital para Pacientes Internados y Enfermería Especializada son de 2025 y pueden cambiar para 2026.\nLos beneficios, primas y/o copagos/coseguros pueden cambiar en enero 1, 2027.\n¹ Puede requerir autorización previa. ² Puede requerir una derivación de su médico.\n*Para quienes tienen Medi-Cal completo, el copago puede ser pagado en parte o en su totalidad por Medi-Cal o un tercero.\nFuente: 2026 Resumen de Beneficios, Alignment Health Plan (Y0141_26267SP_M).",
    'apoyo_para_cuidadores'    => '$0.00 — Reembolso de hasta $300.00 todos los años — O Servicios de apoyo en el hogar (el miembro debe elegir por adelantado)',
    'purificador_aire'         => '$0.00 — 1 purificador de aire o humidificador todos los años (para miembros con afección crónica calificada)',
    'mascotas'                 => '$0.00 — 7 días de alojamiento o 14 paseos al año',
    'control_plagas'           => '$0.00 — 1 servicio todos los años',
];

$planes = [
    [
        'nombre_plan' => 'Alignment Health Heart & Diabetes CalPlus (HMO C-SNP) 039',
        'numero_plan' => 'H3815-039',
        'condados'    => 'Alameda, Fresno, Los Ángeles, Madera, Marin, Merced, Orange, Placer, Riverside, Sacramento, San Bernardino, San Diego, San Francisco, San Joaquín, San Luis Obispo, Santa Clara, Stanislaus, Ventura y Yolo',
        'reembolso_parte_b' => '$0.00',
        'hospital_internado' => "\$275.00 al día, los días 1-6.\n\$0.00 al día, los días 7-90 (días ilimitados por cada ingreso).",
        'hospital_ambulatorio' => 'Servicios hospitalarios: 20% coseguro* · Servicios de observación: 20% coseguro*',
        'dental_preventivo' => "\$0.00 copago por servicios cubiertos por Medicare.\nExamen: \$0.00 por 1 cada seis meses.\nLimpieza: \$0.00 por 1 cada seis meses.\nTratamiento con fluoruro: \$0.00 por 1 todos los años.\nRadiografías: \$0.00 por 1 todos los años.",
        'dental_integral' => "Restauración: \$0.00\nEndodoncia: \$0.00\nPeriodoncia: \$0.00\nProstodoncia Removible: \$0.00\nProstodoncia Fija: \$0.00\nCirugía Oral y Maxilofacial: \$0.00\n\$500.00 límite de cobertura cada tres meses.",
        'transporte' => '$0.00 — 50 viajes de ida a lugares aprobados por el plan todos los años (dentro de un radio de 50 millas)',
        'otc_mensual' => '$129.00 asignación para gastos cada mes (no acumulable). Combinado con la asignación de elementos esenciales, total $129.00/mes.',
        'subsidio_esencial' => '$129.00',
    ],
    [
        'nombre_plan' => 'Alignment Health Heart & Diabetes Access (HMO C-SNP) 044',
        'numero_plan' => 'H3815-044',
        'condados'    => 'Condados de Los Ángeles, Riverside, y San Bernardino',
        'reembolso_parte_b' => '$1.00',
        'hospital_internado' => "\$1,676.00 deducible por cada período de beneficios.\nDías 1-60: \$0.00 por cada período de beneficios.\nDías 61-90: \$419.00 por día de cada período de beneficios.\nDías 91 y siguientes: \$838.00 por cada 'día de reserva de por vida' después del día 90 (hasta 60 días a lo largo de su vida).\nPasados los días de reserva de por vida: todos los costos.",
        'hospital_ambulatorio' => 'Servicios hospitalarios: 20% coseguro* · Servicios de observación: 20% coseguro*',
        'dental_preventivo' => "20% coseguro* por servicios cubiertos por Medicare.\nExamen: \$0.00 por 1 cada seis meses.\nLimpieza: \$0.00 por 1 cada seis meses.\nTratamiento con fluoruro: \$0.00 por 1 todos los años.\nRadiografías: \$0.00 por 1 todos los años.",
        'dental_integral' => "Restauración: \$0.00\nEndodoncia: \$0.00\nPeriodoncia: \$0.00\nProstodoncia Removible: \$0.00\nProstodoncia Fija: \$0.00\nCirugía Oral y Maxilofacial: \$0.00\n\$750.00 límite de cobertura cada tres meses.",
        'transporte' => '$0.00 — 100 viajes de ida a lugares aprobados por el plan todos los años (dentro de un radio de 50 millas)',
        'otc_mensual' => '$194.00 asignación para gastos cada mes (no acumulable). Combinado con la asignación de elementos esenciales, total $194.00/mes.',
        'subsidio_esencial' => '$194.00',
    ],
    [
        'nombre_plan' => 'Alignment Health Silicon (HMO C-SNP) 045',
        'numero_plan' => 'H3815-045',
        'condados'    => 'Condado de Santa Clara',
        'reembolso_parte_b' => '$5.00',
        'hospital_internado' => "\$1,676.00 deducible por cada período de beneficios.\nDías 1-60: \$0.00 por cada período de beneficios.\nDías 61-90: \$419.00 por día de cada período de beneficios.\nDías 91 y siguientes: \$838.00 por cada 'día de reserva de por vida' después del día 90 (hasta 60 días a lo largo de su vida).\nPasados los días de reserva de por vida: todos los costos.",
        'hospital_ambulatorio' => 'Servicios hospitalarios: 20% coseguro* · Servicios de observación: 20% coseguro*',
        'dental_preventivo' => "20% coseguro* por servicios cubiertos por Medicare.\nExamen: \$0.00 por 1 cada seis meses.\nLimpieza: \$0.00 por 1 cada seis meses.\nTratamiento con fluoruro: \$0.00 por 1 todos los años.\nRadiografías: \$0.00 por 1 todos los años.",
        'dental_integral' => "Restauración: \$0.00\nEndodoncia: \$0.00\nPeriodoncia: \$0.00\nProstodoncia Removible: \$0.00\nProstodoncia Fija: \$0.00\nCirugía Oral y Maxilofacial: \$0.00\n\$750.00 límite de cobertura cada tres meses.",
        'transporte' => '$0.00 — 60 viajes de ida a lugares aprobados por el plan todos los años (dentro de un radio de 50 millas)',
        'otc_mensual' => '$155.00 asignación para gastos cada mes (no acumulable). Combinado con la asignación de elementos esenciales, total $155.00/mes.',
        'subsidio_esencial' => '$155.00',
    ],
];

$pc_fields = [
    'carrier','tipo','numero_plan','condados','anio','requisito_elegibilidad',
    'prima_mensual','reembolso_parte_b','deducible','deducible_parte_d','moop','umbral_gastos_bolsillo_parte_d',
    'hospital_internado','hospital_ambulatorio','centro_quirurgico_ambulatorio',
    'medico_primario','especialistas','atencion_preventiva',
    'atencion_emergencia','servicios_urgentes','emergencia_mundial','ambulancia',
    'diagnostico_laboratorio','rayos_x','radiologia_terapeutica',
    'examen_auditivo','audifonos','dental_preventivo','dental_integral',
    'examen_vision','anteojos','salud_mental_internado','salud_mental_ambulatorio',
    'enfermeria_especializada','terapia_fisica_habla','transporte',
    'rx_deducible','rx_nivel1','rx_nivel2','rx_nivel3','rx_nivel4','rx_nivel5','rx_nivel6','rx_insulina','rx_vacunas',
    'otc_mensual','gimnasio','pers','quiropractico_acupuntura','podologia','telesalud','dme',
    'apoyo_hogar','comidas_post_hospital','extras_json','notas',
];

$armados = [];
foreach ($planes as $p) {
    $row = array_merge($comun, $p);
    // Lo que solo tienen los planes SNP va junto en "extras_json" (texto libre,
    // igual que llena el formulario de Recursos → Comparar Planes).
    $row['extras_json'] = "Tarjeta Conserje Bajo Demanda: Incluida (acceso a beneficios OTC y Healthy Rewards)\n"
        . "Apoyo para cuidadores de afiliados: " . $comun['apoyo_para_cuidadores'] . "\n"
        . "Purificador/humidificador de aire: " . $comun['purificador_aire'] . "\n"
        . "Subsidio para elementos esenciales (SSBCI): " . $row['subsidio_esencial'] . " al mes (combinado con el de OTC)\n"
        . "Servicios para mascotas: " . $comun['mascotas'] . "\n"
        . "Control de plagas: " . $comun['control_plagas'];
    $armados[] = $row;
}

$accion = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['insertar']);
$resultado = [];
if ($accion) {
    foreach ($armados as $row) {
        $chk = $pdo->prepare("SELECT id FROM planes_comparacion WHERE nombre_plan=? LIMIT 1");
        $chk->execute([$row['nombre_plan']]);
        if ($chk->fetchColumn()) { $resultado[] = [$row['nombre_plan'], 'ya existía — no se tocó']; continue; }
        $vals = [$row['nombre_plan']];
        foreach ($pc_fields as $f) { $vals[] = $row[$f] ?? null; }
        $cols = implode(',', array_merge(['nombre_plan'], $pc_fields, ['agregado_por']));
        $ph   = implode(',', array_fill(0, count($pc_fields) + 2, '?'));
        $vals[] = $user['id'];
        $pdo->prepare("INSERT INTO planes_comparacion ($cols) VALUES ($ph)")->execute($vals);
        $resultado[] = [$row['nombre_plan'], 'insertado ✓'];
    }
}
?><!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><title>Cargar planes Alignment 2026</title>
<style>body{font-family:sans-serif;max-width:700px;margin:30px auto;padding:0 16px;color:#1B3A5C}
h1{font-size:16px}li{margin-bottom:6px}a{color:#2876A8}
.btn{background:#1B4A6B;color:#fff;border:none;border-radius:8px;padding:10px 20px;font-size:13px;cursor:pointer}
.ok{color:#1E7A5C;font-weight:700}</style></head><body>
<h1>Cargar los 3 planes de Alignment Health 2026 a Comparar Planes</h1>
<?php if ($accion): ?>
  <p class="ok">Listo:</p>
  <ul><?php foreach ($resultado as [$nombre, $estado]): ?><li><b><?=htmlspecialchars($nombre)?></b> — <?=$estado?></li><?php endforeach; ?></ul>
  <p><a href="index.php">← Volver al CRM (Recursos → Comparar Planes)</a></p>
  <p style="color:#7A90A4;font-size:12px">Ya puedes borrar este archivo del servidor, no hace falta dejarlo.</p>
<?php else: ?>
  <p>Va a insertar estos 3 planes (si alguno ya existe con ese nombre, no se duplica):</p>
  <ul><?php foreach ($armados as $row): ?><li><?=htmlspecialchars($row['nombre_plan'])?></li><?php endforeach; ?></ul>
  <form method="POST"><button class="btn" type="submit" name="insertar" value="1">Insertar los 3 planes</button></form>
<?php endif; ?>
</body></html>
