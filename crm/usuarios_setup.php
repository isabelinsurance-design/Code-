<?php
// EJECUTA ESTE ARCHIVO UNA SOLA VEZ para crear los usuarios
// Accede: withisabelfuentes.com/crm/usuarios_setup.php
// LUEGO BÓRRALO INMEDIATAMENTE
require_once 'config.php';
$pdo = db();
// ⚠️ SEGURIDAD: define las contraseñas en variables de entorno antes de ejecutar.
//    NO escribas contraseñas reales aquí (este archivo se versiona en Git).
//    Ej:  PASS_ISABEL='...' PASS_SKARLETH='...' php usuarios_setup.php
$team = [
["Isabel Fuentes", "isabel",   "admin", "IF", "#1B4A6B", getenv('PASS_ISABEL')   ?: 'CAMBIAR_ISABEL'],
["Skarleth",       "skarleth", "agent", "SK", "#2876A8", getenv('PASS_SKARLETH') ?: 'CAMBIAR_SKARLETH'],
["Suri",           "suri",     "agent", "SU", "#1E7A5C", getenv('PASS_SURI')     ?: 'CAMBIAR_SURI'],
["Arlette",        "arlette",  "agent", "AR", "#C07A1A", getenv('PASS_ARLETTE')  ?: 'CAMBIAR_ARLETTE'],
["Samia",          "samia",    "agent", "SA", "#7A5BAF", getenv('PASS_SAMIA')    ?: 'CAMBIAR_SAMIA'],
];
$created = 0; $skipped = 0;
foreach ($team as [$nombre, $username, $rol, $ini, $color, $pwd]) {
$check = $pdo->prepare("SELECT id FROM usuarios WHERE username=?");
$check->execute([$username]);
if ($check->fetch()) { $skipped++; continue; }
$hash = password_hash($pwd, PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO usuarios (nombre,username,password_hash,rol,iniciales,color,activo) VALUES (?,?,?,?,?,?,1)")
->execute([$nombre,$username,$hash,$rol,$ini,$color]);
$created++;
}
echo "<h2>✓ Medicare with Isabel — Usuarios Creados</h2>";
echo "<p>Creados: <b>$created</b> | Ya existían: <b>$skipped</b></p>";
echo "<table border='1' cellpadding='8' style='border-collapse:collapse'>";
echo "<tr><th>NOMBRE</th><th>USUARIO</th><th>ROL</th><th>CONTRASEÑA</th></tr>";
foreach ($team as [$nombre,$username,$rol,$ini,$color,$pwd]) {
echo "<tr><td>$nombre</td><td>$username</td><td>$rol</td><td><b>$pwd</b></td></tr>";
}
echo "</table>";
echo "<br><p style='color:red;font-weight:bold'> BORRA ESTE ARCHIVO AHORA: /crm/usuarios_setup.php</p>";
echo "<p><a href='index.php'>→ IR AL CRM</a></p>";