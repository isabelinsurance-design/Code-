# Deploying Athena

A step-by-step for going from the GitHub repo to Athena live on WhatsApp,
written for Isabel. Time budget: **~90 minutes the first time**, half of
that waiting on accounts to finish creating.

Stack we're targeting tonight:
- **Hosting:** Railway ($5/mo Hobby plan)
- **WhatsApp transport:** Twilio Sandbox tonight → your real number after
  Meta verification (3–7 days, runs in parallel)
- **Backups:** Cloudflare R2 (~$0.15/mo)
- **AI:** Anthropic (Athena's brain) + OpenAI (voice in/out)
- **Email:** Gmail with an app password
- **Optional later:** Google Calendar, Nextiva, Instagram

---

## 0. Accounts you need

Create these now (5–10 min total). They can be set up in parallel.

1. **Anthropic** — https://console.anthropic.com — you already have one. Copy your API key (starts with `sk-ant-...`).
2. **OpenAI** — https://platform.openai.com — add $5 of credit. Copy your API key (`sk-...`).
3. **Twilio** — https://www.twilio.com/try-twilio — free trial includes $15 credit, no card required to start.
4. **Cloudflare** — https://dash.cloudflare.com/sign-up — free tier.
5. **Railway** — https://railway.app/login — sign in with GitHub. $5 free trial.
6. **Gmail app password** for isabel.insurance@gmail.com:
   - Google Account → Security → 2-Step Verification → must be ON
   - Then: App Passwords → "Athena" → 16-character password. Save it.

---

## 1. Twilio WhatsApp Sandbox (5 min)

This gets you a working WhatsApp number tonight. The real-number
application runs in parallel and takes 3–7 days for Meta to approve.

1. Twilio Console → **Messaging → Try It Out → Send a WhatsApp Message**.
2. You'll see a sandbox number like `+1 415 523 8886` and a join code like
   `join purple-tiger`. **Text that exact phrase** from your WhatsApp to
   that number. You're in.
3. While you're here, **also start the production-number application**:
   Messaging → Senders → WhatsApp Senders → Add → "Use my own number."
   Follow the steps to verify your business via Meta. This runs while you
   use the sandbox.
4. Copy these for later:
   - Account SID (starts with `AC...`)
   - Auth Token (in Account → API keys & tokens)
   - The sandbox number (`whatsapp:+14155238886`)

---

## 2. Cloudflare R2 bucket for backups (5 min)

1. Cloudflare Dashboard → **R2 Object Storage** → Get Started → confirm a
   payment method (R2's free tier is 10GB; we'll use maybe 50MB/month).
2. **Create bucket** → name it `athena-backups` → US-West region (closest
   to SoCal).
3. **Manage R2 API Tokens** → **Create API Token** → "Athena Backups" →
   permissions = **Object Read & Write** → scope to bucket = `athena-backups`.
4. Save these (you only see them once):
   - Access Key ID
   - Secret Access Key
   - The endpoint URL (Cloudflare shows it as
     `https://<account-id>.r2.cloudflarestorage.com`)

---

## 3. Push to GitHub (already done)

Your `claude/sleepy-darwin-P4k2z` branch on `isabelinsurance-design/Code-`
is up to date. Confirm in your browser if you want.

---

## 4. Railway deploy (15 min)

1. Railway → **New Project** → **Deploy from GitHub repo** → select
   `isabelinsurance-design/Code-`.
2. After it imports, click the service → **Settings**:
   - **Root Directory:** `server`
   - **Build Command:** (leave blank — Nixpacks auto-detects)
   - **Start Command:** `node src/index.js`
3. **Volumes** tab → **+ New Volume** → mount path `/app/server/data`,
   size 1 GB. (Then a second volume at `/app/server/backups`, size 1 GB.)
   Without these, every deploy wipes Isabel's wiki and CRM.
4. **Settings → Networking → Generate Domain.** You'll get something like
   `athena-isabel.up.railway.app`. Save it.

---

## 5. Environment variables (Railway → Variables tab)

Paste all of these. Replace placeholders with your real values.

```bash
# === Required ===
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...

# === Twilio (sandbox first) ===
TWILIO_ACCOUNT_SID=AC...
TWILIO_AUTH_TOKEN=...
TWILIO_WHATSAPP_FROM=whatsapp:+14155238886   # sandbox number
PUBLIC_URL=https://athena-isabel.up.railway.app
TWILIO_REQUIRE_SIGNATURE=true

# === Isabel ===
ISABEL_WHATSAPP=whatsapp:+1XXXXXXXXXX        # your number with +1
ISABEL_NAME=Isabel
TIMEZONE=America/Los_Angeles

# === Gmail ===
GMAIL_USER=isabel.insurance@gmail.com
GMAIL_APP_PASSWORD=xxxx xxxx xxxx xxxx       # 16-char app password
SAMI_EMAIL=sami@...                          # her email

# === TTS (voice replies) ===
TTS_VOICE=nova                               # try shimmer or coral too
TTS_MODEL=tts-1                              # tts-1-hd for higher quality

# === Backups to R2 ===
BACKUP_S3_ENDPOINT=https://<acct-id>.r2.cloudflarestorage.com
BACKUP_S3_BUCKET=athena-backups
BACKUP_S3_REGION=auto
BACKUP_S3_ACCESS_KEY_ID=...                  # from step 2
BACKUP_S3_SECRET_ACCESS_KEY=...

# === LUNA bridge (Athena ↔ CRM del equipo) ===
LUNA_BASE_URL=https://withisabelfuentes.com/luna_api.php
LUNA_API_KEY=                                # ver Paso 5.5 abajo

# === Dashboard operacional ===
DASHBOARD_PASSWORD=                           # contraseña que tú elijas

# === Optional (add when ready) ===
# Google Calendar — needs OAuth flow, defer for now
# GOOGLE_CALENDAR_CLIENT_ID=
# GOOGLE_CALENDAR_CLIENT_SECRET=
# GOOGLE_CALENDAR_REFRESH_TOKEN=
# Nextiva
# NEXTIVA_API_KEY=
# NEXTIVA_ACCOUNT_ID=
# Instagram
# IG_ACCESS_TOKEN=
# IG_USER_ID=
```

Railway will auto-redeploy when you save.

---

## 5.5. Conectar Athena con LUNA (10 min — solo si tienes el CRM LUNA en Bluehost)

LUNA vive aparte en Bluehost (PHP + MySQL). Athena le habla por HTTP usando
un secret compartido en un header. **El CRM de los clientes Medicare vive
en LUNA, no en Athena.** Sin este paso, Maria Medicare en WhatsApp no puede
leer ni escribir los expedientes reales.

### A. Genera el secret compartido

En tu terminal local (o usa cualquier generador hex de 64 caracteres):

```bash
openssl rand -hex 32
```

Copia el resultado. Es el mismo string que va a vivir en DOS lugares:
- **Railway** (Athena) → variable `LUNA_API_KEY`
- **Bluehost** (LUNA) → variable `LUNA_INTERNAL_KEY`

### B. Pega el secret en Railway

En Railway → tu proyecto Athena → Variables tab, agrega:

```bash
LUNA_API_KEY=<el-string-de-64-caracteres>
LUNA_BASE_URL=https://withisabelfuentes.com/luna_api.php
```

(`LUNA_BASE_URL` es la ruta completa al archivo `luna_api.php` de tu LUNA
en Bluehost. Ajústalo si tu LUNA vive en otra subcarpeta.)

### C. Pega el patch PHP en Bluehost

Abre `luna_api.php` en tu Bluehost. **Al inicio del archivo, ANTES de
`session_start()`**, pega esto:

```php
$athenaKey = $_SERVER['HTTP_X_ATHENA_KEY'] ?? '';
$expected  = getenv('LUNA_INTERNAL_KEY') ?: '';
if ($athenaKey && $expected && hash_equals($expected, $athenaKey)) {
    // Petición viene de Athena con el secret correcto → tratarla
    // como Isabel-admin sin necesidad de session de browser.
    $_SESSION['user_id']   = 6;          // ID de Isabel en LUNA
    $_SESSION['rol']       = 'admin';
    $_SESSION['nombre']    = 'Isabel (vía Athena)';
    $_SESSION['is_athena'] = true;
} else {
    session_start();
}
```

### D. Configura `LUNA_INTERNAL_KEY` en Bluehost

Bluehost cPanel → **Variables de entorno** (busca PHP env vars o
similar). Agrega:

```
LUNA_INTERNAL_KEY=<el-mismo-string-de-64-caracteres>
```

Si tu hosting no permite env vars, alternativa rápida: pega el secret
directo en el PHP arriba del bloque (no recomendado para producción
pero funciona). Reemplaza `getenv('LUNA_INTERNAL_KEY') ?: ''` por
`'<el-string>'`.

### E. Verifica conectividad

Después de que Railway se redespliegue, mándale a Athena por WhatsApp:

```
/luna ping
```

Debería contestarte: `LUNA ✓ — 83 miembros en pipeline.` (con tu conteo
real). Si dice "LUNA inalcanzable", revisa que el secret coincida en
ambos lados y que `LUNA_BASE_URL` apunte exactamente al `.php` correcto.

---

## 6. Point Twilio at Railway (2 min)

1. Twilio Console → **Messaging → Try It Out → Send a WhatsApp Message →
   Sandbox Settings**.
2. **"WHEN A MESSAGE COMES IN"** → paste
   `https://athena-isabel.up.railway.app/whatsapp` (your Railway URL).
   Method: **POST**. Save.

---

## 7. First contact

Text **"hola"** to the sandbox number. Within 5–10 seconds Athena should
reply. Try these to confirm everything's wired:

- `hola` → friendly Spanglish greeting
- `recordar: peso hoy 176 lbs` → confirms she captured it
- `que recuerdas` → reads back the wiki
- send a **voice note** → she replies with a voice note
- send a **photo of food** → Carmen the nutrition coach reads it
- `mándale un mensaje a Sami que me confirme la junta` → enters drafts queue,
  shows you the preview, waits for "envía"
- `envía` → actually sends to Sami
- `historial` → audit log of every action she took

---

## 8. Verify backups (next morning)

After 24 hours:

1. Cloudflare R2 → `athena-backups` bucket → should see ~24 `.tar.gz` files
   under the `athena/` prefix.
2. Railway → service → Logs → search "backup" → should see hourly
   `snapshot OK ... (sync ✓)` lines.

If anything's missing, the env vars are wrong — Railway logs will say
exactly why.

---

## 9. When your real number is approved (3–7 days)

1. Twilio Console → **WhatsApp Senders** → your number is now active.
2. Update env vars on Railway:
   - `TWILIO_WHATSAPP_FROM=whatsapp:+1<your-real-number>`
3. Update Twilio webhook for that number → same URL.
4. Done. Athena now uses your real number.

---

## Costs (rough monthly)

| | Cost |
|---|---|
| Railway Hobby | $5 |
| Anthropic (Opus + Sonnet, 1 user) | $10–25 |
| OpenAI (Whisper + TTS) | $2–5 |
| Twilio WhatsApp (after free trial) | $5–15 |
| Cloudflare R2 | ~$0.15 |
| **Total** | **~$22–50/month** |

vs Lindy $49.99/seat, Sintra $97, Gemini Spark Ultra $100 — and none of
those know Medicare, Spanglish, or you.

---

## Troubleshooting

- **No reply when you text:** Railway → Logs. Look for `[whatsapp]` errors.
  Most common: missing env var.
- **"Firma Twilio inválida":** `PUBLIC_URL` doesn't match the URL Twilio is
  hitting. Make sure it's `https://<railway-domain>` with no trailing slash.
- **Crons not firing:** Check `TIMEZONE=America/Los_Angeles` is set.
- **Voice notes come back as text:** `OPENAI_API_KEY` missing or `PUBLIC_URL`
  not reachable from outside. Test `curl <PUBLIC_URL>/health` from your laptop.

---

When something doesn't work, paste the Railway logs into a message to me
and I'll diagnose it.
