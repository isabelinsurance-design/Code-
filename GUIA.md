# 📘 Guía completa — Trabajar tu CRM sin el File Manager de Bluehost

Esta guía resuelve tu punto **3**: dejar de subir archivos a mano al File Manager,
tener todo respaldado y versionado, y trabajar desde un entorno seguro en tu
computadora.

> **Importante:** tu CRM es **PHP + MySQL**. Eso descarta Vercel/GitHub Pages (no
> ejecutan PHP). El plan correcto es: **GitHub** (respaldo + versiones) +
> **entorno local con Docker** (para trabajar) + **despliegue a Bluehost por Git**
> (en vez de arrastrar archivos). Bluehost ya corre PHP y MySQL, así que **no
> migramos nada** — solo cambiamos la *forma de trabajar*.

---

## 🗺️ El flujo nuevo (de un vistazo)

```
   TU COMPUTADORA                GITHUB                  BLUEHOST
   ┌──────────────┐         ┌─────────────┐        ┌──────────────┐
   │ Docker local │  push   │  Repo Code- │  pull  │ Tu CRM real  │
   │ (pruebas)    │ ──────> │ (historial) │ ─────> │ + MySQL      │
   └──────────────┘         └─────────────┘        └──────────────┘
       editas               se guarda todo          se publica solo
```

1. **Editas y pruebas** en tu computadora (Docker) → nada se rompe en producción.
2. **Subes a GitHub** (`push`) → queda respaldado y versionado.
3. **Bluehost trae los cambios** (`pull`, desde cPanel) → sin File Manager.

---

## 🚨 PASO 0 — Seguridad URGENTE (hazlo HOY)

Como estos secretos estuvieron en el código, hay que **rotarlos** (cambiarlos):

1. **API Key de Anthropic** → entra a console.anthropic.com → *API Keys* →
   **revoca** la actual y crea una nueva. Ponla solo en `config.php` (que ya no se sube).
2. **Contraseña de MySQL** → Bluehost → cPanel → *MySQL Databases* → cambia la
   contraseña del usuario `emzmuumy_ISABEL_MEDICARE` y actualízala en `config.php`.
3. **Contraseña del portal financiero** (`FINANCE_PASS`) → ponle una nueva en `config.php`.
4. **Secreto del webhook de Facebook** → genera uno nuevo y actualízalo en `config.php`
   y en tu Google Apps Script.
5. En Bluehost, **borra `usuarios_setup.php`** del servidor (tenía contraseñas).

---

## 🧰 PASO 1 — Instalar lo necesario (una sola vez)

En tu computadora instala:
- **[Git](https://git-scm.com)** — control de versiones.
- **[GitHub Desktop](https://desktop.github.com)** — para subir cambios sin comandos (visual).
- **[Docker Desktop](https://www.docker.com/products/docker-desktop/)** — para correr el CRM local.
- Un editor: **[VS Code](https://code.visualstudio.com)**.

---

## 💻 PASO 2 — Bajar el proyecto y prepararlo

1. En **GitHub Desktop**: `File → Clone repository` → elige el repo **Code-** →
   clónalo a tu computadora.
2. Abre la carpeta en VS Code.
3. Entra a la carpeta `crm/`, copia `config.example.php` y renómbralo a **`config.php`**.
4. Abre `config.php` y pon tus valores reales (las claves nuevas del Paso 0).
   *(Tranquila: `config.php` está en `.gitignore`, nunca se sube.)*

---

## 🐳 PASO 3 — Correr el CRM en tu computadora (sin Bluehost)

1. Trae el esquema de tu base de datos siguiendo **`database/LEEME.md`**
   (exportas desde phpMyAdmin de Bluehost y lo guardas como `database/schema.sql`).
2. Abre una terminal en la carpeta del proyecto y ejecuta:
   ```bash
   docker compose up
   ```
3. Abre en tu navegador:
   - **http://localhost:8080** → tu CRM funcionando 🎉
   - **http://localhost:8081** → phpMyAdmin (ver/editar la base de datos)
4. Cuando termines: `docker compose down` (o Ctrl+C).

Ahora puedes **probar cambios sin miedo**: si algo se rompe, solo afecta a tu
computadora, nunca a los clientes reales.

---

## ✍️ PASO 4 — El día a día: hacer un cambio

1. Edita los archivos en `crm/` con VS Code.
2. Pruébalos en `http://localhost:8080`.
3. En **GitHub Desktop**: escribe un resumen (ej. "arreglo formato de nómina") →
   **Commit** → **Push**.
4. ✅ Tu cambio queda respaldado y versionado en GitHub. **Nunca tocaste el File Manager.**

> ¿Te equivocaste? En GitHub Desktop, pestaña *History*, puedes ver y revertir
> cualquier cambio. Eso es lo que hoy no tienes y por lo que "se pueden perder".

---

## 🚀 PASO 5 — Publicar en Bluehost por Git (sin arrastrar archivos)

Bluehost trae **Git Version Control** en cPanel. Se configura una vez:

1. Sube tu clave SSH de Bluehost a GitHub (o usa un token). *(Te guío si llegas aquí.)*
2. En **cPanel → Git Version Control → Create**:
   - **Clone URL:** la del repo de GitHub.
   - **Repository Path:** una carpeta fuera de `public_html` (ej. `/home/usuario/crm-git`).
3. Para publicar: cada vez que quieras llevar lo nuevo a producción, en
   **cPanel → Git Version Control → Manage → Pull or Deploy → Update from Remote**.
4. Configura el **deploy** para que copie `crm/` dentro de `public_html` (con un
   archivo `.cpanel.yml` que te puedo crear cuando tengas el SSH listo).

Resultado: subes a GitHub desde casa y, con **un clic en cPanel**, Bluehost se
actualiza. Cero File Manager.

> **Alternativa más simple** si Git en cPanel se complica: deja a tus agentes
> trabajando en el Bluehost actual, pero **GitHub como respaldo obligatorio**: cada
> vez que cambies algo, súbelo a GitHub. Aunque despliegues a mano, **ya nunca
> pierdes nada** porque está versionado.

---

## ✅ Qué ganas con todo esto

| Antes | Ahora |
|---|---|
| Archivos sueltos en Bluehost, se pueden perder | Todo en GitHub, con historial completo |
| Claves a la vista en el código | Secretos fuera de Git, en `config.php` privado |
| Editar en vivo (un error rompe el CRM real) | Pruebas en local con Docker, sin riesgo |
| Subir a mano archivo por archivo | `push` + un clic en cPanel |
| Sin forma de volver atrás | Reviertes cualquier cambio cuando quieras |

---

¿Dudas en algún paso? Dime en cuál estás y te acompaño. Y cuando tengas el acceso
SSH de Bluehost listo, te genero el `.cpanel.yml` para que el despliegue sea de un clic.
