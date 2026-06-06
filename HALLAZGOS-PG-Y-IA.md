# 🔎 Hallazgos: PG vs CRM + decisión de IA

## 1. PG ya está cubierto por el CRM (no se integra)

Tras leer a fondo el backend del CRM (`api.php` + `index.php`), se confirmó que el
**CRM ya hace de forma nativa todo lo que hace PG** — y mejor. Mismas funciones,
con tablas y pestañas propias:

| Función | El CRM ya la tiene |
|---|---|
| Campañas | Tab CAMPAÑAS · tablas `campanas`, `campana_contactos`, `campana_logs` |
| Planes / Metas | Tab PLANEACIÓN · `plan_metas`, `plan_checks`, `plan_notas`, `checklist_diario` |
| Reuniones | Tab REUNIONES · `reuniones`, `reuniones_items`, `reuniones_acciones` |
| Roles | Tab ROLES · `roles_asignacion` |
| Capacitación | Tab ENTRENAMIENTO · `entrenamiento_progreso` |
| Recursos / Biblioteca | Tab RECURSOS |
| Rutinas del día | Tab MI DÍA |
| Pipeline, Tickets, Citas, Retención, Bonos, Gastos, Asistencia, Contactos | Tabs nativos |

**Conclusión:** PG es una versión anterior/paralela del mismo sistema. **No se
importa.** Hacerlo duplicaría funciones y —peor— **chocaría con las tablas reales
del CRM** (PG y el CRM usan `campanas`/`campana_contactos` con columnas distintas,
lo que podría corromper datos).

### Qué se hizo
- Se **revirtió** la integración de PG (se quitaron `marketing.html`, `pg_api.php`,
  `ai_proxy.php` y la migración). El CRM quedó intacto.
- Si hubiera **contenido** puntual valioso en PG (guiones de llamada, manejo de
  objeciones, estrategia), se copia ese *texto* a la pestaña RECURSOS/ENTRENAMIENTO
  del CRM. Sin código, sin conflicto. (Pendiente de revisar si vale la pena.)

> Nota: la rama de PG (`claude/magical-hamilton-szQS0`) se queda como está, por si
> alguna vez quieres rescatar algún texto. No se borra nada.

---

## 2. Decisión de IA: un solo cerebro = LUNA

**Contexto (de Isabel):** LUNA es el cerebro del CRM (agentes de IA que se conectan
al CRM). Isabel AI y ARIA **casi no se usan**.

**Decisión:** **No se necesitan Isabel AI ni ARIA.** LUNA es el único cerebro.

| IA | Qué pasa |
|---|---|
| **ARIA** (PG) | Se descarta (PG no se integra). |
| **Isabel AI** (CRM, `api_ai.php`) | Se deja **inerte**. No se borra el archivo (queda de referencia de qué datos debe poder leer LUNA), pero **no se usa**. |
| **LUNA** | Único cerebro. Se conecta al CRM a través de su API. |

### Implicación de seguridad (positiva)
Como ninguna IA del CRM se usará, **NO hace falta poner la API Key de Anthropic en
el CRM.** En `config.php` se deja vacía / como placeholder. Resultado:
- `api_ai.php` queda inerte automáticamente (verifica la key y responde "no configurada").
- **La API Key de Anthropic deja de existir en el CRM** → desaparece uno de los
  riesgos de seguridad más grandes que tenía el sistema. ✅

### Cómo conecta LUNA al CRM
LUNA (sus agentes) debe **leer/escribir datos del CRM por su API** (`api.php`, que
ya tiene ~70 acciones). El archivo `api_ai.php` es buena **referencia** de qué
consultas necesita un agente (buscar miembro, tickets urgentes, estadísticas,
miembros en riesgo, producción del mes, etc.).

---

## Resumen
- **PG:** no se integra (el CRM ya lo hace todo). Revertido.
- **IA:** una sola, LUNA. Isabel AI y ARIA fuera. Sin API Key de Anthropic en el CRM.
- **El CRM** sigue siendo el sistema de datos central y la fuente de verdad.
