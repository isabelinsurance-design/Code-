<?php
ini_set('display_errors', 1); error_reporting(E_ALL);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'session_boot.php';
require_once 'config.php';
$chat_msgs = []; $chat_unread = 0;
$user=auth();$admin=isAdmin();$uid=$user['id'];$today=today();$pdo=db();

// ─── CUENTAS + REFERIDOS — AJAX HANDLER ──────────────────────────────────────
if (!empty($_POST['cue_ajax'])) {
    ob_start();
    header('Content-Type: application/json');
    $pdo_x = db(); $u_x = auth(); $uid_x = $u_x['id'];
    $act = $_POST['action'] ?? '';
    try { switch ($act) {
    case 'save_cuenta':
        $cid = (int)($_POST['cid'] ?? 0);
        $d = [
            'nombre'            => strtoupper(trim($_POST['nombre']          ?? '')),
            'tipo'              => trim($_POST['tipo']                        ?? 'OTRO'),
            'telefono'          => trim($_POST['telefono']                    ?? ''),
            'email'             => trim($_POST['email']                       ?? ''),
            'direccion'         => strtoupper(trim($_POST['direccion']        ?? '')),
            'ciudad'            => strtoupper(trim($_POST['ciudad']           ?? '')),
            'website'           => trim($_POST['website']                     ?? ''),
            'notas'             => trim($_POST['notas']                       ?? ''),
            'es_referente'      => (int)($_POST['es_referente']               ?? 0),
            'dias_recordatorio' => max(1, (int)($_POST['dias_recordatorio']   ?? 30)),
        ];
        ob_clean();
        if (!$d['nombre']) { echo json_encode(['ok'=>false,'error'=>'Nombre requerido']); exit; }
        if ($cid) {
            $s = implode(',', array_map(fn($k)=>"`$k`=?", array_keys($d)));
            $pdo_x->prepare("UPDATE cuentas SET $s WHERE id=?")->execute([...array_values($d), $cid]);
            ob_clean();
            echo json_encode(['ok'=>true,'id'=>$cid]);
        } else {
            $d['agente_id'] = $uid_x;
            $d['activo']    = 1;
            $cols = implode(',', array_map(fn($k)=>"`$k`", array_keys($d)));
            $phs  = implode(',', array_fill(0, count($d), '?'));
            $pdo_x->prepare("INSERT INTO cuentas ($cols) VALUES ($phs)")->execute(array_values($d));
            ob_clean(); echo json_encode(['ok'=>true,'id'=>$pdo_x->lastInsertId()]);
        }
        break;
    case 'delete_cuenta':
        $cid = (int)($_POST['cid'] ?? 0);
        ob_clean();
        if (!$cid) { echo json_encode(['ok'=>false]); exit; }
        foreach (['cuentas_interacciones','cuentas_contactos','referidos'] as $t)
            $pdo_x->prepare("DELETE FROM $t WHERE cuenta_id=?")->execute([$cid]);
        $pdo_x->prepare("UPDATE miembros SET referido_por=NULL WHERE referido_por=?")->execute([$cid]);
        $pdo_x->prepare("DELETE FROM cuentas WHERE id=?")->execute([$cid]);
        ob_clean();
        echo json_encode(['ok'=>true]);
        break;
    case 'save_contacto':
        $ctid = (int)($_POST['ctid'] ?? 0);
        $cid  = (int)($_POST['cid']  ?? 0);
        $d = [
            'cuenta_id'   => $cid,
            'nombre'      => strtoupper(trim($_POST['nombre']   ?? '')),
            'cargo'       => strtoupper(trim($_POST['cargo']    ?? '')),
            'telefono'    => trim($_POST['telefono']             ?? ''),
            'email'       => trim($_POST['email']                ?? ''),
            'notas'       => trim($_POST['notas']                ?? ''),
            'es_principal'=> (int)($_POST['es_principal']        ?? 0),
        ];
        ob_clean();
        if (!$d['nombre']) { echo json_encode(['ok'=>false,'error'=>'Nombre requerido']); exit; }
        if ($ctid) {
            $s = implode(',', array_map(fn($k)=>"`$k`=?", array_keys($d)));
            $pdo_x->prepare("UPDATE cuentas_contactos SET $s WHERE id=?")->execute([...array_values($d), $ctid]);
        } else {
            $cols = implode(',', array_map(fn($k)=>"`$k`", array_keys($d)));
            $phs  = implode(',', array_fill(0, count($d), '?'));
            $pdo_x->prepare("INSERT INTO cuentas_contactos ($cols) VALUES ($phs)")->execute(array_values($d));
        }
        ob_clean();
        echo json_encode(['ok'=>true]);
        break;
    case 'delete_contacto':
        $ctid = (int)($_POST['ctid'] ?? 0);
        $pdo_x->prepare("UPDATE referidos SET contacto_id=NULL WHERE contacto_id=?")->execute([$ctid]);
        $pdo_x->prepare("UPDATE cuentas_interacciones SET contacto_id=NULL WHERE contacto_id=?")->execute([$ctid]);
        $pdo_x->prepare("DELETE FROM cuentas_contactos WHERE id=?")->execute([$ctid]);
        ob_clean();
        echo json_encode(['ok'=>true]);
        break;
    case 'save_interaccion':
        $cid  = (int)($_POST['cid']    ?? 0);
        $ctid = (int)($_POST['ctid']   ?? 0) ?: null;
        $d = [
            'cuenta_id'         => $cid,
            'contacto_id'       => $ctid,
            'agente_id'         => $uid_x,
            'tipo'              => trim($_POST['tipo']               ?? 'LLAMADA'),
            'fecha'             => trim($_POST['fecha']              ?? date('Y-m-d')),
            'resultado'         => trim($_POST['resultado']          ?? ''),
            'descripcion'       => trim($_POST['descripcion']        ?? ''),
            'gasto_descripcion' => trim($_POST['gasto_descripcion']  ?? ''),
            'gasto_monto'       => (float)($_POST['gasto_monto']     ?? 0),
        ];
        $cols = implode(',', array_map(fn($k)=>"`$k`", array_keys($d)));
        $phs  = implode(',', array_fill(0, count($d), '?'));
        $pdo_x->prepare("INSERT INTO cuentas_interacciones ($cols) VALUES ($phs)")->execute(array_values($d));
        ob_clean();
        echo json_encode(['ok'=>true]);
        break;
    case 'delete_interaccion':
        $iid = (int)($_POST['iid'] ?? 0);
        $pdo_x->prepare("DELETE FROM cuentas_interacciones WHERE id=?")->execute([$iid]);
        ob_clean();
        echo json_encode(['ok'=>true]);
        break;
    case 'save_referido':
        $rid = (int)($_POST['rid'] ?? 0);
        $d = [
            'cuenta_id'   => (int)($_POST['cuenta_id']   ?? 0) ?: null,
            'contacto_id' => (int)($_POST['contacto_id'] ?? 0) ?: null,
            'agente_id'   => (int)($_POST['agente_id']   ?? $uid_x),
            'nombre'      => strtoupper(trim($_POST['nombre']    ?? '')),
            'apellido'    => strtoupper(trim($_POST['apellido']  ?? '')),
            'telefono'    => trim($_POST['telefono']              ?? ''),
            'dob'         => trim($_POST['dob']                   ?? '') ?: null,
            'idioma'      => trim($_POST['idioma']                ?? 'ESP'),
            'notas'       => trim($_POST['notas']                 ?? ''),
            'estado'      => trim($_POST['estado']                ?? 'NUEVO'),
        ];
        ob_clean();
        if (!$d['nombre']) { echo json_encode(['ok'=>false,'error'=>'Nombre requerido']); exit; }
        if ($rid) {
            $s = implode(',', array_map(fn($k)=>"`$k`=?", array_keys($d)));
            $pdo_x->prepare("UPDATE referidos SET $s WHERE id=?")->execute([...array_values($d), $rid]);
        } else {
            $cols = implode(',', array_map(fn($k)=>"`$k`", array_keys($d)));
            $phs  = implode(',', array_fill(0, count($d), '?'));
            $pdo_x->prepare("INSERT INTO referidos ($cols) VALUES ($phs)")->execute(array_values($d));
        }
        ob_clean();
        echo json_encode(['ok'=>true,'id'=>$rid ?: $pdo_x->lastInsertId()]);
        break;
    case 'update_estado_referido':
        $rid    = (int)($_POST['rid']    ?? 0);
        $estado = trim($_POST['estado']  ?? '');
        $pdo_x->prepare("UPDATE referidos SET estado=? WHERE id=?")->execute([$estado, $rid]);
        ob_clean();
        echo json_encode(['ok'=>true]);
        break;
    case 'convertir_referido':
        $rid = (int)($_POST['rid'] ?? 0);
        ob_clean();
        if (!$rid) { echo json_encode(['ok'=>false,'error'=>'ID requerido']); exit; }
        $ref = $pdo_x->prepare("SELECT * FROM referidos WHERE id=?");
        $ref->execute([$rid]); $r = $ref->fetch(PDO::FETCH_ASSOC);
        ob_clean();
        if (!$r) { echo json_encode(['ok'=>false,'error'=>'No encontrado']); exit; }
        $ins = $pdo_x->prepare("INSERT INTO miembros (nombre,apellido,telefono,dob,idioma,estado,agente_id,referido_por,created_by) VALUES (?,?,?,?,?,'PROSPECT',?,?,?)");
        $ins->execute([$r['nombre'],$r['apellido'],$r['telefono'],$r['dob'],$r['idioma'],$r['agente_id']?:$uid_x,$r['cuenta_id'],$uid_x]);
        $nuevo_id = $pdo_x->lastInsertId();
        $pdo_x->prepare("UPDATE referidos SET estado='EN PIPELINE', miembro_id=? WHERE id=?")->execute([$nuevo_id, $rid]);
        ob_clean();
        echo json_encode(['ok'=>true,'miembro_id'=>$nuevo_id]);
        break;
    case 'delete_referido':
        $rid = (int)($_POST['rid'] ?? 0);
        $pdo_x->prepare("DELETE FROM referidos WHERE id=?")->execute([$rid]);
        ob_clean();
        echo json_encode(['ok'=>true]);
        break;
    case 'get_cuenta':
        $cid = (int)($_POST['cid'] ?? 0);
        $q = $pdo_x->prepare("SELECT c.*, u.nombre as agente_nombre FROM cuentas c LEFT JOIN usuarios u ON c.agente_id=u.id WHERE c.id=?");
        $q->execute([$cid]); $cuenta = $q->fetch(PDO::FETCH_ASSOC);
        ob_clean();
        if (!$cuenta) { echo json_encode(['ok'=>false]); exit; }
        $qi = $pdo_x->prepare("SELECT ci.*, cc.nombre as contacto_nombre, u.nombre as agente_nombre, u.iniciales as agente_ini, u.color as agente_color FROM cuentas_interacciones ci LEFT JOIN cuentas_contactos cc ON ci.contacto_id=cc.id LEFT JOIN usuarios u ON ci.agente_id=u.id WHERE ci.cuenta_id=? ORDER BY ci.fecha DESC LIMIT 100");
        $qi->execute([$cid]); $ints = $qi->fetchAll(PDO::FETCH_ASSOC);
        $qc = $pdo_x->prepare("SELECT * FROM cuentas_contactos WHERE cuenta_id=? AND activo=1 ORDER BY es_principal DESC, nombre");
        $qc->execute([$cid]); $contactos = $qc->fetchAll(PDO::FETCH_ASSOC);
        $qr = $pdo_x->prepare("SELECT r.*, cc.nombre as contacto_nombre, u.nombre as agente_nombre FROM referidos r LEFT JOIN cuentas_contactos cc ON r.contacto_id=cc.id LEFT JOIN usuarios u ON r.agente_id=u.id WHERE r.cuenta_id=? ORDER BY r.created_at DESC");
        $qr->execute([$cid]); $refs = $qr->fetchAll(PDO::FETCH_ASSOC);
        $qm = $pdo_x->prepare("SELECT m.id, m.nombre, m.apellido, m.estado, m.carrier, m.telefono, m.fecha_efectiva, m.tipo_referido FROM miembros m WHERE m.referido_por=? ORDER BY m.apellido");
        $qm->execute([$cid]); $miembros_c = $qm->fetchAll(PDO::FETCH_ASSOC);
        ob_clean(); echo json_encode(['ok'=>true,'cuenta'=>$cuenta,'interacciones'=>$ints,'contactos'=>$contactos,'referidos'=>$refs,'miembros'=>$miembros_c]);
        break;
    case 'get_contactos_cuenta':
        $cid = (int)($_POST['cid'] ?? 0);
        $q = $pdo_x->prepare("SELECT id, nombre, cargo FROM cuentas_contactos WHERE cuenta_id=? AND activo=1 ORDER BY es_principal DESC, nombre");
        $q->execute([$cid]); $list = $q->fetchAll(PDO::FETCH_ASSOC);
        ob_clean();
        echo json_encode(['ok'=>true,'contactos'=>$list]);
        break;
    default: echo json_encode(['ok'=>false,'error'=>'Acción desconocida']);
    }} catch (Exception $e) { ob_clean(); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

// ─── ENTRENAMIENTO — AJAX HANDLER (marcar semana completada por agente) ──────
if (!empty($_POST['train_ajax'])) {
    header('Content-Type: application/json');
    $pdo_t = db(); $u_t = auth(); $uid_t = $u_t['id'];
    try {
        if (($_POST['action'] ?? '') === 'toggle_training') {
            $sem  = (int)($_POST['semana'] ?? 0);
            $done = (int)($_POST['done']   ?? 0) ? 1 : 0;
            $pdo_t->prepare("INSERT INTO entrenamiento_progreso (agente_id,semana,completado,completado_at)
                             VALUES (?,?,?,?)
                             ON DUPLICATE KEY UPDATE completado=VALUES(completado), completado_at=VALUES(completado_at)")
                  ->execute([$uid_t, $sem, $done, $done ? date('Y-m-d H:i:s') : null]);
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false,'error'=>'Acción desconocida']);
        }
    } catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

// ─── REUNIONES — AJAX HANDLER ────────────────────────────────────────────────
if (!empty($_POST['mtg_ajax'])) {
    header('Content-Type: application/json');
    $pdo_r = db(); $u_r = auth(); $uid_r = $u_r['id']; $act_r = $_POST['action'] ?? '';
    try { switch ($act_r) {
        case 'toggle_item':
            $iid = (int)($_POST['item_id'] ?? 0);
            $pdo_r->prepare("UPDATE reuniones_items SET done = 1 - done WHERE id=?")->execute([$iid]);
            echo json_encode(['ok'=>true]); break;
        case 'toggle_accion':
            $aid = (int)($_POST['accion_id'] ?? 0);
            $pdo_r->prepare("UPDATE reuniones_acciones SET done = 1 - done WHERE id=?")->execute([$aid]);
            echo json_encode(['ok'=>true]); break;
        case 'update_item_nota':
            $iid = (int)($_POST['item_id'] ?? 0); $nt = trim($_POST['nota'] ?? '');
            $pdo_r->prepare("UPDATE reuniones_items SET notas=? WHERE id=?")->execute([$nt, $iid]);
            echo json_encode(['ok'=>true]); break;
        case 'save_notas':
            $rid = (int)($_POST['reunion_id'] ?? 0); $nt = trim($_POST['notas'] ?? '');
            $pdo_r->prepare("UPDATE reuniones SET notas=? WHERE id=?")->execute([$nt, $rid]);
            echo json_encode(['ok'=>true]); break;
        case 'toggle_status':
            $rid = (int)($_POST['reunion_id'] ?? 0);
            $q = $pdo_r->prepare("SELECT status FROM reuniones WHERE id=?"); $q->execute([$rid]); $st = $q->fetchColumn();
            $new = ($st === 'done') ? 'upcoming' : 'done';
            $pdo_r->prepare("UPDATE reuniones SET status=? WHERE id=?")->execute([$new, $rid]);
            echo json_encode(['ok'=>true,'status'=>$new]); break;
        case 'add_item':
            $rid = (int)($_POST['reunion_id'] ?? 0); $sid = (int)($_POST['seccion_id'] ?? 0); $tx = trim($_POST['texto'] ?? '');
            if ($tx === '') { echo json_encode(['ok'=>false,'error'=>'Texto vacío']); break; }
            $pdo_r->prepare("INSERT INTO reuniones_items (reunion_id,seccion_id,texto,responsables,done,notas,orden) VALUES (?,?,?,'',0,'',999)")->execute([$rid,$sid,$tx]);
            echo json_encode(['ok'=>true]); break;
        case 'add_accion':
            $rid = (int)($_POST['reunion_id'] ?? 0); $tx = trim($_POST['texto'] ?? '');
            if ($tx === '') { echo json_encode(['ok'=>false,'error'=>'Texto vacío']); break; }
            $pdo_r->prepare("INSERT INTO reuniones_acciones (reunion_id,texto,responsable,done) VALUES (?,?,?,0)")->execute([$rid,$tx,$uid_r]);
            echo json_encode(['ok'=>true]); break;
        case 'add_seccion':
            $rid = (int)($_POST['reunion_id'] ?? 0); $nm = trim($_POST['nombre'] ?? '');
            if ($nm === '') { echo json_encode(['ok'=>false,'error'=>'Nombre vacío']); break; }
            $pdo_r->prepare("INSERT INTO reuniones_secciones (reunion_id,nombre,orden) VALUES (?,?,999)")->execute([$rid,$nm]);
            echo json_encode(['ok'=>true]); break;
        case 'new_meeting':
            $tt = trim($_POST['titulo'] ?? ''); $fe = trim($_POST['fecha'] ?? '');
            if ($tt === '' || $fe === '') { echo json_encode(['ok'=>false,'error'=>'Falta título o fecha']); break; }
            $tp = trim($_POST['tipo'] ?? 'semanal'); $rc = trim($_POST['recurrencia'] ?? '');
            $pdo_r->prepare("INSERT INTO reuniones (titulo,fecha,tipo,status,recurrencia,asistentes,notas,created_by) VALUES (?,?,?,'upcoming',?,'','',?)")
                  ->execute([$tt,$fe,$tp,$rc,$uid_r]);
            $nid = (int)$pdo_r->lastInsertId();
            $pdo_r->prepare("INSERT INTO reuniones_secciones (reunion_id,nombre,orden) VALUES (?, 'Agenda', 0)")->execute([$nid]);
            echo json_encode(['ok'=>true,'id'=>$nid]); break;
        case 'delete_meeting':
            $rid = (int)($_POST['reunion_id'] ?? 0);
            foreach (['reuniones_items','reuniones_secciones','reuniones_acciones'] as $t)
                $pdo_r->prepare("DELETE FROM $t WHERE reunion_id=?")->execute([$rid]);
            $pdo_r->prepare("DELETE FROM reuniones WHERE id=?")->execute([$rid]);
            echo json_encode(['ok'=>true]); break;
        default: echo json_encode(['ok'=>false,'error'=>'Acción desconocida']);
    }} catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

// ─── CAMPAÑAS — AJAX HANDLER ─────────────────────────────────────────────────
if (!empty($_POST['camp_ajax'])) {
    header('Content-Type: application/json');
    $pdo_c = db(); $u_c = auth(); $uid_c = $u_c['id']; $act_c = $_POST['action'] ?? '';
    try { switch ($act_c) {
        case 'save_campana':
            $id = (int)($_POST['id'] ?? 0);
            $d = [
                'nombre'       => strtoupper(trim($_POST['nombre'] ?? '')),
                'canal'        => strtoupper(trim($_POST['canal'] ?? 'FACEBOOK')),
                'descripcion'  => trim($_POST['descripcion'] ?? ''),
                'estado'       => trim($_POST['estado'] ?? 'ACTIVA'),
                'fecha_inicio' => trim($_POST['fecha_inicio'] ?? '') ?: null,
                'costo'        => (float)($_POST['costo'] ?? 0),
            ];
            if ($d['nombre'] === '') { echo json_encode(['ok'=>false,'error'=>'Nombre requerido']); break; }
            if ($id) {
                $s = implode(',', array_map(fn($k)=>"`$k`=?", array_keys($d)));
                $pdo_c->prepare("UPDATE campanas SET $s WHERE id=?")->execute([...array_values($d), $id]);
                echo json_encode(['ok'=>true,'id'=>$id]);
            } else {
                $d['agente_id'] = $uid_c;
                $cols = implode(',', array_map(fn($k)=>"`$k`", array_keys($d)));
                $phs  = implode(',', array_fill(0, count($d), '?'));
                $pdo_c->prepare("INSERT INTO campanas ($cols) VALUES ($phs)")->execute(array_values($d));
                echo json_encode(['ok'=>true,'id'=>$pdo_c->lastInsertId()]);
            }
            break;
        case 'delete_campana':
            $id = (int)($_POST['id'] ?? 0);
            $pdo_c->prepare("DELETE FROM campana_logs WHERE campana_id=?")->execute([$id]);
            $pdo_c->prepare("DELETE FROM campana_contactos WHERE campana_id=?")->execute([$id]);
            $pdo_c->prepare("DELETE FROM campanas WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true]); break;
        case 'save_contacto':
            $id  = (int)($_POST['id'] ?? 0);
            $cid = (int)($_POST['campana_id'] ?? 0);
            $d = [
                'nombre'   => strtoupper(trim($_POST['nombre'] ?? '')),
                'apellido' => strtoupper(trim($_POST['apellido'] ?? '')),
                'telefono' => trim($_POST['telefono'] ?? ''),
                'email'    => trim($_POST['email'] ?? ''),
                'notas'    => trim($_POST['notas'] ?? ''),
            ];
            if ($d['nombre'] === '') { echo json_encode(['ok'=>false,'error'=>'Nombre requerido']); break; }
            if ($id) {
                $s = implode(',', array_map(fn($k)=>"`$k`=?", array_keys($d)));
                $pdo_c->prepare("UPDATE campana_contactos SET $s WHERE id=?")->execute([...array_values($d), $id]);
                echo json_encode(['ok'=>true,'id'=>$id]);
            } else {
                $d['campana_id'] = $cid; $d['agente_id'] = $uid_c; $d['estado'] = 'ACTIVO';
                $cols = implode(',', array_map(fn($k)=>"`$k`", array_keys($d)));
                $phs  = implode(',', array_fill(0, count($d), '?'));
                $pdo_c->prepare("INSERT INTO campana_contactos ($cols) VALUES ($phs)")->execute(array_values($d));
                echo json_encode(['ok'=>true,'id'=>$pdo_c->lastInsertId()]);
            }
            break;
        case 'delete_contacto':
            $id = (int)($_POST['id'] ?? 0);
            $pdo_c->prepare("DELETE FROM campana_logs WHERE contacto_id=?")->execute([$id]);
            $pdo_c->prepare("DELETE FROM campana_contactos WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true]); break;
        case 'update_contacto_estado':
            $id = (int)($_POST['id'] ?? 0); $est = trim($_POST['estado'] ?? '');
            $pdo_c->prepare("UPDATE campana_contactos SET estado=? WHERE id=?")->execute([$est, $id]);
            echo json_encode(['ok'=>true]); break;
        case 'log_actividad':
            $cid  = (int)($_POST['campana_id'] ?? 0);
            $coid = (int)($_POST['contacto_id'] ?? 0);
            $canal = strtoupper(trim($_POST['canal'] ?? 'LLAMADA'));
            $res   = trim($_POST['resultado'] ?? '');
            $nt    = trim($_POST['notas'] ?? '');
            $nuevo_estado = trim($_POST['nuevo_estado'] ?? '');
            if (!$coid) { echo json_encode(['ok'=>false,'error'=>'Contacto requerido']); break; }
            $pdo_c->prepare("INSERT INTO campana_logs (campana_id,contacto_id,agente_id,canal,resultado,notas) VALUES (?,?,?,?,?,?)")
                  ->execute([$cid,$coid,$uid_c,$canal,$res,$nt]);
            if ($nuevo_estado !== '')
                $pdo_c->prepare("UPDATE campana_contactos SET estado=?, ultima_actividad=NOW() WHERE id=?")->execute([$nuevo_estado,$coid]);
            else
                $pdo_c->prepare("UPDATE campana_contactos SET ultima_actividad=NOW() WHERE id=?")->execute([$coid]);
            echo json_encode(['ok'=>true]); break;
        case 'promover_contacto':
            $id = (int)($_POST['id'] ?? 0);
            $q = $pdo_c->prepare("SELECT * FROM campana_contactos WHERE id=?"); $q->execute([$id]); $ct = $q->fetch(PDO::FETCH_ASSOC);
            if (!$ct) { echo json_encode(['ok'=>false,'error'=>'Contacto no encontrado']); break; }
            if (!empty($ct['promovido'])) { echo json_encode(['ok'=>false,'error'=>'Ya está en el pipeline']); break; }
            $cn = $pdo_c->prepare("SELECT nombre,canal FROM campanas WHERE id=?"); $cn->execute([$ct['campana_id']]); $camp = $cn->fetch(PDO::FETCH_ASSOC);
            $fuente_map = ['FACEBOOK'=>'FACEBOOK LEAD','INSTAGRAM'=>'FACEBOOK LEAD','EVENTO'=>'EVENTO COMUNIDAD','REFERIDO'=>'REFERIDO MIEMBRO','GOOGLE'=>'GOOGLE'];
            $fuente = $fuente_map[$camp['canal'] ?? ''] ?? 'OTRO';
            $extras = 'Promovido de campaña: ' . ($camp['nombre'] ?? '');
            $ins = $pdo_c->prepare("INSERT INTO miembros (nombre,apellido,telefono,email,estado,agente_id,fuente,extras,created_by) VALUES (?,?,?,?,'PROSPECT',?,?,?,?)");
            $ins->execute([$ct['nombre'], $ct['apellido'] ?: '', $ct['telefono'], $ct['email'], $ct['agente_id'] ?: $uid_c, $fuente, $extras, $uid_c]);
            $nid = (int)$pdo_c->lastInsertId();
            $pdo_c->prepare("UPDATE campana_contactos SET miembro_id=?, promovido=1, estado='EN PIPELINE' WHERE id=?")->execute([$nid, $id]);
            echo json_encode(['ok'=>true,'miembro_id'=>$nid]); break;
        default: echo json_encode(['ok'=>false,'error'=>'Acción desconocida']);
    }} catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

// ─── PLANEACIÓN — AJAX HANDLER ───────────────────────────────────────────────
if (!empty($_POST['plan_ajax'])) {
    header('Content-Type: application/json');
    $pdo_p = db(); $u_p = auth(); $act_p = $_POST['action'] ?? '';
    try { switch ($act_p) {
        case 'update_meta':
            $id = (int)($_POST['id'] ?? 0); $pr = max(0,min(100,(int)($_POST['progreso'] ?? 0)));
            $pdo_p->prepare("UPDATE plan_metas SET progreso=? WHERE id=?")->execute([$pr,$id]);
            echo json_encode(['ok'=>true]); break;
        case 'toggle_roadmap':
            $k = trim($_POST['item_key'] ?? '');
            if ($k==='') { echo json_encode(['ok'=>false,'error'=>'key vacío']); break; }
            $pdo_p->prepare("INSERT INTO plan_checks (item_key,done,done_at) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE done=1-done, done_at=NOW()")->execute([$k]);
            echo json_encode(['ok'=>true]); break;
        case 'save_nota':
            $hz = trim($_POST['horizonte'] ?? ''); $ct = trim($_POST['contenido'] ?? '');
            if ($hz==='') { echo json_encode(['ok'=>false,'error'=>'horizonte vacío']); break; }
            $pdo_p->prepare("INSERT INTO plan_notas (horizonte,contenido) VALUES (?,?) ON DUPLICATE KEY UPDATE contenido=VALUES(contenido)")->execute([$hz,$ct]);
            echo json_encode(['ok'=>true]); break;
        default: echo json_encode(['ok'=>false,'error'=>'Acción desconocida']);
    }} catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

// ─── ROLES — AJAX HANDLER ────────────────────────────────────────────────────
if (!empty($_POST['roles_ajax'])) {
    header('Content-Type: application/json');
    $pdo_rl = db(); auth();
    try {
        if (($_POST['action'] ?? '') === 'assign_role') {
            $k = trim($_POST['role_key'] ?? ''); $aid = (int)($_POST['agente_id'] ?? 0);
            if ($k === '') { echo json_encode(['ok'=>false,'error'=>'role_key vacío']); exit; }
            if ($aid > 0)
                $pdo_rl->prepare("INSERT INTO roles_asignacion (role_key,agente_id) VALUES (?,?) ON DUPLICATE KEY UPDATE agente_id=VALUES(agente_id)")->execute([$k,$aid]);
            else
                $pdo_rl->prepare("DELETE FROM roles_asignacion WHERE role_key=?")->execute([$k]);
            echo json_encode(['ok'=>true]);
        } else { echo json_encode(['ok'=>false,'error'=>'Acción desconocida']); }
    } catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

// --- CREAR TABLA FALTANTE PARA LOS CHECKLISTS DE EFECTIVOS ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS efectivos_checks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        miembro_id INT NOT NULL,
        tipo VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_check (miembro_id, tipo)
    )");
} catch (Exception $e) {}
// -------------------------------------------------------------

// --- NUEVO: CREAR TABLA LLAMADAS PROSPECTOS Y CONTAR LLAMADAS ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS llamadas_prospectos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agente_id INT,
        miembro_id INT NULL,
        nombre_libre VARCHAR(255),
        telefono VARCHAR(50),
        contesto TINYINT(1) DEFAULT 0,
        resultado VARCHAR(100) DEFAULT NULL,
        notas TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $cols = $pdo->query("SHOW COLUMNS FROM llamadas_prospectos")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('resultado', $cols)) {
        $pdo->exec("ALTER TABLE llamadas_prospectos ADD COLUMN resultado VARCHAR(100) NULL AFTER contesto");
    }
} catch (Exception $e) {}

// ─── TABLAS: CUENTAS + REFERIDOS ─────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cuentas (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        nombre            VARCHAR(255) NOT NULL,
        tipo              VARCHAR(50)  DEFAULT 'OTRO',
        telefono          VARCHAR(50),
        email             VARCHAR(150),
        direccion         TEXT,
        ciudad            VARCHAR(100),
        website           VARCHAR(255),
        notas             TEXT,
        es_referente      TINYINT(1)   DEFAULT 0,
        dias_recordatorio INT          DEFAULT 30,
        agente_id         INT,
        activo            TINYINT(1)   DEFAULT 1,
        created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cuentas_contactos (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        cuenta_id    INT NOT NULL,
        nombre       VARCHAR(255) NOT NULL,
        cargo        VARCHAR(100),
        telefono     VARCHAR(50),
        email        VARCHAR(150),
        notas        TEXT,
        es_principal TINYINT(1)  DEFAULT 0,
        activo       TINYINT(1)  DEFAULT 1,
        created_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cuentas_interacciones (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        cuenta_id         INT NOT NULL,
        contacto_id       INT,
        agente_id         INT,
        tipo              VARCHAR(30)  DEFAULT 'LLAMADA',
        fecha             DATE         NOT NULL,
        resultado         VARCHAR(100),
        descripcion       TEXT,
        gasto_descripcion VARCHAR(255),
        gasto_monto       DECIMAL(8,2) DEFAULT 0.00,
        created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS referidos (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        cuenta_id   INT,
        contacto_id INT,
        agente_id   INT,
        nombre      VARCHAR(150) NOT NULL,
        apellido    VARCHAR(150),
        telefono    VARCHAR(50),
        dob         DATE,
        idioma      VARCHAR(20)  DEFAULT 'ESP',
        notas       TEXT,
        estado      VARCHAR(30)  DEFAULT 'NUEVO',
        miembro_id  INT          DEFAULT NULL,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $col_rp = $pdo->query("SHOW COLUMNS FROM miembros LIKE 'referido_por'")->fetch();
    if ($col_rp && stripos($col_rp['Type'], 'varchar') !== false) {
        $pdo->exec("ALTER TABLE miembros MODIFY referido_por INT NULL");
    }
} catch (Exception $e) {}
// ─── COLUMNAS EXTRA: SALES ALLEGATION + FOTO PERFIL ──────────────────────────
try {
    $col_sa = $pdo->query("SHOW COLUMNS FROM miembros LIKE 'sales_allegation'")->fetch();
    if (!$col_sa) { $pdo->exec("ALTER TABLE miembros ADD COLUMN sales_allegation TINYINT(1) DEFAULT 0"); }
    $col_fp = $pdo->query("SHOW COLUMNS FROM miembros LIKE 'foto_perfil'")->fetch();
    if (!$col_fp) { $pdo->exec("ALTER TABLE miembros ADD COLUMN foto_perfil VARCHAR(500) DEFAULT NULL"); }
    $col_pa = $pdo->query("SHOW COLUMNS FROM miembros LIKE 'pareja_id'")->fetch();
    if (!$col_pa) { $pdo->exec("ALTER TABLE miembros ADD COLUMN pareja_id INT NULL"); }
} catch (Exception $e) {}
// ─── TABLA GASTOS (expense report) ───────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS gastos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fecha DATE NOT NULL,
        categoria VARCHAR(50) NOT NULL,
        tipo VARCHAR(100) DEFAULT NULL,
        descripcion VARCHAR(500) NOT NULL,
        vendedor VARCHAR(200) DEFAULT NULL,
        monto DECIMAL(10,2) NOT NULL DEFAULT 0,
        metodo_pago ENUM('CASH','CARD','CHECK','ZELLE','OTHER') DEFAULT 'CARD',
        enviado_por INT NOT NULL,
        recibo TINYINT(1) DEFAULT 0,
        estado ENUM('PENDIENTE','APROBADO','RECHAZADO') DEFAULT 'PENDIENTE',
        notas TEXT DEFAULT NULL,
        aprobado_por INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_fecha (fecha),
        KEY idx_categoria (categoria),
        KEY idx_estado (estado)
    )");
    // Columnas extra: foto de factura + reembolso a empleado
    foreach ([
        'recibo_foto'    => "ADD COLUMN recibo_foto VARCHAR(500) NULL",
        'reembolsar_a'   => "ADD COLUMN reembolsar_a INT NULL",
        'reembolsado'    => "ADD COLUMN reembolsado TINYINT(1) DEFAULT 0",
        'reembolsado_at' => "ADD COLUMN reembolsado_at TIMESTAMP NULL",
    ] as $gcol => $gddl) {
        $ex = $pdo->query("SHOW COLUMNS FROM gastos LIKE '$gcol'")->fetch();
        if (!$ex) $pdo->exec("ALTER TABLE gastos $gddl");
    }
} catch (Exception $e) {}
// ─── TABLA ENTRENAMIENTO (progreso de la academia por agente) ────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS entrenamiento_progreso (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agente_id INT NOT NULL,
        semana INT NOT NULL,
        completado TINYINT(1) DEFAULT 0,
        completado_at TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY uk_ag_sem (agente_id, semana)
    )");
} catch (Exception $e) {}
// ─── TABLAS REUNIONES (meetings: agenda, items, acciones) ────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reuniones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ext_id VARCHAR(20) DEFAULT NULL,
        titulo VARCHAR(255) NOT NULL,
        fecha DATE,
        hora VARCHAR(20) DEFAULT NULL,
        tipo VARCHAR(30) DEFAULT 'semanal',
        status VARCHAR(20) DEFAULT 'upcoming',
        recurrencia VARCHAR(120) DEFAULT NULL,
        asistentes VARCHAR(255) DEFAULT NULL,
        notas TEXT,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reuniones_secciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reunion_id INT NOT NULL,
        nombre VARCHAR(255) NOT NULL,
        orden INT DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reuniones_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reunion_id INT NOT NULL,
        seccion_id INT NOT NULL,
        texto VARCHAR(500) NOT NULL,
        responsables VARCHAR(255) DEFAULT NULL,
        done TINYINT(1) DEFAULT 0,
        notas VARCHAR(500) DEFAULT NULL,
        orden INT DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reuniones_acciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reunion_id INT NOT NULL,
        texto VARCHAR(500) NOT NULL,
        responsable INT DEFAULT NULL,
        done TINYINT(1) DEFAULT 0
    )");
    // Sembrar reuniones históricas UNA sola vez (datos del PG system)
    $mtg_cnt = (int)$pdo->query("SELECT COUNT(*) FROM reuniones")->fetchColumn();
    if ($mtg_cnt === 0) {
        $pgmap = [];
        foreach ([1=>'ISABEL',2=>'SAMIA',3=>'ARLETTE',4=>'SKARLETH'] as $pgid=>$nm) {
            $rid = $pdo->query("SELECT id FROM usuarios WHERE nombre LIKE ".$pdo->quote($nm.'%')." LIMIT 1")->fetchColumn();
            if ($rid) $pgmap[$pgid] = (int)$rid;
        }
        $as_arr = function($x){ if ($x === null) return []; return is_array($x) ? $x : [$x]; };
        $mapids = function($arr) use ($pgmap, $as_arr){ $o=[]; foreach($as_arr($arr) as $v){ if(isset($pgmap[(int)$v])) $o[]=$pgmap[(int)$v]; } return implode(',', $o); };
        $mtg_seed = json_decode(base64_decode('W3siaWQiOiJyMSIsImZlY2hhIjoiMjAyNi0wNC0yNSIsInRpdHVsbyI6IlBsYW5lYWNpw7NuIFNlbWFuYWwg4oCUIFPDoWIgMjUgQWJyIiwidGlwbyI6InNlbWFuYWwiLCJzdGF0dXMiOiJkb25lIiwiYXNpc3RlbnRlcyI6WzEsMiwzLDRdLCJyZWN1cnJlbmNpYSI6IlNlbWFuYWwg4oCUIFPDoWJhZG9zIiwibm90YXMiOiJCbG9xdWVvIGRlIEFybGV0dGUgY29uIGZhcm1hY2lhIGVzY2FsYWRvLiBTYW1pIGVuIGJ1ZW4gcml0bW8uIENvbnRyYXRvIERyLiBNYXJ0w61uZXogZmlybWFkby4iLCJhY2Npb25lcyI6W3siaWQiOiJhMSIsInR4dCI6IkVzY2FsYXIgYmxvcXVlbyBmYXJtYWNpYSIsInJlc3AiOjEsImRvbmUiOnRydWV9LHsiaWQiOiJhMiIsInR4dCI6IlNlZ3VpbWllbnRvIGNvbnRyYXRvcyBTa2FybGV0aCIsInJlc3AiOjQsImRvbmUiOnRydWV9LHsiaWQiOiJhMyIsInR4dCI6IlNhbWk6IGNvbXBsZXRhciBpbnNjcmlwY2lvbmVzIGRlbCBwb3J0YWwiLCJyZXNwIjoyLCJkb25lIjp0cnVlfV0sInNlY2Npb25lcyI6W3sibm9tYnJlIjoiTWV0YXMgZGUgbGEgU2VtYW5hIiwiaXRlbXMiOlt7ImlkIjoiaTEiLCJ0ZXh0byI6IlRpY2tldHMgZGVsIGx1bmVzIOKGkiBjZXJyYWRvcyBlbCBzw6FiYWRvIiwicmVzcCI6WzIsMyw0XSwiZG9uZSI6dHJ1ZSwibm90YXMiOiJUb2RvcyBjZXJyYWRvcyJ9LHsiaWQiOiJpMiIsInRleHRvIjoiVmllcm5lczogcmV2aXNpw7NuIGRlIHRpY2tldHMiLCJyZXNwIjpbMSwyLDMsNF0sImRvbmUiOnRydWUsIm5vdGFzIjoiIn0seyJpZCI6ImkzIiwidGV4dG8iOiJDaXRhcyBwb3IgbGxhbWFkYXMgY29tcGxldGFzIiwicmVzcCI6WzIsMyw0XSwiZG9uZSI6ZmFsc2UsIm5vdGFzIjoiUXVlZGFuIDIifSx7ImlkIjoiaTQiLCJ0ZXh0byI6IkxsYW1hZGEgZGlhcmlhIGNvbiBJc2FiZWwiLCJyZXNwIjpbMSwyLDMsNF0sImRvbmUiOnRydWUsIm5vdGFzIjoiQ3VtcGxpZG8gdG9kb3MgbG9zIGTDrWFzIn0seyJpZCI6Imk1IiwidGV4dG8iOiJQcm95ZWN0b3M6IHJlcG9ydGFyIGN1bXBsaWRvcyArIHByw7N4aW1vcyIsInJlc3AiOlsyLDMsNF0sImRvbmUiOnRydWUsIm5vdGFzIjoiIn0seyJpZCI6Imk2IiwidGV4dG8iOiJOZWNlc2lkYWRlcyBkZSBsYSBvZmljaW5hIOKAlCBBcmxldHRlIiwicmVzcCI6WzNdLCJkb25lIjpmYWxzZSwibm90YXMiOiJQZW5kaWVudGUifV19LHsibm9tYnJlIjoiUmV0ZW5jacOzbiIsIml0ZW1zIjpbeyJpZCI6Imk3IiwidGV4dG8iOiJQcm9ibGVtYXMgY3LDrXRpY29zIGNvbiBtaWVtYnJvcyIsInJlc3AiOlsxLDIsMyw0XSwiZG9uZSI6dHJ1ZSwibm90YXMiOiIyIGNhc29zIHJlc3VlbHRvcyJ9XX0seyJub21icmUiOiJWZW50YXMiLCJpdGVtcyI6W3siaWQiOiJpOCIsInRleHRvIjoiTWFya2V0aW5nIGRlIHJlZGVzIiwicmVzcCI6WzEsMiwzLDRdLCJkb25lIjp0cnVlLCJub3RhcyI6IiJ9LHsiaWQiOiJpOSIsInRleHRvIjoiUHJpbWVyYSBsbGFtYWRhIGEgbnVldm9zIGxlYWRzIiwicmVzcCI6WzIsMyw0XSwiZG9uZSI6dHJ1ZSwibm90YXMiOiJTYW1pOiA4IMK3IFNrYXJsZXRoOiAxMiJ9LHsiaWQiOiJpMTAiLCJ0ZXh0byI6IlNlZ3VpbWllbnRvIGEgbGVhZHMgY2FsaWVudGVzIiwicmVzcCI6WzIsMyw0XSwiZG9uZSI6dHJ1ZSwibm90YXMiOiIzIGluc2NyaXBjaW9uZXMgY2VycmFkYXMifV19XX0seyJpZCI6InIyIiwiZmVjaGEiOiIyMDI2LTA0LTExIiwidGl0dWxvIjoiQWdlbmRhIFNlbWFuYWwg4oCUIFPDoWIgMTEgQWJyIiwidGlwbyI6InNlbWFuYWwiLCJzdGF0dXMiOiJkb25lIiwiYXNpc3RlbnRlcyI6WzEsMiwzLDRdLCJyZWN1cnJlbmNpYSI6IlNlbWFuYWwg4oCUIFPDoWJhZG9zIiwibm90YXMiOiJQcm90b2NvbG8gY2hlY2staW5zIDN4IGTDrWEgZGVmaW5pZG8uIiwiYWNjaW9uZXMiOlt7ImlkIjoiYTQiLCJ0eHQiOiJJc2FiZWw6IGF2YW56YXIgZW4gY29udHJhdG9zIiwicmVzcCI6MSwiZG9uZSI6ZmFsc2V9LHsiaWQiOiJhNSIsInR4dCI6IkJhc2UgZGUgZGF0b3MgZGUgcGxhbmVzIiwicmVzcCI6MiwiZG9uZSI6ZmFsc2V9XSwic2VjY2lvbmVzIjpbeyJub21icmUiOiJBY3RpdmlkYWRlcyIsIml0ZW1zIjpbeyJpZCI6ImoxIiwidGV4dG8iOiJDb250cmF0b3MgKElzYWJlbCkiLCJyZXNwIjpbMV0sImRvbmUiOmZhbHNlLCJub3RhcyI6IkVuIHByb2Nlc28ifSx7ImlkIjoiajIiLCJ0ZXh0byI6IkhvcmFyaW9zIGRlbCBlcXVpcG8iLCJyZXNwIjpbMSwyLDMsNF0sImRvbmUiOnRydWUsIm5vdGFzIjoiIn1dfSx7Im5vbWJyZSI6Ik1ldGFzIiwiaXRlbXMiOlt7ImlkIjoiajMiLCJ0ZXh0byI6IlRpY2tldHMgZGVsIGx1bmVzIOKGkiBjZXJyYWRvcyBlbCBzw6FiYWRvIiwicmVzcCI6WzIsMyw0XSwiZG9uZSI6ZmFsc2UsIm5vdGFzIjoiNjAlIn0seyJpZCI6Imo0IiwidGV4dG8iOiJWaWVybmVzOiByZXZpc2nDs24gZGUgdGlja2V0cyIsInJlc3AiOlsxLDIsMyw0XSwiZG9uZSI6dHJ1ZSwibm90YXMiOiIifSx7ImlkIjoiajUiLCJ0ZXh0byI6IjE6MSBzZW1hbmFsIGNvbiBjYWRhIG1pZW1icm8iLCJyZXNwIjpbMV0sImRvbmUiOmZhbHNlLCJub3RhcyI6IkZhbHRhIFNrYXJsZXRoIn1dfV19LHsiaWQiOiJyMyIsImZlY2hhIjoiMjAyNi0wMy0yMSIsInRpdHVsbyI6IlJldW5pw7NuIGRlIFByb2Nlc29zIHkgUHJvdG9jb2xvcyIsInRpcG8iOiJvcGVyYWNpb25lcyIsInN0YXR1cyI6ImRvbmUiLCJhc2lzdGVudGVzIjpbMSwyLDMsNF0sInJlY3VycmVuY2lhIjoiTmluZ3VuYSIsIm5vdGFzIjoiSG90cyBQcm9zcGVjdCwgdGlja2V0cywgbMOtbmVhcywgZGlzdHJpYnVjacOzbiBkZSBsaXN0YXMgZGUgcHJvc3BlY3Rvcy4iLCJhY2Npb25lcyI6W3siaWQiOiJhNiIsInR4dCI6IlRvZGFzOiBhY3R1YWxpemFyIEhvdHMgUHJvc3BlY3QiLCJyZXNwIjoyLCJkb25lIjpmYWxzZX0seyJpZCI6ImE3IiwidHh0IjoiUmV2aXNhciBuw7ptZXJvcyBkZSBsw61uZWEgYWwgODE4IiwicmVzcCI6MSwiZG9uZSI6dHJ1ZX0seyJpZCI6ImE4IiwidHh0IjoiQXNpZ25hciBsaXN0YXMgZGUgcHJvc3BlY3RvcyBjYWRhIHPDoWJhZG8iLCJyZXNwIjoxLCJkb25lIjp0cnVlfV0sInNlY2Npb25lcyI6W3sibm9tYnJlIjoiQWN1ZXJkb3MgZGVsIEVxdWlwbyIsIml0ZW1zIjpbeyJpZCI6ImsxIiwidGV4dG8iOiJIb3RzIFByb3NwZWN0OiBhZ3JlZ2FyIHJlYWdlbmRhZG8sIHF1w6kgcGFzw7MgeSBub3RhcyIsInJlc3AiOlsxLDIsMyw0XSwiZG9uZSI6ZmFsc2UsIm5vdGFzIjoiNzAlIn0seyJpZCI6ImsyIiwidGV4dG8iOiJBbCBoYWNlciBjaXRhczogVE9EQSBsYSBpbmZvIGVuIHRpY2tldCIsInJlc3AiOlsxLDIsMyw0XSwiZG9uZSI6ZmFsc2UsIm5vdGFzIjoiODAlIn0seyJpZCI6ImszIiwidGV4dG8iOiJOw7ptZXJvcyBkZSBsw61uZWEgYWwgODE4IiwicmVzcCI6WzFdLCJkb25lIjp0cnVlLCJub3RhcyI6IlZlcmlmaWNhZG8ifSx7ImlkIjoiazQiLCJ0ZXh0byI6IkxsYW1hZGFzIGEgbWllbWJyb3M6IHNpZW1wcmUgZGVsIDMyMy00MDItNDE0NSIsInJlc3AiOlsyLDMsNF0sImRvbmUiOmZhbHNlLCJub3RhcyI6Ijg1JSJ9LHsiaWQiOiJrNSIsInRleHRvIjoiVG9kYXMgc2FiZXIgZW4gcXXDqSBwbGFuZXMgc2UgaGFjZSBlbCBIUkEiLCJyZXNwIjpbMSwyLDMsNF0sImRvbmUiOmZhbHNlLCJub3RhcyI6IjYwJSJ9XX0seyJub21icmUiOiJQUk9UT0NPTE8g4oCUIERpc3RyaWJ1Y2nDs24gZGUgTGlzdGFzIiwiaXRlbXMiOlt7ImlkIjoiazYiLCJ0ZXh0byI6IkNhZGEgc8OhYmFkbzogSXNhYmVsIGFzaWduYSBsaXN0YXMgZGUgcHJvc3BlY3RvcyIsInJlc3AiOlsxXSwiZG9uZSI6dHJ1ZSwibm90YXMiOiJJbXBsZW1lbnRhZG8ifSx7ImlkIjoiazciLCJ0ZXh0byI6IlRvZGFzIHNhYmVyIHF1w6kgbGlzdGEgdHJhYmFqYSBjYWRhIHVuYSIsInJlc3AiOlsxLDIsMyw0XSwiZG9uZSI6ZmFsc2UsIm5vdGFzIjoiODAlIn1dfV19LHsiaWQiOiJyNCIsImZlY2hhIjoiMjAyNi0wMy0wMiIsInRpdHVsbyI6IlJlc3VtZW4gZGUgTGxhbWFkYSBTZW1hbmFsIiwidGlwbyI6InNlZ3VpbWllbnRvIiwic3RhdHVzIjoiZG9uZSIsImFzaXN0ZW50ZXMiOlsxLDIsMyw0XSwicmVjdXJyZW5jaWEiOiJTZW1hbmFsIiwibm90YXMiOiJSZXNwb25zYWJsZSBkZSBsbGFtYWRhcyAzMC82MC85MCBkw61hcyBhc2lnbmFkby4iLCJhY2Npb25lcyI6W3siaWQiOiJhOSIsInR4dCI6IkFzaWduYXIgcmVzcG9uc2FibGUgbGxhbWFkYXMgMzAvNjAvOTAgZMOtYXMiLCJyZXNwIjoxLCJkb25lIjp0cnVlfSx7ImlkIjoiYTEwIiwidHh0IjoiTGxhbWFyIGEgZWZlY3Rpdm9zIGRlIG1hcnpvIiwicmVzcCI6MiwiZG9uZSI6dHJ1ZX1dLCJzZWNjaW9uZXMiOlt7Im5vbWJyZSI6IkFjdWVyZG9zIiwiaXRlbXMiOlt7ImlkIjoibDEiLCJ0ZXh0byI6IlJlc3BvbnNhYmxlIGRlIGxsYW1hZGFzIDMwLzYwLzkwIGTDrWFzIiwicmVzcCI6WzFdLCJkb25lIjp0cnVlLCJub3RhcyI6IkFzaWduYWRvIn0seyJpZCI6ImwyIiwidGV4dG8iOiJNYXJ0ZXM6IElzYWJlbCBkZXNkZSBjYXNhIOKAlCB0cmFuc2ZlcmlyIGxsYW1hZGFzIiwicmVzcCI6WzEsMiwzLDRdLCJkb25lIjp0cnVlLCJub3RhcyI6IkFjdGl2byJ9LHsiaWQiOiJsMyIsInRleHRvIjoiTGxhbWFyIGEgZWZlY3Rpdm9zIGRlIG1hcnpvIiwicmVzcCI6WzIsMyw0XSwiZG9uZSI6dHJ1ZSwibm90YXMiOiJDb21wbGV0YWRvIn1dfV19LHsiaWQiOiJyNSIsImZlY2hhIjoiMjAyNi0wMi0yMSIsInRpdHVsbyI6IlJldW5pw7NuIGRlIE9wZXJhY2lvbmVzIOKAlCBGZWIgMjEiLCJ0aXBvIjoib3BlcmFjaW9uZXMiLCJzdGF0dXMiOiJkb25lIiwiYXNpc3RlbnRlcyI6WzEsMiwzLDRdLCJyZWN1cnJlbmNpYSI6Ik5pbmd1bmEiLCJub3RhcyI6IkltcGxlbWVudGFjacOzbiBkZSBOZXh0aXZhLiBQcm90b2NvbG8gZGUgbWllbWJyb3MgbW9sZXN0b3MgZXN0YWJsZWNpZG8uIiwiYWNjaW9uZXMiOlt7ImlkIjoiYTExIiwidHh0IjoiQ29uZmlndXJhciBsw61uZWEgTmV4dGl2YSIsInJlc3AiOjEsImRvbmUiOnRydWV9LHsiaWQiOiJhMTIiLCJ0eHQiOiJQcm90b2NvbG8gbWllbWJyb3MgbW9sZXN0b3M6IHRyYW5zZmVyZW5jaWEgaW5tZWRpYXRhIGEgSXNhYmVsIiwicmVzcCI6MSwiZG9uZSI6dHJ1ZX0seyJpZCI6ImExMyIsInR4dCI6IkxsYW1hZGFzIHNlZ3VpbWllbnRvIDMwLzYwLzkwIGTDrWFzIiwicmVzcCI6MiwiZG9uZSI6ZmFsc2V9XSwic2VjY2lvbmVzIjpbeyJub21icmUiOiIxLiBOZXh0aXZhIiwiaXRlbXMiOlt7ImlkIjoibjEiLCJ0ZXh0byI6IkltcGxlbWVudGFjacOzbiBudWV2YSBsw61uZWEgTmV4dGl2YSIsInJlc3AiOlsxXSwiZG9uZSI6dHJ1ZSwibm90YXMiOiIifSx7ImlkIjoibjIiLCJ0ZXh0byI6IkNpZXJyZSBkZSBzZXNpb25lcyBhY3RpdmFzIiwicmVzcCI6WzFdLCJkb25lIjp0cnVlLCJub3RhcyI6IiJ9LHsiaWQiOiJuMyIsInRleHRvIjoiUmVzdHJpY2Npw7NuIGRlIGFjY2VzbyIsInJlc3AiOlsxXSwiZG9uZSI6dHJ1ZSwibm90YXMiOiIifV19LHsibm9tYnJlIjoiMi4gUHJvdG9jb2xvcyIsIml0ZW1zIjpbeyJpZCI6Im40IiwidGV4dG8iOiJQUk9UT0NPTE86IG1pZW1icm9zIG1vbGVzdG9zIOKGkiB0cmFuc2ZlcmVuY2lhIElOTUVESUFUQSBhIElzYWJlbCIsInJlc3AiOlsxLDIsMyw0XSwiZG9uZSI6dHJ1ZSwibm90YXMiOiJBY3Rpdm8ifSx7ImlkIjoibjUiLCJ0ZXh0byI6IkTDrWEgZmlqbyBzZW1hbmFsIHBhcmEgY2l0YXMg4oCUIE1hcnRlcyIsInJlc3AiOlsxXSwiZG9uZSI6dHJ1ZSwibm90YXMiOiIifSx7ImlkIjoibjYiLCJ0ZXh0byI6IkxsYW1hZGFzIHNlZ3VpbWllbnRvIDMwLzYwLzkwIGTDrWFzIiwicmVzcCI6WzIsMyw0XSwiZG9uZSI6ZmFsc2UsIm5vdGFzIjoiNjAlIn1dfSx7Im5vbWJyZSI6IjMuIEVzdGFuZGFyaXphY2nDs24iLCJpdGVtcyI6W3siaWQiOiJuNyIsInRleHRvIjoiRG9jdW1lbnRhY2nDs24gb2JsaWdhdG9yaWE6IHJlZ2lzdHJvIGV4aGF1c3Rpdm8gZGUgY2FkYSB0aWNrZXQiLCJyZXNwIjpbMSwyLDMsNF0sImRvbmUiOmZhbHNlLCJub3RhcyI6Ijc1JSJ9XX1dfSx7ImlkIjoicjYiLCJmZWNoYSI6IjIwMjYtMDUtMDIiLCJ0aXR1bG8iOiJQbGFuZWFjacOzbiBTZW1hbmFsIOKAlCBTw6FiIDIgTWF5IiwidGlwbyI6InNlbWFuYWwiLCJzdGF0dXMiOiJ1cGNvbWluZyIsImFzaXN0ZW50ZXMiOlsxLDIsMyw0XSwicmVjdXJyZW5jaWEiOiJTZW1hbmFsIOKAlCBTw6FiYWRvcyIsIm5vdGFzIjoiIiwiYWNjaW9uZXMiOlt7ImlkIjoiYTE0IiwidHh0IjoiVG9kYXMgdHJhZXIgYWN0dWFsaXphY2nDs24gZGUgcHJveWVjdG9zIiwicmVzcCI6MiwiZG9uZSI6ZmFsc2V9LHsiaWQiOiJhMTUiLCJ0eHQiOiJBcmxldHRlOiBsaXN0YSBuZWNlc2lkYWRlcyBkZSBsYSBvZmljaW5hIiwicmVzcCI6MywiZG9uZSI6ZmFsc2V9XSwic2VjY2lvbmVzIjpbeyJub21icmUiOiJNZXRhcyBkZSBsYSBTZW1hbmEiLCJpdGVtcyI6W3siaWQiOiJwMSIsInRleHRvIjoiVGlja2V0cyBkZWwgbHVuZXMg4oaSIGNlcnJhZG9zIGVsIHPDoWJhZG8iLCJyZXNwIjpbMiwzLDRdLCJkb25lIjpmYWxzZSwibm90YXMiOiIifSx7ImlkIjoicDIiLCJ0ZXh0byI6IlZpZXJuZXM6IHJldmlzacOzbiBkZSB0aWNrZXRzIiwicmVzcCI6WzEsMiwzLDRdLCJkb25lIjpmYWxzZSwibm90YXMiOiIifSx7ImlkIjoicDMiLCJ0ZXh0byI6IkNpdGFzIHBvciBsbGFtYWRhcyBjb21wbGV0YXMiLCJyZXNwIjpbMiwzLDRdLCJkb25lIjpmYWxzZSwibm90YXMiOiIifSx7ImlkIjoicDQiLCJ0ZXh0byI6IkxsYW1hZGEgZGlhcmlhIGNvbiBJc2FiZWwiLCJyZXNwIjpbMSwyLDMsNF0sImRvbmUiOmZhbHNlLCJub3RhcyI6IiJ9LHsiaWQiOiJwNSIsInRleHRvIjoiUHJveWVjdG9zOiByZXBvcnRhciBjdW1wbGlkb3MgKyBwcsOzeGltb3MiLCJyZXNwIjpbMiwzLDRdLCJkb25lIjpmYWxzZSwibm90YXMiOiIifSx7ImlkIjoicDYiLCJ0ZXh0byI6Ik5lY2VzaWRhZGVzIGRlIGxhIG9maWNpbmEg4oCUIEFybGV0dGUiLCJyZXNwIjpbM10sImRvbmUiOmZhbHNlLCJub3RhcyI6IiJ9XX0seyJub21icmUiOiJSZXRlbmNpw7NuIiwiaXRlbXMiOlt7ImlkIjoicDciLCJ0ZXh0byI6IlByb2JsZW1hcyBjcsOtdGljb3MgY29uIG1pZW1icm9zIiwicmVzcCI6WzEsMiwzLDRdLCJkb25lIjpmYWxzZSwibm90YXMiOiIifV19LHsibm9tYnJlIjoiVmVudGFzIiwiaXRlbXMiOlt7ImlkIjoicDgiLCJ0ZXh0byI6Ik1hcmtldGluZyBkZSByZWRlcyIsInJlc3AiOlsxLDIsMyw0XSwiZG9uZSI6ZmFsc2UsIm5vdGFzIjoiIn0seyJpZCI6InA5IiwidGV4dG8iOiJQcmltZXJhIGxsYW1hZGEg4oCUIG51ZXZvcyBsZWFkcyIsInJlc3AiOlsyLDMsNF0sImRvbmUiOmZhbHNlLCJub3RhcyI6IiJ9LHsiaWQiOiJwMTAiLCJ0ZXh0byI6IlNlZ3VpbWllbnRvIGEgbGVhZHMgY2FsaWVudGVzIiwicmVzcCI6WzIsMyw0XSwiZG9uZSI6ZmFsc2UsIm5vdGFzIjoiIn1dfV19LHsiaWQiOiJyMTAiLCJmZWNoYSI6IjIwMjYtMDUtMDYiLCJ0aXR1bG8iOiJFbnRyZW5hbWllbnRvIGRlbCBFcXVpcG8iLCJ0aXBvIjoiZW50cmVuYW1pZW50byIsInN0YXR1cyI6InVwY29taW5nIiwiYXNpc3RlbnRlcyI6WzEsMiwzLDRdLCJyZWN1cnJlbmNpYSI6Ik1pZXJjb2xlcyIsIm5vdGFzIjoiU2VzaW9uIHNlbWFuYWwgZGUgZW50cmVuYW1pZW50by4gVGVtYXM6IGNvbXBsaWFuY2UsIHNjcmlwdHMgZGUgdmVudGFzLCBoZXJyYW1pZW50YXMsIHByb2R1Y3RvIE1lZGljYXJlLiIsImFjY2lvbmVzIjpbXSwic2VjY2lvbmVzIjpbeyJub21icmUiOiJBZ2VuZGEgZGUgRW50cmVuYW1pZW50byIsIml0ZW1zIjpbeyJpZCI6InRyMSIsInRleHRvIjoiVGVtYSBkZSBsYSBzZW1hbmEg4oCUIGNvbXBsaWFuY2UgbyBzY3JpcHRzIiwicmVzcCI6WzFdLCJkb25lIjpmYWxzZSwibm90YXMiOiIifSx7ImlkIjoidHIyIiwidGV4dG8iOiJQcmFjdGljYSBkZSByb2xlLXBsYXkg4oCUIGxsYW1hZGEgZGUgcHJvc3BlY3RvIiwicmVzcCI6WzIsMyw0XSwiZG9uZSI6ZmFsc2UsIm5vdGFzIjoiIn0seyJpZCI6InRyMyIsInRleHRvIjoiUVx1MDAyNkEg4oCUIGR1ZGFzIGRlbCBlcXVpcG8iLCJyZXNwIjpbMSwyLDMsNF0sImRvbmUiOmZhbHNlLCJub3RhcyI6IiJ9LHsiaWQiOiJ0cjQiLCJ0ZXh0byI6IkFzaWduYWNpb24gZGUgdGFyZWEgcGFyYSBsYSBwcm94aW1hIHNlbWFuYSIsInJlc3AiOlsxXSwiZG9uZSI6ZmFsc2UsIm5vdGFzIjoiIn1dfV19LHsiaWQiOiJyNyIsImZlY2hhIjoiMjAyNi0wNC0yOCIsInRpdHVsbyI6IlN0YW5kdXAgRGlhcmlvIiwidGlwbyI6InN0YW5kdXAiLCJzdGF0dXMiOiJ1cGNvbWluZyIsImFzaXN0ZW50ZXMiOlsxLDIsMyw0XSwicmVjdXJyZW5jaWEiOiJMdW5lcyBhIFZpZXJuZXMgwrcgODozMCBBTSIsIm5vdGFzIjoiIiwiYWNjaW9uZXMiOltdLCJzZWNjaW9uZXMiOlt7Im5vbWJyZSI6IkFnZW5kYSDigJQgMzAgbWluIiwiaXRlbXMiOlt7ImlkIjoicTEiLCJ0ZXh0byI6IkxlYWRzIGNhbGllbnRlcyBkZSBhbm9jaGUiLCJyZXNwIjpbMiwzLDRdLCJkb25lIjpmYWxzZSwibm90YXMiOiIifSx7ImlkIjoicTIiLCJ0ZXh0byI6IkJsb3F1ZW9zIGFjdGl2b3MgZGVsIGVxdWlwbyIsInJlc3AiOlsxLDIsMyw0XSwiZG9uZSI6ZmFsc2UsIm5vdGFzIjoiIn0seyJpZCI6InEzIiwidGV4dG8iOiJQcmlvcmlkYWRlcyBkZWwgZMOtYSIsInJlc3AiOlsxLDIsMyw0XSwiZG9uZSI6ZmFsc2UsIm5vdGFzIjoiIn0seyJpZCI6InE0IiwidGV4dG8iOiLCv0FsZ28gdXJnZW50ZT8iLCJyZXNwIjpbMSwyLDMsNF0sImRvbmUiOmZhbHNlLCJub3RhcyI6IiJ9XX1dfSx7ImlkIjoicjgiLCJmZWNoYSI6IjIwMjYtMDQtMjkiLCJ0aXR1bG8iOiIxOjEgSXNhYmVsIFx1MDAyNiBBcmxldHRlIiwidGlwbyI6IjFvbjEiLCJzdGF0dXMiOiJ1cGNvbWluZyIsImFzaXN0ZW50ZXMiOlsxLDNdLCJyZWN1cnJlbmNpYSI6IlF1aW5jZW5hbCIsIm5vdGFzIjoiIiwiYWNjaW9uZXMiOltdLCJzZWNjaW9uZXMiOlt7Im5vbWJyZSI6IkVzdHJ1Y3R1cmEgMToxIiwiaXRlbXMiOlt7ImlkIjoibzEiLCJ0ZXh0byI6IkxvZ3JvcyBkZXNkZSBsYSDDumx0aW1hIDE6MSIsInJlc3AiOlszXSwiZG9uZSI6ZmFsc2UsIm5vdGFzIjoiIn0seyJpZCI6Im8yIiwidGV4dG8iOiJCbG9xdWVvcyDigJQgZmFybWFjaWE6IGFjdHVhbGl6YWNpw7NuPyIsInJlc3AiOlszXSwiZG9uZSI6ZmFsc2UsIm5vdGFzIjoiIn0seyJpZCI6Im8zIiwidGV4dG8iOiJNZXRhcyDigJQgSEVESVMgb2sgLyBSZXNwdWVzdGEgcXVlamFzIDMwJSIsInJlc3AiOlszXSwiZG9uZSI6ZmFsc2UsIm5vdGFzIjoiIn0seyJpZCI6Im80IiwidGV4dG8iOiLCv1F1w6kgbmVjZXNpdGFzIGRlIElzYWJlbD8iLCJyZXNwIjpbM10sImRvbmUiOmZhbHNlLCJub3RhcyI6IiJ9XX1dfSx7ImlkIjoicjkiLCJmZWNoYSI6IjIwMjYtMDQtMzAiLCJ0aXR1bG8iOiIxOjEgSXNhYmVsIFx1MDAyNiBTa2FybGV0aCIsInRpcG8iOiIxb24xIiwic3RhdHVzIjoidXBjb21pbmciLCJhc2lzdGVudGVzIjpbMSw0XSwicmVjdXJyZW5jaWEiOiJRdWluY2VuYWwiLCJub3RhcyI6IiIsImFjY2lvbmVzIjpbXSwic2VjY2lvbmVzIjpbeyJub21icmUiOiJFc3RydWN0dXJhIDE6MSIsIml0ZW1zIjpbeyJpZCI6InMxIiwidGV4dG8iOiJMb2dyb3Mg4oCUIGNvbnRyYXRvIERyLiBNYXJ0w61uZXogb2siLCJyZXNwIjpbNF0sImRvbmUiOmZhbHNlLCJub3RhcyI6IiJ9LHsiaWQiOiJzMiIsInRleHRvIjoiQmxvcXVlb3Mg4oCUIGxlZ2FsIGNvbiBjcmVkZW5jaWFsZXMiLCJyZXNwIjpbNF0sImRvbmUiOmZhbHNlLCJub3RhcyI6IiJ9LHsiaWQiOiJzMyIsInRleHRvIjoiTWV0YXMg4oCUIDUgcHJvdmVlZG9yZXMgNDAlIC8gQ2VydGlmaWNhY2nDs24gb2siLCJyZXNwIjpbNF0sImRvbmUiOmZhbHNlLCJub3RhcyI6IiJ9LHsiaWQiOiJzNCIsInRleHRvIjoiwr9RdcOpIG5lY2VzaXRhcyBkZSBJc2FiZWw/IiwicmVzcCI6WzRdLCJkb25lIjpmYWxzZSwibm90YXMiOiIifV19XX1d'), true);
        if (is_array($mtg_seed)) {
            $insM = $pdo->prepare("INSERT INTO reuniones (ext_id,titulo,fecha,tipo,status,recurrencia,asistentes,notas) VALUES (?,?,?,?,?,?,?,?)");
            $insS = $pdo->prepare("INSERT INTO reuniones_secciones (reunion_id,nombre,orden) VALUES (?,?,?)");
            $insI = $pdo->prepare("INSERT INTO reuniones_items (reunion_id,seccion_id,texto,responsables,done,notas,orden) VALUES (?,?,?,?,?,?,?)");
            $insA = $pdo->prepare("INSERT INTO reuniones_acciones (reunion_id,texto,responsable,done) VALUES (?,?,?,?)");
            foreach ($mtg_seed as $mt) {
                $insM->execute([
                    $mt['id'] ?? null, $mt['titulo'] ?? '(SIN TÍTULO)', $mt['fecha'] ?? null,
                    $mt['tipo'] ?? 'semanal', $mt['status'] ?? 'upcoming', $mt['recurrencia'] ?? '',
                    $mapids($mt['asistentes'] ?? []), $mt['notas'] ?? ''
                ]);
                $rid_seed = (int)$pdo->lastInsertId(); $so = 0;
                foreach ($as_arr($mt['secciones'] ?? []) as $sec) {
                    $insS->execute([$rid_seed, $sec['nombre'] ?? 'Agenda', $so++]);
                    $sid_seed = (int)$pdo->lastInsertId(); $io = 0;
                    foreach ($as_arr($sec['items'] ?? []) as $it) {
                        $insI->execute([$rid_seed, $sid_seed, $it['texto'] ?? '', $mapids($it['resp'] ?? []), !empty($it['done']) ? 1 : 0, $it['notas'] ?? '', $io++]);
                    }
                }
                foreach ($as_arr($mt['acciones'] ?? []) as $ac) {
                    $resp_a = $as_arr($ac['resp'] ?? []); $resp_a = $resp_a[0] ?? null;
                    $rmap = isset($pgmap[(int)$resp_a]) ? $pgmap[(int)$resp_a] : null;
                    $insA->execute([$rid_seed, $ac['txt'] ?? '', $rmap, !empty($ac['done']) ? 1 : 0]);
                }
            }
        }
    }
} catch (Exception $e) {}
// ─── TABLAS CAMPAÑAS (campaigns: contactos + logs, pipeline propio) ──────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS campanas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(200) NOT NULL,
        canal VARCHAR(40) DEFAULT 'FACEBOOK',
        descripcion TEXT,
        estado VARCHAR(20) DEFAULT 'ACTIVA',
        fecha_inicio DATE,
        costo DECIMAL(10,2) DEFAULT 0,
        agente_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    // Migración: agrega columnas faltantes si 'campanas' viene de una versión vieja
    $camp_cols = $pdo->query("SHOW COLUMNS FROM campanas")->fetchAll(PDO::FETCH_COLUMN);
    $camp_add = [
        'canal'        => "ADD COLUMN canal VARCHAR(40) DEFAULT 'FACEBOOK'",
        'descripcion'  => "ADD COLUMN descripcion TEXT",
        'estado'       => "ADD COLUMN estado VARCHAR(20) DEFAULT 'ACTIVA'",
        'fecha_inicio' => "ADD COLUMN fecha_inicio DATE",
        'costo'        => "ADD COLUMN costo DECIMAL(10,2) DEFAULT 0",
        'agente_id'    => "ADD COLUMN agente_id INT",
    ];
    foreach ($camp_add as $col => $ddl) {
        if (!in_array($col, $camp_cols, true)) { try { $pdo->exec("ALTER TABLE campanas $ddl"); } catch (Exception $e) {} }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS campana_contactos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campana_id INT NOT NULL,
        nombre VARCHAR(150) NOT NULL,
        apellido VARCHAR(150) DEFAULT NULL,
        telefono VARCHAR(50),
        email VARCHAR(150),
        estado VARCHAR(30) DEFAULT 'ACTIVO',
        notas TEXT,
        miembro_id INT DEFAULT NULL,
        promovido TINYINT(1) DEFAULT 0,
        agente_id INT,
        ultima_actividad DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS campana_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campana_id INT NOT NULL,
        contacto_id INT NOT NULL,
        agente_id INT,
        canal VARCHAR(30) DEFAULT 'LLAMADA',
        resultado VARCHAR(100),
        notas TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}
// ─── TABLAS PLANEACIÓN (metas, roadmap, planes día/semana/mes) ───────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS plan_metas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titulo VARCHAR(255) NOT NULL,
        objetivo VARCHAR(255),
        progreso INT DEFAULT 0,
        due VARCHAR(40),
        prioridad VARCHAR(20) DEFAULT 'HIGH',
        notas TEXT,
        orden INT DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS plan_checks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_key VARCHAR(60) NOT NULL UNIQUE,
        done TINYINT(1) DEFAULT 0,
        done_at DATETIME DEFAULT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS plan_notas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        horizonte VARCHAR(20) NOT NULL UNIQUE,
        contenido TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $pm_cnt = (int)$pdo->query("SELECT COUNT(*) FROM plan_metas")->fetchColumn();
    if ($pm_cnt === 0) {
        $pm_seed = [
            ['Crecer de 250 a 500 miembros Medicare Advantage','+31 miembros por mes',0,'Ene 2027','CRITICAL'],
            ['Retención ≥ 95% de miembros actuales','Máximo 12 bajas sobre 250',0,'Dic 2026','CRITICAL'],
            ['Alcanzar 50 reseñas en Google','Pedir review en el Day 30',0,'Sep 2026','HIGH'],
            ['Red de 10 médicos y clínicas referidoras','Outreach mensual a clínicas',0,'Sep 2026','HIGH'],
            ['Costo por lead < $25 en campañas digitales','Facebook/Instagram geo en español',0,'Jun 2026','HIGH'],
        ];
        $pm_ins = $pdo->prepare("INSERT INTO plan_metas (titulo,objetivo,progreso,due,prioridad,orden) VALUES (?,?,?,?,?,?)");
        foreach ($pm_seed as $i=>$m) $pm_ins->execute([$m[0],$m[1],$m[2],$m[3],$m[4],$i]);
    }
} catch (Exception $e) {}
// ─── TABLA ROLES (asignación de responsabilidades) ───────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS roles_asignacion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_key VARCHAR(20) NOT NULL UNIQUE,
        agente_id INT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}
// ─────────────────────────────────────────────────────────────────────────────

// Contar llamadas de hoy para el reporte
$stmt_llam_p = $pdo->prepare("SELECT COUNT(*) FROM llamadas_prospectos WHERE agente_id=? AND DATE(created_at)=?");
$stmt_llam_p->execute([$uid, $today]);
$mis_llamadas_prospectos_hoy = $stmt_llam_p->fetchColumn();

$stmt_llam_pc = $pdo->prepare("SELECT COUNT(*) FROM llamadas_prospectos WHERE agente_id=? AND DATE(created_at)=? AND contesto=1");
$stmt_llam_pc->execute([$uid, $today]);
$mis_llamadas_prosp_conts = $stmt_llam_pc->fetchColumn();

$stmt_llam_pnc = $pdo->prepare("SELECT COUNT(*) FROM llamadas_prospectos WHERE agente_id=? AND DATE(created_at)=? AND contesto=0");
$stmt_llam_pnc->execute([$uid, $today]);
$mis_llamadas_prosp_no_conts = $stmt_llam_pnc->fetchColumn();

$stmt_llam_s = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE agente_id=? AND tipo='LLAMADA' AND DATE(fecha_creacion)=?");
$stmt_llam_s->execute([$uid, $today]);
$mis_llamadas_servicio_hoy = $stmt_llam_s->fetchColumn();
// ----------------------------------------------------------------


// NUEVO: Guardar cambios del Gestor de Tareas Masivo (Crear, Editar, Eliminar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_gestor_tareas'])) {
    
    // 1. Eliminar tareas seleccionadas
    if (!empty($_POST['task_delete'])) {
        foreach ($_POST['task_delete'] as $del_id) {
            $del = $pdo->prepare("DELETE FROM tareas_personalizadas WHERE id=? AND agente_id=?");
            $del->execute([$del_id, $uid]);
        }
    }

    // 2. Actualizar existentes y crear nuevas
    if (!empty($_POST['task_id'])) {
        foreach ($_POST['task_id'] as $tid) {
            $orden = $_POST['task_orden'][$tid] ?? 0;
            $frec = $_POST['task_frec'][$tid] ?? 'DIARIA';
            $dia_mes = $_POST['task_dia_mes'][$tid] ?? 1;
            $dias = isset($_POST['task_dias'][$tid]) ? implode(',', $_POST['task_dias'][$tid]) : '';
            $texto = $_POST['task_texto'][$tid] ?? 'Nueva Tarea';

            if (str_starts_with($tid, 'new_')) {
                // Es una tarea nueva (INSERT)
                $item_key = uniqid('task_'); // Genera un ID único para el checklist
                $ins = $pdo->prepare("INSERT INTO tareas_personalizadas (agente_id, item_key, item_texto, orden, frecuencia, dias_semana, dia_mes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$uid, $item_key, $texto, $orden, $frec, $dias, $dia_mes]);
            } else {
                // Es una tarea existente (UPDATE)
                $upd = $pdo->prepare("UPDATE tareas_personalizadas SET item_texto=?, orden=?, frecuencia=?, dias_semana=?, dia_mes=? WHERE id=? AND agente_id=?");
                $upd->execute([$texto, $orden, $frec, $dias, $dia_mes, $tid, $uid]);
            }
        }
    }
    // Recargar página para ver los cambios
    header("Location: index.php");
    exit;
}
$users_all=$pdo->query("SELECT * FROM usuarios WHERE activo=1 ORDER BY rol DESC,nombre")->fetchAll();
$agents=array_filter($users_all,fn($u)=>$u['rol']==='agent');
// Alertas de retención: miembros que necesitan llamada — excluyendo las ya completadas
$alertas_hoy = 0;
try {
    $alertas_hoy = (int) $pdo->query("
        SELECT COUNT(DISTINCT m.id) FROM miembros m
        WHERE m.estado='ACTIVE' AND m.fecha_efectiva IS NOT NULL AND (
          (DATEDIFF(CURDATE(),m.fecha_efectiva) BETWEEN 0  AND 14 AND NOT EXISTS (SELECT 1 FROM efectivos_checks ec WHERE ec.miembro_id=m.id AND ec.tipo='llam_bienvenida'))
          OR (DATEDIFF(CURDATE(),m.fecha_efectiva) BETWEEN 25 AND 40 AND NOT EXISTS (SELECT 1 FROM retencion_llamadas rl WHERE rl.miembro_id=m.id AND rl.tipo='30'))
          OR (DATEDIFF(CURDATE(),m.fecha_efectiva) BETWEEN 55 AND 70 AND NOT EXISTS (SELECT 1 FROM retencion_llamadas rl WHERE rl.miembro_id=m.id AND rl.tipo='60'))
          OR (DATEDIFF(CURDATE(),m.fecha_efectiva) BETWEEN 85 AND 100 AND NOT EXISTS (SELECT 1 FROM retencion_llamadas rl WHERE rl.miembro_id=m.id AND rl.tipo='90'))
        )
    ")->fetchColumn();
} catch (Exception $e) { $alertas_hoy = 0; }

// ─── MIGRACIONES AUTOMÁTICAS IDEMPOTENTES ────────────────────────────────────
// Unificar DISENROLLED dentro de CANCELED (son el mismo estado)
try { $pdo->exec("UPDATE miembros SET estado='CANCELED' WHERE estado='DISENROLLED'"); } catch (Exception $e) {}
// Asegurar columna miembro_id en pago_bonos (para verificar ventas → bono)
try {
    $col = $pdo->query("SHOW COLUMNS FROM pago_bonos LIKE 'miembro_id'")->fetch();
    if (!$col) $pdo->exec("ALTER TABLE pago_bonos ADD COLUMN miembro_id INT NULL");
} catch (Exception $e) {}
// IDs de miembros que ya tienen un bono de venta registrado (para no duplicar)
$bonos_miembro_ids = [];
try {
    foreach ($pdo->query("SELECT DISTINCT miembro_id FROM pago_bonos WHERE miembro_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $bmid)
        $bonos_miembro_ids[(int)$bmid] = true;
} catch (Exception $e) {}

// Members con verificación de SOA
$members=$pdo->query("SELECT m.*,u.nombre as agente_nombre,u.color as agente_color,u.iniciales as agente_ini,
(SELECT COUNT(*) FROM soa WHERE miembro_id=m.id AND estado='FIRMADO') as has_soa
FROM miembros m LEFT JOIN usuarios u ON m.agente_id=u.id ORDER BY m.apellido,m.nombre")->fetchAll();

// Tickets — admin ve todos, agente ve SOLO donde es responsable (asignado_a)
// Si no hay asignado_a, fallback a agente_id (creador) para no perder tickets antiguos
$tkt_select = "SELECT t.*,
                      u.nombre   as agente_nombre,    u.color   as agente_color,    u.iniciales   as agente_ini,
                      a.nombre   as asignado_nombre,  a.color   as asignado_color,  a.iniciales   as asignado_ini,
                      TRIM(CONCAT(COALESCE(m.nombre,''),' ',COALESCE(m.apellido,''))) as miembro_nombre,
                      m.telefono as miembro_telefono, m.estado  as miembro_estado
               FROM tickets t
               LEFT JOIN usuarios u ON t.agente_id  = u.id
               LEFT JOIN usuarios a ON t.asignado_a = a.id
               LEFT JOIN miembros m ON t.miembro_id = m.id";

if ($admin) {
    $tickets = $pdo->query("$tkt_select
                            ORDER BY FIELD(t.estado,'ABIERTO','EN PROCESO','PENDIENTE','CERRADO'),
                                     FIELD(t.prioridad,'ALTA','MEDIA','BAJA'),
                                     t.fecha_creacion DESC, t.id DESC")->fetchAll();
} else {
    $stmt = $pdo->prepare("$tkt_select
                           WHERE t.asignado_a = ?
                              OR (t.asignado_a IS NULL AND t.agente_id = ?)
                           ORDER BY FIELD(t.estado,'ABIERTO','EN PROCESO','PENDIENTE','CERRADO'),
                                    FIELD(t.prioridad,'ALTA','MEDIA','BAJA'),
                                    t.fecha_creacion DESC, t.id DESC");
    $stmt->execute([$uid, $uid]);
    $tickets = $stmt->fetchAll();
}
// Separate open vs all for dashboard counts
$tickets_open = array_filter($tickets, fn($t) => $t['estado'] !== 'CERRADO');

// === TIPOS DE TICKET — el sistema decide la categoría según el tipo elegido ===
$TIPO_MIEMBRO = ['FOLLOW UP','QUEJA','CAMBIO DE DOCTOR','CLIENTE','CITA','APLICACION',
                 'SERVICIO AL CLIENTE','LLAMADA','LLAMADA PERDIDA','CITA DENTAL','URGENTE'];
$TIPO_TAREA   = ['SOPORTE','TASK','MARKETING','NEXTIVA','ENTRENAMIENTO','CRM','PROYECTO','OTRO'];
$TIPOS_TODOS  = array_merge($TIPO_MIEMBRO, $TIPO_TAREA);

// Cargar next_steps de todos los tickets (ordenados: pendientes primero por fecha, luego completados)
$next_steps_por_ticket = [];
try {
  foreach ($pdo->query("SELECT ns.*, u.nombre as agente_nombre, u.iniciales as agente_ini, u.color as agente_color
                        FROM ticket_next_steps ns
                        LEFT JOIN usuarios u ON ns.agente_id = u.id
                        ORDER BY ns.completado ASC,
                                 CASE WHEN ns.fecha_programada IS NULL THEN 1 ELSE 0 END,
                                 ns.fecha_programada ASC,
                                 ns.id ASC") as $ns) {
    $next_steps_por_ticket[$ns['ticket_id']][] = $ns;
  }
} catch (Exception $e) { /* tabla aún no existe — primera vez */ }

$citas=$pdo->query("SELECT c.*,
                           u.nombre as agente_nombre, u.color as agente_color, u.iniciales as agente_ini,
                           cu.nombre as completada_nombre, cu.iniciales as completada_ini,
                           CONCAT(m.apellido,', ',m.nombre) as miembro_nombre,
                           m.telefono as miembro_telefono, m.estado as miembro_estado
                    FROM citas c
                    LEFT JOIN usuarios u  ON c.agente_id      = u.id
                    LEFT JOIN usuarios cu ON c.completada_por = cu.id
                    LEFT JOIN miembros m  ON c.miembro_id     = m.id
                    ORDER BY c.fecha DESC, c.hora ASC")->fetchAll();
// Index de citas por miembro_id (para el pipeline de prospectos)
$citas_por_miembro=[];
foreach($citas as $__c){ if(!empty($__c['miembro_id'])) $citas_por_miembro[$__c['miembro_id']][]=$__c; }

$mci=$pdo->prepare("SELECT * FROM asistencia WHERE agente_id=? AND fecha=?");$mci->execute([$uid,$today]);$my_ci=$mci->fetch();
$tcq=$pdo->prepare("SELECT a.*,u.nombre,u.color,u.iniciales FROM asistencia a LEFT JOIN usuarios u ON a.agente_id=u.id WHERE a.fecha=?");$tcq->execute([$today]);$today_ckins=$tcq->fetchAll();
$open_tks = count(array_filter($tickets_open, fn($t) => in_array($t['tipo'] ?? '', $TIPO_MIEMBRO, true)));
$hoy_fecha = date('Y-m-d');

// Conteo para el globo rojo del menú — solo tickets de MIEMBRO, ABIERTOS, SLA hoy o vencido
$alerta_menu = count(array_filter($tickets_open, function($t) use ($hoy_fecha, $TIPO_MIEMBRO) {
    return $t['estado'] === 'ABIERTO'
        && in_array($t['tipo'] ?? '', $TIPO_MIEMBRO, true)
        && (empty($t['sla_fecha']) || $t['sla_fecha'] <= $hoy_fecha);
}));

// Las llamadas de hoy ya fueron calculadas arriba (líneas de $stmt_llam_p)
// No se repiten aquí para evitar queries duplicadas

// Contar tickets cerrados por mí el día de hoy (miembro + tareas generales).
// Se excluyen APLICACION (se cuenta como APPS) y LLAMADAS (se cuentan aparte).
$hoy_fmt = date('Y-m-d');
$mis_cerrados_hoy = count(array_filter($tickets, function($t) use ($uid, $hoy_fmt) {
    $es_mio = (!empty($t['asignado_a'])) ? ($t['asignado_a'] == $uid) : ($t['agente_id'] == $uid);
    $fecha_cierre = $t['fecha_cierre'] ?? '';
    $tipo = $t['tipo'] ?? '';
    return $es_mio && $t['estado'] === 'CERRADO' && str_starts_with($fecha_cierre, $hoy_fmt)
           && !in_array($tipo, ['APLICACION','LLAMADA','LLAMADA PERDIDA'], true);
}));

// Contar APPS (Tickets cerrados hoy que son de tipo 'APLICACION')
$mis_apps_hoy = count(array_filter($tickets, function($t) use ($uid, $hoy_fmt) {
    $es_mio = (!empty($t['asignado_a'])) ? ($t['asignado_a'] == $uid) : ($t['agente_id'] == $uid);
    $fecha_cierre = $t['fecha_cierre'] ?? '';
    return $es_mio && $t['estado'] === 'CERRADO' && str_starts_with($fecha_cierre, $hoy_fmt) && ($t['tipo'] ?? '') === 'APLICACION';
}));

// Contar citas creadas por mí hoy — solo tipos productivos (ENROLLMENT, AEP, T65)
$stmt_citas_hoy = $pdo->prepare("SELECT COUNT(*) FROM citas WHERE agente_id=? AND DATE(created_at)=? AND tipo IN ('ENROLLMENT','AEP','T65')");
$stmt_citas_hoy->execute([$uid, $today]);
$mis_citas_creadas_hoy = $stmt_citas_hoy->fetchColumn();

// Contar APPS POR HACER (Tickets de tipo 'APLICACION' abiertos/sin cerrar de mi pertenencia)
$mis_apps_por_hacer = count(array_filter($tickets, function($t) use ($uid) {
    return ((!empty($t['asignado_a']) ? $t['asignado_a'] == $uid : $t['agente_id'] == $uid) && $t['estado'] !== 'CERRADO' && ($t['tipo'] ?? '') === 'APLICACION');
}));

// Contar tickets que actualicé hoy (solo tipo MIEMBRO — excluye TASK/TAREA)
$tipos_miembro_sql = implode(',', array_map(fn($t) => $pdo->quote($t), $TIPO_MIEMBRO));
$stmt_act = $pdo->prepare("
    SELECT COUNT(DISTINCT ticket_ref) FROM (
        SELECT ns.ticket_id AS ticket_ref
        FROM ticket_next_steps ns
        JOIN tickets t ON t.id = ns.ticket_id
        WHERE ns.agente_id = ? AND DATE(ns.created_at) = ?
          AND t.tipo IN ($tipos_miembro_sql)
        UNION
        SELECT t.id AS ticket_ref
        FROM actividad a
        JOIN tickets t ON t.miembro_id = a.miembro_id
        WHERE a.agente_id = ?
          AND a.tipo IN ('TICKET', 'NOTA')
          AND DATE(a.fecha_hora) = ?
          AND (t.asignado_a = ? OR (t.asignado_a IS NULL AND t.agente_id = ?))
          AND t.tipo IN ($tipos_miembro_sql)
    ) combined
");
$stmt_act->execute([$uid, $today, $uid, $today, $uid, $uid]);
$tkt_act_count = $mis_actualizados_hoy = (int)$stmt_act->fetchColumn();


// Contar tickets que me pertenecen (Abiertos, En Proceso, Pendientes) con SLA de hoy o vencido — SOLO MIEMBRO
$mis_tickets_abiertos = count(array_filter($tickets_open, function($t) use ($uid, $hoy_fecha, $TIPO_MIEMBRO) {
    $es_mio    = (!empty($t['asignado_a'])) ? ($t['asignado_a'] == $uid) : ($t['agente_id'] == $uid);
    $sla_alerta = (empty($t['sla_fecha']) || $t['sla_fecha'] <= $hoy_fecha);
    return $es_mio && $sla_alerta && in_array($t['tipo'] ?? '', $TIPO_MIEMBRO, true);
}));

$urgent_tks=count(array_filter($tickets_open,fn($t)=>$t['prioridad']==='ALTA'));
$llamadas=$pdo->query("SELECT l.*,u.iniciales,u.color FROM llamadas_perdidas l LEFT JOIN usuarios u ON l.agente_id=u.id ORDER BY l.fecha DESC,l.hora DESC")->fetchAll();
$pending_llam=count(array_filter($llamadas,fn($l)=>$l['estado']==='PENDIENTE'));
$rq=$admin?"SELECT r.*,u.nombre,u.color,u.iniciales FROM reporte_diario r LEFT JOIN usuarios u ON r.agente_id=u.id WHERE r.fecha='$today' ORDER BY u.nombre":"SELECT r.*,u.nombre,u.color,u.iniciales FROM reporte_diario r LEFT JOIN usuarios u ON r.agente_id=u.id WHERE r.agente_id=$uid AND r.fecha='$today' LIMIT 1";
$reportes_hoy=$pdo->query($rq)->fetchAll();

// Checklist completado por agente hoy
$checklist_stats = [];
$stmt_ck = $pdo->query(
    "SELECT cd.agente_id,
            COUNT(*) as total,
            SUM(cd.completado) as completadas
     FROM checklist_diario cd
     JOIN tareas_personalizadas tp ON cd.item_key = tp.item_key AND tp.agente_id = cd.agente_id
     WHERE cd.fecha = '$today'
     GROUP BY cd.agente_id"
);
foreach ($stmt_ck->fetchAll() as $ck) {
    $checklist_stats[$ck['agente_id']] = $ck;
}

$my_reporte=$admin?null:($reportes_hoy[0]??null);

// Checklist stats por agente hoy
$checklist_stats = [];
$checklist_por_agente = [];
try {
    $stmt_cks = $pdo->query(
        "SELECT cd.agente_id, COUNT(*) as total, SUM(cd.completado) as completadas
         FROM checklist_diario cd
         JOIN tareas_personalizadas tp ON cd.item_key = tp.item_key AND tp.agente_id = cd.agente_id
         WHERE cd.fecha = '$today'
         GROUP BY cd.agente_id"
    );
    foreach ($stmt_cks->fetchAll() as $ck) {
        $checklist_stats[$ck['agente_id']] = $ck;
    }
    $stmt_ckd = $pdo->query(
        "SELECT cd.agente_id, cd.item_texto, cd.completado, cd.completado_at
         FROM checklist_diario cd
         JOIN tareas_personalizadas tp ON cd.item_key = tp.item_key AND tp.agente_id = cd.agente_id
         WHERE cd.fecha = '$today'
         ORDER BY cd.agente_id, cd.completado DESC, tp.orden ASC"
    );
    foreach ($stmt_ckd->fetchAll() as $row) {
        $checklist_por_agente[$row['agente_id']][] = $row;
    }
} catch (Exception $e) {}

$activos_total=count(array_filter($members,fn($m)=>$m['estado']==='ACTIVE'));
$t65_count=count(array_filter($members,fn($m)=>in_array($m['estado'],['SIN HACER','SIN FIRMAR'])));
// IN PROCESS + READY TO ENROLL → van al pipeline y cuentan como pendientes
$followups=count(array_filter($members,fn($m)=>in_array($m['estado'],['IN PROCESS','READY TO ENROLL'])));
$activos_mes=count(array_filter($members,fn($m)=>$m['estado']==='ACTIVE'&&$m['subestado']==='NEW ENROLLMENT'&&!empty($m['fecha_efectiva'])&&str_starts_with($m['fecha_efectiva'],date('Y-m'))));
$cancelados_mes=count(array_filter($members,fn($m)=>in_array($m['estado'],['CANCELED','DENIED','CERRADO','DISENROLLED'])&&!empty($m['fecha_cancelacion'])&&str_starts_with($m['fecha_cancelacion'],date('Y-m'))));
$cancelados_total=count(array_filter($members,fn($m)=>in_array($m['estado'],['CANCELED','DENIED','CERRADO','DISENROLLED'])));
// String del próximo mes (ejemplo: '2026-06')
$next_month_str = date('Y-m', strtotime('first day of next month'));
$next_month_label = strtoupper(date('M Y', strtotime('first day of next month')));

// FUTUROS EFECTIVOS: cualquier estado con fecha_efectiva el próximo mes
$futuros_efectivos = count(array_filter($members, function($m) use ($next_month_str) {
    return !empty($m['fecha_efectiva'])
        && str_starts_with($m['fecha_efectiva'], $next_month_str);
}));

// META DE PRODUCCIÓN: NEW ENROLLMENT únicamente (NO RE-SIGNED) con fecha_efectiva próximo mes
$apps_proceso = count(array_filter($members, function($m) use ($next_month_str) {
    $estado_ok    = in_array($m['estado'], ['ACTIVE', 'IN PROCESS', 'READY TO ENROLL']);
    $fecha_ok     = !empty($m['fecha_efectiva']) && str_starts_with($m['fecha_efectiva'], $next_month_str);
    $es_new_enroll= ($m['subestado'] ?? '') !== 'RE-SIGNED'; // excluir cambios de plan
    return $estado_ok && $fecha_ok && $es_new_enroll;
}));
// T65 próximos 90 días
$t65_alertas=$pdo->query("SELECT id,nombre,apellido,dob,telefono,estado,carrier,agente_id,DATE_ADD(dob,INTERVAL 65 YEAR) as fecha_65,DATEDIFF(DATE_ADD(dob,INTERVAL 65 YEAR),CURDATE()) as dias_restantes FROM miembros WHERE DATE_ADD(dob,INTERVAL 65 YEAR) BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 90 DAY) ORDER BY fecha_65 ASC")->fetchAll();
// Auto-generate retention notifications
// Solo en navegaciones reales (no en el auto-refresco AJAX) para no repetir
// trabajo pesado cada 30s. Internamente además corre como máximo 1 vez al día.
$ES_REFRESCO_AJAX = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
if(!$ES_REFRESCO_AJAX){ try{generarNotificacionesRetencion($pdo,$users_all);}catch(Exception $e){} }
$ef_checks=[];
try{foreach($pdo->query("SELECT * FROM efectivos_checks") as $e)$ef_checks[$e['miembro_id']][$e['tipo']]=$e;}catch(Exception $e){}
// Filtro corregido: Que sea de este mes Y que el estado sea estrictamente 'ACTIVE'
$ef_mes = array_filter($members, function($m) {
    $es_de_este_mes = (!empty($m['fecha_efectiva']) && str_starts_with($m['fecha_efectiva'], date('Y-m'))) || 
                      (!empty($m['app_fecha']) && str_starts_with($m['app_fecha'], date('Y-m')));
    
    return $es_de_este_mes && $m['estado'] === 'ACTIVE';
});

try{
  $chat_msgs=array_reverse($pdo->query("SELECT c.*,u.nombre,u.color,u.iniciales FROM chat_mensajes c LEFT JOIN usuarios u ON c.user_id=u.id ORDER BY c.id DESC LIMIT 60")->fetchAll());
  $lsq=$pdo->prepare("SELECT last_seen_chat FROM usuarios WHERE id=?");
  $lsq->execute([$uid]);
  $lt=$lsq->fetchColumn();
  // Si nunca abrió el chat → contar TODOS los mensajes del grupo de otros (no solo desde "última vista")
  // Si ya lo abrió alguna vez → contar solo los nuevos desde esa fecha
  if($lt){
    $cu=$pdo->prepare("SELECT COUNT(*) FROM chat_mensajes WHERE user_id!=? AND created_at>? AND (tipo='GRUPO' OR tipo IS NULL) AND (es_dm=0 OR es_dm IS NULL)");
    $cu->execute([$uid,$lt]);
  }else{
    $cu=$pdo->prepare("SELECT COUNT(*) FROM chat_mensajes WHERE user_id!=? AND (tipo='GRUPO' OR tipo IS NULL) AND (es_dm=0 OR es_dm IS NULL)");
    $cu->execute([$uid]);
  }
  $chat_unread=(int)$cu->fetchColumn();
}catch(Exception $e){}
$notif_unread=0;
try{$nq=$pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE user_id=? AND leido=0");$nq->execute([$uid]);$notif_unread=(int)$nq->fetchColumn();}catch(Exception $e){}
$actividad=$pdo->query("SELECT a.*,u.nombre,u.color,u.iniciales,CONCAT(m.apellido,' ',m.nombre) as miembro_nombre FROM actividad a LEFT JOIN usuarios u ON a.agente_id=u.id LEFT JOIN miembros m ON a.miembro_id=m.id ORDER BY a.fecha_hora DESC LIMIT 150")->fetchAll();
// Tabs use safe ASCII IDs for JS; tabn maps to display names with accents
// ─── CARGA DE DATOS: CUENTAS + REFERIDOS ─────────────────────────────────────
$cuentas_list = []; $cuentas_vencidas = []; $cue_alerta_count = 0; $referidos_pendientes = 0;
try {
    $cuentas_list = $pdo->query("
        SELECT c.*, u.nombre AS agente_nombre, u.color AS agente_color, u.iniciales AS agente_ini,
               COUNT(DISTINCT ref.id)  AS cnt_referidos,
               COUNT(DISTINCT mie.id)  AS cnt_miembros,
               MAX(ci.fecha)           AS ultima_interaccion,
               DATEDIFF(CURDATE(), MAX(ci.fecha)) AS dias_desde,
               SUM(ci.gasto_monto)     AS total_gastado
        FROM cuentas c
        LEFT JOIN usuarios u               ON c.agente_id   = u.id
        LEFT JOIN referidos ref            ON ref.cuenta_id = c.id
        LEFT JOIN miembros mie             ON mie.referido_por = c.id
        LEFT JOIN cuentas_interacciones ci ON ci.cuenta_id  = c.id
        WHERE c.activo = 1
        GROUP BY c.id
        ORDER BY CASE WHEN MAX(ci.fecha) IS NULL THEN 1 ELSE 0 END DESC,
                 DATEDIFF(CURDATE(), MAX(ci.fecha)) DESC, c.nombre ASC
    ")->fetchAll();
    $cuentas_vencidas = array_filter($cuentas_list, fn($c) =>
        $c['dias_desde'] === null || $c['dias_desde'] >= (int)($c['dias_recordatorio'] ?? 30)
    );
    $cue_alerta_count = count($cuentas_vencidas);
    $referidos_pendientes = (int)$pdo->query("SELECT COUNT(*) FROM referidos WHERE estado NOT IN ('EN PIPELINE','NO INTERESADO')")->fetchColumn();
} catch (Exception $e) {}
$cue_total      = count($cuentas_list);
$cue_referentes = count(array_filter($cuentas_list, fn($c)=>$c['es_referente']));
// ─────────────────────────────────────────────────────────────────────────────

$tabs_admin=['DASHBOARD','MI DÍA','PLANEACION','MIEMBROS','RETENCION','PIPELINE','CAMPANAS','CITAS','TICKETS','COMUNICACION','REUNIONES','PORTALES','BONOS','GASTOS','ASISTENCIA','ROLES','RECURSOS','ENTRENAMIENTO','CONTACTOS','REPORTES','ADMIN'];
$tabs_agent=['DASHBOARD','MI DÍA','PLANEACION','MIEMBROS','RETENCION','PIPELINE','CAMPANAS','CITAS','TICKETS','COMUNICACION','REUNIONES','PORTALES','BONOS','GASTOS','ASISTENCIA','ROLES','CONTACTOS','RECURSOS','ENTRENAMIENTO'];
$tabs=$admin?$tabs_admin:$tabs_agent;
$ticon=['DASHBOARD'=>'▣','ISABEL AI'=>'🤖','MI DÍA'=>'📋','PLANEACION'=>'🧭','MIEMBROS'=>'◉','PORTALES'=>'🖥','PIPELINE'=>'▲','CAMPANAS'=>'📣','CITAS'=>'◷','TICKETS'=>'◈','ASISTENCIA'=>'◐','ROLES'=>'🧩','POLIZAS'=>'◎','BONOS'=>'◈','COMUNICACION'=>'◌','RECURSOS'=>'◍','RETENCION'=>'📞','CONTACTOS'=>'🤝','REPORTES'=>'▦','GASTOS'=>'💰','REUNIONES'=>'📅','ENTRENAMIENTO'=>'🎓','ADMIN'=>'⊞'];
$tabn=['DASHBOARD'=>'DASHBOARD','ISABEL AI'=>'ISABEL AI','MI DÍA'=>'MI DÍA','PLANEACION'=>'PLANEACIÓN','MIEMBROS'=>'MIEMBROS','PIPELINE'=>'PIPELINE','CAMPANAS'=>'CAMPAÑAS','CITAS'=>'CITAS','TICKETS'=>'TICKETS/TASK','ASISTENCIA'=>'ASISTENCIA','ROLES'=>'ROLES','POLIZAS'=>'PÓLIZAS','BONOS'=>'MIS BONOS','COMUNICACION'=>'COMUNICACIÓN','RECURSOS'=>'RECURSOS','RETENCION'=>'RETENCIÓN','CONTACTOS'=>'CONTACTOS','REPORTES'=>'REPORTES','GASTOS'=>'GASTOS','REUNIONES'=>'REUNIONES','ENTRENAMIENTO'=>'ENTRENAMIENTO','ADMIN'=>'ADMIN'];
$P1='#1B4A6B';$P2='#2876A8';$BG='#EBF4F9';$CB='#C8DFF0';$G='#1E7A5C';$R='#B83232';$A='#C07A1A';$MU='#7A90A4';$TX='#1B3A5C';
function badge(?string $s, bool $sm = false) : string {
    $s = $s ?? ''; $map=['ACTIVE'=>['#1E7A5C','#EAF5F0','#8DCFBA'],'IN PROCESS'=>['#1B5E8C','#EBF5FB','#A9D0E8'],'PLAN CHANGE'=>['#5B3FAF','#F3F0FB','#C2B0E8'],'SIN HACER'=>['#C07A1A','#FEF8EE','#F5D5A0'],'SIN FIRMAR'=>['#C05C1A','#FEF2EB','#F5C4A0'],'CANCELED'=>['#B83232','#FDF0EE','#EFA09A'],'DENIED'=>['#B83232','#FDF0EE','#EFA09A'],'CERRADO'=>['#888780','#F1EFE8','#B4B2A9'],'DISENROLLED'=>['#993C1D','#FAECE7','#F0997B'],'ACTIVO'=>['#1E7A5C','#EAF5F0','#8DCFBA'],'CANCELADO'=>['#B83232','#FDF0EE','#EFA09A'],'PENDIENTE'=>['#1B5E8C','#EBF5FB','#A9D0E8'],'PROSPECTO'=>['#1E7A8C','#EAF4F6','#8DC8D0'],'ABIERTO'=>['#B83232','#FDF0EE','#EFA09A'],'EN PROCESO'=>['#C07A1A','#FEF8EE','#F5D5A0'],'CERRADO'=>['#1E7A5C','#EAF5F0','#8DCFBA'],'FIRMADO'=>['#1E7A5C','#EAF5F0','#8DCFBA'],'ALTA'=>['#B83232','#FDF0EE','#EFA09A'],'MEDIA'=>['#C07A1A','#FEF8EE','#F5D5A0'],'BAJA'=>['#1E7A8C','#EAF4F6','#8DC8D0'],'ACTIVA'=>['#1E7A5C','#EAF5F0','#8DCFBA'],'DEVUELTA'=>['#1E7A5C','#EAF5F0','#8DCFBA'],'ADMIN'=>['#1B4A6B','#EBF4F9','#C8DFF0'],'EMPLEADO'=>['#1E7A8C','#EAF4F6','#8DC8D0']];$c=$map[$s]??['#7A90A4','#F4F8FC','#C8DFF0'];$p=$sm?'2px 8px':'3px 10px';$f=$sm?'9px':'10px';return "<span style=\"padding:$p;border-radius:20px;font-size:$f;font-weight:800;background:{$c[1]};color:{$c[0]};border:1px solid {$c[2]};white-space:nowrap;letter-spacing:.5px;text-transform:uppercase\">$s</span>";}
function av(string $i,string $c,int $z=28):string{return "<div style=\"width:{$z}px;height:{$z}px;border-radius:50%;background:$c;display:flex;align-items:center;justify-content:center;font-size:".round($z*.32)."px;font-weight:900;color:#fff;flex-shrink:0;font-family:'DM Sans',sans-serif\">$i</div>";}
function strftime_es(string $ym):string{
    // Convierte "YYYY-MM" a "Mes YYYY" en español sin depender de strftime/locale
    $meses=[1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    [$y,$m]=array_map('intval', explode('-',$ym));
    return ($meses[$m]??'?').' '.$y;
}
function calc_hours(?string $ci,?string $lo,?string $li,?string $co,?string $bo=null,?string $bi=null):?string{if(!$ci||!$co)return null;$s=strtotime("1970-01-01 $ci");$e=strtotime("1970-01-01 $co");$t=$e-$s;if($lo&&$li){$ls=strtotime("1970-01-01 $lo");$le=strtotime("1970-01-01 $li");$t-=($le-$ls);}if($bo&&$bi){$bs=strtotime("1970-01-01 $bo");$be=strtotime("1970-01-01 $bi");$t-=($be-$bs);}if($t<=0)return null;return floor($t/3600).'H '.floor(($t%3600)/60).'M';}
function generarNotificacionesRetencion(PDO $pdo, array $users_all):void {
// Candado diario: si ya se generaron hoy, no repetir el trabajo en cada carga.
$flag = sys_get_temp_dir().'/crm_ret_'.date('Ymd').'.flag';
if (is_file($flag)) return;
@touch($flag); // marcar de inmediato para evitar corridas repetidas/concurrentes
$intervalos = [7, 30, 60, 90];
foreach ($intervalos as $d) {
$stmt = $pdo->prepare("SELECT id,nombre,apellido,carrier FROM miembros WHERE estado='ACTIVE' AND fecha_efectiva=DATE_SUB(CURDATE(),INTERVAL ? DAY)");
$stmt->execute([$d]);
foreach ($stmt->fetchAll() as $m) {
$msg = " RETENCIÓN {$d} DÍAS: Llamar a {$m['nombre']} {$m['apellido']}".($m['carrier']?" ({$m['carrier']})":'');
// Send to ALL users (no individual agent assignment per Isabel's rule)
foreach ($users_all as $u) {
$check = $pdo->prepare("SELECT id FROM notificaciones WHERE user_id=? AND mensaje=? AND DATE(created_at)=CURDATE()");
$check->execute([$u['id'],$msg]);
if (!$check->fetch()) {
$pdo->prepare("INSERT INTO notificaciones (user_id,tipo,mensaje) VALUES (?,'RETENCION',?)")
->execute([$u['id'],$msg]);
// Notify Isabel on Telegram (solo una vez, no por cada usuario)
if ($u['rol']==='admin') notificarAIsabel(" *RETENCIÓN {$d} DÍAS*\n".$msg, $pdo);
}
}
}
}
}
function chk(?array $ef,int $mid,string $tipo):string{$d=!empty($ef[$mid][$tipo]);$bg=$d?'#EAF5F0':'#fff';$bc=$d?'#8DCFBA':'#C8DFF0';$tc=$d?'#1E7A5C':'#94A3B8';return "<button class=\"efbtn\" onclick=\"toggleEf($mid,'$tipo',this)\" style=\"background:$bg;border:1.5px solid $bc;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:900;color:$tc;cursor:pointer\">".($d?'✓':'○')."</button>";}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Medicare with Isabel — CRM</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800;900&family=Great+Vibes&display=swap" rel="stylesheet">
<script>try{if(localStorage.getItem('crm_theme')==='dark'){document.documentElement.setAttribute('data-theme','dark');document.documentElement.style.background='#0F1923';}}catch(e){}</script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:<?=$BG?>;font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;flex-direction:column;font-size:13px;color:<?=$TX?>;text-transform:uppercase}
header{background:<?=$P1?>;height:58px;display:flex;align-items:center;gap:12px;padding:0 20px;position:sticky;top:0;z-index:300;box-shadow:0 2px 16px rgba(27,74,107,.35)}

.brand{display:flex;align-items:center;gap:8px;flex-shrink:0}
.brand-script{font-family:'Great Vibes',cursive;font-size:26px;color:#fff;line-height:1;text-transform:none !important;letter-spacing:0}
.brand-sub{font-size:8px;font-weight:700;color:rgba(255,255,255,.6);letter-spacing:4px;text-transform:uppercase !important}
.hpill{border-radius:20px;padding:3px 9px;font-size:7px;font-weight:900;letter-spacing:1px;text-transform:uppercase;white-space:nowrap}
.huser{display:flex;gap:6px;align-items:center;background:rgba(255,255,255,.07);border-radius:20px;padding:4px 11px 4px 5px;border:1px solid rgba(255,255,255,.12);cursor:pointer;text-decoration:none}
.huser-name{font-size:9px;font-weight:900;color:#fff;letter-spacing:1.5px;text-transform:uppercase}
.huser-role{font-size:7px;color:rgba(255,255,255,.45);letter-spacing:1px;text-transform:uppercase}
.hbtn{background:rgba(255,255,255,.1);color:rgba(255,255,255,.85);border:1px solid rgba(255,255,255,.2);border-radius:8px;padding:5px 10px;font-size:9px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif;letter-spacing:1px;text-transform:uppercase;position:relative}
.hbadge{position:absolute;top:-5px;right:-5px;background:#EF4444;color:#fff;border-radius:20px;padding:1px 5px;font-size:7px;font-weight:900;min-width:14px;text-align:center}
nav{background:#fff;border-bottom:1px solid <?=$CB?>;padding:0 20px;display:flex;overflow-x:auto;position:sticky;top:58px;z-index:100;box-shadow:0 2px 8px rgba(27,74,107,.06)}
.ntab{background:transparent;border:none;cursor:pointer;padding:10px 12px;font-size:8px;font-weight:900;white-space:nowrap;border-bottom:3px solid transparent;margin-bottom:-1px;color:<?=$MU?>;font-family:'DM Sans',sans-serif;letter-spacing:2px;text-transform:uppercase;display:flex;align-items:center;gap:4px}
.ntab.active{color:<?=$P1?>;border-bottom-color:<?=$P1?>}
.nbadge{border-radius:20px;padding:1px 5px;font-size:7px;font-weight:900}
main{padding:14px 18px;max-width:1400px;margin:0 auto;width:100%;flex:1}
.page-title{display:flex;align-items:center;gap:9px;margin-bottom:13px}
.page-title h1{font-size:12px;font-weight:900;color:<?=$P1?>;letter-spacing:4px;text-transform:uppercase}
.card{background:#fff;border:1px solid <?=$CB?>;border-radius:14px;overflow:hidden}
.card-header{padding:12px 17px;border-bottom:1px solid <?=$CB?>;background:linear-gradient(to right,<?=$BG?>,#fff);display:flex;align-items:center;justify-content:space-between;gap:10px}
.card-title{font-size:10px;font-weight:900;color:<?=$P1?>;letter-spacing:2px;text-transform:uppercase}
.card-sub{font-size:8px;color:<?=$MU?>;letter-spacing:1px;text-transform:uppercase}
table{width:100%;border-collapse:collapse}
th{padding:7px 13px;text-align:left;font-size:9px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px;background:<?=$BG?>;border-bottom:1px solid <?=$CB?>;white-space:nowrap}
td{padding:9px 13px;border-bottom:1px solid <?=$CB?>60;vertical-align:middle}
tr:hover td{background:<?=$BG?>}
.btn{border:none;border-radius:10px;padding:9px 18px;font-size:10px;font-weight:800;cursor:pointer;font-family:'DM Sans',sans-serif;letter-spacing:1px;text-transform:uppercase;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;text-decoration:none}
.btn-p{background:<?=$P1?>;color:#fff}.btn-b{background:<?=$P2?>;color:#fff}.btn-sky{background:#4A9CC8;color:#fff}
.btn-sm{padding:5px 12px;font-size:9px;border-radius:7px}.btn-full{width:100%;justify-content:center}
.btn-gh{background:#fff;color:<?=$MU?>;border:1px solid <?=$CB?>}.btn-gr{background:#EAF5F0;color:#1E7A5C;border:1px solid #8DCFBA}
.btn-am{background:#FEF8EE;color:#C07A1A;border:1px solid #F5D5A0}.btn-re{background:#FDF0EE;color:#B83232;border:1px solid #EFA09A}
.btn-bl{background:#EBF5FB;color:#1B5E8C;border:1px solid #A9D0E8}
.stats-row{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:12px}
.stat-card{background:#fff;border:1px solid <?=$CB?>;border-radius:14px;padding:12px 15px;flex:1;min-width:85px;cursor:pointer;border-top:3px solid currentColor}
.stat-icon{font-size:8px;font-weight:900;color:<?=$MU?>;letter-spacing:2px;text-transform:uppercase;margin-bottom:5px}
.stat-val{font-size:20px;font-weight:900;line-height:1}
.form-group{margin-bottom:9px}
.form-label{display:block;font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:3px}
.form-input{width:100%;border:1.5px solid <?=$CB?>;border-radius:9px;padding:8px 11px;font-size:12px;font-family:'DM Sans',sans-serif;outline:none;background:<?=$BG?>;color:<?=$TX?>;text-transform:uppercase}
.form-input:focus{border-color:<?=$P2?>;background:#fff}
.form-input[type=password],.form-input[type=email],.form-input[type=date],.form-input[type=time],.form-input[type=number]{text-transform:none}
select.form-input{cursor:pointer}.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:9px}.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:9px}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(27,74,107,.55);z-index:9000;justify-content:flex-start;align-items:flex-start;padding:20px 12px;overflow-y:auto}
.modal-overlay.open{display:flex;isolation:isolate}
.modal{background:#fff;border-radius:17px;padding:22px;width:100%;max-width:780px;box-shadow:0 24px 64px rgba(27,74,107,.3);border:1px solid <?=$CB?>;margin:auto}
.modal-sm{max-width:460px}.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.modal-title{font-size:11px;font-weight:900;color:<?=$P1?>;letter-spacing:3px;text-transform:uppercase}
.modal-close{background:<?=$BG?>;border:none;border-radius:7px;width:28px;height:28px;cursor:pointer;font-size:13px;color:<?=$MU?>}
.ci-steps{display:flex;gap:3px;margin-bottom:13px;flex-wrap:wrap}

.ci-step{flex:1;min-width:60px;text-align:center;padding:8px 4px;border-radius:9px;border:1px solid <?=$CB?>;background:<?=$BG?>}
.ci-step.done{background:#EAF5F0;border-color:#8DCFBA}.ci-step.cur{background:#EBF5FB;border-color:#A9D0E8}.ci-step.brk{background:#FFF8EE;border-color:#F5D5A0}
.ci-step-icon{font-size:13px}.ci-step-lbl{font-size:7px;font-weight:900;color:<?=$MU?>;letter-spacing:.5px;text-transform:uppercase;line-height:1.3}
.ci-step.done .ci-step-lbl{color:#1E7A5C}.ci-step.cur .ci-step-lbl{color:#1B5E8C}.ci-step.brk .ci-step-lbl{color:#C07A1A}
.ci-step-val{font-size:11px;font-weight:900;margin-top:2px}
.alert-bar{background:#FEF8EE;border:1px solid #F5D5A0;border-left:4px solid #C07A1A;border-radius:11px;padding:10px 16px;margin-bottom:12px;display:flex;gap:18px;flex-wrap:wrap;font-size:9px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:#C07A1A}
.pipeline-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:9px}
.pipe-col-header{display:flex;align-items:center;gap:4px;margin-bottom:8px;padding:6px 10px;border-radius:8px;font-size:8px;font-weight:900;text-transform:uppercase;letter-spacing:1px}
.pipe-card{background:#fff;border:1px solid <?=$CB?>;border-radius:9px;padding:9px 11px;cursor:pointer;margin-bottom:6px;border-top:3px solid}.pipe-card:hover{background:<?=$BG?>}
.script-card{background:#fff;border:1px solid <?=$CB?>;border-radius:13px;overflow:hidden;cursor:pointer}
.script-card.open .script-header{background:linear-gradient(135deg,<?=$P1?>,<?=$P2?>)}
.script-header{padding:12px 15px;display:flex;justify-content:space-between;align-items:center;background:linear-gradient(to right,<?=$BG?>,#fff)}
.script-body{padding:14px 16px;display:none}.script-card.open .script-body{display:block}
.script-pre{font-size:9px;color:<?=$TX?>;line-height:1.9;white-space:pre-wrap;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:9px;padding:13px 15px;margin-bottom:10px}
.chat-fab{position:fixed;bottom:18px;right:18px;width:50px;height:50px;background:<?=$P1?>;border:none;border-radius:50%;cursor:pointer;font-size:20px;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(27,74,107,.4);z-index:800}
.chat-fab-badge{position:absolute;top:-4px;right:-4px;background:#EF4444;color:#fff;border-radius:20px;padding:2px 6px;font-size:10px;font-weight:900;min-width:18px;text-align:center;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.2);line-height:1}
.chat-panel{position:fixed;bottom:78px;right:18px;width:320px;max-height:460px;background:#fff;border:1px solid <?=$CB?>;border-radius:16px;box-shadow:0 16px 48px rgba(27,74,107,.25);z-index:800;display:flex;flex-direction:column;overflow:hidden}
.chat-panel.hidden{display:none}
.chat-messages{flex:1;overflow-y:auto;padding:11px;display:flex;flex-direction:column;gap:7px;min-height:180px}
.chat-msg{padding:7px 10px;border-radius:10px;max-width:86%;font-size:10px;line-height:1.5}
.chat-msg.me{background:<?=$P1?>;color:#fff;align-self:flex-end;border-bottom-right-radius:3px}
.chat-msg.them{background:<?=$BG?>;color:<?=$TX?>;align-self:flex-start;border:1px solid <?=$CB?>;border-bottom-left-radius:3px}
.chat-msg-meta{font-size:7px;opacity:.65;margin-bottom:2px;text-transform:uppercase;letter-spacing:.5px;font-weight:700}
.notif-dropdown{position:absolute;top:52px;right:0;width:290px;background:#fff;border:1px solid <?=$CB?>;border-radius:13px;box-shadow:0 8px 32px rgba(27,74,107,.18);z-index:900;display:none}
.notif-dropdown.open{display:block}
.portal-card{background:#fff;border:1px solid <?=$CB?>;border-radius:10px;padding:11px 14px;border-left:3px solid <?=$P2?>;margin-bottom:8px}
.toast{position:fixed;bottom:80px;left:50%;transform:translateX(-50%) translateY(14px);z-index:9000;background:<?=$P1?>;color:#fff;border-radius:11px;padding:9px 20px;font-size:9px;font-weight:900;box-shadow:0 8px 24px rgba(27,74,107,.35);transition:all .3s;opacity:0;pointer-events:none;letter-spacing:1.5px;text-transform:uppercase}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
footer{text-align:center;padding:9px;border-top:1px solid <?=$CB?>;font-size:7px;color:<?=$MU?>;background:#fff;letter-spacing:2px;text-transform:uppercase}
.tab-pane{display:none}.tab-pane.active{display:block}
.member-row{transition:all .2s ease}
.member-row:hover{background-color:#F0F9FF !important;transform:scale(1.003);box-shadow:0 4px 12px rgba(27,74,107,.1);z-index:10;position:relative}
.search-match{animation:highlight .5s ease}
@keyframes highlight{0%{background:#FFF9C4}100%{background:transparent}}
/* ── DARK MODE (opcional) ── */
[data-theme="dark"]{background:#0F1923 !important;color:#D0E4F0}
[data-theme="dark"] body{background:#0F1923;color:#D0E4F0}
[data-theme="dark"] .card{background:#162030;border-color:#1E3045}
[data-theme="dark"] .card-header{background:linear-gradient(to right,#162030,#1A2840);border-color:#1E3045}
[data-theme="dark"] .card-title{color:#7EB8D8}
[data-theme="dark"] nav{background:#162030;border-color:#1E3045}
[data-theme="dark"] .ntab{color:#5B7A8F}
[data-theme="dark"] .ntab.active{color:#7EB8D8;border-bottom-color:#7EB8D8}
[data-theme="dark"] th{background:#0F1923;color:#5B8DB8}
[data-theme="dark"] td{border-color:#1E304520}
[data-theme="dark"] tr:hover td{background:#1A2840}

[data-theme="dark"] .form-input{background:#0F1923;border-color:#1E3045;color:#D0E4F0}
[data-theme="dark"] .form-input:focus{border-color:#2876A8;background:#162030}
[data-theme="dark"] .btn-gh{background:#1A2840;color:#7A90A4;border-color:#1E3045}
[data-theme="dark"] .stat-card{background:#162030;border-color:#1E3045}
[data-theme="dark"] .alert-bar{background:#2A1F0E;border-color:#5C3A0A;color:#F5D5A0}
[data-theme="dark"] .ci-step{background:#0F1923;border-color:#1E3045}
[data-theme="dark"] .ci-step.done{background:#0D2217;border-color:#1E5C3A}
[data-theme="dark"] .ci-step.cur{background:#0D1E30;border-color:#1E3A5C}
[data-theme="dark"] .portal-card{background:#162030;border-color:#1E3045}
[data-theme="dark"] .notif-dropdown{background:#162030;border-color:#1E3045}
[data-theme="dark"] .chat-panel{background:#162030;border-color:#1E3045}
[data-theme="dark"] .chat-msg.them{background:#0F1923;border-color:#1E3045;color:#D0E4F0}
[data-theme="dark"] .pipe-card{background:#162030;border-color:#1E3045}
[data-theme="dark"] .pipe-card:hover{background:#1A2840}
[data-theme="dark"] footer{background:#162030;border-color:#1E3045;color:#5B7A8F}
[data-theme="dark"] .modal{background:#162030;border-color:#1E3045}
[data-theme="dark"] .modal-close{background:#0F1923}
[data-theme="dark"] .script-pre{background:#0F1923;border-color:#1E3045}
[data-theme="dark"] .script-header{background:linear-gradient(to right,#162030,#1A2840)}
[data-theme="dark"] .page-title h1{color:#7EB8D8}
[data-theme="dark"] .info-box{background:#0F1923;border-color:#1E3045}
[data-theme="dark"] select{background:#162030;color:#D0E4F0}
[data-theme="dark"] .nbadge{filter:brightness(.8)}
/* ── TICKET CARDS ── */
.ticket-card{background:#fff;border:1px solid <?=$CB?>;border-radius:13px;padding:13px 14px;transition:box-shadow .15s,transform .15s}
.ticket-card:hover{box-shadow:0 6px 18px rgba(27,74,107,.12);transform:translateY(-1px)}
.ticket-card.tkt-cerrada{opacity:.65}
.tkt-pill{background:transparent;border:none;border-radius:7px;padding:4px 10px;font-size:8px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif;letter-spacing:1px;text-transform:uppercase;color:<?=$MU?>;transition:background .12s,color .12s}
.tkt-pill:hover{background:rgba(27,74,107,.07)}
.tkt-pill.tkt-pill-on{background:#fff;color:<?=$P1?>;box-shadow:0 1px 4px rgba(27,74,107,.15)}
[data-theme="dark"] .ticket-card{background:#162030;border-color:#1E3045}
[data-theme="dark"] .tkt-pill.tkt-pill-on{background:#0F1923;color:#7EB8D8}
/* ── PROFILE MODAL INNER TABS ── */
#profile-content .ntab,#profile-modal .ntab{background:transparent;border:none;cursor:pointer;padding:9px 13px;font-size:8px;font-weight:900;white-space:nowrap;border-bottom:3px solid transparent;color:#7A90A4;font-family:'DM Sans',sans-serif;letter-spacing:1.5px;text-transform:uppercase;display:inline-flex;align-items:center;gap:5px}
#profile-content .ntab.active,#profile-modal .ntab.active{color:#1B4A6B;border-bottom-color:#1B4A6B}
#profile-content nav,#profile-content .profile-nav{display:flex;overflow-x:auto;border-bottom:2px solid #C8DFF0;margin-bottom:16px;background:#fff;padding:0;gap:0}
[data-theme="dark"] #profile-content .ntab{color:#5B7A8F}
[data-theme="dark"] #profile-content .ntab.active{color:#7EB8D8;border-bottom-color:#7EB8D8}
[data-theme="dark"] #profile-content nav,[data-theme="dark"] #profile-content .profile-nav{background:#162030;border-color:#1E3045}

/* Estilos para el Modal de Configuración */
/* ── PIPELINE STYLES ── */
.pipe-temp-badge {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 2px 7px; border-radius: 20px; font-size: 8px; font-weight: 900;
    text-transform: uppercase; letter-spacing: .5px; white-space: nowrap;
}
.pipe-temp-hot  { background: #FEF0EE; color: #C03A1A; border: 1px solid #F5B8A8; }
.pipe-temp-warm { background: #FEF8EE; color: #C07A1A; border: 1px solid #F5D5A0; }
.pipe-temp-cold { background: #EBF5FB; color: #1B5E8C; border: 1px solid #A9D0E8; }
.pipe-temp-aep  { background: #F3F0FB; color: #5B3FAF; border: 1px solid #C2B0E8; }
.pipe-temp-t65  { background: #EAF5F0; color: #1E7A5C; border: 1px solid #8DCFBA; }
.pipe-col-header { padding: 8px 12px; border-radius: 10px; margin-bottom: 10px; font-weight: 900; font-size: 10px; display: flex; justify-content: space-between; align-items: center; }
.pipe-card { border-radius: 10px; padding: 11px 12px; margin-bottom: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); transition: box-shadow .15s, transform .15s; }
.pipe-card:hover { box-shadow: 0 5px 14px rgba(0,0,0,0.12); transform: translateY(-1px); }

/* Efecto visual para las cajas del reporte diario */
.reporte-box-btn:hover {
    border-color: #2876A8 !important; /* Usa tu variable $P2 */
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(27,74,107,0.15) !important;
    cursor: pointer;
}
</style>
</head>
<body data-admin="<?=$admin?'1':'0'?>" data-uid="<?=$uid?>">
<header>
<div class="brand">
    <img src="https://withisabelfuentes.com/wp-content/uploads/2026/04/logo.png" alt="Logo" style="width: 30px; height: auto; margin-right: 10px;">
    <div>
        <div class="brand-script">Medicare</div>
        <div class="brand-sub">WITH ISABEL</div>
    </div>
</div>
<div style="width:1px;height:28px;background:rgba(255,255,255,.2);flex-shrink:0"></div>
<div style="display:flex;gap:5px;flex:1;flex-wrap:wrap;overflow:hidden">
<?php if($urgent_tks>0):?><span class="hpill" style="background:rgba(184,50,50,.3);color:#FCA5A5;border:1px solid rgba(184,50,50,.4)"> <?=$urgent_tks?> URGENTE<?=$urgent_tks>1?'S':''?></span><?php endif;?>
<?php if($open_tks>0):?><span class="hpill" style="background:rgba(184,50,50,.2);color:#FCA5A5;border:1px solid rgba(184,50,50,.3)">◈ <?=$open_tks?> TICKETS</span><?php endif;?>
<?php if($pending_llam>0):?><span class="hpill" style="background:rgba(192,122,26,.25);color:#FDE68A;border:1px solid rgba(192,122,26,.35)">◌ <?=$pending_llam?> LLAMADAS</span><?php endif;?>
<?php if($my_ci&&$my_ci['check_in']&&!$my_ci['check_out']):?><span class="hpill" style="background:rgba(30,122,92,.25);color:#6EE7B7;border:1px solid rgba(30,122,92,.35)"> <?=substr($my_ci['check_in'],0,5)?></span><?php endif;?>


<button class="hpill" id="btn-reg-llamada" style="background:#1B4A6B;color:#fff;border:1px solid #C8DFF0;cursor:pointer;display:flex;align-items:center;gap:4px" onclick="openLlamadaRapidaModal()"><span>📞</span> REGISTRAR LLAMADA</button>

</div>
<div style="display:flex;gap:7px;align-items:center;flex-shrink:0;position:relative">
</div>
<div style="display:flex;gap:7px;align-items:center;flex-shrink:0;position:relative">

<a href="/luna/" class="hbtn" title="Ir a Luna" style="text-decoration:none">🌙 LUNA</a>
<div style="position:relative"><button onclick="toggleDarkMode()" class="hbtn" id="dark-btn">🌙</button>
<button class="hbtn" onclick="toggleNotifPanel()" title="Notificaciones">🔔<?php if($notif_unread>0):?><span class="hbadge"><?=$notif_unread?></span><?php endif;?></button>
<div id="notif-dropdown" class="notif-dropdown">
<div style="padding:10px 14px;border-bottom:1px solid <?=$CB?>;display:flex;justify-content:space-between;align-items:center"><span style="font-size:9px;font-weight:900;color:<?=$P1?>;letter-spacing:2px;text-transform:uppercase">NOTIFICACIONES</span><button onclick="markAllNotifRead()" style="font-size:8px;color:<?=$P2?>;background:none;border:none;cursor:pointer;font-weight:900;font-family:'DM Sans',sans-serif;text-transform:uppercase">MARCAR LEÍDAS</button></div>
<div id="notif-list" style="max-height:240px;overflow-y:auto;padding:5px 0"><div style="padding:14px;text-align:center;font-size:8px;color:<?=$MU?>;text-transform:uppercase">CARGANDO...</div></div>
</div>
</div>
<a href="logout.php" class="huser"><?=av(h($user['iniciales']),h($user['color']),24)?><div><div class="huser-name"><?=h(explode(' ',$user['nombre'])[0])?></div><div class="huser-role"><?=$admin?'ADMIN':'EMPLEADO'?> · SALIR</div></div></a>
<?php if($admin):?><button class="hbtn" onclick="openFinance()" title="Portal Financiero" style="font-size:13px;padding:5px 9px">💰</button><?php endif;?>
</div>
</header>

<nav id="main-nav">
<?php foreach($tabs as $t): $tn = $tabn[$t] ?? $t; ?>
<button class="ntab<?=$t==='DASHBOARD'?' active':''?>" onclick="showTab('<?=$t?>')" data-tab="<?=$t?>">
<span><?=$ticon[$t]??'▪'?></span><?=$tn?>
<?php if($t==='TICKETS' && $mis_tickets_abiertos > 0):?><span class="nbadge" style="background:#FDF0EE;color:#B83232;border:1px solid #EFA09A"><?=$mis_tickets_abiertos?></span>
<?php elseif($t==='MIEMBROS'):?><span class="nbadge" style="background:<?=$BG?>;color:<?=$MU?>;border:1px solid <?=$CB?>"><?=count($members)?></span>
<?php elseif($t==='RETENCION'&&$alertas_hoy>0):?><span class="nbadge" style="background:#FEF8EE;color:#C07A1A;border:1px solid #F5D5A0"><?=$alertas_hoy?></span>
<?php elseif($t==='COMUNICACION'&&$chat_unread>0):?><span class="nbadge" style="background:#FEF8EE;color:#C07A1A;border:1px solid #F5D5A0"><?=$chat_unread?></span>
<?php endif;?>
</button>
<?php endforeach;?>
</nav>
<main>
<div class="page-title"><span id="tab-icon" style="font-size:14px">▣</span><h1 id="tab-title">DASHBOARD</h1></div>
<!-- DASHBOARD -->
<div id="tab-DASHBOARD" class="tab-pane active">
<?php if(!empty($alertas_hoy) && $alertas_hoy>0): ?>
<div class="alert-bar" style="background:#FEF8EE;border-left-color:#C07A1A;color:#C07A1A">
<?=$alertas_hoy?> LLAMADA<?=$alertas_hoy>1?'S':''?> DE RETENCIÓN PENDIENTE<?=$alertas_hoy>1?'S':''?> PARA HOY (7/30/60/90 DÍAS)
</div>
<?php endif;?>
<?php $aitems=array_filter([$urgent_tks>0?" $urgent_tks URGENTE".($urgent_tks>1?'S':''):null,$mis_tickets_abiertos>0?"◈ $mis_tickets_abiertos TICKETS ABIERTOS":null,$pending_llam>0?"◌ $pending_llam LLAMADAS PENDIENTES":null,$t65_count>0?" $t65_count T65 URGENTE":null,$apps_proceso>0?" $apps_proceso APPS EN PROCESO":null]);if($aitems):?><div class="alert-bar"><?php foreach($aitems as $a)echo"<span>$a</span>";?></div><?php endif;?>
<?php if($cue_alerta_count > 0): ?>
<div class="alert-bar" style="background:#F0EBF8;border-left-color:#7B2D8B;color:#7B2D8B;cursor:pointer;display:flex;justify-content:space-between;align-items:center" onclick="showTab('CONTACTOS')">
  <span>🤝 <?=$cue_alerta_count?> CUENTA<?=$cue_alerta_count>1?'S':''?> SIN VISITAR — REVISAR →</span>
  <?php if($referidos_pendientes > 0): ?><span style="background:#7B2D8B;color:#fff;border-radius:20px;padding:2px 10px;font-size:8px;font-weight:900"><?=$referidos_pendientes?> REFERIDOS PENDIENTES</span><?php endif; ?>
</div>
<?php endif; ?>
<?php if(!$admin):$steps=[['ci','CHECK-IN'],['lo','ALMUERZO'],['li','REGRESO  DE ALMUERZO.'],['bo','BREAK'],['bi','REGRESO DE BREAK'],['co','CHECK-OUT']];$vals=['ci'=>$my_ci['check_in']??null,'lo'=>$my_ci['lunch_out']??null,'li'=>$my_ci['lunch_in']??null,'bo'=>$my_ci['break_out']??null,'bi'=>$my_ci['break_in']??null,'co'=>$my_ci['check_out']??null];$bk=['bo','bi'];$ns=null;foreach($steps as $s){if(!$vals[$s[0]]){$ns=$s;break;}}$worked=calc_hours($vals['ci'],$vals['lo'],$vals['li'],$vals['co'],$vals['bo'],$vals['bi']);?>
<div class="card" style="border-top:3px solid <?=$P1?>;margin-bottom:14px">
<div class="card-header"><div class="card-title">CHECK IN— <?=$today?></div><?php if($worked):?><span style="background:#EAF5F0;color:#1E7A5C;border:1px solid #8DCFBA;border-radius:20px;padding:3px 11px;font-size:9px;font-weight:900"> <?=$worked?></span><?php endif;?></div>
<div style="padding:14px 16px">
<div class="ci-steps"><?php foreach($steps as $s):$done=!empty($vals[$s[0]]);$cur=!$done&&$ns&&$ns[0]===$s[0];?><div class="ci-step<?=$done?' done':($cur?' cur':'').' '.(in_array($s[0],$bk)?' brk':'')?>"><div class="ci-step-icon"><?=$done?'✓':($cur?'◐':'○')?></div><div class="ci-step-lbl"><?=$s[1]?></div><?php if($vals[$s[0]]):?><div class="ci-step-val" style="color:<?=$done?'#1E7A5C':'#1B5E8C'?>"><?=substr($vals[$s[0]],0,5)?></div><?php endif;?></div><?php endforeach;?></div>
<?php if($ns):?><button class="btn btn-p btn-full" style="margin-bottom: 8px;" onclick="doCheckin('<?=$ns[0]?>')"><?=in_array($ns[0],$bk)?' ':'◐'?> <?=$ns[1]?></button><?php endif;?>
<!-- BOTÓN INTELIGENTE — detecta siguiente paso automáticamente -->
<button class="btn btn-p btn-full" style="margin-bottom:10px" onclick="registroHora()">
◐ REGISTRAR SIGUIENTE MOVIMIENTO
</button>
<!-- BOTONES LIBRES — cualquier orden -->
<div style="margin-top:10px;border-top:1px solid <?=$CB?>;padding-top:10px">
<div style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:7px">REGISTRO LIBRE — CUALQUIER ORDEN</div>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px">
<?php foreach(['ci'=>['CHECK-IN','check_in'],'lo'=>['SAL.ALM.','lunch_out'],'li'=>['REG.ALM.','lunch_in'],'bo'=>['SAL.BREAK','break_out'],'bi'=>['REG.BREAK','break_in'],'co'=>['SALIDA','check_out']] as $k=>[$lbl,$dbcol]):$vl=$my_ci[$dbcol]??null;?>
<?php $ico=['ci'=>'◐','lo'=>' ','li'=>' ','bo'=>' ','bi'=>' ','co'=>' '];$cls=$vl?($k==='co'?'btn-re':($k==='lo'||$k==='bo'?'btn-am':'btn-gr')):'btn-gh';$lbl_show=$vl?('✓ '.$lbl.': '.substr($vl,0,5)):($ico[$k].' '.$lbl);?>
<button class="btn <?=$cls?> btn-sm" style="padding:10px 6px;font-size:9px;font-weight:900;letter-spacing:.3px" onclick="doCheckin('<?=$k?>')"><?=$lbl_show?></button>
<?php endforeach;?>
</div>
</div>
<?php if($my_ci&&$my_ci['check_out']):?><div style="margin-top:9px;background:#EAF5F0;border:1px solid #8DCFBA;border-radius:8px;padding:8px;font-size:8px;font-weight:900;color:#1E7A5C;text-align:center;text-transform:uppercase">✓ DÍA COMPLETO · <?=$worked?></div>
<?php elseif(!$my_ci):?><div style="margin-top:9px;background:#FEF8EE;border:1px solid #F5D5A0;border-radius:8px;padding:8px;font-size:8px;color:#C07A1A;text-transform:uppercase">⚠ SIN CHECK-IN</div><?php endif;?>
</div>
</div>
<?php endif;?>

<div class="stats-row">
<?php foreach([
  ['◉', count($members), 'TOTAL', 'CONTACTOS', $P1, "showTab('MIEMBROS')"],
  ['✓', $activos_total, 'ACTIVOS', 'CON PÓLIZA', $G, "irAMiembros('ACTIVE')"],
  [' ', $futuros_efectivos, 'FUTUROS', 'EFECTIVOS '.$next_month_label, '#1E7A8C', "showTab('PIPELINE')"],
  ['✗', $cancelados_mes, 'CANCELADOS', 'ESTE MES', $R, "irAMiembros('CANCELED')"],
  ['✗', $cancelados_total, 'CANCELADOS', 'TOTAL', $R, "irAMiembros('CANCELED')"],
  [' ', $apps_proceso, 'META', 'PRÓXIMO MES', $A, "showTab('PIPELINE')"],
  ['◈', $urgent_tks, 'URGENTES', 'TICKETS', $R, "showTab('TICKETS')"],
  ['◐', $followups, 'PIPELINE', 'IN PROCESS + RTE', '#1B5E8C', "showTab('PIPELINE')"]
] as [$ic, $v, $lbl, $sub, $col, $onclick]):?>
<div class="stat-card" style="color:<?=$col?>; cursor:pointer;" onclick="<?=$onclick?>">
  <div class="stat-icon"><?=$ic?> <?=$lbl?></div>
  <div class="stat-val" style="color:<?=$col?>"><?=$v?></div>
  <div style="font-size:8px;color:<?=$MU?>;margin-top:2px;letter-spacing:1px;text-transform:uppercase"><?=$sub?></div>
</div>
<?php endforeach;?>
</div>
<?php if(count($t65_alertas)>0):?>
<div class="card" style="border-top:4px solid #C05C1A;margin-bottom:14px;background:#FFFBF5">
<div class="card-header">
<div><div class="card-title" style="color:#C05C1A"> ALERTAS T65 — PRÓXIMOS 90 DÍAS</div><div class="card-sub"><?=count($t65_alertas)?> MIEMBRO<?=count($t65_alertas)>1?'S':''?> POR CUMPLIR 65</div></div>
<span onclick="showTab('MIEMBROS')" style="font-size:8px;font-weight:900;color:#C05C1A;cursor:pointer;text-transform:uppercase;letter-spacing:.5px">VER TODOS →</span>
</div>
<div style="padding:10px 12px;max-height:260px;overflow-y:auto">
<?php
$t65_grupos=[
  ['label'=>'⚠ URGENTE — 0 A 30 DÍAS','color'=>'#B83232','bg'=>'#FDF0EE','border'=>'#EFA09A','min'=>0,'max'=>30],
  ['label'=>' PRÓXIMO — 31 A 60 DÍAS','color'=>'#C07A1A','bg'=>'#FDF6EC','border'=>'#F5D5A0','min'=>31,'max'=>60],
  ['label'=>' EN HORIZONTE — 61 A 90 DÍAS','color'=>'#2876A8','bg'=>'#EBF4F9','border'=>'#C8DFF0','min'=>61,'max'=>90],
];
foreach($t65_grupos as $grp):
  $grp_items=array_filter($t65_alertas,fn($ta)=>$ta['dias_restantes']>=$grp['min']&&$ta['dias_restantes']<=$grp['max']);
  if(empty($grp_items)) continue;
?>
<div style="font-size:7px;font-weight:900;color:<?=$grp['color']?>;text-transform:uppercase;letter-spacing:1.2px;padding:3px 8px;background:<?=$grp['bg']?>;border-left:3px solid <?=$grp['border']?>;border-radius:4px;margin-bottom:5px;margin-top:10px"><?=$grp['label']?></div>
<?php foreach($grp_items as $ta):?>
<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;border-bottom:1px solid #F5D5A0;gap:8px">
  <div style="flex:1;min-width:0;cursor:pointer" onclick="openProfile(<?=$ta['id']?>)">
    <div style="font-size:10px;font-weight:900;color:<?=$TX?>;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($ta['nombre'].' '.$ta['apellido'])?></div>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:1px"><?=!empty($ta['telefono'])?'📞 '.h($ta['telefono']):''?><?=!empty($ta['carrier'])?' · '.h($ta['carrier']):''?><?=!empty($ta['estado'])?' · '.h($ta['estado']):''?></div>
  </div>
  <div style="display:flex;align-items:center;gap:5px;flex-shrink:0">
    <span style="background:<?=$grp['bg']?>;color:<?=$grp['color']?>;border:1px solid <?=$grp['border']?>;border-radius:20px;padding:2px 8px;font-size:8px;font-weight:900;white-space:nowrap"><?=($ta['dias_restantes']<=0?'¡HOY!':$ta['dias_restantes'].' DÍAS')?></span>
    <span style="font-size:8px;color:<?=$MU?>;white-space:nowrap"><?=date('m/d/Y',strtotime($ta['fecha_65']))?></span>
    <?php if(!empty($ta['telefono'])):?><a href="tel:<?=h($ta['telefono'])?>" style="font-size:9px;background:<?=$G?>;color:#fff;border-radius:6px;padding:2px 7px;text-decoration:none;font-weight:900" title="LLAMAR" onclick="event.stopPropagation()">📞</a><?php endif;?>
  </div>
</div>
<?php endforeach; endforeach;?>
</div>
</div>
<?php endif;?>
<?php
$meta_mensual = 20;
$progreso = $meta_mensual > 0 ? min(round(($apps_proceso/$meta_mensual)*100),100) : 0;
?>
<div class="card" style="margin-bottom:14px;padding:14px 17px">
<div style="display:flex;justify-content:space-between;margin-bottom:8px">
<span style="font-size:9px;font-weight:900;letter-spacing:1px;color:<?=$P1?>;text-transform:uppercase">
    META DE PRODUCCIÓN: <?=$next_month_label?></span>
<span style="font-size:9px;font-weight:900;color:<?=$G?>"><?=$apps_proceso?> / <?=$meta_mensual?> NEW ENROLLMENTS</span>
</div>
<div style="width:100%;height:10px;background:<?=$BG?>;border-radius:10px;overflow:hidden;border:1px solid <?=$CB?>">
<div style="width:<?=$progreso?>%;height:100%;background:linear-gradient(to right,<?=$P2?>,<?=$G?>);transition:width .5s;border-radius:10px"></div>
</div>
<div style="font-size:8px;color:<?=$MU?>;margin-top:4px;letter-spacing:1px;text-transform:uppercase"><?=$progreso?>% DE LA META</div>
</div>
<?php if(count($ef_mes)>0):?>
<div class="card" style="border-top:3px solid #1E7A5C;margin-bottom:14px">
<div class="card-header"><div><div class="card-title"> EFECTIVOS DEL MES — <?=strtoupper(date('F Y'))?></div><div class="card-sub"><?=count($ef_mes)?> MIEMBRO<?=count($ef_mes)>1?'S':''?> · CHECKLIST</div></div></div>
<div style="overflow-x:auto"><table>
<tr><th>MIEMBRO</th><th>EFECTIVA</th><th>CARRIER</th><th>APP✉</th><th>APROBADA</th><th>HRA</th><th>DR.✓</th><th>DRIVE</th><th>SMS</th><th>LLAM.B</th><th></th></tr>
<?php foreach($ef_mes as $m):?><tr><td><div style="font-weight:900;font-size:9px;color:<?=$P1?>;cursor:pointer" onclick="openProfile(<?=$m['id']?>)"><?=h($m['apellido'].', '.$m['nombre'])?></div><div style="font-size:8px;color:<?=$MU?>"><?=h(explode(' ',$m['agente_nombre']??'')[0])?></div></td><td style="font-size:8px;color:<?=$MU?>"><?=$m['fecha_efectiva']??'—'?></td><td><?php if($m['carrier']):?><span style="background:#EBF5FB;color:#1B5E8C;border:1px solid #A9D0E8;border-radius:20px;padding:1px 7px;font-size:8px;font-weight:900"><?=h($m['carrier'])?></span><?php else:?>—<?php endif;?></td><td><?=chk($ef_checks,$m['id'],'app_enviada')?></td><td><?=chk($ef_checks,$m['id'],'app_aprobada')?></td><td><?=chk($ef_checks,$m['id'],'hra')?></td><td><?=chk($ef_checks,$m['id'],'doctor_verificado')?></td><td><?=chk($ef_checks,$m['id'],'id_drive')?></td><td><?=chk($ef_checks,$m['id'],'sms_enviado')?></td><td><?=chk($ef_checks,$m['id'],'llam_bienvenida')?></td><td><button class="btn btn-b btn-sm" onclick="openProfile(<?=$m['id']?>)">◉</button></td></tr>
<?php endforeach;?>
</table></div>
</div>
<?php endif;?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:11px;margin-bottom:11px">

<div class="card"><div class="card-header"><div class="card-title">◌ PROSPECTOS PENDIENTES</div><button class="btn btn-gh btn-sm" onclick="showTab('PIPELINE')">PIPELINE →</button></div>
<?php
$prosp_pend = array_filter($members, function($m) use($admin,$uid){
  if(!$admin && (int)($m['agente_id']??0)!==$uid) return false;
  return !in_array($m['estado'], ['ACTIVE','CANCELED','DENIED','CERRADO','DISENROLLED']);
});
$prosp_pend = array_slice(array_values($prosp_pend), 0, 12);
if(empty($prosp_pend)):?><div style="padding:18px;text-align:center;font-size:8px;color:<?=$MU?>;text-transform:uppercase">✓ SIN PROSPECTOS PENDIENTES</div>
<?php else: foreach($prosp_pend as $m):?><div style="padding:8px 15px;border-bottom:1px solid <?=$CB?>;display:flex;gap:8px;align-items:center;cursor:pointer" onclick="openProfile(<?=$m['id']?>)"><?=av(h($m['agente_ini']??'?'),h($m['agente_color']??$P2),26)?><div style="flex:1"><div style="font-weight:900;font-size:10px;color:<?=$P1?>"><?=h($m['apellido'].', '.$m['nombre'])?></div><div style="font-size:8px;color:<?=$MU?>"><?=h($m['estado']?:'PROSPECT')?> · <?=h($m['ciudad'])?></div></div><?=badge($m['estado']?:'PROSPECT',true)?></div><?php endforeach; endif;?>
</div>
<div class="card"><div class="card-header"><div class="card-title">◈ TICKETS ABIERTOS</div><button class="btn btn-gh btn-sm" onclick="showTab('TICKETS')">VER →</button></div>
<?php foreach(array_slice(array_values($tickets_open),0,6) as $t):?><div style="padding:8px 15px;border-bottom:1px solid <?=$CB?>;display:flex;gap:8px;align-items:center;cursor:pointer" onclick="openProfile(<?=$t['miembro_id']?>)"><div style="flex:1"><div style="font-weight:900;font-size:10px;color:<?=$P1?>"><?=h($t['miembro_nombre']??'—')?></div><div style="font-size:8px;color:<?=$MU?>"><?=h(substr($t['descripcion'],0,50))?></div></div><?=badge($t['prioridad'],true)?></div><?php endforeach;?>
<?php if($open_tks===0):?><div style="padding:18px;text-align:center;font-size:8px;color:<?=$MU?>;text-transform:uppercase">✓ SIN TICKETS</div><?php endif;?>
</div>
</div>
<?php if($admin):?><div class="card"><div class="card-header"><div class="card-title">◐ ASISTENCIA HOY</div><button class="btn btn-gh btn-sm" onclick="showTab('ASISTENCIA')">DETALLE →</button></div><div style="display:flex;overflow-x:auto;padding:11px 14px;gap:9px">
<?php foreach($agents as $ag):$ci=array_filter($today_ckins,fn($c)=>$c['agente_id']==$ag['id']);$ci=reset($ci)?:null;$w=calc_hours($ci['check_in']??null,$ci['lunch_out']??null,$ci['lunch_in']??null,$ci['check_out']??null,$ci['break_out']??null,$ci['break_in']??null);?>
<div style="min-width:105px;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:11px;padding:10px 12px;text-align:center;border-top:3px solid <?=h($ag['color'])?>"><?=av(h($ag['iniciales']),h($ag['color']),30)?><div style="font-weight:900;font-size:10px;color:<?=$P1?>;margin-top:5px"><?=h(explode(' ',$ag['nombre'])[0])?></div><div style="font-size:8px;color:<?=$ci&&$ci['check_in']?'#1E7A5C':'#B83232'?>;font-weight:800;margin-top:2px"><?=$ci&&$ci['check_in']?'✓ '.substr($ci['check_in'],0,5):'—'?></div><div style="font-size:11px;font-weight:900;color:<?=$P1?>"><?=$w??'—'?></div></div>
<?php endforeach;?></div></div><?php endif;?>
</div><!-- /DASHBOARD -->

<!-- ENTRENAMIENTO (ACADEMIA) -->
<div id="tab-ENTRENAMIENTO" class="tab-pane">
<?php
$TRAINING_CALENDAR = [
 ['week'=>1,'fecha'=>'May 7','tema'=>'Cómo funcionan las Redes (Networks) de Medicare',
  'objetivo'=>'Entender HMO vs PPO, qué es la red de médicos, y cómo explicarlo al miembro sin jerga médica.',
  'contenido'=>['Diferencia entre HMO y PPO en lenguaje simple','Cómo saber si un doctor está en la red','Qué pasa si el miembro va fuera de la red','Beneficios adicionales: visión, dental, OTC, transporte'],
  'roleplay'=>['escenario'=>'Un prospecto pregunta: ¿Puedo seguir viendo a mi doctor de siempre?','prospecto'=>'Llevo 10 años con el Dr. Martínez. No quiero cambiar de doctor. ¿Si me cambio a este plan lo pierdo?','objetivo_agente'=>'Calificar si el doctor está en red, explicar el proceso sin asustar al prospecto, y agendar cita con Isabel.'],
  'tarea'=>'Buscar 3 clínicas en Van Nuys y verificar si están en red con SCAN o Anthem.'],
 ['week'=>2,'fecha'=>'May 14','tema'=>'Cómo Hacer Mejores Llamadas — La Llamada Perfecta',
  'objetivo'=>'Dominar los primeros 30 segundos, calificar rápido, y agendar con Isabel sin perder el lead.',
  'contenido'=>['Los 3 errores más comunes en la primera llamada','Script de apertura que funciona','Cómo calificar: 3 preguntas clave','Cómo manejar el "ya tengo Medicare" o "no me interesa"','Cómo cerrar la llamada y agendar con Isabel'],
  'roleplay'=>['escenario'=>'Lead nuevo de Facebook que respondió a un anuncio de beneficios dentales.','prospecto'=>'Hola, vi su anuncio en Facebook. Tengo 67 años y ya tengo Medicare pero pago mucho por el dentista.','objetivo_agente'=>'Responder en menos de 60 segundos, calificar con 3 preguntas, y agendar con Isabel ese mismo día.'],
  'tarea'=>'Grabar una llamada simulada de 3 minutos y enviarsela a Isabel para feedback.'],
 ['week'=>3,'fecha'=>'May 21','tema'=>'El Protocolo de Retención: Day 1 / 15 / 30 / 90',
  'objetivo'=>'Ejecutar el sistema de retención sin que ningún miembro nuevo se pierda en los primeros 90 días.',
  'contenido'=>['Por qué los primeros 90 días son críticos para la retención','Qué decir exactamente en cada llamada','Cómo registrar en CRM para no perder ningún seguimiento','Cómo pedir el Google Review en Day 30 sin incomodar'],
  'roleplay'=>['escenario'=>'Llamada Day 15 — el miembro aún no ha recibido su tarjeta.','prospecto'=>'Hola, me inscribí hace 15 días y todavía no me llega la tarjeta. Ya fui al médico y me dijeron que no tenía seguro.','objetivo_agente'=>'Calmar al miembro, verificar el estatus en el portal, resolver o escalar a Isabel, y quedar bien con el miembro.'],
  'tarea'=>'Hacer las llamadas Day 15 de todos los miembros inscritos en abril.'],
 ['week'=>4,'fecha'=>'May 28','tema'=>'Compliance CMS: Qué Puedes y Qué NO Puedes Decir',
  'objetivo'=>'Conocer las reglas de CMS que protegen a la agencia y evitar errores que cuestan la licencia.',
  'contenido'=>['Las 5 cosas que NUNCA puedes decir a un prospecto','Cómo hablar de beneficios sin mencionar carriers','Qué pasa si un prospecto pregunta por un plan específico','Cómo revisar un post de redes antes de publicar','Casos reales de agentes que perdieron su licencia por compliance'],
  'roleplay'=>['escenario'=>'Un prospecto insiste en que le compares los planes de SCAN vs Humana.','prospecto'=>'Sí pero dígame, ¿cuál es mejor, SCAN o Humana? Mi vecina tiene SCAN y dice que es muy bueno.','objetivo_agente'=>'Redirigir sin comparar carriers, mantener al prospecto interesado, y agendar con Isabel.'],
  'tarea'=>'Revisar los últimos 5 posts de redes y marcar cuáles tienen riesgo de compliance.'],
 ['week'=>5,'fecha'=>'Jun 4','tema'=>'Cómo Usar el CRM para Nunca Perder un Lead',
  'objetivo'=>'Dominar el CRM para que ningún lead quede sin seguimiento y los datos sean siempre exactos.',
  'contenido'=>['El flujo de un lead desde que llega hasta que se inscribe','Cómo taggear la fuente correctamente (Facebook, evento, referido)','Reglas de actualización: qué y cuándo actualizar','Cómo usar el pipeline para saber qué leads atacar hoy','Cómo preparar el reporte semanal en 10 minutos'],
  'roleplay'=>['escenario'=>'El viernes hay 8 leads sin actualizar en el CRM desde el lunes.','prospecto'=>'N/A — ejercicio individual de CRM.','objetivo_agente'=>'Priorizar y actualizar los 8 leads en 20 minutos con la información correcta.'],
  'tarea'=>'Actualizar el CRM con todos los contactos de la semana antes del sábado.'],
 ['week'=>6,'fecha'=>'Jun 11','tema'=>'Cómo Manejar Objeciones — Los 5 "NO" más Comunes',
  'objetivo'=>'Convertir los "no me interesa" en citas agendadas con Isabel.',
  'contenido'=>['"Ya tengo Medicare" — cómo responder','"No tengo tiempo" — cómo responder','"Mi hijo me ayuda con eso" — cómo responder','"No confío en los seguros" — cómo responder','"Llamé pero no contestó" — protocolo de seguimiento'],
  'roleplay'=>['escenario'=>'Prospecto de evento comunitario que dio su teléfono pero ahora dice que no le interesa.','prospecto'=>'Mire, yo di mi número en el evento porque me presionaron. Yo ya tengo mi Medicare y estoy bien así.','objetivo_agente'=>'No rendirse en el primer "no", hacer 2 preguntas de calificación, y dejar la puerta abierta para el AEP.'],
  'tarea'=>'Practicar los 5 scripts de objeciones con una compañera y reportar cuál fue el más difícil.'],
 ['week'=>7,'fecha'=>'Jun 18','tema'=>'El Evento Comunitario Perfecto — De la Invitación al Lead',
  'objetivo'=>'Maximizar los leads calificados de cada evento comunitario.',
  'contenido'=>['Cómo hablar en un senior center sin sonar como vendedor','Los materiales que sí funcionan y los que no','Cómo registrar asistentes correctamente para el CRM','Los primeros 48 horas después del evento','Cómo convertir un asistente curioso en una cita con Isabel'],
  'roleplay'=>['escenario'=>'Taller en iglesia. Una señora de 72 años hace preguntas difíciles frente a todo el grupo.','prospecto'=>'¿Y por qué debo cambiarme si ya tengo Medicare y mi médico me atiende bien? ¿Cuánto me va a costar?','objetivo_agente'=>'Responder con confianza frente al grupo, no mencionar costos ni carriers, e invitar a una conversación privada con Isabel después del taller.'],
  'tarea'=>'Planear el siguiente evento: lugar, fecha, materiales, y qué preguntas pueden surgir.'],
 ['week'=>8,'fecha'=>'Jun 25','tema'=>'Google Reviews y Reputación Online',
  'objetivo'=>'Conseguir 10 reviews en el siguiente mes sin presionar a los miembros.',
  'contenido'=>['Por qué los Google Reviews son la herramienta de ventas más poderosa','El momento exacto para pedir el review (Day 30)','El mensaje exacto que funciona — por texto o WhatsApp','Cómo responder a un review negativo','Cómo llegar a 50 reviews antes de septiembre'],
  'roleplay'=>['escenario'=>'Llamada Day 30 — el miembro está contento. Momento de pedir el review.','prospecto'=>'Sí, todo ha ido muy bien. La tarjeta llegó a tiempo y mi doctor está en la red.','objetivo_agente'=>'Agradecer, preguntar si hay algo más, y pedir el Google Review de forma natural sin que suene a obligación.'],
  'tarea'=>'Enviar el link de Google Review a 5 miembros del mes pasado y reportar cuántos respondieron.'],
 ['week'=>9,'fecha'=>'Jul 2','tema'=>'El AEP — La Temporada Más Importante del Año',
  'objetivo'=>'Prepararse desde ahora para maximizar inscripciones en el Annual Enrollment Period (Oct-Dic).',
  'contenido'=>['Qué es el AEP y por qué es crítico para llegar a 500 miembros','Cómo preparar a los 250 miembros actuales para que no se vayan','Cómo generar una lista de leads calientes antes de octubre','El script de llamada pre-AEP que funciona','Cómo manejar el volumen alto de inscripciones en noviembre'],
  'roleplay'=>['escenario'=>'Llamada de retención pre-AEP a un miembro activo.','prospecto'=>'¿Por qué me llama? ¿Es que algo cambiará en mi plan?','objetivo_agente'=>'Tranquilizar al miembro, recordarle sus beneficios actuales, y dejar claro que pueden revisar sus opciones con Isabel en octubre.'],
  'tarea'=>'Hacer una lista de los 10 miembros con mayor riesgo de no renovar y planear cómo llamarlos.'],
 ['week'=>10,'fecha'=>'Jul 9','tema'=>'Redes Sociales con Compliance — Contenido que Sí Funciona',
  'objetivo'=>'Crear contenido que genere leads sin violar las reglas de CMS.',
  'contenido'=>['Los tipos de posts que sí puedes publicar','Cómo hablar de beneficios sin mencionar nombres de planes','Cómo crear videos cortos con Isabel que generen confianza','Cómo responder mensajes de Facebook sin violar compliance','Cómo medir si el contenido está funcionando (costo por lead)'],
  'roleplay'=>['escenario'=>'Un seguidor de Instagram comenta en un post: ¿Cuál plan es el mejor de Van Nuys?','prospecto'=>'¿Cuál plan recomiendan? Mi vecino tiene Humana y dice que es el mejor.','objetivo_agente'=>'Responder públicamente de forma que no viole compliance, invitar a mensajes directos, y calificar en privado.'],
  'tarea'=>'Crear 3 borradores de posts para la siguiente semana y revisarlos en equipo para compliance.'],
 ['week'=>11,'fecha'=>'Jul 16','tema'=>'Cómo Trabajar Como Equipo: Comunicación y Protocolos',
  'objetivo'=>'Reducir errores por mala comunicación y asegurarse que todos saben qué hacer en cada situación.',
  'contenido'=>['El protocolo de escalación: cuándo y cómo llamar a Isabel','Cómo compartir un lead sin crear confusión','El check-in diario: qué incluir y qué no','Cómo dar feedback a una compañera sin generar conflicto','Los protocolos del sábado: listas, tickets, y reportes'],
  'roleplay'=>['escenario'=>'Dos agentes recibieron el mismo lead y ambas lo llamaron.','prospecto'=>'N/A — role-play interno entre agentes.','objetivo_agente'=>'Resolver el conflicto, definir quién sigue el lead, y actualizar el CRM para que no vuelva a pasar.'],
  'tarea'=>'Escribir el protocolo personal de cómo manejar un lead duplicado y compartirlo en el grupo.'],
 ['week'=>12,'fecha'=>'Jul 23','tema'=>'Cierre y Evaluación — ¿Estás Lista para el AEP?',
  'objetivo'=>'Revisar lo aprendido en 12 semanas y preparar a cada agente para el AEP.',
  'contenido'=>['Quiz de 10 preguntas — compliance, redes, scripts','Simulación completa: lead a inscripción en 20 minutos','Evaluación individual de Isabel: fortalezas y áreas de mejora','Plan personal de cada agente para el AEP','Celebración: 12 semanas de entrenamiento completadas'],
  'roleplay'=>['escenario'=>'Simulación completa de principio a fin: lead de Facebook hasta inscripción.','prospecto'=>'Sí, vi su anuncio. Tengo 65 años, vivo en Van Nuys, y recién califiqué para Medicare. No sé por dónde empezar.','objetivo_agente'=>'Todo el proceso: calificar, presentar, resolver objeciones, agendar con Isabel, y hacer el seguimiento.'],
  'tarea'=>'Completar la evaluación final y enviar metas personales para el AEP a Isabel.'],
];
$train_done = [];
try {
  $st_tr = $pdo->prepare("SELECT semana,completado FROM entrenamiento_progreso WHERE agente_id=?");
  $st_tr->execute([$uid]);
  foreach ($st_tr->fetchAll() as $rowt) if ($rowt['completado']) $train_done[(int)$rowt['semana']] = true;
} catch (Exception $e) {}
$train_total = count($TRAINING_CALENDAR);
$train_compl = count($train_done);
$train_pct   = $train_total > 0 ? round($train_compl / $train_total * 100) : 0;
?>
<div class="card" style="border-top:3px solid <?=$P1?>;margin-bottom:14px;padding:14px 17px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:9px;flex-wrap:wrap;gap:8px">
    <div>
      <div class="card-title" style="font-size:11px">🎓 ACADEMIA — ENTRENAMIENTO SEMANAL</div>
      <div style="font-size:8px;color:<?=$MU?>;letter-spacing:1px;text-transform:uppercase;margin-top:3px">PROGRAMA DE 12 SEMANAS · MIÉRCOLES</div>
    </div>
    <span id="train-count" style="background:#EAF5F0;color:#1E7A5C;border:1px solid #8DCFBA;border-radius:20px;padding:4px 13px;font-size:10px;font-weight:900;white-space:nowrap"><?=$train_compl?> / <?=$train_total?> COMPLETADAS</span>
  </div>
  <div style="width:100%;height:10px;background:<?=$BG?>;border-radius:10px;overflow:hidden;border:1px solid <?=$CB?>">
    <div id="train-bar" style="width:<?=$train_pct?>%;height:100%;background:linear-gradient(to right,<?=$P2?>,<?=$G?>);transition:width .5s;border-radius:10px"></div>
  </div>
  <div id="train-pct-label" style="font-size:8px;color:<?=$MU?>;margin-top:4px;letter-spacing:1px;text-transform:uppercase"><?=$train_pct?>% DEL PROGRAMA</div>
</div>
<?php foreach($TRAINING_CALENDAR as $w): $done = !empty($train_done[$w['week']]); ?>
<div class="card" style="margin-bottom:11px;border-left:4px solid <?=$done?'#1E7A5C':$CB?>">
  <div class="card-header" style="flex-wrap:wrap;gap:8px">
    <div style="display:flex;align-items:center;gap:10px">
      <div style="width:34px;height:34px;border-radius:9px;background:<?=$done?'#EAF5F0':$BG?>;border:1px solid <?=$done?'#8DCFBA':$CB?>;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:13px;color:<?=$done?'#1E7A5C':$P1?>;flex-shrink:0"><?=$w['week']?></div>
      <div>
        <div class="card-title" style="font-size:10px"><?=h($w['tema'])?></div>
        <div style="font-size:8px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-top:2px">SEMANA <?=$w['week']?> · <?=h($w['fecha'])?></div>
      </div>
    </div>
    <button class="btn <?=$done?'btn-gr':'btn-gh'?> btn-sm" id="train-btn-<?=$w['week']?>" onclick="toggleTraining(<?=$w['week']?>,this)"><?=$done?'✓ COMPLETADA':'MARCAR COMPLETA'?></button>
  </div>
  <div style="padding:13px 17px">
    <div style="font-size:9px;color:<?=$TX?>;line-height:1.7;margin-bottom:11px"><strong style="color:<?=$P2?>">OBJETIVO:</strong> <?=h($w['objetivo'])?></div>
    <div style="font-size:8px;font-weight:900;color:<?=$P1?>;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:5px">CONTENIDO</div>
    <ul style="margin:0 0 13px 17px;padding:0">
      <?php foreach($w['contenido'] as $c):?><li style="font-size:9px;color:<?=$TX?>;line-height:1.8"><?=h($c)?></li><?php endforeach;?>
    </ul>
    <div style="background:#F3F0FB;border:1px solid #C2B0E8;border-radius:10px;padding:11px 14px;margin-bottom:11px">
      <div style="font-size:8px;font-weight:900;color:#5B3FAF;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:6px">🎭 ROLE-PLAY</div>
      <div style="font-size:9px;color:<?=$TX?>;line-height:1.7;margin-bottom:6px"><strong>ESCENARIO:</strong> <?=h($w['roleplay']['escenario'])?></div>
      <div style="font-size:9px;color:<?=$TX?>;line-height:1.7;margin-bottom:6px;background:#fff;border-radius:7px;padding:8px 11px;border:1px solid #E3D9F5"><strong>PROSPECTO:</strong> "<?=h($w['roleplay']['prospecto'])?>"</div>
      <div style="font-size:9px;color:<?=$TX?>;line-height:1.7"><strong>TU OBJETIVO:</strong> <?=h($w['roleplay']['objetivo_agente'])?></div>
    </div>
    <div style="background:#FEF8EE;border:1px solid #F5D5A0;border-radius:10px;padding:10px 14px">
      <div style="font-size:8px;font-weight:900;color:#C07A1A;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:4px">📌 TAREA DE LA SEMANA</div>
      <div style="font-size:9px;color:<?=$TX?>;line-height:1.7"><?=h($w['tarea'])?></div>
    </div>
  </div>
</div>
<?php endforeach;?>
</div><!-- /ENTRENAMIENTO -->
<script>
function toggleTraining(sem, btn){
  var done = btn.textContent.indexOf('✓') === -1 ? 1 : 0;
  fetch(location.pathname, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'train_ajax=1&action=toggle_training&semana='+sem+'&done='+done
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    if(!d.ok){ if(typeof toast==='function') toast('Error: '+(d.error||'')); return; }
    if(done){ btn.textContent='✓ COMPLETADA'; btn.className='btn btn-gr btn-sm'; btn.closest('.card').style.borderLeftColor='#1E7A5C'; }
    else    { btn.textContent='MARCAR COMPLETA'; btn.className='btn btn-gh btn-sm'; btn.closest('.card').style.borderLeftColor='#C8DFF0'; }
    var btns=document.querySelectorAll('[id^="train-btn-"]'), total=btns.length, comp=0;
    btns.forEach(function(b){ if(b.textContent.indexOf('✓')>-1) comp++; });
    var pct = total>0 ? Math.round(comp/total*100) : 0;
    var bar=document.getElementById('train-bar'); if(bar) bar.style.width=pct+'%';
    var lbl=document.getElementById('train-pct-label'); if(lbl) lbl.textContent=pct+'% DEL PROGRAMA';
    var cnt=document.getElementById('train-count'); if(cnt) cnt.textContent=comp+' / '+total+' COMPLETADAS';
    if(typeof toast==='function') toast(done?'Semana completada':'Semana desmarcada');
  })
  .catch(function(){ if(typeof toast==='function') toast('Error de red'); });
}
</script>

<!-- REUNIONES -->
<div id="tab-REUNIONES" class="tab-pane">
<?php
if (!function_exists('mtg_avs')) {
  function mtg_avs($csv, $umap){ $o=''; foreach(array_filter(explode(',', (string)$csv)) as $uid_x){ if(isset($umap[$uid_x])){ $u=$umap[$uid_x]; $o.=av(h($u['iniciales']), h($u['color']??'#2876A8'), 18); } } return $o; }
}
$mtg_umap=[]; foreach($users_all as $uu) $mtg_umap[$uu['id']]=$uu;
$TIPO_MTG=[
 'semanal'=>['#1E7A5C','#EAF5F0','SEMANAL'],
 'operaciones'=>['#5B3FAF','#F3F0FB','OPERACIONES'],
 'seguimiento'=>['#1E7A8C','#EAF4F6','SEGUIMIENTO'],
 '1on1'=>['#5B3FAF','#F3F0FB','1:1'],
 'standup'=>['#2876A8','#EBF5FB','STANDUP'],
 'entrenamiento'=>['#C07A1A','#FEF8EE','ENTRENAMIENTO'],
];
$reuniones=[];$sec_by_m=[];$item_by_s=[];$acc_by_m=[];
try{
 $reuniones=$pdo->query("SELECT * FROM reuniones ORDER BY fecha DESC, id DESC")->fetchAll();
 foreach($pdo->query("SELECT * FROM reuniones_secciones ORDER BY orden,id") as $s)$sec_by_m[$s['reunion_id']][]=$s;
 foreach($pdo->query("SELECT * FROM reuniones_items ORDER BY orden,id") as $it)$item_by_s[$it['seccion_id']][]=$it;
 foreach($pdo->query("SELECT * FROM reuniones_acciones ORDER BY id") as $a)$acc_by_m[$a['reunion_id']][]=$a;
}catch(Exception $e){}
$today_m=date('Y-m-d');
$m_prox=count(array_filter($reuniones,fn($r)=>$r['status']==='upcoming'));
$m_done=count(array_filter($reuniones,fn($r)=>$r['status']==='done'));
?>
<div class="card" style="border-top:3px solid <?=$P1?>;margin-bottom:14px;padding:13px 16px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:9px">
    <div>
      <div class="card-title" style="font-size:11px">📅 REUNIONES & 1:1</div>
      <div style="font-size:8px;color:<?=$MU?>;letter-spacing:1px;text-transform:uppercase;margin-top:3px"><?=count($reuniones)?> REUNIONES · <?=$m_prox?> PRÓXIMAS · <?=$m_done?> HECHAS</div>
    </div>
    <button class="btn btn-p btn-sm" onclick="openNewMtg()">+ NUEVA REUNIÓN</button>
  </div>
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:11px">
    <button class="btn btn-p btn-sm mtg-filter" onclick="filterMtg('todas',this)">TODAS</button>
    <button class="btn btn-gh btn-sm mtg-filter" onclick="filterMtg('prox',this)">PRÓXIMAS</button>
    <button class="btn btn-gh btn-sm mtg-filter" onclick="filterMtg('done',this)">HECHAS</button>
    <button class="btn btn-gh btn-sm mtg-filter" onclick="filterMtg('1on1',this)">1:1</button>
  </div>
</div>
<?php if(empty($reuniones)):?>
<div class="card" style="padding:30px;text-align:center;font-size:9px;color:<?=$MU?>;text-transform:uppercase">📅 NO HAY REUNIONES — CREA UNA CON "NUEVA REUNIÓN"</div>
<?php endif;?>
<?php foreach($reuniones as $r):
  $tc=$TIPO_MTG[$r['tipo']]??['#7A90A4','#F4F8FC',strtoupper($r['tipo'])];
  $d_ts=$r['fecha']?strtotime($r['fecha']):time();
  $mon=strtoupper(date('M',$d_ts));$day=date('j',$d_ts);
  if($r['status']==='done'){$stl='HECHA';$stc='#1E7A5C';$stb='#EAF5F0';$stbo='#8DCFBA';}
  elseif($r['fecha']&&$r['fecha']<$today_m){$stl='PASADA';$stc='#C07A1A';$stb='#FEF8EE';$stbo='#F5D5A0';}
  else{$stl='PRÓXIMA';$stc='#1B5E8C';$stb='#EBF5FB';$stbo='#A9D0E8';}
  $secs=$sec_by_m[$r['id']]??[];
  $all_items=0;$done_items=0;
  foreach($secs as $sec){foreach($item_by_s[$sec['id']]??[] as $it){$all_items++;if($it['done'])$done_items++;}}
  $pct=$all_items?round($done_items/$all_items*100):0;
  $accs=$acc_by_m[$r['id']]??[];
  $pa=count(array_filter($accs,fn($a)=>!$a['done']));
?>
<div class="card mtg-card" data-status="<?=h($r['status'])?>" data-tipo="<?=h($r['tipo'])?>" style="margin-bottom:10px;border-left:4px solid <?=$tc[0]?>">
  <div class="card-header" style="cursor:pointer;flex-wrap:wrap;gap:9px" onclick="mtgToggleCard(<?=$r['id']?>)">
    <div style="display:flex;align-items:center;gap:11px;min-width:0;flex:1">
      <div style="background:<?=$tc[1]?>;border-radius:9px;padding:5px 9px;text-align:center;min-width:42px;flex-shrink:0">
        <div style="font-size:8px;font-weight:900;color:<?=$tc[0]?>;text-transform:uppercase"><?=$mon?></div>
        <div style="font-size:17px;font-weight:900;color:<?=$P1?>;line-height:1"><?=$day?></div>
      </div>
      <div style="min-width:0">
        <div class="card-title" style="font-size:10px;white-space:normal"><?=h($r['titulo'])?></div>
        <div style="display:flex;gap:5px;flex-wrap:wrap;align-items:center;margin-top:4px">
          <span style="background:<?=$tc[1]?>;color:<?=$tc[0]?>;border-radius:20px;padding:1px 8px;font-size:8px;font-weight:900"><?=$tc[2]?></span>
          <span style="background:<?=$stb?>;color:<?=$stc?>;border:1px solid <?=$stbo?>;border-radius:20px;padding:1px 8px;font-size:8px;font-weight:900"><?=$stl?></span>
          <?php if($r['recurrencia']):?><span style="font-size:8px;color:<?=$MU?>">🔁 <?=h($r['recurrencia'])?></span><?php endif;?>
          <?php if($pa>0):?><span style="font-size:8px;font-weight:900;color:#C07A1A"><?=$pa?> PEND</span><?php endif;?>
          <?php if($all_items>0):?><span style="font-size:8px;font-weight:900;color:<?=$pct==100?'#1E7A5C':($pct<40?'#B83232':'#1B5E8C')?>"><?=$done_items?>/<?=$all_items?> · <?=$pct?>%</span><?php endif;?>
        </div>
      </div>
    </div>
    <span style="font-size:13px;color:<?=$MU?>;flex-shrink:0">▾</span>
  </div>
  <div id="mtg-body-<?=$r['id']?>" style="display:none;padding:13px 17px;border-top:1px solid <?=$CB?>">
    <div style="display:flex;gap:6px;align-items:center;margin-bottom:12px;flex-wrap:wrap">
      <span style="font-size:8px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px">ASISTENTES:</span>
      <?php $avs=mtg_avs($r['asistentes'],$mtg_umap); echo $avs?:'<span style="font-size:9px;color:#7A90A4">—</span>';?>
    </div>
    <div style="display:flex;gap:7px;margin-bottom:13px;flex-wrap:wrap">
      <button class="btn <?=$r['status']==='done'?'btn-am':'btn-gr'?> btn-sm" onclick="mtgToggleStatus(<?=$r['id']?>)"><?=$r['status']==='done'?'↺ REABRIR':'✓ MARCAR HECHA'?></button>
      <button class="btn btn-re btn-sm" onclick="mtgDelete(<?=$r['id']?>)">✕ ELIMINAR</button>
    </div>
    <div style="margin-bottom:14px">
      <div style="font-size:8px;font-weight:900;color:<?=$P1?>;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:5px">NOTAS</div>
      <textarea id="mtg-notas-<?=$r['id']?>" class="form-input" rows="3" style="text-transform:none"><?=h($r['notas'])?></textarea>
      <button class="btn btn-gh btn-sm" style="margin-top:6px" onclick="mtgSaveNotas(<?=$r['id']?>)">GUARDAR NOTAS</button>
    </div>
    <div style="margin-bottom:14px">
      <div style="font-size:8px;font-weight:900;color:<?=$P1?>;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:6px">ACCIONES / ACUERDOS</div>
      <?php foreach($accs as $a):?>
      <div style="display:flex;gap:9px;align-items:center;padding:7px 0;border-bottom:1px solid <?=$CB?>">
        <div onclick="mtgToggleAccion(<?=$a['id']?>)" style="width:17px;height:17px;border-radius:5px;border:1.5px solid <?=$a['done']?'#1E7A5C':'#C8DFF0'?>;background:<?=$a['done']?'#1E7A5C':'#fff'?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;cursor:pointer;color:#fff;font-size:9px;font-weight:900"><?=$a['done']?'✓':''?></div>
        <span style="flex:1;font-size:10px;color:<?=$a['done']?$MU:$TX?>;<?=$a['done']?'text-decoration:line-through':''?>"><?=h($a['texto'])?></span>
        <?php if($a['responsable']&&isset($mtg_umap[$a['responsable']])):$ua=$mtg_umap[$a['responsable']];?><?=av(h($ua['iniciales']),h($ua['color']??'#2876A8'),20)?><?php endif;?>
      </div>
      <?php endforeach;?>
      <input type="text" class="form-input" style="font-size:9px;padding:6px 9px;margin-top:7px;text-transform:none" placeholder="+ NUEVA ACCIÓN... (ENTER)" onkeydown="if(event.key==='Enter'){event.preventDefault();mtgAddAccion(<?=$r['id']?>,this);}">
    </div>
    <?php foreach($secs as $sec): $items=$item_by_s[$sec['id']]??[]; $sd=count(array_filter($items,fn($i)=>$i['done'])); $sp=count($items)?round($sd/count($items)*100):0;?>
    <div style="margin-bottom:13px">
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:9px;margin-bottom:7px">
        <span style="font-size:9px;font-weight:900;color:<?=$P1?>;text-transform:uppercase;letter-spacing:.5px"><?=h($sec['nombre'])?></span>
        <?php if(count($items)>0):?><span style="font-size:8px;font-weight:900;color:<?=$sp==100?'#1E7A5C':'#1B5E8C'?>"><?=$sd?>/<?=count($items)?> · <?=$sp?>%</span><?php endif;?>
      </div>
      <?php foreach($items as $it):?>
      <div style="background:#fff;border:1.5px solid <?=$it['done']?'#8DCFBA':$CB?>;border-radius:9px;padding:10px 12px;margin-bottom:6px">
        <div style="display:flex;gap:9px;align-items:flex-start">
          <div onclick="mtgToggleItem(<?=$it['id']?>)" style="width:18px;height:18px;border-radius:5px;border:1.5px solid <?=$it['done']?'#1E7A5C':'#C8DFF0'?>;background:<?=$it['done']?'#1E7A5C':'#fff'?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;cursor:pointer;margin-top:1px;color:#fff;font-size:10px;font-weight:900"><?=$it['done']?'✓':''?></div>
          <div style="flex:1;min-width:0">
            <div style="font-size:10px;font-weight:700;color:<?=$it['done']?$MU:$TX?>;line-height:1.5;<?=$it['done']?'text-decoration:line-through':''?>"><?=h($it['texto'])?></div>
            <?php $rav=mtg_avs($it['responsables'],$mtg_umap); if($rav):?><div style="display:flex;gap:3px;margin-top:5px;flex-wrap:wrap"><?=$rav?></div><?php endif;?>
            <input type="text" class="form-input" style="font-size:9px;padding:5px 8px;margin-top:6px;text-transform:none" placeholder="NOTA..." value="<?=h($it['notas'])?>" onchange="mtgItemNota(<?=$it['id']?>,this.value)">
          </div>
        </div>
      </div>
      <?php endforeach;?>
      <input type="text" class="form-input" style="font-size:9px;padding:6px 9px;text-transform:none" placeholder="+ AGREGAR ITEM... (ENTER)" onkeydown="if(event.key==='Enter'){event.preventDefault();mtgAddItem(<?=$r['id']?>,<?=$sec['id']?>,this);}">
    </div>
    <?php endforeach;?>
    <input type="text" class="form-input" style="font-size:9px;padding:6px 9px;text-transform:none" placeholder="+ NUEVA SECCIÓN... (ENTER)" onkeydown="if(event.key==='Enter'){event.preventDefault();mtgAddSeccion(<?=$r['id']?>,this);}">
  </div>
</div>
<?php endforeach;?>
</div><!-- /REUNIONES -->

<!-- MODAL: NUEVA REUNIÓN -->
<div id="modal-mtg-new" class="modal-overlay"><div class="modal modal-sm">
  <div class="modal-header"><div class="modal-title">NUEVA REUNIÓN</div><button class="modal-close" onclick="closeModal('modal-mtg-new')">✕</button></div>
  <form onsubmit="submitNewMtg(event)">
    <div class="form-group"><label class="form-label">TÍTULO *</label><input type="text" name="titulo" class="form-input" required></div>
    <div class="grid-2">
      <div class="form-group"><label class="form-label">FECHA *</label><input type="date" name="fecha" class="form-input" required></div>
      <div class="form-group"><label class="form-label">TIPO</label><select name="tipo" class="form-input">
        <option value="semanal">SEMANAL</option><option value="operaciones">OPERACIONES</option><option value="seguimiento">SEGUIMIENTO</option><option value="1on1">1:1</option><option value="standup">STANDUP</option><option value="entrenamiento">ENTRENAMIENTO</option>
      </select></div>
    </div>
    <div class="form-group"><label class="form-label">RECURRENCIA</label><input type="text" name="recurrencia" class="form-input" placeholder="EJ: SEMANAL — SÁBADOS"></div>
    <div style="display:flex;justify-content:flex-end;gap:7px;margin-top:8px">
      <button type="button" class="btn btn-gh btn-sm" onclick="closeModal('modal-mtg-new')">CANCELAR</button>
      <button type="submit" class="btn btn-p btn-sm">CREAR REUNIÓN</button>
    </div>
  </form>
</div></div>
<script>
function mtgToggleCard(id){
  var b=document.getElementById('mtg-body-'+id); if(!b) return;
  var open=b.style.display!=='none';
  b.style.display=open?'none':'block';
  try{ if(open) sessionStorage.removeItem('mtgOpen'); else sessionStorage.setItem('mtgOpen',id); }catch(e){}
}
function _mtgReload(){ if(typeof softReload==='function'){ softReload(); return; } try{sessionStorage.setItem('pendingReload','1');sessionStorage.setItem('activeTab','REUNIONES');sessionStorage.setItem('mtgScroll',window.scrollY);}catch(e){} location.reload(); }
function mtgPost(params,reload){
  return fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'mtg_ajax=1&'+params})
   .then(function(r){return r.json();})
   .then(function(d){ if(!d||!d.ok){ if(typeof toast==='function')toast('Error: '+((d&&d.error)||'')); return d; } if(reload){_mtgReload();} return d; })
   .catch(function(){ if(typeof toast==='function')toast('Error de red'); });
}
function mtgToggleItem(i){mtgPost('action=toggle_item&item_id='+i,true);}
function mtgToggleAccion(a){mtgPost('action=toggle_accion&accion_id='+a,true);}
function mtgItemNota(i,v){mtgPost('action=update_item_nota&item_id='+i+'&nota='+encodeURIComponent(v),false).then(function(){if(typeof toast==='function')toast('Nota guardada');});}
function mtgAddItem(r,s,inp){if(!inp.value.trim())return;mtgPost('action=add_item&reunion_id='+r+'&seccion_id='+s+'&texto='+encodeURIComponent(inp.value.trim()),true);}
function mtgAddAccion(r,inp){if(!inp.value.trim())return;mtgPost('action=add_accion&reunion_id='+r+'&texto='+encodeURIComponent(inp.value.trim()),true);}
function mtgAddSeccion(r,inp){if(!inp.value.trim())return;mtgPost('action=add_seccion&reunion_id='+r+'&nombre='+encodeURIComponent(inp.value.trim()),true);}
function mtgSaveNotas(r){var t=document.getElementById('mtg-notas-'+r);if(!t)return;mtgPost('action=save_notas&reunion_id='+r+'&notas='+encodeURIComponent(t.value),false).then(function(){if(typeof toast==='function')toast('Notas guardadas');});}
function mtgToggleStatus(r){mtgPost('action=toggle_status&reunion_id='+r,true);}
function mtgDelete(r){if(!confirm('¿Eliminar esta reunión? Esto borrará su agenda y acuerdos.'))return;try{sessionStorage.removeItem('mtgOpen');}catch(e){}mtgPost('action=delete_meeting&reunion_id='+r,true);}
function filterMtg(f,btn){
  document.querySelectorAll('#tab-REUNIONES .mtg-filter').forEach(function(b){b.className='btn btn-gh btn-sm mtg-filter';});
  if(btn)btn.className='btn btn-p btn-sm mtg-filter';
  document.querySelectorAll('#tab-REUNIONES .mtg-card').forEach(function(c){
    var s=c.dataset.status,t=c.dataset.tipo;
    var show=(f==='todas')||(f==='prox'&&s==='upcoming')||(f==='done'&&s==='done')||(f==='1on1'&&t==='1on1');
    c.style.display=show?'':'none';
  });
}
function openNewMtg(){openModal('modal-mtg-new');}
function submitNewMtg(e){e.preventDefault();var f=e.target;
  var p='action=new_meeting&titulo='+encodeURIComponent(f.titulo.value)+'&fecha='+encodeURIComponent(f.fecha.value)+'&tipo='+encodeURIComponent(f.tipo.value)+'&recurrencia='+encodeURIComponent(f.recurrencia.value);
  mtgPost(p,false).then(function(d){if(d&&d.ok){try{sessionStorage.setItem('mtgOpen',d.id);}catch(e){}_mtgReload();}});
}
document.addEventListener('DOMContentLoaded',function(){
  try{
    var o=sessionStorage.getItem('mtgOpen'); if(o){var b=document.getElementById('mtg-body-'+o); if(b)b.style.display='block';}
    var sc=sessionStorage.getItem('mtgScroll'); if(sc){ setTimeout(function(){window.scrollTo(0,parseInt(sc));sessionStorage.removeItem('mtgScroll');},150); }
  }catch(e){}
});
</script>

<!-- CAMPAÑAS -->
<div id="tab-CAMPANAS" class="tab-pane">
<?php
$CANAL_COL=['FACEBOOK'=>['#1B5E8C','#EBF5FB'],'INSTAGRAM'=>['#5B3FAF','#F3F0FB'],'EVENTO'=>['#1E7A5C','#EAF5F0'],'REFERIDO'=>['#C07A1A','#FEF8EE'],'GOOGLE'=>['#B83232','#FDF0EE'],'OTRO'=>['#7A90A4','#F1F1F1']];
$CC_EST=['ACTIVO'=>['#1B5E8C','#EBF5FB','ACTIVO'],'INTERESADO'=>['#1E7A5C','#EAF5F0','INTERESADO'],'CITA'=>['#5B3FAF','#F3F0FB','CITA AGENDADA'],'INSCRITO'=>['#1E7A5C','#EAF5F0','INSCRITO'],'NO_INTERESADO'=>['#B83232','#FDF0EE','NO INTERESADO'],'DESCARTADO'=>['#7A90A4','#F1F1F1','DESCARTADO'],'EN PIPELINE'=>['#C07A1A','#FEF8EE','EN PIPELINE']];
$campanas=[];$cc_by_camp=[];$clog_by_contacto=[];
try{
 $campanas=$pdo->query("SELECT c.*, u.iniciales as agente_ini, u.color as agente_color FROM campanas c LEFT JOIN usuarios u ON c.agente_id=u.id ORDER BY FIELD(c.estado,'ACTIVA','PAUSADA','CERRADA'), c.created_at DESC")->fetchAll();
 foreach($pdo->query("SELECT * FROM campana_contactos ORDER BY promovido ASC, id DESC") as $ct)$cc_by_camp[$ct['campana_id']][]=$ct;
 foreach($pdo->query("SELECT * FROM campana_logs ORDER BY id DESC") as $lg)$clog_by_contacto[$lg['contacto_id']][]=$lg;
}catch(Exception $e){}
$camp_total=count($campanas);
$camp_activas=count(array_filter($campanas,fn($c)=>$c['estado']==='ACTIVA'));
$cc_total=0;$cc_pipe=0; foreach($cc_by_camp as $list){foreach($list as $ct){$cc_total++; if($ct['promovido'])$cc_pipe++;}}
$cc_all=[]; foreach($cc_by_camp as $list){foreach($list as $ct){$cc_all[$ct['id']]=$ct;}}
?>
<div class="card" style="border-top:3px solid <?=$P1?>;margin-bottom:14px;padding:13px 16px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:9px">
    <div>
      <div class="card-title" style="font-size:11px">📣 CAMPAÑAS</div>
      <div style="font-size:8px;color:<?=$MU?>;letter-spacing:1px;text-transform:uppercase;margin-top:3px"><?=$camp_total?> CAMPAÑAS · <?=$camp_activas?> ACTIVAS · <?=$cc_total?> CONTACTOS · <?=$cc_pipe?> EN PIPELINE</div>
    </div>
    <button class="btn btn-p btn-sm" onclick="openCampForm()">+ NUEVA CAMPAÑA</button>
  </div>
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:11px">
    <button class="btn btn-p btn-sm camp-filter" onclick="filterCamp('todas',this)">TODAS</button>
    <button class="btn btn-gh btn-sm camp-filter" onclick="filterCamp('ACTIVA',this)">ACTIVAS</button>
    <button class="btn btn-gh btn-sm camp-filter" onclick="filterCamp('CERRADA',this)">CERRADAS</button>
  </div>
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
<div class="card camp-card" data-estado="<?=h($c['estado'])?>" style="margin-bottom:10px;border-left:4px solid <?=$cl[0]?>">
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
      <button class="btn btn-gh btn-sm" onclick="openCampForm(<?=$c['id']?>)">✎ EDITAR CAMPAÑA</button>
      <button class="btn btn-re btn-sm" onclick="deleteCampana(<?=$c['id']?>)">✕ ELIMINAR</button>
    </div>
    <?php if(empty($contactos)):?>
    <div style="font-size:9px;color:<?=$MU?>;text-transform:uppercase;padding:8px 0">SIN CONTACTOS — AGREGA EL PRIMERO</div>
    <?php else: foreach($contactos as $ct):
      $ce=$CC_EST[$ct['estado']]??['#7A90A4','#F1F1F1',$ct['estado']];
      $ph=preg_replace('/[^0-9]/','',$ct['telefono']??'');
      $logs=$clog_by_contacto[$ct['id']]??[]; $lastlog=$logs[0]??null;
      $nm=trim($ct['nombre'].' '.($ct['apellido']??''));
    ?>
    <div style="background:#fff;border:1px solid <?=$CB?>;border-radius:10px;padding:10px 13px;margin-bottom:7px">
      <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap">
        <div style="flex:1;min-width:0">
          <div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap">
            <span style="font-weight:900;font-size:10px;color:<?=$P1?>"><?=h($nm)?></span>
            <?php if($ct['promovido']):?>
              <span style="background:<?=$ce[1]?>;color:<?=$ce[0]?>;border-radius:20px;padding:1px 8px;font-size:8px;font-weight:900"><?=$ce[2]?></span>
            <?php else:?>
              <select class="form-input" style="font-size:8px;padding:3px 6px;width:auto;display:inline-block;text-transform:none" onchange="ccEstado(<?=$ct['id']?>,this.value)">
                <?php foreach(['ACTIVO','INTERESADO','CITA','INSCRITO','NO_INTERESADO','DESCARTADO'] as $es):?><option value="<?=$es?>"<?=$ct['estado']===$es?' selected':''?>><?=str_replace('_',' ',$es)?></option><?php endforeach;?>
              </select>
            <?php endif;?>
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:3px">
            <?php if($ct['telefono']):?><span style="font-size:8px;color:<?=$MU?>">📞 <?=h($ct['telefono'])?></span><?php endif;?>
            <?php if($lastlog):?><span style="font-size:8px;color:<?=$MU?>">ÚLTIMO: <?=h($lastlog['canal'])?> — <?=h($lastlog['resultado'])?></span><?php endif;?>
          </div>
        </div>
        <div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center">
          <?php if($ct['telefono']):?>
          <a href="tel:<?=h($ct['telefono'])?>" class="btn btn-bl btn-sm" style="font-size:8px">LLAMAR</a>
          <a href="https://wa.me/1<?=$ph?>" target="_blank" class="btn btn-gr btn-sm" style="font-size:8px">WA</a>
          <?php endif;?>
          <?php if($ct['promovido']):?>
            <button class="btn btn-am btn-sm" style="font-size:8px" onclick="openProfile(<?=$ct['miembro_id']?>)">◉ VER PERFIL</button>
          <?php else:?>
            <button class="btn btn-gh btn-sm" style="font-size:8px" onclick="openCcLog(<?=$ct['campana_id']?>,<?=$ct['id']?>,'<?=h(addslashes($nm))?>')">📋 REGISTRAR</button>
            <button class="btn btn-p btn-sm" style="font-size:8px" onclick="promoverContacto(<?=$ct['id']?>,'<?=h(addslashes($nm))?>')">▲ PIPELINE</button>
            <button class="btn btn-gh btn-sm" style="font-size:8px" onclick="openCcForm(<?=$ct['campana_id']?>,<?=$ct['id']?>)">✎</button>
            <button class="btn btn-re btn-sm" style="font-size:8px" onclick="deleteContacto(<?=$ct['id']?>)">✕</button>
          <?php endif;?>
        </div>
      </div>
      <div id="cc-hist-<?=$ct['id']?>" style="display:none">
        <?php if(empty($logs)):?><div style="font-size:9px;color:<?=$MU?>;padding:8px;text-transform:uppercase">SIN ACTIVIDAD REGISTRADA</div><?php else: foreach($logs as $lg):?>
        <div style="display:flex;gap:8px;padding:6px 0;border-bottom:1px solid <?=$CB?>">
          <span style="font-size:8px;font-weight:900;color:<?=$P2?>;min-width:54px"><?=date('d/m/y',strtotime($lg['created_at']))?></span>
          <div style="flex:1"><div style="font-size:9px;font-weight:700;color:<?=$TX?>"><?=h($lg['canal'])?> — <?=h($lg['resultado'])?></div><?php if($lg['notas']):?><div style="font-size:8px;color:<?=$MU?>"><?=h($lg['notas'])?></div><?php endif;?></div>
        </div>
        <?php endforeach; endif;?>
      </div>
    </div>
    <?php endforeach; endif;?>
  </div>
</div>
<?php endforeach;?>
</div><!-- /CAMPANAS -->

<!-- MODAL: NUEVA/EDITAR CAMPAÑA -->
<div id="modal-camp-form" class="modal-overlay"><div class="modal modal-sm">
  <div class="modal-header"><div class="modal-title" id="camp-form-title">NUEVA CAMPAÑA</div><button class="modal-close" onclick="closeModal('modal-camp-form')">✕</button></div>
  <form onsubmit="saveCampana(event)">
    <input type="hidden" name="id" id="camp-id">
    <div class="form-group"><label class="form-label">NOMBRE *</label><input type="text" name="nombre" id="camp-nombre" class="form-input" required></div>
    <div class="grid-2">
      <div class="form-group"><label class="form-label">CANAL</label><select name="canal" id="camp-canal" class="form-input">
        <option value="FACEBOOK">FACEBOOK</option><option value="INSTAGRAM">INSTAGRAM</option><option value="EVENTO">EVENTO</option><option value="REFERIDO">REFERIDO</option><option value="GOOGLE">GOOGLE</option><option value="OTRO">OTRO</option>
      </select></div>
      <div class="form-group"><label class="form-label">ESTADO</label><select name="estado" id="camp-estado" class="form-input">
        <option value="ACTIVA">ACTIVA</option><option value="PAUSADA">PAUSADA</option><option value="CERRADA">CERRADA</option>
      </select></div>
    </div>
    <div class="grid-2">
      <div class="form-group"><label class="form-label">FECHA INICIO</label><input type="date" name="fecha_inicio" id="camp-fecha" class="form-input"></div>
      <div class="form-group"><label class="form-label">COSTO ($)</label><input type="number" step="0.01" name="costo" id="camp-costo" class="form-input" placeholder="0.00"></div>
    </div>
    <div class="form-group"><label class="form-label">DESCRIPCIÓN</label><textarea name="descripcion" id="camp-desc" class="form-input" rows="2" style="text-transform:none"></textarea></div>
    <div style="display:flex;justify-content:flex-end;gap:7px;margin-top:8px">
      <button type="button" class="btn btn-gh btn-sm" onclick="closeModal('modal-camp-form')">CANCELAR</button>
      <button type="submit" class="btn btn-p btn-sm">GUARDAR</button>
    </div>
  </form>
</div></div>

<!-- MODAL: NUEVO/EDITAR CONTACTO -->
<div id="modal-cc-form" class="modal-overlay"><div class="modal modal-sm">
  <div class="modal-header"><div class="modal-title" id="cc-form-title">NUEVO CONTACTO</div><button class="modal-close" onclick="closeModal('modal-cc-form')">✕</button></div>
  <form onsubmit="saveContacto(event)">
    <input type="hidden" name="id" id="cc-id"><input type="hidden" name="campana_id" id="cc-campana-id">
    <div class="grid-2">
      <div class="form-group"><label class="form-label">NOMBRE *</label><input type="text" name="nombre" id="cc-nombre" class="form-input" required></div>
      <div class="form-group"><label class="form-label">APELLIDO</label><input type="text" name="apellido" id="cc-apellido" class="form-input"></div>
    </div>
    <div class="grid-2">
      <div class="form-group"><label class="form-label">TELÉFONO</label><input type="text" name="telefono" id="cc-telefono" class="form-input"></div>
      <div class="form-group"><label class="form-label">EMAIL</label><input type="email" name="email" id="cc-email" class="form-input" style="text-transform:none"></div>
    </div>
    <div class="form-group"><label class="form-label">NOTAS</label><textarea name="notas" id="cc-notas" class="form-input" rows="2" style="text-transform:none"></textarea></div>
    <div style="display:flex;justify-content:flex-end;gap:7px;margin-top:8px">
      <button type="button" class="btn btn-gh btn-sm" onclick="closeModal('modal-cc-form')">CANCELAR</button>
      <button type="submit" class="btn btn-p btn-sm">GUARDAR</button>
    </div>
  </form>
</div></div>

<!-- MODAL: REGISTRAR ACTIVIDAD -->
<div id="modal-cc-log" class="modal-overlay"><div class="modal modal-sm">
  <div class="modal-header"><div class="modal-title">REGISTRAR — <span id="cc-log-name"></span></div><button class="modal-close" onclick="closeModal('modal-cc-log')">✕</button></div>
  <form onsubmit="saveLog(event)">
    <input type="hidden" name="campana_id" id="cc-log-campana"><input type="hidden" name="contacto_id" id="cc-log-contacto">
    <div class="grid-2">
      <div class="form-group"><label class="form-label">CANAL</label><select name="canal" id="cc-log-canal" class="form-input" onchange="ccUpdateOutcomes()">
        <option value="LLAMADA">📞 LLAMADA</option><option value="WHATSAPP">💬 WHATSAPP</option><option value="FLYER">📄 FLYER</option><option value="CITA">🤝 CITA</option>
      </select></div>
      <div class="form-group"><label class="form-label">RESULTADO</label><select name="resultado" id="cc-log-resultado" class="form-input" style="text-transform:none"></select></div>
    </div>
    <div class="form-group"><label class="form-label">ACTUALIZAR ESTADO A (OPCIONAL)</label><select name="nuevo_estado" id="cc-log-estado" class="form-input">
      <option value="">— NO CAMBIAR —</option><option value="ACTIVO">ACTIVO</option><option value="INTERESADO">INTERESADO</option><option value="CITA">CITA AGENDADA</option><option value="INSCRITO">INSCRITO</option><option value="NO_INTERESADO">NO INTERESADO</option><option value="DESCARTADO">DESCARTADO</option>
    </select></div>
    <div class="form-group"><label class="form-label">NOTAS</label><textarea name="notas" id="cc-log-notas" class="form-input" rows="2" style="text-transform:none" placeholder="QUÉ DIJO, PRÓXIMO PASO..."></textarea></div>
    <div style="display:flex;justify-content:flex-end;gap:7px;margin-top:8px">
      <button type="button" class="btn btn-gh btn-sm" onclick="closeModal('modal-cc-log')">CANCELAR</button>
      <button type="submit" class="btn btn-p btn-sm">GUARDAR REGISTRO</button>
    </div>
    <div style="margin-top:13px;border-top:1px solid <?=$CB?>;padding-top:9px">
      <div style="font-size:8px;font-weight:900;color:<?=$P1?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:5px">HISTORIAL</div>
      <div id="cc-log-history" style="max-height:180px;overflow-y:auto"></div>
    </div>
  </form>
</div></div>
<script>
var CAMP_DATA=<?=json_encode($campanas)?>;
var CC_DATA=<?=json_encode($cc_all)?>;
var CAMP_OUTCOMES={
  LLAMADA:['No contestó','Dejó buzón','Contestó - sin interés','Contestó - interesado','SOA firmado','Cita agendada','Inscrito','No interesado'],
  WHATSAPP:['Enviado - sin respuesta','No respondió','Quiere info','Interesado','No interesado'],
  FLYER:['Entregado','Vio y preguntó','Sin respuesta'],
  CITA:['Cita confirmada','Muy interesado','SOA firmado','Inscrito','No le interesó']
};
function _campReload(){ if(typeof softReload==='function'){ softReload(); return; } try{sessionStorage.setItem('pendingReload','1');sessionStorage.setItem('activeTab','CAMPANAS');sessionStorage.setItem('campScroll',window.scrollY);}catch(e){} location.reload(); }
function campPost(params,reload){
  return fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'camp_ajax=1&'+params})
   .then(function(r){return r.json();})
   .then(function(d){ if(!d||!d.ok){ if(typeof toast==='function')toast('Error: '+((d&&d.error)||'')); return d; } if(reload){_campReload();} return d; })
   .catch(function(){ if(typeof toast==='function')toast('Error de red'); });
}
function campToggleCard(id){
  var b=document.getElementById('camp-body-'+id); if(!b)return;
  var open=b.style.display!=='none'; b.style.display=open?'none':'block';
  try{ if(open)sessionStorage.removeItem('campOpen'); else sessionStorage.setItem('campOpen',id); }catch(e){}
}
function filterCamp(f,btn){
  document.querySelectorAll('#tab-CAMPANAS .camp-filter').forEach(function(b){b.className='btn btn-gh btn-sm camp-filter';});
  if(btn)btn.className='btn btn-p btn-sm camp-filter';
  document.querySelectorAll('#tab-CAMPANAS .camp-card').forEach(function(c){ c.style.display=(f==='todas'||c.dataset.estado===f)?'':'none'; });
}
function openCampForm(id){
  document.getElementById('camp-id').value=id||'';
  document.getElementById('camp-form-title').textContent=id?'EDITAR CAMPAÑA':'NUEVA CAMPAÑA';
  var d=id?CAMP_DATA.filter(function(x){return x.id==id;})[0]:null;
  document.getElementById('camp-nombre').value=d?d.nombre:'';
  document.getElementById('camp-canal').value=d?d.canal:'FACEBOOK';
  document.getElementById('camp-estado').value=d?d.estado:'ACTIVA';
  document.getElementById('camp-fecha').value=d&&d.fecha_inicio?d.fecha_inicio:'';
  document.getElementById('camp-costo').value=d&&d.costo?d.costo:'';
  document.getElementById('camp-desc').value=d&&d.descripcion?d.descripcion:'';
  openModal('modal-camp-form');
}
function saveCampana(e){e.preventDefault();var f=e.target;
  var p='action=save_campana&id='+encodeURIComponent(f.id.value)+'&nombre='+encodeURIComponent(f.nombre.value)+'&canal='+encodeURIComponent(f.canal.value)+'&estado='+encodeURIComponent(f.estado.value)+'&fecha_inicio='+encodeURIComponent(f.fecha_inicio.value)+'&costo='+encodeURIComponent(f.costo.value)+'&descripcion='+encodeURIComponent(f.descripcion.value);
  campPost(p,false).then(function(d){if(d&&d.ok){try{sessionStorage.setItem('campOpen',d.id);}catch(e){}_campReload();}});
}
function deleteCampana(id){if(!confirm('¿Eliminar esta campaña? Se borrarán sus contactos y registros.'))return;try{sessionStorage.removeItem('campOpen');}catch(e){}campPost('action=delete_campana&id='+id,true);}
function openCcForm(campId,ctId){
  document.getElementById('cc-id').value=ctId||'';
  document.getElementById('cc-campana-id').value=campId||'';
  document.getElementById('cc-form-title').textContent=ctId?'EDITAR CONTACTO':'NUEVO CONTACTO';
  var d=ctId?CC_DATA[ctId]:null;
  document.getElementById('cc-nombre').value=d?d.nombre:'';
  document.getElementById('cc-apellido').value=d&&d.apellido?d.apellido:'';
  document.getElementById('cc-telefono').value=d&&d.telefono?d.telefono:'';
  document.getElementById('cc-email').value=d&&d.email?d.email:'';
  document.getElementById('cc-notas').value=d&&d.notas?d.notas:'';
  openModal('modal-cc-form');
}
function saveContacto(e){e.preventDefault();var f=e.target;
  var camp=f.campana_id.value;
  var p='action=save_contacto&id='+encodeURIComponent(f.id.value)+'&campana_id='+encodeURIComponent(camp)+'&nombre='+encodeURIComponent(f.nombre.value)+'&apellido='+encodeURIComponent(f.apellido.value)+'&telefono='+encodeURIComponent(f.telefono.value)+'&email='+encodeURIComponent(f.email.value)+'&notas='+encodeURIComponent(f.notas.value);
  campPost(p,false).then(function(d){if(d&&d.ok){try{sessionStorage.setItem('campOpen',camp);}catch(e){}_campReload();}});
}
function deleteContacto(id){if(!confirm('¿Eliminar este contacto y su historial?'))return;campPost('action=delete_contacto&id='+id,true);}
function ccEstado(id,val){campPost('action=update_contacto_estado&id='+id+'&estado='+encodeURIComponent(val),false).then(function(){if(typeof toast==='function')toast('Estado actualizado');});}
function ccUpdateOutcomes(){
  var canal=document.getElementById('cc-log-canal').value;
  var sel=document.getElementById('cc-log-resultado');
  var opts=CAMP_OUTCOMES[canal]||[];
  sel.innerHTML=opts.map(function(o){return '<option value="'+o+'">'+o+'</option>';}).join('');
}
function openCcLog(campId,ctId,name){
  document.getElementById('cc-log-campana').value=campId;
  document.getElementById('cc-log-contacto').value=ctId;
  document.getElementById('cc-log-name').textContent=name||'';
  document.getElementById('cc-log-canal').value='LLAMADA';
  ccUpdateOutcomes();
  document.getElementById('cc-log-estado').value='';
  document.getElementById('cc-log-notas').value='';
  var hist=document.getElementById('cc-hist-'+ctId);
  document.getElementById('cc-log-history').innerHTML=hist?hist.innerHTML:'';
  openModal('modal-cc-log');
}
function saveLog(e){e.preventDefault();var f=e.target;var camp=f.campana_id.value;
  var p='action=log_actividad&campana_id='+encodeURIComponent(camp)+'&contacto_id='+encodeURIComponent(f.contacto_id.value)+'&canal='+encodeURIComponent(f.canal.value)+'&resultado='+encodeURIComponent(f.resultado.value)+'&nuevo_estado='+encodeURIComponent(f.nuevo_estado.value)+'&notas='+encodeURIComponent(f.notas.value);
  campPost(p,false).then(function(d){if(d&&d.ok){try{sessionStorage.setItem('campOpen',camp);}catch(e){}_campReload();}});
}
function promoverContacto(id,name){
  if(!confirm('¿Pasar a '+(name||'este contacto')+' al PIPELINE real del CRM? Se creará como prospecto.'))return;
  campPost('action=promover_contacto&id='+id,false).then(function(d){
    if(d&&d.ok){ if(typeof toast==='function')toast('✓ Movido al pipeline'); _campReload(); }
  });
}
document.addEventListener('DOMContentLoaded',function(){
  try{
    var o=sessionStorage.getItem('campOpen'); if(o){var b=document.getElementById('camp-body-'+o); if(b)b.style.display='block';}
    var sc=sessionStorage.getItem('campScroll'); if(sc){ setTimeout(function(){window.scrollTo(0,parseInt(sc));sessionStorage.removeItem('campScroll');},150); }
  }catch(e){}
});
</script>

<!-- PLANEACIÓN -->
<div id="tab-PLANEACION" class="tab-pane">
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
<a href="estrategia.php" style="flex:1;min-width:220px;display:flex;align-items:center;justify-content:space-between;gap:10px;background:<?=$P1?>;color:#fff;border-radius:11px;padding:12px 16px;text-decoration:none">
  <span style="font-size:11px;font-weight:900;letter-spacing:1px;text-transform:uppercase">◍ Estrategia & Embudo Diario</span>
  <span style="font-size:10px;font-weight:900;background:rgba(255,255,255,.18);border-radius:8px;padding:5px 11px">ABRIR →</span>
</a>
<a href="equipo.php" style="flex:1;min-width:220px;display:flex;align-items:center;justify-content:space-between;gap:10px;background:#E67E22;color:#fff;border-radius:11px;padding:12px 16px;text-decoration:none">
  <span style="font-size:11px;font-weight:900;letter-spacing:1px;text-transform:uppercase">🔥 Wins · Rachas · Mood</span>
  <span style="font-size:10px;font-weight:900;background:rgba(255,255,255,.18);border-radius:8px;padding:5px 11px">ABRIR →</span>
</a>
</div>
<?php
$plan_metas=[]; $plan_checks=[]; $plan_notas=[];
try{
  $plan_metas=$pdo->query("SELECT * FROM plan_metas ORDER BY orden,id")->fetchAll();
  foreach($pdo->query("SELECT item_key,done FROM plan_checks") as $pc)$plan_checks[$pc['item_key']]=(int)$pc['done'];
  foreach($pdo->query("SELECT horizonte,contenido FROM plan_notas") as $pn)$plan_notas[$pn['horizonte']]=$pn['contenido'];
}catch(Exception $e){}
$GP=['CRITICAL'=>['#B83232','#FDF0EE','CRÍTICO'],'HIGH'=>['#C07A1A','#FEF8EE','ALTO'],'MEDIUM'=>['#1B5E8C','#EBF5FB','MEDIO']];
?>
<div style="display:flex;border-bottom:2px solid <?=$CB?>;margin-bottom:14px;overflow-x:auto;background:#fff;border-radius:11px 11px 0 0;border:1px solid <?=$CB?>">
<?php foreach(['METAS','90 DÍAS','FORTALEZAS','GAPS','PLANES'] as $pt):?><button class="ntab<?=$pt==='METAS'?' active':''?>" data-ptab="<?=$pt?>" onclick="showPlanTab('<?=$pt?>')"><?=$pt?></button><?php endforeach;?>
</div>

<div id="ptab-METAS">
<div style="margin-bottom:8px;font-size:9px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px">METAS DE CRECIMIENTO — RUMBO 500</div>
<?php foreach($plan_metas as $m): $g=$GP[$m['prioridad']]??$GP['HIGH']; ?>
<div class="card meta-card" style="margin-bottom:10px;padding:13px 16px;border-left:4px solid <?=$g[0]?>">
  <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:flex-start;margin-bottom:8px">
    <div style="flex:1;min-width:0">
      <div style="font-size:11px;font-weight:900;color:<?=$P1?>"><?=h($m['titulo'])?></div>
      <?php if($m['objetivo']):?><div style="font-size:8px;color:<?=$MU?>;margin-top:2px"><?=h($m['objetivo'])?></div><?php endif;?>
    </div>
    <div style="display:flex;gap:6px;align-items:center;flex-shrink:0">
      <span style="background:<?=$g[1]?>;color:<?=$g[0]?>;border-radius:20px;padding:1px 8px;font-size:8px;font-weight:900"><?=$g[2]?></span>
      <?php if($m['due']):?><span style="font-size:8px;color:<?=$MU?>"><?=h($m['due'])?></span><?php endif;?>
      <span class="meta-pct" style="font-size:12px;font-weight:900;color:<?=$g[0]?>;min-width:34px;text-align:right"><?=$m['progreso']?>%</span>
    </div>
  </div>
  <div style="width:100%;height:9px;background:<?=$BG?>;border-radius:10px;overflow:hidden;border:1px solid <?=$CB?>;margin-bottom:7px">
    <div class="meta-bar" style="width:<?=$m['progreso']?>%;height:100%;background:<?=$g[0]?>;border-radius:10px;transition:width .3s"></div>
  </div>
  <input type="range" min="0" max="100" value="<?=$m['progreso']?>" style="width:100%" oninput="planMeta(<?=$m['id']?>,this.value,this)" onchange="planMetaSave(<?=$m['id']?>,this.value)">
</div>
<?php endforeach;?>
</div>

<?php
$VISION=[
 ['📊','Volumen de Leads','Anuncios + referidos','Ads + Google LSA + YouTube + Red de Doctores + Eventos'],
 ['🛡','Nivel de Confianza','Personas que conocen a Isabel personalmente','Autoridad comunitaria con reseñas, video, medios y eventos'],
 ['👥','Capacidad del Equipo','4 agentes trabajando manual','4 agentes + automatización haciendo el trabajo de 7'],
 ['🔄','Retención de Miembros','Equipo llama cuando puede','Toques automatizados + revisiones trimestrales + NPS'],
 ['👑','Rol de Isabel','Hace todo','Visión, cerrar ventas, autoridad — la máquina hace el resto'],
];
$ROAD=[
 ['Semana 1-2','Fundación','#1B5E8C',['Configurar Google Local Service Ads','Lanzar campaña Google Reviews','Agregar tags de fuente a todos los leads','Automatización de confirmación de citas','Activar tracking SLA respuesta 60 minutos','Template de scorecard del agente']],
 ['Mes 1','Construir la Máquina','#1E7A5C',['Lanzar canal de YouTube — primeros 4 videos en español','Primer evento comunitario — centro de adultos mayores','Outreach a 10 clínicas en Van Nuys','Matriz de scoring de leads','Video testimonial — primeros 3 miembros','Programa de recompensa por referidos','Dashboard de costo por adquisición']],
 ['Mes 2','Automatizar y Amplificar','#5B3FAF',['Sistema de SMS broadcast','Newsletter Medicare Matters — primera edición','Bot de Retención — flag 45 días sin contacto','Secuencias automáticas de cumpleaños y festividades','Encuesta NPS a miembros','Playbook de manejo de objeciones','Dashboard mensual P&L']],
 ['Mes 3+','Dominar y Escalar','#C07A1A',['Isabel da primera charla en evento comunitario','Pitch a medios — Telemundo / radio','Reclutamiento: 2 candidatos en pipeline','Revisión de contratos de aseguradoras — negociar top 3','Plan de expansión territorial — 1 código postal nuevo','Hito: 100 reseñas de Google','Sistema de leads SEP operacional']],
];
?>
<div id="ptab-90 DÍAS" style="display:none">
<div style="margin-bottom:8px;font-size:9px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px">VISIÓN DÍA 90 — DE DÓNDE ESTAMOS A DÓNDE VAMOS</div>
<?php foreach($VISION as [$ic,$area,$now,$meta]):?>
<div class="card" style="margin-bottom:8px;padding:11px 14px;display:grid;grid-template-columns:150px 1fr 1fr;gap:10px;align-items:center">
  <div style="display:flex;gap:8px;align-items:center"><span style="font-size:18px"><?=$ic?></span><span style="font-size:9px;font-weight:900;color:<?=$P1?>"><?=h($area)?></span></div>
  <div style="font-size:9px;color:<?=$MU?>;line-height:1.5">HOY: <?=h($now)?></div>
  <div style="font-size:9px;color:#1E7A5C;font-weight:700;line-height:1.5;background:#EAF5F0;border:1px solid #8DCFBA;border-radius:8px;padding:7px 9px">META: <?=h($meta)?></div>
</div>
<?php endforeach;?>
<div style="margin:14px 0 8px;font-size:9px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px">ROADMAP — PLAN DE EJECUCIÓN</div>
<?php foreach($ROAD as $pi=>[$ph,$title,$col,$items]):
  $dn=0; foreach($items as $ii=>$it){ if(!empty($plan_checks['r'.$pi.'_'.$ii]))$dn++; } $tot=count($items); $pct=$tot?round($dn/$tot*100):0;
?>
<div class="card rm-phase" style="margin-bottom:11px;border-left:4px solid <?=$col?>">
  <div class="card-header" style="flex-wrap:wrap;gap:8px">
    <div><div style="font-size:8px;font-weight:900;color:<?=$col?>;text-transform:uppercase;letter-spacing:1px"><?=h($ph)?></div><div class="card-title" style="font-size:11px"><?=h($title)?></div></div>
    <div style="display:flex;align-items:center;gap:8px"><span class="rm-pct" style="font-size:9px;font-weight:900;color:<?=$col?>"><?=$dn?>/<?=$tot?> · <?=$pct?>%</span><div style="width:60px;height:5px;background:<?=$BG?>;border-radius:10px;overflow:hidden"><div class="rm-bar" style="width:<?=$pct?>%;height:100%;background:<?=$col?>"></div></div></div>
  </div>
  <div style="padding:9px 16px">
  <?php foreach($items as $ii=>$it): $k='r'.$pi.'_'.$ii; $done=!empty($plan_checks[$k]); ?>
    <div style="display:flex;gap:9px;align-items:center;padding:7px 0;border-bottom:1px solid <?=$CB?>">
      <div class="rm-box" data-done="<?=$done?'1':'0'?>" onclick="togRoadmap('<?=$k?>',this)" style="width:17px;height:17px;border-radius:5px;border:1.5px solid <?=$done?'#1E7A5C':'#C8DFF0'?>;background:<?=$done?'#1E7A5C':'#fff'?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;cursor:pointer;color:#fff;font-size:9px;font-weight:900"><?=$done?'✓':''?></div>
      <span class="rm-text" style="font-size:9px;color:<?=$done?$MU:$TX?>;<?=$done?'text-decoration:line-through':''?>;line-height:1.5"><?=h($it)?></span>
    </div>
  <?php endforeach;?>
  </div>
</div>
<?php endforeach;?>
</div>

<?php $STR=['Notion CRM — Pipeline tracking, vistas del equipo, base de datos de prospectos','Programa Outreach — contacto en 60 min','Programa Retención — check-ins y llamadas de beneficios','Sistema Luna AI — asistente principal + sub-agentes','Landing Page — withisabelfuentes.com (bilingüe, CMS compliant)','Facebook / Instagram — anuncios geo-targeteados ES/EN','Contratos Multi-Aseguradora — 8 carriers','Equipo Capacitado — roles definidos']; ?>
<div id="ptab-FORTALEZAS" style="display:none">
<div style="background:#EAF5F0;border:1px solid #8DCFBA;border-radius:11px;padding:11px 15px;margin-bottom:13px;font-size:9px;font-weight:900;color:#1E7A5C;text-transform:uppercase;letter-spacing:1px">✓ LO QUE YA TIENES</div>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:9px">
<?php foreach($STR as $s):?>
<div class="card" style="padding:11px 14px;display:flex;gap:9px;align-items:flex-start;border-color:#8DCFBA">
  <div style="width:22px;height:22px;border-radius:50%;background:#1E7A5C;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0">✓</div>
  <div style="font-size:9px;color:<?=$TX?>;line-height:1.5"><?=h($s)?></div>
</div>
<?php endforeach;?>
</div>
</div>

<?php $GAPS=[['Sistema de Reputación','CRITICAL','No hay sistema de reseñas Google, no testimoniales en video, no prueba social'],['YouTube / Contenido en Video','CRITICAL','El generador de confianza más grande en Medicare. Isabel en cámara = autoridad'],['Red de Doctores y Clínicas','HIGH','Los doctores refieren pacientes. No hay alianzas formales'],['Google Local Service Ads','HIGH','La gente busca "agente Medicare cerca". No estamos capturando ese tráfico'],['Sistema de Email Newsletter','HIGH','Sin línea directa a la lista de email de miembros y prospectos'],['Sistema de Leads SEP','HIGH','Los leads de Special Enrollment Period pasan todo el año. No hay sistema'],['Broadcast SMS','MEDIUM','No podemos mensajear masivamente para AEP'],['Inteligencia Financiera','MEDIUM','No hay tracker de comisiones, ROI por canal, ni forecasting'],['Programa de Referidos','MEDIUM','Los miembros quieren referir pero no hay sistema formal']]; ?>
<div id="ptab-GAPS" style="display:none">
<div style="background:#FEF8EE;border:1px solid #F5D5A0;border-radius:11px;padding:11px 15px;margin-bottom:13px;font-size:9px;font-weight:900;color:#C07A1A;text-transform:uppercase;letter-spacing:1px">⚠ GAPS A LLENAR — LOS CRÍTICOS PRIMERO</div>
<?php foreach($GAPS as [$item,$pr,$desc]): $g=$GP[$pr]; ?>
<div class="card" style="margin-bottom:8px;padding:11px 15px;border-left:4px solid <?=$g[0]?>">
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:4px">
    <span style="font-size:10px;font-weight:900;color:<?=$P1?>"><?=h($item)?></span>
    <span style="background:<?=$g[1]?>;color:<?=$g[0]?>;border-radius:20px;padding:1px 8px;font-size:7px;font-weight:900"><?=$g[2]?></span>
  </div>
  <div style="font-size:9px;color:<?=$MU?>;line-height:1.5"><?=h($desc)?></div>
</div>
<?php endforeach;?>
</div>

<div id="ptab-PLANES" style="display:none">
<div style="margin-bottom:8px;font-size:9px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px">PLANES — ESCRIBE TUS PRIORIDADES POR HORIZONTE (SE GUARDA AL SALIR DEL CUADRO)</div>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:11px">
<?php foreach([['DIA','📅 PLAN DEL DÍA','#1B5E8C'],['SEMANA','🗓️ PLAN DE LA SEMANA','#1E7A5C'],['MES','📆 PLAN DEL MES','#5B3FAF']] as [$hz,$lbl,$col]):?>
<div class="card" style="border-top:3px solid <?=$col?>">
  <div class="card-header"><div class="card-title" style="font-size:10px"><?=$lbl?></div></div>
  <div style="padding:11px 13px">
    <textarea id="plan-nota-<?=$hz?>" class="form-input" rows="7" style="text-transform:none" placeholder="ESCRIBE AQUÍ..." onblur="planNota('<?=$hz?>')"><?=h($plan_notas[$hz]??'')?></textarea>
    <button class="btn btn-gh btn-sm" style="margin-top:6px" onclick="planNota('<?=$hz?>')">GUARDAR</button>
  </div>
</div>
<?php endforeach;?>
</div>
</div>
</div><!-- /PLANEACION -->
<script>
function showPlanTab(id){
  ['METAS','90 DÍAS','FORTALEZAS','GAPS','PLANES'].forEach(function(t){var el=document.getElementById('ptab-'+t);if(el)el.style.display=t===id?'':'none';});
  document.querySelectorAll('#tab-PLANEACION [data-ptab]').forEach(function(b){b.classList.toggle('active',b.dataset.ptab===id);});
}
function planMeta(id,val,el){
  var card=el.closest('.meta-card'); if(!card)return;
  var bar=card.querySelector('.meta-bar'); if(bar)bar.style.width=val+'%';
  var lbl=card.querySelector('.meta-pct'); if(lbl)lbl.textContent=val+'%';
}
function planMetaSave(id,val){
  fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'plan_ajax=1&action=update_meta&id='+id+'&progreso='+val})
    .then(function(r){return r.json();}).then(function(d){if(d&&d.ok&&typeof toast==='function')toast('Meta actualizada');}).catch(function(){});
}
function togRoadmap(key,el){
  var nd=el.dataset.done==='1'?0:1; el.dataset.done=nd;
  el.style.background=nd?'#1E7A5C':'#fff'; el.style.borderColor=nd?'#1E7A5C':'#C8DFF0'; el.textContent=nd?'✓':'';
  var txt=el.parentNode.querySelector('.rm-text'); if(txt){txt.style.textDecoration=nd?'line-through':'none';txt.style.color=nd?'#7A90A4':'#1B3A5C';}
  var phase=el.closest('.rm-phase');
  if(phase){var boxes=phase.querySelectorAll('.rm-box');var t=boxes.length,d=0;boxes.forEach(function(b){if(b.dataset.done==='1')d++;});var pct=t?Math.round(d/t*100):0;var lbl=phase.querySelector('.rm-pct');if(lbl)lbl.textContent=d+'/'+t+' · '+pct+'%';var bar=phase.querySelector('.rm-bar');if(bar)bar.style.width=pct+'%';}
  fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'plan_ajax=1&action=toggle_roadmap&item_key='+encodeURIComponent(key)}).catch(function(){});
}
function planNota(hz){
  var t=document.getElementById('plan-nota-'+hz); if(!t)return;
  fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'plan_ajax=1&action=save_nota&horizonte='+encodeURIComponent(hz)+'&contenido='+encodeURIComponent(t.value)})
    .then(function(r){return r.json();}).then(function(d){if(d&&d.ok&&typeof toast==='function')toast('Plan guardado');}).catch(function(){});
}
</script>

<!-- ROLES (ACTIVIDADES & ROLES) -->
<div id="tab-ROLES" class="tab-pane">
<?php
$ALL_ROLES=[
 ['r01','Inscripciones & Ventas','Responsable principal de cierres','Cierra la mayoría de inscripciones nuevas. Coordina con Isabel los cierres.'],
 ['r02','Inscripciones & Ventas','Responsable de seguimiento de leads — SLA 60 min','Primera persona que responde a todos los leads nuevos en menos de 60 minutos.'],
 ['r03','Inscripciones & Ventas','Responsable de agenda de Isabel','Coordina y protege el calendario de Isabel para citas de inscripción y eventos.'],
 ['r04','Inscripciones & Ventas','Responsable de carga al portal de aseguradoras','Sube cada inscripción al portal del carrier y captura el número de confirmación el mismo día.'],
 ['r05','Retención de Miembros','Responsable del protocolo Day 1 / 15 / 30 / 90','Ejecuta TODAS las llamadas de retención a nuevos miembros en los días 1, 15, 30 y 90 de cada inscripción.'],
 ['r06','Retención de Miembros','Responsable de casos críticos y escalación a Isabel','Identifica y escala a Isabel cualquier miembro en riesgo de baja, con queja o sin contacto +45 días.'],
 ['r07','Retención de Miembros','Responsable de Google Reviews','Pide la reseña de Google a cada miembro en el Day 30. Un mensaje por miembro, sin insistir. Meta: 50 reviews.'],
 ['r08','Retención de Miembros','Responsable de llamadas pre-AEP a todos los miembros','Septiembre-octubre: llama a los 250+ miembros para retenerlos antes del Annual Enrollment Period.'],
 ['r09','Outreach Comunitario','Responsable de senior centers y eventos comunitarios','Contacta, agenda y ejecuta todos los talleres en senior centers, iglesias y centros comunitarios. Meta: 4 eventos/mes.'],
 ['r10','Outreach Comunitario','Responsable de red de médicos y clínicas referidoras','Construye y mantiene relaciones con clínicas y médicos de Van Nuys. Meta: 10 alianzas para Sep 2026.'],
 ['r11','Outreach Comunitario','Responsable de materiales bilingüe (flyers, checklists)','Diseña y actualiza todos los materiales de campo. Sin logos de carriers. Info de contacto de Isabel incluida.'],
 ['r12','Marketing Digital','Responsable de campañas pagadas Facebook/Instagram','Crea, monitorea y optimiza todas las campañas. Meta: costo por lead menor a $25.'],
 ['r13','Marketing Digital','Responsable de contenido orgánico en redes sociales','Publica 3-5 posts/semana en español. Responde mensajes en menos de 60 min.'],
 ['r14','Marketing Digital','Responsable de compliance de comunicaciones externas','Revisa que TODO contenido y comunicación cumpla regulaciones CMS antes de publicar. Sin carriers, sin comparaciones.'],
 ['r15','CRM & Administración','Responsable de actualización diaria del CRM','Asegura que todos los leads y miembros estén actualizados en el CRM cada día antes de las 6pm.'],
 ['r16','CRM & Administración','Responsable de reportes semanales de métricas','Prepara y presenta semanalmente: inscripciones, pipeline, retención, CPL. Con datos reales del CRM.'],
 ['r17','CRM & Administración','Responsable de asignación de listas de prospectos','Cada sábado se asignan listas. Todos saben qué lista trabaja cada uno. Sin cruce de listas.'],
 ['r18','Operaciones de Oficina','Responsable de suministros y materiales de oficina','Identifica qué falta, informa a Isabel, mantiene inventario básico siempre disponible.'],
 ['r19','Operaciones de Oficina','Responsable de coordinación de agenda de Isabel','Confirma citas 24h antes, evita conflictos, notifica cambios con anticipación.'],
 ['r20','Estrategia & Reportes','Responsable del standup diario (facilitador)','Facilita el check-in matutino. Todas participan, bloqueos se registran, duración máxima 20 min.'],
];
$ROLE_ICONS=['Inscripciones & Ventas'=>'💼','Retención de Miembros'=>'🔄','Outreach Comunitario'=>'🏘','Marketing Digital'=>'📱','CRM & Administración'=>'📋','Operaciones de Oficina'=>'🏢','Estrategia & Reportes'=>'📊'];
$role_asig=[]; try{ foreach($pdo->query("SELECT role_key,agente_id FROM roles_asignacion") as $ra)$role_asig[$ra['role_key']]=(int)$ra['agente_id']; }catch(Exception $e){}
$roles_by_area=[]; foreach($ALL_ROLES as $r)$roles_by_area[$r[1]][]=$r;
$role_counts=[]; foreach($role_asig as $rk=>$aid){ if($aid)$role_counts[$aid]=($role_counts[$aid]??0)+1; }
$rl_total=count($ALL_ROLES); $rl_asig=count(array_filter($role_asig,fn($a)=>$a>0));
?>
<div class="card" style="border-top:3px solid <?=$P1?>;margin-bottom:14px;padding:13px 16px">
  <div class="card-title" style="font-size:11px">🧩 ACTIVIDADES & ROLES</div>
  <div style="font-size:8px;color:<?=$MU?>;letter-spacing:1px;text-transform:uppercase;margin-top:3px"><?=$rl_asig?>/<?=$rl_total?> RESPONSABILIDADES ASIGNADAS · ¿QUIÉN HACE QUÉ?</div>
  <?php if($role_counts):?>
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">
    <?php foreach($users_all as $uu): $cnt=$role_counts[$uu['id']]??0; if($cnt==0)continue; ?>
    <div style="display:flex;gap:5px;align-items:center;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:20px;padding:3px 11px 3px 4px">
      <?=av(h($uu['iniciales']),h($uu['color']??$P2),20)?>
      <span style="font-size:8px;font-weight:900;color:<?=$P1?>"><?=h(explode(' ',$uu['nombre'])[0])?></span>
      <span style="font-size:8px;font-weight:900;color:<?=$MU?>;background:#fff;border-radius:20px;padding:0 6px"><?=$cnt?></span>
    </div>
    <?php endforeach;?>
  </div>
  <?php endif;?>
</div>
<?php foreach($roles_by_area as $area=>$roles): $ic=$ROLE_ICONS[$area]??'•'; ?>
<div style="margin-bottom:8px;font-size:9px;font-weight:900;color:<?=$P1?>;text-transform:uppercase;letter-spacing:1.5px;padding:4px 0"><?=$ic?> <?=h($area)?></div>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:9px;margin-bottom:14px">
<?php foreach($roles as [$rk,$ar,$nm,$desc]): $asig=$role_asig[$rk]??0; ?>
<div class="card" style="padding:12px 14px<?=$asig?';border-left:4px solid #1E7A5C':''?>">
  <div style="font-size:9px;font-weight:900;color:<?=$P1?>;line-height:1.4;margin-bottom:4px"><?=h($nm)?></div>
  <div style="font-size:8px;color:<?=$MU?>;line-height:1.5;margin-bottom:8px"><?=h($desc)?></div>
  <select class="form-input" style="font-size:9px;padding:6px 9px;text-transform:none" onchange="assignRole('<?=$rk?>',this.value,this)">
    <option value="0">— SIN ASIGNAR —</option>
    <?php foreach($users_all as $uu):?><option value="<?=$uu['id']?>"<?=$asig==$uu['id']?' selected':''?>><?=h($uu['nombre'])?></option><?php endforeach;?>
  </select>
</div>
<?php endforeach;?>
</div>
<?php endforeach;?>
</div><!-- /ROLES -->
<script>
function assignRole(key,aid,el){
  fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'roles_ajax=1&action=assign_role&role_key='+encodeURIComponent(key)+'&agente_id='+aid})
    .then(function(r){return r.json();})
    .then(function(d){
      if(d&&d.ok){ if(typeof toast==='function')toast('Rol asignado'); var card=el.closest('.card'); if(card)card.style.borderLeft=(aid&&aid!=='0')?'4px solid #1E7A5C':'none'; }
      else if(typeof toast==='function')toast('Error: '+((d&&d.error)||''));
    }).catch(function(){ if(typeof toast==='function')toast('Error de red'); });
}
</script>





<!-- MI DÍA — Daily Checklist per role -->
<div id="tab-MI DÍA" class="tab-pane">
<?php
// 0. ¿Qué día es hoy?
$dia_semana = date('w'); // 0=Domingo, 1=Lunes...
$dia_mes    = date('j'); // 1 al 31

// 1. Tareas que tocan HOY
$stmt = $pdo->prepare("SELECT item_key, item_texto FROM tareas_personalizadas
                       WHERE agente_id = ?
                       AND (
                           frecuencia = 'DIARIA'
                           OR (frecuencia = 'DIAS_ESPECIFICOS' AND FIND_IN_SET(?, dias_semana) > 0)
                           OR (frecuencia = 'MENSUAL' AND dia_mes = ?)
                       )");
$stmt->execute([$uid, $dia_semana, $dia_mes]);
$mis_tareas_maestras = $stmt->fetchAll(PDO::FETCH_ASSOC);
$claves_validas = array_column($mis_tareas_maestras, 'item_key');

// 2. Limpieza inteligente
if (count($claves_validas) > 0) {
    $in_placeholders = implode(',', array_fill(0, count($claves_validas), '?'));
    $params = array_merge([$uid, $today], $claves_validas);
    $pdo->prepare("DELETE FROM checklist_diario WHERE agente_id = ? AND fecha = ? AND item_key NOT IN ($in_placeholders)")->execute($params);
} else {
    $pdo->prepare("DELETE FROM checklist_diario WHERE agente_id = ? AND fecha = ?")->execute([$uid, $today]);
}

// 3. Verificar existentes hoy
$stmt_existentes = $pdo->prepare("SELECT item_key FROM checklist_diario WHERE agente_id = ? AND fecha = ?");
$stmt_existentes->execute([$uid, $today]);
$claves_hoy = $stmt_existentes->fetchAll(PDO::FETCH_COLUMN);

// 4. Insertar las que faltan
foreach ($mis_tareas_maestras as $tarea) {
    if (!in_array($tarea['item_key'], $claves_hoy)) {
        $ins = $pdo->prepare("INSERT IGNORE INTO checklist_diario (agente_id, fecha, item_key, item_texto, completado) VALUES (?, ?, ?, ?, 0)");
        $ins->execute([$uid, $today, $tarea['item_key'], $tarea['item_texto']]);
    }
}

// 5. Lista final ORDENADA
$stmt_check = $pdo->prepare("SELECT cd.id, cd.item_key, cd.item_texto, cd.completado
                             FROM checklist_diario cd
                             JOIN tareas_personalizadas tp ON cd.item_key = tp.item_key
                                                         AND tp.agente_id = ?
                             WHERE cd.agente_id = ? AND cd.fecha = ?
                             ORDER BY tp.orden ASC, tp.id ASC");
$stmt_check->execute([$uid, $uid, $today]);
$tareas_hoy   = $stmt_check->fetchAll(PDO::FETCH_ASSOC);
$total_tareas = count($tareas_hoy);
$mis_ck_total = $total_tareas;
$mis_ck_done  = count(array_filter($tareas_hoy, fn($t) => (int)$t['completado'] === 1));
$mis_ck_pct   = $mis_ck_total > 0 ? round(($mis_ck_done / $mis_ck_total) * 100) : 0;
?>

<!-- ══ CHECKLIST HEADER ══ -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px">
  <div style="flex:1;min-width:200px">
    <div style="font-size:11px;font-weight:900;color:<?=$P1?>;letter-spacing:2px;text-transform:uppercase">📋 MI CHECKLIST DE HOY — <?=strtoupper(date('l m/d'))?></div>
    <div style="font-size:8px;color:<?=$MU?>;letter-spacing:1px;text-transform:uppercase;margin-top:2px">Marca todo antes del cierre del día</div>
    <?php if($total_tareas > 0): ?>
    <div style="margin-top:10px;display:flex;align-items:center;gap:9px">
      <div style="flex:1;height:7px;background:<?=$CB?>;border-radius:99px;overflow:hidden">
        <div id="chk-progress-bar" style="height:100%;width:0%;background:linear-gradient(to right,<?=$P2?>,<?=$G?>);border-radius:99px;transition:width .4s ease"></div>
      </div>
      <span id="chk-progress-text" style="font-size:8px;font-weight:900;color:<?=$MU?>;min-width:40px;text-align:right">0/<?=$total_tareas?></span>
    </div>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:6px;align-items:center;flex-shrink:0;margin-top:2px">
    <button onclick="document.getElementById('modal-gestor-tareas').style.display='flex'" class="btn btn-gh btn-sm">⚙️ GESTIONAR</button>
    <button onclick="resetChecklist()" class="btn btn-gh btn-sm" title="Reiniciar checklist del día">↺ REINICIAR</button>
  </div>
</div>

<!-- ══ LISTA DE TAREAS ══ -->
<?php if($total_tareas > 0): ?>
<div class="card" style="margin-bottom:14px;border-top:3px solid <?=$P1?>">
  <div class="card-header">
    <div style="display:flex;align-items:center;gap:8px">
      <div class="card-title">MIS TAREAS DEL DÍA</div>
    </div>
    <span id="chk-count-badge" style="background:<?=$BG?>;color:<?=$MU?>;border:1px solid <?=$CB?>;border-radius:20px;padding:2px 10px;font-size:8px;font-weight:900;transition:all .3s">0/<?=$total_tareas?></span>
  </div>
  <div style="padding:10px 14px;display:flex;flex-direction:column;gap:6px">
    <?php foreach($tareas_hoy as $idx => $item): ?>
    <div class="checklist-item" data-key="<?=h($item['item_key'])?>" data-completado="<?=(int)$item['completado']?>"
         style="display:flex;align-items:center;gap:12px;cursor:pointer;padding:11px 14px;border-radius:10px;border:1.5px solid <?=$CB?>;background:#fff;transition:all .2s ease;-webkit-user-select:none;user-select:none">
      <div class="chk-box" style="width:24px;height:24px;border-radius:50%;border:2.5px solid <?=$CB?>;background:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;color:<?=$MU?>;flex-shrink:0;transition:all .25s ease">○</div>
      <span class="chk-label" style="font-size:10px;font-weight:700;color:<?=$TX?>;line-height:1.4;flex:1;transition:all .2s ease"><?=h($item['item_texto'])?></span>
      <div class="chk-num" style="font-size:8px;font-weight:900;color:<?=$CB?>;flex-shrink:0"><?=str_pad($idx+1,2,'0',STR_PAD_LEFT)?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php else: ?>
<!-- ══ ESTADO VACÍO ══ -->
<div class="card" style="margin-bottom:14px;border-top:3px solid <?=$CB?>">
  <div style="padding:40px 20px;text-align:center">
    <div style="font-size:40px;margin-bottom:12px">📋</div>
    <div style="font-size:11px;font-weight:900;color:<?=$P1?>;letter-spacing:2px;text-transform:uppercase;margin-bottom:6px">SIN TAREAS PARA HOY</div>
    <div style="font-size:9px;color:<?=$MU?>;margin-bottom:18px;max-width:260px;margin-left:auto;margin-right:auto;line-height:1.6">No tienes tareas configuradas para este día de la semana. Agrega tareas y elige los días en que deben aparecer.</div>
    <button onclick="document.getElementById('modal-gestor-tareas').style.display='flex'" class="btn btn-p btn-sm">⚙️ CONFIGURAR MIS TAREAS</button>
  </div>
</div>
<?php endif; ?>

<!-- ══ BANNER COMPLETADO ══ -->
<div id="checklist-done" style="display:none;background:linear-gradient(135deg,#EAF5F0,#D1FAE5);border:2px solid #6EE7B7;border-radius:14px;padding:26px 20px;text-align:center;margin-bottom:14px;animation:fadeIn .4s ease">
  <div style="font-size:32px;margin-bottom:8px">🎉</div>
  <div style="font-size:12px;font-weight:900;color:#1E7A5C;letter-spacing:2px;text-transform:uppercase">¡CHECKLIST COMPLETO!</div>
  <div style="font-size:9px;color:#1E7A5C;margin-top:5px;text-transform:uppercase;opacity:.8">Excelente trabajo hoy, <?=h(explode(' ',$user['nombre'])[0])?></div>
</div>

<style>
/* ── CHECKLIST ITEM STATES ── */
.checklist-item.checked {
  background: #EAF5F0 !important;
  border-color: #8DCFBA !important;
}
.checklist-item.checked .chk-box {
  background: #1E7A5C !important;
  border-color: #1E7A5C !important;
  color: #fff !important;
}
.checklist-item.checked .chk-label {
  text-decoration: line-through !important;
  color: #94A3B8 !important;
}
.checklist-item.checked .chk-num { color: #8DCFBA !important; }
.checklist-item:hover:not(.checked) {
  border-color: <?=$P2?> !important;
  background: <?=$BG?> !important;
  transform: translateX(2px);
}
.checklist-item:hover:not(.checked) .chk-box { border-color: <?=$P2?> !important; }
[data-theme="dark"] .checklist-item { background: #162030 !important; border-color: #1E3045 !important; }
[data-theme="dark"] .checklist-item .chk-label { color: #D0E4F0 !important; }
[data-theme="dark"] .checklist-item:hover:not(.checked) { background: #1A2840 !important; }
[data-theme="dark"] .checklist-item.checked { background: #0D2217 !important; border-color: #1E5C3A !important; }
@keyframes fadeIn { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }
</style>

<script>
(function(){
const TODAY_KEY = '<?=$today?>';
const TOTAL = <?=$total_tareas?>;

function updateProgress() {
  const items = document.querySelectorAll('#tab-MI\\ DÍA .checklist-item');
  let done = 0;
  items.forEach(it => { if (it.classList.contains('checked')) done++; });
  const pct = TOTAL > 0 ? Math.round((done / TOTAL) * 100) : 0;
  const bar   = document.getElementById('chk-progress-bar');
  const txt   = document.getElementById('chk-progress-text');
  const badge = document.getElementById('chk-count-badge');
  const banner= document.getElementById('checklist-done');
  if (bar) {
    bar.style.width = pct + '%';
    bar.style.background = (done === TOTAL && TOTAL > 0)
      ? 'linear-gradient(to right,#1E7A5C,#16A34A)'
      : 'linear-gradient(to right,<?=$P2?>,<?=$G?>)';
  }
  if (txt) txt.textContent = done + '/' + TOTAL;
  if (badge) {
    badge.textContent = done + '/' + TOTAL;
    badge.style.background  = (done === TOTAL && TOTAL > 0) ? '#EAF5F0' : '<?=$BG?>';
    badge.style.color       = (done === TOTAL && TOTAL > 0) ? '#1E7A5C' : '<?=$MU?>';
    badge.style.borderColor = (done === TOTAL && TOTAL > 0) ? '#8DCFBA' : '<?=$CB?>';
  }
  if (banner) banner.style.display = (done === TOTAL && TOTAL > 0) ? 'block' : 'none';
}

// DESPUÉS — reemplazar por esto:
// REEMPLAZAR POR:
function bindChecklist() {
  document.querySelectorAll('#tab-MI\\ DÍA .checklist-item').forEach(function(item) {
    if (item.dataset.bound) return;
    item.dataset.bound = '1';

    // Restaurar desde data-completado (viene de la BD via PHP)
    if (item.dataset.completado === '1') {
      item.classList.add('checked');
      const box = item.querySelector('.chk-box');
      if (box) box.textContent = '✓';
      const lbl = item.querySelector('.chk-label');
      if (lbl) lbl.style.textDecoration = 'line-through';
    }

    item.addEventListener('click', function() {
      const self = this;
      const itemKey = self.dataset.key;
      const isChecked = self.classList.toggle('checked');
      const box = self.querySelector('.chk-box');
      if (box) box.textContent = isChecked ? '✓' : '○';
      const lbl2 = self.querySelector('.chk-label');
      if (lbl2) lbl2.style.textDecoration = isChecked ? 'line-through' : 'none';
      updateProgress();

      fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle_checklist&item_key=' + encodeURIComponent(itemKey)
      })
      .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
      })
      .then(function(txt) {
        let d;
        try { d = JSON.parse(txt); } 
        catch(e) { throw new Error('Respuesta no es JSON: ' + txt.substring(0,100)); }
        if (!d.ok) {
          // Revertir visualmente
          self.classList.toggle('checked');
          const b = self.querySelector('.chk-box');
          if (b) b.textContent = self.classList.contains('checked') ? '✓' : '○';
          updateProgress();
          toast('⚠ ' + (d.error || 'Error al guardar'));
        }
      })
      .catch(function(err) {
        console.error('Checklist error:', err);
        // Revertir visualmente
        self.classList.toggle('checked');
        const b = self.querySelector('.chk-box');
        if (b) b.textContent = self.classList.contains('checked') ? '✓' : '○';
        updateProgress();
        toast('⚠ Error: ' + err.message);
      });
    });
  });
  updateProgress();
}

window.resetChecklist = function() {
  if (!confirm('¿Reiniciar el checklist del día?')) return;
  document.querySelectorAll('#tab-MI\\ DÍA .checklist-item').forEach(function(item) {
    if (!item.classList.contains('checked')) return;
    item.classList.remove('checked');
    const box = item.querySelector('.chk-box');
    if (box) box.textContent = '○';
    fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=toggle_checklist&item_key=' + encodeURIComponent(item.dataset.key)
    });
  });
  updateProgress();
};

document.addEventListener('DOMContentLoaded', function(){ bindChecklist(); updateProgress(); });
setTimeout(function(){ bindChecklist(); updateProgress(); }, 300);
window._refreshChecklist = function(){ bindChecklist(); updateProgress(); };
})();
</script>
<?php
// ── OBSERVATIONS FROM ISABEL ──────────────────────────

// Isabel can leave notes for each employee stored in DB
$obs_q = $pdo->prepare("SELECT mensaje FROM notificaciones WHERE user_id=? AND tipo='OBSERVACION' ORDER BY created_at DESC LIMIT 1");
$obs_q->execute([$uid]); $obs = $obs_q->fetchColumn();
?>
<?php if($obs):?>
<div class="card" style="border-top:3px solid #C07A1A;margin-bottom:11px">
<div class="card-header"><div class="card-title"> NOTAS DE ISABEL PARA TI</div></div>
<div style="padding:13px 16px;font-size:11px;color:<?=$TX?>;line-height:1.7;background:#FEF8EE;border-radius:0 0 13px 13px"><?=nl2br(h($obs))?></div>
</div>
<?php endif;?>

<!-- MY CITAS TODAY -->
<?php $my_citas = array_filter($citas, fn($c)=>$c['agente_id']==$uid && $c['fecha']==$today); ?>
<div class="card" style="border-top:3px solid <?=$P2?>;margin-bottom:11px">
<div class="card-header">
<div class="card-title"> MIS CITAS DE HOY</div>
<div style="display:flex;gap:6px;align-items:center">
<span style="background:#EBF5FB;color:#1B5E8C;border:1px solid #A9D0E8;border-radius:20px;padding:2px 9px;font-size:8px;font-weight:900"><?=count($my_citas)?></span>
<button class="btn btn-bl btn-sm" onclick="openModal('cita-form-modal')">+ NUEVA</button>
</div>
</div>

<?php if(count($my_citas)>0):?>
<div style="padding:9px 14px;display:flex;flex-direction:column;gap:7px">
<?php foreach($my_citas as $c):?>
<div style="display:flex;align-items:center;gap:10px;padding:9px 12px;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:9px;cursor:pointer" onclick="openProfile(<?=$c['miembro_id']?>)">
<div style="font-weight:900;font-size:12px;color:<?=$P2?>;min-width:40px"><?=substr($c['hora'],0,5)?></div>
<div style="flex:1"><div style="font-weight:900;font-size:10px;color:<?=$P1?>"><?=h($c['miembro_nombre']??'—')?></div><div style="font-size:8px;color:<?=$MU?>"><?=h($c['tipo'])?> · <?=h($c['modalidad'])?></div></div>
<?=badge($c['estado'],true)?>
</div>
<?php endforeach;?>
</div>
<?php else:?><div style="padding:16px;text-align:center;font-size:8px;color:<?=$MU?>;text-transform:uppercase">SIN CITAS PARA HOY</div><?php endif;?>
</div>
<!-- DAILY REPORT -->
<div class="card" style="border-top:3px solid <?=$G?>">
<div class="card-header">
<div class="card-title">▦ REPORTE DEL DÍA</div>
<?=badge($my_reporte&&$my_reporte['enviado']?'ENVIADO ✓':'PENDIENTE',true)?>
</div>
<?php if($my_reporte&&$my_reporte['enviado']):?>
<div style="padding:13px 16px">
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:10px">
<?php
$rpt_items = [
  ['llamadas_prospectos','LLAM.PROSP.','#2876A8'],
  ['contestaron','✅ CONTS.','#1E7A5C'],
  ['interesados','★ INTERES.','#C0392B'],
  ['buzon','📬 BUZÓN','#C07A1A'],
  ['llamadas_servicio','LLAM.SERV.','#1E7A8C'],
  ['citas_confirmadas','CITAS','#1B4A6B'],
  ['tickets_resueltos','TKT.CERR.','#C07A1A'],
  ['tickets_actualizados','TKT.ACT.','#7A90A4'],
  ['apps_enviadas','APPS','#1E7A5C'],
  ['apps_por_hacer','APPS X HACER','#1B4A6B'],
  ['checklist','CHECKLIST','#1E7A5C'],
];
foreach($rpt_items as [$n,$l,$c]):
  $val = match($n) {
    'llamadas_prospectos' => $my_reporte['llamadas_prospectos'] ?? 0,
    'contestaron'         => $my_reporte['contestaron'] ?? 0,
    'interesados'         => $my_reporte['interesados'] ?? 0,
    'buzon'               => $my_reporte['buzon'] ?? 0,
    'llamadas_servicio'   => $my_reporte['llamadas_servicio'] ?? 0,
    'citas_confirmadas'   => $my_reporte['citas_confirmadas'] ?? 0,
    'tickets_resueltos'   => $my_reporte['tickets_resueltos'] ?? 0,
    'tickets_actualizados'=> $my_reporte['tickets_actualizados'] ?? 0,
    'apps_enviadas'       => $my_reporte['apps_enviadas'] ?? 0,
    'apps_por_hacer'      => $my_reporte['apps_por_hacer'] ?? 0,
    'checklist'           => ($mis_ck_done ?? 0).'  /  '.($mis_ck_total ?? 0),
    default               => 0
  };
?>
  <div style="background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:9px;padding:8px;text-align:center">
    <div style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;margin-bottom:3px;letter-spacing:.5px"><?=$l?></div>
    <div style="font-size:20px;font-weight:900;color:<?=$c?>"><?=$val?></div>
  </div>
<?php endforeach;?>
</div>
<?php if(!empty($my_reporte['nota'])):?>
<div style="background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:8px;padding:10px 13px;font-size:9px;color:<?=$TX?>;line-height:1.6">
<div style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;margin-bottom:4px">NOTA DEL DÍA</div>
<?=nl2br(h($my_reporte['nota']))?>
</div>
<?php endif;?>
</div>
<?php else:?>
<form style="padding:14px 16px" onsubmit="submitReporte(event)">
<div style="font-size:8px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px">Llena esto antes de hacer check-out</div>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:9px">
  <?php
  $rpt_form = [
    ['llamadas_prospectos','LLAM.PROSP.'],
    ['contestaron','✅ CONTS.'],
    ['interesados','★ INTERES.'],
    ['buzon','📬 BUZÓN'],
    ['llamadas_servicio','LLAM.SERV.'],
    ['citas_confirmadas','CITAS'],
    ['tickets_resueltos','TKT.CERR.'],
    ['tickets_actualizados','TKT.ACT.'],
    ['apps_enviadas','APPS COMPL.'],
    ['apps_por_hacer','APPS X HACER'],
  ];
  foreach($rpt_form as [$n,$l]):
    $val = match($n) {
      'llamadas_prospectos' => $mis_llamadas_prospectos_hoy ?? 0,
      'contestaron'         => $mis_llamadas_prosp_conts ?? 0,
      'interesados'         => $my_reporte['interesados'] ?? 0,
      'buzon'               => $mis_llamadas_prosp_no_conts ?? 0,
      'llamadas_servicio'   => $mis_llamadas_servicio_hoy ?? 0,
      'citas_confirmadas'   => $mis_citas_creadas_hoy ?? 0,
      'tickets_resueltos'   => $mis_cerrados_hoy ?? 0,
      'tickets_actualizados'=> $tkt_act_count ?? 0,
      'apps_enviadas'       => $mis_apps_hoy ?? 0,
      'apps_por_hacer'      => $mis_apps_por_hacer ?? 0,
      default               => 0
    };
    $readonly_fields = ['llamadas_prospectos','contestaron','buzon','llamadas_servicio','tickets_resueltos','tickets_actualizados','citas_confirmadas','apps_enviadas','apps_por_hacer'];
  ?>
  <div style="background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:9px;padding:8px">
    <label class="form-label"><?=$l?></label>
    <input type="number" name="<?=$n?>" min="0" value="<?=$val?>" class="form-input" style="text-align:center;font-size:16px;font-weight:900;color:<?=$P1?>" <?=in_array($n,$readonly_fields)?'readonly':''?>>
  </div>
  <?php endforeach;?>
  </div>
  

<div class="form-group"><label class="form-label">NOTA DEL DÍA — ¿Qué hiciste hoy?</label><textarea name="nota" class="form-input" rows="3" placeholder="Ej: Hice 15 llamadas, confirmé 3 citas, ayudé con caso de Leonza Lozano..." style="text-transform:none"></textarea></div>
<button type="submit" class="btn btn-p btn-full" style="font-size:11px">▦ ENVIAR REPORTE DEL DÍA</button>
</form>
<?php endif;?>
</div>
</div><!-- /MI DÍA -->
<!-- PORTALES -->
<div id="tab-PORTALES" class="tab-pane">
<?php
$portal_members = $pdo->query("
SELECT id,nombre,apellido,carrier,plan,fecha_efectiva,estado,
app_estado_cms,app_carrier_estado,
DATEDIFF(CURDATE(),fecha_efectiva) as dias_activo
FROM miembros
WHERE estado IN ('ACTIVE','CANCELED','DENIED','CERRADO','DISENROLLED','IN PROCESS')
OR (carrier IS NOT NULL AND carrier != '')
ORDER BY FIELD(estado,'ACTIVE','IN PROCESS','CANCELED','DENIED','DISENROLLED'), fecha_efectiva DESC
")->fetchAll();
$p_activos = count(array_filter($portal_members,fn($m)=>$m['estado']==='ACTIVE'));
$p_cancelados= count(array_filter($portal_members,fn($m)=>in_array($m['estado'],['CANCELED','DENIED','CERRADO','DISENROLLED'])));
$p_pendientes= count(array_filter($portal_members,fn($m)=>$m['estado']==='IN PROCESS'));
?>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
<div style="background:#EAF5F0;border:1px solid #8DCFBA;border-radius:10px;padding:9px 18px;text-align:center;flex:1;min-width:80px">
<div style="font-size:8px;font-weight:900;color:#1E7A5C;text-transform:uppercase;letter-spacing:1px"> EFECTIVOS</div>
<div id="p-cnt-activos" style="font-size:26px;font-weight:900;color:#1E7A5C"><?=$p_activos?></div>
</div>
<div style="background:#FDF0EE;border:1px solid #EFA09A;border-radius:10px;padding:9px 18px;text-align:center;flex:1;min-width:80px">
<div style="font-size:8px;font-weight:900;color:#B83232;text-transform:uppercase;letter-spacing:1px"> CANCELADOS</div>
<div id="p-cnt-cancelados" style="font-size:26px;font-weight:900;color:#B83232"><?=$p_cancelados?></div>
</div>
<div style="background:#FEF8EE;border:1px solid #F5D5A0;border-radius:10px;padding:9px 18px;text-align:center;flex:1;min-width:80px">
<div style="font-size:8px;font-weight:900;color:#C07A1A;text-transform:uppercase;letter-spacing:1px">⚠ PENDIENTES</div>
<div id="p-cnt-pendientes" style="font-size:26px;font-weight:900;color:#C07A1A"><?=$p_pendientes?></div>
</div>
<div style="background:#EBF5FB;border:1px solid #A9D0E8;border-radius:10px;padding:9px 18px;text-align:center;flex:1;min-width:80px">
<div style="font-size:8px;font-weight:900;color:#1B5E8C;text-transform:uppercase;letter-spacing:1px"> TOTAL</div>
<div id="p-cnt-total" style="font-size:26px;font-weight:900;color:#1B5E8C"><?=count($portal_members)?></div>
</div>
</div>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:11px">
<select id="pf-carrier" onchange="filterPortalTab()" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase">
<option value="">TODOS LOS CARRIERS</option>

<?php foreach(['SCAN','ANTHEM','HUMANA','ALIGNMENT','LA CARE','HEALTH NET','MOLINA','UNITED'] as $c):?>
<option><?=$c?></option>
<?php endforeach;?>
</select>
<select id="pf-estado" onchange="filterPortalTab()" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase">
<option value="">TODOS LOS ESTADOS</option>
<option value="ACTIVE"> ACTIVE</option>
<option value="IN PROCESS">⚠ IN PROCESS</option>
<option value="CANCELED"> CANCELED</option>
<option value="DENIED"> DENIED</option>
<option value="CERRADO"> CERRADO</option>
</select>
</div>
<div class="card">
<div class="card-header">
<div class="card-title"> PORTALES — EFECTIVOS Y CANCELADOS</div>
<button class="btn btn-gh btn-sm" onclick="exportPortalCSV()"> CSV</button>
</div>
<div style="overflow-x:auto"><table>
<tr><th>MIEMBRO</th><th>CARRIER</th><th>PLAN</th><th>F. EFECTIVA</th><th>DÍAS ACTIVO</th><th>ESTADO</th><th>APP STATUS</th><th></th></tr>
<tbody id="portal-tab-tbody">
<?php foreach($portal_members as $m):
$dias = $m['fecha_efectiva'] ? $m['dias_activo'] : null;
$ec=['ACTIVE'=>['#1E7A5C','#EAF5F0'],'CANCELED'=>['#B83232','#FDF0EE'],'DENIED'=>['#B83232','#FDF0EE'],'CERRADO'=>['#888780','#F1EFE8'],'DISENROLLED'=>['#993C1D','#FAECE7'],'IN PROCESS'=>['#1B5E8C','#EBF5FB']];
[$ec_color,$ec_bg]=$ec[$m['estado']]??['#7A90A4','#EBF4F9'];
?>
<tr class="portal-tab-row" data-estado="<?=$m['estado']?>" data-carrier="<?=strtoupper($m['carrier']??'')?>">
<td style="font-weight:900;font-size:9px;color:<?=$P2?>;cursor:pointer" onclick="openProfile(<?=$m['id']?>)"><?=h($m['apellido'].', '.$m['nombre'])?></td>
<td><span style="background:#EBF5FB;color:#1B5E8C;border:1px solid #A9D0E8;border-radius:20px;padding:2px 8px;font-size:8px;font-weight:900"><?=h($m['carrier']??'—')?></span></td>
<td style="font-size:8px;color:<?=$MU?>"><?=h(substr($m['plan']??'—',0,28))?></td>
<td style="font-size:8px;color:<?=$MU?>"><?=$m['fecha_efectiva']??'—'?></td>
<?php
if ($dias === null): ?>
    <td style="font-size:9px;color:#7A90A4">—</td>
<?php elseif ($dias < 0): ?>
    <td style="font-size:8px;font-weight:900;color:#5B3FAF">Inicia en <?=abs($dias)?> días</td>
<?php else: ?>
    <td style="font-size:9px;font-weight:900;color:<?=$dias>90?'#1E7A5C':($dias>30?'#C07A1A':'#B83232')?>"><?=$dias?> días</td>
<?php endif; ?>
<td><span style="background:<?=$ec_bg?>;color:<?=$ec_color?>;border:1px solid <?=$ec_color?>40;border-radius:20px;padding:2px 9px;font-size:9px;font-weight:900"><?=$m['estado']?></span></td>
<td style="font-size:8px;color:<?=$MU?>"><?=h($m['app_estado_cms']??'—')?></td>
<td><button class="btn btn-b btn-sm" onclick="openProfile(<?=$m['id']?>)">◉</button></td>
</tr>
<?php endforeach;?>
</tbody>
</table></div>
</div>
</div><!-- /PORTALES -->
<!-- MIEMBROS -->
<div id="tab-MIEMBROS" class="tab-pane">
<div style="display:flex;gap:8px;margin-bottom:11px;flex-wrap:wrap;align-items:center">
<div style="display:flex;align-items:center;gap:10px;background:#fff;border:2px solid <?=$CB?>;border-radius:12px;padding:10px 15px;flex:1;max-width:400px;box-shadow:0 2px 8px rgba(0,0,0,.05)">
<span style="font-size:16px;color:<?=$P2?>"> </span>
<input type="text" id="member-search" placeholder="BUSCAR POR NOMBRE, TEL, MBI, DIRECCIÓN..." onkeyup="smartSearch()" style="background:transparent;border:none;outline:none;font-size:13px;width:100%;font-family:'DM Sans',sans-serif;text-transform:uppercase;color:<?=$TX?>">

</div>
<select id="filter-estado" onchange="filterMembers()" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase"><option value="">TODOS LOS ESTADOS</option><?php foreach(['ACTIVE','IN PROCESS','PLAN CHANGE','SIN HACER','SIN FIRMAR','CANCELED','DENIED','CERRADO'] as $e):?><option><?=$e?></option><?php endforeach;?></select>
<button class="btn btn-b btn-sm" onclick="openMemberForm()">+ NUEVO MIEMBRO</button>
</div>
<div style="display:flex;gap:4px;margin-bottom:11px;flex-wrap:wrap">
<?php 
$nm = date('Y-m', strtotime('first day of next month'));
// Meses disponibles para el filtro (basado en fecha_efectiva de los miembros)
$meses_disponibles = [];
foreach($members as $m_){
    $mes = substr($m_['fecha_efectiva']??'',0,7);
    if($mes && !in_array($mes,$meses_disponibles)) $meses_disponibles[] = $mes;
}
sort($meses_disponibles);

$pill_list = ['TODOS','FUTUROS','ACTIVE','READY TO ENROLL','IN PROCESS','PLAN CHANGE','CANCELED'];
foreach($pill_list as $p):
    if($p === 'TODOS'){ 
        $lbl='TODOS'; $val=''; $c=count($members); 
    } elseif($p === 'FUTUROS'){ 
        $lbl='FUTUROS EFECTIVOS'; $val='FUTUROS';
        // Cualquier estado con fecha_efectiva el próximo mes
        $c=count(array_filter($members, fn($m)=>
            str_starts_with($m['fecha_efectiva']??'', $next_month_str)
        ));
    } else { 
        $lbl=$p; $val=$p; 
        $c=count(array_filter($members, fn($m)=>$m['estado']===$p)); 
    }
?>
<button class="pill-btn<?=$p==='TODOS'?' active':''?>" data-estado="<?=$val?>" onclick="setPill(this)" style="padding:4px 11px;border-radius:20px;border:1px solid <?=$CB?>;background:#fff;color:<?=$MU?>;font-size:8px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif;letter-spacing:1px;text-transform:uppercase">
    <?=$lbl?> <span style="opacity:.6">(<?=$c?>)</span>
</button>
<?php endforeach;?>
<!-- Filtro por mes de efectividad -->
<?php if(!empty($meses_disponibles)): ?>
<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:8px">
  <span style="font-size:8px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px">📅 EFECTIVO:</span>
  <button class="mes-pill active" data-mes="" onclick="setMesPill(this)"
    style="padding:3px 10px;border-radius:20px;border:1px solid <?=$CB?>;background:<?=$P1?>;color:#fff;font-size:8px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif;letter-spacing:1px;text-transform:uppercase">
    TODOS
  </button>
  <?php foreach($meses_disponibles as $ms): 
    $dt = DateTime::createFromFormat('Y-m', $ms);
    $lbl_mes = $dt ? strtoupper($dt->format('M Y')) : $ms;
    $cnt_mes = count(array_filter($members, fn($m_)=>substr($m_['fecha_efectiva']??'',0,7)===$ms));
  ?>
  <button class="mes-pill" data-mes="<?=$ms?>" onclick="setMesPill(this)"
    style="padding:3px 10px;border-radius:20px;border:1px solid <?=$CB?>;background:#fff;color:<?=$MU?>;font-size:8px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif;letter-spacing:1px;text-transform:uppercase">
    <?=$lbl_mes?> <span style="opacity:.6">(<?=$cnt_mes?>)</span>
  </button>
  <?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<div class="card"><div style="overflow-x:auto"><table id="members-table">
<tr><th>MIEMBRO</th><th>TELÉFONO</th><th>CIUDAD</th><th>PLAN/CARRIER</th><th>ESTADO</th><th>MBI</th><th>TKT</th><th></th></tr>
<?php foreach($members as $m):$mtks=count(array_filter($tickets,fn($t)=>$t['miembro_id']==$m['id']&&$t['estado']!=='CERRADO'));?>
<tr class="member-row" data-estado="<?=$m['estado']?>" data-fecha="<?=$m['fecha_efectiva']?>" data-subestado="<?=$m['subestado']??''?>" data-mes="<?=substr($m['fecha_efectiva']??''  ,0,7)?>" data-agente="<?=$m['agente_id']?>" data-search="<?=strtolower($m['apellido'].' '.$m['nombre'].' '.$m['telefono'].' '.$m['mbi'].' '.$m['carrier'].' '.$m['zip'].' '.($m['direccion_calle']??'').' '.($m['ciudad']??''))?>" style="cursor:pointer" onclick="openProfile(<?=$m['id']?>)">
<td><div style="display:flex;gap:7px;align-items:center"><?=av(h($m['agente_ini']??'?'),h($m['agente_color']??$P2),24)?><div><div style="font-weight:900;font-size:10px;color:<?=$P1?>"><?=h($m['apellido'].', '.$m['nombre'])?><?=(!empty($m['has_soa'])&&$m['has_soa']==0)?'<span style="color:#B83232;font-size:9px" title="SOA PENDIENTE"> </span>':''?><?=(!empty($m['sales_allegation']))?'<span style="background:#B83232;color:#fff;border-radius:4px;padding:1px 5px;font-size:7px;font-weight:900;margin-left:4px" title="SALES ALLEGATION">⚠ ALLEG.</span>':''?></div><div style="font-size:8px;color:<?=$MU?>"><?=$m['dob']?(date('Y')-date('Y',strtotime($m['dob']))).' AÑOS':''?><?php if($m['alerta_activa']):?> <?php endif;?></div></div></div></td>
<td style="font-size:9px;color:<?=$MU?>"><?=h($m['telefono'])?></td>
<td style="font-size:8px;color:<?=$MU?>"><?=h($m['ciudad'])?></td>
<td><?php if($m['plan']):?><div style="font-size:9px;font-weight:800;color:<?=$TX?>"><?=h($m['plan'])?></div><div style="font-size:8px;color:<?=$P2?>"><?=h($m['carrier'])?></div><?php else:?><span style="color:<?=$MU?>;font-size:8px">—</span><?php endif;?></td>
<td><?=badge($m['estado'])?></td>
<td style="font-size:8px;color:<?=$MU?>"><?=h($m['mbi']??'—')?></td>
<td><?php if($mtks>0):?><span style="background:#FDF0EE;color:#B83232;border:1px solid #EFA09A;border-radius:20px;padding:2px 7px;font-size:8px;font-weight:900"><?=$mtks?></span><?php else:?>—<?php endif;?></td>
<td onclick="event.stopPropagation()"><button class="btn btn-b btn-sm" onclick="openProfile(<?=$m['id']?>)">◉</button></td>
</tr>
<?php endforeach;?>
</table></div>
<div style="padding:7px 14px;font-size:8px;color:<?=$MU?>;border-top:1px solid <?=$CB?>;letter-spacing:1.5px;text-transform:uppercase;background:<?=$BG?>">MOSTRANDO <span id="member-count"><?=count($members)?></span> MIEMBROS</div>
</div>

<!-- ══ HISTORIAL POR MES ══════════════════════════════════════════════════ -->
<div class="card" style="margin-top:11px">
  <div class="card-header">
    <div>
      <div class="card-title">🗂 HISTORIAL DE MIEMBROS POR MES</div>
      <div class="card-sub">Todos los que tuvieron plan activo en ese mes — sin importar si después cambiaron o cancelaron</div>
    </div>
    <div style="display:flex;gap:7px;align-items:center">
      <input type="month" id="hist-mes-input" value="<?=date('Y-m')?>"
        style="padding:5px 9px;border:1.5px solid <?=$CB?>;border-radius:8px;font-size:9px;font-family:'DM Sans',sans-serif;color:<?=$P1?>"
        onchange="cargarHistorial(this.value)">
      <button class="btn btn-b btn-sm" onclick="cargarHistorial(document.getElementById('hist-mes-input').value)">VER →</button>
    </div>
  </div>
  <div id="hist-result" style="padding:18px;text-align:center;font-size:9px;color:<?=$MU?>;text-transform:uppercase">
    ← SELECCIONA UN MES PARA VER EL HISTORIAL
  </div>
</div>

<script>
function cargarHistorial(mes) {
  const box = document.getElementById('hist-result');
  box.innerHTML = '<div style="padding:18px;text-align:center;font-size:9px;color:#7A90A4">⏳ CARGANDO...</div>';
  const fd = new FormData();
  fd.append('action','get_historial_mes'); fd.append('mes',mes);
  fetch('api.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(!d.ok||!d.historial.length){
      box.innerHTML='<div style="padding:24px;text-align:center;font-size:9px;color:#7A90A4;text-transform:uppercase">SIN REGISTROS PARA ESE MES</div>';
      return;
    }
    const h = d.historial;
    const newEnroll = h.filter(x=>x.subestado==='NEW ENROLLMENT'||!x.subestado);
    const reSigned  = h.filter(x=>x.subestado==='RE-SIGNED');
    const dt = new Date(mes+'-01');
    const mesLabel = dt.toLocaleDateString('en-US',{month:'long',year:'numeric'}).toUpperCase();

    let html = `<div style="display:flex;gap:10px;padding:12px 15px;border-bottom:1px solid #C8DFF0;flex-wrap:wrap">
      <div style="background:#EAF5F0;border:1px solid #8DCFBA;border-radius:9px;padding:8px 16px;text-align:center;min-width:100px">
        <div style="font-size:22px;font-weight:900;color:#1E7A5C">${h.length}</div>
        <div style="font-size:8px;color:#1E7A5C;font-weight:900;text-transform:uppercase">TOTAL ${mesLabel}</div>
      </div>
      <div style="background:#EBF4F9;border:1px solid #A9D0E8;border-radius:9px;padding:8px 16px;text-align:center;min-width:100px">
        <div style="font-size:22px;font-weight:900;color:#1B4A6B">${newEnroll.length}</div>
        <div style="font-size:8px;color:#1B4A6B;font-weight:900;text-transform:uppercase">NEW ENROLLMENT</div>
      </div>
      <div style="background:#F3F0FB;border:1px solid #C2B0E8;border-radius:9px;padding:8px 16px;text-align:center;min-width:100px">
        <div style="font-size:22px;font-weight:900;color:#5B3FAF">${reSigned.length}</div>
        <div style="font-size:8px;color:#5B3FAF;font-weight:900;text-transform:uppercase">RE-SIGNED</div>
      </div>
    </div>
    <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">
    <tr>
      <th style="background:#EBF4F9;color:#2876A8;font-size:8px;text-transform:uppercase;padding:6px 10px;text-align:left;border-bottom:2px solid #C8DFF0">MIEMBRO</th>
      <th style="background:#EBF4F9;color:#2876A8;font-size:8px;text-transform:uppercase;padding:6px 10px;border-bottom:2px solid #C8DFF0">PLAN / CARRIER</th>
      <th style="background:#EBF4F9;color:#2876A8;font-size:8px;text-transform:uppercase;padding:6px 10px;border-bottom:2px solid #C8DFF0">TIPO</th>
      <th style="background:#EBF4F9;color:#2876A8;font-size:8px;text-transform:uppercase;padding:6px 10px;border-bottom:2px solid #C8DFF0">INICIO</th>
      <th style="background:#EBF4F9;color:#2876A8;font-size:8px;text-transform:uppercase;padding:6px 10px;border-bottom:2px solid #C8DFF0">FIN / MOTIVO</th>
      <th style="background:#EBF4F9;color:#2876A8;font-size:8px;text-transform:uppercase;padding:6px 10px;border-bottom:2px solid #C8DFF0">AGENTE</th>
    </tr>`;

    h.forEach((r,i)=>{
      const fin = r.fecha_fin
        ? `<span style="color:#B83232">${r.fecha_fin.substring(0,7)}</span> <span style="font-size:7px;background:#FDF0EE;color:#B83232;border-radius:10px;padding:1px 6px">${r.motivo_fin||''}</span>`
        : `<span style="color:#1E7A5C;font-weight:900">ACTIVO ✓</span>`;
      const tipo = r.subestado === 'RE-SIGNED'
        ? `<span style="background:#F3F0FB;color:#5B3FAF;border-radius:20px;padding:1px 7px;font-size:7px;font-weight:900">🔄 RE-SIGNED</span>`
        : `<span style="background:#EAF5F0;color:#1E7A5C;border-radius:20px;padding:1px 7px;font-size:7px;font-weight:900">✦ NEW</span>`;
      html += `<tr style="border-bottom:1px solid #EBF4F9;${i%2?'background:#F8FBFD':''}">
        <td style="padding:7px 10px;cursor:pointer" onclick="openProfile(${r.miembro_id})">
          <div style="font-weight:900;font-size:10px;color:#1B4A6B">${r.apellido}, ${r.nombre}</div>
          <div style="font-size:8px;color:#7A90A4">${r.telefono||'—'} · ${r.ciudad||'—'}</div>
        </td>
        <td style="padding:7px 10px">
          <div style="font-size:9px;font-weight:800;color:#1B3A5C">${r.plan||'—'}</div>
          <div style="font-size:8px;color:#2876A8">${r.carrier||'—'}</div>
        </td>
        <td style="padding:7px 10px">${tipo}</td>
        <td style="padding:7px 10px;font-size:9px;color:#7A90A4">${r.fecha_inicio.substring(0,7)}</td>
        <td style="padding:7px 10px;font-size:9px">${fin}</td>
        <td style="padding:7px 10px;font-size:8px;color:#7A90A4">${r.agente_nombre||'—'}</td>
      </tr>`;
    });
    html += '</table></div>';
    box.innerHTML = html;
  }).catch(()=>{ box.innerHTML='<div style="padding:18px;text-align:center;font-size:9px;color:#B83232">ERROR CARGANDO HISTORIAL</div>'; });
}
</script>

</div><!-- /MIEMBROS -->

<!-- PIPELINE -->

<?php
// ══════════════════════════════════════════════════════
// TAB RETENCIÓN — embebido directamente en index.php
// ══════════════════════════════════════════════════════

// Crear tablas si no existen
try { $pdo->exec("CREATE TABLE IF NOT EXISTS retencion_llamadas (id INT AUTO_INCREMENT PRIMARY KEY, miembro_id INT NOT NULL, tipo ENUM('BIENVENIDA','30','60','90') NOT NULL, resultado ENUM('COMPLETADA','NO CONTESTÓ','BUZÓN') NOT NULL, notas TEXT, completada_por INT, completada_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_ret (miembro_id, tipo))"); } catch(Exception $e){}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS retencion_cuestionario_30 (id INT AUTO_INCREMENT PRIMARY KEY, miembro_id INT NOT NULL, puede_sms TINYINT(1) DEFAULT NULL, usa_whatsapp TINYINT(1) DEFAULT NULL, usa_facebook TINYINT(1) DEFAULT NULL, nos_siguio TINYINT(1) DEFAULT NULL, link_enviado TINYINT(1) DEFAULT NULL, usa_insulina TINYINT(1) DEFAULT NULL, ayudas_movilidad VARCHAR(500) DEFAULT NULL, necesita_delivery TINYINT(1) DEFAULT NULL, llego_tarjeta TINYINT(1) DEFAULT NULL, explicaste_tarjeta TINYINT(1) DEFAULT NULL, direccion_correcta TINYINT(1) DEFAULT NULL, esta_casado TINYINT(1) DEFAULT NULL, doctor_correcto TINYINT(1) DEFAULT NULL, ha_ido_citas TINYINT(1) DEFAULT NULL, satisfecho_doctor TINYINT(1) DEFAULT NULL, cambiar_doctor TINYINT(1) DEFAULT NULL, va_dentista TINYINT(1) DEFAULT NULL, necesita_dentista TINYINT(1) DEFAULT NULL, usa_anteojos TINYINT(1) DEFAULT NULL, explicaste_uber TINYINT(1) DEFAULT NULL, explicaste_gym TINYINT(1) DEFAULT NULL, beneficios_repasados TEXT DEFAULT NULL, explicaste_no_dar_info TINYINT(1) DEFAULT NULL, referido_nuevo VARCHAR(255) DEFAULT NULL, donde_conocio_isabel VARCHAR(255) DEFAULT NULL, notas_generales TEXT DEFAULT NULL, completada_por INT DEFAULT NULL, completada_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_q30 (miembro_id))"); } catch(Exception $e){}

// Cargar datos de retención
$_ret_calls = [];
try { $rs = $pdo->query("SELECT rl.*, u.nombre AS por_nombre FROM retencion_llamadas rl LEFT JOIN usuarios u ON rl.completada_por=u.id"); foreach($rs as $rl) { $_ret_calls[$rl['miembro_id']][$rl['tipo']] = $rl; } } catch(Exception $e){}

$_ret_bienvenidas = [];
try { foreach($pdo->query("SELECT miembro_id, created_at FROM efectivos_checks WHERE tipo='llam_bienvenida' AND done=1") as $ec) { $_ret_bienvenidas[$ec['miembro_id']] = $ec['created_at']; } } catch(Exception $e){}

$_ret_q30_ids = [];
try { foreach($pdo->query("SELECT miembro_id, completada_at FROM retencion_cuestionario_30") as $q) { $_ret_q30_ids[$q['miembro_id']] = $q['completada_at']; } } catch(Exception $e){}

// Procesar miembros activos
$_today_ts = strtotime(date('Y-m-d'));
$_ret_list = [];
foreach($members as $m) {
    if($m['estado'] !== 'ACTIVE') continue;
    if(empty($m['fecha_efectiva'])) continue;
    $dias = (int)round(($_today_ts - strtotime($m['fecha_efectiva'])) / 86400);
    if($dias < 0) continue;
    $bienvenida_done = isset($_ret_bienvenidas[$m['id']]);
    $call30 = $_ret_calls[$m['id']]['30'] ?? null;
    $call60 = $_ret_calls[$m['id']]['60'] ?? null;
    $call90 = $_ret_calls[$m['id']]['90'] ?? null;
    $urgente = false;
    if(!$bienvenida_done && $dias <= 14) $urgente = true;
    if(!$bienvenida_done && $dias > 14)  $urgente = true;
    if(!$call30 && $dias >= 25) $urgente = true;
    if(!$call60 && $dias >= 55) $urgente = true;
    if(!$call90 && $dias >= 85) $urgente = true;
    $_ret_list[] = ['id'=>$m['id'],'nombre'=>$m['nombre'],'apellido'=>$m['apellido'],'telefono'=>$m['telefono']??'','carrier'=>$m['carrier']??'','fecha_efe'=>$m['fecha_efectiva'],'dias'=>$dias,'bienvenida'=>$bienvenida_done?($_ret_bienvenidas[$m['id']]??''):null,'call30'=>$call30,'call60'=>$call60,'call90'=>$call90,'q30'=>isset($_ret_q30_ids[$m['id']])?$_ret_q30_ids[$m['id']]:null,'urgente'=>$urgente];
}
usort($_ret_list, function($a,$b){ return $b['urgente']<=>$a['urgente'] ?: $a['dias']<=>$b['dias']; });
$_st_total   = count($_ret_list);
$_st_urgente = count(array_filter($_ret_list, function($m){ return $m['urgente']; }));
$_st_bienok  = count(array_filter($_ret_list, function($m){ return $m['bienvenida']; }));
$_st_30ok    = count(array_filter($_ret_list, function($m){ return $m['call30']; }));
$_st_60ok    = count(array_filter($_ret_list, function($m){ return $m['call60']; }));
$_st_90ok    = count(array_filter($_ret_list, function($m){ return $m['call90']; }));
?>
<div id="tab-RETENCION" class="tab-pane">
<div style="padding:18px 20px 10px">

<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:9px;margin-bottom:18px">
<?php
$_ret_stats = [['ACTIVOS',$_st_total,'#1B4A6B'],['URGENTES',$_st_urgente,'#B83232'],['BIENVENIDA',$_st_bienok,'#1E7A5C'],['30 DÍAS',$_st_30ok,'#1E7A5C'],['60 DÍAS',$_st_60ok,'#1E7A8C'],['90 DÍAS',$_st_90ok,'#5B3FAF']];
foreach($_ret_stats as $_rs) {
    echo "<div style='background:#fff;border:1px solid #C8DFF0;border-top:3px solid {$_rs[2]};border-radius:10px;padding:10px 13px;text-align:center'><div style='font-size:7px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:.7px;margin-bottom:3px'>".htmlspecialchars($_rs[0])."</div><div style='font-size:20px;font-weight:900;color:{$_rs[2]}'>{$_rs[1]}</div></div>";
}
?>
</div>

<div style="display:flex;gap:7px;margin-bottom:13px;align-items:center;flex-wrap:wrap">
<span style="font-size:9px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:.7px">FILTRAR:</span>
<?php foreach(['TODOS','URGENTES','BIENVENIDA','30 DÍAS','60 DÍAS','90 DÍAS','✓ CON CUESTIONARIO','○ SIN CUESTIONARIO'] as $_rf): ?>
<button class="btn btn-gh btn-sm ret-fb" data-rf="<?=htmlspecialchars($_rf)?>" onclick="retFilter('<?=htmlspecialchars($_rf)?>')" style="<?=$_rf==='TODOS'?'background:#1B4A6B;color:#fff':''?>"><?=htmlspecialchars($_rf)?></button>
<?php endforeach; ?>
<input id="ret-search" type="text" placeholder="Buscar miembro..." class="form-input" style="max-width:200px;margin-left:auto" oninput="retFilter()">
</div>

<div style="overflow-x:auto">
<table>
<tr><th>MIEMBRO</th><th>CARRIER</th><th>F. EFECTIVA</th><th style="text-align:center">DÍAS</th><th style="text-align:center">BIENVENIDA</th><th style="text-align:center">30 DÍAS</th><th style="text-align:center">60 DÍAS</th><th style="text-align:center">90 DÍAS</th><th style="text-align:center">CUEST.</th><th></th></tr>
<?php foreach($_ret_list as $_rm):
    $_dias = $_rm['dias'];
    $_mid  = (int)$_rm['id'];
    $_ur   = $_rm['urgente'] ? '1' : '0';
    $_srch = strtolower($_rm['apellido'].' '.$_rm['nombre'].' '.$_rm['carrier'].' '.$_rm['telefono']);

    // Helper: render chip inline
    // Bienvenida chip
    if($_rm['bienvenida']) {
        $_chip_b = "<div style='text-align:center'><div style='display:inline-block;padding:2px 8px;background:#EAF5F0;color:#1E7A5C;border:1.5px solid #8DCFBA;border-radius:20px;font-size:8px;font-weight:900'>✓ OK</div><div style='font-size:7px;color:#7A90A4'>".date('d/m/y',strtotime($_rm['bienvenida']))."</div></div>";
    } elseif($_dias <= 14) {
        $_chip_b = "<div style='text-align:center'><div style='font-size:8px;color:#C07A1A;font-weight:700'>📋 Dashboard</div><div style='font-size:7px;color:#94A3B8'>Ef. del Mes</div></div>";
    } elseif($_dias > 14) {
        $_chip_b = "<div style='text-align:center'><div style='font-size:8px;color:#B83232;font-weight:700'>🚨 Sin marcar</div><div style='font-size:7px;color:#94A3B8'>→ Dashboard</div></div>";
    } else {
        $_chip_b = "<div style='text-align:center;font-size:8px;color:#94A3B8'>—</div>";
    }

    // 30 días chip
    $_c30ts = $_rm['call30'] ? $_rm['call30']['completada_at'] : null;
    if($_c30ts) {
        $_chip_30 = "<div style='text-align:center'><div style='display:inline-block;padding:2px 8px;background:#EAF5F0;color:#1E7A5C;border:1.5px solid #8DCFBA;border-radius:20px;font-size:8px;font-weight:900'>✓ OK</div><div style='font-size:7px;color:#7A90A4'>".date('d/m/y',strtotime($_c30ts))."</div></div>";
    } elseif($_dias < 25) {
        $_chip_30 = "<div style='text-align:center;font-size:8px;color:#94A3B8'>en ".(25-$_dias)."d</div>";
    } elseif($_dias <= 40) {
        $_chip_30 = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetQ30({$_mid})' style='background:#FEF8EE;color:#C07A1A;border:1.5px solid #F5D5A0;font-size:8px;font-weight:900;padding:3px 8px'>📞 HOY</button></div>";
    } else {
        $_chip_30 = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetQ30({$_mid})' style='background:#FDF0EE;color:#B83232;border:1.5px solid #EFA09A;font-size:8px;font-weight:900;padding:3px 8px'>🚨 VENCIDA</button></div>";
    }

    // 60 días chip
    $_c60ts = $_rm['call60'] ? $_rm['call60']['completada_at'] : null;
    if($_c60ts) {
        $_chip_60 = "<div style='text-align:center'><div style='display:inline-block;padding:2px 8px;background:#EAF5F0;color:#1E7A5C;border:1.5px solid #8DCFBA;border-radius:20px;font-size:8px;font-weight:900'>✓ OK</div><div style='font-size:7px;color:#7A90A4'>".date('d/m/y',strtotime($_c60ts))."</div></div>";
    } elseif($_dias < 55) {
        $_chip_60 = "<div style='text-align:center;font-size:8px;color:#94A3B8'>en ".(55-$_dias)."d</div>";
    } elseif($_dias <= 70) {
        $_chip_60 = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"60\")' style='background:#FEF8EE;color:#C07A1A;border:1.5px solid #F5D5A0;font-size:8px;font-weight:900;padding:3px 8px'>📞 HOY</button></div>";
    } else {
        $_chip_60 = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"60\")' style='background:#FDF0EE;color:#B83232;border:1.5px solid #EFA09A;font-size:8px;font-weight:900;padding:3px 8px'>🚨 VENCIDA</button></div>";
    }

    // 90 días chip
    $_c90ts = $_rm['call90'] ? $_rm['call90']['completada_at'] : null;
    if($_c90ts) {
        $_chip_90 = "<div style='text-align:center'><div style='display:inline-block;padding:2px 8px;background:#EAF5F0;color:#1E7A5C;border:1.5px solid #8DCFBA;border-radius:20px;font-size:8px;font-weight:900'>✓ OK</div><div style='font-size:7px;color:#7A90A4'>".date('d/m/y',strtotime($_c90ts))."</div></div>";
    } elseif($_dias < 85) {
        $_chip_90 = "<div style='text-align:center;font-size:8px;color:#94A3B8'>en ".(85-$_dias)."d</div>";
    } elseif($_dias <= 100) {
        $_chip_90 = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"90\")' style='background:#FEF8EE;color:#C07A1A;border:1.5px solid #F5D5A0;font-size:8px;font-weight:900;padding:3px 8px'>📞 HOY</button></div>";
    } else {
        $_chip_90 = "<div style='text-align:center'><button class='btn btn-sm' onclick='openRetSimple({$_mid},\"90\")' style='background:#FDF0EE;color:#B83232;border:1.5px solid #EFA09A;font-size:8px;font-weight:900;padding:3px 8px'>🚨 VENCIDA</button></div>";
    }

    $_dc = $_dias<=14?'#1E7A5C':($_dias<=40?'#C07A1A':($_dias<=70?'#1E7A8C':($_dias<=100?'#5B3FAF':'#1B4A6B')));
?>
<tr class="ret-row"
 data-ur="<?=$_ur?>"
 data-dias="<?=$_dias?>"
 data-search="<?=htmlspecialchars($_srch)?>"
 data-pend-b="<?=($_rm['bienvenida']?'0':'1')?>"
 data-pend-30="<?=(!$_rm['call30']&&$_dias>=25?'1':'0')?>"
 data-pend-60="<?=(!$_rm['call60']&&$_dias>=55?'1':'0')?>"
 data-pend-90="<?=(!$_rm['call90']&&$_dias>=85?'1':'0')?>"
 data-q30="<?=$_rm['q30']?'1':'0'?>"
 style="<?=$_rm['urgente']?'background:#FFFBF2':''?>">
<td><div style="font-weight:900;font-size:9px;color:#1B4A6B;cursor:pointer" onclick="openProfile(<?=$_mid?>)"><?=htmlspecialchars($_rm['apellido'].', '.$_rm['nombre'])?></div><div style="font-size:8px;color:#7A90A4"><?=htmlspecialchars($_rm['telefono']??'—')?></div></td>
<td><?php if($_rm['carrier']): ?><span style="background:#EBF5FB;color:#1B5E8C;border:1px solid #A9D0E8;border-radius:20px;padding:1px 7px;font-size:8px;font-weight:900"><?=htmlspecialchars($_rm['carrier'])?></span><?php else: ?>—<?php endif; ?></td>
<td style="font-size:8px;color:#7A90A4"><?=$_rm['fecha_efe']?></td>
<td style="text-align:center"><span style="font-weight:900;font-size:12px;color:<?=$_dc?>"><?=$_dias?>d</span></td>
<td><?=$_chip_b?></td>
<td><?=$_chip_30?></td>
<td><?=$_chip_60?></td>
<td><?=$_chip_90?></td>
<td style="text-align:center">
<?php if($_rm['q30']): ?>
<span style="background:#EAF5F0;color:#1E7A5C;border:1px solid #8DCFBA;border-radius:20px;padding:2px 8px;font-size:8px;font-weight:900">✓</span>
<?php elseif($_dias >= 25): ?>
<button class="btn btn-sm" onclick="openRetQ30(<?=$_mid?>)" style="background:#EBF5FB;color:#1B5E8C;border:1.5px solid #A9D0E8;font-size:8px;font-weight:900">📋</button>
<?php else: ?>
<span style="color:#94A3B8;font-size:8px">—</span>
<?php endif; ?>
</td>
<td><button class="btn btn-b btn-sm" onclick="openProfile(<?=$_mid?>)">◉</button></td>
</tr>
<?php endforeach; ?>
</table>
</div>
</div>
</div>

<div class="modal-overlay" id="ret-simple-modal">
<div class="modal modal-sm">
<div class="modal-header"><div class="modal-title" id="rsm-title">📞 LLAMADA DE RETENCIÓN</div><button class="modal-close" onclick="closeModal('ret-simple-modal')">✕</button></div>
<div style="padding:16px">
<input type="hidden" id="rsm-mid"><input type="hidden" id="rsm-tipo">
<div style="font-size:9px;color:#7A90A4;margin-bottom:12px;line-height:1.6" id="rsm-script"></div>
<div class="form-group"><label class="form-label">RESULTADO *</label>
<div style="display:flex;flex-direction:column;gap:7px;margin-top:6px">
<button type="button" onclick="saveRetSimple('COMPLETADA')" style="background:#EAF5F0;color:#1E7A5C;border:1.5px solid #8DCFBA;font-weight:900;font-size:10px;padding:10px 14px;text-align:left;border-radius:8px;cursor:pointer">✅ COMPLETADA — Contestó y se hizo el seguimiento</button>
<button type="button" onclick="saveRetSimple('NO CONTESTÓ')" style="background:#FDF0EE;color:#B83232;border:1.5px solid #EFA09A;font-weight:900;font-size:10px;padding:10px 14px;text-align:left;border-radius:8px;cursor:pointer">📵 NO CONTESTÓ — 3 intentos sin respuesta</button>
<button type="button" onclick="saveRetSimple('BUZÓN')" style="background:#FEF8EE;color:#C07A1A;border:1.5px solid #F5D5A0;font-weight:900;font-size:10px;padding:10px 14px;text-align:left;border-radius:8px;cursor:pointer">📬 BUZÓN — Se dejó mensaje de voz</button>
</div></div>
<div class="form-group" style="margin-top:10px"><label class="form-label">NOTAS (opcional)</label><textarea id="rsm-notas" class="form-input" rows="2" style="text-transform:none" placeholder="Observaciones..."></textarea></div>
</div>
</div>
</div>

<div class="modal-overlay" id="ret-q30-modal">
<div class="modal" style="max-width:700px;width:96vw">
<div class="modal-header"><div class="modal-title">📋 CUESTIONARIO 30 DÍAS — <span id="rq30-nombre" style="color:#2876A8"></span></div><button class="modal-close" onclick="closeModal('ret-q30-modal')">✕</button></div>
<form id="ret-q30-form" onsubmit="submitRetQ30(event)">
<input type="hidden" name="miembro_id" id="rq30-mid">
<div style="max-height:65vh;overflow-y:auto;padding:14px 18px">
<div class="section-divider">CONTACTO Y REDES</div>
<div class="grid-3">
<?php foreach([['puede_sms','¿Puedo enviarle SMS a este número?'],['usa_whatsapp','¿Usa WhatsApp?'],['usa_facebook','¿Usa Facebook?'],['nos_siguio_ig','¿Nos siguió en Facebook/Instagram?']] as list($_qn,$_ql)): ?>
<div class="form-group"><label class="form-label"><?=$_ql?></label><div style="display:flex;gap:10px;margin-top:4px"><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="<?=$_qn?>" value="1"> SÍ</label><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="<?=$_qn?>" value="0"> NO</label><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="<?=$_qn?>" value="" checked> N/D</label></div></div>
<?php endforeach; ?>
</div>
<div style="background:#F3F0FB;border:1px solid #C2B0E8;border-radius:9px;padding:9px 13px;margin-bottom:12px;font-size:9px;color:#5B3FAF;font-weight:700">
  📱 <b>RECORDATORIO:</b> Invitar a seguirnos en <b>Facebook e Instagram</b> — compartir página y dar like. Mencionar que publicamos tips de salud y avisos importantes.
</div>
<div class="grid-2"><div class="form-group"><label class="form-label">TELÉFONO ALTERNATIVO</label><input type="text" name="telefono2_new" class="form-input" placeholder="(818) 555-0000"></div><div class="form-group"><label class="form-label">EMAIL</label><input type="email" name="email_new" class="form-input" placeholder="correo@ejemplo.com"></div></div>
<div class="section-divider">SALUD</div>
<div class="grid-2">
<?php foreach([['usa_insulina','¿Utiliza insulina?'],['necesita_delivery','¿Necesita entrega de medicinas o visita de doctor a casa?'],['en_ihss','¿Está en IHSS (cuidado en casa)?']] as list($_qn,$_ql)): ?>
<div class="form-group"><label class="form-label"><?=$_ql?></label><div style="display:flex;gap:10px;margin-top:4px"><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="<?=$_qn?>" value="1"> SÍ</label><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="<?=$_qn?>" value="0"> NO</label><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="<?=$_qn?>" value="" checked> N/D</label></div></div>
<?php endforeach; ?>
</div>
<div class="form-group"><label class="form-label">DISPOSITIVOS / AYUDAS</label><div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:5px"><?php foreach(['BASTÓN','WALKER','PAÑALES','ENSURE','NINGUNO'] as $_ay): ?><label style="display:flex;align-items:center;gap:5px;font-size:9px;font-weight:800;cursor:pointer;text-transform:uppercase"><input type="checkbox" name="ayudas_movilidad[]" value="<?=$_ay?>"> <?=$_ay?></label><?php endforeach; ?></div></div>
<div class="grid-2"><div class="form-group"><label class="form-label">ENFERMEDADES CRÓNICAS (actualizar si cambió)</label><textarea name="condiciones_cronicas_new" class="form-input" rows="2" style="text-transform:none" placeholder="Diabetes, hipertensión..."></textarea></div><div class="form-group"><label class="form-label">MEDICAMENTOS (actualizar)</label><textarea name="prescripciones_new" class="form-input" rows="2" style="text-transform:none" placeholder="Cuántos toma, nombres"></textarea></div></div>
<div class="section-divider">DIRECCIÓN</div>
<div class="form-group"><label class="form-label">¿Está correcta su dirección?</label><div style="display:flex;gap:10px;margin-top:4px"><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="direccion_correcta" value="1"> SÍ</label><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="direccion_correcta" value="0"> NO</label><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="direccion_correcta" value="" checked> N/D</label></div></div>
<div class="grid-2"><div class="form-group"><label class="form-label">CALLE (si cambió)</label><input type="text" name="direccion_calle_new" class="form-input"></div><div class="form-group"><label class="form-label">APT</label><input type="text" name="direccion_apto_new" class="form-input"></div></div>
<div class="grid-3"><div class="form-group"><label class="form-label">CIUDAD</label><input type="text" name="ciudad_new" class="form-input"></div><div class="form-group"><label class="form-label">STATE</label><input type="text" name="estado_dir_new" class="form-input" placeholder="CA" maxlength="2"></div><div class="form-group"><label class="form-label">ZIP</label><input type="text" name="zip_new" class="form-input"></div></div>
<div class="section-divider">VIDA PERSONAL</div>
<div class="grid-2">
<div class="form-group">
  <label class="form-label">¿CON QUIÉN VIVE?</label>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:5px">
    <?php foreach(['SOLO/A','ESPOSO/A','HIJOS/AS','NIETOS/AS','CUIDADOR/A','OTRO'] as $_cv): ?>
    <label style="display:flex;align-items:center;gap:5px;font-size:9px;font-weight:800;cursor:pointer;text-transform:uppercase">
      <input type="checkbox" name="con_quien_vive[]" value="<?=$_cv?>"> <?=$_cv?>
    </label>
    <?php endforeach; ?>
  </div>
</div>
<div class="form-group">
  <label class="form-label">¿CÓMO LLEGA A SUS CITAS?</label>
  <select name="transporte" class="form-input">
    <option value="">— SELECCIONAR —</option>
    <option value="MANEJA SOLO">🚗 Maneja solo/a</option>
    <option value="FAMILIAR">👨‍👩‍👧 Lo lleva un familiar</option>
    <option value="UBER/LYFT">📱 Uber / Lyft / America Logistics</option>
    <option value="TRANSPORTE PÚBLICO">🚌 Transporte público</option>
    <option value="AMBULANCIA/NEMT">🚑 Ambulancia / NEMT</option>
    <option value="NO SALE">🏠 No sale de casa</option>
    <option value="OTRO">Otro</option>
  </select>
</div>
</div>
<div class="section-divider">ORIGEN</div>
<div class="grid-2"><div class="form-group"><label class="form-label">¿DÓNDE CONOCIÓ A ISABEL?</label><input type="text" name="donde_conocio_isabel" class="form-input" placeholder="Evento, iglesia, Facebook..."></div><div class="form-group"><label class="form-label">NOMBRE DE QUIEN LO RECOMENDÓ</label><input type="text" name="referido_por_new" class="form-input" placeholder="Nombre completo"></div></div>
<div class="section-divider">TARJETAS / INFORMACIÓN PERSONAL</div>
<div class="grid-3">
<?php foreach([['llego_tarjeta','¿Le llegaron las tarjetas del plan?'],['explicaste_tarjeta','¿Le explicaste las tarjetas?'],['esta_casado','¿Está casado/a?']] as list($_qn,$_ql)): ?>
<div class="form-group"><label class="form-label"><?=$_ql?></label><div style="display:flex;gap:10px;margin-top:4px"><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="<?=$_qn?>" value="1"> SÍ</label><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="<?=$_qn?>" value="0"> NO</label><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="<?=$_qn?>" value="" checked> N/D</label></div></div>
<?php endforeach; ?>
</div>
<div class="form-group"><label class="form-label">PROFESIÓN (actualizar si cambió)</label><input type="text" name="profesion_new" class="form-input"></div>
<div class="section-divider">DOCTOR PCP</div>
<div class="grid-3">
<?php foreach([['doctor_correcto','¿Su doctor está correcto?'],['ha_ido_citas','¿Ha ido a sus citas?'],['satisfecho_doctor','¿Se siente bien con su doctor?'],['cambiar_doctor','¿Necesitamos cambiarle el doctor?']] as list($_qn,$_ql)): ?>
<div class="form-group"><label class="form-label"><?=$_ql?></label><div style="display:flex;gap:10px;margin-top:4px"><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="<?=$_qn?>" value="1"> SÍ</label><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="<?=$_qn?>" value="0"> NO</label><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="<?=$_qn?>" value="" checked> N/D</label></div></div>
<?php endforeach; ?>
</div>
<div class="grid-2"><div class="form-group"><label class="form-label">NOMBRE DOCTOR (actualizar si cambió)</label><input type="text" name="pcp_new" class="form-input" placeholder="Dr. Ramirez"></div><div class="form-group"><label class="form-label">GRUPO MÉDICO</label><input type="text" name="pcp_group_new" class="form-input" placeholder="AltaMed, ApolloMed..."></div></div>
<div class="section-divider">DENTISTA / VISIÓN</div>
<div class="grid-3">
<?php foreach([['va_dentista','¿Va al dentista?'],['necesita_dentista','¿Necesitamos recomendarle dentista?'],['usa_anteojos','¿Usa anteojos?']] as list($_qn,$_ql)): ?>
<div class="form-group"><label class="form-label"><?=$_ql?></label><div style="display:flex;gap:10px;margin-top:4px"><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="<?=$_qn?>" value="1"> SÍ</label><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="<?=$_qn?>" value="0"> NO</label><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="<?=$_qn?>" value="" checked> N/D</label></div></div>
<?php endforeach; ?>
</div>
<div class="form-group"><label class="form-label">DATOS DEL DENTISTA (actualizar si cambió)</label><input type="text" name="dentista_new" class="form-input" placeholder="Dr. Garcia - (323) 555-0000"></div>
<div class="section-divider">CUENTA / PROVEEDOR REFERIDO</div>
<div style="font-size:8px;color:#7A90A4;margin-bottom:10px">Anotar si se le recomendó o visitó algún proveedor (dentista, visión, clínica, etc.) que esté en nuestros Contactos.</div>
<div class="grid-2">
<div class="form-group">
  <label class="form-label">CUENTA / PROVEEDOR</label>
  <select name="cuenta_referida_id" id="rq30-cuenta" class="form-input">
    <option value="">— NINGUNA —</option>
    <?php foreach($cuentas_list as $_cu): ?>
    <option value="<?=(int)$_cu['id']?>"><?=h($_cu['nombre'])?><?=$_cu['tipo']?' — '.h($_cu['tipo']):''?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="form-group">
  <label class="form-label">TIPO DE RELACIÓN</label>
  <select name="cuenta_referida_tipo" class="form-input">
    <option value="">—</option>
    <option value="DENTISTA">🦷 Dentista</option>
    <option value="VISIÓN">👓 Visión / Óptica</option>
    <option value="MÉDICO">🩺 Médico especialista</option>
    <option value="CLÍNICA">🏥 Clínica</option>
    <option value="FARMACIA">💊 Farmacia</option>
    <option value="OTRO">Otro</option>
  </select>
</div>
</div>
<div class="section-divider">BENEFICIOS EXPLICADOS</div>
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:12px">
<?php foreach(['uber'=>'Uber / America Logistics (844-923-0744)','gym'=>'Gym / SilverSneakers','otc'=>'Beneficio OTC','dental_ben'=>'Beneficio Dental','vision_ben'=>'Beneficio Vision','medicamentos'=>'Medicamentos cubiertos','no_dar_info'=>'NO dar informacion a extraños','brokers'=>'Cuidado con brokers - 323-402-4145'] as $_bk=>$_bl): ?>
<label style="display:flex;align-items:flex-start;gap:6px;font-size:9px;font-weight:700;cursor:pointer;line-height:1.5"><input type="checkbox" name="beneficios[]" value="<?=$_bk?>" style="margin-top:2px;flex-shrink:0"> <?=$_bl?></label>
<?php endforeach; ?>
</div>
<div class="grid-2">
<div class="form-group"><label class="form-label">¿Explicaste que NO deben dar informacion a brokers?</label><div style="display:flex;gap:10px;margin-top:4px"><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="explicaste_no_dar_info" value="1"> SÍ</label><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="explicaste_no_dar_info" value="0"> NO</label><label style="display:flex;align-items:center;gap:4px;font-size:9px;font-weight:800;cursor:pointer"><input type="radio" name="explicaste_no_dar_info" value="" checked> N/D</label></div></div>
<div class="form-group"><label class="form-label">¿AMIGO / FAMILIAR AL QUE PODAMOS AYUDAR?</label><input type="text" name="referido_nuevo" class="form-input" placeholder="Nombre y telefono del referido"></div>
</div>
<div class="section-divider">RESULTADO DE LA LLAMADA</div>
<div class="grid-2">
<div class="form-group"><label class="form-label">RESULTADO *</label><select name="resultado_q30" class="form-input" required><option value="">Seleccionar</option><option value="COMPLETADA">COMPLETADA</option><option value="NO CONTESTÓ">NO CONTESTO</option><option value="BUZÓN">BUZON</option></select></div>
<div class="form-group"><label class="form-label">NOTAS ADICIONALES</label><textarea name="notas_generales" class="form-input" rows="2" style="text-transform:none" placeholder="Observaciones importantes..."></textarea></div>
</div>
</div>
<div style="display:flex;justify-content:flex-end;gap:7px;padding:12px 18px;border-top:1px solid #C8DFF0"><button type="button" class="btn btn-gh btn-sm" onclick="closeModal('ret-q30-modal')">CANCELAR</button><button type="submit" class="btn btn-b btn-sm">GUARDAR CUESTIONARIO</button></div>
</form>
</div>
</div>

<script>
const _RET_NOMBRES = {<?php foreach($_ret_list as $_rm): ?><?=(int)$_rm['id']?>:"<?=addslashes($_rm['nombre'].' '.$_rm['apellido'])?>",<?php endforeach; ?>};
let _retF='TODOS';
function retFilter(f){
  if(f!==undefined){
    _retF=f;
    document.querySelectorAll('.ret-fb').forEach(function(b){b.style.background='';b.style.color='';});
    var ab=document.querySelector('.ret-fb[data-rf="'+f+'"]');
    if(ab){ab.style.background='#1B4A6B';ab.style.color='#fff';}
  }
  var q=(document.getElementById('ret-search')?document.getElementById('ret-search').value:'').toLowerCase();
  document.querySelectorAll('.ret-row').forEach(function(row){
    var show=true;
    if(q&&!row.dataset.search.includes(q)) show=false;
    if(_retF==='URGENTES')   show=show&&row.dataset.ur==='1';
    if(_retF==='BIENVENIDA') show=show&&row.dataset.pendB==='1';
    if(_retF==='30 DÍAS') show=show&&row.dataset.pend30==='1';
    if(_retF==='60 DÍAS') show=show&&row.dataset.pend60==='1';
    if(_retF==='90 DÍAS') show=show&&row.dataset.pend90==='1';
    if(_retF==='✓ CON CUESTIONARIO') show=show&&row.dataset.q30==='1';
    if(_retF==='○ SIN CUESTIONARIO') show=show&&row.dataset.q30==='0';
    row.style.display=show?'':'none';
  });
}
function openRetSimple(mid,tipo){
  document.getElementById('rsm-mid').value=mid;
  document.getElementById('rsm-tipo').value=tipo;
  document.getElementById('rsm-notas').value='';
  var labels={BIENVENIDA:'BIENVENIDA',60:'60 DIAS',90:'90 DIAS'};
  document.getElementById('rsm-title').textContent='Llamada '+( labels[tipo]||tipo);
  openModal('ret-simple-modal');
}
function saveRetSimple(resultado){
  var mid=document.getElementById('rsm-mid').value;
  var tipo=document.getElementById('rsm-tipo').value;
  var notas=document.getElementById('rsm-notas').value;
  var fd=new FormData();
  fd.append('action','save_retencion_llamada');fd.append('miembro_id',mid);fd.append('tipo',tipo);fd.append('resultado',resultado);fd.append('notas',notas);
  fetch('api.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){if(d.ok){if(typeof toast==='function')toast('Llamada registrada');closeModal('ret-simple-modal');softReload();}else if(typeof toast==='function')toast('Error: '+(d.error||'No se pudo guardar'));});
}
function openRetQ30(mid){
  document.getElementById('rq30-mid').value=mid;
  document.getElementById('rq30-nombre').textContent=_RET_NOMBRES[mid]||'';
  document.getElementById('ret-q30-form').reset();
  document.getElementById('rq30-mid').value=mid;
  openModal('ret-q30-modal');
}
function submitRetQ30(e){
  e.preventDefault();
  var btn=e.target.querySelector('[type=submit]');
  if(btn){btn.disabled=true;btn.textContent='GUARDANDO...';}
  var fd=new FormData(e.target);
  fd.append('action','save_retencion_q30');
  fetch('api.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){if(d.ok){if(typeof toast==='function')toast('Cuestionario guardado');closeModal('ret-q30-modal');softReload();}else{if(typeof toast==='function')toast('Error: '+(d.error||''));if(btn){btn.disabled=false;btn.textContent='GUARDAR CUESTIONARIO';}}}).catch(function(){if(btn){btn.disabled=false;btn.textContent='GUARDAR CUESTIONARIO';}});
}
</script>


<div id="tab-PIPELINE" class="tab-pane">
<?php
$hoy = date('Y-m-d');
$todos_los_pasos = [];
$pasos_por_miembro = [];
$lista_prioridad_hoy = [];
// Mapa indexado para búsqueda instantánea por ID
$members_map = array_column($members, null, 'id');

try {
    $pasos_query = $pdo->query("SELECT pp.*, u.nombre as agente_nombre FROM pipeline_pasos pp LEFT JOIN usuarios u ON pp.agente_id=u.id WHERE pp.completado = 0 ORDER BY pp.fecha_programada ASC");
    $todos_los_pasos = $pasos_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Crear un mapa rápido para saber de qué agente es cada prospecto
    $dueño_miembro = [];
    foreach($members as $m) {
        $dueño_miembro[$m['id']] = $m['agente_id'];
    }

    foreach($todos_los_pasos as $p){
        // NUEVO: Si no eres admin, filtramos para que solo veas las actividades de TUS prospectos
        $owner_id = $dueño_miembro[$p['miembro_id']] ?? null;
        if (!$admin && $owner_id != $uid) {
            continue;
        }

        if(!isset($pasos_por_miembro[$p['miembro_id']])) {
            $pasos_por_miembro[$p['miembro_id']] = $p;
        }
        if($p['fecha_programada'] <= $hoy) {
            $lista_prioridad_hoy[] = $p;
        }
    }
} catch (Exception $e) { }

// Clasificación de miembros
$pipe_pros=[]; $pipe_cita=[]; $pipe_app=[]; $pipe_sold=[];
$states_app  = ['SIN HACER','SIN FIRMAR','IN PROCESS','PLAN CHANGE','READY TO ENROLL'];
$states_lost = ['CANCELED','DENIED','CERRADO','DISENROLLED'];

foreach($members as $m){
    if(!$admin && (int)$m['agente_id']!==$uid) continue;
    $est = $m['estado'] ?? '';
    if($est==='ACTIVE') $pipe_sold[] = $m;
    elseif(in_array($est,$states_app)) $pipe_app[] = $m;
    elseif(!empty($citas_por_miembro[$m['id']])) $pipe_cita[] = $m;
    elseif(in_array($est,$states_lost)) continue;
    else $pipe_pros[] = $m;
}

// Ordenar prospectos: hot primero, luego warm, cold, aep, t65, sin clasificar
$temp_order = ['hot'=>0,'warm'=>1,'cold'=>2,'aep'=>3,'t65'=>4,null=>5,''=>5];
usort($pipe_pros, function($a, $b) use ($temp_order, $pasos_por_miembro, $hoy) {
    $ta = strtolower($a['fuente'] ?? '');
    $tb = strtolower($b['fuente'] ?? '');
    $temps = ['hot','warm','cold','aep','t65'];
    $va = in_array($ta,$temps) ? $temp_order[$ta] : 5;
    $vb = in_array($tb,$temps) ? $temp_order[$tb] : 5;
    if ($va !== $vb) return $va - $vb;
    // Secundario: más días vencido = más urgente (primero)
    $pa = $pasos_por_miembro[$a['id']] ?? null;
    $pb = $pasos_por_miembro[$b['id']] ?? null;
    $da = $pa ? (int)floor((strtotime($hoy)-strtotime($pa['fecha_programada']))/86400) : -999;
    $db = $pb ? (int)floor((strtotime($hoy)-strtotime($pb['fecha_programada']))/86400) : -999;
    return $db - $da;
});

// Ordenar actividades de hoy por urgencia también
usort($lista_prioridad_hoy, function($a, $b) use ($hoy) {
    $da = (int)floor((strtotime($hoy)-strtotime($a['fecha_programada']))/86400);
    $db = (int)floor((strtotime($hoy)-strtotime($b['fecha_programada']))/86400);
    return $db - $da;
});

$total_f = count($pipe_pros)+count($pipe_cita)+count($pipe_app)+count($pipe_sold);
$conv = $total_f>0 ? round(count($pipe_sold)*100/$total_f) : 0;

// Sub-conteos de temperatura en prospectos
$hot_count  = count(array_filter($pipe_pros, fn($m)=>strtolower($m['fuente']??'')==='hot'));
$warm_count = count(array_filter($pipe_pros, fn($m)=>strtolower($m['fuente']??'')==='warm'));
$cold_count = count(array_filter($pipe_pros, fn($m)=>strtolower($m['fuente']??'')==='cold'));
$aep_count  = count(array_filter($pipe_pros, fn($m)=>strtolower($m['fuente']??'')==='aep'));
$t65_pipe_count = count(array_filter($pipe_pros, fn($m)=>strtolower($m['fuente']??'')==='t65'));
?>

<!-- TOOLBAR -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
        <button onclick="switchPipeView('principal')" id="btn-pipe-main"
            style="background:#1B4A6B; color:#fff; border:2px solid #1B4A6B; border-radius:10px; font-weight:900; font-size:9px; cursor:pointer; padding:8px 16px; letter-spacing:1.5px; text-transform:uppercase;">
            🏠 PIPELINE
        </button>
        <button onclick="switchPipeView('actividades')" id="btn-pipe-today"
            style="background:#fff; border:1.5px solid #C8DFF0; border-radius:10px; font-weight:900; font-size:9px; cursor:pointer; color:#7A90A4; padding:8px 16px; letter-spacing:1.5px; text-transform:uppercase;">
            ⚡ ACTIVIDADES HOY <span style="background:#EBF4F9; color:#1B4A6B; border-radius:20px; padding:1px 6px; font-size:8px; margin-left:4px;"><?=count($lista_prioridad_hoy)?></span>
        </button>
    </div>
    <div style="display:flex; gap:7px; align-items:center; flex-wrap:wrap;">
        <input type="text" id="pipe-search" placeholder="🔍 Buscar prospecto..."
            oninput="filterPipeProspects()"
            style="border:1.5px solid #C8DFF0; border-radius:9px; padding:7px 11px; font-size:9px; background:#fff; font-family:'DM Sans',sans-serif; font-weight:700; width:180px; outline:none; color:#1B3A5C;">
        <?php if($admin):?>
        <select id="pipe-agente-filter" onchange="filterPipeProspects()" style="border:1.5px solid #C8DFF0; border-radius:9px; padding:7px 10px; font-size:9px; background:#fff; font-family:'DM Sans',sans-serif; font-weight:800; text-transform:uppercase;">
            <option value="">TODOS LOS AGENTES</option>
            <?php foreach($users_all as $u):?>
            <option value="<?=$u['id']?>"><?=h(explode(' ',$u['nombre'])[0])?></option>
            <?php endforeach;?>
        </select>
        <?php endif;?>
        <button onclick="openPipeConfigModal()"
            style="background:#fff; color:#1B4A6B; border:1.5px solid #1B4A6B; border-radius:10px; padding:8px 14px; font-size:9px; font-weight:900; cursor:pointer; letter-spacing:1.5px; text-transform:uppercase; display:flex; align-items:center; gap:5px;">
            ⚙️ CONFIGURAR PASOS
        </button>
    </div>
</div>

<!-- STATS BAR -->
<div id="pipe-stats" style="display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:18px">
    <?php
    $pipe_stat_ids = ['pros','cita','app','sold','conv'];
    $i_stat = 0;
    foreach([
        ['🔥', count($pipe_pros), 'PROSPECTOS', '#7A90A4'],
        ['📅', count($pipe_cita), 'CON CITA',   '#1B5E8C'],
        ['📝', count($pipe_app),  'EN APP',      '#C07A1A'],
        ['✓',  count($pipe_sold), 'VENDIDOS',   '#1E7A5C'],
        ['%',  $conv.'%',         'CONVERSIÓN', '#5B3FAF'],
    ] as [$ic,$v,$lbl,$col]):?>
    <div style="background:#fff; border:1px solid #D1E1F0; border-radius:12px; padding:12px; text-align:center; border-top:4px solid <?=$col?>; box-shadow:0 2px 4px rgba(0,0,0,0.02)">
        <div style="font-size:16px;margin-bottom:4px"><?=$ic?></div>
        <div id="pipe-stat-<?=$pipe_stat_ids[$i_stat]?>" style="font-size:20px;font-weight:900;color:<?=$col?>"><?=$v?></div>
        <div style="font-size:9px;color:#7A90A4;font-weight:900;text-transform:uppercase;margin-top:2px"><?=$lbl?></div>
    </div>
    <?php $i_stat++; endforeach;?>
</div>

<!-- ALERTAS T65 — RECORDATORIOS DE LLAMADA (gente por cumplir 65 / sacar Medicare) -->
<?php
$t65_pipe = array_values(array_filter($t65_alertas, fn($ta)=> $admin || (int)($ta['agente_id']??0)===$uid));
if(count($t65_pipe)>0):
?>
<div class="card" style="border-top:4px solid #C05C1A; background:#FFFBF5; margin-bottom:16px">
  <div class="card-header" style="cursor:pointer" onclick="var b=document.getElementById('pipe-t65-body');b.style.display=b.style.display==='none'?'block':'none';this.querySelector('.t65-chev').textContent=b.style.display==='none'?'▸':'▾'">
    <div>
      <div class="card-title" style="color:#C05C1A">🎂 T65 — RECORDATORIOS DE LLAMADA</div>
      <div class="card-sub"><?=count($t65_pipe)?> PERSONA<?=count($t65_pipe)>1?'S':''?> POR CUMPLIR 65 / SACAR MEDICARE — PRÓXIMOS 90 DÍAS</div>
    </div>
    <span class="t65-chev" style="font-size:13px;color:#C05C1A;font-weight:900">▾</span>
  </div>
  <div id="pipe-t65-body" style="padding:8px 12px;max-height:300px;overflow-y:auto">
  <?php
  $t65_grupos_pipe=[
    ['label'=>'⚠ URGENTE — 0 A 30 DÍAS','color'=>'#B83232','bg'=>'#FDF0EE','border'=>'#EFA09A','min'=>0,'max'=>30],
    ['label'=>'📅 PRÓXIMO — 31 A 60 DÍAS','color'=>'#C07A1A','bg'=>'#FDF6EC','border'=>'#F5D5A0','min'=>31,'max'=>60],
    ['label'=>'🔭 EN HORIZONTE — 61 A 90 DÍAS','color'=>'#2876A8','bg'=>'#EBF4F9','border'=>'#C8DFF0','min'=>61,'max'=>90],
  ];
  foreach($t65_grupos_pipe as $grp):
    $grp_items=array_filter($t65_pipe,fn($ta)=>$ta['dias_restantes']>=$grp['min']&&$ta['dias_restantes']<=$grp['max']);
    if(empty($grp_items)) continue;
  ?>
  <div style="font-size:7px;font-weight:900;color:<?=$grp['color']?>;text-transform:uppercase;letter-spacing:1.2px;padding:3px 8px;background:<?=$grp['bg']?>;border-left:3px solid <?=$grp['border']?>;border-radius:4px;margin-bottom:5px;margin-top:10px"><?=$grp['label']?></div>
  <?php foreach($grp_items as $ta):?>
  <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;border-bottom:1px solid #F5D5A0;gap:8px">
    <div style="flex:1;min-width:0;cursor:pointer" onclick="openProfile(<?=$ta['id']?>)">
      <div style="font-size:10px;font-weight:900;color:<?=$TX?>;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($ta['nombre'].' '.$ta['apellido'])?></div>
      <div style="font-size:8px;color:<?=$MU?>;margin-top:1px"><?=!empty($ta['telefono'])?'📞 '.h($ta['telefono']):''?><?=!empty($ta['carrier'])?' · '.h($ta['carrier']):''?><?=!empty($ta['estado'])?' · '.h($ta['estado']):''?></div>
    </div>
    <div style="display:flex;align-items:center;gap:5px;flex-shrink:0">
      <span style="background:<?=$grp['bg']?>;color:<?=$grp['color']?>;border:1px solid <?=$grp['border']?>;border-radius:20px;padding:2px 8px;font-size:8px;font-weight:900;white-space:nowrap"><?=($ta['dias_restantes']<=0?'¡HOY!':$ta['dias_restantes'].' DÍAS')?></span>
      <span style="font-size:8px;color:<?=$MU?>;white-space:nowrap"><?=date('m/d/Y',strtotime($ta['fecha_65']))?></span>
      <?php if(!empty($ta['telefono'])):?><a href="tel:<?=h($ta['telefono'])?>" style="font-size:9px;background:<?=$G?>;color:#fff;border-radius:6px;padding:2px 7px;text-decoration:none;font-weight:900" title="LLAMAR" onclick="event.stopPropagation()">📞</a><?php endif;?>
    </div>
  </div>
  <?php endforeach; endforeach;?>
  </div>
</div>
<?php endif;?>

<!-- TEMPERATURA FILTER PILLS (only for prospectos column) -->
<div id="pipe-temp-pills" style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; align-items:center;">
    <span style="font-size:8px; font-weight:900; color:#7A90A4; text-transform:uppercase; letter-spacing:1px;">TEMPERATURA:</span>
    <button class="pipe-temp-pill pipe-temp-pill-on" data-temp="" onclick="setPipeTemp(this,'')" style="padding:4px 12px; border-radius:20px; border:1.5px solid #C8DFF0; background:#EBF4F9; color:#1B4A6B; font-size:8px; font-weight:900; cursor:pointer; font-family:'DM Sans',sans-serif; text-transform:uppercase; letter-spacing:.5px;">
        TODOS <span style="opacity:.6;">(<?=count($pipe_pros)?>)</span>
    </button>
    <?php if($hot_count>0):?>
    <button class="pipe-temp-pill" data-temp="hot" onclick="setPipeTemp(this,'hot')" style="padding:4px 12px; border-radius:20px; border:1.5px solid #F5B8A8; background:#fff; color:#C03A1A; font-size:8px; font-weight:900; cursor:pointer; font-family:'DM Sans',sans-serif; text-transform:uppercase; letter-spacing:.5px;">
        🔥 HOT <span style="opacity:.6;">(<?=$hot_count?>)</span>
    </button>
    <?php endif;?>
    <?php if($warm_count>0):?>
    <button class="pipe-temp-pill" data-temp="warm" onclick="setPipeTemp(this,'warm')" style="padding:4px 12px; border-radius:20px; border:1.5px solid #F5D5A0; background:#fff; color:#C07A1A; font-size:8px; font-weight:900; cursor:pointer; font-family:'DM Sans',sans-serif; text-transform:uppercase; letter-spacing:.5px;">
        🌡 WARM <span style="opacity:.6;">(<?=$warm_count?>)</span>
    </button>
    <?php endif;?>
    <?php if($cold_count>0):?>
    <button class="pipe-temp-pill" data-temp="cold" onclick="setPipeTemp(this,'cold')" style="padding:4px 12px; border-radius:20px; border:1.5px solid #A9D0E8; background:#fff; color:#1B5E8C; font-size:8px; font-weight:900; cursor:pointer; font-family:'DM Sans',sans-serif; text-transform:uppercase; letter-spacing:.5px;">
        ❄ COLD <span style="opacity:.6;">(<?=$cold_count?>)</span>
    </button>
    <?php endif;?>
    <?php if($aep_count>0):?>
    <button class="pipe-temp-pill" data-temp="aep" onclick="setPipeTemp(this,'aep')" style="padding:4px 12px; border-radius:20px; border:1.5px solid #C2B0E8; background:#fff; color:#5B3FAF; font-size:8px; font-weight:900; cursor:pointer; font-family:'DM Sans',sans-serif; text-transform:uppercase; letter-spacing:.5px;">
        📋 AEP <span style="opacity:.6;">(<?=$aep_count?>)</span>
    </button>
    <?php endif;?>
    <?php if($t65_pipe_count>0):?>
    <button class="pipe-temp-pill" data-temp="t65" onclick="setPipeTemp(this,'t65')" style="padding:4px 12px; border-radius:20px; border:1.5px solid #8DCFBA; background:#fff; color:#1E7A5C; font-size:8px; font-weight:900; cursor:pointer; font-family:'DM Sans',sans-serif; text-transform:uppercase; letter-spacing:.5px;">
        🎂 T65 <span style="opacity:.6;">(<?=$t65_pipe_count?>)</span>
    </button>
    <?php endif;?>
</div>

<!-- PRINCIPAL VIEW -->
<div id="pipe-view-principal">
    <div class="pipeline-grid" style="display:grid; grid-template-columns:repeat(4,1fr); gap:12px;">
        <?php
        $cols = [
            ['PROSPECTO',     '#7A90A4', '👥', $pipe_pros, 'pros'],
            ['CON CITA',      '#1B5E8C', '📅', $pipe_cita, 'cita'],
            ['EN APLICACIÓN', '#C07A1A', '📝', $pipe_app,  'app'],
            ['VENDIDO',       '#1E7A5C', '✓',  $pipe_sold, 'sold'],
        ];
        foreach($cols as [$titulo,$col,$ic,$items,$colkey]):?>
        <div class="pipe-col" data-col="<?=$colkey?>">
            <div class="pipe-col-header" style="background:<?=$col?>15; border:1px solid <?=$col?>44; color:<?=$col?>;">
                <span><?=$ic?> <?=$titulo?></span>
                <span style="background:<?=$col?>25; border-radius:20px; padding:2px 8px; font-size:9px;"><?=count($items)?></span>
            </div>

            <?php foreach($items as $m):
                $p_act = $pasos_por_miembro[$m['id']] ?? null;
                $apellido = $m['apellido'] ?? '';
                $nombre   = $m['nombre']   ?? '';
                $telefono = $m['telefono'] ?? '';
                $nombre_completo = trim($apellido.', '.$nombre) ?: 'Sin Nombre';
                $fuente = strtolower(trim($m['fuente'] ?? ''));
                $agente_id_m = $m['agente_id'] ?? '';

                // Temperatura badge
                $temp_cfg = [
                    'hot'  => ['🔥 HOT',  'pipe-temp-hot'],
                    'warm' => ['🌡 WARM', 'pipe-temp-warm'],
                    'cold' => ['❄ COLD',  'pipe-temp-cold'],
                    'aep'  => ['📋 AEP',  'pipe-temp-aep'],
                    't65'  => ['🎂 T65',  'pipe-temp-t65'],
                ];
                $temp_badge = '';
                if(isset($temp_cfg[$fuente])){
                    [$tlabel,$tcls] = $temp_cfg[$fuente];
                    $temp_badge = "<span class='pipe-temp-badge $tcls'>$tlabel</span>";
                }

                // Paso vencido?
                $paso_vencido = $p_act && $p_act['fecha_programada'] < $hoy;
            ?>
            <div class="pipe-card" data-temp="<?=h($fuente)?>" data-agente="<?=h($agente_id_m)?>" data-nombre="<?=strtolower(h($nombre_completo))?>"
                 style="border-top:3px solid <?=$col?>; background:#fff; <?=$paso_vencido?'border-left:3px solid #B83232;':''?>">

                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px;">
                    <div onclick="openProfile(<?=$m['id']?>)" style="cursor:pointer; flex:1; min-width:0;">
                        <div style="font-weight:900; font-size:10px; color:#1B4A6B; line-height:1.3; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            <?=htmlspecialchars($nombre_completo, ENT_QUOTES, 'UTF-8')?>
                        </div>
                        <div style="font-size:8px; color:#7A90A4; margin-top:1px;">
                            <?=htmlspecialchars($telefono, ENT_QUOTES, 'UTF-8')?>
                        </div>
                    </div>
                    <?php if($temp_badge): echo $temp_badge; endif; ?>
                </div>

                <?php if($p_act):
                    $desc_paso = $p_act['descripcion'] ?? '';
                    $fecha_paso = $p_act['fecha_programada'] ?? '';
                    $fecha_disp = $fecha_paso ? date('m/d', strtotime($fecha_paso)) : '—';
                    $bg_paso = $paso_vencido ? '#FDF0EE' : '#FFF9F0';
                    $border_paso = $paso_vencido ? '#EFA09A' : '#FFE4BC';
                    $color_lbl = $paso_vencido ? '#B83232' : '#C07A1A';
                    $lbl_paso = $paso_vencido ? '⚠ VENCIDO' : 'SIGUIENTE PASO';
                ?>
                <div style="background:<?=$bg_paso?>; border:1px solid <?=$border_paso?>; border-radius:7px; padding:7px 8px; margin-bottom:8px;">
                    <div style="font-size:7px; font-weight:900; color:<?=$color_lbl?>; margin-bottom:3px;"><?=$lbl_paso?></div>
                    <div style="font-size:9px; color:#333; line-height:1.3; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                        <?=htmlspecialchars($desc_paso, ENT_QUOTES, 'UTF-8')?>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:5px;">
                        <span style="font-size:7px; color:<?=$color_lbl?>; font-weight:800;">📅 <?=$fecha_disp?></span>
                        <button onclick="completarPasoPipeline(<?=$p_act['id']?>, this)"
                            style="background:#1E7A5C; color:white; border:none; border-radius:5px; font-size:7px; padding:3px 7px; cursor:pointer; font-weight:900; font-family:'DM Sans',sans-serif;">
                            ✓ LISTO
                        </button>
                    </div>
                </div>
                <?php else: ?>
                <button onclick="aplicarPasosConfig(<?=$m['id']?>)"
                    style="width:100%; background:#F4F8FC; border:1.5px dashed #C8DFF0; color:#7A90A4; font-size:8px; padding:6px; border-radius:7px; cursor:pointer; font-weight:900; margin-bottom:8px; font-family:'DM Sans',sans-serif; text-transform:uppercase; letter-spacing:1px;">
                    + APLICAR SECUENCIA
                </button>
                <?php endif; ?>

                <!-- Nota rápida -->
                <div style="display:flex; gap:4px;">
                    <input type="text" id="p-note-<?=$m['id']?>" placeholder="Nota rápida..."
                        style="flex:1; font-size:9px; padding:5px 7px; border:1.5px solid #E2E8F0; border-radius:7px; font-family:'DM Sans',sans-serif; outline:none; color:#1B3A5C;"
                        onkeydown="if(event.key==='Enter') savePipelineNote(<?=$m['id']?>, this.nextElementSibling)">
                    <button onclick="savePipelineNote(<?=$m['id']?>, this)"
                        style="background:#1B4A6B; color:white; border:none; border-radius:7px; padding:0 9px; cursor:pointer; font-weight:900; font-size:12px; flex-shrink:0;">
                        +
                    </button>
                </div>

                <!-- Cambiar temperatura (solo prospectos) -->
                <?php if($colkey === 'pros'): ?>
                <div style="display:flex; gap:3px; margin-top:6px; flex-wrap:wrap;">
                    <?php foreach([''=>['—','#94A3B8'],'hot'=>['🔥','#C03A1A'],'warm'=>['🌡','#C07A1A'],'cold'=>['❄','#1B5E8C'],'aep'=>['📋','#5B3FAF'],'t65'=>['🎂','#1E7A5C']] as $tk=>[$ti,$tc]):?>
                    <button onclick="setProsTemp(<?=$m['id']?>,'<?=$tk?>')"
                        style="background:<?=$fuente===$tk?'#EBF4F9':'#F4F8FC'?>; border:1px solid <?=$fuente===$tk?'#1B4A6B':'#E2E8F0'?>; color:<?=$fuente===$tk?'#1B4A6B':$tc?>; border-radius:5px; padding:2px 5px; font-size:9px; cursor:pointer; font-weight:<?=$fuente===$tk?'900':'700'?>; font-family:'DM Sans',sans-serif; flex-shrink:0;"
                        title="<?=$tk===''?'Sin temperatura':strtoupper($tk)?>"><?=$ti?></button>
                    <?php endforeach;?>
                </div>
                <?php endif; ?>

                <!-- Es venta → mandar a bonos (VENDIDO / ACTIVE) -->
                <?php if($colkey === 'sold'):
                    $tiene_bono = !empty($bonos_miembro_ids[(int)$m['id']]);
                ?>
                <div style="margin-top:7px;">
                    <?php if($tiene_bono): ?>
                    <div style="width:100%;background:#EAF5F0;border:1px solid #8DCFBA;color:#1E7A5C;font-size:8px;padding:6px;border-radius:7px;font-weight:900;text-align:center;text-transform:uppercase;letter-spacing:.5px;">
                        ✓ ENVIADO A BONOS
                    </div>
                    <?php else: ?>
                    <button onclick="verificarVentaBono(<?=$m['id']?>, '<?=h(addslashes($nombre_completo))?>', this)"
                        style="width:100%;background:#5B3FAF;border:none;color:#fff;font-size:8px;padding:7px;border-radius:7px;cursor:pointer;font-weight:900;font-family:'DM Sans',sans-serif;text-transform:uppercase;letter-spacing:.5px;">
                        💰 ES VENTA → MANDAR A BONOS
                    </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ACTIVIDADES VIEW -->
<div id="pipe-view-actividades" style="display:none;">
<?php if(empty($lista_prioridad_hoy)): ?>
    <div style="text-align:center;padding:48px 20px;background:#fff;border:1px solid #C8DFF0;border-radius:14px">
        <div style="font-size:28px;margin-bottom:8px">✅</div>
        <div style="font-size:11px;font-weight:900;color:#1B4A6B;letter-spacing:2px;text-transform:uppercase">¡Todo al día!</div>
        <div style="font-size:9px;color:#7A90A4;margin-top:4px">No hay pasos pendientes para hoy.</div>
    </div>
<?php else:
    $hoy_lista   = array_filter($lista_prioridad_hoy, fn($p) => (int)floor((strtotime($hoy)-strtotime($p['fecha_programada']))/86400) === 0);
    $venc_lista  = array_filter($lista_prioridad_hoy, fn($p) => (int)floor((strtotime($hoy)-strtotime($p['fecha_programada']))/86400) >  0);
?>

<!-- Resumen compacto con filtro -->
<div style="display:flex;gap:10px;margin-bottom:16px">
    <?php if(count($venc_lista)>0):?>
    <div onclick="filterActividades('vencidas')" id="act-btn-venc"
         style="background:#FDF0EE;border:2px solid #EFA09A;border-radius:10px;padding:10px 16px;
                display:flex;align-items:center;gap:8px;cursor:pointer;transition:all .15s;user-select:none"
         onmouseover="this.style.transform='scale(1.03)'" onmouseout="this.style.transform=''">
        <span style="font-size:16px">⚠</span>
        <div>
            <div style="font-size:18px;font-weight:900;color:#B83232"><?=count($venc_lista)?></div>
            <div style="font-size:7px;font-weight:900;color:#B83232;text-transform:uppercase">Vencidos</div>
        </div>
    </div>
    <?php endif;?>
    <?php if(count($hoy_lista)>0):?>
    <div onclick="filterActividades('hoy')" id="act-btn-hoy"
         style="background:#EBF5FB;border:2px solid #A9D0E8;border-radius:10px;padding:10px 16px;
                display:flex;align-items:center;gap:8px;cursor:pointer;transition:all .15s;user-select:none"
         onmouseover="this.style.transform='scale(1.03)'" onmouseout="this.style.transform=''">
        <span style="font-size:16px">📅</span>
        <div>
            <div style="font-size:18px;font-weight:900;color:#1B5E8C"><?=count($hoy_lista)?></div>
            <div style="font-size:7px;font-weight:900;color:#1B5E8C;text-transform:uppercase">Para hoy</div>
        </div>
    </div>
    <?php endif;?>
    <div onclick="filterActividades('todas')" id="act-btn-todas"
         style="background:#fff;border:2px solid #C8DFF0;border-radius:10px;padding:10px 16px;
                display:flex;align-items:center;gap:8px;cursor:pointer;transition:all .15s;user-select:none"
         onmouseover="this.style.transform='scale(1.03)'" onmouseout="this.style.transform=''">
        <span style="font-size:16px">◉</span>
        <div>
            <div style="font-size:18px;font-weight:900;color:#7A90A4"><?=count($lista_prioridad_hoy)?></div>
            <div style="font-size:7px;font-weight:900;color:#7A90A4;text-transform:uppercase">Todas</div>
        </div>
    </div>
</div>

<!-- Lista unificada limpia -->
<div style="background:#fff;border:1px solid #C8DFF0;border-radius:13px;overflow:hidden">
<?php
$todos_act = array_merge(array_values($venc_lista), array_values($hoy_lista));
foreach($todos_act as $i => $paso):
    $dias_venc  = (int)floor((strtotime($hoy)-strtotime($paso['fecha_programada']))/86400);
    $vencido    = $dias_venc > 0;
    $mem_act    = $members_map[$paso['miembro_id']] ?? null;
    $nn         = $mem_act ? trim(($mem_act['apellido']??'').', '.($mem_act['nombre']??'')) : 'Desconocido';
    $tel        = $mem_act['telefono'] ?? '';
?>
<div class="pipe-actividad-card" data-agente="<?=h($mem_act['agente_id']??'')?>" data-vencida="<?=$vencido?1:0?>"
     style="display:flex;align-items:center;gap:12px;padding:11px 14px;flex-wrap:nowrap;
            <?=$i>0?'border-top:1px solid #EBF4F9':''?>;
            background:<?=$vencido?'#FFFAF9':'#fff'?>">

    <!-- Indicador lateral -->
    <div style="width:4px;height:36px;border-radius:99px;background:<?=$vencido?'#EFA09A':'#A9D0E8'?>;flex-shrink:0"></div>

    <!-- Info principal -->
    <div style="flex:1;min-width:0">
        <div style="font-size:9px;font-weight:900;color:<?=$vencido?'#B83232':'#1B5E8C'?>;text-transform:uppercase;margin-bottom:2px">
            <?=$vencido ? "⚠ Hace $dias_venc día".($dias_venc>1?'s':'') : '📅 Hoy'?>
        </div>
        <div style="font-size:10px;font-weight:900;color:#1B3A5C;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            <?=h($paso['descripcion']??'')?>
        </div>
        <div style="font-size:8px;color:#7A90A4;margin-top:1px;cursor:pointer" onclick="openProfile(<?=$mem_act['id']??0?>)">
            <?=h($nn)?><?=$tel?" · $tel":''?>
        </div>
    </div>

    <!-- Acción -->
    <button onclick="completarPasoPipeline(<?=$paso['id']?>, this)"
        style="background:#1E7A5C;color:#fff;border:none;border-radius:8px;padding:6px 13px;
               font-size:8px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif;
               text-transform:uppercase;white-space:nowrap;flex-shrink:0;margin-left:auto">
        ✓ Listo
    </button>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<!-- MODAL DE LLAMADA -------------->
<style>
  .lr-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px; }
  .lr-grid-btn { background: #fff; border: 2px solid #C8DFF0; border-radius: 12px; padding: 16px 8px; text-align: center; cursor: pointer; transition: all 0.18s; user-select: none; }
  .lr-grid-btn:hover { border-color: #2876A8; background: #F0F7FC; }
  .lr-grid-btn.active { border-color: #1B4A6B; background: #EBF4F9; box-shadow: 0 0 0 3px rgba(27,74,107,.12); }
  .lr-icon { font-size: 26px; margin-bottom: 6px; display: block; }
  .lr-text { font-size: 10px; font-weight: 900; color: #1B3A5C; line-height: 1.2; text-transform: uppercase; }
  .mpick-wrap { position: relative; }
  .mpick-drop { position: absolute; top: calc(100% + 2px); left: 0; right: 0; background: #fff; border: 1.5px solid #2876A8; border-radius: 10px; z-index: 9999; display: none; max-height: 220px; overflow-y: auto; box-shadow: 0 10px 30px rgba(27,74,107,.18); }
  .mpick-item { padding: 10px 14px; cursor: pointer; font-size: 10px; font-weight: 700; color: #1B4A6B; border-bottom: 1px solid #EBF4F9; line-height: 1.4; }
  .mpick-item:last-child { border-bottom: none; }
  .mpick-item:hover { background: #EBF4F9; }
  .mpick-clear { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 14px; color: #7A90A4; padding: 2px 5px; }
  .lr-mode-seg { display: flex; gap: 0; margin-bottom: 16px; border: 1.5px solid #C8DFF0; border-radius: 10px; overflow: hidden; }
  .lr-mode-seg button { flex: 1; background: transparent; border: none; padding: 10px 6px; font-size: 9px; font-weight: 900; cursor: pointer; font-family: 'DM Sans', sans-serif; text-transform: uppercase; color: #7A90A4; border-right: 1px solid #C8DFF0; transition: all .15s; }
  .lr-mode-seg button:last-child { border-right: none; }
  .lr-mode-seg button.active { background: #1B4A6B; color: #fff; }
</style>

<div class="modal-overlay" id="llamada-rapida-modal">
  <div class="modal modal-sm" style="max-width:520px;">
    <div class="modal-header">
      <div class="modal-title">📞 REGISTRAR LLAMADA</div>
      <button class="modal-close" onclick="closeModal('llamada-rapida-modal')">✕</button>
    </div>
    <form onsubmit="submitLlamadaRapida(event)" id="llamada-rapida-form">
      <input type="hidden" name="tipo_llamada" id="lr-tipo-llamada" value="prospecto">

      <!-- SEGMENTED MODE SELECTOR -->
      <div class="lr-mode-seg">
        <button type="button" class="lr-mode-btn active" data-mode="prospecto" onclick="setLrMode('prospecto')">📞 PROSPECTO</button>
        <button type="button" class="lr-mode-btn" data-mode="servicio" onclick="setLrMode('servicio')">🎧 SERVICIO AL CLIENTE</button>
      </div>

      <!-- PROSPECTO SECTION -->
      <div id="lr-prospecto-section">
        <div class="form-group">
          <label class="form-label">BUSCAR MIEMBRO (OPCIONAL)</label>
          <div class="mpick-wrap">
            <input type="text" id="lr-mpick-input" class="form-input" placeholder="Escribe nombre o teléfono para buscar..." autocomplete="off" oninput="mpickSearch('lr-mpick-input','lr-miembro','lr-mpick-drop',this.value,true)">
            <input type="hidden" name="miembro_id" id="lr-miembro" value="">
            <button type="button" class="mpick-clear" onclick="mpickClear('lr-mpick-input','lr-miembro','lr-mpick-drop')" title="Limpiar">×</button>
            <div id="lr-mpick-drop" class="mpick-drop"></div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:9px">
          <div class="form-group">
            <label class="form-label">NOMBRE</label>
            <input type="text" name="nombre_libre" id="lr-nombre" class="form-input" placeholder="Ej: Juan Pérez">
          </div>
          <div class="form-group">
            <label class="form-label">TELÉFONO</label>
            <input type="text" name="telefono" id="lr-telefono" class="form-input" placeholder="(818) 000-0000">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" style="margin-bottom:10px">¿QUÉ PASÓ?</label>
          <input type="hidden" name="resultado" id="lr-resultado" value="Contestó">
          <div class="lr-grid-3">
            <div class="lr-grid-btn" data-res="No contestó" onclick="setLrResult(this)">
              <span class="lr-icon">🚫</span><span class="lr-text">No contestó</span>
            </div>
            <div class="lr-grid-btn active" data-res="Contestó" onclick="setLrResult(this)">
              <span class="lr-icon">✅</span><span class="lr-text">Contestó</span>
            </div>
            <div class="lr-grid-btn" data-res="Dejó buzón" onclick="setLrResult(this)">
              <span class="lr-icon">📬</span><span class="lr-text">Buzón</span>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">NOTAS (OPCIONAL)</label>
          <textarea name="notas" id="lr-notas" class="form-input" rows="2" placeholder="Ej: Dijo que llama después, tiene cita el martes..." style="text-transform:none;"></textarea>
        </div>
      </div>

      <!-- SERVICIO AL CLIENTE SECTION -->
      <div id="lr-servicio-section" style="display:none">
        <div style="background:#EBF5FB;border:1px solid #A9D0E8;border-radius:10px;padding:10px 13px;margin-bottom:12px;font-size:9px;color:#1B4A6B;font-weight:700">
          🎧 Esta llamada se registra como ticket tipo LLAMADA y se cuenta en <strong>LLAM.SERV.</strong> del reporte.
        </div>
        <div class="form-group">
          <label class="form-label">BUSCAR CLIENTE / MIEMBRO</label>
          <div class="mpick-wrap">
            <input type="text" id="lr-sv-mpick-input" class="form-input" placeholder="Escribe nombre o teléfono..." autocomplete="off" oninput="mpickSearch('lr-sv-mpick-input','lr-sv-miembro','lr-sv-mpick-drop',this.value,false)">
            <input type="hidden" name="miembro_id_sv" id="lr-sv-miembro" value="">
            <button type="button" class="mpick-clear" onclick="mpickClear('lr-sv-mpick-input','lr-sv-miembro','lr-sv-mpick-drop')" title="Limpiar">×</button>
            <div id="lr-sv-mpick-drop" class="mpick-drop"></div>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">NOMBRE SI NO ESTÁ REGISTRADO</label>
          <input type="text" name="nombre_libre_sv" id="lr-nombre-sv" class="form-input" placeholder="Nombre del cliente">
        </div>
        <div class="form-group">
          <label class="form-label">DESCRIPCIÓN DEL TICKET *</label>
          <textarea name="notas_sv" id="lr-notas-sv" class="form-input" rows="3" required placeholder="¿Qué necesita el cliente? ¿Cuál es el motivo de la llamada?" style="text-transform:none;"></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">ESTADO</label>
          <select name="estado_ticket" class="form-input">
            <option value="CERRADO">RESUELTO / CERRADO (solo registro)</option>
            <option value="ABIERTO">ABIERTO (requiere seguimiento)</option>
          </select>
        </div>
      </div>

      <div style="display:flex;gap:7px;justify-content:flex-end;margin-top:4px">
        <button type="button" class="btn btn-gh" onclick="closeModal('llamada-rapida-modal')">CANCELAR</button>
        <button type="submit" class="btn btn-p" id="lr-submit-btn" style="padding-left:28px;padding-right:28px;">GUARDAR ➜</button>
      </div>
    </form>
  </div>
</div>
<!-- CIERRE MODAL: REGISTRAR LLAMADAS -->

<!-- MODAL: CONFIGURAR PASOS PIPELINE -->
<div id="modal-pipe-config" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(27,74,107,0.55); backdrop-filter:blur(4px);">
    <div style="background:#fff; border-radius:16px; width:94%; max-width:560px; margin:40px auto; padding:0; box-shadow:0 20px 50px rgba(27,74,107,0.25); overflow:hidden; max-height:90vh; display:flex; flex-direction:column;">

        <!-- Header del modal -->
        <div style="background:linear-gradient(to right, #1B4A6B, #2876A8); padding:18px 22px; display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
            <div>
                <div style="font-size:11px; font-weight:900; color:#fff; letter-spacing:2px; text-transform:uppercase;">⚙️ CONFIGURAR SECUENCIA</div>
                <div style="font-size:8px; color:rgba(255,255,255,0.6); margin-top:2px; letter-spacing:1px; text-transform:uppercase;">Pasos automáticos del pipeline</div>
            </div>
            <button onclick="document.getElementById('modal-pipe-config').style.display='none'"
                style="background:rgba(255,255,255,0.15); border:1px solid rgba(255,255,255,0.25); color:#fff; border-radius:8px; width:32px; height:32px; font-size:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; font-weight:900; font-family:'DM Sans',sans-serif; flex-shrink:0;">
                ×
            </button>
        </div>

        <!-- Lista de pasos -->
        <div id="lista-pasos-config" style="overflow-y:auto; padding:16px 20px; flex:1;">
            <?php
            try {
                $config_pasos = $pdo->query("SELECT * FROM pipeline_config_pasos ORDER BY dias_intervalo ASC")->fetchAll();
            } catch(Exception $e) { $config_pasos = []; }
            
            if(empty($config_pasos)):?>
            <div style="text-align:center; padding:24px; font-size:9px; color:#7A90A4; font-weight:700; text-transform:uppercase; letter-spacing:1px;">
                No hay pasos configurados aún. Agrega el primero abajo.
            </div>
            <?php else:
            foreach($config_pasos as $cp): ?>
            <div id="row-config-<?=$cp['id']?>" style="display:flex; gap:10px; margin-bottom:10px; align-items:center; background:#F4F8FC; border:1px solid #C8DFF0; border-radius:10px; padding:10px 12px;">
                <div style="flex-shrink:0; text-align:center;">
                    <div style="font-size:7px; font-weight:900; color:#7A90A4; text-transform:uppercase; letter-spacing:1px; margin-bottom:3px;">DÍA</div>
                    <input type="number" value="<?=$cp['dias_intervalo']?>" min="0" max="365"
                        onchange="updatePipeConfig(<?=$cp['id']?>, 'dias_intervalo', this.value)"
                        style="width:55px; font-size:11px; font-weight:900; padding:6px; border-radius:7px; border:1.5px solid #C8DFF0; text-align:center; font-family:'DM Sans',sans-serif; color:#1B4A6B; background:#fff;">
                </div>
                <div style="flex:1; min-width:0;">
                    <div style="font-size:7px; font-weight:900; color:#7A90A4; text-transform:uppercase; letter-spacing:1px; margin-bottom:3px;">ACCIÓN</div>
                    <input type="text" value="<?=htmlspecialchars($cp['accion']??'', ENT_QUOTES,'UTF-8')?>"
                        onchange="updatePipeConfig(<?=$cp['id']?>, 'accion', this.value)"
                        style="width:100%; font-size:10px; padding:7px 9px; border-radius:7px; border:1.5px solid #C8DFF0; font-family:'DM Sans',sans-serif; color:#1B3A5C; background:#fff; outline:none;">
                </div>
                <button onclick="eliminarConfigPaso(<?=$cp['id']?>)"
                    style="background:#FDF0EE; border:1px solid #EFA09A; color:#B83232; padding:7px 10px; border-radius:7px; cursor:pointer; font-size:13px; flex-shrink:0; font-weight:900; font-family:'DM Sans',sans-serif;">
                    ✕
                </button>
            </div>
            <?php endforeach;
            endif;?>
        </div>

        <!-- Footer del modal -->
        <div style="padding:14px 20px; border-top:1px solid #C8DFF0; flex-shrink:0; display:flex; gap:8px;">
            <button onclick="agregarNuevaConfig()"
                style="flex:1; background:#1B4A6B; color:white; border:none; padding:11px; border-radius:10px; font-size:9px; font-weight:900; cursor:pointer; font-family:'DM Sans',sans-serif; letter-spacing:1.5px; text-transform:uppercase; display:flex; align-items:center; justify-content:center; gap:6px;">
                + AÑADIR NUEVO PASO
            </button>
            <button onclick="document.getElementById('modal-pipe-config').style.display='none'"
                style="background:#EBF4F9; color:#1B4A6B; border:1.5px solid #C8DFF0; padding:11px 18px; border-radius:10px; font-size:9px; font-weight:900; cursor:pointer; font-family:'DM Sans',sans-serif; letter-spacing:1.5px; text-transform:uppercase;">
                CERRAR
            </button>
        </div>
    </div>
</div>
</div>
<!-- /PIPELINE -->


<!-- CITAS -->
<div id="tab-CITAS" class="tab-pane">
<?php
// ─── Pre-procesado de citas ──────────────────────────────
$today_d   = date('Y-m-d');
$tomorrow_d= date('Y-m-d', strtotime('+1 day'));
$yest_d    = date('Y-m-d', strtotime('-1 day'));
$week_end  = date('Y-m-d', strtotime('+7 days'));

// Filtrar las que ve cada usuario: admin todas, agentes solo las suyas
$citas_view = $admin ? $citas : array_values(array_filter($citas, fn($c)=>$c['agente_id']==$uid));

// Separar por estado
$citas_pendientes = array_values(array_filter($citas_view, fn($c)=>$c['estado']!=='COMPLETADA' && $c['estado']!=='CANCELADA'));
$citas_completadas= array_values(array_filter($citas_view, fn($c)=>$c['estado']==='COMPLETADA'));
$citas_canceladas = array_values(array_filter($citas_view, fn($c)=>$c['estado']==='CANCELADA'));

// Pendientes: ordenar por fecha ASC, hora ASC (las más próximas primero)
usort($citas_pendientes, fn($a,$b)=>strcmp($a['fecha'].($a['hora']??''), $b['fecha'].($b['hora']??'')));
// Completadas: por fecha DESC (las más recientes primero)
usort($citas_completadas, fn($a,$b)=>strcmp($b['fecha'].($b['hora']??''), $a['fecha'].($a['hora']??'')));

// KPIs rápidos
$citas_hoy_n     = count(array_filter($citas_pendientes, fn($c)=>$c['fecha']==$today_d));
$citas_manana_n  = count(array_filter($citas_pendientes, fn($c)=>$c['fecha']==$tomorrow_d));
$citas_semana_n  = count(array_filter($citas_pendientes, fn($c)=>$c['fecha']>=$today_d && $c['fecha']<=$week_end));
$citas_atrasadas_n = count(array_filter($citas_pendientes, fn($c)=>$c['fecha']<$today_d));

// Helper para renderizar una cita
$render_cita = function($c) use ($P1,$P2,$MU,$BG,$CB,$today_d,$tomorrow_d) {
  $is_today    = $c['fecha']==$today_d;
  $is_tomorrow = $c['fecha']==$tomorrow_d;
  $is_past     = $c['fecha']<$today_d && $c['estado']!=='COMPLETADA';
  $is_done     = $c['estado']==='COMPLETADA';
  $is_canceled = $c['estado']==='CANCELADA';
  $border_color = $is_canceled ? '#999' : ($is_done ? '#1E7A5C' : ($is_past ? '#B83232' : ($is_today ? '#C07A1A' : ($is_tomorrow ? '#2876A8' : $P1))));
  $cli = trim($c['miembro_nombre']??'');
  if ($cli === ', ' || $cli === '') $cli = trim($c['cliente']??'') ?: '— SIN NOMBRE —';
  $hora_disp = !empty($c['hora']) ? substr($c['hora'],0,5) : '--:--';
  $agente_color = $c['agente_color'] ?? $P2;
  $agente_ini   = $c['agente_ini']   ?? '?';
  ?>
  <div class="cita-card" data-fecha="<?=h($c['fecha'])?>" data-agente="<?=h($c['agente_id'])?>" data-tipo="<?=h($c['tipo']??'')?>" data-modalidad="<?=h($c['modalidad']??'')?>" data-search="<?=strtolower(h(($cli.' '.($c['tipo']??'').' '.($c['modalidad']??'').' '.($c['notas']??''))))?>" style="background:#fff;border:1px solid <?=$CB?>;border-left:4px solid <?=$border_color?>;border-radius:10px;padding:11px 13px;<?=$is_done||$is_canceled?'opacity:.65':''?>">
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
          <?php if($is_today):?>HOY<?php elseif($is_tomorrow):?>MAÑANA<?php elseif($is_past&&!$is_done):?>ATRASADA<?php else:?><?=date('m/d',strtotime($c['fecha']))?><?php endif;?>
        </div>
      </div>
    </div>
    <div style="display:flex;gap:5px;flex-wrap:wrap;margin-bottom:7px">
      <span style="background:<?=$BG?>;color:<?=$P1?>;border:1px solid <?=$CB?>;border-radius:9px;padding:2px 7px;font-size:7px;font-weight:900;text-transform:uppercase"><?=h($c['tipo']??'?')?></span>
      <span style="background:<?=$BG?>;color:<?=$P2?>;border:1px solid <?=$CB?>;border-radius:9px;padding:2px 7px;font-size:7px;font-weight:900;text-transform:uppercase">
        <?=$c['modalidad']==='TELÉFONO'?'📞':'🏢'?> <?=h($c['modalidad']??'?')?>
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
      <?php if(!$is_done && !$is_canceled):?>
        <button class="btn btn-gr btn-sm" onclick="completarCita(<?=$c['id']?>)" title="Completar" style="flex:1;padding:5px 8px;font-size:8px">✓ COMPLETAR</button>
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

// Helper para agrupar por fecha y mostrar
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
?>

<!-- HEADER + KPIs + acciones -->
<div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:11px;margin-bottom:13px">
  <div style="display:flex;gap:7px;flex-wrap:wrap">
    <div style="background:#fff;border:1px solid <?=$CB?>;border-left:4px solid #C07A1A;border-radius:9px;padding:7px 12px;min-width:75px">
      <div style="font-size:7px;color:<?=$MU?>;font-weight:900;text-transform:uppercase">HOY</div>
      <div style="font-size:18px;font-weight:900;color:#C07A1A"><?=$citas_hoy_n?></div>
    </div>
    <div style="background:#fff;border:1px solid <?=$CB?>;border-left:4px solid #2876A8;border-radius:9px;padding:7px 12px;min-width:75px">
      <div style="font-size:7px;color:<?=$MU?>;font-weight:900;text-transform:uppercase">MAÑANA</div>
      <div style="font-size:18px;font-weight:900;color:#2876A8"><?=$citas_manana_n?></div>
    </div>
    <div style="background:#fff;border:1px solid <?=$CB?>;border-left:4px solid <?=$P1?>;border-radius:9px;padding:7px 12px;min-width:75px">
      <div style="font-size:7px;color:<?=$MU?>;font-weight:900;text-transform:uppercase">7 DÍAS</div>
      <div style="font-size:18px;font-weight:900;color:<?=$P1?>"><?=$citas_semana_n?></div>
    </div>
    <?php if($citas_atrasadas_n > 0):?>
    <div style="background:#fff;border:1px solid #F4C8C8;border-left:4px solid #B83232;border-radius:9px;padding:7px 12px;min-width:75px">
      <div style="font-size:7px;color:#B83232;font-weight:900;text-transform:uppercase">⚠ ATRASADAS</div>
      <div style="font-size:18px;font-weight:900;color:#B83232"><?=$citas_atrasadas_n?></div>
    </div>
    <?php endif;?>
  </div>
  <div style="display:flex;gap:6px;flex-wrap:wrap">
    <button class="btn btn-gh btn-sm" onclick="exportCitasCSV()" title="Descargar CSV">⤓ CSV</button>
    <button class="btn btn-b btn-sm" onclick="openModal('cita-form-modal')">+ NUEVA CITA</button>
  </div>
</div>

<!-- FILTROS Y SUB-TABS -->
<div style="background:#fff;border:1px solid <?=$CB?>;border-radius:11px;padding:10px 13px;margin-bottom:13px">
  <div style="display:flex;flex-wrap:wrap;gap:7px;align-items:center;margin-bottom:9px">
    <input type="search" id="cita-search" placeholder=" Buscar por nombre, tipo, notas..." onkeyup="filtrarCitas()" style="flex:1;min-width:180px;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:8px;padding:7px 11px;font-size:9px;font-family:'DM Sans',sans-serif;outline:none">
    <input type="date" id="cita-fecha-filtro" onchange="filtrarCitas()" style="background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:8px;padding:6px 9px;font-size:9px;font-family:'DM Sans',sans-serif;outline:none">
    <?php if($admin):?>
    <select id="cita-agente-filtro" onchange="filtrarCitas()" style="background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:8px;padding:6px 9px;font-size:9px;font-family:'DM Sans',sans-serif;outline:none">
      <option value="">Todos los agentes</option>
      <?php foreach($users_all as $u):?><option value="<?=$u['id']?>"><?=h(explode(' ',$u['nombre'])[0])?></option><?php endforeach;?>
    </select>
    <?php endif;?>
    <select id="cita-tipo-filtro" onchange="filtrarCitas()" style="background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:8px;padding:6px 9px;font-size:9px;font-family:'DM Sans',sans-serif;outline:none">
      <option value="">Todos los tipos</option>
      <?php
      $tipos_unicos = array_unique(array_filter(array_column($citas_view,'tipo')));
      sort($tipos_unicos);
      foreach($tipos_unicos as $t):?><option value="<?=h($t)?>"><?=h($t)?></option><?php endforeach;?>
    </select>
    <select id="cita-modalidad-filtro" onchange="filtrarCitas()" style="background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:8px;padding:6px 9px;font-size:9px;font-family:'DM Sans',sans-serif;outline:none">
      <option value="">Todas modalidades</option>
      <option value="OFICINA">Oficina</option>
      <option value="TELÉFONO">Teléfono</option>
      <option value="VIDEO">Video</option>
    </select>
    <button class="btn btn-gh btn-sm" onclick="resetCitaFiltros()" style="font-size:8px">↺ LIMPIAR</button>
  </div>
  <div style="display:flex;gap:0;border-bottom:1px solid <?=$CB?>">
    <button class="cita-subtab active" data-csub="pendientes" onclick="cambiarSubtabCitas('pendientes')" style="background:none;border:none;border-bottom:3px solid <?=$P1?>;color:<?=$P1?>;font-weight:900;font-size:9px;padding:8px 15px;cursor:pointer;font-family:'DM Sans',sans-serif;text-transform:uppercase;letter-spacing:1px">
      ◷ PENDIENTES (<?=count($citas_pendientes)?>)
    </button>
    <button class="cita-subtab" data-csub="completadas" onclick="cambiarSubtabCitas('completadas')" style="background:none;border:none;border-bottom:3px solid transparent;color:<?=$MU?>;font-weight:900;font-size:9px;padding:8px 15px;cursor:pointer;font-family:'DM Sans',sans-serif;text-transform:uppercase;letter-spacing:1px">
      ✓ COMPLETADAS (<?=count($citas_completadas)?>)
    </button>
    <?php if(count($citas_canceladas)):?>
    <button class="cita-subtab" data-csub="canceladas" onclick="cambiarSubtabCitas('canceladas')" style="background:none;border:none;border-bottom:3px solid transparent;color:<?=$MU?>;font-weight:900;font-size:9px;padding:8px 15px;cursor:pointer;font-family:'DM Sans',sans-serif;text-transform:uppercase;letter-spacing:1px">
      ✕ CANCELADAS (<?=count($citas_canceladas)?>)
    </button>
    <?php endif;?>
  </div>
</div>

<!-- ─── PENDIENTES ─── -->
<div id="csub-pendientes" class="csub-pane">
  <?php
  // Agrupar pendientes por fecha relativa
  $g_atrasadas = []; $g_hoy = []; $g_manana = []; $g_semana = []; $g_futuro = [];
  foreach($citas_pendientes as $c) {
    if      ($c['fecha'] < $today_d)                                  $g_atrasadas[] = $c;
    elseif  ($c['fecha'] == $today_d)                                 $g_hoy[]       = $c;
    elseif  ($c['fecha'] == $tomorrow_d)                              $g_manana[]    = $c;
    elseif  ($c['fecha'] <= $week_end)                                $g_semana[]    = $c;
    else                                                              $g_futuro[]    = $c;
  }
  $render_grupo('⚠ ATRASADAS — REQUIEREN ATENCIÓN', '#B83232', $g_atrasadas);
  $render_grupo('● HOY · '.date('m/d/Y'),            '#C07A1A', $g_hoy);
  $render_grupo('► MAÑANA · '.date('m/d/Y',strtotime('+1 day')), '#2876A8', $g_manana);
  $render_grupo('ESTA SEMANA',                       $P1, $g_semana);
  $render_grupo('PRÓXIMAS',                          $P2, $g_futuro);
  if (!count($citas_pendientes)):?>
    <div style="padding:40px;text-align:center;color:<?=$MU?>;background:#fff;border:1px solid <?=$CB?>;border-radius:11px">
      <div style="font-size:32px;margin-bottom:9px">◷</div>
      <div style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1px">SIN CITAS PENDIENTES</div>
      <div style="font-size:8px;color:<?=$MU?>;margin-top:5px">Todo al día. Crea una nueva con el botón de arriba.</div>
    </div>
  <?php endif;?>
</div>

<!-- ─── COMPLETADAS ─── -->
<div id="csub-completadas" class="csub-pane" style="display:none">
  <?php
  // Agrupar completadas por mes (más reciente primero)
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
<?php if(count($citas_canceladas)):?>
<div id="csub-canceladas" class="csub-pane" style="display:none">
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:9px">
    <?php foreach($citas_canceladas as $c) $render_cita($c); ?>
  </div>
</div>
<?php endif;?>

</div><!-- /CITAS -->
<!-- TICKETS -->
<div id="tab-TICKETS" class="tab-pane">

<?php
// Conteos para las stat-cards (ignorando pospuestos)
$today_d = date('Y-m-d');

// NUEVO: Filtramos para que los cuadritos solo cuenten TUS tickets
$mis_tickets_stats = array_filter($tickets, function($t) use ($uid) {
    if (!empty($t['asignado_a'])) return $t['asignado_a'] == $uid;
    return $t['agente_id'] == $uid;
});

$tkt_abiertos   = count(array_filter($mis_tickets_stats, fn($t)=>in_array($t['tipo'],$TIPO_MIEMBRO,true) && $t['estado']==='ABIERTO'    && (empty($t['sla_fecha']) || $t['sla_fecha'] <= $today_d)));
$tkt_pendiente  = count(array_filter($mis_tickets_stats, fn($t)=>in_array($t['tipo'],$TIPO_MIEMBRO,true) && $t['estado']==='PENDIENTE'  && (empty($t['sla_fecha']) || $t['sla_fecha'] <= $today_d)));
$tkt_proceso    = count(array_filter($mis_tickets_stats, fn($t)=>in_array($t['tipo'],$TIPO_MIEMBRO,true) && $t['estado']==='EN PROCESO' && (empty($t['sla_fecha']) || $t['sla_fecha'] <= $today_d)));
$tkt_alta_open  = count(array_filter($mis_tickets_stats, fn($t)=>in_array($t['tipo'],$TIPO_MIEMBRO,true) && $t['prioridad']==='ALTA' && $t['estado']!=='CERRADO' && (empty($t['sla_fecha']) || $t['sla_fecha'] <= $today_d)));
$tkt_cerr_mes   = count(array_filter($mis_tickets_stats, fn($t)=>in_array($t['tipo'],$TIPO_MIEMBRO,true) && $t['estado']==='CERRADO'   && !empty($t['fecha_cierre']) && str_starts_with($t['fecha_cierre'], date('Y-m'))));

$tkt_miembro_cnt = count(array_filter($mis_tickets_stats, fn($t)=>in_array($t['tipo'],$TIPO_MIEMBRO,true)&&$t['estado']!=='CERRADO'&&(empty($t['sla_fecha'])||$t['sla_fecha']<=$today_d)));
$tkt_tarea_cnt   = count(array_filter($mis_tickets_stats, fn($t)=>!in_array($t['tipo'],$TIPO_MIEMBRO,true)&&$t['estado']!=='CERRADO'&&(empty($t['sla_fecha'])||$t['sla_fecha']<=$today_d)));
?>

<div class="stats-row tkt-only" style="margin-bottom:14px">
  <div class="stat-card" style="color:#B83232;cursor:pointer;border-top-color:#B83232" onclick="setTktFiltro('estado','ABIERTO')">
    <div class="stat-icon">◈ ABIERTOS</div>
    <div class="stat-val" style="color:#B83232"><?=$tkt_abiertos?></div>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase">REQUIEREN ATENCIÓN</div>
  </div>
  <div class="stat-card" style="color:#C07A1A;cursor:pointer;border-top-color:#C07A1A" onclick="setTktFiltro('estado','PENDIENTE')">
    <div class="stat-icon"> PENDIENTES</div>
    <div class="stat-val" style="color:#C07A1A"><?=$tkt_pendiente?></div>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase">EN ESPERA</div>
  </div>
  <div class="stat-card" style="color:#1B5E8C;cursor:pointer;border-top-color:#1B5E8C" onclick="setTktFiltro('estado','EN PROCESO')">
    <div class="stat-icon">◐ EN PROCESO</div>
    <div class="stat-val" style="color:#1B5E8C"><?=$tkt_proceso?></div>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase">ACTIVOS</div>
  </div>
  <div class="stat-card" style="color:#B83232;cursor:pointer;border-top-color:#B83232" onclick="setTktFiltro('prio','ALTA')">
    <div class="stat-icon"> URGENTES</div>
    <div class="stat-val" style="color:#B83232"><?=$tkt_alta_open?></div>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase">PRIORIDAD ALTA</div>
  </div>
  <div class="stat-card" style="color:#1E7A5C;cursor:pointer;border-top-color:#1E7A5C" onclick="setTktFiltro('estado','CERRADO')">
    <div class="stat-icon">✓ CERRADOS</div>
    <div class="stat-val" style="color:#1E7A5C"><?=$tkt_cerr_mes?></div>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase">ESTE MES</div>
  </div>
</div>

<div style="display:flex;gap:0;margin-bottom:13px;background:#fff;border:1px solid <?=$CB?>;border-radius:13px;overflow:hidden">
  <button id="vtab-miembro" onclick="setTktVista('miembro')"
    style="flex:1;padding:12px 16px;border:none;cursor:pointer;font-size:9px;font-weight:900;letter-spacing:2px;text-transform:uppercase;font-family:'DM Sans',sans-serif;background:<?=$P1?>;color:#fff;border-right:1px solid <?=$CB?>;display:flex;align-items:center;justify-content:center;gap:6px">
    ◉ TICKETS DE MIEMBROS
    <span id="vtab-miembro-cnt" style="background:rgba(255,255,255,.25);border-radius:20px;padding:1px 8px;font-size:8px"><?=$tkt_miembro_cnt?></span>
  </button>
  <button id="vtab-tarea" onclick="setTktVista('tarea')"
    style="flex:1;padding:12px 16px;border:none;cursor:pointer;font-size:9px;font-weight:900;letter-spacing:2px;text-transform:uppercase;font-family:'DM Sans',sans-serif;background:#fff;color:<?=$MU?>;border-right:1px solid <?=$CB?>;display:flex;align-items:center;justify-content:center;gap:6px">
    ◈ TAREAS GENERALES
    <span id="vtab-tarea-cnt" style="background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:20px;padding:1px 8px;font-size:8px"><?=$tkt_tarea_cnt?></span>
  </button>
  <button id="vtab-proyecto" onclick="setTktVista('proyecto')"
    style="flex:1;padding:12px 16px;border:none;cursor:pointer;font-size:9px;font-weight:900;letter-spacing:2px;text-transform:uppercase;font-family:'DM Sans',sans-serif;background:#fff;color:<?=$MU?>;display:flex;align-items:center;justify-content:center;gap:6px">
    📁 PROYECTOS
    <span id="vtab-proyecto-cnt" style="background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:20px;padding:1px 8px;font-size:8px">0</span>
  </button>
</div>

<!-- Barra de filtros + botón nuevo -->
<div class="card tkt-only" style="padding:11px 14px;margin-bottom:13px">
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <!-- Búsqueda -->
    <!-- Campos señuelo: evitan que Chrome rellene el buscador con credenciales guardadas -->
    <input type="text" style="display:none" name="username_fake" autocomplete="username">
    <input type="password" style="display:none" name="password_fake" autocomplete="new-password">
    <input type="search" id="tkt-search" oninput="filterTickets()" placeholder="🔍  Buscar por cliente, descripción, tipo…"
      value="" autocomplete="off" spellcheck="false"
      style="flex:2;min-width:180px;border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 12px;font-size:11px;font-family:'DM Sans',sans-serif;background:<?=$BG?>;color:<?=$TX?>">

    <!-- Pills de estado: ACTIVOS (por defecto) | CERRADO | TODOS -->
    <div style="display:flex;gap:3px;background:<?=$BG?>;border-radius:10px;padding:3px;flex-shrink:0">
      <button id="tpill-ACTIVOS"  onclick="setTktFiltro('estado','ACTIVOS')"  class="tkt-pill tkt-pill-on">✓ ACTIVOS</button>
      <button id="tpill-CERRADO"  onclick="setTktFiltro('estado','CERRADO')"  class="tkt-pill" style="color:#1E7A5C">CERRADO</button>
      <button id="tpill-"         onclick="setTktFiltro('estado','')"         class="tkt-pill">TODOS</button>
    </div>

    <!-- Prioridad y Tipo -->
    <select id="tkt-prio" onchange="filterTickets()"
      style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 10px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase">
      <option value="">☐ PRIORIDAD</option><option>ALTA</option><option>MEDIA</option><option>BAJA</option>
    </select>
    <select id="tkt-tipo" onchange="filterTickets()"
      style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 10px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase">
      <option value="">☐ TIPO</option>
      <optgroup label="── CON MIEMBRO ──">
      <?php foreach($TIPO_MIEMBRO as $tp):?><option><?=$tp?></option><?php endforeach;?>
      </optgroup>
      <optgroup label="── TAREA GENERAL ──">
      <?php foreach($TIPO_TAREA as $tp):?><option><?=$tp?></option><?php endforeach;?>
      </optgroup>
    </select>
    <?php if($admin):?>
    <select id="tkt-resp" onchange="filterTickets()"
      style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 10px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase">
      <option value="">☐ TODOS LOS AGENTES</option>
      <?php foreach($users_all as $u):?>
      <option value="<?=$u['id']?>"><?=h(explode(' ',$u['nombre'])[0])?></option>
      <?php endforeach;?>
    </select>
    <?php endif;?>

    <!-- Limpiar + Nuevo -->
    <button class="btn btn-gh btn-sm" onclick="limpiarTktFiltros()" title="Limpiar filtros">↺</button>
    <button class="btn btn-p btn-sm" onclick="openTicketForm()">+ NUEVO TICKET</button>
  </div>
</div>

<!-- Lista de tickets -->
<div class="card tkt-only" style="overflow:hidden">
<div style="overflow-x:auto">
<table id="tkt-list" style="width:100%;border-collapse:collapse">
<thead>
<tr style="background:<?=$BG?>">
  <th style="padding:8px 14px;text-align:left;font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px;border-bottom:2px solid <?=$CB?>;white-space:nowrap;width:4px"></th>
  <th style="padding:8px 14px;text-align:left;font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px;border-bottom:2px solid <?=$CB?>;white-space:nowrap">CLIENTE</th>
  <th style="padding:8px 14px;text-align:left;font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px;border-bottom:2px solid <?=$CB?>">DESCRIPCIÓN</th>
  <th style="padding:8px 14px;text-align:left;font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px;border-bottom:2px solid <?=$CB?>;white-space:nowrap">TIPO</th>
  <th style="padding:8px 14px;text-align:left;font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px;border-bottom:2px solid <?=$CB?>;white-space:nowrap">PRIO</th>
  <th style="padding:8px 14px;text-align:left;font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px;border-bottom:2px solid <?=$CB?>;white-space:nowrap">ESTADO</th>
  <th style="padding:8px 14px;text-align:left;font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px;border-bottom:2px solid <?=$CB?>;white-space:nowrap">RESPONSABLE</th>
  <th style="padding:8px 14px;text-align:left;font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px;border-bottom:2px solid <?=$CB?>;white-space:nowrap">SLA / SEG.</th>
  <th style="padding:8px 14px;text-align:left;font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px;border-bottom:2px solid <?=$CB?>;white-space:nowrap">CREADO</th>
  <th style="padding:8px 14px;border-bottom:2px solid <?=$CB?>"></th>
</tr>
</thead>
<tbody>
<?php foreach($tickets as $t):
  $sla_vence  = $t['sla_fecha'] ?? null;
  $sla_alert  = $sla_vence && $sla_vence <= date('Y-m-d', strtotime('+1 day')) && $t['estado']!=='CERRADO';
  $resp_id    = !empty($t['asignado_a']) ? $t['asignado_a'] : $t['agente_id'];
  $is_closed  = $t['estado']==='CERRADO';
  $prio       = $t['prioridad'] ?? 'MEDIA';
  $left_color = ['ALTA'=>'#B83232','MEDIA'=>'#C07A1A','BAJA'=>'#2876A8'][$prio] ?? '#2876A8';

  // Determinar nombre de cliente con fallbacks (string vacío != null)
  $cli = trim($t['miembro_nombre'] ?? '');
  if ($cli === '') $cli = trim($t['cliente'] ?? '');
  if ($cli === '') $cli = trim($t['nombre_referencia'] ?? '');
  if ($cli === '') $cli = '—';
  $display_name = h(mb_substr($cli, 0, 28));

  // Responsable: priorizar asignado, si no, creador
  if (!empty($t['asignado_nombre'])) {
      $resp_nombre = $t['asignado_nombre'];
      $resp_ini    = $t['asignado_ini']   ?? '?';
      $resp_color  = $t['asignado_color'] ?? $P2;
  } else {
      $resp_nombre = $t['agente_nombre'] ?? null;
      $resp_ini    = $t['agente_ini']    ?? '?';
      $resp_color  = $t['agente_color']  ?? $P2;
  }
?>
<tr class="ticket-row<?=$is_closed?' tkt-cerrada':''?>"
    data-id="<?=(int)$t['id']?>"
    style="border-left:3px solid <?=$left_color?>;<?=$is_closed?'opacity:.6':''?>"
    data-vista="<?=in_array($t['tipo'],$TIPO_MIEMBRO,true)?'miembro':'tarea'?>"
    data-prio="<?=h($prio)?>"
    data-estado="<?=h($t['estado']??'')?>"
    data-tipo="<?=h($t['tipo']??'')?>"
    data-resp="<?=h($resp_id??'')?>"
    data-fecha="<?=h($t['fecha_creacion']??'')?>"
    data-sla="<?=h($sla_vence??'')?>"
    data-search="<?=strtolower(h(implode(' ',[$t['miembro_nombre']??'',$t['cliente']??'',$t['descripcion']??'',$t['tipo']??'',$t['fuente']??'',$t['resultado']??'',$t['nombre_referencia']??''])))?>">

  <!-- Indicador de prioridad (columna de color) -->
  <td style="padding:0;width:4px;background:<?=$left_color?>"></td>

  <!-- Cliente -->
  <td style="padding:10px 14px;white-space:nowrap">
    <div style="font-size:10px;font-weight:900;color:<?=$P1?>;<?=!empty($t['miembro_id'])?'cursor:pointer':''?>"
         <?php if(!empty($t['miembro_id'])):?>onclick="openProfile(<?=$t['miembro_id']?>)"<?php endif;?>><?=$display_name?></div>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:1px">#<?=$t['id']?><?=$t['fuente']?' · '.h(mb_substr($t['fuente'],0,12)):'';?></div>
  </td>

  <!-- Descripción -->
  <td style="padding:10px 14px;max-width:280px">
    <div style="font-size:10px;color:<?=$TX?>;line-height:1.4"><?=h(mb_substr($t['descripcion']??'',0,90))?><?=mb_strlen($t['descripcion']??'')>90?'…':''?></div>
    <?php if(!empty($t['notas'])):?>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:3px">💬 <?=h(mb_substr($t['notas'],0,55))?><?=mb_strlen($t['notas'])>55?'…':''?></div>
    <?php endif;?>
    <?php
      // Mostrar el próximo Next Step pendiente (si hay)
      $ns_list  = $next_steps_por_ticket[$t['id']] ?? [];
      $ns_pend  = array_values(array_filter($ns_list, fn($n)=>!$n['completado']));
      $ns_total_pend = count($ns_pend);
      if ($ns_total_pend > 0):
        $ns_proximo = $ns_pend[0];
        $ns_vencido = !empty($ns_proximo['fecha_programada']) && $ns_proximo['fecha_programada'] < date('Y-m-d') && !$is_closed;
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

  <!-- Tipo -->
  <td style="padding:10px 14px;white-space:nowrap">
    <span style="font-size:8px;color:<?=$MU?>;font-weight:700"><?=h($t['tipo']??'OTRO')?></span>
  </td>

  <!-- Prioridad -->
  <td style="padding:10px 14px"><?=badge($prio,true)?></td>

  <!-- Estado -->
  <td style="padding:10px 14px"><?=badge($t['estado']??'ABIERTO',true)?></td>

  <!-- Responsable -->
  <td style="padding:10px 14px;white-space:nowrap">
    <?php if($resp_nombre): ?>
    <div style="display:flex;gap:5px;align-items:center">
      <?=av(h($resp_ini),h($resp_color),20)?>
      <span style="font-size:9px;font-weight:900;color:<?=$P1?>"><?=h(explode(' ',$resp_nombre)[0])?></span>
    </div>
    <?php else:?><span style="font-size:8px;color:<?=$MU?>">—</span><?php endif;?>
  </td>

  <!-- SLA / Seguimiento -->
    <td style="padding:10px 14px;white-space:nowrap;font-size:9px">
        <?php if($sla_vence && !$is_closed):?>
          <div style="font-weight:900;<?=$sla_alert?'color:#B83232':'color:'.$MU?>"><?=date('m/d/Y',strtotime($sla_vence))?></div>
        <?php elseif($t['fecha_seguimiento']??null):?>
          <div style="color:<?=$MU?>"><?=date('m/d/Y',strtotime($t['fecha_seguimiento']))?></div>
        <?php else:?><span style="color:<?=$MU?>">—</span><?php endif;?>
      </td>

  <!-- Creado -->
    <td style="padding:10px 14px;white-space:nowrap;font-size:9px;color:<?=$MU?>">
        <?=!empty($t['fecha_creacion']) ? date('m/d/Y',strtotime($t['fecha_creacion'])) : '—'?>
      </td>

  <!-- Acciones -->
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
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<div id="tkt-count" class="tkt-only" style="padding:8px 14px;font-size:9px;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;border-top:1px solid <?=$CB?>"></div>
<div id="tkt-empty" class="tkt-only" style="display:none;padding:36px;text-align:center;border-top:1px solid <?=$CB?>">
  <div style="font-size:22px;margin-bottom:6px">◈</div>
  <div style="font-size:10px;font-weight:900;color:<?=$MU?>;letter-spacing:2px;text-transform:uppercase">Sin tickets que mostrar</div>
  <div style="font-size:9px;color:<?=$MU?>;margin-top:3px">Ajusta los filtros o crea un nuevo ticket</div>
</div>

<!-- ════════ PANEL DE PROYECTOS ════════ -->
<div id="proyectos-panel" style="display:none">
  <div class="card" style="padding:11px 14px;margin-bottom:13px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="search" id="proy-search" oninput="renderProyectos()" placeholder="🔍  Buscar proyecto…"
      autocomplete="off" spellcheck="false"
      style="flex:2;min-width:160px;border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 12px;font-size:11px;font-family:'DM Sans',sans-serif;background:<?=$BG?>;color:<?=$TX?>">
    <div style="display:flex;gap:3px;background:<?=$BG?>;border-radius:10px;padding:3px;flex-shrink:0">
      <button id="ppill-ACTIVOS" onclick="setProyFiltro('ACTIVOS')" class="tkt-pill tkt-pill-on">EN CURSO</button>
      <button id="ppill-COMPLETADO" onclick="setProyFiltro('COMPLETADO')" class="tkt-pill" style="color:#1E7A5C">COMPLETADOS</button>
      <button id="ppill-TODOS" onclick="setProyFiltro('TODOS')" class="tkt-pill">TODOS</button>
    </div>
    <button class="btn btn-p btn-sm" style="margin-left:auto" onclick="openProyectoForm()">+ NUEVO PROYECTO</button>
  </div>
  <?php if($admin): ?>
  <!-- Resumen de proyectos por estado (solo admin) -->
  <div id="proy-resumen" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:13px"></div>
  <?php endif; ?>
  <div id="proy-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px"></div>
  <div id="proy-empty" style="display:none;padding:46px;text-align:center;background:#fff;border:1px solid <?=$CB?>;border-radius:13px">
    <div style="font-size:30px;margin-bottom:8px">📁</div>
    <div style="font-size:11px;font-weight:900;color:<?=$MU?>;letter-spacing:2px;text-transform:uppercase">Sin proyectos todavía</div>
    <div style="font-size:9px;color:<?=$MU?>;margin-top:4px">Crea tu primer proyecto para llevar el registro de avances</div>
    <button class="btn btn-p btn-sm" style="margin-top:14px" onclick="openProyectoForm()">+ CREAR PROYECTO</button>
  </div>
</div>

</div><!-- /TICKETS -->

<!-- ══ MODAL: FORM DE PROYECTO ══ -->
<div id="modal-proyecto" class="modal-overlay" style="z-index:9700"><div class="modal modal-sm">
  <div class="modal-header"><div class="modal-title" id="proy-modal-title">📁 NUEVO PROYECTO</div><button class="modal-close" onclick="closeModal('modal-proyecto')">✕</button></div>
  <input type="hidden" id="proy-id">
  <div class="form-group"><label class="form-label">TÍTULO *</label><input id="proy-titulo" class="form-input" placeholder="Nombre del proyecto" maxlength="200"></div>
  <div class="form-group"><label class="form-label">DESCRIPCIÓN</label><textarea id="proy-desc" class="form-input" rows="3" placeholder="¿De qué trata el proyecto?"></textarea></div>
  <div style="display:flex;gap:10px">
    <div class="form-group" style="flex:1"><label class="form-label">ESTADO</label><select id="proy-estado" class="form-input"><option value="PLANIFICANDO">PLANIFICANDO</option><option value="EN PROGRESO">EN PROGRESO</option><option value="CONTINUO">CONTINUO (EN CURSO / SIEMPRE)</option><option value="PAUSADO">PAUSADO</option><option value="COMPLETADO">COMPLETADO</option></select></div>
    <div class="form-group" style="flex:1"><label class="form-label">PRIORIDAD</label><select id="proy-prio" class="form-input"><option value="ALTA">ALTA</option><option value="MEDIA" selected>MEDIA</option><option value="BAJA">BAJA</option></select></div>
  </div>
  <div class="form-group"><label class="form-label">PROGRESO: <span id="proy-prog-val">0%</span></label><input type="range" id="proy-prog" min="0" max="100" step="5" value="0" oninput="document.getElementById('proy-prog-val').textContent=this.value+'%'" style="width:100%"></div>
  <div class="form-group"><label class="form-label">RESPONSABLE PRINCIPAL</label><select id="proy-asig" class="form-input"><option value="">— Sin asignar —</option><?php foreach($users_all as $u):?><option value="<?=$u['id']?>"><?=h(explode(' ',$u['nombre'])[0])?></option><?php endforeach;?></select></div>
  <div class="form-group">
    <label class="form-label">EQUIPO · COLABORADORES <span style="color:#7A90A4;font-weight:400">(pueden trabajar en el proyecto)</span></label>
    <div id="proy-team-box" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:6px;border:1.5px solid <?=$CB?>;border-radius:9px;padding:9px;background:<?=$BG?>">
      <?php foreach($users_all as $u):?>
      <label style="display:flex;align-items:center;gap:6px;font-size:10px;font-weight:700;color:<?=$P2?>;cursor:pointer">
        <input type="checkbox" class="proy-team-chk" value="<?=$u['id']?>"> <?=h(explode(' ',$u['nombre'])[0])?>
      </label>
      <?php endforeach;?>
    </div>
  </div>
  <div style="display:flex;gap:10px">
    <div class="form-group" style="flex:1"><label class="form-label">INICIO</label><input type="date" id="proy-finicio" class="form-input"></div>
    <div class="form-group" style="flex:1"><label class="form-label">FECHA LÍMITE</label><input type="date" id="proy-flimite" class="form-input"></div>
  </div>
  <div style="display:flex;justify-content:flex-end;gap:7px;margin-top:8px">
    <button type="button" class="btn btn-gh btn-sm" onclick="closeModal('modal-proyecto')">CANCELAR</button>
    <button type="button" class="btn btn-p btn-sm" onclick="saveProyecto()">GUARDAR</button>
  </div>
</div></div>

<!-- ══ MODAL: DETALLE DE PROYECTO ══ -->
<div id="modal-proyecto-detail" class="modal-overlay"><div class="modal" style="max-width:680px;width:96vw">
  <div class="modal-header"><div class="modal-title">📁 PROYECTO</div><button class="modal-close" onclick="closeModal('modal-proyecto-detail')">✕</button></div>
  <div id="proy-detail-body"></div>
</div></div>

<!-- ASISTENCIA -->
<!-- ASISTENCIA -->
<div id="tab-ASISTENCIA" class="tab-pane">
 
<?php
// ── Quincena activa ─────────────────────────────────────────────────────
$hoy_d = (int)date('j');
$q_default = $hoy_d <= 15 ? 1 : 2;
// Calcular rango de quincena actual
$q_inicio = $q_default === 1
    ? date('Y-m-01')
    : date('Y-m-16');
$q_fin    = $q_default === 1
    ? date('Y-m-15')
    : date('Y-m-t');   // último día del mes actual
$q_label  = $q_default === 1 ? '1ª QUINCENA (1–15)' : '2ª QUINCENA (16–' . date('t') . ')';
?>
 
<!-- CARD SUPERIOR: registro personal de HOY (agente) -->
<?php if(!$admin):?>
<div class="card" style="border-top:3px solid <?=$P1?>;margin-bottom:14px">
  <div class="card-header"><div class="card-title">◐ MI REGISTRO DE HOY</div></div>
  <div style="padding:12px 16px">
    <?php if($my_ci):?>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
      <?php foreach([
        ['CHECK-IN',   $my_ci['check_in'],        '#1E7A5C'],
        ['SAL.ALM.',   $my_ci['lunch_out'],        '#C07A1A'],
        ['REG.ALM.',   $my_ci['lunch_in'],         '#1E7A8C'],
        ['SAL.BREAK',  $my_ci['break_out']??null,  '#C07A1A'],
        ['REG.BREAK',  $my_ci['break_in']??null,   '#1E7A8C'],
        ['CHECK-OUT',  $my_ci['check_out'],        '#B83232'],
      ] as [$l,$v,$c]):?>
      <div style="text-align:center;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:8px;padding:8px">
        <div style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px"><?=$l?></div>
        <div style="font-size:12px;font-weight:900;color:<?=$v?$c:$MU?>"><?=$v?substr($v,0,5):'—'?></div>
      </div>
      <?php endforeach;?>
    </div>
    <?php else:?>
    <div style="color:#C07A1A;font-weight:800;text-transform:uppercase">⚠ SIN REGISTRO HOY</div>
    <?php endif;?>
  </div>
</div>
<?php endif;?>
 
<!-- ADMIN: resumen del día + botón nómina + tabla quincena -->
<?php if($admin):?>
 
<!-- Mini-cards de hoy por agente -->
<div style="display:flex;gap:9px;flex-wrap:wrap;margin-bottom:14px">
<?php foreach($agents as $ag):
  $ci2 = array_filter($today_ckins, fn($c)=>$c['agente_id']==$ag['id']);
  $ci2 = reset($ci2)?:null;
  $w2  = calc_hours(
    $ci2['check_in']??null,$ci2['lunch_out']??null,$ci2['lunch_in']??null,
    $ci2['check_out']??null,$ci2['break_out']??null,$ci2['break_in']??null
  );
?>
<div style="background:#fff;border:1px solid <?=$CB?>;border-radius:12px;padding:12px 14px;flex:1;min-width:120px;border-top:3px solid <?=h($ag['color'])?>">
  <div style="display:flex;gap:7px;align-items:center;margin-bottom:7px">
    <?=av(h($ag['iniciales']),h($ag['color']),34)?>
    <div>
      <div style="font-weight:900;font-size:10px;color:<?=$P1?>"><?=h(explode(' ',$ag['nombre'])[0])?></div>
      <div style="font-size:8px;color:<?=$ci2&&$ci2['check_in']?'#1E7A5C':'#B83232'?>;font-weight:800;text-transform:uppercase">
        <?=$ci2&&$ci2['check_in']?'✓ '.substr($ci2['check_in'],0,5):'SIN CHECK-IN'?>
      </div>
    </div>
  </div>
  <div style="font-size:8px;color:<?=$MU?>">
    HORAS: <b style="color:<?=$w2?'#1E7A5C':$MU?>"><?=$w2??'—'?></b>
    <?php if($ci2&&!empty($ci2['break_out'])):?>
    · BREAK: <b style="color:#C07A1A"><?=substr($ci2['break_out'],0,5).'–'.($ci2['break_in']?substr($ci2['break_in'],0,5):'•')?></b>
    <?php endif;?>
  </div>
</div>
<?php endforeach;?>
</div>
 
<!-- Barra quincena + botón nómina -->
<div style="background:#fff;border:1px solid <?=$CB?>;border-radius:12px;padding:12px 16px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:9px">
  <div>
    <div style="font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px">PERÍODO ACTIVO</div>
    <div style="font-size:11px;font-weight:900;color:<?=$P1?>;margin-top:3px"><?=$q_label?> · <?=strtoupper(date('F Y'))?></div>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:2px"><?=date('m/d/Y',strtotime($q_inicio))?> — <?=date('m/d/Y',strtotime($q_fin))?></div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="reporte_nomina.php?y=<?=date('Y')?>&m=<?=date('n')?>&q=<?=$q_default?>"
       target="_blank"
       class="btn btn-p"
       style="text-decoration:none;padding:8px 16px;font-size:10px">
      $ VER NÓMINA QUINCENA
    </a>
  </div>
</div>
 
<!-- Tabla de asistencia agrupada por quincena -->
<?php
// Asistencia de la quincena activa
$asist_q = $pdo->prepare(
    "SELECT a.*,u.nombre,u.color,u.iniciales
     FROM asistencia a
     LEFT JOIN usuarios u ON a.agente_id=u.id
     WHERE a.fecha BETWEEN ? AND ?
     ORDER BY a.fecha DESC, u.nombre"
);
$asist_q->execute([$q_inicio, $q_fin]);
$rows_q = $asist_q->fetchAll();
?>
<div class="card">
  <div class="card-header">
    <div>
      <div class="card-title">◐ ASISTENCIA — <?=$q_label?></div>
      <div class="card-sub"><?=count($rows_q)?> REGISTROS</div>
    </div>
    <div style="display:flex;gap:6px">
      <!-- Selector de quincena rápida -->
      <select id="asi-q-sel" onchange="cambiarQuincena()"
        style="border:1.5px solid <?=$CB?>;border-radius:8px;padding:5px 9px;font-size:9px;font-weight:800;text-transform:uppercase;font-family:'DM Sans',sans-serif;background:<?=$BG?>">
        <option value="1" <?=$q_default==1?'selected':''?>>1ª Quincena (1–15)</option>
        <option value="2" <?=$q_default==2?'selected':''?>>2ª Quincena (16–fin)</option>
      </select>
    </div>
  </div>
  <div style="overflow-x:auto">
    <table>
      <tr>
        <th>EMPLEADO</th><th>FECHA</th><th>DÍA</th>
        <th>CHECK-IN</th><th>SAL.ALM.</th><th>REG.ALM.</th>
        <th>SAL.BREAK</th><th>REG.BREAK</th><th>CHECK-OUT</th>
        <th>HORAS</th><th>ESTADO</th>
      </tr>
      <?php
      $dias_es = ['','Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
      foreach($rows_q as $c):
        $w3 = calc_hours(
          $c['check_in'],$c['lunch_out'],$c['lunch_in'],
          $c['check_out'],$c['break_out']??null,$c['break_in']??null
        );
        $dow_n = $dias_es[(int)date('w',strtotime($c['fecha']))+1] ?? '—';
        $completo = $c['check_in'] && $c['check_out'];
      ?>
      <tr>
        <td>
          <div style="display:flex;gap:6px;align-items:center">
            <?=av(h($c['iniciales']??'?'),h($c['color']??$P2),22)?>
            <span style="font-weight:900;font-size:9px;color:<?=$P1?>"><?=h(explode(' ',$c['nombre']??'')[0])?></span>
          </div>
        </td>
        <td style="font-size:8px;color:<?=$MU?>;font-weight:800"><?=$c['fecha']?></td>
        <td style="font-size:8px;color:<?=$MU?>"><?=$dow_n?></td>
        <td style="font-weight:900;color:#1E7A5C;font-size:9px"><?=$c['check_in']?substr($c['check_in'],0,5):'—'?></td>
        <td style="font-size:8px;color:<?=$MU?>"><?=$c['lunch_out']?substr($c['lunch_out'],0,5):'—'?></td>
        <td style="font-size:8px;color:<?=$MU?>"><?=$c['lunch_in']?substr($c['lunch_in'],0,5):'—'?></td>
        <td style="font-size:8px;color:<?=$MU?>"><?=!empty($c['break_out'])?substr($c['break_out'],0,5):'—'?></td>
        <td style="font-size:8px;color:<?=$MU?>"><?=!empty($c['break_in'])?substr($c['break_in'],0,5):'—'?></td>
        <td style="font-weight:900;color:<?=$completo?'#B83232':'#C07A1A'?>;font-size:9px">
          <?=$c['check_out']?substr($c['check_out'],0,5):'ACTIVO •'?>
        </td>
        <td style="font-weight:900;color:<?=$w3?'#1E7A5C':$MU?>"><?=$w3??'—'?></td>
        <td>
          <?php if($completo): ?>
            <span style="background:#EAF5F0;color:#1E7A5C;border:1px solid #8DCFBA;padding:2px 8px;border-radius:20px;font-size:7px;font-weight:900">✓ COMPLETO</span>
          <?php elseif($c['check_in']): ?>
            <span style="background:#EBF5FB;color:#1B5E8C;border:1px solid #A9D0E8;padding:2px 8px;border-radius:20px;font-size:7px;font-weight:900">● ACTIVO</span>
          <?php else: ?>
            <span style="background:#FDF0EE;color:#B83232;border:1px solid #EFA09A;padding:2px 8px;border-radius:20px;font-size:7px;font-weight:900">— AUSENTE</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(!count($rows_q)): ?>
      <tr><td colspan="11" style="text-align:center;padding:20px;font-size:9px;color:<?=$MU?>;text-transform:uppercase">SIN REGISTROS EN ESTE PERÍODO</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div><!-- /tabla quincena -->
 
<?php endif; // /admin ?>
 
<!-- HISTORIAL COMPLETO (visible para ambos roles) -->
<div class="card" style="margin-top:14px">
  <div class="card-header">
    <div class="card-title">◐ HISTORIAL COMPLETO</div>
    <div class="card-sub">INMUTABLE</div>
  </div>
  <div style="overflow-x:auto"><table>
    <tr>
      <th>EMPLEADO</th><th>FECHA</th><th>CHECK-IN</th><th>SAL.ALM.</th>
      <th>REG.ALM.</th><th>SAL.BREAK</th><th>REG.BREAK</th><th>CHECK-OUT</th><th>HORAS</th>
    </tr>
    <?php
    $cq = $admin
        ? $pdo->query("SELECT a.*,u.nombre,u.color,u.iniciales FROM asistencia a LEFT JOIN usuarios u ON a.agente_id=u.id ORDER BY a.fecha DESC,u.nombre")
        : $pdo->query("SELECT a.*,u.nombre,u.color,u.iniciales FROM asistencia a LEFT JOIN usuarios u ON a.agente_id=u.id WHERE a.agente_id=$uid ORDER BY a.fecha DESC");
    foreach($cq as $c):
      $w3 = calc_hours(
        $c['check_in'],$c['lunch_out'],$c['lunch_in'],
        $c['check_out'],$c['break_out']??null,$c['break_in']??null
      );
    ?>
    <tr>
      <td>
        <div style="display:flex;gap:6px;align-items:center">
          <?=av(h($c['iniciales']??'?'),h($c['color']??$P2),22)?>
          <span style="font-weight:900;font-size:9px;color:<?=$P1?>"><?=h(explode(' ',$c['nombre']??'')[0])?></span>
        </div>
      </td>
      <td style="font-size:8px;color:<?=$MU?>"><?=$c['fecha']?></td>
      <td style="font-weight:900;color:#1E7A5C;font-size:9px"><?=$c['check_in']?substr($c['check_in'],0,5):'—'?></td>
      <td style="font-size:8px;color:<?=$MU?>"><?=$c['lunch_out']?substr($c['lunch_out'],0,5):'—'?></td>
      <td style="font-size:8px;color:<?=$MU?>"><?=$c['lunch_in']?substr($c['lunch_in'],0,5):'—'?></td>
      <td style="font-size:8px;color:<?=$MU?>"><?=!empty($c['break_out'])?substr($c['break_out'],0,5):'—'?></td>
      <td style="font-size:8px;color:<?=$MU?>"><?=!empty($c['break_in'])?substr($c['break_in'],0,5):'—'?></td>
      <td style="font-weight:900;color:<?=$c['check_out']?'#B83232':'#C07A1A'?>;font-size:9px">
        <?=$c['check_out']?substr($c['check_out'],0,5):'ACTIVO •'?>
      </td>
      <td style="font-weight:900;color:<?=$w3?'#1E7A5C':$MU?>"><?=$w3??'—'?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
 
</div><!-- /ASISTENCIA -->
 
<script>
// Selector de quincena rápido
function cambiarQuincena() {
  const q = document.getElementById('asi-q-sel').value;
  window.open('reporte_nomina.php?y=<?=date('Y')?>&m=<?=date('n')?>&q='+q, '_blank');
}
</script>
<!-- POLIZAS (admin) -->
<?php if($admin):?>
<div id="tab-POLIZAS" class="tab-pane">
<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center">
<select id="pol-carrier" onchange="filterPolizas()" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase"><option value="">TODOS LOS CARRIERS</option><?php foreach(['SCAN','ANTHEM','HUMANA','ALIGNMENT','LA CARE','HEALTH NET','MOLINA','UNITED HEALTHCARE'] as $c):?><option><?=$c?></option><?php endforeach;?></select>
<select id="pol-estado" onchange="filterPolizas()" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase"><option value="">TODOS LOS ESTADOS</option><option>ACTIVA</option><option>CANCELADA</option><option>PENDIENTE</option></select>
<a href="reporte_export.php?fmt=csv&from=<?=date('Y-m-01')?>&to=<?=$today?>" class="btn btn-gr btn-sm" target="_blank"> EXPORTAR CSV</a>
</div>
<?php $all_pols=$pdo->query("SELECT p.*,m.nombre,m.apellido,u.iniciales,u.color FROM polizas p LEFT JOIN miembros m ON p.miembro_id=m.id LEFT JOIN usuarios u ON m.agente_id=u.id ORDER BY p.tipo,m.apellido")->fetchAll();
foreach(['MEDICARE ADVANTAGE','MEDICARE SUPPLEMENT','PART D','DENTAL','SEGURO DE VIDA','VISIÓN','OTRO'] as $pt):$items=array_filter($all_pols,fn($p)=>$p['tipo']===$pt);if(!count($items))continue;?>
<div class="card pol-section" style="margin-bottom:11px"><div class="card-header"><div class="card-title"><?=$pt?></div><div class="card-sub"><span class="pol-count"><?=count($items)?></span> PÓLIZAS</div></div><div style="overflow-x:auto"><table class="pol-table"><tr><th>MIEMBRO</th><th>CARRIER</th><th>PLAN</th><th>EFECTIVA</th><th>PRIMA</th><th>ESTADO</th></tr>
<?php foreach($items as $p):?><tr class="pol-row" data-carrier="<?=strtolower($p['carrier']??'')?>" data-estado="<?=strtolower($p['estado']??'')?>" onclick="openProfile(<?=$p['miembro_id']?>)" style="cursor:pointer"><td><div style="display:flex;gap:6px;align-items:center"><?=av(h($p['iniciales']??'?'),h($p['color']??$P2),20)?><span style="font-weight:900;font-size:9px;color:<?=$P2?>"><?=h($p['apellido'].', '.$p['nombre'])?></span></div></td><td><span style="background:#EBF5FB;color:#1B5E8C;border:1px solid #A9D0E8;border-radius:20px;padding:2px 8px;font-size:8px;font-weight:900"><?=h($p['carrier']??'—')?></span></td><td style="font-size:9px"><?=h($p['plan']??'—')?></td><td style="font-size:8px;color:<?=$MU?>"><?=$p['fecha_efectiva']??'—'?></td><td style="font-weight:900;color:#1E7A5C"><?=$p['prima']>0?'$'.number_format($p['prima'],0):'$0'?></td><td><?=badge($p['estado'],true)?></td></tr><?php endforeach;?>
</table></div></div>
<?php endforeach;?>
</div><!-- /POLIZAS -->
<?php endif;?>
<!-- BONOS (admin + agentes) -->
<div id="tab-BONOS" class="tab-pane">
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">
  <div style="display:flex;gap:7px;align-items:center">
    <select id="bonos-mes" onchange="loadBonos()" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase">
      <option value="all">TODOS LOS MESES</option>
      <option value="Enero">ENERO</option>
      <option value="Febrero">FEBRERO</option>
      <option value="Marzo">MARZO</option>
      <option value="Abril">ABRIL</option>
      <option value="Mayo">MAYO</option>
      <option value="Junio">JUNIO</option>
      <option value="Julio">JULIO</option>
      <option value="Agosto">AGOSTO</option>
      <option value="Septiembre">SEPTIEMBRE</option>
      <option value="Octubre">OCTUBRE</option>
      <option value="Noviembre">NOVIEMBRE</option>
      <option value="Diciembre">DICIEMBRE</option>
    </select>
    <?php if($admin):?>
    <select id="bonos-agente" onchange="loadBonos()" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase">
      <option value="all">TODAS LAS AGENTES</option>
      <?php foreach($pdo->query("SELECT id,nombre FROM usuarios WHERE rol='agent' AND activo=1 ORDER BY nombre")->fetchAll() as $ag):?>
      <option value="<?=$ag['id']?>"><?=strtoupper(h($ag['nombre']))?></option>
      <?php endforeach;?>
    </select>
    <?php endif;?>
  </div>
  <?php if($admin):?>
  <button class="btn btn-p btn-sm" onclick="openBonoForm()">+ AGREGAR</button>
  <?php endif;?>
</div>

<!-- KPI cards -->
<div id="bonos-kpis" style="display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin-bottom:14px">
  <div class="stat-card" style="color:<?=$G?>"><div class="stat-icon"> PAGADO</div><div class="stat-val" id="bkpi-pagado" style="color:<?=$G?>">—</div><div style="font-size:8px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase">confirmado</div></div>
  <div class="stat-card" style="color:<?=$A?>"><div class="stat-icon"> PENDIENTE</div><div class="stat-val" id="bkpi-pend" style="color:<?=$A?>">—</div><div style="font-size:8px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase">por pagar</div></div>
  <div class="stat-card" style="color:<?=$P1?>"><div class="stat-icon"> BONOS</div><div class="stat-val" id="bkpi-bonos" style="color:<?=$P1?>">—</div><div style="font-size:8px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase">de venta</div></div>
  <div class="stat-card" style="color:<?=$R?>"><div class="stat-icon"> CANCELADAS</div><div class="stat-val" id="bkpi-cancel" style="color:<?=$R?>">—</div><div style="font-size:8px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase">ventas</div></div>
</div>

<!-- Table -->
<div class="card">
<div style="overflow-x:auto">
<table>
  <thead>
    <tr>
      <th>#</th>
      <?php if($admin):?><th>AGENTE</th><?php endif;?>
      <th>TIPO</th>
      <th>CLIENTE / NOTA</th>
      <th>FECHA</th>
      <th>MES</th>
      <th>TOTAL</th>
      <th>ESTADO</th>
      <?php if($admin):?><th></th><?php endif;?>
    </tr>
  </thead>
  <tbody id="bonos-tbody">
    <tr><td colspan="9" style="text-align:center;padding:20px;font-size:8px;color:<?=$MU?>;text-transform:uppercase">CARGANDO...</td></tr>
  </tbody>
</table>
</div>
<div id="bonos-footer" style="display:flex;justify-content:flex-end;gap:20px;padding:10px 14px;border-top:1px solid <?=$CB?>;font-size:8px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px"></div>
</div>
</div><!-- /BONOS -->

<!-- MODAL AGREGAR BONO (admin only) -->
<?php if($admin):?>
<div class="modal-overlay" id="bono-form-modal">
  <div class="modal" style="max-width:520px">
    <div style="padding:16px 20px;border-bottom:1px solid <?=$CB?>;display:flex;justify-content:space-between;align-items:center">
      <div style="font-size:10px;font-weight:900;letter-spacing:2px;text-transform:uppercase;color:<?=$P2?>">NUEVO REGISTRO DE PAGO</div>
      <button onclick="closeModal('bono-form-modal')" style="background:none;border:none;cursor:pointer;font-size:16px;color:<?=$MU?>">×</button>
    </div>
    <form id="bono-form" onsubmit="submitBonoForm(event)" style="padding:16px 20px">
      <input type="hidden" id="bf-id" name="id" value="">
      <div class="form-group">
        <label class="form-label">AGENTE</label>
        <select name="agente_id" id="bf-agente" class="form-input" required>
          <?php foreach($pdo->query("SELECT id,nombre FROM usuarios WHERE rol='agent' AND activo=1 ORDER BY nombre")->fetchAll() as $ag):?>
          <option value="<?=$ag['id']?>"><?=h($ag['nombre'])?></option>
          <?php endforeach;?>
        </select>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="form-group">
          <label class="form-label">TIPO</label>
          <select name="tipo" id="bf-tipo" class="form-input" onchange="bonoTipoChange()">
            <option value="Bono por venta">BONO POR VENTA</option>
            <option value="Pago por tickets">PAGO POR TICKETS</option>
            <option value="Ajuste / Otro">AJUSTE / OTRO</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">MES</label>
          <select name="mes" id="bf-mes" class="form-input">
            <?php foreach(['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'] as $m):?>
            <option value="<?=$m?>"><?=strtoupper($m)?></option>
            <?php endforeach;?>
          </select>
        </div>
      </div>
      <div class="form-group" id="bf-cliente-group">
        <label class="form-label">CLIENTE / NOMBRE DE VENTA</label>
        <input type="text" name="cliente" id="bf-cliente" class="form-input" placeholder="Nombre del cliente">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
        <div class="form-group">
          <label class="form-label">FECHA</label>
          <input type="date" name="fecha" id="bf-fecha" class="form-input" value="<?=date('Y-m-d')?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">CANTIDAD</label>
          <input type="number" name="cantidad" id="bf-cantidad" class="form-input" value="1" step="0.5" min="0" oninput="calcBonoTotal()">
        </div>
        <div class="form-group">
          <label class="form-label">PRECIO UNIT.</label>
          <input type="number" name="precio_unidad" id="bf-precio" class="form-input" value="250" step="0.01" min="0" oninput="calcBonoTotal()">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="form-group">
          <label class="form-label">TOTAL</label>
          <input type="number" name="total" id="bf-total" class="form-input" value="250" step="0.01" min="0" style="font-weight:900;color:<?=$G?>">
        </div>
        <div class="form-group">
          <label class="form-label">ESTADO</label>
          <select name="pagado" id="bf-pagado" class="form-input">
            <option value="1">PAGADO</option>
            <option value="0" selected>PENDIENTE</option>
          </select>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="form-group">
          <label class="form-label">COBRO DE REGRESO</label>
          <select name="cobro_regreso" id="bf-cobro" class="form-input">
            <option value="0">NO</option>
            <option value="1">SÍ</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">VENTA CANCELADA</label>
          <select name="venta_cancelada" id="bf-cancelada" class="form-input">
            <option value="0">NO</option>
            <option value="1">SÍ</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">NOTAS</label>
        <textarea name="notas" id="bf-notas" class="form-input" rows="2"></textarea>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
        <button type="button" onclick="closeModal('bono-form-modal')" class="btn btn-gh">CANCELAR</button>
        <button type="submit" class="btn btn-p">GUARDAR</button>
      </div>
    </form>
  </div>
</div>
<?php endif;?>

<!-- COMUNICACION -->
<div id="tab-COMUNICACION" class="tab-pane">
<div style="display:flex;border-bottom:2px solid <?=$CB?>;margin-bottom:14px;overflow-x:auto;background:#fff;border-radius:11px 11px 0 0;border:1px solid <?=$CB?>">
<?php foreach(['SMS','LLAMADAS','EMAILS','HISTORIAL'] as $ct):?><button class="ntab<?=$ct==='SMS'?' active':''?>" onclick="showComTab('<?=$ct?>')" data-ctab="<?=$ct?>"><?=$ct?></button><?php endforeach;?>
</div>
<div id="ctab-SMS">
<div class="grid-2" style="gap:14px">
<div class="card"><div class="card-header"><div class="card-title">◌ ENVIAR SMS</div><div class="card-sub">VÍA TWILIO</div></div><div style="padding:14px 16px">
<div class="grid-2"><div class="form-group"><label class="form-label">NOMBRE</label><input type="text" id="sms-nombre" class="form-input" placeholder="NOMBRE"></div><div class="form-group"><label class="form-label">TELÉFONO</label><input type="text" id="sms-tel" class="form-input" placeholder="(818) 555-0000"></div></div>
<div class="form-group"><label class="form-label">PLANTILLA</label><div style="display:flex;gap:4px;flex-wrap:wrap"><?php foreach([['B','BIENVENIDA'],['A','AEP'],['C','CUMPLEAÑOS'],['T','T65'],['D','DENTAL'],['R','REFERIDO']] as [$k,$v]):?><button class="btn btn-gh btn-sm" onclick="setSmsTemplate('<?=$k?>')"><?=$v?></button><?php endforeach;?></div></div>

<div class="form-group"><label class="form-label">MENSAJE</label><textarea id="sms-msg" class="form-input" rows="4" oninput="updateSmsCount()"></textarea></div>
<div style="display:flex;justify-content:space-between;align-items:center"><span id="sms-count" style="font-size:8px;color:<?=$MU?>;text-transform:uppercase">0/160</span><button class="btn btn-b btn-sm" onclick="sendSms()">◌ ENVIAR SMS</button></div>
</div></div>
<div class="card"><div class="card-header"><div class="card-title"> PLANTILLAS SMS</div></div><div style="padding:11px 14px">
<?php foreach([['BIENVENIDA','HOLA [NOMBRE]! BIENVENIDO/A A MEDICARE WITH ISABEL. COBERTURA ACTIVA. (818) 000-0000 REPLY STOP.'],['AEP','HOLA [NOMBRE]! AEP OCT 15-DIC 7. PLAN GRATIS. (818) 000-0000 REPLY STOP.'],['T65','HOLA [NOMBRE]! SE ACERCA SU CUMPLEAÑOS 65. (818) 000-0000 REPLY STOP.'],['DENTAL','HOLA [NOMBRE]! RECUERDE SU BENEFICIO DENTAL. (818) 000-0000 REPLY STOP.'],['OTC','HOLA [NOMBRE]! BENEFICIO OTC PARA PRODUCTOS DE SALUD. (818) 000-0000'],['REFERIDO','HOLA [NOMBRE]! ¿CONOCE ALGUIEN QUE NECESITE MEDICARE? (818) 000-0000']] as [$n,$t]):?><div style="border:1px solid <?=$CB?>;border-radius:9px;padding:8px 11px;margin-bottom:7px"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px"><span style="font-size:9px;font-weight:900;color:<?=$P1?>;text-transform:uppercase"><?=$n?></span><button class="btn btn-sky btn-sm" onclick="copyText(this)" data-text="<?=htmlspecialchars($t,ENT_QUOTES)?>"> </button></div><div style="font-size:8px;color:<?=$MU?>;line-height:1.7"><?=h($t)?></div></div><?php endforeach;?>
</div></div>
</div>
</div>
<div id="ctab-LLAMADAS" style="display:none">
<?php if($admin):?><div class="card" style="margin-bottom:14px"><div class="card-header"><div class="card-title">▦ REPORTES HOY</div></div><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0"><?php foreach($agents as $ag):$r=null;foreach($reportes_hoy as $rr){if($rr['agente_id']==$ag['id']){$r=$rr;break;}};?><div style="padding:12px 15px;border-bottom:1px solid <?=$CB?>;border-right:1px solid <?=$CB?>;border-top:3px solid <?=$r&&$r['enviado']?'#1E7A5C':'#C07A1A'?>"><div style="display:flex;gap:7px;align-items:center;margin-bottom:8px"><?=av(h($ag['iniciales']),h($ag['color']),28)?><div><div style="font-weight:900;font-size:10px;color:<?=$P1?>"><?=h(explode(' ',$ag['nombre'])[0])?></div><?=badge($r&&$r['enviado']?'FIRMADO':'PENDIENTE',true)?></div></div><?php if($r):?><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:5px"><?php foreach([['◌',($r['llamadas_prospectos']??0)+($r['llamadas_servicio']??0),'LLAM.'],['✅',$r['contestaron']??0,'CONTS.'],['◎',$r['apps_enviadas']??0,'APPS'],['◷',$r['citas_confirmadas']??0,'CITAS'],['⊘',$r['tickets_resueltos']??0,'TKT.'],['📋',$r['apps_por_hacer']??0,'X HACER']] as [$ic,$v,$lb]):?><div style="text-align:center;background:<?=$BG?>;border-radius:7px;padding:5px"><div style="font-size:7px;color:<?=$MU?>;text-transform:uppercase"><?=$ic?> <?=$lb?></div><div style="font-size:14px;font-weight:900;color:<?=$P1?>"><?=$v?></div></div><?php endforeach;?></div><?php else:?><div style="font-size:8px;color:#B83232;font-weight:700;text-transform:uppercase">⚠ NO ENVIADO</div><?php endif;?><?php $ck=$checklist_stats[$ag['id']]??null;$ck_total=$ck['total']??0;$ck_done=(int)($ck['completadas']??0);$ck_pct=$ck_total>0?round(($ck_done/$ck_total)*100):0;?><?php if($ck_total>0):?><div style="margin-top:8px;padding-top:8px;border-top:1px solid <?=$CB?>"><div style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;margin-bottom:4px">✅ CHECKLIST <?=$ck_done?>/<?=$ck_total?> (<?=$ck_pct?>%)</div><div style="height:5px;background:<?=$CB?>;border-radius:99px;overflow:hidden"><div style="height:100%;width:<?=$ck_pct?>%;background:<?=$ck_pct==100?'#16A34A':'#2876A8'?>;border-radius:99px"></div></div></div><?php endif;?></div><?php endforeach;?></div></div><?php endif;?>
<div class="card"><div class="card-header"><div class="card-title"> LLAMADAS PERDIDAS</div><div class="card-sub"><?=$pending_llam?> PENDIENTES</div><button class="btn btn-b btn-sm" onclick="openModal('llamada-form-modal')">+ REGISTRAR</button></div><div style="overflow-x:auto"><table><tr><th>NÚMERO</th><th>NOMBRE</th><th>FECHA</th><th>ORIGEN</th><th>EMPLEADO</th><th>ESTADO</th><th></th></tr>
<?php foreach($llamadas as $l):?><tr><td style="font-weight:900;color:<?=$P2?>;font-size:10px"><?=h($l['numero'])?></td><td style="font-size:9px"><?=h($l['nombre_posible'])?></td><td style="font-size:8px;color:<?=$MU?>"><?=$l['fecha']?> <?=$l['hora']?substr($l['hora'],0,5):''?></td><td><span style="background:#EBF5FB;color:#1B5E8C;border:1px solid #A9D0E8;border-radius:20px;padding:2px 8px;font-size:8px;font-weight:900"><?=h($l['origen'])?></span></td><td><?=av(h($l['iniciales']??'?'),h($l['color']??$P2),20)?></td><td><?=badge($l['estado'],true)?></td><td><div style="display:flex;gap:4px"><?php if($l['estado']==='PENDIENTE'):?><button class="btn btn-gr btn-sm" onclick="devolverLlamada(<?=$l['id']?>)">✓</button><?php endif;?><button class="btn btn-bl btn-sm" onclick="searchMember('<?=addslashes($l['numero'])?>')">◉</button></div></td></tr><?php endforeach;?>
</table></div></div>
</div>
<div id="ctab-EMAILS" style="display:none"><div class="card" style="max-width:580px"><div class="card-header"><div class="card-title">✉ PREPARAR EMAIL</div></div><div style="padding:14px 16px"><div class="grid-2"><div class="form-group"><label class="form-label">PARA</label><input type="email" id="email-to" class="form-input" placeholder="correo@ejemplo.com" style="text-transform:none"></div><div class="form-group"><label class="form-label">NOMBRE</label><input type="text" id="email-nombre" class="form-input" placeholder="NOMBRE APELLIDO"></div></div><div class="form-group"><label class="form-label">PLANTILLA</label><div style="display:flex;gap:5px;flex-wrap:wrap"><?php foreach([['bv','BIENVENIDA'],['aep','AEP'],['fup','FOLLOW-UP'],['chk','CAMBIO DOC.']] as [$k,$v]):?><button class="btn btn-gh btn-sm" onclick="setEmailTemplate('<?=$k?>')"><?=$v?></button><?php endforeach;?></div></div><div class="form-group"><label class="form-label">ASUNTO</label><input type="text" id="email-asunto" class="form-input" placeholder="ASUNTO" style="text-transform:none"></div><div class="form-group"><label class="form-label">MENSAJE</label><textarea id="email-msg" class="form-input" rows="6" style="text-transform:none;line-height:1.7"></textarea></div><button class="btn btn-p btn-sm" onclick="sendEmail()">✉ ABRIR EN CORREO</button></div></div></div>
<div id="ctab-HISTORIAL" style="display:none">
<div style="display:flex;gap:8px;margin-bottom:11px;flex-wrap:wrap;align-items:center">
<select id="hist-ag" onchange="filterHist()" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase"><option value="">TODOS LOS EMPLEADOS</option><?php foreach($users_all as $u):?><option value="<?=$u['id']?>"><?=h(explode(' ',$u['nombre'])[0])?></option><?php endforeach;?></select>
<select id="hist-tipo" onchange="filterHist()" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase"><option value="">TODOS LOS TIPOS</option><?php foreach(['NOTA','TICKET','LLAMADA','CHECK-IN','CHECK-OUT','EFECTIVO'] as $t):?><option><?=$t?></option><?php endforeach;?></select>
</div>
<div class="card"><div style="overflow-x:auto"><table id="hist-table"><tr><th>FECHA/HORA</th><th>EMPLEADO</th><th>TIPO</th><th>MIEMBRO</th><th>DESCRIPCIÓN</th></tr>
<?php foreach($actividad as $a):?><tr class="hist-row" data-ag="<?=$a['agente_id']?>" data-tipo="<?=strtoupper($a['tipo'])?>"><td style="font-size:8px;color:<?=$MU?>;white-space:nowrap"><?=$a['fecha_hora']?></td><td><div style="display:flex;gap:5px;align-items:center"><?=av(h($a['iniciales']??'?'),h($a['color']??$P2),20)?><span style="font-size:8px;font-weight:900;color:<?=$P1?>"><?=h(explode(' ',$a['nombre']??'')[0])?></span></div></td><td><span style="background:#EBF5FB;color:#1B5E8C;border:1px solid #A9D0E8;border-radius:20px;padding:2px 8px;font-size:8px;font-weight:900"><?=h($a['tipo'])?></span></td><td style="font-size:8px;color:<?=$MU?>"><?=$a['miembro_nombre']?h(substr($a['miembro_nombre'],0,25)):'—'?></td><td style="font-size:9px"><?=h(substr($a['descripcion']??'',0,60))?></td></tr><?php endforeach;?>
</table></div></div>
</div>
</div><!-- /COMUNICACION -->
<!-- RECURSOS -->
<div id="tab-RECURSOS" class="tab-pane">
<div style="display:flex;border-bottom:2px solid <?=$CB?>;margin-bottom:14px;overflow-x:auto;background:#fff;border-radius:11px 11px 0 0;border:1px solid <?=$CB?>">
<?php foreach(['SCRIPTS','PLANTILLAS SMS','PROMPTS IA','SECUENCIAS','CARRIERS','PORTALES','SOPs'] as $rt):?><button class="ntab<?=$rt==='SCRIPTS'?' active':''?>" onclick="showRecTab('<?=$rt?>')" data-rtab="<?=$rt?>"><?=$rt?></button><?php endforeach;?>
</div>
<div id="rtab-SCRIPTS">
<div style="display:flex;border-bottom:2px solid <?=$CB?>;margin-bottom:14px;overflow-x:auto">
<?php foreach(['RETENCIÓN','AEP','PROSPECTOS','GUIONES','OBJECIONES','COMPLIANCE'] as $st):?><button class="ntab<?=$st==='RETENCIÓN'?' active':''?>" onclick="showScriptTab('<?=$st?>')" data-stab="<?=$st?>"><?=$st?></button><?php endforeach;?>
</div>
<?php $sc=['RETENCIÓN'=>[['BIENVENIDA','7 DÍAS',"Hola [Nombre], le llama [AGENTE] de Medicare with Isabel.\n\nSu plan está activo desde [FECHA EFECTIVA].\n¿Ya tiene su tarjeta de [CARRIER]? ¿Escogió su PCP?\n\nDudas: (818) 000-0000\n\nRESULTADO: [ ] COMPLETADA [ ] NO CONTESTÓ [ ] BUZÓN"],['30 DÍAS','MES 1',"Seguimiento 30 días — ¿cómo le ha ido con [CARRIER]?\n¿Visitó su doctor? ¿Algún problema con beneficios?\n\nSI HAY PROBLEMA → Crear ticket.\n\nRESULTADO: [ ] COMPLETADA [ ] NO CONTESTÓ [ ] BUZÓN"],['60 DÍAS','MES 2',"Ya llevan 2 meses con [CARRIER].\n¿Ha ido al dentista? ¿Conoce su beneficio OTC?\n\nRESULTADO: [ ] COMPLETADA [ ] NO CONTESTÓ [ ] BUZÓN"],['90 DÍAS','MES 3',"3 meses con [CARRIER]. ¿Está satisfecho/a?\n¿Alguien en su familia necesita Medicare?\n\nEn octubre abre la temporada AEP.\n\nRESULTADO: [ ] COMPLETADA [ ] NO CONTESTÓ [ ] BUZÓN"]],'AEP'=>[['REVISIÓN AEP','OCT 15 – DIC 7',"Hola [Nombre], ya abrió la temporada AEP — oct 15 a dic 7.\n\n¿Ha tenido problemas con [CARRIER]? ¿Sus medicamentos siguen cubiertos?\n\nHagamos una revisión GRATUITA. ¿Le viene bien una cita?\n\nRESULTADO: [ ] CITA [ ] RENOVÓ [ ] CAMBIO [ ] NO CONTESTÓ"]],'PROSPECTOS'=>[['PRIMER CONTACTO','PRIMERA LLAMADA',"Hola [Nombre], le llama [AGENTE] de Medicare with Isabel.\n\n¿Está interesado/a en Medicare? ¿Cuándo cumple 65?\n\nLe ayudamos a encontrar el mejor plan SIN COSTO.\n→ Preguntar: médico, medicamentos, área\n→ Agendar cita si hay interés\n\nRESULTADO: [ ] CITA [ ] LLAMAR LUEGO [ ] NO INTERESADO"]]];
foreach($sc as $stab=>$items):?>
<div class="script-tab-content" id="stab-<?=$stab?>" style="<?=$stab!=='RETENCIÓN'?'display:none':''?>">
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:11px">
<?php foreach($items as [$titulo,$cuando,$texto]):?><div class="script-card" onclick="this.classList.toggle('open');this.querySelector('.script-body').style.display=this.classList.contains('open')?'block':'none'"><div class="script-header"><div><div style="font-size:9px;font-weight:900;color:<?=$P1?>;letter-spacing:1.5px;text-transform:uppercase" class="sc-title"><?=h($titulo)?></div><div style="font-size:8px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase" class="sc-cuando"><?=h($cuando)?></div></div><span class="sc-arrow" style="font-size:14px;color:<?=$P2?>">▼</span></div><div class="script-body"><div class="script-pre"><?=h($texto)?></div><button class="btn btn-sky btn-sm" onclick="event.stopPropagation();copyText(this)" data-text="<?=htmlspecialchars($texto,ENT_QUOTES)?>"> COPIAR</button></div></div><?php endforeach;?>
</div>
</div>
<?php endforeach;?>
<?php
$sc2 = [
 'GUIONES'=>[
   ['PRIMERA LLAMADA','LEAD NUEVO · SLA 60 MIN',"Hola [Nombre], le llama [AGENTE] de Medicare with Isabel.\n\nVi que solicitó información sobre sus beneficios de Medicare. ¿Tiene un minutito?\n\n→ Confirmar elegibilidad: ¿Ya tiene Medicare Parte A y B? ¿Cuándo cumple 65?\n→ Calificar: ¿Tiene médico de preferencia? ¿Toma medicamentos?\n→ Agendar: Le agendo una cita SIN COSTO con Isabel para revisar sus opciones.\n\nRESULTADO: [ ] CITA [ ] LLAMAR LUEGO [ ] NO CALIFICA"],
   ['APERTURA — LLAMADA PERFECTA','PRIMEROS 30 SEGUNDOS',"Los primeros 30 segundos deciden todo.\n\n1. Salude por su nombre y preséntese con la agencia.\n2. Diga el motivo en una sola frase clara.\n3. Pida permiso: ¿Tiene un minuto?\n4. Haga UNA pregunta de calificación.\n\nHable despacio, sonría (se escucha) y escuche más de lo que habla."],
   ['CALIFICACIÓN — 3 PREGUNTAS','ANTES DE AGENDAR',"3 preguntas clave (sin mencionar carriers):\n\n1. ¿Tiene Medicare Parte A y B activas?\n2. ¿Tiene un médico o clínica de preferencia?\n3. ¿Toma medicamentos de receta regularmente?\n\nSi califica → agendar cita con Isabel.\nSi no → registrar para seguimiento futuro (T65 / AEP)."],
   ['CIERRE — AGENDAR CON ISABEL','FIN DE LLAMADA',"[Nombre], lo mejor es que Isabel revise sus opciones con calma y sin compromiso.\n\nTengo disponibilidad [día] a las [hora] o [día] a las [hora]. ¿Cuál le viene mejor?\n\n→ Confirmar teléfono y mejor horario.\n→ Crear la cita en el CRM con TODA la info.\n→ Enviar SOA si aplica (mínimo 48h antes)."],
   ['SEGUIMIENTO — NO CONTESTÓ','PROTOCOLO',"Si no contesta:\n\n1. Dejar buzón corto y amable (nombre, agencia, devolver llamada).\n2. Enviar SMS de seguimiento.\n3. Reintentar en días y horarios DIFERENTES (mañana / tarde).\n4. Máximo 3 intentos antes de marcar para seguimiento largo.\n\nDocumentar CADA intento en el CRM."],
   ['LLAMAR A SENIOR CENTER','ALIANZAS / EVENTOS',"Hola, habla [AGENTE] de Medicare with Isabel, una agencia local aquí en Los Ángeles.\n\nOfrecemos talleres educativos GRATUITOS sobre Medicare — solo educación, sin venta.\n\n¿Con quién puedo coordinar para agendar una charla?\n\n→ Anotar contacto, fecha tentativa y número de asistentes."],
   ['DAY 30 — GOOGLE REVIEW','RETENCIÓN',"[Nombre], me alegra que todo va bien con su plan.\n\nUna última cosa: nos ayudaría muchísimo una reseña en Google contando su experiencia. Le envío el link por mensaje, toma 1 minuto.\n\n→ Enviar link de inmediato.\n→ Nunca presionar; si duda, agradecer igual."],
 ],
 'OBJECIONES'=>[
   ['YA TENGO MEDICARE','OBJECIÓN #1',"¡Qué bueno que ya tiene Medicare! Justo por eso le llamo.\n\nMuchas personas no saben que pueden tener beneficios adicionales sin costo extra, como visión, dental o transporte.\n\nUna revisión gratuita no le compromete a nada. ¿Le gustaría que Isabel le eche un vistazo?"],
   ['NO TENGO TIEMPO','OBJECIÓN #2',"Entiendo perfectamente, [Nombre]. Por eso lo hacemos fácil.\n\nSon solo 15 minutos y puede ser por teléfono, cuando a usted le quede bien.\n\n¿Le viene mejor en la mañana o en la tarde?"],
   ['MI HIJO ME AYUDA CON ESO','OBJECIÓN #3',"¡Me parece excelente que tenga ese apoyo!\n\nPodemos incluir a su hijo o hija en la llamada para que ambos tengan la información. Así nadie se pierde de nada.\n\n¿Qué día podrían los dos?"],
   ['NO CONFÍO EN LOS SEGUROS','OBJECIÓN #4',"La entiendo, y por eso trabajamos diferente.\n\nNo le vendemos nada por teléfono. Isabel solo le explica sus opciones, usted decide con calma y todo es sin costo.\n\nSomos una agencia local aquí en Los Ángeles, con clientes que con gusto la recomiendan."],
   ['LLAMÉ PERO NO CONTESTÓ','OBJECIÓN #5 / SEGUIMIENTO',"Le ofrezco una disculpa por no haber podido atenderle antes.\n\nEstoy aquí ahora y con gusto le ayudo. ¿Tiene unos minutos o prefiere que le agende una hora específica?\n\n→ Asegurar que el próximo contacto quede agendado."],
   ['¿CUÁL PLAN ES MEJOR?','REDIRIGIR (COMPLIANCE)',"NO comparar carriers ni planes por teléfono (regla CMS).\n\nEsa es justo la conversación que Isabel tiene con usted en la cita: revisa SU situación, sus médicos y sus medicinas, y le muestra las opciones que le sirven.\n\n¿Le agendo esa revisión?"],
 ],
 'COMPLIANCE'=>[
   ['LAS 5 REGLAS DE ORO','CMS — CRÍTICO',"• NUNCA mencionar nombres de carriers a prospectos.\n• NUNCA comparar planes específicos.\n• NUNCA garantizar beneficios ni costos.\n• SIEMPRE contactar lead nuevo en menos de 60 minutos.\n• Quejas de miembros → INMEDIATAMENTE a Isabel.\n\nViolar compliance puede costar la licencia de la agencia."],
   ['QUÉ NUNCA DECIR','PROHIBIDO',"NO decir:\n✗ Este plan es el mejor.\n✗ Tal carrier es mejor que tal otro.\n✗ Le garantizo que no va a pagar nada.\n✗ Cámbiese hoy mismo.\n\nEn su lugar: hable de TIPOS de beneficios en general e invite a una cita con Isabel."],
   ['HABLAR DE BENEFICIOS SIN CARRIERS','CÓMO SÍ',"Puede mencionar TIPOS de beneficios sin nombrar planes:\n\n✓ Algunos planes incluyen dental, visión o transporte.\n✓ Hay opciones con beneficio para productos de farmacia (OTC).\n\nLuego: En la cita, Isabel revisa cuáles aplican a su caso."],
   ['SI PREGUNTAN POR UN PLAN','REDIRIGIR',"Si el prospecto pide un plan específico o una comparación:\n\nEntiendo que quiera comparar. Eso lo hace Isabel en la cita, según SU situación; es la forma correcta y la que exige Medicare.\n\nNunca dé la comparación por teléfono ni en redes."],
   ['REDES SOCIALES — ANTES DE PUBLICAR','MARKETING',"Antes de publicar CUALQUIER contenido:\n\n1. ¿Menciona algún carrier? → QUITAR.\n2. ¿Compara o garantiza algo? → QUITAR.\n3. ¿Habla de beneficios en general y educa? → OK.\n4. ¿Incluye 'REPLY STOP' / disclaimer en SMS? → OK.\n\nDuda = NO publicar. Consultar con Isabel."],
   ['SOA — SCOPE OF APPOINTMENT','ANTES DE LA CITA',"CMS exige SOA firmado MÍNIMO 48h antes de la cita de venta.\n\n1. Enviar SOA electrónico al agendar.\n2. Esperar la firma (48h).\n3. Archivar en el Drive del miembro (guardar 10 años).\n\nSin SOA firmado → NO se realiza la cita de plan."],
 ],
];
foreach($sc2 as $stab=>$items):?>
<div class="script-tab-content" id="stab-<?=$stab?>" style="display:none">
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:11px">
<?php foreach($items as [$titulo,$cuando,$texto]):?><div class="script-card" onclick="this.classList.toggle('open');this.querySelector('.script-body').style.display=this.classList.contains('open')?'block':'none'"><div class="script-header"><div><div style="font-size:9px;font-weight:900;color:<?=$P1?>;letter-spacing:1.5px;text-transform:uppercase" class="sc-title"><?=h($titulo)?></div><div style="font-size:8px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase" class="sc-cuando"><?=h($cuando)?></div></div><span class="sc-arrow" style="font-size:14px;color:<?=$P2?>">▼</span></div><div class="script-body"><div class="script-pre"><?=h($texto)?></div><button class="btn btn-sky btn-sm" onclick="event.stopPropagation();copyText(this)" data-text="<?=htmlspecialchars($texto,ENT_QUOTES)?>"> COPIAR</button></div></div><?php endforeach;?>
</div>
</div>
<?php endforeach;?>

</div>
<div id="rtab-PLANTILLAS SMS" style="display:none"><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:11px">
<?php foreach([['BIENVENIDA','HOLA [NOMBRE]! BIENVENIDO/A A MEDICARE WITH ISABEL. COBERTURA ACTIVA. (818) 000-0000 REPLY STOP.'],['AEP','HOLA [NOMBRE]! AEP OCT 15-DIC 7. REVISEMOS SU PLAN GRATIS. (818) 000-0000 REPLY STOP.'],['CUMPLEAÑOS','FELIZ CUMPLEAÑOS [NOMBRE]! DE PARTE DE MEDICARE WITH ISABEL.'],['T65','HOLA [NOMBRE]! SE ACERCA SU CUMPLEAÑOS 65. (818) 000-0000 REPLY STOP.'],['DENTAL','HOLA [NOMBRE]! RECUERDE SU BENEFICIO DENTAL INCLUIDO. (818) 000-0000 REPLY STOP.'],['OTC','HOLA [NOMBRE]! TIENE BENEFICIO OTC PARA PRODUCTOS DE SALUD. (818) 000-0000'],['REFERIDO','HOLA [NOMBRE]! ¿CONOCE ALGUIEN QUE NECESITE MEDICARE? REFERIR ES GRATIS. (818) 000-0000'],['CITA','HOLA [NOMBRE]! SU CITA CON [DOCTOR] EL [FECHA] A LAS [HORA]. PREGUNTAS: (818) 000-0000']] as [$n,$t]):?><div style="background:#fff;border:1px solid <?=$CB?>;border-radius:11px;padding:13px 15px"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:7px"><span style="font-weight:900;font-size:9px;color:<?=$P1?>;text-transform:uppercase"><?=$n?></span><button class="btn btn-sky btn-sm" onclick="copyText(this)" data-text="<?=htmlspecialchars($t,ENT_QUOTES)?>"> </button></div><div style="font-size:8px;color:<?=$MU?>;line-height:1.7;background:<?=$BG?>;border-radius:7px;padding:7px 9px"><?=h($t)?></div></div><?php endforeach;?>
</div></div>
<div id="rtab-CARRIERS" style="display:none">
<?php
$carriers_info=[
  ['SCAN','#1B4A6B','(800)559-3500','producer.scanhealthplan.com','CA','Medicare Advantage HMO · SNP','Primarily LA/OC/San Bernardino counties. Strong DSNP program. Producer services via Availity.'],
  ['ANTHEM / BLUE CROSS','#2876A8','(888)254-2764','anthem.com/ca/producer','CA/NV','MA HMO · PPO · MAPD · Supplement','Broad network. MAPD and supplement options. Anthem Producer Portal for applications.'],
  ['HUMANA','#C07A1A','(800)448-6262','humana.com/producer','National','MA HMO · PPO · MAPD · PDP · Supplement','Strong PDP and supplement lines. Online quoting via Humana Agent Portal (HAP).'],
  ['ALIGNMENT HEALTH','#1E7A5C','(855)265-7217','alignmenthealthcare.com/agents','CA','MA HMO · SNP','Value-based care model. Strong in Southern California. Agent portal via EZCap.'],
  ['LA CARE','#7B2D8B','(213)438-5700','lacare.org/providers/medicare','LA County','MA HMO · DSNP','Los Angeles County only. Strong dual-eligible (Medi-Medi) plan. Agent certification required.'],
  ['HEALTH NET','#B83232','(800)641-7761','healthnet.com/agentsandbrokers','CA','MA HMO · MAPD · PDP','Part of Centene. Good in Northern/Central CA. Producer portal via Availity.'],
  ['MOLINA HEALTHCARE','#C05C1A','(888)858-2150','molinahealthcare.com/providers','Multi-state','MA HMO · SNP · DSNP','Strong DSNP for dual-eligibles. Medicaid crossover opportunities. Online enrollment.'],
  ['UNITED HEALTHCARE','#1B5E8C','(877)842-3210','uhcprovider.com','National','MA HMO · PPO · MAPD · PDP · Supplement','Largest MA carrier nationally. Very strong PPO network. UHC Producer portal.'],
  ['KAISER PERMANENTE','#1E7A5C','(800)491-3665','kp.org/agents','CA (select)','MA HMO','Integrated delivery system. No out-of-network coverage. Strong in Bay Area, LA, San Diego.'],
  ['WELLCARE','#5B3FAF','(877)935-5226','wellcare.com/agents','Multi-state','MA HMO · PDP · SNP','Part of Centene. Strong PDP portfolio. Good SNP options. WellCare agent portal.'],
];
?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:11px">
<?php foreach($carriers_info as [$cname,$ccolor,$ctel,$cweb,$cstate,$cplans,$cnote]):?>
<div style="background:#fff;border:1px solid <?=$CB?>;border-left:4px solid <?=$ccolor?>;border-radius:11px;padding:13px 15px">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px">
    <div style="font-size:11px;font-weight:900;color:<?=$ccolor?>;text-transform:uppercase"><?=h($cname)?></div>
    <span style="background:<?=$BG?>;color:<?=$MU?>;border:1px solid <?=$CB?>;border-radius:20px;padding:1px 7px;font-size:7px;font-weight:700"><?=h($cstate)?></span>
  </div>
  <div style="font-size:8px;font-weight:700;color:<?=$TX?>;margin-bottom:4px;text-transform:uppercase"><?=h($cplans)?></div>
  <div style="font-size:8px;color:<?=$MU?>;line-height:1.5;margin-bottom:8px"><?=h($cnote)?></div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <a href="tel:<?=h($ctel)?>" style="font-size:8px;font-weight:800;color:<?=$ccolor?>;text-decoration:none">📞 <?=h($ctel)?></a>
    <span style="font-size:8px;color:<?=$P2?>;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"> <?=h($cweb)?></span>
    <button class="btn btn-sky btn-sm" style="margin-left:auto" onclick="copyText(this)" data-text="<?=htmlspecialchars($ctel,ENT_QUOTES)?>"><?=h($ctel)?></button>
  </div>
</div>
<?php endforeach;?>
</div>
</div>
<div id="rtab-PORTALES" style="display:none"><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:11px">
<div class="card" style="border-top:3px solid <?=$P1?>"><div class="card-header"><div class="card-title"> CARRIERS</div></div><div style="padding:11px"><?php foreach([['SCAN','(800)559-3500','provider.scanhealthplan.com'],['ANTHEM','(888)254-2764','anthem.com/ca/provider'],['HUMANA','(800)448-6262','humana.com/provider'],['ALIGNMENT','(855)265-7217','alignmenthealthcare.com'],['LA CARE','(213)438-5700','lacare.org/provider'],['HEALTH NET','(800)641-7761','healthnet.com/provider'],['MOLINA','(888)858-2150','molinahealthcare.com'],['UNITED HEALTHCARE','(877)842-3210','uhcprovider.com']] as [$c,$tel,$web]):?><div class="portal-card"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px"><span style="font-weight:900;font-size:9px;color:<?=$P1?>"><?=$c?></span><span style="font-size:9px;font-weight:800;color:<?=$P2?>"><?=$tel?></span></div><div style="font-size:8px;color:<?=$P2?>"> <?=$web?></div></div><?php endforeach;?></div></div>
<div class="card" style="border-top:3px solid #1E7A8C"><div class="card-header"><div class="card-title"> DENTALES</div></div><div style="padding:11px"><?php foreach([['DENTALQUEST (SCAN)','(800)544-0718'],['LIBERTY DENTAL (ANTHEM/LA CARE)','(888)352-7924'],['HUMANA DENTAL','(800)233-4013'],['MOLINA DENTAL','(888)858-2150'],['HEALTH NET DENTAL','(800)641-7761']] as [$d,$tel]):?><div class="portal-card" style="border-left-color:#1E7A8C"><div style="font-weight:900;font-size:9px;color:<?=$P1?>;margin-bottom:3px"><?=$d?></div><div style="font-size:9px;font-weight:800;color:#1E7A8C"><?=$tel?></div></div><?php endforeach;?></div></div>
<div class="card" style="border-top:3px solid #C07A1A"><div class="card-header"><div class="card-title"> GOBIERNO</div></div><div style="padding:11px"><?php foreach([['CMS / MEDICARE.GOV','(800)633-4227','medicare.gov'],['MY MEDICARE','','mymedicare.gov'],['SOCIAL SECURITY','(800)772-1213','ssa.gov'],['MEDI-CAL / DHCS','(916)440-7400','dhcs.ca.gov'],['COVERED CA','(800)300-1506','coveredca.com'],['AHIP CERTIFICATION','','ahip.org']] as [$d,$tel,$web]):?><div class="portal-card" style="border-left-color:#C07A1A"><div style="font-weight:900;font-size:9px;color:<?=$P1?>;margin-bottom:3px"><?=$d?></div><div style="display:flex;gap:9px;font-size:8px"><?php if($tel):?><span style="color:#C07A1A;font-weight:800"><?=$tel?></span><?php endif;?><span style="color:<?=$P2?>"> <?=$web?></span></div></div><?php endforeach;?></div></div>
</div></div>
<div id="rtab-SOPs" style="display:none"><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:11px">
<?php foreach([['CHECK-IN DIARIO',"1. CHECK-IN al llegar (antes 9 AM)\n2. SALIDA ALMUERZO (30-60 min)\n3. REGRESO ALMUERZO\n4. BREAK adicional (10-15 min)\n5. CHECK-OUT al terminar\n6. REPORTE DIARIO antes del CHECK-OUT"],['LLAMADAS SALIENTES',"1. Revisar lista del día\n2. Tener perfil abierto antes de llamar\n3. Usar el script correspondiente\n4. Documentar en CRM inmediatamente\n5. Crear ticket si hay problema"],['TWILIO / NEXTIVA',"ENTRANTE:\n1. Contestar máx 3 timbres\n2. \"Medicare with Isabel, habla [NOMBRE]\"\n3. Buscar en CRM\n4. Documentar como ticket\n\nPERDIDA:\n1. Registrar en LLAMADAS PERDIDAS\n2. Devolver dentro de 2 horas"],['TICKETS',"1. Crear ticket AL MOMENTO del problema\n2. Categorizar correctamente\n3. Prioridad: Alta/Media/Baja\n4. Fecha seguimiento OBLIGATORIA\n5. Actualizar DIARIAMENTE\n6. Cerrar SOLO cuando resuelto 100%"],['SOA - CMS',"CMS REQUIERE:\n- SOA firmado MÍNIMO 48h antes de cita\n- Guardar MÍNIMO 10 años\n- Incluir: nombre, DOB, plan, fecha, firma\n\n1. Enviar SOA electrónico\n2. Esperar 48h\n3. Archivar en Drive del miembro"],['LLAMADAS 30/60/90',"OBLIGATORIO TODOS LOS MIEMBROS NUEVOS:\n- BIENVENIDA: primeros 7 días\n- 30 DÍAS: mes de efectividad\n- 60 DÍAS: 2 meses\n- 90 DÍAS: 3 meses\n\nSi no contesta: 3 intentos en días diferentes."]] as [$titulo,$texto]):?><div class="card"><div class="card-header"><div class="card-title"><?=h($titulo)?></div></div><div style="padding:13px 16px"><div style="font-size:9px;color:<?=$TX?>;line-height:1.9;white-space:pre-wrap"><?=h($texto)?></div><button class="btn btn-sky btn-sm" style="margin-top:10px" onclick="copyText(this)" data-text="<?=htmlspecialchars($texto,ENT_QUOTES)?>"> COPIAR</button></div></div><?php endforeach;?>
</div></div>
<div id="rtab-PROMPTS IA" style="display:none">
<?php
$PROMPTS_IA=[
 ['VENTAS','PRIMERA LLAMADA A LEAD NUEVO',"Escribe un guión de primera llamada en español (máx 45 segundos) para un prospecto de Medicare que acaba de dejar sus datos. Preséntate como agente de Medicare with Isabel, califica con 3 preguntas (Parte A y B, médico de preferencia, medicamentos) e invita a una cita GRATUITA con Isabel. Cumple compliance CMS: sin nombrar carriers, sin comparar planes, sin garantizar costos."],
 ['VENTAS','MANEJAR: YA TENGO MEDICARE',"Dame 3 respuestas cortas y empáticas en español para un prospecto que dice \"ya tengo Medicare\", orientadas a ofrecer una revisión gratuita de beneficios adicionales, sin mencionar carriers ni comparar planes."],
 ['VENTAS','SEGUIMIENTO A QUIEN NO CONTESTÓ',"Redacta un SMS y un mensaje de voz breve en español para un prospecto que no contestó: amable, sin presión, invitando a devolver la llamada. Incluye opción de horario."],
 ['RETENCIÓN','LLAMADA DAY 30 + GOOGLE REVIEW',"Escribe un guión de llamada Day 30 en español para un miembro nuevo: confirmar satisfacción, resolver dudas y pedir de forma natural una reseña en Google, sin insistir."],
 ['RETENCIÓN','MIEMBRO QUIERE CAMBIAR DE PLAN',"Un miembro quiere cambiar de plan. Dame los pasos a seguir en español cumpliendo compliance, qué información recopilar y cómo escalar a Isabel."],
 ['MARKETING','3 POSTS COMPLIANT PARA REDES',"Escribe 3 publicaciones cortas en español para Facebook/Instagram que eduquen sobre beneficios de Medicare (dental, visión, OTC, transporte) SIN mencionar carriers, sin comparar planes y sin garantizar nada. Incluye llamada a la acción para contactar a la agencia."],
 ['MARKETING','RESPONDER MENSAJE DE FACEBOOK',"Redacta una respuesta a un mensaje de Facebook de un prospecto interesado en Medicare: califica brevemente e invita a una cita. Cumple compliance CMS."],
 ['COMPLIANCE','REVISAR COMPLIANCE DE UN TEXTO',"Revisa el siguiente texto y dime si cumple las reglas de CMS (sin carriers, sin comparaciones, sin garantías). Marca exactamente qué cambiar:\n\n[PEGAR TEXTO AQUÍ]"],
 ['OUTREACH','LLAMAR A UN SENIOR CENTER',"Escribe un guión en español para llamar a un senior center y ofrecer un taller educativo gratuito de Medicare (solo educación, sin venta). Objetivo: agendar la charla."],
 ['OUTREACH','EMAIL A CENTRO COMUNITARIO',"Redacta un email corto y profesional en español de seguimiento a un centro comunitario tras una primera conversación, proponiendo una fecha para un taller."],
 ['CRM','RESUMEN DEL DÍA PARA ISABEL',"Con estos datos del día, escribe un resumen ejecutivo de 150 palabras para Isabel: inscripciones, pipeline, tickets, alertas y 1 acción recomendada.\n\nDatos: [PEGAR AQUÍ]"],
 ['CRM','CHECKLIST DE CIERRE DEL CRM',"Dame un checklist en español de cómo actualizar correctamente el CRM al final del día: leads, citas, tickets y estados de miembros."],
];
$PI_COL=['VENTAS'=>'#1B5E8C','RETENCIÓN'=>'#1E7A5C','MARKETING'=>'#5B3FAF','COMPLIANCE'=>'#B83232','OUTREACH'=>'#C07A1A','CRM'=>'#1B4A6B'];
?>
<div style="background:#F3F0FB;border:1px solid #C2B0E8;border-radius:11px;padding:10px 15px;margin-bottom:13px;font-size:8px;color:#5B3FAF;letter-spacing:.5px;text-transform:uppercase;line-height:1.6">🤖 Copia un prompt y pégalo en tu asistente de IA. Reemplaza lo que está [ENTRE CORCHETES].</div>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:11px">
<?php foreach($PROMPTS_IA as [$cat,$titulo,$texto]): $col=$PI_COL[$cat]??$P2; ?>
<div class="card" style="border-left:4px solid <?=$col?>">
  <div class="card-header" style="padding:10px 14px">
    <div><div style="font-size:7px;font-weight:900;color:<?=$col?>;text-transform:uppercase;letter-spacing:1px"><?=$cat?></div><div class="card-title" style="font-size:9px;margin-top:2px"><?=h($titulo)?></div></div>
  </div>
  <div style="padding:11px 14px">
    <div style="font-size:9px;color:<?=$TX?>;line-height:1.7;white-space:pre-wrap;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:8px;padding:9px 11px;margin-bottom:8px"><?=h($texto)?></div>
    <button class="btn btn-sky btn-sm" onclick="copyText(this)" data-text="<?=htmlspecialchars($texto,ENT_QUOTES)?>">COPIAR PROMPT</button>
  </div>
</div>
<?php endforeach;?>
</div>
</div>
<div id="rtab-SECUENCIAS" style="display:none">
<?php
$SECUENCIAS=[
 ['LEAD NUEVO — SLA 60 MIN','#1B5E8C',['Min 0-60: Llamada #1 — script de primera llamada. Si contesta → calificar y agendar con Isabel.','Día 0 (si no contesta): SMS de presentación + dejar buzón breve.','Día 1: Llamada #2 en otra franja horaria (mañana/tarde).','Día 3: WhatsApp con beneficios generales (sin carriers) + invitación.','Día 5: Llamada #3 final + SMS amable de última oportunidad.','Cierre: agendar con Isabel o marcar para T65 / AEP en el CRM.']],
 ['RETENCIÓN — 90 DÍAS','#1E7A5C',['Day 1: Llamada de bienvenida. Confirmar datos y próximos pasos.','Day 15: ¿Llegó la tarjeta? ¿Escogió PCP? Resolver dudas.','Day 30: Satisfacción + pedir Google Review (sin insistir).','Day 60: Recordar beneficios (dental, OTC, transporte).','Day 90: Satisfacción + pedir referidos.']],
 ['PRE-AEP (SEP-OCT)','#5B3FAF',['Llamar a cada miembro activo antes de octubre.','Tranquilizar: recordar sus beneficios actuales.','Ofrecer revisión gratuita de opciones en octubre.','Agendar la cita de revisión con Isabel.','Registrar en el CRM el resultado y el seguimiento.']],
 ['POST-EVENTO (48 HORAS)','#C07A1A',['Día 0: Cargar TODOS los asistentes al CRM con la fuente del evento.','Día 1: SMS de agradecimiento + info de contacto.','Día 2: Llamada de calificación a cada asistente interesado.','Día 5: Segundo intento a quienes no contestaron.','Cierre: agendar citas con Isabel y reportar leads del evento.']],
 ['REFERIDO','#1B4A6B',['Contacto inicial mencionando quién lo refirió (genera confianza).','Calificar con las 3 preguntas clave.','Agendar cita con Isabel si hay interés.','Agradecer a la persona que refirió y registrar el referido.']],
 ['NO-SHOW / REAGENDAR','#B83232',['Mismo día: llamada + SMS para reagendar de inmediato.','Día 1: Reintento en otra franja horaria.','Día 3: Último intento + mensaje amable.','Cierre: reagendar con Isabel o marcar para seguimiento largo.']],
];
?>
<div style="background:#EBF5FB;border:1px solid #A9D0E8;border-radius:11px;padding:10px 15px;margin-bottom:13px;font-size:8px;color:#1B5E8C;letter-spacing:.5px;text-transform:uppercase;line-height:1.6">🔁 Secuencias listas para usar. Cada paso indica el canal y el momento. Documenta cada toque en el CRM.</div>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:11px">
<?php foreach($SECUENCIAS as [$titulo,$col,$pasos]): $copytext=$titulo."\n".implode("\n",array_map(fn($p,$i)=>($i+1).". ".$p,$pasos,array_keys($pasos))); ?>
<div class="card" style="border-top:3px solid <?=$col?>">
  <div class="card-header"><div class="card-title" style="font-size:10px;color:<?=$col?>">🔁 <?=h($titulo)?></div></div>
  <div style="padding:11px 14px">
    <?php foreach($pasos as $i=>$p):?>
    <div style="display:flex;gap:9px;align-items:flex-start;padding:6px 0;border-bottom:1px solid <?=$CB?>">
      <div style="width:18px;height:18px;border-radius:50%;background:<?=$col?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:900;flex-shrink:0"><?=$i+1?></div>
      <div style="font-size:9px;color:<?=$TX?>;line-height:1.5"><?=h($p)?></div>
    </div>
    <?php endforeach;?>
    <button class="btn btn-sky btn-sm" style="margin-top:9px" onclick="copyText(this)" data-text="<?=htmlspecialchars($copytext,ENT_QUOTES)?>">COPIAR SECUENCIA</button>
  </div>
</div>
<?php endforeach;?>
</div>
</div>
</div><!-- /RECURSOS -->


<!-- REPORTES (admin) -->
<?php if($admin):?>
<div id="tab-REPORTES" class="tab-pane">

<?php
// ─── REPORTE DE CAMPAÑAS (conectado a CAMPAÑAS) ──────────────────────────────
$rep_camps=[]; $rc_tot_leads=0; $rc_tot_pipe=0; $rc_tot_cost=0; $rc_by_canal=[];
$rc_canal_col=['FACEBOOK'=>['#1B5E8C','#EBF5FB'],'INSTAGRAM'=>['#5B3FAF','#F3F0FB'],'EVENTO'=>['#1E7A5C','#EAF5F0'],'REFERIDO'=>['#C07A1A','#FEF8EE'],'GOOGLE'=>['#B83232','#FDF0EE'],'OTRO'=>['#7A90A4','#F1F1F1']];
try{
  $rep_camps=$pdo->query("
    SELECT c.id,c.nombre,c.canal,c.estado,c.costo,
      COUNT(cc.id) AS n_contactos,
      SUM(CASE WHEN cc.promovido=1 THEN 1 ELSE 0 END) AS n_pipe,
      SUM(CASE WHEN cc.estado='INTERESADO' THEN 1 ELSE 0 END) AS n_interes
    FROM campanas c LEFT JOIN campana_contactos cc ON cc.campana_id=c.id
    GROUP BY c.id ORDER BY c.created_at DESC
  ")->fetchAll();
  foreach($rep_camps as $rcx){
    $rc_tot_leads+=(int)$rcx['n_contactos']; $rc_tot_pipe+=(int)$rcx['n_pipe']; $rc_tot_cost+=(float)$rcx['costo'];
    $cn=$rcx['canal']; if(!isset($rc_by_canal[$cn]))$rc_by_canal[$cn]=['leads'=>0,'pipe'=>0,'cost'=>0];
    $rc_by_canal[$cn]['leads']+=(int)$rcx['n_contactos']; $rc_by_canal[$cn]['pipe']+=(int)$rcx['n_pipe']; $rc_by_canal[$cn]['cost']+=(float)$rcx['costo'];
  }
}catch(Exception $e){}
$rc_cpl=($rc_tot_leads>0 && $rc_tot_cost>0)?$rc_tot_cost/$rc_tot_leads:0;
$rc_conv=$rc_tot_leads>0?round($rc_tot_pipe/$rc_tot_leads*100):0;
?>
<div style="margin-bottom:8px;font-size:11px;font-weight:900;color:<?=$P1?>;letter-spacing:2px;text-transform:uppercase">📣 RENDIMIENTO DE CAMPAÑAS</div>
<?php if(empty($rep_camps)):?>
<div class="card" style="padding:18px;text-align:center;font-size:9px;color:<?=$MU?>;text-transform:uppercase;margin-bottom:18px">SIN CAMPAÑAS TODAVÍA — CRÉALAS EN LA PESTAÑA CAMPAÑAS</div>
<?php else:?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-bottom:12px">
  <?php foreach([
    ['👥', $rc_tot_leads, 'TOTAL LEADS', $P2],
    ['▲', $rc_tot_pipe, 'EN PIPELINE', '#C07A1A'],
    ['📈', $rc_conv.'%', 'CONVERSIÓN', '#1E7A5C'],
    ['💰', '$'.number_format($rc_tot_cost,0), 'COSTO TOTAL', $P1],
    ['🎯', '$'.number_format($rc_cpl,2), 'COSTO / LEAD', $rc_cpl>0&&$rc_cpl<=25?'#1E7A5C':($rc_cpl>25?'#B83232':$MU)],
  ] as [$ic,$v,$lb,$c]):?>
  <div style="background:#fff;border:1px solid <?=$CB?>;border-radius:11px;padding:12px 14px;text-align:center">
    <div style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px"><?=$ic?> <?=$lb?></div>
    <div style="font-size:19px;font-weight:900;color:<?=$c?>"><?=$v?></div>
  </div>
  <?php endforeach;?>
</div>
<?php if(!empty($rc_by_canal)):?>
<div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px">
  <?php foreach($rc_by_canal as $cn=>$cd): $col=$rc_canal_col[$cn]??['#7A90A4','#F1F1F1']; $cplc=$cd['leads']>0&&$cd['cost']>0?$cd['cost']/$cd['leads']:0;?>
  <div style="background:<?=$col[1]?>;border:1px solid <?=$col[0]?>33;border-radius:10px;padding:8px 13px;min-width:130px">
    <div style="font-size:8px;font-weight:900;color:<?=$col[0]?>;text-transform:uppercase;letter-spacing:.5px"><?=h($cn)?></div>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:2px"><?=$cd['leads']?> LEADS · ▲<?=$cd['pipe']?><?php if($cplc>0):?> · $<?=number_format($cplc,2)?>/LEAD<?php endif;?></div>
  </div>
  <?php endforeach;?>
</div>
<div class="card" style="margin-bottom:18px;overflow-x:auto">
<table><thead><tr><th>CAMPAÑA</th><th>CANAL</th><th style="text-align:center">LEADS</th><th style="text-align:center">INTERES.</th><th style="text-align:center">PIPELINE</th><th style="text-align:center">CONV.</th><th style="text-align:right">$/LEAD</th></tr></thead><tbody>
<?php foreach($rep_camps as $rcx): $cv=$rcx['n_contactos']>0?round($rcx['n_pipe']/$rcx['n_contactos']*100):0; $cpl=($rcx['costo']>0&&$rcx['n_contactos']>0)?$rcx['costo']/$rcx['n_contactos']:0; $col=$rc_canal_col[$rcx['canal']]??['#7A90A4','#F1F1F1'];?>
<tr>
  <td style="font-weight:900;font-size:9px;color:<?=$P1?>"><?=h($rcx['nombre'])?></td>
  <td><span style="background:<?=$col[1]?>;color:<?=$col[0]?>;border-radius:20px;padding:1px 8px;font-size:8px;font-weight:900"><?=h($rcx['canal'])?></span></td>
  <td style="text-align:center;font-size:10px;font-weight:900"><?=$rcx['n_contactos']?></td>
  <td style="text-align:center;font-size:9px;color:#1E7A5C;font-weight:900"><?=$rcx['n_interes']?></td>
  <td style="text-align:center;font-size:10px;font-weight:900;color:#C07A1A"><?=$rcx['n_pipe']?></td>
  <td style="text-align:center;font-size:9px;font-weight:900;color:<?=$cv>=20?'#1E7A5C':($cv>0?'#C07A1A':$MU)?>"><?=$cv?>%</td>
  <td style="text-align:right;font-size:9px;font-weight:900;color:<?=$cpl>0&&$cpl<=25?'#1E7A5C':($cpl>25?'#B83232':$MU)?>"><?=$cpl>0?'$'.number_format($cpl,2):'—'?></td>
</tr>
<?php endforeach;?>
</tbody></table>
</div>
<?php endif;?>
<?php endif;?>

<?php
// Preparar datos JSON para JS
$rep_json = [];
foreach ($agents as $ag) {
    $r2  = null; foreach ($reportes_hoy as $rr) { if ($rr['agente_id']==$ag['id']) { $r2=$rr; break; } }
    $ci3 = null; foreach ($today_ckins as $c) { if ($c['agente_id']==$ag['id']) { $ci3=$c; break; } }
    $horas = calc_hours($ci3['check_in']??null,$ci3['lunch_out']??null,$ci3['lunch_in']??null,$ci3['check_out']??null,$ci3['break_out']??null,$ci3['break_in']??null);
    $agtks = count(array_filter($tickets, fn($t) => ((!empty($t['asignado_a']) ? $t['asignado_a']==$ag['id'] : $t['agente_id']==$ag['id']) && $t['estado']!=='CERRADO' && in_array($t['tipo']??'',$TIPO_MIEMBRO,true) && (empty($t['sla_fecha']) || $t['sla_fecha'] <= $today))));
    $ck    = $checklist_stats[$ag['id']] ?? null;
    $items = $checklist_por_agente[$ag['id']] ?? [];
    $rep_json[$ag['id']] = [
        'id'        => $ag['id'],
        'nombre'    => explode(' ', $ag['nombre'])[0],
        'nombre_completo' => $ag['nombre'],
        'iniciales' => $ag['iniciales'],
        'color'     => $ag['color'],
        'horas'     => $horas,
        'firmado'   => $r2 && $r2['enviado'],
        'llam'      => $r2 ? (($r2['llamadas_prospectos']??0)+($r2['llamadas_servicio']??0)) : 0,
        'apps'      => $r2 ? ($r2['apps_enviadas']??0) : 0,
        'citas'     => $r2 ? ($r2['citas_confirmadas']??0) : 0,
        'apps_por_hacer' => $r2 ? ($r2['apps_por_hacer']??0) : 0,
        'tickets_r' => $r2 ? ($r2['tickets_resueltos']??0) : 0,
        'nota'      => $r2 ? ($r2['nota']??'') : '',
        'tickets'   => $agtks,
        'ck_total'  => (int)($ck['total']??0),
        'ck_done'   => (int)($ck['completadas']??0),
        'checklist' => array_map(fn($i) => [
            'texto'      => $i['item_texto'],
            'completado' => (bool)$i['completado'],
            'hora'       => $i['completado_at'] ? substr($i['completado_at'],11,5) : null
        ], $items),
        'checkin'   => $ci3 ? (($ci3['check_in'] ?? null)  ? substr($ci3['check_in'],0,5)  : null) : null,
        'checkout'  => $ci3 ? (($ci3['check_out'] ?? null) ? substr($ci3['check_out'],0,5) : null) : null,
    ];
}
?>

<!-- STATS GLOBALES -->
<?php
$tll = 0; $tapps = 0;
try {
    $tll=$pdo->query("SELECT COALESCE(SUM(llamadas_prospectos+llamadas_servicio),0) FROM reporte_diario WHERE fecha='$today'")->fetchColumn();
    $tapps=$pdo->query("SELECT COALESCE(SUM(apps_enviadas),0) FROM reporte_diario WHERE fecha='$today'")->fetchColumn();
} catch (Exception $e) {}
$ck_global_total = array_sum(array_column($checklist_stats, 'total'));
$ck_global_done  = array_sum(array_column($checklist_stats, 'completadas'));
?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-bottom:18px">
<?php foreach([
    ['◌', $tll,              'LLAMADAS HOY',   '#2876A8'],
    ['◎', $tapps,            'APPS HOY',       '#1B4A6B'],
    ['◈', $open_tks,         'TICKETS PEND.',  '#B83232'],
    ['◉', $activos_total,    'ACTIVOS',        '#1E7A5C'],
    ['✅', $ck_global_done.'/'.$ck_global_total, 'CHECKLIST', '#C07A1A'],
] as [$ic,$v,$lb,$c]):?>
<div style="background:#fff;border:1px solid <?=$CB?>;border-radius:11px;padding:12px 14px;text-align:center">
    <div style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px"><?=$ic?> <?=$lb?></div>
    <div style="font-size:20px;font-weight:900;color:<?=$c?>"><?=$v?></div>
</div>
<?php endforeach;?>
</div>

<!-- GRID DE AGENTES -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:18px">
<?php foreach($agents as $ag):
    $d = $rep_json[$ag['id']];
    $ck_pct = $d['ck_total']>0 ? round(($d['ck_done']/$d['ck_total'])*100) : 0;
    $bar_color = $ck_pct==100 ? '#16A34A' : ($ck_pct>=50 ? '#2876A8' : '#C07A1A');
?>
<div onclick="showRepDetalle(<?=$ag['id']?>)" style="background:#fff;border:1px solid <?=$CB?>;border-radius:13px;overflow:hidden;border-top:4px solid <?=h($ag['color'])?>;cursor:pointer;transition:box-shadow .2s" onmouseover="this.style.boxShadow='0 4px 18px rgba(27,74,107,.13)'" onmouseout="this.style.boxShadow='none'">
    <!-- Header -->
    <div style="padding:11px 14px;display:flex;gap:9px;align-items:center;background:<?=$BG?>">
        <?=av(h($ag['iniciales']),h($ag['color']),38)?>
        <div style="flex:1;min-width:0">
            <div style="font-weight:900;font-size:11px;color:<?=$P1?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=h(explode(' ',$ag['nombre'])[0])?></div>
            <div style="margin-top:2px"><?=badge($d['firmado']?'FIRMADO':'PENDIENTE',true)?></div>
        </div>
        <?php if($d['horas']):?>
        <div style="text-align:right;flex-shrink:0">
            <div style="font-size:12px;font-weight:900;color:#1E7A5C"><?=h($d['horas'])?></div>
            <div style="font-size:7px;color:<?=$MU?>;text-transform:uppercase">HORAS</div>
        </div>
        <?php endif;?>
    </div>
    <!-- Métricas -->
    <div style="padding:10px 14px;display:grid;grid-template-columns:repeat(4,1fr);gap:4px;border-bottom:1px solid <?=$CB?>">
        <?php foreach([['◌',$d['llam'],'LLAM.'],['◎',$d['apps'],'APPS'],['◷',$d['citas'],'CITAS'],['◈',$d['tickets'],'TKS']] as [$ic,$v,$lb]):?>
        <div style="text-align:center">
            <div style="font-weight:900;font-size:16px;color:<?=$v>0?$P1:$MU?>"><?=$v?></div>
            <div style="font-size:7px;color:<?=$MU?>;text-transform:uppercase"><?=$lb?></div>
        </div>
        <?php endforeach;?>
    </div>
    <!-- Barra checklist -->
    <div style="padding:10px 14px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
            <div style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px">✅ CHECKLIST</div>
            <div style="font-size:8px;font-weight:900;color:<?=$bar_color?>"><?=$d['ck_done']?>/<?=$d['ck_total']?> (<?=$ck_pct?>%)</div>
        </div>
        <div style="height:6px;background:<?=$CB?>;border-radius:99px;overflow:hidden">
            <div style="height:100%;width:<?=$ck_pct?>%;background:<?=$bar_color?>;border-radius:99px;transition:width .5s ease"></div>
        </div>
        <div style="margin-top:8px;font-size:7px;color:<?=$P2?>;font-weight:900;text-align:center;text-transform:uppercase;letter-spacing:1px">VER DETALLE →</div>
    </div>
</div>
<?php endforeach;?>
</div>

<!-- PANEL DE DETALLE -->
<div id="rep-detalle-panel" style="display:none;background:#fff;border:1.5px solid <?=$CB?>;border-radius:14px;overflow:hidden;margin-bottom:18px">
    <div id="rep-detalle-header" style="padding:14px 18px;background:<?=$BG?>;border-bottom:1px solid <?=$CB?>;display:flex;align-items:center;gap:12px">
        <div id="rep-det-av" style="width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:900;color:#fff;flex-shrink:0"></div>
        <div style="flex:1">
            <div id="rep-det-nombre" style="font-size:13px;font-weight:900;color:<?=$P1?>"></div>
            <div id="rep-det-sub" style="font-size:8px;color:<?=$MU?>;text-transform:uppercase;margin-top:2px"></div>
        </div>
        <button onclick="document.getElementById('rep-detalle-panel').style.display='none'" style="background:none;border:1px solid <?=$CB?>;border-radius:8px;padding:5px 12px;font-size:8px;font-weight:900;color:<?=$MU?>;cursor:pointer;text-transform:uppercase">✕ CERRAR</button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0">
        <!-- Columna izquierda: Métricas + Nota -->
        <div style="padding:16px 18px;border-right:1px solid <?=$CB?>">
            <div style="font-size:8px;font-weight:900;color:<?=$P1?>;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:12px">▦ REPORTE DEL DÍA</div>
            <div id="rep-det-metricas" style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:14px"></div>
            <div id="rep-det-horario" style="margin-bottom:14px"></div>
            <div id="rep-det-nota" style="background:<?=$BG?>;border-radius:9px;padding:10px 13px;font-size:9px;color:<?=$TX?>;line-height:1.6;display:none">
                <div style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;margin-bottom:5px">NOTA DEL DÍA</div>
                <div id="rep-det-nota-txt"></div>
            </div>
        </div>
        <!-- Columna derecha: Checklist -->
        <div style="padding:16px 18px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                <div style="font-size:8px;font-weight:900;color:<?=$P1?>;text-transform:uppercase;letter-spacing:1.5px">✅ CHECKLIST DE HOY</div>
                <div id="rep-det-ck-badge" style="font-size:8px;font-weight:900;color:#1E7A5C"></div>
            </div>
            <div id="rep-det-ck-bar" style="height:6px;border-radius:99px;overflow:hidden;margin-bottom:12px;background:<?=$CB?>">
                <div id="rep-det-ck-fill" style="height:100%;border-radius:99px;transition:width .4s ease"></div>
            </div>
            <div id="rep-det-checklist" style="display:flex;flex-direction:column;gap:5px;max-height:350px;overflow-y:auto"></div>
        </div>
    </div>
</div>

<!-- Filtros históricos -->
<div style="background:#fff;border:1px solid <?=$CB?>;border-radius:13px;padding:14px 16px;margin-top:6px">
    <div style="font-size:8px;font-weight:900;color:<?=$P1?>;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:12px">▦ HISTORIAL DE REPORTES</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <div><label class="form-label" style="display:block">DESDE</label>
            <input type="date" id="rep-from" class="form-input" value="<?=date('Y-m-01')?>" style="width:148px"></div>
        <div><label class="form-label" style="display:block">HASTA</label>
            <input type="date" id="rep-to" class="form-input" value="<?=$today?>" style="width:148px"></div>
        <div><label class="form-label" style="display:block">EMPLEADO</label>
            <select id="rep-ag" class="form-input" style="width:160px">
                <option value="">TODOS</option>
                <?php foreach($agents as $ag):?>
                <option value="<?=$ag['id']?>"><?=h(explode(' ',$ag['nombre'])[0])?></option>
                <?php endforeach;?>
            </select>
        </div>
        <button class="btn btn-p btn-sm" onclick="buscarHistorial()" style="height:36px;padding:0 18px">🔍 BUSCAR</button>
        <button class="btn btn-gh btn-sm" onclick="exportRep('csv')" style="height:36px"> CSV</button>
        <button class="btn btn-gh btn-sm" onclick="exportRep('txt')" style="height:36px"> TXT</button>
    </div>
</div>

<!-- Tabla de resultados históricos -->
<div id="rep-hist-wrap" style="display:none;margin-top:12px">
    <div id="rep-hist-loading" style="display:none;text-align:center;padding:30px;font-size:9px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px">⏳ CARGANDO...</div>
    <div id="rep-hist-tabla"></div>
</div>

<!-- JS DATA + LÓGICA -->
<script>
const REP_DATA = <?=json_encode(array_values($rep_json), JSON_UNESCAPED_UNICODE)?>;

function showRepDetalle(agId) {
    const d = REP_DATA.find(r => r.id == agId);
    if (!d) return;

    // Header
    const av = document.getElementById('rep-det-av');
    av.style.background = d.color;
    av.textContent = d.iniciales;
    document.getElementById('rep-det-nombre').textContent = d.nombre_completo;
    const sub = [];
    if (d.checkin)  sub.push('ENTRADA: ' + d.checkin);
    if (d.checkout) sub.push('SALIDA: ' + d.checkout);
    if (d.horas)    sub.push(d.horas);
    document.getElementById('rep-det-sub').textContent = sub.join('  ·  ') || 'SIN CHECK-IN HOY';

    // Métricas
    const met = document.getElementById('rep-det-metricas');
    const metricas = [
        ['◌ LLAMADAS',  d.llam,      '#2876A8'],
        ['◎ APPS',      d.apps,      '#1B4A6B'],
        ['◷ CITAS',     d.citas,     '#1E7A5C'],
        ['◈ TKS.PEND',  d.tickets,   '#B83232'],
        ['✓ TKS.CERR',  d.tickets_r, '#1E7A8C'],
        ['📋 X HACER',   d.apps_por_hacer, '#5B3FAF'],
    ];
    met.innerHTML = metricas.map(([lb,v,c]) =>
        `<div style="text-align:center;background:#EBF4F9;border-radius:9px;padding:10px 8px">
            <div style="font-size:7px;font-weight:900;color:#7A90A4;text-transform:uppercase;margin-bottom:3px">${lb}</div>
            <div style="font-size:20px;font-weight:900;color:${v>0?c:'#C8DFF0'}">${v}</div>
        </div>`
    ).join('');

    // Estado reporte + horario
    const horDiv = document.getElementById('rep-det-horario');
    const horRows = [];
    if (d.checkin)  horRows.push(`<span style="font-size:9px;font-weight:900;color:#1B4A6B">▶ ENTRADA: ${d.checkin}</span>`);
    if (d.checkout) horRows.push(`<span style="font-size:9px;font-weight:900;color:#1B4A6B">◀ SALIDA: ${d.checkout}</span>`);
    if (d.horas)    horRows.push(`<span style="font-size:9px;font-weight:900;color:#1E7A5C">⏱ ${d.horas} TRABAJADAS</span>`);
    horDiv.innerHTML = `
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px">
            ${horRows.length ? horRows.join('') : '<span style="font-size:8px;color:#7A90A4">Sin check-in hoy</span>'}
        </div>
        <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:9px;
                    background:${d.firmado?'#EAF5F0':'#FEF8EE'};
                    border:1px solid ${d.firmado?'#8DCFBA':'#F5D5A0'}">
            <span style="font-size:14px">${d.firmado?'✅':'⏳'}</span>
            <span style="font-size:8px;font-weight:900;color:${d.firmado?'#1E7A5C':'#C07A1A'};text-transform:uppercase">
                REPORTE ${d.firmado?'ENVIADO':'PENDIENTE DE ENVÍO'}
            </span>
        </div>`;

    // Nota
    const notaDiv = document.getElementById('rep-det-nota');
    if (d.nota && d.nota.trim()) {
        document.getElementById('rep-det-nota-txt').textContent = d.nota;
        notaDiv.style.display = 'block';
    } else {
        notaDiv.style.display = 'none';
    }

    // Checklist
    const total = d.ck_total;
    const done  = d.ck_done;
    const pct   = total > 0 ? Math.round((done/total)*100) : 0;
    const barColor = pct===100 ? '#16A34A' : (pct>=50 ? '#2876A8' : '#C07A1A');

    document.getElementById('rep-det-ck-badge').textContent = `${done}/${total} (${pct}%)`;
    document.getElementById('rep-det-ck-badge').style.color = barColor;
    document.getElementById('rep-det-ck-fill').style.width = pct + '%';
    document.getElementById('rep-det-ck-fill').style.background = barColor;

    const ckList = document.getElementById('rep-det-checklist');
    if (d.checklist.length === 0) {
        ckList.innerHTML = '<div style="font-size:9px;color:#7A90A4;text-align:center;padding:20px">Sin tareas registradas hoy</div>';
    } else {
        ckList.innerHTML = d.checklist.map(item => `
            <div style="display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:9px;
                        background:${item.completado?'#EAF5F0':'#fff'};
                        border:1px solid ${item.completado?'#8DCFBA':'#C8DFF0'}">
                <div style="width:20px;height:20px;border-radius:50%;background:${item.completado?'#1E7A5C':'#fff'};
                            border:2px solid ${item.completado?'#1E7A5C':'#C8DFF0'};
                            display:flex;align-items:center;justify-content:center;
                            font-size:10px;font-weight:900;color:#fff;flex-shrink:0">
                    ${item.completado?'✓':''}
                </div>
                <span style="flex:1;font-size:9px;font-weight:700;color:${item.completado?'#1E7A5C':'#7A90A4'};
                             text-decoration:${item.completado?'none':'none'};line-height:1.4">
                    ${item.texto}
                </span>
                ${item.hora ? `<span style="font-size:7px;color:#1E7A5C;font-weight:900;flex-shrink:0">${item.hora}</span>` : ''}
            </div>
        `).join('');
    }

    // Mostrar panel y hacer scroll
    const panel = document.getElementById('rep-detalle-panel');
    panel.style.display = 'block';
    setTimeout(() => panel.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
}
</script>

</div><!-- /REPORTES -->
<?php endif;?> 

<!-- ════════════════════════════════════════════════════════
     TAB: CONTACTOS (Cuentas + Referidos)
     ════════════════════════════════════════════════════════ -->
<div id="tab-CONTACTOS" class="tab-pane">

<!-- SUB-TABS -->
<div style="display:flex;border-bottom:2px solid <?=$CB?>;margin-bottom:16px;overflow-x:auto">
  <button class="ntab active" onclick="showCueSubTab('CUENTAS')" data-cuetab="CUENTAS">🏢 CUENTAS <span class="nbadge" style="background:<?=$BG?>;color:<?=$MU?>;border:1px solid <?=$CB?>"><?=$cue_total?></span></button>
  <button class="ntab" onclick="showCueSubTab('REFERIDOS')" data-cuetab="REFERIDOS">👥 REFERIDOS <span class="nbadge" style="background:<?=($referidos_pendientes>0)?'#FEF8EE':$BG?>;color:<?=($referidos_pendientes>0)?'#C07A1A':$MU?>;border:1px solid <?=($referidos_pendientes>0)?'#F5D5A0':$CB?>"><?=$referidos_pendientes?></span></button>
  <?php
  $gastos_visitas_total = 0;
  try { $gastos_visitas_total = $pdo->query("SELECT COUNT(*) FROM cuentas_interacciones WHERE gasto_monto > 0")->fetchColumn(); } catch(Exception $e) {}
  ?>
  <button class="ntab" onclick="showCueSubTab('GASTOS')" data-cuetab="GASTOS">💰 GASTOS DE VISITAS <span class="nbadge" style="background:<?=($gastos_visitas_total>0)?'#FEF8EE':$BG?>;color:<?=($gastos_visitas_total>0)?'#C07A1A':$MU?>;border:1px solid <?=($gastos_visitas_total>0)?'#F5D5A0':$CB?>"><?=$gastos_visitas_total?></span></button>
</div>

<!-- ══════ SUB-TAB: CUENTAS ══════ -->
<div id="cue-sub-CUENTAS">
<?php
function ctc_tipo_badge(string $tipo): array {
    return match($tipo) {
        'DENTISTA'        => ['#EBF5FB','#1B5E8C','#A9D0E8'],
        'MÉDICO'          => ['#F3F0FB','#5B3FAF','#C2B0E8'],
        'ASEGURANZA'      => ['#EAF5F0','#1E7A5C','#8DCFBA'],
        'CLÍNICA'         => ['#FEF8EE','#C07A1A','#F5D5A0'],
        'MANAGER OFICINA' => ['#FEF0EE','#C03A1A','#F5B8A8'],
        'FARMACIA'        => ['#F0FBF3','#1A7A44','#8DCFB0'],
        default           => ['#F5F5F5','#7A90A4','#C8DFF0'],
    };
}
?>
<!-- Stats -->
<div style="display:flex;gap:9px;flex-wrap:wrap;margin-bottom:14px">
<div style="background:#fff;border:1px solid <?=$CB?>;border-radius:14px;padding:11px 16px;flex:1;min-width:95px;border-top:3px solid <?=$P1?>"><div style="font-size:8px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">🏢 CUENTAS</div><div style="font-size:22px;font-weight:900;color:<?=$P1?>"><?=$cue_total?></div></div>
<div style="background:#fff;border:1px solid <?=$CB?>;border-radius:14px;padding:11px 16px;flex:1;min-width:95px;border-top:3px solid #1E7A5C"><div style="font-size:8px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px"> REFERENTES</div><div style="font-size:22px;font-weight:900;color:#1E7A5C"><?=$cue_referentes?></div><div style="font-size:7px;color:<?=$MU?>;text-transform:uppercase">Mandan miembros</div></div>
<div style="background:#fff;border:1px solid <?=$CB?>;border-radius:14px;padding:11px 16px;flex:1;min-width:95px;border-top:3px solid <?=($cue_alerta_count>0)?'#B83232':'#1E7A5C'?>"><div style="font-size:8px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">⚠ SIN VISITAR</div><div style="font-size:22px;font-weight:900;color:<?=($cue_alerta_count>0)?'#B83232':'#1E7A5C'?>"><?=$cue_alerta_count?></div><div style="font-size:7px;color:<?=$MU?>;text-transform:uppercase">Vencidos</div></div>
<div style="background:#fff;border:1px solid <?=$CB?>;border-radius:14px;padding:11px 16px;flex:1;min-width:95px;border-top:3px solid #C07A1A"><div style="font-size:8px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">💰 TOTAL GASTADO</div><div style="font-size:18px;font-weight:900;color:#C07A1A">$<?=number_format(array_sum(array_column($cuentas_list,'total_gastado')),2)?></div><div style="font-size:7px;color:<?=$MU?>;text-transform:uppercase">En visitas</div></div>
</div>
<?php if($cue_alerta_count > 0):?>
<div style="background:#F0EBF8;border:1px solid #C8A0D8;border-left:4px solid #7B2D8B;border-radius:11px;padding:11px 16px;margin-bottom:14px">
  <div style="font-size:9px;font-weight:900;color:#7B2D8B;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:8px">⚠ CUENTAS QUE NECESITAN ATENCIÓN</div>
  <div style="display:flex;flex-wrap:wrap;gap:6px">
  <?php foreach(array_slice($cuentas_vencidas,0,6) as $cv): $d_cv=$cv['dias_desde']; $txt_cv=$d_cv===null?'NUNCA CONTACTADO':"HACE $d_cv DÍAS"; $col_cv=($d_cv===null||$d_cv>60)?'#B83232':'#C07A1A'; ?>
  <div onclick="openCueDetalle(<?=$cv['id']?>)" style="background:#fff;border:1px solid #C8A0D8;border-radius:9px;padding:7px 12px;cursor:pointer;display:flex;gap:9px;align-items:center;min-width:170px;flex:1">
    <div style="flex:1;min-width:0"><div style="font-weight:900;font-size:9px;color:#1B4A6B;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($cv['nombre'])?></div><div style="font-size:8px;color:<?=$MU?>"><?=h($cv['tipo'])?></div></div>
    <span style="font-size:8px;font-weight:900;color:<?=$col_cv?>;white-space:nowrap"><?=$txt_cv?></span>
  </div>
  <?php endforeach;?>
  <?php if($cue_alerta_count>6):?><div style="font-size:8px;color:#7B2D8B;font-weight:900;padding:7px 12px;display:flex;align-items:center">+<?=$cue_alerta_count-6?> MÁS</div><?php endif;?>
  </div>
</div>
<?php endif;?>
<div class="card">
<div class="card-header" style="flex-wrap:wrap;gap:8px">
  <div class="card-title">🏢 TODAS LAS CUENTAS</div>
  <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;flex:1;justify-content:flex-end">
    <input type="text" id="cue-search" placeholder="BUSCAR..." class="form-input" style="max-width:180px;font-size:9px;padding:6px 10px" oninput="filterCuentas()">
    <select id="cue-tipo-f" class="form-input" style="max-width:155px;font-size:9px;padding:6px 10px" onchange="filterCuentas()">
      <option value="">TODOS LOS TIPOS</option>
      <?php foreach(['DENTISTA','MÉDICO','ASEGURANZA','CLÍNICA','MANAGER OFICINA','FARMACIA','OTRO'] as $t):?><option><?=$t?></option><?php endforeach;?>
    </select>
    <select id="cue-estado-f" class="form-input" style="max-width:130px;font-size:9px;padding:6px 10px" onchange="filterCuentas()">
      <option value="">TODOS</option><option value="vencido">⚠ VENCIDOS</option><option value="ok">✓ AL DÍA</option>
    </select>
    <button class="btn btn-p btn-sm" onclick="openCueModal()">+ NUEVA CUENTA</button>
  </div>
</div>
<div style="overflow-x:auto">
<table id="cue-table"><thead><tr>
  <th>CUENTA</th><th>TIPO</th><th>TELÉFONO</th><th style="text-align:center">REFERIDOS</th><th style="text-align:center">MIEMBROS</th><th>ÚLTIMO CONTACTO</th><th>RECORDATORIO</th><th>AGENTE</th><th></th>
</tr></thead><tbody>
<?php if(empty($cuentas_list)):?>
<tr><td colspan="9" style="text-align:center;padding:32px;color:<?=$MU?>;font-size:9px;text-transform:uppercase">🏢 AGREGA TU PRIMERA CUENTA CON EL BOTÓN DE ARRIBA</td></tr>
<?php else: foreach($cuentas_list as $cu):
  $dias=$cu['dias_desde']; $lim=(int)($cu['dias_recordatorio']??30);
  $ult=$cu['ultima_interaccion']?date('d/m/y',strtotime($cu['ultima_interaccion'])):'—';
  if($dias===null){$rs='NUNCA';$rc='#B83232';$rb='#FDF0EE';$rbo='#EFA09A';}
  elseif($dias>=$lim){$rs="HACE $dias D.";$rc=$dias>$lim*1.5?'#B83232':'#C07A1A';$rb=$dias>$lim*1.5?'#FDF0EE':'#FEF8EE';$rbo=$dias>$lim*1.5?'#EFA09A':'#F5D5A0';}
  else{$rest=$lim-$dias;$rs=$rest<=7?"EN $rest DÍAS":"✓ AL DÍA";$rc=$rest<=7?'#C07A1A':'#1E7A5C';$rb=$rest<=7?'#FEF8EE':'#EAF5F0';$rbo=$rest<=7?'#F5D5A0':'#8DCFBA';}
  $venc_d=($dias===null||$dias>=$lim)?'1':'0';
  $tb=ctc_tipo_badge($cu['tipo']);
?>
<tr class="cue-row" data-search="<?=strtolower(h($cu['nombre']))?>" data-tipo="<?=h($cu['tipo'])?>" data-venc="<?=$venc_d?>">
  <td><div style="font-weight:900;font-size:10px;color:<?=$P1?>;cursor:pointer" onclick="openCueDetalle(<?=$cu['id']?>)"><?=h($cu['nombre'])?></div><?php if($cu['ciudad']):?><div style="font-size:8px;color:<?=$MU?>">📍 <?=h($cu['ciudad'])?></div><?php endif;?><?php if($cu['es_referente']):?><span style="font-size:7px;background:#EAF5F0;color:#1E7A5C;border:1px solid #8DCFBA;border-radius:20px;padding:1px 7px;font-weight:900"> REFERENTE</span><?php endif;?></td>
  <td><span style="background:<?=$tb[0]?>;color:<?=$tb[1]?>;border:1px solid <?=$tb[2]?>;border-radius:20px;padding:2px 9px;font-size:8px;font-weight:900;white-space:nowrap"><?=h($cu['tipo'])?></span></td>
  <td style="font-size:9px"><?=h($cu['telefono']?:'—')?></td>
  <td style="text-align:center"><?php if($cu['cnt_referidos']>0):?><span style="background:#FEF8EE;color:#C07A1A;border:1px solid #F5D5A0;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:900;cursor:pointer" onclick="openCueDetalle(<?=$cu['id']?>,'REFERIDOS')"><?=$cu['cnt_referidos']?></span><?php else:?><span style="color:<?=$MU?>">—</span><?php endif;?></td>
  <td style="text-align:center"><?php if($cu['cnt_miembros']>0):?><span style="background:#EAF5F0;color:#1E7A5C;border:1px solid #8DCFBA;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:900;cursor:pointer" onclick="openCueDetalle(<?=$cu['id']?>,'MIEMBROS')"><?=$cu['cnt_miembros']?></span><?php else:?><span style="color:<?=$MU?>">—</span><?php endif;?></td>
  <td><div style="font-size:9px"><?=$ult?></div><?php if($cu['total_gastado']>0):?><div style="font-size:8px;color:<?=$MU?>">💰 $<?=number_format($cu['total_gastado'],2)?></div><?php endif;?></td>
  <td><span style="background:<?=$rb?>;color:<?=$rc?>;border:1px solid <?=$rbo?>;border-radius:20px;padding:2px 9px;font-size:8px;font-weight:900;white-space:nowrap"><?=$rs?></span><div style="font-size:7px;color:<?=$MU?>;margin-top:2px">CADA <?=$lim?> DÍAS</div></td>
  <td><?php if($cu['agente_ini']):?><?=av(h($cu['agente_ini']),h($cu['agente_color']??$P2),24)?><?php endif;?></td>
  <td><div style="display:flex;gap:4px"><button class="btn btn-b btn-sm" onclick="openCueDetalle(<?=$cu['id']?>)" title="Detalle">◉</button><button class="btn btn-am btn-sm" onclick="openInterModal(<?=$cu['id']?>,'<?=h(addslashes($cu['nombre']))?>')" title="Registrar">📋</button><button class="btn btn-gh btn-sm" onclick="openCueModal(<?=$cu['id']?>)" title="Editar">✎</button></div></td>
</tr>
<?php endforeach; endif;?>
</tbody></table>
</div>
</div>
</div><!-- /cue-sub-CUENTAS -->

<!-- ══════ SUB-TAB: REFERIDOS ══════ -->
<div id="cue-sub-REFERIDOS" style="display:none">
<?php
$refs_all = [];
try {
    $refs_all = $pdo->query("
        SELECT r.*, c.nombre as cuenta_nombre, c.tipo as cuenta_tipo,
               cc.nombre as contacto_nombre, cc.cargo as contacto_cargo,
               u.nombre as agente_nombre, u.iniciales as agente_ini, u.color as agente_color
        FROM referidos r
        LEFT JOIN cuentas c ON r.cuenta_id=c.id
        LEFT JOIN cuentas_contactos cc ON r.contacto_id=cc.id
        LEFT JOIN usuarios u ON r.agente_id=u.id
        ORDER BY FIELD(r.estado,'NUEVO','INTENTANDO','CONTACTADO','INTERESADO','EN PIPELINE','NO INTERESADO'), r.created_at DESC
    ")->fetchAll();
} catch (Exception $e) {}
$ref_stats = [];
foreach(['NUEVO','INTENTANDO','CONTACTADO','INTERESADO','EN PIPELINE','NO INTERESADO'] as $est)
    $ref_stats[$est] = count(array_filter($refs_all, fn($r)=>$r['estado']===$est));
$ref_colores = ['NUEVO'=>['#EBF4F9','#1B4A6B','#A9CDE0'],'INTENTANDO'=>['#FEF8EE','#C07A1A','#F5D5A0'],'CONTACTADO'=>['#F3F0FB','#5B3FAF','#C2B0E8'],'INTERESADO'=>['#EAF5F0','#1E7A5C','#8DCFBA'],'EN PIPELINE'=>['#EBF5FB','#1B5E8C','#A9D0E8'],'NO INTERESADO'=>['#F5F5F5','#7A90A4','#C8DFF0']];
?>
<div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px">
<?php foreach($ref_stats as $est=>$cnt): $col=$ref_colores[$est]; ?>
<div style="background:<?=$col[0]?>;border:1px solid <?=$col[2]?>;border-radius:11px;padding:9px 14px;flex:1;min-width:80px;text-align:center;cursor:pointer" onclick="filterRefs('<?=$est?>')">
  <div style="font-size:18px;font-weight:900;color:<?=$col[1]?>"><?=$cnt?></div>
  <div style="font-size:7px;font-weight:900;color:<?=$col[1]?>;text-transform:uppercase;letter-spacing:.5px;margin-top:2px"><?=$est?></div>
</div>
<?php endforeach;?>
</div>
<div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
  <input type="text" id="ref-search-input" placeholder="BUSCAR REFERIDO..." class="form-input" style="flex:1;min-width:150px;font-size:9px;padding:7px 10px" oninput="filterRefs()">
  <select id="ref-estado-f" class="form-input" style="max-width:160px;font-size:9px;padding:6px 10px" onchange="filterRefs()">
    <option value="">TODOS LOS ESTADOS</option>
    <?php foreach(array_keys($ref_colores) as $est):?><option><?=$est?></option><?php endforeach;?>
  </select>
  <select id="ref-cuenta-f" class="form-input" style="max-width:180px;font-size:9px;padding:6px 10px" onchange="filterRefs()">
    <option value="">TODAS LAS CUENTAS</option>
    <?php foreach($cuentas_list as $cu):?><option value="<?=$cu['id']?>"><?=h($cu['nombre'])?></option><?php endforeach;?>
  </select>
  <button class="btn btn-p btn-sm" onclick="openRefModal()">+ NUEVO REFERIDO</button>
</div>
<div id="refs-grid" style="display:flex;flex-direction:column;gap:8px">
<?php if(empty($refs_all)):?>
<div style="text-align:center;padding:32px;font-size:9px;color:<?=$MU?>;text-transform:uppercase">👥 AÚN NO HAY REFERIDOS — AGRÉGALOS DESDE UNA CUENTA</div>
<?php else: foreach($refs_all as $rf): $col_r=$ref_colores[$rf['estado']]??['#F5F5F5','#7A90A4','#C8DFF0']; $conv=$rf['estado']==='EN PIPELINE'; ?>
<div class="ref-card" style="background:#fff;border:1px solid <?=$CB?>;border-left:4px solid <?=$col_r[1]?>;border-radius:11px;padding:12px 16px;display:flex;gap:12px;align-items:flex-start"
     data-nombre="<?=strtolower(h($rf['nombre'].' '.$rf['apellido']))?>" data-estado="<?=h($rf['estado'])?>" data-cuenta="<?=(int)$rf['cuenta_id']?>">
  <div style="flex:1;min-width:0">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:5px">
      <span style="font-weight:900;font-size:11px;color:<?=$P1?>"><?=h($rf['nombre'].' '.($rf['apellido']??''))?></span>
      <span style="background:<?=$col_r[0]?>;color:<?=$col_r[1]?>;border:1px solid <?=$col_r[2]?>;border-radius:20px;padding:1px 9px;font-size:8px;font-weight:900"><?=h($rf['estado'])?></span>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
      <?php if($rf['telefono']):?><span style="font-size:9px;color:<?=$MU?>">📞 <?=h($rf['telefono'])?></span><?php endif;?>
      <?php if($rf['cuenta_nombre']):?><span style="font-size:9px;color:<?=$MU?>">🏢 <?=h($rf['cuenta_nombre'])?><?=$rf['contacto_nombre']?' — '.h($rf['contacto_nombre']):''?></span><?php endif;?>
      <?php if($rf['agente_ini']):?><span style="font-size:9px;color:<?=$MU?>"><?=av(h($rf['agente_ini']),h($rf['agente_color']??$P2),18)?> <?=h(explode(' ',$rf['agente_nombre'])[0])?></span><?php endif;?>
    </div>
    <?php if($rf['notas']):?><div style="font-size:8px;color:<?=$MU?>;margin-top:5px;line-height:1.5"><?=h(mb_substr($rf['notas'],0,120))?></div><?php endif;?>
  </div>
  <div style="display:flex;flex-direction:column;gap:5px;flex-shrink:0">
    <?php if(!$conv):?>
    <select class="form-input" style="font-size:8px;padding:4px 7px;min-width:130px" onchange="updateEstadoRef(<?=$rf['id']?>, this.value)">
      <?php foreach(array_keys($ref_colores) as $est):?><option value="<?=$est?>"<?=$rf['estado']===$est?' selected':''?>><?=$est?></option><?php endforeach;?>
    </select>
    <?php if($rf['estado']==='INTERESADO'):?><button class="btn btn-gr btn-sm" style="font-size:8px" onclick="convertirRef(<?=$rf['id']?>)"> MOVER AL PIPELINE</button><?php endif;?>
    <?php else:?><button class="btn btn-bl btn-sm" style="font-size:8px" onclick="openProfile(<?=$rf['miembro_id']?>)">◉ VER PERFIL</button><?php endif;?>
    <div style="display:flex;gap:4px"><button class="btn btn-gh btn-sm" style="font-size:8px" onclick="openRefModal(<?=$rf['id']?>)">✎</button><button class="btn btn-re btn-sm" style="font-size:8px" onclick="deleteRef(<?=$rf['id']?>)">✕</button></div>
  </div>
</div>
<?php endforeach; endif;?>
</div>
</div><!-- /cue-sub-REFERIDOS -->

<!-- ══════ SUB-TAB: GASTOS DE VISITAS ══════ -->
<div id="cue-sub-GASTOS" style="display:none">
<?php
$gastos_vis = [];
$gastos_mes_total = 0;
$gastos_grand_total = 0;
try {
    $gastos_vis = $pdo->query("
        SELECT ci.id, ci.fecha, ci.tipo, ci.descripcion, ci.gasto_descripcion, ci.gasto_monto,
               c.nombre AS cuenta_nombre,
               u.nombre AS agente_nombre, u.iniciales AS agente_ini, u.color AS agente_color
        FROM cuentas_interacciones ci
        LEFT JOIN cuentas c ON ci.cuenta_id = c.id
        LEFT JOIN usuarios u ON ci.agente_id = u.id
        WHERE ci.gasto_monto > 0
        ORDER BY ci.fecha DESC, ci.id DESC
    ")->fetchAll();
    $gastos_grand_total = array_sum(array_column($gastos_vis, 'gasto_monto'));
    $cur_ym = date('Y-m');
    $gastos_mes_total = array_sum(array_map(fn($g) => substr($g['fecha'],0,7)===$cur_ym ? $g['gasto_monto'] : 0, $gastos_vis));
} catch(Exception $e) {}
?>
<!-- KPI row -->
<div style="display:flex;gap:9px;flex-wrap:wrap;margin-bottom:14px">
  <div style="background:#fff;border:1px solid <?=$CB?>;border-radius:14px;padding:11px 16px;flex:1;min-width:110px;border-top:3px solid #C07A1A">
    <div style="font-size:8px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">💰 TOTAL GENERAL</div>
    <div style="font-size:20px;font-weight:900;color:#C07A1A">$<?=number_format($gastos_grand_total,2)?></div>
    <div style="font-size:7px;color:<?=$MU?>;text-transform:uppercase">TODAS LAS VISITAS</div>
  </div>
  <div style="background:#fff;border:1px solid <?=$CB?>;border-radius:14px;padding:11px 16px;flex:1;min-width:110px;border-top:3px solid <?=$P1?>">
    <div style="font-size:8px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px"> MES ACTUAL</div>
    <div style="font-size:20px;font-weight:900;color:<?=$P1?>">$<?=number_format($gastos_mes_total,2)?></div>
    <div style="font-size:7px;color:<?=$MU?>;text-transform:uppercase"><?=strtoupper(date('F Y'))?></div>
  </div>
  <div style="background:#fff;border:1px solid <?=$CB?>;border-radius:14px;padding:11px 16px;flex:1;min-width:110px;border-top:3px solid <?=$G?>">
    <div style="font-size:8px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">📋 REGISTROS</div>
    <div style="font-size:20px;font-weight:900;color:<?=$G?>"><?=count($gastos_vis)?></div>
    <div style="font-size:7px;color:<?=$MU?>;text-transform:uppercase">VISITAS CON GASTO</div>
  </div>
</div>
<!-- Filters -->
<div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
  <input type="text" id="gv-search" placeholder="BUSCAR CUENTA, TIPO, DESCRIPCIÓN..." class="form-input"
         style="flex:1;min-width:180px;font-size:9px;padding:7px 10px" oninput="filterGastosVis()">
  <select id="gv-mes" class="form-input" style="max-width:170px;font-size:9px;padding:6px 10px" onchange="filterGastosVis()">
    <option value="">TODOS LOS MESES</option>
    <?php
    $meses_gv = array_unique(array_map(fn($g) => substr($g['fecha'],0,7), $gastos_vis));
    rsort($meses_gv);
    foreach($meses_gv as $ym):
        $dt = DateTime::createFromFormat('Y-m', $ym);
    ?>
    <option value="<?=$ym?>"><?=strtoupper($dt ? $dt->format('F Y') : $ym)?></option>
    <?php endforeach; ?>
  </select>
  <?php if(count($usuarios_list??[]) > 1): ?>
  <select id="gv-agente" class="form-input" style="max-width:160px;font-size:9px;padding:6px 10px" onchange="filterGastosVis()">
    <option value="">TODOS LOS AGENTES</option>
    <?php foreach($usuarios_list??[] as $ua):?><option value="<?=h($ua['iniciales'])?>"><?=h($ua['nombre'])?></option><?php endforeach;?>
  </select>
  <?php endif; ?>
</div>
<!-- Table -->
<div class="card">
<div class="card-header">
  <div class="card-title">💰 GASTOS POR VISITA / CUENTA</div>
  <span id="gv-total-label" style="font-size:9px;font-weight:900;color:#C07A1A"></span>
</div>
<div style="overflow-x:auto">
<table id="gv-table"><thead><tr>
  <th>FECHA</th><th>CUENTA</th><th>TIPO VISITA</th><th>DESCRIPCIÓN GASTO</th><th>AGENTE</th><th style="text-align:right">MONTO</th>
</tr></thead><tbody id="gv-tbody">
<?php if(empty($gastos_vis)):?>
<tr><td colspan="6" style="text-align:center;padding:32px;color:<?=$MU?>;font-size:9px;text-transform:uppercase">💰 AÚN NO HAY GASTOS REGISTRADOS EN VISITAS</td></tr>
<?php else: foreach($gastos_vis as $gv): ?>
<tr class="gv-row member-row"
    data-fecha="<?=substr($gv['fecha'],0,7)?>"
    data-agente="<?=h($gv['agente_ini']??'')?>"
    data-search="<?=strtolower(h(($gv['cuenta_nombre']??'').' '.($gv['tipo']??'').' '.($gv['gasto_descripcion']??'').' '.($gv['descripcion']??'')))?>">
  <td style="font-size:9px;white-space:nowrap"><?=$gv['fecha']?date('d/m/Y',strtotime($gv['fecha'])):'—'?></td>
  <td>
    <div style="font-weight:900;font-size:10px;color:<?=$P1?>"><?=h($gv['cuenta_nombre']??'—')?></div>
    <?php if($gv['descripcion']):?><div style="font-size:8px;color:<?=$MU?>;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=h($gv['descripcion'])?></div><?php endif;?>
  </td>
  <td><span style="background:#EBF4F9;color:<?=$P1?>;border:1px solid <?=$CB?>;border-radius:20px;padding:2px 9px;font-size:8px;font-weight:900;white-space:nowrap"><?=h($gv['tipo']??'—')?></span></td>
  <td style="font-size:9px;max-width:220px"><?=h($gv['gasto_descripcion']??'—')?></td>
  <td><?php if($gv['agente_ini']):?><?=av(h($gv['agente_ini']),h($gv['agente_color']??$P2),22)?><?php else:?>—<?php endif;?></td>
  <td style="text-align:right;font-weight:900;font-size:11px;color:#C07A1A;white-space:nowrap">$<?=number_format($gv['gasto_monto'],2)?></td>
</tr>
<?php endforeach; endif;?>
</tbody>
</table>
</div>
<!-- Total row -->
<div id="gv-total-row" style="display:flex;justify-content:flex-end;padding:10px 16px;border-top:1px solid <?=$CB?>">
  <span style="font-size:10px;font-weight:900;color:#C07A1A">TOTAL: <span id="gv-sum">$<?=number_format($gastos_grand_total,2)?></span></span>
</div>
</div>
</div><!-- /cue-sub-GASTOS -->

</div><!-- /CONTACTOS -->

<!-- ══ MODAL: NUEVA / EDITAR CUENTA ══ -->
<div id="modal-cue-form" class="modal-overlay"><div class="modal modal-sm">
  <div class="modal-header"><div class="modal-title" id="cue-form-title">NUEVA CUENTA</div><button class="modal-close" onclick="closeModal('modal-cue-form')">✕</button></div>
  <input type="hidden" id="cue-edit-id">
  <div class="grid-2">
    <div class="form-group" style="grid-column:1/-1"><label class="form-label">NOMBRE *</label><input id="cue-nombre" type="text" class="form-input" placeholder="EJ: NAVARRO DENTISTRY"></div>
    <div class="form-group"><label class="form-label">TIPO</label><select id="cue-tipo" class="form-input"><?php foreach(['DENTISTA','MÉDICO','ASEGURANZA','CLÍNICA','MANAGER OFICINA','FARMACIA','OTRO'] as $t):?><option><?=$t?></option><?php endforeach;?></select></div>
    <div class="form-group"><label class="form-label">¿ES REFERENTE?</label><select id="cue-es-ref" class="form-input"><option value="0">NO — Solo contacto de apoyo</option><option value="1">SÍ — Nos manda clientes</option></select></div>
    <div class="form-group"><label class="form-label">TELÉFONO</label><input id="cue-tel" type="text" class="form-input" placeholder="(818) 000-0000"></div>
    <div class="form-group"><label class="form-label">EMAIL</label><input id="cue-email" type="email" class="form-input" style="text-transform:none" placeholder="correo@ejemplo.com"></div>
    <div class="form-group" style="grid-column:1/-1"><label class="form-label">DIRECCIÓN</label><input id="cue-dir" type="text" class="form-input"></div>
    <div class="form-group"><label class="form-label">CIUDAD</label><input id="cue-ciudad" type="text" class="form-input" placeholder="LOS ANGELES"></div>
    <div class="form-group"><label class="form-label">RECORDATORIO CADA (DÍAS)</label><input id="cue-dias" type="number" min="7" max="365" class="form-input" value="30"></div>
    <div class="form-group" style="grid-column:1/-1"><label class="form-label">NOTAS</label><textarea id="cue-notas" class="form-input" rows="3" style="text-transform:none" placeholder="Horarios, cómo ayudan, qué necesitan..."></textarea></div>
  </div>
  <div style="display:flex;gap:8px;margin-top:4px"><button class="btn btn-gh" style="flex:1" onclick="closeModal('modal-cue-form')">CANCELAR</button><button class="btn btn-p" style="flex:2" id="cue-form-btn" onclick="saveCuenta()">GUARDAR ➜</button></div>
</div></div>

<!-- ══ MODAL: NUEVO / EDITAR CONTACTO DE CUENTA ══ -->
<div id="modal-ctcuenta-form" class="modal-overlay" style="z-index:1100"><div class="modal modal-sm">
  <div class="modal-header">
    <div><div class="modal-title" id="ctcuenta-form-title">NUEVO CONTACTO</div><div id="ctcuenta-form-cuenta" style="font-size:9px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase"></div></div>
    <button class="modal-close" onclick="closeModal('modal-ctcuenta-form')">✕</button>
  </div>
  <input type="hidden" id="ctcuenta-edit-id"><input type="hidden" id="ctcuenta-cuenta-id">
  <div class="grid-2">
    <div class="form-group" style="grid-column:1/-1"><label class="form-label">NOMBRE *</label><input id="ctcuenta-nombre" type="text" class="form-input" placeholder="EJ: GLENDA NAVARRO"></div>
    <div class="form-group"><label class="form-label">CARGO / ROL</label><input id="ctcuenta-cargo" type="text" class="form-input" placeholder="MANAGER, RECEPCIONISTA..."></div>
    <div class="form-group"><label class="form-label">ES CONTACTO PRINCIPAL</label><select id="ctcuenta-principal" class="form-input"><option value="1">SÍ — PRINCIPAL</option><option value="0">NO — SECUNDARIO</option></select></div>
    <div class="form-group"><label class="form-label">TELÉFONO</label><input id="ctcuenta-tel" type="text" class="form-input"></div>
    <div class="form-group"><label class="form-label">EMAIL</label><input id="ctcuenta-email" type="email" class="form-input" style="text-transform:none"></div>
    <div class="form-group" style="grid-column:1/-1"><label class="form-label">NOTAS</label><textarea id="ctcuenta-notas" class="form-input" rows="2" style="text-transform:none"></textarea></div>
  </div>
  <div style="display:flex;gap:8px;margin-top:4px"><button class="btn btn-gh" style="flex:1" onclick="closeModal('modal-ctcuenta-form')">CANCELAR</button><button class="btn btn-p" style="flex:2" id="ctcuenta-form-btn" onclick="saveCtcCuenta()">GUARDAR ➜</button></div>
</div></div>

<!-- ══ MODAL: REGISTRAR INTERACCIÓN ══ -->
<div id="modal-inter-form" class="modal-overlay" style="z-index:1100"><div class="modal modal-sm">
  <div class="modal-header">
    <div><div class="modal-title">📋 REGISTRAR INTERACCIÓN</div><div id="inter-nombre" style="font-size:9px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase"></div></div>
    <button class="modal-close" onclick="closeModal('modal-inter-form')">✕</button>
  </div>
  <input type="hidden" id="inter-cue-id">
  <div class="form-group"><label class="form-label">TIPO</label><div style="display:flex;gap:6px;flex-wrap:wrap">
    <?php foreach(['VISITA'=>'🏢','LLAMADA'=>'📞','EMAIL'=>'📧','REUNIÓN'=>'🤝','OTRO'=>'◌'] as $t=>$ic):?>
    <button class="btn btn-gh btn-sm inter-tipo-btn" data-tipo="<?=$t?>" onclick="setInterTipo(this)" style="<?=$t==='LLAMADA'?'background:#EBF5FB;color:#1B5E8C;border-color:#A9D0E8':''?>"><?=$ic?> <?=$t?></button>
    <?php endforeach;?>
  </div><input type="hidden" id="inter-tipo" value="LLAMADA"></div>
  <div class="form-group"><label class="form-label">HABLÉ CON</label><select id="inter-contacto" class="form-input"><option value="">— CONTACTO (OPCIONAL) —</option></select></div>
  <div class="form-group"><label class="form-label">RESULTADO</label><div style="display:flex;gap:5px;flex-wrap:wrap">
    <?php foreach(['CONTESTÓ','NO CONTESTÓ','VISITA EXITOSA','DEJÉ MATERIALES','PENDIENTE SEGUIMIENTO'] as $r):?>
    <button class="btn btn-gh btn-sm inter-res-btn" data-res="<?=$r?>" onclick="setInterRes(this)" style="<?=$r==='CONTESTÓ'?'background:#EAF5F0;color:#1E7A5C;border-color:#8DCFBA':''?>"><?=$r?></button>
    <?php endforeach;?>
  </div><input type="hidden" id="inter-resultado" value="CONTESTÓ"></div>
  <div class="grid-2">
    <div class="form-group"><label class="form-label">FECHA</label><input type="date" id="inter-fecha" class="form-input" value="<?=date('Y-m-d')?>"></div>
    <div class="form-group"><label class="form-label">GASTO $</label><input type="number" id="inter-gasto" class="form-input" min="0" step="0.01" placeholder="0.00" oninput="toggleGastoDesc(this)"></div>
    <div class="form-group" style="grid-column:1/-1" id="gasto-desc-wrap" style="display:none"><label class="form-label">¿QUÉ LLEVASTE / EN QUÉ GASTASTE?</label><input type="text" id="inter-gasto-desc" class="form-input" placeholder="EJ: DONAS KRISPY KREME, MATERIALES SCAN, TARJETAS..."></div>
    <div class="form-group" style="grid-column:1/-1"><label class="form-label">NOTAS</label><textarea id="inter-desc" class="form-input" rows="4" style="text-transform:none" placeholder="Ej: Hablé con Glenda, quedó en llamarme el jueves..."></textarea></div>
  </div>
  <div style="display:flex;gap:8px;margin-top:4px"><button class="btn btn-gh" style="flex:1" onclick="closeModal('modal-inter-form')">CANCELAR</button><button class="btn btn-p" style="flex:2" id="inter-form-btn" onclick="saveInter()">GUARDAR ➜</button></div>
</div></div>

<!-- ══ MODAL: NUEVO / EDITAR REFERIDO ══ -->
<div id="modal-ref-form" class="modal-overlay style="z-index:1100""><div class="modal modal-sm">
  <div class="modal-header"><div class="modal-title" id="ref-form-title">NUEVO REFERIDO</div><button class="modal-close" onclick="closeModal('modal-ref-form')">✕</button></div>
  <input type="hidden" id="ref-edit-id">
  <div class="grid-2">
    <div class="form-group"><label class="form-label">NOMBRE *</label><input id="ref-nombre" type="text" class="form-input"></div>
    <div class="form-group"><label class="form-label">APELLIDO</label><input id="ref-apellido" type="text" class="form-input"></div>
    <div class="form-group"><label class="form-label">TELÉFONO</label><input id="ref-tel" type="text" class="form-input" placeholder="(818) 000-0000"></div>
    <div class="form-group"><label class="form-label">FECHA DE NACIMIENTO</label><input id="ref-dob" type="date" class="form-input"></div>
    <div class="form-group"><label class="form-label">IDIOMA</label><select id="ref-idioma" class="form-input"><option value="ESP">ESPAÑOL</option><option value="ENG">INGLÉS</option></select></div>
    <div class="form-group"><label class="form-label">ASIGNAR A AGENTE</label><select id="ref-agente" class="form-input"><?php foreach($users_all as $u):?><option value="<?=$u['id']?>"<?=$u['id']==$uid?' selected':''?>><?=h(explode(' ',$u['nombre'])[0])?></option><?php endforeach;?></select></div>
    <div class="form-group"><label class="form-label">VIENE DE LA CUENTA</label><select id="ref-cuenta" class="form-input" onchange="loadContactosCuenta(this.value)"><option value="">— NINGUNA —</option><?php foreach($cuentas_list as $cu):?><option value="<?=$cu['id']?>"><?=h($cu['nombre'])?></option><?php endforeach;?></select></div>
    <div class="form-group"><label class="form-label">QUIÉN LO REFIRIÓ</label><select id="ref-contacto" class="form-input"><option value="">— SELECCIONAR —</option></select></div>
    <div class="form-group" style="grid-column:1/-1"><label class="form-label">NOTAS</label><textarea id="ref-notas" class="form-input" rows="3" style="text-transform:none" placeholder="Ej: 68 años, tiene Medicare A y B, busca plan con dentista..."></textarea></div>
  </div>
  <div style="display:flex;gap:8px;margin-top:4px"><button class="btn btn-gh" style="flex:1" onclick="closeModal('modal-ref-form')">CANCELAR</button><button class="btn btn-p" style="flex:2" id="ref-form-btn" onclick="saveRef()">GUARDAR ➜</button></div>
</div></div>

<!-- ══ MODAL: DETALLE DE CUENTA ══ -->
<div id="modal-cue-detalle" class="modal-overlay"><div class="modal" style="max-width:920px">
  <div class="modal-header">
    <div><div class="modal-title" id="cue-det-titulo">DETALLE</div><div id="cue-det-sub" style="font-size:9px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase"></div></div>
    <div style="display:flex;gap:6px">
      <button class="btn btn-am btn-sm" id="cue-det-inter-btn">📋 REGISTRAR</button>
      <button class="btn btn-sky btn-sm" id="cue-det-addctc-btn">+ CONTACTO</button>
      <button class="btn btn-gh btn-sm" id="cue-det-edit-btn">✎ EDITAR</button>
      <button class="btn btn-gh btn-sm" onclick="printCueReport()">🖨 REPORTE</button>
      <button class="modal-close" onclick="closeModal('modal-cue-detalle')">✕</button>
    </div>
  </div>
  <div style="display:flex;border-bottom:2px solid <?=$CB?>;margin-bottom:14px;overflow-x:auto">
    <?php foreach(['INFO'=>'◉ INFO','CONTACTOS'=>'👥 CONTACTOS','HISTORIAL'=>'📋 HISTORIAL','REFERIDOS'=>'🔀 REFERIDOS','MIEMBROS'=>' MIEMBROS'] as $dt=>$dl):?>
    <button class="ntab<?=$dt==='INFO'?' active':''?>" onclick="showCueDetTab('<?=$dt?>')" data-cue-tab="<?=$dt?>"><?=$dl?></button>
    <?php endforeach;?>
  </div>
  <div id="cue-det-INFO"><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:9px" id="cue-det-info-grid"></div><div id="cue-det-notas-box" style="display:none;margin-top:12px;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:9px;padding:11px 14px;font-size:9px;color:<?=$TX?>;line-height:1.7"><div style="font-size:7px;font-weight:900;color:<?=$MU?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">NOTAS</div><div id="cue-det-notas-txt"></div></div><div id="cue-det-gastos-box" style="display:none;margin-top:12px"><div style="font-size:9px;font-weight:900;color:#C07A1A;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:8px">💰 GASTOS EN VISITAS</div><div id="cue-det-gastos-content"></div></div></div>
  <div id="cue-det-CONTACTOS" style="display:none"><div id="cue-det-ctc-list" style="display:flex;flex-direction:column;gap:8px"></div></div>
  <div id="cue-det-HISTORIAL" style="display:none"><div style="display:flex;justify-content:flex-end;margin-bottom:10px"><button class="btn btn-am btn-sm" id="cue-det-inter-btn2">+ NUEVA INTERACCIÓN</button></div><div id="cue-det-hist-list" style="display:flex;flex-direction:column;gap:8px;max-height:440px;overflow-y:auto;padding-right:4px"></div></div>
  <div id="cue-det-REFERIDOS" style="display:none"><div style="display:flex;justify-content:flex-end;margin-bottom:10px"><button class="btn btn-am btn-sm" id="cue-det-addref-btn">+ NUEVO REFERIDO</button></div><div id="cue-det-ref-list" style="display:flex;flex-direction:column;gap:8px;max-height:440px;overflow-y:auto"></div></div>
  <div id="cue-det-MIEMBROS" style="display:none"><div id="cue-det-mie-list" style="display:flex;flex-direction:column;gap:6px"></div></div>
</div></div>





<!-- GASTOS — admin ve todo y aprueba/reembolsa; empleados registran sus compras -->
<div id="tab-GASTOS" class="tab-pane">
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">
  <div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap">
    <select id="gastos-mes" onchange="loadGastos()" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase">
      <option value="all">TODOS LOS MESES</option>
      <?php foreach(['1'=>'ENERO','2'=>'FEBRERO','3'=>'MARZO','4'=>'ABRIL','5'=>'MAYO','6'=>'JUNIO','7'=>'JULIO','8'=>'AGOSTO','9'=>'SEPTIEMBRE','10'=>'OCTUBRE','11'=>'NOVIEMBRE','12'=>'DICIEMBRE'] as $mn=>$ml):?><option value="<?=$mn?>"<?=$mn==date('n')?' selected':''?>><?=$ml?></option><?php endforeach;?>
    </select>
    <select id="gastos-cat" onchange="loadGastos()" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase">
      <option value="all">TODAS LAS CATEGORÍAS</option>
      <option value="OFFICE">OFFICE EXPENSES</option>
      <option value="MEETING">CLIENT/PROSPECT MEETING</option>
      <option value="PAYROLL">PAYROLL &amp; COMPENSATION</option>
      <option value="MARKETING">MARKETING &amp; ADVERTISING</option>
      <option value="TRAINING">TRAINING &amp; DEVELOPMENT</option>
    </select>
    <select id="gastos-est" onchange="loadGastos()" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase">
      <option value="all">TODOS LOS ESTADOS</option>
      <option value="PENDIENTE">PENDIENTE</option>
      <option value="APROBADO">APROBADO</option>
      <option value="RECHAZADO">RECHAZADO</option>
    </select>
  </div>
  <button class="btn btn-p btn-sm" onclick="openGastoForm()">+ AGREGAR GASTO</button>
</div>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin-bottom:14px">
  <div class="stat-card"><div class="stat-icon">💰 TOTAL MES</div><div class="stat-val" id="gkpi-total" style="color:<?=$P1?>">—</div><div style="font-size:8px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase">todos los estados</div></div>
  <div class="stat-card"><div class="stat-icon"> APROBADO</div><div class="stat-val" id="gkpi-aprobado" style="color:<?=$G?>">—</div><div style="font-size:8px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase">confirmado</div></div>
  <div class="stat-card"><div class="stat-icon"> PENDIENTE</div><div class="stat-val" id="gkpi-pendiente" style="color:<?=$A?>">—</div><div style="font-size:8px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase">por aprobar</div></div>
  <div class="stat-card"><div class="stat-icon"> RECHAZADO</div><div class="stat-val" id="gkpi-rechazado" style="color:<?=$R?>">—</div><div style="font-size:8px;color:<?=$MU?>;margin-top:2px;text-transform:uppercase">denegado</div></div>
</div>
<div id="gastos-reembolso-banner" style="display:none;background:#FEF8EE;border:1px solid #F5D5A0;border-radius:10px;padding:10px 15px;margin-bottom:12px;font-size:10px;font-weight:900;color:#C07A1A;text-transform:uppercase;letter-spacing:.5px"></div>
<div class="card">
<div style="overflow-x:auto">
<table>
<thead><tr><th>FECHA</th><th>CATEGORÍA</th><th>TIPO</th><th>DESCRIPCIÓN</th><th>PROVEEDOR / PAGADO A</th><th>MONTO</th><th>MÉTODO</th><th>ENVIADO POR</th><th>FACTURA</th><th>REEMBOLSO</th><th>ESTADO</th><th></th></tr></thead>
<tbody id="gastos-tbody"><tr><td colspan="12" style="text-align:center;color:<?=$MU?>;padding:30px;font-size:9px;text-transform:uppercase">CARGANDO...</td></tr></tbody>
</table>
</div>
</div>
</div><!-- /tab-GASTOS -->

<!-- Modal: Nuevo Gasto -->
<div id="gasto-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;padding:22px 24px;width:560px;max-width:96vw;max-height:88vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <div style="font-size:11px;font-weight:900;color:<?=$P1?>;text-transform:uppercase;letter-spacing:1.5px">💰 NUEVO GASTO</div>
      <button onclick="closeGastoModal()" style="background:none;border:none;font-size:18px;cursor:pointer;color:<?=$MU?>;padding:2px 6px">✕</button>
    </div>
    <form id="gasto-form">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
        <div>
          <div style="font-size:7px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">FECHA *</div>
          <input type="date" name="fecha" required value="<?=date('Y-m-d')?>" style="width:100%;border:1.5px solid <?=$CB?>;border-radius:8px;padding:8px 10px;font-size:11px;font-family:'DM Sans',sans-serif;outline:none;background:#fff;box-sizing:border-box">
        </div>
        <div>
          <div style="font-size:7px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">MONTO *</div>
          <input type="number" name="monto" min="0" step="0.01" required placeholder="0.00" style="width:100%;border:1.5px solid <?=$CB?>;border-radius:8px;padding:8px 10px;font-size:11px;font-family:'DM Sans',sans-serif;outline:none;background:#fff;box-sizing:border-box">
        </div>
        <div>
          <div style="font-size:7px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">CATEGORÍA *</div>
          <select name="categoria" id="gasto-cat-sel" required onchange="updateGastoTipos()" style="width:100%;border:1.5px solid <?=$CB?>;border-radius:8px;padding:8px 10px;font-size:11px;font-family:'DM Sans',sans-serif;background:#fff;text-transform:uppercase;box-sizing:border-box">
            <option value="">— SELECCIONAR —</option>
            <option value="OFFICE">OFFICE EXPENSES</option>
            <option value="MEETING">CLIENT/PROSPECT MEETING</option>
            <option value="PAYROLL">PAYROLL &amp; COMPENSATION</option>
            <option value="MARKETING">MARKETING &amp; ADVERTISING</option>
            <option value="TRAINING">TRAINING &amp; DEVELOPMENT</option>
          </select>
        </div>
        <div>
          <div style="font-size:7px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">TIPO</div>
          <select name="tipo" id="gasto-tipo-sel" style="width:100%;border:1.5px solid <?=$CB?>;border-radius:8px;padding:8px 10px;font-size:11px;font-family:'DM Sans',sans-serif;background:#fff;text-transform:uppercase;box-sizing:border-box">
            <option value="">— SELECCIONAR CATEGORÍA PRIMERO —</option>
          </select>
        </div>
        <div style="grid-column:1/-1">
          <div style="font-size:7px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">DESCRIPCIÓN *</div>
          <input type="text" name="descripcion" required placeholder="DESCRIPCIÓN DEL GASTO..." style="width:100%;border:1.5px solid <?=$CB?>;border-radius:8px;padding:8px 10px;font-size:11px;font-family:'DM Sans',sans-serif;outline:none;background:#fff;text-transform:uppercase;box-sizing:border-box">
        </div>
        <div>
          <div style="font-size:7px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">PROVEEDOR / PAGADO A</div>
          <input type="text" name="vendedor" placeholder="NOMBRE DEL PROVEEDOR..." style="width:100%;border:1.5px solid <?=$CB?>;border-radius:8px;padding:8px 10px;font-size:11px;font-family:'DM Sans',sans-serif;outline:none;background:#fff;text-transform:uppercase;box-sizing:border-box">
        </div>
        <div>
          <div style="font-size:7px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">MÉTODO DE PAGO</div>
          <select name="metodo_pago" style="width:100%;border:1.5px solid <?=$CB?>;border-radius:8px;padding:8px 10px;font-size:11px;font-family:'DM Sans',sans-serif;background:#fff;text-transform:uppercase;box-sizing:border-box">
            <option value="CARD">TARJETA (CARD)</option>
            <option value="CASH">CASH / EFECTIVO</option>
            <option value="CHECK">CHEQUE</option>
            <option value="ZELLE">ZELLE</option>
            <option value="OTHER">OTRO</option>
          </select>
        </div>
        <div style="grid-column:1/-1">
          <div style="font-size:7px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">NOTAS</div>
          <textarea name="notas" placeholder="NOTAS ADICIONALES..." style="width:100%;border:1.5px solid <?=$CB?>;border-radius:8px;padding:8px 10px;font-size:11px;font-family:'DM Sans',sans-serif;outline:none;background:#fff;min-height:55px;resize:vertical;text-transform:uppercase;box-sizing:border-box"></textarea>
        </div>
        <div style="grid-column:1/-1">
          <div style="font-size:7px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">📷 FOTO DE LA FACTURA / RECIBO</div>
          <input type="file" name="recibo_foto" accept="image/*,application/pdf" capture="environment" style="width:100%;border:1.5px dashed <?=$CB?>;border-radius:8px;padding:8px 10px;font-size:10px;font-family:'DM Sans',sans-serif;background:#F8FBFE;box-sizing:border-box">
          <div style="font-size:7px;color:#7A90A4;margin-top:3px;text-transform:uppercase;letter-spacing:.5px">PUEDES TOMAR LA FOTO O SUBIR IMAGEN/PDF</div>
        </div>
        <div style="grid-column:1/-1;background:#FEF8EE;border:1px solid #F5D5A0;border-radius:8px;padding:10px 12px">
          <div style="font-size:7px;font-weight:900;color:#C07A1A;text-transform:uppercase;letter-spacing:1px;margin-bottom:5px">💵 ¿LO PAGÓ UN EMPLEADO DE SU BOLSILLO? (PARA REEMBOLSARLE)</div>
          <select name="reembolsar_a" style="width:100%;border:1.5px solid #F5D5A0;border-radius:8px;padding:8px 10px;font-size:11px;font-family:'DM Sans',sans-serif;background:#fff;box-sizing:border-box">
            <option value="">— NO / LO PAGÓ LA OFICINA —</option>
            <?php foreach($users_all as $u):?><option value="<?=$u['id']?>"><?=h($u['nombre'])?></option><?php endforeach;?>
          </select>
          <div style="font-size:7px;color:#C07A1A;margin-top:4px;text-transform:uppercase;letter-spacing:.5px">★ SI SELECCIONAS A ALGUIEN, EL GASTO QUEDA COMO "POR REEMBOLSAR" HASTA QUE SE LE PAGUE</div>
        </div>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:6px">
        <button type="button" onclick="closeGastoModal()" class="btn btn-gh btn-sm">CANCELAR</button>
        <button type="button" id="gasto-save-btn" onclick="saveGasto()" class="btn btn-p btn-sm">✓ GUARDAR GASTO</button>
      </div>
    </form>
  </div>
</div>

<!-- ADMIN -->
<div id="tab-ADMIN" class="tab-pane">
<div style="display:flex;border-bottom:2px solid <?=$CB?>;margin-bottom:14px;overflow-x:auto;background:#fff;border-radius:11px 11px 0 0;border:1px solid <?=$CB?>">
<?php foreach(['EMPLEADOS','CERTIFICACIONES','CONTRASEÑAS','METAS','NOTIFICACIONES','INCENTIVOS','IMPORTAR','HISTORIAL'] as $at):?><button class="ntab<?=$at==='EMPLEADOS'?' active':''?>" onclick="showAdminTab('<?=$at?>')" data-atab="<?=$at?>"><?=$at?></button><?php endforeach;?>
</div>
<div id="atab-EMPLEADOS"><div class="card"><div class="card-header"><div class="card-title">EMPLEADOS</div></div><table><tr><th>EMPLEADO</th><th>ROL</th><th>USUARIO</th><th>EMAIL</th></tr><?php foreach($users_all as $u):?><tr><td><div style="display:flex;gap:7px;align-items:center"><?=av(h($u['iniciales']),h($u['color']),28)?><span style="font-weight:900;font-size:9px;color:<?=$P1?>"><?=h($u['nombre'])?></span></div></td><td><?=badge($u['rol']==='admin'?'ADMIN':'EMPLEADO',true)?></td><td style="font-size:9px;color:#1B5E8C;font-weight:800"><?=h($u['username'])?></td><td style="font-size:8px;color:<?=$MU?>"><?=h($u['email']??'—')?></td></tr><?php endforeach;?></table></div></div>
<div id="atab-CERTIFICACIONES" style="display:none">
<div style="background:#EBF5FB;border:1px solid #A9D0E8;border-radius:10px;padding:9px 14px;font-size:8px;color:#1B5E8C;font-weight:800;letter-spacing:1px;text-transform:uppercase;margin-bottom:14px">◎ SOLO ISABEL FUENTES LLEVA CERTIFICACIONES · CA LIC #0D96598</div>
<?php $isabel=current(array_filter($users_all,fn($u)=>$u['rol']==='admin'));if($isabel):?><div style="background:#fff;border:1px solid <?=$CB?>;border-radius:13px;overflow:hidden;max-width:480px"><div style="padding:14px 16px;border-bottom:1px solid <?=$CB?>;display:flex;gap:9px;align-items:center"><?=av(h($isabel['iniciales']),h($isabel['color']),40)?><div><div style="font-weight:900;font-size:11px;color:<?=$P1?>"><?=h($isabel['nombre'])?></div><div style="font-size:8px;color:<?=$MU?>">BROKER · CA LIC #0D96598</div></div></div><div style="padding:13px 16px"><?php foreach([['AHIP',$isabel['ahip_date']??'—'],['LICENCIA CA','#0D96598'],['SCAN','2025-09-30'],['ANTHEM','2025-09-30'],['HUMANA','2025-09-30'],['ALIGNMENT','2025-09-30'],['LA CARE','2025-09-30'],['HEALTH NET','2025-09-30'],['MOLINA','2025-09-30'],['UHC','2025-09-30']] as [$l,$v]):?><div style="display:flex;justify-content:space-between;padding:6px 0;font-size:9px;border-bottom:1px solid <?=$BG?>"><span style="color:<?=$MU?>;font-weight:700;text-transform:uppercase"><?=$l?></span><span style="font-weight:900;color:<?=$P1?>"><?=$v?></span></div><?php endforeach;?></div></div><?php endif;?>
</div>
<div id="atab-CONTRASEÑAS" style="display:none"><div id="pwd-lock" style="display:flex;align-items:center;justify-content:center;min-height:240px"><div style="background:#fff;border:1px solid <?=$CB?>;border-radius:16px;padding:36px 32px;max-width:360px;width:100%;text-align:center"><div style="font-size:10px;font-weight:900;color:<?=$P1?>;letter-spacing:3px;text-transform:uppercase;margin-bottom:14px"> ZONA PRIVADA</div><div id="pwd-err" style="display:none;background:#FDF0EE;color:#B83232;border:1px solid #EFA09A;border-radius:8px;padding:7px;font-size:9px;font-weight:900;margin-bottom:11px;text-transform:uppercase">CONTRASEÑA INCORRECTA</div><input type="password" id="admin-pwd" placeholder="••••••••" class="form-input" style="margin-bottom:9px"><button class="btn btn-p btn-full" onclick="unlockPasswords()"> INGRESAR</button></div></div><div id="pwd-content" style="display:none"><div class="card"><div class="card-header"><div class="card-title">ACCESOS</div></div><table><tr><th>EMPLEADO</th><th>USUARIO</th><th>CONTRASEÑA</th><th>ROL</th></tr><?php foreach($users_all as $u):?><tr><td><div style="display:flex;gap:7px;align-items:center"><?=av(h($u['iniciales']),h($u['color']),24)?><span style="font-weight:900;font-size:9px;color:<?=$P1?>"><?=h($u['nombre'])?></span></div></td><td style="font-size:9px;font-weight:800;color:#1B5E8C"><?=h($u['username'])?></td><td><span data-pwd="<?=htmlspecialchars(ucfirst($u['username']).'2026!',ENT_QUOTES)?>" style="cursor:pointer;font-size:9px;color:<?=$MU?>" onclick="this.textContent=this.dataset.pwd">••••••••</span></td><td><?=badge($u['rol']==='admin'?'ADMIN':'EMPLEADO',true)?></td></tr><?php endforeach;?></table></div></div></div>
<div id="atab-METAS" style="display:none"><div class="card"><div class="card-header"><div class="card-title">METAS MENSUALES</div></div><table><tr><th>EMPLEADO</th><th>LLAMADAS/DÍA</th><th>CITAS/MES</th><th>APPS/MES</th><th>META AEP</th></tr><?php foreach($agents as $ag):?><tr><td><div style="display:flex;gap:7px;align-items:center"><?=av(h($ag['iniciales']),h($ag['color']),26)?><span style="font-weight:900;font-size:9px;color:<?=$P1?>"><?=h(explode(' ',$ag['nombre'])[0])?></span></div></td><td style="font-weight:900;color:<?=$P2?>;font-size:12px">20</td><td style="font-weight:900;color:<?=$P1?>;font-size:12px">8</td><td style="font-weight:900;color:#1E7A5C;font-size:12px">4</td><td style="font-weight:900;color:#C07A1A;font-size:12px">15</td></tr><?php endforeach;?></table></div></div>
<div id="atab-NOTIFICACIONES" style="display:none">

<div style="display:grid;grid-template-columns:1fr 1fr;gap:11px">
<div class="card"><div class="card-header"><div class="card-title"> NOTIFICACIÓN RÁPIDA</div></div><div style="padding:14px 16px">
<div class="form-group"><label class="form-label">PARA</label><select id="notif-target" class="form-input"><?php foreach($users_all as $u):?><option value="<?=$u['id']?>"><?=h($u['nombre'])?></option><?php endforeach;?></select></div>
<div class="form-group"><label class="form-label">MENSAJE</label><textarea id="notif-msg" class="form-input" rows="3"></textarea></div>
<button class="btn btn-p btn-sm" onclick="sendNotif()"> ENVIAR</button>
</div></div>
<div class="card" style="border-top:3px solid #C07A1A"><div class="card-header"><div class="card-title" style="color:#C07A1A"> OBSERVACIÓN PARA EMPLEADA</div><div class="card-sub">Aparece en su tab MI DÍA</div></div><div style="padding:14px 16px">
<div class="form-group"><label class="form-label">EMPLEADA</label><select id="obs-target" class="form-input"><?php foreach($agents as $u):?><option value="<?=$u['id']?>"><?=h($u['nombre'])?></option><?php endforeach;?></select></div>
<div class="form-group"><label class="form-label">OBSERVACIÓN / NOTA</label><textarea id="obs-msg" class="form-input" rows="4" placeholder="Ej: Skarleth, recuerda confirmar las citas de mañana antes de las 4pm..." style="text-transform:none"></textarea></div>
<button class="btn btn-am btn-sm" onclick="sendObservacion()"> GUARDAR OBSERVACIÓN</button>
</div></div>
</div>
</div>
<div id="atab-INCENTIVOS" style="display:none">
<div style="background:#EAF5F0;border:1px solid #8DCFBA;border-radius:10px;padding:9px 14px;font-size:8px;color:#1E7A5C;font-weight:800;letter-spacing:1px;text-transform:uppercase;margin-bottom:14px">
REGLA: BONO L.<?=BONO_MONTO?> POR PÓLIZA · SE CONSOLIDA A LOS <?=DIAS_RETENCION?> DÍAS · CANCELACIÓN ANTES = CHARGEBACK
</div>
<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center">
<select id="bono-filter-emp" onchange="filterBonos()" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase">
<option value="">TODOS LOS EMPLEADOS</option>
<?php foreach($agents as $ag):?><option value="<?=h(explode(' ',$ag['nombre'])[0])?>"><?=h(explode(' ',$ag['nombre'])[0])?></option><?php endforeach;?>
</select>
<select id="bono-filter-status" onchange="filterBonos()" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase">
<option value="">TODOS LOS ESTADOS</option>
<option>PENDIENTE</option><option>CONSOLIDADO</option><option>CHARGEBACK</option>
</select>
<button class="btn btn-gr btn-sm" onclick="loadBonos()">↻ ACTUALIZAR</button>
</div>
<div class="card">
<div class="card-header"><div class="card-title"> LIQUIDACIÓN DE INCENTIVOS</div><div id="bono-resumen" class="card-sub"></div></div>
<div style="overflow-x:auto"><table>
<tr><th>EMPLEADO</th><th>MIEMBRO</th><th>EFECTIVA</th><th>DÍAS ACTIVO</th><th>ESTADO</th><th>STATUS BONO</th><th>MONTO (L.)</th></tr>
<tbody id="bonos-tbody"><tr><td colspan="7" style="text-align:center;padding:20px;font-size:8px;color:#7A90A4;text-transform:uppercase">CARGANDO...</td></tr></tbody>
</table></div>
</div>

</div>
<div id="atab-IMPORTAR" style="display:none">
<div style="background:#EBF5FB;border:1px solid #A9D0E8;border-radius:10px;padding:9px 14px;font-size:8px;color:#1B5E8C;font-weight:800;letter-spacing:1px;text-transform:uppercase;margin-bottom:14px">
IMPORTAR PROSPECTOS DESDE CSV · FORMATO: Nombre, Apellido, Teléfono
</div>
<div class="card" style="max-width:520px">
<div class="card-header"><div class="card-title"> IMPORTAR CSV</div></div>
<div style="padding:16px">

<div class="form-group"><label class="form-label">ASIGNAR A EMPLEADO <span style="color:#7A90A4;font-weight:400">(OPCIONAL)</span></label>
<select id="import-agente" class="form-input">
<option value="0">— SIN ASIGNAR —</option>
<?php foreach($agents as $ag):?><option value="<?=$ag['id']?>"><?=h($ag['nombre'])?></option><?php endforeach;?>
</select>
</div>
<div class="form-group"><label class="form-label">ARCHIVO CSV</label>
<input type="file" id="import-file" accept=".csv" class="form-input" style="padding:6px">
</div>
<div style="background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:8px;padding:9px 12px;margin-bottom:11px">
<div style="font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">FORMATO ESPERADO</div>
<div style="font-size:9px;color:<?=$MU?>;font-family:monospace">Nombre, Apellido, Teléfono<br>María, González, (818)555-0142<br>Roberto, Flores, (323)555-0287</div>
</div>
<div id="import-result" style="display:none;margin-bottom:11px"></div>
<button class="btn btn-p btn-sm" onclick="importCSV()"> IMPORTAR PROSPECTOS</button>
</div>
</div>
</div>
<div id="atab-HISTORIAL" style="display:none">
<div style="display:flex;gap:7px;margin-bottom:12px;flex-wrap:wrap;align-items:center">
  <select id="hl-tipo" onchange="loadAuditLog()" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase">
    <option value="all">TODOS LOS TIPOS</option>
    <option value="SISTEMA">SISTEMA</option>
    <option value="NOTA">NOTA</option>
    <option value="RETENCION">RETENCIÓN</option>
    <option value="BONOS">BONOS</option>
    <option value="CITA">CITA</option>
    <option value="TICKET">TICKET</option>
    <option value="LLAMADA">LLAMADA</option>
  </select>
  <select id="hl-usuario" onchange="loadAuditLog()" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;font-weight:800;text-transform:uppercase">
    <option value="all">TODOS LOS USUARIOS</option>
    <?php foreach($users_all as $u_hl):?><option value="<?=$u_hl['id']?>"><?=strtoupper(h($u_hl['nombre']))?></option><?php endforeach;?>
  </select>
  <input type="date" id="hl-desde" onchange="loadAuditLog()" value="<?=date('Y-m-01')?>" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 10px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;outline:none">
  <input type="date" id="hl-hasta" onchange="loadAuditLog()" value="<?=date('Y-m-d')?>" style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 10px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;outline:none">
  <input type="text" id="hl-search" oninput="loadAuditLog()" placeholder="BUSCAR EN DESCRIPCIÓN..." style="border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:9px;background:#fff;font-family:'DM Sans',sans-serif;outline:none;text-transform:uppercase;min-width:180px">
</div>
<div class="card">
<div style="overflow-x:auto">
<table>
  <thead><tr><th>FECHA / HORA</th><th>USUARIO</th><th>TIPO</th><th>DESCRIPCIÓN</th><th>MIEMBRO</th></tr></thead>
  <tbody id="hl-tbody"><tr><td colspan="5" style="text-align:center;padding:24px;font-size:9px;color:<?=$MU?>;text-transform:uppercase">SELECCIONA UN FILTRO O ESPERA LA CARGA...</td></tr></tbody>
</table>
</div>
</div>
</div>
</div><!-- /ADMIN -->

</main>
<footer>MEDICARE WITH ISABEL · SCAN · ANTHEM · HUMANA · ALIGNMENT · LA CARE · HEALTH NET · MOLINA · UNITED HEALTHCARE · CA LIC #0D96598<?php if($admin):?><span onclick="openFinance()" style="margin-left:12px;cursor:pointer;color:rgba(200,223,240,.4)" title="Portal Financiero">◎</span><?php endif;?></footer>
<div id="toast" class="toast"></div>
<!-- MODALS -->
<div class="modal-overlay" id="member-form-modal" style="z-index:9600"><div class="modal"><div id="member-form-content"></div></div></div>
<div class="modal-overlay" id="profile-modal"><div class="modal" id="profile-content"></div></div>
<div class="modal-overlay" id="ticket-form-modal"><div class="modal" style="max-width:640px">
<div class="modal-header">
  <div class="modal-title" id="tkt-modal-title">◈ NUEVO TICKET</div>
  <button class="modal-close" onclick="closeModal('ticket-form-modal')">✕</button>
</div>
<form onsubmit="submitTicket(event)">
  <input type="hidden" name="id" id="tkt-id" value="">

  <!-- ─────────── DATOS DEL TICKET ─────────── -->
  <div class="form-group">
    <label class="form-label">MIEMBRO (buscar por nombre o tel.)</label>
    <div class="mpick-wrap">
      <input type="text" id="tkt-mpick-input" class="form-input" placeholder="Escribe nombre o teléfono para buscar..." autocomplete="off" oninput="mpickSearch('tkt-mpick-input','ticket-mid-sel','tkt-mpick-drop',this.value,false)">
      <input type="hidden" name="miembro_id" id="ticket-mid-sel" value="">
      <button type="button" class="mpick-clear" onclick="mpickClear('tkt-mpick-input','ticket-mid-sel','tkt-mpick-drop')" title="Limpiar">×</button>
      <div id="tkt-mpick-drop" class="mpick-drop"></div>
    </div>
  </div>
  <div class="form-group" id="tkt-cliente-wrap">
    <label class="form-label">NOMBRE CLIENTE (si no es miembro)</label>
    <input type="text" name="cliente" id="tkt-cliente" class="form-input" placeholder="Nombre del contacto (opcional)">
  </div>

  <div class="grid-2">
    <div class="form-group"><label class="form-label">TIPO *</label>
    <select name="tipo" id="tkt-tipo-sel" class="form-input" required>
      <optgroup label="── CON MIEMBRO ──">
        <?php foreach($TIPO_MIEMBRO as $tp):?><option value="<?=$tp?>"><?=$tp?></option><?php endforeach;?>
      </optgroup>
      <optgroup label="── TAREA GENERAL ──">
        <?php foreach($TIPO_TAREA as $tp):?><option value="<?=$tp?>"><?=$tp?></option><?php endforeach;?>
      </optgroup>
    </select>
    <div id="tkt-vista-hint" style="font-size:8px;color:<?=$MU?>;margin-top:3px;font-weight:700;letter-spacing:.5px"></div>
    </div>
    <div class="form-group"><label class="form-label">PRIORIDAD</label>
    <select name="prioridad" class="form-input"><option>ALTA</option><option selected>MEDIA</option><option>BAJA</option></select></div>
  </div>

  <div class="grid-2">
    <div class="form-group"><label class="form-label">FUENTE / ORIGEN</label>
    <select name="fuente" id="tkt-fuente" class="form-input" onchange="tktFuenteChange(this)">
      <option value="">— Seleccionar —</option>
      <?php foreach(['Nextiva','WhatsApp','CRM','Email','Referido','Citas AEP','Lista de Prospectos','Twilio','REPORTE DE APLICACIONES','FACEBOOK','Teams','Llamadas perdidas de Isabel','Otra'] as $f):?>
      <option value="<?=h($f)?>"><?=h($f)?></option>
      <?php endforeach;?>
    </select></div>
    <div class="form-group" id="tkt-ref-wrap" style="display:none">
      <label class="form-label">NOMBRE DE QUIEN REFIRIÓ</label>
      <input type="text" name="nombre_referencia" id="tkt-nref" class="form-input" placeholder="Nombre del referente">
    </div>
  </div>

  <div class="form-group"><label class="form-label">DESCRIPCIÓN *</label>
    <textarea name="descripcion" id="tkt-desc" class="form-input" rows="3" required placeholder="¿Qué necesita resolverse?"></textarea></div>

  <div class="grid-2">
    <div class="form-group"><label class="form-label">FECHA SEGUIMIENTO</label>
      <input type="date" name="fecha_seguimiento" id="tkt-fseg" class="form-input" value="<?=date('Y-m-d',strtotime('+7 days'))?>"></div>
    <div class="form-group"><label class="form-label">SLA (FECHA LÍMITE)</label>
      <input type="date" name="sla_fecha" id="tkt-sla" class="form-input"></div>
  </div>
  <div class="grid-2">
    <div class="form-group"><label class="form-label">ESTADO</label>
    <select name="estado" id="tkt-estado-sel" class="form-input" onchange="tktEstadoChange(this)">
      <option value="ABIERTO">ABIERTO</option><option value="PENDIENTE">PENDIENTE</option>
      <option value="EN PROCESO">EN PROCESO</option><option value="CERRADO">CERRADO</option>
    </select></div>
    <div class="form-group"><label class="form-label">ASIGNAR A</label>
    <select name="asignado_a" class="form-input">
      <option value="">— MISMO AGENTE —</option>
      <?php foreach($users_all as $ag):?><option value="<?=$ag['id']?>"><?=h(explode(' ',$ag['nombre'])[0])?><?=$ag['rol']==='admin'?' (ADMIN)':''?></option><?php endforeach;?>
    </select></div>
  </div>
  <div class="form-group" id="tkt-nota-wrap">
    <label class="form-label">NOTAS / ACTUALIZACIÓN</label>
    <textarea name="notas" id="tkt-notas" class="form-input" rows="2" placeholder="Actualización del trabajo en curso…"></textarea>
  </div>
  <div class="form-group" id="tkt-result-wrap">
    <label class="form-label">RESULTADO FINAL</label>
    <textarea name="resultado" id="tkt-resultado" class="form-input" rows="2" placeholder="Resultado / desenlace del ticket (se llena al cerrar)"></textarea>
  </div>

  <!-- ─────────── NEXT STEPS ─────────── -->
  <div id="tkt-nextsteps-wrap" style="margin-top:8px;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:11px;padding:11px 13px;display:none">
    <div style="display:flex;align-items:center;gap:7px;margin-bottom:9px">
      <span style="font-size:11px;font-weight:900;color:<?=$P1?>;letter-spacing:1.5px;text-transform:uppercase">→ NEXT STEPS</span>
      <span id="tkt-ns-count" style="background:#fff;border:1px solid <?=$CB?>;border-radius:20px;padding:1px 8px;font-size:8px;font-weight:900;color:<?=$P2?>">0</span>
      <span style="flex:1"></span>
      <span style="font-size:8px;color:<?=$MU?>;font-weight:700">Pasos / instrucciones a seguir</span>
    </div>
    <div id="tkt-ns-list" style="display:flex;flex-direction:column;gap:5px;margin-bottom:9px"></div>
    <div style="display:flex;gap:6px;align-items:flex-end">
      <div style="flex:2">
        <label class="form-label" style="font-size:8px">DESCRIPCIÓN DEL PASO</label>
        <input type="text" id="tkt-ns-desc-input" class="form-input" placeholder="Ej: Llamar al carrier para verificar coverage" onkeydown="if(event.key==='Enter'){event.preventDefault();addNextStep();}">
      </div>
      <div style="flex:1">
        <label class="form-label" style="font-size:8px">FECHA</label>
        <input type="date" id="tkt-ns-date-input" class="form-input">
      </div>
      <button type="button" class="btn btn-p btn-sm" onclick="addNextStep()" style="height:34px;white-space:nowrap">+ AGREGAR</button>
    </div>
    <div style="font-size:8px;color:<?=$MU?>;margin-top:6px;font-style:italic">
      ⚡ Al cerrar el ticket, todos los pasos pendientes se completan automáticamente
    </div>
  </div>

  <div style="display:flex;gap:7px;justify-content:flex-end;margin-top:11px">
    <button type="button" class="btn btn-gh btn-sm" onclick="closeModal('ticket-form-modal')">CANCELAR</button>
    <button type="submit" class="btn btn-b btn-sm">◈ GUARDAR</button>
  </div>
</form></div></div>
<!-- ══════════ MODAL: VER TICKET CERRADO ══════════ -->
<div class="modal-overlay" id="ticket-cerrado-modal">
  <div class="modal" style="max-width:640px">
    <div class="modal-header" style="background:#EAF5F0;border-bottom:2px solid #8DCFBA">
      <div class="modal-title" style="color:#1E7A5C">✓ TICKET CERRADO — <span id="tktc-id-lbl"></span></div>
      <button class="modal-close" onclick="closeModal('ticket-cerrado-modal')">✕</button>
    </div>
    <div style="padding:18px 22px;display:flex;flex-direction:column;gap:12px;max-height:75vh;overflow-y:auto">

      <!-- Encabezado: cliente + badges -->
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <div style="font-size:14px;font-weight:900;color:#1B3A5C" id="tktc-nombre-lbl">—</div>
        <span id="tktc-tipo-badge"></span>
        <span id="tktc-prio-badge"></span>
      </div>

      <!-- Grid de meta-datos -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 18px;background:#F8FBFD;border:1px solid #C8DFF0;border-radius:10px;padding:12px 16px">
        <div><div style="font-size:8px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:.8px">Fuente</div><div style="font-size:10px;font-weight:700;color:#1B3A5C;margin-top:2px" id="tktc-fuente-lbl">—</div></div>
        <div><div style="font-size:8px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:.8px">Responsable</div><div style="font-size:10px;font-weight:700;color:#1B3A5C;margin-top:2px" id="tktc-resp-lbl">—</div></div>
        <div><div style="font-size:8px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:.8px">Fecha creación</div><div style="font-size:10px;font-weight:700;color:#1B3A5C;margin-top:2px" id="tktc-created-lbl">—</div></div>
        <div><div style="font-size:8px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:.8px">Fecha cierre</div><div style="font-size:10px;font-weight:700;color:#1E7A5C;margin-top:2px" id="tktc-closed-lbl">—</div></div>
        <div><div style="font-size:8px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:.8px">SLA (fecha límite)</div><div style="font-size:10px;font-weight:700;color:#1B3A5C;margin-top:2px" id="tktc-sla-lbl">—</div></div>
        <div><div style="font-size:8px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:.8px">Seguimiento</div><div style="font-size:10px;font-weight:700;color:#1B3A5C;margin-top:2px" id="tktc-fseg-lbl">—</div></div>
      </div>

      <!-- Descripción -->
      <div>
        <div style="font-size:8px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px">📋 Descripción del problema</div>
        <div style="background:#fff;border:1px solid #C8DFF0;border-radius:8px;padding:11px 13px;font-size:10px;color:#1B3A5C;line-height:1.6;white-space:pre-wrap" id="tktc-desc-lbl"></div>
      </div>

      <!-- Notas -->
      <div id="tktc-notas-wrap">
        <div style="font-size:8px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px">💬 Notas / Actualizaciones</div>
        <div style="background:#FEF8EE;border:1px solid #F5D5A0;border-radius:8px;padding:11px 13px;font-size:10px;color:#1B3A5C;line-height:1.6;white-space:pre-wrap" id="tktc-notas-lbl"></div>
      </div>

      <!-- Resultado / Nota de cierre -->
      <div id="tktc-resultado-wrap">
        <div style="font-size:8px;font-weight:900;color:#1E7A5C;text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px">✅ Nota de cierre / Resultado</div>
        <div style="background:#EAF5F0;border:1px solid #8DCFBA;border-radius:8px;padding:11px 13px;font-size:10px;color:#1B3A5C;line-height:1.6;white-space:pre-wrap;font-weight:700" id="tktc-resultado-lbl"></div>
      </div>

      <!-- Next Steps -->
      <div id="tktc-nextsteps-wrap" style="display:none">
        <div style="font-size:8px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px">→ NEXT STEPS</div>
        <div id="tktc-ns-list" style="display:flex;flex-direction:column;gap:4px"></div>
      </div>

    </div>
    <div style="padding:12px 22px;border-top:1px solid #C8DFF0;display:flex;justify-content:flex-end">
      <button class="btn btn-gh btn-sm" onclick="closeModal('ticket-cerrado-modal')">CERRAR</button>
    </div>
  </div>
</div>
<!-- ══════════════════════════════════════════════ -->

<div class="modal-overlay" id="cita-form-modal">
  <div class="modal modal-sm">
    <div class="modal-header">
      <div class="modal-title" id="cita-modal-title">◷ NUEVA CITA</div>
      <button class="modal-close" onclick="closeModal('cita-form-modal')">✕</button>
    </div>
    <form onsubmit="submitCita(event)" id="cita-form">
      <input type="hidden" name="id" id="cita-id" value="">

      <!-- Switch miembro / cliente libre -->
      <div class="form-group">
        <div style="display:flex;gap:6px;margin-bottom:6px">
          <button type="button" class="cli-mode-btn active" data-mode="miembro" onclick="setCitaClienteMode('miembro')" style="flex:1;background:<?=$P1?>;color:#fff;border:none;border-radius:8px;padding:7px;font-size:8px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif;text-transform:uppercase">◉ MIEMBRO REGISTRADO</button>
          <button type="button" class="cli-mode-btn" data-mode="libre" onclick="setCitaClienteMode('libre')" style="flex:1;background:<?=$BG?>;color:<?=$P1?>;border:1px solid <?=$CB?>;border-radius:8px;padding:7px;font-size:8px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif;text-transform:uppercase">✎ NOMBRE LIBRE</button>
        </div>
      </div>

      <div class="form-group" id="cita-miembro-group">
        <label class="form-label">MIEMBRO (buscar por nombre o tel.)</label>
        <div class="mpick-wrap">
          <input type="text" id="cita-mpick-input" class="form-input" placeholder="Escribe nombre o teléfono para buscar..." autocomplete="off" oninput="mpickSearch('cita-mpick-input','cita-miembro-sel','cita-mpick-drop',this.value,false)">
          <input type="hidden" name="miembro_id" id="cita-miembro-sel" value="">
          <button type="button" class="mpick-clear" onclick="mpickClear('cita-mpick-input','cita-miembro-sel','cita-mpick-drop')" title="Limpiar">×</button>
          <div id="cita-mpick-drop" class="mpick-drop"></div>
        </div>
      </div>
      <div class="form-group" id="cita-cliente-group" style="display:none">
        <label class="form-label">NOMBRE DEL CLIENTE / PROSPECTO</label>
        <input type="text" name="cliente" id="cita-cliente-input" class="form-input" placeholder="Ej: Juan Pérez (818) 555-0000">
      </div>

      <div class="grid-2">
        <div class="form-group">
          <label class="form-label">TIPO</label>
          <select name="tipo" id="cita-tipo" class="form-input">
            <?php foreach(['PRESENTACIÓN','AEP','RETENCIÓN','SEGUIMIENTO','CITA DENTAL','T65','ENROLLMENT','EN CASA DEL PROSPECTO','OTRO'] as $t):?><option><?=$t?></option><?php endforeach;?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">MODALIDAD</label>
          <select name="modalidad" id="cita-modalidad" class="form-input">
            <option value="OFICINA">🏢 OFICINA</option>
            <option value="TELÉFONO">📞 TELÉFONO</option>
            <option value="VIDEO">📹 VIDEO</option>
          </select>
        </div>
      </div>

      <div class="grid-2">
        <div class="form-group">
          <label class="form-label">FECHA</label>
          <input type="date" name="fecha" id="cita-fecha" class="form-input" value="<?=$today?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">HORA</label>
          <input type="time" name="hora" id="cita-hora" class="form-input" value="09:00">
        </div>
      </div>

      <?php if($admin):?>
      <div class="form-group">
        <label class="form-label">RESPONSABLE</label>
        <select name="agente_id" id="cita-agente" class="form-input">
          <?php foreach($users_all as $u):?>
            <option value="<?=$u['id']?>" <?=$u['id']==$uid?'selected':''?>><?=h(explode(' ',$u['nombre'])[0])?><?=$u['rol']==='admin'?' (ADMIN)':''?></option>
          <?php endforeach;?>
        </select>
      </div>
      <?php endif;?>

      <div class="form-group">
        <label class="form-label">NOTAS</label>
        <textarea name="notas" id="cita-notas" class="form-input" rows="3" placeholder="Detalles, recordatorios, link de Zoom, dirección, etc." style="text-transform:none;font-family:'DM Sans',sans-serif"></textarea>
      </div>

      <div style="display:flex;gap:7px;justify-content:flex-end">
        <button type="button" class="btn btn-gh btn-sm" onclick="closeModal('cita-form-modal')">CANCELAR</button>
        <button type="submit" class="btn btn-b btn-sm">◷ GUARDAR</button>
      </div>
    </form>
  </div>
</div>
<div class="modal-overlay" id="llamada-form-modal"><div class="modal modal-sm"><div class="modal-header"><div class="modal-title">◌ REGISTRAR LLAMADA</div><button class="modal-close" onclick="closeModal('llamada-form-modal')">✕</button></div><form onsubmit="submitLlamada(event)"><div class="grid-2"><div class="form-group"><label class="form-label">NÚMERO *</label><input type="text" name="numero" class="form-input" placeholder="(818) 555-0000" required></div><div class="form-group"><label class="form-label">ORIGEN</label><select name="origen" class="form-input"><option>TWILIO</option><option>NEXTIVA</option><option>OTRO</option></select></div></div><div style="display:flex;gap:7px;justify-content:flex-end;margin-top:8px"><button type="button" class="btn btn-gh btn-sm" onclick="closeModal('llamada-form-modal')">CANCELAR</button><button type="submit" class="btn btn-b btn-sm">◌ REGISTRAR</button></div></form></div></div>
<?php if($admin):?><div class="modal-overlay" id="finance-modal"><div class="modal" style="max-width:900px;background:#0B1E3D;border:1px solid rgba(255,255,255,.1)"><div id="finance-login" style="text-align:center;padding:30px"><div style="font-size:11px;font-weight:900;color:#fff;letter-spacing:4px;text-transform:uppercase;margin-bottom:14px">◎ PORTAL FINANCIERO</div><div id="fin-err" style="display:none;background:rgba(184,50,50,.2);color:#FCA5A5;border:1px solid rgba(184,50,50,.3);border-radius:9px;padding:8px;font-size:9px;font-weight:900;margin-bottom:12px;text-transform:uppercase">CONTRASEÑA INCORRECTA</div><input type="password" id="fin-pwd" placeholder="••••••••" style="width:100%;max-width:280px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:9px;padding:10px 13px;font-size:13px;font-family:'DM Sans',sans-serif;outline:none;color:#fff;box-sizing:border-box;letter-spacing:2px;margin-bottom:10px;display:block;margin-left:auto;margin-right:auto"><button onclick="financeAuth()" style="background:rgba(196,154,42,.2);color:#E8C354;border:1px solid rgba(196,154,42,.3);border-radius:11px;padding:11px 30px;font-size:10px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif;letter-spacing:3px;text-transform:uppercase">INGRESAR →</button></div><div id="finance-content" style="display:none"><div style="padding:0 22px;display:flex;align-items:center;height:56px;border-bottom:1px solid rgba(255,255,255,.08)"><div style="font-size:11px;font-weight:900;color:#E8C354;letter-spacing:4px;text-transform:uppercase">◎ PORTAL FINANCIERO</div><div style="margin-left:auto;display:flex;gap:7px"><span style="background:rgba(30,122,92,.2);color:#6EE7B7;border:1px solid rgba(30,122,92,.3);border-radius:20px;padding:3px 11px;font-size:8px;font-weight:900;text-transform:uppercase"> ISABEL FUENTES</span><button onclick="closeFinance()" style="background:rgba(184,50,50,.2);color:#FCA5A5;border:1px solid rgba(184,50,50,.3);border-radius:9px;padding:5px 12px;font-size:8px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif;text-transform:uppercase">× SALIR</button></div></div><div id="fin-kpis" style="display:flex;gap:9px;flex-wrap:wrap;padding:16px 22px 0"></div><div style="padding:14px 22px"><div style="display:flex;border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:14px"><?php foreach(['RESUMEN','POR CARRIER','POR AGENTE','DISCREPANCIAS'] as $ft):?><button class="ntab<?=$ft==='RESUMEN'?' active':''?>" onclick="showFinTab('<?=$ft?>')" data-ftab="<?=$ft?>" style="color:rgba(255,255,255,.5);border-bottom-color:transparent"><?=$ft?></button><?php endforeach;?></div><div id="fin-table"></div></div></div><div style="text-align:right;padding:10px 22px;border-top:1px solid rgba(255,255,255,.08)"><button onclick="closeModal('finance-modal')" style="background:rgba(255,255,255,.1);color:rgba(255,255,255,.6);border:none;border-radius:9px;padding:6px 14px;font-size:8px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif;text-transform:uppercase">CERRAR</button></div></div></div><?php endif;?>

<!-- BATTLE PLAN MODAL — solo saludo, sin bloquear -->
<div id="battle-plan-modal" style="display:none;position:fixed;inset:0;background:rgba(27,74,107,.65);z-index:2000;align-items:center;justify-content:center;backdrop-filter:blur(8px)">
<div style="background:#fff;border-radius:17px;padding:30px 26px;width:100%;max-width:380px;box-shadow:0 24px 64px rgba(27,74,107,.35);border-top:5px solid <?=$P1?>;margin:20px;text-align:center">
<div style="font-size:28px;margin-bottom:10px"> </div>
<div style="font-size:13px;font-weight:900;color:<?=$P1?>;letter-spacing:2px;text-transform:uppercase;margin-bottom:6px">
HOLA <?=h(explode(' ',$user['nombre'])[0])?>, ¡TU PLAN DE HOY!
</div>
<div style="font-size:10px;color:<?=$MU?>;letter-spacing:1px;margin-bottom:20px"><?=$today?></div>
<div style="background:<?=$BG?>;border-radius:11px;padding:14px;margin-bottom:20px;text-align:left">
<div style="font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px">TUS ACTIVIDADES DE HOY:</div>
<?php
$acts=['◐ Haz tu CHECK-IN para empezar',' Revisa tus tickets urgentes',' Trabaja tu lista de prospectos',' Confirma citas de hoy y mañana','▦ Envía tu reporte antes de salir'];
if(!empty($alertas_hoy)&&$alertas_hoy>0) array_splice($acts,1,0,[" ".($alertas_hoy)." llamada".($alertas_hoy>1?'s':'')." de retención"]);
foreach($acts as $i=>$a):?>

<div style="display:flex;align-items:center;gap:9px;padding:7px 0;border-bottom:1px solid <?=$CB?>60">
<div style="width:20px;height:20px;border-radius:50%;background:<?=$P1?>;color:#fff;font-size:8px;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0"><?=$i+1?></div>
<div style="font-size:10px;font-weight:700;color:<?=$TX?>"><?=$a?></div>
</div>
<?php endforeach;?>
</div>
<button class="btn btn-p btn-full" style="font-size:12px;padding:14px" onclick="closeBattlePlan()">
¡VAMOS A TRABAJAR!
</button>
</div>
</div>
<!-- CHAT FAB -->
<button class="chat-fab" onclick="toggleChat()">💬<?php if($chat_unread>0):?><span class="chat-fab-badge"><?=$chat_unread?></span><?php endif;?></button>
<div id="chat-panel" class="chat-panel hidden">
<div style="background:<?=$P1?>;padding:11px 14px;border-radius:16px 16px 0 0">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
    <div><div style="font-size:9px;font-weight:900;color:#fff;letter-spacing:2px;text-transform:uppercase">💬 CHAT DEL EQUIPO</div><div style="font-size:7px;color:rgba(255,255,255,.5);text-transform:uppercase">MEDICARE WITH ISABEL</div></div>
    <button onclick="toggleChat()" style="background:rgba(255,255,255,.1);border:none;border-radius:7px;width:26px;height:26px;cursor:pointer;color:rgba(255,255,255,.7);font-size:12px">✕</button>
  </div>
  <!-- Chat tabs: GRUPO / DIRECTO -->
  <div style="display:flex;gap:3px">
    <button id="ctab-btn-GRUPO" onclick="switchChatTab('GRUPO')" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:7px;padding:4px 11px;font-size:8px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif;letter-spacing:1px;text-transform:uppercase">👥 GRUPO</button>
    <button id="ctab-btn-DM" onclick="switchChatTab('DM')" style="background:rgba(255,255,255,.08);color:rgba(255,255,255,.6);border:none;border-radius:7px;padding:4px 11px;font-size:8px;font-weight:900;cursor:pointer;font-family:'DM Sans',sans-serif;letter-spacing:1px;text-transform:uppercase">✉ DIRECTO</button>
  </div>
</div>

<!-- GRUPO TAB -->
<div id="chat-tab-GRUPO" style="display:flex;flex-direction:column;flex:1;overflow:hidden">
  <div id="chat-messages" class="chat-messages">
  <?php foreach($chat_msgs as $cm):$isMe=$cm['user_id']==$uid;?><div class="chat-msg <?=$isMe?'me':'them'?>" data-id="<?=$cm['id']?>"><div class="chat-msg-meta"><?=$isMe?'TÚ':h(explode(' ',$cm['nombre']??'')[0])?> · <?=date('H:i',strtotime($cm['created_at']))?></div><?=h($cm['mensaje'])?></div><?php endforeach;?>
  <?php if(!count($chat_msgs)):?><div style="text-align:center;color:<?=$MU?>;font-size:8px;padding:20px;text-transform:uppercase">¡INICIA LA CONVERSACIÓN!</div><?php endif;?>
  </div>
  <div style="display:flex;gap:6px;padding:10px;border-top:1px solid <?=$CB?>"><input type="text" id="chat-input" placeholder="ESCRIBE UN MENSAJE..." style="flex:1;border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:11px;font-family:'DM Sans',sans-serif;outline:none;background:<?=$BG?>" onkeydown="if(event.key==='Enter')sendChat()"><button class="btn btn-p btn-sm" onclick="sendChat()" title="Enviar">▶</button></div>
</div>

<!-- DM TAB -->
<div id="chat-tab-DM" style="display:none;flex-direction:column;flex:1;overflow:hidden">
  <div style="padding:8px 10px;border-bottom:1px solid <?=$CB?>;background:#F8FAFC">
    <select id="dm-target" class="form-input" style="font-size:9px;padding:5px 9px" onchange="loadDMs()">
      <option value="">— SELECCIONA PERSONA —</option>
      <?php foreach($users_all as $u): if($u['id']==$uid) continue; ?>
      <option value="<?=$u['id']?>"><?=h($u['nombre'])?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div id="dm-messages" class="chat-messages" style="min-height:120px">
    <div style="text-align:center;color:<?=$MU?>;font-size:8px;padding:20px;text-transform:uppercase">Selecciona una persona arriba</div>
  </div>
  <div style="display:flex;gap:6px;padding:10px;border-top:1px solid <?=$CB?>"><input type="text" id="dm-input" placeholder="MENSAJE PRIVADO..." style="flex:1;border:1.5px solid <?=$CB?>;border-radius:9px;padding:7px 11px;font-size:11px;font-family:'DM Sans',sans-serif;outline:none;background:<?=$BG?>" onkeydown="if(event.key==='Enter')sendDM()"><button class="btn btn-p btn-sm" onclick="sendDM()" title="Enviar">▶</button></div>
</div>
</div>



<script>
const ADMIN=<?=$admin?'true':'false'?>;const UID=<?=$uid?>;
// ── CSRF: todo POST por fetch lleva el token de la sesión (api.php lo verifica) ──
const CSRF_TOKEN='<?=h($_SESSION['csrf_token'] ?? '')?>';
(function(){
  const _fetch = window.fetch;
  window.fetch = function(input, init){
    init = init || {};
    if ((init.method||'GET').toUpperCase() === 'POST') {
      try {
        if (init.headers instanceof Headers) init.headers.set('X-CSRF-Token', CSRF_TOKEN);
        else init.headers = Object.assign({}, init.headers||{}, {'X-CSRF-Token': CSRF_TOKEN});
      } catch(e){}
    }
    return _fetch.call(this, input, init);
  };
})();
let chatLastId=<?=count($chat_msgs)&&end($chat_msgs)?end($chat_msgs)['id']:0?>;
function toast(msg,dur=2800){const t=document.getElementById('toast');t.textContent=msg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),dur);}
function showTab(id){
document.querySelectorAll('.tab-pane').forEach(p=>p.style.display='none');
document.querySelectorAll('.ntab[data-tab]').forEach(b=>b.classList.remove('active'));
const el=document.getElementById('tab-'+id);if(el)el.style.display='block';
document.querySelectorAll('.ntab[data-tab="'+id+'"]').forEach(b=>b.classList.add('active'));
const names={DASHBOARD:'DASHBOARD','MI DÍA':'MI DÍA',PLANEACION:'PLANEACIÓN',MIEMBROS:'MIEMBROS',RETENCION:'RETENCIÓN',PORTALES:'PORTALES',PIPELINE:'PIPELINE',CAMPANAS:'CAMPAÑAS',CITAS:'CITAS',TICKETS:'TICKETS/TASK',ASISTENCIA:'ASISTENCIA',ROLES:'ROLES',POLIZAS:'PÓLIZAS',BONOS:'MIS BONOS',COMUNICACION:'COMUNICACIÓN',RECURSOS:'RECURSOS',CONTACTOS:'CONTACTOS',REPORTES:'REPORTES',GASTOS:'GASTOS',REUNIONES:'REUNIONES',ENTRENAMIENTO:'ENTRENAMIENTO',ADMIN:'ADMIN'};
const icons={DASHBOARD:'▣','MI DÍA':'📋',PLANEACION:'🧭',MIEMBROS:'◉',RETENCION:'📞',PORTALES:'🖥',PIPELINE:'▲',CAMPANAS:'📣',CITAS:'◷',TICKETS:'◈',ASISTENCIA:'◐',ROLES:'🧩',POLIZAS:'◎',BONOS:'◈',COMUNICACION:'◌',RECURSOS:'◍',CONTACTOS:'🤝',REPORTES:'▦',GASTOS:'💰',REUNIONES:'📅',ENTRENAMIENTO:'🎓',ADMIN:'⊞'};
document.getElementById('tab-icon').textContent=icons[id]||'▪';
document.getElementById('tab-title').textContent=names[id]||id;
if(id==='BONOS') loadBonos();
if(id==='GASTOS') loadGastos();
if(id==='TICKETS'){ filterTickets(); setTktVista(_tktVista); }
if(id==='MI DÍA' && window._refreshChecklist) setTimeout(window._refreshChecklist, 50);
try{sessionStorage.setItem('activeTab',id);}catch(e){}
}
function irAMiembros(estado) {
    showTab('MIEMBROS'); // Cambia a la pestaña de miembros
    // Busca el botón "pill" de ese estado y le hace clic automáticamente
    const pill = document.querySelector('.pill-btn[data-estado="' + estado + '"]');
    if(pill) {
        setPill(pill);
    }
}
function showComTab(id){['SMS','LLAMADAS','EMAILS','HISTORIAL'].forEach(t=>{const el=document.getElementById('ctab-'+t);if(el)el.style.display=t===id?'':'none';});document.querySelectorAll('.ntab[data-ctab]').forEach(b=>b.classList.toggle('active',b.dataset.ctab===id));}
function showRecTab(id){['SCRIPTS','PLANTILLAS SMS','PROMPTS IA','SECUENCIAS','CARRIERS','PORTALES','SOPs'].forEach(t=>{const el=document.getElementById('rtab-'+t);if(el)el.style.display=t===id?'':'none';});document.querySelectorAll('.ntab[data-rtab]').forEach(b=>b.classList.toggle('active',b.dataset.rtab===id));}
function showScriptTab(id){document.querySelectorAll('.script-tab-content').forEach(e=>e.style.display='none');document.querySelectorAll('.ntab[data-stab]').forEach(b=>b.classList.remove('active'));const el=document.getElementById('stab-'+id);if(el)el.style.display='';document.querySelector('.ntab[data-stab="'+id+'"]')?.classList.add('active');}
function showAdminTab(id){
  if(id==='INCENTIVOS') loadBonosIncentivos();
  if(id==='HISTORIAL') loadAuditLog();
  ['EMPLEADOS','CERTIFICACIONES','CONTRASEÑAS','METAS','NOTIFICACIONES','INCENTIVOS','IMPORTAR','HISTORIAL'].forEach(t=>{const el=document.getElementById('atab-'+t);if(el)el.style.display=t===id?'':'none';});
  document.querySelectorAll('.ntab[data-atab]').forEach(b=>b.classList.toggle('active',b.dataset.atab===id));
}
function registroHora(){
// Auto-detecta el siguiente paso según lo que ya está registrado
const steps = ['ci','lo','li','bo','bi','co'];
const labels = {ci:'CHECK-IN',lo:'SALIDA ALMUERZO',li:'REGRESO ALMUERZO',bo:'SALIDA BREAK',bi:'REGRESO BREAK',co:'CHECK-OUT'};

// Read current values from the step buttons
let nextField = null;
const stepsEl = document.querySelectorAll('.ci-step');
// Find first step that shows '--:--' (not yet recorded)
for(const s of steps){
// Check if any step div shows '--:--'
const allText = Array.from(document.querySelectorAll('.ci-step-val,.ci-step')).map(e=>e.textContent);
// Simpler: just call API to get today's record and detect next
break;
}
// Detect from the libre buttons: find first one with '--:--'
const libreBtns = document.querySelectorAll('.btn[onclick^="doCheckin"]');
for(const btn of libreBtns){
const timeSpan = btn.querySelector('span:last-child');
if(timeSpan && timeSpan.textContent.trim()==='--:--'){
const match = btn.getAttribute('onclick').match(/doCheckin\('(\w+)'\)/);
if(match){ nextField = match[1]; break; }
}
}
if(!nextField){ toast('✓ TODOS LOS REGISTROS DEL DÍA COMPLETADOS'); return; }
if(confirm('¿Registrar ' + labels[nextField] + ' ahora?')){ doCheckin(nextField); }
}
// ── VOZ DEL BOT ────────────────────────────────────────
const NOMBRE_USUARIO = "<?=h(explode(' ',$user['nombre'])[0])?>";
const TICKETS_HOY = <?=$open_tks?>;
const ALERTAS_HOY = <?=(int)($alertas_hoy??0)?>;
const HAY_REPORTE = <?=(!$admin&&$my_reporte&&$my_reporte['enviado'])?'true':'false'?>;
const HAY_CHECKIN = <?=(!empty($my_ci['check_in']))?'true':'false'?>;
function hablar(texto){
if(!window.speechSynthesis) return;
window.speechSynthesis.cancel();
const u = new SpeechSynthesisUtterance(texto);
u.lang='es-US'; u.pitch=1.1; u.rate=0.92;
const voices = window.speechSynthesis.getVoices();
const v = voices.find(v=>v.lang.startsWith('es')&&v.name.includes('female'))||voices.find(v=>v.lang.startsWith('es'))||null;
if(v) u.voice=v;
window.speechSynthesis.speak(u);
}
// Saludo al cargar (requiere primer clic por política del navegador)
window.addEventListener('DOMContentLoaded',()=>{
  const _vk=`greeted_${UID}_<?=date('Y-m-d')?>`;
  if(sessionStorage.getItem(_vk))return;
  sessionStorage.setItem(_vk,'1');
  let msg = `Hola ${NOMBRE_USUARIO}, bienvenida. `;
if(ALERTAS_HOY>0){
msg += `Tienes ${ALERTAS_HOY} llamadas de retención pendientes. `;
} else {
msg += '¡Buen trabajo! ';
}
msg += 'Recuerda hacer tu check-in y enviar tu reporte al final del día.';
document.body.addEventListener('click',()=>{ if(!window.speechSynthesis.speaking) hablar(msg); },{once:true});
});
// ── CHECK-IN con voces ─────────────────────────────────
function doCheckin(field){
if(field==='co'){
if(!HAY_REPORTE){
hablar(`¡Espera ${NOMBRE_USUARIO}! Aún no has enviado tu reporte diario. Es muy importante para Isabel.`);
if(!confirm(' No has enviado tu reporte del día.\n¿Quieres marcar salida sin reportar?')){
showTab('COMUNICACION'); showComTab('LLAMADAS'); return;
}
} else {
hablar(`Excelente trabajo hoy, ${NOMBRE_USUARIO}. Descansa y nos vemos mañana.`);
}
}
fetch('api.php',{method:'POST',body:new URLSearchParams({action:'checkin',field})})
.then(r=>r.json()).then(d=>{
if(d.ok){const h=d.data?.hora||d.data?.time||'';toast('✓ '+h);setTimeout(()=>softReload(),400);}
else toast(d.error||'Error');
});
}
// ── BATTLE PLAN ────────────────────────────────────────
window.addEventListener('load',()=>{
if(!HAY_CHECKIN && !ADMIN){
const m = document.getElementById('battle-plan-modal');
if(m) m.style.display='flex';
}
});
function closeBattlePlan(){
const m=document.getElementById('battle-plan-modal');
if(m)m.style.display='none';
hablar('¡Vamos ' + NOMBRE_USUARIO + '! Recuerda hacer tu check-in primero.');
toast('¡BIENVENIDA '+NOMBRE_USUARIO.toUpperCase()+'!');
}
// ── ALERTA DE SEGUIMIENTO (5 min después) ─────────────
setTimeout(()=>{
if(window.speechSynthesis.speaking) return;
const follows = <?=count(array_filter($members,fn($m)=>$m['estado']==='IN PROCESS'))?>;
if(follows>0){
let msg=`Atención ${NOMBRE_USUARIO}. `;
msg+=`Tienes ${follows} prospectos en seguimiento esperando tu llamada. No los dejes enfriar. `;
hablar(msg);
}
}, 300000); // 5 minutos
function smartSearch(){
const filter=document.getElementById('member-search')?.value.toLowerCase().trim()||'';
const rows=document.querySelectorAll('.member-row');
let count=0;
rows.forEach(r=>{
const searchText=r.getAttribute('data-search')||'';
const est=document.getElementById('filter-estado')?.value||'';
const ag='';
const match=(!filter||searchText.includes(filter))&&(!est||r.dataset.estado===est);
r.style.display=match?'':'none';
if(match){count++;if(filter)r.classList.add('search-match');setTimeout(()=>r.classList.remove('search-match'),600);}
});
const mc=document.getElementById('member-count');if(mc)mc.textContent=count;
}
function filterMembers(){smartSearch();}
function filterMembers(){const q=document.getElementById('member-search')?.value.toLowerCase()||'';const est=document.getElementById('filter-estado')?.value||'';const ag='';let c=0;document.querySelectorAll('.member-row').forEach(r=>{const m=(!q||r.dataset.search.includes(q))&&(!est||r.dataset.estado===est)&&(!ag||r.dataset.agente===ag);r.style.display=m?'':'none';if(m)c++;});const mc=document.getElementById('member-count');if(mc)mc.textContent=c;}
// Estado del filtro de mes
let _currentMes = '';

function setMesPill(btn) {
    _currentMes = btn.dataset.mes || '';
    document.querySelectorAll('.mes-pill').forEach(b => {
        const on = b.dataset.mes === _currentMes;
        b.style.background = on ? '<?=$P1?>' : '#fff';
        b.style.color      = on ? '#fff' : '<?=$MU?>';
    });
    // Re-aplicar filtro de estado actual
    const activePill = document.querySelector('.pill-btn.active');
    if (activePill) setPill(activePill);
}

function setPill(btn){
    document.querySelectorAll('.pill-btn').forEach(b=>{b.style.background='#fff';b.style.color='#7A90A4';b.style.borderColor='#C8DFF0';});
    btn.style.background='#1B4A6B';btn.style.color='#fff';btn.style.borderColor='#1B4A6B';
    
    const est = btn.dataset.estado;
    const nextMonth = '<?= date('Y-m', strtotime('first day of next month')) ?>';
    let count = 0;

    document.querySelectorAll('.member-row').forEach(r=>{
        let match = false;
        if(est === 'FUTUROS'){
            // Cualquier estado con fecha_efectiva el próximo mes
            match = r.dataset.fecha && r.dataset.fecha.startsWith(nextMonth);
        } else {
            // Lógica normal para el resto de botones
            match = (!est || r.dataset.estado === est);
        }
        
        // También aplicar filtro de mes si está activo
        if (match && _currentMes) {
            match = r.dataset.mes === _currentMes;
        }
        r.style.display = match ? '' : 'none';
        if(match) count++;
    });

    const mc = document.getElementById('member-count');
    if(mc) mc.textContent = count;
    
    const fe = document.getElementById('filter-estado');
    if(fe) fe.value = est === 'FUTUROS' ? '' : est;
}
function filterPolizas(){const c=document.getElementById('pol-carrier')?.value.toLowerCase()||'';const e=document.getElementById('pol-estado')?.value.toLowerCase()||'';document.querySelectorAll('.pol-row').forEach(r=>{r.style.display=(!c||r.dataset.carrier.includes(c))&&(!e||r.dataset.estado.includes(e))?'':'none';});}
// ── TICKETS FILTROS Y VISTA ───────────────────────────────────────────
let _tktFiltroEstado = 'ACTIVOS'; // por defecto: solo activos
let _tktVista        = 'miembro'; // por defecto: tickets de miembros

function setTktVista(vista){
  _tktVista = vista;
  // Estilos de los tabs
  const P1 = '<?=$P1?>', MU = '<?=$MU?>', BG = '<?=$BG?>', CB = '<?=$CB?>';
  const tabs = {miembro:'vtab-miembro', tarea:'vtab-tarea', proyecto:'vtab-proyecto'};
  for(const [v,id] of Object.entries(tabs)){
    const btn = document.getElementById(id);
    if(!btn) continue;
    const on = (v===vista);
    btn.style.background = on ? P1 : '#fff';
    btn.style.color      = on ? '#fff' : MU;
    const cnt = document.getElementById(id+'-cnt');
    if(cnt){ cnt.style.background = on?'rgba(255,255,255,.25)':BG; cnt.style.border = on?'none':'1px solid '+CB; cnt.style.color = on?'#fff':MU; }
  }
  // Mostrar/ocultar bloques de tickets vs panel de proyectos
  const esProy = (vista==='proyecto');
  document.querySelectorAll('.tkt-only').forEach(el=>{ el.style.display = esProy ? 'none' : ''; });
  const pp = document.getElementById('proyectos-panel');
  if(pp) pp.style.display = esProy ? '' : 'none';
  if(esProy){ loadProyectos(); }
  else { filterTickets(); }
}

// ════════════════════════════════════════════════════════════════
//  PROYECTOS  —  lógica de cliente
// ════════════════════════════════════════════════════════════════
let _proyData = [];
let _proyFiltro = 'ACTIVOS';
let _proyDetailId = null;
const _PROY_EST_COLOR = {'PLANIFICANDO':'#8896A5','EN PROGRESO':'#1B5E8C','CONTINUO':'#5B3FAF','PAUSADO':'#C07A1A','COMPLETADO':'#1E7A5C'};
const _PROY_PRIO_COLOR = {'ALTA':'#B83232','MEDIA':'#C07A1A','BAJA':'#2876A8'};

function pEsc(s){return (s==null?'':String(s)).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function teamAvatars(equipoStr){
  if(!equipoStr) return '';
  const arr=String(equipoStr).split('|').filter(Boolean);
  if(!arr.length) return '';
  const show=arr.slice(0,4).map(s=>{const i=s.indexOf(':');const ini=i>=0?s.slice(0,i):s;const col=i>=0?s.slice(i+1):'#1B5E8C';
    return `<span title="${pEsc(ini)}" style="display:inline-flex;width:17px;height:17px;border-radius:50%;background:${pEsc(col)};color:#fff;font-size:7px;font-weight:900;align-items:center;justify-content:center;border:1.5px solid #fff;margin-left:-5px">${pEsc(ini)}</span>`;}).join('');
  const extra=arr.length>4?`<span style="font-size:8px;color:#8896A5;margin-left:3px">+${arr.length-4}</span>`:'';
  return `<span style="display:inline-flex;align-items:center;margin-left:2px">${show}${extra}</span>`;
}
function fileIcon(name){
  const e=((name||'').split('.').pop()||'').toLowerCase();
  if(['jpg','jpeg','png','gif','webp'].includes(e))return '🖼️';
  if(e==='pdf')return '📄';
  if(['doc','docx'].includes(e))return '📝';
  if(['xls','xlsx','csv'].includes(e))return '📊';
  if(['ppt','pptx'].includes(e))return '📑';
  if(e==='zip')return '🗜️';
  return '📎';
}

function loadProyectos(){
  fetch('api.php?action=list_proyectos').then(r=>r.json()).then(d=>{
    if(!d.ok){ toast(d.error||'Error al cargar proyectos'); return; }
    _proyData = d.data||[];
    const cnt = document.getElementById('vtab-proyecto-cnt');
    if(cnt) cnt.textContent = _proyData.filter(p=>p.estado!=='COMPLETADO').length;
    renderProyResumen();
    renderProyectos();
  }).catch(()=>toast('Error de conexión'));
}

// Resumen por estado (solo admin): total, en curso y desglose por estado
function renderProyResumen(){
  const box = document.getElementById('proy-resumen');
  if(!box) return;
  const estados = ['EN PROGRESO','PLANIFICANDO','CONTINUO','PAUSADO','COMPLETADO'];
  const labels  = {'EN PROGRESO':'En progreso','PLANIFICANDO':'Planificando','CONTINUO':'Continuo','PAUSADO':'Pausado','COMPLETADO':'Completados'};
  const counts = {}; estados.forEach(e=>counts[e]=0);
  _proyData.forEach(p=>{ if(counts[p.estado]!=null) counts[p.estado]++; });
  const total   = _proyData.length;
  const activos = _proyData.filter(p=>p.estado!=='COMPLETADO').length;
  let html = `<div style="display:flex;align-items:center;gap:7px;background:<?=$P1?>;color:#fff;border-radius:10px;padding:7px 13px;font-size:9px;font-weight:900;letter-spacing:.5px"><span style="font-size:13px">📁</span> ${total} PROYECTO${total!==1?'S':''} · ${activos} EN CURSO</div>`;
  estados.forEach(e=>{
    if(!counts[e]) return; // no mostrar estados en cero (más limpio)
    const c = _PROY_EST_COLOR[e] || '#8896A5';
    html += `<div style="display:flex;align-items:center;gap:6px;background:#fff;border:1px solid ${c}40;border-radius:10px;padding:7px 11px;font-size:9px;font-weight:800;color:<?=$TX?>;text-transform:uppercase">
      <span style="width:9px;height:9px;border-radius:50%;background:${c};display:inline-block"></span>
      ${labels[e]} <span style="background:${c};color:#fff;border-radius:20px;padding:1px 7px;font-weight:900">${counts[e]}</span>
    </div>`;
  });
  // Cualquier proyecto con un estado fuera de la lista (no quede sin contar)
  const otros = total - estados.reduce((s,e)=>s+counts[e],0);
  if(otros>0){
    html += `<div style="display:flex;align-items:center;gap:6px;background:#fff;border:1px solid #8896A540;border-radius:10px;padding:7px 11px;font-size:9px;font-weight:800;color:<?=$TX?>;text-transform:uppercase">
      <span style="width:9px;height:9px;border-radius:50%;background:#8896A5;display:inline-block"></span>
      Otros / sin estado <span style="background:#8896A5;color:#fff;border-radius:20px;padding:1px 7px;font-weight:900">${otros}</span>
    </div>`;
  }
  box.innerHTML = html;
}

function setProyFiltro(f){
  _proyFiltro=f;
  document.querySelectorAll('#proyectos-panel .tkt-pill').forEach(p=>p.classList.remove('tkt-pill-on'));
  const b=document.getElementById('ppill-'+f); if(b)b.classList.add('tkt-pill-on');
  renderProyectos();
}

function proyOrdSort(a,b){ return ((parseInt(a.orden)||0)-(parseInt(b.orden)||0)) || (a.id-b.id); }

function proyCardHTML(p, canUp, canDown){
  const pc=Math.max(0,Math.min(100,parseInt(p.progreso)||0));
  const ec=_PROY_EST_COLOR[p.estado]||'#8896A5';
  const resp = p.asig_nombre ? p.asig_nombre.split(' ')[0] : (p.creador_nombre?p.creador_nombre.split(' ')[0]:'—');
  const vence = p.fecha_limite ? `<span style="font-size:8px;color:#8896A5">📅 ${pEsc(p.fecha_limite)}</span>` : '';
  const foco = parseInt(p.es_foco)===1;
  const completed = p.estado==='COMPLETADO';
  const btn = (label,title,extra,enabled,onclick)=>`<button title="${title}" ${enabled?'':'disabled'} onclick="${enabled?onclick:''}" style="background:#fff;border:1px solid <?=$CB?>;color:${enabled?'<?=$P2?>':'#CBD5E0'};border-radius:7px;width:22px;height:22px;cursor:${enabled?'pointer':'default'};font-size:10px;line-height:1;padding:0;${extra}">${label}</button>`;
  const focoBtn = `<button title="${foco?'Quitar foco':'Marcar como foco actual'}" onclick="setFocoProyecto(${p.id},event)" style="background:${foco?'#C9A227':'#fff'};border:1px solid ${foco?'#C9A227':'<?=$CB?>'};color:${foco?'#fff':'#C9A227'};border-radius:7px;width:22px;height:22px;cursor:pointer;font-size:11px;line-height:1;padding:0">★</button>`;
  const ctrl = completed ? '' : `<div style="display:flex;gap:3px;align-items:center;flex-shrink:0" onclick="event.stopPropagation()">
      ${focoBtn}
      ${btn('▲','Subir','',canUp,`moveProyecto(${p.id},'up',event)`)}
      ${btn('▼','Bajar','',canDown,`moveProyecto(${p.id},'down',event)`)}
    </div>`;
  const borderCol = foco ? '#C9A227' : '<?=$CB?>';
  const focoShadow = foco ? '0 0 0 2px rgba(201,162,39,.28)' : 'none';
  return `<div onclick="openProyectoDetail(${p.id})" style="cursor:pointer;background:#fff;border:1px solid ${borderCol};border-left:3px solid ${_PROY_PRIO_COLOR[p.prioridad]||'#2876A8'};border-radius:13px;padding:14px;transition:box-shadow .15s;box-shadow:${focoShadow}" onmouseover="this.style.boxShadow='0 6px 18px rgba(27,74,107,.12)'" onmouseout="this.style.boxShadow='${focoShadow}'">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:6px">
        <div style="font-weight:900;font-size:12px;color:<?=$P1?>;line-height:1.25">${foco?'⭐ ':''}${pEsc(p.titulo)}</div>
        ${ctrl}
      </div>
      ${p.descripcion?`<div style="font-size:9px;color:<?=$MU?>;margin-bottom:9px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">${pEsc(p.descripcion)}</div>`:''}
      <div style="height:7px;background:<?=$BG?>;border-radius:20px;overflow:hidden;margin-bottom:5px">
        <div style="height:100%;width:${pc}%;background:${ec};border-radius:20px;transition:width .3s"></div>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center">
        <span style="font-size:8px;font-weight:900;color:${ec}">${pc}%</span>
        <div style="display:flex;gap:8px;align-items:center">
          ${vence}
          <span style="font-size:8px;color:#8896A5;display:inline-flex;align-items:center">👤 ${pEsc(resp)}${teamAvatars(p.equipo)}</span>
          ${parseInt(p.n_archivos)>0?`<span style="font-size:8px;color:#8896A5">📎 ${p.n_archivos}</span>`:''}
          ${parseInt(p.n_avances)>0?`<span style="font-size:8px;color:#8896A5">💬 ${p.n_avances}</span>`:''}
        </div>
      </div>
    </div>`;
}

function renderProyectos(){
  const grid=document.getElementById('proy-grid');
  const empty=document.getElementById('proy-empty');
  if(!grid)return;
  const q=(document.getElementById('proy-search')?.value||'').toLowerCase();
  const matchQ = p => !q || ((p.titulo||'')+' '+(p.descripcion||'')+' '+(p.asig_nombre||'')).toLowerCase().includes(q);
  const pool=_proyData.filter(p=>{
    if(_proyFiltro==='ACTIVOS' && p.estado==='COMPLETADO') return false;
    if(_proyFiltro==='COMPLETADO' && p.estado!=='COMPLETADO') return false;
    return matchQ(p);
  });
  if(!pool.length){ grid.innerHTML=''; grid.style.display='none'; empty.style.display=''; return; }
  empty.style.display='none';
  grid.style.display='block';

  const gridCSS='display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px';
  let html='';

  // ⭐ Foco actual arriba
  const foco=pool.find(p=>parseInt(p.es_foco)===1 && p.estado!=='COMPLETADO');
  if(foco){
    html+=`<div style="margin-bottom:16px">
      <div style="font-size:9px;font-weight:900;color:#C9A227;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:7px">⭐ En esto estamos ahora</div>
      <div style="${gridCSS}">${proyCardHTML(foco,false,false)}</div>
    </div>`;
  }

  // Secciones por estado
  const order = _proyFiltro==='COMPLETADO'
      ? ['COMPLETADO']
      : (_proyFiltro==='TODOS' ? ['EN PROGRESO','PLANIFICANDO','CONTINUO','PAUSADO','COMPLETADO'] : ['EN PROGRESO','PLANIFICANDO','CONTINUO','PAUSADO']);
  const labels={'EN PROGRESO':'🔵 En progreso','PLANIFICANDO':'⚪ Planificando','CONTINUO':'🔁 Continuo (en curso)','PAUSADO':'🟠 Pausado','COMPLETADO':'✅ Completados'};
  order.forEach(est=>{
    const group=pool.filter(p=>p.estado===est && !(foco&&p.id===foco.id)).sort(proyOrdSort);
    if(!group.length) return;
    const ec=_PROY_EST_COLOR[est]||'#8896A5';
    html+=`<div style="margin-bottom:18px">
      <div style="display:flex;align-items:center;gap:7px;margin-bottom:9px">
        <span style="font-size:10px;font-weight:900;color:${ec};text-transform:uppercase;letter-spacing:1.5px">${labels[est]||est}</span>
        <span style="font-size:8px;font-weight:900;color:#8896A5;background:<?=$BG?>;border-radius:20px;padding:1px 8px">${group.length}</span>
      </div>
      <div style="${gridCSS}">${group.map((p,i)=>proyCardHTML(p, i>0, i<group.length-1)).join('')}</div>
    </div>`;
  });

  // Catch-all: proyectos con un estado fuera de la lista, para que no queden ocultos
  if(_proyFiltro!=='COMPLETADO'){
    const conocidos = ['EN PROGRESO','PLANIFICANDO','CONTINUO','PAUSADO','COMPLETADO'];
    const otros = pool.filter(p=> conocidos.indexOf(p.estado)<0 && !(foco&&p.id===foco.id)).sort(proyOrdSort);
    if(otros.length){
      html+=`<div style="margin-bottom:18px">
        <div style="display:flex;align-items:center;gap:7px;margin-bottom:9px">
          <span style="font-size:10px;font-weight:900;color:#8896A5;text-transform:uppercase;letter-spacing:1.5px">◻ Otros / sin estado</span>
          <span style="font-size:8px;font-weight:900;color:#8896A5;background:<?=$BG?>;border-radius:20px;padding:1px 8px">${otros.length}</span>
        </div>
        <div style="${gridCSS}">${otros.map((p,i)=>proyCardHTML(p, i>0, i<otros.length-1)).join('')}</div>
      </div>`;
    }
  }
  grid.innerHTML=html;
}

function moveProyecto(id, dir, ev){
  if(ev) ev.stopPropagation();
  const p=_proyData.find(x=>x.id==id); if(!p) return;
  const group=_proyData.filter(x=>x.estado===p.estado).sort(proyOrdSort);
  const idx=group.findIndex(x=>x.id==id);
  const j = dir==='up' ? idx-1 : idx+1;
  if(j<0 || j>=group.length) return;
  [group[idx],group[j]]=[group[j],group[idx]];
  group.forEach((g,i)=>{ g.orden=i; });   // mutamos los objetos de _proyData
  renderProyectos();
  fetch('api.php',{method:'POST',body:new URLSearchParams({action:'save_proyecto_orden', ids:JSON.stringify(group.map(g=>g.id))})})
    .then(r=>r.json()).then(d=>{ if(!d.ok) toast(d.error||'No se pudo guardar el orden'); })
    .catch(()=>toast('Error de conexión'));
}

function setFocoProyecto(id, ev){
  if(ev) ev.stopPropagation();
  const p=_proyData.find(x=>x.id==id); if(!p) return;
  const turningOn = parseInt(p.es_foco)!==1;
  _proyData.forEach(x=>x.es_foco=0);     // solo uno puede ser foco
  if(turningOn) p.es_foco=1;
  renderProyectos();
  fetch('api.php',{method:'POST',body:new URLSearchParams({action:'set_foco_proyecto', id})})
    .then(r=>r.json()).then(d=>{ if(!d.ok){ toast(d.error||'Error'); loadProyectos(); } })
    .catch(()=>{ toast('Error de conexión'); loadProyectos(); });
}

function openProyectoForm(id=null){
  const p = id ? _proyData.find(x=>x.id==id) : null;
  document.getElementById('proy-id').value = p?p.id:'';
  document.getElementById('proy-modal-title').textContent = p?'✏️ EDITAR PROYECTO':'📁 NUEVO PROYECTO';
  document.getElementById('proy-titulo').value = p?(p.titulo||''):'';
  document.getElementById('proy-desc').value = p?(p.descripcion||''):'';
  document.getElementById('proy-estado').value = p?p.estado:'PLANIFICANDO';
  document.getElementById('proy-prio').value = p?p.prioridad:'MEDIA';
  const pg = p?(parseInt(p.progreso)||0):0;
  document.getElementById('proy-prog').value = pg;
  document.getElementById('proy-prog-val').textContent = pg+'%';
  document.getElementById('proy-asig').value = p?(p.asignado_a||''):'';
  document.getElementById('proy-finicio').value = p?(p.fecha_inicio||''):'';
  document.getElementById('proy-flimite').value = p?(p.fecha_limite||''):'';
  // Pre-marcar el equipo (colaboradores)
  const teamIds = (p && p.equipo_ids) ? String(p.equipo_ids).split(',') : [];
  document.querySelectorAll('.proy-team-chk').forEach(chk=>{ chk.checked = teamIds.includes(chk.value); });
  openModal('modal-proyecto');
}

function saveProyecto(){
  const titulo=document.getElementById('proy-titulo').value.trim();
  if(!titulo){ toast('Escribe un título'); return; }
  const id=document.getElementById('proy-id').value;
  const team=[...document.querySelectorAll('.proy-team-chk:checked')].map(c=>c.value);
  const body=new URLSearchParams({
    action: id?'update_proyecto':'save_proyecto',
    id: id,
    titulo,
    descripcion: document.getElementById('proy-desc').value.trim(),
    estado: document.getElementById('proy-estado').value,
    prioridad: document.getElementById('proy-prio').value,
    progreso: document.getElementById('proy-prog').value,
    asignado_a: document.getElementById('proy-asig').value,
    fecha_inicio: document.getElementById('proy-finicio').value,
    fecha_limite: document.getElementById('proy-flimite').value,
    team: JSON.stringify(team)
  });
  fetch('api.php',{method:'POST',body}).then(r=>r.json()).then(d=>{
    if(!d.ok){ toast(d.error||'Error'); return; }
    closeModal('modal-proyecto');
    toast('✓ Proyecto guardado');
    loadProyectos();
    const det=document.getElementById('modal-proyecto-detail');
    if(det && det.classList.contains('open') && _proyDetailId) openProyectoDetail(_proyDetailId);
  }).catch(()=>toast('Error de conexión'));
}

function openProyectoDetail(id){
  _proyDetailId=id;
  openModal('modal-proyecto-detail');
  document.getElementById('proy-detail-body').innerHTML='<div style="padding:40px;text-align:center;color:#8896A5;font-size:10px">Cargando…</div>';
  fetch('api.php?action=get_proyecto&id='+id).then(r=>r.json()).then(d=>{
    if(!d.ok){ document.getElementById('proy-detail-body').innerHTML='<div style="padding:30px;text-align:center;color:#B83232;font-size:10px">'+pEsc(d.error||'Error')+'</div>'; return; }
    renderProyectoDetail(d.data);
  }).catch(()=>{ document.getElementById('proy-detail-body').innerHTML='<div style="padding:30px;text-align:center;color:#B83232;font-size:10px">Error de conexión</div>'; });
}

function renderProyectoDetail(p){
  const ec=_PROY_EST_COLOR[p.estado]||'#8896A5';
  const pc=Math.max(0,Math.min(100,parseInt(p.progreso)||0));
  const equipo=p.equipo||[];
  const enEquipo=equipo.some(m=>m.id==UID);
  const canEdit = ADMIN || p.asignado_a==UID || p.agente_id==UID || enEquipo;
  const canDel  = ADMIN || p.agente_id==UID;
  const av=(p.avances||[]).map(a=>`
    <div style="display:flex;gap:9px;padding:10px 0;border-bottom:1px solid <?=$BG?>">
      <div style="flex-shrink:0;width:26px;height:26px;border-radius:50%;background:${pEsc(a.color||'#1B5E8C')};color:#fff;display:flex;align-items:center;justify-content:center;font-size:8px;font-weight:900">${pEsc(a.iniciales||'?')}</div>
      <div style="flex:1;min-width:0">
        <div style="display:flex;justify-content:space-between;gap:8px">
          <span style="font-size:9px;font-weight:900;color:<?=$P1?>">${pEsc((a.nombre||'').split(' ')[0])}</span>
          <span style="font-size:8px;color:#8896A5;white-space:nowrap">${pEsc((a.created_at||'').slice(0,16))}${(a.progreso!=null)?` · <b style="color:${ec}">${a.progreso}%</b>`:''}</span>
        </div>
        <div style="font-size:10px;color:<?=$TX?>;margin-top:2px;line-height:1.4;white-space:pre-wrap;word-break:break-word">${pEsc(a.nota)}</div>
      </div>
      ${(ADMIN||a.usuario_id==UID||p.agente_id==UID)?`<button onclick="deleteAvance(${a.id})" title="Borrar" style="flex-shrink:0;background:none;border:none;cursor:pointer;color:#C9A0A0;font-size:11px">✕</button>`:''}
    </div>`).join('') || '<div style="padding:14px;text-align:center;color:#8896A5;font-size:9px">Aún no hay avances registrados</div>';

  const ar=(p.archivos||[]).map(f=>`
    <div style="display:flex;align-items:center;gap:8px;padding:7px 9px;background:<?=$BG?>;border-radius:8px;margin-bottom:5px">
      <span style="font-size:14px">${fileIcon(f.nombre_original)}</span>
      <a href="${pEsc(f.ruta)}" target="_blank" rel="noopener" style="flex:1;font-size:9px;font-weight:700;color:#1B5E8C;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${pEsc(f.nombre_original)}</a>
      ${(ADMIN||f.usuario_id==UID||p.agente_id==UID||p.asignado_a==UID)?`<button onclick="deleteArchivo(${f.id})" title="Borrar" style="flex-shrink:0;background:none;border:none;cursor:pointer;color:#C9A0A0;font-size:11px">✕</button>`:''}
    </div>`).join('') || '<div style="font-size:9px;color:#8896A5;padding:4px 0">Sin archivos adjuntos</div>';

  document.getElementById('proy-detail-body').innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:6px">
      <div style="font-weight:900;font-size:15px;color:<?=$P1?>;line-height:1.25">${pEsc(p.titulo)}</div>
      <span style="flex-shrink:0;font-size:8px;font-weight:900;letter-spacing:.5px;text-transform:uppercase;color:#fff;background:${ec};padding:4px 9px;border-radius:20px">${pEsc(p.estado)}</span>
    </div>
    ${p.descripcion?`<div style="font-size:10px;color:<?=$MU?>;margin-bottom:10px;line-height:1.45;white-space:pre-wrap;word-break:break-word">${pEsc(p.descripcion)}</div>`:''}
    <div style="height:9px;background:<?=$BG?>;border-radius:20px;overflow:hidden;margin-bottom:4px"><div style="height:100%;width:${pc}%;background:${ec};border-radius:20px"></div></div>
    <div style="display:flex;justify-content:space-between;font-size:8px;color:#8896A5;margin-bottom:12px">
      <span><b style="color:${ec};font-size:10px">${pc}%</b> completado</span>
      <span>${p.fecha_limite?'📅 Límite: '+pEsc(p.fecha_limite):''}</span>
    </div>
    <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:8px;color:#8896A5;padding:9px 11px;background:<?=$BG?>;border-radius:9px;margin-bottom:12px">
      <span>👤 Responsable: <b style="color:<?=$P1?>">${pEsc(p.asig_nombre?p.asig_nombre.split(' ')[0]:(p.creador_nombre?p.creador_nombre.split(' ')[0]:'—'))}</b></span>
      <span>✍️ Creó: <b>${pEsc(p.creador_nombre?p.creador_nombre.split(' ')[0]:'—')}</b></span>
      ${p.prioridad?`<span>⚑ ${pEsc(p.prioridad)}</span>`:''}
    </div>
    ${equipo.length?`<div style="margin-bottom:12px">
      <div style="font-size:8px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">👥 Equipo del proyecto (${equipo.length})</div>
      <div style="display:flex;flex-wrap:wrap;gap:6px">
        ${equipo.map(m=>`<span style="display:inline-flex;align-items:center;gap:5px;background:<?=$BG?>;border:1px solid <?=$CB?>;border-radius:20px;padding:2px 9px 2px 2px;font-size:9px;font-weight:700;color:<?=$P1?>">
          <span style="width:18px;height:18px;border-radius:50%;background:${pEsc(m.color||'#1B5E8C')};color:#fff;font-size:7px;font-weight:900;display:inline-flex;align-items:center;justify-content:center">${pEsc(m.iniciales||'?')}</span>
          ${pEsc((m.nombre||'').split(' ')[0])}</span>`).join('')}
      </div>
    </div>`:''}
    ${canEdit?`<div style="display:flex;gap:7px;margin-bottom:14px">
      <button class="btn btn-gh btn-sm" onclick="openProyectoForm(${p.id})">✏️ EDITAR</button>
      ${canDel?`<button class="btn btn-gh btn-sm" style="color:#B83232" onclick="deleteProyecto(${p.id})">🗑 ELIMINAR</button>`:''}
    </div>`:''}
    ${canEdit?`<div style="border:1px solid <?=$CB?>;border-radius:11px;padding:12px;margin-bottom:14px">
      <div style="font-size:9px;font-weight:900;color:<?=$P1?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">➕ Registrar avance</div>
      <textarea id="av-nota" class="form-input" rows="2" placeholder="¿Qué se avanzó?" style="margin-bottom:8px"></textarea>
      <label style="display:flex;align-items:center;gap:7px;font-size:9px;color:<?=$MU?>;margin-bottom:8px;cursor:pointer">
        <input type="checkbox" id="av-setprog" onchange="document.getElementById('av-prog-wrap').style.display=this.checked?'flex':'none'"> Actualizar progreso del proyecto
      </label>
      <div id="av-prog-wrap" style="display:none;align-items:center;gap:9px;margin-bottom:9px">
        <input type="range" id="av-prog" min="0" max="100" step="5" value="${pc}" oninput="document.getElementById('av-prog-val').textContent=this.value+'%'" style="flex:1">
        <span id="av-prog-val" style="font-size:9px;font-weight:900;color:${ec};min-width:34px">${pc}%</span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
        <label class="btn btn-gh btn-sm" style="cursor:pointer;margin:0">📎 ADJUNTAR<input type="file" onchange="uploadProyectoArchivo(this)" style="display:none"></label>
        <button class="btn btn-p btn-sm" onclick="addAvance()">GUARDAR AVANCE</button>
      </div>
    </div>`:''}
    <div style="font-size:9px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">📎 Archivos (${(p.archivos||[]).length})</div>
    <div style="margin-bottom:14px">${ar}</div>
    <div style="font-size:9px;font-weight:900;color:<?=$P2?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">💬 Historial de avances (${(p.avances||[]).length})</div>
    <div>${av}</div>
  `;
}

function addAvance(){
  const nota=document.getElementById('av-nota')?.value.trim()||'';
  if(!nota){ toast('Escribe una nota de avance'); return; }
  const body=new URLSearchParams({action:'add_avance',proyecto_id:_proyDetailId,nota});
  if(document.getElementById('av-setprog')?.checked) body.set('progreso',document.getElementById('av-prog').value);
  fetch('api.php',{method:'POST',body}).then(r=>r.json()).then(d=>{
    if(!d.ok){ toast(d.error||'Error'); return; }
    toast('✓ Avance registrado');
    openProyectoDetail(_proyDetailId);
    loadProyectos();
  }).catch(()=>toast('Error de conexión'));
}

function uploadProyectoArchivo(input){
  if(!input.files||!input.files[0])return;
  const f=input.files[0];
  if(f.size>10*1024*1024){ toast('El archivo supera 10MB'); input.value=''; return; }
  const fd=new FormData();
  fd.append('action','upload_proyecto_archivo');
  fd.append('proyecto_id',_proyDetailId);
  fd.append('archivo',f);
  toast('Subiendo…');
  fetch('api.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(!d.ok){ toast(d.error||'Error al subir'); return; }
    toast('✓ Archivo subido');
    openProyectoDetail(_proyDetailId);
    loadProyectos();
  }).catch(()=>toast('Error de conexión'));
  input.value='';
}

function deleteProyecto(id){
  if(!confirm('¿Eliminar este proyecto con todos sus avances y archivos? No se puede deshacer.'))return;
  fetch('api.php',{method:'POST',body:new URLSearchParams({action:'delete_proyecto',id})}).then(r=>r.json()).then(d=>{
    if(!d.ok){ toast(d.error||'Error'); return; }
    closeModal('modal-proyecto-detail'); toast('Proyecto eliminado'); loadProyectos();
  }).catch(()=>toast('Error de conexión'));
}
function deleteAvance(id){
  if(!confirm('¿Borrar este avance?'))return;
  fetch('api.php',{method:'POST',body:new URLSearchParams({action:'delete_avance',id})}).then(r=>r.json()).then(d=>{
    if(!d.ok){ toast(d.error||'Error'); return; }
    openProyectoDetail(_proyDetailId); loadProyectos();
  }).catch(()=>toast('Error de conexión'));
}
function deleteArchivo(id){
  if(!confirm('¿Borrar este archivo?'))return;
  fetch('api.php',{method:'POST',body:new URLSearchParams({action:'delete_proyecto_archivo',id})}).then(r=>r.json()).then(d=>{
    if(!d.ok){ toast(d.error||'Error'); return; }
    openProyectoDetail(_proyDetailId); loadProyectos();
  }).catch(()=>toast('Error de conexión'));
}

function setTktFiltro(group, val){
  if(group==='estado'){
    _tktFiltroEstado = val;
    document.querySelectorAll('.tkt-pill').forEach(p=>{
      const pid = p.id.replace('tpill-','');
      p.classList.toggle('tkt-pill-on', pid === val);
    });
  } else if(group==='prio'){
    const sel = document.getElementById('tkt-prio');
    if(sel) sel.value = val;
  }
  filterTickets();
}

function limpiarTktFiltros(){
  _tktFiltroEstado = 'ACTIVOS';
  document.querySelectorAll('.tkt-pill').forEach(p=>p.classList.remove('tkt-pill-on'));
  const ap = document.getElementById('tpill-ACTIVOS');
  if(ap) ap.classList.add('tkt-pill-on');
  ['tkt-prio','tkt-tipo','tkt-resp'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
  const s = document.getElementById('tkt-search'); if(s) s.value='';
  filterTickets();
}

function filterTickets(){
  const search = (document.getElementById('tkt-search')?.value||'').toLowerCase();
  const estado = _tktFiltroEstado;
  const prio   = document.getElementById('tkt-prio')?.value||'';
  const tipo   = document.getElementById('tkt-tipo')?.value||'';
  const resp   = document.getElementById('tkt-resp')?.value||'';
  let visible  = 0;
  
  // Obtenemos la fecha de hoy
  const today = new Date().toISOString().slice(0,10);

  document.querySelectorAll('.ticket-row').forEach(r => {
    const d = r.dataset;
    const okVista = d.vista === _tktVista;
    
    let okEstado = false;

    // LÓGICA DE LOS BOTONES Y CUADRITOS SUPERIORES:
    if (estado === 'ACTIVOS') {
        // Muestra activos pero oculta los del futuro
        okEstado = d.estado !== 'CERRADO' && (!d.sla || d.sla <= today);
    } 
    else if (estado === 'CERRADO') {
        okEstado = d.estado === 'CERRADO';
    } 
    else if (estado === '') {
        // Botón "TODOS" (muestra todo, incluyendo los del futuro)
        okEstado = d.estado !== 'CERRADO';
    } 
    else {
        // Clic en los cuadritos superiores: ABIERTO, PENDIENTE, EN PROCESO (ocultando futuros)
        okEstado = d.estado === estado && (!d.sla || d.sla <= today);
    }

    const ok = okVista
      && okEstado
      && (!search || d.search.includes(search))
      && (!prio   || d.prio === prio)
      && (!tipo   || d.tipo === tipo)
      && (!resp   || d.resp === resp);
      
    r.style.display = ok ? '' : 'none';
    if(ok) visible++;
  });
  
  const cnt = document.getElementById('tkt-count');
  if(cnt) cnt.textContent = visible ? visible + ' ticket'+(visible>1?'s':'')+' mostrado'+(visible>1?'s':'') : '';
  const empty = document.getElementById('tkt-empty');
  if(empty) empty.style.display = visible===0 ? 'block' : 'none';
}

function quickTktStatus(id, newEstado){
  fetch('api.php',{method:'POST',body:new URLSearchParams({action:'update_ticket',id,estado:newEstado})})
    .then(r=>r.json()).then(d=>{
      if(d.ok){
        toast('✓ ESTADO ACTUALIZADO');
        const row=document.querySelector('.ticket-row[data-id="'+id+'"]');
        if(row) row.dataset.estado=newEstado;
        if(typeof filterTickets==='function') filterTickets();
        saveTabAndReload();
      }
      else toast('⚠ '+(d.error||'Error'));
    });
}

// ── TIPOS DE TICKETS — el sistema decide miembro/tarea según el tipo ──
const TIPO_MIEMBRO = <?=json_encode($TIPO_MIEMBRO)?>;
const TIPO_TAREA   = <?=json_encode($TIPO_TAREA)?>;

// Estado del modal: pasos del ticket actual + flag de nuevo/edición
window._tktNextSteps = [];   // [{id?, descripcion, fecha_programada, completado, _local?}]
window._tktIsNew     = true;

function tktTipoChange(){
  const sel  = document.getElementById('tkt-tipo-sel');
  const hint = document.getElementById('tkt-vista-hint');
  if(!sel || !hint) return;
  const tipo = sel.value;
  if(TIPO_MIEMBRO.includes(tipo)){
    hint.innerHTML = '◉ Este ticket aparecerá en <b>TICKETS DE MIEMBROS</b>';
    hint.style.color = '<?=$P1?>';
  } else if(TIPO_TAREA.includes(tipo)){
    hint.innerHTML = '◈ Este ticket aparecerá en <b>TAREAS GENERALES</b>';
    hint.style.color = '<?=$P2?>';
  } else {
    hint.innerHTML = '';
  }
}

function tktFuenteChange(sel){
  const wrap = document.getElementById('tkt-ref-wrap');
  if(!wrap) return;
  wrap.style.display = sel.value === 'Referido' ? '' : 'none';
  if(sel.value !== 'Referido'){
    const nref = document.getElementById('tkt-nref');
    if(nref) nref.value = '';
  }
}

function openTicketForm(mid=null, tktData=null){
  // Reset del botón de guardar (si quedó deshabilitado de un guardado anterior)
  const _tktSubmitBtn = document.querySelector('#ticket-form-modal [type=submit]');
  if(_tktSubmitBtn){ _tktSubmitBtn.disabled = false; _tktSubmitBtn.textContent = '◈ GUARDAR'; }
  // Reset campos
  document.getElementById('tkt-id').value = '';
  document.getElementById('tkt-modal-title').textContent = '◈ NUEVO TICKET';
  document.getElementById('tkt-desc').value = '';
  document.getElementById('tkt-notas').value = '';
  document.getElementById('tkt-resultado').value = '';
  document.getElementById('tkt-cliente').value = '';
  document.getElementById('tkt-nref').value = '';
  document.getElementById('tkt-sla').value = '';
  document.getElementById('tkt-fseg').value = '<?=date('Y-m-d',strtotime('+7 days'))?>';
  document.getElementById('tkt-estado-sel').value = 'ABIERTO';
  document.getElementById('tkt-fuente').value = '';
  document.getElementById('tkt-ref-wrap').style.display = 'none';
  // Form de tipo: por defecto FOLLOW UP (con miembro)
  const tipoSel = document.getElementById('tkt-tipo-sel');
  if(tipoSel) tipoSel.value = 'FOLLOW UP';
  const tktMpickInp = document.getElementById('tkt-mpick-input');
  if(tktMpickInp) tktMpickInp.value = '';
  const tktMpickDrop = document.getElementById('tkt-mpick-drop');
  if(tktMpickDrop) tktMpickDrop.style.display = 'none';
  // Selector de miembro
  const midSel = document.getElementById('ticket-mid-sel');
  if(midSel) midSel.value = mid || '';
  // Also update the picker text if mid is provided
  const midInp = document.getElementById('tkt-mpick-input');
  if(midInp) {
    if(mid) {
      const m = _membersData.find(x => x.id == mid);
      midInp.value = m ? m.label : '';
    } else {
      midInp.value = '';
    }
  }
  // Reset next steps
  window._tktNextSteps = [];
  window._tktIsNew = true;
  // Mostrar wrapper de next steps (siempre visible)
  document.getElementById('tkt-nextsteps-wrap').style.display = '';
  document.getElementById('tkt-ns-desc-input').value = '';
  document.getElementById('tkt-ns-date-input').value = '';

  if(tktData){
    window._tktIsNew = false;
    document.getElementById('tkt-id').value = tktData.id || '';
    document.getElementById('tkt-modal-title').textContent = '◈ ACTUALIZAR TICKET #' + tktData.id;
    document.getElementById('tkt-desc').value     = tktData.desc || '';
    document.getElementById('tkt-notas').value    = tktData.notas || '';
    document.getElementById('tkt-resultado').value= tktData.resultado || '';
    document.getElementById('tkt-cliente').value  = tktData.cliente || '';
    document.getElementById('tkt-nref').value     = tktData.nombre_referencia || '';
    document.getElementById('tkt-sla').value      = tktData.sla || '';
    document.getElementById('tkt-fseg').value     = tktData.fseg || '';
    document.getElementById('tkt-estado-sel').value = tktData.estado || 'ABIERTO';
    document.getElementById('tkt-fuente').value   = tktData.fuente || '';
    if(tipoSel && tktData.tipo) tipoSel.value     = tktData.tipo;
    // Selector de prioridad y asignado
    const fForm = document.querySelector('#ticket-form-modal form');
    if(fForm){
      const pSel = fForm.querySelector('[name=prioridad]');
      if(pSel && tktData.prioridad) pSel.value = tktData.prioridad;
      const aSel = fForm.querySelector('[name=asignado_a]');
      if(aSel) aSel.value = tktData.asignado_a || '';
    }
    if(midSel) midSel.value = tktData.mid || '';
    // Update picker text for edit mode
    const midInp2 = document.getElementById('tkt-mpick-input');
    if(midInp2 && tktData.mid) {
      const m2 = _membersData.find(x => x.id == tktData.mid);
      midInp2.value = m2 ? m2.label : (tktData.miembro_nombre || '');
    } else if(midInp2) { midInp2.value = ''; }
    // Cargar next steps existentes
    window._tktNextSteps = (tktData.next_steps || []).map(ns => ({
      id: ns.id,
      descripcion: ns.descripcion,
      fecha_programada: ns.fecha_programada || '',
      completado: parseInt(ns.completado) === 1,
      fecha_completado: ns.fecha_completado || null,
      agente_nombre: ns.agente_nombre || null
    }));
    tktFuenteChange(document.getElementById('tkt-fuente'));
    tktEstadoChange(document.getElementById('tkt-estado-sel'));
  }
  tktTipoChange();
  renderNextSteps();
  openModal('ticket-form-modal');
}

function updateTicket(id){
  fetch('api.php?action=get_ticket&id='+id).then(r=>r.json()).then(d=>{
    if(!d.ok){ toast('⚠ '+(d.error||'No se pudo cargar')); return; }
    openTicketForm(d.data.miembro_id, {
      id: d.data.id,
      desc: d.data.descripcion,
      notas: d.data.notas || '',
      resultado: d.data.resultado || '',
      cliente: d.data.cliente || '',
      nombre_referencia: d.data.nombre_referencia || '',
      sla: d.data.sla_fecha || '',
      fseg: d.data.fecha_seguimiento || '',
      estado: d.data.estado,
      mid: d.data.miembro_id,
      tipo: d.data.tipo,
      prioridad: d.data.prioridad,
      asignado_a: d.data.asignado_a,
      fuente: d.data.fuente,
      next_steps: d.data.next_steps || []
    });
  });
}

function verTicketCerrado(id){
  fetch('api.php?action=get_ticket&id='+id).then(r=>r.json()).then(d=>{
    if(!d.ok){ toast('⚠ '+(d.error||'No se pudo cargar')); return; }
    const t = d.data;
    const fmtDate = s => {
      if(!s) return '—';
      const dt = new Date(s.length<=10 ? s+'T12:00:00' : s);
      return dt.toLocaleDateString('es-US',{month:'2-digit',day:'2-digit',year:'numeric'});
    };

    document.getElementById('tktc-id-lbl').textContent      = '#' + t.id;
    document.getElementById('tktc-nombre-lbl').textContent  = t.miembro_nombre || t.cliente || '—';
    document.getElementById('tktc-tipo-badge').innerHTML    = badgeJS(t.tipo    || 'OTRO', true);
    document.getElementById('tktc-prio-badge').innerHTML    = badgeJS(t.prioridad|| 'MEDIA', true);
    document.getElementById('tktc-fuente-lbl').textContent  = t.fuente || '—';
    document.getElementById('tktc-resp-lbl').textContent    = t.asignado_nombre || t.agente_nombre || '—';
    document.getElementById('tktc-created-lbl').textContent = fmtDate(t.fecha_creacion);
    document.getElementById('tktc-closed-lbl').textContent  = fmtDate(t.fecha_cierre);
    document.getElementById('tktc-sla-lbl').textContent     = fmtDate(t.sla_fecha);
    document.getElementById('tktc-fseg-lbl').textContent    = fmtDate(t.fecha_seguimiento);
    document.getElementById('tktc-desc-lbl').textContent    = t.descripcion || '(sin descripción)';

    const notasWrap = document.getElementById('tktc-notas-wrap');
    document.getElementById('tktc-notas-lbl').textContent   = t.notas || '';
    notasWrap.style.display = t.notas ? '' : 'none';

    const resWrap = document.getElementById('tktc-resultado-wrap');
    document.getElementById('tktc-resultado-lbl').textContent = t.resultado || '';
    resWrap.style.display = t.resultado ? '' : 'none';

    const nsWrap = document.getElementById('tktc-nextsteps-wrap');
    const nsList = document.getElementById('tktc-ns-list');
    const ns = t.next_steps || [];
    if(ns.length){
      nsWrap.style.display = '';
      nsList.innerHTML = ns.map(n=>`
        <div style="display:flex;align-items:flex-start;gap:8px;background:#F8FBFD;border:1px solid #C8DFF0;border-radius:7px;padding:7px 10px">
          <span style="font-size:13px;margin-top:1px">${n.completado==1?'✅':'⬜'}</span>
          <div style="flex:1">
            <div style="font-size:9px;font-weight:800;color:${n.completado==1?'#7A90A4':'#1B3A5C'};text-decoration:${n.completado==1?'line-through':'none'}">${n.descripcion||''}</div>
            ${n.fecha_programada?`<div style="font-size:8px;color:#7A90A4;margin-top:1px">📅 ${fmtDate(n.fecha_programada)}</div>`:''}
            ${(n.agente_nombre&&n.completado==1)?`<div style="font-size:8px;color:#1E7A5C;margin-top:1px">✓ ${n.agente_nombre}</div>`:''}
          </div>
        </div>`).join('');
    } else {
      nsWrap.style.display = 'none';
    }

    openModal('ticket-cerrado-modal');
  });
}

function badgeJS(s, sm){
  const map={
    'ALTA':    ['#B83232','#FDF0EE','#EFA09A'],
    'MEDIA':   ['#C07A1A','#FEF8EE','#F5D5A0'],
    'BAJA':    ['#1E7A8C','#EAF4F6','#8DC8D0'],
    'ABIERTO': ['#B83232','#FDF0EE','#EFA09A'],
    'EN PROCESO':['#C07A1A','#FEF8EE','#F5D5A0'],
    'PENDIENTE':['#1B5E8C','#EBF5FB','#A9D0E8'],
    'CERRADO': ['#1E7A5C','#EAF5F0','#8DCFBA'],
    'FOLLOW UP':['#1B5E8C','#EBF5FB','#A9D0E8'],
    'APLICACION':['#5B3FAF','#F3F0FB','#C2B0E8'],
  };
  const c = map[s] || ['#7A90A4','#F4F8FC','#C8DFF0'];
  const p = sm ? '2px 8px' : '3px 10px', f = sm ? '9px' : '10px';
  return `<span style="padding:${p};border-radius:20px;font-size:${f};font-weight:800;background:${c[1]};color:${c[0]};border:1px solid ${c[2]};white-space:nowrap;letter-spacing:.5px;text-transform:uppercase">${s}</span>`;
}

function tktEstadoChange(sel){
  const wrap = document.getElementById('tkt-nota-wrap');
  if(wrap){
    const lbl = wrap.querySelector('.form-label');
    if(lbl) lbl.textContent = sel.value==='CERRADO' ? 'NOTA DE CIERRE *' : 'NOTAS / ACTUALIZACIÓN';
    const ta = document.getElementById('tkt-notas');
    if(ta) ta.required = sel.value==='CERRADO';
  }
  // Hint cuando va a cerrar y hay pasos pendientes
  const pendientes = (window._tktNextSteps||[]).filter(n=>!n.completado).length;
  if(sel.value==='CERRADO' && pendientes>0){
    toast('⚡ Al guardar, se completarán '+pendientes+' next step'+(pendientes>1?'s':'')+' automáticamente');
  }
}

// ── NEXT STEPS ────────────────────────────────────────────────────────
function renderNextSteps(){
  const list  = document.getElementById('tkt-ns-list');
  const cnt   = document.getElementById('tkt-ns-count');
  if(!list) return;
  const arr   = window._tktNextSteps || [];
  const pend  = arr.filter(n=>!n.completado).length;
  const total = arr.length;
  if(cnt) cnt.textContent = total ? (pend+'/'+total+' pendientes') : '0';

  if(!arr.length){
    list.innerHTML = '<div style="font-size:9px;color:<?=$MU?>;font-style:italic;text-align:center;padding:9px">No hay next steps. Agrega el primero abajo ↓</div>';
    return;
  }
  const today = new Date().toISOString().slice(0,10);
  list.innerHTML = arr.map((n, idx) => {
    const isVencido = !n.completado && n.fecha_programada && n.fecha_programada < today;
    const refStr   = n.id != null ? `data-id="${n.id}"` : `data-idx="${idx}"`;
    const fechaStr = n.fecha_programada
      ? `<span style="font-size:8px;font-weight:800;color:${isVencido?'#B83232':'<?=$P2?>'};white-space:nowrap">${isVencido?'⚠ ':'📅 '}${n.fecha_programada}</span>`
      : '';
    const doneStr  = n.completado
      ? `<span style="font-size:7px;color:#1E7A5C;font-weight:900;text-transform:uppercase">✓ COMPLETADO${n.fecha_completado?' · '+n.fecha_completado.substring(0,10):''}</span>`
      : '';
    return `
      <div ${refStr} style="background:#fff;border:1px solid ${isVencido?'#EFA09A':'<?=$CB?>'};border-left:3px solid ${n.completado?'#1E7A5C':(isVencido?'#B83232':'<?=$P2?>')};border-radius:8px;padding:7px 10px;display:flex;align-items:center;gap:8px;${n.completado?'opacity:.65':''}">
        <button type="button" onclick="toggleNextStep(${n.id!=null?n.id:'null'},${idx})" title="${n.completado?'Reabrir':'Marcar completado'}"
          style="background:${n.completado?'#1E7A5C':'#fff'};border:1.5px solid ${n.completado?'#1E7A5C':'<?=$CB?>'};border-radius:5px;width:18px;height:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#fff;font-size:11px;font-weight:900;padding:0">
          ${n.completado?'✓':''}
        </button>
        <div style="flex:1;min-width:0">
          <div style="font-size:10px;color:<?=$TX?>;font-weight:700;line-height:1.3;${n.completado?'text-decoration:line-through':''}">${escapeHtml(n.descripcion)}</div>
          <div style="display:flex;gap:7px;margin-top:2px;align-items:center;flex-wrap:wrap">
            ${fechaStr}${doneStr}
          </div>
        </div>
        <button type="button" onclick="deleteNextStep(${n.id!=null?n.id:'null'},${idx})" title="Eliminar"
          style="background:none;border:none;cursor:pointer;color:<?=$MU?>;font-size:14px;padding:2px 5px">×</button>
      </div>`;
  }).join('');
}

function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function addNextStep(){
  const desc = document.getElementById('tkt-ns-desc-input').value.trim();
  const date = document.getElementById('tkt-ns-date-input').value;
  if(!desc){ toast('⚠ Escribe la descripción del paso'); return; }
  const tktId = document.getElementById('tkt-id').value;

  if(!window._tktIsNew && tktId){
    // Ticket ya existe → guardar en BD ya
    fetch('api.php',{method:'POST',body:new URLSearchParams({
      action:'add_next_step', ticket_id:tktId, descripcion:desc, fecha_programada:date
    })}).then(r=>r.json()).then(d=>{
      if(d.ok){
        window._tktNextSteps.push({
          id: d.id, descripcion: desc, fecha_programada: date,
          completado: false, fecha_completado: null
        });
        document.getElementById('tkt-ns-desc-input').value = '';
        document.getElementById('tkt-ns-date-input').value = '';
        renderNextSteps();
        toast('✓ NEXT STEP AGREGADO');
      } else {
        toast('⚠ '+(d.error||'Error'));
      }
    });
  } else {
    // Ticket nuevo → guardar en memoria, se enviará al crear el ticket
    window._tktNextSteps.push({
      descripcion: desc, fecha_programada: date,
      completado: false, fecha_completado: null, _local: true
    });
    document.getElementById('tkt-ns-desc-input').value = '';
    document.getElementById('tkt-ns-date-input').value = '';
    renderNextSteps();
  }
}

function toggleNextStep(id, idx){
  const item = (id != null)
    ? window._tktNextSteps.find(n => n.id === id)
    : window._tktNextSteps[idx];
  if(!item) return;

  if(id != null){
    // Persistir en BD
    const action = item.completado ? 'reopen_next_step' : 'complete_next_step';
    fetch('api.php',{method:'POST',body:new URLSearchParams({action, id})})
      .then(r=>r.json()).then(d=>{
        if(d.ok){
          item.completado = !item.completado;
          item.fecha_completado = item.completado ? new Date().toISOString().slice(0,19).replace('T',' ') : null;
          renderNextSteps();
        } else toast('⚠ '+(d.error||'Error'));
      });
  } else {
    // Local — simplemente toggle
    item.completado = !item.completado;
    item.fecha_completado = item.completado ? new Date().toISOString().slice(0,19).replace('T',' ') : null;
    renderNextSteps();
  }
}

function deleteNextStep(id, idx){
  if(!confirm('¿Eliminar este next step?')) return;
  if(id != null){
    fetch('api.php',{method:'POST',body:new URLSearchParams({action:'delete_next_step', id})})
      .then(r=>r.json()).then(d=>{
        if(d.ok){
          window._tktNextSteps = window._tktNextSteps.filter(n => n.id !== id);
          renderNextSteps();
          toast('✓ ELIMINADO');
        } else toast('⚠ '+(d.error||'Error'));
      });
  } else {
    window._tktNextSteps.splice(idx, 1);
    renderNextSteps();
  }
}

function submitTicket(e){
  e.preventDefault();
  const fd = new FormData(e.target);
  const isEdit = !!document.getElementById('tkt-id').value;
  fd.append('action', isEdit ? 'update_ticket' : 'save_ticket');
  // Adjuntar next steps locales (solo los que no tienen id en BD)
  const localSteps = (window._tktNextSteps || []).filter(n => n.id == null);
  if(localSteps.length){
    fd.append('next_steps_json', JSON.stringify(localSteps.map(n => ({
      descripcion: n.descripcion,
      fecha_programada: n.fecha_programada || null,
      completado: n.completado ? 1 : 0
    }))));
  }
  const btn = e.target.querySelector('[type=submit]');
  if(btn){ btn.disabled = true; btn.textContent = 'GUARDANDO...'; }
  fetch('api.php',{method:'POST',body:new URLSearchParams(fd)})
    .then(r=>r.json()).then(d=>{
      if(d.ok){
        toast(isEdit ? '✓ TICKET ACTUALIZADO' : '✓ TICKET CREADO');
        // Reflejar el nuevo estado en la fila al instante (sin esperar el refresco)
        const _tid = document.getElementById('tkt-id').value;
        const _newEst = document.getElementById('tkt-estado-sel')?.value || '';
        if(isEdit && _tid && _newEst){
          const row=document.querySelector('.ticket-row[data-id="'+_tid+'"]');
          if(row){ row.dataset.estado=_newEst; if(_newEst==='CERRADO') row.classList.add('tkt-cerrada'); }
          if(typeof filterTickets==='function') filterTickets();
        }
        if(btn){ btn.disabled = false; btn.textContent = '◈ GUARDAR'; }
        closeModal('ticket-form-modal');
        saveTabAndReload();
      } else {
        toast('⚠ '+(d.error||'Error al guardar'));
        if(btn){ btn.disabled = false; btn.textContent = '◈ GUARDAR'; }
      }
    }).catch(()=>{
      toast('⚠ ERROR — INTENTA DE NUEVO');
      if(btn){ btn.disabled = false; btn.textContent = '◈ GUARDAR'; }
    });
}

// Hook tipo change
document.addEventListener('DOMContentLoaded', () => {
  const tipoSel = document.getElementById('tkt-tipo-sel');
  if(tipoSel) tipoSel.addEventListener('change', tktTipoChange);
});
function searchMember(num){document.getElementById('member-search').value=num;filterMembers();showTab('MIEMBROS');}
function submitReporte(e){e.preventDefault();const fd=new FormData(e.target);fd.append('action','save_reporte');const btn=e.target.querySelector('[type=submit]');if(btn){btn.disabled=true;btn.textContent='ENVIANDO...';}fetch('api.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.ok){toast('✓ REPORTE ENVIADO — BUEN TRABAJO! ✓');// Reload so the page shows the submitted summary instead of the form
saveTabAndReload();}else{toast(d.error||'Error al enviar');if(btn){btn.disabled=false;btn.textContent='▦ ENVIAR REPORTE';}}}).catch(()=>{if(btn){btn.disabled=false;btn.textContent='▦ ENVIAR REPORTE';}toast('⚠ Error de red');});}
function filterHist(){const ag=document.getElementById('hist-ag')?.value||'';const tipo=document.getElementById('hist-tipo')?.value||'';document.querySelectorAll('.hist-row').forEach(r=>{r.style.display=(!ag||r.dataset.ag===ag)&&(!tipo||r.dataset.tipo===tipo)?'':'none';});}

function buscarHistorial() {
    const from  = document.getElementById('rep-from').value;
    const to    = document.getElementById('rep-to').value;
    const ag_id = document.getElementById('rep-ag').value;
    const wrap  = document.getElementById('rep-hist-wrap');
    const load  = document.getElementById('rep-hist-loading');
    const tabla = document.getElementById('rep-hist-tabla');

    if (!from || !to) { toast('⚠ Selecciona fechas'); return; }

    wrap.style.display  = 'block';
    load.style.display  = 'block';
    tabla.innerHTML     = '';

    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_reportes_historicos&from=' + from + '&to=' + to + (ag_id ? '&agente_id=' + ag_id : '')
    })
    .then(r => r.json())
    .then(d => {
        load.style.display = 'none';
        if (!d.ok) { tabla.innerHTML = '<div style="color:#B83232;font-size:9px;padding:20px;text-align:center">⚠ ' + (d.error||'Error') + '</div>'; return; }
        const rows = d.data;
        if (!rows.length) {
            tabla.innerHTML = '<div style="text-align:center;padding:30px;font-size:9px;color:#7A90A4;font-weight:900;text-transform:uppercase">Sin reportes en ese período</div>';
            return;
        }

        // Agrupar por fecha
        const porFecha = {};
        rows.forEach(r => {
            if (!porFecha[r.fecha]) porFecha[r.fecha] = [];
            porFecha[r.fecha].push(r);
        });

        let html = '';
        Object.keys(porFecha).sort().reverse().forEach(fecha => {
            const dia = new Date(fecha + 'T12:00:00');
            const label = dia.toLocaleDateString('es-MX', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
            html += `<div style="font-size:8px;font-weight:900;color:#1B4A6B;text-transform:uppercase;letter-spacing:1.5px;margin:16px 0 8px;padding-left:4px">${label.toUpperCase()}</div>`;

            porFecha[fecha].forEach(r => {
                const ck_pct = r.ck_total > 0 ? Math.round((r.ck_done / r.ck_total) * 100) : 0;
                const ck_color = ck_pct === 100 ? '#16A34A' : ck_pct >= 50 ? '#2876A8' : '#C07A1A';
                const items_ok   = (r.ck_items_ok   || []);
                const items_pend = (r.ck_items_pend || []);

                html += `
                <div style="background:#fff;border:1px solid #C8DFF0;border-radius:13px;overflow:hidden;margin-bottom:10px;border-left:4px solid ${r.color||'#2876A8'}">
                    <!-- Header agente -->
                    <div style="padding:10px 14px;background:#EBF4F9;display:flex;align-items:center;gap:10px">
                        <div style="width:34px;height:34px;border-radius:50%;background:${r.color||'#2876A8'};display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;color:#fff;flex-shrink:0">${r.iniciales||'?'}</div>
                        <div style="flex:1">
                            <div style="font-size:10px;font-weight:900;color:#1B4A6B">${r.nombre||'—'}</div>
                            <div style="font-size:7px;color:#7A90A4;text-transform:uppercase;margin-top:1px">REPORTE ENVIADO</div>
                        </div>
                        ${r.nota ? `<div style="font-size:8px;color:#7A90A4;max-width:200px;text-align:right;font-style:italic;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${r.nota}">"${r.nota}"</div>` : ''}
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0">
                        <!-- Métricas -->
                        <div style="padding:12px 14px;border-right:1px solid #C8DFF0">
                            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:10px">
                                ${[
                                    ['LLAM.PROSP.', parseInt(r.llamadas_prospectos||0), '#2876A8'],
                                    ['✅ CONTS.',   parseInt(r.contestaron||0),           '#1E7A5C'],
                                    ['📬 BUZÓN',    parseInt(r.buzon||0),                 '#C07A1A'],
                                    ['LLAM.SERV.',  parseInt(r.llamadas_servicio||0),     '#1E7A8C'],
                                    ['APPS',        r.apps_enviadas||0,                   '#1B4A6B'],
                                    ['CITAS',       r.citas_confirmadas||0,               '#1E7A5C'],
                                    ['TKS.CERR',    r.tickets_resueltos||0,               '#1E7A8C'],
                                    ['APPS X HACER', r.apps_por_hacer||0,                '#5B3FAF'],
                                ].map(([lb,v,c]) => `
                                    <div style="text-align:center;background:#EBF4F9;border-radius:8px;padding:7px 4px">
                                        <div style="font-size:6px;font-weight:900;color:#7A90A4;text-transform:uppercase;margin-bottom:2px">${lb}</div>
                                        <div style="font-size:17px;font-weight:900;color:${v>0?c:'#C8DFF0'}">${v}</div>
                                    </div>`).join('')}
                            </div>
                            <!-- Barra checklist -->
                            <div style="margin-top:4px">
                                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                                    <div style="font-size:7px;font-weight:900;color:#7A90A4;text-transform:uppercase">✅ CHECKLIST</div>
                                    <div style="font-size:7px;font-weight:900;color:${ck_color}">${r.ck_done}/${r.ck_total} (${ck_pct}%)</div>
                                </div>
                                <div style="height:5px;background:#C8DFF0;border-radius:99px;overflow:hidden">
                                    <div style="height:100%;width:${ck_pct}%;background:${ck_color};border-radius:99px"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Checklist detalle -->
                        <div style="padding:12px 14px;max-height:220px;overflow-y:auto">
                            <div style="font-size:7px;font-weight:900;color:#1B4A6B;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">ACTIVIDADES</div>
                            ${items_ok.filter(Boolean).map(t => `
                                <div style="display:flex;align-items:center;gap:7px;padding:5px 8px;border-radius:7px;background:#EAF5F0;border:1px solid #8DCFBA;margin-bottom:4px">
                                    <span style="color:#1E7A5C;font-size:11px;font-weight:900;flex-shrink:0">✓</span>
                                    <span style="font-size:8px;font-weight:700;color:#1E7A5C;line-height:1.3">${t}</span>
                                </div>`).join('')}
                            ${items_pend.filter(Boolean).map(t => `
                                <div style="display:flex;align-items:center;gap:7px;padding:5px 8px;border-radius:7px;background:#fff;border:1px solid #C8DFF0;margin-bottom:4px">
                                    <span style="color:#C8DFF0;font-size:11px;font-weight:900;flex-shrink:0">○</span>
                                    <span style="font-size:8px;font-weight:700;color:#7A90A4;line-height:1.3">${t}</span>
                                </div>`).join('')}
                            ${!items_ok.length && !items_pend.length ? '<div style="font-size:8px;color:#7A90A4;text-align:center;padding:10px">Sin datos de checklist</div>' : ''}
                        </div>
                    </div>
                </div>`;
            });
        });

        tabla.innerHTML = html;
    })
    .catch(() => {
        load.style.display = 'none';
        tabla.innerHTML = '<div style="color:#B83232;font-size:9px;padding:20px;text-align:center">⚠ Error de conexión</div>';
    });
}

function exportRep(fmt){const from=document.getElementById('rep-from')?.value;const to=document.getElementById('rep-to')?.value;const ag=document.getElementById('rep-ag')?.value||'';window.open('reporte_export.php?fmt='+fmt+'&from='+from+'&to='+to+(ag?'&agente='+ag:''),'_blank');}
function setSmsTemplate(k){const n=document.getElementById('sms-nombre')?.value||'[NOMBRE]';const t={B:'HOLA '+n+'! BIENVENIDO/A A MEDICARE WITH ISABEL. COBERTURA ACTIVA. (818) 000-0000 REPLY STOP.',A:'HOLA '+n+'! AEP OCT 15-DIC 7. REVISEMOS SU PLAN GRATIS. (818) 000-0000 REPLY STOP.',C:'FELIZ CUMPLEAÑOS '+n+'! DE PARTE DE MEDICARE WITH ISABEL.',T:'HOLA '+n+'! SE ACERCA SU CUMPLEAÑOS 65. (818) 000-0000 REPLY STOP.',D:'HOLA '+n+'! RECUERDE SU BENEFICIO DENTAL. (818) 000-0000 REPLY STOP.',R:'HOLA '+n+'! ¿CONOCE ALGUIEN QUE NECESITE MEDICARE? (818) 000-0000'};const el=document.getElementById('sms-msg');if(el){el.value=t[k]||'';updateSmsCount();}}
function updateSmsCount(){const m=document.getElementById('sms-msg');const c=document.getElementById('sms-count');if(m&&c)c.textContent=m.value.length+'/160';}
function sendSms(){const n=document.getElementById('sms-nombre')?.value;const t=document.getElementById('sms-tel')?.value;toast(' SMS PREPARADO PARA '+(n||t));}
function setEmailTemplate(k){const n=document.getElementById('email-nombre')?.value||'[NOMBRE]';const tm={bv:{s:'Bienvenido/a a Medicare with Isabel',b:'Estimado/a '+n+',\n\nBienvenido/a al plan Medicare. Su cobertura está activa.\n\nIsabel Fuentes — (818) 000-0000'},aep:{s:'Revisión AEP — Temporada Abierta',b:'Estimado/a '+n+',\n\nYa abrió la temporada AEP (oct 15 – dic 7). Hagamos una revisión gratuita.\n\n(818) 000-0000 — Isabel Fuentes'},fup:{s:'Seguimiento — Medicare with Isabel',b:'Estimado/a '+n+',\n\n¿Tiene preguntas sobre su plan? Estoy aquí para ayudarle.\n\nIsabel Fuentes — (818) 000-0000'},chk:{s:'Cambio de Doctor — Acción Requerida',b:'Estimado/a '+n+',\n\nNos informaron que su doctor puede no estar en la red. Llámenos: (818) 000-0000\n\nIsabel Fuentes'}};const t=tm[k];if(t){const a=document.getElementById('email-asunto');const m=document.getElementById('email-msg');if(a)a.value=t.s;if(m)m.value=t.b;}}
function sendEmail(){const to=document.getElementById('email-to')?.value;const a=document.getElementById('email-asunto')?.value||'';const m=document.getElementById('email-msg')?.value||'';if(!to){toast('⚠ INGRESA UN EMAIL');return;}window.location.href='mailto:'+to+'?subject='+encodeURIComponent(a)+'&body='+encodeURIComponent(m);toast('✓ ABRIENDO CORREO');}
function toggleEf(mid,tipo,btn){fetch('api.php',{method:'POST',body:new URLSearchParams({action:'toggle_efectivo',miembro_id:mid,tipo})}).then(r=>r.json()).then(d=>{if(d.ok){const done=d.data.done;btn.textContent=done?'✓':'○';btn.style.background=done?'#EAF5F0':'#fff';btn.style.borderColor=done?'#8DCFBA':'#C8DFF0';btn.style.color=done?'#1E7A5C':'#94A3B8';toast(done?'✓ MARCADO':'○ DESMARCADO');}else toast(d.error);});}
function toggleChat(){const p=document.getElementById('chat-panel');p.classList.toggle('hidden');if(!p.classList.contains('hidden')){scrollChat();fetch('api.php',{method:'POST',body:new URLSearchParams({action:'mark_chat_seen'})});const b=document.querySelector('.chat-fab-badge');if(b)b.remove();}}
function scrollChat(){const m=document.getElementById('chat-messages');if(m)m.scrollTop=m.scrollHeight;}
function sendChat(){const inp=document.getElementById('chat-input');const msg=inp?.value.trim();if(!msg)return;inp.value='';const sendBtn=inp?.nextElementSibling;if(sendBtn)sendBtn.disabled=true;fetch('api.php',{method:'POST',body:new URLSearchParams({action:'send_chat',mensaje:msg})}).then(r=>r.json()).then(d=>{if(d.ok&&d.data){appendChatMsg({id:d.data.id,mensaje:msg,me:true});chatLastId=d.data.id;}else{inp.value=msg;toast('⚠ ERROR AL ENVIAR');}}).catch(()=>{inp.value=msg;toast('⚠ ERROR DE RED');}).finally(()=>{if(sendBtn)sendBtn.disabled=false;});}
function appendChatMsg(m){const box=document.getElementById('chat-messages');const isMe=m.me||m.user_id==UID;const t=m.created_at?(m.created_at+'').substr(11,5):'';const div=document.createElement('div');div.className='chat-msg '+(isMe?'me':'them');if(m.id)div.dataset.id=m.id;div.innerHTML='<div class="chat-msg-meta">'+(isMe?'TÚ':(m.nombre||'?').split(' ')[0])+' · '+t+'</div>'+(m.mensaje+'').replace(/</g,'&lt;');box.appendChild(div);scrollChat();}

setInterval(()=>{fetch('api.php?action=get_chat&since='+chatLastId).then(r=>r.json()).then(d=>{if(d.ok&&d.data.messages&&d.data.messages.length){d.data.messages.forEach(m=>{if(!document.querySelector('.chat-msg[data-id="'+m.id+'"]')){appendChatMsg(m);if(m.user_id!=UID){const panelOculto=document.getElementById('chat-panel').classList.contains('hidden');if(panelOculto){let b=document.querySelector('.chat-fab-badge');if(!b){b=document.createElement('span');b.className='chat-fab-badge';b.textContent='1';document.querySelector('.chat-fab').appendChild(b);}else{const n=parseInt(b.textContent)||0;b.textContent=(n+1>99)?'99+':String(n+1);}}/* 🔔 Notificación de Windows: cuando el panel está oculto O la pestaña no tiene el foco */if(panelOculto||document.hidden){const remitente=(m.nombre||'Compañero').split(' ')[0];enviarNotificacionPush('💬 '+remitente,m.mensaje);}}}chatLastId=Math.max(chatLastId,m.id);});}}); },8000);
function toggleNotifPanel(){const p=document.getElementById('notif-dropdown');p.classList.toggle('open');if(p.classList.contains('open'))loadNotifs();}
function loadNotifs(){fetch('api.php?action=get_notifs').then(r=>r.json()).then(d=>{if(!d.ok)return;const list=document.getElementById('notif-list');if(!d.data.notifs||!d.data.notifs.length){list.innerHTML='<div style="padding:14px;text-align:center;font-size:8px;color:#7A90A4;text-transform:uppercase">SIN NOTIFICACIONES</div>';return;}list.innerHTML=d.data.notifs.map(n=>'<div style="padding:9px 14px;border-bottom:1px solid #EBF4F9;background:'+(n.leido?'#fff':'#FEF8EE')+'" onclick="markNotifRead('+n.id+',this)"><div style="font-size:8px;font-weight:900;color:#1B4A6B;text-transform:uppercase">'+n.tipo+'<span style="float:right;color:#7A90A4;font-weight:400">'+n.created_at.substr(5,11)+'</span></div><div style="font-size:9px;color:#1B3A5C;margin-top:3px">'+n.mensaje+'</div></div>').join('');});}
function markNotifRead(id,el){fetch('api.php',{method:'POST',body:new URLSearchParams({action:'mark_notif_read',id})});if(el)el.style.background='#fff';}
function markAllNotifRead(){fetch('api.php',{method:'POST',body:new URLSearchParams({action:'mark_notif_read',id:0})});document.getElementById('notif-dropdown').classList.remove('open');const b=document.querySelector('.hbadge');if(b)b.remove();toast('✓ LEÍDAS');}
function sendObservacion(){
const uid=document.getElementById('obs-target').value;
const msg=document.getElementById('obs-msg').value.trim();
if(!uid||uid==='0'){toast('⚠ SELECCIONA UNA EMPLEADA');return;}
if(!msg){toast('⚠ ESCRIBE UNA OBSERVACIÓN');return;}
fetch('api.php',{method:'POST',body:new URLSearchParams({action:'send_notif',user_id:uid,mensaje:msg,tipo:'OBSERVACION'})})
.then(r=>r.json()).then(d=>{if(d.ok){toast('✓ OBSERVACIÓN ENVIADA — VISIBLE EN SU MI DÍA');document.getElementById('obs-msg').value='';}else toast('⚠ ERROR: '+(d.error||'No se pudo guardar'));}).catch(()=>toast('⚠ ERROR DE RED'));
}
function sendNotif(){const uid=document.getElementById('notif-target')?.value;const msg=document.getElementById('notif-msg')?.value.trim();if(!uid||uid==='0'){toast('⚠ SELECCIONA UN DESTINATARIO');return;}if(!msg){toast('⚠ ESCRIBE UN MENSAJE');return;}fetch('api.php',{method:'POST',body:new URLSearchParams({action:'send_notif',user_id:uid,mensaje:msg})}).then(r=>r.json()).then(d=>{if(d.ok){toast('✓ NOTIFICACIÓN ENVIADA');const el=document.getElementById('notif-msg');if(el)el.value='';}else toast('⚠ ERROR: '+(d.error||'No se pudo enviar — revisa la BD'));}).catch(()=>toast('⚠ ERROR DE RED'));}
document.addEventListener('click',e=>{const p=document.getElementById('notif-dropdown');if(p&&p.classList.contains('open')&&!p.contains(e.target)&&!e.target.closest('.hbtn'))p.classList.remove('open');});
function openMemberForm(id=null){
  fetch('member_form.php'+(id?'?id='+id:''))
    .then(r=>r.text())
    .then(html=>{
      const c=document.getElementById('member-form-content');
      c.innerHTML=html;
      // Re-crear los <script> para que el navegador los ejecute
      c.querySelectorAll('script').forEach(s=>{
        const n=document.createElement('script');
        if(s.src) n.src=s.src; else n.textContent=s.textContent;
        s.parentNode.replaceChild(n,s);
      });
      openModal('member-form-modal');
    });
}
 
function openProfile(id){
  fetch('profile.php?id='+id)
    .then(r=>r.text())
    .then(html=>{
      const c=document.getElementById('profile-content');
      c.innerHTML=html;
      // Re-crear los <script> para que el navegador los ejecute
      c.querySelectorAll('script').forEach(s=>{
        const n=document.createElement('script');
        if(s.src) n.src=s.src; else n.textContent=s.textContent;
        s.parentNode.replaceChild(n,s);
      });
      openModal('profile-modal');
    });
}
function openFinance(){openModal('finance-modal');}
function closeFinance(){closeModal('finance-modal');document.getElementById('finance-content').style.display='none';document.getElementById('finance-login').style.display='';}
function financeAuth(){const pwd=document.getElementById('fin-pwd').value;fetch('api.php',{method:'POST',body:new URLSearchParams({action:'finance_auth',pass:pwd})}).then(r=>r.json()).then(d=>{if(d.ok){document.getElementById('finance-login').style.display='none';document.getElementById('finance-content').style.display='';loadFinanceData('RESUMEN');}else{document.getElementById('fin-err').style.display='block';document.getElementById('fin-pwd').value='';}});}
function showFinTab(tab){document.querySelectorAll('.ntab[data-ftab]').forEach(b=>{b.style.color=b.dataset.ftab===tab?'#E8C354':'rgba(255,255,255,.5)';b.style.borderBottomColor=b.dataset.ftab===tab?'#E8C354':'transparent';});loadFinanceData(tab);}
function loadFinanceData(tab){fetch('finance_data.php?tab='+encodeURIComponent(tab)).then(r=>r.text()).then(html=>{document.getElementById('fin-table').innerHTML=html;});fetch('finance_data.php?tab=KPIS').then(r=>r.text()).then(h=>{document.getElementById('fin-kpis').innerHTML=h;});}
function unlockPasswords(){const pwd=document.getElementById('admin-pwd').value;fetch('api.php',{method:'POST',body:new URLSearchParams({action:'finance_auth',pass:pwd})}).then(r=>r.json()).then(d=>{if(d.ok){document.getElementById('pwd-lock').style.display='none';document.getElementById('pwd-content').style.display='';}else document.getElementById('pwd-err').style.display='block';});}
function copyText(btn){navigator.clipboard?.writeText(btn.dataset.text).then(()=>toast('✓ COPIADO')).catch(()=>toast('COPIA MANUAL'));}
function closeCueSub(modalId) {
    closeModal(modalId);
    if (cueCurrentId) openModal('modal-cue-detalle');
}
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');}));

// ── REFRESCO SUAVE (sin recargar la página) ─────────────────────────────
// Reemplaza a location.reload(): vuelve a pedir la página en segundo plano y
// reemplaza solo el contenido de los paneles, preservando scroll, pestaña
// activa, filtros/búsqueda y secciones desplegadas. Da sensación de tiempo real.
window._softReloading = false;
window._lastSoftReload = 0;
window._softReloadStart = 0;
window._softReloadPending = false;
// Libera el candado y, si se pidió otro refresco mientras este corría, lo lanza.
function _softReloadDone(){
  window._softReloading = false;
  if(window._softReloadPending){ window._softReloadPending = false; setTimeout(function(){ softReload(); }, 80); }
}
function softReload(done){
  if(window._softReloading){
    // Si el refresco anterior quedó colgado (>10s) lo liberamos; si sigue vivo,
    // encolamos uno más para que TU cambio sí se refleje (no se pierde el refresco).
    if(Date.now() - (window._softReloadStart||0) < 10000){ window._softReloadPending = true; return; }
    window._softReloading = false;
  }
  // Solo refrescamos la pestaña ACTIVA (mucho más ligero que reconstruir las 20)
  var active = document.querySelector('main .tab-pane.active');
  if(!active){ if(typeof done==='function'){ try{done();}catch(e){} } return; }
  window._softReloading = true;
  window._softReloadStart = Date.now();
  // 1) Snapshot del estado dentro del panel activo
  var scrollY = window.scrollY;
  var vals = {};   // valores de inputs/selects/textareas con id
  var disp = {};   // estado de display (acordeones/paneles abiertos o cerrados)
  active.querySelectorAll('[id]').forEach(function(el){
    var tag = el.tagName;
    if(tag==='INPUT'||tag==='SELECT'||tag==='TEXTAREA'){
      vals[el.id] = (el.type==='checkbox'||el.type==='radio') ? el.checked : el.value;
    }
    if(el.style && el.style.display){ disp[el.id] = el.style.display; }
  });
  // 2) Pedir la página fresca con timeout (para no quedar colgados)
  var ctrl = (typeof AbortController!=='undefined') ? new AbortController() : null;
  var killer = setTimeout(function(){ try{ ctrl && ctrl.abort(); }catch(e){} }, 8000);
  var opts = {headers:{'X-Requested-With':'XMLHttpRequest'}};
  if(ctrl) opts.signal = ctrl.signal;
  fetch(window.location.href, opts)
    .then(function(r){ return r.text(); })
    .then(function(html){
      clearTimeout(killer);
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var fresh = doc.getElementById(active.id);
      if(!fresh){ _softReloadDone(); return; }
      active.innerHTML = fresh.innerHTML;
      // 3) Restaurar estado del usuario sobre el contenido fresco
      Object.keys(disp).forEach(function(id){
        var el = document.getElementById(id);
        if(el){ el.style.display = disp[id]; }
      });
      Object.keys(vals).forEach(function(id){
        var el = document.getElementById(id);
        if(el && (el.tagName==='INPUT'||el.tagName==='SELECT'||el.tagName==='TEXTAREA')){
          if(el.type==='checkbox'||el.type==='radio'){ el.checked = vals[id]; }
          else { el.value = vals[id]; }
        }
      });
      // 4) Re-inicializar lo que depende de listeners (no de onclick inline)
      try{ if(typeof window._refreshChecklist==='function'){ window._refreshChecklist(); } }catch(e){}
      // TICKETS: restaurar la sub-vista completa (miembro / tarea / proyecto),
      // igual que hace showTab. Si no, reaparece "SIN TICKETS" y se pierde el
      // conteo de proyectos al refrescar estando en la pestaña PROYECTOS.
      try{
        if(active.id==='tab-TICKETS'){
          if(typeof _tktFiltroEstado!=='undefined'){
            document.querySelectorAll('.tkt-pill').forEach(function(p){
              var pid = p.id.replace('tpill-','');
              p.classList.toggle('tkt-pill-on', pid===_tktFiltroEstado);
            });
          }
          if(typeof setTktVista==='function' && typeof _tktVista!=='undefined'){ setTktVista(_tktVista); }
          else if(typeof filterTickets==='function'){ filterTickets(); }
        }
      }catch(e){}
      // Reabrir acordeón recién creado de reuniones / campañas (si aplica)
      try{ var mo=sessionStorage.getItem('mtgOpen'); if(mo){ var mb=document.getElementById('mtg-body-'+mo); if(mb) mb.style.display='block'; sessionStorage.removeItem('mtgOpen'); } }catch(e){}
      try{ var co=sessionStorage.getItem('campOpen'); if(co){ var cb=document.getElementById('camp-body-'+co); if(cb) cb.style.display='block'; sessionStorage.removeItem('campOpen'); } }catch(e){}
      // 5) Restaurar scroll
      window.scrollTo(0, scrollY);
      window._lastSoftReload = Date.now();
      _softReloadDone();
      if(typeof done==='function'){ try{ done(); }catch(e){} }
    })
    .catch(function(){ clearTimeout(killer); _softReloadDone(); });
}

// ── AUTO-REFRESCO PERIÓDICO ─────────────────────────────────────────────
// Refresca solo en segundo plano cada cierto tiempo para ver en vivo lo que
// registran las compañeras, SIN interrumpir lo que estás haciendo.
window.AUTO_REFRESH_MS = window.AUTO_REFRESH_MS || 45000; // 45 s
function _canAutoRefresh(){
  try{
    if(localStorage.getItem('crm_autorefresh')==='off') return false;
  }catch(e){}
  if(window._softReloading) return false;          // ya hay un refresco en curso
  if(document.hidden) return false;                // pestaña en segundo plano
  if(document.querySelector('.modal-overlay.open')) return false; // hay un modal abierto
  var ae = document.activeElement;                 // estás escribiendo/editando algo
  if(ae){
    var t = ae.tagName;
    if(t==='INPUT'||t==='TEXTAREA'||t==='SELECT') return false;
    if(ae.isContentEditable) return false;
  }
  return true;
}
setInterval(function(){ if(_canAutoRefresh()) softReload(); }, window.AUTO_REFRESH_MS);
// Al volver a la pestaña, refresca — pero con freno para evitar ráfagas
// (al cambiar de app en el móvil, visibilitychange se dispara muy seguido).
document.addEventListener('visibilitychange', function(){
  if(document.hidden) return;
  if((Date.now() - (window._lastSoftReload||0)) < 20000) return; // no más de 1 cada 20s
  if(_canAutoRefresh()) softReload();
});
// Permite apagar/encender desde la consola: crmAutoRefresh(false) / crmAutoRefresh(true)
window.crmAutoRefresh = function(on){
  try{ localStorage.setItem('crm_autorefresh', on===false?'off':'on'); }catch(e){}
  return on===false ? 'Auto-refresco APAGADO' : 'Auto-refresco ENCENDIDO';
};

// ── Garantizar que REGISTRAR LLAMADA siempre funcione ─────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('btn-reg-llamada');
    if (!btn) return;
    btn.removeAttribute('onclick');
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        if (typeof openLlamadaRapidaModal === 'function') {
            openLlamadaRapidaModal();
        } else {
            // función no definida — mostrar error en pantalla
            const div = document.createElement('div');
            div.style.cssText = 'position:fixed;top:60px;left:50%;transform:translateX(-50%);z-index:99999;background:#B83232;color:#fff;padding:10px 20px;border-radius:9px;font-size:11px;font-family:monospace;cursor:pointer;max-width:90vw';
            div.textContent = '🔴 ERROR: openLlamadaRapidaModal no definida. Revisa consola (F12).';
            div.onclick = () => div.remove();
            document.body.appendChild(div);
            console.error('openLlamadaRapidaModal is not defined. Check JS errors above this line.');
        }
    });
});
document.querySelectorAll('.script-card').forEach(card=>{card.addEventListener('click',function(){const o=this.classList.contains('open');const t=this.querySelector('.sc-title');const c=this.querySelector('.sc-cuando');const a=this.querySelector('.sc-arrow');if(t)t.style.color=o?'#fff':'<?=$P1?>';if(c)c.style.color=o?'rgba(255,255,255,.6)':'<?=$MU?>';if(a)a.style.color=o?'#fff':'<?=$P2?>';});});
document.querySelectorAll('.pill-btn').forEach(b=>{if(b.classList.contains('active')){b.style.background='#1B4A6B';b.style.color='#fff';b.style.borderColor='#1B4A6B';}});
scrollChat();

// ── PAGO DE BONOS (tab MIS BONOS) ────────────────────────────
const isAdmin = <?=$admin?'true':'false'?>;
function loadBonos(){
  const mes = document.getElementById('bonos-mes')?.value||'all';
  const agente = isAdmin ? (document.getElementById('bonos-agente')?.value||'all') : 'me';
  let url = 'api.php?action=get_pago_bonos&mes='+encodeURIComponent(mes);
  if(isAdmin && agente !== 'all') url += '&agente_id='+agente;
  fetch(url).then(r=>r.json()).then(d=>{
    if(!d.ok){toast('Error cargando bonos');return;}
    window._bonosRows = d.data.registros||[];
    renderBonos(d.data.registros, d.data.total_pagado, d.data.total_pendiente);
  }).catch(()=>toast('Error de conexión'));
}

function renderBonos(rows, totalPagado, totalPend){
  const tbody = document.getElementById('bonos-tbody');
  const footer = document.getElementById('bonos-footer');
  if(!tbody) return;
  if(!rows||!rows.length){
    tbody.innerHTML='<tr><td colspan="9" style="text-align:center;padding:20px;font-size:8px;color:#7A90A4;text-transform:uppercase">SIN REGISTROS</td></tr>';
    footer.innerHTML=''; return;
  }
  const fmt = n => '$'+parseFloat(n||0).toLocaleString('en-US',{minimumFractionDigits:0,maximumFractionDigits:0});
  const tipoBadge = t => t==='Bono por venta'
    ? '<span style="background:#EDE8FC;color:#4A3BAA;border-radius:20px;padding:2px 8px;font-size:7px;font-weight:900">BONO</span>'
    : t==='Pago por tickets'
    ? '<span style="background:#E3EEF9;color:#1A5A9A;border-radius:20px;padding:2px 8px;font-size:7px;font-weight:900">TICKETS</span>'
    : '<span style="background:#F1EFE8;color:#5F5E5A;border-radius:20px;padding:2px 8px;font-size:7px;font-weight:900">AJUSTE</span>';
  const estadoBadge = r => r.venta_cancelada=='1'
    ? '<span style="background:#FCEBEB;color:#A32D2D;border-radius:20px;padding:2px 8px;font-size:7px;font-weight:900">CANCELADA</span>'
    : r.pagado=='1'
    ? '<span style="background:#EAF3DE;color:#3B6D11;border-radius:20px;padding:2px 8px;font-size:7px;font-weight:900">PAGADO</span>'
    : '<span style="background:#FAEEDA;color:#854F0B;border-radius:20px;padding:2px 8px;font-size:7px;font-weight:900">PENDIENTE</span>';
  const toggleBtn = r => isAdmin
    ? `<div style="display:flex;gap:3px;flex-wrap:nowrap;align-items:center">
        <button onclick="toggleBonoPagado(${r.id},${r.pagado=='1'?0:1})" style="background:${r.pagado=='1'?'#FEF8EE':'#EAF5F0'};border:1px solid ${r.pagado=='1'?'#F5D5A0':'#8DCFBA'};border-radius:7px;padding:3px 8px;font-size:7px;font-weight:900;cursor:pointer;color:${r.pagado=='1'?'#854F0B':'#1E7A5C'};white-space:nowrap">${r.pagado=='1'?'↩ REVERTIR':'✓ PAGAR'}</button>
        <button onclick="editBono(${r.id})" title="EDITAR" style="background:#EBF4F9;border:1px solid #C8DFF0;border-radius:7px;padding:3px 8px;font-size:7px;font-weight:900;cursor:pointer;color:#1B4A6B">✎</button>
        <button onclick="deleteBono(${r.id})" title="ELIMINAR" style="background:#FDF0EE;border:1px solid #EFA09A;border-radius:7px;padding:3px 8px;font-size:7px;font-weight:900;cursor:pointer;color:#B83232">✕</button>
       </div>`
    : '';
  const mesBg = m => m==='Febrero'?'background:#E6F1FB;color:#185FA5':m==='Marzo'?'background:#E1F5EE;color:#0F6E56':'background:#F1EFE8;color:#5F5E5A';

  let bonos=0, canceladas=0;
  tbody.innerHTML = rows.map(r=>{
    if(r.tipo==='Bono por venta') bonos++;
    if(r.venta_cancelada=='1') canceladas++;
    const fecha = r.fecha ? r.fecha.slice(0,10) : '—';
    return `<tr>
      <td style="font-size:8px;color:#7A90A4">${r.id}</td>
      ${isAdmin?`<td style="font-size:9px;font-weight:900;color:#1B4A6B">${r.agente_nombre||'—'}</td>`:''}
      <td>${tipoBadge(r.tipo)}</td>
      <td style="font-size:9px">${r.cliente||'—'}</td>
      <td style="font-size:8px;color:#7A90A4">${fecha}</td>
      <td><span style="border-radius:20px;padding:2px 8px;font-size:7px;font-weight:900;${mesBg(r.mes)}">${(r.mes||'').toUpperCase()}</span></td>
      <td style="font-weight:900;font-size:9px">${parseFloat(r.total||0)>0?fmt(r.total):'—'}</td>
      <td>${estadoBadge(r)}</td>
      ${isAdmin?`<td>${toggleBtn(r)}</td>`:''}
    </tr>`;
  }).join('');

  document.getElementById('bkpi-pagado').textContent = fmt(totalPagado);
  document.getElementById('bkpi-pend').textContent   = fmt(totalPend);
  document.getElementById('bkpi-bonos').textContent  = bonos;
  document.getElementById('bkpi-cancel').textContent = canceladas;

  const total = parseFloat(totalPagado||0)+parseFloat(totalPend||0);
  footer.innerHTML = `<span>TOTAL: <strong>${fmt(total)}</strong></span><span style="color:#3B6D11;margin-left:16px">PAGADO: <strong>${fmt(totalPagado)}</strong></span><span style="color:#854F0B;margin-left:16px">PENDIENTE: <strong>${fmt(totalPend)}</strong></span>`;
}

function toggleBonoPagado(id, nuevoValor){
  fetch('api.php',{method:'POST',body:new URLSearchParams({action:'toggle_bono_pagado',id,pagado:nuevoValor})})
    .then(r=>r.json()).then(d=>{
      if(d.ok) loadBonos();
      else toast('Error: '+d.error);
    });
}
function editBono(id){
  const row=(window._bonosRows||[]).find(r=>r.id==id);
  if(row) openBonoForm(row);
  else toast('⚠ No se encontró el registro');
}
function deleteBono(id){
  if(!confirm('¿Eliminar este bono? Esta acción no se puede deshacer.')) return;
  fetch('api.php',{method:'POST',body:new URLSearchParams({action:'delete_pago_bono',id})})
    .then(r=>r.json()).then(d=>{
      if(d.ok){loadBonos();toast('Bono eliminado');}
      else toast('Error: '+d.error);
    });
}

// ── HISTORIAL / AUDIT LOG ─────────────────────────────────────
function loadAuditLog(){
  const tipo    = document.getElementById('hl-tipo')?.value    || 'all';
  const usuario = document.getElementById('hl-usuario')?.value || 'all';
  const desde   = document.getElementById('hl-desde')?.value  || '';
  const hasta   = document.getElementById('hl-hasta')?.value   || '';
  const search  = document.getElementById('hl-search')?.value  || '';
  const tb = document.getElementById('hl-tbody');
  if(tb) tb.innerHTML='<tr><td colspan="5" style="text-align:center;padding:20px;font-size:9px;color:#7A90A4">CARGANDO...</td></tr>';
  fetch('api.php?action=get_audit_log&tipo='+tipo+'&usuario='+usuario+'&desde='+desde+'&hasta='+hasta+'&search='+encodeURIComponent(search))
    .then(r=>r.json()).then(d=>{
      if(!d.ok||!tb){return;}
      if(!d.data||!d.data.length){
        tb.innerHTML='<tr><td colspan="5" style="text-align:center;padding:24px;font-size:9px;color:#7A90A4;text-transform:uppercase">SIN REGISTROS EN ESTE PERÍODO</td></tr>';
        return;
      }
      function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
      const tipoCols={SISTEMA:'#2876A8',NOTA:'#1E7A5C',RETENCION:'#7B2D8B',BONOS:'#C07A1A',CITA:'#1B5E8C',TICKET:'#B83232',LLAMADA:'#1E7A5C'};
      const tipoBg  ={SISTEMA:'#EBF4F9',NOTA:'#EAF5F0',RETENCION:'#F3F0FB',BONOS:'#FDF6EC',CITA:'#EBF5FB',TICKET:'#FDF0EE',LLAMADA:'#EAF5F0'};
      tb.innerHTML=d.data.map(a=>`<tr>
        <td style="font-size:8px;color:#7A90A4;white-space:nowrap">${esc((a.created_at||'').slice(0,16).replace('T',' '))}</td>
        <td style="font-size:9px;font-weight:800;color:#1B4A6B">${esc(a.user_nombre||'—')}</td>
        <td><span style="background:${tipoBg[a.tipo]||'#F5F5F5'};color:${tipoCols[a.tipo]||'#333'};border-radius:20px;padding:2px 8px;font-size:7px;font-weight:900;white-space:nowrap">${esc(a.tipo||'—')}</span></td>
        <td style="font-size:9px;color:#1B3A5C;max-width:300px;word-break:break-word">${esc(a.descripcion||'—')}</td>
        <td style="font-size:9px;color:#2876A8;font-weight:800">${a.miembro_id?`<span style="cursor:pointer;text-decoration:underline" onclick="openProfile(${a.miembro_id})">${esc(a.miembro_nombre||'#'+a.miembro_id)}</span>`:'—'}</td>
      </tr>`).join('');
    }).catch(()=>{if(tb)tb.innerHTML='<tr><td colspan="5" style="text-align:center;padding:20px;color:#B83232;font-size:9px">ERROR DE CONEXIÓN</td></tr>';});
}

function openBonoForm(row){
  document.getElementById('bf-id').value      = row?.id||'';
  document.getElementById('bf-agente').value  = row?.agente_id||'';
  document.getElementById('bf-tipo').value    = row?.tipo||'Bono por venta';
  document.getElementById('bf-cliente').value = row?.cliente||'';
  document.getElementById('bf-fecha').value   = row?.fecha?.slice(0,10)||'<?=date('Y-m-d')?>';
  document.getElementById('bf-mes').value     = row?.mes||'Abril';
  document.getElementById('bf-cantidad').value= row?.cantidad||1;
  document.getElementById('bf-precio').value  = row?.precio_unidad||250;
  document.getElementById('bf-total').value   = row?.total||250;
  document.getElementById('bf-pagado').value  = row?.pagado||'0';
  document.getElementById('bf-cobro').value   = row?.cobro_regreso||'0';
  document.getElementById('bf-cancelada').value = row?.venta_cancelada||'0';
  document.getElementById('bf-notas').value   = row?.notas||'';
  bonoTipoChange();
  openModal('bono-form-modal');
}

function bonoTipoChange(){
  const tipo = document.getElementById('bf-tipo').value;
  const cg = document.getElementById('bf-cliente-group');
  if(tipo==='Pago por tickets'){
    if(cg) cg.style.display='none';
    document.getElementById('bf-precio').value=1;
    calcBonoTotal();
  } else {
    if(cg) cg.style.display='';
    if(tipo==='Bono por venta') { document.getElementById('bf-precio').value=250; calcBonoTotal(); }
  }
}

function calcBonoTotal(){
  const c=parseFloat(document.getElementById('bf-cantidad')?.value||0);
  const p=parseFloat(document.getElementById('bf-precio')?.value||0);
  const t=document.getElementById('bf-total');
  if(t) t.value=(c*p).toFixed(2);
}

function submitBonoForm(e){
  e.preventDefault();
  const fd = new FormData(document.getElementById('bono-form'));
  fd.append('action','save_pago_bono');
  fetch('api.php',{method:'POST',body:new URLSearchParams(fd)}).then(r=>r.json()).then(d=>{
    if(d.ok){closeModal('bono-form-modal');loadBonos();toast('Registro guardado');}
    else toast('Error: '+d.error);
  });
}

// ── GASTOS (expense report) ───────────────────────────────────
function loadGastos(){
  const mes=document.getElementById('gastos-mes')?.value||'all';
  const cat=document.getElementById('gastos-cat')?.value||'all';
  const est=document.getElementById('gastos-est')?.value||'all';
  const yr=<?=date('Y')?>;
  const tb=document.getElementById('gastos-tbody');
  if(tb) tb.innerHTML='<tr><td colspan="12" style="text-align:center;padding:20px;color:#7A90A4;font-size:9px">CARGANDO...</td></tr>';
  fetch('api.php?action=get_gastos&mes='+mes+'&cat='+encodeURIComponent(cat)+'&est='+est+'&year='+yr)
    .then(r=>r.text())
    .then(txt=>{
      let d;
      try{ d=JSON.parse(txt); }
      catch(e){
        console.error('get_gastos respuesta no-JSON:',txt);
        if(tb) tb.innerHTML='<tr><td colspan="12" style="text-align:center;padding:20px;color:#B83232;font-size:9px">ERROR: respuesta inválida del servidor. Abre F12 → Console para ver el detalle.</td></tr>';
        return;
      }
      if(!d.ok){ if(tb) tb.innerHTML='<tr><td colspan="12" style="text-align:center;padding:20px;color:#B83232;font-size:9px">ERROR: '+(d.error||'desconocido')+'</td></tr>'; return; }
      // jsonOk envuelve todo dentro de "data": { data:[gastos], totales:{} }
      const payload = d.data||{};
      const rows = Array.isArray(payload) ? payload : (payload.data||[]);
      renderGastos(rows);
      const t=payload.totales||{total:0,aprobado:0,pendiente:0,rechazado:0};
      const fmt=v=>'$'+parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
      const setKpi=(id,v)=>{const el=document.getElementById(id);if(el)el.textContent=fmt(v);};
      setKpi('gkpi-total',t.total);setKpi('gkpi-aprobado',t.aprobado);setKpi('gkpi-pendiente',t.pendiente);setKpi('gkpi-rechazado',t.rechazado);
      const banner=document.getElementById('gastos-reembolso-banner');
      if(banner){
        const pend=rows.filter(g=>g.reembolsar_a && g.reembolsado!='1');
        const tot=pend.reduce((s,g)=>s+parseFloat(g.monto||0),0);
        if(pend.length){banner.style.display='block';banner.textContent='💵 POR REEMBOLSAR A EMPLEADOS: '+fmt(tot)+' ('+pend.length+' gasto'+(pend.length>1?'s':'')+')';}
        else banner.style.display='none';
      }
    })
    .catch(e=>{
      console.error('get_gastos fetch error:',e);
      if(tb) tb.innerHTML='<tr><td colspan="12" style="text-align:center;padding:20px;color:#B83232;font-size:9px">ERROR DE CONEXIÓN: '+(e.message||e)+'</td></tr>';
    });
}
const GASTO_CAT_LABELS={OFFICE:'OFFICE',MEETING:'MEETING',PAYROLL:'PAYROLL',MARKETING:'MARKETING',TRAINING:'TRAINING'};
const GASTO_CAT_COLORS={OFFICE:'#1B4A6B',MEETING:'#1E7A5C',PAYROLL:'#7A90A4',MARKETING:'#C07A1A',TRAINING:'#2876A8'};
const GASTO_EST_COLOR={PENDIENTE:'#C07A1A',APROBADO:'#1E7A5C',RECHAZADO:'#B83232'};
const GASTO_EST_BG={PENDIENTE:'#FDF6EC',APROBADO:'#EAF5F0',RECHAZADO:'#FDF0EE'};
function renderGastos(rows){
  const tb=document.getElementById('gastos-tbody');
  if(!tb) return;
  if(!rows||!rows.length){tb.innerHTML='<tr><td colspan="12" style="text-align:center;color:#7A90A4;padding:30px;font-size:9px;text-transform:uppercase">SIN GASTOS EN ESTE PERÍODO</td></tr>';return;}
  function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
  const fmt=v=>'$'+parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
  tb.innerHTML=rows.map(g=>{
    // FACTURA: link a la foto si existe
    const factura = g.recibo_foto
      ? `<a href="${esc(g.recibo_foto)}" target="_blank" title="VER FACTURA" style="font-size:14px;text-decoration:none">📄</a>`
      : (g.recibo=='1'?'<span style="font-size:11px;color:#1E7A5C">✓</span>':'<span style="color:#C8DFF0">—</span>');
    // REEMBOLSO: si hay reembolsar_a, mostrar a quién y si está pagado
    let reemb;
    if(g.reembolsar_a && g.reembolsar_nombre){
      if(g.reembolsado=='1'){
        reemb=`<span style="background:#EAF5F0;color:#1E7A5C;border:1px solid #8DCFBA;border-radius:20px;padding:2px 7px;font-size:7px;font-weight:900;white-space:nowrap">✓ PAGADO A ${esc(g.reembolsar_nombre.split(' ')[0].toUpperCase())}</span>`;
      } else {
        reemb=`<span style="background:#FEF8EE;color:#C07A1A;border:1px solid #F5D5A0;border-radius:20px;padding:2px 7px;font-size:7px;font-weight:900;white-space:nowrap">⏳ DEBE A ${esc(g.reembolsar_nombre.split(' ')[0].toUpperCase())}</span>`
          + (ADMIN?`<button onclick="toggleGastoReembolso(${g.id},1)" title="MARCAR COMO PAGADO" class="btn btn-gh btn-sm" style="font-size:7px;padding:2px 6px;margin-left:3px">💵 PAGAR</button>`:'');
      }
    } else { reemb='<span style="color:#C8DFF0;font-size:9px">—</span>'; }
    return `
    <tr>
      <td style="font-size:9px;white-space:nowrap">${esc(g.fecha?.split('T')[0]||g.fecha||'—')}</td>
      <td><span style="background:#EBF4F9;color:${GASTO_CAT_COLORS[g.categoria]||'#1B3A5C'};border-radius:20px;padding:2px 8px;font-size:7px;font-weight:900;border:1px solid #C8DFF0;white-space:nowrap">${GASTO_CAT_LABELS[g.categoria]||esc(g.categoria)}</span></td>
      <td style="font-size:8px;color:#7A90A4;white-space:nowrap">${esc(g.tipo||'—')}</td>
      <td style="font-size:9px;color:#1B3A5C;max-width:180px;word-break:break-word">${esc(g.descripcion)}</td>
      <td style="font-size:9px;color:#7A90A4">${esc(g.vendedor||'—')}</td>
      <td style="font-size:10px;font-weight:900;color:#1B4A6B;white-space:nowrap">${fmt(g.monto)}</td>
      <td><span style="background:#F0F4F8;border-radius:4px;padding:2px 6px;font-size:7px;font-weight:700">${esc(g.metodo_pago||'—')}</span></td>
      <td style="font-size:9px;color:#1B3A5C">${esc(g.enviado_nombre||'—')}</td>
      <td style="text-align:center">${factura}</td>
      <td style="text-align:center">${reemb}</td>
      <td><span style="background:${GASTO_EST_BG[g.estado]||'#F5F5F5'};color:${GASTO_EST_COLOR[g.estado]||'#333'};border-radius:20px;padding:2px 8px;font-size:7px;font-weight:900;white-space:nowrap">${esc(g.estado||'—')}</span></td>
      <td><div style="display:flex;gap:3px;flex-wrap:nowrap">
        ${(ADMIN&&g.estado==='PENDIENTE')?`<button onclick="updateGastoStatus(${g.id},'APROBADO')" title="APROBAR" class="btn btn-gh btn-sm" style="font-size:7px;padding:3px 7px">✓</button><button onclick="updateGastoStatus(${g.id},'RECHAZADO')" title="RECHAZAR" class="btn btn-sm" style="font-size:7px;padding:3px 7px;background:#FDF0EE;color:#B83232;border:1px solid #EFA09A">✕</button>`:''}
        ${(ADMIN||g.enviado_por==UID)?`<button onclick="deleteGasto(${g.id})" title="ELIMINAR" class="btn btn-sm" style="font-size:7px;padding:3px 7px;background:#F5F5F5;color:#7A90A4;border:1px solid #D0D7DE">🗑</button>`:''}
      </div></td>
    </tr>`;}).join('');
}
function openGastoForm(){
  document.getElementById('gasto-modal').style.display='flex';
  document.getElementById('gasto-form').reset();
  document.getElementById('gasto-tipo-sel').innerHTML='<option value="">— SELECCIONAR CATEGORÍA PRIMERO —</option>';
}
function closeGastoModal(){document.getElementById('gasto-modal').style.display='none';}
function updateGastoTipos(){
  const tiposPorCat={
    OFFICE:['RENT','UTILITIES','SUPPLIES','EQUIPMENT','SOFTWARE','MAINTENANCE'],
    MEETING:['MEALS','GIFTS','MILEAGE','PARKING','TRANSPORTATION','LODGING','PRINTED MATERIALS'],
    PAYROLL:['SALARIES','WAGES','OVERTIME','COMMISSIONS','BONUSES','CONTRACTOR PAYMENTS','BENEFITS'],
    MARKETING:['ADS','FLYERS','EVENTS','WEBSITE','MERCHANDISE'],
    TRAINING:['COURSES','COACHING','BOOKS','CONFERENCES']
  };
  const cat=document.getElementById('gasto-cat-sel')?.value;
  const sel=document.getElementById('gasto-tipo-sel');
  if(!sel) return;
  const opts=tiposPorCat[cat]||[];
  sel.innerHTML='<option value="">— TIPO OPCIONAL —</option>'+opts.map(t=>`<option value="${t}">${t}</option>`).join('');
}
async function saveGasto(){
  const form=document.getElementById('gasto-form');
  if(!form.checkValidity()){form.reportValidity();return;}
  const fd=new FormData(form);
  fd.append('action','save_gasto');
  const btn=document.getElementById('gasto-save-btn');
  if(btn){btn.disabled=true;btn.textContent='GUARDANDO...';}
  try{
    const r=await fetch('api.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){closeGastoModal();loadGastos();if(typeof toast==='function')toast('✓ GASTO GUARDADO');}
    else alert(d.error||'Error al guardar');
  }catch(e){alert('Error de conexión');}
  if(btn){btn.disabled=false;btn.textContent='✓ GUARDAR GASTO';}
}
async function toggleGastoReembolso(id,pagado){
  if(pagado && !confirm('¿Confirmas que YA le pagaste/reembolsaste este gasto al empleado?')) return;
  const fd=new FormData();
  fd.append('action','toggle_gasto_reembolso');fd.append('id',id);fd.append('pagado',pagado?1:0);
  const r=await fetch('api.php',{method:'POST',body:fd});
  const d=await r.json();
  if(d.ok){loadGastos();if(typeof toast==='function')toast('✓ REEMBOLSO ACTUALIZADO');}
  else alert(d.error||'Error');
}
async function updateGastoStatus(id,estado){
  const fd=new FormData();
  fd.append('action','update_gasto_status');
  fd.append('id',id);fd.append('estado',estado);
  const r=await fetch('api.php',{method:'POST',body:fd});
  const d=await r.json();
  if(d.ok){loadGastos();if(typeof toast==='function')toast('✓ ESTADO ACTUALIZADO');}
  else alert(d.error||'Error');
}
async function deleteGasto(id){
  if(!confirm('¿Eliminar este gasto?')) return;
  const fd=new FormData();
  fd.append('action','delete_gasto');fd.append('id',id);
  const r=await fetch('api.php',{method:'POST',body:fd});
  const d=await r.json();
  if(d.ok){loadGastos();if(typeof toast==='function')toast('Gasto eliminado');}
  else alert(d.error||'Error');
}

// ── INCENTIVOS (admin — sección existente, renombrada) ────────
function loadBonosIncentivos(){
fetch('api.php?action=get_bonos_incentivos').then(r=>r.json()).then(d=>{
if(!d.ok){toast('Error cargando bonos');return;}
window._bonosData = d.data.reporte;
filterBonosIncentivos();
});
}
function filterBonosIncentivos(){
const data = window._bonosData || [];
const emp = document.getElementById('bono-filter-emp')?.value||'';
const sts = document.getElementById('bono-filter-status')?.value||'';
const filtered = data.filter(b=>(!emp||b.empleado?.includes(emp))&&(!sts||b.status===sts));
const tbody = document.getElementById('bonos-tbody');
if(!tbody)return;
if(!filtered.length){tbody.innerHTML='<tr><td colspan="7" style="text-align:center;padding:20px;font-size:8px;color:#7A90A4;text-transform:uppercase">SIN RESULTADOS</td></tr>';return;}

const colores = {CONSOLIDADO:'#1E7A5C',CHARGEBACK:'#B83232',PENDIENTE:'#C07A1A'};
let total = 0;
tbody.innerHTML = filtered.map(b=>{
const c = colores[b.status]||'#7A90A4';
if(b.monto!==0)total+=b.monto;
const monto = b.monto===0?'<span style="color:#7A90A4">CONSOLIDADO</span>':`<span style="color:${c};font-weight:900">${b.monto<0?'− ':'+ '}L. ${Math.abs(b.monto)}.00</span>`;
return `<tr><td style="font-size:9px;font-weight:900;color:#1B4A6B">${b.empleado||'—'}</td><td style="font-size:9px">${b.miembro}</td><td style="font-size:8px;color:#7A90A4">${b.efectiva||'—'}</td><td style="font-size:9px;font-weight:800">${b.dias} días</td><td>${b.status==='CANCELADO'?'<span style="color:#B83232;font-weight:900">CANCELADO</span>':b.estado}</td><td><span style="font-weight:900;color:${c}">${b.status}</span></td><td>${monto}</td></tr>`;
}).join('');
const res = document.getElementById('bono-resumen');
if(res) res.textContent = `${filtered.length} PÓLIZAS · BALANCE: L. ${total}.00`;
}
function importCSV(){
const file = document.getElementById('import-file')?.files[0];
const agente = document.getElementById('import-agente')?.value;
if(!file){toast('⚠ SELECCIONA UN ARCHIVO CSV');return;}
const fd = new FormData();
fd.append('action','import_csv');
fd.append('agente_id',agente);
fd.append('file',file);
fetch('api.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
const res = document.getElementById('import-result');
if(d.ok){
if(res){res.style.display='';res.style.background='#EAF5F0';res.style.border='1px solid #8DCFBA';res.style.borderRadius='8px';res.style.padding='8px 12px';res.style.fontSize='9px';res.style.fontWeight='900';res.style.color='#1E7A5C';res.style.textTransform='uppercase';res.textContent='✓ IMPORTADOS: '+d.data.importados+' IMPORTADOS'+(d.data.duplicados?' · '+d.data.duplicados+' DUPLICADOS OMITIDOS':'');}
toast('✓ '+d.data.importados+' PROSPECTOS IMPORTADOS');
setTimeout(()=>softReload(),1200);
} else {
toast('ERROR: '+(d.error||'Fallo al importar'));
}
});
}
// ── DARK MODE ──────────────────────────────────────
function toggleDarkMode(){
const isDark=document.documentElement.getAttribute('data-theme')==='dark';
const next=isDark?'light':'dark';
document.documentElement.setAttribute('data-theme',next);
document.body.setAttribute('data-theme',next);
document.getElementById('dark-btn').textContent=next==='dark'?'☀️':'🌙';
try{localStorage.setItem('crm_theme',next);}catch(e){}
}
(function initTheme(){
try{
const saved=localStorage.getItem('crm_theme')||'light';
if(saved==='dark'){
document.documentElement.setAttribute('data-theme','dark');
document.body.setAttribute('data-theme','dark');
const b=document.getElementById('dark-btn');if(b)b.textContent='☀️';
}

}catch(e){}
})();

// ── CUESTIONARIO DE APLICACIÓN ─────────────────────────────────────────────
 
// Reemplaza la función original completarCita(id)
function completarCita(id) {
  const btn = event.currentTarget;
  if (btn) { btn.disabled = true; btn.textContent = '⏳'; }
  fetch('api.php?action=get_cita&id=' + id)
    .then(r => r.json())
    .then(d => {
      if (btn) { btn.disabled = false; btn.textContent = '✓ COMPLETAR'; }
      if (!d.ok) { toast('⚠ ' + (d.error || 'Error al cargar cita')); return; }
      _abrirAppModal(id, d.data);
    })
    .catch(() => {
      if (btn) { btn.disabled = false; btn.textContent = '✓ COMPLETAR'; }
      toast('⚠ Error de red');
    });
}
 
function _abrirAppModal(citaId, cita) {
  // Reset del form
  document.getElementById('app-form').reset();
  document.getElementById('app-cita-id').value  = citaId;
  document.getElementById('app-mid').value       = cita.miembro_id || '';
  document.getElementById('app-sep').value       = '';
 
  // Título
  const nombre = (cita.miembro_nombre || cita.cliente || '').trim() || 'PROSPECTO';
  document.getElementById('app-modal-title').textContent =
    '📋 CUESTIONARIO DE APLICACIÓN — ' + nombre.toUpperCase();
 
  // Pre-rellenar con datos del miembro si existe en BD
  if (cita.miembro_id) {
    const m = _membersFullData.find(x => x.id == cita.miembro_id);
    if (m) _rellenarAppForm(m);
  }
 
  // Defaults
  _setAppLang(cita.miembro_id
    ? (_membersFullData.find(x => x.id == cita.miembro_id)?.idioma || 'ESP')
    : 'ESP');
 
  // Fecha efectiva default = 1ro del próximo mes
  const efe = document.getElementById('app-fecha-efe');
  if (efe && !efe.value) {
    const d = new Date();
    d.setDate(1);
    d.setMonth(d.getMonth() + 1);
    efe.value = d.toISOString().split('T')[0];
  }
 
  openModal('app-modal');
}
 
function _rellenarAppForm(m) {
  const f = (id, v) => { const el = document.getElementById(id); if (el && v) el.value = v; };
  f('app-nombre',        m.nombre);
  f('app-apellido',      m.apellido);
  f('app-dob',           m.dob);
  f('app-tel',           m.telefono);
  f('app-email',         m.email);
  f('app-mbi',           m.mbi);
  f('app-parte-a',       m.parte_a);
  f('app-parte-b',       m.parte_b);
  f('app-elegibilidad',  m.elegibilidad);
  f('app-pcp',           m.pcp);
  f('app-pcp-group',     m.pcp_group);
  f('app-carrier',       m.carrier);
  f('app-plan',          m.plan);
  f('app-calle',         m.direccion_calle);
  f('app-apto',          m.direccion_apto);
  f('app-ciudad',        m.ciudad);
  f('app-zip',           m.zip);
  f('app-county',        m.county || 'LOS ANGELES');
  f('app-cronicas',      m.condiciones_cronicas);
  f('app-medicamentos',  m.prescripciones);
  if (m.fecha_efectiva)  f('app-fecha-efe', m.fecha_efectiva);
 
  // Medi-Cal
  const medEl = document.getElementById('app-medical');
  if (medEl && m.medical) {
    medEl.value = m.medical;
    _toggleMedical();
    f('app-medical-nivel', m.medical_nivel);
  }
 
  // Referido
  if (m.referido_por) {
    _setAppRef('si');
    f('app-referido-por', m.referido_por);
  }
}
 
function _setAppLang(lang) {
  document.querySelectorAll('.app-lang-btn').forEach(b => {
    const activo = b.dataset.lang === lang;
    b.style.background  = activo ? '#1B4A6B' : '#fff';
    b.style.color       = activo ? '#fff'     : '#7A90A4';
    b.style.borderColor = activo ? '#1B4A6B'  : '#C8DFF0';
  });
  const el = document.getElementById('app-idioma');
  if (el) el.value = lang;
}
 
function _setAppRef(val) {
  document.querySelectorAll('.app-ref-btn').forEach(b => {
    const activo = b.dataset.val === val;
    b.style.background  = activo ? '#1B4A6B' : '#fff';
    b.style.color       = activo ? '#fff'     : '#7A90A4';
    b.style.borderColor = activo ? '#1B4A6B'  : '#C8DFF0';
  });
  const wrap = document.getElementById('app-ref-wrap');
  if (wrap) wrap.style.display = val === 'si' ? '' : 'none';
}
 
function _toggleMedical() {
  const sel  = document.getElementById('app-medical');
  const wrap = document.getElementById('app-medical-nivel-wrap');
  if (wrap) wrap.style.display = sel?.value === 'SÍ' ? '' : 'none';
}
 
function submitAppForm(e) {
  e.preventDefault();
  const btn = document.getElementById('app-submit-btn');
  btn.disabled = true; btn.textContent = 'GUARDANDO...';
 
  const fd      = new FormData(e.target);
  const citaId  = document.getElementById('app-cita-id').value;
  const mid     = document.getElementById('app-mid').value;
  const sep     = (document.getElementById('app-sep')?.value || '').trim();
 
  // SEP → agregar a extras
  if (sep) {
    const extraActual = (_membersFullData.find(m => m.id == mid)?.extras || '');
    const extraNuevo  = (extraActual ? extraActual + '\n' : '') + 'SEP: ' + sep;
    fd.set('extras', extraNuevo);
  }
  fd.delete('_sep');
 
  // 1. Guardar miembro con READY TO ENROLL (solo si tiene miembro_id)
  const guardarMiembro = () => {
    if (!mid) return Promise.resolve({ ok: true });
    fd.set('action', 'save_member');
    fd.set('id',     mid);
    fd.set('estado', 'READY TO ENROLL');
    return fetch('api.php', { method: 'POST', body: fd }).then(r => r.json());
  };
 
  // 2. Marcar cita como COMPLETADA
  const completarCitaFetch = () =>
    fetch('api.php', {
      method: 'POST',
      body: new URLSearchParams({ action: 'complete_cita', id: citaId })
    }).then(r => r.json());
 
  guardarMiembro()
    .then(d => {
      if (!d.ok) throw new Error(d.error || 'Error guardando datos');
      return completarCitaFetch();
    })
    .then(d => {
      if (!d.ok) throw new Error(d.error || 'Error completando cita');
      toast('✓ CITA COMPLETADA — LISTO PARA APLICAR 🎉');
      closeModal('app-modal');
      saveTabAndReload();
    })
    .catch(err => {
      toast('⚠ ' + err.message);
      btn.disabled = false;
      btn.textContent = '✓ GUARDAR Y MARCAR READY TO ENROLL';
    });
}

function cancelarCita(id){
  if(!confirm('¿Cancelar esta cita?\n\nEsta acción se puede deshacer editando la cita.'))return;
  fetch('api.php',{method:'POST',body:new URLSearchParams({action:'cancel_cita',id})})
    .then(r=>r.json()).then(d=>{if(d.ok){toast('✓ CITA CANCELADA');saveTabAndReload();}else toast(d.error||'Error');});
}

function editarCita(id){
  fetch('api.php?action=get_cita&id='+id)
    .then(r=>r.json()).then(d=>{
      if(!d.ok){ toast(d.error||'Error'); return; }
      const c = d.data;
      document.getElementById('cita-modal-title').textContent = '✎ EDITAR CITA #'+c.id;
      document.getElementById('cita-id').value = c.id;
      // Modo cliente
      if(c.miembro_id){
        setCitaClienteMode('miembro');
        document.getElementById('cita-miembro-sel').value = c.miembro_id;
        const citaMpickInp = document.getElementById('cita-mpick-input');
        if(citaMpickInp && c.miembro_id) {
          const cm = _membersData.find(x => x.id == c.miembro_id);
          citaMpickInp.value = cm ? cm.label : (c.miembro_nombre || '');
        }
      } else {
        setCitaClienteMode('libre');
        document.getElementById('cita-cliente-input').value = c.cliente || '';
      }
      document.getElementById('cita-tipo').value = c.tipo || 'PRESENTACIÓN';
      document.getElementById('cita-modalidad').value = c.modalidad || 'OFICINA';
      document.getElementById('cita-fecha').value = c.fecha || '';
      document.getElementById('cita-hora').value = (c.hora||'').substring(0,5);
      document.getElementById('cita-notas').value = c.notas || '';
      const ag = document.getElementById('cita-agente');
      if(ag && c.agente_id) ag.value = c.agente_id;
      openModal('cita-form-modal');
    });
}

function setCitaClienteMode(mode){
  document.querySelectorAll('.cli-mode-btn').forEach(b=>{
    if(b.dataset.mode===mode){
      b.style.background = '#1B4A6B'; b.style.color = '#fff'; b.style.border = 'none';
      b.classList.add('active');
    } else {
      b.style.background = '#EBF4F9'; b.style.color = '#1B4A6B'; b.style.border = '1px solid #C8DFF0';
      b.classList.remove('active');
    }
  });
  document.getElementById('cita-miembro-group').style.display = mode==='miembro' ? '' : 'none';
  document.getElementById('cita-cliente-group').style.display = mode==='libre' ? '' : 'none';
  // Limpiar el campo no usado para que no se envíe basura
  if(mode==='miembro') document.getElementById('cita-cliente-input').value = '';
  else {
    document.getElementById('cita-miembro-sel').value = '';
    const citaMpickInp2 = document.getElementById('cita-mpick-input');
    if(citaMpickInp2) citaMpickInp2.value = '';
  }
}

function abrirNuevaCita(){
  // Resetear todo
  document.getElementById('cita-modal-title').textContent = '◷ NUEVA CITA';
  document.getElementById('cita-form').reset();
  document.getElementById('cita-id').value = '';
  document.getElementById('cita-fecha').value = new Date().toISOString().slice(0,10);
  document.getElementById('cita-hora').value = '09:00';
  document.getElementById('cita-notas').value = '';
  setCitaClienteMode('miembro');
  __origOpenModal('cita-form-modal'); // <-- SOLUCIÓN
}

// Override openModal para nueva cita: si abres "cita-form-modal" sin cargar datos, resetea
const __origOpenModal = window.openModal;
window.openModal = function(id){
  if(id==='cita-form-modal' && !document.getElementById('cita-id').value){
    abrirNuevaCita();
    return;
  }
  __origOpenModal(id);
};

function crearTicketDesdeCita(citaId){
  fetch('api.php?action=get_cita&id='+citaId)
    .then(r=>r.json()).then(d=>{
      if(!d.ok){ toast(d.error||'Error'); return; }
      const c = d.data;
      // Pre-llenar el modal de ticket con datos de la cita
      const tForm = document.getElementById('ticket-form-modal')?.querySelector('form');
      if(!tForm){ toast('No se encontró el formulario de tickets'); return; }
      // Reset y cierra modal de cita si está abierto
      tForm.reset();
      closeModal('cita-form-modal');
      // Llenar campos
      const setFld = (n,v)=>{ const el = tForm.querySelector(`[name=${n}]`); if(el && v!=null) el.value = v; };
      setFld('miembro_id', c.miembro_id || '');
      setFld('cliente', c.cliente || '');
      setFld('tipo', 'FOLLOW UP');
      setFld('prioridad', 'MEDIA');
      const cliName = c.miembro_nombre || c.cliente || '';
      setFld('descripcion', `Seguimiento de cita del ${c.fecha} a las ${(c.hora||'').substring(0,5)} (${c.tipo||''} - ${c.modalidad||''})${cliName?' con '+cliName:''}`);
      setFld('fuente', 'CRM');
      setFld('asignado_a', c.agente_id || '');
      openModal('ticket-form-modal');
    });
}

function cambiarSubtabCitas(sub){
  document.querySelectorAll('.cita-subtab').forEach(b=>{
    if(b.dataset.csub===sub){
      b.style.borderBottom = '3px solid #1B4A6B';
      b.style.color = '#1B4A6B';
      b.classList.add('active');
    } else {
      b.style.borderBottom = '3px solid transparent';
      b.style.color = '#7A90A4';
      b.classList.remove('active');
    }
  });
  document.querySelectorAll('.csub-pane').forEach(p=>p.style.display='none');
  const target = document.getElementById('csub-'+sub);
  if(target) target.style.display = '';
  filtrarCitas();
}

function filtrarCitas(){
  const q       = (document.getElementById('cita-search')?.value||'').toLowerCase().trim();
  const fecha   = document.getElementById('cita-fecha-filtro')?.value||'';
  const agente  = document.getElementById('cita-agente-filtro')?.value||'';
  const tipo    = document.getElementById('cita-tipo-filtro')?.value||'';
  const moda    = document.getElementById('cita-modalidad-filtro')?.value||'';

  document.querySelectorAll('.cita-card').forEach(c=>{
    let show = true;
    if(q && !(c.dataset.search||'').includes(q)) show = false;
    if(fecha && c.dataset.fecha!==fecha) show = false;
    if(agente && c.dataset.agente!==agente) show = false;
    if(tipo && c.dataset.tipo!==tipo) show = false;
    if(moda && c.dataset.modalidad!==moda) show = false;
    c.style.display = show ? '' : 'none';
  });

  // Ocultar grupos vacíos
  document.querySelectorAll('.cita-grupo').forEach(g=>{
    const visibles = [...g.querySelectorAll('.cita-card')].filter(c=>c.style.display!=='none').length;
    g.style.display = visibles>0 ? '' : 'none';
  });
}

function resetCitaFiltros(){
  ['cita-search','cita-fecha-filtro','cita-agente-filtro','cita-tipo-filtro','cita-modalidad-filtro'].forEach(id=>{
    const el = document.getElementById(id);
    if(el) el.value = '';
  });
  filtrarCitas();
}

function exportCitasCSV(){
  const visibles = [...document.querySelectorAll('.cita-card')].filter(c=>c.style.display!=='none' && c.closest('.csub-pane') && c.closest('.csub-pane').style.display!=='none');
  if(!visibles.length){ toast('No hay citas visibles para exportar'); return; }
  const filas = [['Fecha','Hora','Cliente','Tipo','Modalidad','Agente','Notas']];
  visibles.forEach(c=>{
    const cli = c.querySelector('div[style*="cursor:pointer"], div[style*="font-weight:900"]')?.textContent.trim() || '';
    const hora = c.querySelector('div[style*="font-size:14px"]')?.textContent.trim() || '';
    filas.push([c.dataset.fecha, hora, cli, c.dataset.tipo, c.dataset.modalidad, '', '']);
  });
  const csv = filas.map(r=>r.map(v=>'"'+(''+v).replace(/"/g,'""')+'"').join(',')).join('\n');
  const blob = new Blob(['\ufeff'+csv],{type:'text/csv;charset=utf-8'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'citas_'+new Date().toISOString().slice(0,10)+'.csv';
  a.click();
  toast('✓ CSV descargado');
}

// ── TAB RESTORE AFTER RELOAD ────────────────────────────────────
function saveTabAndReload(){
  try{
    sessionStorage.setItem('pendingReload','1');
    // Persist ticket filter state so tickets don't "disappear" after any action
    sessionStorage.setItem('tktVista', _tktVista);
    sessionStorage.setItem('tktFiltroEstado', _tktFiltroEstado);
    const resp = document.getElementById('tkt-resp')?.value||'';
    sessionStorage.setItem('tktResp', resp);
    const prio = document.getElementById('tkt-prio')?.value||'';
    sessionStorage.setItem('tktPrio', prio);
    const tipo = document.getElementById('tkt-tipo')?.value||'';
    sessionStorage.setItem('tktTipo', tipo);
    // Save search text to avoid browser autocomplete re-populating it
    const searchVal = document.getElementById('tkt-search')?.value||'';
    sessionStorage.setItem('tktSearch', searchVal);
  }catch(e){}
  setTimeout(()=>softReload(),300);
}
(function restoreTab(){
  try{
    const saved=sessionStorage.getItem('activeTab');
    const pending=sessionStorage.getItem('pendingReload');
    if(saved && pending==='1'){
      sessionStorage.removeItem('pendingReload');
      // Restore ticket filter state BEFORE calling showTab so filterTickets uses correct values
      const vista = sessionStorage.getItem('tktVista');
      const filtroEstado = sessionStorage.getItem('tktFiltroEstado');
      const resp  = sessionStorage.getItem('tktResp');
      const prio  = sessionStorage.getItem('tktPrio');
      const tipo  = sessionStorage.getItem('tktTipo');
      const search = sessionStorage.getItem('tktSearch');
      if(vista) _tktVista = vista;
      if(filtroEstado) _tktFiltroEstado = filtroEstado;
      setTimeout(()=>{
        showTab(saved);
        // Restore DOM selects after tab renders
        if(saved==='TICKETS'){
          if(resp !== null){ const el=document.getElementById('tkt-resp'); if(el) el.value=resp; }
          if(prio !== null){ const el=document.getElementById('tkt-prio'); if(el) el.value=prio; }
          if(tipo !== null){ const el=document.getElementById('tkt-tipo'); if(el) el.value=tipo; }
          // Restore search text (saved explicitly, so no browser autocomplete interference)
          const sEl=document.getElementById('tkt-search'); if(sEl) sEl.value=search||'';
          // Re-apply pill visual
          document.querySelectorAll('.tkt-pill').forEach(p=>{
            const pid=p.id.replace('tpill-','');
            p.classList.toggle('tkt-pill-on', pid===_tktFiltroEstado);
          });
          filterTickets();
        }
      },50);
    } else {
      // Normal page load (no pending reload): ensure search box is empty
      setTimeout(()=>{const sEl=document.getElementById('tkt-search');if(sEl)sEl.value='';},0);
    }
  }catch(e){}
})();
// pageshow fires after bfcache restore AND normal load — always clear tkt-search
window.addEventListener('pageshow', function(){
  try{
    const sEl = document.getElementById('tkt-search');
    if(sEl && !sessionStorage.getItem('pendingReload')) sEl.value = '';
  }catch(e){}
});

// ── CHAT DM FUNCTIONS ──────────────────────────────────────────
let activeChatTab='GRUPO';
let dmLastId=0;
function switchChatTab(tab){
  activeChatTab=tab;
  document.getElementById('chat-tab-GRUPO').style.display=tab==='GRUPO'?'flex':'none';
  document.getElementById('chat-tab-DM').style.display=tab==='DM'?'flex':'none';
  const gBtn=document.getElementById('ctab-btn-GRUPO');
  const dBtn=document.getElementById('ctab-btn-DM');
  if(gBtn){gBtn.style.background=tab==='GRUPO'?'rgba(255,255,255,.2)':'rgba(255,255,255,.08)';gBtn.style.color=tab==='GRUPO'?'#fff':'rgba(255,255,255,.6)';}
  if(dBtn){dBtn.style.background=tab==='DM'?'rgba(255,255,255,.2)':'rgba(255,255,255,.08)';dBtn.style.color=tab==='DM'?'#fff':'rgba(255,255,255,.6)';}
  if(tab==='DM'){const s=document.getElementById('dm-target');if(s&&s.value)loadDMs();}
}
function loadDMs(){
  const toUser=document.getElementById('dm-target')?.value;
  if(!toUser)return;
  const box=document.getElementById('dm-messages');
  box.innerHTML='<div style="text-align:center;font-size:8px;color:#7A90A4;padding:14px">Cargando...</div>';
  fetch('api.php?action=get_dms&with='+toUser).then(r=>r.json()).then(d=>{
    if(!d.ok){box.innerHTML='<div style="padding:14px;font-size:8px;color:#B83232;text-align:center">Error cargando mensajes</div>';return;}
    const msgs=d.data.messages||[];
    if(!msgs.length){box.innerHTML='<div style="text-align:center;font-size:8px;color:#7A90A4;padding:20px;text-transform:uppercase">Sin mensajes aún · ¡Envía el primero!</div>';return;}
    box.innerHTML=msgs.map(m=>{
      const isMe=m.sender_id==UID;
      return '<div class="chat-msg '+(isMe?'me':'them')+'" style="margin-bottom:4px"><div class="chat-msg-meta">'+(isMe?'TÚ':m.sender_nombre)+' · '+(m.created_at+'').substr(11,5)+'</div>'+m.mensaje.replace(/</g,'&lt;')+'</div>';
    }).join('');
    box.scrollTop=box.scrollHeight;
    dmLastId=msgs[msgs.length-1]?.id||0;
  }).catch(()=>{box.innerHTML='<div style="padding:14px;font-size:8px;color:#B83232;text-align:center">Error de red</div>';});
}
function sendDM(){
  const inp=document.getElementById('dm-input');
  const toUser=document.getElementById('dm-target')?.value;
  const msg=inp?.value.trim();
  if(!toUser){toast('⚠ SELECCIONA UNA PERSONA');return;}
  if(!msg)return;
  inp.value='';
  fetch('api.php',{method:'POST',body:new URLSearchParams({action:'send_dm',to_user:toUser,mensaje:msg})})
    .then(r=>r.json()).then(d=>{
      if(d.ok){
        const box=document.getElementById('dm-messages');
        const div=document.createElement('div');
        div.className='chat-msg me';
        div.innerHTML='<div class="chat-msg-meta">TÚ · ahora</div>'+msg.replace(/</g,'&lt;');
        box.appendChild(div);box.scrollTop=box.scrollHeight;
      }else toast('⚠ '+(d.error||'Error enviando'));
    }).catch(()=>toast('⚠ Error de red'));
}
// Poll DMs
setInterval(()=>{
  const toUser=document.getElementById('dm-target')?.value;
  if(activeChatTab==='DM'&&toUser&&document.getElementById('chat-panel')&&!document.getElementById('chat-panel').classList.contains('hidden')){
    fetch('api.php?action=get_dms&with='+toUser+'&since='+dmLastId).then(r=>r.json()).then(d=>{
      if(d.ok&&d.data.messages&&d.data.messages.length){
        const box=document.getElementById('dm-messages');
        d.data.messages.forEach(m=>{
          if(!box.querySelector('[data-dmid="'+m.id+'"]')){
            const div=document.createElement('div');
            div.className='chat-msg '+(m.sender_id==UID?'me':'them');
            div.dataset.dmid=m.id;
            div.innerHTML='<div class="chat-msg-meta">'+(m.sender_id==UID?'TÚ':m.sender_nombre)+' · '+(m.created_at+'').substr(11,5)+'</div>'+m.mensaje.replace(/</g,'&lt;');
            box.appendChild(div);box.scrollTop=box.scrollHeight;
            // 🔔 Notificación de Windows si el mensaje es de otro y la pestaña no tiene foco
            if(m.sender_id!=UID&&document.hidden){
              enviarNotificacionPush('✉ '+(m.sender_nombre||'DM').split(' ')[0],m.mensaje);
            }
          }
          dmLastId=Math.max(dmLastId,m.id);
        });
      }
    });
  }
},9000);

// ── DAILY CHECKLIST PERSISTENCE ────────────────────────────────
// El checklist ahora se persiste en la BD via bindChecklist() + api.php (toggle_checklist)
// Se eliminó el initChecklist que usaba localStorage (solo guardaba por navegador)

// ── CHECK-IN ENFORCEMENT ────────────────────────────────────────
<?php if(!$admin && $my_ci && $my_ci['check_in'] && !$my_ci['check_out']): ?>
window.addEventListener('beforeunload',function(e){
  const msg='⚠ Aún no has hecho CHECK-OUT. ¿Seguro que quieres salir?';
  e.returnValue=msg;return msg;
});
<?php endif; ?>

// ── RELOAD HELPERS — preserve tab ──────────────────────────────
function closeTicket(id){
  const nota=prompt('📝 NOTA DE CIERRE (obligatoria):');
  if(!nota||!nota.trim()){toast('⚠️ Escribe una nota para cerrar');return;}
  fetch('api.php',{method:'POST',body:new URLSearchParams({action:'close_ticket',id,nota_cierre:nota})})
    .then(r=>r.json()).then(d=>{
      if(d.ok){
        toast('✓ TICKET CERRADO');
        // Actualización instantánea de la fila (no esperar al refresco completo)
        const row=document.querySelector('.ticket-row[data-id="'+id+'"]');
        if(row){ row.dataset.estado='CERRADO'; row.classList.add('tkt-cerrada'); }
        if(typeof filterTickets==='function') filterTickets();
        saveTabAndReload();
      }
      else toast('⚠ '+(d.error||'Error'));
    });
}
function submitCita(e){
  e.preventDefault();
  const fd = new FormData(e.target);
  const id = document.getElementById('cita-id').value;
  fd.append('action', id ? 'update_cita' : 'save_cita');
  // Validar: o miembro_id o cliente, no puede ir vacío
  if(!fd.get('miembro_id') && !fd.get('cliente').trim()){
    toast('⚠ Debes seleccionar un miembro o escribir el nombre del cliente');
    return;
  }
  fetch('api.php',{method:'POST',body:new URLSearchParams(fd)})
    .then(r=>r.json()).then(d=>{
      if(d.ok){
        toast(id ? '✓ CITA ACTUALIZADA' : '✓ CITA GUARDADA');
        closeModal('cita-form-modal');
        saveTabAndReload();
      } else toast(d.error||'Error al guardar');
    });
}
function submitLlamada(e){e.preventDefault();const fd=new FormData(e.target);fd.append('action','save_llamada');fetch('api.php',{method:'POST',body:new URLSearchParams(fd)}).then(r=>r.json()).then(d=>{if(d.ok){toast('✓ REGISTRADA');closeModal('llamada-form-modal');saveTabAndReload();}});}
function devolverLlamada(id){fetch('api.php',{method:'POST',body:new URLSearchParams({action:'devolver_llamada',id})}).then(r=>r.json()).then(d=>{if(d.ok){toast('✓ DEVUELTA');saveTabAndReload();}});}
</script>

<div id="urgentes-popup" style="display:none;position:fixed;top:20px;right:20px;z-index:9999;max-width:320px">
</div>
<script>
// Urgent tickets popup — show on load, every 15 min
function mostrarUrgentes(){
  const urgentes = <?= json_encode(array_values(array_filter($tickets, fn($t)=>
    $t['prioridad']==='ALTA' && $t['estado']!=='CERRADO'
  ))) ?>;
  if(!urgentes.length) return;
  const pop = document.getElementById('urgentes-popup');
  if(!pop) return;
  pop.innerHTML = `<div style="background:#FDF0EE;border:2px solid #B83232;border-radius:12px;padding:14px;box-shadow:0 4px 20px rgba(0,0,0,.15)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <span style="font-weight:900;font-size:11px;color:#B83232;text-transform:uppercase">◈ ${urgentes.length} TICKET${urgentes.length>1?'S':''} URGENTE${urgentes.length>1?'S':''}</span>
      <button onclick="document.getElementById('urgentes-popup').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:16px;color:#B83232">✕</button>
    </div>
    ${urgentes.slice(0,3).map(t=>`
      <div style="background:#fff;border-radius:8px;padding:8px 10px;margin-bottom:6px;border-left:3px solid #B83232">
        <div style="font-size:10px;font-weight:700;color:#1B3A5C">${(t.miembro_nombre||t.cliente||'—').substring(0,30)}</div>
        <div style="font-size:9px;color:#7A90A4;margin-top:2px">${(t.descripcion||'').substring(0,60)}</div>
        <div style="font-size:8px;color:#B83232;margin-top:3px">SLA: ${t.sla_fecha||'No definido'}</div>
      </div>`).join('')}
    ${urgentes.length>3?`<div style="font-size:9px;color:#7A90A4;text-align:center">+${urgentes.length-3} más → Tab Tickets</div>`:''}
    <button onclick="showTab('TICKETS');document.getElementById('urgentes-popup').style.display='none'"
      style="width:100%;margin-top:8px;background:#B83232;color:#fff;border:none;border-radius:7px;padding:7px;font-size:10px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif">
      VER TODOS LOS URGENTES →
    </button>
  </div>`;
  pop.style.display='block';
}
// Show after 2 seconds and every 15 minutes
setTimeout(mostrarUrgentes, 2000);
setInterval(mostrarUrgentes, 900000);
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (Notification.permission !== "granted") {
        Notification.requestPermission();
    }
});
</script>

<script>
function enviarNotificacionPush(titulo, mensaje) {
    if (!("Notification" in window)) return;
    if (Notification.permission === "granted") {
        const opciones = {
            body: mensaje,
            icon: 'https://withisabelfuentes.com/favicon.png', // Cambia esto por tu logo
            tag: 'chat-msg',          // reemplaza la notificación anterior en lugar de apilar
            renotify: true,           // pero hace sonar/parpadear de nuevo
            requireInteraction: false
        };
        const n = new Notification(titulo, opciones);
        // Al hacer click: enfoca la ventana y abre el panel del chat
        n.onclick = function() {
            window.focus();
            const panel = document.getElementById('chat-panel');
            if (panel && panel.classList.contains('hidden')) {
                if (typeof toggleChat === 'function') toggleChat();
            }
            n.close();
        };
        // Auto-cerrar a los 8s para que no se quede pegada
        setTimeout(() => { try { n.close(); } catch(e){} }, 8000);
    } else if (Notification.permission !== "denied") {
        Notification.requestPermission();
    }
}
</script>

<script>
// 📨 AVISO AL HACER LOGIN — si hay mensajes sin leer, lo informamos al usuario
<?php if($chat_unread > 0): ?>
document.addEventListener('DOMContentLoaded', function(){
  const total = <?= (int)$chat_unread ?>;
  const txt = total === 1
    ? '💬 Tienes 1 mensaje sin leer en el chat'
    : '💬 Tienes ' + total + ' mensajes sin leer en el chat';
  // Toast normal del sistema (se ve en la esquina)
  setTimeout(() => { if (typeof toast === 'function') toast(txt); }, 1500);
  // Hacer "latir" el botón del chat para llamar la atención
  setTimeout(() => {
    const fab = document.querySelector('.chat-fab');
    if (fab){
      fab.style.animation = 'chatPulse 1.2s ease-in-out 4';
    }
  }, 1800);
  // Notificación de Windows también, por si la pestaña está en background
  setTimeout(() => {
    if (typeof enviarNotificacionPush === 'function' && document.hidden){
      enviarNotificacionPush('💬 Mensajes sin leer', txt);
    }
  }, 2000);
});
<?php endif; ?>
</script>

<style>
@keyframes chatPulse {
  0%,100% { transform: scale(1); box-shadow: 0 8px 24px rgba(27,74,107,.4); }
  50%     { transform: scale(1.15); box-shadow: 0 8px 32px rgba(239,68,68,.6); }
}
</style>


<!-- ══════════════════════════════════════════════
     GESTOR MASIVO DE TAREAS — Modal rediseñado
     Drag & drop para reordenar, sin campo numérico
     ══════════════════════════════════════════════ -->
<style>
/* ── Modal overlay ── */
#modal-gestor-tareas {
    display: none;
    position: fixed; top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(27,58,92,.55);
    backdrop-filter: blur(3px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}

/* ── Modal box ── */
#gestor-box {
    background: #fff;
    border: 1px solid <?=$CB?>;
    border-radius: 16px;
    width: 95%;
    max-width: 720px;
    max-height: 88vh;
    display: flex;
    flex-direction: column;
    position: relative;
    box-shadow: 0 20px 60px rgba(27,74,107,.2);
    overflow: hidden;
}

/* ── Header ── */
#gestor-box .gm-header {
    background: linear-gradient(135deg, <?=$P1?>, <?=$P2?>);
    padding: 18px 22px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}
#gestor-box .gm-header h3 {
    color: #fff;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    font-family: 'DM Sans', sans-serif;
    display: flex;
    align-items: center;
    gap: 8px;
}
#gestor-box .gm-header h3 span.gm-icon {
    width: 28px; height: 28px;
    background: rgba(255,255,255,.18);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
}
.gm-close {
    background: rgba(255,255,255,.15);
    border: 1px solid rgba(255,255,255,.25);
    color: #fff;
    width: 30px; height: 30px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.gm-close:hover { background: rgba(255,255,255,.28); }

/* ── Hint bar ── */
.gm-hint {
    background: <?=$BG?>;
    border-bottom: 1px solid <?=$CB?>;
    padding: 9px 22px;
    font-size: 10px;
    color: <?=$MU?>;
    letter-spacing: .5px;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

/* ── Scrollable list ── */
#gestor-lista-wrap {
    overflow-y: auto;
    flex: 1;
    padding: 14px 18px;
}

/* ── Individual task card ── */
.gm-task-card {
    background: #fff;
    border: 1.5px solid <?=$CB?>;
    border-radius: 12px;
    margin-bottom: 8px;
    display: flex;
    align-items: stretch;
    transition: box-shadow .15s, border-color .15s, transform .12s;
    cursor: default;
    position: relative;
}
.gm-task-card.dragging {
    opacity: .5;
    box-shadow: 0 8px 28px rgba(27,74,107,.25);
    transform: scale(1.01);
}
.gm-task-card.drag-over {
    border-color: <?=$P2?>;
    background: <?=$BG?>;
}

/* Handle */
.gm-handle {
    width: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: grab;
    color: <?=$CB?>;
    border-right: 1.5px solid <?=$CB?>;
    border-radius: 10px 0 0 10px;
    flex-shrink: 0;
    transition: color .15s, background .15s;
    font-size: 15px;
    user-select: none;
}
.gm-handle:hover { color: <?=$P2?>; background: <?=$BG?>; }
.gm-handle:active { cursor: grabbing; }

/* Badge número de orden */
.gm-num {
    position: absolute;
    top: -6px; left: -6px;
    width: 18px; height: 18px;
    background: <?=$P1?>;
    color: #fff;
    border-radius: 50%;
    font-size: 8px;
    font-weight: 900;
    display: flex; align-items: center; justify-content: center;
    font-family: 'DM Sans', sans-serif;
    pointer-events: none;
    box-shadow: 0 2px 6px rgba(27,74,107,.3);
}

/* Body del card */
.gm-card-body {
    flex: 1;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

/* Nombre de la tarea (Editable) */
.gm-task-name-input {
    font-size: 12px;
    font-weight: 800;
    color: #1B4A6B; /* $P1 */
    font-family: 'DM Sans', sans-serif;
    line-height: 1.3;
    border: 1px dashed transparent;
    background: transparent;
    width: 100%;
    padding: 2px 4px;
    border-radius: 4px;
    transition: all 0.2s;
    outline: none;
}
.gm-task-name-input:hover {
    border-color: #C8DFF0;
}
.gm-task-name-input:focus {
    border-color: #2876A8;
    background: #F4F8FC;
}

/* Frecuency row */
.gm-frec-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.gm-frec-label {
    font-size: 9px;
    font-weight: 700;
    color: <?=$MU?>;
    letter-spacing: 1px;
    text-transform: uppercase;
    flex-shrink: 0;
}

/* Frecuency pills toggle */
.gm-frec-pills {
    display: flex;
    gap: 4px;
}
.gm-frec-pill {
    background: <?=$BG?>;
    border: 1.5px solid <?=$CB?>;
    color: <?=$MU?>;
    border-radius: 20px;
    padding: 4px 11px;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .8px;
    text-transform: uppercase;
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    transition: all .13s;
}
.gm-frec-pill.active {
    background: <?=$P1?>;
    border-color: <?=$P1?>;
    color: #fff;
}
.gm-frec-pill:hover:not(.active) {
    border-color: <?=$P2?>;
    color: <?=$P2?>;
}

/* Días específicos */
.gm-dias-wrap {
    display: none;
    align-items: center;
    gap: 5px;
    flex-wrap: wrap;
    margin-top: 2px;
}
.gm-dia-btn {
    width: 30px; height: 30px;
    border-radius: 50%;
    border: 1.5px solid <?=$CB?>;
    background: #fff;
    color: <?=$MU?>;
    font-size: 9px;
    font-weight: 900;
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    transition: all .12s;
    display: flex; align-items: center; justify-content: center;
}
.gm-dia-btn.active {
    background: <?=$P1?>;
    border-color: <?=$P1?>;
    color: #fff;
    box-shadow: 0 2px 8px rgba(27,74,107,.25);
}
.gm-dia-btn:hover:not(.active) {
    border-color: <?=$P2?>;
    color: <?=$P2?>;
}

/* Mensual config */
.gm-mensual-wrap {
    display: none;
    align-items: center;
    gap: 8px;
    margin-top: 2px;
}
.gm-mensual-wrap label {
    font-size: 10px;
    color: <?=$MU?>;
}
.gm-dia-mes-input {
    width: 52px;
    border: 1.5px solid <?=$CB?>;
    border-radius: 8px;
    padding: 5px 8px;
    font-size: 12px;
    font-weight: 700;
    text-align: center;
    color: <?=$P1?>;
    font-family: 'DM Sans', sans-serif;
}
.gm-dia-mes-input:focus {
    outline: none;
    border-color: <?=$P2?>;
}

/* Footer */
.gm-footer {
    padding: 14px 18px;
    border-top: 1px solid <?=$CB?>;
    background: <?=$BG?>80;
    display: flex;
    gap: 10px;
    flex-shrink: 0;
}
.gm-btn-save {
    flex: 1;
    background: <?=$P1?>;
    color: #fff;
    border: none;
    border-radius: 10px;
    padding: 11px 20px;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    transition: background .15s, transform .1s;
}
.gm-btn-save:hover { background: <?=$P2?>; }
.gm-btn-save:active { transform: scale(.98); }
.gm-btn-cancel {
    background: #fff;
    color: <?=$MU?>;
    border: 1.5px solid <?=$CB?>;
    border-radius: 10px;
    padding: 11px 18px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1px;
    text-transform: uppercase;
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    transition: border-color .15s, color .15s;
}
.gm-btn-cancel:hover { border-color: <?=$P2?>; color: <?=$P2?>; }
</style>

<div id="modal-gestor-tareas">
    <div id="gestor-box">
        
        <div class="gm-header">
            <h3><span class="gm-icon">⚙️</span> GESTIONAR MIS TAREAS</h3>
            <button class="gm-close" onclick="cerrarGestorTareas()">✕</button>
        </div>
        
        <div class="gm-hint">
            💡 Arrastra las tareas desde el icono (⠿) para reordenarlas.
        </div>

        <div id="gestor-lista-wrap">
            <div id="gestor-lista">
                <?php
                // Obtener las tareas personalizadas del agente
                $tareas_guardadas = [];
                try {
                    $stmt_gt = $pdo->prepare("SELECT * FROM tareas_personalizadas WHERE agente_id = ? ORDER BY orden ASC, id ASC");
                    $stmt_gt->execute([$uid]);
                    $tareas_guardadas = $stmt_gt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) { /* tabla aún no existe */ }
                $dias_nombres = [1=>'L', 2=>'M', 3=>'X', 4=>'J', 5=>'V', 6=>'S', 0=>'D'];

                if (empty($tareas_guardadas)): ?>
                    <div style="text-align:center; padding: 20px; color: #7A90A4; font-size: 10px; text-transform:uppercase;">
                        No tienes tareas configuradas. Haz clic en "+ Añadir Tarea" abajo.
                    </div>
                <?php else:
                    foreach ($tareas_guardadas as $idx => $tm):
                        $tid = $tm['id'];
                        $frec = $tm['frecuencia'];
                        $dias_arr = explode(',', $tm['dias_semana'] ?? '');
                ?>
                <div class="gm-task-card" draggable="true" data-id="<?=$tid?>" data-orden="<?=$idx+1?>">
                    <span class="gm-num"><?=$idx+1?></span>
                    <div class="gm-handle" title="Arrastra para reordenar">⠿</div>
                    <div class="gm-card-body">
                        
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                            <input type="text" class="gm-task-name-input" value="<?=h($tm['item_texto'])?>">
                            <button type="button" onclick="gmEliminarTarea(this, '<?=$tid?>')" title="Eliminar Tarea" style="background:none; border:none; color:#B83232; cursor:pointer; font-size:14px; padding:0 4px; opacity:0.6; transition:opacity 0.2s;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.6">✕</button>
                        </div>

                        <div class="gm-frec-row">
                            <span class="gm-frec-label">Frecuencia:</span>
                            <div class="gm-frec-pills">
                                <button type="button"
                                    class="gm-frec-pill <?=$frec==='DIARIA'?'active':''?>"
                                    data-frec="DIARIA"
                                    onclick="gmSetFrec(this, '<?=$tid?>')">Diaria</button>
                                <button type="button"
                                    class="gm-frec-pill <?=$frec==='DIAS_ESPECIFICOS'?'active':''?>"
                                    data-frec="DIAS_ESPECIFICOS"
                                    onclick="gmSetFrec(this, '<?=$tid?>')">Días específicos</button>
                                <button type="button"
                                    class="gm-frec-pill <?=$frec==='MENSUAL'?'active':''?>"
                                    data-frec="MENSUAL"
                                    onclick="gmSetFrec(this, '<?=$tid?>')">Mensual</button>
                            </div>
                        </div>

                        <div class="gm-dias-wrap" id="dias_<?=$tid?>"
                             style="display:<?=$frec==='DIAS_ESPECIFICOS'?'flex':'none'?>;">
                            <?php foreach($dias_nombres as $num => $letra): ?>
                                <button type="button"
                                    class="gm-dia-btn <?=in_array((string)$num, $dias_arr, true)?'active':''?>"
                                    data-val="<?=$num?>"
                                    data-tid="<?=$tid?>"
                                    onclick="this.classList.toggle('active')"
                                    title="<?=['1'=>'Lunes','2'=>'Martes','3'=>'Miércoles','4'=>'Jueves','5'=>'Viernes','6'=>'Sábado','0'=>'Domingo'][$num]?>"
                                ><?=$letra?></button>
                            <?php endforeach; ?>
                        </div>

                        <div class="gm-mensual-wrap" id="mes_<?=$tid?>"
                             style="display:<?=$frec==='MENSUAL'?'flex':'none'?>;">
                            <label>Día del mes:</label>
                            <input type="number"
                                class="gm-dia-mes-input"
                                id="diames_<?=$tid?>"
                                value="<?=(int)($tm['dia_mes']??1)?>"
                                min="1" max="31">
                        </div>
                        
                    </div>
                </div>
                <?php 
                    endforeach; 
                endif; 
                ?>
            </div> </div>

        <div class="gm-footer">
            <button type="button" class="gm-btn-cancel" onclick="gmAgregarTarea()" style="color:#1B5E8C; border-color:#C8DFF0; background:#EBF5FB;">+ Añadir Tarea</button>
            <div style="flex:1"></div>
            <button type="button" class="gm-btn-cancel" onclick="cerrarGestorTareas()">Cancelar</button>
            <button type="button" class="gm-btn-save" style="flex:0; min-width:180px;" onclick="gmGuardar()">💾 Guardar cambios</button>
        </div>
        
        
        <!-- Form oculto que se envía -->
        <form id="form-gestor-tareas" method="POST" style="display:none;">
            <input type="hidden" name="guardar_gestor_tareas" value="1">
            <div id="form-gestor-inputs"></div>
        </form>
    </div>
</div>

<script>
// ── Abrir / cerrar modal ──────────────────────────────────
function abrirGestorTareas() {
    const m = document.getElementById('modal-gestor-tareas');
    m.style.display = 'flex';
    gmRenumber();
}
function cerrarGestorTareas() {
    document.getElementById('modal-gestor-tareas').style.display = 'none';
}
document.addEventListener('DOMContentLoaded', function() {
    const mgEl = document.getElementById('modal-gestor-tareas');
    if (mgEl) mgEl.addEventListener('click', function(e) {
        if (e.target === this) cerrarGestorTareas();
    });
});

// ── Cambiar frecuencia ────────────────────────────────────
function gmSetFrec(btn, tid) {
    // Desactivar pills hermanas
    btn.closest('.gm-frec-pills').querySelectorAll('.gm-frec-pill').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    const frec = btn.dataset.frec;
    document.getElementById('dias_' + tid).style.display = (frec === 'DIAS_ESPECIFICOS') ? 'flex' : 'none';
    document.getElementById('mes_'  + tid).style.display = (frec === 'MENSUAL')          ? 'flex' : 'none';
}

// ── Drag & drop nativo ────────────────────────────────────
try { (function() {
    const lista = document.getElementById('gestor-lista');
    if (!lista) return; // salir si el elemento no existe
    let dragged = null;

    lista.addEventListener('dragstart', function(e) {
        dragged = e.target.closest('.gm-task-card');
        if (!dragged) return;
        setTimeout(() => dragged.classList.add('dragging'), 0);
        e.dataTransfer.effectAllowed = 'move';
    });

    lista.addEventListener('dragend', function() {
        if (dragged) { dragged.classList.remove('dragging'); dragged = null; }
        document.querySelectorAll('.gm-task-card').forEach(c => c.classList.remove('drag-over'));
        gmRenumber();
    });

    lista.addEventListener('dragover', function(e) {
        e.preventDefault();
        const target = e.target.closest('.gm-task-card');
        if (!target || target === dragged) return;
        document.querySelectorAll('.gm-task-card').forEach(c => c.classList.remove('drag-over'));
        target.classList.add('drag-over');
        const rect = target.getBoundingClientRect();
        const after = (e.clientY - rect.top) > rect.height / 2;
        lista.insertBefore(dragged, after ? target.nextSibling : target);
    });

    lista.addEventListener('dragleave', function(e) {
        const t = e.target.closest('.gm-task-card');
        if (t) t.classList.remove('drag-over');
    });

    lista.addEventListener('drop', function(e) {
        e.preventDefault();
    });
})(); } catch(e) { console.warn('drag-drop init:', e); }

// ── Renumerar badges ──────────────────────────────────────
function gmRenumber() {
    document.querySelectorAll('#gestor-lista .gm-task-card').forEach((c, i) => {
        const num = c.querySelector('.gm-num');
        if (num) num.textContent = i + 1;
    });
}

// ── Agregar, Eliminar y Guardar ─────────────────────────
let newTidCounter = 1;

function gmAgregarTarea() {
    const tid = 'new_' + newTidCounter++;
    const lista = document.getElementById('gestor-lista');

    const html = `
        <div class="gm-task-card" draggable="true" data-id="${tid}" data-orden="999">
            <span class="gm-num">0</span>
            <div class="gm-handle" title="Arrastra para reordenar">⠿</div>
            <div class="gm-card-body">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                    <input type="text" class="gm-task-name-input" value="" placeholder="Escribe tu nueva tarea aquí...">
                    <button type="button" onclick="gmEliminarTarea(this, '${tid}')" style="background:none; border:none; color:#B83232; cursor:pointer;">✕</button>
                </div>
                <div class="gm-frec-row">
                    <span class="gm-frec-label">Frecuencia:</span>
                    <div class="gm-frec-pills">
                        <button type="button" class="gm-frec-pill active" data-frec="DIARIA" onclick="gmSetFrec(this, '${tid}')">Diaria</button>
                        <button type="button" class="gm-frec-pill" data-frec="DIAS_ESPECIFICOS" onclick="gmSetFrec(this, '${tid}')">Días específicos</button>
                        <button type="button" class="gm-frec-pill" data-frec="MENSUAL" onclick="gmSetFrec(this, '${tid}')">Mensual</button>
                    </div>
                </div>
                <div class="gm-dias-wrap" id="dias_${tid}" style="display:none;">
                    ${['L','M','X','J','V','S','D'].map((letra, i) => `
                        <button type="button" class="gm-dia-btn" data-val="${i==6?0:i+1}" onclick="this.classList.toggle('active')">${letra}</button>
                    `).join('')}
                </div>
                <div class="gm-mensual-wrap" id="mes_${tid}" style="display:none;">
                    <label>Día del mes:</label>
                    <input type="number" class="gm-dia-mes-input" id="diames_${tid}" value="1" min="1" max="31">
                </div>
            </div>
        </div>
    `;
    lista.insertAdjacentHTML('beforeend', html);
    gmRenumber();
}

function gmEliminarTarea(btn, tid) {
    if (!confirm('¿Seguro que deseas eliminar esta tarea?')) return;
    const card = btn.closest('.gm-task-card');
    card.style.opacity = '0';
    setTimeout(() => {
        card.remove();
        gmRenumber();
        // Si no es una tarea recién creada, la agregamos a la lista de borrado para PHP
        if (!tid.startsWith('new_')) {
            const container = document.getElementById('form-gestor-inputs');
            const i = document.createElement('input');
            i.type = 'hidden'; i.name = 'task_delete[]'; i.value = tid;
            container.appendChild(i);
        }
    }, 200);
}

function gmGuardar() {
    const container = document.getElementById('form-gestor-inputs');
    // NO limpiar container.innerHTML aquí para no borrar los inputs de 'task_delete[]'

    const cards = Array.from(document.querySelectorAll('#gestor-lista .gm-task-card'));
    cards.forEach((card, idx) => {
        const tid   = card.dataset.id;
        const orden = idx + 1;

        // Frecuencia activa
        const frecBtn = card.querySelector('.gm-frec-pill.active');
        const frec    = frecBtn ? frecBtn.dataset.frec : 'DIARIA';

        // Días activos
        const diasActivos = Array.from(card.querySelectorAll('.gm-dia-btn.active'))
                                 .map(b => b.dataset.val);

        // Día del mes
        const diaMesInput = card.querySelector('.gm-dia-mes-input');
        const diaMes      = diaMesInput ? diaMesInput.value : 1;
        
        // Texto de la tarea
        const textoInput  = card.querySelector('.gm-task-name-input');
        const textoVal    = textoInput ? textoInput.value : 'Nueva Tarea';

        const add = (n, v) => {
            const i = document.createElement('input');
            i.type = 'hidden'; i.name = n; i.value = v;
            container.appendChild(i);
        };

        add('task_id[]', tid);
        add('task_texto[' + tid + ']', textoVal); // ¡Enviamos el texto modificado!
        add('task_orden[' + tid + ']', orden);
        add('task_frec['  + tid + ']', frec);
        add('task_dia_mes[' + tid + ']', diaMes);

        if (frec === 'DIAS_ESPECIFICOS') {
            diasActivos.forEach(d => add('task_dias[' + tid + '][]', d));
            if (diasActivos.length === 0) add('task_dias[' + tid + '][]', '');
        }
    });

    document.getElementById('form-gestor-tareas').submit();
}

function completarPasoPipeline(pasoId, btn) {
    if(!confirm('¿Marcar este paso como completado?')) return;
    btn.disabled = true;
    btn.textContent = '...';
    const fd = new FormData();
    fd.append('action', 'completar_paso_pipeline');
    fd.append('id', pasoId);
    fetch('api.php', {method:'POST', body:fd})
    .then(r=>r.json())
    .then(d=>{
        if(d.ok) {
            const card = btn.closest('.pipe-card') || btn.closest('div[style]');
            if(card){ card.style.opacity = '0.4'; card.style.transform = 'scale(0.97)'; }
            toast('✓ PASO COMPLETADO');
            setTimeout(() => softReload(), 400);
        } else {
            btn.disabled = false; btn.textContent = '✓ LISTO';
            toast('⚠ ' + (d.error || 'Error'));
        }
    }).catch(()=>{ btn.disabled = false; btn.textContent = '✓ LISTO'; });
}

function savePipelineNote(mid, btn) {
    const input = document.getElementById('p-note-'+mid);
    const nota = input.value.trim();
    if(!nota){ input.focus(); return; }
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action', 'save_nota');
    fd.append('miembro_id', mid);
    fd.append('nota', nota);
    fetch('api.php', {method:'POST', body:fd})
    .then(r=>r.json())
    .then(d=>{
        if(d.ok) { input.value = ''; toast('✓ NOTA GUARDADA'); }
        else toast('⚠ ' + (d.error || 'Error'));
        btn.disabled = false;
    }).catch(()=>{ btn.disabled = false; });
}

function switchPipeView(view) {
    const main   = document.getElementById('pipe-view-principal');
    const act    = document.getElementById('pipe-view-actividades');
    const pills  = document.getElementById('pipe-temp-pills');
    const btnMain  = document.getElementById('btn-pipe-main');
    const btnToday = document.getElementById('btn-pipe-today');
    if(view === 'principal') {
        main.style.display  = 'block';
        act.style.display   = 'none';
        if(pills) pills.style.display = 'flex';
        btnMain.style.background  = '#1B4A6B'; btnMain.style.color = '#fff';
        btnToday.style.background = '#fff';    btnToday.style.color = '#7A90A4';
    } else {
        main.style.display  = 'none';
        act.style.display   = 'block';
        if(pills) pills.style.display = 'none';
        btnToday.style.background = '#1B4A6B'; btnToday.style.color = '#fff';
        btnMain.style.background  = '#fff';    btnMain.style.color = '#1B4A6B';
    }
}

function aplicarPasosConfig(miembroId) {
    if(!confirm('¿Aplicar la secuencia de pasos automáticos a este prospecto?')) return;
    const fd = new FormData();
    fd.append('action', 'aplicar_pasos_automaticos');
    fd.append('miembro_id', miembroId);
    fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if(d.ok) { toast('✓ PASOS APLICADOS'); setTimeout(()=>softReload(), 400); }
        else toast('⚠ ' + (d.msg || d.error || 'Sin pasos configurados'));
    });
}

function updatePipeConfig(id, campo, valor) {
    const fd = new FormData();
    fd.append('action', 'update_pipeline_config');
    fd.append('id', id);
    fd.append('campo', campo);
    fd.append('valor', valor);
    fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => { if(d.ok) toast('✓ GUARDADO'); else toast('⚠ ' + (d.error||'Error')); });
}

function agregarNuevaConfig() {
    const btn = event.currentTarget;
    btn.disabled = true; btn.textContent = 'AGREGANDO...';
    const fd = new FormData();
    fd.append('action', 'add_pipeline_config_row');
    fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if(d.ok) {
            // Agregar nueva fila dinámicamente sin reload
            const lista = document.getElementById('lista-pasos-config');
            const newId = d.id || Date.now();
            const row = document.createElement('div');
            row.id = 'row-config-' + newId;
            row.style.cssText = 'display:flex; gap:10px; margin-bottom:10px; align-items:center; background:#F4F8FC; border:1px solid #C8DFF0; border-radius:10px; padding:10px 12px;';
            row.innerHTML = `
                <div style="flex-shrink:0; text-align:center;">
                    <div style="font-size:7px; font-weight:900; color:#7A90A4; text-transform:uppercase; letter-spacing:1px; margin-bottom:3px;">DÍA</div>
                    <input type="number" value="1" min="0" max="365"
                        onchange="updatePipeConfig(${newId}, 'dias_intervalo', this.value)"
                        style="width:55px; font-size:11px; font-weight:900; padding:6px; border-radius:7px; border:1.5px solid #C8DFF0; text-align:center; font-family:'DM Sans',sans-serif; color:#1B4A6B; background:#fff;">
                </div>
                <div style="flex:1; min-width:0;">
                    <div style="font-size:7px; font-weight:900; color:#7A90A4; text-transform:uppercase; letter-spacing:1px; margin-bottom:3px;">ACCIÓN</div>
                    <input type="text" value="Nueva Tarea de Seguimiento" placeholder="Describe el paso..."
                        onchange="updatePipeConfig(${newId}, 'accion', this.value)"
                        style="width:100%; font-size:10px; padding:7px 9px; border-radius:7px; border:1.5px solid #C8DFF0; font-family:'DM Sans',sans-serif; color:#1B3A5C; background:#fff; outline:none;">
                </div>
                <button onclick="eliminarConfigPaso(${newId})"
                    style="background:#FDF0EE; border:1px solid #EFA09A; color:#B83232; padding:7px 10px; border-radius:7px; cursor:pointer; font-size:13px; flex-shrink:0; font-weight:900; font-family:'DM Sans',sans-serif;">✕</button>`;
            // Remove "no pasos" placeholder if present
            const placeholder = lista.querySelector('[style*="text-align:center"]');
            if(placeholder && placeholder.textContent.includes('No hay pasos')) placeholder.remove();
            lista.appendChild(row);
            row.querySelector('input[type=text]').focus();
            toast('✓ PASO AÑADIDO');
        } else {
            toast('⚠ ' + (d.error||'Error al añadir'));
        }
        btn.disabled = false; btn.textContent = '+ AÑADIR NUEVO PASO';
    }).catch(()=>{ btn.disabled = false; btn.textContent = '+ AÑADIR NUEVO PASO'; });
}

function eliminarConfigPaso(id) {
    if(!confirm('¿Eliminar este paso de la configuración?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_pipeline_config');
    fd.append('id', id);
    fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if(d.ok) {
            const row = document.getElementById('row-config-' + id);
            if(row){ row.style.opacity='0'; row.style.transform='scale(0.95)'; setTimeout(()=>row.remove(), 200); }
            toast('✓ PASO ELIMINADO');
        } else toast('⚠ ' + (d.error||'Error'));
    });
}

function openPipeConfigModal() {
    const m = document.getElementById('modal-pipe-config');
    if (m) { m.style.display = 'flex'; m.style.alignItems = 'flex-start'; m.style.justifyContent = 'center'; }
}

// Filtro de temperatura en prospectos
function setPipeTemp(btn, temp) {
    document.querySelectorAll('.pipe-temp-pill').forEach(b => {
        b.style.background = '#fff';
        b.style.fontWeight = '900';
        b.classList.remove('pipe-temp-pill-on');
    });
    btn.style.background = '#EBF4F9';
    btn.classList.add('pipe-temp-pill-on');
    filterPipeProspects(temp);
}

function filterPipeProspects(temp) {
    if (temp === undefined) {
        const activeBtn = document.querySelector('.pipe-temp-pill-on');
        temp = activeBtn ? activeBtn.dataset.temp : '';
    }
    const agente = document.getElementById('pipe-agente-filter')?.value || '';
    const search  = (document.getElementById('pipe-search')?.value || '').toLowerCase().trim();

    const counts = { pros:0, cita:0, app:0, sold:0 };

    document.querySelectorAll('.pipe-col').forEach(col => {
        const colKey = col.dataset.col;
        let colVisible = 0;

        col.querySelectorAll('.pipe-card').forEach(card => {
            const cardTemp   = card.dataset.temp   || '';
            const cardAgente = card.dataset.agente || '';
            const cardNombre = card.dataset.nombre || '';

            const tempOk   = colKey !== 'pros' || !temp   || cardTemp   === temp;
            const agenteOk = !agente || cardAgente === agente;
            const searchOk = !search || cardNombre.includes(search);

            const show = tempOk && agenteOk && searchOk;
            card.style.display = show ? '' : 'none';
            if (show) colVisible++;
        });

        // Actualizar contador de columna
        const header = col.querySelector('.pipe-col-header span:last-child');
        if (header) header.textContent = colVisible;
        if (counts[colKey] !== undefined) counts[colKey] = colVisible;
    });

    // Actualizar stats bar
    const total = counts.pros + counts.cita + counts.app + counts.sold;
    const conv  = total > 0 ? Math.round(counts.sold * 100 / total) : 0;
    const upd = (id, val) => { const el = document.getElementById(id); if(el) el.textContent = val; };
    upd('pipe-stat-pros', counts.pros);
    upd('pipe-stat-cita', counts.cita);
    upd('pipe-stat-app',  counts.app);
    upd('pipe-stat-sold', counts.sold);
    upd('pipe-stat-conv', conv + '%');

    // Filtrar actividades también
    document.querySelectorAll('.pipe-actividad-card').forEach(card => {
        const cardAgente = card.dataset.agente || '';
        card.style.display = (!agente || cardAgente === agente) ? '' : 'none';
    });
}

function setProsTemp(mid, temp) {
    const fd = new FormData();
    fd.append('action', 'set_prospect_temp');
    fd.append('id', mid);
    fd.append('temp', temp);
    fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if(d.ok) { toast('✓ TEMPERATURA ACTUALIZADA'); setTimeout(()=>softReload(), 400); }
        else toast('⚠ ' + (d.error||'Error'));
    });
}

// ── ES VENTA → MANDAR A BONOS (agente o admin, solo si está ACTIVE) ──
function verificarVentaBono(mid, nombre, btn) {
    if(!confirm('¿Confirmas que esta venta ya está ACTIVA y quieres mandarla a BONOS?\n\n'+nombre)) return;
    if(btn){ btn.disabled = true; btn.textContent = 'ENVIANDO...'; }
    const fd = new FormData();
    fd.append('action', 'verificar_venta_bono');
    fd.append('miembro_id', mid);
    fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if(d.ok) {
            toast('✓ VENTA ENVIADA A BONOS');
            if(btn){
                const wrap = btn.parentNode;
                wrap.innerHTML = '<div style="width:100%;background:#EAF5F0;border:1px solid #8DCFBA;color:#1E7A5C;font-size:8px;padding:6px;border-radius:7px;font-weight:900;text-align:center;text-transform:uppercase;letter-spacing:.5px;">✓ ENVIADO A BONOS</div>';
            }
        } else {
            toast('⚠ ' + (d.error||'Error'));
            if(btn){ btn.disabled = false; btn.textContent = '💰 ES VENTA → MANDAR A BONOS'; }
        }
    })
    .catch(()=>{ toast('⚠ Error de conexión'); if(btn){ btn.disabled=false; btn.textContent='💰 ES VENTA → MANDAR A BONOS'; } });
}

// ── MODAL REGISTRO RÁPIDO DE LLAMADAS ──
// ──────────────────────────────────────────────────────────────
// MEMBER PICKER — searchable autocomplete replacing <select>
// ──────────────────────────────────────────────────────────────
const _membersData = <?= json_encode(array_map(fn($m) => [
  'id'     => (int)$m['id'],
  'label'  => h($m['apellido'].', '.$m['nombre']) . (!empty($m['telefono']) ? ' · '.h($m['telefono']) : ''),
  'nombre' => h($m['nombre'].' '.$m['apellido']),
  'tel'    => h($m['telefono'] ?? ''),
  'search' => strtolower($m['apellido'].' '.$m['nombre'].' '.($m['telefono']??''))
], $members)) ?>;


// ── DATOS COMPLETOS PARA PRE-RELLENAR EL CUESTIONARIO ─────────────────────
const _membersFullData = <?= json_encode(array_map(fn($m) => [
  'id'                  => (int)($m['id'] ?? 0),
  'nombre'              => $m['nombre']              ?? '',
  'apellido'            => $m['apellido']             ?? '',
  'dob'                 => $m['dob']                  ?? '',
  'telefono'            => $m['telefono']              ?? '',
  'email'               => $m['email']                ?? '',
  'idioma'              => $m['idioma']               ?? 'ESP',
  'carrier'             => $m['carrier']              ?? '',
  'plan'                => $m['plan']                 ?? '',
  'pcp'                 => $m['pcp']                  ?? '',
  'pcp_group'           => $m['pcp_group']            ?? '',
  'condiciones_cronicas'=> $m['condiciones_cronicas'] ?? '',
  'prescripciones'      => $m['prescripciones']       ?? '',
  'medical'             => $m['medical']              ?? 'NO',
  'medical_nivel'       => $m['medical_nivel']        ?? '',
  'mbi'                 => $m['mbi']                  ?? '',
  'parte_a'             => $m['parte_a']              ?? '',
  'parte_b'             => $m['parte_b']              ?? '',
  'elegibilidad'        => $m['elegibilidad']         ?? '',
  'referido_por'        => $m['referido_por']         ?? '',
  'direccion_calle'     => $m['direccion_calle']      ?? '',
  'direccion_apto'      => $m['direccion_apto']       ?? '',
  'ciudad'              => $m['ciudad']               ?? '',
  'zip'                 => $m['zip']                  ?? '',
  'county'              => $m['county']               ?? 'LOS ANGELES',
  'extras'              => $m['extras']               ?? '',
  'fecha_efectiva'      => $m['fecha_efectiva']       ?? '',
], $members)) ?>;


// Stored per-search context: maps dropId → { inputId, hiddenId, fillName }
const _mpickCtx = {};

function mpickSearch(inputId, hiddenId, dropId, query, fillName) {
    _mpickCtx[dropId] = { inputId, hiddenId, fillName };
    const drop = document.getElementById(dropId);
    const q = query.toLowerCase().trim();
    if (!q || q.length < 2) { drop.style.display = 'none'; return; }
    const results = _membersData.filter(m => m.search.includes(q)).slice(0, 10);
    if (!results.length) { drop.innerHTML = '<div class="mpick-item" style="color:#7A90A4;cursor:default">Sin resultados</div>'; drop.style.display = 'block'; return; }
    // Use data-* attributes to avoid any escaping issues in inline handlers
    drop.innerHTML = results.map(m => {
        const safeLabel  = m.label .replace(/&/g,'&amp;').replace(/"/g,'&quot;');
        const safeNombre = m.nombre.replace(/&/g,'&amp;').replace(/"/g,'&quot;');
        const safeTel    = m.tel   .replace(/&/g,'&amp;').replace(/"/g,'&quot;');
        return `<div class="mpick-item"
                     data-id="${m.id}"
                     data-label="${safeLabel}"
                     data-nombre="${safeNombre}"
                     data-tel="${safeTel}"
                     data-drop="${dropId}"
                     onmousedown="mpickItemClick(event,this)">
                  ${safeLabel}
                </div>`;
    }).join('');
    drop.style.display = 'block';
}

function mpickItemClick(e, el) {
    e.preventDefault(); // prevent blur hiding dropdown before selection
    const dropId   = el.dataset.drop;
    const ctx      = _mpickCtx[dropId] || {};
    const inputId  = ctx.inputId;
    const hiddenId = ctx.hiddenId;
    const fillName = ctx.fillName;
    const id       = el.dataset.id;
    const label    = el.dataset.label;
    const nombre   = el.dataset.nombre;
    const tel      = el.dataset.tel;

    if (inputId)  document.getElementById(inputId).value  = label;
    if (hiddenId) document.getElementById(hiddenId).value = id;
    document.getElementById(dropId).style.display = 'none';

    // Auto-fill name/phone for llamada prospecto
    if (fillName && inputId === 'lr-mpick-input') {
        const ni = document.getElementById('lr-nombre');
        const ti = document.getElementById('lr-telefono');
        if (ni && !ni.value) ni.value = nombre;
        if (ti && !ti.value) ti.value = tel;
    }
}

function mpickClear(inputId, hiddenId, dropId) {
    const inp = document.getElementById(inputId);
    const hid = document.getElementById(hiddenId);
    const drp = document.getElementById(dropId);
    if (inp) inp.value = '';
    if (hid) hid.value = '';
    if (drp) drp.style.display = 'none';
    if (inputId === 'lr-mpick-input') {
        const ni = document.getElementById('lr-nombre');
        const ti = document.getElementById('lr-telefono');
        if (ni) ni.value = '';
        if (ti) ti.value = '';
    }
}

// Hide dropdowns when clicking outside any picker
document.addEventListener('click', (e) => {
    if (!e.target.closest('.mpick-wrap')) {
        document.querySelectorAll('.mpick-drop').forEach(d => d.style.display = 'none');
    }
});

// ──────────────────────────────────────────────────────────────
// LLAMADA RÁPIDA MODAL
// ──────────────────────────────────────────────────────────────
function openLlamadaRapidaModal() {
    document.getElementById('llamada-rapida-form').reset();
    // Reset submit button state (puede quedar disabled por error anterior)
    const sbtn = document.getElementById('lr-submit-btn');
    if (sbtn) { sbtn.disabled = false; sbtn.textContent = 'GUARDAR ➜'; }
    // Clear member pickers
    ['lr-mpick-input','lr-sv-mpick-input'].forEach(id => { const el = document.getElementById(id); if(el) el.value = ''; });
    ['lr-miembro','lr-sv-miembro'].forEach(id => { const el = document.getElementById(id); if(el) el.value = ''; });
    ['lr-mpick-drop','lr-sv-mpick-drop'].forEach(id => { const el = document.getElementById(id); if(el) el.style.display='none'; });
    setLrMode('prospecto');
    document.querySelectorAll('.lr-grid-btn').forEach(b => b.classList.remove('active'));
    const def = document.querySelector('.lr-grid-btn[data-res="Contestó"]');
    if (def) def.classList.add('active');
    document.getElementById('lr-resultado').value = 'Contestó';
    openModal('llamada-rapida-modal');
}

function setLrMode(mode) {
    document.getElementById('lr-tipo-llamada').value = mode;
    document.querySelectorAll('.lr-mode-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.mode === mode);
    });
    const isProsp = (mode === 'prospecto');
    document.getElementById('lr-prospecto-section').style.display = isProsp ? '' : 'none';
    document.getElementById('lr-servicio-section').style.display  = isProsp ? 'none' : '';
    // Servicio needs description required
    const notas_sv = document.getElementById('lr-notas-sv');
    if (notas_sv) notas_sv.required = !isProsp;
}

function setLrResult(btn) {
    document.querySelectorAll('.lr-grid-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('lr-resultado').value = btn.dataset.res;
}

function submitLlamadaRapida(e) {
    e.preventDefault();
    const mode = document.getElementById('lr-tipo-llamada').value;
    const btn  = document.getElementById('lr-submit-btn');
    btn.disabled = true;
    btn.textContent = 'REGISTRANDO...';

    let fd;
    if (mode === 'prospecto') {
        fd = new FormData(e.target);
        fd.append('action', 'save_llamada_prospecto');
    } else {
        // Servicio: crear SOLO un ticket tipo LLAMADA.
        // FormData LIMPIO: no arrastramos campos del modo prospecto
        // (sobre todo 'resultado=Contestó', que marcaba la llamada como contestada).
        const miembroSv = document.getElementById('lr-sv-miembro').value;
        const nombreSv  = document.getElementById('lr-nombre-sv').value;
        const notasSv   = document.getElementById('lr-notas-sv').value;
        const estadoSv  = e.target.querySelector('[name="estado_ticket"]')?.value || 'CERRADO';
        fd = new FormData();
        fd.append('action', 'save_ticket');
        fd.append('tipo', 'LLAMADA');
        fd.append('fuente', 'CRM');
        fd.append('prioridad', 'MEDIA');
        fd.append('estado', estadoSv);
        fd.append('descripcion', notasSv);
        if (miembroSv) fd.append('miembro_id', miembroSv);
        else fd.append('cliente', nombreSv);
    }

    fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            toast('✓ LLAMADA REGISTRADA');
            closeModal('llamada-rapida-modal');
            saveTabAndReload();
        } else {
            toast('⚠ ' + (d.error || 'Error'));
            btn.disabled = false;
            btn.textContent = 'GUARDAR ➜';
        }
    }).catch(() => {
        toast('⚠ ERROR DE RED');
        btn.disabled = false;
        btn.textContent = 'GUARDAR ➜';
    });
}

 
function filterPortalTab() {
    const carrier = document.getElementById('pf-carrier').value.toUpperCase().trim();
    const estado  = document.getElementById('pf-estado').value.trim();

    const CANCELADOS = ['CANCELED','DENIED','CERRADO','DISENROLLED'];
    let cActivos = 0, cCancelados = 0, cPendientes = 0, cTotal = 0;

    document.querySelectorAll('.portal-tab-row').forEach(function(row) {
        const rowCarrier = (row.dataset.carrier || '').toUpperCase().trim();
        const rowEstado  = (row.dataset.estado  || '').trim();

        const okCarrier = !carrier || rowCarrier === carrier;
        const okEstado  = !estado  || rowEstado  === estado;

        if (okCarrier && okEstado) {
            row.style.display = '';
            cTotal++;
            if (rowEstado === 'ACTIVE')            cActivos++;
            else if (CANCELADOS.includes(rowEstado)) cCancelados++;
            else if (rowEstado === 'IN PROCESS')   cPendientes++;
        } else {
            row.style.display = 'none';
        }
    });

    // Actualizar los 4 contadores del encabezado
    const el = id => document.getElementById(id);
    if (el('p-cnt-activos'))    el('p-cnt-activos').textContent    = cActivos;
    if (el('p-cnt-cancelados')) el('p-cnt-cancelados').textContent = cCancelados;
    if (el('p-cnt-pendientes')) el('p-cnt-pendientes').textContent = cPendientes;
    if (el('p-cnt-total'))      el('p-cnt-total').textContent      = cTotal;
}

function filterActividades(tipo) {
    // Estilos activo/inactivo
    const btns = {
        'vencidas': document.getElementById('act-btn-venc'),
        'hoy':      document.getElementById('act-btn-hoy'),
        'todas':    document.getElementById('act-btn-todas')
    };
    Object.entries(btns).forEach(([k, el]) => {
        if (!el) return;
        el.style.opacity  = k === tipo ? '1'    : '0.45';
        el.style.transform = k === tipo ? 'scale(1.05)' : '';
    });

    document.querySelectorAll('.pipe-actividad-card').forEach(card => {
        const esVencida = card.dataset.vencida === '1';
        if (tipo === 'todas')    card.style.display = '';
        else if (tipo === 'vencidas') card.style.display = esVencida  ? '' : 'none';
        else if (tipo === 'hoy')      card.style.display = !esVencida ? '' : 'none';
    });
}

</script>

<!-- ══ MODAL: CUESTIONARIO DE APLICACIÓN ══════════════════════════════════ -->
<div class="modal-overlay" id="app-modal">
  <div class="modal" style="max-width:800px">
 
    <div class="modal-header">
      <div>
        <div class="modal-title" id="app-modal-title">📋 CUESTIONARIO DE APLICACIÓN</div>
        <div style="font-size:8px;color:#7A90A4;margin-top:3px;text-transform:uppercase;letter-spacing:1px">
          Al guardar → estado: <strong style="color:#5B3FAF">READY TO ENROLL</strong> · Cita → <strong style="color:#1E7A5C">COMPLETADA</strong>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('app-modal')">✕</button>
    </div>
 
    <form id="app-form" onsubmit="submitAppForm(event)">
      <input type="hidden" id="app-cita-id">
      <input type="hidden" id="app-mid" name="id">
      <input type="hidden" name="action" value="save_member">
      <input type="hidden" name="estado"  value="READY TO ENROLL">
 
      <div style="max-height:68vh;overflow-y:auto;padding:18px 22px">
 
        <!-- ── DATOS PERSONALES ─────────────────────────────────── -->
        <div style="font-size:9px;font-weight:900;color:#1B4A6B;letter-spacing:2px;text-transform:uppercase;
                    padding:7px 12px;background:#EBF4F9;border-radius:9px;border-left:4px solid #1B4A6B;margin-bottom:12px">
          👤 DATOS PERSONALES
        </div>
 
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">NOMBRE *</label>
            <input type="text" name="nombre" id="app-nombre" class="form-input" required>
          </div>
          <div class="form-group">
            <label class="form-label">APELLIDO *</label>
            <input type="text" name="apellido" id="app-apellido" class="form-input" required>
          </div>
        </div>
 
        <div class="grid-3">
          <div class="form-group">
            <label class="form-label">FECHA DE NACIMIENTO</label>
            <input type="date" name="dob" id="app-dob" class="form-input">
          </div>
          <div class="form-group">
            <label class="form-label">TELÉFONO</label>
            <input type="text" name="telefono" id="app-tel" class="form-input" placeholder="(818) 555-0000">
          </div>
          <div class="form-group">
            <label class="form-label">EMAIL</label>
            <input type="email" name="email" id="app-email" class="form-input"
                   style="text-transform:none" placeholder="correo@ejemplo.com">
          </div>
        </div>
 
        <!-- Idioma toggle -->
        <div class="form-group">
          <label class="form-label">IDIOMA</label>
          <div style="display:flex;gap:7px;margin-top:3px">
            <button type="button" class="app-lang-btn" data-lang="ESP"
                    onclick="_setAppLang('ESP')"
                    style="flex:1;padding:9px;border-radius:9px;border:1.5px solid #1B4A6B;
                           background:#1B4A6B;color:#fff;font-size:10px;font-weight:900;
                           cursor:pointer;font-family:'DM Sans',sans-serif">
              🇲🇽 ESPAÑOL
            </button>
            <button type="button" class="app-lang-btn" data-lang="ENG"
                    onclick="_setAppLang('ENG')"
                    style="flex:1;padding:9px;border-radius:9px;border:1.5px solid #C8DFF0;
                           background:#fff;color:#7A90A4;font-size:10px;font-weight:900;
                           cursor:pointer;font-family:'DM Sans',sans-serif">
              🇺🇸 INGLÉS
            </button>
          </div>
          <input type="hidden" name="idioma" id="app-idioma" value="ESP">
        </div>
 
        <!-- ── DIRECCIÓN ──────────────────────────────────────────── -->
        <div style="font-size:9px;font-weight:900;color:#1B4A6B;letter-spacing:2px;text-transform:uppercase;
                    padding:7px 12px;background:#EBF4F9;border-radius:9px;border-left:4px solid #1B4A6B;
                    margin:16px 0 12px">
          📍 DIRECCIÓN
        </div>
 
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">CALLE</label>
            <input type="text" name="direccion_calle" id="app-calle" class="form-input"
                   autocomplete="off" placeholder="123 Main St">
          </div>
          <div class="form-group">
            <label class="form-label">APT / SUITE</label>
            <input type="text" name="direccion_apto" id="app-apto" class="form-input" autocomplete="off">
          </div>
        </div>
 
        <div class="grid-3">
          <div class="form-group">
            <label class="form-label">CIUDAD</label>
            <input type="text" name="ciudad" id="app-ciudad" class="form-input" autocomplete="off">
          </div>
          <div class="form-group">
            <label class="form-label">COUNTY</label>
            <select name="county" id="app-county" class="form-input">
              <?php foreach (['LOS ANGELES','ORANGE','SAN BERNARDINO','RIVERSIDE','VENTURA','SAN DIEGO','OTRO'] as $o): ?>
              <option><?= $o ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">ZIP</label>
            <input type="text" name="zip" id="app-zip" class="form-input"
                   autocomplete="off" placeholder="90001">
          </div>
        </div>
 
        <!-- ── MEDICARE & MÉDICO ──────────────────────────────────── -->
        <div style="font-size:9px;font-weight:900;color:#1B4A6B;letter-spacing:2px;text-transform:uppercase;
                    padding:7px 12px;background:#EBF4F9;border-radius:9px;border-left:4px solid #1B4A6B;
                    margin:16px 0 12px">
          🏥 MEDICARE & MÉDICO
        </div>
 
        <div class="grid-3">
          <div class="form-group">
            <label class="form-label">MBI</label>
            <input type="text" name="mbi" id="app-mbi" class="form-input"
                   placeholder="1EG4-TE5-MK72">
          </div>
          <div class="form-group">
            <label class="form-label">PARTE A</label>
            <input type="date" name="parte_a" id="app-parte-a" class="form-input">
          </div>
          <div class="form-group">
            <label class="form-label">PARTE B</label>
            <input type="date" name="parte_b" id="app-parte-b" class="form-input">
          </div>
        </div>
 
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">ELEGIBILIDAD</label>
            <select name="elegibilidad" id="app-elegibilidad" class="form-input">
              <option value="">—</option>
              <?php foreach (['MEDICARE A+B','SOLO PART A','SOLO PART B','DUAL','LIS/EXTRA HELP','PACE','OTRO'] as $o): ?>
              <option><?= $o ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">MEDI-CAL</label>
            <select name="medical" id="app-medical" class="form-input"
                    onchange="_toggleMedical()">
              <option value="NO">NO</option>
              <option value="SÍ">SÍ</option>
            </select>
          </div>
        </div>
 
        <div class="form-group" id="app-medical-nivel-wrap" style="display:none">
          <label class="form-label">NIVEL MEDI-CAL</label>
          <input type="text" name="medical_nivel" id="app-medical-nivel"
                 class="form-input" placeholder="1, 2…">
        </div>
 
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">DOCTOR PCP</label>
            <input type="text" name="pcp" id="app-pcp" class="form-input"
                   placeholder="Dr. Ramírez">
          </div>
          <div class="form-group">
            <label class="form-label">GRUPO MÉDICO</label>
            <input type="text" name="pcp_group" id="app-pcp-group"
                   class="form-input" placeholder="AltaMed, ApolloMed, SCAN IPA…">
          </div>
        </div>
 
        <!-- ── PLAN A APLICAR ─────────────────────────────────────── -->
        <div style="font-size:9px;font-weight:900;color:#5B3FAF;letter-spacing:2px;text-transform:uppercase;
                    padding:7px 12px;background:#F3F0FB;border-radius:9px;border-left:4px solid #5B3FAF;
                    margin:16px 0 12px">
          📋 PLAN A APLICAR
        </div>
 
        <div class="grid-3">
          <div class="form-group">
            <label class="form-label">CARRIER *</label>
            <select name="carrier" id="app-carrier" class="form-input" required>
              <option value="">— SELECCIONAR —</option>
              <?php foreach (['SCAN','ANTHEM','HUMANA','ALIGNMENT','LA CARE','HEALTH NET','MOLINA','UNITED HEALTHCARE','BLUE SHIELD','KAISER','OTRO'] as $o): ?>
              <option><?= $o ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">PLAN</label>
            <input type="text" name="plan" id="app-plan" class="form-input"
                   placeholder="Nombre del plan">
          </div>
          <div class="form-group">
            <label class="form-label">FECHA EFECTIVA</label>
            <input type="date" name="fecha_efectiva" id="app-fecha-efe" class="form-input">
          </div>
        </div>
 
        <!-- SEP -->
        <div class="form-group">
          <label class="form-label">
            SEP — RAZÓN DE PERÍODO ESPECIAL
            <span style="font-weight:400;color:#7A90A4;font-size:7px"> (se guardará en Extras)</span>
          </label>
          <input type="text" id="app-sep" class="form-input"
                 style="text-transform:none"
                 placeholder="Ej: Pérdida de cobertura laboral · cambio de residencia · T65 · mudanza…">
        </div>
 
        <!-- ── SALUD ───────────────────────────────────────────────── -->
        <div style="font-size:9px;font-weight:900;color:#1E7A5C;letter-spacing:2px;text-transform:uppercase;
                    padding:7px 12px;background:#EAF5F0;border-radius:9px;border-left:4px solid #1E7A5C;
                    margin:16px 0 12px">
          💊 SALUD
        </div>
 
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">ENFERMEDADES CRÓNICAS</label>
            <textarea name="condiciones_cronicas" id="app-cronicas" class="form-input"
                      rows="3" style="text-transform:none"
                      placeholder="Diabetes tipo 2&#10;Hipertensión&#10;Insuficiencia renal…"></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">MEDICAMENTOS</label>
            <textarea name="prescripciones" id="app-medicamentos" class="form-input"
                      rows="3" style="text-transform:none"
                      placeholder="Metformina 500mg&#10;Lisinopril 10mg&#10;Atorvastatina 20mg…"></textarea>
          </div>
        </div>
 
        <!-- ── REFERIDO ────────────────────────────────────────────── -->
        <div style="font-size:9px;font-weight:900;color:#7B2D8B;letter-spacing:2px;text-transform:uppercase;
                    padding:7px 12px;background:#F3EEF8;border-radius:9px;border-left:4px solid #7B2D8B;
                    margin:16px 0 12px">
          🤝 REFERIDO
        </div>
 
        <div class="form-group">
          <label class="form-label">¿VINO REFERIDO POR ALGUIEN?</label>
          <div style="display:flex;gap:7px;margin-top:4px">
            <button type="button" class="app-ref-btn" data-val="no"
                    onclick="_setAppRef('no')"
                    style="flex:1;padding:9px;border-radius:9px;border:1.5px solid #1B4A6B;
                           background:#1B4A6B;color:#fff;font-size:10px;font-weight:900;
                           cursor:pointer;font-family:'DM Sans',sans-serif">
              NO
            </button>
            <button type="button" class="app-ref-btn" data-val="si"
                    onclick="_setAppRef('si')"
                    style="flex:1;padding:9px;border-radius:9px;border:1.5px solid #C8DFF0;
                           background:#fff;color:#7A90A4;font-size:10px;font-weight:900;
                           cursor:pointer;font-family:'DM Sans',sans-serif">
              SÍ
            </button>
          </div>
        </div>
 
        <div id="app-ref-wrap" style="display:none">
          <div class="form-group">
            <label class="form-label">CUENTA / REFERENTE</label>
            <select name="referido_por" id="app-referido-por" class="form-input">
              <option value="">— SELECCIONAR CUENTA —</option>
              <?php foreach ($cuentas_list as $cu): ?>
              <option value="<?= (int)$cu['id'] ?>">
                <?= h($cu['nombre']) ?><?= $cu['es_referente'] ? ' ⭐' : '' ?>
                <?= $cu['tipo'] ? ' — ' . h($cu['tipo']) : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
 
      </div><!-- /scroll -->
 
      <!-- Footer con botones -->
      <div style="display:flex;gap:9px;padding:14px 20px;border-top:1px solid #C8DFF0;
                  background:#F8FAFC;border-radius:0 0 17px 17px">
        <button type="button" class="btn btn-gh"
                onclick="closeModal('app-modal')">
          CANCELAR
        </button>
        <div style="flex:1"></div>
        <button type="submit" class="btn btn-p" id="app-submit-btn"
                style="background:#5B3FAF;padding-left:28px;padding-right:28px;font-size:10px">
          ✓ GUARDAR Y MARCAR READY TO ENROLL
        </button>
      </div>
 
    </form>
  </div>
</div>
<!-- ══ FIN MODAL CUESTIONARIO DE APLICACIÓN ══════════════════════════════ -->


<!-- ══ MODAL: CAMBIO DE PLAN (desde tabla Miembros) ══ -->
<div id="modal-cambio-plan" class="modal-overlay" style="z-index:1200">
  <div class="modal modal-sm">
    <div class="modal-header">
      <div class="modal-title">🔄 CAMBIO DE PLAN</div>
      <button class="modal-close" onclick="closeModal('modal-cambio-plan')">✕</button>
    </div>
    <div id="cp-info-actual" style="background:#EBF4F9;border:1px solid #C8DFF0;border-radius:9px;padding:10px 13px;margin-bottom:14px;font-size:9px;color:#1B4A6B"></div>
    <input type="hidden" id="cp-miembro-id">
    <div class="grid-2" style="margin-bottom:12px">
      <div class="form-group">
        <label class="form-label">NUEVO CARRIER</label>
        <select id="cp-carrier" class="form-input">
          <option value="">— MISMO —</option>
          <?php foreach(['SCAN','ANTHEM','HUMANA','ALIGNMENT','LA CARE','HEALTH NET','MOLINA','UNITED HEALTHCARE','BLUE SHIELD','KAISER','OTRO'] as $c):?>
          <option value="<?=$c?>"><?=$c?></option>
          <?php endforeach;?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">NUEVO PLAN</label>
        <input id="cp-plan" type="text" class="form-input" placeholder="Nombre del nuevo plan">
      </div>
    </div>
    <div class="form-group" style="margin-bottom:14px">
      <label class="form-label">NUEVA FECHA EFECTIVA</label>
      <input id="cp-fecha" type="date" class="form-input">
    </div>
    <div style="background:#FEF8EE;border:1px solid #F5D5A0;border-radius:8px;padding:8px 12px;margin-bottom:14px;font-size:8px;color:#C07A1A">
      ⚠ El miembro pasará a <b>READY TO ENROLL — RE-SIGNED</b> y deberá completar el proceso hasta ser ACTIVE de nuevo.
    </div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-gh" style="flex:1" onclick="closeModal('modal-cambio-plan')">CANCELAR</button>
      <button class="btn btn-gr" style="flex:2" id="cp-btn-confirm" onclick="confirmarCambioPlan()">🔄 CONFIRMAR CAMBIO</button>
    </div>
  </div>
</div>

<script>
function abrirCambioPlan(mid, carrier, plan, fecha, nombre) {
    document.getElementById('cp-miembro-id').value = mid;
    document.getElementById('cp-carrier').value    = carrier || '';
    document.getElementById('cp-plan').value       = plan    || '';
    document.getElementById('cp-fecha').value      = '';
    document.getElementById('cp-info-actual').innerHTML =
        '<b>' + nombre + '</b><br>' +
        'Plan actual: <b>' + (carrier ? carrier + ' — ' : '') + (plan || '—') + '</b><br>' +
        'Efectivo: ' + (fecha ? fecha.substring(0,7) : '—');
    openModal('modal-cambio-plan');
}
function confirmarCambioPlan() {
    const btn = document.getElementById('cp-btn-confirm');
    btn.disabled = true; btn.textContent = 'GUARDANDO...';
    const fd = new FormData();
    fd.append('action',        'iniciar_cambio_plan');
    fd.append('miembro_id',    document.getElementById('cp-miembro-id').value);
    fd.append('nuevo_carrier', document.getElementById('cp-carrier').value);
    fd.append('nuevo_plan',    document.getElementById('cp-plan').value);
    fd.append('nueva_fecha',   document.getElementById('cp-fecha').value);
    fetch('api.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(d=>{
            if(d.ok){
                toast('✓ CAMBIO DE PLAN INICIADO — Estado: READY TO ENROLL');
                closeModal('modal-cambio-plan');
                saveTabAndReload();
            } else {
                toast('⚠ '+(d.error||'Error'));
                btn.disabled=false; btn.textContent='🔄 CONFIRMAR CAMBIO';
            }
        });
}
</script>
</body>
</html>
<!-- CONTACTOS JS - injected before end -->
<script>
// ─── CUENTAS + REFERIDOS JS ───────────────────────────────────────────────────
let cueCurrentId = null, cueCurrentNombre = '';

function showCueSubTab(tab) {
    ['CUENTAS','REFERIDOS','GASTOS'].forEach(t => { const el=document.getElementById('cue-sub-'+t); if(el) el.style.display=t===tab?'':'none'; });
    document.querySelectorAll('[data-cuetab]').forEach(b => b.classList.toggle('active', b.dataset.cuetab===tab));
    if(tab==='GASTOS') filterGastosVis();
}
function filterGastosVis() {
    const q=(document.getElementById('gv-search')?.value||'').toLowerCase();
    const mes=document.getElementById('gv-mes')?.value||'';
    const agente=document.getElementById('gv-agente')?.value||'';
    let total=0, count=0;
    document.querySelectorAll('.gv-row').forEach(row => {
        const okQ=!q||(row.dataset.search||'').includes(q);
        const okM=!mes||(row.dataset.fecha||'')=== mes;
        const okA=!agente||(row.dataset.agente||'')===agente;
        const show=okQ&&okM&&okA;
        row.style.display=show?'':'none';
        if(show){
            const monto=parseFloat(row.querySelector('td:last-child')?.textContent?.replace(/[$,]/g,'')||0);
            total+=monto; count++;
        }
    });
    const sumEl=document.getElementById('gv-sum');
    if(sumEl) sumEl.textContent='$'+total.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    const lblEl=document.getElementById('gv-total-label');
    if(lblEl) lblEl.textContent=count>0?count+' REGISTRO'+(count!==1?'S':''):'';
}
function filterCuentas() {
    const q=(document.getElementById('cue-search')?.value||'').toLowerCase();
    const tp=(document.getElementById('cue-tipo-f')?.value||'').toUpperCase();
    const st=document.getElementById('cue-estado-f')?.value||'';
    document.querySelectorAll('.cue-row').forEach(row => {
        const okQ=!q||(row.dataset.search||'').includes(q);
        const okTp=!tp||(row.dataset.tipo||'').toUpperCase()===tp;
        const okSt=!st||(st==='vencido'?row.dataset.venc==='1':row.dataset.venc==='0');
        row.style.display=okQ&&okTp&&okSt?'':'none';
    });
}
function filterRefs(estadoClick=null) {
    if(estadoClick){ const sel=document.getElementById('ref-estado-f'); if(sel) sel.value=sel.value===estadoClick?'':estadoClick; }
    const q=(document.getElementById('ref-search-input')?.value||'').toLowerCase();
    const est=document.getElementById('ref-estado-f')?.value||'';
    const cue=document.getElementById('ref-cuenta-f')?.value||'';
    document.querySelectorAll('.ref-card').forEach(card => {
        const okQ=!q||(card.dataset.nombre||'').includes(q);
        const okEst=!est||(card.dataset.estado||'')===est;
        const okCue=!cue||(card.dataset.cuenta||'')===cue;
        card.style.display=okQ&&okEst&&okCue?'':'none';
    });
}

// ── Modal Cuenta ──────────────────────────────────────────────────
function openCueModal(cid=null) {
    document.getElementById('cue-edit-id').value=cid||'';
    document.getElementById('cue-form-title').textContent=cid?'EDITAR CUENTA':'NUEVA CUENTA';
    ['cue-nombre','cue-tel','cue-email','cue-dir','cue-ciudad','cue-notas'].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=''; });
    document.getElementById('cue-tipo').value='DENTISTA'; document.getElementById('cue-es-ref').value='0'; document.getElementById('cue-dias').value='30';
    if(cid){ const fd=new FormData(); fd.append('cue_ajax','1'); fd.append('action','get_cuenta'); fd.append('cid',cid);
        fetch('index.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(!d.ok)return; const c=d.cuenta;
            document.getElementById('cue-nombre').value=c.nombre||''; document.getElementById('cue-tipo').value=c.tipo||'OTRO';
            document.getElementById('cue-tel').value=c.telefono||''; document.getElementById('cue-email').value=c.email||'';
            document.getElementById('cue-dir').value=c.direccion||''; document.getElementById('cue-ciudad').value=c.ciudad||'';
            document.getElementById('cue-notas').value=c.notas||''; document.getElementById('cue-es-ref').value=c.es_referente||'0';
            document.getElementById('cue-dias').value=c.dias_recordatorio||'30';
        }); }
    openModal('modal-cue-form');
}
function saveCuenta() {
    const btn=document.getElementById('cue-form-btn'); const cid=document.getElementById('cue-edit-id').value;
    const nombre=document.getElementById('cue-nombre').value.trim(); if(!nombre){toast('⚠ NOMBRE REQUERIDO');return;}
    btn.disabled=true; btn.textContent='GUARDANDO...';
    const fd=new FormData(); fd.append('cue_ajax','1'); fd.append('action','save_cuenta'); fd.append('cid',cid);
    [['cue-nombre','nombre'],['cue-tipo','tipo'],['cue-tel','telefono'],['cue-email','email'],['cue-dir','direccion'],['cue-ciudad','ciudad'],['cue-notas','notas'],['cue-dias','dias_recordatorio']].forEach(([elId,key])=>{ const el=document.getElementById(elId); if(el) fd.append(key,el.value); });
    fd.append('es_referente',document.getElementById('cue-es-ref').value);
    fetch('index.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.ok){toast('✓ CUENTA GUARDADA');closeModal('modal-cue-form');saveTabAndReload();}else{toast('⚠ '+(d.error||'Error'));btn.disabled=false;btn.textContent='GUARDAR ➜';} }).catch(()=>{btn.disabled=false;btn.textContent='GUARDAR ➜';});
}

// ── Modal Contacto de Cuenta ──────────────────────────────────────
function openCtcCuentaModal(cid, cNombre, ctid=null) {
    document.getElementById('ctcuenta-cuenta-id').value=cid; document.getElementById('ctcuenta-edit-id').value=ctid||'';
    document.getElementById('ctcuenta-form-title').textContent=ctid?'EDITAR CONTACTO':'NUEVO CONTACTO';
    document.getElementById('ctcuenta-form-cuenta').textContent=cNombre;
    ['ctcuenta-nombre','ctcuenta-cargo','ctcuenta-tel','ctcuenta-email','ctcuenta-notas'].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=''; });
    document.getElementById('ctcuenta-principal').value='0';
    closeModal('modal-cue-detalle');
    openModal('modal-ctcuenta-form');
}
function saveCtcCuenta() {
    const btn=document.getElementById('ctcuenta-form-btn'); const cid=document.getElementById('ctcuenta-cuenta-id').value; const ctid=document.getElementById('ctcuenta-edit-id').value;
    const nombre=document.getElementById('ctcuenta-nombre').value.trim(); if(!nombre){toast('⚠ NOMBRE REQUERIDO');return;}
    btn.disabled=true; btn.textContent='GUARDANDO...';
    const fd=new FormData(); fd.append('cue_ajax','1'); fd.append('action','save_contacto'); fd.append('cid',cid); fd.append('ctid',ctid);
    [['ctcuenta-nombre','nombre'],['ctcuenta-cargo','cargo'],['ctcuenta-tel','telefono'],['ctcuenta-email','email'],['ctcuenta-notas','notas']].forEach(([elId,key])=>{ const el=document.getElementById(elId); if(el) fd.append(key,el.value); });
    fd.append('es_principal',document.getElementById('ctcuenta-principal').value);
    fetch('index.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.ok){toast('✓ CONTACTO GUARDADO');closeModal('modal-ctcuenta-form');if(cueCurrentId)openCueDetalle(cueCurrentId,'CONTACTOS');}else{toast('⚠ '+(d.error||'Error'));btn.disabled=false;btn.textContent='GUARDAR ➜';} }).catch(()=>{btn.disabled=false;btn.textContent='GUARDAR ➜';});
}

// ── Modal Interacción ─────────────────────────────────────────────
function openInterModal(cid, nombre='') {
    cueCurrentId=cid; document.getElementById('inter-cue-id').value=cid; document.getElementById('inter-nombre').textContent=nombre;
    document.getElementById('inter-fecha').value=new Date().toISOString().split('T')[0];
    ['inter-desc','inter-gasto-desc'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
    document.getElementById('inter-gasto').value=''; document.getElementById('inter-tipo').value='LLAMADA'; document.getElementById('inter-resultado').value='CONTESTÓ';
    const gdw=document.getElementById('gasto-desc-wrap'); if(gdw) gdw.style.display='none';
    document.querySelectorAll('.inter-tipo-btn').forEach(b=>{b.style.background='';b.style.color='';b.style.borderColor='';if(b.dataset.tipo==='LLAMADA'){b.style.background='#EBF5FB';b.style.color='#1B5E8C';b.style.borderColor='#A9D0E8';}});
    document.querySelectorAll('.inter-res-btn').forEach(b=>{b.style.background='';b.style.color='';b.style.borderColor='';if(b.dataset.res==='CONTESTÓ'){b.style.background='#EAF5F0';b.style.color='#1E7A5C';b.style.borderColor='#8DCFBA';}});
    const sel=document.getElementById('inter-contacto'); if(sel){sel.innerHTML='<option value="">— CON QUIÉN HABLÉ (OPCIONAL) —</option>';if(cid){const fd2=new FormData();fd2.append('cue_ajax','1');fd2.append('action','get_contactos_cuenta');fd2.append('cid',cid);fetch('index.php',{method:'POST',body:fd2}).then(r=>r.json()).then(d2=>{if(d2.ok)d2.contactos.forEach(c=>{const opt=document.createElement('option');opt.value=c.id;opt.textContent=c.nombre+(c.cargo?' — '+c.cargo:'');sel.appendChild(opt);});});}}
    closeModal('modal-cue-detalle');
    openModal('modal-inter-form');
}
function setInterTipo(btn){document.querySelectorAll('.inter-tipo-btn').forEach(b=>{b.style.background='';b.style.color='';b.style.borderColor='';});btn.style.background='#EBF5FB';btn.style.color='#1B5E8C';btn.style.borderColor='#A9D0E8';document.getElementById('inter-tipo').value=btn.dataset.tipo;}
function setInterRes(btn){document.querySelectorAll('.inter-res-btn').forEach(b=>{b.style.background='';b.style.color='';b.style.borderColor='';});btn.style.background='#EAF5F0';btn.style.color='#1E7A5C';btn.style.borderColor='#8DCFBA';document.getElementById('inter-resultado').value=btn.dataset.res;}
function toggleGastoDesc(input){const w=document.getElementById('gasto-desc-wrap');if(w)w.style.display=parseFloat(input.value)>0?'':'none';}
function saveInter() {
    const btn=document.getElementById('inter-form-btn'); const cid=document.getElementById('inter-cue-id').value;
    btn.disabled=true; btn.textContent='GUARDANDO...';
    const fd=new FormData(); fd.append('cue_ajax','1'); fd.append('action','save_interaccion'); fd.append('cid',cid);
    fd.append('ctid',document.getElementById('inter-contacto').value); fd.append('tipo',document.getElementById('inter-tipo').value);
    fd.append('fecha',document.getElementById('inter-fecha').value); fd.append('resultado',document.getElementById('inter-resultado').value);
    fd.append('descripcion',document.getElementById('inter-desc').value); fd.append('gasto_monto',document.getElementById('inter-gasto').value||'0');
    fd.append('gasto_descripcion',document.getElementById('inter-gasto-desc').value);
    fetch('index.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.ok){toast('✓ INTERACCIÓN GUARDADA');closeModal('modal-inter-form');if(cueCurrentId&&document.getElementById('modal-cue-detalle').classList.contains('open'))openCueDetalle(cueCurrentId,'HISTORIAL');else saveTabAndReload();}else{toast('⚠ '+(d.error||'Error'));btn.disabled=false;btn.textContent='GUARDAR ➜';} }).catch(()=>{btn.disabled=false;btn.textContent='GUARDAR ➜';});
}

// ── Modal Referido ────────────────────────────────────────────────
function openRefModal(rid=null, cuentaId=null) {
    document.getElementById('ref-edit-id').value=rid||''; document.getElementById('ref-form-title').textContent=rid?'EDITAR REFERIDO':'NUEVO REFERIDO';
    ['ref-nombre','ref-apellido','ref-tel','ref-notas'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
    document.getElementById('ref-dob').value=''; document.getElementById('ref-idioma').value='ESP';
    const ags=document.getElementById('ref-agente'); if(ags) ags.value='<?=$uid?>';
    const cueSel=document.getElementById('ref-cuenta'); if(cueSel) cueSel.value=cuentaId||'';
    const ctcSel=document.getElementById('ref-contacto'); if(ctcSel){ctcSel.innerHTML='<option value="">— SELECCIONAR —</option>'; if(cuentaId) loadContactosCuenta(cuentaId);}
    closeModal('modal-cue-detalle');
    openModal('modal-ref-form');
}
function loadContactosCuenta(cid) {
    const sel=document.getElementById('ref-contacto'); if(!sel||!cid){if(sel)sel.innerHTML='<option value="">— SELECCIONAR —</option>';return;}
    const fd=new FormData(); fd.append('cue_ajax','1'); fd.append('action','get_contactos_cuenta'); fd.append('cid',cid);
    fetch('index.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ sel.innerHTML='<option value="">— SELECCIONAR —</option>'; if(d.ok) d.contactos.forEach(c=>{const opt=document.createElement('option');opt.value=c.id;opt.textContent=c.nombre+(c.cargo?' — '+c.cargo:'');sel.appendChild(opt);}); });
}
function saveRef() {
    const btn=document.getElementById('ref-form-btn'); const rid=document.getElementById('ref-edit-id').value;
    const nombre=document.getElementById('ref-nombre').value.trim(); if(!nombre){toast('⚠ NOMBRE REQUERIDO');return;}
    btn.disabled=true; btn.textContent='GUARDANDO...';
    const fd=new FormData(); fd.append('cue_ajax','1'); fd.append('action','save_referido'); fd.append('rid',rid);
    [['ref-nombre','nombre'],['ref-apellido','apellido'],['ref-tel','telefono'],['ref-dob','dob'],['ref-idioma','idioma'],['ref-notas','notas'],['ref-cuenta','cuenta_id'],['ref-contacto','contacto_id'],['ref-agente','agente_id']].forEach(([elId,key])=>{const el=document.getElementById(elId);if(el)fd.append(key,el.value);});
    fetch('index.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.ok){toast('✓ REFERIDO GUARDADO');closeModal('modal-ref-form');if(cueCurrentId&&document.getElementById('modal-cue-detalle').classList.contains('open'))openCueDetalle(cueCurrentId,'REFERIDOS');else saveTabAndReload();}else{toast('⚠ '+(d.error||'Error'));btn.disabled=false;btn.textContent='GUARDAR ➜';} }).catch(()=>{btn.disabled=false;btn.textContent='GUARDAR ➜';});
}
function updateEstadoRef(rid, estado) {
    const fd=new FormData(); fd.append('cue_ajax','1'); fd.append('action','update_estado_referido'); fd.append('rid',rid); fd.append('estado',estado);
    fetch('index.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.ok){toast('✓ ESTADO ACTUALIZADO');saveTabAndReload();}else toast('⚠ '+(d.error||'Error')); });
}
function convertirRef(rid) {
    if(!confirm('¿Mover este referido al pipeline como PROSPECTO?\n\nSe creará en Miembros con estado PROSPECT.')) return;
    const fd=new FormData(); fd.append('cue_ajax','1'); fd.append('action','convertir_referido'); fd.append('rid',rid);
    fetch('index.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.ok){toast('✓ PROSPECTO CREADO');setTimeout(()=>openProfile(d.miembro_id),800);saveTabAndReload();}else toast('⚠ '+(d.error||'Error')); });
}
function deleteRef(rid) {
    if(!confirm('¿Eliminar este referido?')) return;
    const fd=new FormData(); fd.append('cue_ajax','1'); fd.append('action','delete_referido'); fd.append('rid',rid);
    fetch('index.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.ok){toast('✓ ELIMINADO');saveTabAndReload();}else toast('⚠ '+(d.error||'Error')); });
}

// ── Modal Detalle de Cuenta ───────────────────────────────────────
function openCueDetalle(cid, tabInicial='INFO') {
    cueCurrentId=cid;
    document.getElementById('cue-det-titulo').textContent='CARGANDO...'; document.getElementById('cue-det-sub').textContent='';
    openModal('modal-cue-detalle'); showCueDetTab(tabInicial);
    const fd=new FormData(); fd.append('cue_ajax','1'); fd.append('action','get_cuenta'); fd.append('cid',cid);
    fetch('index.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(!d.ok){toast('⚠ ERROR');return;}
        const c=d.cuenta; cueCurrentNombre=c.nombre;
        document.getElementById('cue-det-titulo').textContent=c.nombre;
        document.getElementById('cue-det-sub').textContent=[c.tipo,c.ciudad].filter(Boolean).join(' — ');
        document.getElementById('cue-det-inter-btn').onclick=()=>openInterModal(cid,c.nombre);
        document.getElementById('cue-det-inter-btn2').onclick=()=>openInterModal(cid,c.nombre);
        document.getElementById('cue-det-edit-btn').onclick=()=>{closeModal('modal-cue-detalle');openCueModal(cid);};
        document.getElementById('cue-det-addctc-btn').onclick=()=>openCtcCuentaModal(cid,c.nombre);
        document.getElementById('cue-det-addref-btn').onclick=()=>{closeModal('modal-cue-detalle');openRefModal(null,cid);};
        // INFO
        const info=[['TIPO',c.tipo||'—'],['TELÉFONO',c.telefono||'—'],['EMAIL',c.email||'—'],['DIRECCIÓN',c.direccion||'—'],['CIUDAD',c.ciudad||'—'],['RECORDATORIO','CADA '+(c.dias_recordatorio||30)+' DÍAS'],['ES REFERENTE',c.es_referente=='1'?'✓ SÍ':'NO'],['AGENTE',c.agente_nombre||'—']];
        document.getElementById('cue-det-info-grid').innerHTML=info.map(([l,v])=>`<div style="background:#EBF4F9;border:1px solid #C8DFF0;border-radius:9px;padding:9px 13px"><div style="font-size:7px;font-weight:900;color:#7A90A4;text-transform:uppercase;letter-spacing:1px;margin-bottom:2px">${l}</div><div style="font-size:10px;font-weight:800;color:#1B3A5C;word-break:break-word">${v}</div></div>`).join('');
        const nb=document.getElementById('cue-det-notas-box'); if(c.notas){nb.style.display='';document.getElementById('cue-det-notas-txt').textContent=c.notas;}else nb.style.display='none';
        const gastos=d.interacciones.filter(i=>parseFloat(i.gasto_monto)>0); const gBox=document.getElementById('cue-det-gastos-box');
        if(gastos.length){gBox.style.display='';const total=gastos.reduce((s,i)=>s+parseFloat(i.gasto_monto),0);document.getElementById('cue-det-gastos-content').innerHTML=`<div style="display:flex;gap:10px;flex-wrap:wrap"><div style="background:#FEF8EE;border:1px solid #F5D5A0;border-radius:9px;padding:10px 16px;text-align:center;min-width:120px"><div style="font-size:22px;font-weight:900;color:#C07A1A">$${total.toFixed(2)}</div><div style="font-size:8px;color:#C07A1A;text-transform:uppercase;font-weight:900">TOTAL INVERTIDO</div><div style="font-size:8px;color:#C07A1A">${gastos.length} VISITA${gastos.length>1?'S':''}</div></div><div style="flex:1;min-width:200px">${gastos.slice(0,5).map(g=>`<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #F5D5A0;font-size:9px"><span>${g.fecha} — ${g.gasto_descripcion||'Gasto'}</span><span style="font-weight:900;color:#C07A1A">$${parseFloat(g.gasto_monto).toFixed(2)}</span></div>`).join('')}</div></div>`;}else gBox.style.display='none';
        // CONTACTOS
        const ctcList=document.getElementById('cue-det-ctc-list');
        if(!d.contactos.length){ctcList.innerHTML='<div style="padding:20px;text-align:center;font-size:9px;color:#7A90A4;text-transform:uppercase">SIN CONTACTOS — USA "+ CONTACTO" PARA AGREGAR</div>';}
        else{ctcList.innerHTML=d.contactos.map(ct=>`<div style="background:#fff;border:1px solid #C8DFF0;border-radius:10px;padding:12px 15px;display:flex;gap:10px;align-items:center"><div style="width:36px;height:36px;border-radius:50%;background:${ct.es_principal=='1'?'#1B4A6B':'#EBF4F9'};display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:900;color:${ct.es_principal=='1'?'#fff':'#1B4A6B'};flex-shrink:0">${ct.nombre.charAt(0)}</div><div style="flex:1;min-width:0"><div style="font-weight:900;font-size:10px;color:#1B4A6B">${ct.nombre}${ct.es_principal=='1'?' <span style="font-size:7px;background:#EAF5F0;color:#1E7A5C;border:1px solid #8DCFBA;border-radius:20px;padding:1px 7px;font-weight:900">PRINCIPAL</span>':''}</div><div style="font-size:8px;color:#7A90A4;margin-top:2px">${ct.cargo||'—'}${ct.telefono?' · 📞 '+ct.telefono:''}${ct.email?' · '+ct.email:''}</div>${ct.notas?`<div style="font-size:8px;color:#7A90A4;font-style:italic;margin-top:2px">${ct.notas}</div>`:''}</div><button onclick="deleteCtcCuenta(${ct.id})" style="background:none;border:1px solid #EFA09A;color:#B83232;border-radius:7px;padding:3px 8px;font-size:9px;cursor:pointer;font-weight:900">✕</button></div>`).join('');}
        // HISTORIAL
        const tipoIcon={VISITA:'🏢',LLAMADA:'📞',EMAIL:'📧','REUNIÓN':'🤝',OTRO:'◌'};
        const hList=document.getElementById('cue-det-hist-list');
        if(!d.interacciones.length){hList.innerHTML='<div style="padding:24px;text-align:center;font-size:9px;color:#7A90A4;text-transform:uppercase">SIN INTERACCIONES REGISTRADAS AÚN</div>';}
        else{hList.innerHTML=d.interacciones.map(i=>`<div style="background:#fff;border:1px solid #C8DFF0;border-radius:10px;padding:11px 14px"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:6px"><div style="display:flex;gap:8px;align-items:center"><span style="font-size:15px">${tipoIcon[i.tipo]||'◌'}</span><div><div style="font-weight:900;font-size:9px;color:#1B4A6B;text-transform:uppercase">${i.tipo}${i.contacto_nombre?' CON '+i.contacto_nombre:''}</div><div style="font-size:8px;color:#7A90A4">${i.fecha} · ${i.agente_nombre||'—'}</div></div></div><div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap">${i.resultado?`<span style="font-size:8px;font-weight:900;color:#1E7A5C">${i.resultado}</span>`:''}${parseFloat(i.gasto_monto)>0?`<span style="background:#FEF8EE;color:#C07A1A;border:1px solid #F5D5A0;border-radius:20px;padding:1px 8px;font-size:8px;font-weight:900">💰 $${parseFloat(i.gasto_monto).toFixed(2)}${i.gasto_descripcion?' — '+i.gasto_descripcion:''}</span>`:''}<button onclick="deleteInter(${i.id})" style="background:none;border:1px solid #EFA09A;color:#B83232;border-radius:7px;padding:2px 7px;font-size:9px;cursor:pointer;font-weight:900">✕</button></div></div>${i.descripcion?`<div style="font-size:9px;color:#1B3A5C;line-height:1.6;white-space:pre-wrap">${i.descripcion}</div>`:''}</div>`).join('');}
        // REFERIDOS
        const rcol={'NUEVO':['#EBF4F9','#1B4A6B'],'INTENTANDO':['#FEF8EE','#C07A1A'],'CONTACTADO':['#F3F0FB','#5B3FAF'],'INTERESADO':['#EAF5F0','#1E7A5C'],'EN PIPELINE':['#EBF5FB','#1B5E8C'],'NO INTERESADO':['#F5F5F5','#7A90A4']};
        const rList=document.getElementById('cue-det-ref-list');
        if(!d.referidos.length){rList.innerHTML='<div style="padding:24px;text-align:center;font-size:9px;color:#7A90A4;text-transform:uppercase">SIN REFERIDOS DE ESTA CUENTA AÚN</div>';}
        else{rList.innerHTML=d.referidos.map(r=>{const rc=rcol[r.estado]||['#F5F5F5','#7A90A4'];return`<div style="background:#fff;border:1px solid #C8DFF0;border-left:4px solid ${rc[1]};border-radius:10px;padding:11px 14px;display:flex;gap:10px;align-items:center"><div style="flex:1"><div style="font-weight:900;font-size:10px;color:#1B4A6B">${r.nombre} ${r.apellido||''}</div><div style="font-size:8px;color:#7A90A4;margin-top:2px">${r.telefono||'—'}${r.contacto_nombre?' · '+r.contacto_nombre:''} · ${r.agente_nombre||''}</div>${r.notas?`<div style="font-size:8px;color:#7A90A4;font-style:italic;margin-top:2px">${r.notas.substring(0,80)}</div>`:''}</div><span style="background:${rc[0]};color:${rc[1]};border-radius:20px;padding:2px 10px;font-size:8px;font-weight:900;white-space:nowrap">${r.estado}</span>${r.miembro_id?`<button onclick="openProfile(${r.miembro_id})" class="btn btn-bl btn-sm" style="font-size:8px">◉ PERFIL</button>`:''}</div>`;}).join('');}
        // MIEMBROS
        const mList=document.getElementById('cue-det-mie-list');
        if(!d.miembros.length){mList.innerHTML='<div style="padding:24px;text-align:center;font-size:9px;color:#7A90A4;text-transform:uppercase">SIN MIEMBROS REGISTRADOS DE ESTA CUENTA</div>';}
        else{mList.innerHTML=d.miembros.map(m=>{const esEnt=(m.tipo_referido||'ENTRANTE')==='ENTRANTE';const bColor=esEnt?'#1E7A5C':'#1B5E8C';const bBg=esEnt?'#EAF5F0':'#EBF4F9';const bLabel=esEnt?'📥 NOS LO ENVIARON':'📤 LO ENVIAMOS';const showBadge=m.tipo_referido!=null&&m.tipo_referido!='';return`<div style="background:#fff;border:1px solid #C8DFF0;border-left:4px solid ${showBadge?bColor:'#C8DFF0'};border-radius:9px;padding:9px 12px;display:flex;gap:9px;align-items:center"><div style="flex:1;min-width:0"><div style="font-weight:900;font-size:10px;color:#1B4A6B;cursor:pointer" onclick="openProfile(${m.id})">${m.apellido}, ${m.nombre}</div><div style="font-size:8px;color:#7A90A4">${m.estado}${m.carrier?' · '+m.carrier:''}${m.fecha_efectiva?' · '+m.fecha_efectiva:''}</div>${showBadge?`<span style="display:inline-block;margin-top:4px;background:${bBg};color:${bColor};border-radius:20px;padding:1px 8px;font-size:7px;font-weight:900">${bLabel}</span>`:''}</div><button class="btn btn-b btn-sm" onclick="openProfile(${m.id})">◉</button></div>`;}).join('');}
    });
}
function showCueDetTab(tab) {
    ['INFO','CONTACTOS','HISTORIAL','REFERIDOS','MIEMBROS'].forEach(t=>{const el=document.getElementById('cue-det-'+t);if(el)el.style.display=t===tab?'':'none';});
    document.querySelectorAll('[data-cue-tab]').forEach(b=>b.classList.toggle('active',b.dataset.cueTab===tab));
}
function deleteCtcCuenta(ctid) {
    if(!confirm('¿Eliminar este contacto?'))return;
    const fd=new FormData();fd.append('cue_ajax','1');fd.append('action','delete_contacto');fd.append('ctid',ctid);
    fetch('index.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.ok){toast('✓ ELIMINADO');if(cueCurrentId)openCueDetalle(cueCurrentId,'CONTACTOS');}else toast('⚠ '+(d.error||'Error'));});
}
function deleteInter(iid) {
    if(!confirm('¿Eliminar esta interacción?'))return;
    const fd=new FormData();fd.append('cue_ajax','1');fd.append('action','delete_interaccion');fd.append('iid',iid);
    fetch('index.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.ok){toast('✓ ELIMINADO');if(cueCurrentId)openCueDetalle(cueCurrentId,'HISTORIAL');}else toast('⚠ '+(d.error||'Error'));});
}

// ── Reporte imprimible ────────────────────────────────────────────
function printCueReport() {
    if(!cueCurrentId)return;
    const fd=new FormData();fd.append('cue_ajax','1');fd.append('action','get_cuenta');fd.append('cid',cueCurrentId);
    fetch('index.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(!d.ok)return;
        const c=d.cuenta, ints=d.interacciones, ctcs=d.contactos, refs=d.referidos, mies=d.miembros;
        const totalGasto = ints.reduce((s,i)=>s+parseFloat(i.gasto_monto||0),0);

        // Split members by direction
        const mieIn  = mies.filter(m=>(m.tipo_referido||'ENTRANTE')==='ENTRANTE');
        const mieOut = mies.filter(m=>m.tipo_referido==='SALIENTE');

        // Split referrals by direction (pending only)
        const refPend    = refs.filter(r=>r.estado!=='EN PIPELINE');
        const refPendIn  = refPend.filter(r=>(r.tipo_referido||'ENTRANTE')==='ENTRANTE');
        const refPendOut = refPend.filter(r=>r.tipo_referido==='SALIENTE');

        const today = new Date().toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});

        const memberRow = (m,i) => `<tr style="${i%2?'background:#F8FBFD':''}">
          <td style="width:28px;color:#7A90A4">${i+1}</td>
          <td><b>${m.apellido}, ${m.nombre}</b></td>
          <td><span style="background:${m.estado==='ACTIVE'?'#EAF5F0':'#EBF4F9'};color:${m.estado==='ACTIVE'?'#1E7A5C':'#1B4A6B'};border-radius:20px;padding:1px 8px;font-size:9px;font-weight:bold">${m.estado}</span></td>
          <td>${m.carrier||'—'}</td>
          <td>${m.fecha_efectiva||'—'}</td>
        </tr>`;

        const refRow = (r,i) => `<tr style="${i%2?'background:#F8FBFD':''}">
          <td><b>${r.nombre} ${r.apellido||''}</b></td>
          <td>${r.telefono||'—'}</td>
          <td>${r.estado}</td>
          <td>${r.contacto_nombre||'—'}</td>
        </tr>`;

        const memberTable = (list, title, color, icon) => !list.length ? '' : `
          <h2 style="color:${color};border-color:${color}40">${icon} ${title}</h2>
          <table><tr>
            <th style="background:${color}15;color:${color}">#</th>
            <th style="background:${color}15;color:${color}">Name</th>
            <th style="background:${color}15;color:${color}">Status</th>
            <th style="background:${color}15;color:${color}">Carrier</th>
            <th style="background:${color}15;color:${color}">Effective Date</th>
          </tr>${list.map(memberRow).join('')}</table>`;

        const refTable = (list, title, color, icon) => !list.length ? '' : `
          <h2 style="color:${color};border-color:${color}40">${icon} ${title}</h2>
          <table><tr>
            <th style="background:${color}15;color:${color}">Name</th>
            <th style="background:${color}15;color:${color}">Phone</th>
            <th style="background:${color}15;color:${color}">Status</th>
            <th style="background:${color}15;color:${color}">Referred By</th>
          </tr>${list.map(refRow).join('')}</table>`;

        const win=window.open('','_blank');
        win.document.write(`<!DOCTYPE html><html><head>
        <title>Account Report — ${c.nombre}</title>
        <style>
          body{font-family:Arial,sans-serif;padding:32px;color:#1B3A5C;font-size:11px;max-width:900px;margin:0 auto}
          h1{color:#1B4A6B;font-size:22px;margin-bottom:3px}
          h2{font-size:11px;margin:20px 0 7px;border-bottom:2px solid #C8DFF0;padding-bottom:5px;
             text-transform:uppercase;letter-spacing:1.5px}
          table{width:100%;border-collapse:collapse;margin-bottom:14px}
          th{font-size:9px;text-transform:uppercase;padding:6px 10px;text-align:left;border:1px solid #C8DFF0;font-weight:900}
          td{padding:7px 10px;border:1px solid #C8DFF0;font-size:10px;vertical-align:top}
          .stat{display:inline-block;text-align:center;padding:12px 18px;border-radius:10px;margin:0 8px 8px 0;min-width:90px}
          .log{background:#F8FBFD;border:1px solid #C8DFF0;border-radius:7px;padding:10px 13px;margin-bottom:8px}
          .divider{border:none;border-top:1px dashed #C8DFF0;margin:16px 0}
          @media print{body{padding:16px}}
        </style></head><body>

        <!-- HEADER -->
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;padding-bottom:14px;border-bottom:2px solid #1B4A6B">
          <div>
            <h1>🏢 ${c.nombre}</h1>
            <div style="color:#7A90A4;font-size:10px;margin-top:3px">${c.tipo||''}${c.ciudad?' · '+c.ciudad:''}</div>
            <div style="color:#7A90A4;font-size:9px;margin-top:5px">
              📞 ${c.telefono||'—'} &nbsp;·&nbsp; 📧 ${c.email||'—'}
              ${c.website?'&nbsp;·&nbsp; 🌐 '+c.website:''}
            </div>
          </div>
          <div style="text-align:right;color:#7A90A4;font-size:9px;line-height:1.8">
            <div style="font-weight:bold;color:#1B4A6B;font-size:10px">Medicare with Isabel</div>
            <div>${today}</div>
            <div>Follow-up every ${c.dias_recordatorio||30} days</div>
          </div>
        </div>

        <!-- STATS -->
        <div style="margin-bottom:20px">
          <div class="stat" style="background:#EAF5F0;border:1px solid #8DCFBA">
            <div style="font-size:26px;font-weight:bold;color:#1E7A5C">${mieIn.length}</div>
            <div style="font-size:8px;color:#1E7A5C;font-weight:bold;text-transform:uppercase">Referred to Us</div>
          </div>
          <div class="stat" style="background:#EBF4F9;border:1px solid #A9D0E8">
            <div style="font-size:26px;font-weight:bold;color:#1B5E8C">${mieOut.length}</div>
            <div style="font-size:8px;color:#1B5E8C;font-weight:bold;text-transform:uppercase">We Sent Them</div>
          </div>
          <div class="stat" style="background:#FEF8EE;border:1px solid #F5D5A0">
            <div style="font-size:26px;font-weight:bold;color:#C07A1A">${refPend.length}</div>
            <div style="font-size:8px;color:#C07A1A;font-weight:bold;text-transform:uppercase">Pending Referrals</div>
          </div>
          <div class="stat" style="background:#F3F0FB;border:1px solid #C3B4E8">
            <div style="font-size:26px;font-weight:bold;color:#5B3FAF">${ints.length}</div>
            <div style="font-size:8px;color:#5B3FAF;font-weight:bold;text-transform:uppercase">Interactions</div>
          </div>
          ${totalGasto>0?`<div class="stat" style="background:#FEF8EE;border:1px solid #F5D5A0">
            <div style="font-size:26px;font-weight:bold;color:#C07A1A">$${totalGasto.toFixed(2)}</div>
            <div style="font-size:8px;color:#C07A1A;font-weight:bold;text-transform:uppercase">Total Invested</div>
          </div>`:''}
        </div>

        <!-- CONTACTS -->
        ${ctcs.length?`<h2>👥 Contacts</h2>
        <table><tr>
          <th>Name</th><th>Title / Role</th><th>Phone</th><th>Email</th>
        </tr>${ctcs.map((ct,i)=>`<tr style="${i%2?'background:#F8FBFD':''}">
          <td><b>${ct.nombre}</b>${ct.es_principal=='1'?' ⭐':''}</td>
          <td>${ct.cargo||'—'}</td><td>${ct.telefono||'—'}</td><td>${ct.email||'—'}</td>
        </tr>`).join('')}</table>`:''}

        <!-- MEMBERS: REFERRED TO US -->
        ${memberTable(mieIn,'Members — Referred to Us (They Sent Them)','#1E7A5C','📥')}

        <!-- MEMBERS: WE SENT -->
        ${memberTable(mieOut,'Members — We Sent to This Account','#1B5E8C','📤')}

        <!-- PENDING REFERRALS: REFERRED TO US -->
        ${refTable(refPendIn,'Pending Referrals — They Referred to Us','#C07A1A','🔀')}

        <!-- PENDING REFERRALS: WE SENT -->
        ${refTable(refPendOut,'Pending Referrals — We Sent','#5B3FAF','🔀')}

        <!-- INTERACTION HISTORY -->
        ${ints.length?`<h2>📋 Interaction History</h2>
        ${ints.map(i=>`<div class="log">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px">
            <b>${i.tipo}${i.contacto_nombre?' with '+i.contacto_nombre:''} ${i.resultado?'— '+i.resultado:''}</b>
            <span style="color:#7A90A4;font-size:9px">${i.fecha} · ${i.agente_nombre||''}</span>
          </div>
          ${parseFloat(i.gasto_monto)>0?`<div style="color:#C07A1A;font-size:9px;font-weight:bold;margin-bottom:3px">💰 $${parseFloat(i.gasto_monto).toFixed(2)}${i.gasto_descripcion?' — '+i.gasto_descripcion:''}</div>`:''}
          ${i.descripcion?`<div style="color:#1B3A5C;line-height:1.6">${i.descripcion}</div>`:''}
        </div>`).join('')}`:''}

        <!-- NOTES -->
        ${c.notas?`<h2>📝 Notes</h2><div class="log" style="line-height:1.8">${c.notas}</div>`:''}

        <hr class="divider">
        <div style="text-align:center;font-size:8px;color:#7A90A4;margin-top:10px">
          Medicare with Isabel · CRM Report · Generated ${today}
        </div>
        </body></html>`);
        win.document.close();
        setTimeout(()=>win.print(),600);
    });
}
</script>
