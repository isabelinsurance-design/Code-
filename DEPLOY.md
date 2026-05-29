# Deploying Athena

Guía paso a paso para poner Athena en producción. Pensada para que **Sami la siga sin pelearse**. Presupuesto de tiempo: **~90 minutos la primera vez**, la mitad esperando que cuentas terminen de crearse.

---

## Para Sami — por qué Railway y NO Bluehost

LUNA vive en Bluehost. Eso ya funciona perfecto y no se toca. **Athena NO puede vivir en Bluehost.** Esta es la razón clara, en una página, para que no haya dudas cuando alguien pregunte "¿y por qué no la metemos donde ya tenemos hosting?":

### LUNA y Athena son técnicamente diferentes

| | LUNA | Athena |
|---|---|---|
| **Tipo de app** | Web tradicional (request/response) | Proceso 24/7 siempre prendido |
| **Lenguaje** | PHP + MySQL | Node.js + archivos JSON |
| **Cómo trabaja** | Skarleth abre browser → PHP responde → cierra | Athena escucha cron jobs, WhatsApp entrante, llamadas, IMAP — todo el día sin parar |
| **Hosting que necesita** | Bluehost (shared hosting clásico) ✓ | Hosting que corra Node.js persistente ✗ Bluehost no |

### Las 4 cosas que Athena hace que Bluehost NO soporta

**1. Athena necesita un proceso Node.js corriendo 24/7.**
Adentro del proceso viven 10 cron jobs:
- 6:30am — briefing matutino a Isabel
- 9pm — evening check-in
- Domingo 6pm — review semanal
- 2am — reflexión nocturna (el cerebro de Athena consolida memorias mientras Isabel duerme)
- 5am — triage de Gmail
- Cada hora 7am-9pm — task tick (Athena trabaja silenciosamente en su cola)
- Cada 2h — chase de compromisos
- Cada hora — backup a Cloudflare R2
- Cada hora — limpieza de archivos de audio temporales
- Cada hora — limpieza de seen-message IDs

Bluehost shared hosting NO mantiene procesos prendidos. Cada request PHP nace y muere. Si tratas de meter Athena ahí, los crons no se ejecutan, Athena no manda el briefing, no reacciona a emails.

**2. Athena necesita WebSocket server.**
Cuando un cliente Medicare llama al teléfono Twilio de Isabel, Athena contesta usando **Twilio ConversationRelay** — una conexión WebSocket en vivo donde Twilio le manda lo que dice el cliente y Athena le manda de regreso la voz de Isabel. WebSocket = conexión que dura minutos, no segundos. Bluehost shared NO soporta WebSocket servers. Sin esto: el teléfono de Athena no funciona.

**3. Athena necesita IMAP IDLE persistente con Gmail.**
Para que Athena reaccione a emails al SEGUNDO que llegan (no esperar a las 5am), abre una conexión IMAP IDLE con Gmail que queda colgada esperando notificación. Es una conexión de horas. Bluehost shared no permite mantener conexiones así.

**4. Athena necesita memoria en RAM compartida entre requests.**
Las protecciones contra ataques (rate limiting, idempotencia, dedupe de mensajes Twilio) viven en estructuras de datos en RAM del proceso. Si cada request es un proceso nuevo (PHP), no hay manera de mantener esa memoria entre requests. Athena sería vulnerable a doble-procesamiento de mensajes.

### Por qué Railway resuelve todo

Railway es hosting diseñado para procesos persistentes como Athena:

- ✅ **Procesos Node.js 24/7 nativos** — los cron jobs corren porque el proceso nunca muere
- ✅ **WebSocket out of the box** — la llamada de Twilio se conecta sin configuración extra
- ✅ **Volúmenes persistentes** — la carpeta `data/` (wiki, tareas, memoria de Athena) sobrevive deploys
- ✅ **Deploy desde GitHub con un click** — push a la branch → Railway redespliega solo
- ✅ **Env vars en panel web** — sin tocar archivos
- ✅ **Auto-restart si crashea** — Athena se levanta sola
- ✅ **Logs en vivo** — cuando algo falla, ves qué pasó
- ✅ **$5/mo** vs días peleando para hacerlo en Bluehost (que terminaría no funcionando)

### La regla simple

> **LUNA vive en Bluehost** (donde brilla — PHP, MySQL, web app).
> **Athena vive en Railway** (donde brilla — Node.js, cron, WebSocket).
> **Se hablan entre sí por HTTP** vía `luna_api.php` con un secret compartido (ver Paso 5.5).
> **NUNCA las mezcles.** Cada una en su hosting óptimo.

---

## ⚡ Deploy MÍNIMO — pónganla viva hoy, agreguen features después

**Si esta es la primera vez deployando Athena**, vayan por esta ruta corta primero. ~45 minutos. Deja TODAS las features opcionales (voz, LUNA, Calendar, ElevenLabs, etc.) para activar después. Lo importante es ver Athena viva contestando WhatsApp esta noche.

> Si quieren la guía completa con todas las integraciones desde el día 1, salten esta sección y vayan a "Stack que vas a parar tonight" abajo.

### Lo que VA a funcionar después de este deploy mínimo

- ✅ WhatsApp conversacional con Athena (texto, voz notes, fotos, PDFs)
- ✅ Las 17 coaches consultables vía Athena (consultar_especialistas)
- ✅ Capture by default (notas, tareas, entidades, compromisos)
- ✅ Memoria por capas (wiki, temporada, historial 40 turnos)
- ✅ Borradores de email/SMS con confirmación ("envía")
- ✅ Skills (proponer, aprobar, invocar)
- ✅ Dashboard operacional en `/dashboard` con `DASHBOARD_PASSWORD`
- ✅ Crons proactivos: briefing 6:30am, evening 9pm, weekly review domingo, reflexión nocturna 2am
- ✅ Slash commands (/help, /agenda, /pendientes, /historial, /tareas, etc.)

### Lo que se queda APAGADO (activable después con env var)

| Feature | Para activar después agregar... |
|---|---|
| Llamadas telefónicas en vivo | Comprar número Twilio Voice + apuntar webhook `/voice/incoming` |
| Voz clonada de Isabel | `ELEVENLABS_API_KEY` + `ELEVENLABS_VOICE_ID` |
| Maria habla con LUNA (CRM real) | `LUNA_BASE_URL` + `LUNA_API_KEY` + patch PHP (paso 5.5) |
| Email triage automático 5am | `GMAIL_USER` + `GMAIL_APP_PASSWORD` |
| Reacción instantánea a emails (IMAP IDLE) | `INBOX_IDLE=true` después de Gmail config |
| Google Calendar (ver/crear citas) | `GOOGLE_CALENDAR_CLIENT_ID/SECRET/REFRESH_TOKEN` |
| Backups automáticos a R2 | `BACKUP_S3_ENDPOINT/BUCKET/REGION/ACCESS_KEY_ID/SECRET_ACCESS_KEY` |
| Instagram read-only | `IG_ACCESS_TOKEN` + `IG_USER_ID` |
| Nextiva SMS visibility | `NEXTIVA_API_KEY` + `NEXTIVA_ACCOUNT_ID` |

Cada feature es un toggle de env var. Athena detecta qué está configurado y prende lo que tiene.

### Antes de empezar — ten esto a la mano

1. Una computadora con browser (Chrome/Safari/Edge)
2. El teléfono de Isabel con WhatsApp instalado
3. Una app de notas abierta (Notepad, Notes, lo que sea) — vas a copiar/pegar 5 cosas importantes
4. La tarjeta de crédito de Isabel (para Anthropic y OpenAI mínimo)
5. Acceso a GitHub con la cuenta de `isabelinsurance-design` (para Railway)

> **Regla de oro:** cuando una página te muestre una "API Key" o "Token", **CÓPIALA INMEDIATAMENTE** a tu app de notas. Si cierras la página sin copiar, casi siempre la tienes que crear de nuevo desde cero.

---

## PASO 1 — Sacar la API key de Anthropic (5 min)

Esta es la "cabeza" de Athena. Sin esto, Athena no piensa.

**1.1.** Abre tu browser y entra a: **https://console.anthropic.com**

**1.2.** Si no tienes cuenta de Anthropic:
- Click "Sign Up"
- Usa el email de Isabel
- Sigue las instrucciones (verificación de email, etc.)

**1.3.** Si ya tienes cuenta: Login.

**1.4.** Una vez dentro del console, en el menú izquierdo busca **"API Keys"**. Click.

**1.5.** Click el botón **"Create Key"** (arriba a la derecha).

**1.6.** Te aparece un popup. En "Name" escribe: `Athena`. Click **"Create Key"**.

**1.7.** ⚠️ Te muestra una llave que empieza con `sk-ant-api03-...` (es muy larga).

**1.8.** **COPIA ESA LLAVE COMPLETA** y pégala en tu app de notas con la etiqueta `ANTHROPIC_API_KEY`. Si cierras este popup sin copiar, NO la podrás ver otra vez — hay que crear una nueva.

**1.9.** Verifica que la cuenta tenga crédito: arriba a la derecha → **"Plans & Billing"**. Debe decir al menos $10 disponibles. Si no, agregar tarjeta y mínimo $20.

✅ **Al final de este paso tienes:** una key `sk-ant-...` copiada en notas.

---

## PASO 2 — Sacar la API key de OpenAI (5 min)

Esta es para que Athena pueda escuchar las voice notes que Isabel le mande (Whisper).

**2.1.** Abre: **https://platform.openai.com**

**2.2.** Login (o crear cuenta si no tienes).

**2.3.** En el menú izquierdo (a veces hay que abrirlo con el icono ☰), busca **"API keys"**.

**2.4.** Click **"Create new secret key"**.

**2.5.** En "Name" escribe: `Athena`. Permissions: deja "All" (default). Click **"Create secret key"**.

**2.6.** ⚠️ Te aparece una llave que empieza con `sk-...`.

**2.7.** **COPIA ESA LLAVE** a tu app de notas con la etiqueta `OPENAI_API_KEY`. (Igual que con Anthropic — si la pierdes hay que crear nueva.)

**2.8.** Verifica crédito: arriba derecha → Settings → **Billing** → debe haber al menos $5. Si no, agrega tarjeta.

✅ **Al final de este paso tienes:** una key `sk-...` copiada en notas.

---

## PASO 3 — Crear cuenta Twilio + activar WhatsApp Sandbox (10 min)

Esto es lo que conecta WhatsApp con Athena. Vamos a usar el "Sandbox" de Twilio porque es gratis y funciona inmediato (sin esperar aprobación de Meta para el número real, que tarda 3-7 días).

**3.1.** Abre: **https://www.twilio.com/try-twilio**

**3.2.** Click "Sign Up". Llena el formulario:
- Email de Isabel
- Password fuerte
- Número de teléfono de Isabel (para verificación SMS)

**3.3.** Verifica el código SMS que te mandan al teléfono de Isabel.

**3.4.** Twilio te pregunta unas cosas para personalizar la cuenta:
- "What do you plan to build?": **Other**
- "Which Twilio product?": **Messaging** y **WhatsApp**
- "Which language?": **JavaScript / Node.js**
- "Are you a developer?": **No**

**3.5.** Llegas al dashboard. Arriba a la derecha verás un banner: **"Trial Balance: $15.00"**. Eso es suficiente.

**3.6.** En el menú izquierdo: **"Messaging"** → **"Try it out"** → **"Send a WhatsApp message"**.

**3.7.** En esa pantalla aparece (anota estos dos datos):
- Un número de teléfono: **`+1 415 523 8886`** (este es el número sandbox de Twilio, lo mismo para todos)
- Un "join code" tipo: **`join word-word`** (por ejemplo: `join sunny-tiger`) — este es ÚNICO para tu cuenta

**3.8.** ⚠️ Ahora **abre WhatsApp en el teléfono de Isabel**.

**3.9.** Agrega un contacto nuevo:
- Nombre: `Athena Sandbox`
- Número: `+1 415 523 8886`

**3.10.** Manda un mensaje a ese contacto con el texto exacto del "join code" del paso 3.7 (por ejemplo: `join sunny-tiger`).

**3.11.** Espera 5 segundos. Twilio te debe responder algo como:
> ✅ Twilio Sandbox: You are all set! Messages sent to this number...

Si te respondió eso, el teléfono de Isabel está **suscrito al sandbox**. Sin este paso, Athena no le puede mandar mensajes a Isabel.

**3.12.** Ahora copia los credenciales de Twilio:
- Click el logo de Twilio (arriba izquierda) para volver al dashboard principal
- En el centro de la pantalla, en una caja gris, verás:
  - **Account SID:** empieza con `AC` y es muy largo → **CÓPIALO** a notas como `TWILIO_ACCOUNT_SID`
  - **Auth Token:** está oculto con `••••`. Click el ícono del ojo para verlo → **CÓPIALO** a notas como `TWILIO_AUTH_TOKEN`

✅ **Al final de este paso tienes:**
- WhatsApp de Isabel suscrito al sandbox de Twilio
- `TWILIO_ACCOUNT_SID` copiado
- `TWILIO_AUTH_TOKEN` copiado

---

## PASO 4 — Deployar a Railway (10 min)

Railway es donde Athena va a vivir 24/7.

**4.1.** Abre: **https://railway.app/login**

**4.2.** Click **"Login with GitHub"**. Si te pide autorizar, autoriza.

**4.3.** Si es tu primera vez en Railway, te pregunta si quieres el trial gratis. Acepta. Te da $5 de crédito gratis.

**4.4.** En el dashboard, click el botón **"New Project"** (arriba derecha o en el centro).

**4.5.** Selecciona **"Deploy from GitHub repo"**.

**4.6.** Te pide autorizar Railway para ver tus repos de GitHub. Click "Configure GitHub App" → autoriza el repo `isabelinsurance-design/Code-` (puedes autorizar solo ese, no todos).

**4.7.** De vuelta en Railway, ahora puedes seleccionar el repo. Selecciona **`isabelinsurance-design/Code-`**.

**4.8.** Railway empieza a buildear automáticamente. **PERO** está usando la branch `main` y necesitamos otra branch. Vamos a cambiarla:
- Click en el cuadro del servicio (donde dice el nombre del repo)
- Click **"Settings"** tab
- Scroll hasta **"Source"** section
- En "Branch", cambia de `main` a: **`claude/sleepy-darwin-P4k2z`**
- Click fuera para guardar
- Railway va a re-buildear con la branch correcta. Esperar 2-3 minutos.

**4.9.** Ver el progreso: click **"Deployments"** tab. El último deploy debe pasar de "Building" → "Deploying" → "Active" (con check verde). Si dice "Crashed", click el deploy y revisa los logs.

**4.10.** Una vez "Active", generar el URL público:
- Settings tab → scroll a **"Networking"** section
- Click **"Generate Domain"**
- Te aparece un URL como: **`https://athena-isabel-production-XXXX.up.railway.app`**

**4.11.** ⚠️ **COPIA ESE URL COMPLETO** a tu app de notas como `PUBLIC_URL`. Es la dirección donde vive Athena en internet.

✅ **Al final de este paso tienes:**
- Athena deployada en Railway (pero todavía sin variables, así que crashea)
- URL público copiado

---

## PASO 5 — Pegar las 11 variables en Railway (10 min)

Las variables son la configuración secreta de Athena. Sin ellas no funciona.

**5.1.** En el proyecto Railway (donde estás), click **"Variables"** tab.

**5.2.** Click **"Raw Editor"** (arriba a la derecha).

**5.3.** Te aparece una caja vacía. **COPIA Y PEGA esto tal cual:**

```bash
ANTHROPIC_API_KEY=sk-ant-PEGA-AQUI
OPENAI_API_KEY=sk-PEGA-AQUI
PUBLIC_URL=https://PEGA-AQUI.railway.app
TWILIO_ACCOUNT_SID=ACPEGA-AQUI
TWILIO_AUTH_TOKEN=PEGA-AQUI
TWILIO_WHATSAPP_FROM=whatsapp:+14155238886
TWILIO_REQUIRE_SIGNATURE=true
ISABEL_WHATSAPP=whatsapp:+1XXXXXXXXXX
ISABEL_NAME=Isabel
TIMEZONE=America/Los_Angeles
DASHBOARD_PASSWORD=ELIJAN-UN-PASSWORD
```

**5.4.** Ahora reemplaza cada `PEGA-AQUI` con el valor real de tus notas:
- `ANTHROPIC_API_KEY` → la del paso 1
- `OPENAI_API_KEY` → la del paso 2
- `PUBLIC_URL` → el URL del paso 4.11 (completo, con `https://`)
- `TWILIO_ACCOUNT_SID` → del paso 3.12 (empieza con `AC`)
- `TWILIO_AUTH_TOKEN` → del paso 3.12
- `ISABEL_WHATSAPP` → el número de Isabel con `whatsapp:+1` adelante (ej. `whatsapp:+13105551234`)
- `DASHBOARD_PASSWORD` → invéntense uno (mínimo 8 caracteres, será para abrir el dashboard)

**5.5.** **NO toques estos** (déjenlos exacto como están):
- `TWILIO_WHATSAPP_FROM=whatsapp:+14155238886` ← este es el sandbox de Twilio, igual para todos
- `TWILIO_REQUIRE_SIGNATURE=true`
- `ISABEL_NAME=Isabel`
- `TIMEZONE=America/Los_Angeles`

**5.6.** Click **"Update Variables"**.

**5.7.** Railway detecta el cambio y redespliega automáticamente. Esperen 1-2 min. **Deployments tab** → último deploy debe pasar a verde "Active" otra vez.

✅ **Al final de este paso:** Athena está corriendo con todas las credenciales.

---

## PASO 6 — Apuntar el webhook de Twilio a Railway (2 min)

Esto le dice a Twilio "cuando llegue un mensaje al sandbox, mándaselo a Athena en Railway".

**6.1.** Abre otra vez Twilio Console: **https://console.twilio.com**

**6.2.** Menú izquierdo → **"Messaging"** → **"Try it out"** → **"Send a WhatsApp message"**.

**6.3.** Arriba en la pantalla, hay 3 tabs: "Send a WhatsApp message" / "**Sandbox settings**" / "Sandbox participants". Click **"Sandbox settings"**.

**6.4.** Verás dos campos:
- **"WHEN A MESSAGE COMES IN"** ← este es el importante
- **"STATUS CALLBACK URL"** ← deja vacío

**6.5.** En el primer campo, BORRA lo que haya y pega tu URL Railway + `/whatsapp` al final.

Ejemplo: si tu `PUBLIC_URL` es `https://athena-isabel-production-7a2k.up.railway.app`, entonces aquí va:
```
https://athena-isabel-production-7a2k.up.railway.app/whatsapp
```

**6.6.** Method al lado: déjalo en **`HTTP POST`** (default).

**6.7.** Scroll hasta abajo → click **"Save"**.

✅ **Al final de este paso:** Twilio sabe a dónde mandar los mensajes que reciba.

---

## PASO 7 — La prueba (3 min)

Momento de la verdad.

**7.1.** Abre WhatsApp en el teléfono de Isabel.

**7.2.** Ve al chat con "Athena Sandbox" (el contacto del paso 3.9).

**7.3.** Manda el mensaje: **`hola`**

**7.4.** Espera entre 5 y 15 segundos.

**7.5.** **Athena debe contestar** con un saludo en español/spanglish, presentándose como tu chief of staff.

### Si SÍ contestó

🎉 **Está VIVA.** Ya puedes:

- Mandarle `"Recuerda que prefiero llamar a clientes después de las 11am"` → guarda en memoria
- Mandarle `"¿Qué recuerdas de mí?"` → te lo lee de vuelta
- Mandarle `"/help"` → lista de comandos
- Mandarle `"Consulta a María Medicare sobre AEP"` → consulta especialista
- Abrir `https://TU-URL.railway.app/dashboard` en un browser → te pide usuario (cualquiera) y password (el `DASHBOARD_PASSWORD` del paso 5.4)
- Mañana a las 6:30am debe llegar el briefing matutino solo

### Si NO contestó

1. **Railway** → Deployments tab → último deploy → **"View Logs"**
2. Busca líneas con `error`, `failed`, `crashed`, o `Cannot find`
3. Lo más común:
   - **Env var mal escrita** (un espacio extra, una letra de menos en el SID). Re-checar paso 5.
   - **URL Railway mal copiada en el webhook Twilio** (paso 6.5). Re-checar.
   - **Anthropic sin crédito** (paso 1.9). Agregar más dinero.
   - **El teléfono de Isabel NO está suscrito al sandbox** (paso 3.11 nunca pasó). Mandar el "join code" otra vez.
4. Si nada de eso es: copia el error de los logs y pásalo a la sesión de Claude para que diagnostique.

---

## ✅ Checklist final del deploy mínimo

Marcá cada uno cuando lo termines:

- [ ] PASO 1: `ANTHROPIC_API_KEY` copiada
- [ ] PASO 2: `OPENAI_API_KEY` copiada
- [ ] PASO 3: WhatsApp de Isabel suscrito al sandbox + Twilio SID/Token copiados
- [ ] PASO 4: Railway deployado, URL público copiado
- [ ] PASO 5: 11 variables pegadas en Railway
- [ ] PASO 6: Webhook de Twilio apuntando a Railway
- [ ] PASO 7: Athena contestó "hola" por WhatsApp 🎉

### Después, agreguen features una por una

Orden recomendado para ir agregando, en orden de mayor valor / menor esfuerzo:

1. **Gmail app password** (5 min) → triage matutino + drafts de email
2. **Cloudflare R2 backups** (5 min) → seguridad de la memoria de Athena
3. **LUNA bridge** (30 min — necesita patch PHP en Bluehost) → Maria puede leer/escribir CRM real
4. **Google Calendar** (15 min — OAuth) → ver agenda + crear citas
5. **Llamadas telefónicas** (1 hora — número Twilio Voice) → Athena contesta llamadas
6. **ElevenLabs voz clonada** (30 min — grabar 5 min de Isabel) → Athena suena como Isabel en llamadas
7. **Instagram / Nextiva** (10 min cada uno) → si las usan

Cada paso es: agregar env vars → Railway redespliega solo → la feature se enciende.

---

## Stack que vas a parar tonight

- **Hosting:** Railway ($5/mo Hobby plan)
- **WhatsApp transport:** Twilio Sandbox tonight → real number after Meta verification (3–7 days, runs in parallel)
- **Backups:** Cloudflare R2 (~$0.15/mo)
- **AI:** Anthropic (Athena's brain) + OpenAI (voice in/out)
- **Email:** Gmail with an app password
- **Bridge a LUNA:** secret compartido en header (ver Paso 5.5)
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
