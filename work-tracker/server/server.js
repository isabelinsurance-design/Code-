#!/usr/bin/env node
// NetConnect Work Tracker: API + live dashboard. No dependencies.
//
//   node server.js            → http://localhost:8787
//
// Data is kept as plain JSON in ./data (one file per day), easy to back up or
// import into the CRM. Configure with environment variables:
//   PORT         default 8787
//   DATA_DIR     default ./data
//   TRACKER_KEY  key the Chrome extensions send (generated on first run if unset)
//   ADMIN_KEY    key for the dashboard, categories and counters (generated if unset)

'use strict';
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const PORT = Number(process.env.PORT) || 8787;
const DATA_DIR = path.resolve(process.env.DATA_DIR || path.join(__dirname, 'data'));
const DASHBOARD = path.join(__dirname, 'public', 'dashboard.html');
const OFFLINE_AFTER_MS = 3 * 60 * 1000;     // no heartbeat for 3 min = offline
const FORGET_EMPLOYEE_MS = 30 * 864e5;      // drop from the board after 30 days of silence
const SEEN_BATCH_MS = 3 * 864e5;            // remember uploaded batch ids this long
const MAX_BODY = 2 * 1024 * 1024;
const STATES = ['active', 'idle', 'away', 'lunch', 'break'];
const MODES = ['work', 'lunch', 'break', 'off'];
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const SLOT_RE = /^([01]\d|2[0-3]):[0-5]\d$/;

// Starting point for the site → category table. Edit it from the dashboard
// ("Sites & categories") or in data/categories.json; the extension never changes.
const DEFAULT_CATEGORIES = {
  categories: [
    { name: 'CRM', work: true },
    { name: 'Carrier portals', work: true },
    { name: 'Medicare', work: true },
    { name: 'Email/Calendar', work: true },
    { name: 'Other approved work', work: true },
    { name: 'Other apps (outside Chrome)', work: true },
    { name: 'Not work', work: false },
  ],
  domains: {
    'uhcjarvis.com': 'Carrier portals',
    'humana.com': 'Carrier portals',
    'alignmenthealthplan.com': 'Carrier portals',
    'medicare.gov': 'Medicare',
    'mail.google.com': 'Email/Calendar',
    'calendar.google.com': 'Email/Calendar',
    'youtube.com': 'Not work',
    '(outside chrome)': 'Other apps (outside Chrome)',
    '(browser)': 'Other approved work',
    '(local file)': 'Other approved work',
  },
};

// ---------- storage: JSON files cached in memory, written ~2s after a change ----------

fs.mkdirSync(path.join(DATA_DIR, 'days'), { recursive: true });
const cache = new Map();
const dirty = new Set();
let saveTimer = null;

function load(file, fallback) {
  try {
    return JSON.parse(fs.readFileSync(path.join(DATA_DIR, file), 'utf8'));
  } catch (err) {
    if (err.code === 'ENOENT') return fallback;
    throw err; // never silently replace a file we couldn't read
  }
}
function writeNow(file, value) {
  const full = path.join(DATA_DIR, file);
  fs.writeFileSync(full + '.tmp', JSON.stringify(value));
  fs.renameSync(full + '.tmp', full);
}
function doc(file, fallback) {
  if (!cache.has(file)) cache.set(file, load(file, fallback()));
  return cache.get(file);
}
function changed(file) {
  dirty.add(file);
  saveTimer ||= setTimeout(saveAll, 2000);
}
function saveAll() {
  clearTimeout(saveTimer);
  saveTimer = null;
  for (const file of dirty) writeNow(file, cache.get(file));
  dirty.clear();
}

const blankEmployee = name => ({ name, clockIn: null, clockOut: null, totals: {}, domains: {}, slots: {}, counters: {} });
const dayDoc = date => doc(`days/${date}.json`, () => ({ date, employees: {} }));
const statusDoc = () => doc('status.json', () => ({}));
const seenDoc = () => doc('seen-batches.json', () => ({}));
function categoriesDoc() {
  if (!fs.existsSync(path.join(DATA_DIR, 'categories.json')) && !cache.has('categories.json')) {
    cache.set('categories.json', structuredClone(DEFAULT_CATEGORIES));
    changed('categories.json');
  }
  return doc('categories.json', () => structuredClone(DEFAULT_CATEGORIES));
}

// ---------- keys ----------

const keys = { tracker: process.env.TRACKER_KEY, admin: process.env.ADMIN_KEY };
let keysFromFile = false;
if (!keys.tracker || !keys.admin) {
  let saved = load('keys.json', null);
  if (!saved) {
    saved = { tracker: crypto.randomBytes(18).toString('base64url'), admin: crypto.randomBytes(18).toString('base64url') };
    writeNow('keys.json', saved);
  }
  keys.tracker ||= saved.tracker;
  keys.admin ||= saved.admin;
  keysFromFile = true;
}

function keyMatches(given, expected) {
  const hash = value => crypto.createHash('sha256').update(String(value || '')).digest();
  return crypto.timingSafeEqual(hash(given), hash(expected));
}
function requireKey(req, url, which) {
  const given = which === 'tracker' ? req.headers['x-tracker-key'] : req.headers['x-admin-key'] || url.searchParams.get('key');
  if (!keyMatches(given, keys[which])) throw httpError(401, 'Wrong or missing key');
}

// ---------- helpers ----------

function httpError(status, message) {
  return Object.assign(new Error(message), { status });
}
const add = (obj, key, value) => {
  obj[key] = Math.round(((obj[key] || 0) + value) * 10) / 10;
};
const cleanName = v => (typeof v === 'string' ? v.trim().replace(/\s+/g, ' ').slice(0, 60) : '');
const cleanDomain = v =>
  typeof v === 'string' && /^[\w.\-:[\]() ]{1,253}$/.test(v) ? v.toLowerCase().replace(/^www\./, '') : '';
const localDate = (t = Date.now()) => {
  const d = new Date(t);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

// Longest matching suffix wins: "www2.humana.com" → "humana.com".
function categoryLookup(cats) {
  const work = Object.fromEntries(cats.categories.map(c => [c.name, c.work !== false]));
  return domain => {
    for (let d = domain || ''; d; ) {
      const name = cats.domains[d];
      if (name && name in work) return { name, work: work[name] };
      const dot = d.indexOf('.');
      if (dot < 0) break;
      d = d.slice(dot + 1);
    }
    return null;
  };
}

// ---------- ingest ----------

function addItem(id, name, item) {
  if (!item || !DATE_RE.test(item.date) || !SLOT_RE.test(item.slot) || !STATES.includes(item.state)) return;
  const seconds = Number(item.seconds);
  if (!(seconds > 0 && seconds <= 301)) return; // one item covers at most one 5-minute slot
  const { state } = item;
  const domain = state === 'active' || state === 'idle' ? cleanDomain(item.domain) : '';

  const day = dayDoc(item.date);
  const emp = (day.employees[id] ||= blankEmployee(name));
  emp.name = name;
  add(emp.totals, state, seconds);
  if (domain) add((emp.domains[domain] ||= {}), state, seconds);
  const slot = (emp.slots[item.slot] ||= { s: {}, d: {} });
  add(slot.s, state, seconds);
  if (state === 'active' && domain) add(slot.d, domain, seconds);
  changed(`days/${item.date}.json`);
}

function updateStatus(id, name, st, skew, version) {
  if (!st || !MODES.includes(st.mode)) return;
  const at = v => (Number.isFinite(v) && v > 1e12 ? v + skew : null);
  const status = {
    name,
    day: DATE_RE.test(st.day) ? st.day : null,
    mode: st.mode,
    modeSince: at(st.modeSince),
    idle: ['active', 'idle', 'locked'].includes(st.idle) ? st.idle : 'active',
    domain: st.mode === 'work' ? cleanDomain(st.domain) || null : null,
    domainSince: at(st.domainSince),
    clockIn: at(st.clockIn),
    clockOut: at(st.clockOut),
    lastActiveAt: at(st.lastActiveAt),
    lastSeen: Date.now(),
    version: String(version || '').slice(0, 20),
  };
  statusDoc()[id] = status;
  changed('status.json');

  if (status.day && (status.clockIn || status.clockOut)) {
    const day = dayDoc(status.day);
    const emp = (day.employees[id] ||= blankEmployee(name));
    const clockIn = status.clockIn && emp.clockIn ? Math.min(status.clockIn, emp.clockIn) : status.clockIn || emp.clockIn;
    if (clockIn !== emp.clockIn || status.clockOut !== emp.clockOut) {
      Object.assign(emp, { clockIn, clockOut: status.clockOut });
      changed(`days/${status.day}.json`);
    }
  }
}

function ingest(body) {
  const name = cleanName(body.employee);
  if (!name) throw httpError(400, 'employee is required');
  const id = name.toLowerCase();
  const sentAt = Number(body.sentAt);
  const skew = Number.isFinite(sentAt) && Math.abs(Date.now() - sentAt) < 864e5 ? Date.now() - sentAt : 0;

  const seen = seenDoc();
  const accepted = [];
  for (const batch of Array.isArray(body.batches) ? body.batches.slice(0, 1000) : []) {
    if (!batch || typeof batch.id !== 'string' || batch.id.length > 64) continue;
    if (!seen[batch.id]) {
      for (const item of Array.isArray(batch.items) ? batch.items.slice(0, 2000) : []) addItem(id, name, item);
      seen[batch.id] = Date.now();
      changed('seen-batches.json');
    }
    accepted.push(batch.id); // already-stored batches are acknowledged, not re-counted
  }
  updateStatus(id, name, body.status, skew, body.version);
  return { ok: true, accepted, serverTime: Date.now() };
}

function prune() {
  const seen = seenDoc();
  for (const [id, at] of Object.entries(seen)) if (Date.now() - at > SEEN_BATCH_MS) delete seen[id];
  const status = statusDoc();
  for (const [id, st] of Object.entries(status)) if (Date.now() - st.lastSeen > FORGET_EMPLOYEE_MS) delete status[id];
  changed('seen-batches.json');
  changed('status.json');
}

// ---------- report for the dashboard ----------

function live(st, lookup, now) {
  if (!st) return { state: 'offline', lastSeen: null };
  const base = { day: st.day, lastSeen: st.lastSeen, lastActiveAt: st.lastActiveAt, version: st.version };
  if (now - st.lastSeen > OFFLINE_AFTER_MS) return { ...base, state: 'offline' };
  if (st.mode === 'off') return { ...base, state: 'off', since: st.clockOut };
  if (st.mode !== 'work') return { ...base, state: st.mode, since: st.modeSince };
  if (st.idle === 'locked') return { ...base, state: 'away', since: st.lastActiveAt };
  if (st.idle === 'idle') return { ...base, state: 'idle', since: st.lastActiveAt };
  const category = lookup(st.domain);
  return {
    ...base,
    state: 'working',
    domain: st.domain,
    category: category && category.name,
    work: category ? category.work : null,
    since: st.domainSince,
  };
}

function report(date) {
  const now = Date.now();
  const cats = categoriesDoc();
  const lookup = categoryLookup(cats);
  const day = cache.get(`days/${date}.json`) || load(`days/${date}.json`, { date, employees: {} });
  const status = statusDoc();
  const unassigned = {};

  const ids = new Set([...Object.keys(day.employees), ...Object.keys(status)]);
  const employees = [...ids].map(id => {
    const emp = day.employees[id] || blankEmployee(status[id].name);
    const byCategory = {};
    let work = 0;
    let nonWork = 0;
    let unreviewed = 0;

    const domains = Object.entries(emp.domains).map(([domain, t]) => {
      const category = lookup(domain);
      const active = t.active || 0;
      if (!category) {
        unreviewed += active;
        add(unassigned, domain, active + (t.idle || 0));
      } else {
        add(byCategory, category.name, active);
        if (category.work) work += active;
        else nonWork += active;
      }
      return { domain, category: category && category.name, work: category ? category.work : null, active, idle: t.idle || 0 };
    });

    const timeline = Object.entries(emp.slots)
      .map(([slot, { s, d }]) => {
        const parts = { work: 0, nonWork: 0, unreviewed: 0, idle: s.idle || 0, away: s.away || 0, lunch: s.lunch || 0, break: s.break || 0 };
        for (const [domain, secs] of Object.entries(d)) {
          const category = lookup(domain);
          parts[!category ? 'unreviewed' : category.work ? 'work' : 'nonWork'] += secs;
        }
        // Active seconds without a domain (shouldn't happen) still count as work time.
        parts.work += Math.max(0, (s.active || 0) - Object.values(d).reduce((a, b) => a + b, 0));
        const kind = Object.keys(parts).reduce((a, b) => (parts[b] > parts[a] ? b : a));
        return { slot, kind, parts };
      })
      .sort((a, b) => a.slot.localeCompare(b.slot));

    const categories = cats.categories
      .filter(c => byCategory[c.name] > 0)
      .map(c => ({ name: c.name, work: c.work !== false, seconds: byCategory[c.name] }));
    if (unreviewed > 0) categories.push({ name: 'Not categorized yet', work: null, seconds: unreviewed });
    categories.sort((a, b) => b.seconds - a.seconds);

    return {
      id,
      name: emp.name,
      live: live(status[id], lookup, now),
      clockIn: emp.clockIn,
      clockOut: emp.clockOut,
      totals: Object.fromEntries(STATES.map(state => [state, emp.totals[state] || 0])),
      work,
      nonWork,
      unreviewed,
      categories,
      domains: domains.sort((a, b) => b.active + b.idle - (a.active + a.idle)).slice(0, 40),
      timeline,
      counters: emp.counters || {},
    };
  });

  employees.sort((a, b) => a.name.localeCompare(b.name));
  return {
    date,
    serverTime: now,
    offlineAfterMs: OFFLINE_AFTER_MS,
    categories: cats.categories,
    employees,
    unassigned: Object.entries(unassigned)
      .map(([domain, seconds]) => ({ domain, seconds }))
      .sort((a, b) => b.seconds - a.seconds)
      .slice(0, 50),
  };
}

// ---------- admin writes ----------

function saveCategories(body) {
  const list = Array.isArray(body.categories) ? body.categories : [];
  const categories = [];
  for (const c of list.slice(0, 50)) {
    const name = cleanName(c && c.name).slice(0, 40);
    if (name && !categories.some(x => x.name === name)) categories.push({ name, work: c.work !== false });
  }
  if (!categories.length) throw httpError(400, 'At least one category is required');
  const domains = {};
  for (const [domain, name] of Object.entries(body.domains || {}).slice(0, 5000)) {
    const clean = cleanDomain(domain.trim());
    if (clean && categories.some(c => c.name === name)) domains[clean] = name;
  }
  cache.set('categories.json', { categories, domains });
  changed('categories.json');
  return cache.get('categories.json');
}

function saveCounters(body) {
  const name = cleanName(body.employee);
  if (!name) throw httpError(400, 'employee is required');
  const date = DATE_RE.test(body.date) ? body.date : localDate();
  const day = dayDoc(date);
  const emp = (day.employees[name.toLowerCase()] ||= blankEmployee(name));
  emp.counters ||= {};
  for (const [key, value] of Object.entries(body.counters || {}).slice(0, 10)) {
    const label = cleanName(key).slice(0, 30);
    if (label && Number.isFinite(Number(value))) emp.counters[label] = Number(value);
  }
  changed(`days/${date}.json`);
  return { ok: true, date, counters: emp.counters };
}

// ---------- http ----------

async function readBody(req) {
  const chunks = [];
  let size = 0;
  for await (const chunk of req) {
    size += chunk.length;
    if (size > MAX_BODY) throw httpError(413, 'Request too large');
    chunks.push(chunk);
  }
  try {
    return JSON.parse(Buffer.concat(chunks).toString('utf8') || '{}');
  } catch {
    throw httpError(400, 'Invalid JSON');
  }
}

function send(res, status, body) {
  if (body === undefined) return res.writeHead(status).end();
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
  res.end(JSON.stringify(body));
}

const server = http.createServer(async (req, res) => {
  // The extension calls from a chrome-extension:// origin; keys, not origins, protect the API.
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Tracker-Key, X-Admin-Key');
  if (req.method === 'OPTIONS') return send(res, 204);

  const url = new URL(req.url, 'http://localhost');
  try {
    switch (`${req.method} ${url.pathname}`) {
      case 'GET /':
      case 'GET /dashboard':
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        return fs.createReadStream(DASHBOARD).pipe(res);
      case 'GET /healthz':
        return send(res, 200, { ok: true });
      case 'GET /api/ping':
        requireKey(req, url, 'tracker');
        return send(res, 200, { ok: true, serverTime: Date.now() });
      case 'POST /api/activity':
        requireKey(req, url, 'tracker');
        return send(res, 200, ingest(await readBody(req)));
      case 'GET /api/day': {
        requireKey(req, url, 'admin');
        const date = url.searchParams.get('date') || localDate();
        if (!DATE_RE.test(date)) throw httpError(400, 'date must be YYYY-MM-DD');
        return send(res, 200, report(date));
      }
      case 'GET /api/categories':
        requireKey(req, url, 'admin');
        return send(res, 200, categoriesDoc());
      case 'PUT /api/categories':
        requireKey(req, url, 'admin');
        return send(res, 200, saveCategories(await readBody(req)));
      case 'POST /api/counters':
        requireKey(req, url, 'admin');
        return send(res, 200, saveCounters(await readBody(req)));
      default:
        return send(res, 404, { error: 'Not found' });
    }
  } catch (err) {
    if (!err.status) console.error(err);
    send(res, err.status || 500, { error: err.status ? err.message : 'Server error' });
  }
});

prune();
setInterval(prune, 3600 * 1000).unref();
for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => {
    saveAll();
    process.exit(0);
  });
}

server.listen(PORT, () => {
  console.log(`Work Tracker running on http://localhost:${PORT}`);
  console.log(`Data folder: ${DATA_DIR}`);
  if (keysFromFile) {
    console.log(`Tracker key (paste into each extension's Settings): ${keys.tracker}`);
    console.log(`Dashboard: http://localhost:${PORT}/?key=${keys.admin}`);
  }
});
