// Custom service worker para Athena PWA.
// vite-plugin-pwa (injectManifest) inyecta el precache de Workbox
// y respeta este código para handlers custom (push, click).

import { precacheAndRoute } from 'workbox-precaching';
import { registerRoute, NavigationRoute } from 'workbox-routing';
import { NetworkFirst } from 'workbox-strategies';

// Precache assets generados por Vite (CSS, JS chunks, íconos)
precacheAndRoute(self.__WB_MANIFEST || []);

// Para navegación, intentamos red primero (la API debe estar fresca)
// y caemos a cache si offline. La denylist de /api/* asegura que
// los endpoints NUNCA se sirvan stale.
registerRoute(
  new NavigationRoute(new NetworkFirst({ cacheName: 'athena-pages' }), {
    denylist: [/^\/api\//],
  })
);

// ---- Push notifications ----
self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch { data = { title: 'Athena', body: event.data?.text() || '' }; }
  const title = data.title || 'Athena';
  const options = {
    body: data.body || '',
    icon: data.icon || '/app/icon-192.png',
    badge: data.badge || '/app/icon-192.png',
    tag: data.tag || 'athena',
    data: { url: data.url || '/app/hoy' },
    renotify: data.tag === 'briefing' || data.tag === 'urgent',
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = event.notification.data?.url || '/app/hoy';
  event.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    // Si ya hay una ventana abierta, foco + navega
    for (const c of all) {
      if (c.url.includes('/app') && 'focus' in c) {
        await c.focus();
        if ('navigate' in c) c.navigate(targetUrl);
        return;
      }
    }
    // Si no, abre nueva
    if (self.clients.openWindow) {
      await self.clients.openWindow(targetUrl);
    }
  })());
});

// Activa el SW nuevo de inmediato (no esperes a que cierren todas las pestañas)
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
