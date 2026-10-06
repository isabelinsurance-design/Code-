<?php
// ─────────────────────────────────────────────────────────────────────────
//  REPARACIÓN FINAL — Alignment Health 2026
//  1) Borra el id=71 (duplicado exacto del id=65 "...Giveback 055 (HMO)" —
//     mismo contenido letra por letra, solo cambiaba el nombre).
//  2) Corrige el numero_plan de los 3 planes C-SNP 2026 (ids 1,2,3), que
//     tenían el prefijo del contrato ("H3815-039") mientras que sus
//     versiones 2027 (ids 6,7,8) solo tienen el código ("039") — por eso
//     no se emparejaban para la comparación ANOC. Se les quita el prefijo
//     para que coincidan.
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
echo "=== REPARACIÓN FINAL: Alignment Health 2026 ===\n\n";

// 1) Confirmar que 65 y 71 son idénticos antes de borrar, por seguridad.
$s = $pdo->prepare("SELECT * FROM planes_comparacion WHERE id IN (65,71)");
$s->execute();
$rows = $s->fetchAll(PDO::FETCH_ASSOC);
$campos_a_ignorar = ['id','nombre_plan','created_at','updated_at','agregado_por'];
if (count($rows) === 2) {
    $a = $rows[0]; $b = $rows[1];
    $diferencias = [];
    foreach ($a as $campo => $valor) {
        if (in_array($campo, $campos_a_ignorar)) continue;
        if ((string)$valor !== (string)($b[$campo] ?? null)) $diferencias[] = $campo;
    }
    if ($diferencias) {
        echo "⚠ NO se borró nada — id=65 e id=71 tienen diferencias reales en: " . implode(', ', $diferencias) . "\n";
        echo "Revisa esto manualmente antes de continuar.\n\n";
    } else {
        $pdo->prepare("DELETE FROM planes_comparacion WHERE id=71")->execute();
        echo "✅ Confirmado: id=65 e id=71 eran idénticos en todo menos el nombre. Se borró el id=71 (duplicado).\n\n";
    }
} else {
    echo "⚠ No se encontraron las 2 filas (65 y 71) — puede que ya se haya corregido antes. Filas encontradas: " . count($rows) . "\n\n";
}

// 2) Corregir el numero_plan de los C-SNP 2026 para que coincida con 2027.
$correcciones = ['1' => '039', '2' => '044', '3' => '045'];
foreach ($correcciones as $id => $codigoCorrecto) {
    $stmt = $pdo->prepare("SELECT numero_plan, nombre_plan FROM planes_comparacion WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo "⚠ id=$id no existe, se saltó.\n"; continue; }
    if ($row['numero_plan'] === $codigoCorrecto) {
        echo "id=$id (\"{$row['nombre_plan']}\"): numero_plan ya era \"$codigoCorrecto\" — no se tocó.\n";
        continue;
    }
    $upd = $pdo->prepare("UPDATE planes_comparacion SET numero_plan=? WHERE id=?");
    $upd->execute([$codigoCorrecto, $id]);
    echo "✅ id=$id (\"{$row['nombre_plan']}\"): numero_plan corregido de \"{$row['numero_plan']}\" a \"$codigoCorrecto\".\n";
}

echo "\n=== Estado final de Alignment Health Plan en la tabla ===\n";
$all = $pdo->query("SELECT id, nombre_plan, numero_plan, anio, activo FROM planes_comparacion WHERE carrier='Alignment Health Plan' ORDER BY numero_plan, anio")->fetchAll(PDO::FETCH_ASSOC);
foreach ($all as $r) {
    echo "id={$r['id']} | {$r['anio']} | numero_plan=\"{$r['numero_plan']}\" | activo={$r['activo']} | {$r['nombre_plan']}\n";
}
echo "\nPuedes borrar este archivo (fix_alignment_2026.php) cuando termines.\n";
