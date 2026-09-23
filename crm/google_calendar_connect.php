<?php
/* Redirige a Isabel a la pantalla de Google para darle permiso al CRM de
 * escribir en su calendario. Solo un admin puede iniciar la conexión —
 * es UN solo calendario compartido para todas las citas del CRM. */
require_once 'session_boot.php';
require_once 'config.php';
require_once 'lib_google_calendar.php';

$user = auth();
if (!isAdmin()) { http_response_code(403); echo 'Solo un administrador puede conectar Google Calendar.'; exit; }
if (!google_calendar_configurado()) {
    http_response_code(500);
    echo 'Google Calendar todavía no está configurado en config.php (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET).';
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;
header('Location: ' . google_calendar_auth_url($state));
exit;
