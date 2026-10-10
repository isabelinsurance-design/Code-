// NetConnect Work Tracker: background service worker (Manifest V3).
//
// Every time the employee switches tab, window or app, goes idle, or locks the
// computer, the time since the previous change is credited to whatever was in
// front of them. Time is kept in 5-minute slots and uploaded once a minute,
// along with an activity log: each unbroken stretch on one site (or idle,
// lunch…) with its start and end time.
//
// Phone calls made in Nextiva's Chrome tab are noticed too (see "phone calls" below).
// For CRM sites the manager marks "show sections", a sanitized path says which
// area is open (e.g. "leads"), with record ids removed. If a desktop-app helper
// is installed, the app in front is named while the person is outside Chrome.
//
// Privacy: only the site's hostname (e.g. "humana.com") and times are stored
// or sent. Never the full URL, page titles, page contents, form fields or
// keystrokes; URLs and titles on carrier portals can contain member data.
// For calls, only start and end times: no numbers, names or audio.

const VERSION = chrome.runtime.getManifest().version;
const TICK_MINUTES = 1;              // heartbeat + upload cadence
const IDLE_AFTER_SECONDS = 5 * 60;   // no keyboard/mouse for 5 min = idle
const MAX_GAP_MS = 90 * 1000;        // longest gap credited at once (sleep, crash)
const SLOT_MS = 5 * 60 * 1000;
const MAX_OUTBOX = 3000;             // batches kept while the server is unreachable
const BATCHES_PER_REQUEST = 500;
const OUTSIDE_CHROME = '(outside chrome)';
const MODES = ['work', 'lunch', 'break', 'meeting', 'off'];

const FRESH_STATE = {
  day: null,          // local YYYY-MM-DD this workday belongs to
  mode: 'work',       // work | lunch | break | meeting | off   (chosen in the popup)
  modeSince: null,
  idle: 'active',     // active | idle | locked       (from chrome.idle)
  domain: null,       // hostname in front of the employee right now
  section: null,      // CRM area, or (outside Chrome) the desktop app in front
  domainSince: null,
  clockIn: null,
  clockOut: null,
  lastActiveAt: null,
  lastTick: null,
  totals: {},         // today's milliseconds by state, shown in the popup
};

chrome.idle.setDetectionInterval(IDLE_AFTER_SECONDS);
chrome.alarms.get('tick').then(alarm => {
  if (!alarm) chrome.alarms.create('tick', { periodInMinutes: TICK_MINUTES });
});

// Events arrive in bursts (tab switch + focus change), so state updates run one at a time.
let queue = Promise.resolve();
function serial(fn) {
  const run = queue.then(fn);
  queue = run.catch(err => console.error('[work-tracker]', err));
  return run;
}

const pad = n => String(n).padStart(2, '0');
const localDay = t => {
  const d = new Date(t);
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};
const localTime = t => {
  const d = new Date(t);
  return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

function hostnameOf(url) {
  try {
    const u = new URL(url);
    if (u.protocol === 'http:' || u.protocol === 'https:') return u.hostname.toLowerCase().replace(/^www\./, '');
    if (u.protocol === 'file:') return '(local file)';
  } catch {}
  return '(browser)'; // new tab, settings, extension pages
}

async function front() {
  try {
    const win = await chrome.windows.getLastFocused();
    if (!win || !win.focused) return { domain: OUTSIDE_CHROME, url: null };
    const [tab] = await chrome.tabs.query({ active: true, windowId: win.id });
    const url = tab ? tab.url || tab.pendingUrl : null;
    return { domain: tab ? hostnameOf(url) : OUTSIDE_CHROME, url };
  } catch {
    return { domain: OUTSIDE_CHROME, url: null };
  }
}

// The CRM area in front, from the path and hash. Keeps only the FIRST word-like
// segment (letters and hyphens, 2–24 chars), skipping routing wrappers. Anything
// with a digit is dropped, and deeper segments (where a record or a person's name
// would sit) are never taken, so ids, member ids, dates and names stay on the
// computer. "…/leads/4411" and "…/contacts/johnsmith" both become just the area.
const PATH_SKIP = new Set([
  'app', 'apps', 'index', 'home', 'main', 'default', 'dashboard',
  'v1', 'v2', 'v3', 'api', 'public', 'web', 'portal', 'location', 'locations',
  'account', 'accounts', 'org', 'orgs', 'workspace', 'view', 'detail', 'details',
  'u', 's', 'p', 'c', 'r', 'e', 'd', 'id',
]);
function crmSection(url) {
  try {
    const u = new URL(url);
    for (const seg of `${u.pathname}/${u.hash.replace(/^#!?/, '')}`.split(/[/?&=]+/)) {
      const s = seg.trim().toLowerCase();
      if (!/^[a-z][a-z-]{1,23}$/.test(s) || PATH_SKIP.has(s)) continue;
      return s; // the first real area word; deeper segments are not read
    }
    return null;
  } catch {
    return null;
  }
}

// On a CRM site marked "show sections" → the CRM area. Outside Chrome, if the
// desktop-app helper is installed → the app in front. Otherwise nothing.
function sectionFor(f, pathDomains, foreground) {
  if (f.domain === OUTSIDE_CHROME) {
    return foreground && foreground.name && Date.now() - foreground.at < 90000 ? foreground.name : null;
  }
  if (f.url && (pathDomains || []).some(pd => f.domain === pd || f.domain.endsWith(`.${pd}`))) return crmSection(f.url);
  return null;
}

function segmentState(s) {
  if (s.mode !== 'work') return s.mode; // lunch | break | meeting
  if (s.idle === 'locked') return 'away';
  return s.idle;                        // active | idle
}

// Credit [from, to) to what was in front of the employee, split at 5-minute slots,
// and extend the activity log (a stretch on the same site and state stays one entry).
function credit(s, buckets, log, from, to) {
  if (s.mode === 'off' || !s.clockIn) return;
  const state = segmentState(s);
  const onSite = state === 'active' || state === 'idle';
  const domain = onSite ? s.domain || OUTSIDE_CHROME : '';
  const section = onSite ? s.section || null : null;
  for (let t = from; t < to; ) {
    const slot = Math.floor(t / SLOT_MS) * SLOT_MS;
    const end = Math.min(to, slot + SLOT_MS);
    const key = [localDay(slot), localTime(slot), domain, state].join('|');
    buckets[key] = (buckets[key] || 0) + (end - t);
    t = end;
  }
  s.totals[state] = (s.totals[state] || 0) + (to - from);
  const last = log[log.length - 1];
  if (last && last.domain === domain && last.state === state && (last.section || null) === section && from - last.to < 2000) last.to = to;
  else log.push({ from, to, domain, state, section });
}

async function checkpoint({ idle, fresh = false } = {}) {
  const now = Date.now();
  const stored = await chrome.storage.local.get(['state', 'buckets', 'log', 'config', 'pathDomains', 'foreground']);
  const s = { ...FRESH_STATE, ...stored.state };
  const buckets = stored.buckets || {};
  const log = stored.log || [];

  // Close the segment that just ended. A long gap means the computer slept or
  // Chrome was closed, so only the first moments of it are credited.
  if (s.lastTick && !fresh && now > s.lastTick) {
    credit(s, buckets, log, s.lastTick, Math.min(now, s.lastTick + MAX_GAP_MS));
  }

  const today = localDay(now);
  if (s.day !== today) {
    Object.assign(s, { day: today, mode: 'work', modeSince: now, clockIn: null, clockOut: null, totals: {} });
  }

  s.idle = idle || (await chrome.idle.queryState(IDLE_AFTER_SECONDS));
  const f = await front();
  const section = sectionFor(f, stored.pathDomains, stored.foreground);
  if (f.domain !== s.domain || (section || null) !== (s.section || null)) Object.assign(s, { domain: f.domain, section: section || null, domainSince: now });
  if ((await chrome.idle.queryState(15)) === 'active') s.lastActiveAt = now;
  if (s.mode === 'work' && !s.clockIn && s.idle === 'active') s.clockIn = now;
  s.lastTick = now;

  await chrome.storage.local.set({ state: s, buckets, log });
  showBadge(s, stored.config);
  return s;
}

async function setMode(mode) {
  const s = await checkpoint();
  const now = Date.now();
  s.mode = mode;
  s.modeSince = now;
  if (mode === 'off') {
    s.clockOut = now;
  } else {
    s.clockOut = null;
    if (!s.clockIn) s.clockIn = now;
  }
  const { config } = await chrome.storage.local.get('config');
  await chrome.storage.local.set({ state: s });
  showBadge(s, config);
  return s;
}

function showBadge(s, config) {
  const configured = config && config.serverUrl && config.employee;
  const text = !configured ? '!' : { lunch: 'LUN', break: 'BRK', meeting: 'MTG', off: 'OFF' }[s.mode] || '';
  chrome.action.setBadgeText({ text });
  chrome.action.setBadgeBackgroundColor({ color: !configured ? '#C0392B' : s.mode === 'off' ? '#8C7A60' : '#4A7B9D' });
}

// What the dashboard shows live. Off the clock, at lunch or in a meeting, the current site is not shared.
function liveStatus(s, phone) {
  if (!s) return null;
  const working = s.mode === 'work';
  return {
    day: s.day,
    mode: s.mode,
    modeSince: s.modeSince,
    idle: s.idle,
    domain: working ? s.domain : null,
    section: working ? s.section || null : null,
    domainSince: working ? s.domainSince : null,
    clockIn: s.clockIn,
    clockOut: s.clockOut,
    lastActiveAt: s.lastActiveAt,
    call: phone && phone.call ? { id: phone.call.id, startedAt: phone.call.from } : null,
  };
}

// ---------- phone calls in Nextiva's Chrome tab ----------
//
// Two ways to tell a call is on; the first that works wins:
//  1. phone-hook.js, inside Nextiva's page, sees the call's audio connection open
//     and close. Exact.
//  2. Until (1) has worked once on this computer: the Nextiva tab plays sound for
//     a while (a conversation, not a message ping). The call ends after 45 s of
//     silence, and its end time is the last sound heard.
// Calls in the Nextiva desktop app or on a desk phone can't be seen from Chrome;
// they come from the dashboard's Nextiva call-history import instead.
const AUDIO_CALL_MS = 6000;   // this much sound = a conversation
const AUDIO_GAP_MS = 15000;   // sounds closer together than this are one stretch
const AUDIO_END_MS = 45000;   // silence this long = the call ended
const MIN_CALL_MS = 5000;
const freshPhone = () => ({ open: {}, audio: {}, call: null, done: [], hookWorks: false, openEnded: 0 });
const isPhoneTab = url => hostnameOf(url || '').includes('nextiva');

// Runs one change to the call state, then decides whether a call started or ended.
// Returns true when it did, so the dashboard can be told right away.
async function phoneUpdate(change) {
  const now = Date.now();
  const { phone: stored } = await chrome.storage.local.get('phone');
  const p = { ...freshPhone(), ...stored };
  const before = JSON.stringify(p);
  const callBefore = p.call && p.call.id;
  change(p, now);
  decideCall(p, now);
  if (JSON.stringify(p) !== before) await chrome.storage.local.set({ phone: p });
  return (p.call && p.call.id) !== callBefore;
}

// phone-hook.js in one frame of a Nextiva tab reports how many call connections are open.
function openSignal(p, key, count, now) {
  const prev = p.open[key];
  if (count > 0) {
    p.open[key] = { since: prev ? prev.since : now, at: now };
    p.hookWorks = true;
  } else if (prev) {
    delete p.open[key];
    p.openEnded = now;
  }
}

function audioSignal(p, tabId, playing, now) {
  const a = p.audio[tabId] || { since: null, last: 0, heard: 0, playStart: null };
  if (playing && a.playStart == null) {
    if (!a.since || now - a.last > AUDIO_GAP_MS) Object.assign(a, { since: now, heard: 0 });
    a.playStart = now;
  } else if (!playing && a.playStart != null) {
    a.heard += now - a.playStart;
    a.last = now;
    a.playStart = null;
  }
  p.audio[tabId] = a;
}

// A Nextiva tab closed: its connections are gone. (A reloaded page reports 0 open
// connections by itself within 2 seconds.)
function forgetTab(p, tabId, now) {
  for (const key of Object.keys(p.open)) if (key.startsWith(`${tabId}:`)) openSignal(p, key, 0, now);
  const a = p.audio[tabId];
  if (a) {
    if (a.playStart != null && p.call && !p.call.byHook) p.call.last = Math.max(p.call.last, now);
    delete p.audio[tabId];
  }
}

function decideCall(p, now) {
  // A page that stopped reporting (crashed, or the extension restarted) no longer counts.
  for (const [key, o] of Object.entries(p.open)) if (now - o.at > 60000) openSignal(p, key, 0, o.at);
  const opens = Object.values(p.open);

  let soundNow = false;
  let soundLast = 0;
  let talkSince = null;
  for (const a of Object.values(p.audio)) {
    const playing = a.playStart != null;
    const heard = a.heard + (playing ? now - a.playStart : 0);
    soundNow ||= playing;
    soundLast = Math.max(soundLast, playing ? now : a.last);
    if (a.since && heard >= AUDIO_CALL_MS && (playing || now - a.last < AUDIO_END_MS)) talkSince = Math.min(talkSince ?? Infinity, a.since);
  }

  const c = p.call;
  if (!c) {
    if (opens.length) p.call = { id: crypto.randomUUID(), from: Math.min(...opens.map(o => o.since)), last: now, byHook: true };
    else if (!p.hookWorks && talkSince != null) p.call = { id: crypto.randomUUID(), from: talkSince, last: now, byHook: false };
    return;
  }
  if (opens.length) {
    Object.assign(c, { byHook: true, last: now });
  } else if (c.byHook) {
    finishCall(p, p.openEnded > c.from ? p.openEnded : c.last); // the connection closed: hung up
  } else if (soundNow) {
    c.last = now;
  } else {
    c.last = Math.max(c.last, soundLast);
    if (now - c.last >= AUDIO_END_MS) finishCall(p, c.last);
  }
}

function finishCall(p, end) {
  if (end - p.call.from >= MIN_CALL_MS) p.done.push({ id: p.call.id, from: p.call.from, to: end });
  p.done.splice(0, Math.max(0, p.done.length - 500));
  p.call = null;
}

// Once a minute: catch up on any sound changes missed while the extension was asleep.
async function phoneTick() {
  const tabs = await chrome.tabs.query({});
  return phoneUpdate((p, now) => {
    const phoneTabs = new Map(tabs.filter(t => isPhoneTab(t.url)).map(t => [String(t.id), t]));
    for (const tabId of Object.keys(p.audio)) if (!phoneTabs.has(tabId)) forgetTab(p, tabId, now);
    for (const [tabId, t] of phoneTabs) {
      const playing = Boolean(p.audio[tabId] && p.audio[tabId].playStart != null);
      if (Boolean(t.audible) !== playing) audioSignal(p, tabId, Boolean(t.audible), now);
    }
  });
}

function onPhoneChange(change) {
  serial(() => phoneUpdate(change)).then(callChanged => callChanged && upload());
}

// Move finished buckets into a batch with a unique id. The server ignores ids it
// has already stored, so re-sending after a lost response never double-counts.
async function sealBatch() {
  const { buckets = {}, log = [], outbox = [], phone, inputSlots = {} } = await chrome.storage.local.get(['buckets', 'log', 'outbox', 'phone', 'inputSlots']);
  const calls = (phone && phone.done) || [];
  const activity = Object.entries(inputSlots).map(([key, seconds]) => {
    const [date, slot] = key.split('|');
    return { date, slot, seconds: Math.round(seconds * 10) / 10 };
  }).filter(a => a.seconds > 0);
  const items = Object.entries(buckets)
    .map(([key, ms]) => {
      const [date, slot, domain, state] = key.split('|');
      return { date, slot, domain, state, seconds: Math.round(ms / 100) / 10 };
    })
    .filter(item => item.seconds > 0);
  if (items.length || log.length || calls.length || activity.length) outbox.push({ id: crypto.randomUUID(), items, log, calls, activity });
  outbox.splice(0, Math.max(0, outbox.length - MAX_OUTBOX));
  await chrome.storage.local.set({ buckets: {}, log: [], outbox, inputSlots: {}, ...(calls.length && { phone: { ...phone, done: [] } }) });
}

let uploading = false;
let uploadAgain = false; // something changed (e.g. a call started) while an upload was running
async function upload() {
  if (uploading) {
    uploadAgain = true;
    return;
  }
  uploading = true;
  try {
    await serial(sealBatch);
    const { config = {}, state, outbox = [], phone } = await chrome.storage.local.get(['config', 'state', 'outbox', 'phone']);
    if (!config.serverUrl || !config.employee) return;
    const res = await fetch(`${config.serverUrl.replace(/\/+$/, '')}/api/activity`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Tracker-Key': config.key || '' },
      body: JSON.stringify({
        employee: config.employee,
        version: VERSION,
        sentAt: Date.now(),
        status: liveStatus(state, phone),
        batches: outbox.slice(0, BATCHES_PER_REQUEST),
      }),
      signal: AbortSignal.timeout(15000),
    });
    if (!res.ok) throw new Error(res.status === 401 ? 'Wrong tracker key' : `Server answered ${res.status}`);
    const answer = await res.json();
    const done = new Set(answer.accepted || []);
    await serial(async () => {
      const { outbox: current = [] } = await chrome.storage.local.get('outbox');
      await chrome.storage.local.set({
        outbox: current.filter(batch => !done.has(batch.id)),
        lastSync: { at: Date.now(), ok: true },
        // The employee's own day as the office counts it (calls included), for the popup.
        me: answer.me ? { ...answer.me, at: Date.now() } : null,
        // Which CRM sites to record the area of (the manager decides on the dashboard).
        ...(Array.isArray(answer.pathDomains) && { pathDomains: answer.pathDomains }),
      });
    });
    await nudge(answer.nudge);
  } catch (err) {
    await chrome.storage.local.set({ lastSync: { at: Date.now(), ok: false, error: String(err.message || err) } });
  } finally {
    uploading = false;
    if (uploadAgain) {
      uploadAgain = false;
      upload();
    }
  }
}

// A reminder from the server (not-work site, idle, long lunch…), shown once each.
async function nudge(n) {
  if (!n || typeof n.id !== 'string') return;
  const { lastNudge } = await chrome.storage.local.get('lastNudge');
  if (n.id === lastNudge) return;
  const action = MODES.includes(n.action) ? n.action : null;
  await chrome.storage.local.set({ lastNudge: n.id, nudgeAction: action });
  chrome.notifications.create('nudge', {
    type: 'basic',
    iconUrl: 'icons/icon128.png',
    title: String(n.title || 'Work Tracker').slice(0, 80),
    message: String(n.message || '').slice(0, 200),
    buttons: action ? [{ title: action === 'break' ? 'Start break' : 'Back to work' }] : [],
    priority: 1,
  });
}

chrome.notifications.onButtonClicked.addListener(async id => {
  if (id !== 'nudge') return;
  chrome.notifications.clear(id);
  const { nudgeAction } = await chrome.storage.local.get('nudgeAction');
  if (MODES.includes(nudgeAction)) serial(() => setMode(nudgeAction)).then(upload);
});

// Keyboard/mouse active-seconds from a page's activity.js, added to the current slot.
async function addInput(seconds) {
  const now = Date.now();
  const key = `${localDay(now)}|${localTime(Math.floor(now / SLOT_MS) * SLOT_MS)}`;
  const { inputSlots = {} } = await chrome.storage.local.get('inputSlots');
  inputSlots[key] = Math.min(SLOT_MS / 1000, (inputSlots[key] || 0) + seconds);
  await chrome.storage.local.set({ inputSlots });
}

// Optional desktop-app helper (native messaging): names the app in front while
// the person is outside Chrome. Only the app name is received, never a window
// title or document name. If the helper isn't installed, connecting just fails
// and the feature stays off.
let nativePort = null;
function connectApps() {
  if (nativePort) return;
  try {
    nativePort = chrome.runtime.connectNative('com.netconnect.apptracker');
    nativePort.onMessage.addListener(msg => {
      const name = msg && typeof msg.app === 'string' ? msg.app.replace(/[^\w .-]/g, '').trim().slice(0, 40) : null;
      chrome.storage.local.set({ foreground: { name: name || null, at: Date.now() } });
    });
    nativePort.onDisconnect.addListener(() => { nativePort = null; });
  } catch {
    nativePort = null;
  }
}
connectApps();

chrome.tabs.onActivated.addListener(() => serial(() => checkpoint()));
chrome.tabs.onUpdated.addListener((tabId, change, tab) => {
  if (change.url && tab.active) serial(() => checkpoint());
  if (change.audible !== undefined && isPhoneTab(tab.url)) onPhoneChange((p, now) => audioSignal(p, tabId, change.audible, now));
});
chrome.tabs.onRemoved.addListener(tabId => onPhoneChange((p, now) => forgetTab(p, tabId, now)));
chrome.windows.onFocusChanged.addListener(() => serial(() => checkpoint()));
chrome.idle.onStateChanged.addListener(idle => serial(() => checkpoint({ idle })).then(upload));
chrome.alarms.onAlarm.addListener(({ name }) => {
  if (name === 'tick') {
    connectApps(); // the service worker may have been restarted
    serial(() => checkpoint()).then(() => serial(phoneTick)).then(upload);
  }
});

// Chrome was closed: nothing happened since the last tick, so don't credit the gap.
chrome.runtime.onStartup.addListener(() => serial(() => checkpoint({ fresh: true })));
chrome.runtime.onInstalled.addListener(({ reason }) => {
  serial(() => checkpoint({ fresh: true }));
  if (reason === 'install') chrome.runtime.openOptionsPage();
});

chrome.runtime.onMessage.addListener((msg, sender, reply) => {
  if (msg.type === 'phone' && sender.tab) {
    const key = `${sender.tab.id}:${sender.frameId || 0}`;
    onPhoneChange((p, now) => openSignal(p, key, Number(msg.open) || 0, now));
    return false;
  }
  if (msg.type === 'input' && sender.tab) {
    serial(() => addInput(Math.min(SLOT_MS / 1000, Number(msg.seconds) || 0)));
    return false;
  }
  if (msg.type === 'status') {
    serial(() => checkpoint()).then(reply);
  } else if (msg.type === 'setMode' && MODES.includes(msg.mode)) {
    serial(() => setMode(msg.mode)).then(s => {
      reply(s);
      upload();
    });
  } else if (msg.type === 'sync') {
    serial(() => checkpoint()).then(upload).then(() => reply(true));
  } else {
    return false;
  }
  return true; // reply is sent asynchronously
});
