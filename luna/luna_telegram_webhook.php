<?php
/* ════════════════════════════════════════════════════════════════
   LUNA TELEGRAM WEBHOOK
   Medicare with Isabel

   Recibe callbacks de Telegram cuando Isabel tapa un botón
   en el briefing matutino. Ejecuta la acción en el CRM
   y confirma el resultado por Telegram.

   SETUP:
   1. Subir este archivo a public_html/luna/luna_telegram_webhook.php
   2. Registrar el webhook con Telegram (ver guía abajo)
   3. URL del webhook: https://withisabelfuentes.com/luna/luna_telegram_webhook.php

   REGISTRAR WEBHOOK (solo una vez, copiar y abrir en navegador):
   https://api.telegram.org/bot{TOKEN}/setWebhook?url=https://withisabelfuentes.com/luna/luna_telegram_webhook.php&secret_token={EL_MISMO_TELEGRAM_WEBHOOK_SECRET}

   ⚠️ SEGURIDAD: define TELEGRAM_WEBHOOK_SECRET en luna_config.php (un string
   largo que inventes) y regístralo con &secret_token= como arriba. Telegram
   lo mandará en cada request y aquí lo verificamos — sin eso, cualquiera
   que conozca la URL podría forjar botonazos.
════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/luna_config.php';  // ← config propio de LUNA

// 🔒 Verificación del secret del webhook (header oficial de Telegram).
// Si TELEGRAM_WEBHOOK_SECRET está definido, TODO request debe traerlo.
$__tg_secret = trim((string)(getenv('TELEGRAM_WEBHOOK_SECRET')
    ?: (defined('TELEGRAM_WEBHOOK_SECRET') ? TELEGRAM_WEBHOOK_SECRET : '')));
if ($__tg_secret !== '') {
    $__tg_recibido = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if (!hash_equals($__tg_secret, $__tg_recibido)) {
        http_response_code(403);
        exit('forbidden');
    }
}

define('TG_TOKEN', 'XXXX:YYYY');           // ← mismo token del BotFather
define('ISABEL_CHAT_ID', '0');             // ← chat_id de Isabel
define('SAMIA_CHAT_ID',  '0');             // ← chat_id de Samia (para referidos)

// Whitelist de chat IDs autorizados — bloqueamos todo lo demás
function isAuthorizedChat($incomingId) {
  $allowed = array_filter([ISABEL_CHAT_ID, SAMIA_CHAT_ID], fn($id) => $id !== '0' && !empty($id));
  // Si no hay nadie configurado todavía, NO aceptamos nada (seguro por default)
  if (empty($allowed)) return false;
  return in_array((string)$incomingId, array_map('strval', $allowed), true);
}

// ─── DB ─────────────────────────────────────────────────────
try {
  $pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch (Exception $e) {
  http_response_code(500);
  exit('DB error');
}

// Ensure luna_referrals exists (idempotent)
$pdo->exec("CREATE TABLE IF NOT EXISTS luna_referrals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  referrer_id INT NOT NULL,
  referred_id INT DEFAULT NULL,
  referred_nombre VARCHAR(120) DEFAULT NULL,
  referred_telefono VARCHAR(20) DEFAULT NULL,
  ask_sent_at DATETIME DEFAULT NULL,
  ask_sent_by INT DEFAULT NULL,
  referral_received_at DATETIME DEFAULT NULL,
  enrolled_at DATETIME DEFAULT NULL,
  status ENUM('ASKED','RECEIVED','ENROLLED','DECLINED') DEFAULT 'ASKED',
  notes TEXT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_referrer (referrer_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ─── PARSE TELEGRAM UPDATE ───────────────────────────────────
$raw = file_get_contents('php://input');
$update = json_decode($raw, true);

if (!$update) {
  http_response_code(200); // Always 200 to Telegram or it keeps retrying
  exit('no update');
}

// Telegram sends callback_query when a button is tapped
$callback = $update['callback_query'] ?? null;
$message  = $update['message'] ?? null;

// ─── HELPERS ────────────────────────────────────────────────
function tgReply($text, $chatId = null) {
  if (!$chatId) $chatId = ISABEL_CHAT_ID;
  $payload = [
    'chat_id'    => $chatId,
    'text'       => $text,
    'parse_mode' => 'Markdown',
    'disable_web_page_preview' => true,
  ];
  $ch = curl_init("https://api.telegram.org/bot" . TG_TOKEN . "/sendMessage");
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);
  curl_exec($ch);
  curl_close($ch);
}

function tgAnswerCallback($callbackId, $text = '') {
  // This dismisses the loading spinner on the button
  $ch = curl_init("https://api.telegram.org/bot" . TG_TOKEN . "/answerCallbackQuery");
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, ['callback_query_id' => $callbackId, 'text' => $text]);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 5);
  curl_exec($ch);
  curl_close($ch);
}

function tgEditMessage($chatId, $messageId, $newText) {
  $ch = curl_init("https://api.telegram.org/bot" . TG_TOKEN . "/editMessageText");
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'chat_id'    => $chatId,
    'message_id' => $messageId,
    'text'       => $newText,
    'parse_mode' => 'Markdown',
  ]);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);
  curl_exec($ch);
  curl_close($ch);
}

// ─── SECURITY: Solo chats autorizados (Isabel + Samia) ──────
$incomingChatId = $callback
  ? ($callback['message']['chat']['id'] ?? 0)
  : ($message['chat']['id'] ?? 0);

if (!isAuthorizedChat($incomingChatId)) {
  http_response_code(200); // 200 to Telegram — silent reject
  exit('unauthorized');
}

// ─── HANDLE INLINE BUTTON TAPS ───────────────────────────────
if ($callback) {
  $action     = $callback['data'];         // e.g. 'create_retencion_tickets'
  $callbackId = $callback['id'];
  $msgId      = $callback['message']['id'] ?? null;
  $chatId     = $callback['message']['chat']['id'] ?? ISABEL_CHAT_ID;

  // Acciones que SOLO Isabel puede ejecutar (no Samia)
  $adminOnlyActions = [
    'create_retencion_tickets', 'create_hotlead_tickets',
    'create_t65_reminder', 'list_alta_tickets'
  ];
  $isAdmin = (string)$chatId === (string)ISABEL_CHAT_ID;
  $isAdminAction = in_array($action, $adminOnlyActions, true)
                || strpos($action, 'skip_') === 0;

  if ($isAdminAction && !$isAdmin) {
    tgAnswerCallback($callbackId, '🔒 Solo Isabel puede aprobar esta acción');
    http_response_code(200);
    exit('forbidden');
  }

  // Acknowledge button tap immediately (removes spinner)
  tgAnswerCallback($callbackId, '⏳ Procesando...');

  switch ($action) {

    // ── Crear tickets de retención para Samia ──────────────
    case 'create_retencion_tickets':
      try {
        $members = $pdo->query("
          SELECT m.id, m.nombre, m.apellido, m.carrier,
                 DATEDIFF(CURDATE(), m.fecha_efectiva) AS dias_activo
          FROM miembros m WHERE m.estado='ACTIVO'
            AND (m.fecha_efectiva=DATE_SUB(CURDATE(),INTERVAL 7 DAY) OR
                 m.fecha_efectiva=DATE_SUB(CURDATE(),INTERVAL 30 DAY) OR
                 m.fecha_efectiva=DATE_SUB(CURDATE(),INTERVAL 60 DAY) OR
                 m.fecha_efectiva=DATE_SUB(CURDATE(),INTERVAL 90 DAY))
          LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($members)) {
          tgReply("ℹ️ No hay miembros de retención para hoy.", $chatId);
          break;
        }

        // Create ticket for each member
        $stmt = $pdo->prepare("
          INSERT INTO tickets (miembro_id, tipo, descripcion, prioridad, estado, asignado_a, created_at)
          VALUES (?, 'RETENCION', ?, 'MEDIA', 'ABIERTO', 10, NOW())
        ");
        // user_id 10 = Samia
        $created = [];
        foreach ($members as $m) {
          $desc = "Llamada de retención Day {$m['dias_activo']} — {$m['carrier']}";
          $stmt->execute([$m['id'], $desc]);
          $created[] = "• {$m['nombre']} {$m['apellido']} (Day {$m['dias_activo']})";
        }

        $list = implode("\n", $created);
        $count = count($created);

        // Edit original message to show it was done
        if ($msgId) {
          tgEditMessage($chatId, $msgId, "✅ *Retención — hecho*\n_{$count} tickets creados para Samia_");
        }

        tgReply("✅ *{$count} tickets de retención creados para Samia:*\n{$list}\n\n_Samia los verá en su panel._", $chatId);

      } catch (Exception $e) {
        tgReply("⚠️ Error creando tickets: " . $e->getMessage(), $chatId);
      }
      break;

    // ── Crear tickets de hot leads para Skarleth ───────────
    case 'create_hotlead_tickets':
      try {
        $leads = $pdo->query("
          SELECT m.id, m.nombre, m.apellido, m.telefono,
                 DATEDIFF(CURDATE(), COALESCE(
                   (SELECT MAX(DATE(a.fecha_hora)) FROM actividad a WHERE a.miembro_id=m.id),
                   m.created_at
                 )) AS dias_sin_contacto
          FROM miembros m WHERE m.estado='HOT LEAD'
          HAVING dias_sin_contacto >= 3
          ORDER BY dias_sin_contacto DESC LIMIT 15
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($leads)) {
          tgReply("ℹ️ No hay hot leads fríos en este momento.", $chatId);
          break;
        }

        $stmt = $pdo->prepare("
          INSERT INTO tickets (miembro_id, tipo, descripcion, prioridad, estado, asignado_a, created_at)
          VALUES (?, 'SEGUIMIENTO', ?, 'ALTA', 'ABIERTO', 7, NOW())
        ");
        // user_id 7 = Skarleth
        $created = [];
        foreach ($leads as $h) {
          $desc = "Hot lead sin contacto {$h['dias_sin_contacto']} días — llamar HOY";
          $stmt->execute([$h['id'], $desc]);
          $created[] = "• {$h['nombre']} {$h['apellido']} ({$h['dias_sin_contacto']}d) — {$h['telefono']}";
        }

        $list = implode("\n", $created);
        $count = count($created);

        if ($msgId) {
          tgEditMessage($chatId, $msgId, "✅ *Hot leads — hecho*\n_{$count} tickets creados para Skarleth_");
        }

        tgReply("✅ *{$count} tickets de seguimiento creados para Skarleth:*\n{$list}", $chatId);

      } catch (Exception $e) {
        tgReply("⚠️ Error: " . $e->getMessage(), $chatId);
      }
      break;

    // ── Crear recordatorio T65 para Isabel ─────────────────
    case 'create_t65_reminder':
      try {
        $t65s = $pdo->query("
          SELECT id, nombre, apellido, dob,
                 DATEDIFF(DATE_ADD(dob, INTERVAL 65 YEAR), CURDATE()) AS dias_para_65
          FROM miembros WHERE estado != 'ACTIVO'
            AND DATE_ADD(dob, INTERVAL 65 YEAR) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
          ORDER BY dias_para_65 ASC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($t65s)) {
          tgReply("ℹ️ No hay T65 urgentes en este momento.", $chatId);
          break;
        }

        $stmt = $pdo->prepare("
          INSERT INTO tickets (miembro_id, tipo, descripcion, prioridad, estado, asignado_a, created_at)
          VALUES (?, 'T65_URGENTE', ?, 'ALTA', 'ABIERTO', 6, NOW())
        ");
        // user_id 6 = Isabel
        $created = [];
        foreach ($t65s as $t) {
          $desc = "T65 URGENTE — cumple 65 en {$t['dias_para_65']} días — contactar esta semana";
          $stmt->execute([$t['id'], $desc]);
          $created[] = "• {$t['nombre']} {$t['apellido']} — {$t['dias_para_65']}d para 65";
        }

        $list = implode("\n", $created);
        $count = count($created);

        if ($msgId) {
          tgEditMessage($chatId, $msgId, "✅ *T65 — recordatorio creado*\n_{$count} ticket(s) asignados a Isabel_");
        }

        tgReply("✅ *{$count} recordatorio(s) T65 creados para ti (Isabel):*\n{$list}", $chatId);

      } catch (Exception $e) {
        tgReply("⚠️ Error: " . $e->getMessage(), $chatId);
      }
      break;

    // ── Ver lista de tickets ALTA ───────────────────────────
    case 'list_alta_tickets':
      try {
        $tickets = $pdo->query("
          SELECT t.id, t.descripcion, u.iniciales, CONCAT(m.nombre,' ',m.apellido) AS miembro,
                 DATEDIFF(CURDATE(), DATE(t.created_at)) AS dias_abierto
          FROM tickets t
          LEFT JOIN miembros m ON t.miembro_id=m.id
          LEFT JOIN usuarios u ON t.asignado_a=u.id
          WHERE t.estado!='CERRADO' AND t.prioridad='ALTA'
          ORDER BY t.created_at ASC LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($tickets)) {
          tgReply("ℹ️ No hay tickets ALTA abiertos en este momento.", $chatId);
          break;
        }

        $list = array_map(fn($t) =>
          "• #{$t['id']} {$t['miembro']} — " . mb_substr($t['descripcion'], 0, 50) . "... ({$t['dias_abierto']}d)",
          $tickets
        );

        tgReply("🚨 *Tickets ALTA abiertos:*\n" . implode("\n", $list) . "\n\n_Abre LUNA para cerrarlos._", $chatId);

      } catch (Exception $e) {
        tgReply("⚠️ Error: " . $e->getMessage(), $chatId);
      }
      break;

    // ── Skip actions (do nothing) ───────────────────────────
    case 'skip_retencion':
    case 'skip_hotleads':
    case 'skip_t65':
    case 'skip_tickets':
      if ($msgId) {
        tgEditMessage($chatId, $msgId, "⏭️ _Pospuesto — lo verás en el próximo briefing si sigue pendiente._");
      }
      break;

    // ── Referidos — Samia confirmó que envió el mensaje ─────
    default:
      if (strpos($action, 'referral_sent_') === 0) {
        $memberId = (int)str_replace('referral_sent_', '', $action);
        try {
          // Log in luna_referrals
          $pdo->prepare("
            INSERT INTO luna_referrals (referrer_id, ask_sent_at, ask_sent_by, status)
            VALUES (?, NOW(), 10, 'ASKED')
          ")->execute([$memberId]);

          // Fetch member name for confirmation
          $stmt = $pdo->prepare("SELECT nombre, apellido FROM miembros WHERE id=?");
          $stmt->execute([$memberId]);
          $m = $stmt->fetch(PDO::FETCH_ASSOC);
          $nombre = $m ? "{$m['nombre']} {$m['apellido']}" : "Miembro #{$memberId}";

          if ($msgId) {
            tgEditMessage($chatId, $msgId, "✅ *Referido pedido — {$nombre}*\n_Registrado. LUNA lo va a seguir._");
          }
        } catch (Exception $e) {
          tgReply("⚠️ No se pudo registrar: " . $e->getMessage(), $chatId);
        }
        break;
      }

      if (strpos($action, 'referral_skip_') === 0) {
        if ($msgId) {
          tgEditMessage($chatId, $msgId, "⏭️ _Saltado — aparecerá en la próxima semana._");
        }
        break;
      }

      tgReply("⚠️ Acción no reconocida: `{$action}`", $chatId);
  }
}

// ─── HANDLE TEXT MESSAGES (commands) ─────────────────────────
if ($message) {
  $text   = trim($message['text'] ?? '');
  $chatId = $message['chat']['id'] ?? ISABEL_CHAT_ID;

  switch ($text) {
    case '/start':
      tgReply("👋 ¡Hola Isabel! Soy *LUNA*, tu asistente de Medicare.\n\nCada mañana a las 7:00 AM te mando el briefing del día con botones de acción directos.\n\n_Comandos disponibles:_\n/pipeline — resumen del pipeline\n/alertas — alertas activas\n/ayuda — lista de comandos", $chatId);
      break;

    case '/pipeline':
      try {
        $rows = $pdo->query("SELECT estado, COUNT(*) AS total FROM miembros WHERE estado IS NOT NULL GROUP BY estado ORDER BY total DESC")->fetchAll();
        $lines = array_map(fn($r) => "• {$r['estado']}: {$r['total']}", $rows);
        tgReply("📊 *Pipeline actual:*\n" . implode("\n", $lines), $chatId);
      } catch (Exception $e) {
        tgReply("⚠️ Error: " . $e->getMessage(), $chatId);
      }
      break;

    case '/alertas':
      try {
        $alerts = [];
        // Hot leads fríos
        $frios = $pdo->query("
          SELECT COUNT(*) FROM miembros m WHERE m.estado='HOT LEAD'
          AND DATEDIFF(CURDATE(), COALESCE(
            (SELECT MAX(DATE(a.fecha_hora)) FROM actividad a WHERE a.miembro_id=m.id),
            m.created_at
          )) >= 3
        ")->fetchColumn();
        if ($frios > 0) $alerts[] = "🔥 {$frios} hot leads sin contactar +3 días";

        $ret = $pdo->query("SELECT COUNT(*) FROM miembros WHERE estado='ACTIVO' AND fecha_efectiva IN (DATE_SUB(CURDATE(),INTERVAL 7 DAY),DATE_SUB(CURDATE(),INTERVAL 30 DAY),DATE_SUB(CURDATE(),INTERVAL 60 DAY),DATE_SUB(CURDATE(),INTERVAL 90 DAY))")->fetchColumn();
        if ($ret > 0) $alerts[] = "📞 {$ret} miembro(s) de retención HOY";

        $soa = $pdo->query("SELECT COUNT(*) FROM miembros m WHERE m.estado IN('ACTIVO','PENDIENTE') AND (SELECT COUNT(*) FROM soa s WHERE s.miembro_id=m.id AND s.estado='FIRMADO')=0")->fetchColumn();
        if ($soa >= 3) $alerts[] = "⚠️ {$soa} SOAs sin firmar";

        tgReply(empty($alerts)
          ? "✅ *Sin alertas activas.* Todo en orden."
          : "⚡ *Alertas activas:*\n" . implode("\n", $alerts),
          $chatId
        );
      } catch (Exception $e) {
        tgReply("⚠️ Error: " . $e->getMessage(), $chatId);
      }
      break;

    case '/ayuda':
      tgReply("🤖 *LUNA — Comandos disponibles:*\n\n/pipeline — ver pipeline\n/alertas — ver alertas activas\n/ayuda — esta lista\n\n_El briefing automático llega cada mañana a las 7:00 AM con botones de acción._", $chatId);
      break;
  }
}

// Always respond 200 to Telegram
http_response_code(200);
echo 'ok';
