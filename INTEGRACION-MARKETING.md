# 🔗 Integración PG (Marketing) → CRM — Fase 1

Trae **Campañas + Planes mensuales** de PG al CRM, compartiendo la base de
datos y **sin exponer claves**. Esta es la primera fase del plan de
`COMPARACION-PG-vs-CRM.md`.

## Qué se agregó
| Archivo | Qué hace |
|---|---|
| `crm/marketing.html` | El sistema PG (campañas, planes, ARIA) dentro del CRM. La IA pasa por el proxy seguro, no expone la API Key. |
| `crm/pg_api.php` | Backend de PG **asegurado**: usa `config.php` (login del CRM), sin credenciales en el código, sin CORS abierto. Guarda en la misma base de datos. |
| `crm/ai_proxy.php` | Proxy seguro hacia Claude: la API Key vive en el servidor. |
| `database/migracion-marketing.sql` | Crea las tablas nuevas (campañas, contactos, planes). Reusa `usuarios` y `reporte_diario` → sin duplicar. |
| `crm/index.php` | Botón 📣 "Marketing" en la barra superior. |

## Cómo activarla (una vez)
1. **Aplicar la base de datos:** Bluehost → phpMyAdmin → tu BD → pestaña **SQL**
   → pega `database/migracion-marketing.sql` → **Continuar**.
2. **Publicar:** sube esta rama a GitHub y haz **Deploy** en cPanel (como en
   `DEPLOY-BLUEHOST.md`).
3. **Probar:** entra al CRM → botón **📣** arriba → debe abrir Marketing.
   Crea una campaña de prueba y recarga: debe persistir (ya está en MySQL).

## Seguridad — lo que mejoró vs PG original
- ❌ PG original: claves MySQL en el código + API abierta a todo internet.
- ✅ Ahora: usa el login del CRM, claves en `config.php` (fuera de Git), IA por proxy.

## Lo que sigue (próximas fases)
Según `COMPARACION-PG-vs-CRM.md`, faltan por traer de forma nativa:
Metas/Wins, Growth, Capacitación/Exámenes, Rutinas del día, Biblioteca,
Inteligencia de competencia, Coach, Reuniones, Reviews/Pulse, Proyectos/Roles.
Y **fusionar ARIA dentro de Isabel AI** (una sola IA).

> Nota: en esta Fase 1, `marketing.html` trae también las pestañas propias de PG
> (incluido su pipeline). El CRM sigue siendo la fuente de verdad; en la
> siguiente fase quitamos las pestañas duplicadas de PG y dejamos solo lo nuevo.
