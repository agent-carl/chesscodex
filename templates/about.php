<?php
/** @var string $baseUrl */
/** @var string $siteUrl */
$baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
ob_start();
?>
<article class="about-page">
    <header class="about-header">
        <h1>About Chess Codex</h1>
        <p class="lede">A free, open encyclopedia of every named chess opening — built as a side project, no ads, no signup.</p>
    </header>

    <section class="about-section">
        <h2>Who made this</h2>
        <p>
            I'm a high school student in Norway. I built Chess Codex in my spare time because
            I wanted a single reference that combined what I liked from Lichess's opening explorer
            (live statistics) and an encyclopedia-style site (one page per named opening with
            interactive board, theory, and analysis) — without ads, paywalls, or accounts in
            the way.
        </p>
        <p>
            It's a hobby project. I'm still learning. If something feels rough — it probably
            is. Feedback is welcome via the <strong>Suggest an improvement</strong> form on
            any opening page.
        </p>
    </section>

    <section class="about-section">
        <h2>Where the data comes from</h2>
        <ul>
            <li>
                <strong>Opening list (3,690 lines)</strong> — from the
                <a href="https://github.com/lichess-org/chess-openings" rel="noopener">Lichess
                chess-openings</a> dataset, public domain. ECO codes, PGN move sequences,
                canonical names.
            </li>
            <li>
                <strong>Game statistics</strong> — fetched on demand from the
                <a href="https://lichess.org/api#tag/Opening-Explorer" rel="noopener">Lichess
                Opening Explorer API</a> and cached server-side. Win rates, popular replies,
                everything you see in the "Statistics from Lichess" panel.
            </li>
            <li>
                <strong>Engine evaluations</strong> — Stockfish, via the
                <a href="https://lichess.org/api#tag/Tablebase/operation/apiCloudeval" rel="noopener">Lichess
                Cloud Eval API</a>, cached per opening. Only positions Lichess has already
                analysed return an evaluation.
            </li>
            <li>
                <strong>Play vs the engine</strong> — uses
                <a href="https://github.com/lichess-org/stockfish.wasm" rel="noopener">Stockfish.wasm</a>
                running entirely in your browser. Nothing is sent to a server for this; the
                engine is loaded once and runs locally.
            </li>
            <li>
                <strong>Editorial descriptions</strong> — when an opening has a prose
                description, it was either written by me directly or accepted from a
                community submission via the suggest form. Pages without a curated
                description fall back to a generated "Overview" built from the underlying
                metadata.
            </li>
        </ul>
    </section>

    <section class="about-section">
        <h2>How it's built</h2>
        <p>
            Vanilla PHP 8.1, MySQL, plain JavaScript (ES modules). No framework, no build
            step, no bundler — minified by a tiny in-repo PHP script. Hosted on shared OVH
            for a few euros a month. Source-first, optimised for being readable rather than
            clever.
        </p>
        <p>
            Open-source libraries that do the heavy lifting:
            <a href="https://github.com/jhlywa/chess.js" rel="noopener">chess.js</a> (move validation),
            <a href="https://github.com/lichess-org/chessground" rel="noopener">chessground</a>
            (the board UI),
            <a href="https://github.com/lichess-org/stockfish.wasm" rel="noopener">Stockfish.wasm</a>
            (the engine for /play),
            <a href="https://github.com/erusev/parsedown" rel="noopener">Parsedown</a> (Markdown rendering).
        </p>
    </section>

    <section class="about-section">
        <h2>Privacy</h2>
        <p>
            No tracking, no cookies for analytics, no third-party scripts. The site stores
            a single cookie only if you log in as admin (that's not you). Visit counts in
            the admin dashboard are aggregate-only — no IP addresses, no sessions, no
            fingerprint. Bots and the admin's own visits are excluded from those counts.
        </p>
        <p>
            The only outbound requests the site makes are to <code>lichess.org</code> for
            statistics and engine evaluations, and only server-side — your browser never
            talks to Lichess directly.
        </p>
    </section>

    <section class="about-section">
        <h2>Contact</h2>
        <p>
            The fastest way to flag something is the <strong>Suggest an improvement</strong>
            form on any opening page. Bug reports, missing openings, wrong moves —
            everything goes there.
        </p>
    </section>
</article>
<?php
$body = ob_get_clean();
$title = 'About · Chess Codex';
$description = 'About Chess Codex — a free, ad-free encyclopedia of 3,690 chess openings, built as a hobby project by a high school student in Norway.';
$canonical = $siteUrl . $baseUrl . I18n::url('/about');
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type' => 'AboutPage',
            'name'  => 'About Chess Codex',
            'url'   => $canonical,
            'description' => $description,
            'inLanguage'  => I18n::locale(),
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => t('site.name'), 'item' => $siteUrl . $baseUrl . I18n::url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'About',         'item' => $canonical],
            ],
        ],
    ],
];
require __DIR__ . '/layout.php';
