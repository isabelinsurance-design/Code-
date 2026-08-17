<?php
/* ═══════════════════════════════════════════════════════════════════
 *  NORMALIZAR_TELEFONOS.PHP — arregla el formato de los teléfonos que
 *  ya estaban guardados, para que todos queden como +1XXXXXXXXXX.
 *  ─────────────────────────────────────────────────────────────────
 *  · Solo lo puede correr un ADMIN con sesión iniciada.
 *  · Es seguro correrlo varias veces — un número ya normalizado no
 *    se vuelve a tocar (no rompe nada si se corre dos veces).
 *  · Recorre TODAS las tablas donde se guardan teléfonos de personas
 *    (miembros, contactos de campaña, cuentas referentes, referidos,
 *    llamadas a prospectos). No toca "pcp_phone" (teléfono del
 *    doctor), porque a veces trae extensión (ej. "x123") y limpiarlo
 *    a lo bruto podría dañar ese dato.
 *  · Después de usarlo puedes borrar este archivo del servidor, o
 *    dejarlo — no hace daño si alguien más lo abre sin ser admin
 *    (se lo bloquea) ni si se corre de nuevo por accidente.
 * ═══════════════════════════════════════════════════════════════════ */
require_once 'session_boot.php';
require_once 'config.php';
require_once 'lib_telefono.php';

$user = auth();
if (empty($user) || ($user['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Solo un administrador con sesión iniciada puede correr esto.');
}
$pdo = db();

// [tabla, columna(s) de teléfono]
$objetivos = [
    ['miembros', ['telefono', 'telefono2']],
    ['campana_contactos', ['telefono']],
    ['cuentas', ['telefono']],
    ['cuentas_contactos', ['telefono']],
    ['referidos', ['telefono']],
    ['llamadas_prospectos', ['telefono']],
];

$reporte = [];
foreach ($objetivos as [$tabla, $cols]) {
    foreach ($cols as $col) {
        $r = ['tabla' => $tabla, 'columna' => $col, 'revisados' => 0, 'cambiados' => 0, 'error' => null];
        try {
            // Confirmar que la tabla/columna existe antes de tocarla (por si
            // esta instalación es más vieja y todavía no tiene esa tabla).
            $existe = $pdo->query("SHOW COLUMNS FROM `$tabla` LIKE " . $pdo->quote($col))->fetch();
            if (!$existe) { $r['error'] = 'Columna no existe — se omite'; $reporte[] = $r; continue; }

            $rows = $pdo->query("SELECT id, `$col` AS val FROM `$tabla` WHERE `$col` IS NOT NULL AND `$col` <> ''")->fetchAll(PDO::FETCH_ASSOC);
            $upd = $pdo->prepare("UPDATE `$tabla` SET `$col` = ? WHERE id = ?");
            foreach ($rows as $row) {
                $r['revisados']++;
                $nuevo = normalizar_tel($row['val']);
                if ($nuevo !== '' && $nuevo !== $row['val']) {
                    $upd->execute([$nuevo, $row['id']]);
                    $r['cambiados']++;
                }
            }
        } catch (Exception $e) {
            $r['error'] = $e->getMessage();
        }
        $reporte[] = $r;
    }
}
?>
<!doctype html><html><head><meta charset="utf-8"><title>Normalizar teléfonos</title>
<style>body{font-family:sans-serif;max-width:700px;margin:30px auto;padding:0 16px}
table{border-collapse:collapse;width:100%;margin-top:14px}
td,th{border:1px solid #ccc;padding:6px 10px;text-align:left;font-size:13px}
th{background:#f0f0f0}.err{color:#B83232}</style></head><body>
<h2>Medicare with Isabel — Normalizar teléfonos</h2>
<p>Formato final: <code>+1XXXXXXXXXX</code> (código de país, sin espacios ni símbolos).</p>
<table><tr><th>Tabla</th><th>Columna</th><th>Revisados</th><th>Cambiados</th><th>Nota</th></tr>
<?php foreach ($reporte as $r): ?>
<tr><td><?=htmlspecialchars($r['tabla'])?></td><td><?=htmlspecialchars($r['columna'])?></td>
<td><?=$r['revisados']?></td><td><b><?=$r['cambiados']?></b></td>
<td class="err"><?=$r['error']?htmlspecialchars($r['error']):''?></td></tr>
<?php endforeach; ?>
</table>
<p style="margin-top:20px"><a href="index.php">→ IR AL CRM</a></p>
</body></html>
