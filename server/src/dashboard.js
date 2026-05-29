// ============================================================
//  Dashboard — observabilidad en vivo
//  ──────────────────────────────────
//  Una página HTML simple que Isabel o Sami pueden abrir para ver
//  qué está haciendo Athena en tiempo real. NO React, NO bundler,
//  HTML+JS plano que jala /dashboard/state cada 5s.
//
//  Paleta lino cálido (matches todoisabel.html). Tipografía Fraunces
//  para títulos, DM Sans para body, DM Mono para datos.
//
//  Acceso: Basic Auth con DASHBOARD_PASSWORD (env). Usuario libre
//  (cualquiera funciona). El password se mete una vez en el browser.
//  Si DASHBOARD_PASSWORD no está, el dashboard se desactiva.
// ============================================================
import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const BACKUP_DIR = join(__dirname, '..', 'backups');

export function dashboardEnabled() {
  return Boolean(process.env.DASHBOARD_PASSWORD);
}

export function dashboardAuth(req, res, next) {
  if (!dashboardEnabled()) return res.status(404).send('Dashboard deshabilitado.');
  const header = req.headers.authorization || '';
  if (!header.startsWith('Basic ')) {
    res.set('WWW-Authenticate', 'Basic realm="Athena Dashboard"');
    return res.status(401).send('Auth required');
  }
  try {
    const decoded = Buffer.from(header.slice(6), 'base64').toString('utf8');
    const [, pass] = decoded.split(':');
    if (pass !== process.env.DASHBOARD_PASSWORD) {
      res.set('WWW-Authenticate', 'Basic realm="Athena Dashboard"');
      return res.status(401).send('Wrong password');
    }
  } catch {
    return res.status(401).send('Bad auth header');
  }
  next();
}

// ============================================================
//  HTML
// ============================================================
export function renderDashboardHtml() {
  return `<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Athena · Dashboard</title>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#F6F1E9">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #F6F1E9; --bg2: #EDE6D8; --bg3: #E3DAC8;
    --card: #FFFEFB; --card2: #FAF7F2;
    --ink: #1C1A17; --ink2: rgba(28,26,23,0.62); --ink3: rgba(28,26,23,0.38);
    --gold: #8B7355; --gold2: #6B5640;
    --gold3: rgba(139,115,85,0.12); --gold4: rgba(139,115,85,0.06);
    --amber: #C4784A; --amber2: rgba(196,120,74,0.14);
    --red: #B85540; --red2: rgba(184,85,64,0.14);
    --green: #6A9472; --green2: rgba(106,148,114,0.14);
    --border: rgba(139,115,85,0.16);
    --border2: rgba(139,115,85,0.30);
    --shadow: 0 14px 40px rgba(80,60,40,0.10);
    --shadow2: 0 4px 14px rgba(80,60,40,0.06);
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: 'DM Sans', system-ui, sans-serif;
    background: var(--bg);
    background-image: radial-gradient(circle at 20% 0%, rgba(139,115,85,0.05) 0, transparent 50%),
                      radial-gradient(circle at 100% 100%, rgba(196,120,74,0.04) 0, transparent 50%);
    color: var(--ink);
    padding: 24px 20px 60px;
    min-height: 100vh;
    font-size: 14px;
    line-height: 1.5;
  }
  .wrap { max-width: 1400px; margin: 0 auto; }

  /* ─ Hero ─ */
  .hero {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 22px 26px;
    margin-bottom: 18px;
    box-shadow: var(--shadow);
    display: flex; justify-content: space-between; align-items: center; gap: 24px;
    flex-wrap: wrap;
  }
  .hero h1 {
    font-family: 'Fraunces', serif;
    font-weight: 500;
    font-size: 32px;
    letter-spacing: -0.02em;
    background: linear-gradient(135deg, #8B7355 0%, #A89070 50%, #8B7355 100%);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
  }
  .hero .sub { color: var(--ink2); font-size: 12px; margin-top: 4px; font-family: 'DM Mono', monospace; }
  .hero .stamp { color: var(--ink3); font-size: 12px; font-family: 'DM Mono', monospace; }
  .hero .dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%;
               background: var(--green); margin-right: 6px;
               animation: pulse 2s infinite; }
  @keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.6; transform: scale(1.4); }
  }

  /* ─ AEP banner ─ */
  .aep {
    background: linear-gradient(135deg, var(--amber2) 0%, rgba(196,120,74,0.06) 100%);
    border: 1px solid var(--amber);
    border-radius: 12px;
    padding: 12px 18px;
    margin-bottom: 18px;
    font-family: 'Fraunces', serif;
    color: var(--gold2);
    font-size: 15px;
    display: none;
  }
  .aep.active { display: block; }

  /* ─ Grid ─ */
  .grid {
    display: grid;
    gap: 16px;
    grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
  }
  .card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 18px 20px;
    box-shadow: var(--shadow2);
    transition: box-shadow .2s;
  }
  .card:hover { box-shadow: var(--shadow); }
  .card.kpi { grid-column: 1 / -1; background: linear-gradient(135deg, #FFFEFB 0%, #FAF7F2 100%); }
  .card.wide { grid-column: span 2; }
  .card h2 {
    font-family: 'Fraunces', serif;
    font-weight: 500;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: var(--gold);
    margin-bottom: 14px;
    display: flex; align-items: center; gap: 10px;
  }
  .card h2::before {
    content: ''; width: 16px; height: 1px; background: var(--gold);
    display: inline-block;
  }

  /* ─ Rows ─ */
  .row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 7px 0;
    border-top: 1px dashed var(--border);
    font-size: 13px;
    gap: 12px;
  }
  .row:first-of-type { border-top: 0; padding-top: 0; }
  .row .label { color: var(--ink2); }
  .row .val { font-family: 'DM Mono', monospace; color: var(--ink); font-weight: 500; }
  .num { font-family: 'Fraunces', serif; font-size: 26px; font-weight: 500; color: var(--gold2); }

  /* ─ KPI tiles ─ */
  .kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 12px;
  }
  .kpi-tile {
    background: var(--card2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 14px 16px;
    text-align: left;
  }
  .kpi-tile .lbl {
    font-size: 10px; text-transform: uppercase; letter-spacing: 0.1em;
    color: var(--ink3); margin-bottom: 4px;
  }
  .kpi-tile .val {
    font-family: 'Fraunces', serif; font-size: 28px; font-weight: 500;
    color: var(--gold2); line-height: 1.1;
  }
  .kpi-tile .sub { font-size: 11px; color: var(--ink3); margin-top: 2px; }
  .kpi-tile.warn .val { color: var(--amber); }
  .kpi-tile.bad .val { color: var(--red); }

  /* ─ Pills ─ */
  .pill {
    display: inline-block; padding: 2px 8px; border-radius: 4px;
    font-size: 10px; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.04em; margin-right: 6px; font-family: 'DM Sans', sans-serif;
  }
  .pill.alto { background: var(--red2); color: var(--red); }
  .pill.aviso { background: var(--amber2); color: var(--amber); }
  .pill.info { background: var(--green2); color: var(--green); }

  /* ─ Activity log ─ */
  .activity { font-family: 'DM Mono', monospace; font-size: 11px;
              max-height: 360px; overflow-y: auto; }
  .activity > div {
    padding: 5px 0; border-top: 1px dotted var(--border);
    display: flex; gap: 8px;
  }
  .activity > div:first-child { border-top: 0; }
  .ts { color: var(--ink3); white-space: nowrap; }
  .tool { color: var(--gold); font-weight: 500; white-space: nowrap; }
  .summary { color: var(--ink2); overflow: hidden; text-overflow: ellipsis; }

  /* ─ Misc ─ */
  .muted { color: var(--ink3); font-style: italic; font-size: 13px; }
  .item { padding: 7px 0; border-top: 1px dashed var(--border); font-size: 13px; }
  .item:first-of-type { border-top: 0; padding-top: 0; }
  .item .meta { color: var(--ink3); font-size: 11px; font-family: 'DM Mono', monospace; }

  /* ─ Tasks by owner ─ */
  .owners { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 12px; }
  .owner {
    background: var(--card2); border: 1px solid var(--border);
    border-radius: 8px; padding: 10px 12px; text-align: center;
  }
  .owner .lbl { font-size: 10px; text-transform: uppercase; color: var(--ink3); }
  .owner .val { font-family: 'Fraunces', serif; font-size: 22px; color: var(--gold2); }

  pre { background: var(--card2); padding: 10px; border-radius: 8px;
        overflow-x: auto; font-size: 11px; max-height: 280px; margin: 0;
        font-family: 'DM Mono', monospace; border: 1px solid var(--border); }

  @media (max-width: 720px) {
    body { padding: 14px 12px 60px; font-size: 13px; }
    .hero { padding: 16px 18px; }
    .hero h1 { font-size: 26px; }
    .card.wide { grid-column: span 1; }
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="hero">
    <div>
      <h1>Athena</h1>
      <div class="sub"><span class="dot"></span>Dashboard operacional · refresca cada 5s</div>
    </div>
    <div class="stamp" id="stamp">cargando…</div>
  </div>
  <div class="aep" id="aep"></div>
  <div class="grid" id="grid"></div>
</div>

<script>
const grid = document.getElementById('grid');
const stamp = document.getElementById('stamp');
const aepBanner = document.getElementById('aep');

async function load() {
  try {
    const r = await fetch('/dashboard/state', { credentials: 'include' });
    if (!r.ok) { stamp.textContent = 'auth err'; return; }
    const s = await r.json();
    render(s);
    stamp.textContent = new Date().toLocaleTimeString();
  } catch (e) {
    stamp.textContent = 'fetch err';
  }
}

function render(s) {
  // AEP banner
  if (s.aep_active) {
    aepBanner.innerHTML = '✦ AEP activo (Oct 15 – Dec 7) — ' + s.aep_days_left + ' días restantes';
    aepBanner.classList.add('active');
  } else {
    aepBanner.classList.remove('active');
  }

  grid.innerHTML = '';

  // 1. KPI hero
  const kpis = [
    { lbl: 'Clientes activos', val: s.crm.active, sub: s.crm.total + ' totales' },
    { lbl: 'Leads', val: s.crm.lead, sub: s.crm.prospect + ' prospects' },
    { lbl: 'Tareas activas', val: s.tasks.active },
    { lbl: 'Borradores', val: s.outbound.length, cls: s.outbound.length > 5 ? 'warn' : '' },
    { lbl: 'Compromisos', val: s.commitments.pending },
    { lbl: 'Gaps', val: s.gaps.length, cls: s.gaps_alto > 0 ? 'bad' : (s.gaps.length > 10 ? 'warn' : '') },
    { lbl: 'Auditor', val: s.auditor.length, cls: s.auditor_alto > 0 ? 'bad' : '' },
    { lbl: 'Skills activas', val: s.skills.active, sub: s.skills.draft + ' drafts' },
  ];
  cardWide('Estado general', \`<div class="kpis">\${kpis.map(k =>
    \`<div class="kpi-tile \${k.cls || ''}"><div class="lbl">\${k.lbl}</div><div class="val">\${k.val}</div>\${k.sub ? \`<div class="sub">\${k.sub}</div>\` : ''}</div>\`
  ).join('')}</div>\`, 'kpi');

  // 2. Compliance Medicare (top-priority)
  cardEl('Compliance Medicare',
    \`<div class="row"><span class="label">SOA faltante</span><span class="val">\${s.compliance.soa_missing}</span></div>
     <div class="row"><span class="label">MBI pendiente</span><span class="val">\${s.compliance.mbi_pending}</span></div>
     <div class="row"><span class="label">Sin touchpoint 12m</span><span class="val">\${s.compliance.no_touchpoint_12m}</span></div>
     <div class="row"><span class="label">TCPA sin firmar</span><span class="val">\${s.compliance.tcpa_missing}</span></div>
     <div class="row"><span class="label">T65 pipeline (6m)</span><span class="val">\${s.compliance.t65_pipeline}</span></div>
     <div class="row"><span class="label">Próx renovaciones (60d)</span><span class="val">\${s.compliance.upcoming_renewals}</span></div>\`);

  // 3. Auditor — calidad del CRM
  const auditHtml = s.auditor.length ? s.auditor.slice(0, 12).map(a =>
    \`<div class="item"><span class="pill \${a.severidad}">\${a.severidad}</span>\${escapeHtml(a.target_name || '—')}<div class="meta">\${escapeHtml(a.mensaje)}</div></div>\`
  ).join('') : '<div class="muted">CRM limpio. ✓</div>';
  cardEl(\`Auditor CRM (\${s.auditor.length})\`, auditHtml);

  // 4. Gaps / known unknowns
  const gapsHtml = s.gaps.length ? s.gaps.slice(0, 12).map(g =>
    \`<div class="item"><span class="pill \${g.severidad}">\${g.severidad}</span>\${escapeHtml(g.target_name || '—')}<div class="meta">\${escapeHtml(g.missing_field)}</div></div>\`
  ).join('') : '<div class="muted">Sin huecos. ✓</div>';
  cardEl(\`Known-unknowns (\${s.gaps.length})\`, gapsHtml);

  // 5. Signals
  const sigHtml = s.signals.length ? s.signals.map(x =>
    \`<div class="item"><span class="pill \${x.severidad}">\${x.severidad || 'info'}</span>\${escapeHtml(x.mensaje)}</div>\`
  ).join('') : '<div class="muted">Sin señales activas.</div>';
  cardEl(\`Señales (\${s.signals.length})\`, sigHtml);

  // 6. Borradores en cola
  const outHtml = s.outbound.length ? s.outbound.map(o =>
    \`<div class="item"><strong>\${(o.type || '').toUpperCase()}</strong> → \${escapeHtml(o.para || '—')}<div class="meta">[\${escapeHtml(o.id)}] \${escapeHtml((o.contenido || o.cuerpo || '').slice(0, 100))}…</div></div>\`
  ).join('') : '<div class="muted">Sin borradores pendientes.</div>';
  cardEl(\`Cola de envío (\${s.outbound.length})\`, outHtml);

  // 7. Tasks by owner
  const tasksHtml = \`<div class="owners">
      <div class="owner"><div class="lbl">Athena</div><div class="val">\${s.tasks.by_owner.athena}</div></div>
      <div class="owner"><div class="lbl">Isabel</div><div class="val">\${s.tasks.by_owner.isabel}</div></div>
      <div class="owner"><div class="lbl">Sami</div><div class="val">\${s.tasks.by_owner.sami}</div></div>
    </div>
    \${s.tasks.recent.slice(0, 8).map(t =>
      \`<div class="item"><strong>\${escapeHtml(t.responsable)}</strong> · \${escapeHtml(t.descripcion || '')}\${t.vence ? \`<div class="meta">vence \${(t.vence || '').slice(0,10)}</div>\` : ''}</div>\`
    ).join('') || '<div class="muted">Sin tareas activas.</div>'}\`;
  cardEl(\`Tareas (\${s.tasks.active})\`, tasksHtml);

  // 8. Compromisos pendientes
  const cmHtml = s.commitments.recent.length ? s.commitments.recent.slice(0, 10).map(c =>
    \`<div class="item"><strong>\${escapeHtml(c.persona)}</strong> → \${escapeHtml(c.descripcion || '')}\${c.vence ? \`<div class="meta">vence \${(c.vence || '').slice(0,10)}</div>\` : ''}</div>\`
  ).join('') : '<div class="muted">Sin compromisos pendientes.</div>';
  cardEl(\`Compromisos (\${s.commitments.pending})\`, cmHtml);

  // 9. Skills — drafts pendientes de aprobación + activas
  let skillsHtml = '';
  if (s.skills.draft_list.length) {
    skillsHtml += s.skills.draft_list.map(sk =>
      \`<div class="item"><span class="pill aviso">draft</span><strong>\${escapeHtml(sk.name)}</strong> v\${sk.version}<div class="meta">\${escapeHtml(sk.descripcion || '')}</div></div>\`
    ).join('');
  }
  if (s.skills.active_list.length) {
    skillsHtml += s.skills.active_list.map(sk =>
      \`<div class="item"><span class="pill info">activa</span><strong>\${escapeHtml(sk.name)}</strong> v\${sk.version} · \${sk.invocaciones || 0} usos</div>\`
    ).join('');
  }
  if (!skillsHtml) skillsHtml = '<div class="muted">Sin skills.</div>';
  cardEl(\`Skills (\${s.skills.active} activas / \${s.skills.draft} drafts)\`, skillsHtml);

  // 10. Audit log
  const actHtml = s.activity.length ? \`<div class="activity">\${
    s.activity.map(e =>
      \`<div><span class="ts">\${(e.ts || '').slice(11,16)}</span><span class="tool">\${escapeHtml(e.tool)}</span><span class="summary">\${escapeHtml(e.result_summary || e.input_summary || '')}</span></div>\`
    ).join('')
  }</div>\` : '<div class="muted">Audit log vacío.</div>';
  cardEl(\`Audit log (últimos \${s.activity.length})\`, actHtml, 'wide');

  // 11. Backups
  const bkHtml = s.backups.length ? \`<pre>\${s.backups.slice(0,10).map(b => \`\${b.name}  \${(b.size/1024).toFixed(1)}KB  \${b.mtime}\`).join('\\n')}</pre>\` : '<div class="muted">Sin snapshots todavía.</div>';
  cardEl(\`Backups locales (\${s.backups.length})\`, bkHtml);
}

function cardEl(title, html, extraCls = '') {
  const div = document.createElement('div');
  div.className = 'card ' + extraCls;
  div.innerHTML = \`<h2>\${title}</h2>\${html}\`;
  grid.appendChild(div);
}
function cardWide(title, html, extraCls = '') {
  cardEl(title, html, extraCls + ' kpi');
}

function escapeHtml(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
}

load();
setInterval(load, 5000);
</script>
</body>
</html>`;
}

// ============================================================
//  Estado JSON
// ============================================================
function readJsonSafe(file, fallback) {
  try {
    if (existsSync(file)) return JSON.parse(readFileSync(file, 'utf8'));
  } catch { /* ignore */ }
  return fallback;
}

function aepWindow() {
  const now = new Date();
  const year = now.getFullYear();
  const start = new Date(year, 9, 15); // Oct 15
  const end = new Date(year, 11, 7, 23, 59, 59); // Dec 7
  const active = now >= start && now <= end;
  const daysLeft = active ? Math.ceil((end - now) / 86_400_000) : null;
  return { active, daysLeft };
}

export async function buildDashboardState() {
  const crm = readJsonSafe(join(DATA_DIR, 'crm.json'), []);
  const tasks = readJsonSafe(join(DATA_DIR, 'tasks.json'), []);
  const commitments = readJsonSafe(join(DATA_DIR, 'commitments.json'), []);
  const outbound = readJsonSafe(join(DATA_DIR, 'outbound_queue.json'), []);
  const activity = readJsonSafe(join(DATA_DIR, 'activity.json'), []);
  const signalsBlob = readJsonSafe(join(DATA_DIR, 'signals.json'), { signals: [] });

  // Computed via modules
  let gaps = [];
  let auditor = [];
  let skillsActive = [];
  let skillsDraft = [];
  let crmHelpers = null;
  try {
    const g = await import('./gaps.js');
    gaps = g.computeGaps({ limit: 100 });
  } catch { /* ignore */ }
  try {
    const a = await import('./auditor.js');
    auditor = a.auditCrm({ limit: 50 });
  } catch { /* ignore */ }
  try {
    const sk = await import('./skills.js');
    skillsActive = sk.listSkills({ status: 'active' });
    skillsDraft = sk.listSkills({ status: 'draft' });
  } catch { /* ignore */ }
  try {
    crmHelpers = await import('./crm.js');
  } catch { /* ignore */ }

  // Compliance counters (via crm.js helpers if available)
  const compliance = {
    soa_missing: 0,
    mbi_pending: 0,
    no_touchpoint_12m: 0,
    tcpa_missing: 0,
    t65_pipeline: 0,
    upcoming_renewals: 0,
  };
  try {
    if (crmHelpers) {
      compliance.soa_missing = crmHelpers.clientsWithSoaIssue?.()?.length || 0;
      compliance.mbi_pending = crmHelpers.clientsWithMbiPending?.()?.length || 0;
      compliance.no_touchpoint_12m = crmHelpers.clientsNeedingAnnualTouch?.()?.length || 0;
      compliance.t65_pipeline = crmHelpers.t65Pipeline?.(6)?.length || 0;
      compliance.upcoming_renewals = crmHelpers.upcomingRenewals?.(60)?.length || 0;
      // TCPA missing — count manually since no exported helper
      compliance.tcpa_missing = crm.filter((c) =>
        (c.status === 'active' || c.status === 'prospect') &&
        !(c.tcpa_consent?.status === 'signed')
      ).length;
    }
  } catch { /* swallow — dashboard is best-effort */ }

  // Backups
  let backups = [];
  if (existsSync(BACKUP_DIR)) {
    backups = readdirSync(BACKUP_DIR)
      .filter((f) => f.endsWith('.tar.gz'))
      .map((f) => {
        const st = statSync(join(BACKUP_DIR, f));
        return { name: f, size: st.size, mtime: new Date(st.mtimeMs).toISOString().slice(0, 16) };
      })
      .sort((a, b) => b.mtime.localeCompare(a.mtime));
  }

  const activeTasks = tasks.filter((t) => t.status !== 'lista' && t.status !== 'cancelada');
  const pendingCommits = commitments.filter((c) => c.status === 'pendiente');

  const byOwner = { athena: 0, isabel: 0, sami: 0 };
  for (const t of activeTasks) {
    if (byOwner[t.responsable] !== undefined) byOwner[t.responsable]++;
  }

  const aep = aepWindow();

  return {
    aep_active: aep.active,
    aep_days_left: aep.daysLeft,
    crm: {
      active: crm.filter((c) => c.status === 'active').length,
      lead: crm.filter((c) => c.status === 'lead').length,
      prospect: crm.filter((c) => c.status === 'prospect').length,
      inactive: crm.filter((c) => c.status === 'inactive').length,
      total: crm.length,
    },
    compliance,
    tasks: {
      active: activeTasks.length,
      by_owner: byOwner,
      recent: activeTasks.slice(0, 10),
    },
    commitments: {
      pending: pendingCommits.length,
      recent: pendingCommits.slice(0, 10),
    },
    outbound,
    gaps,
    gaps_alto: gaps.filter((g) => g.severidad === 'alto').length,
    auditor,
    auditor_alto: auditor.filter((a) => a.severidad === 'alto').length,
    signals: signalsBlob.signals || [],
    skills: {
      active: skillsActive.length,
      draft: skillsDraft.length,
      active_list: skillsActive,
      draft_list: skillsDraft,
    },
    activity: activity.slice(0, 50),
    backups,
  };
}
