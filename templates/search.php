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
$noindex = true;
require __DIR__ . '/layout.php';
