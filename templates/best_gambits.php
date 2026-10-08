<?php
/** @var string $page     'best-gambits-for-beginners' */
/** @var array  $gambits  Rankings::bestGambitsForBeginners(): 'white' => rows, 'black' => rows */
/** @var string $baseUrl */
/** @var string $siteUrl */
$esc      = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$baseEsc  = $esc($baseUrl);
$minGames = Rankings::MIN_GAMES_AT_LEVEL['default'];
$pct      = static fn (?float $x): string => $x === null ? '—' : number_format($x, 1) . '%';
$lineUrl  = static fn (array $r): string => $baseEsc . $esc(I18n::url('/openings/' . $r['slug']));
// First moves only: "1. e4 e5 2. Nf3 Nc6 3. Bb5 a6 4. Ba4…".
$moves = static function (string $pgn): string {
    if (mb_strlen($pgn) <= 40) return $pgn;
    $cut = mb_substr($pgn, 0, (int) mb_strrpos(mb_substr($pgn, 0, 41), ' '));
    return preg_replace('/\s*\d+\.+$/', '', $cut) . '…';
};
$sideName = ['white' => 'White', 'black' => 'Black'];
ob_start();
?>
<article class="ranking-page">
    <header>
        <h1>Best chess gambits for beginners</h1>
        <p class="lede">Gambits ranked by how well they score for the side that plays them — wins plus half the
            draws — in rated Lichess games between players rated under 1400. Among the lines reached in at least
            <?= number_format($minGames) ?> such games; each gambit appears once, as its best-scoring line.</p>
        <nav class="ranking-nav" aria-label="Other rankings">
            <?php foreach (Rankings::LABELS as $path => $label): ?>
                <?php if ($path === $page): ?>
                    <strong aria-current="page"><?= $esc($label) ?></strong>
                <?php else: ?>
                    <a href="<?= $baseEsc . $esc(I18n::url('/' . $path)) ?>"><?= $esc($label) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <a href="<?= $baseEsc . $esc(I18n::url('/openings')) ?>">All openings A–Z</a>
        </nav>
    </header>

    <?php if (!$gambits['white'] && !$gambits['black']): ?>
        <p class="ranking-method">The numbers for this level are still being collected from Lichess — check back in a few hours.</p>
    <?php else: ?>
        <p class="ranking-answer">
            <?php foreach (['white', 'black'] as $i => $side): $top = $gambits[$side][0] ?? null; if (!$top) continue; ?>
                <?= $i === 0 ? 'Under 1400, the gambit that scores best for White is' : ($gambits['white'] ? 'for Black it is' : 'Under 1400, the gambit that scores best for Black is') ?>
                <a href="<?= $lineUrl($top) ?>"><?= $esc($top['name']) ?></a>:
                <?= $pct($top['low']) ?> in <?= $esc(Rankings::compact($top['games'])) ?> games<?= $i === 0 ? ';' : '.' ?>
            <?php endforeach; ?>
            A high score here says that players at this level often go wrong against the gambit, not that it is
            sound: the last two columns give the score at 2200 and up and Stockfish's verdict.
        </p>
    <?php endif; ?>

    <?php foreach (['white', 'black'] as $side): if (!$gambits[$side]) continue; ?>
    <section id="gambits-for-<?= $side ?>">
        <h2 class="ranking-heading">Gambits for <?= $sideName[$side] ?></h2>
        <div class="ranking-table-wrap">
            <table class="ranking-table">
                <thead>
                    <tr>
                        <th class="ranking-num">#</th>
                        <th>Gambit</th>
                        <th class="ranking-moves">Moves</th>
                        <th class="ranking-num" title="Games between players rated under 1400">Games</th>
                        <th class="ranking-num" title="<?= $sideName[$side] ?>'s score among players rated under 1400">Under 1400</th>
                        <th class="ranking-num" title="<?= $sideName[$side] ?>'s score among players rated 2200 and up">2200+</th>
                        <th class="ranking-num" title="Stockfish after the line's last move, in pawns from White's side">Stockfish</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($gambits[$side] as $i => $r): $e = $r['eval']; ?>
                    <tr>
                        <td class="ranking-num"><?= $i + 1 ?></td>
                        <td class="ranking-name">
                            <a href="<?= $lineUrl($r) ?>">
                                <span class="eco-tag"><?= $esc($r['eco']) ?></span>
                                <span><?= $esc($r['name']) ?></span>
                            </a>
                        </td>
                        <td class="ranking-moves"><code><?= $esc($moves($r['pgn_moves'])) ?></code></td>
                        <td class="ranking-num"><?= $esc(Rankings::compact($r['games'])) ?></td>
                        <td class="ranking-num"><?= $pct($r['low']) ?></td>
                        <td class="ranking-num"><?= $pct($r['high']) ?></td>
                        <td class="ranking-num"<?= $e ? ' title="' . $esc(EngineEval::verdict($e)) . '"' : '' ?>><?= $e ? $esc(EngineEval::scoreText($e)) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endforeach; ?>

    <p class="ranking-method">
        Numbers: rated blitz, rapid and classical games on Lichess, fetched for the
        <?= number_format(LevelStats::lineCount()) ?> most-played lines and refreshed monthly. A line counts as a
        gambit when its name has "Gambit" or "Countergambit" in it, "… Gambit Declined" lines left out; it is
        scored for the side that offers the gambit. 2200+ is shown from 10,000 games. Stockfish 19 at depth 30,
        after the line's last move, in pawns from White's side.
    </p>
    <p class="ranking-method">
        More: <a href="<?= $baseEsc . $esc(I18n::url('/gambits')) ?>#all-gambits">all chess gambits A–Z</a> ·
        <a href="<?= $baseEsc . $esc(I18n::url('/gambits')) ?>#stockfish-vs-practice">gambits that fade as the players get stronger</a> ·
        <a href="<?= $baseEsc . $esc(I18n::url('/best-openings-for-white/beginners')) ?>">best openings for White for beginners</a> ·
        <a href="<?= $baseEsc . $esc(I18n::url('/best-openings-for-black/beginners')) ?>">for Black</a>
    </p>
</article>
<?php
$body        = ob_get_clean();
$title       = 'Best Chess Gambits for Beginners, Ranked by Lichess Results';
$description = 'The gambits that score best for White and for Black among Lichess players rated under 1400, with their score at 2200+ and Stockfish\'s verdict.';
$canonical   = $siteUrl . $baseUrl . I18n::url('/' . $page);
$noindex     = !$gambits['white'] && !$gambits['black'];
$listed      = array_merge($gambits['white'], $gambits['black']);
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'       => 'CollectionPage',
            'name'        => 'Best chess gambits for beginners',
            'url'         => $canonical,
            'description' => $description,
            'inLanguage'  => I18n::locale(),
            'mainEntity'  => [
                '@type'           => 'ItemList',
                'numberOfItems'   => count($listed),
                'itemListElement' => array_map(static fn (int $i, array $r): array => [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'name'     => $r['name'],
                    'url'      => $siteUrl . $baseUrl . I18n::url('/openings/' . $r['slug']),
                ], array_keys($listed), $listed),
            ],
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => t('site.name'), 'item' => $siteUrl . $baseUrl . I18n::url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Best chess gambits for beginners', 'item' => $canonical],
            ],
        ],
    ],
];
require __DIR__ . '/layout.php';
