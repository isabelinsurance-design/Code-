<?php
/* ═══════════════════════════════════════════════════════════════════
 *  SMS_WEBHOOK_LOG.PHP — ver los últimos intentos de Twilio de avisar
 *  de un SMS entrante, para diagnosticar por qué uno no aparece en
 *  el CRM (¿Twilio ni siquiera llamó? ¿la firma no coincidió? ¿se
 *  guardó bien pero con otro número?).
 *  ─────────────────────────────────────────────────────────────────
 *  Solo lo puede ver un ADMIN con sesión iniciada.
 * ═══════════════════════════════════════════════════════════════════ */
require_once 'session_boot.php';
require_once 'config.php';

$user = auth();
if (empty($user) || ($user['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Solo un administrador con sesión iniciada puede ver esto.');
}
$pdo = db();
$rows = [];
try {
    $rows = $pdo->query("SELECT * FROM sms_webhook_log ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!doctype html><html><head><meta charset="utf-8"><title>Log de SMS entrantes</title>
<style>body{font-family:sans-serif;max-width:1100px;margin:30px auto;padding:0 16px}
table{border-collapse:collapse;width:100%;margin-top:14px;font-size:12px}
td,th{border:1px solid #ccc;padding:6px 8px;text-align:left;vertical-align:top}
th{background:#f0f0f0}
.ok{color:#1E7A5C;font-weight:700}.bad{color:#B83232;font-weight:700}
pre{white-space:pre-wrap;word-break:break-all;margin:0;font-size:10px;color:#555;max-width:340px}</style></head><body>
<h2>Medicare with Isabel — Últimos intentos de SMS entrante</h2>
<p>Si mandaste un SMS de prueba y no aparece en el CRM, busca la fila más reciente aquí:</p>
<ul>
<li><b>Nada aparece / no hay filas nuevas</b> → Twilio ni siquiera llegó a llamar a <code>sms_webhook.php</code>. Revisa en la consola de Twilio → tu número → Messaging → "A message comes in" que la URL esté bien puesta, y en Twilio → Monitor → Logs → Errors si hay algún error.</li>
<li><b>motivo = firma_invalida</b> → la petición llegó, pero no se pudo confirmar que viniera de Twilio (revisa que TWILIO_AUTH_TOKEN en config.php sea EXACTAMENTE el mismo que muestra la consola de Twilio).</li>
<li><b>motivo = sin_auth_token_configurado</b> → falta poner TWILIO_AUTH_TOKEN en config.php.</li>
<li><b>motivo = ok</b> → se guardó correctamente. Si aun así no lo ves en el CRM, probablemente el número no coincide exactamente con el de esa conversación (compara la columna "teléfono" aquí contra el de la pantalla del CRM).</li>
</ul>
<table><tr><th>Fecha</th><th>Motivo</th><th>Teléfono</th><th>Firma OK</th><th>IP</th><th>Datos recibidos</th></tr>
<?php if (empty($rows)): ?>
<tr><td colspan="6" style="text-align:center;color:#888">Sin registros todavía — Twilio no ha llamado a este webhook.</td></tr>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr>
<td style="white-space:nowrap"><?=htmlspecialchars($r['created_at'])?></td>
<td class="<?=$r['motivo']==='ok'?'ok':'bad'?>"><?=htmlspecialchars($r['motivo'])?></td>
<td><?=htmlspecialchars($r['telefono']??'—')?></td>
<td><?=$r['firma_valida']===null?'—':($r['firma_valida']?'✓':'✕')?></td>
<td><?=htmlspecialchars($r['ip']??'—')?></td>
<td><pre><?=htmlspecialchars($r['post_raw']??'')?></pre></td>
</tr>
<?php endforeach; ?>
</table>
<p style="margin-top:20px"><a href="index.php">→ IR AL CRM</a></p>
</body></html>
