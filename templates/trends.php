<?php
/** @var string $page    'opening-trends' */
/** @var array  $trends  Rankings::trends() */
/** @var string $baseUrl */
/** @var string $siteUrl */
$esc     = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$baseEsc = $esc($baseUrl);
$pct     = static fn (float $share): string => $esc(YearStats::percent($share));
// To the opening page's own chart.
$lineUrl = static fn (array $r): string => $baseEsc . $esc('/openings/' . $r['slug']) . '#opening-years-title';
$lineA   = static fn (array $r): string => $esc(Opening::article($r['name'])) . '<a href="' . $lineUrl($r) . '">' . $esc($r['name']) . '</a>';
// The moves too, where one name covers several positions (King's Pawn Game: 1. e4, and 1. e4 e5).
$lineAM  = static fn (array $r): string => $lineA($r) . ' (<code>' . $esc($r['moves']) . '</code>)';
$change  = static fn (array $r): string => YearStats::change($r['ratio']);
['from' => $from, 'to' => $to] = $trends;
$ready = $to !== null && ($trends['rising'] || $trends['falling']);
$table = static function (array $rows, string $caption) use ($esc, $pct, $lineUrl, $change, $from, $to): string {
    $h = '<div class="ranking-table-wrap"><table class="ranking-table trends-table"><caption class="visually-hidden">' . $esc($caption) . '</caption>'
       . '<thead><tr><th>Opening</th><th class="ranking-num">' . $from . '</th><th class="ranking-num">' . $to . '</th>'
       . '<th class="ranking-num">Change</th><th class="trends-spark" scope="col"><span class="visually-hidden">Share by year</span></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr><td class="ranking-name"><a href="' . $lineUrl($r) . '"><span class="eco-tag">' . $esc($r['eco']) . '</span> <span>' . $esc($r['name']) . '</span></a>'
            . '<code class="trends-moves">' . $esc($r['moves']) . '</code></td>'
            . '<td class="ranking-num">' . $pct($r['first']) . '</td><td class="ranking-num">' . $pct($r['last']) . '</td>'
            . '<td class="ranking-num trends-change">' . $esc($change($r)) . '</td>'
            . '<td class="trends-spark">' . TrendChart::sparkline($r['years']) . '</td></tr>';
    }
    return $h . '</tbody></table></div>';
};
$featured = array_values(array_filter([$trends['gained'], $trends['lost']]));
ob_start();
?>
<article class="ranking-page trends-page">
    <header>
        <h1>Chess opening trends</h1>
        <p class="lede">Which openings are played more and which less than<?= $ready ? ' in ' . $from : ' before' ?>: the share of
            rated Lichess games between players rated 1600 to 2500 that reach each of the <?= number_format($trends['lines']) ?>
            most played lines, year by year<?= $ready ? ' from ' . $from . ' to ' . $to : '' ?>.</p>
        <nav class="ranking-nav" aria-label="Other rankings">
            <?php foreach (Rankings::LABELS as $path => $label): ?>
                <?php if ($path === $page): ?>
                    <strong aria-current="page"><?= $esc($label) ?></strong>
                <?php else: ?>
                    <a href="<?= $baseEsc . $esc('/' . $path) ?>"><?= $esc($label) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <a href="<?= $baseEsc . $esc('/openings') ?>">All openings A–Z</a>
        </nav>
    </header>

    <?php if (!$ready): ?>
        <p class="ranking-method">The numbers are still being collected from Lichess. Check back in a few hours.</p>
    <?php else:
        $g = $trends['gained'];
        $l = $trends['lost'];
        $top = array_slice($trends['rising'], 0, 3); ?>
        <p class="ranking-answer">
            <?php if ($g): ?>The line that gained the most ground since <?= $from ?> is <?= $lineAM($g) ?>: <?= $pct($g['last']) ?>
                of the games in <?= $to ?>, up from <?= $pct($g['first']) ?>.<?php endif; ?>
            <?php if ($top): ?>The fastest risers: <?= implode(', ', array_map(static fn (array $r): string => $lineA($r) . ' (' . $esc($change($r)) . ')', $top)) ?>.<?php endif; ?>
            <?php if ($l): ?>The biggest loss: <?= $lineAM($l) ?>, from <?= $pct($l['first']) ?> to <?= $pct($l['last']) ?>.<?php endif; ?>
        </p>

        <?php if ($featured): ?>
        <div class="trends-featured">
            <?php foreach ($featured as $f): $fy = YearStats::forOpening($f['id']); if (!$fy) continue; ?>
                <section class="trends-chart">
                    <h2 class="trends-chart-title"><a href="<?= $lineUrl($f) ?>"><?= $esc($f['name']) ?></a>
                        <code class="trends-moves"><?= $esc($f['moves']) ?></code></h2>
                    <?= TrendChart::years($fy, 'Line chart: ' . Opening::article($f['name']) . $f['name'] . "'s share of games by year. The numbers are in the table below.") ?>
                </section>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($trends['rising']): ?>
        <section id="rising">
            <h2 class="ranking-heading">Rising: played more than in <?= $from ?></h2>
            <?= $table($trends['rising'], 'Openings played more than in ' . $from) ?>
        </section>
        <?php endif; ?>
        <?php if ($trends['falling']): ?>
        <section id="falling">
            <h2 class="ranking-heading">Falling: played less than in <?= $from ?></h2>
            <?= $table($trends['falling'], 'Openings played less than in ' . $from) ?>
        </section>
        <?php endif; ?>
        <?php if ($trends['firstMoves']): ?>
        <section id="first-moves">
            <h2 class="ranking-heading">First moves</h2>
            <?= $table($trends['firstMoves'], "White's first moves") ?>
        </section>
        <?php endif; ?>

        <p class="ranking-method">
            Share: the games that reach a line's position in a year, out of all the year's rated blitz, rapid and classical
            Lichess games between players rated 1600 to 2500 (the Lichess opening explorer; the number of games grew about
            tenfold over these years, so the share is what compares them). Rising and falling: lines whose share moved by
            a fifth or more, with at least <?= $pct(Rankings::TREND_MIN_SHARE) ?> of the games at the higher end; one line per opening, the one
            that moved most. Each opening's page has its chart with every year, <?= $to + 1 ?> so far included.
        </p>
    <?php endif; ?>
    <p class="ranking-method">
        See also <a href="<?= $baseEsc . $esc('/popular-openings') ?>">the most popular openings</a>,
        <a href="<?= $baseEsc . $esc('/best-openings-for-white') ?>">best openings for White</a>,
        <a href="<?= $baseEsc . $esc('/best-openings-for-black') ?>">for Black</a> and
        <a href="<?= $baseEsc . $esc('/gambits') ?>">chess gambits</a>
    </p>
</article>
<?php if ($ready): ?>
<script type="module" src="<?= $baseEsc ?>/public/charts.min.js?v=<?= @filemtime(__DIR__ . '/../public/charts.min.js') ?: 1 ?>"></script>
<?php endif;
$body        = ob_get_clean();
$title       = 'Chess Opening Trends' . ($ready ? " {$from}–{$to}" : '') . ': Rising and Falling Openings';
$description = $ready && $trends['gained']
    ? 'How often the ' . number_format($trends['lines']) . " most played chess openings were played on Lichess each year since $from: the "
      . $trends['gained']['name'] . ' went from ' . YearStats::percent($trends['gained']['first']) . ' to ' . YearStats::percent($trends['gained']['last'])
      . ' of the games. The openings that rose, the ones that fell, and the first moves.'
    : 'Which chess openings are played more and which less than ten years ago, by their share of Lichess games each year.';
$canonical   = $siteUrl . $baseUrl . '/' . $page;
$noindex     = !$ready;
$all         = array_merge($trends['rising'], $trends['falling']);
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'       => 'CollectionPage',
            'name'        => 'Chess opening trends',
            'url'         => $canonical,
            'description' => $description,
            'inLanguage'  => 'en',
            'mainEntity'  => [
                '@type'           => 'ItemList',
                'numberOfItems'   => count($all),
                'itemListElement' => array_map(static fn (int $i, array $r): array => [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'name'     => $r['name'],
                    'url'      => $siteUrl . $baseUrl . '/openings/' . $r['slug'],
                ], array_keys($all), $all),
            ],
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => t('site.name'), 'item' => $siteUrl . $baseUrl . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Chess opening trends', 'item' => $canonical],
            ],
        ],
    ],
];
require __DIR__ . '/layout.php';
