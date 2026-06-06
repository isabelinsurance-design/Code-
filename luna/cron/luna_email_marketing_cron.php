<?php
/* ════════════════════════════════════════════════════════════════
   LUNA EMAIL MARKETING — Comunicaciones a Miembros
   Medicare with Isabel

   TRES CRONS:
   - Cumpleaños:     0 8 * * *     (diario 8am — detecta cumpleaños HOY)
   - Newsletter:     0 9 15 * *    (día 15 de cada mes)
   - Pre-AEP:        0 9 1 9 *     (1 septiembre — recordatorio AEP)

   CONFIGURA un cron por cada tipo, o uno que detecta cuál aplica.
════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../luna_config.php';  // ← config propio de LUNA
require_once __DIR__ . '/../luna_ai.php'; // cerebro IA (degradación elegante si no hay key)

$CONFIG = [
  'from_name'    => 'Isabel Fuentes · Medicare',
  'from_email'   => 'isabel@withisabelfuentes.com',
  'reply_to'     => 'info@withisabelfuentes.com',
  'timezone'     => 'America/Los_Angeles',
  'log_file'     => __DIR__ . '/luna_email_marketing_log.txt',
  'agency_phone' => '(310) 270-0626',
  'agency_url'   => 'https://withisabelfuentes.com',
  'cta_url'      => 'https://withisabelfuentes.com',
  // Mode: 'birthday' | 'newsletter' | 'aep' | 'auto' (detects based on date)
  'mode'         => $_GET['mode'] ?? 'auto',
];

date_default_timezone_set($CONFIG['timezone']);

function logMarketing($msg, $config) {
  @file_put_contents($config['log_file'], '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

// ─── DB ───────────────────────────────────────────────────
try {
  $pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch (Exception $e) {
  logMarketing("FATAL: DB - " . $e->getMessage(), $CONFIG);
  exit(1);
}

// ─── EMAIL SENDER HELPER ─────────────────────────────────
function sendMemberEmail($to, $toName, $subject, $htmlBody, $config) {
  $headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    'From: '     . $config['from_name'] . ' <' . $config['from_email'] . '>',
    'Reply-To: ' . $config['reply_to'],
    'X-Mailer: LUNA-Marketing',
  ];
  $personalized = str_replace(
    ['{{nombre}}', '{{phone}}', '{{url}}'],
    [$toName, $config['agency_phone'], $config['cta_url']],
    $htmlBody
  );
  return @mail($to, $subject, $personalized, implode("\r\n", $headers));
}

function emailWrapper($title, $preheader, $body, $config) {
  return '<!DOCTYPE html><html><head><meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>' . htmlspecialchars($title) . '</title>
  </head>
  <body style="margin:0;padding:0;background:#f5f5f7;font-family:-apple-system,BlinkMacSystemFont,sans-serif;">
  <div style="display:none;max-height:0;overflow:hidden;">' . htmlspecialchars($preheader) . '</div>
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center" style="padding:24px 16px;">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <!-- Header -->
        <tr><td style="background:linear-gradient(135deg,#1a56ff,#0097a7);padding:28px 32px;">
          <div style="color:#fff;font-size:11px;font-weight:700;letter-spacing:2px;opacity:.8;text-transform:uppercase;margin-bottom:6px;">Medicare with Isabel</div>
          <div style="color:#fff;font-size:22px;font-weight:700;font-family:Georgia,serif;">' . $title . '</div>
        </td></tr>
        <!-- Body -->
        <tr><td style="padding:28px 32px;font-size:14px;line-height:1.8;color:#3a3a3c;">' . $body . '</td></tr>
        <!-- Footer -->
        <tr><td style="background:#f5f5f7;padding:20px 32px;text-align:center;font-size:11px;color:#8e8e93;border-top:1px solid #e5e5ea;">
          <strong style="color:#52606d;">Isabel Fuentes · Agente de Medicare Licenciada</strong><br>
          ' . $config['agency_phone'] . ' · <a href="' . $config['agency_url'] . '" style="color:#1a56ff;">' . $config['agency_url'] . '</a><br><br>
          <em>Está recibiendo este email porque es cliente de Medicare with Isabel. Para dejar de recibir comunicaciones, contáctenos.</em>
          <br><br>
          <em>Not connected with or endorsed by the U.S. government or the federal Medicare program.</em>
        </td></tr>
      </table>
    </td></tr>
  </table>
  </body></html>';
}

// ─── SALUDO DE CUMPLEAÑOS PERSONALIZADO (IA, con fallback) ──
// Personaliza SOLO el saludo (cálido, sin afirmaciones de cobertura). El
// resto del email (CTA, firma, disclaimers CMS) sigue siendo determinista.
function smartBirthdayGreeting($member) {
  if (!lunaAIEnabled()) return null;

  $system =
    "Eres Isabel Fuentes, agente licenciada de Medicare en California, escribiendo "
  . "un saludo de cumpleaños cálido y personal por email a un cliente. REGLAS: español "
  . "cercano, trato de usted; 2 o 3 párrafos cortos, cada uno envuelto en <p>...</p>; "
  . "agradece su confianza y su relación. CUMPLIMIENTO CMS (obligatorio): NO menciones "
  . "precios, primas, beneficios específicos, ni hagas promesas de cobertura; no "
  . "promociones planes. Puedes nombrar el carrier solo para decir que están para "
  . "ayudar con su plan. NUNCA inventes datos personales. Devuelve SOLO los <p>, sin "
  . "encabezado, sin firma y sin el botón de llamada (eso se agrega aparte).";

  $user =
    "Cliente: {$member['nombre']} {$member['apellido']}\n"
  . "Carrier: " . ($member['carrier'] ?: 'su plan') . "\n"
  . "Cumple años hoy" . (!empty($member['edad']) ? " ({$member['edad']} años)" : '') . ".\n\n"
  . "Escribe el saludo de cumpleaños.";

  return lunaAI($system, $user, 500);
}

// ════════════════════════════════════════════════════════════
// MODO 1: CUMPLEAÑOS
// ════════════════════════════════════════════════════════════
function runBirthdayCampaign($pdo, $config) {
  $hoy = date('m-d');

  $members = $pdo->query("
    SELECT m.nombre, m.apellido, m.email, m.carrier, m.plan_nombre,
           YEAR(CURDATE()) - YEAR(m.dob) AS edad
    FROM miembros m
    WHERE m.estado = 'ACTIVO'
      AND m.email IS NOT NULL AND m.email != ''
      AND DATE_FORMAT(m.dob, '%m-%d') = '{$hoy}'
  ")->fetchAll(PDO::FETCH_ASSOC);

  $sent = 0;
  $aiCount = 0;
  foreach ($members as $m) {
    $nombre = $m['nombre'];

    // Saludo personalizado por IA; si no hay IA, plantilla determinista de siempre.
    $greeting = smartBirthdayGreeting($m);
    if ($greeting === null) {
      $greeting = "
      <p>Hola <strong>{$nombre}</strong>,</p>
      <p>En el equipo de Medicare with Isabel le deseamos un muy <strong>¡Feliz Cumpleaños! 🎂</strong></p>
      <p>Gracias por permitirnos ser parte de su cuidado de salud. Cuidar de usted es nuestro compromiso.</p>
      <p>Si tiene alguna pregunta sobre su plan <strong>{$m['carrier']}</strong>, o si hay algo en lo que podamos ayudarle, estamos a sus órdenes.</p>";
    } else {
      $aiCount++;
    }

    // El CTA y la firma se mantienen deterministas (compliance + consistencia).
    $body = $greeting . "
      <div style='background:#f0f9ff;border-left:4px solid #1a56ff;padding:16px 20px;border-radius:0 8px 8px 0;margin:20px 0;'>
        <strong>¿Sabía que puede revisar sus beneficios en cualquier momento?</strong><br>
        Llámenos al <a href='tel:{$config['agency_phone']}' style='color:#1a56ff;'>{$config['agency_phone']}</a> y con gusto lo ayudamos.
      </div>
      <p>Con mucho cariño,<br><strong>Isabel Fuentes</strong><br><em>Su Agente de Medicare</em></p>
    ";

    $html = emailWrapper(
      "¡Feliz Cumpleaños, {$nombre}! 🎂",
      "El equipo de Medicare with Isabel le desea un muy feliz cumpleaños",
      $body, $config
    );

    $ok = sendMemberEmail($m['email'], $nombre, "🎂 Feliz Cumpleaños de parte de Isabel — Medicare with Isabel", $html, $config);
    if ($ok) $sent++;
  }

  logMarketing("Birthday: sent={$sent}, total_candidates=" . count($members) . ", ai_personalized={$aiCount}", $config);
  return ['mode' => 'birthday', 'sent' => $sent, 'candidates' => count($members), 'ai_personalized' => $aiCount];
}

// ════════════════════════════════════════════════════════════
// MODO 2: NEWSLETTER MENSUAL
// ════════════════════════════════════════════════════════════
function runNewsletterCampaign($pdo, $config) {
  $mes = ['enero','febrero','marzo','abril','mayo','junio',
          'julio','agosto','septiembre','octubre','noviembre','diciembre'][(int)date('n') - 1];
  $anio = date('Y');

  $members = $pdo->query("
    SELECT m.nombre, m.apellido, m.email, m.carrier, m.plan_nombre
    FROM miembros m
    WHERE m.estado = 'ACTIVO'
      AND m.email IS NOT NULL AND m.email != ''
    ORDER BY m.apellido
  ")->fetchAll(PDO::FETCH_ASSOC);

  // Tip del mes — rotates based on month number
  $tips = [
    1  => ['title' => '¿Ya revisó sus beneficios del Año Nuevo?', 'body' => 'Enero es el momento perfecto para revisar todos sus beneficios del plan. Si hubo cambios en su red de médicos o en sus medicamentos, es importante saberlo ahora.'],
    2  => ['title' => 'Beneficios preventivos que no debe perder', 'body' => 'Medicare cubre muchos servicios preventivos sin costo: exámenes de la vista, audiología, vacunas, y más. ¡Aproveche todos sus beneficios!'],
    3  => ['title' => 'Primavera = revisión de medicamentos', 'body' => 'Si sus medicamentos cambiaron recientemente, verifica que siguen cubiertos en su formulario. Es un buen momento para revisar con su médico.'],
    4  => ['title' => 'Cuide su corazón: beneficios cardíacos en Medicare', 'body' => 'Medicare cubre chequeos cardiovasculares preventivos. Pregunte a su médico sobre los exámenes que tiene disponibles sin costo adicional.'],
    5  => ['title' => '¿Tiene familiares que cumplan 65 este año?', 'body' => 'Si conoce a alguien próximo a cumplir 65, cuéntele sobre Medicare. Con gusto los ayudamos a entender sus opciones antes de que venza su ventana de inscripción.'],
    6  => ['title' => 'Beneficios de verano — dental y visión', 'body' => 'Muchos planes de Medicare Advantage incluyen cobertura dental y de visión. Ahora es buen momento para usar esos beneficios antes de que terminen.'],
    7  => ['title' => 'Viajes de verano y su cobertura de emergencias', 'body' => 'Si viaja este verano, sepa que tiene cobertura de emergencias en todo el país. Guarde siempre su tarjeta del plan y el número de urgencias.'],
    8  => ['title' => 'Preparación para el AEP — octubre ya se acerca', 'body' => 'El período de inscripción anual (AEP) comienza el 15 de octubre. En agosto empezamos a preparar las revisiones para que esté listo cuando llegue el momento.'],
    9  => ['title' => '🗓️ El AEP comienza el 15 de octubre', 'body' => 'A partir del 15 de octubre puede cambiar, agregar, o eliminar cobertura Medicare. Este año revisaremos juntos si su plan sigue siendo el mejor para usted.'],
    10 => ['title' => '⏰ AEP en curso — ¿revisamos su plan?', 'body' => 'El período de inscripción está abierto hasta el 7 de diciembre. Si no ha revisado su plan este año, contáctenos para asegurarse de tener la mejor cobertura para 2025.'],
    11 => ['title' => 'Última oportunidad para el AEP — cierra el 7 de diciembre', 'body' => 'El período de inscripción anual cierra pronto. Si no ha actuado aún, llámenos esta semana para revisar sus opciones antes de que sea tarde.'],
    12 => ['title' => 'Felices Fiestas y sus beneficios de salud en enero', 'body' => 'Gracias por confiar en nuestro equipo durante este año. En enero los cambios de cobertura entran en vigor — si tiene preguntas, estamos aquí para usted.'],
  ];

  $tip = $tips[(int)date('n')] ?? $tips[1];

  $sent = 0;
  foreach ($members as $m) {
    $nombre = $m['nombre'];
    $body = "
      <p>Hola <strong>{$nombre}</strong>,</p>
      <p>Le envío el boletín de <strong>{$mes} {$anio}</strong> de Medicare with Isabel. Este mes quiero compartirle algo importante sobre su cobertura de salud.</p>

      <div style='background:linear-gradient(135deg,rgba(26,86,255,.04),rgba(0,151,167,.04));border:1px solid rgba(26,86,255,.12);border-radius:10px;padding:20px 24px;margin:20px 0;'>
        <div style='font-size:16px;font-weight:700;color:#1a56ff;margin-bottom:8px;'>{$tip['title']}</div>
        <p style='margin:0;color:#3a3a3c;'>{$tip['body']}</p>
      </div>

      <p>Recuerde que siempre puede contactarme directamente para cualquier pregunta sobre su plan <strong>{$m['carrier']}</strong> o para revisar sus beneficios.</p>

      <div style='text-align:center;margin:28px 0;'>
        <a href='tel:{$config['agency_phone']}' style='background:#1a56ff;color:#fff;padding:13px 28px;border-radius:10px;text-decoration:none;font-weight:700;font-size:14px;display:inline-block;'>
          📞 Llamarme: {$config['agency_phone']}
        </a>
      </div>

      <p>Con mucho gusto,<br><strong>Isabel Fuentes</strong><br><em>Su Agente de Medicare · {$config['agency_phone']}</em></p>
    ";

    $html = emailWrapper(
      "Medicare con Isabel — {$mes} {$anio}",
      $tip['title'],
      $body, $config
    );

    $ok = sendMemberEmail(
      $m['email'], $nombre,
      "📬 Boletín Medicare — {$mes} {$anio} · Isabel Fuentes",
      $html, $config
    );
    if ($ok) $sent++;
  }

  logMarketing("Newsletter {$mes} {$anio}: sent={$sent}, total=" . count($members), $config);
  return ['mode' => 'newsletter', 'sent' => $sent, 'total' => count($members)];
}

// ════════════════════════════════════════════════════════════
// MODO 3: PRE-AEP (septiembre)
// ════════════════════════════════════════════════════════════
function runAEPCampaign($pdo, $config) {
  $anio = (int)date('Y') + 1;

  $members = $pdo->query("
    SELECT m.nombre, m.apellido, m.email, m.carrier, m.plan_nombre,
           DATE_FORMAT(m.fecha_efectiva,'%d %b %Y') AS activo_desde
    FROM miembros m
    WHERE m.estado = 'ACTIVO'
      AND m.email IS NOT NULL AND m.email != ''
    ORDER BY m.apellido
  ")->fetchAll(PDO::FETCH_ASSOC);

  $sent = 0;
  foreach ($members as $m) {
    $nombre = $m['nombre'];
    $body = "
      <p>Hola <strong>{$nombre}</strong>,</p>
      <p>Le escribo porque se acerca una fecha muy importante: el <strong>Período de Inscripción Anual de Medicare (AEP)</strong>, que comienza el <strong>15 de octubre</strong>.</p>

      <div style='background:#fff8e7;border-left:4px solid #d97706;padding:16px 20px;border-radius:0 8px 8px 0;margin:20px 0;'>
        <strong>📅 Fechas importantes del AEP {$anio}:</strong><br>
        • <strong>15 de octubre:</strong> Comienza el período de inscripción<br>
        • <strong>7 de diciembre:</strong> Cierra el período — NO hay extensiones<br>
        • <strong>1 de enero {$anio}:</strong> Entran en vigor los cambios
      </div>

      <p>Su plan actual es <strong>{$m['carrier']}</strong>. Antes de que empiece el AEP, me gustaría revisar juntos:</p>
      <ul style='margin:10px 0;padding-left:20px;line-height:2;'>
        <li>¿Sus médicos siguen en la red del plan?</li>
        <li>¿Sus medicamentos siguen cubiertos?</li>
        <li>¿Han cambiado sus beneficios dentales y de visión?</li>
        <li>¿Existe un plan mejor para su situación en {$anio}?</li>
      </ul>

      <div style='text-align:center;margin:28px 0;'>
        <a href='tel:{$config['agency_phone']}' style='background:#d97706;color:#fff;padding:13px 28px;border-radius:10px;text-decoration:none;font-weight:700;font-size:14px;display:inline-block;'>
          📞 Agendar mi revisión anual gratuita
        </a>
      </div>

      <p>Esta revisión es completamente <strong>gratuita</strong> para usted y no tiene ninguna obligación de cambiar si su plan actual sigue siendo el mejor.</p>
      <p>Con mucho gusto le ayudo a navegar el AEP con confianza.</p>
      <p>Su agente de confianza,<br><strong>Isabel Fuentes</strong><br><em>{$config['agency_phone']}</em></p>
    ";

    $html = emailWrapper(
      "Su revisión anual de Medicare — AEP {$anio}",
      "El AEP comienza el 15 de octubre. Revisemos juntos su plan antes de que sea tarde.",
      $body, $config
    );

    $ok = sendMemberEmail(
      $m['email'], $nombre,
      "⏰ AEP Medicare {$anio} — Revisión anual gratuita con Isabel",
      $html, $config
    );
    if ($ok) $sent++;
  }

  logMarketing("AEP campaign: sent={$sent}, total=" . count($members), $config);
  return ['mode' => 'aep', 'sent' => $sent, 'total' => count($members)];
}

// ─── AUTO MODE — detecta qué correr según la fecha ───────
function detectMode() {
  $month = (int)date('n');
  $day   = (int)date('j');

  if ($month === 9 && $day === 1) return 'aep';       // 1 de septiembre = pre-AEP
  if ($day === 15) return 'newsletter';                // día 15 = newsletter
  return 'birthday';                                   // todos los días = cumpleaños
}

// ─── MAIN ─────────────────────────────────────────────────
$startTime = microtime(true);
$mode = $CONFIG['mode'] === 'auto' ? detectMode() : $CONFIG['mode'];
logMarketing("=== Email Marketing started — mode: {$mode} ===", $CONFIG);

switch ($mode) {
  case 'birthday':
    $result = runBirthdayCampaign($pdo, $CONFIG); break;
  case 'newsletter':
    $result = runNewsletterCampaign($pdo, $CONFIG); break;
  case 'aep':
    $result = runAEPCampaign($pdo, $CONFIG); break;
  default:
    $result = ['error' => 'Modo no reconocido: ' . $mode];
}

$elapsed = round((microtime(true) - $startTime) * 1000);
logMarketing("=== Done in {$elapsed}ms — " . json_encode($result) . " ===", $CONFIG);

if (php_sapi_name() !== 'cli') {
  header('Content-Type: application/json');
  echo json_encode(['ok' => true, 'result' => $result, 'elapsed_ms' => $elapsed], JSON_PRETTY_PRINT);
}
