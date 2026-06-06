# Conectar el repositorio a Bluehost (despliegue automático)

Después de esto, **cada cambio se sube solo** a Bluehost. Ya no hay que bajar
y subir archivos a mano. Lo hace Sami **una sola vez** (~10 min).

## Qué hace
Cuando se sube un cambio a la carpeta `luna/`, GitHub lo **publica solo** en
Bluehost por FTP (solo la carpeta `luna/`; no toca nada más del sitio).
**NUNCA** sobrescribe `luna_config.php` (las llaves reales quedan intactas).

---

## PASO 1 — Sami consigue los datos de FTP (cPanel)
En Bluehost → cPanel → **FTP Accounts** (Cuentas FTP):
- **Servidor (host):** algo como `ftp.tudominio.com` o la IP del servidor (aparece en "Configure FTP Client").
- **Usuario:** el usuario FTP (ej. `usuario@tudominio.com`).
- **Contraseña:** la de ese usuario FTP (si no la recuerda, puede crear una cuenta FTP nueva ahí mismo).
- **Ruta de la carpeta luna:** la ruta hasta `…/website_5a1c69e7/luna/` **como la ve el FTP**, terminando en `/`.
  Casi siempre es `./public_html/website_5a1c69e7/luna/` (o `/public_html/website_5a1c69e7/luna/`).

## PASO 2 — Guardar esos datos como "Secrets" en GitHub
En GitHub: repo **isabelinsurance-design/Code-** → **Settings** → (menú izq.)
**Secrets and variables** → **Actions** → botón **New repository secret**.

Crea estos **4 secretos** (nombre exacto, mayúsculas):

| Nombre del secret | Valor |
|---|---|
| `FTP_SERVER` | el host FTP (paso 1) |
| `FTP_USERNAME` | el usuario FTP |
| `FTP_PASSWORD` | la contraseña FTP |
| `FTP_LUNA_DIR` | la ruta a la carpeta luna, ej. `./public_html/website_5a1c69e7/luna/` |

> 🔒 Los "secrets" van encriptados en GitHub. No se ven en el código ni en los logs.

## PASO 3 — Probar
En GitHub → pestaña **Actions** → workflow **"Desplegar LUNA a Bluehost"** →
botón **Run workflow** → elige la rama → **Run**.
- Si sale ✅ verde → ¡quedó! Cada cambio futuro se sube solo.
- Si sale ❌ rojo → abre el log y revisa:
  - "530 Login incorrect" → usuario/contraseña FTP mal.
  - "No such directory" → la ruta `FTP_LUNA_DIR` está mal.
  - Falla de FTPS → en `.github/workflows/deploy-luna.yml` cambia `protocol: ftps` por `protocol: ftp`.

---

## Notas
- Solo sube la carpeta `luna/`. El resto del sitio (marketing, root) no se toca.
- **No sobrescribe `luna_config.php`** — las llaves que Sami puso siguen intactas.
- Si alguna vez quieren pausar el auto-despliegue, borra (o renombra) el archivo
  `.github/workflows/deploy-luna.yml`.

## Alternativa (si prefieren no usar FTP)
cPanel también tiene **Git™ Version Control** (clonar el repo en el servidor y
"Update from Remote"). Pero como el repo es privado, requiere una llave de
despliegue (más enredado). Por eso recomendamos el método FTP de arriba.
