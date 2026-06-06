# Estado de LUNA — Resumen para Athena
*(De la sesión de construcción de Luna · 6 jun 2026)*

Athena: esto es lo que Isabel y yo (la sesión que construye Luna) acordamos e
hicimos hoy. Te lo paso para que estemos sincronizadas.

---

## 1. Arquitectura acordada (DEFINITIVA)

```
ISABEL
 ↓ (habla)
🏛 ATHENA — Chief of Staff (app aparte, TODA su vida; varios agentes personales)
   └─ agente "Luna" = PUENTE ──►  🧠 LUNA (app aparte, cerebro del NEGOCIO)
                                     ├─ agentes de negocio + tableros
                                     └─►  🗄️ CRM (Bluehost MySQL)
```

- **Athena** = Chief of Staff personal de Isabel (toda su vida). App por sí sola.
- **Luna** = cerebro del **negocio** (Medicare/CRM). App aparte, con agentes y charts.
- Dentro de Athena, "Luna" es un **agente-puente** al Luna real (no es el cerebro).
- **El puente = la llave de servicio** (header `X-LUNA-Key` → `luna_api.php`).

**Acceso doble a Luna (clave):**
- 🗣️ **Rápido** (reportes, preguntas sueltas) → Isabel le pregunta a **Athena**, y tu agente-puente "Luna" trae la respuesta del cerebro real.
- 🛠️ **Profundo** (crear estrategia de marketing, dar instrucciones, trabajo en detalle) → Isabel abre la **app de Luna directo** (PWA/web) y le habla a Luna y sus agentes sin pasar por Athena.

---

## 2. Qué se arregló/construyó hoy en Luna
*(todo en la rama `claude/happy-planck-Dtzud`, subido a GitHub)*

- ✅ **Error 500 resuelto**: Luna no tenía config propio → se creó `luna/luna_config.php` (credenciales DB + llaves) con cargador robusto de rutas.
- ✅ **Error "Unexpected token <DOCTYPE"**: el frontend llamaba a `../luna_api.php` (ruta mala) → corregido a `luna_api.php` (misma carpeta).
- ✅ **Fix de creación de tickets de Athena**: se adapta al ENUM real de la tabla, nunca tickets sueltos, valida que el cliente exista.
- ✅ **Nuevo endpoint `luna_tickets_by_agent`**: desglose de tickets por agente (abiertos/cerrados/alta/vencidos) — resuelve la pregunta de los tickets de Arlette.
- ✅ **Luna es PWA**: instalable en el teléfono como app.
- ✅ **Telegram apagado**: reportes por correo + dentro de la app.
- ✅ **Voz**: Luna lee sus respuestas en voz; micrófono donde se puede (en iPhone se usa el dictado del teclado).
- ✅ **Marketing como agente nativo de Luna** (con la voz/estrategia del sistema de marketing de Isabel) + **conectado al cerebro** (Luna consulta sola a Marketing/Analista/etc.).

**Agentes de NEGOCIO que viven en Luna:** 🌙 LUNA (principal), 🎛️ Centro de Comando, 📊 Analista, ✍️ Estudio Creativo, ⚖️ Compliance, 🎯 Sales Coach, 💎 Retención, 💪 Coach, ⚙️ Config, 🎓 Onboarding, 📢 Ads, 📣 Marketing.

---

## 3. ⛔ Lo ÚNICO pendiente: DESPLIEGUE (necesita Bluehost)

Para que Luna esté viva y tú (Athena) puedas conectarte:

1. **Subir** los archivos nuevos a `/home1/emzmuumy/public_html/website_5a1c69e7/luna/`
   (index.html, luna_api.php, luna_config.php, marketing.html, manifest.json, sw.js, los íconos, los crons).
2. **Llenar `luna_config.php`**: credenciales MySQL (cPanel → MySQL Databases) + `ANTHROPIC_API_KEY`.
3. ⭐ **ARREGLAR EL PUENTE (el 403)**: la `LUNA_SERVICE_KEY` en `luna_config.php` debe ser **idéntica** a la llave que Athena manda en `X-LUNA-Key` (la `LUNA_API_KEY` de Railway). Revisar que no haya una variable de entorno vieja que la pise.

- Guía paso a paso: `luna/RUNBOOK_SAMI.md`
- Bluehost corre **PHP 8.4**.

---

## 4. Para ti, Athena (cómo te conectas)

- Tu agente-puente "Luna" llama al cerebro real con la llave de servicio (`X-LUNA-Key`) a `luna_api.php`. Cuando la llave esté sincronizada (paso 3), el puente funciona.
- **Lo que tu llave puede hacer** (allowlist): LEER el CRM + CREAR tickets. No edita/cierra/borra.
- **Endpoints útiles para reportes**: `luna_full_briefing`, `luna_tickets_by_agent`, `luna_pipeline_summary`, `luna_hot_leads`, `luna_retention_alerts`, `luna_open_tickets`, `luna_business_health`.

---

## 5. Preguntas abiertas / próximos pasos
- **Marketing inteligente** (profundizar): que Luna analice los gaps reales del CRM y proponga campañas concretas.
- **Lead scoring / Retention radar**: revisar primero qué datos existen en el CRM antes de prometerlos.
- **Definir** qué agentes son "personales" (viven en Athena) vs "negocio" (viven en Luna).
