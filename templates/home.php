<?php
/** @var array $groups */
/** @var array $popular */
/** @var array $gambits */
/** @var array $firstMoves  Opening::firstMoveTree() */
/** @var array|null $featured  Opening-of-the-day, deterministic per date. */
/** @var string $baseUrl */
/** @var string $siteUrl */
ob_start();

$total = 0;
foreach ($groups as $g) $total += (int) $g['count'];
$totalFmt = number_format($total);
$baseEsc  = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
?>
<?php /* The masthead opens with the job (find a line by name, moves or code)
         and the most characteristic thing in the subject: a position on the
         board, today's opening. */ ?>
<section class="masthead">
    <div class="masthead-text">
        <h1><?= htmlspecialchars(t('home.hero.h1'), ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="masthead-lede"><?= htmlspecialchars(t('home.hero.lede', ['count' => $totalFmt]), ENT_QUOTES, 'UTF-8') ?></p>
        <?php /* The name search, with suggestions from site.js; without JavaScript
                 the form opens /search with the words filled in. */ ?>
        <form class="masthead-search" action="<?= $baseEsc . htmlspecialchars('/search', ENT_QUOTES, 'UTF-8') ?>" method="get" role="search">
            <?php $nameSearchId = 'hero-search'; require __DIR__ . '/partials/name_search.php'; ?>
        </form>
        <?php /* Ways in besides the search, right under it (a directory's
                 "search, then categories"), in the words people search with. */ ?>
        <nav class="masthead-browse" aria-label="Browse openings">
            <?php foreach ([
                ['/openings', 'List of chess openings A–Z', $totalFmt . ' named lines'],
                ['/eco', 'ECO codes', 'A00 to E99'],
                ['/popular-openings', 'Most popular openings', 'top 100 on Lichess'],
            ] as [$href, $label, $note]): ?>
            <a href="<?= $baseEsc . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
                <span class="masthead-browse-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="masthead-browse-note"><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
    </div>
    <?php if ($featured): ?>
    <a class="masthead-board" href="<?= $baseEsc . htmlspecialchars('/openings/' . $featured['slug'], ENT_QUOTES, 'UTF-8') ?>">
        <img src="<?= $baseEsc . htmlspecialchars(Opening::diagramPath((string) $featured['slug'], true), ENT_QUOTES, 'UTF-8') ?>"
             width="720" height="720" alt="Chess diagram: <?= htmlspecialchars($featured['name'], ENT_QUOTES, 'UTF-8') ?>" decoding="async">
        <span class="masthead-board-caption">
            <span class="masthead-board-label">Opening of the day, <?= date('F j') ?></span>
            <span class="masthead-board-name"><?= htmlspecialchars($featured['name'], ENT_QUOTES, 'UTF-8') ?> <span class="eco-tag"><?= htmlspecialchars($featured['eco'], ENT_QUOTES, 'UTF-8') ?></span></span>
            <span class="masthead-board-moves"><?= htmlspecialchars(Opening::keepNumbers(trim((string) ($featured['pgn_moves'] ?? ''))), ENT_QUOTES, 'UTF-8') ?></span>
        </span>
    </a>
    <?php endif; ?>
</section>

<!-- Recently viewed openings — JS-populated. Hidden when empty so the
     first-time visitor doesn't see a blank slot. -->
<section class="home-recent" id="home-recent" hidden aria-label="Recently viewed">
    <header class="home-recent-head">
        <h2 class="section-label">Pick up where you left off</h2>
        <button type="button" class="home-recent-clear" id="home-recent-clear" aria-label="Clear recently viewed history">Clear</button>
    </header>
    <ul class="home-recent-list" id="home-recent-list"></ul>
</section>
<?php /* Not deferred: fills the section before the content below is painted. */ ?>
<script src="<?= $baseEsc ?>/public/home.min.js?v=<?= @filemtime(__DIR__ . '/../public/home.min.js') ?: 1 ?>" data-base="<?= $baseEsc ?>"></script>

<?php /* The ECO classification as the five volumes it was printed in: each
         spine's height is its share of the lines, standing on one shelf. */
$maxGroup = max(array_map(static fn (array $g): int => (int) $g['count'], $groups) ?: [1]); ?>
<section class="shelf" aria-labelledby="shelf-title">
    <h2 id="shelf-title">The five ECO volumes</h2>
    <ol class="shelf-list">
    <?php foreach ($groups as $g):
        $href = $g['root_slug'] ? $baseUrl . '/openings/' . $g['root_slug'] : null; ?>
        <li style="--size: <?= round((int) $g['count'] / max(1, $maxGroup), 3) ?>">
            <<?= $href ? 'a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' : 'span' ?> class="shelf-volume">
                <span class="shelf-letter"><?= htmlspecialchars($g['letter'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="shelf-label"><?= htmlspecialchars($g['label'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="shelf-count"><?= htmlspecialchars(t('home.card.openings', ['count' => number_format((int) $g['count'])]), ENT_QUOTES, 'UTF-8') ?></span>
            </<?= $href ? 'a' : 'span' ?>>
        </li>
    <?php endforeach; ?>
    </ol>
</section>

<?php
// Helper — same DOM/CSS for "famous openings" and "famous gambits" strips.
$renderStrip = static function (string $h2, string $lede, string $modifier, array $rows, string $moreUrl, string $moreText) use ($baseEsc) {
    if (empty($rows)) return; ?>
    <section class="home-popular <?= $modifier ?>">
        <header class="home-popular-head">
            <h2><?= htmlspecialchars($h2, ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="home-popular-lede"><?= htmlspecialchars($lede, ENT_QUOTES, 'UTF-8') ?></p>
        </header>
        <ul class="index-list">
            <?php foreach ($rows as $p):
                $pos = strpos((string) $p['name'], ': ');
                [$family, $rest] = $pos === false ? ['', (string) $p['name']]
                    : [substr((string) $p['name'], 0, $pos + 1), substr((string) $p['name'], $pos + 2)]; ?>
                <li>
                    <a href="<?= $baseEsc . htmlspecialchars('/openings/' . $p['slug'], ENT_QUOTES, 'UTF-8') ?>">
                        <span class="eco-tag"><?= htmlspecialchars($p['eco'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="index-name"><?php if ($family !== ''): ?><span class="index-family"><?= htmlspecialchars($family, ENT_QUOTES, 'UTF-8') ?></span> <?php endif; ?><?= htmlspecialchars($rest, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="index-leader" aria-hidden="true"></span>
                        <span class="index-moves"><?= htmlspecialchars(Opening::keepNumbers(Opening::movesFrom((string) $p['pgn_moves'], 0)), ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="home-popular-more"><a href="<?= $baseEsc . htmlspecialchars($moreUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($moreText, ENT_QUOTES, 'UTF-8') ?></a></p>
    </section>
<?php };

$renderStrip(
    'Famous openings',
    'The lines you have probably heard of. Start here if you do not know where to look.',
    'home-popular-classic',
    $popular,
    '/popular-openings',
    'The 100 most-played openings'
);
$renderStrip(
    'Famous gambits',
    'Sharp, sacrificial openings where one side gives up material for initiative.',
    'home-popular-gambits',
    $gambits,
    '/gambits',
    'The 100 most-played gambits'
);

// One step of the first-move tree: its move (number muted), its name — in a
// chip only what the step above doesn't already say, in a row the whole name,
// muted when it is the same — and its share of the games after the step above.
$fmPct = static function (float $share): string {
    $p = round($share * 100, 1);
    return ($p >= 10 ? (string) round($p) : ($p >= 0.1 ? number_format($p, 1) : '<0.1')) . '%';
};
$fmLink = static function (array $n, string $class, bool $withPct = false) use ($baseEsc, $fmPct): string {
    $esc  = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $name = match (true) {
        $n['label'] !== ''     => ' <span class="fm-name">' . $esc($n['label']) . '</span>',
        $class === 'fm-link'   => ' <span class="fm-name is-same">' . $esc($n['name']) . '</span>',
        default                => '<span class="visually-hidden"> ' . $esc($n['name']) . '</span>',
    };
    return '<a class="' . $class . '" href="' . $baseEsc . $esc('/openings/' . $n['slug']) . '"'
        . ($n['label'] === '' && $class !== 'fm-link' ? ' title="' . $esc($n['name']) . '"' : '') . '>'
        . '<span class="fm-move"><span class="fm-no">' . $esc($n['no']) . '</span>' . $esc($n['move']) . '</span>'
        . $name . ($withPct ? ' <span class="fm-pct">' . $esc($fmPct($n['share'])) . '</span>' : '') . '</a>';
};
// The first move's bar is a part of all games (on a track); the replies' bars
// compare them with the most played one, which fills its bar.
$fmBar = static fn (float $width, string $class = 'fm-bar'): string =>
    '<span class="' . $class . '" aria-hidden="true"><i style="width:' . round($width * 100, 1) . '%"></i></span>';
?>
<?php if (!empty($firstMoves['main'])): ?>
<section class="home-popular home-first-moves">
    <header class="home-popular-head">
        <h2>Openings by first move</h2>
        <p class="home-popular-lede">White's most played first moves, Black's main replies and White's main second moves, with the share of the games each one gets on Lichess.</p>
    </header>
    <div class="first-moves">
        <?php foreach ($firstMoves['main'] as $first):
            $topReply = max(array_column($first['replies'], 'share') ?: [1]); ?>
            <div class="first-move">
                <h3 class="fm-head"><?= $fmLink($first, 'fm-head-link') ?>
                    <span class="fm-pct"><?= htmlspecialchars($fmPct($first['share']), ENT_QUOTES, 'UTF-8') ?><span class="visually-hidden"> of all games</span></span></h3>
                <?= $fmBar($first['share'], 'fm-bar is-head') ?>
                <ul class="fm-replies">
                    <?php foreach ($first['replies'] as $reply): ?>
                        <li>
                            <div class="fm-row"><?= $fmLink($reply, 'fm-link') ?><?= $fmBar($reply['share'] / $topReply) ?>
                                <span class="fm-pct"><?= htmlspecialchars($fmPct($reply['share']), ENT_QUOTES, 'UTF-8') ?></span></div>
                            <?php if ($reply['seconds']): ?>
                                <ul class="fm-chips">
                                    <?php foreach ($reply['seconds'] as $second): ?>
                                        <li><?= $fmLink($second, 'fm-chip', true) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($firstMoves['other']): ?>
        <div class="first-moves-other">
            <h3>Other first moves</h3>
            <ul class="fm-chips">
                <?php foreach ($firstMoves['other'] as $other): ?>
                    <li><?= $fmLink($other, 'fm-chip', true) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <p class="first-moves-note">A first move's percentage is its share of all games; a reply's or a second move's, its share
        of the games that reach the move above it. Rated Lichess games between players rated 1600 to 2500.
        <a href="<?= $baseEsc . htmlspecialchars('/opening-trends', ENT_QUOTES, 'UTF-8') ?>">Opening trends since <?= YearStats::FIRST_YEAR ?></a></p>
</section>
<?php endif; ?>

<section class="home-popular home-rankings">
    <header class="home-popular-head">
        <h2>Rankings from Lichess games</h2>
        <p class="home-popular-lede">Which openings score best for each side, which are played most and which more than ten years ago, and the most-played gambits.</p>
    </header>
    <ul class="index-list index-list-plain">
        <?php foreach (Rankings::LABELS as $path => $label): ?>
            <li>
                <a href="<?= $baseEsc . htmlspecialchars('/' . $path, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="about-section home-intro">
    <h2>How the openings are organized</h2>
    <p>
        Every opening here carries one of the
        <a href="<?= $baseEsc . htmlspecialchars('/eco', ENT_QUOTES, 'UTF-8') ?>">ECO codes</a>, a system that files chess openings into
        five volumes: <strong>A</strong>, flank openings such as the English and the Réti, plus
        the Dutch and the Benoni; <strong>B</strong>, semi-open games such as the Sicilian, the
        Caro-Kann and the Pirc; <strong>C</strong>, open games after 1.e4 e5, plus the French
        Defense; <strong>D</strong>, closed and semi-closed games after 1.d4 d5, such as the
        Queen's Gambit, plus the Grünfeld; <strong>E</strong>, Indian defenses such as the
        Catalan, the Nimzo-Indian and the King's Indian.
    </p>
    <p>
        Names and move orders come from the Lichess chess-openings dataset:
        <?= htmlspecialchars($totalFmt, ENT_QUOTES, 'UTF-8') ?> named lines spread over almost all of
        the 500 ECO codes. Each
        opening page shows the moves on an interactive board, where the line sits in the opening
        tree, its sub-variations, and how it scores in Lichess games between players rated 1600 to
        2500, so you can see how an opening actually does, not just how it starts.
    </p>
    <p>
        Know the moves but not the name?
        <a href="<?= $baseEsc . htmlspecialchars('/search', ENT_QUOTES, 'UTF-8') ?>">Search by moves or by FEN</a>.
        Want to practice? Every line can be drilled from memory, or played out against Stockfish at
        six strengths, right in your browser.
    </p>
</section>
<?php
$body = ob_get_clean();
$title = t('home.title');
$description = t('home.description', ['count' => $totalFmt]);
$canonical = $siteUrl . $baseUrl . '/';
$publisherLd = [
    '@type' => 'Organization',
    'name'  => t('site.name'),
    'url'   => $canonical,
    'logo'  => [
        '@type' => 'ImageObject',
        'url'   => $siteUrl . $baseUrl . '/public/icon-512.png',
    ],
];
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'       => 'WebSite',
            'name'        => t('site.name'),
            // Google's fallback site name if it doesn't take the main one.
            'alternateName' => ['ChessCodex.org'],
            'url'         => $canonical,
            'description' => $description,
            'inLanguage'  => 'en',
            'publisher'   => $publisherLd,
            // No SearchAction: Google retired the sitelinks search box it fed.
        ],
        [
            '@type'        => 'Organization',
            'name'         => t('site.name'),
            'url'          => $canonical,
            'logo'         => $siteUrl . $baseUrl . '/public/icon-512.png',
        ],
    ],
];
require __DIR__ . '/layout.php';
