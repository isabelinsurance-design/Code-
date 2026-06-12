# Medicare with Isabel — LUNA + CRM

Repositorio del sistema de **Medicare with Isabel**: LUNA (asistente IA del
equipo, PWA + API PHP), sus crons y el material de marketing.

## ⭐ Rama de producción

> **`claude/happy-planck-Dtzud`** es la rama de producción.
> Todo cambio se hace y se sube AHÍ. `main` está desactualizada (histórica).

## 🚀 Cómo se despliega (UNA sola vía)

Cada `git push` a `claude/happy-planck-Dtzud` que toque `luna/**` se publica
**solo** en Bluehost vía **GitHub Actions** (FTPS):
`.github/workflows/deploy-luna.yml` → `public_html/website_5a1c69e7/luna/`.

- El deploy **nunca** sobreescribe `luna_config.php` del servidor (ahí viven
  las credenciales reales; no está en el repo).
- ⚠️ **NO usar** el botón "Deploy HEAD Commit" de cPanel → Git Version
  Control (`.cpanel.yml`): despliega la HEAD que tenga cPanel y puede hacer
  **rollback silencioso** de lo que publicó GitHub Actions. Es solo un
  respaldo manual de emergencia.

## 🗺️ Mapa del repo

| Carpeta / archivo | Qué es | ¿Se despliega? |
|---|---|---|
| `luna/` | **Producción**: PWA (`index.html`), API (`luna_api.php`), crons, webhook Telegram, diagnóstico | ✅ Sí (automático) |
| `marketing-legacy/` | Sistema de marketing anterior (archivado; partes ya viven dentro de LUNA) | ❌ No |
| `index.html` (raíz) | Dashboard viejo pre-LUNA (manejaba la API key en el navegador — no usar) | ❌ No |
| `AUDIT.md` | Auditoría de seguridad/arquitectura (jun 2026) con plan de trabajo por fases | — |

## 🔑 Configuración del servidor (`luna/luna_config.php`, NO está en el repo)

Plantilla: `luna/luna_config.example.php`. Claves principales:
`LUNA_SERVICE_KEY` (puente Athena), `ANTHROPIC_API_KEY` (IA),
`LUNA_CRON_TOKEN` (disparo manual de crons por HTTP),
`TELEGRAM_WEBHOOK_SECRET` (bot), `LUNA_CHAT_ALLOWED` / `LUNA_CHAT_DAILY_CAP`
(candado y tope del chat IA), `LUNA_AI_MODEL` (modelo de Claude).

## 📚 Documentación operativa

En `luna/*.md`: `RUNBOOK_SAMI.md` (despliegue paso a paso),
`HANDOFF_NUEVA_SESION*.md` (arquitectura completa), `DEPLOY_LUNA.md`,
`TEST_CHECKLIST.md`, `CHECKLIST_DESPLIEGUE.md`.
