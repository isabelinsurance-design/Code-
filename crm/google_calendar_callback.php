<?php
/* Google manda aquí de vuelta a Isabel después de que le da permiso (o lo
 * rechaza) — se guarda el token y se le regresa a la pestaña de Citas con
 * un mensaje de éxito/error. */
require_once 'session_boot.php';
require_once 'config.php';
require_once 'lib_google_calendar.php';

$user = auth();
$pdo  = db();

$errorGoogle = $_GET['error'] ?? null;
if ($errorGoogle) {
    header('Location: index.php?google_cal=error&msg=' . urlencode('Google no dio el permiso: ' . $errorGoogle));
    exit;
}

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
if ($code === '' || $state === '' || empty($_SESSION['google_oauth_state']) || !hash_equals($_SESSION['google_oauth_state'], $state)) {
    header('Location: index.php?google_cal=error&msg=' . urlencode('La conexión expiró o no es válida — intenta de nuevo.'));
    exit;
}
unset($_SESSION['google_oauth_state']);

$r = google_calendar_exchange_code($pdo, $code, $user['id']);
if ($r['ok']) {
    header('Location: index.php?google_cal=ok&email=' . urlencode($r['email'] ?? ''));
} else {
    header('Location: index.php?google_cal=error&msg=' . urlencode($r['error'] ?? 'No se pudo conectar'));
}
exit;
