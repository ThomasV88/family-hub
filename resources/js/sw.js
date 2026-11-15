import { precacheAndRoute, cleanupOutdatedCaches, matchPrecache } from 'workbox-precaching';

// Precache-only worker (FOUND-03): hashed build assets, icons and the offline page.
// Pages, Inertia JSON and API responses are never cached here.
cleanupOutdatedCaches();
precacheAndRoute(self.__WB_MANIFEST);

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') return;
    event.respondWith(fetch(event.request).catch(() => matchPrecache('offline.html')));
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
});

// Web Push. Always show a notification (browsers penalise silent pushes). The payload is title,
// url and tag only; the url is honoured for same-origin paths ('/x', never '//x').
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = {};
    }
    if (!data || typeof data !== 'object') data = {};

    const title = typeof data.title === 'string' && data.title !== '' ? data.title : 'Family Hub';
    // Same-origin paths only: '/x' passes, '//x' and '/\\x' (which WHATWG parsing turns into a host) do not.
    let url = '/';
    if (typeof data.url === 'string' && data.url.startsWith('/') && !data.url.startsWith('//') && !data.url.includes('\\')) {
        try {
            if (new URL(data.url, self.location.origin).origin === self.location.origin) url = data.url;
        } catch {
            url = '/';
        }
    }
    const options = {
        icon: '/icons/pwa-192x192.png',
        badge: '/icons/pwa-192x192.png',
        data: { url },
    };
    if (typeof data.tag === 'string' && data.tag !== '') options.tag = data.tag;

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            for (const client of windows) {
                if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                    return client.focus().then((focused) => ('navigate' in focused ? focused.navigate(url) : focused));
                }
            }
            return self.clients.openWindow(url);
        }),
    );
});
