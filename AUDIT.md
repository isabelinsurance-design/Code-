# Codebase Audit — Medicare with Isabel CRM

**Audited:** 2026-06-12 · **Scope:** full repository (`crm/` app, deployment, DB patterns)
**Stack:** PHP 8.2 + MySQL 8, no framework, no dependency manager. cPanel/Bluehost deploy.
**Size:** ~17,500 lines PHP across 17 files; `index.php` alone is **10,895 lines**.

> **Remediation status (2026-06-12, same day):** Phase 1 + role fix implemented —
> ✅ C1 (`usuarios_setup.php` hardened: admin-gated, env-only passwords, never echoed)
> ✅ C2 (CSRF: per-session token, global fetch wrapper, verified on every POST to `api.php`/`api_ai.php`; plus `SameSite=Lax` cookies)
> ✅ H1 (webhook secret: config constant first, empty/placeholder refused, `hash_equals`)
> ✅ H2 (`save_member`: non-admin can no longer change `agente_id` on existing members; form locks the selector and always includes the current agent)
> ✅ H3 (admin gates on the 3 pipeline-config actions)
> ✅ H4 (`'agente'`→`'agent'` queries fixed in `index.php`)
> ✅ H6 (session cookies: `HttpOnly` + `Secure` (auto) + `SameSite=Lax` via shared `session_boot.php`; hardened logout)
> ✅ M2 (login throttling: 8 failed attempts/10 min per user/IP; `display_errors` off in `login.php`)
> ✅ M5 (`uploads/.htaccess` denies script execution, versioned + deployed)
> Remaining: H5 (runtime DDL → deploy-time migrations), M1 (decompose `index.php`), M3 (transactions), M6 (tests/CI), and Phase 3–4 items.

---

## 1. Executive Summary

The CRM is a functional, feature-rich single-tenant app (members, pipeline, tickets,
citas, bonos, gastos, retención, campañas, AI assistant). It works in production, but it
carries **significant structural and security debt** typical of an organically-grown PHP
monolith:

- **No CSRF protection** on ~77 state-changing endpoints (session-cookie auth).
- A **publicly reachable user-creation script** (`usuarios_setup.php`).
- **Schema migrations (DDL) run on every page load** — performance and safety risk.
- One **10,895-line `index.php`** mixing PHP, HTML, CSS, and ~3,000 lines of inline JS.
- A latent **role-value inconsistency** (`'agent'` vs `'agente'`) that silently breaks queries.
- **No tests, no CI, no dependency manager.**

None of the findings indicate active compromise, and SQL access is mostly parameterized.
The priorities below focus on closing the security gaps and arresting structural decay
before the monolith becomes unmaintainable.

---

## 2. Architecture Overview

```
Browser (1 big SPA-ish page)
   │  full-page load + AJAX (fetch) + "softReload" partial refresh
   ▼
crm/index.php  ──────────────►  renders ALL tabs/panes + inline JS/CSS (10.9k lines)
   │
   ├─ crm/api.php        JSON API, 77 actions via switch($action)
   ├─ crm/api_ai.php     Anthropic proxy (Isabel AI)
   ├─ crm/profile.php    member profile (HTML fragment via AJAX)
   ├─ crm/member_form.php member add/edit form (HTML fragment)
   ├─ crm/finance_data.php  finance portal (admin + 2nd password)
   ├─ crm/fb_leads_webhook.php  inbound FB lead webhook
   ├─ crm/login.php / logout.php
   ├─ crm/equipo.php, estrategia.php, reporte_nomina.php, reporte_export.php
   └─ one-off scripts: usuarios_setup.php, migrar_indices.php, import_gastos.php
   ▼
config.php (gitignored) → db() PDO singleton, auth(), isAdmin(), constants
   ▼
MySQL (single DB, ~30+ tables created/altered lazily at runtime)
```

**Patterns:**
- **Server-rendered monolith.** `index.php` renders every tab up-front; the client shows/hides
  panes (`showTab`) and refreshes via a custom `softReload()` that re-fetches the whole page
  and swaps the active pane's innerHTML.
- **Action-dispatch API.** `api.php` is one giant `switch ($action)`; auth is a single gate at
  the top, per-action authorization is ad-hoc (`if(!$admin) …`).
- **Schema-on-demand.** Tables and columns are created/altered inside request handlers with
  `CREATE TABLE IF NOT EXISTS` / `SHOW COLUMNS` / `ALTER TABLE` rather than versioned migrations.

---

## 3. Code Structure

| File | Lines | Role | Notes |
|---|---|---|---|
| `index.php` | 10,895 | Everything: layout, all tabs, ~3k lines JS, 27 `<script>/<style>` blocks, 322 inline `onclick` | **Primary maintainability risk** |
| `api.php` | 2,237 | 77 JSON actions | 27 inline DDL ops; mixed `$admin`/`isAdmin()` |
| `profile.php` | 1,000 | Member profile fragment | |
| `api_ai.php` | 713 | Anthropic API proxy | key stays server-side ✅ |
| `member_form.php` | 677 | Member form fragment | |
| `reporte_nomina.php` | 451 | Payroll report | |
| `fb_leads_webhook.php` | 302 | Lead intake | secret check has a gap (§7) |
| others | <250 ea. | login, equipo, estrategia, exports, one-off scripts | |

**Observations**
- **God file:** `index.php` couples data loading, business rules, HTML, CSS, and JS. 253 JS
  functions, 322 inline `onclick` attributes — high coupling, hard to test or change safely.
- **Mixed languages in identifiers** (Spanish/English) — e.g., `miembros`, `gastos`, `tickets`,
  `reembolsar_a`, alongside `agent`/`agente`.
- **107 TODO/FIXME/HACK markers** across the codebase.
- **No separation** of presentation/logic/data; no router; no templating engine.

---

## 4. Dependencies

- **No `composer.json` / `package.json`.** Pure PHP standard library + PDO; no third-party PHP
  libraries vendored or managed.
- **External runtime deps:** Google Fonts (CDN) on several pages; Anthropic API (`api_ai.php`);
  optional Telegram/email notifications.
- **Implication:** no supply-chain surface from packages (good), but also no automated way to
  patch, lock, or audit anything; everything is hand-rolled.

---

## 5. Database Patterns

- **PDO, exceptions on, FETCH_ASSOC default** — good baseline (`config.example.php`).
- **Parameterized queries almost everywhere** — SQL injection surface is small. Dynamic column
  names that reach SQL are whitelisted (`api.php:58/674/1161`) ✅.
- **Runtime DDL (anti-pattern):** ~66 `CREATE TABLE IF NOT EXISTS` / `ALTER TABLE` / `SHOW
  COLUMNS` statements run inside normal requests (39 in `index.php`, 27 in `api.php`). Every page
  load issues schema checks. Risks: latency, lock contention, partial/inconsistent schema across
  servers, and accidental data migrations (e.g. `index.php:828`
  `UPDATE miembros SET estado='CANCELED' WHERE estado='DISENROLLED'` runs on every load).
- **Duplicated definitions:** `retencion_llamadas` created in two places (`api.php:1316` and
  `:1380`); same logic scattered.
- **Indexes** exist only via the manual `migrar_indices.php` script (must be run by hand);
  newer tables created at runtime may lack indexes.
- **No transactions** around multi-write operations (e.g. `save_member` writes `miembros` +
  `historial_planes` + `actividad` separately; a mid-way failure leaves partial state).
- **Mass-assignment:** `save_member` builds INSERT/UPDATE from `array_intersect(POST keys,
  columns)` — convenient but lets any writable column be set by the client.

---

## 6. API Endpoints (`api.php`, 77 actions)

Single gate at top: `session_start()` + `$_SESSION['user']` check → `Content-Type: application/json`,
`display_errors=0` (correct for JSON). Per-action authorization is manual.

**Well-gated (admin / ownership checks present):** `import_csv`, `save_comision`,
`toggle_bono_pagado`, `save_pago_bono`, `delete_pago_bono`, `finance_auth`,
`get_bonos_report`, `toggle_gasto_reembolso`, `update_gasto_status`, ticket/cita/nota/proyecto
edits & deletes (owner-or-admin), etc. — **37 gating checks total.**

**Authorization gaps found:**
- `add_pipeline_config_row`, `update_pipeline_config`, `delete_pipeline_config` — **no admin
  check**; any logged-in user can alter pipeline automation config.
- `save_member` — **no admin/owner gate**; any logged-in user can edit any member and set any
  whitelisted column, including `agente_id`, `ss`, `mbi`. (Root cause of the earlier
  "responsible agent silently changed" issue.)

**Consistency issues:** uses both `$admin` (cached) and `isAdmin()` (live) interchangeably;
`jsonOk($x)` always wraps as `{ok:true,data:$x}`, which has tripped up callers (the GASTOS
double-wrap bug).

---

## 7. Deployment Config

- **`.cpanel.yml`:** on "Deploy HEAD Commit", copies `crm/.` → `public_html/.../crm/`. No build
  step, no asset pipeline, no migration step.
- **Secrets:** `config.php` and `prompts.php` are gitignored and **not** overwritten by deploy ✅.
  `.gitignore` also excludes `uploads/`, DB dumps, `.env`. Good hygiene.
- **`docker-compose.yml`:** local dev (PHP 8.2 + MySQL 8 + phpMyAdmin), weak creds but local-only.
- **Risks:**
  - One-off scripts (`usuarios_setup.php`, `migrar_indices.php`, `import_gastos.php`) are inside
    `crm/` and therefore **deployed and web-reachable**.
  - **`fb_leads_webhook.php` secret gap:** it does `define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET_FB') ?: '')`
    (reads the **env var**, not the config **constant**). On typical cPanel runtime `getenv` returns
    nothing → `WEBHOOK_SECRET === ''` → the check `$secret !== WEBHOOK_SECRET` passes for an
    **empty secret**, leaving the webhook effectively unauthenticated.
  - **Session cookies not hardened** — no `HttpOnly`/`Secure`/`SameSite` configured anywhere.

---

## 8. Findings by Severity

### 🔴 Critical
| # | Finding | Location |
|---|---|---|
| C1 | **`usuarios_setup.php` is public (no auth) and creates users + prints passwords.** Idempotent (skips existing) but should never be deployable/reachable. | `usuarios_setup.php` (whole file) |
| C2 | **No CSRF protection** on any of the ~77 state-changing endpoints; auth is cookie-based, so any external page can forge authenticated POSTs (create/delete members, tickets, bonos, gastos, etc.). | `api.php` (all), `api_ai.php` |

### 🟠 High
| # | Finding | Location |
|---|---|---|
| H1 | **FB webhook auth bypass** when `WEBHOOK_SECRET_FB` isn't a runtime env var → empty secret accepted. | `fb_leads_webhook.php:16-17,34` |
| H2 | **`save_member` has no admin/owner authorization** → any user can edit any member and overwrite `agente_id`, `ss`, `mbi`, etc. (mass-assignment + privilege issue). | `api.php` `save_member` |
| H3 | **Pipeline-config endpoints unauthenticated beyond login** (no admin gate). | `api.php` add/update/delete_pipeline_config |
| H4 | **Role value inconsistency `'agent'` vs `'agente'`** → queries silently return empty depending on the real DB value (e.g. bonos dropdowns vs attendance/team lists). | `index.php:4894,4952` vs `equipo.php`, `estrategia.php`, `finance_data.php`, `reporte_nomina.php`, `fb_leads_webhook.php:254` |
| H5 | **Runtime DDL on every request** (perf + schema-drift + accidental data updates like the global `DISENROLLED→CANCELED`). | `index.php` (39), `api.php` (27) |
| H6 | **Session cookies not hardened** (`HttpOnly`/`Secure`/`SameSite` unset) → XSS cookie theft / CSRF easier. | global (no `session_set_cookie_params`) |

### 🟡 Medium
| # | Finding | Location |
|---|---|---|
| M1 | **10,895-line `index.php` monolith** (PHP+HTML+CSS+~3k JS, 322 inline `onclick`). Severe maintainability/testability risk. | `index.php` |
| M2 | **Login accepts 4 password variants & has no rate limiting/lockout** → easier brute force. | `login.php:18-31` |
| M3 | **No transactions** around multi-table writes (partial-write corruption). | `api.php` `save_member`, others |
| M4 | **One-off scripts shipped to production** (`migrar_indices.php`, `import_gastos.php`). Admin-gated but should be removed post-use. | those files |
| M5 | **Uploads served from web-reachable dir without execution hardening** (no `.htaccess`); mitigated by extension whitelist. | `api.php` upload_foto/recibo/proyecto; `uploads/` |
| M6 | **No automated tests, no CI, no linting gate.** | repo-wide |
| M7 | **Duplicated/again-defined schema & logic** (e.g. `retencion_llamadas` twice). | `api.php:1316,1380` |
| M8 | **Indexes only via manual script**; runtime-created tables may be unindexed → slow as data grows. | `migrar_indices.php`, runtime `CREATE TABLE` |

### 🟢 Low
| # | Finding | Location |
|---|---|---|
| L1 | 107 TODO/FIXME/HACK markers. | repo-wide |
| L2 | Mixed Spanish/English identifiers; magic-string statuses scattered (no central enum). | repo-wide |
| L3 | No structured logging / error monitoring. | repo-wide |
| L4 | Potential N+1 building per-agent stats in loops. | `index.php` dashboard/reports |
| L5 | `jsonOk()` double-wrap convention is error-prone (already caused a bug). | `api.php:13` |

---

## 9. Prioritized Task List (with effort estimates)

> Effort key: **S** ≈ <½ day · **M** ≈ ½–2 days · **L** ≈ 3–5 days · **XL** ≈ 1–3 weeks

### Phase 1 — Security hardening (do first)
1. **[C1·S]** Delete `usuarios_setup.php` from the repo/server (or move behind admin auth + remove password display). Same for one-off scripts after use (**M4**).
2. **[H1·S]** Fix webhook secret: compare against the `WEBHOOK_SECRET_FB` **constant**, and reject empty/unset secrets (`if (WEBHOOK_SECRET_FB === '' || !hash_equals(WEBHOOK_SECRET_FB,$secret)) 401`).
3. **[H6·S]** Harden session cookies: `session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Lax'])` before `session_start()` everywhere (centralize in `config.php`).
4. **[H2·M]** Add authorization to `save_member` (only admin may change `agente_id`/sensitive fields; restrict editable columns by role).
5. **[H3·S]** Add `if(!$admin) jsonErr(...)` to the three pipeline-config actions.
6. **[C2·L]** Add CSRF protection: issue a per-session token, inject into a JS variable, send via `X-CSRF-Token` header from the central `fetch` wrapper, verify in `api.php`/`api_ai.php`. (One choke point each — large only because of breadth of testing.)
7. **[M2·S]** Add login throttling (e.g. delay/lockout after N failures per username/IP); reconsider the 4-variant password matching.

### Phase 2 — Data integrity & DB health
8. **[H4·M]** Resolve `agent`/`agente` role inconsistency: pick one value, migrate `usuarios.rol`, update all queries; add a constant.
9. **[H5·L]** Move runtime DDL into a versioned migration script run **once at deploy** (extend `migrar_indices.php` into a real migrator); remove per-request `CREATE/ALTER/SHOW COLUMNS` and the global `DISENROLLED→CANCELED` update.
10. **[M3·M]** Wrap multi-table writes (`save_member`, ticket close + next-steps, bonos) in transactions.
11. **[M8·S]** Ensure all hot tables have indexes (run/extend `migrar_indices.php`); add indexes for `gastos`, `proyectos`, new tables.
12. **[M7·S]** De-duplicate repeated `CREATE TABLE`/logic.

### Phase 3 — Structure & maintainability
13. **[M1·XL]** Decompose `index.php`: extract each tab into an included partial; move inline JS to versioned `.js` files; move CSS to a stylesheet. Incremental, tab by tab.
14. **[L5·S]** Normalize API responses (decide flat `{ok,data,totales}` vs nested) and document it; add a tiny client helper.
15. **[M6·L]** Introduce a minimal test harness (PHPUnit) covering `api.php` authz + critical money paths (bonos, gastos, comisiones); add a GitHub Action running `php -l` + tests.
16. **[M5·S]** Drop a `.htaccess` in `uploads/` disabling script execution; validate MIME (not just extension) on upload.
17. **[L2·M]** Centralize status enums (estados de miembro/ticket/gasto) into one PHP + JS source of truth.

### Phase 4 — Operability (nice-to-have)
18. **[L3·M]** Add structured error logging (to file/Sentry-like) instead of silent `catch(Exception $e){}`.
19. **[L1·ongoing]** Triage the 107 TODO/FIXME markers; convert real ones to tracked issues.
20. **[L4·M]** Profile dashboard/report queries; eliminate N+1 with joins/aggregates.

**Suggested first sprint:** items 1–5 + 7 (all S/M security wins, ~3–4 days) → item 8 (role fix) → item 6 (CSRF).

---

## 10. What's Already Good
- Parameterized SQL almost everywhere; whitelisted dynamic columns.
- `config.php`/secrets correctly gitignored and preserved across deploys.
- AI API key stays server-side; admin + second-factor password on finance portal.
- `session_regenerate_id(true)` on login (anti session-fixation).
- Reasonable per-action ownership checks on tickets/citas/notas/proyectos.
- Soft-reload UX with auto-refresh is thoughtfully built.
