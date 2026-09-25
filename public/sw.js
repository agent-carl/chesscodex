/**
 * Chess Codex service worker.
 *
 * Strategy:
 *   - static assets (/vendor, /public + .js/.css/.wasm/.svg/.woff2…)
 *     stale-while-revalidate, capped at MAX_STATIC entries
 *   - HTML pages: network-first with cache fallback, capped at MAX_HTML
 *   - API / sitemap / robots: never cached
 *
 * Cache name has a version suffix so deploys (which flip the asset-URL
 * `?v=<hash>` query) end up with new entries; old caches are pruned on
 * activate. Bump CACHE_VERSION when changing the strategy itself.
 */

const CACHE_VERSION  = 'codex-v3';
const STATIC_PATTERN = /\/(?:vendor|public)\/.+\.(?:js|css|wasm|svg|woff2|woff|png|jpg|jpeg|gif)(?:\?.*)?$/;

// Per-cache entry caps. When exceeded, oldest entries are pruned. Prevents
// the SW cache from growing unbounded for users who browse the whole
// 3,690-opening catalogue over many sessions.
const MAX_STATIC = 200;
const MAX_HTML   = 100;

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k)));
        await self.clients.claim();
    })());
});

/**
 * Trim a cache to at most `max` entries — drop the oldest first.
 * Called after every put() so the cache stays bounded.
 */
async function trim(cache, max) {
    const keys = await cache.keys();
    if (keys.length <= max) return;
    const drop = keys.length - max;
    for (let i = 0; i < drop; i++) await cache.delete(keys[i]);
}

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // API + dynamic — always fresh from network, never cached.
    if (url.pathname.startsWith('/api/') ||
        url.pathname === '/sitemap.xml' ||
        url.pathname === '/robots.txt') {
        return;
    }

    // Static assets — stale-while-revalidate with size cap.
    if (STATIC_PATTERN.test(url.pathname)) {
        event.respondWith((async () => {
            const cache  = await caches.open(CACHE_VERSION + '-static');
            const cached = await cache.match(req);
            const networkPromise = fetch(req).then(async (res) => {
                if (res && res.status === 200) {
                    await cache.put(req, res.clone());
                    await trim(cache, MAX_STATIC);
                }
                return res;
            }).catch(() => null);
            return cached || (await networkPromise) || new Response('Offline', { status: 504 });
        })());
        return;
    }

    // HTML pages — network-first, fall back to cache.
    event.respondWith((async () => {
        try {
            const fresh = await fetch(req);
            if (fresh && fresh.status === 200) {
                const cache = await caches.open(CACHE_VERSION + '-html');
                await cache.put(req, fresh.clone());
                await trim(cache, MAX_HTML);
            }
            return fresh;
        } catch (e) {
            const cached = await caches.match(req);
            return cached || new Response('Offline and not in cache.', { status: 504 });
        }
    })());
});
