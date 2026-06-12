<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
/**
 * api_ai_mejorada.php — Versión Extendida de Isabel AI
 * 
 * NUEVAS HERRAMIENTAS AGREGADAS:
 * - sugerir_agenda: Agenda inteligente del día
 * - comparar_carriers: Análisis comparativo de carriers
 * - analizar_sentimiento: Detecta insatisfacción en notas
 * - proyectar_bonos: Proyecciones financieras
 * - buscar_miembro_avanzado: Búsqueda con múltiples filtros
 * 
 * Para usar: renombrar a api_ai.php o reemplazar contenido
 */

require_once 'session_boot.php';
require_once 'config.php';
$user  = auth();
$admin = isAdmin();
$uid   = $user['id'];
header('Content-Type: application/json');
if (!csrf_check_post()) { echo json_encode(['ok'=>false,'error'=>'Sesión desactualizada — recarga la página (Ctrl+F5)']); exit; }

if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY || strpos(ANTHROPIC_API_KEY, 'PON_TU_KEY') !== false) {
    echo json_encode(['ok'=>false,'error'=>'API key no configurada.']); exit;
}

$input   = json_decode(file_get_contents('php://input'), true);
$action  = $input['action'] ?? '';
$pdo     = db();

// ═══════════════════════════════════════════════════════════════
// TOOLS EXTENDIDOS — Herramientas originales + nuevas
// ═══════════════════════════════════════════════════════════════
$TOOLS = [
    // ─── ORIGINALES ───
    [
        'name'        => 'buscar_miembro',
        'description' => 'Busca uno o varios miembros por nombre, apellido, MBI, teléfono o carrier. Devuelve perfil completo.',
        'input_schema'=> [
            'type'       => 'object',
            'properties' => ['query'=>['type'=>'string','description'=>'Nombre, apellido, MBI o teléfono a buscar']],
            'required'   => ['query'],
        ],
    ],
    [
        'name'        => 'ver_tickets_miembro',
        'description' => 'Devuelve los tickets abiertos o recientes de un miembro específico.',
        'input_schema'=> [
            'type'       => 'object',
            'properties' => ['miembro_id'=>['type'=>'integer','description'=>'ID del miembro']],
            'required'   => ['miembro_id'],
        ],
    ],
    [
        'name'        => 'estadisticas_generales',
        'description' => 'Devuelve estadísticas del CRM: total activos, nuevos del mes, tickets abiertos urgentes, cancelaciones del mes.',
        'input_schema'=> ['type'=>'object','properties'=>[]],
    ],
    [
        'name'        => 'miembros_en_riesgo',
        'description' => 'Lista miembros activos sin llamadas de retención recientes, con múltiples tickets abiertos, o próximos a 90 días sin consolidar.',
        'input_schema'=> ['type'=>'object','properties'=>[]],
    ],
    [
        'name'        => 'crear_ticket',
        'description' => 'Crea un nuevo ticket en el CRM para un miembro.',
        'input_schema'=> [
            'type'       => 'object',
            'properties' => [
                'miembro_id' => ['type'=>'integer','description'=>'ID del miembro (opcional)'],
                'tipo'       => ['type'=>'string','description'=>'SERVICIO|LLAMADA|APLICACION|CITA|SEGUIMIENTO|QUEJA|SOPORTE|OTRO'],
                'prioridad'  => ['type'=>'string','description'=>'ALTA|MEDIA|BAJA'],
                'descripcion'=> ['type'=>'string','description'=>'Descripción del ticket'],
                'sla_dias'   => ['type'=>'integer','description'=>'Días para el SLA (default 7)'],
            ],
            'required'   => ['descripcion','tipo','prioridad'],
        ],
    ],
    [
        'name'        => 'tickets_urgentes',
        'description' => 'Devuelve todos los tickets de prioridad ALTA que están abiertos, con su SLA y responsable.',
        'input_schema'=> ['type'=>'object','properties'=>[]],
    ],
    [
        'name'        => 'proximos_sla',
        'description' => 'Lista tickets cuyo SLA vence en los próximos N días.',
        'input_schema'=> [
            'type'       => 'object',
            'properties' => ['dias'=>['type'=>'integer','description'=>'Cuántos días hacia adelante (default 3)']],
        ],
    ],
    [
        'name'        => 'produccion_del_mes',
        'description' => 'Devuelve producción mensual: nuevos activos, re-signed, disenrolled, desglosado por semana.',
        'input_schema'=> [
            'type'       => 'object',
            'properties' => [
                'mes'  => ['type'=>'integer','description'=>'Mes (1-12, default actual)'],
                'anio' => ['type'=>'integer','description'=>'Año (default actual)'],
            ],
        ],
    ],
    [
        'name'        => 'generar_sms',
        'description' => 'Redacta un SMS personalizado para un miembro dado un motivo.',
        'input_schema'=> [
            'type'       => 'object',
            'properties' => [
                'miembro_id'=> ['type'=>'integer','description'=>'ID del miembro'],
                'motivo'    => ['type'=>'string','description'=>'Motivo del mensaje (bienvenida, retención, AEP, etc.)'],
            ],
            'required'   => ['miembro_id','motivo'],
        ],
    ],

    // ─── NUEVAS HERRAMIENTAS ───
    
    [
        'name'        => 'sugerir_agenda',
        'description' => 'Sugiere las llamadas más prioritarias del día basado en retención, tickets urgentes y SLAs próximos a vencer. Devuelve una lista ordenada por prioridad.',
        'input_schema'=> [
            'type'       => 'object',
            'properties' => [
                'max_items' => ['type'=>'integer','description'=>'Máximo de sugerencias (default 10)'],
            ],
        ],
    ],
    
    [
        'name'        => 'comparar_carriers',
        'description' => 'Compara el desempeño de dos carriers: tasa de retención, quejas, tickets abiertos, satisfacción promedio.',
        'input_schema'=> [
            'type'       => 'object',
            'properties' => [
                'carrier1' => ['type'=>'string','description'=>'Nombre del primer carrier'],
                'carrier2' => ['type'=>'string','description'=>'Nombre del segundo carrier'],
            ],
            'required'   => ['carrier1','carrier2'],
        ],
    ],
    
    [
        'name'        => 'analizar_sentimiento',
        'description' => 'Analiza las notas recientes de un miembro para detectar señales de insatisfacción o problemas. Útil para identificar riesgo de cancelación.',
        'input_schema'=> [
            'type'       => 'object',
            'properties' => [
                'miembro_id' => ['type'=>'integer','description'=>'ID del miembro'],
            ],
            'required'   => ['miembro_id'],
        ],
    ],
    
    [
        'name'        => 'proyectar_bonos',
        'description' => 'Calcula proyección de bonos para un mes específico basado en tendencias actuales de enrolamiento y retención.',
        'input_schema'=> [
            'type'       => 'object',
            'properties' => [
                'mes'  => ['type'=>'integer','description'=>'Mes a proyectar (1-12)'],
                'anio' => ['type'=>'integer','description'=>'Año'],
            ],
            'required'   => ['mes','anio'],
        ],
    ],
    
    [
        'name'        => 'buscar_miembro_avanzado',
        'description' => 'Búsqueda avanzada de miembros con múltiples filtros: carrier, estado, rango de edad, ciudad, condiciones crónicas.',
        'input_schema'=> [
            'type'       => 'object',
            'properties' => [
                'carrier'     => ['type'=>'string','description'=>'Carrier (opcional)'],
                'estado'      => ['type'=>'string','description'=>'Estado del miembro (opcional)'],
                'edad_min'    => ['type'=>'integer','description'=>'Edad mínima (opcional)'],
                'edad_max'    => ['type'=>'integer','description'=>'Edad máxima (opcional)'],
                'ciudad'      => ['type'=>'string','description'=>'Ciudad (opcional)'],
                'condiciones' => ['type'=>'string','description'=>'Condición crónica a buscar (opcional)'],
            ],
        ],
    ],
];

// ═══════════════════════════════════════════════════════════════
// EJECUTAR TOOLS (extendido)
// ═══════════════════════════════════════════════════════════════
function executeTool(string $name, array $inp, PDO $pdo, array $user, bool $admin, int $uid): string {
    switch ($name) {

        // ─── HERRAMIENTAS ORIGINALES ───
        
        case 'buscar_miembro':
            $q = '%' . $inp['query'] . '%';
            $stmt = $pdo->prepare("
                SELECT id, nombre, apellido, telefono, estado, subestado,
                       carrier, plan, fecha_efectiva, mbi, ciudad,
                       condiciones_cronicas, extras,
                       TIMESTAMPDIFF(YEAR, dob, CURDATE()) as edad
                FROM miembros
                WHERE nombre LIKE ? OR apellido LIKE ?
                   OR mbi LIKE ? OR telefono LIKE ? OR carrier LIKE ?
                LIMIT 5");
            $stmt->execute([$q,$q,$q,$q,$q]);
            $rows = $stmt->fetchAll();
            if (!$rows) return json_encode(['resultado'=>'No se encontraron miembros con esa búsqueda.']);
            return json_encode(['miembros'=>$rows,'total'=>count($rows)]);

        case 'ver_tickets_miembro':
            $stmt = $pdo->prepare("
                SELECT t.id, t.tipo, t.prioridad, t.estado, t.descripcion,
                       t.fecha_creacion, t.sla_fecha, t.fecha_cierre,
                       u.nombre as responsable
                FROM tickets t
                LEFT JOIN usuarios u ON t.asignado_a=u.id
                WHERE t.miembro_id=?
                ORDER BY t.created_at DESC LIMIT 10");
            $stmt->execute([$inp['miembro_id']]);
            return json_encode(['tickets'=>$stmt->fetchAll()]);

        case 'estadisticas_generales':
            $mes = date('Y-m');
            $activos = $pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='ACTIVE'")->fetchColumn();
            $nuevos  = $pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='ACTIVE' AND subestado='NEW ENROLLMENT' AND DATE_FORMAT(fecha_efectiva,'%Y-%m')='$mes'")->fetchColumn();
            $tkt_urg = $pdo->query("SELECT COUNT(*) FROM tickets WHERE estado!='CERRADO' AND prioridad='ALTA'")->fetchColumn();
            $disenrolled = $pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='DISENROLLED' AND DATE_FORMAT(fecha_cancelacion,'%Y-%m')='$mes'")->fetchColumn();
            $in_process  = $pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='IN PROCESS'")->fetchColumn();
            return json_encode(compact('activos','nuevos','tkt_urg','disenrolled','in_process','mes'));

        case 'miembros_en_riesgo':
            $stmt = $pdo->query("
                SELECT m.id, m.nombre, m.apellido, m.carrier, m.estado,
                       m.fecha_efectiva,
                       DATEDIFF(CURDATE(), m.fecha_efectiva) as dias_activo,
                       m.llam_90,
                       (SELECT COUNT(*) FROM tickets t WHERE t.miembro_id=m.id AND t.estado!='CERRADO') as tickets_abiertos
                FROM miembros m
                WHERE m.estado='ACTIVE'
                  AND (
                    m.llam_90 IS NULL OR m.llam_90=''
                    OR (SELECT COUNT(*) FROM tickets t WHERE t.miembro_id=m.id AND t.estado!='CERRADO') >= 2
                    OR DATEDIFF(CURDATE(), m.fecha_efectiva) BETWEEN 75 AND 95
                  )
                ORDER BY dias_activo ASC
                LIMIT 15");
            return json_encode(['en_riesgo'=>$stmt->fetchAll()]);

        case 'crear_ticket':
            if (!$admin && $name === 'crear_ticket') {
                return json_encode(['error'=>'Solo administradores pueden crear tickets vía IA']);
            }
            $sla = date('Y-m-d', strtotime('+'.($inp['sla_dias']??7).' days'));
            $mid = isset($inp['miembro_id']) && $inp['miembro_id'] ? (int)$inp['miembro_id'] : null;
            $pdo->prepare("INSERT INTO tickets
                (miembro_id,agente_id,asignado_a,tipo,prioridad,estado,descripcion,fecha_creacion,sla_fecha)
                VALUES (?,?,?,?,?,?,?,CURDATE(),?)")
                ->execute([$mid,$uid,$uid,$inp['tipo'],$inp['prioridad'],'ABIERTO',$inp['descripcion'],$sla]);
            $new_id = $pdo->lastInsertId();
            return json_encode(['ok'=>true,'ticket_id'=>$new_id,'mensaje'=>"Ticket #$new_id creado con éxito. SLA: $sla"]);

        case 'tickets_urgentes':
            $stmt = $pdo->query("
                SELECT t.id, t.tipo, t.descripcion, t.sla_fecha,
                       COALESCE(CONCAT(m.apellido,', ',m.nombre), t.cliente, t.nombre_referencia) as contacto,
                       u.nombre as responsable,
                       DATEDIFF(t.sla_fecha, CURDATE()) as dias_restantes
                FROM tickets t
                LEFT JOIN miembros m ON t.miembro_id=m.id
                LEFT JOIN usuarios u ON t.asignado_a=u.id
                WHERE t.estado!='CERRADO' AND t.prioridad='ALTA'
                ORDER BY t.sla_fecha ASC");
            return json_encode(['urgentes'=>$stmt->fetchAll()]);

        case 'proximos_sla':
            $dias = $inp['dias'] ?? 3;
            $stmt = $pdo->prepare("
                SELECT t.id, t.tipo, t.descripcion, t.sla_fecha,
                       COALESCE(CONCAT(m.apellido,', ',m.nombre), t.cliente) as contacto,
                       u.nombre as responsable,
                       DATEDIFF(t.sla_fecha, CURDATE()) as dias_restantes
                FROM tickets t
                LEFT JOIN miembros m ON t.miembro_id=m.id
                LEFT JOIN usuarios u ON t.asignado_a=u.id
                WHERE t.estado!='CERRADO'
                  AND t.sla_fecha IS NOT NULL
                  AND t.sla_fecha <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                ORDER BY t.sla_fecha ASC");
            $stmt->execute([$dias]);
            return json_encode(['proximos_sla'=>$stmt->fetchAll()]);

        case 'produccion_del_mes':
            $m = $inp['mes']  ?? (int)date('m');
            $a = $inp['anio'] ?? (int)date('Y');
            $mes_str = sprintf('%04d-%02d', $a, $m);
            $nuevos   = $pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='ACTIVE' AND subestado='NEW ENROLLMENT' AND DATE_FORMAT(fecha_efectiva,'%Y-%m')='$mes_str'")->fetchColumn();
            $resigned = $pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='ACTIVE' AND subestado='RE-SIGNED' AND DATE_FORMAT(fecha_efectiva,'%Y-%m')='$mes_str'")->fetchColumn();
            $bajas    = $pdo->query("SELECT COUNT(*) FROM miembros WHERE estado IN ('DISENROLLED','CANCELED') AND DATE_FORMAT(fecha_cancelacion,'%Y-%m')='$mes_str'")->fetchColumn();
            return json_encode(compact('nuevos','resigned','bajas','mes_str'));

        case 'generar_sms':
            $m = $pdo->prepare("SELECT nombre, apellido, carrier FROM miembros WHERE id=?");
            $m->execute([$inp['miembro_id']]);
            $mb = $m->fetch();
            if (!$mb) return json_encode(['error'=>'Miembro no encontrado']);
            if (function_exists('generarSMS')) {
                $sms = generarSMS($mb['nombre'].' '.$mb['apellido'], $inp['motivo'], $mb['carrier']??'', $user['nombre']);
                return json_encode(['sms'=>$sms,'miembro'=>$mb['nombre'].' '.$mb['apellido']]);
            }
            return json_encode(['error'=>'prompts.php no cargado']);

        // ─── NUEVAS HERRAMIENTAS ───

        case 'sugerir_agenda':
            $max = $inp['max_items'] ?? 10;
            $agenda = [];
            
            // 1. Retenciones del día (máxima prioridad)
            $retenciones = $pdo->query("
                SELECT id, nombre, apellido, carrier, fecha_efectiva,
                       DATEDIFF(CURDATE(), fecha_efectiva) as dias_activo,
                       'RETENCION' as tipo_accion,
                       CASE 
                         WHEN DATEDIFF(CURDATE(), fecha_efectiva) = 90 THEN 1
                         WHEN DATEDIFF(CURDATE(), fecha_efectiva) = 60 THEN 2
                         WHEN DATEDIFF(CURDATE(), fecha_efectiva) = 30 THEN 3
                         WHEN DATEDIFF(CURDATE(), fecha_efectiva) = 7 THEN 4
                       END as prioridad
                FROM miembros
                WHERE estado='ACTIVE'
                  AND fecha_efectiva IN (
                    DATE_SUB(CURDATE(), INTERVAL 7 DAY),
                    DATE_SUB(CURDATE(), INTERVAL 30 DAY),
                    DATE_SUB(CURDATE(), INTERVAL 60 DAY),
                    DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                  )
                ORDER BY prioridad ASC
            ")->fetchAll();
            foreach ($retenciones as $r) {
                $agenda[] = [
                    'tipo' => 'RETENCIÓN '.$r['dias_activo'].' días',
                    'miembro' => $r['nombre'].' '.$r['apellido'],
                    'miembro_id' => $r['id'],
                    'carrier' => $r['carrier'],
                    'prioridad' => $r['prioridad'],
                ];
            }
            
            // 2. Tickets urgentes sin resolver
            $tickets = $pdo->query("
                SELECT t.id, t.descripcion, CONCAT(m.nombre,' ',m.apellido) as miembro,
                       m.id as miembro_id, m.carrier
                FROM tickets t
                LEFT JOIN miembros m ON t.miembro_id=m.id
                WHERE t.estado!='CERRADO' AND t.prioridad='ALTA'
                ORDER BY t.sla_fecha ASC
                LIMIT 5
            ")->fetchAll();
            foreach ($tickets as $t) {
                $agenda[] = [
                    'tipo' => 'TICKET URGENTE',
                    'miembro' => $t['miembro'] ?? 'Sin asignar',
                    'miembro_id' => $t['miembro_id'],
                    'descripcion' => substr($t['descripcion'], 0, 60).'...',
                    'prioridad' => 1,
                ];
            }
            
            // 3. Miembros en riesgo sin llamadas recientes
            $riesgo = $pdo->query("
                SELECT id, nombre, apellido, carrier
                FROM miembros
                WHERE estado='ACTIVE'
                  AND (llam_90 IS NULL OR llam_90='')
                  AND DATEDIFF(CURDATE(), fecha_efectiva) > 45
                LIMIT 3
            ")->fetchAll();
            foreach ($riesgo as $r) {
                $agenda[] = [
                    'tipo' => 'SEGUIMIENTO (sin llamadas)',
                    'miembro' => $r['nombre'].' '.$r['apellido'],
                    'miembro_id' => $r['id'],
                    'carrier' => $r['carrier'],
                    'prioridad' => 3,
                ];
            }
            
            // Ordenar por prioridad y limitar
            usort($agenda, fn($a,$b) => $a['prioridad'] <=> $b['prioridad']);
            $agenda = array_slice($agenda, 0, $max);
            
            return json_encode(['agenda'=>$agenda,'total'=>count($agenda)]);

        case 'comparar_carriers':
            $c1 = $inp['carrier1'];
            $c2 = $inp['carrier2'];
            
            $stats = [];
            foreach ([$c1, $c2] as $carrier) {
                $activos = $pdo->prepare("SELECT COUNT(*) FROM miembros WHERE carrier=? AND estado='ACTIVE'");
                $activos->execute([$carrier]);
                
                $cancelados = $pdo->prepare("SELECT COUNT(*) FROM miembros WHERE carrier=? AND estado='DISENROLLED' AND fecha_cancelacion >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)");
                $cancelados->execute([$carrier]);
                
                $quejas = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE estado!='CERRADO' AND tipo='QUEJA' AND miembro_id IN (SELECT id FROM miembros WHERE carrier=?)");
                $quejas->execute([$carrier]);
                
                $tickets_total = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE miembro_id IN (SELECT id FROM miembros WHERE carrier=?)");
                $tickets_total->execute([$carrier]);
                
                $stats[$carrier] = [
                    'activos' => $activos->fetchColumn(),
                    'cancelados_6m' => $cancelados->fetchColumn(),
                    'quejas_abiertas' => $quejas->fetchColumn(),
                    'tickets_totales' => $tickets_total->fetchColumn(),
                ];
                
                // Calcular tasa de retención
                $total = $stats[$carrier]['activos'] + $stats[$carrier]['cancelados_6m'];
                $stats[$carrier]['tasa_retencion'] = $total > 0 ? round(($stats[$carrier]['activos'] / $total) * 100, 1) : 0;
                $stats[$carrier]['quejas_por_100_miembros'] = $stats[$carrier]['activos'] > 0 ? round(($stats[$carrier]['quejas_abiertas'] / $stats[$carrier]['activos']) * 100, 1) : 0;
            }
            
            return json_encode(['comparacion'=>$stats]);

        case 'analizar_sentimiento':
            $mid = $inp['miembro_id'];
            
            // Obtener notas recientes y tickets
            $miembro = $pdo->prepare("SELECT nombre, apellido, extras FROM miembros WHERE id=?");
            $miembro->execute([$mid]);
            $m = $miembro->fetch();
            
            if (!$m) return json_encode(['error'=>'Miembro no encontrado']);
            
            $tickets = $pdo->prepare("
                SELECT descripcion, tipo, prioridad, created_at
                FROM tickets
                WHERE miembro_id=?
                ORDER BY created_at DESC
                LIMIT 5
            ");
            $tickets->execute([$mid]);
            $tks = $tickets->fetchAll();
            
            // Análisis simple de palabras negativas
            $texto_completo = ($m['extras'] ?? '') . ' ';
            foreach ($tks as $t) {
                $texto_completo .= $t['descripcion'] . ' ';
            }
            
            $palabras_negativas = ['enojado', 'molesto', 'frustra', 'queja', 'problema', 'mal servicio', 'cancelar', 'insatisfecho', 'peor', 'nunca'];
            $palabras_positivas = ['contento', 'satisfecho', 'excelente', 'gracias', 'bien', 'feliz'];
            
            $sentimiento_negativo = 0;
            $sentimiento_positivo = 0;
            
            foreach ($palabras_negativas as $palabra) {
                $sentimiento_negativo += substr_count(strtolower($texto_completo), $palabra);
            }
            foreach ($palabras_positivas as $palabra) {
                $sentimiento_positivo += substr_count(strtolower($texto_completo), $palabra);
            }
            
            $nivel_riesgo = 'BAJO';
            if ($sentimiento_negativo > 3) $nivel_riesgo = 'ALTO';
            elseif ($sentimiento_negativo > 1) $nivel_riesgo = 'MEDIO';
            
            return json_encode([
                'miembro' => $m['nombre'].' '.$m['apellido'],
                'nivel_riesgo' => $nivel_riesgo,
                'señales_negativas' => $sentimiento_negativo,
                'señales_positivas' => $sentimiento_positivo,
                'tickets_recientes' => count($tks),
                'recomendacion' => $nivel_riesgo === 'ALTO' ? 'Llamar HOY para retención' : ($nivel_riesgo === 'MEDIO' ? 'Hacer seguimiento esta semana' : 'Miembro estable'),
            ]);

        case 'proyectar_bonos':
            $mes = $inp['mes'];
            $anio = $inp['anio'];
            
            // Obtener tendencias de los últimos 3 meses
            $tendencias = [];
            for ($i = 1; $i <= 3; $i++) {
                $fecha = date('Y-m', strtotime("-$i month"));
                $nuevos = $pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='ACTIVE' AND subestado='NEW ENROLLMENT' AND DATE_FORMAT(fecha_efectiva,'%Y-%m')='$fecha'")->fetchColumn();
                $tendencias[] = $nuevos;
            }
            
            // Promedio de nuevos por mes
            $promedio_nuevos = array_sum($tendencias) / count($tendencias);
            
            // Proyección simple (se puede mejorar con algoritmos más sofisticados)
            $proyeccion_nuevos = round($promedio_nuevos);
            
            // Bonos aproximados (ajustar según estructura real de bonos)
            $bono_por_nuevo = 150; // Ejemplo
            $bono_proyectado = $proyeccion_nuevos * $bono_por_nuevo;
            
            return json_encode([
                'mes' => "$mes/$anio",
                'nuevos_proyectados' => $proyeccion_nuevos,
                'tendencia_3_meses' => $tendencias,
                'promedio_mensual' => round($promedio_nuevos, 1),
                'bono_estimado' => $bono_proyectado,
                'nota' => 'Proyección basada en promedio de últimos 3 meses',
            ]);

        case 'buscar_miembro_avanzado':
            $where = ['1=1'];
            $params = [];
            
            if (!empty($inp['carrier'])) {
                $where[] = 'carrier = ?';
                $params[] = $inp['carrier'];
            }
            if (!empty($inp['estado'])) {
                $where[] = 'estado = ?';
                $params[] = $inp['estado'];
            }
            if (!empty($inp['ciudad'])) {
                $where[] = 'ciudad LIKE ?';
                $params[] = '%'.$inp['ciudad'].'%';
            }
            if (!empty($inp['condiciones'])) {
                $where[] = 'condiciones_cronicas LIKE ?';
                $params[] = '%'.$inp['condiciones'].'%';
            }
            if (isset($inp['edad_min'])) {
                $where[] = 'TIMESTAMPDIFF(YEAR, dob, CURDATE()) >= ?';
                $params[] = $inp['edad_min'];
            }
            if (isset($inp['edad_max'])) {
                $where[] = 'TIMESTAMPDIFF(YEAR, dob, CURDATE()) <= ?';
                $params[] = $inp['edad_max'];
            }
            
            $sql = "SELECT id, nombre, apellido, telefono, estado, carrier, plan, ciudad,
                           TIMESTAMPDIFF(YEAR, dob, CURDATE()) as edad,
                           condiciones_cronicas
                    FROM miembros
                    WHERE " . implode(' AND ', $where) . "
                    LIMIT 20";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll();
            
            return json_encode([
                'miembros' => $results,
                'total' => count($results),
                'filtros_aplicados' => array_filter($inp),
            ]);

        default:
            return json_encode(['error'=>'Herramienta desconocida: '.$name]);
    }
}

// ═══════════════════════════════════════════════════════════════
// CHAT — igual que antes pero con system prompt mejorado
// ═══════════════════════════════════════════════════════════════
if ($action === 'chat') {
    $history  = $input['history'] ?? [];
    $message  = trim($input['message'] ?? '');
    if (!$message) { echo json_encode(['ok'=>false,'error'=>'Mensaje vacío']); exit; }

    // System prompt mejorado
    $system = "Eres Isabel AI, el asistente inteligente del CRM Medicare with Isabel.

CONTEXTO CRÍTICO:
- Usuario actual: {$user['nombre']} (".($admin?'Admin':'Agente').")
- Fecha: ".date('d/m/Y, H:i')."
- Tienes acceso en tiempo real a la base de datos mediante herramientas

PERSONALIDAD:
- PROACTIVA: Si detectas un problema, propón soluciones sin que te pidan
- PRECISA: Da números exactos de la BD, nunca estimes
- CONCISA: Máximo 3 párrafos, usa bullets para listas
- PRÁCTICA: Termina cada respuesta con una acción concreta si aplica
- BILINGÜE: Responde en el idioma que te escriban

CAPACIDADES EXTENDIDAS:
✓ Buscar miembros (simple y avanzada)
✓ Ver tickets y crear nuevos
✓ Identificar miembros en riesgo
✓ Analizar sentimiento en notas
✓ Comparar carriers
✓ Sugerir agenda del día
✓ Proyectar bonos
✓ Ver producción y estadísticas
✓ Generar SMS

REGLAS CRÍTICAS:
1. NUNCA inventes datos — si no los tienes, usa una herramienta
2. Si mencionan un nombre → usa buscar_miembro SIEMPRE
3. Si piden crear ticket → confirma que eres admin primero
4. Si detectas múltiples problemas → prioriza por urgencia
5. Usa formato de números: 1,234 no 1234

Cuando uses herramientas, sintetiza los resultados de forma clara.";

    // Agregar nuevo mensaje a historial
    $messages = $history;
    $messages[] = ['role'=>'user','content'=>$message];

    // Loop de tool use
    $max_iterations = 5;
    $iteration = 0;
    $final_text = '';
    $actions_taken = [];

    while ($iteration < $max_iterations) {
        $iteration++;

        $payload = [
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 1500,
            'system'     => $system,
            'tools'      => $TOOLS,
            'messages'   => $messages,
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: '.ANTHROPIC_API_KEY,
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$res) {
            echo json_encode(['ok'=>false,'error'=>'Error al conectar con la IA (HTTP '.$code.')']);
            exit;
        }

        $resp = json_decode($res, true);
        $stop_reason = $resp['stop_reason'] ?? '';
        $content     = $resp['content'] ?? [];

        // Agregar respuesta del asistente al historial
        $messages[] = ['role'=>'assistant','content'=>$content];

        if ($stop_reason === 'end_turn') {
            // Respuesta final — extraer texto
            foreach ($content as $block) {
                if ($block['type'] === 'text') {
                    $final_text .= $block['text'];
                }
            }
            break;
        }

        if ($stop_reason === 'tool_use') {
            // Ejecutar tools y continuar
            $tool_results = [];
            foreach ($content as $block) {
                if ($block['type'] === 'tool_use') {
                    $tool_name = $block['name'];
                    $tool_inp  = $block['input'];
                    $tool_id   = $block['id'];

                    $result = executeTool($tool_name, $tool_inp, $pdo, $user, $admin, $uid);
                    $actions_taken[] = $tool_name;

                    $tool_results[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $tool_id,
                        'content'     => $result,
                    ];
                }
            }
            // Agregar resultados de tools al historial
            $messages[] = ['role'=>'user','content'=>$tool_results];
            continue;
        }

        break; // stop_reason inesperado
    }

    if (!$final_text) {
        $final_text = 'No pude generar una respuesta. Intenta de nuevo.';
    }

    // Devolver respuesta + historial limpio
    $clean_history = array_filter($messages, function($m) {
        if ($m['role'] === 'user' && is_array($m['content'])) {
            foreach ($m['content'] as $b) {
                if (($b['type']??'') === 'tool_result') return false;
            }
        }
        return true;
    });

    echo json_encode([
        'ok'           => true,
        'text'         => $final_text,
        'actions'      => array_unique($actions_taken),
        'history'      => array_values($clean_history),
    ]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Acción no válida']);
