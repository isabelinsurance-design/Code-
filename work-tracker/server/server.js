#!/usr/bin/env node
// NetConnect Work Tracker: API + live dashboard. No dependencies.
//
//   node server.js            → http://localhost:8787
//
// Data is kept as plain JSON in ./data (one file per day), easy to back up or
// import into the CRM. Configure with environment variables:
//   PORT                default 8787
//   DATA_DIR            default ./data
//   TZ                  the office time zone, e.g. America/New_York (calls are filed by local day)
//   AFTER_CALL_MINUTES  wrap-up window after each call, default 5
//   TRACKER_KEY         key the Chrome extensions send (generated on first run if unset)
//   INTEGRATION_KEY     key for the phone system / CRM: calls, CRM actions, counters (generated if unset)
//   ADMIN_KEY           key for the dashboard and categories (generated if unset)

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
const SEEN_ID_MS = 3 * 864e5;               // remember uploaded batch / event ids this long
const STALE_CALL_MS = 4 * 3600 * 1000;      // a call never reported as ended stops counting after 4 h
const AFTER_CALL_MS = minutesFromEnv('AFTER_CALL_MINUTES', 5) * 60000;
const SLOT_MS = 5 * 60 * 1000;
const SLOT_SECONDS = SLOT_MS / 1000;
const MAX_BODY = 2 * 1024 * 1024;
const STATES = ['active', 'idle', 'away', 'lunch', 'break', 'meeting'];
const MODES = ['work', 'lunch', 'break', 'meeting', 'off'];
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const SLOT_RE = /^([01]\d|2[0-3]):[0-5]\d$/;
const CRM_CATEGORY = 'CRM'; // sites in this category give "CRM open" and "CRM work" time

// Phone-system wording → the four outcomes the dashboard counts.
const OUTCOMES = {
  connected: 'connected', completed: 'connected', answered: 'connected',
  'no-answer': 'no-answer', no_answer: 'no-answer', noanswer: 'no-answer', missed: 'no-answer', canceled: 'no-answer', cancelled: 'no-answer',
  voicemail: 'voicemail', machine: 'voicemail',
  busy: 'busy', failed: 'failed',
};

// Starting point for the site → category table. Edit it from the dashboard
// ("Sites & categories") or in data/categories.json; the extension never changes.
const DEFAULT_CATEGORIES = {
  categories: [
    { name: CRM_CATEGORY, work: true },
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

function minutesFromEnv(name, fallback) {
  const value = Number(process.env[name]);
  return process.env[name] !== undefined && Number.isFinite(value) && value >= 0 ? value : fallback;
}

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

const blankEmployee = name => ({ name, clockIn: null, clockOut: null, totals: {}, domains: {}, slots: {}, counters: {}, calls: [], crmActions: 0 });
const dayDoc = date => doc(`days/${date}.json`, () => ({ date, employees: {} }));
function employeeOn(date, id, name) {
  const emp = (dayDoc(date).employees[id] ||= blankEmployee(name));
  emp.calls ||= []; // day files written before calls were tracked
  emp.counters ||= {};
  return emp;
}
const statusDoc = () => doc('status.json', () => ({}));   // latest extension heartbeat per employee
const liveDoc = () => doc('live.json', () => ({}));       // current call + last CRM action per employee
const seenDoc = () => doc('seen-batches.json', () => ({}));
function categoriesDoc() {
  if (!fs.existsSync(path.join(DATA_DIR, 'categories.json')) && !cache.has('categories.json')) {
    cache.set('categories.json', structuredClone(DEFAULT_CATEGORIES));
    changed('categories.json');
  }
  return doc('categories.json', () => structuredClone(DEFAULT_CATEGORIES));
}

// ---------- keys ----------

const KEY_ENV = { tracker: 'TRACKER_KEY', integration: 'INTEGRATION_KEY', admin: 'ADMIN_KEY' };
const KEY_HEADER = { tracker: 'x-tracker-key', integration: 'x-integration-key', admin: 'x-admin-key' };
const keys = {};
const keysFromFile = [];
{
  const saved = load('keys.json', {});
  let generated = false;
  for (const [which, env] of Object.entries(KEY_ENV)) {
    if (process.env[env]) {
      keys[which] = process.env[env];
      continue;
    }
    if (!saved[which]) {
      saved[which] = crypto.randomBytes(18).toString('base64url');
      generated = true;
    }
    keys[which] = saved[which];
    keysFromFile.push(which);
  }
  if (generated) writeNow('keys.json', saved);
}

function keyMatches(given, expected) {
  const hash = value => crypto.createHash('sha256').update(String(value || '')).digest();
  return crypto.timingSafeEqual(hash(given), hash(expected));
}
// Extensions send their key as a header. Webhooks and the dashboard may use ?key= instead.
function requireKey(req, url, ...allowed) {
  for (const which of allowed) {
    const given = req.headers[KEY_HEADER[which]] || (which === 'tracker' ? null : url.searchParams.get('key'));
    if (given && keyMatches(given, keys[which])) return;
  }
  throw httpError(401, 'Wrong or missing key');
}

// ---------- helpers ----------

function httpError(status, message) {
  return Object.assign(new Error(message), { status });
}
const add = (obj, key, value) => {
  obj[key] = Math.round(((obj[key] || 0) + value) * 10) / 10;
};
const sum = obj => Object.values(obj).reduce((a, b) => a + b, 0);
const pad = n => String(n).padStart(2, '0');
const cleanName = v => (typeof v === 'string' ? v.trim().replace(/\s+/g, ' ').slice(0, 60) : '');
const cleanLabel = v => (typeof v === 'string' || typeof v === 'number' ? String(v).trim().slice(0, 100) || null : null);
const cleanDomain = v =>
  typeof v === 'string' && /^[\w.\-:[\]() ]{1,253}$/.test(v) ? v.toLowerCase().replace(/^www\./, '') : '';
const cleanDirection = v => {
  const s = String(v || '').toLowerCase();
  return s.startsWith('out') ? 'outbound' : s.startsWith('in') ? 'inbound' : null;
};
const localDate = (t = Date.now()) => {
  const d = new Date(t);
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};
const localTime = t => {
  const d = new Date(t);
  return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
};
function dayBounds(date) {
  const start = new Date(`${date}T00:00:00`);
  const end = new Date(start);
  end.setDate(end.getDate() + 1);
  return [start.getTime(), end.getTime()];
}
// Accepts epoch ms, epoch seconds, or an ISO date string.
function parseTime(v) {
  let t = typeof v === 'number' ? v : typeof v === 'string' ? (/^\d+$/.test(v) ? Number(v) : Date.parse(v)) : NaN;
  if (t > 1e9 && t < 1e11) t *= 1000;
  return Number.isFinite(t) && t > 1e12 && t < Date.now() + 864e5 ? t : null;
}
function rememberId(id) {
  const seen = seenDoc();
  if (seen[id]) return false;
  seen[id] = Date.now();
  changed('seen-batches.json');
  return true;
}

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

// ---------- ingest: Chrome extension ----------

function addItem(id, name, item) {
  if (!item || !DATE_RE.test(item.date) || !SLOT_RE.test(item.slot) || !STATES.includes(item.state)) return;
  const seconds = Number(item.seconds);
  if (!(seconds > 0 && seconds <= SLOT_SECONDS + 1)) return; // one item covers at most one 5-minute slot
  const { state } = item;
  const domain = state === 'active' || state === 'idle' ? cleanDomain(item.domain) : '';

  const emp = employeeOn(item.date, id, name);
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
    const emp = employeeOn(status.day, id, name);
    const clockIn = status.clockIn && emp.clockIn ? Math.min(status.clockIn, emp.clockIn) : status.clockIn || emp.clockIn;
    if (clockIn !== emp.clockIn || status.clockOut !== emp.clockOut) {
      Object.assign(emp, { clockIn, clockOut: status.clockOut });
      changed(`days/${status.day}.json`);
    }
  }
}

function ingestActivity(body) {
  const name = cleanName(body.employee);
  if (!name) throw httpError(400, 'employee is required');
  const id = name.toLowerCase();
  const sentAt = Number(body.sentAt);
  const skew = Number.isFinite(sentAt) && Math.abs(Date.now() - sentAt) < 864e5 ? Date.now() - sentAt : 0;

  const accepted = [];
  for (const batch of Array.isArray(body.batches) ? body.batches.slice(0, 1000) : []) {
    if (!batch || typeof batch.id !== 'string' || batch.id.length > 64) continue;
    if (rememberId(batch.id)) {
      for (const item of Array.isArray(batch.items) ? batch.items.slice(0, 2000) : []) addItem(id, name, item);
    }
    accepted.push(batch.id); // already-stored batches are acknowledged, not re-counted
  }
  updateStatus(id, name, body.status, skew, body.version);
  return { ok: true, accepted, serverTime: Date.now() };
}

// ---------- ingest: phone system and CRM ----------

// A call is posted once when it starts (no endedAt) and again when it ends, or
// only once when it ends. Re-posting the same callId updates it, never duplicates it.
function ingestCall(c) {
  const name = cleanName(c && c.employee);
  const callId = cleanLabel(c && c.callId);
  const start = parseTime(c && c.startedAt);
  if (!name || !callId || !start) throw httpError(400, 'employee, callId and startedAt are required');
  let end = c.endedAt != null ? parseTime(c.endedAt) : null;
  if (c.endedAt != null && !end) throw httpError(400, 'endedAt is not a valid time');
  if (!end && c.durationSeconds != null && Number(c.durationSeconds) >= 0) end = start + Number(c.durationSeconds) * 1000;
  if (end && end < start) throw httpError(400, 'endedAt is before startedAt');

  const id = name.toLowerCase();
  const date = localDate(start);
  const live = (liveDoc()[id] ||= {});
  live.name = name;
  const day = dayDoc(date);
  const known = ((day.employees[id] && day.employees[id].calls) || []).find(x => x.id === callId);

  if (!end) {
    if (!known) live.call = { id: callId, startedAt: start, direction: cleanDirection(c.direction), contact: cleanLabel(c.contact) };
  } else {
    if (live.call && live.call.id === callId) live.call = null;
    live.lastCallEnd = Math.max(live.lastCallEnd || 0, end);
    const emp = employeeOn(date, id, name);
    // Member/prospect names stay out of history; only the CRM record id is kept.
    const record = {
      id: callId,
      start,
      end,
      outcome: OUTCOMES[String(c.outcome || '').toLowerCase()] || null,
      direction: cleanDirection(c.direction),
      recordId: cleanLabel(c.recordId),
    };
    if (known) Object.assign(known, record);
    else if (emp.calls.length < 3000) emp.calls.push(record);
    changed(`days/${date}.json`);
  }
  changed('live.json');
  return { callId, live: !end };
}

function ingestCrmEvent(e) {
  const name = cleanName(e && e.employee);
  if (!name) throw httpError(400, 'employee is required');
  if (e.eventId != null && !rememberId(`crm:${cleanLabel(e.eventId)}`)) return { duplicate: true };
  const at = Math.min(parseTime(e.at) || Date.now(), Date.now());
  const id = name.toLowerCase();

  const live = (liveDoc()[id] ||= {});
  live.name = name;
  if (!live.crm || at >= live.crm.at) {
    live.crm = { action: cleanLabel(e.action) || 'action', at, record: cleanLabel(e.record), recordId: cleanLabel(e.recordId) };
  }
  const date = localDate(at);
  const emp = employeeOn(date, id, name);
  emp.crmActions = (emp.crmActions || 0) + 1;
  const counter = cleanName(e.count).slice(0, 30);
  if (counter) emp.counters[counter] = (emp.counters[counter] || 0) + 1;
  changed(`days/${date}.json`);
  changed('live.json');
  return { ok: true };
}

function saveCounters(body) {
  const name = cleanName(body.employee);
  if (!name) throw httpError(400, 'employee is required');
  const date = DATE_RE.test(body.date) ? body.date : localDate();
  const emp = employeeOn(date, name.toLowerCase(), name);
  for (const [key, value] of Object.entries(body.counters || {}).slice(0, 10)) {
    const label = cleanName(key).slice(0, 30);
    if (label && Number.isFinite(Number(value))) emp.counters[label] = Number(value);
  }
  changed(`days/${date}.json`);
  return { ok: true, date, counters: emp.counters };
}

const many = (body, key, fn) => {
  const list = Array.isArray(body[key]) ? body[key] : [body];
  return { ok: true, results: list.slice(0, 500).map(fn) };
};

function prune() {
  const now = Date.now();
  const seen = seenDoc();
  for (const [id, at] of Object.entries(seen)) if (now - at > SEEN_ID_MS) delete seen[id];
  const status = statusDoc();
  for (const [id, st] of Object.entries(status)) if (now - st.lastSeen > FORGET_EMPLOYEE_MS) delete status[id];
  const live = liveDoc();
  for (const [id, info] of Object.entries(live)) {
    if (info.call && now - info.call.startedAt > STALE_CALL_MS) info.call = null;
    const last = Math.max(info.lastCallEnd || 0, (info.crm && info.crm.at) || 0);
    if (!info.call && now - last > FORGET_EMPLOYEE_MS) delete live[id];
  }
  for (const file of ['seen-batches.json', 'status.json', 'live.json']) changed(file);
}

// ---------- merging browser time with calls ----------

function mergeIntervals(list) {
  const out = [];
  for (const [a, b] of [...list].sort((x, y) => x[0] - y[0])) {
    const last = out[out.length - 1];
    if (last && a <= last[1]) last[1] = Math.max(last[1], b);
    else out.push([a, b]);
  }
  return out;
}

// Seconds of each interval falling in each 5-minute slot ("10:15" → 120).
function perSlot(intervals) {
  const out = {};
  for (const [from, to] of intervals) {
    for (let t = from; t < to; ) {
      const slot = Math.floor(t / SLOT_MS) * SLOT_MS;
      const end = Math.min(to, slot + SLOT_MS);
      add(out, localTime(slot), (end - t) / 1000);
      t = end;
    }
  }
  return out;
}

// Calls (merged so a transfer or two lines at once count once) and the
// after-call wrap-up window that follows each one.
function phoneTime(emp, info, date, now) {
  const [dayStart, dayEnd] = dayBounds(date);
  const raw = emp.calls.map(c => [c.start, c.end]);
  const call = info && info.call;
  const liveCall = call && now - call.startedAt < STALE_CALL_MS && call.startedAt < dayEnd && now > dayStart ? call : null;
  if (liveCall) raw.push([liveCall.startedAt, now]);
  const calls = mergeIntervals(raw.map(([a, b]) => [Math.max(a, dayStart), Math.min(b, dayEnd)]).filter(([a, b]) => b > a));
  const wrap = calls
    .map(([, end], i) => [end, Math.min(end + AFTER_CALL_MS, calls[i + 1] ? calls[i + 1][0] : Infinity, now, dayEnd)])
    .filter(([a, b]) => b > a);
  return { calls: perSlot(calls), wrap: perSlot(wrap), liveCall };
}

const ABSORB_ORDER = ['idle', 'away', 'active', 'meeting', 'break', 'lunch'];

// One 5-minute slot. The phone system is the source of truth while a call is
// live: call seconds replace what the browser saw in the same minutes (idle
// first), so a 13-minute call with the CRM open is 13 minutes of work, not 26,
// and nobody is idle for talking instead of clicking.
function mergeSlot(ext, callSec, wrapSec, lookup) {
  const s = { ...ext.s };
  const d = { ...ext.d };
  let overflow = callSec + sum(s) - SLOT_SECONDS;
  for (const state of ABSORB_ORDER) {
    if (overflow <= 0) break;
    const take = Math.min(s[state] || 0, overflow);
    if (!take) continue;
    if (state === 'active') for (const dom in d) d[dom] *= (s.active - take) / s.active;
    s[state] -= take;
    overflow -= take;
  }
  // CRM activity right after a call is wrap-up: notes, scheduling, status changes.
  let wrap = 0;
  for (const dom of Object.keys(d).filter(dom => (lookup(dom) || {}).name === CRM_CATEGORY)) {
    if (wrap >= wrapSec) break;
    const take = Math.min(d[dom], wrapSec - wrap);
    d[dom] -= take;
    s.active = (s.active || 0) - take;
    wrap += take;
  }
  return { s, d, call: callSec, wrap };
}

// ---------- report for the dashboard ----------

function live(st, info, lookup, now) {
  const call = info && info.call && now - info.call.startedAt < STALE_CALL_MS ? info.call : null;
  const crm = info && info.crm ? { action: info.crm.action, at: info.crm.at, record: info.crm.record } : null;
  const base = { day: st && st.day, lastSeen: st ? st.lastSeen : null, lastActiveAt: st && st.lastActiveAt, version: st && st.version, crm };
  if (call) return { ...base, state: 'call', since: call.startedAt, contact: call.contact, direction: call.direction };
  if (!st || now - st.lastSeen > OFFLINE_AFTER_MS) return { ...base, state: 'offline' };
  if (st.mode === 'off') return { ...base, state: 'off', since: st.clockOut };
  if (st.mode !== 'work') return { ...base, state: st.mode, since: st.modeSince };
  const inCrm = (lookup(st.domain) || {}).name === CRM_CATEGORY;
  if (info && info.lastCallEnd && now - info.lastCallEnd < AFTER_CALL_MS && st.idle === 'active' && inCrm) {
    return { ...base, state: 'acw', since: info.lastCallEnd };
  }
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

function employeeReport(id, emp, st, info, cats, lookup, date, now) {
  const phone = phoneTime(emp, info, date, now);
  const acc = { call: 0, wrap: 0, work: 0, nonWork: 0, unreviewed: 0, meeting: 0, idle: 0, away: 0, lunch: 0, break: 0 };
  const byCategory = {};
  const slots = [...new Set([...Object.keys(emp.slots), ...Object.keys(phone.calls), ...Object.keys(phone.wrap)])].sort();

  const timeline = slots.flatMap(slot => {
    const m = mergeSlot(emp.slots[slot] || { s: {}, d: {} }, phone.calls[slot] || 0, phone.wrap[slot] || 0, lookup);
    const parts = {
      call: m.call, wrap: m.wrap, work: m.s.meeting || 0, nonWork: 0, unreviewed: 0,
      idle: m.s.idle || 0, away: m.s.away || 0, lunch: (m.s.lunch || 0) + (m.s.break || 0),
    };
    for (const [dom, secs] of Object.entries(m.d)) {
      const category = lookup(dom);
      if (category) add(byCategory, category.name, secs);
      parts[!category ? 'unreviewed' : category.work ? 'work' : 'nonWork'] += secs;
    }
    parts.work += Math.max(0, (m.s.active || 0) - sum(m.d)); // active time without a site still counts
    for (const k of ['work', 'nonWork', 'unreviewed', 'idle', 'away']) acc[k] += parts[k];
    for (const k of ['meeting', 'lunch', 'break']) acc[k] += m.s[k] || 0;
    acc.work -= m.s.meeting || 0;
    acc.call += m.call;
    acc.wrap += m.wrap;
    const groups = { ...parts, call: m.call + m.wrap };
    delete groups.wrap;
    if (sum(groups) < 1) return []; // e.g. a wrap-up window with nothing happening in it
    return [{ slot, kind: Object.keys(groups).reduce((a, b) => (groups[b] > groups[a] ? b : a)), parts }];
  });

  const activities = [
    { name: 'Calls', kind: 'call', seconds: acc.call },
    { name: 'After-call work', kind: 'call', seconds: acc.wrap },
    ...cats.categories.map(c => ({
      name: c.name === CRM_CATEGORY ? 'CRM work' : c.name,
      kind: c.work !== false ? 'work' : 'nonWork',
      seconds: byCategory[c.name] || 0,
    })),
    { name: 'Meeting / training', kind: 'work', seconds: acc.meeting },
    { name: 'Not categorized yet', kind: 'unreviewed', seconds: acc.unreviewed },
  ]
    .filter(a => a.seconds >= 30)
    .sort((a, b) => (a.kind === 'unreviewed') - (b.kind === 'unreviewed') || (a.kind === 'nonWork') - (b.kind === 'nonWork') || b.seconds - a.seconds);

  const calls = emp.calls;
  const count = calls.length + (phone.liveCall ? 1 : 0);
  const connected = calls.filter(c => c.outcome === 'connected').length;
  const handled = calls.some(c => c.outcome) ? connected : calls.length;
  let crmOpen = 0;
  for (const [dom, t] of Object.entries(emp.domains)) {
    if ((lookup(dom) || {}).name === CRM_CATEGORY) crmOpen += (t.active || 0) + (t.idle || 0);
  }
  const firstCall = calls.length ? Math.min(...calls.map(c => c.start)) : phone.liveCall ? phone.liveCall.startedAt : null;

  return {
    id,
    name: emp.name || (st && st.name) || (info && info.name) || id,
    live: live(st, info, lookup, now),
    clockIn: emp.clockIn && firstCall ? Math.min(emp.clockIn, firstCall) : emp.clockIn || firstCall,
    clockOut: emp.clockOut,
    working: acc.call + acc.wrap + acc.work + acc.meeting,
    totals: acc,
    activities,
    calls: {
      count,
      connected,
      byOutcome: calls.reduce((o, c) => ((o[c.outcome || 'unknown'] = (o[c.outcome || 'unknown'] || 0) + 1), o), {}),
      talk: acc.call,
      wrap: acc.wrap,
      avgHandle: handled ? (acc.call + acc.wrap) / handled : null,
    },
    crm: { open: crmOpen, active: byCategory[CRM_CATEGORY] || 0, actions: emp.crmActions || 0 },
    domains: Object.entries(emp.domains)
      .map(([domain, t]) => {
        const category = lookup(domain);
        return { domain, category: category && category.name, work: category ? category.work : null, active: t.active || 0, idle: t.idle || 0 };
      })
      .sort((a, b) => b.active + b.idle - (a.active + a.idle))
      .slice(0, 40),
    timeline,
    counters: emp.counters || {},
  };
}

function report(date) {
  const now = Date.now();
  const cats = categoriesDoc();
  const lookup = categoryLookup(cats);
  const day = cache.get(`days/${date}.json`) || load(`days/${date}.json`, { date, employees: {} });
  const status = statusDoc();
  const liveInfo = liveDoc();

  const ids = new Set([...Object.keys(day.employees), ...Object.keys(status), ...Object.keys(liveInfo)]);
  const employees = [...ids].map(id => {
    const emp = { ...blankEmployee(null), ...day.employees[id] };
    return employeeReport(id, emp, status[id], liveInfo[id], cats, lookup, date, now);
  });

  const unassigned = {};
  for (const emp of Object.values(day.employees)) {
    for (const [domain, t] of Object.entries(emp.domains)) {
      if (!lookup(domain)) add(unassigned, domain, (t.active || 0) + (t.idle || 0));
    }
  }

  employees.sort((a, b) => a.name.localeCompare(b.name));
  return {
    date,
    serverTime: now,
    offlineAfterMs: OFFLINE_AFTER_MS,
    afterCallMs: AFTER_CALL_MS,
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
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Tracker-Key, X-Integration-Key, X-Admin-Key');
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
        requireKey(req, url, 'tracker', 'integration');
        return send(res, 200, { ok: true, serverTime: Date.now() });
      case 'POST /api/activity':
        requireKey(req, url, 'tracker');
        return send(res, 200, ingestActivity(await readBody(req)));
      case 'POST /api/calls':
        requireKey(req, url, 'integration');
        return send(res, 200, many(await readBody(req), 'calls', ingestCall));
      case 'POST /api/crm-events':
        requireKey(req, url, 'integration');
        return send(res, 200, many(await readBody(req), 'events', ingestCrmEvent));
      case 'POST /api/counters':
        requireKey(req, url, 'integration', 'admin');
        return send(res, 200, saveCounters(await readBody(req)));
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
  console.log(`Data folder: ${DATA_DIR} · time zone: ${Intl.DateTimeFormat().resolvedOptions().timeZone}`);
  if (keysFromFile.includes('tracker')) console.log(`Tracker key (paste into each extension's Settings): ${keys.tracker}`);
  if (keysFromFile.includes('integration')) console.log(`Integration key (phone system / CRM): ${keys.integration}`);
  if (keysFromFile.includes('admin')) console.log(`Dashboard: http://localhost:${PORT}/?key=${keys.admin}`);
});
