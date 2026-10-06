<?php
// ─────────────────────────────────────────────────────────────────────────
//  DIAGNÓSTICO — Duplicado del plan Alignment Health 055 (HMO) 2026
//  Se detectó un plan duplicado: id=65 "...Giveback 055 (HMO)" (ya existía
//  de antes) e id=71 "...Giveback (HMO)" (insertado por el script anterior).
//  Esta página solo MUESTRA el contenido completo de ambos, lado a lado,
//  para decidir cuál conservar — no borra ni cambia nada todavía.
//  Puedes borrar este archivo después de usarlo.
// ─────────────────────────────────────────────────────────────────────────
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'session_boot.php';
require_once 'config.php';
$user = auth();
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

header('Content-Type: text/plain; charset=utf-8');

$ids = [65, 71];
foreach ($ids as $id) {
    $stmt = $pdo->prepare("SELECT * FROM planes_comparacion WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "========================================\n";
    echo "ID $id\n";
    echo "========================================\n";
    if (!$row) {
        echo "(no existe esta fila)\n\n";
        continue;
    }
    foreach ($row as $campo => $valor) {
        echo "$campo: " . str_replace("\n", " | ", (string)$valor) . "\n";
    }
    echo "\n";
}

echo "========================================\n";
echo "También el 056 y 047, para confirmar que no tienen el mismo problema:\n";
echo "========================================\n";
$rows = $pdo->query("SELECT id, nombre_plan, numero_plan, anio FROM planes_comparacion WHERE carrier='Alignment Health Plan' ORDER BY anio, nombre_plan")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "id={$r['id']} | {$r['anio']} | numero_plan=\"{$r['numero_plan']}\" | {$r['nombre_plan']}\n";
}
