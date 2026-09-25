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

if (strlen($description) > 160) {
    $description = rtrim(substr($description, 0, 157), " ,.;:-") . '…';
}

$esc = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

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
        $fp = @fopen($abs, 'rb');
        if ($fp) {
            $chunk = @fread($fp, 65536);
            @fclose($fp);
            if ($chunk !== false) $ver = substr(sha1($chunk), 0, 8);
        } else {
            $ver = (string) @filemtime($abs);
        }
    }
    return $cache[$path] = $baseEsc . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '?v=' . $ver;
};

// Critical CSS inlined for first paint — covers header, brand, base typography,
// hero. Full stylesheet loads async right after.
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
    // the site-wide card. Cached on disk in db/og_cache/ for 30 days.
    $ogSlug = isset($opening['slug']) ? (string) $opening['slug'] : '';
    $ogImg  = $siteUrl . $baseUrl . '/og.php' . ($ogSlug !== '' ? '?slug=' . urlencode($ogSlug) : '');
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
    <link rel="apple-touch-icon" href="<?= $asset('/public/favicon.svg') ?>">
    <link rel="manifest" href="<?= $asset('/public/manifest.webmanifest') ?>">

    <meta name="author" content="<?= $esc(t('site.name')) ?>">
    <meta name="theme-color" content="#2a5d8f" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#16181d" media="(prefers-color-scheme: dark)">

    <!-- Preconnect: Lichess Explorer is hit from /api/stats which we proxy
         server-side, but lichess.org is also referenced from footer + JSON-LD
         attribution. Cheap dns-prefetch + preconnect saves ~200 ms on repeat
         visitors who click through. -->
    <link rel="preconnect" href="https://lichess.org" crossorigin>
    <link rel="dns-prefetch" href="https://explorer.lichess.ovh">

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
    <link rel="preload" href="<?= $asset('/public/style.min.css') ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?= $asset('/public/style.min.css') ?>"></noscript>
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
    <script>
        (function () {
            var saved = null;
            try { saved = localStorage.getItem('codex-theme'); } catch (e) {}
            var theme = saved || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.dataset.theme = theme;
        })();
    </script>
    <?php if ($jsonLd): ?>
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <?php endif; ?>
</head>
<body>
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
                <a href="<?= $baseEsc . $esc(I18n::url('/search')) ?>" title="Press / from anywhere to focus search"<?= $navAria('/search') ?>>
                    <?= $esc(t('nav.search')) ?>
                    <kbd class="kbd-hint" aria-hidden="true">/</kbd>
                </a>
                <a href="<?= $baseEsc . $esc(I18n::url('/random')) ?>" title="Jump to a random opening" rel="nofollow">
                    <span aria-hidden="true">&#127922;</span>
                    Random
                </a>
                <?php
                // Admin-only quick link with pending-count badge. Anonymous
                // visitors don't have a session cookie, so we skip both the
                // session start AND the COUNT query entirely — that keeps
                // public pages cacheable and Set-Cookie-free.
                if (isset($_COOKIE['codex_admin'])) {
                    require_once __DIR__ . '/../lib/Auth.php';
                    if (Auth::isLoggedIn()) {
                        require_once __DIR__ . '/../lib/Submissions.php';
                        $adminCounts  = Submissions::counts();
                        $adminPending = (int) ($adminCounts['pending'] ?? 0);
                        ?>
                        <a class="admin-link" href="<?= $baseEsc ?>/admin">
                            Admin
                            <?php if ($adminPending > 0): ?>
                                <span class="admin-link-badge" title="<?= $adminPending ?> pending submissions"><?= $adminPending ?></span>
                            <?php endif; ?>
                        </a>
                        <?php
                    }
                }
                ?>
            </nav>
            <button class="theme-toggle" type="button" aria-label="<?= $esc(t('nav.toggle_theme.dark')) ?>" id="theme-toggle">
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
    <script>
        (function () {
            var btn = document.getElementById('theme-toggle');
            function syncPressed() {
                if (!btn) return;
                var dark = document.documentElement.dataset.theme === 'dark';
                btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
                btn.setAttribute('aria-label', dark ? <?= json_encode(t('nav.toggle_theme.light')) ?> : <?= json_encode(t('nav.toggle_theme.dark')) ?>);
            }
            syncPressed();
            if (btn) btn.addEventListener('click', function () {
                var next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
                document.documentElement.dataset.theme = next;
                try { localStorage.setItem('codex-theme', next); } catch (e) {}
                syncPressed();
            });
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', function () {
                    // Explicit scope '/' — works thanks to the
                    // Service-Worker-Allowed: / header set in .htaccess.
                    navigator.serviceWorker.register('<?= $baseEsc ?>/public/sw.js', { scope: '<?= $baseEsc ?>/' }).catch(function () {});
                });
            }
        })();
    </script>
</body>
</html>
