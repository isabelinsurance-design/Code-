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

## Pendiente (roadmap) — ver conversación
Rate limiting, cola de borradores outbound, review-hooks de compliance,
y aprobación previa para TODAS las tools de escritura.
