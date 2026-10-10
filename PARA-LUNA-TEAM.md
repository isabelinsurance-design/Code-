# Para el equipo LUNA — Sistema de Marketing Facebook (Isabel Fuentes)

Este paquete es un **componente para integrar dentro de LUNA en Bluehost**.
No es un sistema independiente para desplegar por separado.

## Qué es

Un sistema visual de marketing Medicare en español para Facebook, hecho como
single-page app HTML/JS. Mismo público (hispano 60+ SoCal), misma misión que
el resto de LUNA: **viral en Facebook → leads baratos → autoridad reconocida**.

## Archivos

| Archivo | Para qué |
|---|---|
| `isabel-sistema-completo-UNICO.html` | **El archivo principal.** Single-file de ~1.8 MB con todo embebido (20 herramientas + el Maestro). Sirve tal cual desde Bluehost. |
| `index.html` | Versión editable que carga `tools/` por separado. Útil para mantenimiento. |
| `tools/` (20 archivos) | Las 20 herramientas standalone — cada una tiene un interceptor de fetch inyectado (busca `ISABEL UNIFIED`) para compartir la API key. |
| `bot/` | **Bot de Telegram en Python.** ⚠️ No corre en Bluehost (ver "Bot" abajo). |
| `CLAUDE.md` | Documentación técnica detallada para futuras sesiones de desarrollo. |
| `tests/` | Pruebas en navegador real (`node tests/run.cjs`) con un servidor falso de Anthropic. |

## Integración en LUNA (Bluehost)

1. Subir `isabel-sistema-completo-UNICO.html` al servidor (estático, sin dependencias).
2. Crear un link/pestaña desde LUNA que abra ese archivo (o embed via iframe).
3. La API key de Anthropic se guarda en localStorage del navegador de Isabel —
   no necesita configuración server-side.

## Patrones de Athena ya incluidos (browser-side)

Estos vienen baked en el sistema, sin necesidad de servidor:

- **Memoria por capas** (4 stores: hechos / personas / tareas / compromisos)
- **Capture-by-default** (al hablar con Cerebro IA, extrae y guarda entidades en paralelo)
- **Voz por agente** (cada uno de los 6 agentes del Equipo IA tiene su voz y palabras prohibidas)
- **UNA acción concreta al cierre** (regla obligatoria en `ISABEL_SYSTEM`)
- **Salud del Negocio** (score 0-100 con coloreado por tier)
- **Gaps & Signals** (lista priorizada de qué necesita atención)
- **Compliance gating CMS** (escaneo automático de output IA contra reglas CMS)

## Lo que falta (necesita server-side en Bluehost o Athena)

Estos patrones de Athena requieren un proceso corriendo en servidor — no funcionan
en navegador:

- 🌅 Briefings diarios (6:30am / 9pm / Domingo) — cron job
- 🌙 Signals nocturnos / "dreaming"
- 📤 Drafts queue con confirmation gate
- 📲 Integración WhatsApp Business

Si LUNA va a hacerlos en PHP en Bluehost, se pueden replicar con cron jobs
nativos del cPanel. Si los hace Athena (que ya está en Railway), Athena puede
mandarle el briefing a Isabel directamente vía sus canales actuales (WhatsApp,
Telegram, voz).

### Blueprint PHP para Bluehost (receta mínima)

Si el equipo LUNA elige Bluehost para el lado servidor, esto es lo mínimo:

**1. Tablas MySQL (memoria persistente que sincroniza con el navegador):**
```sql
CREATE TABLE luna_memoria (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  layer        ENUM('hechos','personas','tareas','compromisos') NOT NULL,
  payload      JSON NOT NULL,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  done         TINYINT(1) DEFAULT 0,
  due_date     DATE NULL,
  INDEX (layer), INDEX (due_date)
);
CREATE TABLE luna_drafts (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  channel      VARCHAR(32) NOT NULL,       -- 'telegram','email','whatsapp'
  to_addr      VARCHAR(255),
  body         TEXT NOT NULL,
  status       ENUM('pending','approved','sent','discarded') DEFAULT 'pending',
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE luna_audit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(64), payload JSON, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

**2. Cron en cPanel (briefing 6:30am hora SoCal):**
```
30 6 * * * /usr/bin/php /home/USER/luna/cron/briefing.php >> /home/USER/luna/logs/briefing.log 2>&1
```

**2b. Helpers compartidos en `config.php`** (llamar a Claude y a Telegram sin sorpresas):
```php
// Llama a Claude y devuelve SOLO el texto. Los modelos actuales "piensan" primero
// (bloques "thinking") y esos tokens cuentan dentro de max_tokens → deja max_tokens holgado
// y no leas content[0] a ciegas. Si la búsqueda web pausa el turno (pause_turn) se continúa.
function claude_call(array $body, int $timeout = 120): array {
  global $ANTHROPIC_KEY;
  $text = ''; $sources = []; $resp = [];
  for ($i = 0; $i < 5; $i++) {
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => $timeout,
      CURLOPT_POSTFIELDS => json_encode($body),
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $ANTHROPIC_KEY, 'anthropic-version: 2023-06-01'],
    ]);
    $resp = json_decode(curl_exec($ch), true) ?: [];
    foreach (($resp['content'] ?? []) as $b) {
      if (($b['type'] ?? '') !== 'text') continue;            // ignora thinking / tool_use / tool_result
      $text .= ($b['text'] ?? '') . "\n\n";
      foreach (($b['citations'] ?? []) as $c) if (!empty($c['url'])) $sources[$c['url']] = $c['title'] ?? $c['url'];
    }
    if (($resp['stop_reason'] ?? '') !== 'pause_turn') break;
    $body['messages'][] = ['role' => 'assistant', 'content' => $resp['content']];   // se devuelve tal cual y sigue
  }
  return ['text' => trim($text), 'sources' => $sources, 'stop' => $resp['stop_reason'] ?? '',
          'error' => $resp['error']['message'] ?? null];
}

// Telegram: POST (no GET: el texto largo no cabe en una URL), en partes de <4096 caracteres y SIN parse_mode
// (el texto de la IA trae * y _ sueltos que Telegram rechazaría como Markdown).
function telegram_send($chat_id, string $text): void {
  global $TELEGRAM_BOT_TOKEN;
  foreach (mb_str_split($text, 3800) as $part) {
    $ch = curl_init("https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendMessage");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => ['chat_id' => $chat_id, 'text' => $part]]);
    curl_exec($ch);
  }
}
```

**3. `cron/briefing.php` — esqueleto:**
```php
<?php
require __DIR__ . '/../config.php';   // $ANTHROPIC_KEY, $TELEGRAM_BOT_TOKEN, $ISABEL_CHAT_ID, $PDO + helpers de 2b
// 1. compute trust score + gaps from luna_memoria
$score = compute_health($PDO);
$gaps  = compute_gaps($PDO);
// 2. call Claude
$r = claude_call([
  'model' => 'claude-sonnet-5-5',
  'max_tokens' => 4096,
  'output_config' => ['effort' => 'medium'],
  'system' => ISABEL_SYSTEM,
  'messages' => [['role'=>'user','content'=>build_briefing_prompt($score, $gaps)]],
]);
$text = $r['text'] ?: 'Briefing error: ' . ($r['error'] ?? $r['stop']);
// 3. send to Telegram
telegram_send($ISABEL_CHAT_ID, "🌅 Briefing — Salud: {$score}/100\n\n" . $text);
```

**4. `webhook-telegram.php` — recibe mensajes:**
```php
<?php
require __DIR__ . '/config.php';
$update = json_decode(file_get_contents('php://input'), true);
$msg = $update['message']['text'] ?? '';
$chat = $update['message']['chat']['id'] ?? null;
if (!$msg || !$chat) exit;
if ((string)$chat !== (string)$ISABEL_CHAT_ID) exit;   // SOLO Isabel: si no, cualquiera que encuentre el bot gasta tu saldo de Anthropic
// llamar a Claude con claude_call() y el mismo ISABEL_SYSTEM…
// hacer capture-by-default → INSERT luna_memoria
// responder via /sendMessage
```

Telegram apunta su webhook a `https://luna.bluehost.com/webhook-telegram.php` con
`/setWebhook?url=...` y listo — no necesita proceso largo, es HTTP normal.

**5. Sync navegador ↔ servidor (opcional, futuro):**
Endpoints `/api/memoria` GET + POST con bearer token; el navegador hace fetch al
cargar para hidratar `memoria.*` y al guardar para persistir multi-dispositivo.

**6. Inteligencia semanal cron (la pestaña 🔭 Inteligencia automatizada):**

La pestaña "🔭 Inteligencia de Mercado" del navegador es bajo demanda. Para
que corra automática cada lunes y le mande a Isabel los hallazgos por Telegram:

```
0 6 * * 1 /usr/bin/php /home/USER/luna/cron/intel-semanal.php >> /home/USER/luna/logs/intel.log 2>&1
```

`cron/intel-semanal.php` (idéntico flujo a briefing pero con búsqueda web):
```php
<?php
require __DIR__ . '/../config.php';
// Snapshot interno desde MySQL (mismas tablas que luna_memoria, luna_intel previas)
$snapshot = build_self_state_snapshot($PDO);  // helper que genera string con score, plan%, leads, tareas pendientes, gaps, runs previas

$prompt = <<<EOT
Eres el RADAR de Isabel Fuentes. Doble rol: (A) analista de mercado con búsqueda web, (B) Chief of Staff.

ESTADO INTERNO DE ISABEL:
{$snapshot}

Tu reporte debe tener EXACTAMENTE 5 secciones en este orden:

## 📢 Competidores corriendo anuncios bilingües
Quién corre ads de Medicare bilingüe en FB/IG SoCal (Quotely, eHealth, etc): hook, qué hace bien.

## 🔥 Contenido viral en español sobre Medicare
Reels/posts/Lives que funcionan ahora. Hooks, formatos, ángulos.

## 📰 Noticias / cambios CMS
Cambios MA 2026, beneficios nuevos, noticias.

## 💡 3 OPORTUNIDADES específicas para Isabel
Cada una: ángulo, gancho, por qué AHORA.

## 🧭 ANÁLISIS CHIEF OF STAFF (cómo mejorar)
Cambios ESTRUCTURALES (no de contenido). Qué funciona, qué no, qué cambiar esta semana.
Si previas no se ejecutaron, llámalo.

Cierra con "✅ Tu próxima acción:" UNA acción operacional.
NO inventes nombres ni métricas.
EOT;

$r = claude_call([
  'model' => 'claude-opus-5-5',            // el Radar usa el nivel "deep" (igual que la app)
  'max_tokens' => 20000,                   // incluye lo que el modelo "piensa" antes de escribir
  'output_config' => ['effort' => 'high'],
  'system' => ISABEL_SYSTEM,
  'tools' => [[
    'type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 6,
    'user_location' => ['type'=>'approximate', 'city'=>'Los Angeles', 'region'=>'California',
                        'country'=>'US', 'timezone'=>'America/Los_Angeles'],
  ]],
  'messages' => [['role'=>'user', 'content'=>$prompt]],
], 300);                                   // búsqueda web + razonamiento pueden tardar minutos
$text = $r['text'];
if ($r['sources']) {                       // Anthropic pide mostrar las fuentes de la búsqueda web
  $text .= "\n\nFuentes:\n";
  foreach ($r['sources'] as $url => $title) $text .= "• {$title} — {$url}\n";
}

// Guarda en MySQL para historial + manda a Telegram
$PDO->prepare("INSERT INTO luna_intel (text) VALUES (?)")->execute([$text]);
telegram_send($ISABEL_CHAT_ID, "🔭 Inteligencia semanal\n\n" . $text);
```

Necesita tabla:
```sql
CREATE TABLE luna_intel (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  text MEDIUMTEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

## Bot de Telegram (`bot/`)

⚠️ **No deployable en Bluehost.** El bot está en Python con `python-telegram-bot`
usando long-polling — Bluehost no corre procesos largos así.

**Opciones:**
1. **Recomendado:** dejar el bot como **código de referencia** y que Athena (que ya
   está en Railway y maneja conversación con Isabel) absorba estos comandos
   como capacidades nuevas. Los prompts en `bot/bot.py` muestran exactamente
   qué hace cada comando.
2. Re-escribir como webhook PHP en Bluehost (Telegram POST → `bot.php`).
3. Desplegarlo aparte en Railway/Replit/Render (~$0-5/mes).

## Stack del Browser app

- HTML/CSS/JS vanilla (sin frameworks, sin build step)
- Anthropic Claude API directo del navegador, con modelos por nivel y respaldo automático
  (`claude-haiku-5-5` / `claude-sonnet-5-5` / `claude-opus-5-5`; el modelo anterior
  `claude-sonnet-4-20250514` se retiró el 15-jun-2026 — detalles en `CLAUDE.md`)
- Headers requeridos: `x-api-key`, `anthropic-version: 2023-06-01`,
  `anthropic-dangerous-direct-browser-access: true`
- localStorage para persistencia (keys documentadas en `CLAUDE.md`)

## Para regenerar el archivo UNICO

Después de editar `index.html` o cualquier `tools/*.html`:

```bash
python3 build.py          # produce isabel-sistema-completo-UNICO.html (y bot/isabel_system.txt)
node tests/run.cjs        # ~315 comprobaciones en un navegador real (necesita Playwright)
```

`build.py` está commiteado en la raíz del repo. Detalles en `CLAUDE.md` ("Build step" y "Testing").

---

Cualquier duda técnica, todo está en `CLAUDE.md`.
