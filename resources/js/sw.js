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
