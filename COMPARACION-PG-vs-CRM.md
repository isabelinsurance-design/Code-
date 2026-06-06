# 🔍 Comparación: PG (Marketing/Equipo) vs CRM (LUNA)

> Responde a: *"¿Qué de PG todavía NO está en el CRM?"*
>
> - **PG** = `pg_system.html` + `pg_api.php` (rama `claude/magical-hamilton-szQS0`).
>   Sistema de **gestión de equipo + marketing** con su propia IA llamada **ARIA**.
> - **CRM (LUNA)** = la app PHP/MySQL que montamos en `crm/` (miembros, tickets,
>   finanzas, asistencia, Isabel AI, etc.).

---

## 🧩 Dato clave: ya comparten base de datos

PG usa las tablas **`usuarios`** y **`reporte_diario`** — **las mismas del CRM**.
Eso significa que PG fue pensado para vivir junto al CRM: el equipo y los reportes
diarios **no se duplican**, se comparten. PG además lee prospectos/miembros del CRM
a través de una URL configurable (`/api/v1`).

---

## ✅ Lo que NO hay que tocar (ya está en el CRM — no duplicar)

Estas funciones de PG **ya existen en el CRM**, normalmente más completas. Se
**deja la versión del CRM**:

| Función en PG | En el CRM ya existe como | Decisión |
|---|---|---|
| **Pipeline** (`tab-pipe`) | `pipeline_pasos` + `pipeline_config` (auto-genera pasos al crear prospecto) | ⬅️ **Dejar el del CRM** (tú lo confirmaste) |
| **Tickets** (`tab-tickets`) | `tickets` + `ticket_next_steps` + SLA | ⬅️ Dejar el del CRM (más completo) |
| **Asistencia / reloj** (check-in, almuerzo, break) | `asistencia` + `reporte_nomina.php` | ⬅️ Dejar el del CRM |
| **Reporte diario / EOD** | tabla `reporte_diario` (¡la misma!) + `reporte_export.php` | ⬅️ Compartida — no duplicar |
| **Chat de equipo / Comms** (`tab-comms`) | `chat_mensajes` + DM + notificaciones | ⬅️ Dejar el del CRM |
| **Asistente de IA** (ARIA) | Isabel AI (`api_ai.php`, con herramientas a la BD) | ⚖️ Hay **dos IAs** → elegir una (ver abajo) |
| **Leads / prospectos** | `miembros` + webhook de Facebook | ⬅️ Dejar el del CRM; PG los lee vía API |

---

## 🆕 Lo que SÍ falta en el CRM (esto es lo nuevo de PG)

Esto **no existe en el CRM** y son los candidatos a integrar:

| # | Función nueva de PG | Qué hace | Prioridad sugerida |
|---|---|---|---|
| 1 | **Campañas de marketing** (`campanas`, `campana_contactos`, `campana_historial`) | Planear y dar seguimiento a campañas y sus contactos | 🔴 Alta (es el corazón de PG) |
| 2 | **Planes mensuales de contenido** (`monthPlans`, `plan_diario`) | Calendario de contenido/marketing por mes y día | 🔴 Alta |
| 3 | **Metas y logros / Wins** (`tab-goals` 🎯) | Metas por agente, racha (streaks), celebrar wins | 🟠 Media |
| 4 | **Growth** (`tab-growth`) | Seguimiento de crecimiento | 🟠 Media |
| 5 | **Capacitación + Exámenes** (`tab-train`, 🎓 Examen) | Entrenamiento del equipo y quizzes | 🟠 Media |
| 6 | **Rutinas por fase del día** (Mañana / Medio día / Cierre) | Estructura el día del agente + reporte EOD con IA | 🟠 Media |
| 7 | **Biblioteca de actividades** + checklist diario (`checklist_diario`) | Catálogo de actividades reutilizables con guiones de llamada | 🟠 Media |
| 8 | **Inteligencia / Competencia** (`tab-comp`, panel de intel) | Análisis de competidores Medicare | 🟡 Baja-Media |
| 9 | **Coach personal** (IA que aconseja al agente) | Tips personalizados por desempeño | 🟡 Baja |
| 10 | **Reuniones de equipo** (sábados) + notas | Agenda y notas de reuniones | 🟡 Baja |
| 11 | **Reviews y Pulse** del equipo | Evaluaciones y "pulso" del ánimo/desempeño | 🟡 Baja |
| 12 | **Proyectos y Roles** | Gestión de proyectos internos y roles del equipo | 🟡 Baja |
| 13 | **Gamificación de bonos** (rachas, logs de bonos) | Motivación; *distinto* del `pago_bonos` financiero del CRM | 🟡 Baja |

> Nota: el CRM ya tiene **pago de bonos financiero** (`pago_bonos`, bonos por
> retención de 90 días). Lo de PG (#13) es **gamificación/motivación**, otro ángulo.

---

## ⚖️ Las dos IAs: ARIA (PG) vs Isabel AI (CRM)

Tienes **dos asistentes** que hacen cosas parecidas:
- **Isabel AI** (CRM) → ya conectada a la BD con herramientas (buscar miembro,
  tickets, estadísticas, generar SMS…). Server-side, segura.
- **ARIA** (PG) → enfocada en marketing/equipo, lee del CRM vía `/api/v1`.

**Recomendación:** quedarte con **una sola** (Isabel AI del CRM como base) y
**sumarle las capacidades de marketing de ARIA** como herramientas nuevas, en
lugar de mantener dos asistentes separados.

---

## 🛠️ Cómo lo integraría (sin romper nada)

1. **No copiar PG entero.** El CRM ya tiene pipeline, tickets, asistencia, reportes,
   chat y leads — esos se quedan como están.
2. **Portar solo lo nuevo** (la tabla de arriba), empezando por lo 🔴 Alto:
   **Campañas** y **Planes mensuales** — eso es lo que de verdad le falta al CRM.
3. **Reusar las tablas compartidas** (`usuarios`, `reporte_diario`) → cero duplicación.
4. **Una sola IA**: fusionar las capacidades de ARIA dentro de Isabel AI.
5. Hacerlo **por fases**, una sección a la vez, probando en local con Docker antes
   de publicar.

---

## ❓ Para decidir contigo
- ¿Empezamos por **Campañas + Planes mensuales** (lo más valioso que falta)?
- ¿Conservamos **Isabel AI** como la única IA (recomendado) o prefieres ARIA?
- ¿Hay funciones de la lista 🆕 que **no** quieras traer? (para descartarlas ya)
