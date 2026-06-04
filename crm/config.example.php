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

// ─── PROMPTS DE IA (opcional) ─────────────────────────────────────
// Si tienes el archivo de prompts, se carga aquí. Si no existe, la app sigue.
if (file_exists(__DIR__ . '/prompts.php')) {
    require_once __DIR__ . '/prompts.php';
}
