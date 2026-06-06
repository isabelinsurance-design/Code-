<?php
/* ════════════════════════════════════════════════════════════════
   LUNA CONFIG — Medicare with Isabel
   ════════════════════════════════════════════════════════════════

   ░░░ INSTRUCCIONES PARA SAMI ░░░
   ─────────────────────────────────────────────────────────────
   SOLO tienes que llenar los 4 valores de abajo marcados con ★.
   No borres nada más. Guarda y sube a:
       public_html/website_5a1c69e7/luna/luna_config.php

   Los datos de MySQL están en: cPanel → MySQL® Databases
   (usuario, contraseña y nombre de la base de datos).
   ════════════════════════════════════════════════════════════════ */

// ════════════════════════════════════════════════════════════════
//  ★★★  LLENA ESTOS 4 VALORES  ★★★   (deja las comillas)
// ════════════════════════════════════════════════════════════════
$LUNA_DB_HOST = 'localhost';                 // ★ casi siempre es: localhost
$LUNA_DB_USER = 'PON_AQUI_EL_USUARIO';       // ★ usuario de MySQL
$LUNA_DB_PASS = 'PON_AQUI_LA_CONTRASENA';    // ★ contraseña de MySQL
$LUNA_DB_NAME = 'PON_AQUI_LA_BASE_DATOS';    // ★ nombre de la base de datos
// ════════════════════════════════════════════════════════════════


// ── Función de conexión (NO TOCAR) ─────────────────────────────
if (!function_exists('db')) {
    function db(): PDO {
        global $LUNA_DB_HOST, $LUNA_DB_USER, $LUNA_DB_PASS, $LUNA_DB_NAME;
        static $pdo = null;
        if ($pdo !== null) return $pdo;
        $pdo = new PDO(
            "mysql:host={$LUNA_DB_HOST};dbname={$LUNA_DB_NAME};charset=utf8mb4",
            $LUNA_DB_USER,
            $LUNA_DB_PASS,
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
// Si ya la tienes como variable de entorno en Bluehost, deja esta línea
// comentada. Si no, quita las // del inicio y pega tu key.
// define('ANTHROPIC_API_KEY', 'sk-ant-api03-...');

// ── LLAVE DE SERVICIO (Athena → LUNA)  ★ IMPORTANTE ★ ──────────
// Esta llave DEBE SER IDÉNTICA, carácter por carácter, a la que Athena
// manda en el header X-LUNA-Key (su variable de entorno en Railway).
//   • Si Athena YA tiene una llave funcionando: copia ESE mismo texto aquí.
//   • Si empiezas de cero: inventa un string largo y ponlo en LOS DOS lados.
// Si no coinciden, LUNA responde 403 "Llave de servicio inválida" en TODO.
define('LUNA_SERVICE_KEY', 'PON_AQUI_LA_MISMA_LLAVE_QUE_USA_ATHENA');

// ── OPCIONALES (dejar comentados hasta necesitarlos) ───────────
// define('LUNA_SERVICE_AGENT_ID', 1);          // id de Isabel en tabla usuarios
// define('LUNA_DEFAULT_TICKET_MEMBER', 0);     // id del miembro "OTRO"
// define('LUNA_SERVICE_DEFAULT_ASSIGNEE', 1);  // agente por defecto de tickets
// define('LUNA_SERVICE_ALLOW_COMMISSIONS', 1); // Athena lee comisiones (sensible)
