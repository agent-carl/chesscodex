# Architecture

A high-level walkthrough of how a request flows through Caissa Codex, plus the
"why" behind key decisions. Read this when you need to extend the codebase
without breaking something subtle.

## Request lifecycle

```
Browser → Cloudflare (edge cache, 10 min for anonymous pages)
        → Cloudflare Tunnel (cloudflared on the Pi)
        → nginx 127.0.0.1:8081 (deploy/nginx/: static files, headers, CSP)
        → PHP-FPM → index.php
                      │
                      ├─ lib/Autoload.php   class map: lib/<Class>.php
                      ├─ lib/I18n.php, lib/Router.php, lib/Routes.php
                      │
                      ├─ build Router: /robots.txt, /sitemap.xml, /api/*,
                      │  /admin/*, /openings/<slug>, … (see Routing below)
                      │
                      └─ $router->dispatch($path)
                            │
                            └─ Routes::method (pages in Routes.php, /api/* in
                               RoutesApi.php, /admin/* in RoutesAdmin.php —
                               traits of the one Routes class)
                                  ├─ Views::mark(...)       names the page for the view counter
                                  ├─ Opening::… / Cache::remember(...)
                                  ├─ require templates/<x>.php (opening.php also
                                  │     pulls templates/partials/opening_*.php)
                                  └─ require templates/layout.php → response
```

All HTML comes back through `templates/layout.php`: head meta, OG / Twitter
tags, JSON-LD, the one render-blocking stylesheet (`public/style.min.css`,
built from `public/css/NN-*.css` in file-name order), chessground CSS and a
modulepreload of the board modules only when `$needsBoard` is set, and the
`Cache-Control: public, s-maxage=600` header for anonymous GETs.

Assets carry `?v=<xxh3 of the file>` (`$asset()` in the layout); nginx serves
`*.js|css|wasm|png|…` as `immutable` for 30 days. `tools/minify.php` writes
the `.min` files and gives the imports of vendor modules in them the same
`?v=`, so a page loads chess.js / chessground once and a changed vendor file
gets a new URL.

## Data flow for /openings/<slug>

The opening page is the most complex template. Order of operations:

1. **Routes::opening($slug)** does the DB legwork:
   - `Opening::findBySlug($slug)` — 1 row from `codex_openings`
   - `Opening::ancestors($id)` — walks parent_id chain
   - `Opening::children($id)` — direct child variations
   - `Opening::siblings($id, $parentId)` — other children of the same parent
   - `Opening::descendantCount($id)` — recursive count for the "Show all" label
   - `Opening::descendants($id)` — inlined IF count ≤ 50, else lazy via /api/subtree
   - `Views::mark('opening', $id)` — names the page on `<body>`; public/site.js
     then posts the view to /api/view (so pages served from Cloudflare's cache
     count too, and bots without JavaScript don't)

2. **templates/opening.php** assembles the article HTML:
   - Breadcrumb from `$ancestors` (each name once, shortened by the one before)
   - Board with Start / ‹ / › / Flip (the `og.php` diagram sits in `#board`
     as its still picture until chessground mounts); beside it the moves and
     the actions: Play vs Stockfish, Practice, "My repertoire: + White /
     + Black", the "Copy & export" menu (Copy PGN / FEN / link, Share, PGN
     download, Lichess), and under them the stats panel — printed from the
     cache; each next move is a button that shows it on the board and links
     the named line it reaches (second grid column on a wide screen)
   - Overview section (auto-generated if `$o['description']` is empty), with
     the one suggest form: a description, or a problem report (stored with
     `Submissions::REPORT_MARK`, never published)
   - By rating / master games (`LevelStats`), Description (Parsedown, safe
     mode, auto-TOC for ≥ 3 headings)
   - Variations and "Other lines from the same position" (siblings), named
     by what they add to the name above and the moves that lead there;
     Transpositions; Subtree (`<details>`, with a filter for long lists);
     Recently viewed at the end. The statistics, the rating table and the
     line lists live in `templates/partials/opening_{stats,levels,lines}.php`.

3. **public/app.js** mounts chessground on the board, replays the PGN move-by-move
   to populate the move list (one `<li>` per full move), and fires the stats
   fetch when the printed numbers are missing or old.

Every page also loads **public/site.js** (deferred): the name search with
suggestions (`templates/partials/name_search.php`, on the home page, /search
and the 404 page) and the "/" and Ctrl/Cmd+K shortcuts. The ranking behind
the suggestions is `Opening::rankByName()` — apostrophes and accents ignored,
one typo forgiven, short names like QGD and KID known — over all names,
cached on disk.

## Database schema

One SQLite file, `db/chesscodex.sqlite` (WAL mode, `synchronous=NORMAL` for
the SD card), 6 MB. `db/schema.sqlite.sql` creates it and `tools/seed.php`
fills it from `db/*.tsv`. `lib/db.php` registers a PHP `LIKE` with MySQL's
semantics (case- and accent-insensitive) — the site ran on MySQL on its first
host — so name searches match "grunfeld" to "Grünfeld". Hot prefix lookups
(`pgn_canon`, `fen`) use indexed ranges instead of LIKE.

```
codex_openings              ← 3,690 rows, the canonical opening list
├── id (PK), eco, name, slug (UNIQUE)
├── parent_id                tree structure (NULL for roots)
├── depth, move_count, popularity (Lichess game count)
├── fen, pgn_moves, pgn_canon   (indexed: eco, parent_id, pgn_canon, fen)
└── description              admin-edited Markdown, NULL for most

codex_stats_cache            ← Lichess explorer results per position (fen_hash PK)
codex_level_stats            ← results by rating band + master games (tools/fetch-levels.php)
codex_submissions            ← suggested descriptions and problem reports
codex_view_log               ← daily view counts per page type / opening
codex_opening_lines          ← reserved
codex_migrations             ← migration tracker (db/migrations/sqlite/, none yet)
```

Pages never call Lichess: statistics come from the tables above, filled by
slow sequential CLI jobs on the Pi (the stats prewarm, fetch-levels).

## Caching layers

| Cache | Where | TTL | Invalidation |
|---|---|---|---|
| Cloudflare edge | HTML of anonymous pages | 10 min (`s-maxage=600`), stale up to a day on errors | Expires; purge in the Cloudflare dashboard |
| Static files | browser + Cloudflare | 30 days, `immutable` | New `?v=<hash>` URL when the file changes |
| Disk JSON cache | `db/cache/*.json` | 1–6 h | Expires; `Cache::forget($key)` |
| Lichess stats | `codex_stats_cache` | refreshed by the prewarm on the Pi | `prewarm-all.sh` |
| OG cards / diagrams | `db/og_cache/*.png`, `*.webp` | 30 days, and whenever og.php changes | Delete the file |
| Sitemap | `db/sitemap_cache.xml` | until deleted | Delete it after adding URLs |
| Service worker | the visitor's browser | per `CACHE_VERSION` | Versioned assets cache-first, pages network-first, `/api/*` and `/admin` never |

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

nginx sends everything that isn't a file to `index.php`; the route table is
at the top of it.

```
/                                   home
/openings, /openings/letter/<a-z>   index of the 140 openings, all lines by letter
/openings/<slug>                    opening page        /openings/<slug>.pgn  the line + continuations
/eco, /eco/<code>                   ECO codes
/rankings, /best-openings-for-white|black[/<level>], /popular-openings, /gambits
/search                             name search + identifier (moves, PGN, FEN)
/play/<slug>, /train/<slug>         Stockfish (noindex), trainer
/repertoire, /about, /random (302)
/api/search      name / moves / fen lookups (JSON)
/api/stats       Lichess numbers for an opening (from the cache)
/api/subtree/<id>, /api/pgn, /api/suggest (POST), /api/view (POST, 204)
/admin/…         behind Cloudflare Access and the admin login
/robots.txt, /sitemap.xml, /og.php?slug=…[&kind=diagram[&format=webp]]
```

Site is English-only — `I18n::detect()` is a pass-through shim kept for
template compatibility (`I18n::url()` returns the path unchanged, `t()`
loads strings from `lib/lang/en.php`).

## Security model

| Threat | Mitigation |
|---|---|
| XSS in descriptions | `Parsedown::setSafeMode(true)` — raw HTML stripped, `javascript:` URLs neutered |
| XSS generally | Every output escaped; CSP without inline scripts (deploy/nginx/chesscodex-headers.conf) |
| CSRF on admin actions | per-session token, `Auth::checkCsrf()` |
| Admin area | Cloudflare Access in front, bcrypt login with `RateLimit::check('admin_login', 5)` |
| SQL injection | PDO prepared statements only |
| Clickjacking | `X-Frame-Options: SAMEORIGIN` + `frame-ancestors 'self'` |
| Private files | nginx denies `db/`, `lib/`, `tools/`, `config.php`, dotfiles, `*.md|sql|tsv` |
| Vendor tampering | SRI on the chessground CSS; versioned asset URLs |
| Abuse of /api/* | per-IP limits (3–120 per minute); the real IP comes from `CF-Connecting-IP` (nginx `real_ip`) |
| og.php flooding | slugs checked against the DB before anything is cached |

## When something breaks

- **500 on every URL** — `/var/log/nginx/chesscodex.error.log` and PHP-FPM's log on the Pi; usually a syntax error in a deploy (`bash tools/check.sh` catches those first).
- **Stats panel says "Statistics unavailable"** — nothing cached for that line yet, or the prewarm hit Lichess limits. See `db/log/`.
- **Stale page after a deploy** — Cloudflare keeps HTML up to 10 minutes; add `?anything` to see the live page.
- **Diagram or share card looks old** — delete its file in `db/og_cache/`.

## Deferred / known-but-not-fixed

- Unit tests cover the classes (`tests/run.php`); `tests/smoke.php [BASE]` checks every route over HTTP. No template tests.
- CSS is 16 files layered in cascade order; overridden declarations were removed on 2026-09-27, but many selectors are still set in several files.
- Backups: nightly SQLite backup on the Pi to Cloudflare R2 (deploy/chesscodex-backup, systemd timer).
