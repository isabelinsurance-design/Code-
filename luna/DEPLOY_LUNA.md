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
> Los crons usan `require_once __DIR__ . '/../config.php'`. Si los pones en
> `luna/cron/`, ese path sube **dos** niveles — ajusta a `'/../../config.php'`
> o muévelos a `luna/`. (En el repo van en `cron/` solo para orden; en
> producción confirma el path antes de programar el cron.)

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
```
> Recuerda el path de `config.php`: los crons hacen `require '/../config.php'`.
> Si los dejas en `luna/cron/`, ese path sube **dos** niveles y no encontrará
> el config en `public_html/`. Ajusta a `'/../../config.php'` o mueve los crons
> a `luna/`. Confirma antes de programar.

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

## Capa de confianza + memoria por capas (ya en el código)
- Audit log con PII redactado (`luna_audit_log`) + `luna_audit_view` (admin).
- Cola de outbound con aprobación, review-hooks CMS y horas de silencio.
- Entidades, señales, skills, gaps y auditoría estructural (`luna_structural_audit`).
- Todas las tablas se autocrean en el primer uso — sin SQL manual.
