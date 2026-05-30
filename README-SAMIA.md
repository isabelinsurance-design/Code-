# SAMIA — Backend (Fase 1: Fundación)

SAMIA es la asistente IA del equipo de Medicare de Isabel Fuentes. Su trabajo:
**entrenar a los agentes nuevos** y **rescatarlos en vivo** cuando se atoran con un
ticket o un miembro (redes médicas, IPAs, planes, bills, cartas, farmacia…).

Esta es la **Fase 1** del playbook de plataforma de agentes (la misma receta de
Athena): poner una **fundación** sólida antes de añadir autonomía. Lo central de
esta fase: **sacar la API key del navegador y darle a SAMIA un "cuerpo"** (un
backend que siempre puede correr), porque casi todo lo demás del playbook —
memoria que persiste, crons, hooks de seguridad — depende de eso.

---

## Qué cambió (antes → ahora)

**Antes:** `samia.html` llamaba directo a `api.anthropic.com` desde el navegador,
con la API key guardada en `localStorage`. Cualquiera con las herramientas del
navegador veía la key y el prompt completo.

**Ahora:** las 3 superficies de chat de SAMIA llaman a su **propio backend**
(`/api/chat`). La key vive solo en el servidor (`.env`). El backend arma el system
prompt por capas y llama a Anthropic.

```
Navegador (samia.html)  ──POST /api/chat──▶  Backend Node
                                              ├─ Constitución (voz + compliance)
                                              ├─ Especialista (modo)
                                              ├─ Conocimiento de dominio (KB)
                                              ├─ Memoria del agente + sesión
                                              ├─ Datos del KB relevantes al turno
                                              └─▶ Anthropic (key del servidor)
```

---

## Cómo correrlo

Requiere **Node 22+** (sin dependencias: usa `http`, `fetch` y `--env-file` nativos).

```bash
cp .env.example .env          # pon tu ANTHROPIC_API_KEY
npm start                     # http://localhost:8137
```

Abre `http://localhost:8137/samia.html`. `npm start` sirve TODO el repo
(index.html, tools/, samia.html) y además expone `/api`.

---

## Endpoints

| Método | Ruta | Para qué |
|---|---|---|
| GET  | `/api/health` | Estado + stats del KB |
| GET  | `/api/specialists` | Lista de especialistas (para la UI) |
| POST | `/api/chat` | El cerebro: constitución + especialista + KB + memoria → Anthropic |
| GET  | `/api/kb/lookup?type=doctor\|group\|plan\|case&q=...` | Búsqueda determinista en el KB |
| GET  | `/api/audit?n=50` | Últimas N acciones (semilla del dashboard) |

`POST /api/chat` acepta: `{ mode, messages, sessionId?, agentId?, agentName?, webSearch?, context?, model? }`.

---

## Mapa de archivos

```
server/
  index.js          # servidor HTTP + rutas + armado del prompt por capas
  config.js         # env (PORT, modelos por tier, base URL, API key)
  anthropic.js      # cliente Anthropic (fetch nativo, soporta web search)
  constitucion.js   # Patrón #4: voz + valores + no-negociables de compliance
  specialists.js    # Patrón #1/#8/#32: especialistas + router (orquestador-lite)
  static.js         # sirve los HTML estáticos
  kb/
    index.js              # carga + lookups + constructor de contexto
    knowledge.es.md       # conocimiento narrativo (extraído verbatim de SAMIA)
    cases.json            # 77 casos de la librería de 2,233 tickets
    medical-groups.json   # grupos médicos / IPAs (semilla, ampliable)
    doctors.json          # doctores conocidos y su IPA
    plans.json            # planes y qué grupos aceptan
  memory/
    index.js          # Patrón #6/#11/#12/#13: agentes, sesiones, audit log
data/                 # runtime (memoria/sesiones/audit) — ignorado por git
```

---

## Qué patrones del playbook ya quedaron (Fase 1)

- **#1 Multi-agente (base):** especialistas server-side + router. El fan-out
  paralelo con orquestador Opus es el siguiente paso.
- **#2 Model tiers:** `config.js` define orchestrator/specialist/classifier.
- **#4 Constitución compartida:** se inyecta en cada turno.
- **#6 Audit log:** caja negra append-only (últimas 500, con PII redactada).
- **#8/#32 Permisos por código:** cada especialista declara su conocimiento y sus
  lookups; la `ipa` es la **embajadora** de redes médicas (cuando exista Connecture,
  será la única con esas tools).
- **#11/#12/#13 Memoria (semilla):** ficha por agente con captura por defecto +
  historial de sesión (~40 turnos).
- **#18 Web search:** disponible en el backend para la superficie "Asesor".

> **Connecture sigue siendo la fuente oficial para cotizar.** El KB es para
> orientar; cuando un dato pudo cambiar, SAMIA manda a verificar.

---

## Lo que falta (no inventes que ya está)

- El **round-trip real con el modelo** se probó solo hasta la llamada a Anthropic:
  falta correr con una `ANTHROPIC_API_KEY` real para verificarlo de punta a punta.
- La memoria es **ligera**: persiste turnos y temas por agente, pero la extracción
  de hechos salientes con Haiku es de la **Fase 4** (reflexión nocturna).
- El conocimiento de la superficie "Asesor" todavía se arma en el navegador y se
  manda como `context`; falta moverlo del todo al servidor.
- `data/` es local. Aún no hay backups (Patrón #27) ni hosting (Railway).

## Próximas fases sugeridas

- **Fase 3 — Memoria por capas:** entidades = clientes/leads; captura por defecto real.
- **Fase 5 — Autonomía:** briefing matutino, task tick, commitment tracker.
- **Fase 7 — Seguridad:** confirmation gate + review hooks (clave en Medicare/CMS).
- **Fase 9 — Boundaries:** integrar Connecture vía la embajadora `ipa` (si hay API).
