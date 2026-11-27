/* GhostNode – Service Worker (6.6): nur ein Offline-Hinweis und die Grundgestaltung im Zwischenspeicher.
 * Seiten mit persönlichen Daten werden NIE gespeichert; alles andere kommt immer frisch aus dem Netz. */
const CACHE = 'gn-shell-v1';
const SHELL = ['offline.php', 'assets/css/hud.css', 'assets/img/pwa-192.png'];
self.addEventListener('install', e => { e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(k => Promise.all(k.filter(x => x !== CACHE).map(x => caches.delete(x)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
    const r = e.request;
    if (r.method !== 'GET' || new URL(r.url).origin !== location.origin) return;
    if (r.mode === 'navigate') { e.respondWith(fetch(r).catch(() => caches.match('offline.php'))); return; }
    if (SHELL.some(s => r.url.endsWith(s))) e.respondWith(caches.match(r).then(m => m || fetch(r)));
});
