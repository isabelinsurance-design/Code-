<?php
/* ════════════════════════════════════════════════════════════════
   GUARD DE ACCESO PARA CRONS — Medicare with Isabel
   ────────────────────────────────────────────────────────────────
   • Por CLI (así corren los cron jobs de Bluehost: `php archivo.php`)
     SIEMPRE pasa — no cambia nada de lo ya programado.
   • Por HTTP exige LUNA_CRON_TOKEN (env var o constante en
     luna_config.php). Se manda como header `X-Cron-Token: <token>`
     o `?cron_token=<token>` para pruebas manuales.
   • Si el token no está configurado, HTTP queda BLOQUEADO (seguro
     por default): antes cualquiera en internet podía disparar
     envíos masivos de email, llamadas a la IA y respaldos.
════════════════════════════════════════════════════════════════ */
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../luna_config.php';
    $__cron_esperado = trim((string)(getenv('LUNA_CRON_TOKEN')
        ?: (defined('LUNA_CRON_TOKEN') ? LUNA_CRON_TOKEN : '')));
    $__cron_recibido = trim((string)($_SERVER['HTTP_X_CRON_TOKEN']
        ?? $_GET['cron_token'] ?? $_POST['cron_token'] ?? ''));
    if ($__cron_esperado === '' || !hash_equals($__cron_esperado, $__cron_recibido)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'error' => 'Acceso denegado. Este cron corre por CLI; para dispararlo por HTTP define LUNA_CRON_TOKEN en luna_config.php y mándalo en el header X-Cron-Token (o ?cron_token=).',
        ]);
        exit;
    }
    unset($__cron_recibido);
}
