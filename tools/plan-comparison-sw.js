/* Plan Comparison 2027 — offline support.
   App files: network first (always the newest version when online), but if the network hangs for
   4 s or answers with an error, the cached copy is used. Google Fonts: cached after first use.
   Claude API calls and anything else are never touched. */
const VERSION = 'pc-shell-v2';
const FONTS = 'pc-fonts';
const WAIT_MS = 4000;
const SHELL = [
  'plan-comparison.html',
  'plan-comparison.webmanifest',
  'icons/plan-192.png',
  'icons/plan-512.png',
  'icons/plan-maskable-512.png',
  'icons/plan-apple-180.png',
  'icons/plan-icon.svg',
];

// A response that followed a redirect can't answer a navigation — store a clean copy instead.
const plain = res => res.redirected
  ? res.blob().then(b => new Response(b, {status: res.status, statusText: res.statusText, headers: res.headers}))
  : Promise.resolve(res);

self.addEventListener('install', e => {
  e.waitUntil(caches.open(VERSION)
    .then(c => Promise.all(SHELL.map(p => fetch(p, {cache: 'no-cache'}).then(res => {
      if (!res.ok) throw new Error('Could not cache ' + p);
      return plain(res).then(r => c.put(p, r));
    }))))
    .then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k.startsWith('pc-shell-') && k !== VERSION).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  if (url.origin === self.location.origin) {
    const base = new URL('./', self.location).pathname;
    // Hosts with "pretty URLs" serve the page at .../plan-comparison — same file.
    const key = url.pathname === base + 'plan-comparison' ? base + 'plan-comparison.html' : url.pathname;
    if (!SHELL.some(p => key === base + p)) return;
    const cached = () => caches.match(key);
    const net = fetch(req).then(res => {
      if (!res.ok) return res;
      const copy = res.clone();
      return caches.open(VERSION).then(c => plain(copy).then(r => c.put(key, r))).then(() => res, () => res);
    });
    e.waitUntil(net.catch(() => {}));
    e.respondWith(
      Promise.race([net, new Promise(r => setTimeout(r, WAIT_MS))])
        .then(res => (res && res.status < 400) ? res : cached().then(hit => hit || res || net))
        .catch(() => cached().then(hit => hit || Response.error()))
    );
    return;
  }

  if (url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com') {
    e.respondWith(caches.open(FONTS).then(async c => {
      const hit = await c.match(req);
      const net = fetch(req).then(res => { c.put(req, res.clone()); return res; }).catch(() => hit || Response.error());
      return hit || net;
    }));
  }
});
