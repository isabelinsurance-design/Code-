<?php
// ─────────────────────────────────────────────────────────────────────────
//  IMPORTADOR DE UN SOLO USO — Gastos históricos de oficina
//  Abre esta página UNA vez (logueado como admin) para cargar los gastos
//  anteriores. Es idempotente: si lo corres de nuevo, NO duplica.
//  Cuando termines, puedes borrar este archivo.
// ─────────────────────────────────────────────────────────────────────────
require_once 'session_boot.php';
require_once 'config.php';
$user = auth();
if (($user['rol'] ?? '') !== 'admin') { die('Solo un administrador puede importar gastos.'); }
$uid = $user['id'];
$pdo = db();

// Asegurar columnas (por si acaso)
try {
    foreach ([
        'recibo_foto'    => "ADD COLUMN recibo_foto VARCHAR(500) NULL",
        'reembolsar_a'   => "ADD COLUMN reembolsar_a INT NULL",
        'reembolsado'    => "ADD COLUMN reembolsado TINYINT(1) DEFAULT 0",
        'reembolsado_at' => "ADD COLUMN reembolsado_at TIMESTAMP NULL",
    ] as $c => $ddl) {
        $ex = $pdo->query("SHOW COLUMNS FROM gastos LIKE '$c'")->fetch();
        if (!$ex) $pdo->exec("ALTER TABLE gastos $ddl");
    }
} catch (Exception $e) {}

// Buscar id de usuario por nombre (para reembolsos)
function findUser(PDO $pdo, ?string $name): ?int {
    if (!$name) return null;
    $s = $pdo->prepare("SELECT id FROM usuarios WHERE nombre LIKE ? ORDER BY id LIMIT 1");
    $s->execute([$name.'%']);
    $id = $s->fetchColumn();
    if (!$id) { $s->execute(['%'.$name.'%']); $id = $s->fetchColumn(); }
    return $id ? (int)$id : null;
}

// ── Datos del CSV (ya parseados) ──────────────────────────────────────────
// [fecha, concepto, monto, categoria, tipo, estado, responsable, notas]
$rows = [
    ['2026-02-12','SUPERMERCADO LA COLONIA',963.00,'OFFICE','SUPPLIES','APROBADO',null,
     'PLATOS, TOALLAS DE COCINA, AZUCAR, FABULOSO, SAPOLIO, CREMORA, BOLSAS DE BASURA, BOTE DE AGUA, CUCHARAS, TENEDOR, PASTILLA DEL INODORO, JABON DE MANOS, PAQUETE DE PAPEL HIGIENICO, JUGO, RECIPIENTE PARA EL AZUCAR, REPELENTE DE SANCUDOS'],
    ['2026-03-12','3 BOTES DE AGUA',95.00,'OFFICE','SUPPLIES','APROBADO',null,null],
    ['2026-04-06','2 BOTES CON AGUA Y SUMINISTROS DE LIMPIEZA',260.00,'OFFICE','SUPPLIES','APROBADO','Skarleth',
     'AGUA 2, PAPEL HIGIENICO, ACE, CLORO, AZUCAR Y PAN'],
    ['2026-04-15','1 BOTE CON AGUA',30.00,'OFFICE','SUPPLIES','APROBADO','Skarleth',null],
    ['2026-04-16','OTROS GASTOS',20.00,'OFFICE','SUPPLIES','APROBADO','Skarleth','ASISTIN'],
    ['2026-05-05','SUPERMERCADO',1074.00,'OFFICE','SUPPLIES','APROBADO',null,null],
    ['2026-05-08','1 BOTE CON AGUA',30.00,'OFFICE','SUPPLIES','APROBADO',null,null],
    ['2026-05-05','PASTEL DE SAMIA',400.00,'OFFICE',null,'APROBADO',null,'Categoría original: Otros'],
    ['2026-06-01','3 BOTES CON AGUA',90.00,'OFFICE','SUPPLIES','PENDIENTE','ARLETTE','SE LE DEBEN 3 A ARLETTE'],
];

$insertados = []; $saltados = [];
$chk = $pdo->prepare("SELECT id FROM gastos WHERE fecha=? AND descripcion=? AND monto=?");
$ins = $pdo->prepare("INSERT INTO gastos
    (fecha,categoria,tipo,descripcion,vendedor,monto,metodo_pago,enviado_por,recibo,recibo_foto,reembolsar_a,reembolsado,reembolsado_at,estado,aprobado_por,notas,created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

foreach ($rows as $r) {
    [$fecha,$desc,$monto,$cat,$tipo,$estado,$resp,$notas] = $r;
    $chk->execute([$fecha,$desc,$monto]);
    if ($chk->fetch()) { $saltados[] = "$fecha · $desc (\$$monto)"; continue; }

    $reemb_id     = findUser($pdo, $resp);
    // Pagado (APROBADO) + tiene responsable => ya se le reembolsó; Por pagar => debe
    $reembolsado  = ($reemb_id && $estado === 'APROBADO') ? 1 : 0;
    $reemb_at     = $reembolsado ? $fecha.' 12:00:00' : null;
    $aprobado_por = $estado === 'APROBADO' ? $uid : null;

    $ins->execute([
        $fecha, $cat, $tipo, $desc, null, $monto, 'CASH', $uid,
        0, null, $reemb_id, $reembolsado, $reemb_at, $estado, $aprobado_por,
        $notas, $fecha.' 12:00:00'
    ]);
    $insertados[] = "$fecha · $desc (\$".number_format($monto,2).")"
        . ($resp ? " · resp: $resp".($reemb_id?'':' [NO ENCONTRADO]') : '');
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<title>Importar gastos</title>
<style>
body{font-family:'DM Sans',Arial,sans-serif;background:#EBF4F9;color:#1B3A5C;padding:30px;line-height:1.6}
.box{max-width:760px;margin:0 auto;background:#fff;border:1px solid #C8DFF0;border-radius:14px;padding:26px 30px}
h1{font-size:18px;color:#1B4A6B;margin:0 0 4px}
.ok{color:#1E7A5C}.skip{color:#C07A1A}
ul{font-size:13px;padding-left:18px}li{margin:3px 0}
.tag{display:inline-block;background:#EAF5F0;color:#1E7A5C;border:1px solid #8DCFBA;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:700}
.tag2{background:#FEF8EE;color:#C07A1A;border-color:#F5D5A0}
a{color:#2876A8;font-weight:700}
</style></head><body>
<div class="box">
<h1>💰 Importación de gastos históricos</h1>
<p>
  <span class="tag"><?= count($insertados) ?> insertados</span>
  &nbsp;<span class="tag tag2"><?= count($saltados) ?> ya existían (saltados)</span>
</p>

<?php if ($insertados): ?>
<h3 class="ok">✓ Insertados</h3>
<ul><?php foreach ($insertados as $i): ?><li><?= htmlspecialchars($i) ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<?php if ($saltados): ?>
<h3 class="skip">↺ Ya existían (no se duplicaron)</h3>
<ul><?php foreach ($saltados as $i): ?><li><?= htmlspecialchars($i) ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<p style="margin-top:18px;font-size:13px">
  Listo. Abre el CRM → pestaña <b>GASTOS</b> (selecciona <b>“TODOS LOS MESES”</b>) para verlos.<br>
  Por seguridad, puedes <b>borrar este archivo</b> (<code>import_gastos.php</code>) cuando termines.
</p>
<p><a href="index.php">← Volver al CRM</a></p>
</div></body></html>
