# Para Sami — deploy final de Athena (1 jun 2026)

Hola Sami. Hubo mucho cambio en las últimas 24h. Este doc **reemplaza**
`PARA_SAMI_UPDATE.md` que te mandé antes. Sigue ESTE — el anterior está
incompleto.

**Si ya empezaste el deploy de ayer**: detenlo, no completes el deploy
parcial. Mejor hacer un deploy limpio con todo lo nuevo, así no metes
variables en orden y reinicias 4 veces.

**Tiempo estimado total**: 45-60 min.

---

## Resumen de qué tiene Athena ahora

Lo que ya estaba (Phase 1-13.5): WhatsApp, voice calls, email, calendar
read, multi-agente con 7 coaches especialistas, memory layered, LUNA
bridge, CRM compliance.

Lo nuevo de esta sesión (12 commits, branch `claude/sleepy-darwin-P4k2z`):

- 4 módulos nuevos: focus blocks, trust score, rutinas, legal calendar
- Research digest diario al mediodía
- Athena propone mejoras al código por email + GitHub issue
- **App web/PWA en `/app/`** con login + 8 vistas (Hoy, Chat, Brand,
  Agenda, Configura, Aprueba, Tareas, Wiki, Actividad)
- Push notifications (cuando Athena pinga, el iPhone suena nativo)
- Brand Marisol activada con pipeline YouTube/IG
- Compromisos en la app (UI para perseguir promesas de terceros)
- Doc de voice clone para que Isabel grabe (paso 7 abajo, opcional)

---

## PASO 1 — Git pull

```bash
cd <tu carpeta de Code-/>
git fetch origin
git checkout claude/sleepy-darwin-P4k2z
git pull origin claude/sleepy-darwin-P4k2z
```

Confirma que el último commit es `0c30a8e` o más nuevo:

```bash
git log -1 --oneline
```

---

## PASO 2 — Variables en Railway

Railway → tu proyecto Athena → **Variables** → **Raw editor**. Pega
estas 7 nuevas (todas necesarias para que la app web funcione):

```
# Web app + PWA (REQUIRED para /app/)
APP_PASSWORD=<lo que Isabel te diga — algo memorable para ella>
APP_SECRET=<corre en tu terminal: openssl rand -base64 32 y pega>

# Push notifications (REQUIRED para que el iPhone suene)
VAPID_PUBLIC_KEY=<viene del paso 3 abajo>
VAPID_PRIVATE_KEY=<viene del paso 3 abajo>
VAPID_SUBJECT=mailto:isabel.insurance@gmail.com

# GitHub issues (REQUIRED para que Athena pueda proponer mejoras)
GITHUB_TOKEN=<viene del paso 4 abajo>
GITHUB_REPO=isabelinsurance-design/Code-
```

**El `APP_PASSWORD` lo escoge Isabel** — pregúntale por WhatsApp /
llamada. NO inventes uno. Algo memorable para ella, no para ti.

---

## PASO 3 — Generar las VAPID keys (1 min)

En tu terminal local (no en Railway), con el repo ya clonado:

```bash
cd server
node src/push.js --generate-keys
```

Te imprime algo así:

```
Pega estas en Railway:

VAPID_PUBLIC_KEY=BJxxx...
VAPID_PRIVATE_KEY=Iyyy...
VAPID_SUBJECT=mailto:isabel.insurance@gmail.com
```

Pega los 3 valores en Railway. **Las llaves son únicas — guárdalas
una vez generadas; si las pierdes hay que regenerar y Isabel pierde sus
suscripciones de push.**

---

## PASO 4 — Sacar el GitHub Token (3 min)

1. Entra a https://github.com/settings/tokens con tu cuenta de GitHub
   (la que tiene acceso a `isabelinsurance-design/Code-`).
2. **Generate new token (classic)**.
3. Note: "Athena propone mejoras"
4. Expiration: **No expiration** (o 1 año si prefieres).
5. Scopes: marca `repo` (todo el bloque) y `workflow`.
6. **Generate token** → copia el `ghp_xxx...`
7. Pégalo en Railway como `GITHUB_TOKEN`.

Si saltas este paso, todo lo demás funciona. Solo se desactiva la
capacidad de Athena de abrir issues — el email a Isabel sí llega.

---

## PASO 5 — Redeploy

Si Railway está en auto-deploy de la rama `claude/sleepy-darwin-P4k2z`,
agregar las variables ya dispara el redeploy. Si no, click **Deploy**.

**El build tarda 3-4 min** (1 min de server, 2-3 min de compilar la
React app con Vite). En los logs vas a ver:

```
> npm install (server deps)
> npm run build:app
> cd ../app-v2 && npm install && npm run build
✓ built in 1.35s
PWA v0.21.2
mode      injectManifest
precache  18 entries
> npm start
[api] endpoints REST montados en /api/*
[app] React app servido desde /home/.../server/public/app en /app
[cron] briefing programado: "30 6 * * *" (America/Los_Angeles)
[cron] research programado: "0 12 * * *" (America/Los_Angeles)
... (resto de crons)
```

Si NO aparecen las líneas `[api]` y `[app]`, algo falló — mándame los
logs completos del deploy.

---

## PASO 6 — Verificar (5 min)

### a) WhatsApp sigue funcionando

Manda a Athena por WhatsApp: "hola, estás despierta?". Debe contestar
en menos de 30 segundos.

### b) La web app carga

En tu navegador (laptop primero):
```
https://<la-url-de-railway>/app/
```

Debe aparecer un login con fondo lino + "Athena" en serif. Mete el
`APP_PASSWORD` → debe entrar a la pantalla **Hoy** con trust score.

### c) Isabel la instala en su iPhone

1. **Compártele el link** a Isabel por WhatsApp.
2. Ella lo abre en **Safari** del iPhone (NO Chrome ni el WhatsApp
   browser — tiene que ser Safari nativo o la PWA no se instala bien).
3. Hace login con el `APP_PASSWORD`.
4. Botón **Compartir** (cuadrito con flecha) → **Añadir a pantalla
   de inicio**.
5. En su home screen aparece un ícono "A" en lino dorado.

### d) Push notifications

1. Isabel abre la app desde el ícono (no desde Safari).
2. En la pantalla **Hoy**, hay una card "Notificaciones push" con
   botón **Activar**.
3. Click → iOS le pregunta si permite notificaciones → **Permitir**.
4. La card cambia y aparece botón **Probar**.
5. Click **Probar** → debe llegar una notificación nativa al iPhone
   en menos de 5 segundos.

Si la notificación NO llega:
- Confirma que las 3 VAPID vars están en Railway sin espacios.
- Pídele a Isabel que verifique en Ajustes iOS → Athena → Notifs →
  permitido.

---

## PASO 7 — Voice clone (OPCIONAL, lo hace Isabel)

Mira el archivo `docs/VOICE_CLONE_SETUP.md` en el repo. Es para que
Isabel grabe 15 min de su voz y la sintetice en ElevenLabs. Después
te manda los valores y los pones en Railway:

```
TTS_PROVIDER=elevenlabs
ELEVENLABS_API_KEY=sk_...
ELEVENLABS_VOICE_ID=...
ELEVENLABS_MODEL=eleven_flash_v2_5
```

Esto es totalmente opcional. Sin esto Athena sigue usando voz OpenAI
TTS-1 (calidad alta pero genérica, no la voz de Isabel).

---

## PASO 8 — Avisarle a Isabel qué hacer primero

Cuando esté todo arriba, mándale esto:

> Isabel, Athena está full deployed. Lo que te recomiendo hacer
> primero en la app:
>
> 1. Pestaña **Configura → Research**: dale "Sembrar 3 defaults"
>    (Medicare News + Brand Latina + Insurance Industry). Mañana al
>    mediodía recibes el primer digest.
>
> 2. **Configura → Focus blocks**: crea uno o dos bloques de tiempo
>    protegido (ej. "Lectura noche" 9:30–10:30pm L-V).
>
> 3. **Configura → Rutinas**: crea tu morning ritual (los pasos que
>    tienes en la cabeza).
>
> 4. **Wiki & Temporada**: actualiza tu temporada para junio (1-2
>    oraciones).
>
> 5. **Brand**: si quieres arrancar el pipeline de YouTube/IG,
>    habla con Brand Marisol en **Chat** — pídele "tírame 5 ideas
>    para mi canal este mes" y de ahí las metes al backlog.

---

## Resumen rápido para Isabel

**4 env vars que necesito SÍ o SÍ saber de ella:**

1. `APP_PASSWORD` — el password para entrar a la app.

Las otras 6 (`APP_SECRET`, las 3 VAPID, `GITHUB_TOKEN`, `GITHUB_REPO`)
las genero/saco yo.

---

## Si algo se rompe

1. **Build falla en Railway**:
   - Lo más probable es que falle `npm run build:app` por falta de
     deps. Verifica que el `postinstall` corra `cd ../app-v2 &&
     npm install`. Mándame los logs si no.
   - El `postinstall` tiene fallback: aunque falle vite, Athena arranca
     igual — solo `/app/` no existe. WhatsApp sigue ok.

2. **WhatsApp dejó de responder**: NO debería pasar. Si pasa, hago
   rollback. Mándame `git log` del Railway deploy.

3. **App web carga pero login no entra**:
   - Verifica `APP_PASSWORD` y `APP_SECRET` sin comillas / sin espacios.
   - Cookie del browser puede estar caché: pide hard refresh o tab
     incógnito.

4. **PWA no instala en iPhone**: Safari requiere HTTPS. Confirma que
   la URL de Railway es `https://`, no `http://`.

5. **Push test no llega**: las VAPID keys son sensibles a espacios.
   Mejor regenerarlas con `node src/push.js --generate-keys` y pegar
   limpio.

Cualquier duda mándame WhatsApp.

— Claude
