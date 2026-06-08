<?php
/* ═══════════════════════════════════════════════════════════════════
 *  DB_CHECK.PHP  —  Diagnóstico TEMPORAL de conexión a la base de datos
 *  ─────────────────────────────────────────────────────────────────
 *  Úsalo así en el navegador:
 *      .../crm/db_check.php?key=verifica2026
 *
 *  Te dice qué dato del config.php está mal SIN mostrar la contraseña.
 *  ⚠️ BORRA este archivo apenas termines de revisar (por seguridad).
 * ═══════════════════════════════════════════════════════════════════ */

// Llave simple para que nadie más pueda abrirlo por casualidad
if (($_GET['key'] ?? '') !== 'verifica2026') {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config.php';

echo "DIAGNÓSTICO DE CONEXIÓN — Medicare with Isabel CRM\n";
echo str_repeat('=', 55) . "\n\n";

// Mostramos los valores SIN revelar la contraseña real
$pass = defined('DB_PASS') ? DB_PASS : '(no definida)';
$passInfo = ($pass === '' || $pass === '(no definida)')
    ? '⚠️ VACÍA o no definida'
    : strlen($pass) . ' caracteres (empieza con "' . substr($pass, 0, 1) . '", termina con "' . substr($pass, -1) . '")';

echo "DB_HOST: " . (defined('DB_HOST') ? DB_HOST : '(no definida)') . "\n";
echo "DB_USER: " . (defined('DB_USER') ? DB_USER : '(no definida)') . "\n";
echo "DB_NAME: " . (defined('DB_NAME') ? DB_NAME : '(no definida)') . "\n";
echo "DB_PASS: " . $passInfo . "\n\n";
echo str_repeat('-', 55) . "\n\n";

try {
    $pdo = db();
    $n = $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
    echo "✅ CONEXIÓN EXITOSA\n";
    echo "   La base de datos respondió correctamente.\n";
    echo "   Usuarios registrados en 'usuarios': $n\n\n";
    echo "Si el login seguía fallando antes, ya debería funcionar.\n";
    echo "👉 AHORA BORRA ESTE ARCHIVO (db_check.php) por seguridad.\n";
} catch (Throwable $e) {
    echo "❌ FALLA DE CONEXIÓN\n\n";
    echo "Mensaje exacto del servidor:\n  " . $e->getMessage() . "\n\n";
    $msg = $e->getMessage();
    if (strpos($msg, '1045') !== false || stripos($msg, 'Access denied') !== false) {
        echo "DIAGNÓSTICO: El USUARIO o la CONTRASEÑA están mal.\n";
        echo "  → Revisa DB_USER y DB_PASS en config.php.\n";
        echo "  → Confírmalos en cPanel → MySQL Databases.\n";
    } elseif (strpos($msg, '1049') !== false || stripos($msg, 'Unknown database') !== false) {
        echo "DIAGNÓSTICO: El NOMBRE de la base de datos está mal.\n";
        echo "  → Revisa DB_NAME en config.php.\n";
    } elseif (strpos($msg, '2002') !== false || stripos($msg, 'refused') !== false || stripos($msg, 'getaddrinfo') !== false) {
        echo "DIAGNÓSTICO: El HOST está mal.\n";
        echo "  → En Bluehost casi siempre debe ser 'localhost'.\n";
    } else {
        echo "DIAGNÓSTICO: Error de conexión (ver mensaje arriba).\n";
    }
    echo "\n👉 Cuando lo arregles, recarga esta página para confirmar.\n";
    echo "👉 Y BORRA este archivo (db_check.php) al terminar.\n";
}
