// NetConnect Work Tracker: background service worker (Manifest V3).
//
// Every time the employee switches tab, window or app, goes idle, or locks the
// computer, the time since the previous change is credited to whatever was in
// front of them. Time is kept in 5-minute slots and uploaded once a minute.
//
// Privacy: only the site's hostname (e.g. "humana.com") and seconds are stored
// or sent. Never the full URL, page contents, form fields or keystrokes; URLs
// on carrier portals can contain member IDs.

const VERSION = chrome.runtime.getManifest().version;
const TICK_MINUTES = 1;              // heartbeat + upload cadence
const IDLE_AFTER_SECONDS = 5 * 60;   // no keyboard/mouse for 5 min = idle
const MAX_GAP_MS = 90 * 1000;        // longest gap credited at once (sleep, crash)
const SLOT_MS = 5 * 60 * 1000;
const MAX_OUTBOX = 3000;             // batches kept while the server is unreachable
const BATCHES_PER_REQUEST = 500;
const OUTSIDE_CHROME = '(outside chrome)';
const MODES = ['work', 'lunch', 'break', 'off'];

const FRESH_STATE = {
  day: null,          // local YYYY-MM-DD this workday belongs to
  mode: 'work',       // work | lunch | break | off   (chosen in the popup)
  modeSince: null,
  idle: 'active',     // active | idle | locked       (from chrome.idle)
  domain: null,       // hostname in front of the employee right now
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

async function frontDomain() {
  try {
    const win = await chrome.windows.getLastFocused();
    if (!win || !win.focused) return OUTSIDE_CHROME;
    const [tab] = await chrome.tabs.query({ active: true, windowId: win.id });
    return tab ? hostnameOf(tab.url || tab.pendingUrl) : OUTSIDE_CHROME;
  } catch {
    return OUTSIDE_CHROME;
  }
}

function segmentState(s) {
  if (s.mode !== 'work') return s.mode; // lunch | break
  if (s.idle === 'locked') return 'away';
  return s.idle;                        // active | idle
}

// Credit [from, to) to what was in front of the employee, split at 5-minute slots.
function credit(s, buckets, from, to) {
  if (s.mode === 'off' || !s.clockIn) return;
  const state = segmentState(s);
  const domain = state === 'active' || state === 'idle' ? s.domain || OUTSIDE_CHROME : '';
  for (let t = from; t < to; ) {
    const slot = Math.floor(t / SLOT_MS) * SLOT_MS;
    const end = Math.min(to, slot + SLOT_MS);
    const key = [localDay(slot), localTime(slot), domain, state].join('|');
    buckets[key] = (buckets[key] || 0) + (end - t);
    t = end;
  }
  s.totals[state] = (s.totals[state] || 0) + (to - from);
}

async function checkpoint({ idle, fresh = false } = {}) {
  const now = Date.now();
  const stored = await chrome.storage.local.get(['state', 'buckets', 'config']);
  const s = { ...FRESH_STATE, ...stored.state };
  const buckets = stored.buckets || {};

  // Close the segment that just ended. A long gap means the computer slept or
  // Chrome was closed, so only the first moments of it are credited.
  if (s.lastTick && !fresh && now > s.lastTick) {
    credit(s, buckets, s.lastTick, Math.min(now, s.lastTick + MAX_GAP_MS));
  }

  const today = localDay(now);
  if (s.day !== today) {
    Object.assign(s, { day: today, mode: 'work', modeSince: now, clockIn: null, clockOut: null, totals: {} });
  }

  s.idle = idle || (await chrome.idle.queryState(IDLE_AFTER_SECONDS));
  const domain = await frontDomain();
  if (domain !== s.domain) Object.assign(s, { domain, domainSince: now });
  if ((await chrome.idle.queryState(15)) === 'active') s.lastActiveAt = now;
  if (s.mode === 'work' && !s.clockIn && s.idle === 'active') s.clockIn = now;
  s.lastTick = now;

  await chrome.storage.local.set({ state: s, buckets });
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
  const text = !configured ? '!' : { lunch: 'LUN', break: 'BRK', off: 'OFF' }[s.mode] || '';
  chrome.action.setBadgeText({ text });
  chrome.action.setBadgeBackgroundColor({ color: !configured ? '#C0392B' : s.mode === 'off' ? '#8C7A60' : '#4A7B9D' });
}

// What the dashboard shows live. Off the clock or at lunch, the current site is not shared.
function liveStatus(s) {
  if (!s) return null;
  const working = s.mode === 'work';
  return {
    day: s.day,
    mode: s.mode,
    modeSince: s.modeSince,
    idle: s.idle,
    domain: working ? s.domain : null,
    domainSince: working ? s.domainSince : null,
    clockIn: s.clockIn,
    clockOut: s.clockOut,
    lastActiveAt: s.lastActiveAt,
  };
}

// Move finished buckets into a batch with a unique id. The server ignores ids it
// has already stored, so re-sending after a lost response never double-counts.
async function sealBatch() {
  const { buckets = {}, outbox = [] } = await chrome.storage.local.get(['buckets', 'outbox']);
  const items = Object.entries(buckets)
    .map(([key, ms]) => {
      const [date, slot, domain, state] = key.split('|');
      return { date, slot, domain, state, seconds: Math.round(ms / 100) / 10 };
    })
    .filter(item => item.seconds > 0);
  if (items.length) outbox.push({ id: crypto.randomUUID(), items });
  outbox.splice(0, Math.max(0, outbox.length - MAX_OUTBOX));
  await chrome.storage.local.set({ buckets: {}, outbox });
}

let uploading = false;
async function upload() {
  if (uploading) return;
  uploading = true;
  try {
    await serial(sealBatch);
    const { config = {}, state, outbox = [] } = await chrome.storage.local.get(['config', 'state', 'outbox']);
    if (!config.serverUrl || !config.employee) return;
    const res = await fetch(`${config.serverUrl.replace(/\/+$/, '')}/api/activity`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Tracker-Key': config.key || '' },
      body: JSON.stringify({
        employee: config.employee,
        version: VERSION,
        sentAt: Date.now(),
        status: liveStatus(state),
        batches: outbox.slice(0, BATCHES_PER_REQUEST),
      }),
      signal: AbortSignal.timeout(15000),
    });
    if (!res.ok) throw new Error(res.status === 401 ? 'Wrong tracker key' : `Server answered ${res.status}`);
    const done = new Set((await res.json()).accepted || []);
    await serial(async () => {
      const { outbox: current = [] } = await chrome.storage.local.get('outbox');
      await chrome.storage.local.set({
        outbox: current.filter(batch => !done.has(batch.id)),
        lastSync: { at: Date.now(), ok: true },
      });
    });
  } catch (err) {
    await chrome.storage.local.set({ lastSync: { at: Date.now(), ok: false, error: String(err.message || err) } });
  } finally {
    uploading = false;
  }
}

chrome.tabs.onActivated.addListener(() => serial(() => checkpoint()));
chrome.tabs.onUpdated.addListener((_id, change, tab) => {
  if (change.url && tab.active) serial(() => checkpoint());
});
chrome.windows.onFocusChanged.addListener(() => serial(() => checkpoint()));
chrome.idle.onStateChanged.addListener(idle => serial(() => checkpoint({ idle })).then(upload));
chrome.alarms.onAlarm.addListener(({ name }) => {
  if (name === 'tick') serial(() => checkpoint()).then(upload);
});

// Chrome was closed: nothing happened since the last tick, so don't credit the gap.
chrome.runtime.onStartup.addListener(() => serial(() => checkpoint({ fresh: true })));
chrome.runtime.onInstalled.addListener(({ reason }) => {
  serial(() => checkpoint({ fresh: true }));
  if (reason === 'install') chrome.runtime.openOptionsPage();
});

chrome.runtime.onMessage.addListener((msg, _sender, reply) => {
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
