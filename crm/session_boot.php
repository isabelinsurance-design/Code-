<?php
/* ═══════════════════════════════════════════════════════════════════
 *  SESSION_BOOT.PHP — arranque de sesión endurecido (compartido)
 *  ─────────────────────────────────────────────────────────────────
 *  Se incluye ANTES de cualquier session_start()/auth() para que la
 *  cookie de sesión salga con HttpOnly + Secure + SameSite=Lax:
 *    · HttpOnly  → JS no puede leer la cookie (mitiga robo por XSS)
 *    · Secure    → solo viaja por HTTPS (auto-detectado, en local http funciona)
 *    · SameSite=Lax → otros sitios no pueden disparar POSTs con tu sesión (CSRF)
 *  Además genera el token CSRF de la sesión (defensa adicional para
 *  navegadores viejos; api.php lo verifica en cada POST).
 * ═══════════════════════════════════════════════════════════════════ */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/** Verifica el token CSRF en peticiones POST. Llamar tras validar sesión. */
function csrf_check_post(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return true;
    $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    return !empty($_SESSION['csrf_token']) && is_string($tok)
        && hash_equals($_SESSION['csrf_token'], $tok);
}
