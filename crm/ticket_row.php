<?php
/* ═══════════════════════════════════════════════════════════════════
 *  TICKET_ROW.PHP — devuelve el HTML de UN solo <tr> de la tabla de
 *  Tickets (el mismo que ya arma index.php), para poder actualizarlo
 *  después de guardar/cambiar estado sin recargar TODA la página.
 *  ─────────────────────────────────────────────────────────────────
 *  GET id=<ticket_id>. Requiere sesión iniciada. Un agente (no admin)
 *  solo puede refrescar tickets asignados a él (o sin asignar, creados
 *  por él) — el mismo criterio que usa el listado principal.
 * ═══════════════════════════════════════════════════════════════════ */
require_once 'session_boot.php';
require_once 'config.php';
require_once 'lib_row_render.php';
$user = auth();
if (empty($user)) { http_response_code(403); exit; }
$admin = isAdmin();
$uid   = $user['id'];

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit; }

$pdo = db();
$html = render_ticket_row_html($pdo, $id, $admin, $uid);
if ($html === null) { http_response_code(404); exit; }
echo $html;
