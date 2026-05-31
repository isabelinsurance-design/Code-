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
  intel/
    signals.js        # Patrón #16: señales con severidad (threshold/pattern/state/calendar)
    reflection.js     # Patrón #15: reflexión nocturna de 4 pasos
    commitments.js    # Patrón #19: commitment tracker (promesas con fecha + vencidas)
    briefing.js       # Patrón #20: briefing matutino priorizado
    scheduler.js      # Patrón #21: scheduler en-proceso (reflexión/briefing/repaso/tick)
  security/
    compliance.js     # Patrón #7/#33: review hook (reglas de marketing CMS + PII)
    gate.js           # Patrón #5/#26: confirmation gate (override auditado + rewrite)
  memory/
    index.js          # agentes, sesiones, audit log, reflexiones (#6/#12/#15)
    entities.js       # Patrón #11: personas (miembros/leads) + alias + salience + gaps
    wiki.js           # Patrón #9/#10: temporada actual + wiki de hechos largo plazo
    extract.js        # Patrón #13: captura por defecto (Haiku + fallback determinista)
    capture.js        # aplica la captura + arma el contexto de memoria estratificado (#14)
data/                 # runtime (memoria/sesiones/audit/entidades/wiki) — ignorado por git
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

### Fase 3 — Memoria por capas

- **#9 Temporada actual** y **#10 Wiki largo plazo** (`wiki.js`): en qué está
  enfocado el equipo + hechos que no caducan.
- **#11 Entidades por persona** (`entities.js`): cada miembro/lead acumula nombre
  canónico + **alias** ("Mari" = "María Hernández"), atributos tipados (plan, grupo
  médico, doctor, Medi-Cal, condiciones, teléfono), notas, **salience** y **gaps**.
- **#13 Captura por defecto** (`extract.js`): tras cada turno SAMIA guarda sola lo
  que podría perderse. Usa Haiku si hay key; si no, un extractor determinista
  conservador. **No corre en modo `practica`** (los prospectos ahí son ficticios).
- **#14 Contexto estratificado**: el prompt de cada turno ahora incluye
  temporada → wiki → personas relevantes → known unknowns → conocimiento → KB del turno.
- **#17 Known unknowns**: `entities.js` rankea los gaps de todas las personas.

Endpoints nuevos: `GET /api/memory/entities?q=`, `GET /api/memory/entity?id=`,
`GET /api/memory/gaps`, `GET|POST /api/memory/season`, `GET /api/memory/wiki`,
`POST /api/memory/fact`.

### Fase 4 — Inteligencia

- **#15 Reflexión nocturna (4 pasos)** (`intel/reflection.js`): extract (resumen del
  día), entities, **consolidate** (funde duplicados + marca contradicciones), compute
  signals. Corre bajo demanda (`POST /api/intel/reflect`) o por cron (Fase 5). Los
  pasos de consolidación y señales son deterministas — la reflexión siempre aporta
  aunque no haya key.
- **#16 Señales con severidad** (`intel/signals.js`): tipos `threshold` / `pattern` /
  `state` / `calendar`, cada una `alto` / `aviso` / `info`. Ejemplos: AEP en curso,
  "Full Dual sin SOA" (alto), demasiados huecos abiertos, un tipo de ticket repetido
  hoy. Se inyectan en el prompt de los modos operativos.
- **Consolidación segura (PHI):** los duplicados con **substring exacto** se funden
  solos; los **dudosos** (apodo + apellido, ej. "Mari" vs "María Hernández") NO se
  funden — se reportan en `merge-candidates` para que un humano confirme. Fundir mal
  los datos de dos miembros distintos es peor que no fundir.

Endpoints nuevos: `GET /api/intel/signals`, `POST /api/intel/signals/refresh`,
`POST /api/intel/reflect`, `GET /api/intel/reflections`,
`GET /api/memory/merge-candidates`, `POST /api/memory/merge`.

### Fase 5 — Autonomía

- **#19 Commitment tracker** (`intel/commitments.js`): detecta promesas del turno
  ("le voy a enviar el SOA a María el lunes", "el grupo dijo que confirmaría mañana"),
  les pone **fecha** (parser de español: mañana, lunes, "en 3 días", "7 de diciembre"…)
  y las marca **vencidas** cuando pasa la fecha. Equipo vs tercero. Determinista.
- **#20 Briefing matutino** (`intel/briefing.js`): lo que importa hoy, priorizado —
  prioridad ALTA → compromisos vencidos/de hoy → avisos → datos pendientes → resumen
  de anoche. Corto, no un volcado.
- **#21 Scheduler en-proceso** (`intel/scheduler.js`): el latido. Reflexión 02:00,
  briefing 06:30, repaso semanal lun 07:00, *task tick* horario (revisa compromisos +
  refresca señales). Persiste `lastRun` por trabajo → **no duplica ni salta**, y hace
  **catch-up** si el server estuvo caído.

> ⚠️ **Honestidad sobre el entorno:** el scheduler solo corre mientras el proceso del
> servidor está vivo. En un sandbox efímero los crons **no** siguen tras cerrar la
> sesión — para que disparen de verdad a las 6:30am hay que desplegar en algo
> *always-on* (Railway, etc.). La maquinaria está construida y verificada disparando
> los trabajos a mano (`POST /api/intel/run-jobs`).

Endpoints nuevos: `GET|POST /api/intel/briefing`, `GET|POST /api/intel/commitments`,
`GET /api/intel/scheduler`, `POST /api/intel/run-jobs`.

### Fase 7 — Seguridad / Cumplimiento

Convierte los **no-negociables de la constitución** en un **guardrail automático**.

- **#7/#33 Review hook de cumplimiento** (`security/compliance.js`): escanea un draft
  dirigido al miembro (script, carta, mensaje) contra las reglas de marketing de CMS
  — superlativos ("el mejor plan"), respaldo del gobierno ("de parte de Medicare"),
  presión/urgencia, garantías de aceptación, beneficios no confirmados, "gratis" sin
  matiz, saltarse el SOA, datos de pago en venta, selección por salud. Cada hallazgo
  trae **severidad** (`block`/`warn`/`info`) y un **arreglo**. **Determinista** — un
  guardrail que depende de la red no es un guardrail.
- **#5/#26 Confirmation gate** (`security/gate.js`): no deja pasar contenido riesgoso
  en silencio. `pass` → limpio; `review` → un agente con licencia aprueba con
  `acknowledged`; `block` → prohibido, requiere **override explícito que queda
  AUDITADO** con responsable. Reescritura *compliant* opcional (LLM) que convierte "no
  digas esto" en "di esto".
- **Aviso de PII en el chat**: si el agente pega un SSN/MBI/tarjeta, la respuesta trae
  un `compliance.piiAdvisory` recordando mantenerlo en sistemas seguros. No bloquea.
- El hook corre sobre **drafts del agente** (lo que enviará al miembro), no sobre el
  coaching de SAMIA — así "no le digas 'gratis'" no se auto-marca.

Endpoints nuevos: `POST /api/security/review` (body: `{text, acknowledged?, rewrite?,
agentId?}`).

### Fase 10 — Dashboard

- **Panel del equipo** (`samia-dashboard.html`): una UI de una sola página (sin build,
  vanilla JS, misma estética de marca) que hace visible y usable todo lo construido.
  Seis secciones: **Briefing** (regenerable), **Señales** (con severidad/tipo y
  recomputar), **Compromisos** (marcar hechos), **Memoria** (personas, huecos, y
  **confirmar fusión** de duplicados dudosos), **Cumplimiento** (pega un draft → revisa
  contra el guardrail, ve hallazgos+arreglos, pide reescritura, aprueba override) y
  **Sistema** (estado del scheduler + correr trabajos). Badges en el nav muestran
  señales `alto`, compromisos vencidos y duplicados por confirmar.
- Enlazado desde el header de `samia.html` ("Panel"). Llama a los endpoints de las
  fases 3–7; no agrega backend nuevo.

> **Connecture sigue siendo la fuente oficial para cotizar.** El KB es para
> orientar; cuando un dato pudo cambiar, SAMIA manda a verificar.

---

## Lo que falta (no inventes que ya está)

- El **round-trip real con el modelo** se probó solo hasta la llamada a Anthropic:
  falta correr con una `ANTHROPIC_API_KEY` real para verificarlo de punta a punta.
- La **captura con Haiku** (extract.js, motor LLM) se probó hasta la llamada; falta
  verificarla con una key real. El motor **determinista** sí está verificado.
- Aún no hay UI para ver/editar la memoria (eso llega con el dashboard, Fase 10);
  por ahora se inspecciona vía los endpoints `/api/memory/*`.
- El conocimiento de la superficie "Asesor" todavía se arma en el navegador y se
  manda como `context`; falta moverlo del todo al servidor.
- `data/` es local. Aún no hay backups (Patrón #27) ni hosting (Railway).

## Próximas fases sugeridas

- **Fase 9 — Boundaries:** integrar Connecture vía la embajadora `ipa` (si hay API).
- **Despliegue always-on** (Railway): requisito para que el scheduler de la Fase 5
  dispare de verdad (hoy la maquinaria está lista pero el sandbox es efímero).
