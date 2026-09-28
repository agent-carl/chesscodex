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
// (Views are counted by the browser — see Views::mark() — so cached pages count.)
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
    <link rel="modulepreload" href="<?= $asset('/vendor/chess.min.js') ?>">
    <link rel="modulepreload" href="<?= $asset('/vendor/chessground.min.js') ?>">
    <?php endif; ?>

    <?php /* Blocking on purpose: applies the saved theme before the first paint.
             An external file, not inline, so the CSP can forbid inline scripts. */ ?>
    <script src="<?= $asset('/public/theme.min.js') ?>" data-base="<?= $baseEsc ?>"></script>
    <?php /* Name search suggestions and the "/" shortcut, on every page. */ ?>
    <script defer src="<?= $asset('/public/site.min.js') ?>" data-base="<?= $baseEsc ?>"></script>
    <?php /* Render-blocking on purpose: loading it async let the page paint
             half-styled and then reflow (PageSpeed CLS 0.72 on mobile). Being
             blocking, it made an inlined "critical CSS" block pointless: every
             rule of that block was set again here. ~14 KB brotli, cached. */ ?>
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
<body<?= !empty($needsBoard) ? ' class="has-board"' : '' ?><?= Views::bodyAttributes() ?>>
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
                <a href="<?= $baseEsc . $esc(I18n::url('/search')) ?>" title="Press / on any page to search"<?= $navAria('/search') ?>>
                    <?= $esc(t('nav.search')) ?>
                    <kbd class="kbd-hint" aria-hidden="true">/</kbd>
                </a>
                <a class="nav-random" href="<?= $baseEsc . $esc(I18n::url('/random')) ?>" title="Jump to a random opening" rel="nofollow">
                    Random
                </a>
                <a href="<?= $baseEsc . $esc(I18n::url('/repertoire')) ?>" title="The lines you saved, to drill and download" rel="nofollow"<?= $navAria('/repertoire') ?>>Repertoire</a>
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
        // The most searched openings and the rankings, linked from every page:
        // crawlers of a new site otherwise reach them only through the home page.
        try { $footerOpenings = Opening::topPopular(12); } catch (Throwable $e) { $footerOpenings = []; }
        ?>
        <?php if ($footerOpenings): ?>
        <nav class="footer-links" aria-label="Popular openings and rankings">
            <p><span class="footer-links-label">Popular openings:</span>
                <?php foreach ($footerOpenings as $i => $fo): ?><?= $i > 0 ? ' · ' : '' ?><a href="<?= $baseEsc . $esc(I18n::url('/openings/' . $fo['slug'])) ?>"><?= $esc($fo['name']) ?></a><?php endforeach; ?>
            </p>
            <p><span class="footer-links-label">Browse:</span>
                <a href="<?= $baseEsc . $esc(I18n::url('/openings')) ?>">All openings A–Z</a> ·
                <a href="<?= $baseEsc . $esc(I18n::url('/eco')) ?>">ECO codes</a> ·
                <?php foreach (Rankings::LABELS as $fPath => $fLabel): ?><a href="<?= $baseEsc . $esc(I18n::url('/' . $fPath)) ?>"><?= $esc($fLabel) ?></a> · <?php endforeach; ?>
                <a href="<?= $baseEsc . $esc(I18n::url('/search')) ?>">Opening identifier</a>
            </p>
        </nav>
        <?php endif; ?>
        <small><?= t('footer.data') ?></small><br>
        <small style="color:var(--muted)">
            <a href="<?= $baseEsc ?>/about">About</a> ·
            <!--email_off--><a href="mailto:info@chesscodex.org">Contact</a><!--/email_off--> ·
            Powered by <span class="footer-credit"><a href="https://stockfishchess.org/" rel="noopener">Stockfish</a> (GPL-3.0)</span> ·
            <span class="footer-credit"><a href="https://github.com/lichess-org/chessground" rel="noopener">chessground</a> (GPL-3.0)</span> ·
            <span class="footer-credit"><a href="https://github.com/jhlywa/chess.js" rel="noopener">chess.js</a> (BSD-2)</span>
        </small>
    </footer>
</body>
</html>
