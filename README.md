# Caissa Codex

A free, ad-free encyclopedia of every named chess opening — 3,690 lines from the
[Lichess `chess-openings` dataset](https://github.com/lichess-org/chess-openings),
each with an interactive board, live statistics from Lichess, and play-vs-Stockfish.

Live: <https://chesscodex.org>

## Quick start

```bash
# 1. Clone, copy config
cp config.example.php config.php
# Fill in db credentials, lichess_token, admin credentials.

# 2. Upload to your PHP 8.1+ host. Production is a Raspberry Pi (nginx +
#    PHP-FPM + SQLite behind Cloudflare Tunnel) — see deploy/. On Apache,
#    .htaccess wires up the pretty URLs.

# 3. Run schema + data import (one-shot, token-protected):
#    a) Open https://your-site/migrate.php?token=<seed_token>
#       Creates / updates all codex_* tables (idempotent).
#    b) Open https://your-site/seed.php?token=<seed_token>
#       Imports 3,690 openings from db/*.tsv.

# 4. Generate admin password hash:
php -r "echo password_hash('your-strong-pwd', PASSWORD_BCRYPT, ['cost' => 12]);"
# Paste output into config.php → admin → password_hash.

# 5. Test:
php tests/unit.php             # PHP unit tests (no HTTP)
# Hit /tests/smoke.php?token=<seed_token> from the deployed host.
```

## Stack

| Layer | What |
|---|---|
| Language | PHP 8.1+, plain ES-module JavaScript |
| Database | SQLite 3 in production (`db.driver`); MySQL 8 still supported |
| Hosting | Raspberry Pi 5 at home: nginx, PHP 8.4-FPM, Cloudflare Tunnel + Access |
| Board UI | [chessground](https://github.com/lichess-org/chessground) (Lichess) |
| Move validation | [chess.js](https://github.com/jhlywa/chess.js) |
| Engine | [stockfish.wasm](https://github.com/lichess-org/stockfish.wasm) — runs in-browser, no server |
| Stats | Lichess Opening Explorer API (cached server-side, 7-day TTL) |
| Markdown | [Parsedown](https://github.com/erusev/parsedown) (safe mode) |
| Framework | None. ~3,000 lines of hand-rolled PHP |

No bundler, no Composer, no npm. A single `tools/minify.php` script produces
`*.min.js` and `*.min.css` from sources.

## Directory layout

```
/                            ← public root
├── index.php                ← front controller + router
├── og.php                   ← dynamic Open Graph image generator
├── migrate.php              ← migration runner (one-shot, token-gated)
├── seed.php                 ← initial data import (one-shot)
├── prewarm.php              ← Lichess stats cache warmer
├── config.php               ← secrets (NOT in git)
├── .htaccess                ← Apache rewrites + security headers + gzip
│
├── lib/                     ← PHP classes (autoloaded via lib/Autoload.php)
│   ├── Autoload.php         ← class-map autoloader
│   ├── Auth.php             ← admin session + CSRF
│   ├── Cache.php            ← disk-backed get-or-compute helper
│   ├── ChessEngine.php      ← PHP PGN/FEN engine for FEN-indexed lookups
│   ├── I18n.php             ← English-only t() helper (locale shim)
│   ├── Logger.php           ← structured JSON logging
│   ├── LichessExplorer.php  ← HTTP client for Lichess API
│   ├── Migrations.php       ← reads db/migrations/*.sql, tracks in codex_migrations
│   ├── Opening.php          ← main opening lookup + tree queries
│   ├── RateLimit.php        ← per-IP / per-bucket throttle (file-lock)
│   ├── Router.php           ← tiny route dispatcher
│   ├── Routes.php           ← all HTTP handlers as static methods
│   ├── StatsCache.php       ← lichess stats cache layer
│   ├── Submissions.php      ← user-submitted descriptions queue
│   ├── Views.php            ← privacy-respectful visit counter
│   └── lang/                ← i18n strings (en only)
│
├── templates/               ← PHP-rendered HTML
│   ├── layout.php           ← shared <head> + header + footer
│   ├── home.php             ← /
│   ├── opening.php          ← /openings/<slug>
│   ├── play.php             ← /play/<slug>
│   ├── search.php           ← /search
│   ├── openings_index.php   ← /openings (A–Z)
│   ├── about.php            ← /about
│   ├── 404.php              ← Not-found page
│   ├── admin_login.php      ← /admin/login
│   ├── admin_dashboard.php  ← /admin
│   ├── admin_edit.php       ← /admin/edit/<slug>
│   └── admin_review.php     ← /admin/review/<id>
│
├── public/                  ← static assets (served as-is)
│   ├── app.js   + .min.js   ← opening page + recently-viewed + share/copy
│   ├── play.js  + .min.js   ← Stockfish play UI
│   ├── search.js+ .min.js   ← FEN/name/board search
│   ├── sw.js    + .min.js   ← service worker (HTML + static asset cache)
│   ├── style.css+ .min.css  ← all visual CSS
│   ├── favicon.svg          ← chess-knight icon
│   └── manifest.webmanifest ← PWA install manifest
│
├── vendor/                  ← third-party JS / CSS (chess.js, chessground, stockfish.wasm, Parsedown)
├── tests/                   ← unit (PHP) + smoke (HTTP) tests
├── tools/                   ← minify.php
└── db/                      ← migration files, cache, logs, source data
    ├── migrations/          ← <NNN>_*.sql files for migrate.php
    ├── cache/               ← Cache::remember() entries
    ├── log/                 ← Logger output, JSON lines per day
    ├── og_cache/            ← /og.php cached PNGs
    └── *.tsv                ← Lichess source data
```

## Key conventions

- **No npm, no Composer.** Adding deps means dropping files into `vendor/`.
- **All tables prefix `codex_`.** Hosted alongside a WordPress install on the
  same MySQL DB — migration scripts refuse to touch anything else.
- **One-shot scripts gate on `?token=<seed_token>`** from `config.php`.
- **No tracking, no cookies for analytics.** A single session cookie when
  the admin logs in; visit counter aggregates without IP.
- **Mobile-first.** Boards and forms must work at 360 px.

## Running tests

```bash
php tests/unit.php                # framework-free, asserts core PHP classes
# Smoke tests against deployed URL:
php tests/smoke.php
# Or via browser, token-gated:
# https://your-site/tests/smoke.php?token=<seed_token>
```

## Common operations

```bash
# Refresh OG image cache for the popular openings
https://your-site/og_prewarm.php?token=<seed_token>&n=100&offset=0

# Warm Lichess stats (multiple runs, ~25 s each)
https://your-site/prewarm.php?token=<seed_token>&n=50&offset=0

# Adding a new migration: drop SQL into db/migrations/008_my_change.sql, then:
https://your-site/migrate.php?token=<seed_token>
```

## License

Site code: source-available, no formal license declared yet (Reach out via the
"Suggest an improvement" form if you want a copy).

Third-party libraries keep their own licenses — see `vendor/` and the footer link list.
