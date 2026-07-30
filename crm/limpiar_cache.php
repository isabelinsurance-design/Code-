<?php
// ─────────────────────────────────────────────────────────────────────────
//  LIMPIAR CACHÉ DE PHP (OPcache) — de un solo uso
//  A veces, después de hacer Deploy en cPanel, el servidor sigue
//  ejecutando la versión VIEJA del código porque tiene una copia
//  compilada guardada en memoria. Esta página fuerza a que la olvide y
//  lea de nuevo los archivos actualizados.
//  Puedes borrar este archivo después de usarlo.
// ─────────────────────────────────────────────────────────────────────────
require_once 'session_boot.php';
require_once 'config.php';
$user = auth(); // solo pide estar logueado, no hace falta ser admin

$hizo_opcache = false;
$hizo_stat    = false;

if (function_exists('opcache_reset')) {
    $hizo_opcache = @opcache_reset();
}
clearstatcache(true);
$hizo_stat = true;

// Forzar que se vuelva a compilar el archivo principal ahora mismo
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__DIR__ . '/index.php', true);
    @opcache_invalidate(__DIR__ . '/api.php', true);
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<title>Limpiar caché</title>
<style>
body{font-family:'DM Sans',Arial,sans-serif;background:#EBF4F9;color:#1B3A5C;padding:30px;line-height:1.6}
.box{max-width:600px;margin:0 auto;background:#fff;border:1px solid #C8DFF0;border-radius:14px;padding:26px 30px}
h1{font-size:18px;color:#1B4A6B;margin:0 0 14px}
.ok{color:#1E7A5C;font-weight:700}
.warn{color:#C07A1A;font-weight:700}
a{color:#2876A8;font-weight:700}
</style></head><body>
<div class="box">
<h1>🧹 Limpieza de caché de PHP</h1>
<?php if (function_exists('opcache_reset')): ?>
<p class="<?= $hizo_opcache ? 'ok' : 'warn' ?>">
  <?= $hizo_opcache ? '✓ OPcache limpiado correctamente.' : '⚠ OPcache está desactivado o no se pudo limpiar (puede que el servidor no lo esté usando, en cuyo caso este paso no era necesario).' ?>
</p>
<?php else: ?>
<p class="warn">⚠ Este servidor no tiene la extensión OPcache activa — el problema no era esto.</p>
<?php endif; ?>
<p>Ahora abre el CRM otra vez con <b>Ctrl+F5</b> (recarga forzada) e intenta subir tu lista de nuevo.</p>
<p style="margin-top:18px;font-size:13px">Por seguridad, puedes <b>borrar este archivo</b> (<code>limpiar_cache.php</code>) cuando termines.</p>
<p><a href="index.php">← Volver al CRM</a></p>
</div></body></html>
