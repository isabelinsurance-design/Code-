# Para Isabel — ANTES de que Sami se vaya (13 jun 2026)

> Lo más importante de hoy. Si Sami se va con los accesos, eso es lo ÚNICO que no se
> puede recuperar. El código ya está a salvo en git; los accesos están en su cabeza.

## 1. 🔑 LO PRIMERO — pídele a Sami estos accesos y guárdalos TÚ

Pídele a Sami que te dé usuario y contraseña (o que te agregue como dueña/admin) de cada uno.
Guárdalos en un lugar seguro (un gestor de contraseñas, o escritos donde solo tú llegues):

- **Railway** — donde vive Athena. (Cuenta + en qué proyecto está Athena.) ← EL MÁS IMPORTANTE.
- **Bluehost / cPanel** — donde vive LUNA (el CRM del equipo). Usuario de cPanel.
- **Cloudflare R2** — donde están los backups de Athena (las llaves BACKUP_S3_*).
- **Anthropic** (console.anthropic.com) — el cerebro de Athena, donde se recarga el saldo.
- **OpenAI** (platform.openai.com) — voz y transcripción.
- **Twilio** — WhatsApp y llamadas.
- **Gmail** — la cuenta isabel.insurance@gmail.com y su "app password" de 16 letras.
- **Google Calendar** — los permisos (client id / refresh token) si Sami los configuró.

Consejo: pídele a Sami que se SIENTE contigo 20 minutos y entren juntas a cada uno para
confirmar que entras tú sola. Una vez que ella se va, recuperar un acceso perdido puede
tardar días o ser imposible.

## 2. Cómo desplegar tú sola (Railway, sin Sami)

1. Entra a Railway → tu proyecto Athena.
2. Pestaña **Deployments**. Railway normalmente vuelve a desplegar solo cada vez que se
   sube código nuevo (que es lo que yo ya hice). Confirma que el deploy más reciente sea de
   hoy. Si no, hay un botón **Deploy / Redeploy** — apriétalo.
3. Revisa **Logs**: debe arrancar sin "crash" ni "Error". Verás varias líneas normales.

## 3. 🚨 EL VOLUMEN — por qué Athena no guardaba tus cosas

En Railway, si NO hay un "volumen" montado, cada deploy le BORRA la memoria a Athena.

1. Railway → tu proyecto → pestaña **Volumes**.
2. Debe existir un volumen montado EXACTAMENTE en `/app/server/data`. Si no está:
   **+ New Volume** → mount path `/app/server/data` → 1 GB. Repite con `/app/server/backups`.
3. Después de un deploy, en los Logs busca: `[persistencia] OK — data/ sobrevivió`.
   Si en cada deploy ves `[persistencia] ⚠️ data/ parece EFÍMERO`, el volumen NO quedó bien.

(Ya hay red de seguridad: si la memoria arranca vacía pero hay backup, Athena se recupera
sola — verás `[restore] ✅ memoria recuperada` en los logs. Pero el volumen evita perderla
de entrada, así que es lo primero.)

## 4. Dos variables en Railway (pestaña Variables)

- `APP_SECRET` = cualquier texto largo al azar. (Si no lo pones, no se rompe nada, pero la
  sesión del PWA se cierra en cada deploy.)
- `LUNA_API_KEY` = la llave del bridge. **Hay que ROTARLA** porque la vieja se vio en un chat.
  Genera una nueva (pídele a alguien técnico `openssl rand -hex 32`, o yo te la genero), ponla
  en Railway Y en Bluehost (`luna_config.php`, la línea `LUNA_SERVICE_KEY`), y verifica en
  https://withisabelfuentes.com/luna/luna_diag.php que diga conecta: true.

## 5. Recargar Anthropic (sin esto Athena no contesta)

console.anthropic.com → Billing → recargar saldo + activar auto-recharge. Esto es tuyo, no de Sami.

## 6. Cómo confirmar que TODO quedó bien

- Mándale WhatsApp a Athena: "dame el reporte de tickets abiertos" o cualquier cosa. Si
  contesta normal → el cerebro y el saldo están bien.
- El email de equipo de las 6am (al inbox de preview) debe traer citas/seguimientos, no "tickets".
- En los logs de Railway: `[persistencia] OK` y, si hizo falta, `[restore] ✅`.

## En una frase

El código ya está blindado y a salvo en git. Lo único irrecuperable si Sami se va son los
ACCESOS — consíguelos hoy. Lo demás (deploy, volumen, variables) lo puedes hacer tú sola
siguiendo esta guía, o conmigo al lado.
</content>
