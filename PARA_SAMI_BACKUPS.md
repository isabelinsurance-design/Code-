# Para Sami — Volumen persistente + Backups R2 (URGENTE)

**Hola Sami.** Sin esto, cada redeploy borra todo lo que Isabel le ha contado a Athena. Es la primera tarea antes de seguir.

Tiempo total: ~25 minutos.

---

## PARTE 1 — Volumen persistente en Railway (5 min, OBLIGATORIO)

Esto evita que Athena pierda sus datos cuando hagas redeploy.

### Pasos:

1. Entra a **railway.app** → tu proyecto Athena
2. Click en el **service de Athena** (la card del server, no la de Postgres si tienes)
3. En la parte superior: **Settings**
4. Baja hasta la sección **Volumes** (o "Storage")
5. Click **+ New Volume**
6. Configura:
   - **Mount path:** `/app/server/data`
   - **Size:** `1 GB` (basta de sobra para empezar)
7. Click **Add** (o Save)
8. Railway va a redeployar automáticamente con el volumen montado. Espera ~2 min al "Deployed" en verde.

### Verifica que funcionó:

En los logs del nuevo deploy debes ver normalmente el startup de Athena, sin errores. El volumen es transparente — no hay log especial diciendo "volume mounted".

**Para probar que persiste:**
1. Mándale por WhatsApp a Athena: "Recuerda que mi color favorito es azul"
2. Espera que responda confirmando
3. Disparar otro redeploy (cualquier cambio menor que dispare deploy)
4. Después del redeploy, mándale: "¿De qué color era mi color favorito?"
5. Si responde "azul" → volumen funciona ✓
6. Si dice "no recuerdo" → el mount path está mal, revísalo

---

## PARTE 2 — Backups a Cloudflare R2 (20 min, ALTAMENTE RECOMENDADO)

Backup automático cada hora a almacenamiento offsite. Si Railway se cae o el volumen se corrompe, los datos siguen vivos en R2.

R2 es **prácticamente gratis** — 10 GB de almacenamiento gratis para siempre, y todos los datos de Athena pesan unos pocos MB.

### Paso 1 — Crear cuenta Cloudflare (3 min)

Si ya tienes cuenta de Cloudflare, salta al paso 2.

1. Ve a https://dash.cloudflare.com/sign-up
2. Crea cuenta con `isabel.insurance@gmail.com` (o tu email)
3. Confirma email
4. Login

### Paso 2 — Activar R2 (2 min)

1. En el dashboard de Cloudflare, **panel izquierdo** → **R2**
2. Click **Purchase R2** o **Enable R2**
3. Cloudflare te pide agregar método de pago (tarjeta) — necesario aunque el uso sea gratis. Ponla.
4. **Plan Free** queda automáticamente activo. NO te cobran nada hasta que pases de los 10 GB.

### Paso 3 — Crear el bucket (1 min)

1. R2 dashboard → **Create bucket**
2. **Bucket name:** `athena-backups`
3. **Location:** Automatic (o "North America" si ofrece la opción)
4. Click **Create bucket**

### Paso 4 — Crear API Token (3 min)

1. R2 dashboard → arriba a la derecha → **Manage R2 API Tokens**
   (o panel izquierdo → R2 → API)
2. Click **Create API token**
3. Configura:
   - **Token name:** `athena-backups`
   - **Permissions:** `Object Read & Write`
   - **Specify bucket:** `athena-backups` (selecciona del dropdown — NO dejar "all buckets")
   - **TTL:** Forever (o sin expiración)
4. Click **Create API Token**
5. Cloudflare te muestra UNA VEZ las llaves. **Copia los 3 valores:**
   - `Access Key ID`
   - `Secret Access Key`
   - `Endpoint` (parecido a `https://abc123...r2.cloudflarestorage.com`)

**IMPORTANTE: si cierras esa pantalla pierdes el Secret Access Key. Cópialo a un sitio seguro (1Password / nota privada). Si lo pierdes, hay que generar uno nuevo.**

### Paso 5 — Pegar en Railway (2 min)

Railway → tu proyecto Athena → **Variables** → agrega estas 5:

```
BACKUP_S3_ENDPOINT=<el endpoint que copiaste, ej: https://abc123.r2.cloudflarestorage.com>
BACKUP_S3_BUCKET=athena-backups
BACKUP_S3_REGION=auto
BACKUP_S3_ACCESS_KEY_ID=<el Access Key ID>
BACKUP_S3_SECRET_ACCESS_KEY=<el Secret Access Key>
```

Save → Railway redeploya automático.

### Paso 6 — Verificar (2 min)

Después del redeploy, los logs deben mostrar el cron de backup:

```
[cron] backup programado: "15 * * * *" (America/Los_Angeles)
```

A los pocos minutos (próximo :15 de la hora), debe correr el primer backup:

```
[backup] snapshot OK athena-backups-2026-06-01-13-15-00.tar.gz (sync ✓)
```

El `(sync ✓)` al final confirma que se subió a R2.

**Para verificar en R2:**
1. Cloudflare R2 dashboard → tu bucket `athena-backups`
2. Debe aparecer al menos un archivo `.tar.gz` con la fecha

---

## PARTE 3 — Recuperar si algún día algo se corrompe

Si algún día Athena pierde datos o se corrompe:

1. Descarga el último backup de R2 (el `.tar.gz` más reciente)
2. Extrae los archivos `data/*.json`
3. Sube los archivos al volumen de Railway:
   - Railway CLI: `railway run cp local/data.json /app/server/data/data.json`
   - O usa la consola de Railway si está activa
4. Reinicia el servicio

Athena vuelve a tener todos los datos del backup.

---

## Resumen — qué cambia con esto

**ANTES (lo que tenías):**
- Cada redeploy → Athena olvida todo
- Si Railway se cae → pérdida total
- No hay forma de "rollback" si algo se corrompe

**DESPUÉS:**
- Cada redeploy → Athena recuerda todo (volumen persistente)
- Backup horario offsite (R2)
- Recuperación posible desde cualquier hora previa

**Costo extra mensual:** $0 (R2 free tier + volumen Railway está dentro del plan Hobby de $5/mo que ya tienes).

---

## Si algo se rompe

- Si los logs de Railway NO muestran el cron de backup → revisa las 5 env vars de R2
- Si el cron corre pero NO sube a R2 → revisa el Endpoint URL (debe terminar en `.r2.cloudflarestorage.com`, sin slash final)
- Si dice "Access Denied" → el API token no tiene permisos sobre `athena-backups`. Regenera con el bucket específico seleccionado.

Cualquier duda mándame WhatsApp.

— Claude
