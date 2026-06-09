# 🌙 LUNA — Resumen completo para una NUEVA sesión (handoff)

> Pega este documento al inicio de la nueva sesión de Claude. Contiene TODO el
> contexto: arquitectura, estado actual, cómo desplegar, qué funciona, qué falta,
> y las trampas técnicas que ya descubrimos. Última actualización: 9 jun 2026.

---

## 1. QUÉ ES TODO ESTO (arquitectura)

Hay 3 piezas separadas:

- 🦉 **ATHENA** = Chief of Staff personal de Isabel (toda su vida). App aparte en **Railway**, hecha en React. Vive en la rama `claude/sleepy-darwin-P4k2z`, carpeta `app-v2/`. NO se toca desde aquí.
- 🌙 **LUNA** = el cerebro del **negocio** (Medicare/CRM). Es lo que trabajamos aquí. Carpeta `luna/` del repo. Se despliega a Bluehost.
- 🗄️ **CRM** = el sistema donde viven los datos reales (miembros, tickets, pólizas). App PHP en Bluehost (`public_html/crm/`). LUNA lee de la MISMA base de datos del CRM.

**El puente:** Athena le pide datos a LUNA llamando a `luna_api.php` con una **llave de servicio** en el header `X-LUNA-Key` (= la `LUNA_API_KEY` de Railway, 64 caracteres hex). LUNA también acepta `X-Athena-Key` y `Authorization: Bearer`.

**Cómo usa Isabel LUNA:**
- Para preguntar rápido → le habla a **Athena** (que consulta a LUNA por el puente).
- Para trabajo profundo → abre **LUNA directo** (`withisabelfuentes.com/luna/`) y chatea con ella y sus agentes.

---

## 2. REPO Y RAMA

- **Repo:** `isabelinsurance-design/Code-` (ojo: termina en guion, GitHub lo muestra como "Code-").
- **Rama de trabajo de LUNA:** `claude/happy-planck-Dtzud` ← TODO el trabajo de LUNA va aquí.
- **Código de LUNA:** carpeta `luna/` (archivo principal del backend: `luna_api.php`, ~120 KB; frontend: `luna/index.html`, ~210 KB).

---

## 3. ⚙️ DESPLIEGUE — ¡AUTOMÁTICO! (muy importante)

**Cada push a `luna/**` en la rama `claude/happy-planck-Dtzud` se publica SOLO en Bluehost** por FTP, vía GitHub Actions (`.github/workflows/deploy-luna.yml`). Tarda ~1 minuto.

- Los secretos de FTP (`FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_LUNA_DIR`) ya están configurados en GitHub (repo → Settings → Secrets → Actions).
- Despliega a: `/home1/emzmuumy/public_html/website_5a1c69e7/luna/`
- **EXCLUYE `luna_config.php`** (vive solo en el servidor, tiene las llaves reales) y los `.md`.
- **Flujo para hacer cambios:** editar archivo en `luna/` → commit → `git push` a `claude/happy-planck-Dtzud` → se despliega solo → en el navegador **Ctrl+Shift+R** para ver.
- Verificar el deploy: pestaña **Actions** en GitHub (o vía las tools `mcp__github__actions_list` / `get_job_logs`). Bluehost **bloquea WebFetch** (anti-bots), así que NO se puede leer la URL en vivo con WebFetch — hay que pedirle a Isabel que la abra, o usar `luna_diag.php`.

---

## 4. URLs EN VIVO

- **App LUNA:** https://withisabelfuentes.com/luna/
- **API:** https://withisabelfuentes.com/luna/luna_api.php
- **🔧 Diagnóstico (PÚBLICO, secretos enmascarados):** https://withisabelfuentes.com/luna/luna_diag.php
  → Reporta: config cargado, estado de la llave de servicio, ANTHROPIC_API_KEY, si la base conecta, una **prueba real a Anthropic**, y el **último registro del chat** (`ultimo_chat`). **ESTA ES LA MEJOR HERRAMIENTA DE DEBUG** — pídele a Isabel que la abra (con `?v=N` para evitar caché) y pegue el JSON.
- **CRM:** https://withisabelfuentes.com/crm/

---

## 5. 🔑 CONFIGURACIÓN (en `luna_config.php`, SOLO en el servidor, NO en el repo)

`luna_config.php` está en `.gitignore`. En el repo solo está la plantilla `luna_config.example.php`. En el servidor, el archivo real tiene (confirmado funcionando):
- **Credenciales de la base de datos** (reales — la base CONECTA ✅).
- **`ANTHROPIC_API_KEY`** (108 chars, CON créditos ✅). ⚠️ Esta llave se **expuso** en el chat de la sesión vieja — Isabel debe **rotarla** (borrarla en console.anthropic.com y crear una nueva).
- **`LUNA_SERVICE_KEY`** (64 hex, `5e6c…1b7e`) = coincide con la `LUNA_API_KEY` de Railway (el puente con Athena).

El código acepta la llave de servicio bajo `LUNA_SERVICE_KEY` **o** `LUNA_INTERNAL_KEY`, con `trim()`.

---

## 6. ✅ QUÉ FUNCIONA HOY

- LUNA carga y se ve **estilo Athena**: tema crema/café (`--bg:#f7f3ec`, acento `#8b6f47`), selector de agente arriba del chat, voz, íconos de línea (mic/voz).
- **El chat RESPONDE** (Anthropic con créditos + modelo `claude-sonnet-4-6`).
- La **base de datos conecta**; el **puente con Athena** autentica bien.
- **PWA** instalable en el teléfono; **Telegram apagado** (reportes por correo).
- **Voz**: LUNA lee respuestas (voz femenina es-MX/es-US) + micrófono (en iPhone se usa el dictado del teclado).
- **12 agentes** en `index.html` (array `AGENTS`): 🌙 LUNA (principal), Centro de Comando, Analista, Estudio Creativo, Compliance, Sales Coach, Retención, Coach, Config, Onboarding, Ads, Marketing.
- **Marketing**: existe como **agente nativo** (en la lista) Y como **sección** (botón arriba que abre `marketing.html` — el Sistema Maestro con 18 herramientas — embebido en un iframe).
- **Athena (cuenta de servicio)** puede: LEER el CRM + crear tickets, notas de miembro, actividad, citas y leads (estos con candado "origen ATHENA"). NO puede editar/cerrar/borrar/comisiones.

---

## 7. 🐛 TRAMPAS TÉCNICAS YA DESCUBIERTAS (no repetirlas)

1. **PHP `{}` → `[]`:** `json_decode($x, true)` convierte objetos vacíos `{}` en arrays vacíos `[]`. Esto rompió 2 veces las peticiones a Anthropic (con error 400 `Input should be an object`):
   - en `tools[].input_schema.properties` (herramientas sin parámetros)
   - en `messages[].content[].tool_use.input` (herramienta usada sin parámetros)
   **Solución aplicada:** forzar `new stdClass()` cuando quedan vacíos. **Si agregas tools nuevas, cuidado con esto.**
2. **Auto-deploy NO toca `luna_config.php`** — si necesitas cambiar credenciales, se hace a mano en el servidor (File Manager), o pídele a Isabel.
3. **`db()` está envuelto** en try/catch: si la base no conecta, `$pdo = null` y no tira 500 (las acciones con base devuelven error limpio). Auth/whoami/diag funcionan sin base.
4. **El candado del chat ("solo Isabel") está QUITADO** (`canUseChat()` → true + el bloque del backend comentado). Para volver a limitarlo, reactivar.
5. **Los humanos necesitan sesión del CRM** (`$_SESSION['user']`) para usar LUNA directo — si no, da 401 "Inicia sesión en el CRM primero". Isabel debe entrar a `withisabelfuentes.com/crm/` primero.
6. **NO reusar `crm/config.php` con un `require`** en luna_api.php — se intentó y causó un 500. LUNA usa su propio `luna_config.php`.
7. `luna_chat` hace de proxy a Anthropic (bufferizado, con `CURLOPT_RETURNTRANSFER`); si Anthropic da error HTTP lo manda como evento SSE `error`. Registra cada llamada en `luna/luna_chat_last.log` (visible en `luna_diag.php`).

---

## 8. ⏳ PENDIENTE / OPCIONAL

- **Cosmético:** terminar de cambiar emojis por íconos de línea limpios. Ya se hizo el **micrófono y el botón de voz**. Faltan: los botones de arriba (Radar 📡, Junta 🗓️, Marketing 📣) y los emojis de los agentes (🌙 📊 ✍️ ⚖️ etc.). Athena usa íconos **Lucide** (line icons) — usar SVG inline estilo Lucide.
- **Seguridad:** rotar la `ANTHROPIC_API_KEY` (se expuso).
- **Costo:** LUNA y Athena comparten los créditos de Claude — vigilar el saldo. Considerar re-activar el candado "solo Isabel" del chat para controlar costo.
- **Marketing Fase 2:** mover los datos del sistema de marketing de `localStorage` (navegador) a la base de datos MySQL. Hoy las 18 herramientas guardan en el navegador.

---

## 9. CÓMO SEGUIR (flujo de trabajo)

1. Edita archivos en `luna/`.
2. `git add` + `git commit` + `git push` a `claude/happy-planck-Dtzud`.
3. Espera ~1 min (auto-deploy a Bluehost).
4. Pídele a Isabel que haga **Ctrl+Shift+R** en `withisabelfuentes.com/luna/`.
5. Para depurar: usa **`luna_diag.php`** (pídele a Isabel que lo abra con `?v=N` y pegue el JSON). Recuerda que NO puedes WebFetch las URLs de Bluehost (bloqueadas).
6. Para ver el resultado del deploy: `mcp__github__actions_list` (la salida es enorme — extráela con python por rangos de caracteres del archivo guardado).

---

## 10. DOCUMENTOS ÚTILES EN EL REPO (carpeta `luna/`)
`RUNBOOK_SAMI.md`, `CONECTAR_REPO_BLUEHOST.md` (cómo se montó el auto-deploy), `ESTADO_PARA_ATHENA.md` y `PARA_ATHENA_RESPUESTA_2.md` (coordinación con la sesión de Athena), `RESUMEN_HOY.md`, `PARA_SAMI_DESPLIEGUE_FINAL.md`.

---

## RESUMEN EN 1 PÁRRAFO (para arrancar rápido)
LUNA es el cerebro de IA del negocio Medicare de Isabel; vive en `luna/` de este repo (rama `claude/happy-planck-Dtzud`) y se **auto-despliega a Bluehost** por FTP en cada push. Ya funciona: chat con Claude, base de datos conectada, puente con Athena (la app personal en Railway), look estilo Athena, PWA, voz. El config real (`luna_config.php`) vive solo en el servidor (no en el repo) y tiene las credenciales de DB, la `ANTHROPIC_API_KEY` (con créditos) y la `LUNA_SERVICE_KEY`. Para depurar, usa `https://withisabelfuentes.com/luna/luna_diag.php`. Cuidado con la trampa de PHP `{} → []` al mandar tools a Anthropic. Falta (opcional): cambiar el resto de emojis por íconos limpios, rotar la API key expuesta, y vigilar el saldo de Claude.
