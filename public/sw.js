/**
 * Caissa Codex service worker.
 *
 * Strategy:
 *   - static assets (/vendor, /public + .js/.css/.wasm/.svg/.woff2…)
 *     stale-while-revalidate, capped at MAX_STATIC entries
 *   - HTML pages: network-first with cache fallback, capped at MAX_HTML
 *   - API / sitemap / robots / admin: never cached (admin pages carry CSRF
 *     tokens and submitters' emails — they must not linger in Cache Storage)
 *
 * Cache name has a version suffix so deploys (which flip the asset-URL
 * `?v=<hash>` query) end up with new entries; old caches are pruned on
 * activate. Bump CACHE_VERSION when changing the strategy itself.
 */

// v4: purges v3 caches, which could hold /admin pages.
// v5: versioned assets cache-first; unversioned vendor/chess.js no longer used.
const CACHE_VERSION  = 'codex-v5';
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
        // Keep this version's caches ("codex-v4-static", "codex-v4-html").
        // Comparing against CACHE_VERSION itself matched neither name, so
        // every activation used to wipe them.
        const keys = await caches.keys();
        await Promise.all(keys.filter((k) => !k.startsWith(CACHE_VERSION + '-')).map((k) => caches.delete(k)));
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

// Shown for a page that was never opened while online.
function offlinePage() {
    const html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        + '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline · Caissa Codex</title>'
        + '<style>body{margin:0;font:16px/1.55 system-ui,sans-serif;background:#faf7f0;color:#1a1a1a;display:flex;'
        + 'min-height:100vh;align-items:center;justify-content:center;text-align:center;padding:1rem}'
        + 'h1{font-family:Georgia,serif}a{color:#2a5d8f}'
        + '@media(prefers-color-scheme:dark){body{background:#16181d;color:#e8e6e1}a{color:#6ea3d4}}</style></head>'
        + '<body><main><h1>You are offline</h1><p>This page hasn’t been saved on this device yet.<br>'
        + 'The openings you have opened before still work without a connection.</p>'
        + '<p><a href="/">Try the home page</a></p></main></body></html>';
    return new Response(html, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // API + dynamic + admin — always fresh from network, never cached.
    if (url.pathname.startsWith('/api/') ||
        url.pathname === '/admin' || url.pathname.startsWith('/admin/') ||
        url.pathname === '/sitemap.xml' ||
        url.pathname === '/robots.txt') {
        return;
    }

    // Static assets. A versioned URL (?v=<hash>) never changes, so a cached
    // copy is served without asking the network; the rest are
    // stale-while-revalidate. Both capped in size.
    // og.php diagrams carry ?v= too (the hash of og.php), so they are static.
    if (STATIC_PATTERN.test(url.pathname) || url.pathname === '/og.php') {
        event.respondWith((async () => {
            const cache  = await caches.open(CACHE_VERSION + '-static');
            const cached = await cache.match(req);
            if (cached && url.searchParams.has('v')) return cached;
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
            const type = (fresh && fresh.headers.get('Content-Type')) || '';
            if (fresh && fresh.status === 200 && type.startsWith('text/html')) {
                const cache = await caches.open(CACHE_VERSION + '-html');
                await cache.put(req, fresh.clone());
                await trim(cache, MAX_HTML);
            }
            return fresh;
        } catch (e) {
            const cached = await caches.match(req);
            return cached || offlinePage();
        }
    })());
});
