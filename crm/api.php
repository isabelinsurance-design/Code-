<?php
require_once 'session_boot.php';
require_once 'config.php';
// Un API JSON nunca debe imprimir warnings/notices: corromperían la respuesta
// y el navegador mostraría "Error de conexión". Se loguean, no se muestran.
ini_set('display_errors', '0');
header('Content-Type: application/json');
if (empty($_SESSION['user'])) { echo json_encode(['error'=>'No autorizado']); exit; }
if (!csrf_check_post()) { echo json_encode(['ok'=>false,'error'=>'Sesión desactualizada — recarga la página (Ctrl+F5) e intenta de nuevo']); exit; }
$user = $_SESSION['user'];
$admin = $user['rol'] === 'admin';
$uid = $user['id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';
function jsonOk($data=[]) { echo json_encode(['ok'=>true,'data'=>$data]); exit; }
function jsonErr($msg) { echo json_encode(['ok'=>false,'error'=>$msg]); exit; }

function completarNextStepsDelTicket(PDO $pdo, int $ticket_id, ?int $agente_id = null): int {
    $stmt = $pdo->prepare("UPDATE ticket_next_steps
                           SET completado=1, fecha_completado=NOW(),
                               notas_completado=COALESCE(notas_completado, 'Auto-completado al cerrar ticket')
                           WHERE ticket_id=? AND completado=0");
    $stmt->execute([$ticket_id]);
    return $stmt->rowCount();
}

function calcTiempoResolucion(string $fecha_creacion, string $fecha_cierre): string {
    $diff = strtotime($fecha_cierre) - strtotime($fecha_creacion);
    if ($diff <= 0) return '0 min';
    $d = floor($diff / 86400);
    $h = floor(($diff % 86400) / 3600);
    $m = floor(($diff % 3600) / 60);
    if ($d > 0) return "$d d $h h";
    if ($h > 0) return "$h h $m min";
    return "$m min";
}

$TIPO_MIEMBRO_API = ['FOLLOW UP','QUEJA','CAMBIO DE DOCTOR','CLIENTE','CITA','APLICACION',
                     'SERVICIO AL CLIENTE','LLAMADA','LLAMADA PERDIDA','CITA DENTAL','URGENTE'];
// Auto-add DM columns if not present (runs once, cheap)
try {
  $pdo_m = db();
  $cols = $pdo_m->query("SHOW COLUMNS FROM chat_mensajes")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('recipient_id',$cols))
    $pdo_m->exec("ALTER TABLE chat_mensajes ADD COLUMN recipient_id INT NULL DEFAULT NULL, ADD COLUMN es_dm TINYINT(1) NOT NULL DEFAULT 0");
} catch(Exception $e){}

// ── SINGLE try/catch wrapping ONE switch ─────────────────────
try {
switch ($action) {

// ── CHECKIN ──────────────────────────────────────────────────
case 'checkin':
    $field = $_POST['field'] ?? '';
    $valid = [
        'ci'=>'check_in','lo'=>'lunch_out','li'=>'lunch_in',
        'bo'=>'break_out','bi'=>'break_in','co'=>'check_out'
    ];
    if (!isset($valid[$field])) jsonErr('Campo inválido');
    $col = $valid[$field];
    $t = date('H:i:s');
    $pdo = db();
    $existing = $pdo->prepare("SELECT id FROM asistencia WHERE agente_id=? AND fecha=?");
    $existing->execute([$uid, date('Y-m-d')]);
    $row = $existing->fetch();
    if ($row) {
        $pdo->prepare("UPDATE asistencia SET $col=? WHERE id=?")->execute([$t, $row['id']]);
    } else {
        $pdo->prepare("INSERT INTO asistencia (agente_id,fecha,$col) VALUES (?,?,?)")
            ->execute([$uid, date('Y-m-d'), $t]);
    }
    $labels = [
        'ci'=>'CHECK-IN','lo'=>'SALIDA ALMUERZO','li'=>'REGRESO ALMUERZO',
        'bo'=>'SALIDA BREAK','bi'=>'REGRESO BREAK','co'=>'CHECK-OUT'
    ];
    $pdo->prepare("INSERT INTO actividad (agente_id,tipo,descripcion) VALUES (?,?,?)")
        ->execute([$uid, $labels[$field], $labels[$field].' — '.$t]);
    jsonOk(['time'=>$t,'hora'=>$t,'field'=>$field]);
    break;

// ── CORREGIR ASISTENCIA (admin) ───────────────────────────────
// El admin corrige check-in/out de un registro; queda en el HISTORIAL.
case 'edit_asistencia':
    if (!$admin) jsonErr('Solo admin puede corregir asistencia');
    $aid = intval($_POST['id'] ?? 0);
    if (!$aid) jsonErr('ID requerido');
    $pdo = db();
    $old = $pdo->prepare("SELECT a.*, u.nombre AS emp_nombre FROM asistencia a LEFT JOIN usuarios u ON a.agente_id=u.id WHERE a.id=?");
    $old->execute([$aid]);
    $orow = $old->fetch();
    if (!$orow) jsonErr('Registro no encontrado');
    $existing_cols = $pdo->query("SHOW COLUMNS FROM asistencia")->fetchAll(PDO::FETCH_COLUMN);
    $cols = array_values(array_intersect(['check_in','lunch_out','lunch_in','break_out','break_in','check_out'], $existing_cols));
    $sets=[]; $vals=[]; $cambios=[];
    $lbl = ['check_in'=>'CHECK-IN','lunch_out'=>'SAL.ALM','lunch_in'=>'REG.ALM','break_out'=>'SAL.BREAK','break_in'=>'REG.BREAK','check_out'=>'CHECK-OUT'];
    foreach($cols as $col){
        $v = trim($_POST[$col] ?? '');
        $norm = $v==='' ? null : (strlen($v)===5 ? $v.':00' : $v);
        $sets[] = "$col=?"; $vals[] = $norm;
        $oldv = !empty($orow[$col]) ? substr($orow[$col],0,5) : '—';
        $newv = $norm ? substr($norm,0,5) : '—';
        if ($oldv !== $newv) $cambios[] = $lbl[$col].": $oldv→$newv";
    }
    if (empty($sets)) jsonErr('Sin columnas válidas');
    $vals[] = $aid;
    $pdo->prepare("UPDATE asistencia SET ".implode(',', $sets)." WHERE id=?")->execute($vals);
    if ($cambios) {
        $desc = $user['nombre'].' corrigió asistencia de '.($orow['emp_nombre'] ?: ('#'.$orow['agente_id'])).' ('.$orow['fecha'].'): '.implode(' · ', $cambios);
        try { $pdo->prepare("INSERT INTO actividad (agente_id,tipo,descripcion) VALUES (?,?,?)")->execute([$uid, 'ASISTENCIA', $desc]); } catch (Exception $e) {}
    }
    jsonOk(['cambios'=>count($cambios)]);
    break;

// ── MEMBERS ──────────────────────────────────────────────────
case 'iniciar_cambio_plan':
    $pdo = db();
    $mid = (int)($_POST['miembro_id'] ?? 0);
    if (!$mid) jsonErr('ID requerido');
    $curr_q = $pdo->prepare("SELECT plan, carrier, tipo_plan, fecha_efectiva FROM miembros WHERE id=?");
    $curr_q->execute([$mid]); $curr = $curr_q->fetch();
    $n_plan    = trim($_POST['nuevo_plan']    ?? '') ?: $curr['plan'];
    $n_carrier = trim($_POST['nuevo_carrier'] ?? '') ?: $curr['carrier'];
    $n_fecha   = trim($_POST['nueva_fecha']   ?? '') ?: $curr['fecha_efectiva'];
    $plan_ant  = ($curr['carrier'] ? $curr['carrier'].' — ' : '').$curr['plan'];
    $pdo->prepare("UPDATE miembros SET estado='READY TO ENROLL', subestado='RE-SIGNED', plan=?, carrier=?, fecha_efectiva=?, plan_anterior=?, app_tipo='RE-SIGNED', updated_at=NOW() WHERE id=?")
        ->execute([$n_plan, $n_carrier, $n_fecha, $plan_ant, $mid]);
    $pdo->prepare("INSERT INTO actividad (agente_id,miembro_id,tipo,descripcion) VALUES (?,?,?,?)")
        ->execute([$uid, $mid, 'PLAN CHANGE',
            'CAMBIO DE PLAN INICIADO: '.($curr['carrier']?$curr['carrier'].' ':'').$curr['plan']
            .' → '.($n_carrier?$n_carrier.' ':'').$n_plan]);
    jsonOk(['msg'=>'Cambio de plan iniciado']);
    break;

case 'get_historial_mes':
    $pdo = db();
    $mes = trim($_POST['mes'] ?? date('Y-m'));
    $first = $mes.'-01';
    $last  = date('Y-m-t', strtotime($first));
    // Crear tabla si no existe
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS historial_planes (
        id INT AUTO_INCREMENT PRIMARY KEY, miembro_id INT NOT NULL,
        plan VARCHAR(150), carrier VARCHAR(100), tipo_plan VARCHAR(100),
        subestado VARCHAR(50), agente_id INT, fecha_inicio DATE NOT NULL,
        fecha_fin DATE DEFAULT NULL, motivo_fin VARCHAR(50) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mid (miembro_id), INDEX idx_fi (fecha_inicio), INDEX idx_ff (fecha_fin)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}
    $rows = $pdo->prepare("
        SELECT h.*, m.nombre, m.apellido, m.telefono, m.ciudad, m.dob,
               u.nombre as agente_nombre, u.iniciales as agente_ini, u.color as agente_color
        FROM historial_planes h
        JOIN miembros m ON h.miembro_id = m.id
        LEFT JOIN usuarios u ON h.agente_id = u.id
        WHERE h.fecha_inicio <= ?
          AND (h.fecha_fin IS NULL OR h.fecha_fin >= ?)
        ORDER BY m.apellido, m.nombre");
    $rows->execute([$last, $first]);
    jsonOk(['historial' => $rows->fetchAll(PDO::FETCH_ASSOC), 'mes' => $mes]);
    break;

case 'save_member':
    $d = $_POST;
    $pdo = db();

    $existing_cols = $pdo->query("SHOW COLUMNS FROM miembros")->fetchAll(PDO::FETCH_COLUMN);
    $all_fields = ['nombre','middle_name','apellido','telefono','telefono2','estado','subestado','agente_id',
        'dob','sexo','idioma','estado_civil','pareja_id','direccion_calle','direccion_apto','ciudad','county','zip',
        'mbi','member_id','parte_a','parte_b','medical','medical_nivel','ss','elegibilidad','fecha_efectiva',
        'pcp','pcp_address','pcp_city','pcp_group','pcp_phone','pcp_state','pcp_zip',
        'dentista','email','evento','razon_cancelacion','fuente','fuente_campana','referido_por','tipo_referido',
        'plan','carrier','tipo_plan','plan_secundario','plan_anterior',
        'prescripciones','condiciones_cronicas','especialistas','profesion','empresa','estatus_legal',
        'extras','opt_in','opt_out','info_verificada','carpeta_drive',
        'llam_bienvenida','llam_30','llam_60','llam_90',
        'llamada_estado','llamada_nueva','ultimo_contacto',
        'alerta_activa','alerta_texto',
        'app_tipo','app_periodo','app_fecha','app_estado_cms','app_carrier_estado','hra',
        'fecha_cancelacion','broker_mwi','commission_paid',
        'sales_allegation','foto_perfil'];
        
    $fields = array_values(array_intersect($all_fields, $existing_cols));
    if (empty($fields)) jsonErr('No se pudieron detectar columnas de la tabla miembros');

    $clean = function($f) use ($d) {
        if (!isset($d[$f])) return null;
        $v = $d[$f];
        return ($v === '') ? null : $v;
    };

    try {
        if (!empty($d['id'])) {
            // UPDATE EXISTENTE
            // ── ANTES DEL UPDATE: obtener estado actual para comparar ──────────────
            $cambio_log = '';
            $pre = $pdo->prepare("SELECT estado, plan, carrier, tipo_plan, subestado, fecha_efectiva, fecha_cancelacion FROM miembros WHERE id=?");
            $pre->execute([$d['id']]);
            $old_data = $pre->fetch(PDO::FETCH_ASSOC);

            // Auto-guardar plan_anterior si es RE-SIGNED
            if (($d['subestado'] ?? '') === 'RE-SIGNED') {
                $plan_nuevo    = trim($d['plan']    ?? '');
                $carrier_nuevo = trim($d['carrier'] ?? '');
                $plan_viejo    = trim($old_data['plan']    ?? '');
                $carrier_viejo = trim($old_data['carrier'] ?? '');
                if (empty($d['plan_anterior']) && !empty($plan_viejo))
                    $d['plan_anterior'] = $plan_viejo.($carrier_viejo ? ' ('.$carrier_viejo.')' : '');
                if ($plan_viejo !== $plan_nuevo || $carrier_viejo !== $carrier_nuevo)
                    $cambio_log = 'CAMBIO DE PLAN: '.($carrier_viejo?:'—').' '.$plan_viejo.' → '.($carrier_nuevo?:'—').' '.$plan_nuevo;
            }

            // ── UPDATE ────────────────────────────────────────────────────────
            $sets = implode(',', array_map(fn($f)=>"$f=?", $fields));
            $vals = array_map($clean, $fields);
            $vals[] = $d['id'];
            $pdo->prepare("UPDATE miembros SET $sets, updated_at=NOW() WHERE id=?")->execute($vals);

            // ── HISTORIAL DE PLANES ───────────────────────────────────────────
            _historial_planes($pdo, $d['id'], $old_data, $d, $uid);

            // Actividad
            $desc_act = $cambio_log ?: 'Perfil actualizado por '.$user['nombre'];
            $tipo_act = $cambio_log ? 'PLAN CHANGE' : 'SISTEMA';
            $pdo->prepare("INSERT INTO actividad (agente_id,miembro_id,tipo,descripcion) VALUES (?,?,?,?)")
                ->execute([$uid,$d['id'],$tipo_act,$desc_act]);
            jsonOk(['id'=>$d['id'],'msg'=>'Miembro actualizado','cambio_plan'=>!empty($cambio_log)]);
        } else {
            // INSERT NUEVO PROSPECTO
            $cols_str = implode(',', $fields);
            $placeholders = implode(',', array_fill(0, count($fields), '?'));
            $vals = array_map($clean, $fields);
            $pdo->prepare("INSERT INTO miembros ($cols_str) VALUES ($placeholders)")->execute($vals);
            $newId = $pdo->lastInsertId();
            
            $pdo->prepare("INSERT INTO actividad (agente_id,miembro_id,tipo,descripcion) VALUES (?,?,?,?)")
                ->execute([$uid,$newId,'NOTA','PROSPECTO CREADO']);

            // Historial: si se crea directo como ACTIVE, registrar
            if (($d['estado'] ?? '') === 'ACTIVE' && !empty($d['fecha_efectiva'])) {
                _historial_planes($pdo, $newId, ['estado'=>'PROSPECT','plan'=>'','carrier'=>'','fecha_efectiva'=>null,'fecha_cancelacion'=>null], $d, $uid);
            }

            // =========================================================
            // 2. GENERADOR DE PIPELINE DINÁMICO (ACTUALIZADO)
            // =========================================================
            $agente_asignado = !empty($d['agente_id']) ? $d['agente_id'] : $uid;
            
            // Consultamos la configuración que tú misma definas en la tabla pipeline_config
            $config_query = $pdo->query("SELECT descripcion, dias_intervalo FROM pipeline_config ORDER BY orden ASC");
            $pasos_maestros = $config_query->fetchAll();

            // Si no has configurado nada aún, usamos unos por defecto para no dejarlo vacío
            if (empty($pasos_maestros)) {
                $pasos_maestros = [
                    ['descripcion' => 'Llamada de introducción', 'dias_intervalo' => 0],
                    ['descripcion' => 'Seguimiento de información', 'dias_intervalo' => 2]
                ];
            }
            
            $pipe_stmt = $pdo->prepare("INSERT INTO pipeline_pasos (miembro_id, agente_id, descripcion, fecha_programada) 
                                        VALUES (?, ?, ?, DATE_ADD(CURDATE(), INTERVAL ? DAY))");
            
            foreach ($pasos_maestros as $pm) {
                $pipe_stmt->execute([
                    $newId, 
                    $agente_asignado, 
                    $pm['descripcion'], 
                    $pm['dias_intervalo']
                ]);
            }
            // =========================================================

            jsonOk(['id'=>$newId,'msg'=>'Prospecto guardado y Pipeline generado según configuración']);
        }
    } catch (PDOException $e) {
        jsonErr('Error de base de datos: ' . $e->getMessage());
    }
    break;

// ── TICKETS ──────────────────────────────────────────────────
case 'close_ticket':
    $pdo = db(); // <--- CONEXIÓN AÑADIDA
    $id   = (int)($_POST['id'] ?? 0);
    $nota = trim($_POST['nota_cierre'] ?? '');
    if (!$id) jsonErr('ID inválido');

    $prev = $pdo->prepare("SELECT estado, notas, fecha_creacion, asignado_a, agente_id FROM tickets WHERE id=?");
    $prev->execute([$id]);
    $p = $prev->fetch();
    if (!$p) jsonErr('Ticket no encontrado');

    // Permiso: admin todo; agente solo si es responsable o creador
    if (!$admin && $p['asignado_a'] != $uid && $p['agente_id'] != $uid) {
        jsonErr('Sin permiso para cerrar este ticket');
    }

    $notas_final = trim(($p['notas'] ?? '') . ($nota ? "\n\n[CIERRE] " . $nota : ''));
    $tres        = calcTiempoResolucion($p['fecha_creacion'] ?? date('Y-m-d'), date('Y-m-d H:i:s'));

    $pdo->prepare("UPDATE tickets SET estado='CERRADO', fecha_cierre=CURDATE(), notas=?, tiempo_resolucion=? WHERE id=?")
        ->execute([$notas_final ?: null, $tres, $id]);

    completarNextStepsDelTicket($pdo, $id, $uid);
    jsonOk();
    break;

case 'add_next_step':
    $pdo = db(); // <--- CONEXIÓN AÑADIDA
    $ticket_id  = (int)($_POST['ticket_id'] ?? 0);
    $desc       = trim($_POST['descripcion'] ?? '');
    $fecha      = !empty($_POST['fecha_programada']) ? $_POST['fecha_programada'] : null;
    if (!$ticket_id) jsonErr('ticket_id inválido');
    if ($desc === '') jsonErr('La descripción es obligatoria');

    $chk = $pdo->prepare("SELECT estado FROM tickets WHERE id=?");
    $chk->execute([$ticket_id]);
    $tk = $chk->fetch();
    if (!$tk) jsonErr('Ticket no encontrado');

    $completado       = ($tk['estado'] === 'CERRADO') ? 1 : 0;
    $fecha_completado = $completado ? date('Y-m-d H:i:s') : null;

    $stmt = $pdo->prepare("INSERT INTO ticket_next_steps (ticket_id, descripcion, fecha_programada, completado, fecha_completado, agente_id) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$ticket_id, $desc, $fecha, $completado, $fecha_completado, $uid]);
    jsonOk(['id' => (int)$pdo->lastInsertId()]);
    break;

case 'complete_next_step':
    $pdo = db(); // <--- CONEXIÓN AÑADIDA
    $id    = (int)($_POST['id'] ?? 0);
    $notas = trim($_POST['notas_completado'] ?? '');
    if (!$id) jsonErr('ID inválido');
    $pdo->prepare("UPDATE ticket_next_steps SET completado=1, fecha_completado=NOW(), notas_completado=? WHERE id=?")
        ->execute([$notas ?: null, $id]);
    jsonOk();
    break;

case 'reopen_next_step':
    $pdo = db(); // <--- CONEXIÓN AÑADIDA
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('ID inválido');
    $pdo->prepare("UPDATE ticket_next_steps SET completado=0, fecha_completado=NULL, notas_completado=NULL WHERE id=?")->execute([$id]);
    jsonOk();
    break;

case 'delete_next_step':
    $pdo = db(); // <--- CONEXIÓN AÑADIDA
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('ID inválido');
    $pdo->prepare("DELETE FROM ticket_next_steps WHERE id=?")->execute([$id]);
    jsonOk();
    break;    

case 'save_ticket':
    global $TIPO_MIEMBRO_API;
    $pdo = db(); // <--- CONEXIÓN AÑADIDA
    $miembro_id        = !empty($_POST['miembro_id']) ? (int)$_POST['miembro_id'] : null;
    $cliente           = trim($_POST['cliente'] ?? '');
    $tipo              = trim($_POST['tipo'] ?? 'OTRO');
    $prioridad         = $_POST['prioridad'] ?? 'MEDIA';
    $estado            = $_POST['estado'] ?? 'ABIERTO';
    $descripcion       = trim($_POST['descripcion'] ?? '');
    $notas             = trim($_POST['notas'] ?? '');
    $resultado         = trim($_POST['resultado'] ?? '');
    $fuente            = trim($_POST['fuente'] ?? '') ?: null;
    $nombre_referencia = trim($_POST['nombre_referencia'] ?? '') ?: null;
    $fecha_seguimiento = !empty($_POST['fecha_seguimiento']) ? $_POST['fecha_seguimiento'] : null;
    $sla_fecha         = !empty($_POST['sla_fecha']) ? $_POST['sla_fecha'] : null;
    $asignado_a        = !empty($_POST['asignado_a']) ? (int)$_POST['asignado_a'] : null;

    if ($descripcion === '') jsonErr('La descripción es obligatoria');
    if ($tipo === '')        jsonErr('El tipo es obligatorio');

    if (!in_array($tipo, $TIPO_MIEMBRO_API, true)) {
        $miembro_id = null;
    }
    if ($miembro_id) $cliente = '';

    $fecha_cierre       = ($estado === 'CERRADO') ? date('Y-m-d') : null;
    $tiempo_resolucion  = ($estado === 'CERRADO') ? '0 min' : null;

    $sql = "INSERT INTO tickets (miembro_id, agente_id, asignado_a, cliente, tipo, prioridad, estado, descripcion, notas, resultado, fuente, nombre_referencia, fecha_creacion, fecha_seguimiento, fecha_cierre, sla_fecha, tiempo_resolucion) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,CURDATE(),?,?,?,?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $miembro_id, $uid, $asignado_a, ($cliente ?: null), $tipo, $prioridad,
        $estado, $descripcion, ($notas ?: null), ($resultado ?: null), $fuente, $nombre_referencia,
        $fecha_seguimiento, $fecha_cierre, $sla_fecha, $tiempo_resolucion
    ]);
    $new_id = (int)$pdo->lastInsertId();

    if (!empty($_POST['next_steps_json'])) {
        $steps = json_decode($_POST['next_steps_json'], true);
        if (is_array($steps)) {
            $ins = $pdo->prepare("INSERT INTO ticket_next_steps (ticket_id, descripcion, fecha_programada, completado, fecha_completado, agente_id) VALUES (?,?,?,?,?,?)");
            foreach ($steps as $s) {
                $desc = trim($s['descripcion'] ?? '');
                if ($desc === '') continue;
                $fp   = !empty($s['fecha_programada']) ? $s['fecha_programada'] : null;
                $comp = !empty($s['completado']) ? 1 : 0;
                $fc   = $comp ? date('Y-m-d H:i:s') : null;
                $ins->execute([$new_id, $desc, $fp, $comp, $fc, $uid]);
            }
        }
    }

    if ($estado === 'CERRADO') completarNextStepsDelTicket($pdo, $new_id, $uid);
    jsonOk(['id' => $new_id]);
    break;

case 'get_ticket':
    $pdo = db(); // <--- CONEXIÓN AÑADIDA
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if (!$id) jsonErr('ID inválido');

    $stmt = $pdo->prepare("SELECT t.*,
                                  u.nombre   as agente_nombre,    u.iniciales   as agente_ini,    u.color   as agente_color,
                                  a.nombre   as asignado_nombre,  a.iniciales   as asignado_ini,  a.color   as asignado_color,
                                  TRIM(CONCAT(COALESCE(m.nombre,''),' ',COALESCE(m.apellido,''))) as miembro_nombre,
                                  m.telefono as miembro_telefono
                           FROM tickets t
                           LEFT JOIN usuarios u ON t.agente_id  = u.id
                           LEFT JOIN usuarios a ON t.asignado_a = a.id
                           LEFT JOIN miembros m ON t.miembro_id = m.id
                           WHERE t.id=?");
    $stmt->execute([$id]);
    $t = $stmt->fetch();
    if (!$t) jsonErr('Ticket no encontrado');

    // Lectura abierta: cualquier usuario puede VER cualquier ticket (para tener
    // contexto del miembro). La EDICIÓN sigue protegida en update_ticket.

    $ns = $pdo->prepare("SELECT ns.*, u.nombre as agente_nombre, u.iniciales as agente_ini FROM ticket_next_steps ns LEFT JOIN usuarios u ON ns.agente_id = u.id WHERE ns.ticket_id=? ORDER BY ns.completado ASC, CASE WHEN ns.fecha_programada IS NULL THEN 1 ELSE 0 END, ns.fecha_programada ASC, ns.id ASC");
    $ns->execute([$id]);
    $t['next_steps'] = $ns->fetchAll(PDO::FETCH_ASSOC);

    jsonOk($t);
    break;

case 'update_ticket':
    global $TIPO_MIEMBRO_API;
    $pdo = db(); // <--- CONEXIÓN AÑADIDA
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('ID inválido');

    $prev = $pdo->prepare("SELECT estado, fecha_creacion, asignado_a, agente_id FROM tickets WHERE id=?");
    $prev->execute([$id]);
    $prev_data = $prev->fetch();
    if (!$prev_data) jsonErr('Ticket no encontrado');

    // Permiso: admin todo; agente solo si es responsable o creador
    if (!$admin && $prev_data['asignado_a'] != $uid && $prev_data['agente_id'] != $uid) {
        jsonErr('Sin permiso para editar este ticket');
    }

    if (isset($_POST['estado']) && count($_POST) <= 4) {
        $new_estado = $_POST['estado'];
        $extras = [];
        $params = [$new_estado];

        if ($new_estado === 'CERRADO' && $prev_data['estado'] !== 'CERRADO') {
            $extras[] = "fecha_cierre=CURDATE()";
            $tres = calcTiempoResolucion($prev_data['fecha_creacion'] ?? date('Y-m-d'), date('Y-m-d H:i:s'));
            $extras[] = "tiempo_resolucion=?";
            $params[] = $tres;
        }
        $extras_sql = $extras ? ', '.implode(', ', $extras) : '';
        $params[] = $id;
        $pdo->prepare("UPDATE tickets SET estado=? $extras_sql WHERE id=?")->execute($params);

        if ($new_estado === 'CERRADO' && $prev_data['estado'] !== 'CERRADO') {
            completarNextStepsDelTicket($pdo, $id, $uid);
        }
        jsonOk();
    }

    $miembro_id        = !empty($_POST['miembro_id']) ? (int)$_POST['miembro_id'] : null;
    $cliente           = trim($_POST['cliente'] ?? '');
    $tipo              = trim($_POST['tipo'] ?? 'OTRO');
    $prioridad         = $_POST['prioridad'] ?? 'MEDIA';
    $estado            = $_POST['estado'] ?? 'ABIERTO';
    $descripcion       = trim($_POST['descripcion'] ?? '');
    $notas             = trim($_POST['notas'] ?? '');
    $resultado         = trim($_POST['resultado'] ?? '');
    $fuente            = trim($_POST['fuente'] ?? '') ?: null;
    $nombre_referencia = trim($_POST['nombre_referencia'] ?? '') ?: null;
    $fecha_seguimiento = !empty($_POST['fecha_seguimiento']) ? $_POST['fecha_seguimiento'] : null;
    $sla_fecha         = !empty($_POST['sla_fecha']) ? $_POST['sla_fecha'] : null;
    $asignado_a        = !empty($_POST['asignado_a']) ? (int)$_POST['asignado_a'] : null;

    if ($descripcion === '') jsonErr('La descripción es obligatoria');

    if (!in_array($tipo, $TIPO_MIEMBRO_API, true)) {
        $miembro_id = null;
    }
    if ($miembro_id) $cliente = '';

    $cerrando_ahora = ($estado === 'CERRADO' && $prev_data['estado'] !== 'CERRADO');
    $reabriendo     = ($estado !== 'CERRADO' && $prev_data['estado'] === 'CERRADO');

    $sql = "UPDATE tickets SET miembro_id=?, asignado_a=?, cliente=?, tipo=?, prioridad=?, estado=?, descripcion=?, notas=?, resultado=?, fuente=?, nombre_referencia=?, fecha_seguimiento=?, sla_fecha=?";
    $params = [
        $miembro_id, $asignado_a, ($cliente ?: null), $tipo, $prioridad, $estado,
        $descripcion, ($notas ?: null), ($resultado ?: null), $fuente, $nombre_referencia,
        $fecha_seguimiento, $sla_fecha
    ];
    if ($cerrando_ahora) {
        $sql .= ", fecha_cierre=CURDATE(), tiempo_resolucion=?";
        $params[] = calcTiempoResolucion($prev_data['fecha_creacion'] ?? date('Y-m-d'), date('Y-m-d H:i:s'));
    } elseif ($reabriendo) {
        $sql .= ", fecha_cierre=NULL, tiempo_resolucion=NULL";
    }
    $sql .= " WHERE id=?";
    $params[] = $id;
    $pdo->prepare($sql)->execute($params);

    if ($cerrando_ahora) completarNextStepsDelTicket($pdo, $id, $uid);

    if (!empty($_POST['next_steps_json'])) {
        $steps = json_decode($_POST['next_steps_json'], true);
        if (is_array($steps)) {
            $ins = $pdo->prepare("INSERT INTO ticket_next_steps (ticket_id, descripcion, fecha_programada, completado, fecha_completado, agente_id) VALUES (?,?,?,?,?,?)");
            foreach ($steps as $s) {
                $desc = trim($s['descripcion'] ?? '');
                if ($desc === '') continue;
                $fp   = !empty($s['fecha_programada']) ? $s['fecha_programada'] : null;
                $comp = (!empty($s['completado']) || $estado === 'CERRADO') ? 1 : 0;
                $fc   = $comp ? date('Y-m-d H:i:s') : null;
                $ins->execute([$id, $desc, $fp, $comp, $fc, $uid]);
            }
        }
    }

    jsonOk();
    break;

// ── CITAS ─────────────────────────────────────────────────────
case 'save_cita':
    $pdo = db();
    $mid = intval($_POST['miembro_id']??0) ?: null;
    $cli = trim($_POST['cliente']??'') ?: null;
    if (!$mid && !$cli) jsonErr('Debes seleccionar un miembro o escribir el nombre del cliente');
    $tipo = $_POST['tipo'] ?? 'PRESENTACIÓN';
    $modalidad = $_POST['modalidad'] ?? 'OFICINA';
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $hora  = $_POST['hora'] ?? '09:00:00';
    $notas = trim($_POST['notas']??'') ?: null;
    // Admin puede asignar a otro agente; agentes solo a sí mismos
    $agente = $admin ? intval($_POST['agente_id']??$uid) : $uid;
    if (!$agente) $agente = $uid;
    $pdo->prepare("INSERT INTO citas (miembro_id,agente_id,cliente,tipo,modalidad,fecha,hora,estado,notas)
                   VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$mid, $agente, $cli, $tipo, $modalidad, $fecha, $hora, 'PENDIENTE', $notas]);
    jsonOk(['id'=>$pdo->lastInsertId()]);
    break;

case 'update_cita':
    $pdo = db();
    $id  = intval($_POST['id'] ?? 0);
    if (!$id) jsonErr('ID inválido');
    // Verificar permisos
    $prev = $pdo->prepare("SELECT agente_id FROM citas WHERE id=?");
    $prev->execute([$id]);
    $row = $prev->fetch();
    if (!$row) jsonErr('Cita no encontrada');
    if (!$admin && $row['agente_id'] != $uid) jsonErr('Sin permiso para editar esta cita');

    $mid = intval($_POST['miembro_id']??0) ?: null;
    $cli = trim($_POST['cliente']??'') ?: null;
    if (!$mid && !$cli) jsonErr('Debes seleccionar un miembro o escribir el nombre del cliente');
    $tipo      = $_POST['tipo']      ?? 'PRESENTACIÓN';
    $modalidad = $_POST['modalidad'] ?? 'OFICINA';
    $fecha     = $_POST['fecha']     ?? date('Y-m-d');
    $hora      = $_POST['hora']      ?? '09:00:00';
    $notas     = trim($_POST['notas']??'') ?: null;
    $agente    = $admin ? (intval($_POST['agente_id']??$row['agente_id']) ?: $row['agente_id']) : $row['agente_id'];

    $pdo->prepare("UPDATE citas SET miembro_id=?, agente_id=?, cliente=?, tipo=?, modalidad=?, fecha=?, hora=?, notas=?, estado=IF(estado='CANCELADA','PENDIENTE',estado) WHERE id=?")
        ->execute([$mid, $agente, $cli, $tipo, $modalidad, $fecha, $hora, $notas, $id]);
    jsonOk();
    break;

case 'get_cita':
    $pdo = db();
    $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
    if (!$id) jsonErr('ID inválido');
    $stmt = $pdo->prepare("SELECT c.*,
                                  CONCAT(m.apellido,', ',m.nombre) as miembro_nombre,
                                  m.telefono as miembro_telefono
                           FROM citas c
                           LEFT JOIN miembros m ON c.miembro_id = m.id
                           WHERE c.id=?");
    $stmt->execute([$id]);
    $c = $stmt->fetch();
    if (!$c) jsonErr('Cita no encontrada');
    if (!$admin && $c['agente_id'] != $uid) jsonErr('Sin permiso para ver esta cita');
    jsonOk($c);
    break;

case 'complete_cita':
    $pdo = db();
    $id  = intval($_POST['id'] ?? 0);
    if (!$id) jsonErr('ID inválido');
    $prev = $pdo->prepare("SELECT agente_id FROM citas WHERE id=?");
    $prev->execute([$id]);
    $row = $prev->fetch();
    if (!$row) jsonErr('Cita no encontrada');
    if (!$admin && $row['agente_id'] != $uid) jsonErr('Sin permiso');
    $pdo->prepare("UPDATE citas SET estado='COMPLETADA', completada_por=?, completada_at=NOW() WHERE id=?")
        ->execute([$uid, $id]);
    jsonOk();
    break;

case 'cancel_cita':
    $pdo = db();
    $id  = intval($_POST['id'] ?? 0);
    if (!$id) jsonErr('ID inválido');
    $prev = $pdo->prepare("SELECT agente_id FROM citas WHERE id=?");
    $prev->execute([$id]);
    $row = $prev->fetch();
    if (!$row) jsonErr('Cita no encontrada');
    if (!$admin && $row['agente_id'] != $uid) jsonErr('Sin permiso');
    $pdo->prepare("UPDATE citas SET estado='CANCELADA' WHERE id=?")->execute([$id]);
    jsonOk();
    break;

// ── REPORTE DIARIO ────────────────────────────────────────────
case 'save_reporte':
    $pdo = db();
    $d = $_POST;
    // Asegurar columna 'interesados' (etapa del embudo de Estrategia)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM reporte_diario")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('interesados', $cols)) {
            $pdo->exec("ALTER TABLE reporte_diario ADD COLUMN interesados INT DEFAULT 0 AFTER contestaron");
        }
    } catch(Exception $e) {}
    $pdo->prepare("INSERT INTO reporte_diario
        (agente_id, fecha, llamadas_prospectos, contestaron, interesados, buzon,
         llamadas_servicio, citas_confirmadas, tickets_resueltos,
         tickets_actualizados, apps_enviadas, apps_por_hacer, nota, enviado)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE
            llamadas_prospectos  = VALUES(llamadas_prospectos),
            contestaron          = VALUES(contestaron),
            interesados          = VALUES(interesados),
            buzon                = VALUES(buzon),
            llamadas_servicio    = VALUES(llamadas_servicio),
            citas_confirmadas    = VALUES(citas_confirmadas),
            tickets_resueltos    = VALUES(tickets_resueltos),
            tickets_actualizados = VALUES(tickets_actualizados),
            apps_enviadas        = VALUES(apps_enviadas),
            apps_por_hacer       = VALUES(apps_por_hacer),
            nota                 = VALUES(nota),
            enviado              = 1")
        ->execute([
            $uid,
            date('Y-m-d'),
            (int)($d['llamadas_prospectos']  ?? 0),
            (int)($d['contestaron']          ?? 0),
            (int)($d['interesados']          ?? 0),
            (int)($d['buzon']                ?? 0),
            (int)($d['llamadas_servicio']    ?? 0),
            (int)($d['citas_confirmadas']    ?? 0),
            (int)($d['tickets_resueltos']    ?? 0),
            (int)($d['tickets_actualizados'] ?? 0),
            (int)($d['apps_enviadas']        ?? 0),
            (int)($d['apps_por_hacer']      ?? 0),
            trim($d['nota'] ?? ''),
        ]);
    jsonOk();
    break;

// ── EDITAR REPORTE COMO ADMIN ─────────────────────────────────
// El admin edita directamente el reporte de un agente (sin reabrir) y queda
// registrado en el HISTORIAL que el admin hizo el cambio.
case 'admin_edit_reporte':
    if (!$admin) jsonErr('Solo admin puede editar reportes de otros');
    $aid   = intval($_POST['agente_id'] ?? 0);
    $fecha = $_POST['fecha'] ?? '';
    if (!$aid || !$fecha) jsonErr('Agente y fecha requeridos');
    $pdo = db();
    // Asegurar columnas de auditoría
    try {
        if (!$pdo->query("SHOW COLUMNS FROM reporte_diario LIKE 'editado_por'")->fetch())
            $pdo->exec("ALTER TABLE reporte_diario ADD COLUMN editado_por INT NULL");
        if (!$pdo->query("SHOW COLUMNS FROM reporte_diario LIKE 'editado_at'")->fetch())
            $pdo->exec("ALTER TABLE reporte_diario ADD COLUMN editado_at TIMESTAMP NULL");
    } catch (Exception $e) {}
    $d = $_POST;
    $pdo->prepare("INSERT INTO reporte_diario
        (agente_id, fecha, llamadas_prospectos, contestaron, interesados, buzon,
         llamadas_servicio, citas_confirmadas, tickets_resueltos,
         tickets_actualizados, apps_enviadas, apps_por_hacer, nota, enviado, editado_por, editado_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,NOW())
        ON DUPLICATE KEY UPDATE
            llamadas_prospectos=VALUES(llamadas_prospectos), contestaron=VALUES(contestaron),
            interesados=VALUES(interesados), buzon=VALUES(buzon),
            llamadas_servicio=VALUES(llamadas_servicio), citas_confirmadas=VALUES(citas_confirmadas),
            tickets_resueltos=VALUES(tickets_resueltos), tickets_actualizados=VALUES(tickets_actualizados),
            apps_enviadas=VALUES(apps_enviadas), apps_por_hacer=VALUES(apps_por_hacer),
            nota=VALUES(nota), enviado=1, editado_por=VALUES(editado_por), editado_at=NOW()")
        ->execute([
            $aid, $fecha,
            (int)($d['llamadas_prospectos']  ?? 0),
            (int)($d['contestaron']          ?? 0),
            (int)($d['interesados']          ?? 0),
            (int)($d['buzon']                ?? 0),
            (int)($d['llamadas_servicio']    ?? 0),
            (int)($d['citas_confirmadas']    ?? 0),
            (int)($d['tickets_resueltos']    ?? 0),
            (int)($d['tickets_actualizados'] ?? 0),
            (int)($d['apps_enviadas']        ?? 0),
            (int)($d['apps_por_hacer']       ?? 0),
            trim($d['nota'] ?? ''),
            $uid,
        ]);
    // Registrar en el HISTORIAL (actividad) que fue el admin
    try {
        $ag = $pdo->prepare("SELECT nombre FROM usuarios WHERE id=?"); $ag->execute([$aid]);
        $agn = $ag->fetchColumn() ?: ('agente #'.$aid);
        $pdo->prepare("INSERT INTO actividad (agente_id,tipo,descripcion) VALUES (?,?,?)")
            ->execute([$uid, 'REPORTE', $user['nombre'].' editó el reporte de '.$agn.' del '.$fecha]);
    } catch (Exception $e) {}
    jsonOk();
    break;

// ── REABRIR REPORTE (admin) — habilita de nuevo el reporte de un agente ──
// para reconteo (p.ej. ticket cerrado tras enviar) o para agregar notas.
case 'reabrir_reporte':
    if (!$admin) jsonErr('Solo admin puede reabrir reportes');
    $aid   = intval($_POST['agente_id'] ?? 0);
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    if (!$aid) jsonErr('Agente requerido');
    $pdo = db();
    $st = $pdo->prepare("UPDATE reporte_diario SET enviado=0 WHERE agente_id=? AND fecha=?");
    $st->execute([$aid, $fecha]);
    if ($st->rowCount() === 0) jsonErr('No hay reporte enviado de ese agente para reabrir');
    jsonOk();
    break;

// ── LLAMADAS ──────────────────────────────────────────────────
case 'save_llamada':
    $pdo = db();
    $pdo->prepare("INSERT INTO llamadas_perdidas (numero,nombre_posible,fecha,hora,origen,agente_id) VALUES (?,?,?,?,?,?)")
        ->execute([$_POST['numero']??'','DESCONOCIDO',date('Y-m-d'),date('H:i:s'),$_POST['origen']??'TWILIO',$uid]);
    jsonOk();
    break;

case 'devolver_llamada':
    $id = intval($_POST['id']??0);
    db()->prepare("UPDATE llamadas_perdidas SET estado='DEVUELTA' WHERE id=?")->execute([$id]);
    jsonOk();
    break;

// ── ACTIVIDAD ─────────────────────────────────────────────────
case 'log_activity':
    $pdo = db();
    $pdo->prepare("INSERT INTO actividad (agente_id,miembro_id,tipo,descripcion) VALUES (?,?,?,?)")
        ->execute([$uid,$_POST['miembro_id']??null,$_POST['tipo']??'NOTA',$_POST['desc']??'']);
    jsonOk();
    break;

// ── ALERTAS ───────────────────────────────────────────────────
case 'save_alerta':
    if (!$admin) jsonErr('Solo admin');
    $id = intval($_POST['miembro_id']??0);
    db()->prepare("UPDATE miembros SET alerta_activa=1,alerta_texto=? WHERE id=?")->execute([$_POST['texto']??'',$id]);
    jsonOk();
    break;

// ── LLAMADAS RETENCIÓN ────────────────────────────────────────
case 'update_llam':
    $id = intval($_POST['miembro_id']??0);
    $campo = $_POST['campo']??'';
    $valid = ['llam_bienvenida','llam_30','llam_60','llam_90'];
    if (!in_array($campo,$valid)) jsonErr('Campo inválido');
    $val = $_POST['valor']??'';
    db()->prepare("UPDATE miembros SET $campo=? WHERE id=?")->execute([$val,$id]);
    db()->prepare("INSERT INTO actividad (agente_id,miembro_id,tipo,descripcion) VALUES (?,?,?,?)")
        ->execute([$uid,$id,'LLAMADA','INTENTO DE RETENCIÓN ('.strtoupper($campo).'): '.$val]);
    jsonOk();
    break;

// ── EFECTIVOS ─────────────────────────────────────────────────
// ── EFECTIVOS ─────────────────────────────────────────────────
case 'toggle_efectivo':
    $mid = intval($_POST['miembro_id']??0);
    $tipo = $_POST['tipo']??'';
    $valid_tipos = ['app_enviada','app_aprobada','hra','doctor_verificado','id_drive','sms_enviado','llam_bienvenida'];
    if (!$mid || !in_array($tipo,$valid_tipos)) jsonErr('Datos inválidos');
    
    $pdo = db();
    
    // --- PARCHE AUTOMÁTICO DE ESTRUCTURA ---
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS efectivos_checks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            miembro_id INT NOT NULL,
            tipo VARCHAR(50) NOT NULL,
            done TINYINT(1) DEFAULT 1,
            done_by INT,
            done_at DATETIME,
            UNIQUE KEY unique_check (miembro_id, tipo)
        )");
        $cols = $pdo->query("SHOW COLUMNS FROM efectivos_checks")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('done', $cols)) $pdo->exec("ALTER TABLE efectivos_checks ADD COLUMN done TINYINT(1) DEFAULT 1");
        if (!in_array('done_by', $cols)) $pdo->exec("ALTER TABLE efectivos_checks ADD COLUMN done_by INT");
        if (!in_array('done_at', $cols)) $pdo->exec("ALTER TABLE efectivos_checks ADD COLUMN done_at DATETIME");
    } catch(Exception $e) {}
    // ----------------------------------------

    $existing = $pdo->prepare("SELECT id,done FROM efectivos_checks WHERE miembro_id=? AND tipo=?");
    $existing->execute([$mid,$tipo]);
    $row = $existing->fetch();
    
    if ($row) {
        $newDone = $row['done'] ? 0 : 1;
        $pdo->prepare("UPDATE efectivos_checks SET done=?, done_by=?, done_at=NOW() WHERE id=?")
            ->execute([$newDone, $uid, $row['id']]);
    } else {
        $pdo->prepare("INSERT INTO efectivos_checks (miembro_id, tipo, done, done_by, done_at) VALUES (?, ?, 1, ?, NOW())")
            ->execute([$mid, $tipo, $uid]);
        $newDone = 1;
    }
    
    $pdo->prepare("INSERT INTO actividad (agente_id,miembro_id,tipo,descripcion) VALUES (?,?,?,?)")
        ->execute([$uid,$mid,'EFECTIVO',strtoupper($tipo).($newDone?' ✓':' ✗')]);

    // ── Sincronizar BIENVENIDA con retencion_llamadas ─────────
    if ($tipo === 'llam_bienvenida') {
        try {
            if ($newDone) {
                // Marcar como completada en retención
                $pdo->prepare("INSERT INTO retencion_llamadas (miembro_id, tipo, resultado, completada_por)
                    VALUES (?, 'BIENVENIDA', 'COMPLETADA', ?)
                    ON DUPLICATE KEY UPDATE
                        resultado='COMPLETADA', completada_por=VALUES(completada_por), completada_at=CURRENT_TIMESTAMP")
                    ->execute([$mid, $uid]);
            } else {
                // Si se desmarca en el dashboard, quitar de retención también
                $pdo->prepare("DELETE FROM retencion_llamadas WHERE miembro_id=? AND tipo='BIENVENIDA'")
                    ->execute([$mid]);
            }
        } catch (Exception $e) {}
    }

    jsonOk(['done'=>$newDone]);
    break;

// ── DIRECT MESSAGES ──────────────────────────────────────────
case 'send_dm':
    $pdo = db();
    $to = intval($_POST['to_user']??0);
    $msg = trim($_POST['mensaje']??'');
    if (!$to || !$msg) jsonErr('Datos inválidos');
    if (strlen($msg)>500) jsonErr('Mensaje muy largo');
    $chk = $pdo->prepare("SELECT id,nombre FROM usuarios WHERE id=? AND activo=1");
    $chk->execute([$to]); $recipient=$chk->fetch();
    if (!$recipient) jsonErr('Destinatario no encontrado');
    try {
        $pdo->prepare("INSERT INTO chat_mensajes (user_id,recipient_id,mensaje,es_dm) VALUES (?,?,?,1)")
            ->execute([$uid,$to,$msg]);
    } catch(Exception $e) {
        $pdo->prepare("INSERT INTO chat_mensajes (user_id,mensaje) VALUES (?,?)")
            ->execute([$uid,'[DM→'.$recipient['nombre'].'] '.$msg]);
    }
    $newId = $pdo->lastInsertId();
    try {
        $pdo->prepare("INSERT INTO notificaciones (user_id,remitente_id,tipo,mensaje) VALUES (?,?,?,?)")
            ->execute([$to,$uid,'CHAT','Mensaje directo de '.$user['nombre'].': '.substr($msg,0,80)]);
    } catch(Exception $e){}
    jsonOk(['id'=>$newId]);
    break;

case 'get_dms':
    $with = intval($_GET['with']??$_POST['with']??0);
    $since = intval($_GET['since']??0);
    if (!$with) jsonErr('Destinatario requerido');
    $pdo = db();
    try {
        $q = $pdo->prepare("SELECT c.id, c.user_id as sender_id, c.mensaje, c.created_at,
            u.nombre as sender_nombre
            FROM chat_mensajes c LEFT JOIN usuarios u ON c.user_id=u.id
            WHERE c.es_dm=1 AND c.id>?
            AND ((c.user_id=? AND c.recipient_id=?) OR (c.user_id=? AND c.recipient_id=?))
            ORDER BY c.id ASC LIMIT 60");
        $q->execute([$since,$uid,$with,$with,$uid]);
        $msgs = $q->fetchAll();
    } catch(Exception $e) { $msgs = []; }
    jsonOk(['messages'=>$msgs]);
    break;

// ── TEAM CHAT ─────────────────────────────────────────────────
case 'send_chat':
    $pdo = db();
    $msg = trim($_POST['mensaje']??'');
    if (!$msg) jsonErr('Mensaje vacío');
    if (strlen($msg)>500) jsonErr('Mensaje muy largo');
    $pdo->prepare("INSERT INTO chat_mensajes (user_id,mensaje) VALUES (?,?)")->execute([$uid, $msg]);
    $newId = $pdo->lastInsertId();
    try { $pdo->prepare("UPDATE usuarios SET last_seen_chat=NOW() WHERE id=?")->execute([$uid]); } catch(Exception $e){}
    jsonOk(['id'=>$newId]);
    break;

case 'get_chat':
    $since = $_GET['since'] ?? $_POST['since'] ?? 0;
    $pdo = db();
    try {
        $q = $pdo->prepare("SELECT c.*,u.nombre,u.color,u.iniciales FROM chat_mensajes c LEFT JOIN usuarios u ON c.user_id=u.id WHERE c.id>? ORDER BY c.id ASC LIMIT 50");
        $q->execute([$since]);
        $msgs = $q->fetchAll();
        try { $pdo->prepare("UPDATE usuarios SET last_seen_chat=NOW() WHERE id=?")->execute([$uid]); } catch(Exception $e){}
        jsonOk(['messages'=>$msgs]);
    } catch(Exception $e) { jsonOk(['messages'=>[]]); }
    break;

case 'mark_chat_seen':
    try { db()->prepare("UPDATE usuarios SET last_seen_chat=NOW() WHERE id=?")->execute([$uid]); } catch(Exception $e){}
    jsonOk();
    break;

// ── NOTIFICATIONS ─────────────────────────────────────────────
case 'get_notifs':
    try {
        $pdo = db();
        $q = $pdo->prepare("SELECT n.*,u.nombre as remitente_nombre FROM notificaciones n LEFT JOIN usuarios u ON n.remitente_id=u.id WHERE n.user_id=? ORDER BY n.created_at DESC LIMIT 20");
        $q->execute([$uid]);
        $notifs = $q->fetchAll();
        $unread = count(array_filter($notifs, fn($n)=>!$n['leido']));
        jsonOk(['notifs'=>$notifs,'unread'=>$unread]);
    } catch(Exception $e) { jsonOk(['notifs'=>[],'unread'=>0]); }
    break;

case 'mark_notif_read':
    $id = intval($_POST['id']??0);
    try {
        if ($id) {
            db()->prepare("UPDATE notificaciones SET leido=1 WHERE id=? AND user_id=?")->execute([$id,$uid]);
        } else {
            db()->prepare("UPDATE notificaciones SET leido=1 WHERE user_id=?")->execute([$uid]);
        }
    } catch(Exception $e){}
    jsonOk();
    break;

case 'send_notif':
    if (!$admin) jsonErr('Solo admin');
    $target = intval($_POST['user_id']??0);
    $msg = trim($_POST['mensaje']??'');
    if (!$target || !$msg) jsonErr('Datos inválidos');
    $pdo = db();
    $chk = $pdo->prepare("SELECT id FROM usuarios WHERE id=? AND activo=1");
    $chk->execute([$target]);
    if (!$chk->fetch()) jsonErr('Destinatario no encontrado — verifica que el usuario esté activo');
    $pdo->prepare("INSERT INTO notificaciones (user_id,remitente_id,tipo,mensaje) VALUES (?,?,?,?)")
        ->execute([$target,$uid,in_array($_POST['tipo']??'',['OBSERVACION','TICKET','ALERTA','RETENCION'])?$_POST['tipo']:'ADMIN',$msg]);
    jsonOk(['enviada'=>true,'destinatario'=>$target]);
    break;

// ── FINANCE ───────────────────────────────────────────────────
case 'finance_auth':
    if (!$admin) jsonErr('Solo admin');
    if (($_POST['pass']??'') === FINANCE_PASS) {
        $_SESSION['finance_ok'] = true;
        jsonOk();
    }
    jsonErr('Contraseña incorrecta');
    break;

case 'save_comision':
    if (!$admin || empty($_SESSION['finance_ok'])) jsonErr('Sin autorización');
    $d = $_POST;
    $pdo = db();
    if (!empty($d['id'])) {
        $pdo->prepare("UPDATE comisiones SET carrier=?,mes=?,anio=?,monto=?,estado=?,notas=? WHERE id=?")
            ->execute([$d['carrier']??'',$d['mes']??'',intval($d['anio']??2025),floatval($d['monto']??0),$d['estado']??'PENDIENTE',$d['notas']??'',$d['id']]);
    } else {
        $pdo->prepare("INSERT INTO comisiones (miembro_id,agente_id,carrier,mes,anio,monto,estado,notas) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$d['miembro_id']??null,$d['agente_id']??null,$d['carrier']??'',$d['mes']??'',intval($d['anio']??2025),floatval($d['monto']??0),$d['estado']??'PENDIENTE',$d['notas']??'']);
    }
    jsonOk();
    break;

// ── PAGO DE BONOS ─────────────────────────────────────────────
case 'get_pago_bonos':
    $pdo = db();
    $mes_f = $_GET['mes'] ?? $_POST['mes'] ?? '';
    $sql = "SELECT b.*, u.nombre as agente_nombre, u.iniciales, u.color
            FROM pago_bonos b LEFT JOIN usuarios u ON b.agente_id = u.id WHERE 1=1";
    $params = [];
    if (!$admin) { $sql .= " AND b.agente_id = ?"; $params[] = $uid; }
    if ($mes_f && $mes_f !== 'all') { $sql .= " AND b.mes = ?"; $params[] = $mes_f; }
    $sql .= " ORDER BY b.fecha DESC, b.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $total_pagado  = array_sum(array_column(array_filter($rows, fn($r)=>$r['pagado']), 'total'));
    $total_pending = array_sum(array_column(array_filter($rows, fn($r)=>!$r['pagado']), 'total'));
    jsonOk(['registros'=>$rows,'total_pagado'=>$total_pagado,'total_pendiente'=>$total_pending]);
    break;

case 'toggle_bono_pagado':
    if (!$admin) jsonErr('Solo admin puede marcar pagos');
    $id  = intval($_POST['id'] ?? 0);
    $val = intval($_POST['pagado'] ?? 0);
    if (!$id) jsonErr('ID inválido');
    $pdo = db();
    $pdo->prepare("UPDATE pago_bonos SET pagado=? WHERE id=?")->execute([$val, $id]);
    $pdo->prepare("INSERT INTO actividad (agente_id,tipo,descripcion) VALUES (?,?,?)")
        ->execute([$uid,'BONOS','BONO #'.$id.' marcado '.($val?'PAGADO':'PENDIENTE')]);
    jsonOk();
    break;

case 'save_pago_bono':
    if (!$admin) jsonErr('Solo admin puede agregar registros');
    $d   = $_POST;
    $pdo = db();
    $fields = ['agente_id','tipo','cliente','fecha','mes','cantidad','precio_unidad','total','pagado','cobro_regreso','venta_cancelada','notas'];
    if (!empty($d['id'])) {
        $sets = implode(',', array_map(fn($f)=>"$f=?", $fields));
        $vals = array_map(fn($f)=>$d[$f]??null, $fields);
        $vals[] = $d['id'];
        $pdo->prepare("UPDATE pago_bonos SET $sets WHERE id=?")->execute($vals);
        jsonOk(['id'=>$d['id']]);
    } else {
        $cols = implode(',', $fields);
        $ph   = implode(',', array_fill(0, count($fields), '?'));
        $vals = array_map(fn($f)=>$d[$f]??null, $fields);
        $pdo->prepare("INSERT INTO pago_bonos ($cols) VALUES ($ph)")->execute($vals);
        jsonOk(['id'=>$pdo->lastInsertId()]);
    }
    break;

case 'delete_pago_bono':
    if (!$admin) jsonErr('Solo admin');
    $id = intval($_POST['id']??0);
    if (!$id) jsonErr('ID inválido');
    db()->prepare("DELETE FROM pago_bonos WHERE id=?")->execute([$id]);
    jsonOk();
    break;

// ── ES VENTA → MANDAR A BONOS ─────────────────────────────────
// El agente (o admin) confirma que la venta ya está ACTIVE y la manda
// a bonos. El agente solo puede mandar sus propios miembros.
// Se registra como PENDIENTE; el admin la paga. Idempotente por miembro.
case 'verificar_venta_bono':
    $mid = intval($_POST['miembro_id'] ?? 0);
    if (!$mid) jsonErr('ID de miembro requerido');
    $pdo = db();
    // Asegurar columna miembro_id
    try {
        $hasCol = $pdo->query("SHOW COLUMNS FROM pago_bonos LIKE 'miembro_id'")->fetch();
        if (!$hasCol) $pdo->exec("ALTER TABLE pago_bonos ADD COLUMN miembro_id INT NULL");
    } catch (Exception $e) {}
    $m = $pdo->prepare("SELECT id,nombre,apellido,estado,agente_id FROM miembros WHERE id=?");
    $m->execute([$mid]);
    $mem = $m->fetch();
    if (!$mem) jsonErr('Miembro no encontrado');
    if (!$admin && (int)$mem['agente_id'] !== (int)$uid) jsonErr('Solo puedes mandar tus propias ventas');
    if ($mem['estado'] !== 'ACTIVE') jsonErr('La venta debe estar ACTIVE para mandarla a bonos');
    if (empty($mem['agente_id'])) jsonErr('El miembro no tiene agente asignado');
    // Evitar duplicados
    $chk = $pdo->prepare("SELECT id FROM pago_bonos WHERE miembro_id=?");
    $chk->execute([$mid]);
    if ($chk->fetch()) jsonErr('Esta venta ya tiene un bono registrado');
    $meses_es = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $mes_es = $meses_es[(int)date('n')];
    $cliente = trim(($mem['apellido']??'').', '.($mem['nombre']??''));
    $monto = BONO_MONTO;
    $pdo->prepare("INSERT INTO pago_bonos (agente_id,tipo,cliente,fecha,mes,cantidad,precio_unidad,total,pagado,cobro_regreso,venta_cancelada,notas,miembro_id)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$mem['agente_id'],'Bono por venta',$cliente,date('Y-m-d'),$mes_es,1,$monto,$monto,0,0,0,'Venta verificada con el cliente',$mid]);
    $bid = $pdo->lastInsertId();
    try {
        $pdo->prepare("INSERT INTO actividad (agente_id,tipo,descripcion,miembro_id) VALUES (?,?,?,?)")
            ->execute([$uid,'BONOS','VENTA VERIFICADA → BONO #'.$bid.' ('.$cliente.')',$mid]);
    } catch (Exception $e) {}
    jsonOk(['id'=>$bid]);
    break;

// ── BONOS INCENTIVOS ──────────────────────────────────────────
case 'get_bonos_report':
case 'get_bonos_incentivos':
    if (!$admin) jsonErr('Solo admin');
    $bono = BONO_MONTO;
    $dias_ret = DIAS_RETENCION;
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT CONCAT(m.apellido,', ',m.nombre) as miembro, u.nombre as empleado,
               m.fecha_efectiva as efectiva, m.estado,
               DATEDIFF(CURDATE(),m.fecha_efectiva) as dias,
               CASE
                 WHEN m.estado IN ('CANCELED','DENIED','CERRADO','DISENROLLED') AND DATEDIFF(CURDATE(),m.fecha_efectiva) < :dias1 THEN 'CHARGEBACK'
                 WHEN m.estado='ACTIVE' AND DATEDIFF(CURDATE(),m.fecha_efectiva) >= :dias2 THEN 'CONSOLIDADO'
                 ELSE 'PENDIENTE'
               END as status,
               CASE
                 WHEN m.estado IN ('CANCELED','DENIED','CERRADO','DISENROLLED') AND DATEDIFF(CURDATE(),m.fecha_efectiva) < :dias3 THEN :neg
                 WHEN m.estado='ACTIVE' AND DATEDIFF(CURDATE(),m.fecha_efectiva) >= :dias4 THEN 0
                 ELSE :pos
               END as monto
        FROM miembros m LEFT JOIN usuarios u ON m.agente_id=u.id
        WHERE m.fecha_efectiva IS NOT NULL AND m.estado IN ('ACTIVE','CANCELED','DENIED','CERRADO','DISENROLLED')
        ORDER BY m.fecha_efectiva DESC");
    $stmt->execute([':dias1'=>$dias_ret,':dias2'=>$dias_ret,':dias3'=>$dias_ret,':dias4'=>$dias_ret,':neg'=>-$bono,':pos'=>$bono]);
    jsonOk(['reporte'=>$stmt->fetchAll()]);
    break;

// ── IMPORTAR CSV ──────────────────────────────────────────────
case 'import_csv':
    if (!$admin) jsonErr('Solo admin');
    $agente_id = intval($_POST['agente_id']??0) ?: null;
    if (empty($_FILES['file']['tmp_name'])) jsonErr('Sin archivo');
    $pdo = db();
    $handle = fopen($_FILES['file']['tmp_name'],'r');
    fgetcsv($handle);
    $count=0; $errors=0;
    while (($data=fgetcsv($handle))!==false) {
        if (count($data)<2){$errors++;continue;}
        $nombre = trim($data[0]??'');
        $apellido = trim($data[1]??'');
        $telefono = trim($data[2]??'');
        $dob = trim($data[3]??'') ?: null;
        $mbi = trim($data[4]??'') ?: null;
        $carrier = trim($data[5]??'') ?: null;
        $plan = trim($data[6]??'') ?: null;
        $estado = trim($data[7]??'') ?: 'PROSPECTO';
        $estados_validos = ['ACTIVO','HOT LEAD','T65','PROSPECTO','FOLLOW-UP','PENDIENTE','CANCELADO'];
        if (!in_array(strtoupper($estado), $estados_validos)) $estado = 'PROSPECTO';
        $estado = strtoupper($estado);
        if (!$nombre) {$errors++;continue;}
        try {
            if ($telefono) {
                $chk=$pdo->prepare("SELECT id FROM miembros WHERE telefono=?");
                $chk->execute([$telefono]);
                if ($chk->fetch()){$errors++;continue;}
            }
            $pdo->prepare("INSERT INTO miembros (nombre,apellido,telefono,dob,mbi,carrier,plan,estado,agente_id) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$nombre,$apellido,$telefono,$dob,$mbi,$carrier,$plan,$estado,$agente_id]);
            $count++;
        } catch(Exception $e){$errors++;}
    }
    fclose($handle);
    jsonOk(['importados'=>$count,'duplicados'=>$errors]);
    break;

// ── PORTAL REPORT ─────────────────────────────────────────────
case 'get_portal_report':
    $pdo = db();
    $members = $pdo->query("
        SELECT id,nombre,apellido,carrier,plan,fecha_efectiva,estado,
               app_estado_cms,app_carrier_estado,
               DATEDIFF(CURDATE(),fecha_efectiva) as dias_activo
        FROM miembros
        WHERE estado IN ('ACTIVO','CANCELADO','PENDIENTE')
           OR (carrier IS NOT NULL AND carrier != '')
        ORDER BY FIELD(estado,'ACTIVO','PENDIENTE','CANCELADO'), fecha_efectiva DESC")->fetchAll();
    jsonOk(['members'=>$members,'total'=>count($members)]);
    break;

// ── NOTAS DE MIEMBRO ──────────────────────────────────────────
case 'save_nota':
    $mid  = intval($_POST['miembro_id'] ?? 0);
    $nota = trim($_POST['nota'] ?? '');
    if (!$mid)  jsonErr('ID de miembro requerido');
    if (!$nota) jsonErr('La nota no puede estar vacía');
    $pdo = db();
    $pdo->prepare("INSERT INTO notas_miembro (miembro_id, agente_id, nota) VALUES (?,?,?)")
        ->execute([$mid, $uid, $nota]);
    $new_id = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO actividad (agente_id, miembro_id, tipo, descripcion) VALUES (?,?,?,?)")
        ->execute([$uid, $mid, 'NOTA', mb_substr($nota, 0, 120)]);
    $autor = $pdo->prepare("SELECT nombre, iniciales, color FROM usuarios WHERE id=?");
    $autor->execute([$uid]);
    $u = $autor->fetch();
    jsonOk([
        'id'        => $new_id,
        'texto'     => $nota,
        'autor'     => $u['nombre']   ?? $user['nombre'],
        'iniciales' => $u['iniciales'] ?? strtoupper(substr($user['nombre'],0,2)),
        'color'     => $u['color']    ?? '#2876A8',
        'fecha'     => date('m/d/Y g:i a'),
    ]);
    break;

case 'delete_nota':
    $id = intval($_POST['id'] ?? 0);
    if (!$id) jsonErr('ID requerido');
    $pdo = db();
    $check = $pdo->prepare("SELECT agente_id FROM notas_miembro WHERE id=?");
    $check->execute([$id]);
    $row = $check->fetch();
    if (!$row) jsonErr('Nota no encontrada');
    if (!$admin && $row['agente_id'] != $uid) jsonErr('Sin permiso para eliminar esta nota');
    $pdo->prepare("DELETE FROM notas_miembro WHERE id=?")->execute([$id]);
    jsonOk();
    break;

case 'completar_paso_pipeline':
    $id = intval($_POST['id'] ?? 0);
    if (!$id) jsonErr('ID de paso requerido');
    
    $pdo = db();
    // Marcamos el paso actual como completado
    $stmt = $pdo->prepare("UPDATE pipeline_pasos SET completado = 1, fecha_completado = NOW() WHERE id = ?");
    $stmt->execute([$id]);

    // Opcional: Registrar en el historial de actividad
    $info = $pdo->prepare("SELECT miembro_id, descripcion FROM pipeline_pasos WHERE id = ?");
    $info->execute([$id]);
    $paso = $info->fetch();
    
    if ($paso) {
        $pdo->prepare("INSERT INTO actividad (agente_id, miembro_id, tipo, descripcion) VALUES (?, ?, 'SISTEMA', ?)")
            ->execute([$uid, $paso['miembro_id'], "PASO COMPLETADO: " . $paso['descripcion']]);
    }

    jsonOk(['msg' => 'Paso completado']);
    break;   
    
case 'aplicar_pasos_automaticos':
        $mid = (int)$_POST['miembro_id'];
        // $uid already set from session at top

        try {
            // Traemos la configuración de pasos definida en la tabla de ajustes
            $stmt = $pdo->query("SELECT * FROM pipeline_config_pasos ORDER BY dias_intervalo ASC");
            $config = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($config) > 0) {
                $ins = $pdo->prepare("INSERT INTO pipeline_pasos (miembro_id, descripcion, fecha_programada, agente_id, completado) VALUES (?, ?, ?, ?, 0)");
                
                foreach($config as $c) {
                    // Calculamos la fecha: hoy + X días de intervalo
                    $fecha_programada = date('Y-m-d', strtotime("+".$c['dias_intervalo']." days"));
                    $ins->execute([$mid, $c['accion'], $fecha_programada, $uid]);
                }
                echo json_encode(['ok' => true, 'msg' => 'Secuencia aplicada']);
            } else {
                echo json_encode(['ok' => false, 'msg' => 'No hay pasos configurados']);
            }
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'add_pipeline_config_row':
        try {
            $pdo = db();
            $pdo->exec("INSERT INTO pipeline_config_pasos (dias_intervalo, accion) VALUES (1, 'Nueva Tarea de Seguimiento')");
            $newConfigId = $pdo->lastInsertId();
            echo json_encode(['ok' => true, 'id' => $newConfigId]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'update_pipeline_config':
        $id = (int)$_POST['id'];
        $campo = $_POST['campo']; 
        $valor = $_POST['valor'];
        $pdo = db();
        if (in_array($campo, ['dias_intervalo', 'accion'])) {
            $upd = $pdo->prepare("UPDATE pipeline_config_pasos SET $campo = ? WHERE id = ?");
            $upd->execute([$valor, $id]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Campo no permitido']);
        }
        break;

    case 'delete_pipeline_config':
        $id = (int)$_POST['id'];
        $pdo = db();
        try {
            $del = $pdo->prepare("DELETE FROM pipeline_config_pasos WHERE id = ?");
            $del->execute([$id]);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;    


case 'set_prospect_temp':
    $id   = intval($_POST['id'] ?? 0);
    $temp = trim($_POST['temp'] ?? '');
    $allowed = ['hot','warm','cold','aep','t65',''];
    if (!$id) jsonErr('ID requerido');
    if (!in_array(strtolower($temp), $allowed)) jsonErr('Temperatura no válida');
    $pdo = db();
    $pdo->prepare("UPDATE miembros SET fuente=? WHERE id=?")->execute([$temp ?: null, $id]);
    $pdo->prepare("INSERT INTO actividad (agente_id,miembro_id,tipo,descripcion) VALUES (?,?,?,?)")
        ->execute([$uid, $id, 'SISTEMA', 'Temperatura prospecto: ' . ($temp ?: 'sin clasificar')]);
    jsonOk(['msg'=>'Temperatura actualizada']);
    break;
 
case 'save_llamada_prospecto':
    $pdo = db();
    // Asegurar que la tabla existe
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS llamadas_prospectos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agente_id INT, miembro_id INT NULL,
        nombre_libre VARCHAR(255), telefono VARCHAR(50),
        contesto TINYINT(1) DEFAULT 0,
        resultado VARCHAR(100) DEFAULT NULL,
        notas TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"); } catch(Exception $e) {}
    $agente_id = $uid;
    $miembro_id = !empty($_POST['miembro_id']) ? (int)$_POST['miembro_id'] : null;
    $nombre = $_POST['nombre_libre'] ?? '';
    $telefono = $_POST['telefono'] ?? '';
    $resultado = $_POST['resultado'] ?? ''; // Tomamos la opción del grid
    $notas = $_POST['notas'] ?? '';

    // Si marcó no contestó o dejó buzón, es 0. Si no, es 1.
    $contesto = in_array($resultado, ['No contestó', 'Dejó buzón']) ? 0 : 1;

    $stmt = $pdo->prepare("INSERT INTO llamadas_prospectos (agente_id, miembro_id, nombre_libre, telefono, contesto, resultado, notas) VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    if($stmt->execute([$agente_id, $miembro_id, $nombre, $telefono, $contesto, $resultado, $notas])) {
        jsonOk();
    } else {
        jsonErr('No se pudo guardar la llamada');
    }
    break;
    
case 'toggle_checklist':
    $pdo = db(); 
    $item_key = trim($_POST['item_key'] ?? '');
    $fecha    = date('Y-m-d'); // o tu función today()
    
    if (!$item_key) { 
        echo json_encode(['ok'=>false,'error'=>'Sin item_key']); 
        exit; 
    }
    
    $stmt = $pdo->prepare(
        "SELECT id, completado FROM checklist_diario 
         WHERE agente_id=? AND fecha=? AND item_key=? LIMIT 1"
    );
    $stmt->execute([$uid, $fecha, $item_key]);
    $row = $stmt->fetch();
    
    if (!$row) { 
        echo json_encode(['ok'=>false,'error'=>'Tarea no encontrada: '.$item_key]); 
        exit; 
    }
    
    $nuevo = $row['completado'] ? 0 : 1;
    if ($nuevo) {
        $pdo->prepare("UPDATE checklist_diario SET completado=1, completado_at=NOW() WHERE id=?")
            ->execute([$row['id']]);
    } else {
        $pdo->prepare("UPDATE checklist_diario SET completado=0, completado_at=NULL WHERE id=?")
            ->execute([$row['id']]);
    }
    echo json_encode(['ok'=>true, 'completado'=>$nuevo]);
    exit; 
    
case 'get_reportes_historicos':
    $pdo   = db();
    if (!$admin) jsonErr('Sin acceso');
    $from  = $_POST['from']  ?? date('Y-m-01');
    $to    = $_POST['to']    ?? date('Y-m-d');
    $ag_id = !empty($_POST['agente_id']) ? (int)$_POST['agente_id'] : null;

    // Reportes diarios
    $sql = "SELECT r.*, u.nombre, u.color, u.iniciales, ue.nombre AS editor_nombre
            FROM reporte_diario r
            LEFT JOIN usuarios u  ON r.agente_id   = u.id
            LEFT JOIN usuarios ue ON r.editado_por = ue.id
            WHERE r.fecha BETWEEN ? AND ?";
    $params = [$from, $to];
    if ($ag_id) { $sql .= " AND r.agente_id = ?"; $params[] = $ag_id; }
    $sql .= " ORDER BY r.fecha DESC, u.nombre ASC";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $reportes = $stmt->fetchAll();

    // Checklist por agente y fecha
    $ck_sql = "SELECT cd.agente_id, cd.fecha,
                      COUNT(*) as total, SUM(cd.completado) as completadas,
                      GROUP_CONCAT(CASE WHEN cd.completado=1 THEN cd.item_texto END ORDER BY cd.item_texto SEPARATOR '||') as items_ok,
                      GROUP_CONCAT(CASE WHEN cd.completado=0 THEN cd.item_texto END ORDER BY cd.item_texto SEPARATOR '||') as items_pend
               FROM checklist_diario cd
               WHERE cd.fecha BETWEEN ? AND ?";
    $ck_params = [$from, $to];
    if ($ag_id) { $ck_sql .= " AND cd.agente_id = ?"; $ck_params[] = $ag_id; }
    $ck_sql .= " GROUP BY cd.agente_id, cd.fecha";
    $ck_stmt = $pdo->prepare($ck_sql); $ck_stmt->execute($ck_params);

    $ck_map = [];
    foreach ($ck_stmt->fetchAll() as $ck) {
        $ck_map[$ck['agente_id']][$ck['fecha']] = $ck;
    }

    // Merge checklist into reportes
    foreach ($reportes as &$r) {
        $ck = $ck_map[$r['agente_id']][$r['fecha']] ?? null;
        $r['ck_total']    = $ck ? (int)$ck['total']      : 0;
        $r['ck_done']     = $ck ? (int)$ck['completadas'] : 0;
        $r['ck_items_ok'] = $ck && $ck['items_ok']   ? explode('||', $ck['items_ok'])   : [];
        $r['ck_items_pend']= $ck && $ck['items_pend'] ? explode('||', $ck['items_pend']) : [];
    }
    unset($r);

    jsonOk($reportes);
    break;    

// ══════════════════════════════════════════════════════════════
// ── RETENCIÓN — LLAMADAS 30/60/90 DÍAS ────────────────────────
// ══════════════════════════════════════════════════════════════

case 'save_retencion_llamada':
    $pdo = db();

    // Crear tabla si no existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS retencion_llamadas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        miembro_id INT NOT NULL,
        tipo ENUM('BIENVENIDA','30','60','90') NOT NULL,
        resultado ENUM('COMPLETADA','NO CONTESTÓ','BUZÓN') NOT NULL,
        notas TEXT,
        completada_por INT,
        completada_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_ret (miembro_id, tipo)
    )");

    $mid       = intval($_POST['miembro_id'] ?? 0);
    $tipo      = $_POST['tipo']      ?? '';
    $resultado = $_POST['resultado'] ?? '';
    $notas     = trim($_POST['notas'] ?? '');

    if (!$mid || !$tipo || !$resultado) jsonErr('Datos incompletos');
    if (!in_array($tipo, ['BIENVENIDA','30','60','90'])) jsonErr('Tipo inválido');
    if (!in_array($resultado, ['COMPLETADA','NO CONTESTÓ','BUZÓN'])) jsonErr('Resultado inválido');

    // INSERT o UPDATE si ya existe (permite corregir el resultado)
    $pdo->prepare("INSERT INTO retencion_llamadas (miembro_id, tipo, resultado, notas, completada_por)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            resultado      = VALUES(resultado),
            notas          = VALUES(notas),
            completada_por = VALUES(completada_por),
            completada_at  = CURRENT_TIMESTAMP")
        ->execute([$mid, $tipo, $resultado, $notas ?: null, $uid]);

    // Actividad
    $pdo->prepare("INSERT INTO actividad (agente_id, miembro_id, tipo, descripcion) VALUES (?,?,?,?)")
        ->execute([$uid, $mid, 'RETENCION', "Llamada retención {$tipo} días — {$resultado}"]);

    // Si no contestó o buzón → notificación interna para admin
    if ($resultado !== 'COMPLETADA') {
        try {
            $mem = $pdo->prepare("SELECT nombre, apellido FROM miembros WHERE id=?");
            $mem->execute([$mid]);
            $mdata = $mem->fetch();
            if ($mdata) {
                $msg = "📵 Retención {$tipo} días sin contestar: {$mdata['nombre']} {$mdata['apellido']}";
                foreach ($pdo->query("SELECT id FROM usuarios WHERE activo=1 AND rol='admin'") as $u) {
                    // Solo insertar si no existe ya hoy para evitar duplicados
                    $chk = $pdo->prepare("SELECT id FROM notificaciones WHERE user_id=? AND mensaje=? AND DATE(created_at)=CURDATE()");
                    $chk->execute([$u['id'], $msg]);
                    if (!$chk->fetch()) {
                        $pdo->prepare("INSERT INTO notificaciones (user_id, tipo, mensaje) VALUES (?, 'RETENCION', ?)")
                            ->execute([$u['id'], $msg]);
                    }
                }
            }
        } catch (Exception $e) {}
    }

    jsonOk();
    break;

// ── RETENCIÓN — CUESTIONARIO 30 DÍAS ──────────────────────────

case 'save_retencion_q30':
    $pdo = db();

    // Crear tablas si no existen
    $pdo->exec("CREATE TABLE IF NOT EXISTS retencion_llamadas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        miembro_id INT NOT NULL,
        tipo ENUM('BIENVENIDA','30','60','90') NOT NULL,
        resultado ENUM('COMPLETADA','NO CONTESTÓ','BUZÓN') NOT NULL,
        notas TEXT,
        completada_por INT,
        completada_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_ret (miembro_id, tipo)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS retencion_cuestionario_30 (
        id INT AUTO_INCREMENT PRIMARY KEY,
        miembro_id INT NOT NULL,
        puede_sms           TINYINT(1) DEFAULT NULL,
        usa_whatsapp        TINYINT(1) DEFAULT NULL,
        usa_facebook        TINYINT(1) DEFAULT NULL,
        nos_siguio          TINYINT(1) DEFAULT NULL,
        link_enviado        TINYINT(1) DEFAULT NULL,
        usa_insulina        TINYINT(1) DEFAULT NULL,
        ayudas_movilidad    VARCHAR(500) DEFAULT NULL,
        necesita_delivery   TINYINT(1) DEFAULT NULL,
        llego_tarjeta       TINYINT(1) DEFAULT NULL,
        explicaste_tarjeta  TINYINT(1) DEFAULT NULL,
        direccion_correcta  TINYINT(1) DEFAULT NULL,
        esta_casado         TINYINT(1) DEFAULT NULL,
        doctor_correcto     TINYINT(1) DEFAULT NULL,
        ha_ido_citas        TINYINT(1) DEFAULT NULL,
        satisfecho_doctor   TINYINT(1) DEFAULT NULL,
        cambiar_doctor      TINYINT(1) DEFAULT NULL,
        va_dentista         TINYINT(1) DEFAULT NULL,
        necesita_dentista   TINYINT(1) DEFAULT NULL,
        usa_anteojos        TINYINT(1) DEFAULT NULL,
        explicaste_uber     TINYINT(1) DEFAULT NULL,
        explicaste_gym      TINYINT(1) DEFAULT NULL,
        beneficios_repasados TEXT DEFAULT NULL,
        explicaste_no_dar_info TINYINT(1) DEFAULT NULL,
        referido_nuevo      VARCHAR(255) DEFAULT NULL,
        donde_conocio_isabel VARCHAR(255) DEFAULT NULL,
        notas_generales     TEXT DEFAULT NULL,
        completada_por      INT DEFAULT NULL,
        completada_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        nos_siguio_ig       TINYINT(1) DEFAULT NULL,
        en_ihss             TINYINT(1) DEFAULT NULL,
        transporte          VARCHAR(100) DEFAULT NULL,
        con_quien_vive      VARCHAR(200) DEFAULT NULL,
        cuenta_referida_id  INT DEFAULT NULL,
        cuenta_referida_tipo VARCHAR(50) DEFAULT NULL,
        UNIQUE KEY uk_q30 (miembro_id)
    )");
    // Agregar columnas nuevas si la tabla ya existe
    foreach(['nos_siguio_ig TINYINT(1) DEFAULT NULL','en_ihss TINYINT(1) DEFAULT NULL',
             'transporte VARCHAR(100) DEFAULT NULL','con_quien_vive VARCHAR(200) DEFAULT NULL',
             'cuenta_referida_id INT DEFAULT NULL','cuenta_referida_tipo VARCHAR(50) DEFAULT NULL'] as $_nc) {
        try { $pdo->exec("ALTER TABLE retencion_cuestionario_30 ADD COLUMN $_nc"); } catch(Exception $e) {}
    }

    $mid       = intval($_POST['miembro_id'] ?? 0);
    $resultado = $_POST['resultado_q30'] ?? 'COMPLETADA';
    if (!$mid) jsonErr('Miembro no especificado');
    if (!in_array($resultado, ['COMPLETADA','NO CONTESTÓ','BUZÓN'])) jsonErr('Resultado inválido');

    // Convierte '' a NULL, '1'/'0' a int
    $yn = fn($k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? intval($_POST[$k]) : null;

    $ayudas     = array_filter((array)($_POST['ayudas_movilidad'] ?? []));
    $beneficios = array_filter((array)($_POST['beneficios'] ?? []));

    // ── Guardar cuestionario ──────────────────────────────────
    $con_quien = array_filter((array)($_POST['con_quien_vive'] ?? []));

    $pdo->prepare("INSERT INTO retencion_cuestionario_30 (
        miembro_id, puede_sms, usa_whatsapp, usa_facebook, nos_siguio,
        link_enviado, usa_insulina, ayudas_movilidad, necesita_delivery,
        llego_tarjeta, explicaste_tarjeta, direccion_correcta,
        esta_casado, doctor_correcto, ha_ido_citas, satisfecho_doctor,
        cambiar_doctor, va_dentista, necesita_dentista, usa_anteojos,
        explicaste_uber, explicaste_gym, beneficios_repasados,
        explicaste_no_dar_info, referido_nuevo, donde_conocio_isabel,
        notas_generales, completada_por,
        nos_siguio_ig, en_ihss, transporte, con_quien_vive,
        cuenta_referida_id, cuenta_referida_tipo
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
        puede_sms=VALUES(puede_sms), usa_whatsapp=VALUES(usa_whatsapp),
        usa_facebook=VALUES(usa_facebook), nos_siguio=VALUES(nos_siguio),
        link_enviado=VALUES(link_enviado), usa_insulina=VALUES(usa_insulina),
        ayudas_movilidad=VALUES(ayudas_movilidad), necesita_delivery=VALUES(necesita_delivery),
        llego_tarjeta=VALUES(llego_tarjeta), explicaste_tarjeta=VALUES(explicaste_tarjeta),
        direccion_correcta=VALUES(direccion_correcta), esta_casado=VALUES(esta_casado),
        doctor_correcto=VALUES(doctor_correcto), ha_ido_citas=VALUES(ha_ido_citas),
        satisfecho_doctor=VALUES(satisfecho_doctor), cambiar_doctor=VALUES(cambiar_doctor),
        va_dentista=VALUES(va_dentista), necesita_dentista=VALUES(necesita_dentista),
        usa_anteojos=VALUES(usa_anteojos), explicaste_uber=VALUES(explicaste_uber),
        explicaste_gym=VALUES(explicaste_gym), beneficios_repasados=VALUES(beneficios_repasados),
        explicaste_no_dar_info=VALUES(explicaste_no_dar_info),
        referido_nuevo=VALUES(referido_nuevo), donde_conocio_isabel=VALUES(donde_conocio_isabel),
        notas_generales=VALUES(notas_generales), completada_por=VALUES(completada_por),
        completada_at=CURRENT_TIMESTAMP,
        nos_siguio_ig=VALUES(nos_siguio_ig), en_ihss=VALUES(en_ihss),
        transporte=VALUES(transporte), con_quien_vive=VALUES(con_quien_vive),
        cuenta_referida_id=VALUES(cuenta_referida_id), cuenta_referida_tipo=VALUES(cuenta_referida_tipo)")
    ->execute([
        $mid,
        $yn('puede_sms'),    $yn('usa_whatsapp'), $yn('usa_facebook'), $yn('nos_siguio'),
        $yn('link_enviado'), $yn('usa_insulina'),
        implode(', ', $ayudas) ?: null,
        $yn('necesita_delivery'),
        $yn('llego_tarjeta'),  $yn('explicaste_tarjeta'), $yn('direccion_correcta'),
        $yn('esta_casado'),    $yn('doctor_correcto'),     $yn('ha_ido_citas'),
        $yn('satisfecho_doctor'), $yn('cambiar_doctor'),
        $yn('va_dentista'),    $yn('necesita_dentista'),   $yn('usa_anteojos'),
        $yn('explicaste_uber'), $yn('explicaste_gym'),
        implode(',', $beneficios) ?: null,
        $yn('explicaste_no_dar_info'),
        trim($_POST['referido_nuevo'] ?? '') ?: null,
        trim($_POST['donde_conocio_isabel'] ?? '') ?: null,
        trim($_POST['notas_generales'] ?? '') ?: null,
        $uid,
        $yn('nos_siguio_ig'), $yn('en_ihss'),
        trim($_POST['transporte'] ?? '') ?: null,
        implode(', ', $con_quien) ?: null,
        intval($_POST['cuenta_referida_id'] ?? 0) ?: null,
        trim($_POST['cuenta_referida_tipo'] ?? '') ?: null
    ]);

    // ── Guardar automáticamente la llamada de 30 días ─────────
    $pdo->prepare("INSERT INTO retencion_llamadas (miembro_id, tipo, resultado, notas, completada_por)
        VALUES (?, '30', ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            resultado=VALUES(resultado), notas=VALUES(notas),
            completada_por=VALUES(completada_por), completada_at=CURRENT_TIMESTAMP")
        ->execute([$mid, $resultado, trim($_POST['notas_generales'] ?? '') ?: null, $uid]);

    // ── Actualizar campos del miembro que vinieron en el form ─
    $updates = []; $params = [];
    $map = [
        'telefono2_new'            => 'telefono2',
        'email_new'                => 'email',
        'condiciones_cronicas_new' => 'condiciones_cronicas',
        'prescripciones_new'       => 'prescripciones',
        'referido_por_new'         => 'referido_por',
        'direccion_calle_new'      => 'direccion_calle',
        'direccion_apto_new'       => 'direccion_apto',
        'ciudad_new'               => 'ciudad',
        'zip_new'                  => 'zip',
        'profesion_new'            => 'profesion',
        'pcp_new'                  => 'pcp',
        'pcp_group_new'            => 'pcp_group',
        'dentista_new'             => 'dentista',
    ];
    foreach ($map as $post_key => $db_col) {
        $val = trim($_POST[$post_key] ?? '');
        if ($val !== '') { $updates[] = "$db_col = ?"; $params[] = $val; }
    }
    // Estado civil desde ¿está casado?
    $casado = $_POST['esta_casado'] ?? '';
    if ($casado === '1') { $updates[] = "estado_civil = ?"; $params[] = 'CASADO/A'; }
    elseif ($casado === '0') { $updates[] = "estado_civil = ?"; $params[] = 'SOLTERO/A'; }
    // opt_in SMS
    $puede_sms = $_POST['puede_sms'] ?? '';
    if ($puede_sms !== '') { $updates[] = "opt_in = ?"; $params[] = intval($puede_sms); }

    if ($updates) {
        $params[] = $mid;
        $pdo->prepare("UPDATE miembros SET " . implode(', ', $updates) . " WHERE id=?")
            ->execute($params);
    }

    // ── Si hay referido nuevo → crear prospect automáticamente
    $ref_nuevo = trim($_POST['referido_nuevo'] ?? '');
    if ($ref_nuevo) {
        try {
            $agente_q = $pdo->prepare("SELECT agente_id FROM miembros WHERE id=?");
            $agente_q->execute([$mid]);
            $agente_ref = $agente_q->fetchColumn() ?: $uid;
            $pdo->prepare("INSERT INTO miembros (nombre, apellido, telefono, estado, fuente, referido_por, agente_id)
                VALUES (?, '', '', 'PROSPECT', 'REFERIDO MIEMBRO', ?, ?)")
                ->execute([$ref_nuevo, $ref_nuevo, $agente_ref]);
        } catch (Exception $e) {}
    }

    // ── Actividad ─────────────────────────────────────────────
    $pdo->prepare("INSERT INTO actividad (agente_id, miembro_id, tipo, descripcion) VALUES (?,?,?,?)")
        ->execute([$uid, $mid, 'RETENCION', "Cuestionario 30 días completado — {$resultado}"]);

    jsonOk();
    break;

// ── RETENCIÓN — OBTENER CUESTIONARIO 30 DÍAS (para perfil) ────

case 'get_retencion_q30':
    $pdo = db();
    $mid = intval($_GET['id'] ?? $_POST['miembro_id'] ?? 0);
    if (!$mid) jsonErr('ID requerido');
    try {
        $stmt = $pdo->prepare("SELECT q.*, u.nombre AS por_nombre
            FROM retencion_cuestionario_30 q
            LEFT JOIN usuarios u ON q.completada_por = u.id
            WHERE q.miembro_id = ?");
        $stmt->execute([$mid]);
        $q = $stmt->fetch(PDO::FETCH_ASSOC);
        jsonOk($q ?: null);
    } catch (Exception $e) {
        jsonErr($e->getMessage());
    }
    break;

// ── HISTORIAL / AUDIT LOG ─────────────────────────────────────
case 'get_audit_log':
    if (!$admin) jsonErr('Sin permiso');
    $pdo  = db();
    $tipo    = $_GET['tipo']    ?? 'all';
    $usuario = $_GET['usuario'] ?? 'all';
    $desde   = $_GET['desde']   ?? '';
    $hasta   = $_GET['hasta']   ?? '';
    $search  = $_GET['search']  ?? '';
    $where = []; $params = [];
    if ($tipo !== 'all')    { $where[] = 'a.tipo=?';               $params[] = $tipo; }
    if ($usuario !== 'all') { $where[] = 'a.agente_id=?';          $params[] = intval($usuario); }
    if ($desde)             { $where[] = 'DATE(a.created_at)>=?';  $params[] = $desde; }
    if ($hasta)             { $where[] = 'DATE(a.created_at)<=?';  $params[] = $hasta; }
    if ($search)            { $where[] = 'a.descripcion LIKE ?';   $params[] = '%'.$search.'%'; }
    $wc = $where ? 'WHERE '.implode(' AND ', $where) : '';
    $stmt = $pdo->prepare("
        SELECT a.id, a.tipo, a.descripcion, a.created_at, a.miembro_id,
               u.nombre AS user_nombre,
               CONCAT(m.nombre,' ',m.apellido) AS miembro_nombre
        FROM actividad a
        LEFT JOIN usuarios u ON a.agente_id = u.id
        LEFT JOIN miembros m ON a.miembro_id = m.id
        $wc
        ORDER BY a.id DESC
        LIMIT 300");
    $stmt->execute($params);
    jsonOk($stmt->fetchAll());
    break;

// ── GET GASTOS (expense report) ───────────────────────────────
case 'get_gastos':
    $pdo = db();
    $gu  = auth();
    $mes  = $_GET['mes']  ?? 'all';
    $cat  = $_GET['cat']  ?? 'all';
    $est  = $_GET['est']  ?? 'all';
    $year = intval($_GET['year'] ?? date('Y'));
    $where = []; $params = [];
    if ($mes !== 'all') { $where[] = 'MONTH(g.fecha)=? AND YEAR(g.fecha)=?'; $params[] = intval($mes); $params[] = $year; }
    if ($cat !== 'all') { $where[] = 'g.categoria=?'; $params[] = $cat; }
    if ($est !== 'all') { $where[] = 'g.estado=?'; $params[] = $est; }
    // Todos ven los gastos de la oficina (las acciones de aprobar/reembolsar siguen siendo admin)
    $wc = $where ? 'WHERE '.implode(' AND ', $where) : '';
    $stmt = $pdo->prepare("SELECT g.*, u.nombre AS enviado_nombre, r.nombre AS reembolsar_nombre FROM gastos g LEFT JOIN usuarios u ON g.enviado_por=u.id LEFT JOIN usuarios r ON g.reembolsar_a=r.id $wc ORDER BY g.fecha DESC, g.id DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    // Totals without status filter for full breakdown
    $tp = []; $tw = [];
    if ($mes !== 'all') { $tw[] = 'MONTH(fecha)=? AND YEAR(fecha)=?'; $tp[] = intval($mes); $tp[] = $year; }
    if ($cat !== 'all') { $tw[] = 'categoria=?'; $tp[] = $cat; }
    $twc = $tw ? 'WHERE '.implode(' AND ', $tw) : '';
    $ts = $pdo->prepare("SELECT estado, SUM(monto) AS suma FROM gastos $twc GROUP BY estado");
    $ts->execute($tp);
    $totales = ['total'=>0,'aprobado'=>0,'pendiente'=>0,'rechazado'=>0];
    foreach ($ts->fetchAll() as $t) {
        $totales['total'] += floatval($t['suma']);
        $key = strtolower($t['estado']);
        if (isset($totales[$key])) $totales[$key] = floatval($t['suma']);
    }
    jsonOk(['data'=>$rows,'totales'=>$totales]);
    break;

// ── SAVE GASTO ────────────────────────────────────────────────
case 'save_gasto':
    $pdo = db();
    $u   = auth();
    $fecha = trim($_POST['fecha'] ?? '');
    $cat   = trim($_POST['categoria'] ?? '');
    $desc  = trim($_POST['descripcion'] ?? '');
    $monto = floatval($_POST['monto'] ?? 0);
    if (!$fecha || !$cat || !$desc || $monto < 0) jsonErr('Campos requeridos incompletos');
    // Foto de la factura/recibo (opcional)
    $recibo_foto = null;
    if (!empty($_FILES['recibo_foto']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['recibo_foto']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp','pdf'])) jsonErr('Formato de factura no permitido (usa imagen o PDF)');
        $dir = __DIR__ . '/uploads/recibos/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $fname = 'gasto_' . $u['id'] . '_' . time() . '_' . random_int(100,999) . '.' . $ext;
        if (!move_uploaded_file($_FILES['recibo_foto']['tmp_name'], $dir . $fname)) jsonErr('Error al guardar la factura');
        $recibo_foto = 'uploads/recibos/' . $fname;
    }
    $reembolsar_a = intval($_POST['reembolsar_a'] ?? 0) ?: null;
    $pdo->prepare("INSERT INTO gastos (fecha,categoria,tipo,descripcion,vendedor,monto,metodo_pago,enviado_por,recibo,recibo_foto,reembolsar_a,notas)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            $fecha, $cat,
            trim($_POST['tipo'] ?? '') ?: null,
            $desc,
            trim($_POST['vendedor'] ?? '') ?: null,
            $monto,
            in_array($_POST['metodo_pago']??'',['CASH','CARD','CHECK','ZELLE','OTHER']) ? $_POST['metodo_pago'] : 'CARD',
            $u['id'],
            $recibo_foto ? 1 : 0,
            $recibo_foto,
            $reembolsar_a,
            trim($_POST['notas'] ?? '') ?: null
        ]);
    jsonOk();
    break;

// ── TOGGLE REEMBOLSO A EMPLEADO (admin) ───────────────────────
case 'toggle_gasto_reembolso':
    $pdo = db();
    $u   = auth();
    if (!isAdmin()) jsonErr('Solo admin puede marcar reembolsos');
    $id     = intval($_POST['id'] ?? 0);
    $pagado = !empty($_POST['pagado']) ? 1 : 0;
    if (!$id) jsonErr('ID requerido');
    $pdo->prepare("UPDATE gastos SET reembolsado=?, reembolsado_at=".($pagado?'NOW()':'NULL')." WHERE id=?")
        ->execute([$pagado, $id]);
    jsonOk();
    break;

// ── UPDATE GASTO STATUS ───────────────────────────────────────
case 'update_gasto_status':
    $pdo = db();
    $u   = auth();
    if (!isAdmin()) jsonErr('Sin permiso');
    $id     = intval($_POST['id'] ?? 0);
    $estado = trim($_POST['estado'] ?? '');
    if (!$id || !in_array($estado, ['PENDIENTE','APROBADO','RECHAZADO'])) jsonErr('Datos inválidos');
    $pdo->prepare("UPDATE gastos SET estado=?, aprobado_por=? WHERE id=?")->execute([$estado, $u['id'], $id]);
    jsonOk();
    break;

// ── DELETE GASTO ──────────────────────────────────────────────
case 'delete_gasto':
    $pdo = db();
    $gu  = auth();
    $id = intval($_POST['id'] ?? 0);
    if (!$id) jsonErr('ID requerido');
    // Admin borra cualquiera; el empleado solo los suyos
    if (isAdmin()) {
        $pdo->prepare("DELETE FROM gastos WHERE id=?")->execute([$id]);
    } else {
        $pdo->prepare("DELETE FROM gastos WHERE id=? AND enviado_por=?")->execute([$id, $gu['id']]);
    }
    jsonOk();
    break;

// ── TOGGLE SALES ALLEGATION ───────────────────────────────────
case 'toggle_sales_allegation':
    $pdo = db();
    $u   = auth();
    $mid = intval($_POST['miembro_id'] ?? 0);
    $val = intval($_POST['valor'] ?? 0);
    if (!$mid) jsonErr('ID requerido');
    $pdo->prepare("UPDATE miembros SET sales_allegation=? WHERE id=?")->execute([$val ? 1 : 0, $mid]);
    jsonOk();
    break;

// ── UPLOAD FOTO PERFIL ────────────────────────────────────────
case 'upload_foto':
    $pdo = db();
    $u   = auth();
    $mid = intval($_POST['miembro_id'] ?? 0);
    if (!$mid) jsonErr('ID requerido');
    if (empty($_FILES['foto']['tmp_name'])) jsonErr('No se recibió archivo');
    $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) jsonErr('Formato no permitido');
    $dir = __DIR__ . '/uploads/fotos/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $fname = $mid . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($_FILES['foto']['tmp_name'], $dir . $fname)) jsonErr('Error al guardar archivo');
    $url = 'uploads/fotos/' . $fname;
    $pdo->prepare("UPDATE miembros SET foto_perfil=? WHERE id=?")->execute([$url, $mid]);
    jsonOk(['url' => $url]);
    break;

// ── SAVE POST-CITA QUESTIONNAIRE ──────────────────────────────
case 'save_postcita_q':
    $pdo = db();
    $u   = auth();
    $mid = intval($_POST['miembro_id'] ?? 0);
    if (!$mid) jsonErr('ID requerido');
    $pdo->exec("CREATE TABLE IF NOT EXISTS citas_seguimiento (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cita_id INT NULL,
        miembro_id INT NOT NULL,
        grupo_medico VARCHAR(255) DEFAULT NULL,
        panel VARCHAR(255) DEFAULT NULL,
        carrier_elegido VARCHAR(100) DEFAULT NULL,
        plan_elegido VARCHAR(255) DEFAULT NULL,
        resultado ENUM('APLICACION','SOLO INFORMACION','REGRESARA','NO INTERESADO','OTRO') DEFAULT NULL,
        fecha_efectiva DATE DEFAULT NULL,
        notas TEXT,
        completada_por INT,
        completada_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_miembro (miembro_id),
        KEY idx_cita (cita_id)
    )");
    $pdo->prepare("INSERT INTO citas_seguimiento (miembro_id,resultado,carrier_elegido,plan_elegido,grupo_medico,panel,fecha_efectiva,notas,completada_por)
        VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([
            $mid,
            trim($_POST['resultado'] ?? '') ?: null,
            trim($_POST['carrier_elegido'] ?? '') ?: null,
            trim($_POST['plan_elegido'] ?? '') ?: null,
            trim($_POST['grupo_medico'] ?? '') ?: null,
            trim($_POST['panel'] ?? '') ?: null,
            trim($_POST['fecha_efectiva'] ?? '') ?: null,
            trim($_POST['notas'] ?? '') ?: null,
            $u['id']
        ]);
    jsonOk();
    break;

// ════════════════════════════════════════════════════════════════
//  PROYECTOS  —  sección bajo TICKETS (proyectos + avances + archivos)
// ════════════════════════════════════════════════════════════════
case 'list_proyectos':
    $pdo = db();
    ensureProyectosTables($pdo);
    $equipoSel = "(SELECT GROUP_CONCAT(CONCAT(u2.iniciales,':',COALESCE(u2.color,'#1B5E8C')) SEPARATOR '|')
                  FROM proyecto_miembros pm JOIN usuarios u2 ON u2.id=pm.usuario_id WHERE pm.proyecto_id=p.id) AS equipo,
                 (SELECT GROUP_CONCAT(pm.usuario_id) FROM proyecto_miembros pm WHERE pm.proyecto_id=p.id) AS equipo_ids";
    if ($admin) {
        $stmt = $pdo->query("SELECT p.*,
                c.nombre creador_nombre, c.iniciales creador_ini, c.color creador_color,
                a.nombre asig_nombre,    a.iniciales asig_ini,    a.color asig_color,
                (SELECT COUNT(*) FROM proyecto_avances  av WHERE av.proyecto_id=p.id) n_avances,
                (SELECT COUNT(*) FROM proyecto_archivos ar WHERE ar.proyecto_id=p.id) n_archivos,
                $equipoSel
            FROM proyectos p
            LEFT JOIN usuarios c ON p.agente_id  = c.id
            LEFT JOIN usuarios a ON p.asignado_a = a.id
            ORDER BY (p.estado='COMPLETADO') ASC, p.orden ASC, p.id DESC");
        jsonOk($stmt->fetchAll());
    } else {
        $stmt = $pdo->prepare("SELECT p.*,
                c.nombre creador_nombre, c.iniciales creador_ini, c.color creador_color,
                a.nombre asig_nombre,    a.iniciales asig_ini,    a.color asig_color,
                (SELECT COUNT(*) FROM proyecto_avances  av WHERE av.proyecto_id=p.id) n_avances,
                (SELECT COUNT(*) FROM proyecto_archivos ar WHERE ar.proyecto_id=p.id) n_archivos,
                $equipoSel
            FROM proyectos p
            LEFT JOIN usuarios c ON p.agente_id  = c.id
            LEFT JOIN usuarios a ON p.asignado_a = a.id
            WHERE p.asignado_a=? OR p.agente_id=?
               OR EXISTS(SELECT 1 FROM proyecto_miembros pm WHERE pm.proyecto_id=p.id AND pm.usuario_id=?)
            ORDER BY (p.estado='COMPLETADO') ASC, p.orden ASC, p.id DESC");
        $stmt->execute([$uid, $uid, $uid]);
        jsonOk($stmt->fetchAll());
    }
    break;

case 'get_proyecto':
    $pdo = db();
    ensureProyectosTables($pdo);
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if (!$id) jsonErr('ID inválido');
    $stmt = $pdo->prepare("SELECT p.*,
            c.nombre creador_nombre, c.iniciales creador_ini, c.color creador_color,
            a.nombre asig_nombre,    a.iniciales asig_ini,    a.color asig_color
        FROM proyectos p
        LEFT JOIN usuarios c ON p.agente_id  = c.id
        LEFT JOIN usuarios a ON p.asignado_a = a.id
        WHERE p.id=?");
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if (!$p) jsonErr('Proyecto no encontrado');
    if (!proyPuede($pdo, $p, $uid, $admin))
        jsonErr('Sin permiso para ver este proyecto');
    $tm = $pdo->prepare("SELECT u.id, u.nombre, u.iniciales, u.color
                         FROM proyecto_miembros pm JOIN usuarios u ON u.id=pm.usuario_id
                         WHERE pm.proyecto_id=? ORDER BY u.nombre");
    $tm->execute([$id]);
    $p['equipo'] = $tm->fetchAll();
    $av = $pdo->prepare("SELECT av.*, u.nombre, u.iniciales, u.color
                         FROM proyecto_avances av LEFT JOIN usuarios u ON av.usuario_id=u.id
                         WHERE av.proyecto_id=? ORDER BY av.id DESC");
    $av->execute([$id]);
    $p['avances'] = $av->fetchAll();
    $ar = $pdo->prepare("SELECT ar.*, u.nombre, u.iniciales
                         FROM proyecto_archivos ar LEFT JOIN usuarios u ON ar.usuario_id=u.id
                         WHERE ar.proyecto_id=? ORDER BY ar.id DESC");
    $ar->execute([$id]);
    $p['archivos'] = $ar->fetchAll();
    jsonOk($p);
    break;

case 'save_proyecto':
    $pdo = db();
    ensureProyectosTables($pdo);
    $titulo = trim($_POST['titulo'] ?? '');
    if ($titulo === '') jsonErr('El título es obligatorio');
    $descripcion  = trim($_POST['descripcion'] ?? '');
    $estado       = $_POST['estado'] ?? 'PLANIFICANDO';
    $prioridad    = $_POST['prioridad'] ?? 'MEDIA';
    $progreso     = max(0, min(100, (int)($_POST['progreso'] ?? 0)));
    $asignado_a   = !empty($_POST['asignado_a']) ? (int)$_POST['asignado_a'] : null;
    $fecha_inicio = !empty($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : null;
    $fecha_limite = !empty($_POST['fecha_limite']) ? $_POST['fecha_limite'] : null;
    $fecha_cierre = ($estado === 'COMPLETADO') ? date('Y-m-d') : null;
    if ($estado === 'COMPLETADO') $progreso = 100;
    $stmt = $pdo->prepare("INSERT INTO proyectos
        (titulo, descripcion, estado, prioridad, progreso, asignado_a, agente_id, fecha_inicio, fecha_limite, fecha_cierre)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$titulo, ($descripcion ?: null), $estado, $prioridad, $progreso,
        $asignado_a, $uid, $fecha_inicio, $fecha_limite, $fecha_cierre]);
    $newId = (int)$pdo->lastInsertId();
    // Orden inicial = id → el proyecto nuevo queda al final de su grupo
    $pdo->prepare("UPDATE proyectos SET orden=? WHERE id=?")->execute([$newId, $newId]);
    guardarEquipoProyecto($pdo, $newId, $_POST['team'] ?? '');
    jsonOk(['id' => $newId]);
    break;

case 'update_proyecto':
    $pdo = db();
    ensureProyectosTables($pdo);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('ID inválido');
    $cur = $pdo->prepare("SELECT * FROM proyectos WHERE id=?");
    $cur->execute([$id]);
    $p = $cur->fetch();
    if (!$p) jsonErr('Proyecto no encontrado');
    if (!proyPuede($pdo, $p, $uid, $admin))
        jsonErr('Sin permiso para editar este proyecto');
    $titulo = trim($_POST['titulo'] ?? '');
    if ($titulo === '') jsonErr('El título es obligatorio');
    $descripcion  = trim($_POST['descripcion'] ?? '');
    $estado       = $_POST['estado'] ?? $p['estado'];
    $prioridad    = $_POST['prioridad'] ?? $p['prioridad'];
    $progreso     = max(0, min(100, (int)($_POST['progreso'] ?? $p['progreso'])));
    $asignado_a   = !empty($_POST['asignado_a']) ? (int)$_POST['asignado_a'] : null;
    $fecha_inicio = !empty($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : null;
    $fecha_limite = !empty($_POST['fecha_limite']) ? $_POST['fecha_limite'] : null;
    if ($estado === 'COMPLETADO') { $progreso = 100; $fecha_cierre = $p['fecha_cierre'] ?: date('Y-m-d'); }
    else $fecha_cierre = null;
    $stmt = $pdo->prepare("UPDATE proyectos SET titulo=?, descripcion=?, estado=?, prioridad=?,
        progreso=?, asignado_a=?, fecha_inicio=?, fecha_limite=?, fecha_cierre=? WHERE id=?");
    $stmt->execute([$titulo, ($descripcion ?: null), $estado, $prioridad, $progreso,
        $asignado_a, $fecha_inicio, $fecha_limite, $fecha_cierre, $id]);
    if ($estado === 'COMPLETADO') $pdo->prepare("UPDATE proyectos SET es_foco=0 WHERE id=?")->execute([$id]);
    if (isset($_POST['team'])) guardarEquipoProyecto($pdo, $id, $_POST['team']);
    jsonOk();
    break;

case 'add_avance':
    $pdo = db();
    ensureProyectosTables($pdo);
    $pid = (int)($_POST['proyecto_id'] ?? 0);
    $nota = trim($_POST['nota'] ?? '');
    if (!$pid) jsonErr('ID inválido');
    if ($nota === '') jsonErr('Escribe una nota de avance');
    $cur = $pdo->prepare("SELECT * FROM proyectos WHERE id=?");
    $cur->execute([$pid]);
    $p = $cur->fetch();
    if (!$p) jsonErr('Proyecto no encontrado');
    if (!proyPuede($pdo, $p, $uid, $admin))
        jsonErr('Sin permiso para actualizar este proyecto');
    $progreso = (isset($_POST['progreso']) && $_POST['progreso'] !== '')
        ? max(0, min(100, (int)$_POST['progreso'])) : null;
    $ins = $pdo->prepare("INSERT INTO proyecto_avances (proyecto_id, usuario_id, nota, progreso) VALUES (?,?,?,?)");
    $ins->execute([$pid, $uid, $nota, $progreso]);
    // Si el avance trae progreso, actualiza el proyecto (y lo completa si llega a 100)
    if ($progreso !== null) {
        if ($progreso >= 100 && $p['estado'] !== 'CONTINUO') {
            // Los proyectos CONTINUO nunca se auto-completan (son permanentes/recurrentes)
            $pdo->prepare("UPDATE proyectos SET progreso=100, estado='COMPLETADO', es_foco=0,
                           fecha_cierre=COALESCE(fecha_cierre, CURDATE()) WHERE id=?")->execute([$pid]);
        } else {
            $nuevoEstado = ($p['estado'] === 'PLANIFICANDO') ? 'EN PROGRESO' : $p['estado'];
            $pdo->prepare("UPDATE proyectos SET progreso=?, estado=? WHERE id=?")
                ->execute([min(100, $progreso), $nuevoEstado, $pid]);
        }
    }
    jsonOk(['avance_id' => (int)$pdo->lastInsertId()]);
    break;

case 'delete_avance':
    $pdo = db();
    ensureProyectosTables($pdo);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('ID inválido');
    $row = $pdo->prepare("SELECT av.usuario_id, p.agente_id FROM proyecto_avances av
                          JOIN proyectos p ON p.id=av.proyecto_id WHERE av.id=?");
    $row->execute([$id]);
    $a = $row->fetch();
    if (!$a) jsonErr('Avance no encontrado');
    if (!$admin && $a['usuario_id'] != $uid && $a['agente_id'] != $uid)
        jsonErr('Sin permiso');
    $pdo->prepare("DELETE FROM proyecto_avances WHERE id=?")->execute([$id]);
    jsonOk();
    break;

case 'delete_proyecto':
    $pdo = db();
    ensureProyectosTables($pdo);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('ID inválido');
    $cur = $pdo->prepare("SELECT * FROM proyectos WHERE id=?");
    $cur->execute([$id]);
    $p = $cur->fetch();
    if (!$p) jsonErr('Proyecto no encontrado');
    if (!$admin && $p['agente_id'] != $uid) jsonErr('Solo el creador o un admin puede eliminar');
    // Borrar archivos físicos
    $ars = $pdo->prepare("SELECT ruta FROM proyecto_archivos WHERE proyecto_id=?");
    $ars->execute([$id]);
    foreach ($ars->fetchAll(PDO::FETCH_COLUMN) as $ruta) {
        $fp = __DIR__ . '/' . $ruta;
        if (is_file($fp)) @unlink($fp);
    }
    $pdo->prepare("DELETE FROM proyecto_avances  WHERE proyecto_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM proyecto_archivos WHERE proyecto_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM proyecto_miembros WHERE proyecto_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM proyectos WHERE id=?")->execute([$id]);
    jsonOk();
    break;

case 'upload_proyecto_archivo':
    $pdo = db();
    ensureProyectosTables($pdo);
    $pid = (int)($_POST['proyecto_id'] ?? 0);
    if (!$pid) jsonErr('ID inválido');
    $cur = $pdo->prepare("SELECT * FROM proyectos WHERE id=?");
    $cur->execute([$pid]);
    $p = $cur->fetch();
    if (!$p) jsonErr('Proyecto no encontrado');
    if (!proyPuede($pdo, $p, $uid, $admin))
        jsonErr('Sin permiso para subir archivos a este proyecto');
    if (empty($_FILES['archivo']['tmp_name']) || !is_uploaded_file($_FILES['archivo']['tmp_name']))
        jsonErr('No se recibió archivo');
    if (($_FILES['archivo']['error'] ?? 1) !== UPLOAD_ERR_OK) jsonErr('Error en la subida');
    if ($_FILES['archivo']['size'] > 10 * 1024 * 1024) jsonErr('El archivo supera 10MB');
    $orig = $_FILES['archivo']['name'];
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $permitidos = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip'];
    if (!in_array($ext, $permitidos, true)) jsonErr('Formato no permitido: .' . $ext);
    $dir = __DIR__ . '/uploads/proyectos/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $fname = $pid . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $dir . $fname))
        jsonErr('Error al guardar archivo');
    $ruta = 'uploads/proyectos/' . $fname;
    $ins = $pdo->prepare("INSERT INTO proyecto_archivos
        (proyecto_id, usuario_id, nombre_original, ruta, tipo, tamano) VALUES (?,?,?,?,?,?)");
    $ins->execute([$pid, $uid, mb_substr($orig, 0, 255), $ruta,
        ($_FILES['archivo']['type'] ?? null), (int)$_FILES['archivo']['size']]);
    jsonOk(['id' => (int)$pdo->lastInsertId(), 'ruta' => $ruta, 'nombre' => $orig]);
    break;

case 'delete_proyecto_archivo':
    $pdo = db();
    ensureProyectosTables($pdo);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('ID inválido');
    $row = $pdo->prepare("SELECT ar.ruta, ar.usuario_id, p.agente_id, p.asignado_a
                          FROM proyecto_archivos ar JOIN proyectos p ON p.id=ar.proyecto_id
                          WHERE ar.id=?");
    $row->execute([$id]);
    $a = $row->fetch();
    if (!$a) jsonErr('Archivo no encontrado');
    if (!$admin && $a['usuario_id'] != $uid && $a['agente_id'] != $uid && $a['asignado_a'] != $uid)
        jsonErr('Sin permiso');
    $fp = __DIR__ . '/' . $a['ruta'];
    if (is_file($fp)) @unlink($fp);
    $pdo->prepare("DELETE FROM proyecto_archivos WHERE id=?")->execute([$id]);
    jsonOk();
    break;

case 'save_proyecto_orden':
    // Recibe lista de ids en su nuevo orden y guarda orden = posición
    $pdo = db();
    ensureProyectosTables($pdo);
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    if (!is_array($ids) || !$ids) jsonErr('Lista de orden inválida');
    $upd = $pdo->prepare("UPDATE proyectos SET orden=? WHERE id=?");
    $pos = 0;
    foreach ($ids as $pid) {
        $pid = (int)$pid;
        if ($pid > 0) { $upd->execute([$pos, $pid]); $pos++; }
    }
    jsonOk();
    break;

case 'set_foco_proyecto':
    $pdo = db();
    ensureProyectosTables($pdo);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonErr('ID inválido');
    $cur = $pdo->prepare("SELECT * FROM proyectos WHERE id=?");
    $cur->execute([$id]);
    $p = $cur->fetch();
    if (!$p) jsonErr('Proyecto no encontrado');
    if (!proyPuede($pdo, $p, $uid, $admin))
        jsonErr('Sin permiso');
    // Solo un proyecto puede ser el foco: limpia todos y marca este (o lo apaga si ya lo era)
    $pdo->exec("UPDATE proyectos SET es_foco=0");
    if (!$p['es_foco']) {
        $pdo->prepare("UPDATE proyectos SET es_foco=1 WHERE id=?")->execute([$id]);
    }
    jsonOk(['es_foco' => $p['es_foco'] ? 0 : 1]);
    break;

// ── DEFAULT ───────────────────────────────────────────────────
default:
    jsonErr('Acción no válida: ' . htmlspecialchars($action));
    break;

} // end switch
} catch (Exception $e) {
    jsonErr('Error del servidor: ' . $e->getMessage());
}

// ── PROYECTOS — autocreación de tablas (idempotente, barato) ─────────────────
function ensureProyectosTables(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS proyectos (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        titulo       VARCHAR(200) NOT NULL,
        descripcion  TEXT,
        estado       VARCHAR(30)  NOT NULL DEFAULT 'PLANIFICANDO',
        prioridad    VARCHAR(10)  NOT NULL DEFAULT 'MEDIA',
        progreso     INT          NOT NULL DEFAULT 0,
        asignado_a   INT          DEFAULT NULL,
        agente_id    INT          NOT NULL,
        fecha_inicio DATE         DEFAULT NULL,
        fecha_limite DATE         DEFAULT NULL,
        fecha_cierre DATE         DEFAULT NULL,
        created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_estado (estado),
        INDEX idx_asig (asignado_a)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS proyecto_avances (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        proyecto_id INT NOT NULL,
        usuario_id  INT NOT NULL,
        nota        TEXT NOT NULL,
        progreso    INT  DEFAULT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_proy (proyecto_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS proyecto_archivos (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        proyecto_id     INT NOT NULL,
        usuario_id      INT NOT NULL,
        nombre_original VARCHAR(255) NOT NULL,
        ruta            VARCHAR(500) NOT NULL,
        tipo            VARCHAR(100) DEFAULT NULL,
        tamano          INT          DEFAULT NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_proy (proyecto_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS proyecto_miembros (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        proyecto_id INT NOT NULL,
        usuario_id  INT NOT NULL,
        UNIQUE KEY uniq_pm (proyecto_id, usuario_id),
        INDEX idx_proy (proyecto_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $pcols = $pdo->query("SHOW COLUMNS FROM proyectos")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('orden', $pcols, true)) {
            $pdo->exec("ALTER TABLE proyectos ADD COLUMN orden INT NOT NULL DEFAULT 0");
            // Orden inicial estable = id (los proyectos nuevos quedan al final de su grupo)
            $pdo->exec("UPDATE proyectos SET orden = id WHERE orden = 0");
        }
        if (!in_array('es_foco', $pcols, true)) {
            $pdo->exec("ALTER TABLE proyectos ADD COLUMN es_foco TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (Exception $e) {}
    $done = true;
}

// ── PROYECTOS — permiso de acceso (admin, creador, responsable o miembro del equipo)
function proyPuede(PDO $pdo, array $p, int $uid, bool $admin): bool {
    if ($admin) return true;
    if ((int)$p['agente_id'] === $uid || (int)($p['asignado_a'] ?? 0) === $uid) return true;
    $st = $pdo->prepare("SELECT 1 FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=? LIMIT 1");
    $st->execute([(int)$p['id'], $uid]);
    return (bool)$st->fetchColumn();
}

// ── PROYECTOS — reemplaza el equipo (colaboradores) de un proyecto
function guardarEquipoProyecto(PDO $pdo, int $pid, $teamRaw): void {
    $ids = is_array($teamRaw) ? $teamRaw : json_decode((string)$teamRaw, true);
    if (!is_array($ids)) $ids = [];
    $pdo->prepare("DELETE FROM proyecto_miembros WHERE proyecto_id=?")->execute([$pid]);
    if ($ids) {
        $ins = $pdo->prepare("INSERT INTO proyecto_miembros (proyecto_id, usuario_id) VALUES (?,?)");
        $seen = [];
        foreach ($ids as $u) {
            $u = (int)$u;
            if ($u > 0 && empty($seen[$u])) { $seen[$u] = 1; try { $ins->execute([$pid, $u]); } catch (Exception $e) {} }
        }
    }
}

// ── HISTORIAL DE PLANES — helper ─────────────────────────────────────────────
function _historial_planes(PDO $pdo, int $mid, array $old, array $new, int $uid): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS historial_planes (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            miembro_id   INT NOT NULL,
            plan         VARCHAR(150),
            carrier      VARCHAR(100),
            tipo_plan    VARCHAR(100),
            subestado    VARCHAR(50),
            agente_id    INT,
            fecha_inicio DATE NOT NULL,
            fecha_fin    DATE DEFAULT NULL,
            motivo_fin   VARCHAR(50) DEFAULT NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mid (miembro_id),
            INDEX idx_fi  (fecha_inicio),
            INDEX idx_ff  (fecha_fin)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    $new_estado  = $new['estado']          ?? $old['estado']          ?? '';
    $old_estado  = $old['estado']          ?? '';
    $new_fecha   = trim($new['fecha_efectiva'] ?? '');
    $new_plan    = trim($new['plan']        ?? $old['plan']    ?? '');
    $new_carrier = trim($new['carrier']     ?? $old['carrier'] ?? '');
    $new_tipo    = trim($new['tipo_plan']   ?? $old['tipo_plan']  ?? '');
    $new_sub     = trim($new['subestado']   ?? $old['subestado'] ?? '');
    $new_age     = (int)($new['agente_id'] ?? $uid);

    if ($new_estado === 'ACTIVE' && !empty($new_fecha)) {
        // Cerrar registro anterior si tiene diferente fecha_inicio
        $pdo->prepare("UPDATE historial_planes
                       SET fecha_fin = DATE_SUB(?, INTERVAL 1 DAY), motivo_fin = 'CAMBIO DE PLAN'
                       WHERE miembro_id = ? AND fecha_fin IS NULL AND fecha_inicio != ?")
            ->execute([$new_fecha, $mid, $new_fecha]);
        // Insertar si no existe ese período
        $exists = $pdo->prepare("SELECT id FROM historial_planes WHERE miembro_id=? AND fecha_inicio=?");
        $exists->execute([$mid, $new_fecha]);
        if (!$exists->fetch()) {
            $pdo->prepare("INSERT INTO historial_planes
                           (miembro_id, plan, carrier, tipo_plan, subestado, agente_id, fecha_inicio)
                           VALUES (?,?,?,?,?,?,?)")
                ->execute([$mid, $new_plan, $new_carrier, $new_tipo, $new_sub, $new_age, $new_fecha]);
        } else {
            $pdo->prepare("UPDATE historial_planes
                           SET plan=?, carrier=?, tipo_plan=?, subestado=?, agente_id=?
                           WHERE miembro_id=? AND fecha_inicio=?")
                ->execute([$new_plan, $new_carrier, $new_tipo, $new_sub, $new_age, $mid, $new_fecha]);
        }
    } elseif (in_array($new_estado, ['CANCELED','DENIED','DISENROLLED','CERRADO']) && $old_estado === 'ACTIVE') {
        $fecha_fin = trim($new['fecha_cancelacion'] ?? '') ?: date('Y-m-d');
        $pdo->prepare("UPDATE historial_planes
                       SET fecha_fin=?, motivo_fin=?
                       WHERE miembro_id=? AND fecha_fin IS NULL")
            ->execute([$fecha_fin, strtoupper($new_estado), $mid]);
    }
}
