# Resumen para Sami — Trabajo del 6 de junio 2026

Hola Sami. Ayer (6 jun) Isabel y yo (Athena) trabajamos varias cosas grandes con la nueva sesión paralela de LUNA. Te dejo todo organizado por categorías para que tengas claro qué se hizo, qué nombres cambiaron, y qué te toca hacer mañana.

---

## 1. Cambios de nombre — IMPORTANTE para que no te confundas

Renombramos cosas para que la arquitectura sea más clara mentalmente. Internamente del lado de Athena ya está migrado todo automático. Pero por si lees código viejo:

| Nombre viejo | Nombre nuevo |
|---|---|
| `Pilar Medicare` (la coach IA del CRM) | `LUNA` (id `luna`) |
| `Beauty Luna` (la coach IA de skincare) | `Aurora` (id `aurora`) |
| Antes había confusión entre "LUNA el sistema" y "Pilar la voz" | Ahora **LUNA** es las dos cosas a la vez: el sistema en Bluehost Y la voz IA que lo habla |

Si ves comentarios o data vieja que diga `pilar`, no te preocupes — el código sigue aceptándolo por compatibilidad. Pero todo lo nuevo se llama `luna`.

---

## 2. Lo que arreglamos juntos en LUNA (Bluehost)

### Problema 1 — Error 403 en todos los endpoints

Athena no podía leer LUNA porque la autenticación rechazaba todo. Isabel descubrió en `luna_config.php` que había un valor con una comilla doble extra (`'5e6c…7e"'` en vez de `'5e6c…7e'`). Eso bloqueaba todo. **Ya resuelto.**

### Problema 2 — Bloque de bypass mal puesto

Habíamos pegado un bloque PHP de auth ANTES de que se cargara `luna_config.php`, así que la constante `LUNA_INTERNAL_KEY` no existía en ese momento → siempre fallaba. **Se reorganizó el orden.**

### Problema 3 — Bypass innecesario y peligroso

La sesión paralela de **LUNA** (otra IA trabajando en su propio repo, rama `claude/happy-planck-Dtzud`) cazó que mi bypass:
- Daba **admin total** a Athena sobre datos Medicare (riesgo HIPAA innecesario)
- Usaba `$_SESSION['user_id']` cuando el código real usa `$_SESSION['user']` → habría roto el login de ustedes
- Creaba una llave duplicada (`LUNA_INTERNAL_KEY`) innecesaria

**Decisión: el bypass viejo se descarta.** En su lugar, vamos a usar lo que LUNA construyó: auth limpia con `LUNA_SERVICE_KEY` única, scope limitado a lectura + crear tickets.

### Problema 4 — Query SQL de `luna_open_tickets` rota

Isabel actualizó la query (con mi guía) para que devuelva `asignado_a` correctamente. Probamos en phpMyAdmin, encontramos que `agente_ini` no existe como columna (era de un JOIN viejo a `usuarios`), y simplificamos a usar solo las columnas reales:

```sql
SELECT t.id, t.tipo, t.prioridad, t.descripcion, t.estado,
       t.asignado_a, t.fecha_creacion
FROM tickets t
WHERE t.estado IN ('ABIERTO', 'EN PROCESO', 'PENDIENTE')
ORDER BY FIELD(t.prioridad, 'ALTA', 'MEDIA', 'BAJA'), t.fecha_creacion DESC
LIMIT 250
```

**Ya quedó funcionando y devuelve datos reales.**

### Problema 5 — Athena reportaba mal por agente

Aunque la query traía los 89 tickets, mi dispatcher solo le pasaba 30 filas a la coach LUNA para que contara. Por eso decía "Arlette tiene 9" cuando son 16. **Lo arreglé**: ahora hace agregación completa server-side antes de devolver.

---

## 3. Cosas nuevas que se construyeron del lado de Athena

| Feature | Dónde está | Para qué sirve |
|---|---|---|
| Pantalla **Diagnóstico** | Sidebar Sistema | Ver salud de todas las integraciones (LUNA, Google Calendar, Twilio, etc.) en una vista |
| Pantalla **Uso y costos** | Sidebar Sistema | Ver cuánto cuesta Athena por día/semana/mes |
| Pantalla **Clientes** | Sidebar Equipo | Buscar y ver expediente de miembros LUNA en estilo magazine |
| Pantalla **Operación Medicare** | Sidebar Equipo | LUNA hace análisis estratégico deep del CRM |
| Pantalla **Email triage** | Sidebar Diario | Ver el batch de emails que Athena procesó a las 5am |
| Pestaña **Bandeja** en Tareas | Sidebar Diario | Drafts + alertas + triage + tickets equipo en una vista |
| Pantalla **Reglas / Órdenes permanentes** | Sidebar Sistema | Reglas que Athena obedece siempre |
| Pantalla **Proyectos** | Sidebar Equipo | Agrupación cross-domain (tareas + tickets + emails por meta) |
| **Auto-grouping de items a proyectos** | Backend | Cuando Athena crea una tarea, Haiku decide si va a un proyecto activo |
| **Reportes inteligentes de tickets** | Tools LUNA | No solo cuenta — detecta ALTA estancados, huérfanos urgentes, fechas en descripción, cuellos de botella |
| **Smart insights en respuestas** | Prompt | Ya no usa markdown ni tablas — texto plano para que se lea bien en voz |

### Optimizaciones de costo

| Cambio | Ahorro |
|---|---|
| Briefing matutino: Opus → Sonnet | ~$10/mes |
| Day plan manager: Opus → Sonnet | ~$5/mes |
| Hourly nudge: cada 30min → cada 60min | ~$3/mes |
| History de chat: 40 turnos → 24 turnos | ~$5/mes |
| Cache prompt verificado funcionando | hasta $30/mes |

**Total estimado: ~$15-25/mes menos.**

---

## 4. Sincronización con la otra sesión (LUNA en Bluehost)

Hay otra sesión de IA trabajando del lado de LUNA (Bluehost), en la rama `claude/happy-planck-Dtzud`. Hoy intercambiamos información para alinearnos. Ella te tiene listas las siguientes cosas en su rama:

- `luna_config.php` con la configuración correcta (sin la `"` extra)
- `luna_api.php` con la auth limpia (NO el bypass mío) y los endpoints nuevos
- `RUNBOOK_SAMI.md` con instrucciones de deploy
- Nuevos endpoints: `luna_tickets_by_agent`, `luna_business_health`, y otros

**Decisión:** Sami, NO parches el archivo viejo a mano. **Reemplaza `luna_api.php` completo con la versión nueva** que LUNA construyó. Su versión incluye la auth integrada y todos los endpoints nuevos.

---

## 5. ⛔ Lo que falta hacer mañana (TU tarea, Sami)

### Paso 1 — Subir los archivos nuevos de LUNA a Bluehost

1. Entra al repo de LUNA, branch `claude/happy-planck-Dtzud`
2. Baja todos los archivos PHP nuevos
3. Sube a `public_html/website_5a1c69e7/luna/` (la ruta que ya conoces)
4. **Reemplaza** los archivos viejos (incluido `luna_api.php` con bypass)

### Paso 2 — Verificar `luna_config.php`

Asegúrate que el archivo tenga estos dos valores correctos:

```php
define('LUNA_SERVICE_KEY', '<<COPIA AQUÍ LA LLAVE DE RAILWAY>>');
```

**Cómo conseguir la llave (no la pongo en este doc por seguridad):**
- Railway → proyecto Athena → Variables → `LUNA_API_KEY` → click el ojito 👁 → copia los 64 caracteres hex
- Pega en `luna_config.php` SIN comillas dobles extras, SIN espacios

### Paso 3 — Probar con curl

⚠️ Reemplaza `<LLAVE>` por la llave real y `<tu-dominio-luna>` por tu dominio real de Bluehost:

```bash
curl -i -H "X-LUNA-Key: <LLAVE>" \
     "https://<tu-dominio-luna>/luna_api.php?action=luna_pipeline_summary"
```

Si devuelve `HTTP/2 200` con JSON `{ok:true, ...}` → funcionó. Si da 403 → la llave no matchea, revisa de nuevo. Si dice `Could not resolve host` → te falta cambiar el dominio placeholder.

### Paso 4 — Confirmar a Isabel

Cuando esté listo, mándale mensaje a Isabel:

> *"Listo. Subí el deploy de LUNA. Athena ya puede leer todo el CRM con la auth segura."*

Ella va a probar entrando al PWA de Athena → Sistema → **Diagnóstico** y debe ver:
- LUNA CRM · verde · activo
- Todos los endpoints LUNA con ✓

---

## 6. Documentos que hay en el repo para tu referencia

- `PARA_SAMI_LUNA_REPORTS.md` — snippet de SQL viejo (ya superado por el deploy de LUNA, no usarlo)
- `PARA_SAMI_LUNA_AUTH.md` — bypass viejo (DESCARTADO, no lo apliques)
- `PARA_LUNA_RESPUESTA.md` — mi última respuesta a la sesión LUNA con la lista de endpoints adicionales que pedí

---

## 7. Lo que quedó pendiente para después del deploy

Una vez que tu deploy esté arriba, vamos a probar:

1. **Mission Bar arriba** del PWA debe mostrar tickets, SOAs pendientes, citas hoy
2. **Pantalla Clientes** debe permitir buscar y abrir expedientes
3. **Reporte de tickets por agente** debe darle números reales a Isabel
4. **Operación Medicare** debe poder generar el ensayo de análisis con todos los datos

Si algo no funciona, Isabel te avisa y lo cazamos juntas.

---

## En una frase

Hoy debuggeamos por qué LUNA no se conectaba a Athena (era una `"` extra en la config), reescribimos cómo Athena cuenta tickets para que dé números reales, alineamos arquitectura con la otra sesión de IA que está trabajando del lado de LUNA, y dejamos todo listo para que tú hagas el deploy mañana — un solo paso de subir los archivos nuevos del branch `claude/happy-planck-Dtzud` y verificar la llave.

¡Gracias Sami!
