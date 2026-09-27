<?php
/** @var array  $opening */
/** @var array  $lines    Opening::withContinuations($opening), up to 300. */
/** @var string $baseUrl */
/** @var string $siteUrl */
$o       = $opening;
$esc     = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$baseEsc = $esc($baseUrl);
$island  = [
    'base'      => $baseUrl,
    'slug'      => $o['slug'],
    // The side that chose the line (made its last move) practises it by default.
    'color'     => ((int) $o['move_count']) % 2 === 1 ? 'white' : 'black',
    'lines'     => array_map(static fn (array $l): array => [
        'slug' => $l['slug'], 'name' => $l['name'], 'eco' => $l['eco'], 'pgn' => trim((string) $l['pgn_moves']),
    ], $lines),
];
ob_start();
?>
<article class="train">
    <header class="play-header">
        <span class="eco-tag"><?= $esc($o['eco']) ?></span>
        <h1>Practice: <?= $esc($o['name']) ?></h1>
        <p class="parent-link">
            <a href="<?= $baseEsc . $esc(I18n::url('/openings/' . $o['slug'])) ?>">← Back to the opening</a>
            · <a href="<?= $baseEsc . $esc(I18n::url('/repertoire')) ?>">My repertoire</a>
        </p>
    </header>
    <div class="play-grid">
        <div class="play-board-wrap">
            <div id="train-board" class="opening-board"></div>
            <div class="board-controls">
                <button id="train-restart" type="button">Restart</button>
                <button id="train-hint" type="button">Show move</button>
                <button id="train-next" type="button">Next line</button>
                <button id="train-color" type="button">Play the other side</button>
            </div>
        </div>
        <aside class="play-sidebar">
            <section class="train-panel">
                <h2>Line</h2>
                <p><a id="train-line" href="#"></a></p>
                <p class="train-progress" id="train-progress"></p>
                <p class="train-status" id="train-status" aria-live="polite">Loading…</p>
                <?php if (count($lines) > 1): ?>
                    <label class="train-scope">
                        <input type="checkbox" id="train-all">
                        <span id="train-all-label">Drill all <?= count($lines) ?> lines of this opening, lines due for review first</span>
                    </label>
                <?php endif; ?>
                <p class="train-due" id="train-due"></p>
                <p class="train-help">Play the moves of the line for your side; the other side's moves are
                    played for you. Two wrong tries show the move. A line you get right comes back after
                    1, 3, 7, 16 and 35 days; a mistake brings it back tomorrow. Progress is kept in this
                    browser only.</p>
            </section>
        </aside>
    </div>
</article>
<script type="application/json" id="train-data"><?= htmlspecialchars(json_encode($island, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_NOQUOTES, 'UTF-8') ?></script>
<script type="module" src="<?= $baseEsc ?>/public/train.min.js?v=<?= @filemtime(__DIR__ . '/../public/train.min.js') ?: 1 ?>"></script>
<?php
$body       = ob_get_clean();
$needsBoard = true;
$title      = 'Practice ' . $o['name'] . ' · Caissa Codex';
$description = 'Practice the ' . $o['name'] . ' move by move, with review on a schedule.';
$noindex    = true;
require __DIR__ . '/layout.php';
