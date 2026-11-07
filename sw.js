const CACHE_NAME = 'transmecar-v1';
const ASSETS = [
    './',
    './index.html',
    './css/tokens.css',
    './css/style.css',
    './css/components.css',
    './js/app.js',
    './data/manual.txt'
];

self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS))
    );
    self.skipWaiting();
});

self.addEventListener('fetch', (e) => {
    // Para la API de telemetría siempre ir a la red
    if (e.request.url.includes('/api/')) {
        e.respondWith(fetch(e.request).catch(() => caches.match(e.request)));
        return;
    }
    // Para todo lo demás: caché primero, luego red
    e.respondWith(
        caches.match(e.request).then(response => response || fetch(e.request))
    );
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys().then(keys => Promise.all(
            keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
        ))
    );
});