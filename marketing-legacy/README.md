# Marketing Legacy — Sistema Maestro IA (archivo completo)

Este folder es un **respaldo completo y sin modificar** del sistema de
marketing / Facebook de Isabel Fuentes ("Sistema Maestro IA"), tal como
estaba antes de migrar partes a LUNA. Se guarda aquí para que **nada se pierda**.

## ⚠️ Importante
- Esto es un **archivo de referencia/respaldo**. NO está conectado a LUNA ni
  corre en producción.
- Algunos de estos HTML piden la **API key de Anthropic en el navegador**.
  NO los publiques en la web tal cual — usa LUNA (que ya tiene la key del lado
  servidor). Estos quedan solo como respaldo histórico.

## Qué contiene
- `index.html` y `isabel-sistema-completo-UNICO.html` — el generador de contenido
  marketing (Sistema Maestro IA).
- `tools/` — 18 herramientas independientes:
  Facebook Live scripts, Sistema Viral Hispano, Meta Paid Ads, análisis semanal
  de ads, CMS compliance, competencia intel, dashboards de marketing, plan
  semanal, métricas, y más.
- `bot/` — bot de Telegram en Python (`bot.py`) que usa el mismo cerebro de
  marketing (comandos `/hook`, `/live`, `/tip`, `/lead`, `/semana`).
- `CLAUDE.md`, `serve.sh`, `gitignore.txt` — archivos del proyecto original.

## Qué YA está dentro de LUNA (no se perdió)
- Los 9 prompts de contenido → chips de **Estudio Creativo**
- Análisis de ads / ROI → agente **Ads & Métricas**
- Revisión CMS → agente **Compliance**

## Qué todavía NO está en LUNA (candidatos a migrar después)
- Los scripts completos de Facebook Live y el Sistema Viral Hispano (detalle).
- Los dashboards de métricas/análisis semanal (UI con números estructurados).
- La intel de competencia detallada.
- El bot de Telegram (acceso desde el teléfono).
- El contenido educativo extenso del archivo "UNICO" (1.4 MB).

Cuando quieras, revisamos tool por tool y movemos a LUNA lo que falte —
ahora sin riesgo, porque todo está respaldado aquí.
