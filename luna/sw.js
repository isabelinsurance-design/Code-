/* LUNA service worker — mínimo y seguro.
   Solo habilita la instalación como app (PWA). NO cachea datos ni el API
   (luna_api.php) para evitar mostrar información vieja: todo pasa directo a la
   red. Así nunca sirve una pantalla desactualizada (sin el problema del caché). */
const VERSION = 'luna-v1';

self.addEventListener('install', (e) => { self.skipWaiting(); });

self.addEventListener('activate', (e) => {
  e.waitUntil(self.clients.claim());
});

// Passthrough a la red. Tener un handler de fetch es lo que permite instalarla.
self.addEventListener('fetch', (e) => {
  // No interceptamos nada: dejamos que el navegador haga su request normal.
  // (Esto mantiene los datos siempre frescos desde luna_api.php.)
});
