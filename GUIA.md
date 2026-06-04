# 📘 Guía completa — CRM Isabel sin tocar Bluehost

Esta guía te lleva de la mano para trabajar **todo desde GitHub + Vercel**, sin
volver a subir archivos a mano al File Manager de Bluehost, y dejando tu **API
Key escondida y segura**.

> No necesitas ser programadora. Sigue los pasos en orden. Donde diga "copia y
> pega", solo copia y pega.

---

## 🗺️ Cómo funciona todo (en 30 segundos)

```
   TÚ editas archivos
          │
          ▼
   GitHub  ← guarda el código y todo el historial (nunca se pierde nada)
          │   (cada cambio que subes…)
          ▼
   Vercel  ← detecta el cambio y publica la web SOLO. Aquí vive escondida tu API Key.
          │
          ▼
   Tu CRM en internet (https://tu-crm.vercel.app)
```

- **GitHub** = el archivero seguro de tu código. Si algo se rompe, vuelves atrás.
- **Vercel** = publica la web automáticamente y protege tu API Key.
- **Bluehost** = ya no lo tocas. (Opcional: más abajo te digo cómo apuntar tu
  dominio de Bluehost a Vercel.)

---

## 📁 Estructura del proyecto (front y back separados)

```
crm-isabel/
├── public/              👉 FRONTEND (lo que ve el navegador)
│   ├── index.html           tu lanzador / página principal
│   └── tools/               una herramienta = un .html
│
├── api/                 👉 BACKEND (se ejecuta en el servidor, seguro)
│   └── claude.js            proxy que esconde tu API Key
│
├── .env.example         plantilla de la API Key
├── .gitignore           lista de lo que NUNCA se sube (tu .env real)
├── vercel.json          configuración de despliegue
└── GUIA.md              esta guía
```

**Regla de oro:** todo lo que ve el navegador va en `public/`. Los secretos y la
lógica sensible van en `api/`.

---

## 🚀 PARTE 1 — Publicar por primera vez (se hace una sola vez)

### Paso 1. Crea una cuenta en GitHub
1. Entra a https://github.com y crea una cuenta (o inicia sesión).
2. *(Tu repositorio ya existe y se llama `code-`. Si necesitaras uno nuevo:
   botón **New** → ponle nombre → **Create repository**.)*

### Paso 2. Crea una cuenta en Vercel y conéctala a GitHub
1. Entra a https://vercel.com → **Sign Up** → elige **Continue with GitHub**.
2. Autoriza a Vercel para que pueda leer tus repositorios.

### Paso 3. Importa tu repositorio en Vercel
1. En Vercel: **Add New… → Project**.
2. Busca el repositorio del CRM y pulsa **Import**.
3. En "Framework Preset" deja **Other**. No cambies nada más.
4. **¡ALTO! Antes de pulsar Deploy → ve al Paso 4.**

### Paso 4. Guarda tu API Key como secreto (⚠️ lo más importante)
1. En la misma pantalla de import, abre **Environment Variables**.
2. Agrega:
   - **Name:** `ANTHROPIC_API_KEY`
   - **Value:** tu key real (`sk-ant-…`)
3. Pulsa **Add** y luego **Deploy**.

> 🔒 A partir de aquí tu key vive en el servidor de Vercel. El navegador nunca la
> ve. Nadie puede robarla desde "Inspeccionar elemento".

### Paso 5. ¡Listo!
Vercel te dará una dirección tipo `https://code.vercel.app`. Ábrela: tu CRM ya
está en internet. Pulsa el botón "Probar el backend seguro" para confirmar.

---

## ✍️ PARTE 2 — El día a día: cómo hacer cambios (¡sin Bluehost!)

Tienes **dos formas**. Elige la que te resulte más cómoda.

### Opción A — Todo desde la web de GitHub (la más fácil, cero instalación)
1. Entra a tu repositorio en https://github.com
2. Navega a la carpeta `public/tools/`
3. Para **subir un archivo nuevo**: botón **Add file → Upload files** → arrastra
   tus `.html` → abajo escribe un mensajito (ej. "agrego herramienta X") →
   **Commit changes**.
4. Para **editar uno existente**: ábrelo → ícono del lápiz ✏️ → edita →
   **Commit changes**.
5. **En ~30 segundos Vercel publica el cambio solo.** No haces nada más.

> Así es como subes "los archivos de tu CRM": arrástralos a `public/` (la
> página principal) o a `public/tools/` (las herramientas). Nunca más al File
> Manager de Bluehost.

### Opción B — Desde tu computadora (para cambios más grandes)
Instala una vez: [Git](https://git-scm.com) y [GitHub Desktop](https://desktop.github.com)
(es visual, sin comandos). Luego:
1. **Clone** el repositorio a tu computadora.
2. Edita los archivos con tu editor favorito.
3. En GitHub Desktop: escribe un resumen → **Commit** → **Push**.
4. Vercel publica solo.

---

## 🔌 PARTE 3 — Cómo deben llamar tus herramientas a Claude (cambio clave)

**Antes** (inseguro) tus herramientas hacían esto, con la key a la vista:

```js
fetch('https://api.anthropic.com/v1/messages', {
  headers: { 'x-api-key': MI_KEY, 'anthropic-dangerous-direct-browser-access': 'true' }
  // ...
})
```

**Ahora** (seguro) deben llamar a TU backend, sin ninguna key:

```js
fetch('/api/claude', {
  method: 'POST',
  headers: { 'content-type': 'application/json' },
  body: JSON.stringify({
    model: 'claude-opus-4-8',
    max_tokens: 1024,
    messages: [{ role: 'user', content: 'tu pregunta aquí' }]
  })
})
```

> Cuando subas tus archivos, busca en cada uno `api.anthropic.com` y reemplázalo
> por `/api/claude`, y borra la línea de la `x-api-key`. **Yo te puedo hacer
> esto automáticamente en todos los archivos cuando los subas** — solo pídemelo.

---

## 💻 PARTE 4 (opcional) — Probar en tu computadora antes de publicar
1. Instala [Node.js](https://nodejs.org) (versión LTS).
2. En la carpeta del proyecto, en la terminal:
   ```bash
   npm install -g vercel     # una sola vez
   cp .env.example .env      # crea tu archivo de secretos local
   # edita .env y pon tu key real
   vercel dev                # abre http://localhost:3000
   ```
   Así pruebas el backend seguro en tu máquina antes de subir nada.

---

## 🌐 PARTE 5 (opcional) — Usar tu dominio de Bluehost
Si tienes un dominio en Bluehost (ej. `isabelfuentes.com`) y quieres usarlo:
1. En Vercel: **Project → Settings → Domains → Add** → escribe tu dominio.
2. Vercel te dará unos datos DNS (un registro `A` y/o `CNAME`).
3. En Bluehost: **Domains → DNS** → agrega esos registros que te dio Vercel.
4. En unos minutos tu dominio mostrará el CRM alojado en Vercel (seguro), sin
   archivos en el File Manager.

---

## ✅ Resumen de seguridad (lo que ganaste)

| Antes (Bluehost a mano) | Ahora (GitHub + Vercel) |
|---|---|
| Archivos se podían perder | Todo versionado en GitHub, nada se pierde |
| API Key expuesta en el navegador | API Key escondida en el servidor 🔒 |
| Un error sobrescribía sin vuelta atrás | Vuelves a cualquier versión anterior |
| Subir a mano cada archivo | Publicación automática en cada cambio |

---

## 🆘 ¿Algo falla?
- **"Falta ANTHROPIC_API_KEY"** → no guardaste el secreto en Vercel. Repite el Paso 4.
- **La web no se actualiza** → revisa en Vercel → pestaña **Deployments** si hay
  un error (aparece en rojo).
- **Cualquier duda** → dímelo y te guío. Si me subes tus archivos, yo los
  reorganizo, adapto las llamadas a `/api/claude` y te dejo todo listo.
