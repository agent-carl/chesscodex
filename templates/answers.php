<?php
/** @var string $page     'how-to-play-against' */
/** @var array  $answers  Rankings::answers(): 'black' => answers to White's openings, 'white' => to Black's defenses */
/** @var string $baseUrl */
/** @var string $siteUrl */
$esc     = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$baseEsc = $esc($baseUrl);
$pct     = static fn (float $x): string => number_format($x, 1) . '%';
// To the opening page's own "How to play against …" section.
$answerUrl = static fn (array $r): string => $baseEsc . $esc(I18n::url('/openings/' . $r['slug'])) . '#opening-answers-title';
$move      = static fn (array $r, string $san): string => '<code>' . $esc($r['moveNo'] . $san) . '</code>';
$all       = array_merge($answers['black'], $answers['white']);
$count     = count($all);
// The page's answer in numbers: how often another answer scores at least a
// point more than the most played one (a tenth of a point says nothing),
// where that gap is widest, and how often Stockfish's first choice is the
// best-scoring answer.
$differs = array_values(array_filter($all, static fn (array $r): bool => $r['best']['score'] - $r['most']['score'] >= 1.0));
$agrees  = count(array_filter($all, static fn (array $r): bool => $r['engine'] === $r['best']['san']));
$gap     = null;
foreach ($differs as $r) {
    if ($gap === null || $r['best']['score'] - $r['most']['score'] > $gap['best']['score'] - $gap['most']['score']) $gap = $r;
}
$sections = ['black' => ["Against White's openings", 'Black'], 'white' => ["Against Black's defenses", 'White']];
ob_start();
?>
<article class="ranking-page">
    <header>
        <h1>How to play against popular openings</h1>
        <p class="lede">For <?= $count ?> popular openings described on this site: the answer that scores best for
            the side to move — wins plus half the draws — in rated Lichess games between players rated 1600 to 2500,
            the answer played most often, and Stockfish's first choice.</p>
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

    <?php if ($count === 0): ?>
        <p class="ranking-method">The numbers are still being collected from Lichess — check back in a few hours.</p>
    <?php else: ?>
        <p class="ranking-answer">
            Against <?= count($differs) ?> of the <?= $count ?> openings, another answer scores at least a point more
            than the most played one<?php if ($gap): ?>; the gap is widest against
            <?= Opening::article($gap['name']) ?><a href="<?= $answerUrl($gap) ?>"><?= $esc($gap['name']) ?></a>,
            where <?= $move($gap, $gap['best']['san']) ?> scores <?= $pct($gap['best']['score']) ?> and the most played
            <?= $move($gap, $gap['most']['san']) ?> <?= $pct($gap['most']['score']) ?><?php endif; ?>.
            Stockfish's first choice is the best-scoring answer in <?= $agrees ?> of the <?= $count ?>.
        </p>
    <?php endif; ?>

    <?php foreach ($sections as $side => [$heading, $sideName]): if (!$answers[$side]) continue; ?>
    <section id="answers-<?= $side ?>">
        <h2 class="ranking-heading"><?= $esc($heading) ?></h2>
        <div class="ranking-table-wrap">
            <table class="ranking-table">
                <thead>
                    <tr>
                        <th><?= $sideName === 'Black' ? 'White plays' : 'Black plays' ?></th>
                        <th title="The answer with <?= $sideName ?>'s best score">Best answer</th>
                        <th title="The answer played most often">Most played</th>
                        <th title="Stockfish 19's first choice at depth 30">Stockfish</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($answers[$side] as $r): ?>
                    <tr>
                        <td class="ranking-name">
                            <a href="<?= $answerUrl($r) ?>">
                                <span class="eco-tag"><?= $esc($r['eco']) ?></span>
                                <span><?= $esc($r['name']) ?></span>
                            </a>
                        </td>
                        <td class="answers-move"><?= $move($r, $r['best']['san']) ?> <?= $pct($r['best']['score']) ?></td>
                        <td class="answers-move"><?= $move($r, $r['most']['san']) ?> <?= $pct($r['most']['score']) ?></td>
                        <td class="answers-move"><?= $r['engine'] !== null ? $move($r, $r['engine']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endforeach; ?>

    <p class="ranking-method">
        Answers: the moves played next in rated Lichess games between players rated 1600 to 2500 (the Lichess
        opening explorer), counting those played in at least 5% of the games and in 100 or more. Score: wins plus
        half the draws for the side that answers. Stockfish 19 at depth 30, from the opening's final position.
        Each opening's page shows its answers in full, and the table of every move played next.
    </p>
    <p class="ranking-method">
        More: <a href="<?= $baseEsc . $esc(I18n::url('/train')) ?>">practise these openings in the trainer</a> ·
        <a href="<?= $baseEsc . $esc(I18n::url('/best-openings-for-white')) ?>">best openings for White</a> ·
        <a href="<?= $baseEsc . $esc(I18n::url('/best-openings-for-black')) ?>">for Black</a> ·
        <a href="<?= $baseEsc . $esc(I18n::url('/gambits')) ?>">chess gambits</a>
    </p>
</article>
<?php
$body        = ob_get_clean();
$title       = 'How to Play Against Popular Chess Openings: Best Answers';
$description = 'The answer that scores best against each of ' . $count . ' popular chess openings in rated Lichess games, the most played one and Stockfish\'s first choice.';
$canonical   = $siteUrl . $baseUrl . I18n::url('/' . $page);
$noindex     = $count === 0;
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'       => 'CollectionPage',
            'name'        => 'How to play against popular openings',
            'url'         => $canonical,
            'description' => $description,
            'inLanguage'  => I18n::locale(),
            'mainEntity'  => [
                '@type'           => 'ItemList',
                'numberOfItems'   => $count,
                'itemListElement' => array_map(static fn (int $i, array $r): array => [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'name'     => $r['name'],
                    'url'      => $siteUrl . $baseUrl . I18n::url('/openings/' . $r['slug']),
                ], array_keys($all), $all),
            ],
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => t('site.name'), 'item' => $siteUrl . $baseUrl . I18n::url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'How to play against popular openings', 'item' => $canonical],
            ],
        ],
    ],
];
require __DIR__ . '/layout.php';
