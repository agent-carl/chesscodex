<?php
/** @var string $page     One of the Rankings::LABELS keys. */
/** @var array  $rows     Rankings::rows($page, $level). */
/** @var string|null $level  A LevelStats::LEVELS key, on the per-level best-for pages. */
/** @var string $baseUrl */
/** @var string $siteUrl */
$esc     = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$baseEsc = $esc($baseUrl);
$first   = $rows[0] ?? null;
$side    = match ($page) {
    'best-openings-for-white' => 'white',
    'best-openings-for-black' => 'black',
    default                   => null,
};
$texts = [
    'best-openings-for-white' => [
        'title' => 'Best Chess Openings for White, Ranked by Lichess Results',
        'h1'    => 'Best chess openings for White',
        'lede'  => 'Lines where White makes the last move — positions White can steer the game into — ranked by White\'s score: wins plus half the draws. Only lines played in at least a million games are ranked.',
        'desc'  => 'The 50 openings that score best for White in rated Lichess games, among lines played at least a million times, with White, draw and Black percentages for each.',
    ],
    'best-openings-for-black' => [
        'title' => 'Best Chess Openings for Black, Ranked by Lichess Results',
        'h1'    => 'Best chess openings for Black',
        'lede'  => 'Lines where Black makes the last move — the defenses and replies Black can choose — ranked by Black\'s score: wins plus half the draws. Only lines played in at least a million games are ranked.',
        'desc'  => 'The 50 openings that score best for Black in rated Lichess games, among lines played at least a million times, with White, draw and Black percentages for each.',
    ],
    'popular-openings' => [
        'title' => 'Most Popular Chess Openings: the Top 100 on Lichess',
        'h1'    => 'Most popular chess openings',
        'lede'  => 'Named lines ranked by the number of rated Lichess games that reached them.',
        'desc'  => 'The 100 most-played chess openings and variations in rated Lichess games'
                 . ($first ? ' — ' . $first['name'] . ' leads with ' . Rankings::compact($first['games']) . ' games' : '')
                 . ' — with White, draw and Black percentages for each.',
    ],
    'gambits' => [
        'title' => 'Chess Gambits: the 100 Most Played, with Win Rates',
        'h1'    => 'Chess gambits by popularity',
        'lede'  => 'Lines that offer or accept a gambit, ranked by the number of rated Lichess games that reached them. "… Gambit Declined" lines are left out: nothing is sacrificed in them.',
        'desc'  => 'The 100 most-played chess gambits and countergambits in rated Lichess games, each with its moves and how often White and Black win.',
    ],
][$page];

// Level pages: the same ranking in one rating band, or in master games.
$level     = $level ?? null;
$levelWord = [
    'beginners'    => ['for beginners', 'rated under 1400', 'for Beginners (Rated Under 1400)'],
    'intermediate' => ['for intermediate players', 'rated 1400 to 1799', 'for Intermediate Players (1400–1799)'],
    'advanced'     => ['for advanced players', 'rated 1800 to 2199', 'for Advanced Players (1800–2199)'],
    'experts'      => ['for experts', 'rated 2200 and up', 'for Experts (Rated 2200+)'],
    'masters'      => ['in master games', 'rated 2200+ in over-the-board play', 'in Master Games'],
];
if ($side !== null && $level !== null) {
    [$for, $rated, $titleFor] = $levelWord[$level];
    $Side = ucfirst($side);
    $pool = $level === 'masters'
        ? 'over-the-board games of players rated 2200+ (the Lichess masters database)'
        : 'rated Lichess games between players ' . $rated;
    $texts = [
        'title' => "Best Chess Openings for $Side $titleFor",
        'h1'    => "Best chess openings for $Side $for",
        'lede'  => "Lines where $Side makes the last move, ranked by $Side's score — wins plus half the draws — in $pool. "
                 . 'Among the 500 most-played lines, those reached in at least '
                 . number_format(Rankings::MIN_GAMES_AT_LEVEL[$level] ?? Rankings::MIN_GAMES_AT_LEVEL['default'])
                 . ' games at this level.',
        'desc'  => "The openings that score best for $Side in $pool, with $Side, draw and "
                 . ($side === 'white' ? 'Black' : 'White') . ' percentages for each.',
    ];
}

$pct = static fn (int $n, int $of): float => $of > 0 ? $n * 100 / $of : 0.0;
// First moves only: "1. e4 e5 2. Nf3 Nc6 3. Bb5 a6 4. Ba4…".
$moves = static function (string $pgn): string {
    if (mb_strlen($pgn) <= 40) return $pgn;
    $cut = mb_substr($pgn, 0, (int) mb_strrpos(mb_substr($pgn, 0, 41), ' '));
    return preg_replace('/\s*\d+\.+$/', '', $cut) . '…';
};
ob_start();
?>
<article class="ranking-page">
    <header>
        <h1><?= $esc($texts['h1']) ?></h1>
        <p class="lede"><?= $esc($texts['lede']) ?></p>
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
        <?php if ($side !== null):
            $base = '/best-openings-for-' . $side; ?>
            <nav class="ranking-nav ranking-levels" aria-label="Level">
                <span>Level:</span>
                <?php foreach (['' => 'All players, 1600–2500'] + array_map(static fn (array $l): string => $l['label'], LevelStats::LEVELS) as $key => $label):
                    $current = ($key === '' && $level === null) || $key === $level; ?>
                    <?php if ($current): ?>
                        <strong aria-current="page"><?= $esc($label) ?></strong>
                    <?php else: ?>
                        <a href="<?= $baseEsc . $esc(I18n::url($base . ($key !== '' ? '/' . $key : ''))) ?>"><?= $esc($label) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </header>

    <?php if (!$rows): ?>
        <p class="ranking-method">The numbers for this level are still being collected from Lichess — check back in a few hours.</p>
    <?php endif; ?>
    <div class="ranking-table-wrap"<?= $rows ? '' : ' hidden' ?>>
        <table class="ranking-table">
            <thead>
                <tr>
                    <th class="ranking-num">#</th>
                    <th>Opening</th>
                    <th class="ranking-moves">Moves</th>
                    <th class="ranking-num">Games</th>
                    <th>White / Draw / Black</th>
                    <?php if ($side): ?><th class="ranking-num"><?= $side === 'white' ? 'White' : 'Black' ?> score</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $r):
                [$w, $d, $b] = [$pct($r['white'], $r['games']), $pct($r['draws'], $r['games']), $pct($r['black'], $r['games'])];
                $label = sprintf('White %.1f%% · Draw %.1f%% · Black %.1f%%', $w, $d, $b);
            ?>
                <tr>
                    <td class="ranking-num"><?= $i + 1 ?></td>
                    <td class="ranking-name">
                        <a href="<?= $baseEsc . $esc(I18n::url('/openings/' . $r['slug'])) ?>">
                            <span class="eco-tag"><?= $esc($r['eco']) ?></span>
                            <span><?= $esc($r['name']) ?></span>
                        </a>
                    </td>
                    <td class="ranking-moves"><code><?= $esc($moves($r['pgn_moves'])) ?></code></td>
                    <td class="ranking-num"><?= $esc(Rankings::compact($r['games'])) ?></td>
                    <td>
                        <span class="ranking-result">
                            <span class="stats-bar inline" role="img" aria-label="<?= $esc($label) ?>"><span class="stats-bar-w" style="width:<?= round($w, 1) ?>%"></span><span class="stats-bar-d" style="width:<?= round($d, 1) ?>%"></span><span class="stats-bar-b" style="width:<?= round($b, 1) ?>%"></span></span>
                            <span class="ranking-pcts"><?= round($w) ?> / <?= round($d) ?> / <?= round($b) ?></span>
                        </span>
                    </td>
                    <?php if ($side): ?><td class="ranking-num"><?= number_format($r['score'] * 100, 1) ?>%</td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="ranking-method">
        <?php if ($level === null): ?>
        Numbers: rated blitz, rapid and classical games on Lichess between players rated 1600 to 2500,
        from the Lichess opening explorer as cached by this site; a line's numbers are refreshed once
        they are a week old.
        <?php elseif ($level === 'masters'): ?>
        Numbers: the Lichess masters database — over-the-board games between players rated 2200 and up —
        fetched for the 500 most-played lines and refreshed monthly.
        <?php else: ?>
        Numbers: rated blitz, rapid and classical games on Lichess between players <?= $esc($levelWord[$level][1]) ?>,
        fetched for the 500 most-played lines and refreshed monthly.
        <?php endif; ?>
        Each name appears once, as its most-played line.
        <?php if ($side): ?>
            At this level, sharp lines in which a natural-looking reply goes wrong score highest — a
            high score says how a line does in practice, not that it is objectively best.
        <?php endif; ?>
    </p>
</article>
<?php
$body        = ob_get_clean();
$title       = $texts['title'];
$description = $texts['desc'];
$canonical   = $siteUrl . $baseUrl . I18n::url('/' . $page . ($level !== null ? '/' . $level : ''));
// A level page stays out of the index until its numbers are in.
$noindex     = $rows === [];
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'       => 'CollectionPage',
            'name'        => $texts['h1'],
            'url'         => $canonical,
            'description' => $description,
            'inLanguage'  => I18n::locale(),
            'mainEntity'  => [
                '@type'           => 'ItemList',
                'numberOfItems'   => count($rows),
                'itemListElement' => array_map(static fn (int $i, array $r): array => [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'name'     => $r['name'],
                    'url'      => $siteUrl . $baseUrl . I18n::url('/openings/' . $r['slug']),
                ], array_keys($rows), $rows),
            ],
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => t('site.name'), 'item' => $siteUrl . $baseUrl . I18n::url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $texts['h1'],   'item' => $canonical],
            ],
        ],
    ],
];
require __DIR__ . '/layout.php';
