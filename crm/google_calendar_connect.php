<?php
/* Redirige a Isabel (o cualquier empleado con sesión abierta) a la
 * pantalla de Google para darle permiso al CRM de escribir en el
 * calendario — es UN solo calendario compartido para todas las citas del
 * CRM, y cualquier empleado puede conectarlo/desconectarlo. */
require_once 'session_boot.php';
require_once 'config.php';
require_once 'lib_google_calendar.php';

$user = auth();
if (!google_calendar_configurado()) {
    http_response_code(500);
    echo 'Google Calendar todavía no está configurado en config.php (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET).';
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;
header('Location: ' . google_calendar_auth_url($state));
exit;
