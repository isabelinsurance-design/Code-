<?php
// ─────────────────────────────────────────────────────────────────────────
//  REPARACIÓN DE UN SOLO USO — Planes Alignment Health 2026 (055/056/047)
//  Estos 3 planes no se insertaron en planes_comparacion por alguna razón
//  que no dio ningún error visible (probablemente PDO en modo silencioso).
//  Esta página fuerza los errores a mostrarse y hace la inserción directa,
//  sin pasar por el flujo normal de index.php.
//  Puedes borrar este archivo después de usarlo.
// ─────────────────────────────────────────────────────────────────────────
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'session_boot.php';
require_once 'config.php';
$user = auth(); // solo pide estar logueado
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$_align2_base_2026 = [
    'carrier'=>'Alignment Health Plan','tipo'=>'HMO','anio'=>2026,
    'requisito_elegibilidad'=>"Plan Medicare Advantage GENERAL — no requiere ninguna condición crónica ni elegibilidad dual\nDebe tener Medicare Parte A y Parte B y vivir en el área de servicio",
    'deducible'=>'$0.00',
    'deducible_parte_d'=>'$0.00',
    'umbral_gastos_bolsillo_parte_d'=>'$2,100.00 al año (inicia la Etapa de Cobertura Catastrófica)',
    'hospital_ambulatorio'=>"\$200.00 (servicios hospitalarios)\n\$0.00 servicios de observación",
    'medico_primario'=>'$0.00',
    'atencion_preventiva'=>'$0.00 (ej. vacuna de influenza, exámenes de diabetes)',
    'servicios_urgentes'=>'$0.00',
    'diagnostico_laboratorio'=>'$0.00 (procedimientos, pruebas, laboratorio y diagnóstico)',
    'rayos_x'=>'$0.00',
    'radiologia_terapeutica'=>'20% de coaseguro (ej. radioterapia para cáncer)',
    'examen_auditivo'=>'$0.00 — cubierto por Medicare, más 1 examen/ajuste/evaluación de rutina al año',
    'audifonos'=>'No cubierto en el plan base (sí disponible en el Complete Package opcional, ver extras_json)',
    'dental_preventivo'=>"\$0.00 Examen y limpieza (1 cada 6 meses)\n\$0.00 Tratamiento de flúor (1 cada 6 meses)\n\$0.00 Rayos X (1 cada 3 años)",
    'examen_vision'=>'$0.00 — exámenes cubiertos por Medicare, más 1 examen de rutina al año',
    'enfermeria_especializada'=>"\$20.00 por día, días 1-20\n\$100.00 por día, días 21-100\n(no requiere hospitalización previa)",
    'rx_deducible'=>'$0.00 (salvo que se indique lo contrario)',
    'rx_nivel1'=>'$0.00 (minorista 30 días y correo 100 días)',
    'rx_nivel2'=>'$0.00 (minorista 30 días y correo 100 días)',
    'rx_nivel6'=>'$5.00 minorista; $0.00 por correo (Select Care Drugs)',
    'rx_insulina'=>'No más de $35.00 por suministro de 1 mes, en cualquier nivel, incluso antes de pagar el deducible',
    'rx_vacunas'=>'La mayoría de las vacunas de Parte D cubiertas sin costo, incluso antes de pagar el deducible',
    'otc_mensual'=>'No incluido en el plan base — la tarjeta ACCESS On-Demand Concierge (incluida) da acceso a beneficios de venta libre y Healthy Rewards, pero el documento no especifica un monto fijo mensual/trimestral',
    'gimnasio'=>'$0.00 — membresías en gimnasios participantes',
    'pers'=>'No cubierto en el plan base (sí disponible en el Complete Package opcional: $0.00)',
    'podologia'=>'$5.00 — cubierto por Medicare',
    'telesalud'=>'$0.00 para médico primario, especialidad de salud mental y servicios psiquiátricos',
    'apoyo_hogar'=>'No se menciona en este documento',
    'comidas_post_hospital'=>'No se menciona en este documento',
    'notas'=>'Resumen de Beneficios 2026. Datos de la tabla comparativa de 3 planes Alignment Health Plan en un solo documento. Doc: Y0141_26268EN_M. Servicio al Miembro: 1-866-634-2247 (TTY 711).',
];
$_align2_variantes_2026 = [
    '055' => [
        'nombre_plan'=>'Alignment Health L.A. Premium Giveback (HMO)','numero_plan'=>'055',
        'condados'=>'Los Angeles County, California',
        'prima_mensual'=>'$0.00/mes. Es un plan "Giveback": reduce su prima de Medicare Parte B en $185.00/mes',
        'reembolso_parte_b'=>'$185.00/mes de reducción en la prima de Parte B',
        'moop'=>'$2,400.00 al año (no incluye medicamentos recetados)',
        'hospital_internado'=>"\$0.00 por día, días 1-5\n\$200.00 por día, días 6-10\n\$0.00 por día, días 11-90\n(días ilimitados por admisión)",
        'centro_quirurgico_ambulatorio'=>'$100.00',
        'especialistas'=>'$5.00',
        'atencion_emergencia'=>'$150.00 (se exime si es admitido dentro de 48 horas)',
        'emergencia_mundial'=>'$90.00 copago de emergencia / $0.00 copago de urgencia, límite de $25,000.00 al año (se exime si es admitido); el Complete Package opcional agrega $75,000.00 adicionales de límite',
        'ambulancia'=>'$155.00 (terrestre y aérea) — no se exime si es admitido',
        'dental_integral'=>'No cubierto en el plan base (sí disponible en el Complete Package opcional, con prima adicional de $64.90/mes)',
        'anteojos'=>'Límite de cobertura de $150.00 para anteojos/lentes de contacto combinados, cada 2 años',
        'salud_mental_internado'=>"\$120.00 por día, días 1-5\n\$0.00 por día, días 6-90\n\$0.00 para 40 días adicionales\n\$0.00 para 60 \"días de reserva de por vida\"",
        'salud_mental_ambulatorio'=>'$20.00 (especialidad de salud mental y servicios psiquiátricos, individual y grupal)',
        'terapia_fisica_habla'=>'$5.00',
        'transporte'=>'No cubierto en el plan base (sí disponible en el Complete Package opcional: 24 viajes de ida al año, radio de 30 millas)',
        'rx_nivel3'=>'$42.00 minorista (30 días) / $126.00 correo (100 días)',
        'rx_nivel4'=>'45% de coaseguro (minorista 30 días y correo 100 días)',
        'rx_nivel5'=>'33% de coaseguro (minorista); no disponible por correo',
        'quiropractico_acupuntura'=>"\$0.00 cubierto por Medicare para ambos\nRutina: \$0.00 por hasta 24 visitas al año combinadas entre quiropráctico y acupuntura",
        'dme'=>'0% de coaseguro para artículos de $350.00 o menos; 20% para artículos de $350.01 o más (incluye monitores continuos de glucosa)',
        'extras_json'=>"Tarjeta ACCESS On-Demand Concierge: incluida (acceso a beneficios OTC y Healthy Rewards)\nServicios para mascotas: \$0.00 — 7 días de hospedaje o 14 paseos al año\nControl de plagas: \$0.00 — 1 servicio al año\nCOMPLETE PACKAGE (opcional, +\$64.90/mes): dental integral (0% diagnóstico, 50% restaurativo/endodoncia/cirugía oral, 0%-50% periodoncia, 0% prostodoncia removible y fija; límite \$1,500/año), audífonos \$195-\$1,750 copago c/u (2 al año), PERS \$0.00, transporte 24 viajes/año (radio 30 millas), cobertura mundial de emergencia adicional de \$75,000/año",
    ],
    '056' => [
        'nombre_plan'=>'Alignment Health S.D. Premium Giveback (HMO)','numero_plan'=>'056',
        'condados'=>'San Diego County, California',
        'prima_mensual'=>'$0.00/mes. Es un plan "Giveback": reduce su prima de Medicare Parte B en $185.00/mes',
        'reembolso_parte_b'=>'$185.00/mes de reducción en la prima de Parte B',
        'moop'=>'$2,400.00 al año (no incluye medicamentos recetados)',
        'hospital_internado'=>"\$0.00 por día, días 1-5\n\$200.00 por día, días 6-10\n\$0.00 por día, días 11-90\n(días ilimitados por admisión)",
        'centro_quirurgico_ambulatorio'=>'$100.00',
        'especialistas'=>'$5.00',
        'atencion_emergencia'=>'$150.00 (se exime si es admitido dentro de 48 horas)',
        'emergencia_mundial'=>'$90.00 copago de emergencia / $0.00 copago de urgencia, límite de $25,000.00 al año (se exime si es admitido); el Complete Package opcional agrega $75,000.00 adicionales de límite',
        'ambulancia'=>'$155.00 (terrestre y aérea) — no se exime si es admitido',
        'dental_integral'=>'No cubierto en el plan base (sí disponible en el Complete Package opcional, con prima adicional de $64.90/mes)',
        'anteojos'=>'Límite de cobertura de $150.00 para anteojos/lentes de contacto combinados, cada 2 años',
        'salud_mental_internado'=>"\$120.00 por día, días 1-5\n\$0.00 por día, días 6-90\n\$0.00 para 40 días adicionales\n\$0.00 para 60 \"días de reserva de por vida\"",
        'salud_mental_ambulatorio'=>'$20.00 (especialidad de salud mental y servicios psiquiátricos, individual y grupal)',
        'terapia_fisica_habla'=>'$5.00',
        'transporte'=>'No cubierto en el plan base (sí disponible en el Complete Package opcional: 24 viajes de ida al año, radio de 30 millas)',
        'rx_deducible'=>'$615.00 para Nivel 4 y Nivel 5',
        'rx_nivel3'=>'$47.00 minorista (30 días) / $141.00 correo (100 días)',
        'rx_nivel4'=>'45% de coaseguro (minorista 30 días y correo 100 días)',
        'rx_nivel5'=>'25% de coaseguro (minorista); no disponible por correo',
        'quiropractico_acupuntura'=>"\$0.00 cubierto por Medicare para ambos\nRutina: \$0.00 por hasta 24 visitas al año combinadas entre quiropráctico y acupuntura",
        'dme'=>'0% de coaseguro para artículos de $350.00 o menos; 20% para artículos de $350.01 o más (incluye monitores continuos de glucosa)',
        'extras_json'=>"Tarjeta ACCESS On-Demand Concierge: incluida (acceso a beneficios OTC y Healthy Rewards)\nServicios para mascotas: \$0.00 — 7 días de hospedaje o 14 paseos al año\nControl de plagas: \$0.00 — 1 servicio al año\nCOMPLETE PACKAGE (opcional, +\$64.90/mes): dental integral (0% diagnóstico, 50% restaurativo/endodoncia/cirugía oral, 0%-50% periodoncia, 0% prostodoncia removible y fija; límite \$1,500/año), audífonos \$195-\$1,750 copago c/u (2 al año), PERS \$0.00, transporte 24 viajes/año (radio 30 millas), cobertura mundial de emergencia adicional de \$75,000/año",
    ],
    '047' => [
        'nombre_plan'=>'Alignment Health smartSavings (HMO)','numero_plan'=>'047',
        'condados'=>'Los Angeles, Orange, Riverside, San Bernardino y San Diego, California',
        'prima_mensual'=>'$0.00/mes. También es un plan "Giveback", pero con una reducción menor: $150.00/mes de la prima de Parte B (vs $185.00/mes en los otros 2 planes Alignment)',
        'reembolso_parte_b'=>'$150.00/mes de reducción en la prima de Parte B',
        'moop'=>'$2,899.00 al año (no incluye medicamentos recetados) — más alto que los otros 2 planes Alignment ($2,400.00)',
        'hospital_internado'=>"\$120.00 por día, días 1-5\n\$0.00 por día, días 6-90\n(días ilimitados por admisión)",
        'centro_quirurgico_ambulatorio'=>'$50.00 — más barato que los otros 2 planes Alignment ($100.00)',
        'especialistas'=>'$5.00',
        'atencion_emergencia'=>'$110.00 (se exime si es admitido dentro de 48 horas) — más barato que los otros 2 planes Alignment ($150.00)',
        'emergencia_mundial'=>'$0.00 (emergencia y urgencia), límite de $25,000.00 al año — mejor que los otros 2 planes Alignment ($90.00 de copago de emergencia); el Complete Package opcional agrega $75,000.00 adicionales de límite',
        'dental_integral'=>"Restaurativo: \$20.00-\$400.00\nEndodoncia: \$25.00-\$350.00\nPeriodoncia: \$15.00-\$550.00\nProstodoncia removible: \$20.00-\$570.00\nProstodoncia fija: \$40.00-\$400.00\nCirugía oral/maxilofacial: \$25.00-\$250.00\nSÍ incluido en el plan base — a diferencia de los otros 2 planes Alignment, que NO cubren esto sin pagar el Complete Package opcional",
        'anteojos'=>'Límite de $200.00/año para anteojos Y además $100.00/año para lentes de contacto (por separado) — distinto a los otros 2 planes Alignment, que dan $150.00 combinado cada 2 años',
        'salud_mental_internado'=>"\$120.00 por día, días 1-10\n\$0.00 por día, días 11-90\n\$0.00 para 40 días adicionales\n\$0.00 para 60 \"días de reserva de por vida\"",
        'salud_mental_ambulatorio'=>'$10.00 (especialidad de salud mental) — más barato que los otros 2 planes Alignment ($20.00). Servicios psiquiátricos: $20.00 (igual)',
        'terapia_fisica_habla'=>'$0.00 — gratis, a diferencia de los otros 2 planes Alignment ($5.00)',
        'transporte'=>'No cubierto en el plan base (sí disponible en el Complete Package opcional: 24 viajes de ida al año, radio de 30 millas)',
        'ambulancia'=>'$100.00 terrestre / $200.00 aérea (se exime si es admitido) — distinto a los otros 2 planes Alignment ($155.00 fijo, NO se exime si es admitido)',
        'rx_nivel3'=>'$30.00 minorista (30 días) / $75.00 correo (100 días) — el más barato de los 3 planes Alignment',
        'rx_nivel4'=>'$100.00 minorista (30 días) / $300.00 correo (100 días) — copago fijo, no porcentaje (a diferencia de los otros 2 planes Alignment, que cobran 45% de coaseguro)',
        'rx_nivel5'=>'33% de coaseguro (minorista); no disponible por correo',
        'quiropractico_acupuntura'=>'$10.00 cubierto por Medicare (quiropráctico); $0.00 cubierto por Medicare (acupuntura). Este plan NO ofrece quiropráctico ni acupuntura de RUTINA (a diferencia de los otros 2 planes Alignment, que dan 24 visitas/año combinadas a $0.00)',
        'dme'=>'20% de coaseguro en todos los artículos (sin tramo de $0% para artículos de bajo costo, a diferencia de los otros 2 planes Alignment)',
        'extras_json'=>"Tarjeta ACCESS On-Demand Concierge: incluida (acceso a beneficios OTC y Healthy Rewards)\nServicios para mascotas: \$0.00 — 7 días de hospedaje o 14 paseos al año\nControl de plagas: \$0.00 — 1 servicio al año\nCOMPLETE PACKAGE (opcional, +\$64.90/mes): dental integral (0% diagnóstico, 50% en TODOS los demás servicios incluyendo prostodoncia removible y fija — peor que los otros 2 planes Alignment, que dan 0% en removable/fija; límite \$1,500/año), audífonos \$195-\$1,750 copago c/u (2 al año), PERS \$0.00, transporte 24 viajes/año (radio 30 millas), cobertura mundial de emergencia adicional de \$75,000/año",
    ],
];

header('Content-Type: text/plain; charset=utf-8');
echo "=== REPARACIÓN: Alignment Health 2026 (055/056/047) ===\n\n";

foreach ($_align2_variantes_2026 as $pbp => $overrides) {
    $nombre = $overrides['nombre_plan'];
    echo "--- PBP $pbp: \"$nombre\" ---\n";
    try {
        $existe = $pdo->prepare("SELECT id FROM planes_comparacion WHERE nombre_plan=? AND anio=?");
        $existe->execute([$nombre, 2026]);
        $row = $existe->fetch();
        if ($row) {
            echo "  Ya existía (id={$row['id']}) — no se tocó.\n\n";
            continue;
        }
        $plan = array_merge($_align2_base_2026, $overrides);
        $cols = array_keys($plan);
        $ins = $pdo->prepare("INSERT INTO planes_comparacion (".implode(',', $cols).") VALUES (".implode(',', array_fill(0, count($cols), '?')).")");
        $ins->execute(array_values($plan));
        $newId = $pdo->lastInsertId();
        echo "  ✅ INSERTADO correctamente (id=$newId).\n\n";
    } catch (Exception $e) {
        echo "  ❌ ERROR AL INSERTAR: " . $e->getMessage() . "\n\n";
    }
}

echo "=== Estado final de Alignment Health Plan en la tabla ===\n";
$all = $pdo->query("SELECT id, nombre_plan, anio, activo FROM planes_comparacion WHERE carrier='Alignment Health Plan' ORDER BY anio, nombre_plan")->fetchAll();
foreach ($all as $r) {
    echo "  id={$r['id']} | {$r['anio']} | activo={$r['activo']} | {$r['nombre_plan']}\n";
}
echo "\nPuedes borrar este archivo (fix_alignment_2026.php) cuando termines.\n";
