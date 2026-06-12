<?php
/* ═══════════════════════════════════════════════════════════════════
 *  USUARIOS_SETUP.PHP — creación inicial de usuarios (endurecido)
 *  ─────────────────────────────────────────────────────────────────
 *  · Si la tabla usuarios YA tiene registros → exige sesión de ADMIN.
 *    (Solo queda abierto en una instalación vacía, donde aún no
 *     existe ningún usuario con quien autenticarse.)
 *  · Las contraseñas se toman SOLO de variables de entorno
 *    (PASS_ISABEL, PASS_SKARLETH, ...). Si no están definidas, ese
 *    usuario se OMITE — nunca se crea con contraseña placeholder.
 *  · Nunca imprime contraseñas en pantalla.
 *  · Tras usarlo, bórralo del servidor.
 * ═══════════════════════════════════════════════════════════════════ */
require_once 'session_boot.php';
require_once 'config.php';
$pdo = db();

$total_usuarios = 0;
try { $total_usuarios = (int)$pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn(); }
catch (Exception $e) { /* tabla aún no existe: instalación vacía */ }

if ($total_usuarios > 0) {
    if (empty($_SESSION['user']) || ($_SESSION['user']['rol'] ?? '') !== 'admin') {
        http_response_code(403);
        die('Ya existen usuarios. Solo un administrador con sesión iniciada puede ejecutar este script.');
    }
}

$team = [
    ["Isabel Fuentes", "isabel",   "admin", "IF", "#1B4A6B", getenv('PASS_ISABEL')   ?: ''],
    ["Skarleth",       "skarleth", "agent", "SK", "#2876A8", getenv('PASS_SKARLETH') ?: ''],
    ["Suri",           "suri",     "agent", "SU", "#1E7A5C", getenv('PASS_SURI')     ?: ''],
    ["Arlette",        "arlette",  "agent", "AR", "#C07A1A", getenv('PASS_ARLETTE')  ?: ''],
    ["Samia",          "samia",    "agent", "SA", "#7A5BAF", getenv('PASS_SAMIA')    ?: ''],
];

$created = 0; $skipped = 0; $sin_pass = [];
foreach ($team as [$nombre, $username, $rol, $ini, $color, $pwd]) {
    if ($pwd === '' || strlen($pwd) < 8) { $sin_pass[] = $username; continue; }
    $check = $pdo->prepare("SELECT id FROM usuarios WHERE username=?");
    $check->execute([$username]);
    if ($check->fetch()) { $skipped++; continue; }
    $hash = password_hash($pwd, PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO usuarios (nombre,username,password_hash,rol,iniciales,color,activo) VALUES (?,?,?,?,?,?,1)")
        ->execute([$nombre,$username,$hash,$rol,$ini,$color]);
    $created++;
}

echo "<h2>Medicare with Isabel — Setup de usuarios</h2>";
echo "<p>Creados: <b>$created</b> · Ya existían: <b>$skipped</b> · Omitidos por falta de contraseña (env var no definida o &lt;8 caracteres): <b>".count($sin_pass)."</b></p>";
if ($sin_pass) {
    echo "<p>Omitidos: <b>".htmlspecialchars(implode(', ', $sin_pass))."</b> — define PASS_* como variable de entorno y vuelve a ejecutar.</p>";
}
echo "<p style='color:red;font-weight:bold'>BORRA ESTE ARCHIVO DEL SERVIDOR: /crm/usuarios_setup.php</p>";
echo "<p><a href='index.php'>→ IR AL CRM</a></p>";
