# Architecture

A high-level walkthrough of how a request flows through Caissa Codex, plus the
"why" behind key decisions. Read this when you need to extend the codebase
without breaking something subtle.

## Request lifecycle

```
Browser  →  Apache (rewrite)  →  index.php
                                    │
                                    ├─ require lib/Autoload.php   (class-map)
                                    ├─ require lib/I18n.php
                                    ├─ require lib/Router.php
                                    ├─ require lib/Routes.php
                                    │
                                    ├─ I18n::detect($path)        ← strip /uk, /de, /fr
                                    ├─ build Router instance
                                    │     adds /robots.txt, /sitemap.xml,
                                    │     /api/*, /admin/*, /openings/<slug>, …
                                    │
                                    └─ $router->dispatch($cleanPath)
                                          │
                                          └─ [Routes::class, 'method']  ← static handler
                                                 │
                                                 ├─ Views::track(...)        ← visit counter
                                                 ├─ Opening::findBySlug(...) ← DB
                                                 ├─ Cache::remember(...)     ← disk cache
                                                 │
                                                 ├─ require templates/<x>.php
                                                 │     ↓
                                                 └─ require templates/layout.php
                                                       echoes <html>… → response
```

All HTTP responses come back through the layout template, which inlines a
small critical-CSS block, lazy-loads the full stylesheet, optionally loads
chessground CSS (only when `$needsBoard` is set), and emits the structured
data (JSON-LD), OG / Twitter meta, etc.

## Data flow for /openings/<slug>

The opening page is the most complex template. Order of operations:

1. **Routes::opening($slug)** does the DB legwork:
   - `Opening::findBySlug($slug)` — 1 row from `codex_openings`
   - `Opening::ancestors($id)` — walks parent_id chain
   - `Opening::children($id)` — direct child variations
   - `Opening::siblings($id, $parentId)` — other children of the same parent
   - `Opening::descendantCount($id)` — recursive count for the "Show all" label
   - `Opening::descendants($id)` — inlined IF count ≤ 50, else lazy via /api/subtree
   - `Views::track('opening', $id)` — fire-and-forget visit counter

2. **templates/opening.php** assembles the article HTML:
   - Tree path collapsible (uses `$ancestors`)
   - Share / Tools row (Copy PGN, Copy FEN, Open in Lichess)
   - Board + move-list aside (mounted by `public/app.js`)
   - Overview section (auto-generated if `$o['description']` is empty)
   - Stats panel (`<p data-state="loading">` → JS fills from `/api/stats`)
   - Description (Parsedown, safe mode, with auto-TOC for ≥ 3 headings)
   - Children list, Related (siblings), Subtree (`<details>`)

3. **public/app.js** mounts chessground on the board, replays the PGN move-by-move
   to populate the move-list buttons, and fires the stats fetch.

## Database schema

All tables are `codex_*` prefixed and live in a shared MySQL DB alongside
unrelated WordPress tables. Migration / seeding scripts hard-code that prefix
and refuse to touch anything else.

```
codex_openings              ← 3,690 rows, the canonical opening list
├── id (PK, AI)
├── eco                      e.g. "B20"
├── name                     e.g. "Sicilian Defense"
├── slug                     URL-safe, UNIQUE
├── parent_id                tree structure (NULL for roots)
├── depth, move_count, popularity
├── fen, pgn_moves, pgn_canon
└── description              admin-edited Markdown, NULL for most

codex_opening_lines          ← reserved for future use
codex_stats_cache            ← Lichess Opening Explorer cache (fen_hash PK)
codex_submissions            ← public-submitted descriptions, pending → accepted/rejected
codex_view_log               ← daily aggregated visit counts
codex_migrations             ← migration tracker (version PK)
```

## Caching layers

The site has four distinct caches with different invalidation strategies:

| Cache | Where | TTL | Invalidation |
|---|---|---|---|
| Disk JSON cache | `db/cache/*.json` | 1h–6h | Auto-expires; `Cache::forget($key)` to bust |
| MySQL stats cache | `codex_stats_cache` | 7 days (refresh after 30d hard) | On TTL via `prewarm.php` |
| OG image cache | `db/og_cache/*.png` | 30 days | `og_prewarm.php` re-bakes; just delete the file to force regen |
| Service Worker | browser | per-deploy | `CACHE_VERSION` bumped in `public/sw.js` |
| HTTP cache headers | `.htaccess` | 30 days for `*.{js,css,wasm,svg,png}` | Cache-bust via `?v=<content-hash>` |

## Identity / auth

Single admin user, stored in `config.php`:

```
'admin' => [
    'username'      => '<plain>',
    'password_hash' => '<bcrypt>',
]
```

Login flow:
- `Auth::attemptLogin($u, $p)` — bcrypt verify + rate-limit + Logger
- On success: `session_regenerate_id(true)`, set `codex_admin_user`, set `codex_csrf`.
- All mutating admin endpoints check `Auth::checkCsrf($_POST['csrf'])`.

Anonymous traffic never gets a PHP session cookie. The admin-link badge in
the header explicitly checks `isset($_COOKIE['codex_admin'])` before starting
a session, so we don't blow up HTTP caching for the 99.9% of visitors who
aren't logged in.

## Routing conventions

All URLs are pretty (Apache mod_rewrite). Route patterns in
`index.php`:

```
/                          → home
/about                     → about
/openings                  → alphabetical index
/openings/<slug>           → single opening page
/play/<slug>               → Stockfish play UI
/search                    → board + name + FEN search
/random                    → 302 to a random opening
/api/search                → JSON: moves / fen / name autocomplete
/api/stats?id=<id>         → JSON: Lichess opening explorer (cached; 503 + Retry-After while busy)
/api/subtree/<id>          → JSON: descendants (lazy-loaded)
/api/suggest               → POST: queue a description for review
/admin/...                 → admin dashboard, review, edit, bulk
/robots.txt, /sitemap.xml  → SEO endpoints
/og.php?slug=…             → dynamic OG image
/migrate.php?token=…       → migration runner
```

Site is English-only — `I18n::detect()` is a pass-through shim kept for
template compatibility (`I18n::url()` returns the path unchanged, `t()`
loads strings from `lib/lang/en.php`).

## Security model

| Threat | Mitigation |
|---|---|
| XSS in descriptions | `Parsedown::setSafeMode(true)` — raw HTML stripped, `javascript:` URLs neutered |
| CSRF on mutating endpoints | per-session token in `_SESSION['codex_csrf']`, verified by `Auth::checkCsrf()` |
| Brute-force login | `RateLimit::check('admin_login', 5)` per IP per minute |
| SQL injection | All queries use PDO prepared statements with named params |
| Open redirect | `Auth::takeIntendedPath()` strict-matches a path stored in session |
| Clickjacking | `X-Frame-Options: SAMEORIGIN` + `frame-ancestors 'self'` in CSP |
| Mixed content | HSTS + `Strict-Transport-Security` |
| Vendor tampering | `integrity="sha384-…"` on chessground CSS (SRI) |
| Asset cache poisoning | Content-hash `?v=<sha1>` URLs |
| Bot abuse of /api/* | per-IP throttles (3-60 req/min by endpoint) |
| og.php DoS via random slugs | server-side validates slug exists in DB before caching |

## When something breaks

- **500 page on every URL** — check `error_log` + `db/log/<today>.log` for PHP errors. Most likely a syntax error in a recent deploy.
- **Stats panel shows "Statistics unavailable"** — Lichess API down, rate-limited, or `lichess_token` invalid. Logged to `db/log/`.
- **404 page works but has no suggestions** — `Opening::suggestSimilar()` failed silently. Check DB connectivity.
- **OG image returns generic instead of opening-specific** — slug validation failed. Verify the slug exists in `codex_openings`.

## Deferred / known-but-not-fixed

- No proper test coverage for templates — just unit tests on classes + HTTP smoke tests.
- `Routes.php` is ~500 lines, could split into RoutesAdmin / RoutesApi.
- No CI; tests are run manually.
- No automated DB backups. If you need them, OVH offers a "backup service" add-on at hosting-panel level — backs up the whole MySQL DB independently of the app code.
