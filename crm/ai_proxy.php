<?php
/**
 * ai_proxy.php — Proxy seguro hacia Claude (Anthropic)
 * ─────────────────────────────────────────────────────────────
 * El marketing (ARIA) y cualquier herramienta del navegador llaman AQUÍ
 * en vez de a api.anthropic.com directamente. Así la API Key vive en el
 * servidor (config.php) y NUNCA viaja al navegador.
 *
 * Requiere sesión del CRM. Reenvía el cuerpo JSON tal cual a Anthropic.
 */
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY
    || strpos(ANTHROPIC_API_KEY, 'PON_TU_KEY') !== false) {
    http_response_code(500);
    echo json_encode(['error' => 'API key no configurada en el servidor (config.php)']);
    exit;
}

$body = file_get_contents('php://input');
if (!$body) { http_response_code(400); echo json_encode(['error' => 'Cuerpo vacío']); exit; }

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
    ],
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($res === false) {
    http_response_code(502);
    echo json_encode(['error' => 'No se pudo contactar a Anthropic', 'detail' => $err]);
    exit;
}

http_response_code($code ?: 500);
echo $res;
