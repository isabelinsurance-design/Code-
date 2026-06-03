# ✅ Checklist de Despliegue — LUNA (sesión de mejoras)

> **Para:** quien administra el servidor de Bluehost
> **Rama de git:** `claude/happy-planck-Dtzud`
> **Destino:** `public_html/luna/` en Bluehost
> **Tiempo estimado:** 15–20 min
> **Riesgo:** bajo (no se borra nada; las tablas nuevas se crean solas)

---

## 1) Subir los archivos (a `public_html/luna/`)

Sube estos **9 archivos** de la rama `claude/happy-planck-Dtzud`, respetando las carpetas:

**Nuevos (3):**
- [ ] `luna/luna_radar.php`
- [ ] `luna/luna_meetings.php`
- [ ] `luna/cron/luna_radar_cron.php`
- [ ] `luna/cron/luna_task_reminders_cron.php`

**Modificados (reemplazar los existentes):**
- [ ] `luna/luna_api.php`   ← incluye el **fix del error de tickets** y permisos de Athena
- [ ] `luna/luna_ai.php`
- [ ] `luna/index.html`     ← botones nuevos 📡 Radar y 🗓️ Junta + chat solo para Isabel
- [ ] `luna/cron/luna_weekly_cron.php`
- [ ] `luna/DEPLOY_LUNA.md` (documentación)

> 💡 Lo más urgente para el error de Athena es **`luna/luna_api.php`**. Si solo
> puedes subir uno ahora, sube ese: arregla el "Data truncated" al crear tickets.

---

## 2) Revisar configuración (en `config.php`, en la raíz de Bluehost)

Confirma que existan (ya deberían, del setup anterior):
- [ ] `ANTHROPIC_API_KEY` — necesaria para el Radar, briefing, reporte semanal.
- [ ] `LUNA_SERVICE_KEY` — la llave con la que Athena/Pilar llama a LUNA.

Y completa los correos del equipo para los recordatorios de tareas, en
`luna/cron/luna_task_reminders_cron.php` (variable `$REMIND['team']`):
- [ ] Skarleth → su correo
- [ ] Samia → su correo
- [ ] Arlette → su correo

---

## 3) Agregar los crons nuevos (panel de Bluehost → Cron Jobs)

Ajusta la ruta `.../luna/` a la real de tu servidor:

```
30 6 * * *  php .../luna/cron/luna_radar_cron.php daily      # 📡 Radar diario
0  7 * * 1  php .../luna/cron/luna_radar_cron.php weekly     # 📡 Radar + correo lunes
0  8 * * *  php .../luna/cron/luna_task_reminders_cron.php   # ⏰ Recordatorios de tareas
```
- [ ] Radar diario agregado
- [ ] Radar semanal (lunes) agregado
- [ ] Recordatorios agregado

> Los crons existentes (briefing, señales, respaldo, reporte viernes, etc.)
> **no cambian de horario**; solo se reemplaza el archivo de `luna_weekly_cron.php`.

---

## 4) Verificar que todo quedó bien

- [ ] **Athena crea un ticket** sin el error "Data truncated" (la prueba principal).
- [ ] Abrir la plataforma `withisabelfuentes.com/luna/` → ver botones **📡 Radar** y **🗓️ Junta** arriba.
- [ ] En 📡 Radar (como Isabel) → **🔄 Actualizar ahora** → aparecen hallazgos (~1 min).
- [ ] En 🗓️ Junta → registrar una junta de prueba con una tarea.
- [ ] Con un usuario del equipo (no Isabel): el chat aparece **deshabilitado** con el mensaje "solo para Isabel". ✅ correcto.
- [ ] Isabel sí puede chatear normal.

---

## 5) Notas importantes

- 🗄️ **No hay que correr SQL a mano.** Las tablas nuevas (`luna_radar_*`,
  `luna_meetings`, `luna_meeting_actions`) se crean solas la primera vez.
- 🔁 **Reversa rápida:** si algo sale mal, vuelve a subir la versión anterior
  de `luna_api.php` / `index.html`. No se borró ni alteró ninguna tabla.
- 🔒 El fix de tickets es **auto-adaptable**: lee de la propia base de datos qué
  tipos acepta, así que funciona sin tocar el esquema.
- 💬 El chat de IA quedó **solo para Isabel** (control de costo). Para habilitar
  a alguien más: agregar su `user_id` en `$CHAT_EXTRA_UIDS` dentro de `luna_api.php`.

---

## Resumen en una línea
**Sube los 9 archivos a `public_html/luna/`, agrega 3 crons, llena los correos del
equipo, y verifica que Athena cree un ticket. Las tablas se crean solas.**
