# 🔍 AUDITORÍA DEL CODEBASE — Medicare with Isabel (LUNA + CRM)

**Fecha:** 12 de junio 2026
**Rama auditada:** `claude/happy-planck-Dtzud` (rama de producción de facto)
**Alcance:** todo el repositorio — backend PHP, PWA, crons, webhooks, despliegue, docs, legacy.

---

## 1. ARQUITECTURA (mapa general)

```
┌─────────────────────────────────────────────────────────────────┐
│  GitHub repo (isabelinsurance-design/Code-)                     │
│  rama claude/happy-planck-Dtzud ──push──► GitHub Actions (FTPS) │
│                                              │                  │
│  .cpanel.yml (deploy manual cPanel) ─────────┤  ⚠️ dos vías     │
└──────────────────────────────────────────────┼──────────────────┘
                                               ▼
                  Bluehost: public_html/website_5a1c69e7/luna/
┌─────────────────────────────────────────────────────────────────┐
│ PRODUCCIÓN (withisabelfuentes.com/luna/)                        │
│                                                                 │
│  index.html (PWA, 4 184 líneas)  ←→  luna_api.php (2 663 líneas)│
│   · 12 agentes IA (AGENT_TOOLS)       · 67 endpoints (switch)   │
│   · 40+ tools nativos Anthropic       · sesión PHP + svc key    │
│   · sw.js (passthrough, sin caché)    · proxy a Anthropic       │
│                                                                 │
│  9 crons (briefing, radar, weekly, reminders, compliance,       │
│           signals, referral, email-marketing, backup)           │
│  luna_telegram_webhook.php · luna_diag.php (público)            │
│  luna_ai.php (helper Anthropic + web_search)                    │
│                                                                 │
│  MySQL (PDO, utf8mb4): miembros, usuarios, tickets, citas,      │
│   actividad, soa, llamadas_perdidas, comisiones + luna_*        │
│   (memoria, señales, radar, meetings, outbound, audit…          │
│    auto-creadas con CREATE TABLE IF NOT EXISTS)                 │
└─────────────────────────────────────────────────────────────────┘
        ▲                              ▲
        │ X-LUNA-Key (allowlist)       │ chat IDs whitelist
   Athena (Railway)                Telegram bot
```

**Integraciones externas:** Anthropic API (chat + web search, modelo fijo
`claude-sonnet-4-6`), Telegram Bot API, email vía `mail()` de PHP, puente
Athena↔LUNA por header `X-LUNA-Key`.

**Dependencias:** cero gestores de paquetes (sin composer/npm). PHP 8 vanilla
+ PDO + curl; JS vanilla en el frontend. Menos superficie de supply-chain,
pero todo el framework es artesanal (auth, routing, render).

**Componentes NO desplegados (peso muerto en el repo):** `index.html` raíz
(1 899 líneas, dashboard viejo), `tools/` (18 archivos, duplicado exacto de
`marketing-legacy/tools/`), `marketing-legacy/` completo (~2.5 MB, incluye
bot de Telegram en Python ya apagado).

---

## 2. PATRONES DE BASE DE DATOS

- **Acceso:** PDO con `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, utf8mb4. ✅
- **Prepared statements:** ~95 usos en `luna_api.php`; cero inyecciones SQL
  detectadas en el API. ✅
- **Interpolación directa (estilo, no vulnerabilidad):** 3 cláusulas `LIMIT`
  interpoladas pero casteadas a int y acotadas (`luna_api.php:1088, 2006, 2020`);
  varios crons interpolan valores generados por el servidor (p. ej.
  `luna_email_marketing_cron.php:133` interpola `date('m-d')` — seguro hoy,
  mal precedente).
- **Esquema implícito:** no hay migraciones; las tablas `luna_*` se crean
  on-demand con `CREATE TABLE IF NOT EXISTS` repetido en varios lugares
  (la de memoria está duplicada 4 veces: `luna_api.php:1774, 1791, 1864, 1934`).
- **Sin paginación** en resultados grandes (`luna_entity_search`,
  `luna_memory_get`, `luna_full_briefing`) y subconsultas correlacionadas
  (`luna_api.php:1655`) que escalarán mal con más miembros.

## 3. ENDPOINTS DEL API (resumen)

67 acciones en un solo `switch` (`luna_api.php:386–2650`): 38 de lectura y
29 de escritura. Tres capas de permiso: sesión PHP → `requireAdmin()` /
`requireActor()` / allowlist de servicio (Athena: 20 lecturas + 5 escrituras
aditivas, `rol='service'`, nunca admin). El inventario completo por endpoint
con líneas está en el reporte del switch — bien diseñado en general, con
auditoría (`lunaAudit`) en los denegados.

---

## 4. HALLAZGOS POR SEVERIDAD

### 🔴 CRÍTICO

| # | Hallazgo | Dónde | Detalle |
|---|----------|-------|---------|
| C1 | **ANTHROPIC_API_KEY expuesta sin rotar** | consola Anthropic (la llave vieja estuvo pegada en HTML y en el chat de una sesión anterior) | Los propios docs lo piden 4 veces (`HANDOFF_NUEVA_SESION.md:59`, `DEPLOY_LUNA.md:22`, `TEST_CHECKLIST.md:12`, `HANDOFF_…PARTE2.md:122`) y no hay evidencia de rotación. Quien la tenga puede gastar crédito ilimitado. El código actual ya NO contiene llaves (verificado), pero la llave sigue viva. |
| C2 | **9 crons ejecutables por HTTP sin autenticación** | `luna/cron/*.php` (p. ej. `luna_email_marketing_cron.php:346`, `luna_backup_cron.php:123`) | Cualquiera en internet puede disparar envíos masivos de email a miembros (`?mode=birthday`), llamadas a Anthropic (costo), reportes y backups. |
| C3 | **Webhook de Telegram sin verificación de firma** | `luna_telegram_webhook.php:65–71` | Acepta cualquier JSON en `php://input`; solo filtra por chat ID (adivinable/filtrable). Un atacante puede forjar callbacks y crear tickets. Telegram ofrece `secret_token` y no se valida. |
| C4 | **`luna_diag.php` público filtra demasiado** | `luna_diag.php:27–33, 36–41, 81` | Expone longitud + primeros/últimos 4 chars de la llave de servicio y de la API key, errores crudos de la BD (host/usuario), y los últimos 900 caracteres del log del chat (**puede contener PII de miembros**). |

### 🟠 ALTO

| # | Hallazgo | Dónde | Detalle |
|---|----------|-------|---------|
| A1 | **Sin protección CSRF en las 29 escrituras** | todo `luna_api.php` POST | Sin token ni `SameSite` explícito: una página maliciosa puede crear tickets/notas/citas con la sesión de un agente logueado. |
| A2 | **Llave de servicio aceptada por GET/POST** | `luna_api.php:57` (`?service_key=`) | Queda en access logs, historial y cachés de proxy. Debería ser solo headers. |
| A3 | **XSS: ~30 `innerHTML` con datos del API sin `esc()` consistente** | `luna/index.html:2350, 2533, 2596, 2620, 3339…` y `index.html:1547, 1830` | Si un nombre/nota de miembro trae HTML, se ejecuta en el navegador de quien lo vea. Existe `esc()` pero no se aplica parejo. |
| A4 | **Chat IA abierto a todo usuario logueado** | server: candado comentado `luna_api.php:415–422`; client: `canUseChat()` siempre `true` (`luna/index.html:3644`) | Cualquier agente puede consumir Anthropic sin límite (costo) y extraer datos vía tools. Decisión consciente ("por ahora") pero sin límite de gasto ni rate limit. |
| A5 | **Dashboard legacy maneja la API key en el navegador** | `index.html` raíz `:1439–1465, 1518–1541` (y copias en `marketing-legacy/`) | Guarda la llave en localStorage en texto plano y llama a Anthropic desde el browser (origen de C1). **No está desplegado**, pero sigue en el repo listo para reusarse. |
| A6 | **Dos mecanismos de deploy simultáneos + rama de producción ambigua** | `.cpanel.yml` (HEAD, sin rama fija) vs GitHub Actions (`claude/happy-planck-Dtzud`) | La rama de producción va 63 commits adelante y 23 atrás de `main`. Un clic en cPanel desde otra HEAD puede hacer rollback silencioso de producción. |

### 🟡 MEDIO

| # | Hallazgo | Dónde |
|---|----------|-------|
| M1 | Mensajes de excepción devueltos al cliente (`Error inesperado: …`) | `luna_api.php:2657` |
| M2 | Sin rate limiting en ningún endpoint (DoS, exfiltración paginando, costo IA) | global |
| M3 | `system` prompt del chat sin límite de tamaño ni validación | `luna_api.php:434` |
| M4 | `.gitignore` incompleto: no cubre `*.log`, `*.sql(.gz)`, `.env` | `.gitignore` |
| M5 | Backup: comando offsite pasa por shell (`offsite_cmd`) y el dir de backups depende de no acabar bajo `public_html` | `luna_backup_cron.php:102, 31–67` |
| M6 | `isAdmin` del frontend acepta `location.protocol==='file:'` como admin (solo UI, pero confuso/riesgoso) | `luna/index.html:2975, 3018, 3104` |
| M7 | Config duplicada en los 9 crons (emails, remitentes, umbrales) — cambiar un correo = tocar 9 archivos | `luna/cron/*` |
| M8 | Webhook Telegram sin rate limit (taps repetidos = tickets duplicados) | `luna_telegram_webhook.php:159–203` |
| M9 | Validación de entrada laxa: longitudes sin tope en notas/memoria/body (`luna_memory_set`, `luna_review_outbound`, `luna_create_member`) | `luna_api.php:1877, 2148, 1281` |

### 🟢 BAJO

| # | Hallazgo |
|---|----------|
| B1 | Monolitos: `luna/index.html` 4 184 líneas (UI + agentes + memoria + audit en un archivo); `luna_api.php` switch de 2 260 líneas. |
| B2 | `tools/` es duplicado byte-a-byte de `marketing-legacy/tools/` (≈1 MB x2) + `index.html` raíz legacy: peso muerto. |
| B3 | Logging inconsistente (9 helpers `logXxx()` distintos); errores de `mail()` silenciados con `@`. |
| B4 | Auditoría incompleta: `luna_update_goal`, `luna_signal_dismiss`, `luna_outbound_approve` no escriben en `lunaAudit`. |
| B5 | Modelo de IA hardcodeado (`claude-sonnet-4-6` en `luna_ai.php:48,97` y `luna_api.php`). |
| B6 | Accesibilidad: navegación solo-emoji, sin ARIA, estado solo por color. |
| B7 | Ramas viejas sin limpiar (7 ramas; varias muertas) y PR #1 abierto a `main` sin decisión. |
| B8 | Sin `session_regenerate_id()` visible ni endpoint de logout en el API (revisar el login del CRM). |

### ✅ Lo que está BIEN (vale decirlo)

- Cero inyección SQL en el API; `hash_equals` + `trim` en la llave de servicio; allowlist de servicio con rol `service` (defensa en dos capas).
- Service worker passthrough: no cachea respuestas del API. Decisión correcta.
- Secretos de FTP solo en GitHub Secrets; `luna_config.php` fuera del repo; llave de servicio enmascarada en docs.
- Degradación elegante de IA en crons (fallback determinista si no hay key).
- Documentación operativa real y por audiencia (runbook, handoff, checklist) — raro de ver y muy valioso.

---

## 5. PLAN DE TRABAJO PRIORIZADO (con estimados)

### Fase 0 — HOY (≈1 hora, sin código)
| Tarea | Esfuerzo | Cubre |
|---|---|---|
| 0.1 Rotar la `ANTHROPIC_API_KEY` en console.anthropic.com y actualizar `luna_config.php` en el servidor | **15 min** | C1 |
| 0.2 Poner límite de gasto/alertas de uso en la consola de Anthropic | **10 min** | C1, A4 |
| 0.3 Configurar `secret_token` al registrar el webhook de Telegram (paso 1 de C3) | **15 min** | C3 |

### Fase 1 — Esta semana (≈1 día de trabajo)
| Tarea | Esfuerzo | Cubre |
|---|---|---|
| 1.1 Token de cron: exigir `?cron_token=` (o header) en los 9 crons; rechazar sin él; quitar overrides `?mode=` por HTTP | **2–3 h** | C2 |
| 1.2 Validar `secret_token` en `luna_telegram_webhook.php` (rechazar si no coincide) | **1 h** | C3 |
| 1.3 Blindar `luna_diag.php`: exigir admin o reducir a `{status:'up'}`; eliminar el dump del chat log | **30–45 min** | C4 |
| 1.4 Dejar de aceptar `?service_key=` por GET/POST (solo headers) — avisar a Athena por si lo usa así | **30 min** | A2 |
| 1.5 No devolver `$e->getMessage()` al cliente; loggear con ID de error | **30 min** | M1 |

### Fase 2 — Próximas 2 semanas (≈2–3 días)
| Tarea | Esfuerzo | Cubre |
|---|---|---|
| 2.1 CSRF: token de sesión en todos los POST + `SameSite=Strict` en la cookie | **3–5 h** | A1 |
| 2.2 Barrido XSS: aplicar `esc()`/`textContent` en los ~30 `innerHTML` señalados | **4–8 h** | A3 |
| 2.3 Rate limiting básico por sesión (p. ej. 60 req/min) + tope diario de llamadas a `luna_chat` por usuario | **2–3 h** | M2, A4 |
| 2.4 Decidir el candado del chat: re-activar el gate del servidor con lista de usuarios permitidos (config, no comentario) | **1 h** | A4 |
| 2.5 Resolver el deploy doble: elegir GitHub Actions como única vía, fijar rama en `.cpanel.yml` o desactivar cPanel Git; decidir destino de PR #1 (merge a `main` o declarar la rama actual como producción en el README) | **1–2 h + decisión** | A6 |
| 2.6 Completar `.gitignore` (`*.log`, `*.sql*`, `.env*`) | **10 min** | M4 |
| 2.7 Topes de longitud en inputs (notas, memoria, system prompt, body) | **1–2 h** | M3, M9 |

### Fase 3 — Este mes (mantenibilidad, ≈3–5 días)
| Tarea | Esfuerzo | Cubre |
|---|---|---|
| 3.1 `luna_cron_config.php` compartido (emails, umbrales, modelo IA) + `lunaLog()` único | **3–4 h** | M7, B3, B5 |
| 3.2 Borrar peso muerto: `tools/` duplicado, evaluar archivar `index.html` raíz y `marketing-legacy/` en otra rama/tag | **1 h** | B2, A5 |
| 3.3 Auditar escrituras faltantes en `lunaAudit` + rate limit del webhook Telegram | **2 h** | B4, M8 |
| 3.4 Extraer el switch gigante a funciones-handler por dominio (sin cambiar comportamiento) | **1–2 días** | B1 |
| 3.5 Paginación en endpoints de listas grandes y quitar subconsultas correlacionadas | **3–4 h** | rendimiento |
| 3.6 Limpiar ramas muertas; documentar la rama de producción en README | **30 min** | B7 |

### Fase 4 — Backlog (cuando haya calma)
- Partir `luna/index.html` en módulos (build con Vite o ES modules nativos) — **3–5 días**.
- Mover el dashboard legacy a proxy server-side si algún día se revive — **2–4 h**.
- ARIA + navegación por teclado en la PWA — **1–2 días**.
- Revisar `session_regenerate_id()` y logout en el login del CRM — **1–2 h**.
- Migraciones de BD versionadas en vez de `CREATE TABLE IF NOT EXISTS` dispersos — **1 día**.

---

## 6. NOTA FINAL

El sistema está mejor de lo que sugiere su tamaño: el API no tiene inyección
SQL, la cuenta de servicio está bien acotada, y la decisión de proxear
Anthropic por el servidor en LUNA es correcta. Los riesgos graves se
concentran en **cuatro puertas sin candado** (llave sin rotar, crons
abiertos, webhook sin firma, diagnóstico público) — todas arreglables en
menos de un día de trabajo. La deuda estructural (monolitos, deploy doble,
rama ambigua) es real pero no urgente; conviene atacarla después de cerrar
las puertas.
