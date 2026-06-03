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
0 6  * * 1  php .../luna/cron/luna_radar_cron.php weekly    # 📡 radar semanal (lunes)
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
con una **llave de servicio**. Permisos: **LEER todo + CREAR solo**
(leads, tickets, citas, notas, actividad). NO puede editar, cerrar, borrar,
cambiar estado ni tocar comisiones.

**1. Define la llave en `config.php` (Bluehost):**
```php
define('LUNA_SERVICE_KEY', 'pega-aquí-una-llave-larga-aleatoria');
// Agente real al que se atribuyen los registros que cree Athena (FK).
// Crea un agente "Athena" en el CRM y pon su id, o usa el de Isabel.
define('LUNA_SERVICE_AGENT_ID', 1);
// (Opcional) Permitir que Athena LEA comisiones. OFF por defecto
// porque es un bot de cara al cliente. Descomenta para habilitar:
// define('LUNA_SERVICE_ALLOW_COMMISSIONS', 1);
```
Genera la llave con: `php -r "echo bin2hex(random_bytes(32));"`

**2. Cómo llama Athena (desde Railway):**
```
POST https://withisabelfuentes.com/luna/luna_api.php?action=luna_create_ticket
Header:  X-LUNA-Key: <LUNA_SERVICE_KEY>
Body (form-url-encoded):
   tipo=PROSPECTO&prioridad=MEDIA&descripcion=Ricardo prospecto T65 desde WhatsApp
```
Respuesta: `{"ok":true,"data":{"id":123,"message":"Ticket #123 creado."}}`

**3. Seguridad:**
- Acción fuera de la allowlist (ej. `luna_update_member_status`) → `403`.
- Athena tiene rol `service`, no `admin` → los `requireAdmin()` la bloquean (doble candado).
- Cada llamada queda en `luna_audit_log` con prefijo `ATHENA:` para distinguirla.
- Para revocar: borra/rota `LUNA_SERVICE_KEY` en `config.php`. No afecta tu login.

## Capa de confianza + memoria por capas (ya en el código)
- Audit log con PII redactado (`luna_audit_log`) + `luna_audit_view` (admin).
- Cola de outbound con aprobación, review-hooks CMS y horas de silencio.
- Entidades, señales, skills, gaps y auditoría estructural (`luna_structural_audit`).
- Todas las tablas se autocrean en el primer uso — sin SQL manual.
