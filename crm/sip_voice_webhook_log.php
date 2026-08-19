<?php
/* ═══════════════════════════════════════════════════════════════════
 *  SIP_VOICE_WEBHOOK_LOG.PHP — ver los últimos intentos de llamada
 *  saliente desde el teléfono Grandstream, para diagnosticar por qué
 *  una llamada timbra y se cuelga (¿qué número mandó realmente el
 *  teléfono? ¿la firma de Twilio coincidió?).
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
    $rows = $pdo->query("SELECT * FROM sip_webhook_log ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!doctype html><html><head><meta charset="utf-8"><title>Log de llamadas salientes SIP</title>
<style>body{font-family:sans-serif;max-width:1100px;margin:30px auto;padding:0 16px}
table{border-collapse:collapse;width:100%;margin-top:14px;font-size:12px}
td,th{border:1px solid #ccc;padding:6px 8px;text-align:left;vertical-align:top}
th{background:#f0f0f0}
.ok{color:#1E7A5C;font-weight:700}.bad{color:#B83232;font-weight:700}
pre{white-space:pre-wrap;word-break:break-all;margin:0;font-size:10px;color:#555;max-width:340px}</style></head><body>
<h2>Medicare with Isabel — Últimas llamadas salientes desde el teléfono SIP</h2>
<p>Marca de nuevo desde el teléfono Grandstream y busca la fila más reciente aquí:</p>
<ul>
<li><b>Nada aparece / no hay filas nuevas</b> → Twilio ni siquiera llegó a llamar a <code>sip_voice_webhook.php</code>. Revisa en Twilio → Voice → SIP Domains → tu dominio → "A call comes in" que la URL esté bien puesta.</li>
<li><b>motivo = firma_invalida</b> → la petición llegó pero no se pudo confirmar que viniera de Twilio.</li>
<li><b>motivo = sin_numero</b> → Twilio llamó pero no mandó ningún número en "To".</li>
<li><b>motivo = ok</b> → mira la columna "Número marcado": muestra <i>lo que mandó el teléfono → lo que se le mandó a Twilio</i>. Compáralo contra el número que de verdad marcaste — ahí se ve si el teléfono está mandando dígitos de más, de menos, o distintos.</li>
</ul>
<table><tr><th>Fecha</th><th>Motivo</th><th>Número marcado</th><th>IP</th><th>Datos recibidos (To/From completos)</th></tr>
<?php if (empty($rows)): ?>
<tr><td colspan="5" style="text-align:center;color:#888">Sin registros todavía — Twilio no ha llamado a este webhook.</td></tr>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr>
<td style="white-space:nowrap"><?=htmlspecialchars($r['created_at'])?></td>
<td class="<?=$r['motivo']==='ok'?'ok':'bad'?>"><?=htmlspecialchars($r['motivo'])?></td>
<td><?=htmlspecialchars($r['to_marcado']??'—')?></td>
<td><?=htmlspecialchars($r['ip']??'—')?></td>
<td><pre><?=htmlspecialchars($r['post_raw']??'')?></pre></td>
</tr>
<?php endforeach; ?>
</table>
<p style="margin-top:20px"><a href="index.php">→ IR AL CRM</a></p>
</body></html>
