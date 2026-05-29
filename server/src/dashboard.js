// ============================================================
//  Dashboard — observabilidad en vivo
//  ──────────────────────────────────
//  Una página HTML simple que Isabel o Sami pueden abrir para ver
//  qué está haciendo Athena en tiempo real. NO React, NO bundler,
//  HTML+JS plano que jala /dashboard/state cada 5s.
//
//  Acceso: Basic Auth con DASHBOARD_PASSWORD (env). Usuario libre
//  (cualquiera funciona). El password se mete una vez en el browser.
//  Si DASHBOARD_PASSWORD no está, el dashboard se desactiva.
//
//  Inspirado por el patrón de IndyDevDan y la versión "boris"
//  de Cherny — todo tool call queda visible en una tabla.
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

// Middleware Basic Auth. Pide credenciales si no vienen, valida si sí.
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

// ---- Página HTML ----
export function renderDashboardHtml() {
  return `<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Athena · Dashboard</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root {
    --bg: #0f1115; --fg: #e6e8eb; --muted: #8a93a6;
    --card: #161922; --border: #232838; --accent: #7aa2ff;
    --alto: #ff6b6b; --aviso: #ffc658; --info: #7adfa6;
  }
  * { box-sizing: border-box }
  body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
         background: var(--bg); color: var(--fg); padding: 12px; }
  h1 { margin: 0 0 4px; font-size: 18px; }
  .sub { color: var(--muted); font-size: 12px; margin-bottom: 16px; }
  .grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(330px, 1fr)); }
  .card { background: var(--card); border: 1px solid var(--border);
          border-radius: 10px; padding: 12px; }
  .card h2 { margin: 0 0 8px; font-size: 13px; color: var(--muted);
             text-transform: uppercase; letter-spacing: .04em; }
  .row { display: flex; justify-content: space-between; padding: 4px 0;
         border-top: 1px dashed var(--border); font-size: 13px; }
  .row:first-of-type { border-top: 0; }
  .pill { display: inline-block; padding: 1px 6px; border-radius: 4px;
          font-size: 11px; font-weight: 600; margin-right: 4px; }
  .pill.alto { background: rgba(255,107,107,.15); color: var(--alto); }
  .pill.aviso { background: rgba(255,198,88,.15); color: var(--aviso); }
  .pill.info { background: rgba(122,223,166,.15); color: var(--info); }
  .num { font-size: 22px; font-weight: 700; }
  .muted { color: var(--muted); }
  pre { background: #0a0c10; padding: 8px; border-radius: 6px;
        overflow-x: auto; font-size: 11px; max-height: 380px; margin: 0; }
  .activity { font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
              font-size: 11px; line-height: 1.5; max-height: 420px; overflow-y: auto; }
  .activity > div { padding: 2px 0; border-top: 1px dotted var(--border); }
  .activity > div:first-child { border-top: 0; }
  .ts { color: var(--muted); margin-right: 6px; }
  .tool { color: var(--accent); }
  .stamp { float: right; color: var(--muted); font-size: 11px; }
  a { color: var(--accent); text-decoration: none; }
</style>
</head>
<body>
<h1>👑 Athena · Dashboard <span class="stamp" id="stamp"></span></h1>
<div class="sub">Live state — autorefresca cada 5s. Branch: <code>${process.env.GIT_BRANCH || 'claude/sleepy-darwin-P4k2z'}</code></div>
<div class="grid" id="grid"></div>

<script>
const grid = document.getElementById('grid');
const stamp = document.getElementById('stamp');

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
  grid.innerHTML = '';
  // 1. KPIs
  card('KPIs',
    \`<div class="row"><span>Clientes activos</span><span class="num">\${s.crm.active}</span></div>
    <div class="row"><span>Leads / prospects</span><span class="num">\${s.crm.lead}/\${s.crm.prospect}</span></div>
    <div class="row"><span>Tareas activas</span><span class="num">\${s.tasks.active}</span></div>
    <div class="row"><span>Compromisos pendientes</span><span class="num">\${s.commitments.pending}</span></div>
    <div class="row"><span>Borradores en cola</span><span class="num">\${s.outbound.length}</span></div>\`);

  // 2. Gaps
  const gapsHtml = s.gaps.length ? s.gaps.slice(0, 15).map(g =>
    \`<div class="row"><span><span class="pill \${g.severidad}">\${g.severidad}</span> \${escapeHtml(g.target_name)} · \${g.missing_field}</span></div>\`
  ).join('') : '<div class="muted">Sin huecos. ✓</div>';
  card(\`Known-unknowns (\${s.gaps.length})\`, gapsHtml);

  // 3. Signals
  const sigHtml = s.signals.length ? s.signals.map(x =>
    \`<div class="row"><span class="pill \${x.severidad}">\${x.severidad}</span> \${escapeHtml(x.mensaje)}</div>\`
  ).join('') : '<div class="muted">Sin señales.</div>';
  card(\`Señales (\${s.signals.length})\`, sigHtml);

  // 4. Outbound
  const outHtml = s.outbound.length ? s.outbound.map(o =>
    \`<div class="row"><span>[\${o.id}] \${o.type.toUpperCase()} → \${escapeHtml(o.para || '')}</span></div>\`
  ).join('') : '<div class="muted">Sin borradores.</div>';
  card('Cola de envío', outHtml);

  // 5. Tasks
  const tasksHtml = s.tasks.recent.length ? s.tasks.recent.slice(0, 10).map(t =>
    \`<div class="row"><span>\${escapeHtml(t.responsable)} · \${escapeHtml(t.descripcion || '')}</span></div>\`
  ).join('') : '<div class="muted">Sin tareas activas.</div>';
  card(\`Tareas activas (\${s.tasks.active})\`, tasksHtml);

  // 6. Commitments
  const cmHtml = s.commitments.recent.length ? s.commitments.recent.slice(0, 10).map(c =>
    \`<div class="row"><span>\${escapeHtml(c.persona)} → \${escapeHtml(c.descripcion || '')}</span></div>\`
  ).join('') : '<div class="muted">Sin compromisos.</div>';
  card(\`Compromisos (\${s.commitments.pending})\`, cmHtml);

  // 7. Skills
  const skillsHtml = s.skills.length ? s.skills.map(sk =>
    \`<div class="row"><span>[\${sk.name}] v\${sk.version} · \${sk.invocaciones || 0} usos</span></div>\`
  ).join('') : '<div class="muted">Sin skills activas.</div>';
  card(\`Skills (\${s.skills.length})\`, skillsHtml);

  // 8. Activity log (último N)
  const actHtml = s.activity.length ? \`<div class="activity">\${
    s.activity.map(e =>
      \`<div><span class="ts">\${(e.ts || '').slice(11,16)}</span><span class="tool">\${escapeHtml(e.tool)}</span> · \${escapeHtml(e.result_summary || e.input_summary || '')}</div>\`
    ).join('')
  }</div>\` : '<div class="muted">Audit log vacío.</div>';
  card(\`Audit log (últimos \${s.activity.length})\`, actHtml);

  // 9. Backups
  const bkHtml = s.backups.length ? \`<pre>\${s.backups.slice(0,15).map(b => \`\${b.name}  \${(b.size/1024).toFixed(1)}KB  \${b.mtime}\`).join('\\n')}</pre>\` : '<div class="muted">Sin snapshots todavía.</div>';
  card(\`Backups locales (\${s.backups.length})\`, bkHtml);
}

function card(title, html) {
  const div = document.createElement('div');
  div.className = 'card';
  div.innerHTML = \`<h2>\${title}</h2>\${html}\`;
  grid.appendChild(div);
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
}

load();
setInterval(load, 5000);
</script>
</body>
</html>`;
}

// ---- Estado JSON que la página jala cada 5s ----
function readJsonSafe(file, fallback) {
  try {
    if (existsSync(file)) return JSON.parse(readFileSync(file, 'utf8'));
  } catch { /* ignore */ }
  return fallback;
}

export async function buildDashboardState() {
  const crm = readJsonSafe(join(DATA_DIR, 'crm.json'), []);
  const tasks = readJsonSafe(join(DATA_DIR, 'tasks.json'), []);
  const commitments = readJsonSafe(join(DATA_DIR, 'commitments.json'), []);
  const outbound = readJsonSafe(join(DATA_DIR, 'outbound_queue.json'), []);
  const activity = readJsonSafe(join(DATA_DIR, 'activity.json'), []);
  const signalsBlob = readJsonSafe(join(DATA_DIR, 'signals.json'), { signals: [] });

  // Gaps + skills via import (computan en vivo)
  let gaps = [];
  let skills = [];
  try {
    const g = await import('./gaps.js');
    gaps = g.computeGaps({ limit: 100 });
  } catch { /* ignore */ }
  try {
    const sk = await import('./skills.js');
    skills = sk.listSkills({ status: 'active' });
  } catch { /* ignore */ }

  // Backups locales
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

  return {
    crm: {
      active: crm.filter((c) => c.status === 'active').length,
      lead: crm.filter((c) => c.status === 'lead').length,
      prospect: crm.filter((c) => c.status === 'prospect').length,
      inactive: crm.filter((c) => c.status === 'inactive').length,
      total: crm.length,
    },
    tasks: {
      active: activeTasks.length,
      recent: activeTasks.slice(0, 10),
    },
    commitments: {
      pending: pendingCommits.length,
      recent: pendingCommits.slice(0, 10),
    },
    outbound,
    gaps,
    signals: signalsBlob.signals || [],
    skills,
    activity: activity.slice(0, 50),
    backups,
  };
}
