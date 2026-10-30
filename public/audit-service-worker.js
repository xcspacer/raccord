/*
 * Keeps the field screens working without a network: the pages of an audit and the OTDR acceptance page, and the
 * scripts, styles and photos they use, are kept as they were last seen and served from there when the network is
 * missing. The audit data itself lives in the phone's database (see resources/js/audit), not here; the OTDR acceptance
 * never sends its measurements anywhere.
 */
const PAGES = 'audit-pages-v1';
const ASSETS = 'audit-assets-v1';
const MEDIA = 'audit-media-v1';
const KEEP = [PAGES, ASSETS, MEDIA];

const isOfflinePage = (url) => /^\/audits\/(optical|full|cabinet\/)/.test(url.pathname) || url.pathname === '/otdr';
const isAsset = (url) => url.pathname.startsWith('/build/') || url.pathname.startsWith('/livewire') || /\.(?:woff2?|svg|ico|png)$/.test(url.pathname);
const isMedia = (url) => /^\/audits\/photos\//.test(url.pathname);
const boxKey = (url) => (/^\/audits\/cabinet\/[^/]+\/(box|point)$/.test(url.pathname) ? url.origin + url.pathname : url.href);

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        for (const name of await caches.keys()) {
            if (!KEEP.includes(name)) {
                await caches.delete(name);
            }
        }

        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data === 'clear') {
        event.waitUntil(Promise.all(KEEP.map((name) => caches.delete(name))));
    }
});

const networkFirst = async (request, key) => {
    const cache = await caches.open(PAGES);

    try {
        const response = await fetch(request);

        if (response.ok && !response.redirected) {
            cache.put(key, response.clone());
        }

        return response;
    } catch {
        return (await cache.match(key)) ?? Response.error();
    }
};

const cacheFirst = async (request, name) => {
    const cache = await caches.open(name);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok) {
        cache.put(request, response.clone());
    }

    return response;
};

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate' && isOfflinePage(url)) {
        event.respondWith(networkFirst(request, boxKey(url)));
    } else if (isMedia(url)) {
        event.respondWith(cacheFirst(request, MEDIA));
    } else if (isAsset(url)) {
        event.respondWith(cacheFirst(request, ASSETS));
    }
});
