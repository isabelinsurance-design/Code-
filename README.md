# Todo Isabel — El Imperio 👑

Tu sistema personal de coaches con IA, más **La Directora**: tu chief of staff
autónoma que te escribe por WhatsApp, delega a las coaches, manda y revisa
correos, le pasa tareas a Sami, y te busca cada mañana — sin que abras nada.

Este repo tiene **dos partes**:

| Carpeta | Qué es | Dónde corre |
|---|---|---|
| `app/` | La app de los 17 coaches (un archivo HTML) | Tu teléfono / navegador |
| `server/` | La Directora autónoma (Node.js) | La nube (siempre prendida) |

---

## 1. La app (`app/todoisabel.html`)

Es la app que ya tenías, con dos bugs arreglados:

- **El chat ya funciona** — le faltaba un header (`anthropic-dangerous-direct-browser-access`) que el navegador exige para hablar con la API de Anthropic. Sin él, ningún coach respondía.
- **La voz ya funciona** — los botones de "agregar tarea por voz" y "hablar por voz" llamaban a una función `startVoice()` que no existía. La creé.

**Para usarla:** abre `app/todoisabel.html` en Chrome o Safari, pon tu API Key
de Anthropic cuando te la pida, y listo. Todo se guarda en tu navegador.

> ⚠️ Tu API Key vive en el navegador. Está bien para uso personal en tu
> teléfono, pero no publiques este HTML en un sitio público con tu key dentro.

---

## 2. La Directora autónoma (`server/`)

Aquí está la magia que pediste: una IA que **te busca a ti**, no al revés.

### Cómo funciona (el concepto importante)

Una app en el navegador solo "vive" mientras la tienes abierta. Para que las
coaches te escriban **aunque tengas todo cerrado**, necesitas algo que viva
**fuera** de tu teléfono y nunca se duerma: un **servidor en la nube**.

```
   Tú (WhatsApp)
        │  escribes / contestas
        ▼
     Twilio  ──────────►  Servidor en la nube (Node.js)
        ▲                       │
        │  respuesta            ▼
        │                 LA DIRECTORA (Claude Opus 4.7)
        │                       │  decide qué hacer
        │         ┌─────────────┼─────────────┬──────────────┐
        │         ▼             ▼             ▼              ▼
        │   consulta a     manda tarea     manda/revisa   guarda en
        │   especialista   a Sami          tu email       memoria
        │   (Carmen,                                      (Isabel Wiki)
        │    Rivera, María...)
        │
        └─ Cada mañana, La Directora te escribe sola (cron)
```

La Directora es el **cerebro central**. Tú solo hablas con ella. Ella decide
cuándo consultar a Carmen (comida), Rivera (ejercicio), María (Medicare),
Elena (dinero), etc., y cuándo pasarle algo a Sami.

### Lo que necesitas (cuentas)

1. **Anthropic API key** — la que ya tienes (https://console.anthropic.com)
2. **Twilio** — ya la tienes. Activa el **WhatsApp Sandbox** (gratis para probar):
   Twilio Console → Messaging → Try it out → Send a WhatsApp message.
   Te da un número y un código para conectar tu WhatsApp.
3. Un lugar donde correr el servidor 24/7: **Railway** o **Render** (~$5/mes).

### Configurar y correr

```bash
cd server
cp .env.example .env      # luego edita .env con tus datos reales
npm install
npm start
```

Llena el `.env` con:
- Tu `ANTHROPIC_API_KEY`
- Tus credenciales de Twilio (`TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`)
- `TWILIO_WHATSAPP_FROM` = el número de WhatsApp de Twilio
- `ISABEL_WHATSAPP` = tu WhatsApp (a donde llega el briefing de la mañana)
- `SAMI_WHATSAPP` = el WhatsApp/SMS de Sami
- (Opcional) `GMAIL_USER` + `GMAIL_APP_PASSWORD` para activar los correos

### Conectar WhatsApp a tu servidor

1. Despliega el servidor en Railway/Render → te da una URL pública
   (ej. `https://todo-isabel.up.railway.app`).
2. En Twilio, en la configuración del WhatsApp Sandbox, pon como
   **"When a message comes in"**:  `https://TU-URL/whatsapp`  (método POST).
3. Mándale un WhatsApp a tu número de Twilio. La Directora contesta. 🎉

### Probar el briefing de la mañana sin esperar

```bash
cd server
npm run briefing
```

(En producción se manda solo a la hora de `MORNING_BRIEFING_CRON`, por defecto
6:30 AM hora de California.)

---

## Notas importantes

**Costo del modelo.** La Directora usa **Claude Opus 4.7** (el más inteligente).
Si mandas muchos mensajes al día y quieres bajar costos, cambia `DIRECTORA_MODEL`
en el `.env` a `claude-sonnet-4-6` (más barato, casi igual de bueno para esto).

**Memoria permanente.** Ahora la memoria se guarda en `server/data/*.json`. En
Railway ese disco se borra al re-desplegar. Para memoria 100% permanente, lo
siguiente sería conectar un **volumen de Railway** o una base de datos
(Supabase/Postgres). Lo dejé listo para ese paso.

**Email.** Si pones tus datos de Gmail (con una *contraseña de aplicación*:
https://myaccount.google.com/apppasswords), La Directora puede leer y mandar
correos. Si no, simplemente te avisa que no está configurado y todo lo demás
sigue funcionando.

**Seguridad del webhook.** Para producción, pon `VERIFY_TWILIO_SIGNATURE=true`
y `PUBLIC_URL` con tu URL real, para que solo Twilio pueda mandarle mensajes a
tu servidor.

---

## Lo que sigue (cuando quieras)

- [ ] Memoria permanente con base de datos (Supabase)
- [ ] Que La Directora pueda hacer **llamadas** de voz (VAPI.ai)
- [ ] Más briefings durante el día (mediodía, noche)
- [ ] Que las coaches de la app y las del servidor compartan la misma memoria
