/*
 * Keeps the standalone « Code Fibre » app usable without a network (stale-while-revalidate): the page and the files it
 * uses are answered from the cache at once and refreshed in the background. The page is registered with its own scope,
 * so this worker never handles the rest of the platform.
 */
const CACHE = 'code-fibre-v3';

const scopeUrl = () => self.registration.scope;

const sameOrigin = (url) => new URL(url, self.location.href).origin === self.location.origin;

/**
 * Files referenced by the page itself (styles, scripts, icons, manifest).
 */
const referencedFiles = (html) => [...html.matchAll(/\b(?:href|src)="([^"]+)"/g)]
    .map((match) => new URL(match[1].replaceAll('&amp;', '&'), scopeUrl()).href)
    .filter((url) => sameOrigin(url));

const keep = async (cache, urls) => {
    await Promise.all([...new Set(urls)].filter(sameOrigin).map((url) => cache.add(url).catch(() => {})));
};

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE);
        const page = await fetch(scopeUrl());

        if (page.ok && !page.redirected) {
            await cache.put(scopeUrl(), page.clone());
            await keep(cache, referencedFiles(await page.text()));
        }

        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        for (const name of await caches.keys()) {
            if (name !== CACHE) {
                await caches.delete(name);
            }
        }

        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'keep' && Array.isArray(event.data.urls)) {
        event.waitUntil(caches.open(CACHE).then((cache) => keep(cache, event.data.urls)));
    }
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET' || !sameOrigin(request.url)) {
        return;
    }

    event.respondWith((async () => {
        const cache = await caches.open(CACHE);
        const cached = await cache.match(request);

        const network = fetch(request).then((response) => {
            if (response.ok && !response.redirected) {
                cache.put(request, response.clone());
            } else if (response.status === 404 && request.mode === 'navigate') {
                // The module was switched off: stop serving the kept copy.
                cache.delete(request);
            }

            return response;
        }).catch(() => cached ?? Response.error());

        if (cached) {
            event.waitUntil(network);

            return cached;
        }

        return network;
    })());
});
