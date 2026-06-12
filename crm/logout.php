<?php
require_once 'session_boot.php';
$_SESSION = [];
// Invalida también la cookie del navegador, no solo el archivo de sesión
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: login.php');
exit;
