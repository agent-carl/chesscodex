<?php
/** @var string $title */
/** @var string $baseUrl */
/** @var string $body */
/** @var string|null $description */
/** @var string|null $canonical */
/** @var bool|null $noindex */
/** @var array|null $jsonLd */
$title = $title ?? t('site.name');
$baseUrl = $baseUrl ?? '';
$baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
$description = $description ?? t('home.description', ['count' => '3,690']);
$canonical   = $canonical ?? null;
$noindex     = !empty($noindex);
$jsonLd      = $jsonLd ?? null;
$ogType      = $ogType ?? 'website';   // templates may override to 'article'
$locale      = I18n::locale();

// Search results show ~160 characters. Cut at a word boundary, counting
// characters rather than bytes: a byte cut through a "·" or "ü" left invalid
// UTF-8, which htmlspecialchars() turns into an empty description.
if (mb_strlen($description) > 160) {
    $cut = mb_substr($description, 0, 159);
    $space = mb_strrpos($cut, ' ');
    if ($space !== false && $space > 100) $cut = mb_substr($cut, 0, $space);
    $description = preg_replace('/[\s,.;:·-]+$/u', '', $cut) . '…';
}

$esc = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// Admin-only quick link with pending-count badge (null = not logged in).
// Anonymous visitors don't have a session cookie, so we skip both the
// session start AND the COUNT query entirely — that keeps public pages
// cacheable and Set-Cookie-free. Checked here, before any output: started
// from the header markup, session_start() failed with "headers already
// sent" (PHP-FPM flushes after 4 KB) and the link never appeared.
$adminPending = null;
if (isset($_COOKIE['codex_admin'])) {
    require_once __DIR__ . '/../lib/Auth.php';
    if (Auth::isLoggedIn()) {
        require_once __DIR__ . '/../lib/Submissions.php';
        $adminPending = (int) (Submissions::counts()['pending'] ?? 0);
    }
}

// Pages anonymous visitors see may be kept by Cloudflare for 10 minutes, and
// served from its cache for up to a day if the Pi can't answer. Browsers
// still check back every time (max-age=0). Admins, the admin area, errors
// and anything but GET/HEAD send no such header, so they're never cached.
// (Pages served from the cache don't reach Views::track().)
$pagePath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
if (!headers_sent() && $adminPending === null
    && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)
    && in_array(http_response_code(), [200, 404], true)
    && !preg_match('#^' . preg_quote($baseUrl, '#') . '/admin(/|$)#', $pagePath)) {
    header('Cache-Control: public, max-age=0, s-maxage=600, stale-if-error=86400');
}

$projectRoot = realpath(__DIR__ . '/..');
// Asset URL helper. Cache-bust uses a content hash (first 8 hex of SHA-1
// over the first 64 KB) so versioned URLs only change when content actually
// changes — better than mtime which flips on touch / git checkout / FTP
// re-upload of unchanged files. Cached in-memory per request.
$asset = static function (string $path) use ($baseEsc, $projectRoot): string {
    static $cache = [];
    if (isset($cache[$path])) return $cache[$path];
    $abs = $projectRoot . $path;
    $ver = '1';
    if (is_file($abs)) {
        // Hash the whole file: hashing only the first 64 KB kept the version
        // unchanged for edits near the end of style.min.css (73 KB), so
        // Cloudflare and browsers kept serving the stale "immutable" copy.
        // xxh3 is fast enough to run per request.
        $hash = @hash_file('xxh3', $abs);
        $ver  = $hash !== false ? substr($hash, 0, 8) : (string) @filemtime($abs);
    }
    return $cache[$path] = $baseEsc . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '?v=' . $ver;
};

// Preload hint with the exact versioned URL the <link> below uses, so the
// browser (and Cloudflare Early Hints) fetch the stylesheet once, early.
if (!headers_sent()) {
    header('Link: <' . $asset('/public/style.min.css') . '>; rel=preload; as=style', false);
    header('Link: <' . $asset('/public/theme.min.js') . '>; rel=preload; as=script', false);
}

// Critical CSS inlined for first paint — covers header, brand, base typography,
// hero. The full stylesheet is linked right after it (render-blocking).
$criticalCss = <<<'CSS'
:root{--font-body:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;--font-display:ui-serif,"Iowan Old Style","Source Serif Pro","Apple Garamond",Georgia,"Times New Roman",serif;--font-mono:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;--bg:#faf7f0;--bg-elevated:#fff;--bg-subtle:#f1ecdf;--fg:#1a1a1a;--fg-soft:#3a3a3a;--muted:#6b6b6b;--border:#e6e0d4;--border-strong:#c8bfa9;--accent:#2a5d8f;--accent-soft:#e8eef6;--accent-hover:#1d4773;--radius-sm:3px;--radius:6px;--space-2:.5rem;--space-3:.75rem;--space-4:1rem;--space-5:1.5rem;--space-6:2rem}
[data-theme="dark"]{--bg:#16181d;--bg-elevated:#1f2228;--bg-subtle:#1a1d22;--fg:#e8e6e1;--fg-soft:#c8c5be;--muted:#9a978e;--border:#2c3038;--border-strong:#3a3f48;--accent:#6ea3d4;--accent-soft:#1e2a38;--accent-hover:#95bce0}
*,*::before,*::after{box-sizing:border-box}
html,body{margin:0;padding:0;background:var(--bg);color:var(--fg);font-family:var(--font-body);font-size:16px;line-height:1.55;-webkit-font-smoothing:antialiased}
a{color:var(--accent);text-decoration:none}
h1,h2,h3{font-family:var(--font-display);font-weight:600;color:var(--fg);margin:0 0 var(--space-3)}
h1{font-size:1.75rem;line-height:1.2}
button{font:inherit;color:inherit;cursor:pointer}
.site-header{position:sticky;top:0;z-index:10;background:var(--bg-elevated);border-bottom:1px solid var(--border)}
.site-header-inner{max-width:1080px;margin:0 auto;padding:var(--space-3) var(--space-4);display:flex;align-items:center;justify-content:space-between;gap:var(--space-4)}
.brand{display:inline-flex;align-items:baseline;gap:var(--space-2);color:var(--fg);text-decoration:none;font-weight:600}
.brand-mark{font-family:var(--font-display);font-size:1.6rem;line-height:1;color:var(--accent);transform:translateY(2px)}
.brand-name{font-size:1.05rem}
.site-nav{margin-left:auto;margin-right:var(--space-3);display:flex;gap:var(--space-4)}
.site-nav a{color:var(--fg-soft);font-size:.95rem}
.theme-toggle{appearance:none;background:transparent;border:1px solid var(--border);color:var(--fg-soft);width:36px;height:36px;border-radius:var(--radius);display:inline-flex;align-items:center;justify-content:center}
.container{max-width:1080px;margin:0 auto;padding:var(--space-5) var(--space-4) var(--space-6)}
.hero{text-align:center;padding:var(--space-6) 0 var(--space-5);border-bottom:1px solid var(--border);margin-bottom:var(--space-6)}
.hero h1{font-size:2.4rem;margin-bottom:var(--space-2)}
CSS;
?><!DOCTYPE html>
<html lang="<?= $esc($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $esc($title) ?></title>
    <meta name="description" content="<?= $esc($description) ?>">
    <meta name="robots" content="<?= $noindex ? 'noindex,follow' : 'index,follow,max-image-preview:large,max-snippet:-1' ?>">
    <?php if ($canonical): ?>
    <link rel="canonical" href="<?= $esc($canonical) ?>">
    <?php endif; ?>
    <?php
    // Open Graph image: opening-specific slug if we're on an opening page, else
    // the site-wide card. Cached for 30 days on disk, at Cloudflare and by
    // social sites; ?v= (hash of og.php, same as opening.php) changes the URL
    // whenever the card design does.
    $ogSlug = isset($opening['slug']) ? (string) $opening['slug'] : '';
    $ogVer  = substr((string) @hash_file('xxh3', $projectRoot . '/og.php'), 0, 8);
    $ogImg  = $siteUrl . $baseUrl . '/og.php?' . ($ogSlug !== '' ? 'slug=' . urlencode($ogSlug) . '&' : '') . 'v=' . $ogVer;
    ?>
    <meta property="og:type" content="<?= $esc($ogType) ?>">
    <meta property="og:site_name" content="<?= $esc(t('site.name')) ?>">
    <meta property="og:title" content="<?= $esc($title) ?>">
    <meta property="og:description" content="<?= $esc($description) ?>">
    <meta property="og:image" content="<?= $esc($ogImg) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:locale" content="en_US">
    <?php if ($canonical): ?>
    <meta property="og:url" content="<?= $esc($canonical) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= $esc($title) ?>">
    <meta name="twitter:description" content="<?= $esc($description) ?>">
    <meta name="twitter:image" content="<?= $esc($ogImg) ?>">

    <link rel="icon" type="image/svg+xml" href="<?= $asset('/public/favicon.svg') ?>">
    <?php /* Raster fallbacks: Google Search wants a multiple of 48 px, iOS needs PNG. */ ?>
    <link rel="icon" type="image/png" sizes="192x192" href="<?= $asset('/public/icon-192.png') ?>">
    <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $asset('/public/apple-touch-icon.png') ?>">
    <link rel="manifest" href="<?= $asset('/public/manifest.webmanifest') ?>">

    <meta name="author" content="<?= $esc(t('site.name')) ?>">
    <meta name="theme-color" content="#2a5d8f" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#16181d" media="(prefers-color-scheme: dark)">

    <?php /* No preconnect to lichess.org: the Lichess API is called only by the
             server (/api/stats), and a preconnect would make every visitor's
             browser contact Lichess — the About page promises it never does. */ ?>
    <!-- Modulepreload for the shared vendor deps every board page imports.
         Browser starts parsing chess.js + chessground while the HTML is
         still streaming, shaving ~150-300 ms off time-to-interactive on
         repeat visits. Page-specific entry (app.min.js / play.min.js / …)
         loads as usual via its <script type="module"> tag at the bottom. -->
    <?php if (!empty($needsBoard)): ?>
    <link rel="modulepreload" href="<?= $asset('/vendor/chess.js') ?>">
    <link rel="modulepreload" href="<?= $asset('/vendor/chessground.min.js') ?>">
    <?php endif; ?>

    <style id="critical-css"><?= $criticalCss ?></style>
    <?php /* Blocking on purpose: applies the saved theme before the first paint.
             An external file, not inline, so the CSP can forbid inline scripts. */ ?>
    <script src="<?= $asset('/public/theme.min.js') ?>" data-base="<?= $baseEsc ?>"></script>
    <?php /* Render-blocking on purpose: loading it async let the page paint with
             only the critical CSS and then reflow (PageSpeed CLS 0.72 on mobile).
             It's ~12 KB brotli from Cloudflare's cache. */ ?>
    <link rel="stylesheet" href="<?= $asset('/public/style.min.css') ?>">
    <?php
    // Chessground board CSS — only loaded on pages that actually render a
    // board. Set $needsBoard = true in templates that mount Chessground
    // (opening, play, search). Otherwise the ~30 KB of piece + square
    // sprites would download for every visit to /about, /openings
    // (alphabetical index), /admin, 404, etc.
    //
    // SRI hashes guarantee the file the browser downloads matches what
    // we shipped — even if FTP is compromised and someone swaps in a
    // tracker-laden chessground.css, the browser will refuse to apply it.
    // Hashes computed via: openssl dgst -sha384 -binary <file> | base64
    // Update them after any vendor/* file change.
    if (!empty($needsBoard)):
    ?>
    <link rel="stylesheet" href="<?= $asset('/vendor/chessground.base.css') ?>"
          integrity="sha384-xC640aoNTmtjZ0u134MQFxX+je+vedHUFNkXCvU7TQRU6ZJgXpTl8bUDKaIDraBz"
          crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $asset('/vendor/chessground.brown.css') ?>"
          integrity="sha384-FNiviQs+kF/vWoOm7Bi/QYTWBeQIH/DskHImXQ5g6zGjcJwj1eoUahVUSwYEXfH1"
          crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $asset('/vendor/chessground.cburnett.css') ?>"
          integrity="sha384-t0l6ORC8cGo8/GMWCsKb4kVgvWzfwkDU8W9CXOh6Ai8dvgfMdhWl5UYMddaI835A"
          crossorigin="anonymous">
    <?php endif; ?>
    <?php if ($jsonLd): ?>
    <?php /* JSON_HEX_TAG: a "</script>" inside any value can't end the block early. */ ?>
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    <?php endif; ?>
</head>
<?php /* has-board keeps the empty #board placeholder at its full square size
         until Chessground mounts (see ".opening-board:empty" in public/css/);
         without it the board popped in late and shifted the page. */ ?>
<body<?= !empty($needsBoard) ? ' class="has-board"' : '' ?>>
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <header class="site-header">
        <div class="site-header-inner">
            <a class="brand" href="<?= $baseEsc . $esc(I18n::url('/')) ?>">
                <span class="brand-mark" aria-hidden="true">&#9816;</span>
                <span class="brand-name"><?= $esc(t('site.name')) ?></span>
            </a>
            <?php
            // Compute current path once for aria-current marking. Strip query +
            // base prefix to match the routes in index.php.
            $navPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
            if ($baseUrl !== '' && strpos($navPath, $baseUrl) === 0) {
                $navPath = substr($navPath, strlen($baseUrl)) ?: '/';
            }
            $navAria = static fn (string $route): string =>
                $navPath === $route ? ' aria-current="page"' : '';
            ?>
            <nav class="site-nav">
                <a href="<?= $baseEsc . $esc(I18n::url('/openings')) ?>"<?= $navAria('/openings') ?>>Openings</a>
                <a href="<?= $baseEsc . $esc(I18n::url('/eco')) ?>" title="Openings by ECO code"<?= $navAria('/eco') ?>>ECO</a>
                <a href="<?= $baseEsc . $esc(I18n::url('/rankings')) ?>"<?= $navAria('/rankings') ?>>Rankings</a>
                <a href="<?= $baseEsc . $esc(I18n::url('/search')) ?>" title="Press / from anywhere to focus search"<?= $navAria('/search') ?>>
                    <?= $esc(t('nav.search')) ?>
                    <kbd class="kbd-hint" aria-hidden="true">/</kbd>
                </a>
                <a href="<?= $baseEsc . $esc(I18n::url('/random')) ?>" title="Jump to a random opening" rel="nofollow">
                    <span aria-hidden="true">&#127922;</span>
                    <span class="site-nav-label">Random</span>
                </a>
                <?php if ($adminPending !== null): ?>
                <a class="admin-link" href="<?= $baseEsc ?>/admin">
                    Admin
                    <?php if ($adminPending > 0): ?>
                        <span class="admin-link-badge" title="<?= $adminPending ?> pending submissions"><?= $adminPending ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            </nav>
            <button class="theme-toggle" type="button" aria-label="<?= $esc(t('nav.toggle_theme.dark')) ?>" id="theme-toggle"
                    data-label-dark="<?= $esc(t('nav.toggle_theme.dark')) ?>" data-label-light="<?= $esc(t('nav.toggle_theme.light')) ?>">
                <span class="theme-icon-light" aria-hidden="true">&#9728;</span>
                <span class="theme-icon-dark" aria-hidden="true">&#9790;</span>
            </button>
        </div>
    </header>
    <main class="container" id="main-content">
        <?= $body ?>
    </main>
    <button type="button" class="back-to-top" id="back-to-top" aria-label="Back to top" hidden>
        <span aria-hidden="true">↑</span>
    </button>
    <div class="toast-region" id="toast-region" aria-live="polite" aria-atomic="false"></div>
    <!-- Print-only banner: shows the canonical URL so a printed page is
         referenceable. `canonical` is set per-template; falls back to host. -->
    <?php if (!empty($canonical)): ?>
        <p class="print-only print-canonical">
            <?= $esc($canonical) ?>
        </p>
    <?php endif; ?>
    <footer class="site-footer">
        <?php
        // Site version — read once per request from the VERSION file. Just a
        // build identifier (e.g. for bug reports). No git commit hash since
        // we don't ship a .git directory to the server.
        static $codexVersion = null;
        if ($codexVersion === null) {
            $vfile = __DIR__ . '/../VERSION';
            $codexVersion = is_file($vfile) ? trim((string) @file_get_contents($vfile)) : '';
        }
        ?>
        <small><?= t('footer.data') ?></small><br>
        <small style="color:var(--muted)">
            <a href="<?= $baseEsc ?>/about">About</a> ·
            <a href="mailto:info@chesscodex.org">Contact</a> ·
            Powered by <a href="https://stockfishchess.org/" rel="noopener">Stockfish</a> (GPL-3.0) ·
            <a href="https://github.com/lichess-org/chessground" rel="noopener">chessground</a> (GPL-3.0) ·
            <a href="https://github.com/jhlywa/chess.js" rel="noopener">chess.js</a> (BSD-2)
            <?php if ($codexVersion !== ''): ?>
                · <span class="footer-version">v<?= $esc($codexVersion) ?></span>
            <?php endif; ?>
        </small>
    </footer>
</body>
</html>
