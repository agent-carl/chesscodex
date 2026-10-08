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
$sideName  = $island['color'] === 'white' ? 'White' : 'Black';
$otherName = $island['color'] === 'white' ? 'Black' : 'White';
// The most played lines that go on from this one, for the text below the board.
$others = array_values(array_filter($lines, static fn (array $l): bool => $l['slug'] !== $o['slug'] && $l['name'] !== $o['name']));
usort($others, static fn (array $a, array $b): int => (int) $b['popularity'] <=> (int) $a['popularity']);
$seenNames = [];
$others = array_slice(array_values(array_filter($others, static function (array $l) use (&$seenNames): bool {
    return !isset($seenNames[$l['name']]) && ($seenNames[$l['name']] = true);
})), 0, 8);
$lead = !empty($o['description']) ? Opening::leadSentence((string) $o['description']) : null;
ob_start();
?>
<article class="train">
    <header class="play-header">
        <span class="eco-tag"><?= $esc($o['eco']) ?></span>
        <h1><?= $esc($o['name']) ?> trainer</h1>
        <p class="parent-link">
            <a href="<?= $baseEsc . $esc(I18n::url('/openings/' . $o['slug'])) ?>">← Back to the opening</a>
            · <a href="<?= $baseEsc . $esc(I18n::url('/train')) ?>">All openings to practise</a>
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

    <section class="about-section train-about">
        <h2>Practise the <?= $esc($o['name']) ?></h2>
        <p>
            <?php if ($lead !== null): ?><?= $esc($lead) ?><?php endif; ?>
            Play <?= $sideName ?>'s moves of the line from memory —
            <code><?= $esc(Opening::keepNumbers(trim((string) $o['pgn_moves']))) ?></code> — and the trainer plays
            <?= $otherName ?>'s replies; "Play the other side" turns it around.
        </p>
        <?php if (count($lines) > 1): ?>
        <p>
            "Drill all" adds the <?= number_format(count($lines) - 1) ?> named
            line<?= count($lines) === 2 ? '' : 's' ?> that continue it<?php if ($others): ?>, such as
            <?= implode(', ', array_map(static fn (array $l): string => '<a href="' . $baseEsc . $esc(I18n::url('/openings/' . $l['slug'])) . '">'
                . $esc($l['name']) . '</a>', $others)) ?><?php endif; ?>.
        </p>
        <?php endif; ?>
        <p>
            Read about the line, its statistics on Lichess and Stockfish's evaluation on the
            <a href="<?= $baseEsc . $esc(I18n::url('/openings/' . $o['slug'])) ?>"><?= $esc($o['name']) ?> page</a>,
            or pick another opening in the <a href="<?= $baseEsc . $esc(I18n::url('/train')) ?>">opening trainer</a>.
        </p>
    </section>
</article>
<script type="application/json" id="train-data"><?= htmlspecialchars(json_encode($island, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_NOQUOTES, 'UTF-8') ?></script>
<script type="module" src="<?= $baseEsc ?>/public/train.min.js?v=<?= @filemtime(__DIR__ . '/../public/train.min.js') ?: 1 ?>"></script>
<?php
$body       = ob_get_clean();
$needsBoard = true;
// "Italian Game Trainer: Practice the Moves Online", as far as 60 characters allow.
$title = $o['name'] . ' Trainer';
foreach ([': Practice the Moves Online', ': Practice the Moves', ' – Practice'] as $suffix) {
    if (mb_strlen($title . $suffix) <= 60) { $title .= $suffix; break; }
}
$description = 'Practice the ' . $o['name'] . ' (' . $o['eco'] . ') move by move: play ' . $sideName . '\'s moves from memory, '
             . (count($lines) > 1 ? number_format(count($lines)) . ' named lines, ' : '')
             . 'with spaced-repetition review. Free, no account.';
$canonical = $siteUrl . $baseUrl . I18n::url('/train/' . $o['slug']);
// Indexed for the described openings, the ones /train lists; the other
// lines' trainers would be the same page 3,500 times over.
$noindex = empty($o['description']);
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'               => 'WebApplication',
            'name'                => $o['name'] . ' trainer',
            'url'                 => $canonical,
            'description'         => $description,
            'applicationCategory' => 'GameApplication',
            'operatingSystem'     => 'Any',
            'isAccessibleForFree' => true,
            'inLanguage'          => I18n::locale(),
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => t('site.name'), 'item' => $siteUrl . $baseUrl . I18n::url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Chess opening trainer', 'item' => $siteUrl . $baseUrl . I18n::url('/train')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $o['name'] . ' trainer', 'item' => $canonical],
            ],
        ],
    ],
];
require __DIR__ . '/layout.php';
