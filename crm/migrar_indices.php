<?php
/**
 * MIGRACIÓN DE ÍNDICES — Fase 3 (rendimiento)
 * ------------------------------------------------------------------
 * Agrega índices a las columnas que más se consultan, para que el CRM
 * siga rápido a medida que crecen los datos.
 *
 * Uso:
 *   1) Abre:  withisabelfuentes.com/crm/migrar_indices.php   (PREVISUALIZA, no cambia nada)
 *   2) Revisa la lista y pulsa "EJECUTAR" (o abre ...?ejecutar=1).
 *
 * Es seguro:
 *   - Solo CREA índices que falten (no borra ni toca datos).
 *   - Si un índice ya existe o una columna no existe, lo omite.
 *   - En MySQL 8 (InnoDB) la creación es "online" (no bloquea el CRM).
 *
 * Cuando termine y todo diga "creado / ya existía", puedes BORRAR este archivo.
 */
require_once 'session_boot.php';
require_once 'config.php';
$user = auth();                 // exige sesión iniciada (si no, redirige a login)
if (!isAdmin()) { http_response_code(403); exit('Solo administradores pueden ejecutar esta migración.'); }
$pdo = db();

$ejecutar = !empty($_GET['ejecutar']);

// ── Plan de índices: [tabla, nombre_indice, [columnas...]] ──────────────────
// Solo índices que NO existen hoy según el esquema; nombres únicos para no chocar.
$plan = [
  // miembros: la tabla más grande y consultada, hoy solo tiene PRIMARY KEY
  ['miembros', 'idx_miembros_agente',         ['agente_id']],
  ['miembros', 'idx_miembros_estado',         ['estado']],
  ['miembros', 'idx_miembros_carrier',        ['carrier']],
  ['miembros', 'idx_miembros_fecha_efectiva', ['fecha_efectiva']],
  ['miembros', 'idx_miembros_telefono',       ['telefono']],
  ['miembros', 'idx_miembros_referido_por',   ['referido_por']],

  // notificaciones: filtros por usuario + no leídas + orden por fecha
  ['notificaciones', 'idx_notif_user_leido',   ['user_id','leido']],
  ['notificaciones', 'idx_notif_user_created', ['user_id','created_at']],

  // chat: polling por fecha y mensajes directos
  ['chat_mensajes', 'idx_chat_created',   ['created_at']],
  ['chat_mensajes', 'idx_chat_recipient', ['recipient_id']],

  // llamadas de prospectos: métricas por agente y día (MI DÍA / reportes)
  ['llamadas_prospectos', 'idx_llam_agente_created', ['agente_id','created_at']],
  ['llamadas_prospectos', 'idx_llam_miembro',        ['miembro_id']],

  // logs de campañas
  ['campana_logs', 'idx_campl_campana',  ['campana_id']],
  ['campana_logs', 'idx_campl_contacto', ['contacto_id']],

  // cuentas (contactos / interacciones / referidos)
  ['cuentas_interacciones', 'idx_ci_cuenta_fecha', ['cuenta_id','fecha']],
  ['cuentas_interacciones', 'idx_ci_contacto',     ['contacto_id']],
  ['cuentas_interacciones', 'idx_ci_agente',       ['agente_id']],
  ['cuentas_contactos',     'idx_cc_cuenta_activo', ['cuenta_id','activo']],
  ['referidos', 'idx_ref_cuenta_created', ['cuenta_id','created_at']],
  ['referidos', 'idx_ref_estado',         ['estado']],
  ['referidos', 'idx_ref_contacto',       ['contacto_id']],
  ['referidos', 'idx_ref_miembro',        ['miembro_id']],

  // reportes / asistencia: consultas por fecha entre todos los agentes
  ['reporte_diario', 'idx_rep_fecha',  ['fecha']],
  ['asistencia',     'idx_asist_fecha', ['fecha']],

  // comisiones: filtro por estado (pagadas/pendientes)
  ['comisiones', 'idx_com_estado', ['estado']],

  // pipeline: tareas pendientes ordenadas por fecha programada
  ['pipeline_pasos', 'idx_pp_completado_fecha', ['completado','fecha_programada']],
];

// ── Helpers (idempotencia) ──────────────────────────────────────────────────
function tablaExiste(PDO $pdo, string $t): bool {
  try { return (bool)$pdo->query("SHOW TABLES LIKE ".$pdo->quote($t))->fetchColumn(); }
  catch (Exception $e) { return false; }
}
function columnaExiste(PDO $pdo, string $t, string $c): bool {
  try { $s=$pdo->prepare("SHOW COLUMNS FROM `$t` LIKE ?"); $s->execute([$c]); return (bool)$s->fetch(); }
  catch (Exception $e) { return false; }
}
function indiceExiste(PDO $pdo, string $t, string $name): bool {
  try { $s=$pdo->prepare("SHOW INDEX FROM `$t` WHERE Key_name=?"); $s->execute([$name]); return (bool)$s->fetch(); }
  catch (Exception $e) { return false; }
}

$filas = [];
$creados = 0; $existian = 0; $omitidos = 0; $errores = 0;

foreach ($plan as [$tabla, $nombre, $cols]) {
  $colsTxt = implode(', ', $cols);
  if (!tablaExiste($pdo, $tabla)) { $filas[]=[$tabla,$nombre,$colsTxt,'omitido','la tabla no existe']; $omitidos++; continue; }
  $faltante = null;
  foreach ($cols as $c) { if (!columnaExiste($pdo,$tabla,$c)) { $faltante=$c; break; } }
  if ($faltante) { $filas[]=[$tabla,$nombre,$colsTxt,'omitido',"columna «$faltante» no existe"]; $omitidos++; continue; }
  if (indiceExiste($pdo,$tabla,$nombre)) { $filas[]=[$tabla,$nombre,$colsTxt,'ya existía','—']; $existian++; continue; }

  if (!$ejecutar) { $filas[]=[$tabla,$nombre,$colsTxt,'pendiente','se creará al ejecutar']; continue; }

  $colSql = implode(',', array_map(fn($c)=>"`$c`", $cols));
  try {
    $pdo->exec("CREATE INDEX `$nombre` ON `$tabla` ($colSql)");
    $filas[]=[$tabla,$nombre,$colsTxt,'✓ creado','—']; $creados++;
  } catch (Exception $e) {
    $filas[]=[$tabla,$nombre,$colsTxt,'error',$e->getMessage()]; $errores++;
  }
}

// ── Reporte ─────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
function hh($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
$colorEstado = function($e){
  if (strpos($e,'creado')!==false) return '#1E7A5C';
  if ($e==='ya existía')          return '#2876A8';
  if ($e==='pendiente')           return '#C07A1A';
  if ($e==='error')               return '#B83232';
  return '#7A90A4';
};
?>
<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Migración de índices — CRM</title>
<style>
  body{font-family:'DM Sans',system-ui,Arial,sans-serif;background:#EBF4F9;color:#1B3A5C;margin:0;padding:24px}
  .wrap{max-width:860px;margin:0 auto;background:#fff;border:1px solid #C8DFF0;border-radius:16px;padding:28px}
  h1{font-size:18px;color:#1B4A6B;margin:0 0 4px}
  .sub{font-size:12px;color:#7A90A4;margin-bottom:18px}
  .resumen{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}
  .chip{padding:8px 14px;border-radius:10px;font-size:12px;font-weight:800}
  table{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px}
  th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #EBF4F9}
  th{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#7A90A4}
  .estado{font-weight:800}
  .btn{display:inline-block;margin-top:18px;background:#1B4A6B;color:#fff;text-decoration:none;padding:11px 20px;border-radius:10px;font-weight:800;font-size:13px}
  .btn.gris{background:#7A90A4}
  .aviso{background:#FEF8EE;border:1px solid #F5D5A0;color:#C07A1A;border-radius:10px;padding:12px 14px;font-size:12px;margin:14px 0}
  .ok{background:#EAF5F0;border:1px solid #8DCFBA;color:#1E7A5C;border-radius:10px;padding:12px 14px;font-size:12px;margin:14px 0}
</style></head><body><div class="wrap">
<h1>⚡ Migración de índices — Fase 3</h1>
<div class="sub"><?= $ejecutar ? 'Modo: EJECUTANDO cambios' : 'Modo: PREVISUALIZACIÓN (no se cambió nada todavía)' ?></div>

<div class="resumen">
  <span class="chip" style="background:#EAF5F0;color:#1E7A5C">✓ Creados: <?= $creados ?></span>
  <span class="chip" style="background:#EBF5FB;color:#2876A8">Ya existían: <?= $existian ?></span>
  <span class="chip" style="background:#FEF8EE;color:#C07A1A">Omitidos: <?= $omitidos ?></span>
  <?php if($errores):?><span class="chip" style="background:#FDF0EE;color:#B83232">Errores: <?= $errores ?></span><?php endif; ?>
</div>

<?php if(!$ejecutar): ?>
  <div class="aviso">Esto es una <b>previsualización</b>. Aún no se ha modificado la base de datos.
  Revisa la lista y, si todo se ve bien, pulsa <b>EJECUTAR</b>.</div>
  <a class="btn" href="?ejecutar=1">▶ EJECUTAR Y CREAR LOS ÍNDICES</a>
<?php else: ?>
  <div class="ok">Listo. Los índices marcados como <b>✓ creado</b> ya están activos.
  Cuando confirmes que el CRM va más rápido, puedes <b>borrar este archivo</b> (<code>/crm/migrar_indices.php</code>).</div>
  <a class="btn gris" href="index.php">← VOLVER AL CRM</a>
<?php endif; ?>

<table>
  <tr><th>Tabla</th><th>Índice</th><th>Columnas</th><th>Estado</th><th>Detalle</th></tr>
  <?php foreach($filas as [$t,$n,$c,$e,$d]): ?>
  <tr>
    <td><b><?= hh($t) ?></b></td>
    <td><?= hh($n) ?></td>
    <td style="color:#7A90A4"><?= hh($c) ?></td>
    <td class="estado" style="color:<?= $colorEstado($e) ?>"><?= hh($e) ?></td>
    <td style="color:#7A90A4"><?= hh($d) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
</div></body></html>
