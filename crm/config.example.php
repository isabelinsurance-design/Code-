<?php
/* ═══════════════════════════════════════════════════════════════════
 *  CONFIG.EXAMPLE.PHP  —  Medicare with Isabel CRM
 *  ─────────────────────────────────────────────────────────────────
 *  Esta es la PLANTILLA (sí se sube a GitHub, NO tiene secretos).
 *
 *  PARA USARLA:
 *    1. Copia este archivo y renómbralo a  config.php
 *    2. Rellena tus valores reales (o déjalos en blanco si usas
 *       variables de entorno con Docker / Bluehost).
 *  El archivo config.php REAL nunca se sube (está en .gitignore).
 * ═══════════════════════════════════════════════════════════════════ */

ini_set('display_errors', 0);        // En producción: 0. En local puedes poner 1.
error_reporting(E_ALL);

// ─── BASE DE DATOS ────────────────────────────────────────────────
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'TU_USUARIO_MYSQL');
define('DB_PASS', getenv('DB_PASS') ?: 'TU_PASSWORD_MYSQL');
define('DB_NAME', getenv('DB_NAME') ?: 'TU_BASE_DE_DATOS');

// ─── SECRETOS DE LA APP ───────────────────────────────────────────
define('FINANCE_PASS',      getenv('FINANCE_PASS')      ?: 'CAMBIA_ESTA_CLAVE');   // portal financiero
define('ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: 'sk-ant-PON_TU_KEY');   // Isabel AI
define('WEBHOOK_SECRET_FB', getenv('WEBHOOK_SECRET_FB') ?: 'CAMBIA_ESTE_SECRETO'); // leads de Facebook

// ─── TWILIO (SMS) — opcional ────────────────────────────────────────
// Sin esto configurado, el CRM sigue funcionando normal — solo no se
// podrán enviar SMS (si llegan SMS entrantes por el webhook, de todos
// modos se guardan; el sms_webhook.php no depende de estas 3).
define('TWILIO_SID',         getenv('TWILIO_SID')         ?: '');  // Account SID de Twilio
define('TWILIO_AUTH_TOKEN',  getenv('TWILIO_AUTH_TOKEN')  ?: '');  // Auth Token de Twilio (también valida el webhook entrante)
define('TWILIO_FROM_NUMBER', getenv('TWILIO_FROM_NUMBER') ?: '');  // Tu número de Twilio, formato +1XXXXXXXXXX

// ─── AVISOS EN VIVO (opcional) ─────────────────────────────────────
// Servidor ws-relay (Railway) que avisa al instante a los navegadores
// conectados cuando alguien guarda algo. Si se dejan vacíos, el CRM sigue
// funcionando normal (con el refresco automático periódico de siempre).
define('RELAY_URL',    getenv('RELAY_URL')    ?: '');  // ej: https://tu-proyecto.up.railway.app
define('RELAY_SECRET', getenv('RELAY_SECRET') ?: '');  // la misma clave que pusiste en Railway

// ─── PARÁMETROS DE NEGOCIO ────────────────────────────────────────
define('BONO_MONTO',     250);   // Incentivo por póliza
define('DIAS_RETENCION', 90);    // Días activo para consolidar bono
define('CRM_NAME',       'Medicare with Isabel');
define('CRM_WEB',        'withisabelfuentes.com');

date_default_timezone_set('America/Los_Angeles');

// ─── CONEXIÓN PDO ─────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

// ─── SESIÓN / AUTENTICACIÓN ───────────────────────────────────────
function auth(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user'])) { header('Location: login.php'); exit; }
    return $_SESSION['user'];
}
function isAdmin(): bool { return ($_SESSION['user']['rol'] ?? '') === 'admin'; }
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function today(): string { return date('Y-m-d'); }
function now():   string { return date('Y-m-d H:i:s'); }

// ─── NOTIFICACIONES (Telegram / Email / Panel) ────────────────────
function notificarAIsabel(string $mensaje, ?PDO $pdo = null): void {
    if (defined('TELEGRAM_TOKEN') && defined('TELEGRAM_CHAT_ID')) {
        $url = 'https://api.telegram.org/bot'.TELEGRAM_TOKEN.'/sendMessage?'
             . http_build_query(['chat_id'=>TELEGRAM_CHAT_ID,'text'=>$mensaje,'parse_mode'=>'Markdown']);
        @file_get_contents($url);
    }
    if (defined('EMAIL_ISABEL')) {
        @mail(EMAIL_ISABEL, 'Notificación CRM Medicare with Isabel', $mensaje);
    }
    if ($pdo) {
        try {
            $admin_id = $pdo->query("SELECT id FROM usuarios WHERE rol='admin' LIMIT 1")->fetchColumn() ?: 1;
            $pdo->prepare("INSERT INTO notificaciones (user_id,tipo,mensaje) VALUES (?,'SISTEMA',?)")
                ->execute([$admin_id, $mensaje]);
        } catch (Exception $e) {}
    }
}

// ─── AVISAR AL RELAY (avisos en vivo) ──────────────────────────────
// Se llama después de guardar algo importante (ticket, miembro, cita,
// gasto, checklist...) para que el relay le avise AL INSTANTE a los demás
// navegadores conectados. Nunca manda datos reales — solo el nombre de la
// pestaña que cambió. Tiene un timeout MUY corto (y doblemente puesto, en
// milisegundos y en segundos, por si el servidor no respeta la versión en
// milisegundos) para que en el peor de los casos nunca le agregue más de
// ~1 segundo a la acción del usuario. Si falla o tarda, no pasa nada — el
// refresco automático periódico sigue de respaldo.
function notify_relay(string $tab = ''): void {
    if (!RELAY_URL || !RELAY_SECRET) return;
    if (!function_exists('curl_init')) return;
    $ch = curl_init(rtrim(RELAY_URL, '/') . '/broadcast');
    curl_setopt_array($ch, [
        CURLOPT_POST              => true,
        CURLOPT_POSTFIELDS        => json_encode(['tab' => $tab]),
        CURLOPT_HTTPHEADER        => ['Content-Type: application/json', 'X-Relay-Secret: ' . RELAY_SECRET],
        CURLOPT_TIMEOUT_MS        => 800,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
        CURLOPT_TIMEOUT           => 1,  // respaldo en segundos por si el host ignora los _MS
        CURLOPT_CONNECTTIMEOUT    => 1,  // (algunos cPanel/libcurl viejos no los soportan)
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_SSL_VERIFYPEER    => true,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

// ─── RESPONDER PRIMERO, AVISAR DESPUÉS ──────────────────────────────
// Envía la respuesta JSON al navegador (y, si el hosting lo soporta,
// CIERRA esa conexión ahí mismo con fastcgi_finish_request) ANTES de
// avisarle al relay. Así el usuario que guarda NUNCA espera por Railway —
// su propia acción se siente instantánea pase lo que pase con el relay.
function jsonOkNotify($data, string $tab): void {
    echo json_encode(['ok' => true, 'data' => $data]);
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
    notify_relay($tab);
    exit;
}

// ─── PROMPTS DE IA (opcional) ─────────────────────────────────────
// Si tienes el archivo de prompts, se carga aquí. Si no existe, la app sigue.
if (file_exists(__DIR__ . '/prompts.php')) {
    require_once __DIR__ . '/prompts.php';
}
