# LUNA — Despliegue y notas de seguridad

## Estructura en Bluehost (`public_html/`)
```
public_html/
├── config.php              ← credenciales DB + API key (NUNCA en git)
└── luna/
    ├── index.html
    ├── luna_api.php
    ├── luna_telegram_webhook.php
    └── cron/
        ├── luna_briefing_cron.php
        ├── luna_weekly_cron.php
        ├── luna_compliance_cron.php
        ├── luna_referral_cron.php
        └── luna_email_marketing_cron.php
```
> Los crons cargan `require_once __DIR__ . '/../../config.php'` — sube **dos**
> niveles desde `luna/cron/` hasta la raíz `public_html/` donde vive el config
> del CRM. Ya quedó corregido en el código: deja los crons en `luna/cron/`.

## 🔑 API key de Anthropic (CAMBIO IMPORTANTE)
Antes la key estaba en el HTML (visible para cualquiera). Ahora el browser
llama a `luna_api.php?action=luna_chat`, que la reenvía a Anthropic con la
key del **servidor**. Para activarlo, define la key en UNO de estos lugares:

**Opción A — constante en `config.php` (más simple en Bluehost):**
```php
define('ANTHROPIC_API_KEY', 'sk-ant-...');
```

**Opción B — variable de entorno** (`.htaccess` o panel de Bluehost):
```
SetEnv ANTHROPIC_API_KEY sk-ant-...
```

Sin esto, el chat responde `500 — Falta ANTHROPIC_API_KEY`.

### ⚠️ Revoca la key vieja
La key que estuvo en el HTML hay que **rotarla** en console.anthropic.com:
cualquiera que haya visto el código fuente la tiene.

## Seguridad — qué ya está cubierto
- `luna_chat` requiere sesión PHP iniciada (igual que el resto de la API).
- Cada consulta IA se registra en `actividad` como `LUNA_CHAT` (audit, sin PII).
- Los datos del CRM en el banner de alertas ahora pasan por `esc()` (anti-XSS).

## Crons a programar en Bluehost
```
0 7  * * *  php .../luna/cron/luna_briefing_cron.php       # briefing matutino
0 2  * * *  php .../luna/cron/luna_signals_cron.php        # señales nocturnas (#7/#15)
0 3  * * *  php .../luna/cron/luna_backup_cron.php         # respaldo MySQL (#5)
0 17 * * 5  php .../luna/cron/luna_weekly_cron.php         # reporte viernes
0 8  1 * *  php .../luna/cron/luna_compliance_cron.php     # auditoría mensual
0 9  * * 3  php .../luna/cron/luna_referral_cron.php       # referidos miércoles
0 8  * * *  php .../luna/cron/luna_email_marketing_cron.php # cumpleaños/newsletter/AEP
30 6 * * *  php .../luna/cron/luna_radar_cron.php daily     # 📡 radar diario (tendencias)
0 7  * * 1  php .../luna/cron/luna_radar_cron.php weekly    # 📡 radar semanal + email lunes 7am
0 8  * * *  php .../luna/cron/luna_task_reminders_cron.php  # ⏰ recordatorios de tareas de la junta
```
> Los crons ya cargan `require '/../../config.php'` (dos niveles arriba), así
> que funcionan tal cual desde `luna/cron/`. No hay que ajustar el path.

### 📡 Radar de Tendencias (nuevo)
LUNA investiga la web (web_search de Anthropic) y guarda hallazgos accionables
en `luna_radar_runs` / `luna_radar_items` (se crean solas). El equipo los ve en
el botón **📡 Radar**; Isabel puede correrlo a mano con **🔄 Actualizar ahora**.
Cinco frentes: viral/marketing, redes sociales, Medicare/CMS, competencia y
**mejora (Chief of Staff)**. Necesita `ANTHROPIC_API_KEY`; sin ella degrada
elegante (guarda un run marcado y no rompe nada).

**Entrega del Chief of Staff:**
- **Lunes 7am** — el cron `weekly` manda un correo corto SOLO con el radar
  (resumen + hallazgos). Ajusta destinatario en `$RADAR_EMAIL` dentro de
  `luna_radar_cron.php`.
- **Viernes 5pm** — el reporte semanal (`luna_weekly_cron.php`) genera un
  radar fresco ese día e incluye la sección Chief of Staff junto con los KPIs.
  Arriba lleva una **🗓️ Agenda para la junta de equipo (sábado)**: 3-4 puntos
  accionables sintetizados de los KPIs y el radar (con responsable sugerido).

### 🗓️ Junta de Equipo — acuerdos y seguimiento (nuevo)
Botón **🗓️ Junta** en la plataforma. Isabel registra los acuerdos y tareas de
la junta del sábado (qué, quién, para cuándo). Las tareas quedan con estado
(pendiente/hecho/cancelado) en `luna_meetings` / `luna_meeting_actions` (se
crean solas). LUNA da seguimiento: lo **pendiente** aparece como sección
**📌 Pendientes de la junta pasada** en el reporte del viernes y alimenta la
agenda del sábado, cerrando el ciclo semana a semana.

**⏰ Recordatorios automáticos:** `luna_task_reminders_cron.php` corre cada
mañana (8am) y avisa por correo a cada responsable las tareas que vencen
hoy/mañana o ya vencidas, más un resumen para Isabel. Nudge diario hasta que
se marquen hechas. **Completa los correos del equipo** en `$REMIND['team']`
dentro del cron (Skarleth, Samia, Arlette…); si falta uno, su tarea aparece
en el resumen de Isabel marcada "sin correo". También intenta resolver el
correo desde `usuarios.email` si esa columna existe.

## Respaldos (#5) — opciones en config.php
```php
// Carpeta destino (por defecto: ../private_backups, FUERA de public_html)
define('BACKUP_DIR', '/home/usuario/private_backups');
// Retención: el script guarda 30 días por defecto.
// Subida offsite opcional ({FILE} = ruta del .gz):
define('BACKUP_OFFSITE_CMD', 'aws s3 cp {FILE} s3://mi-bucket/luna/');
// Telegram opcional para avisos de backup:
define('TG_TOKEN', '123456:abc...');
define('TG_ISABEL_CHAT', '99999999');
```
El backup usa `mysqldump --single-transaction` (seguro en InnoDB), comprime con
gzip, rota por antigüedad y borra automáticamente un respaldo corrupto (<1KB).
**Verifica que `mysqldump` esté en el PATH del cron de Bluehost.**

## 🤝 Conexión Athena/Pilar → LUNA (cuenta de servicio)
Athena (Pilar, en Railway) llama a LUNA **máquina-a-máquina**, sin sesión humana,
con una **llave de servicio**. Permisos: **SOLO-LECTURA** (decisión de Isabel) —
Athena/Pilar **lee** el CRM e informa a LUNA, pero **NO escribe nada**: no crea,
edita, cierra, borra, cambia estado ni toca comisiones. Athena y Pilar son **una
sola** cuenta de servicio (`"Athena (Pilar)"`), no dos capas separadas.

**1. Define la llave en `config.php` (Bluehost):**
```php
define('LUNA_SERVICE_KEY', 'pega-aquí-una-llave-larga-aleatoria');
// Identidad (FK) a la que se atribuyen las llamadas de Athena en el audit log.
// Crea un agente "Athena" en el CRM y pon su id, o usa el de Isabel.
define('LUNA_SERVICE_AGENT_ID', 1);
// (Opcional) Permitir que Athena LEA comisiones. OFF por defecto
// porque es un bot de cara al cliente. Descomenta para habilitar:
// define('LUNA_SERVICE_ALLOW_COMMISSIONS', 1);
```
Genera la llave con: `php -r "echo bin2hex(random_bytes(32));"`

**2. Cómo consulta Athena (desde Railway):**
```
GET https://withisabelfuentes.com/luna/luna_api.php?action=luna_full_briefing
Header:  X-LUNA-Key: <LUNA_SERVICE_KEY>
```
Respuesta: `{"ok":true,"data":{ ...resumen del día para informar a LUNA... }}`
> Lectura (briefing, hot leads, T65, SOAs, pipeline…) + crear tickets (abajo).
> Cualquier otra escritura responde `403`.

**2b. Cómo crea un TICKET Athena (contrato — sin tickets sueltos):**
```
POST .../luna_api.php?action=luna_create_ticket
Header:  X-LUNA-Key: <LUNA_SERVICE_KEY>
Body (form-url-encoded):
   # Ticket de un CLIENTE (obligatorio miembro_id + responsable):
   clase=miembro&miembro_id=123&asignado_a=7&tipo=SEGUIMIENTO&prioridad=ALTA&descripcion=...
   # TAREA (sin cliente):
   clase=tarea&asignado_a=7&descripcion=...
   # PROYECTO (sin cliente, se marca "PROYECTO:" en la descripción):
   clase=proyecto&asignado_a=7&descripcion=...
```
Reglas que aplica el servidor automáticamente:
- **Nunca suelto:** si no mandas `asignado_a`, va al **dueño por defecto**
  (`LUNA_SERVICE_DEFAULT_ASSIGNEE`, o el admin id 1). Si el responsable no existe, igual.
- **De cliente:** `clase=miembro` exige `miembro_id` válido (si no, error claro).
- **Tarea/Proyecto:** sin cliente; tipo `TAREA`. El proyecto se etiqueta `PROYECTO:`.
- **Origen Athena:** se guarda `fuente='ATHENA'` y la descripción se prefija `[Athena]`,
  así sabes **quién lo creó** (Athena), **a quién** (`asignado_a`) y **de qué cliente** (`miembro_id`).
- Respuesta: `{"ok":true,"data":{"id":N,"clase":"...","asignado_a":N,"miembro_id":N,"fuente":"ATHENA"}}`.

> **Tickets SIN cliente (tareas/proyectos/generales):** el CRM web lista los
> tickets POR cliente, así que uno sin miembro **no se ve en la web** (queda
> "huérfano"). Solución: crea en el CRM un miembro llamado **"OTRO"** (o
> "General"); LUNA cuelga ahí los tickets sin cliente para que SÍ aparezcan.
> El servidor lo autodetecta por nombre (OTRO/GENERAL/OFICINA/TAREAS) o puedes
> fijarlo: `define('LUNA_DEFAULT_TICKET_MEMBER', 999); // id del miembro "OTRO"`.
> Si no hay ninguno, la respuesta trae un campo `aviso` advirtiéndolo.
>
> Config opcional en `config.php`:
> `define('LUNA_SERVICE_DEFAULT_ASSIGNEE', 1);  // a quién van los tickets sin responsable`
> Para FILTRAR por origen en el CRM, conviene que la columna `tickets.fuente` permita
> `'ATHENA'`. Si es ENUM y no lo tiene, el servidor cae a `'CRM'` pero la etiqueta
> `[Athena]` en la descripción **siempre** queda. Para habilitar el filtro:
> `ALTER TABLE tickets MODIFY fuente ENUM('CRM','WEB','ATHENA', ...);` (ajusta a tus valores).

**3. Seguridad:**
- Acción fuera de la allowlist (ej. editar/borrar/cerrar) → `403`.
- Athena tiene rol `service`, no `admin` → los `requireAdmin()` la bloquean (doble candado).
- Cada llamada queda en `luna_audit_log` con prefijo `ATHENA:` para distinguirla.
- Para revocar: borra/rota `LUNA_SERVICE_KEY` en `config.php`. No afecta tu login.

## Capa de confianza + memoria por capas (ya en el código)
- Audit log con PII redactado (`luna_audit_log`) + `luna_audit_view` (admin).
- Cola de outbound con aprobación, review-hooks CMS y horas de silencio.
- Entidades, señales, skills, gaps y auditoría estructural (`luna_structural_audit`).
- Todas las tablas se autocrean en el primer uso — sin SQL manual.
