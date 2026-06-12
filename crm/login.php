<?php
// Producción: los errores se loguean, no se muestran (no filtrar rutas/SQL)
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once 'session_boot.php';
require_once 'config.php';
if (!empty($_SESSION['user'])) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = strtolower(trim($_POST['username'] ?? ''));
    $password = trim($_POST['password'] ?? '');
    try {
        $pdo_l = db();
        // ── Freno anti fuerza-bruta: máx 8 intentos fallidos por usuario/IP en 10 min ──
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        try {
            $pdo_l->exec("CREATE TABLE IF NOT EXISTS login_intentos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100), ip VARCHAR(45), exito TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_li_user (username, created_at), KEY idx_li_ip (ip, created_at))");
        } catch (Exception $e) {}
        $bloqueado = false;
        try {
            $st = $pdo_l->prepare("SELECT COUNT(*) FROM login_intentos
                WHERE (username=? OR ip=?) AND exito=0 AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
            $st->execute([$username, $ip]);
            $bloqueado = ((int)$st->fetchColumn()) >= 8;
        } catch (Exception $e) {}
        if ($bloqueado) {
            $error = 'DEMASIADOS INTENTOS — ESPERA 10 MINUTOS E INTENTA DE NUEVO.';
            $matched = false; $user = null;
        } else {
        $stmt = $pdo_l->prepare("SELECT * FROM usuarios WHERE username=? AND activo=1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        // Try exact, trimmed, and common mobile autocorrect variants
        $matched = false;
        if ($user) {
            $tries = [
                $password,
                strtolower($password),
                ucfirst(strtolower($password)),
                strtolower(substr($password,0,1)).substr($password,1),
            ];
            foreach ($tries as $try) {
                if (password_verify($try, $user['password_hash'])) {
                    $matched = true; break;
                }
            }
        }
        try {
            $pdo_l->prepare("INSERT INTO login_intentos (username, ip, exito) VALUES (?,?,?)")
                  ->execute([$username, $ip, $matched ? 1 : 0]);
        } catch (Exception $e) {}
        }
        if ($matched) {
            session_regenerate_id(true); // Previene fijación de sesión
            $_SESSION['user'] = [
                'id'       => $user['id'],
                'username' => $user['username'],
                'nombre'   => $user['nombre'],
                'rol'      => $user['rol'],
                'color'    => $user['color'],
                'iniciales'=> $user['iniciales'],
            ];
            header('Location: index.php'); exit;
        } elseif ($error === '') {
            $error = 'DATOS INCORRECTOS: Respeta mayúsculas y minúsculas.';
        }
    } catch (Exception $e) {
        $error = 'ERROR DE CONEXIÓN — VERIFICA CONFIG.PHP';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Medicare with Isabel — Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800;900&family=Great+Vibes&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;background:linear-gradient(150deg,#EBF4F9 0%,#D6EAF5 60%,#C2DBF0 100%);
  display:flex;align-items:center;justify-content:center;font-family:'DM Sans',sans-serif;padding:20px}
.card{background:rgba(255,255,255,.97);border-radius:22px;padding:44px 38px;width:400px;
  max-width:94vw;box-shadow:0 16px 48px rgba(27,74,107,.18);border:1px solid rgba(200,223,240,.9)}
.logo{text-align:center;margin-bottom:28px}
.butterfly-wrap{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:6px}
.brand-script{font-family:'Great Vibes',cursive;font-size:32px;color:#2D3142;line-height:1}
.brand-sub{font-size:9px;font-weight:700;color:#5B8DB8;letter-spacing:6px;text-transform:uppercase;margin-top:2px}
.divider{width:36px;height:2px;background:#2876A8;margin:10px auto 0;border-radius:2px}
.tagline{font-size:8px;font-weight:700;color:#7A90A4;letter-spacing:3px;text-transform:uppercase;margin-top:8px}
.error{background:#FDF0EE;color:#B83232;border:1px solid #EFA09A;border-radius:9px;
  padding:9px 13px;font-size:10px;font-weight:800;margin-bottom:14px;text-align:center;
  letter-spacing:1px;text-transform:uppercase}
label{display:block;font-size:8px;font-weight:900;color:#2876A8;text-transform:uppercase;
  letter-spacing:1.5px;margin-bottom:4px;margin-top:11px}
input[type=text],input[type=password]{
  width:100%;border:1.5px solid #C8DFF0;border-radius:9px;
  padding:11px 14px;font-size:15px;font-family:'DM Sans',sans-serif;outline:none;
  background:#EBF4F9;color:#1B3A5C;
  /* NO text-transform — let user type normally */
  text-transform:none !important;
  -webkit-text-security:none;
}
input[type=password]{ letter-spacing:2px; }
input:focus{border-color:#2876A8;background:#fff}
.btn{width:100%;background:linear-gradient(135deg,#1B4A6B,#2876A8);color:#fff;border:none;
  border-radius:11px;padding:13px;font-size:10px;font-weight:900;cursor:pointer;
  letter-spacing:3px;text-transform:uppercase;font-family:'DM Sans',sans-serif;margin-top:16px}
.btn:hover{opacity:.9}
.demo{margin-top:16px;background:#EBF4F9;border:1px solid #C8DFF0;border-radius:10px;
  padding:11px 14px;font-size:9px}
.demo strong{color:#1B4A6B;letter-spacing:2px;display:block;margin-bottom:5px;text-transform:uppercase}
.demo div{color:#7A90A4;margin-bottom:2px;letter-spacing:.5px}
.hint{font-size:8px;color:#7A90A4;margin-top:5px;letter-spacing:.5px}
</style>
</head>
<body>
<div class="card">
<div class="logo">
    <div class="logo-image-wrap" style="text-align: center; margin-bottom: 10px;">
        <img src="https://withisabelfuentes.com/wp-content/uploads/2026/04/logoMWI.png" 
             alt="Medicare con Isabel" 
             style="max-width: 220px; height: auto; display: block; margin: 0 auto;">
    </div>
    <div class="divider"></div>
    <div class="tagline">PORTAL DEL EQUIPO</div>
</div>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="" id="login-form">
    <label>USUARIO</label>
    <input
      type="text"
      name="username"
      id="username"
      placeholder="isabel"
      required
      autocomplete="username"
      autocapitalize="none"
      autocorrect="off"
      autocomplete="off"
      spellcheck="false"
    >
    <div class="hint">Escribe en minúsculas</div>

    <label>CONTRASEÑA</label>
    <input
      type="password"
      name="password"
      id="password"
      placeholder="••••••••"
      required
      autocomplete="current-password"
      autocapitalize="none"
      autocorrect="off"
      spellcheck="false"
    >
    <div class="hint">Ejemplo:  respeta mayúsculas y números</div>

    <button type="submit" class="btn">ENTRAR →</button>
  </form>
</div>
<script>
// Force lowercase on username as user types
document.getElementById('username').addEventListener('input', function() {
  var pos = this.selectionStart;
  this.value = this.value.toLowerCase();
  this.setSelectionRange(pos, pos);
});

// Prevent autocapitalize on password field
document.getElementById('password').addEventListener('focus', function() {
  this.setAttribute('autocapitalize', 'none');
});
</script>
</body>
</html>
