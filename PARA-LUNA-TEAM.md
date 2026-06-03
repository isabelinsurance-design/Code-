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
| `isabel-sistema-completo-UNICO.html` | **El archivo principal.** Single-file de ~1.4 MB con todo embebido (18 herramientas + 5 tabs nuevas). Sirve tal cual desde Bluehost. |
| `index.html` | Versión editable que carga `tools/` por separado. Útil para mantenimiento. |
| `tools/` (18 archivos) | Las 18 herramientas standalone — cada una tiene un interceptor de fetch inyectado (busca `ISABEL UNIFIED`) para compartir la API key. |
| `bot/` | **Bot de Telegram en Python.** ⚠️ No corre en Bluehost (ver "Bot" abajo). |
| `CLAUDE.md` | Documentación técnica detallada para futuras sesiones de desarrollo. |

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

**3. `cron/briefing.php` — esqueleto:**
```php
<?php
require __DIR__ . '/../config.php';   // $ANTHROPIC_KEY, $TELEGRAM_BOT_TOKEN, $ISABEL_CHAT_ID, $PDO
// 1. compute trust score + gaps from luna_memoria
$score = compute_health($PDO);
$gaps  = compute_gaps($PDO);
// 2. call Claude
$body = json_encode([
  'model' => 'claude-sonnet-4-20250514',
  'max_tokens' => 600,
  'system' => ISABEL_SYSTEM,
  'messages' => [['role'=>'user','content'=>build_briefing_prompt($score, $gaps)]],
]);
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => $body,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'x-api-key: ' . $ANTHROPIC_KEY,
    'anthropic-version: 2023-06-01',
  ],
]);
$resp = json_decode(curl_exec($ch), true);
$text = $resp['content'][0]['text'] ?? 'Briefing error';
// 3. send to Telegram
$msg = "🌅 *Briefing* — Salud: *{$score}/100*\n\n" . $text;
file_get_contents("https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendMessage?" . http_build_query([
  'chat_id' => $ISABEL_CHAT_ID, 'text' => $msg, 'parse_mode' => 'Markdown',
]));
```

**4. `webhook-telegram.php` — recibe mensajes:**
```php
<?php
require __DIR__ . '/config.php';
$update = json_decode(file_get_contents('php://input'), true);
$msg = $update['message']['text'] ?? '';
$chat = $update['message']['chat']['id'] ?? null;
if (!$msg || !$chat) exit;
// llamar a Claude con el mismo ISABEL_SYSTEM…
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
$prompt = <<<EOT
Analista de inteligencia para Isabel Fuentes (Medicare hispano SoCal).
Investiga AHORA usando búsqueda web:
1. Competidores corriendo anuncios bilingües (Quotely, eHealth, etc) — qué dicen.
2. Contenido viral en español sobre Medicare esta semana.
3. Cambios CMS / Medicare Advantage 2026.
4. 3 OPORTUNIDADES específicas para Isabel + UNA acción concreta.
NO inventes datos.
EOT;

$body = json_encode([
  'model' => 'claude-sonnet-4-20250514',
  'max_tokens' => 4096,
  'system' => ISABEL_SYSTEM,
  'tools' => [['type'=>'web_search_20250305', 'name'=>'web_search', 'max_uses'=>6]],
  'messages' => [['role'=>'user', 'content'=>$prompt]],
]);
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => $body,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'x-api-key: ' . $ANTHROPIC_KEY,
    'anthropic-version: 2023-06-01',
  ],
  CURLOPT_TIMEOUT => 120,  // web search puede tardar
]);
$resp = json_decode(curl_exec($ch), true);

// Combina text blocks (filtra tool_use/tool_result)
$text = '';
foreach (($resp['content'] ?? []) as $b) {
  if (($b['type'] ?? '') === 'text') $text .= ($b['text'] ?? '') . "\n\n";
}

// Guarda en MySQL para historial + manda a Telegram
$PDO->prepare("INSERT INTO luna_intel (text) VALUES (?)")->execute([$text]);
$msg = "🔭 *Inteligencia semanal*\n\n" . substr($text, 0, 3500);
file_get_contents("https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendMessage?" . http_build_query([
  'chat_id' => $ISABEL_CHAT_ID, 'text' => $msg, 'parse_mode' => 'Markdown',
]));
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
- Anthropic Claude API directo del navegador (`claude-sonnet-4-20250514`)
- Headers requeridos: `x-api-key`, `anthropic-version: 2023-06-01`,
  `anthropic-dangerous-direct-browser-access: true`
- localStorage para persistencia (keys documentadas en `CLAUDE.md`)

## Para regenerar el archivo UNICO

Después de editar `index.html` o cualquier `tools/*.html`:

```bash
# El script de build está descrito en CLAUDE.md (sección "Build step")
python3 build_single.py  # produce isabel-sistema-completo-UNICO.html
```

(El script de build no está commiteado — está en el flujo de trabajo de Claude
Code; replicable en 30 líneas de Python.)

---

Cualquier duda técnica, todo está en `CLAUDE.md`.
