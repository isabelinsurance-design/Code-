<?php
/* ════════════════════════════════════════════════════════════════
   LUNA CONFIG — Medicare with Isabel
   ════════════════════════════════════════════════════════════════

   INSTRUCCIONES PARA SAMI (Bluehost):
   ─────────────────────────────────────────────────────────────
   1. Copia este archivo a:  public_html/website_5a1c69e7/luna/luna_config.php
   2. Rellena los 4 campos marcados con  ← LLENAR  (DB + API key)
   3. Guarda. LUNA ya debería arrancar.

   Los datos de la base de datos los encuentras en Bluehost →
   cPanel → MySQL® Databases (usuario, contraseña, nombre de BD).
   La LUNA_SERVICE_KEY la inventas tú: cualquier string largo y
   aleatorio (ej: genera uno en passwordsgenerator.net).
   ════════════════════════════════════════════════════════════════ */

// ── BASE DE DATOS ──────────────────────────────────────────────
define('LUNA_DB_HOST', 'localhost');
define('LUNA_DB_USER', 'LLENAR_usuario_mysql');      // ← LLENAR
define('LUNA_DB_PASS', 'LLENAR_password_mysql');     // ← LLENAR
define('LUNA_DB_NAME', 'LLENAR_nombre_base_datos');  // ← LLENAR

// ── FUNCIÓN DE CONEXIÓN ────────────────────────────────────────
if (!function_exists('db')) {
    function db(): PDO {
        static $pdo = null;
        if ($pdo !== null) return $pdo;
        $pdo = new PDO(
            'mysql:host=' . LUNA_DB_HOST
                . ';dbname=' . LUNA_DB_NAME
                . ';charset=utf8mb4',
            LUNA_DB_USER,
            LUNA_DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        return $pdo;
    }
}

// ── API KEY DE ANTHROPIC (Claude / Athena) ─────────────────────
// Puedes definirla aquí O como variable de entorno en Bluehost.
// Si ya la tienes como env var, comenta la línea define().
if (!defined('ANTHROPIC_API_KEY')) {
    define('ANTHROPIC_API_KEY', 'LLENAR_sk-ant-api03-...');  // ← LLENAR
}

// ── LLAVE DE SERVICIO (Athena → LUNA, máquina-a-máquina) ──────
// String largo y aleatorio. Athena la manda en el header X-LUNA-Key.
// Genérala en: https://www.random.org/strings/ o similar.
if (!defined('LUNA_SERVICE_KEY')) {
    define('LUNA_SERVICE_KEY', 'LLENAR_llave_secreta_larga');  // ← LLENAR
}

// ── OPCIONALES (dejar comentados hasta necesitarlos) ───────────
// ID del usuario de Isabel en la tabla `usuarios` (default: 1)
// define('LUNA_SERVICE_AGENT_ID', 1);

// ID del miembro "OTRO" en `miembros` para tickets sin cliente
// define('LUNA_DEFAULT_TICKET_MEMBER', 0);

// ID del agente por defecto para tickets sin responsable
// define('LUNA_SERVICE_DEFAULT_ASSIGNEE', 1);

// Permitir que Athena lea comisiones (sensible — off por defecto)
// define('LUNA_SERVICE_ALLOW_COMMISSIONS', 1);
