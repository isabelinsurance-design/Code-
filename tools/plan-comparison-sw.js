/* Plan Comparison 2027 — offline support.
   App files: network first (always the newest version when online), cached copy when offline.
   Google Fonts: cached after first use. Claude API calls are never touched. */
const VERSION = 'pc-shell-v1';
const FONTS = 'pc-fonts';
const SHELL = [
  'plan-comparison.html',
  'plan-comparison.webmanifest',
  'icons/plan-192.png',
  'icons/plan-512.png',
  'icons/plan-maskable-512.png',
  'icons/plan-apple-180.png',
  'icons/plan-icon.svg',
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(VERSION).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
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
    if (!SHELL.some(p => url.pathname === base + p)) return;
    e.respondWith(
      fetch(req)
        .then(res => {
          if (res.ok) { const copy = res.clone(); caches.open(VERSION).then(c => c.put(url.pathname, copy)); }
          return res;
        })
        .catch(() => caches.match(url.pathname).then(hit => hit || Response.error()))
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
