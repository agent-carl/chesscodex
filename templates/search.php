<?php
/** @var string $baseUrl */
/** @var string $siteUrl */
ob_start();
$baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
?>
<article class="search">
    <header class="search-header">
        <h1><?= htmlspecialchars(t('search.h1'), ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="lede"><?= htmlspecialchars(t('search.lede'), ENT_QUOTES, 'UTF-8') ?></p>

        <details class="search-fen search-paste" open>
            <summary>
                <span class="search-fen-icon" aria-hidden="true">&#9998;</span>
                <span class="search-fen-label"><?= htmlspecialchars(t('search.paste.label'), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="search-fen-chevron" aria-hidden="true">&#8250;</span>
            </summary>
            <div class="search-fen-body">
                <p class="search-fen-hint"><?= htmlspecialchars(t('search.paste.hint'), ENT_QUOTES, 'UTF-8') ?></p>
                <form id="search-paste-form" autocomplete="off">
                    <textarea id="search-paste-input" rows="2" spellcheck="false"
                              aria-label="<?= htmlspecialchars(t('search.paste.label'), ENT_QUOTES, 'UTF-8') ?>"
                              placeholder="1. e4 c5 2. Nf3 d6 3. d4 cxd4 4. Nxd4 Nf6 5. Nc3 a6"></textarea>
                    <button type="submit">
                        <span aria-hidden="true">&#128269;</span>
                        <span><?= htmlspecialchars(t('search.paste.find'), ENT_QUOTES, 'UTF-8') ?></span>
                    </button>
                </form>
                <p class="search-paste-status" id="search-paste-status" aria-live="polite" hidden></p>
            </div>
        </details>

        <div class="search-by-name">
            <label for="search-name-input" class="search-by-name-label">Search by name</label>
            <div class="search-by-name-field">
                <input type="search" id="search-name-input"
                       placeholder="Najdorf, Ruy Lopez, Queen's Gambit…"
                       autocomplete="off" spellcheck="false"
                       aria-controls="search-name-results"
                       aria-autocomplete="list">
                <span class="search-by-name-icon" aria-hidden="true">&#128269;</span>
            </div>
            <ul class="search-by-name-results" id="search-name-results" hidden role="listbox"></ul>
        </div>

        <details class="search-fen">
            <summary>
                <span class="search-fen-icon" aria-hidden="true">&#9812;</span>
                <span class="search-fen-label"><?= htmlspecialchars(t('search.fen.label'), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="search-fen-chevron" aria-hidden="true">&#8250;</span>
            </summary>
            <div class="search-fen-body">
                <p class="search-fen-hint"><?= htmlspecialchars(t('search.fen.hint'), ENT_QUOTES, 'UTF-8') ?></p>
                <form id="search-fen-form" autocomplete="off">
                    <input type="text" id="search-fen-input"
                        placeholder="rnbqkbnr/pp1ppppp/8/2p5/4P3/8/PPPP1PPP/RNBQKBNR w KQkq c6 0 2"
                        aria-label="FEN string"
                        spellcheck="false">
                    <button type="submit">
                        <span aria-hidden="true">&#128269;</span>
                        <span><?= htmlspecialchars(t('search.fen.find'), ENT_QUOTES, 'UTF-8') ?></span>
                    </button>
                </form>
            </div>
        </details>
    </header>

    <div class="search-grid">
        <div class="search-board-wrap">
            <div id="search-board" class="opening-board"></div>
            <div class="board-controls">
                <button id="search-undo" type="button"><?= htmlspecialchars(t('search.board.undo'), ENT_QUOTES, 'UTF-8') ?></button>
                <button id="search-reset" type="button"><?= htmlspecialchars(t('search.board.reset'), ENT_QUOTES, 'UTF-8') ?></button>
                <span class="board-hint"><?= htmlspecialchars(t('search.board.hint'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>

        <aside class="search-sidebar">
            <section class="search-result" id="search-result" data-state="empty" aria-live="polite">
                <h2><?= htmlspecialchars(t('search.match'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="search-empty"><?= htmlspecialchars(t('search.empty'), ENT_QUOTES, 'UTF-8') ?></p>
                <div class="search-match" hidden>
                    <a class="search-match-link" href="#">
                        <span class="eco-tag"></span>
                        <span class="search-match-name"></span>
                    </a>
                    <p class="search-match-meta"></p>
                </div>
            </section>

            <section class="search-continuations" id="search-continuations" hidden>
                <h2><?= htmlspecialchars(t('search.continuations'), ENT_QUOTES, 'UTF-8') ?></h2>
                <ul class="child-list"></ul>
            </section>

            <section class="search-played">
                <h2><?= htmlspecialchars(t('search.played'), ENT_QUOTES, 'UTF-8') ?></h2>
                <ol id="search-played-list" class="move-list" aria-label="<?= htmlspecialchars(t('search.played'), ENT_QUOTES, 'UTF-8') ?>"></ol>
            </section>
        </aside>
    </div>

    <?php
    // Worked examples: the moves open this page with them filled in, the name
    // goes straight to the opening.
    $examples = [
        ['1. e4 c5 2. Nf3 d6 3. d4 cxd4 4. Nxd4 Nf6 5. Nc3 a6', 'sicilian-defense-najdorf-variation', 'Sicilian Defense: Najdorf Variation', 'B90'],
        ['1. e4 e5 2. Nf3 Nc6 3. Bb5 a6', 'ruy-lopez-morphy-defense', 'Ruy Lopez: Morphy Defense', 'C70'],
        ['1. d4 Nf6 2. c4 e6 3. Nf3 d5 4. Nc3 Be7 5. Bg5 O-O 6. e3 Nbd7', 'queens-gambit-declined-orthodox-defense', "Queen's Gambit Declined: Orthodox Defense", 'D60'],
        ['1. e4 c6 2. d4 d5 3. e5', 'caro-kann-defense-advance-variation', 'Caro-Kann Defense: Advance Variation', 'B12'],
    ];
    $searchUrl = $baseUrl . I18n::url('/search');
    ?>
    <section class="about-section search-about">
        <h2>How the identifier works</h2>
        <p>
            Every one of the 3,690 named lines in the Lichess chess-openings dataset is indexed by
            its moves. The identifier finds the longest named line that your moves begin with, so a
            game that leaves known theory early still gets the name of the opening it started as —
            along with how many moves past documented theory it went.
        </p>
        <p>
            Different move orders can reach the same position. Search by FEN to list every named
            line that arrives at a position, whatever the order of moves; the move counters and the
            en-passant field are ignored. The name search matches parts of names, with or without
            accents (<em>najdorf</em>, <em>grunfeld</em>), and ECO codes such as <em>B20</em>.
        </p>
        <h2>Examples</h2>
        <ul class="search-examples">
            <?php foreach ($examples as [$moves, $slug, $name, $eco]): ?>
            <li>
                <a href="<?= htmlspecialchars($searchUrl . '?moves=' . rawurlencode($moves), ENT_QUOTES, 'UTF-8') ?>"><code><?= htmlspecialchars($moves, ENT_QUOTES, 'UTF-8') ?></code></a>
                →
                <a href="<?= htmlspecialchars($baseUrl . I18n::url('/openings/' . $slug), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></a>
                (<?= htmlspecialchars($eco, ENT_QUOTES, 'UTF-8') ?>)
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
</article>

<script type="application/json" id="search-data">
<?= htmlspecialchars(json_encode([
    'searchApiUrl'   => $baseUrl . '/api/search',
    'openingPathFmt' => $baseUrl . I18n::url('/openings/{slug}'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_NOQUOTES, 'UTF-8') ?>
</script>
<script type="module" src="<?= $baseEsc ?>/public/search.min.js?v=<?= @filemtime(__DIR__ . '/../public/search.min.js') ?: 1 ?>"></script>
<?php
$body = ob_get_clean();
$needsBoard = true;
$title = t('search.title');
$description = t('search.lede');
// Indexable: it's the page for "which opening is this?" searches. Links with
// ?moves=… all point their canonical here.
$canonical = $siteUrl . $baseUrl . I18n::url('/search');
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'               => 'WebApplication',
            'name'                => t('search.h1'),
            'url'                 => $canonical,
            'description'         => t('search.lede'),
            'applicationCategory' => 'GameApplication',
            'operatingSystem'     => 'Any',
            'isAccessibleForFree' => true,
            'inLanguage'          => I18n::locale(),
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => t('site.name'), 'item' => $siteUrl . $baseUrl . I18n::url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => t('search.h1'), 'item' => $canonical],
            ],
        ],
    ],
];
require __DIR__ . '/layout.php';
