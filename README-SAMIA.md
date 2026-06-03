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

### Fase 8 — Voz y juicio (inspirada en Athena)

Cierra los dos patrones "make-or-break" que faltaban del playbook de Athena (capture
y memoria por capas ya estaban).

- **Principios Norte + 4 hábitos** (`constitucion.js`): la constitución ahora trae 5
  principios norte (proteger al miembro/agente, Connecture es la verdad, priorizar lo
  que destraba, no inventar, no repreguntar) y los 4 hábitos que hacen a SAMIA útil de
  verdad: **nunca inventa** (di "déjame verificar X" + cómo), **sintetiza no recita**,
  **UNA acción concreta al final** ("Tu próximo paso: …"), **anticipa la siguiente
  pregunta**.
- **Voz por modo** (`specialists.js` → `vozBlock`): cada especialista declara
  **palabras/cosas que NUNCA usa** (superlativos, "gratis", "garantizado", respaldo del
  gobierno, presión — consistente con el guardrail de la Fase 7) y **cuándo rebotar** el
  tema a otro modo. Se inyecta en el system prompt. Así cada modo se siente distinto y
  no se sale de su carril.

### Fase 9 — Salud del negocio (trust score de Athena)

- **Un número 0-100** (`intel/health.js`): "¿el negocio rueda solo hoy, o necesito
  meter mano?". Determinista, desde la memoria que SAMIA ya tiene. Cinco componentes:
  seguridad/cumplimiento (30), compromisos (25), datos pendientes (20), carga de
  señales (15), presión de calendario (10). Bandas: **autopilot ≥80 · revisa 50-79 ·
  necesita <50**. Como Athena (#11), no da la lista de problemas — da **EL foco más
  doloroso** con un "ciérralo hoy". Recomputa señales primero, así endpoint y briefing
  siempre coinciden.
- Aparece como **primera línea del briefing** y como **tarjeta + chip de score** en el
  dashboard (sección Briefing y topbar).

Endpoint nuevo: `GET /api/intel/health`.

### Fase 11 — Fan-out paralelo (orquestador multi-agente)

Completa el patrón #1 del playbook (multi-agente real, no solo router).

- **`mode:'auto'` en `/api/chat`** (`orchestrator.js`): cuando una pregunta toca >1
  dominio ("el doctor salió de la red del IPA **y** llegó un bill de $400"), el
  orquestador (1) **enruta** a los especialistas relevantes, (2) los consulta **en
  paralelo** (`Promise.allSettled`, cada uno con su system prompt enfocado), y (3)
  **sintetiza** con Opus en UNA respuesta de SAMIA — aplicando "sintetiza, no recites"
  y "UNA acción concreta" (Fase 8). Si solo toca 1 dominio, esa respuesta pasa directa
  sin overhead de síntesis.
- **Router con fallback determinista**: keywords (testeable sin red) + router LLM
  (Haiku) cuando hay key. Catálogo extensible (`FANOUT_CATALOG`); hoy `ipa` + `bill`.
- Degradación honesta: sin key, `mode:'auto'` devuelve 503 y audita `orchestrate_error`
  con los especialistas que se hubieran consultado.

Endpoints nuevos: `POST /api/chat` con `{mode:'auto'}`, `POST /api/orchestrate/route`
(vista del router sin LLM).

### Fase 12 — Skills (playbooks aprobados)

- **`intel/skills.js`** (patrón Athena #9): cuando un tema se repite, SAMIA **propone**
  un playbook reusable (draft). Un humano lo **aprueba** (confirmation gate — SAMIA no
  se auto-aprueba) y se vuelve **invocable**: cuando la pregunta hace match por
  triggers, el playbook ya validado se **inyecta en el prompt** para responder
  consistente y sin re-razonar. Cuenta las invocaciones.
- **Auto-propuesta**: la reflexión nocturna (#15) llama `proposeFromPatterns()` — toma
  las señales de patrón (Fase 4) y crea drafts para temas recurrentes sin skill.
- **Dashboard**: sección **Skills** — aprobar/editar/descartar propuestas y ver las
  activas con su contador de uso; badge de drafts pendientes.

Endpoints nuevos: `GET /api/skills`, `POST /api/skills` (proponer),
`POST /api/skills/{approve,reject,invoke}`.

### Fase 13 — Crecimiento / Radar (investigación continua)

- **`intel/growth.js`** (patrón Athena #18: buscar antes de inventar): SAMIA no solo
  opera el negocio, cada semana sale a **investigar cómo mejorarlo**. El Radar tiene
  **5 lentes**. Cuatro+ miran AFUERA con **búsqueda web real** (`web_search` nativo de la
  API, server-side) sobre una **agenda rotativa** de 6 temas (marketing/viral, generación
  de prospectos, reglas CMS, planes/beneficios, herramientas, retención) — un tema por
  semana. Devuelve **2-3 ideas accionables** con insight, acción, esfuerzo y **fuente (URL)**.
- **5a lente — Jefe de gabinete (Chief of Staff)** [petición de Isabel]: mira hacia
  ADENTRO. No usa web — lee los datos PROPIOS de SAMIA (volumen de chats y tendencia,
  especialista más consultado, compromisos vencidos, skills aprobadas sin uso, drafts
  pendientes, salud del negocio, overrides de compliance, adopción de ideas) y dice **qué
  funciona y qué cambiar** para que SAMIA y el equipo mejoren. Como sale de datos REALES
  (no inventa), **funciona aun sin key**: produce observaciones deterministas y, con key,
  el LLM las afina/prioriza. Corre en CADA barrido del Radar (`runRadar`), no cada 7
  semanas. Endpoint de su foto interna: `GET /api/growth/chief`.
- **Honestidad**: sin key/web NO inventa — registra el intento con la razón y no guarda
  ideas falsas (mismo principio que el resto de SAMIA).
- **Ciclo de idea**: new → doing → done | dismissed.
- **Scheduler**: nuevo job `growth-research` los **lunes 05:00** (antes del briefing, para
  que la mejor idea salga en él). También on-demand desde el dashboard.
- **Surface**: línea `💡 Idea (...)` en el briefing matutino + sección **Crecimiento** en
  el dashboard (correr investigación, elegir tema, marcar hacer/hecho/descartar).

Endpoints nuevos: `GET /api/growth`, `GET /api/growth/chief`, `POST /api/growth/research`
(`{topic?}` — `topic:"chief-of-staff"` corre la lente interna), `POST /api/growth/idea`
(`{id,status}`). *Los caminos con búsqueda web solo se verifican en el deploy con key
(ver `SMOKE-TEST.md`); la lente de jefe de gabinete sí corre sin key.*

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
- `data/` es local. Aún no hay backups automáticos (Patrón #27); con un volumen
  persistente la memoria ya sobrevive a redeploys (ver `DEPLOY.md`).

## Despliegue (always-on)

Para que el scheduler de la Fase 5 dispare de verdad (briefing 06:30, reflexión
02:00, etc.) hay que correr SAMIA en un host *always-on* — un sandbox efímero no
sirve. El repo ya trae la config: `railway.json`, `nixpacks.toml`, `Procfile`,
`DATA_DIR` overridable para el volumen, y `npm start` que funciona con o sin `.env`.
**Guía paso a paso en [`DEPLOY.md`](DEPLOY.md)** (Railway: variables, dominio, volumen
persistente en `/data`, `TZ` para hora de California).

Ya desplegado con una key real, corre el **[`SMOKE-TEST.md`](SMOKE-TEST.md)**: 9 pasos
con `curl` que validan los caminos que dependen del LLM (chat, captura Haiku, resumen
de reflexión, reescritura compliant, síntesis del fan-out, skills inyectadas) — lo
único que el sandbox no puede verificar.

## Próximas fases sugeridas

- **Fase 9 — Boundaries:** integrar Connecture vía la embajadora `ipa` (si hay API).
- **Backups (#27):** snapshot programado de `data/` (mejora sobre el volumen).
