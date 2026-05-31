# Para Sami — actualización del deploy (1 jun 2026)

Hola Sami. Ayer arrancaste el deploy de Athena. Desde entonces (commit
`bfa5d90`) hay 3 cambios que necesitas saber. **No es rehacer nada — son
sumas a lo que ya tienes.**

---

## Resumen en 60 segundos

1. **Athena ahora tiene una web app/PWA en `/app/`** — Isabel la instala en su
   iPhone como app nativa. WhatsApp sigue funcionando igual; la app es
   adicional.
2. **Hay 4 módulos nuevos** (focus blocks / trust score / rutinas / legal
   calendar) y un **research digest** que corre al mediodía.
3. **Athena ahora puede proponer mejoras a su propio código** vía email y
   GitHub issues — tú solo le das el `GITHUB_TOKEN`.

Cero cambios en DNS, Twilio, Gmail. Lo que hiciste ayer sigue funcionando.

---

## Pasos concretos (orden importa)

### 1. Hacer `git pull` en tu carpeta local

```bash
cd <carpeta donde tienes Code-/>
git checkout claude/sleepy-darwin-P4k2z
git pull origin claude/sleepy-darwin-P4k2z
```

Debes ver los últimos 6 commits hasta `bfa5d90 PWA polish — íconos instalables`.

### 2. Agregar 4 variables NUEVAS en Railway

Railway → Variables → Add. Pega estas 4:

```
APP_PASSWORD=<lo que Isabel escoja, ej: "MiContraseñaAthena2026">
APP_SECRET=<corre en tu terminal: openssl rand -base64 32 — pega el resultado>
GITHUB_TOKEN=<ver paso 3 abajo>
GITHUB_REPO=isabelinsurance-design/Code-
```

- `APP_PASSWORD` es lo que Isabel va a teclear en su iPhone para entrar a la
  app. Se lo dices a Isabel directamente, NO me lo mandas a mí.
- `APP_SECRET` es la llave que firma el cookie de sesión. Genera uno random
  (`openssl rand -base64 32` te da un string de 44 caracteres). No tiene
  que ser memorable.

### 3. Sacar el `GITHUB_TOKEN` (3 min)

1. Entra a https://github.com/settings/tokens (con tu cuenta de GitHub que
   tiene acceso a `isabelinsurance-design/Code-`).
2. Click **Generate new token (classic)**.
3. Note: "Athena propone mejoras"
4. Expiration: **No expiration** (o 1 año, como prefieras).
5. Scopes: marca **`repo`** (todo el bloque) y **`workflow`**.
6. Generate token → copia el `ghp_xxx...` que te da.
7. Pégalo en Railway como `GITHUB_TOKEN`.

Si saltas este paso, Athena seguirá funcionando 100%. Solo perdería la
capacidad de abrir GitHub issues — el email a Isabel le llegaría igual.

### 4. (Opcional) Variables más nuevas que también puedes agregar

```
RESEARCH_DIGEST_CRON=0 12 * * *    # default mediodía hora Isabel, cambia si quieres
```

Si no pones nada, usa los defaults.

### 5. Empuja el redeploy en Railway

Si Railway ya está auto-deploy de la rama `claude/sleepy-darwin-P4k2z`,
solo agregar las variables ya dispara el redeploy. Si no, click **Deploy**
en Railway.

**El build va a tardar 2-3 min** (es 1 min más que antes — el extra es la
compilación de la app React, que corre automática vía `postinstall`).

En los logs vas a ver:

```
> npm install (instala deps de server)
> npm run build:app  (entra a app-v2/, instala, vite build)
✓ built in 1.35s
> npm start
[api] endpoints REST montados en /api/*
[app] React app servido desde /app/.../public/app en /app
```

Si NO ves esas 2 últimas líneas, algo se rompió — mándame los logs.

### 6. Verifica que la app web funciona (2 min)

1. En tu navegador (no del iPhone — en tu laptop primero):
   `https://<la-url-de-railway>/app/`
2. Debe aparecer un login con fondo lino y "Athena" en serif.
3. Mete el `APP_PASSWORD` que pusiste en Railway → debe entrar a la
   pantalla **Hoy** con el trust score.
4. Si entras → la app funciona. Avísale a Isabel.

### 7. Isabel instala la app en su iPhone (1 min — se lo dices a ella)

1. Safari iPhone → `https://<url-railway>/app/`
2. Hacer login con el password.
3. Botón **Compartir** (cuadrito con flecha arriba) → **Añadir a pantalla
   de inicio**.
4. Listo: tiene un ícono "A" en lino dorado en su home screen, abre la app
   en modo standalone (sin barra del browser).

---

## Lo que sigue funcionando igual (no toques nada)

- WhatsApp con Athena — mismo número, mismo prompt, mismo cerebro.
- Cron jobs (briefing 6:30am, evening 9pm, etc.) — siguen iguales.
- LUNA bridge — sigue igual.
- Voice calls de Twilio — siguen iguales.
- Dashboard viejo en `/dashboard` (read-only) — sigue funcionando.

---

## Si algo se rompe

1. **Build falla en Railway**: mándame los logs completos del deploy. Lo más
   probable: alguna dep nueva no se instaló. El `postinstall` tiene
   fallback que NO rompe el deploy si Vite falla — Athena arranca igual,
   solo `/app/` no existe.

2. **App web carga pero no login**: revisa que `APP_PASSWORD` y `APP_SECRET`
   estén bien en Railway (sin comillas alrededor, sin espacios).

3. **Login funciona pero `/app/` aparece en blanco**: hard refresh (Cmd+Shift+R
   en laptop, o desinstalar y reinstalar la PWA en iPhone). Es el service
   worker viejo cacheado.

4. **WhatsApp dejó de responder**: NO debería pasar — los cambios son aditivos.
   Si pasó, dime y revertimos.

---

## Para Isabel — qué hacer después del deploy

1. Abre la app, ve a **Configura → Research** y dale "Sembrar 3 defaults"
   (Medicare News + Brand Latina + Insurance Industry). Empieza el digest
   diario al mediodía siguiente.
2. Ve a **Configura → Focus blocks** y crea uno o dos bloques de tiempo
   protegido (ej: "Lectura noche" 9:30–10:30pm L-V).
3. Ve a **Configura → Rutinas** y crea tu morning ritual (los pasos que
   ya tienes en la cabeza).
4. **Wiki & Temporada**: actualiza la temporada para junio. 1-2 oraciones.

Eso es todo. Los demás módulos se llenan solos con el uso.
