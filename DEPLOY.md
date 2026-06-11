# Publicar la landing en Bluehost con Git (despliegue automático)

La landing (`landingpage.php`) se publica como `index.php` en la raíz de tu
sitio. El despliegue lo hace el archivo `.cpanel.yml` de este repo.

## Una sola vez: conectar el repo a Bluehost

### 1) Dar acceso de lectura al servidor (Deploy Key)
Como el repo es privado, Bluehost necesita una llave SSH autorizada.

1. En **cPanel → SSH Access → Manage SSH Keys** (o dentro de *Git Version
   Control* al crear el repo), copia la **llave pública** (public key) que
   muestra el servidor.
2. En GitHub: repo **isabelinsurance-design/Code-** → **Settings → Deploy keys
   → Add deploy key** → pega la llave → ✅ deja "Allow write access" SIN marcar
   → **Add key**.

### 2) Clonar el repo en cPanel
1. **cPanel → Git™ Version Control → Create.**
2. Activa **"Clone a Repository"**.
3. **Clone URL:** `git@github.com:isabelinsurance-design/Code-.git`
4. **Repository Path:** `repositories/code` (o el nombre que prefieras).
5. Crea. cPanel clonará el repo.

### 3) Elegir la rama y desplegar
1. En el repo recién creado → **Manage**.
2. En **"Checked-Out Branch"** selecciona la rama que tiene el código
   (`claude/sweet-ramanujan-Fwiwj`, o `main` si ya está fusionado).
3. Pestaña **"Pull or Deploy" → "Update from Remote"** (trae los últimos
   cambios) → luego **"Deploy HEAD Commit"**.
4. Abre `https://withisabelfuentes.com/` → debe verse la landing. 🎉

## Cada actualización futura
Cuando cambiemos algo en el repo: en cPanel → Git Version Control → tu repo →
**Update from Remote** → **Deploy HEAD Commit**. Listo.

## Revertir
El primer despliegue guarda tu `index.php` original como `index.php.firstbak`
en la raíz del sitio. Para revertir, bórralo nuevo y renómbralo de vuelta.

## Notas
- Confirma el **Document Root** en *cPanel → Domains*. Si no es
  `public_html/website_5a1c69e7`, ajusta la línea `DEPLOYPATH` en `.cpanel.yml`.
- Las imágenes/logo se cargan desde `/wp-content/uploads/...`. Mientras esos
  archivos existan en el servidor, todo funciona. Si algún día borras esa
  carpeta, hay que mover esas imágenes al repo.
- Faltan por completar antes de publicar: tu **NPN** y la **QUOTE_URL**.
