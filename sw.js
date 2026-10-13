// sw.js — Veedo Service Worker (Web Push + PWA caching)
const CACHE_VERSION = 'v3';
const CACHE_NAME    = 'veedo-static-' + CACHE_VERSION;

// Asset-uri pre-cached la instalare (esențiale pentru PWA + offline soft-fallback)
const PRECACHE = [
    '/favicon.svg',
    '/assets/icon-192.png',
    '/assets/icon-512.png',
];

self.addEventListener('install', e => {
    self.skipWaiting();
    e.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(PRECACHE))
            .catch(err => console.warn('[SW] Precache fail:', err))
    );
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys.filter(k => k.startsWith('veedo-') && k !== CACHE_NAME)
                    .map(k => caches.delete(k))
            ))
            .then(() => clients.claim())
    );
});

// ── Fetch: stale-while-revalidate pentru asset-uri statice ────
// (HTML, API, admin, uploads, .php → trecere directă la network)
self.addEventListener('fetch', event => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;
    if (url.pathname.startsWith('/api/'))      return;
    if (url.pathname.startsWith('/admin/'))    return;
    if (url.pathname.startsWith('/uploads/'))  return;
    if (url.pathname.endsWith('.php'))         return;
    if (url.pathname === '/sw.js')             return;

    const isStatic =
        url.pathname.startsWith('/css/')    ||
        url.pathname.startsWith('/js/')     ||
        url.pathname.startsWith('/assets/') ||
        url.pathname === '/favicon.svg'     ||
        /\.(woff2?|ttf|otf|eot)$/i.test(url.pathname);

    if (!isStatic) return;

    event.respondWith(
        caches.open(CACHE_NAME).then(cache =>
            cache.match(req).then(cached => {
                const networkFetch = fetch(req).then(response => {
                    if (response && response.ok && response.type === 'basic') {
                        cache.put(req, response.clone()).catch(() => {});
                    }
                    return response;
                }).catch(() => cached);
                return cached || networkFetch;
            })
        )
    );
});

// ── Push event ────────────────────────────────────────────
self.addEventListener('push', function(event) {
    if (!event.data) return;

    let data;
    try { data = event.data.json(); }
    catch(e) { data = { title: 'Veedo', body: event.data.text(), icon: '/assets/icon-192.png' }; }

    const options = {
        body:    data.body    || '',
        icon:    data.icon    || '/assets/icon-192.png',
        badge:   data.badge   || '/assets/badge-72.png',
        image:   data.image   || undefined,
        data:    { url: data.url || '/', notif_id: data.notif_id || null },
        actions: data.actions || [],
        vibrate: [200, 100, 200],
        tag:     data.tag     || 'veedo-notif',
        renotify: true,
        requireInteraction: false,
    };

    event.waitUntil(
        self.registration.showNotification(data.title || 'Veedo', options)
    );
});

// ── Click pe notificare ───────────────────────────────────
self.addEventListener('notificationclick', function(event) {
    event.notification.close();

    const url      = event.notification.data?.url || '/';
    const notifId  = event.notification.data?.notif_id;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(list) {
            // Marcam ca citita
            if (notifId) {
                fetch('/api/notif-action.php?action=read_one&id=' + notifId, { credentials: 'same-origin' });
            }

            // Deschidem sau focusam fereastra
            for (const client of list) {
                if (client.url.includes(self.location.origin) && 'focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            return clients.openWindow(url);
        })
    );
});

// ── Push subscription change ──────────────────────────────
self.addEventListener('pushsubscriptionchange', function(event) {
    event.waitUntil(
        self.registration.pushManager.subscribe(event.oldSubscription.options)
            .then(sub => fetch('/api/csrf-token.php', { credentials: 'same-origin' })
                .then(r => r.json())
                .then(t => fetch('/api/push-subscribe.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': t.token || '' },
                    body: JSON.stringify(sub.toJSON()),
                    credentials: 'same-origin'
                })))
    );
});
