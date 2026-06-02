# Para el equipo LUNA — Sistema de Marketing Facebook (Isabel Fuentes)

Este paquete es un **componente para integrar dentro de LUNA en Bluehost**.
No es un sistema independiente para desplegar por separado.

## Qué es

Un sistema visual de marketing Medicare en español para Facebook, hecho como
single-page app HTML/JS. Mismo público (hispano 60+ SoCal), misma misión que
el resto de LUNA: **viral en Facebook → leads baratos → autoridad reconocida**.

## Archivos

| Archivo | Para qué |
|---|---|
| `isabel-sistema-completo-UNICO.html` | **El archivo principal.** Single-file de ~1.4 MB con todo embebido (18 herramientas + 5 tabs nuevas). Sirve tal cual desde Bluehost. |
| `index.html` | Versión editable que carga `tools/` por separado. Útil para mantenimiento. |
| `tools/` (18 archivos) | Las 18 herramientas standalone — cada una tiene un interceptor de fetch inyectado (busca `ISABEL UNIFIED`) para compartir la API key. |
| `bot/` | **Bot de Telegram en Python.** ⚠️ No corre en Bluehost (ver "Bot" abajo). |
| `CLAUDE.md` | Documentación técnica detallada para futuras sesiones de desarrollo. |

## Integración en LUNA (Bluehost)

1. Subir `isabel-sistema-completo-UNICO.html` al servidor (estático, sin dependencias).
2. Crear un link/pestaña desde LUNA que abra ese archivo (o embed via iframe).
3. La API key de Anthropic se guarda en localStorage del navegador de Isabel —
   no necesita configuración server-side.

## Patrones de Athena ya incluidos (browser-side)

Estos vienen baked en el sistema, sin necesidad de servidor:

- **Memoria por capas** (4 stores: hechos / personas / tareas / compromisos)
- **Capture-by-default** (al hablar con Cerebro IA, extrae y guarda entidades en paralelo)
- **Voz por agente** (cada uno de los 6 agentes del Equipo IA tiene su voz y palabras prohibidas)
- **UNA acción concreta al cierre** (regla obligatoria en `ISABEL_SYSTEM`)
- **Salud del Negocio** (score 0-100 con coloreado por tier)
- **Gaps & Signals** (lista priorizada de qué necesita atención)
- **Compliance gating CMS** (escaneo automático de output IA contra reglas CMS)

## Lo que falta (necesita server-side en Bluehost o Athena)

Estos patrones de Athena requieren un proceso corriendo en servidor — no funcionan
en navegador:

- 🌅 Briefings diarios (6:30am / 9pm / Domingo) — cron job
- 🌙 Signals nocturnos / "dreaming"
- 📤 Drafts queue con confirmation gate
- 📲 Integración WhatsApp Business

Si LUNA va a hacerlos en PHP en Bluehost, se pueden replicar con cron jobs
nativos del cPanel. Si los hace Athena (que ya está en Railway), Athena puede
mandarle el briefing a Isabel directamente vía sus canales actuales (WhatsApp,
Telegram, voz).

## Bot de Telegram (`bot/`)

⚠️ **No deployable en Bluehost.** El bot está en Python con `python-telegram-bot`
usando long-polling — Bluehost no corre procesos largos así.

**Opciones:**
1. **Recomendado:** dejar el bot como **código de referencia** y que Athena (que ya
   está en Railway y maneja conversación con Isabel) absorba estos comandos
   como capacidades nuevas. Los prompts en `bot/bot.py` muestran exactamente
   qué hace cada comando.
2. Re-escribir como webhook PHP en Bluehost (Telegram POST → `bot.php`).
3. Desplegarlo aparte en Railway/Replit/Render (~$0-5/mes).

## Stack del Browser app

- HTML/CSS/JS vanilla (sin frameworks, sin build step)
- Anthropic Claude API directo del navegador (`claude-sonnet-4-20250514`)
- Headers requeridos: `x-api-key`, `anthropic-version: 2023-06-01`,
  `anthropic-dangerous-direct-browser-access: true`
- localStorage para persistencia (keys documentadas en `CLAUDE.md`)

## Para regenerar el archivo UNICO

Después de editar `index.html` o cualquier `tools/*.html`:

```bash
# El script de build está descrito en CLAUDE.md (sección "Build step")
python3 build_single.py  # produce isabel-sistema-completo-UNICO.html
```

(El script de build no está commiteado — está en el flujo de trabajo de Claude
Code; replicable en 30 líneas de Python.)

---

Cualquier duda técnica, todo está en `CLAUDE.md`.
