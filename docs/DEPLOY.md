# Desplegar SAMIA en Railway (always-on)

SAMIA es un backend Node **zero-dependencias** (solo runtime, sin `npm install` de
paquetes). Esta guia lo pone a correr 24/7 en [Railway](https://railway.app) para que
el **scheduler** de la Fase 5 (briefing 06:30, reflexion 02:00, repaso semanal, tick
horario) dispare de verdad — algo que un sandbox efimero no puede hacer.

> **Por que importa el volumen:** el contenedor de Railway tiene un disco **efimero**.
> Sin un volumen persistente, la memoria de SAMIA (`data/*.json`: personas, señales,
> compromisos, audit log) **se borra en cada redeploy o reinicio**. El paso 4 monta un
> volumen y apunta `DATA_DIR` ahi para que la memoria sobreviva.

---

## Qué ya está listo en el repo

- `package.json` → `npm start` corre `node --env-file-if-exists=.env server/index.js`.
  Carga `.env` si existe (local) y continua sin el si no (Railway inyecta las vars).
- `railway.json` → builder Nixpacks, start `npm start`, **healthcheck en `/api/health`**,
  reinicio ante fallo.
- `nixpacks.toml` → fija **Node 22**.
- `Procfile` → `web: npm start` (portabilidad a otros PaaS).
- `DATA_DIR` es **overridable por entorno** (apunta al volumen).

---

## Pasos

### 1. Crear el proyecto
- Railway → **New Project → Deploy from GitHub repo** → elige este repo y la rama
  (`claude/great-davinci-OWzcr` o la que mergees a `main`).
- Railway detecta Node por Nixpacks y construye solo (no hay build step real).

### 2. Variables de entorno
En **Variables**, agrega:

| Variable | Valor | Nota |
|---|---|---|
| `ANTHROPIC_API_KEY` | `sk-ant-…` | **Requerida** para los modos LLM (chat, captura Haiku, reescritura). Sin ella, los caminos deterministas siguen funcionando. |
| `DATA_DIR` | `/data` | Debe coincidir con el mount del volumen (paso 4). |

Opcionales (tienen default): `MAX_TOKENS`, `ANTHROPIC_BASE_URL`, `MODEL_*`.
**No** fijes `PORT` — Railway lo inyecta y el server ya lo lee (`process.env.PORT`).

### 3. Exponer el dominio
- **Settings → Networking → Generate Domain**. Railway enruta el dominio publico al
  `PORT` que inyecta; el server escucha en ese puerto en todas las interfaces.

### 4. Volumen persistente (memoria)
- **Variables/Storage → New Volume**. Mount path: **`/data`**.
- Asegura que la variable `DATA_DIR=/data` (paso 2) apunte a ese mismo path.
- Tras esto, `data/*.json` vive en el volumen y sobrevive a redeploys.

### 5. Verificar
```bash
curl https://TU-DOMINIO.up.railway.app/api/health      # -> {"ok":true,...}
```
- Abre `https://TU-DOMINIO.up.railway.app/` → la escuela. `/dashboard` → el panel.
- En el panel, seccion **Sistema**: deberias ver el scheduler **activo** y, tras unos
  minutos, ejecuciones del *task tick* horario. El briefing se genera 06:30 hora del
  servidor (UTC por defecto — ver nota de zona horaria).

---

## Notas

- **Zona horaria:** el contenedor corre en **UTC** por defecto, asi que "06:30" es UTC.
  Para hora de California, agrega la variable `TZ=America/Los_Angeles`.
- **Costo:** un backend zero-dep en reposo consume muy poco; el plan de hobby de
  Railway suele alcanzar. El scheduler solo hace trabajo ligero cada hora.
- **Secretos:** nunca subas `.env` (ya esta en `.gitignore`). La key vive solo en las
  Variables de Railway.
- **Backups (Patron #27, pendiente):** aun no hay snapshot automatico de `data/`. Para
  empezar, basta con que el volumen persista; un backup programado es una mejora futura.
- **Otros PaaS:** el `Procfile` permite desplegar igual en Render/Fly/Heroku-likes;
  solo replica las env vars y monta un disco en el path de `DATA_DIR`.
