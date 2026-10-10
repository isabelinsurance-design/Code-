# Verificar la fusión: ¿se subió todo a LUNA?

> 5 minutos. Abre LUNA, sigue la lista, marca lo que ves. Si algo falta, dile
> a Sammy exactamente cuál ítem falló.

---

## En qué fase estás

Si hiciste **solo el drop-in** (Fase 1 del runbook): lo único que tenía que
subirse a LUNA es **UN archivo** + **UN link en el menú**. El resto (sync de
datos, briefings automáticos, registro como agente) es Fases 2-4, posterior.

| Fase | Archivos en LUNA | Tu trabajo de verificación |
|---|---|---|
| **🟢 Fase 1** (drop-in) | 1 archivo HTML + 1 link en nav | Esta página ↓ |
| 🟡 Fase 2 (sync MySQL) | + 4 tablas + 8 endpoints PHP | Después |
| 🔵 Fase 3 (crons) | + 2 archivos PHP en `cron/` | Después |
| 🟣 Fase 4 (agente) | + registro en orchestrator | Opcional |

**Si todavía no has hecho Fases 2-4, NO debes esperar ver MySQL/PHP/crons en
LUNA. Eso es normal y correcto.**

---

## ✅ Verificación de Fase 1 (drop-in)

### 1 — El archivo está subido (15 segundos)
- Sammy debe haber subido **`isabel-sistema-completo-UNICO.html`** a una
  carpeta dentro de LUNA (típicamente `/marketing/`).
- Tamaño esperado: **~1.8 MB** (es un archivo grande porque trae las 20
  herramientas embebidas).
- Si Sammy te dio una URL, ábrela en Chrome. Si carga el sistema, el archivo
  está bien.

### 2 — El link está en el menú de LUNA (15 segundos)
- Abre LUNA como siempre.
- En el menú/navegación principal debes ver un item nuevo, algo como:
  - **🦋 Marketing**
- Click → te lleva al sistema de marketing.

### 3 — Las pestañas EMPEZAR AQUÍ funcionan (2 minutos)
Click sidebar, una por una. Debes ver el contenido cargar para cada una:

- [ ] 📅 **Calendario AEP** — es el landing del 1 de octubre al 7 de diciembre: ves la tarjeta "Hoy", el semáforo de aplicaciones y la lista de lo que toca publicar
- [ ] 🎯 **Plan de Acción** (es el landing el resto del año) — ves el contador AEP 2026 arriba + Salud del Negocio + Gaps
- [ ] 🎨 **Identidad de Marca** — paleta de 6 colores con tu hex codes, mariposa, tagline
- [ ] 🖼️ **Plantillas de Posts** — 4 plantillas visuales (Quién soy, Live, Tip, Lead)
- [ ] 📣 **Viral & Autoridad** — las 7 reglas para volverte viral
- [ ] 🧠 **Memoria** — 4 tarjetas (Hechos, Personas, Tareas, Compromisos) + botón "Respaldo"
- [ ] 🧭 **Pregunta Inteligente** — input grande + 6 coach chips
- [ ] 🔭 **Radar** — botón naranja "🔍 Activar Radar"
- [ ] 🤖 **Equipo IA** — botón "🚀 Generar Mi Semana" + 6 tarjetas
- [ ] 📱 **Agente Móvil** — pasos para instalar el bot

### 4 — Las pestañas TU DÍA A DÍA (30 segundos)
- [ ] 📊 **Dashboard** — los 4 números de arriba muestran `0` o `—` (NO números falsos como 247)
- [ ] 🧠 **Cerebro IA** — caja de texto grande para preguntar

### 5 — Las pestañas CREAR CONTENIDO (30 segundos)
- [ ] 📢 Meta Ads Studio
- [ ] 🔥 Viral Generator
- [ ] 🎙️ FB Live Scripts
- [ ] 📅 Calendario — semana visible

### 6 — Las pestañas INTELIGENCIA y NEGOCIOS (20 segundos)
- [ ] 🕵️ Competencia Intel
- [ ] ⚖️ CMS Compliance
- [ ] 🛡️ Revisor de Piezas — caja para soltar o pegar una imagen
- [ ] 👥 CRM Leads
- [ ] 📈 Métricas

### 7 — Las 20 Herramientas Avanzadas (30 segundos)
- En el sidebar, click el botón **"🔧 Herramientas Avanzadas"** con el chip
  de **"20"**.
- Debe expandirse mostrando 20 items. Si ves los 20, está completo.
- Los dos primeros son **📘 Estrategia AEP · Meta 300** y **🎂 Leads T65 DIY**; después vienen estas 18:
  1. Centro de Comando
  2. Sistema Completo
  3. Sistema Maestro v2
  4. Marketing Hub
  5. Meta Paid Ads
  6. Automatización
  7. Sistema Viral
  8. Facebook Live
  9. Plan Semanal
  10. Cerebro System
  11. Medicare Intel Pro
  12. Competencia Intel
  13. Marketing Intel
  14. Medicare Ads CRM
  15. CMS Compliance
  16. Métricas Semanales
  17. Análisis Ads
  18. Análisis FB
- Click en cualquiera → debe abrirse el dashboard completo dentro del marco.

### 8 — La búsqueda funciona (10 segundos)
- En la caja "Buscar pestaña…" arriba del sidebar, escribe `radar`.
- Debe filtrar y dejar solo "Radar" visible.
- Borra el texto → vuelven todas.

### 9 — La API Key (5 segundos)
- Arriba a la derecha hay un input "sk-ant-... API Key".
- Pega tu Anthropic API key.
- El círculo verde a la izquierda del input debe encenderse.

### 10 — Una llamada IA real (30 segundos, opcional)
- Ve a **🧠 Cerebro IA**.
- Escribe "dame 3 ideas de Reel" en la caja.
- Click el botón de generar.
- Después de ~10 segundos debes ver una respuesta + al final
  "✅ Tu próxima acción: …".
- Si funciona = la integración con Anthropic API funciona desde LUNA.
- Si falla, abre **⚙️ Ajustes → 🔌 Probar mi conexión**: dice en español si el problema es la llave, el saldo o el modelo.

---

## 🚨 Si algo falta

| Síntoma | Qué decirle a Sammy |
|---|---|
| El link "Marketing" no está en el menú de LUNA | "Falta agregar el `<a href>` en el nav. Ver PHASE-1-QUICKSTART.md paso 2." |
| El link existe pero abre página 404 | "El archivo no está donde dice el link. Verifica el path en File Manager." |
| Abre pero el sidebar está cortado | "El archivo se subió incompleto (corrupto/truncado). Re-subir." |
| Faltan pestañas en EMPEZAR AQUÍ | "Archivo viejo subido. El UNICO actual tiene 10 pestañas en EMPEZAR AQUÍ (la primera es Calendario AEP)." |
| Las 20 herramientas no aparecen aunque expandas | "El UNICO subido no es el correcto. Tamaño esperado: ~1.8 MB." |
| Llamada IA da error | "Verifica que la API key pegada empieza con `sk-ant-` y tiene saldo en console.anthropic.com." |

---

## Comparación rápida (qué debe coincidir)

Si tienes acceso al servidor / Sammy quiere doble-chequear:

| Archivo en MI repo | Tamaño | Debe estar en LUNA |
|---|---|---|
| `isabel-sistema-completo-UNICO.html` | **1.8 MB** | ✅ SÍ — el único archivo necesario |
| `index.html` + carpeta `tools/` | ~320 KB + 20 archivos | Opcional (alternativa al UNICO) |
| `CLAUDE.md` | 8.9 KB | Para devs (no necesario para que funcione) |
| `MERGE-TO-LUNA.md` | 10 KB | Para Sammy (referencia) |
| `PARA-LUNA-TEAM.md` | 10 KB | Para Fases 2-3 (no urgente) |
| `PHASE-1-QUICKSTART.md` | 2 KB | Para Sammy (ya cumplió su rol) |
| `bot/` | Python | NO sube a Bluehost (no funciona ahí) |

**Lo crítico:** un solo archivo HTML de ~1.8 MB en LUNA. Si eso está, Fase 1
está completa.

---

## Resumen

Si las 10 verificaciones de arriba pasaron ✅ — **nada se perdió.** El sistema
de marketing está completo dentro de LUNA en modo Fase 1 (drop-in).

Las Fases 2 (sync MySQL), 3 (briefings automáticos) y 4 (agente del
orchestrator) son trabajo posterior de Sammy — no se hicieron como parte de
Fase 1 y eso es correcto.

🦋
