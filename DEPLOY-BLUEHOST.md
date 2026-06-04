# 🚀 Desplegar el CRM en Bluehost por Git (sin File Manager)

Objetivo: que para publicar cambios solo tengas que **subir a GitHub** y dar
**un clic en cPanel**. Nunca más arrastrar archivos.

Se configura **una sola vez**. Después, publicar es de 10 segundos.

---

## ✅ Antes de empezar — verifica tu usuario de cPanel
1. Entra a Bluehost → **cPanel**.
2. En el panel derecho, busca **"General Information" → "Username"**.
3. Si tu usuario **NO** es `emzmuumy`, abre el archivo **`.cpanel.yml`** (en este
   repo) y reemplaza `emzmuumy` por tu usuario real, en las dos líneas. Guarda y
   sube el cambio a GitHub.

---

## PARTE A — Conectar GitHub con Bluehost (elige UNA opción)

Tu repositorio es **privado**, así que Bluehost necesita permiso para leerlo.
La opción más sencilla es con un **token**:

### Opción 1 — Token de GitHub (recomendada, más fácil)
1. En GitHub: foto de perfil → **Settings → Developer settings →
   Personal access tokens → Tokens (classic) → Generate new token (classic)**.
2. Marca el permiso **`repo`** (solo lectura del repo basta). Genera y **copia el token**.
3. Tu URL de clonación será así (pega el token donde dice `TU_TOKEN`):
   ```
   https://TU_TOKEN@github.com/isabelinsurance-design/Code-.git
   ```

### Opción 2 — Llave SSH (más técnica, más segura a largo plazo)
1. En cPanel → **SSH Access → Manage SSH Keys → Generate a New Key**.
2. Copia la **llave pública**.
3. En GitHub → repo **Code-** → **Settings → Deploy keys → Add deploy key** →
   pega la llave. (Solo lectura.)
4. Tu URL de clonación será:
   ```
   git@github.com:isabelinsurance-design/Code-.git
   ```

> ¿No sabes cuál elegir? Usa la **Opción 1**. Si te traba, me dices y vamos a la 2.

---

## PARTE B — Crear el repositorio en cPanel
1. En cPanel busca **"Git Version Control"** (sección Files).
2. Pulsa **Create**.
3. Llena:
   - **Clone a Repository:** actívalo (ON).
   - **Clone URL:** la URL de la Parte A (con tu token o SSH).
   - **Repository Path:** `repositories/code-`
     *(una carpeta nueva fuera de public_html — cPanel la crea sola)*
   - **Repository Name:** `code-`
4. Pulsa **Create**. cPanel descargará tu repositorio. ✅

---

## PARTE C — Publicar (esto es lo que repetirás siempre)
1. En cPanel → **Git Version Control** → junto a tu repo, pulsa **Manage**.
2. Pestaña **Pull or Deploy**:
   - **Update from Remote** → trae lo último de GitHub.
   - **Deploy HEAD Commit** → ejecuta `.cpanel.yml` y copia `crm/` a
     `public_html/crm/`. 🎉
3. Abre **withisabelfuentes.com/crm/** y verifica que todo está bien.

---

## PARTE D — La primera vez en el servidor (importante)
Como `config.php` **no viene en el repositorio** (lleva tus claves), asegúrate de
que ya exista en `public_html/crm/config.php` en el servidor:
- Si tu CRM ya estaba funcionando ahí, **ya existe** — no toques nada.
- Si es un servidor nuevo: copia `config.example.php` a `config.php` (una vez, por
  File Manager o SSH) y pon tus claves reales. Después, ya nunca más el File Manager.

---

## 🔁 Tu rutina a partir de ahora
```
1. Editas en tu compu  →  pruebas en Docker (localhost:8080)
2. GitHub Desktop: Commit + Push
3. cPanel: Update from Remote  →  Deploy HEAD Commit
4. Listo, publicado ✅
```

---

¿En qué paso estás? Dime tu **usuario de cPanel** (para confirmar la ruta) y si
prefieres **token o SSH**, y te acompaño en la pantalla que sigue.
