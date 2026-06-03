# Runbook de Sami

Todo lo que necesitas saber para mantener a Athena viva y operacional.

Última actualización: 3 de junio 2026

---

## Qué es esto

Soy Sami, la asistente humana de Isabel. **Athena** es la AI Chief of Staff. Mi rol con Athena: la mantengo viva. Le saco lo que ella no puede ejecutar sola (compras, comprobaciones manuales, contactos externos). Cuando algo se rompe, yo lo arreglo o escalo a quien lo arregle.

Este documento es mi cabecera de cama. Cuando Isabel me dice "Athena no contesta", abro esto.

---

## 🚨 Setup inicial — checklist de bloqueantes

Mientras estos NO estén verdes, la mitad de Athena no funciona. Ataco en este orden:

### 1. Crédito Anthropic (CRÍTICO — Athena no piensa sin esto)
→ https://console.anthropic.com/settings/billing
- Login con la cuenta que generó `ANTHROPIC_API_KEY`
- "Add credits" → mínimo $10 USD
- Activar **Auto-reload** para que no se vuelva a vaciar sin avisar
- **Síntoma cuando está roto:** Railway logs muestran `Your credit balance is too low to access the Anthropic API`

### 2. Crédito OpenAI (voz, transcripción, TTS)
→ https://platform.openai.com/settings/organization/billing/overview
- Login con la cuenta que generó `OPENAI_API_KEY`
- Add credits → $10 USD + Auto-reload
- **Síntoma:** voz no llega, transcripciones no fluyen. Log: `You exceeded your current quota`
- **Cuenta separada de ChatGPT Plus** — tener Plus NO da créditos API

### 3. APP_PASSWORD + APP_SECRET (acceso a la PWA web)
→ Railway → proyecto "athena" → Variables
- `APP_PASSWORD`: contraseña memorable para Isabel (ej. `Isabel2026Athena`)
- `APP_SECRET`: random 32+ chars. Genero con `openssl rand -base64 32`
- Mandar APP_PASSWORD por WhatsApp a Isabel
- **Síntoma cuando falta:** PWA `/app/` rebota al login y rechaza

---

## 🔧 Setup operacional (no bloqueante pero desbloquea features)

### 4. Gmail SMTP/IMAP (envío + lectura de email)
- Email: `connect@withisabelfuentes.com` (Google Workspace)
- En Railway:
  - `GMAIL_USER=connect@withisabelfuentes.com`
  - `GMAIL_APP_PASSWORD=<16 chars sin espacios>`
- Para generar el App Password:
  1. Login en https://myaccount.google.com con `connect@withisabelfuentes.com`
  2. Activar 2FA si no está → https://myaccount.google.com/signinoptions/two-step-verification
  3. Generar app password → https://myaccount.google.com/apppasswords
  4. App name: `Athena`. Copia los 16 chars sin espacios
- **Verificar funciona:** en logs busca `[idle] conectado, escuchando INBOX (... mensajes)`. Para SMTP, mandarle a Athena por WhatsApp: *"manda email a [mi email] que diga prueba"* → `envía`

### 5. Twilio SMS (mandar SMS a clientes no-WhatsApp)
→ https://console.twilio.com/us1/develop/phone-numbers/manage/search
- Buscar US, capability **SMS** (no solo voice)
- Comprar (~$1.15/mes)
- En Railway: `TWILIO_SMS_FROM=+1XXXXXXXXXX` (sin "whatsapp:" prefijo, sin espacios)
- **Síntoma:** errores Twilio "Mismatch" o "from number incompatible"

### 6. ISABEL_VOICE_PHONE (Athena LLAMA a Isabel por eventos críticos)
- En Railway: `ISABEL_VOICE_PHONE=+1XXXXXXXXXX` (el número de teléfono real de Isabel, sin "whatsapp:")
- Sin esto: Athena deriva el número quitando "whatsapp:" del `ISABEL_WHATSAPP` (puede funcionar si el WhatsApp es el mismo número que el celular)
- **Cuándo se usa:** eventos de Calendar con `[LLAMA]`, `[CALL]` o `🔔` en el título → Athena llama 15 min antes ADEMÁS del WhatsApp

### 7. ElevenLabs voice cloning (Athena contesta con voz de Isabel)
- Plan Starter ~$5/mes en https://elevenlabs.io
- Grabar 5 min de Isabel hablando en Spanglish natural → subir como Voice Clone
- En Railway:
  - `ELEVENLABS_API_KEY=<key>`
  - `ELEVENLABS_VOICE_ID=<id de la voz clonada>`
  - `TTS_PROVIDER=elevenlabs` (default era openai)
- **Sin esto:** Athena usa OpenAI nova (default genérica)

### 8. VAPID keys (push notifications al iPhone)
- En el server local: `cd server && node src/push.js --generate-keys`
- Pegar los 3 valores en Railway:
  - `VAPID_PUBLIC_KEY=<public>`
  - `VAPID_PRIVATE_KEY=<private>`
  - `VAPID_SUBJECT=mailto:isabel.insurance@gmail.com`
- Isabel debe instalar la PWA primero (Safari → Compartir → Añadir a pantalla de inicio)
- **Sin esto:** no hay alertas al iPhone cuando la PWA está cerrada

### 9. GITHUB_TOKEN (Athena propone mejoras como GitHub issues)
- Personal Access Token con scope `repo`
- En https://github.com/settings/tokens → Generate new token (classic) → Note: "Athena improvements"
- En Railway: `GITHUB_TOKEN=ghp_...`
- `GITHUB_REPO=isabelinsurance-design/Code-` (debe estar ya)

### 10. MCP_SERVERS (opcional — Athena tiene Canva, Notion, etc.)
- Cuando esté listo: JSON array en Railway
  ```
  MCP_SERVERS='[
    {"type":"url","url":"https://mcp.zapier.com/v1","name":"zapier","authorization_token":"sk_zap_..."},
    {"type":"url","url":"https://mcp.notion.com/v1","name":"notion","authorization_token":"secret_..."}
  ]'
  ```
- Scaffold ya está en código (`server/src/mcp_servers.js`). Solo falta el JSON real.

---

## 📞 Decisión pendiente: NEXTIVA

Isabel usa Nextiva para llamadas con clientes. La app de Nextiva da problemas. **Decisión necesaria con Isabel:**

| Opción | Pro | Contra |
|---|---|---|
| **A. Reemplazar con Twilio** | Ya configurado. Athena ya llama por Twilio. ElevenLabs da voz Isabel | Cambio de número operacional. Clientes conocen el viejo |
| **B. Mantener Nextiva** | Cero disrupción para clientes | Sigue rota la app. No integra con Athena |
| **C. Híbrido** | Athena = Twilio, Isabel = Nextiva. Cada quien su línea | 2 números visibles para clientes |
| **D. Integrar Nextiva API** | Mejor de todo | Depende de calidad API Nextiva |

**Recomendación:** D si Nextiva tiene API decente. C si no.

**Mi job:** investigar API Nextiva (docs, costo, OAuth, etc.) y presentar a Isabel con recomendación firme.

---

## 📋 Mis SOPs estándar (lo que ya hago / debo hacer)

### Cada mañana
- Revisar Railway dashboard rápido: deploy verde? logs limpios? memoria/CPU OK?
- Si Isabel pinguea "Athena no contesta" → ir a sección **Troubleshooting** abajo

### Cada viernes
- Verificar que el rapport semanal de Isabel se mandó 6pm (logs: `[rapport] semanal enviado`)
- Si Isabel no respondió, recordarle gentilmente

### Cada domingo
- Verificar que el self-grade corrió 8pm (`[self_grade] sem ... score N/100`)
- Si propuso un cambio + Isabel lo aprobó → IMPLEMENTARLO esta semana
  - Si es código: crear PR con Claude
  - Si es env var: actualizar Railway
  - Si es compra: ejecutar (Twilio, ElevenLabs, etc.)

### Cada lunes
- Revisar las tareas con `responsable=sami` en `/tareas` o slash `/tareas sami`
- Despachar las que dependen de mí esta semana
- Las que dependen de terceros → mandar email/llamar

### Cada mes
- Verificar balance Anthropic + OpenAI (que auto-reload haya prendido)
- Verificar backups en R2: que la última snapshot existe < 2h atrás
- Verificar logs Railway de los últimos 7 días por error patterns recurrentes

---

## 🔥 Troubleshooting — cuándo y qué revisar

### "Athena no contesta WhatsApp"
1. Railway dashboard → ¿deploy verde Active?
2. Logs últimos 5 min → buscar `[whatsapp]`
3. ¿Hay error `credit balance too low` (Anthropic)? → fix #1 setup
4. ¿Hay error de Twilio signature? → verificar `TWILIO_REQUIRE_SIGNATURE=true` y que el Twilio webhook apunta a la URL correcta
5. ¿Idempotencia? Mensaje duplicado a veces se ignora — pedirle a Isabel re-enviar con otro texto

### "PWA no carga / login falla"
1. Verificar URL: `https://athena-integrity-production.up.railway.app/app/`
2. Verificar APP_PASSWORD coincide con lo que Isabel teclea
3. Si "Cargando…" eterno: deploy crashed → Railway → Restart
4. Si ve dashboard pero datos vacíos: API auth probablemente OK pero data file vacío (post-deploy, antes de uso)

### "Email no envía"
1. En logs busca `[idle] conectado` → si NO está, IMAP fail → revisar `GMAIL_USER` + `GMAIL_APP_PASSWORD`
2. Si IMAP OK pero SMTP timeout: regenerar App Password (puede haber expirado)
3. Verificar 2FA sigue activo en la cuenta `connect@withisabelfuentes.com`

### "Voz no llega / voz fail"
1. OpenAI balance — fix #2 setup
2. Si ElevenLabs configured: verificar `ELEVENLABS_API_KEY` válido + créditos en ElevenLabs

### "Calendar no recuerda"
1. Verificar `GOOGLE_CLIENT_ID` + `GOOGLE_CLIENT_SECRET` + `GOOGLE_REFRESH_TOKEN` configurados
2. Refresh token expira a veces — necesito refrescar OAuth desde mi máquina

### "LUNA no responde"
1. WhatsApp Isabel: `/luna ping` → ¿devuelve "LUNA inalcanzable" o un número?
2. Si inalcanzable: verificar Bluehost que `luna_api.php` está vivo (hit URL en browser)
3. Verificar `LUNA_API_KEY` en Railway coincide con `LUNA_INTERNAL_KEY` en Bluehost env

### "Briefing matutino no llegó"
1. Es 6:30am hora SoCal? Verificar `TIMEZONE=America/Los_Angeles` en Railway
2. Logs: buscar `[briefing]` cerca de la hora esperada
3. Si `saltado: cap diario` → Isabel ya recibió 4 unsolicited ese día (cap normal)

---

## 🛠 Slash commands que YO puedo correr (desde mi WhatsApp)

Lista de comandos donde Sami está autorizada — todos empiezan con `/`. Algunos solo Isabel los puede correr.

| Comando | Qué hace |
|---|---|
| `/help` | Esta lista |
| `/briefing` | Dispara el briefing matutino manualmente |
| `/luna [ping]` | Verifica conexión a LUNA (briefing completo sin args, ping rápido con args) |
| `/gaps [alto|aviso|info]` | Ver qué huecos hay (compliance, datos faltantes) |
| `/signals` | Las señales que la reflexión nocturna detectó |
| `/agenda [hrs]` | Próximos eventos del calendar (default 24h) |
| `/pendientes` | Ver borradores que esperan "envía" |
| `/compromisos [persona]` | Promesas hacia Isabel (todas o por persona) |
| `/tareas [athena|isabel|sami]` | Cola de tareas por dueño |
| `/skills` | Playbooks activos |
| `/historial [n]` | Últimas N acciones que Athena hizo |
| `/huecos [dias]` | Huecos libres en calendar (default 7 días) |
| `/revisar <texto>` | Athena revisa un borrador mío antes de mandarlo (CMS, claims, tone) |
| `/rapport` | Forzar rapport ping a Isabel |
| `/research` | Forzar research digest |
| `/chase` | Forzar commitment chase ahora |
| `/reading [pending|leido|archivado]` | Ver reading list |
| `/trends` | Ver trends pendientes |
| `/scan` | Forzar trend scan ahora |
| `/grade` | Forzar self-grade ahora |
| `/mejora` | Digest Chief of Staff (propuestas + último grade) |

---

## 🗝 Variables de entorno — cheatsheet

| Variable | Para qué |
|---|---|
| `ANTHROPIC_API_KEY` | Cerebro de Athena |
| `OPENAI_API_KEY` | Whisper + TTS |
| `PUBLIC_URL` | URL pública para Twilio (`https://athena-integrity-production.up.railway.app`) |
| `TWILIO_ACCOUNT_SID` + `TWILIO_AUTH_TOKEN` | Twilio API |
| `TWILIO_WHATSAPP_FROM` | Número WhatsApp de envío (`whatsapp:+1...`) |
| `TWILIO_SMS_FROM` | Número SMS-capable (`+1...` sin prefijo) |
| `TWILIO_VOICE_FROM` | Número voice (puede ser el mismo que SMS) |
| `TWILIO_REQUIRE_SIGNATURE` | Seguridad — `true` en prod |
| `ISABEL_WHATSAPP` | Su WhatsApp (`whatsapp:+1...`) |
| `ISABEL_VOICE_PHONE` | Su teléfono para llamadas (`+1...` sin prefijo) |
| `ISABEL_NAME` | Nombre para firmas |
| `SAMI_EMAIL` | Mi email para que Athena me delegue |
| `SAMI_WHATSAPP` | Mi WhatsApp (`whatsapp:+1...`) |
| `TIMEZONE` | `America/Los_Angeles` |
| `APP_PASSWORD` + `APP_SECRET` | PWA access |
| `GMAIL_USER` + `GMAIL_APP_PASSWORD` | Email connect@withisabelfuentes.com |
| `LUNA_BASE_URL` + `LUNA_API_KEY` | Bridge a CRM |
| `GOOGLE_CLIENT_ID` + `GOOGLE_CLIENT_SECRET` + `GOOGLE_REFRESH_TOKEN` | Calendar |
| `VAPID_*` (3) | Push notifications |
| `ELEVENLABS_*` (2) | Voz clonada |
| `GITHUB_TOKEN` + `GITHUB_REPO` | Issues automáticos |
| `BACKUP_S3_*` (5) | R2 backups |
| `MCP_SERVERS` | JSON de servidores MCP |
| `RATE_LIMIT_PER_MIN` | Default 30 |
| `*_CRON` (varios) | Override horario de crons individuales |

---

## 📂 Dónde vive todo

- **Repo:** `github.com/isabelinsurance-design/Code-`
- **Rama activa:** `claude/sleepy-darwin-P4k2z`
- **Deploy:** Railway proyecto "athena"
- **URL pública:** `https://athena-integrity-production.up.railway.app`
- **PWA:** `https://athena-integrity-production.up.railway.app/app/`
- **Memoria persistente:** Volume mounted en `/app/data/` del container Railway
- **Backups:** Cloudflare R2 bucket configurado en `BACKUP_S3_*` env vars
- **CRM (LUNA):** `https://withisabelfuentes.com/luna/luna_api.php` (Bluehost)
- **Coaches HTML legacy:** `app/todoisabel.html` — single file standalone (no la usa Isabel ya, la PWA la reemplaza)
- **Manual de Athena:** `MANUAL_ATHENA.md` en la raíz del repo
- **Este runbook:** `RUNBOOK_SAMI.md` en la raíz del repo

---

## 🎯 Mi mantra

> Athena debe ser invisible cuando funciona. Cuando deja de funcionar, soy yo quien lo nota primero.

Mi job no es ejecutar cada tarea de Isabel — eso lo hace Athena. Mi job es que Athena siga viva, que tenga los recursos que necesita, y que cuando proponga un cambio (vía self-grade o lente Chief of Staff), yo lo evalúe e implemente o escale.
