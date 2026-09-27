<?php
/** @var array $groups */
/** @var array $popular */
/** @var array $gambits */
/** @var array|null $featured  Opening-of-the-day, deterministic per date. */
/** @var string $baseUrl */
/** @var string $siteUrl */
ob_start();

$total = 0;
foreach ($groups as $g) $total += (int) $g['count'];
$totalFmt = number_format($total);
$baseEsc  = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
?>
<section class="hero">
    <h1><?= htmlspecialchars(t('home.hero.h1'), ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="lede"><?= htmlspecialchars(t('home.hero.lede'), ENT_QUOTES, 'UTF-8') ?></p>
    <?php /* The name search, with suggestions from site.js; without JavaScript
             the form opens /search with the words filled in. */ ?>
    <form class="hero-search" action="<?= $baseEsc . htmlspecialchars(I18n::url('/search'), ENT_QUOTES, 'UTF-8') ?>" method="get" role="search">
        <?php $nameSearchId = 'hero-search'; require __DIR__ . '/partials/name_search.php'; ?>
    </form>
    <p class="hero-meta"><?= htmlspecialchars(t('home.hero.meta', ['count' => $totalFmt]), ENT_QUOTES, 'UTF-8') ?></p>
    <p class="hero-actions">
        <a class="hero-action-link" href="<?= $baseEsc . htmlspecialchars(I18n::url('/openings'), ENT_QUOTES, 'UTF-8') ?>">Browse all openings A–Z →</a>
        <a class="hero-action-link" href="<?= $baseEsc . htmlspecialchars(I18n::url('/search'), ENT_QUOTES, 'UTF-8') ?>">Identify an opening from moves →</a>
        <a class="hero-action-link" href="<?= $baseEsc . htmlspecialchars(I18n::url('/eco'), ENT_QUOTES, 'UTF-8') ?>">Browse by ECO code →</a>
        <a class="hero-action-link" href="<?= $baseEsc . htmlspecialchars(I18n::url('/random'), ENT_QUOTES, 'UTF-8') ?>" rel="nofollow">A random opening →</a>
    </p>
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

<?php if ($featured): ?>
<section class="home-featured">
    <header class="home-featured-head">
        <h2 class="section-label">Opening of the day</h2>
        <span class="home-featured-date"><?= date('F j, Y') ?></span>
    </header>
    <a class="home-featured-card" href="<?= $baseEsc . htmlspecialchars(I18n::url('/openings/' . $featured['slug']), ENT_QUOTES, 'UTF-8') ?>">
        <img class="home-featured-diagram" src="<?= $baseEsc . htmlspecialchars(Opening::diagramPath((string) $featured['slug'], true), ENT_QUOTES, 'UTF-8') ?>"
             width="720" height="720" alt="" decoding="async">
        <span class="home-featured-body">
        <span class="home-featured-title">
            <span class="eco-tag"><?= htmlspecialchars($featured['eco'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="home-featured-name"><?= htmlspecialchars($featured['name'], ENT_QUOTES, 'UTF-8') ?></span>
        </span>
        <code class="home-featured-moves"><?= htmlspecialchars(trim((string) ($featured['pgn_moves'] ?? '')), ENT_QUOTES, 'UTF-8') ?></code>
        <?php if (!empty($featured['description'])):
            // Snippet: first ~200 chars of the description's first paragraph,
            // as plain text — the description is Markdown, so drop link
            // targets ("[x](/y)" → "x"), emphasis, code ticks and headings.
            $snippet = preg_split('/\n\s*\n/', trim(strip_tags((string) $featured['description'])))[0];
            $snippet = preg_replace(['/!?\[([^\]]*)\]\([^)]*\)/', '/(\*\*|__|\*|`)/', '/^#+\s*/m'], ['$1', '', ''], $snippet);
            $snippet = preg_replace('/\s+/', ' ', $snippet);
            if (mb_strlen($snippet) > 200) $snippet = rtrim(mb_substr($snippet, 0, 200), ' .,;:-') . '…';
        ?>
            <span class="home-featured-snippet"><?= htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
        <span class="home-featured-cta">Study this opening →</span>
        </span>
    </a>
</section>
<?php endif; ?>

<div class="group-grid">
<?php foreach ($groups as $g): ?>
    <?php $href = $g['root_slug']
        ? $baseUrl . I18n::url('/openings/' . $g['root_slug'])
        : null; ?>
    <a class="group-card<?= $href ? '' : ' is-empty' ?>"
       <?php if ($href): ?>href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"<?php endif ?>>
        <span class="group-letter"><?= htmlspecialchars($g['letter'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="group-label"><?= htmlspecialchars($g['label'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="group-count"><?= htmlspecialchars(t('home.card.openings', ['count' => number_format((int) $g['count'])]), ENT_QUOTES, 'UTF-8') ?></span>
    </a>
<?php endforeach; ?>
</div>

<?php
// Helper — same DOM/CSS for "famous openings" and "famous gambits" strips.
$renderStrip = static function (string $h2, string $lede, string $modifier, array $rows, string $moreUrl, string $moreText) use ($baseEsc) {
    if (empty($rows)) return; ?>
    <section class="home-popular <?= $modifier ?>">
        <header class="home-popular-head">
            <h2><?= htmlspecialchars($h2, ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="home-popular-lede"><?= htmlspecialchars($lede, ENT_QUOTES, 'UTF-8') ?></p>
        </header>
        <ul class="home-popular-list">
            <?php foreach ($rows as $p):
                $pos = strpos((string) $p['name'], ': ');
                [$kicker, $cardName] = $pos === false ? ['', (string) $p['name']]
                    : [substr((string) $p['name'], 0, $pos), substr((string) $p['name'], $pos + 2)]; ?>
                <li>
                    <a href="<?= $baseEsc . htmlspecialchars(I18n::url('/openings/' . $p['slug']), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="eco-tag"><?= htmlspecialchars($p['eco'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="home-popular-text">
                            <span class="home-popular-name"><?= htmlspecialchars($cardName, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if ($kicker !== ''): ?><span class="home-popular-kicker"><?= htmlspecialchars($kicker, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                            <span class="home-popular-moves"><?= htmlspecialchars(Opening::movesFrom((string) $p['pgn_moves'], 0), ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="home-popular-more"><a href="<?= $baseEsc . htmlspecialchars(I18n::url($moreUrl), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($moreText, ENT_QUOTES, 'UTF-8') ?> →</a></p>
    </section>
<?php };

$renderStrip(
    'Famous openings',
    'The lines you have probably heard of — start here if you do not know where to look.',
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
?>

<section class="home-popular home-rankings">
    <header class="home-popular-head">
        <h2>Rankings from Lichess games</h2>
        <p class="home-popular-lede">Which openings score best for each side, which are played most, and the most-played gambits.</p>
    </header>
    <ul class="home-rankings-list">
        <?php foreach (Rankings::LABELS as $path => $label): ?>
            <li>
                <a href="<?= $baseEsc . htmlspecialchars(I18n::url('/' . $path), ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> →
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="about-section home-intro">
    <h2>How the openings are organized</h2>
    <p>
        Every opening here carries a code from the
        <a href="<?= $baseEsc . htmlspecialchars(I18n::url('/eco'), ENT_QUOTES, 'UTF-8') ?>">ECO system</a>, which files chess openings into
        five volumes: <strong>A</strong> — flank openings such as the English and the Réti, plus
        the Dutch and the Benoni; <strong>B</strong> — semi-open games such as the Sicilian, the
        Caro-Kann and the Pirc; <strong>C</strong> — open games after 1.e4 e5, plus the French
        Defense; <strong>D</strong> — closed and semi-closed games after 1.d4 d5, such as the
        Queen's Gambit, plus the Grünfeld; <strong>E</strong> — Indian defenses such as the
        Catalan, the Nimzo-Indian and the King's Indian.
    </p>
    <p>
        Names and move orders come from the Lichess chess-openings dataset:
        <?= htmlspecialchars($totalFmt, ENT_QUOTES, 'UTF-8') ?> named lines spread over almost all of
        the 500 ECO codes. Each
        opening page shows the moves on an interactive board, where the line sits in the opening
        tree, its sub-variations, and how it scores in Lichess games between players rated 1600 to
        2500 — so you can see how an opening actually does, not just how it starts.
    </p>
    <p>
        Know the moves but not the name?
        <a href="<?= $baseEsc . htmlspecialchars(I18n::url('/search'), ENT_QUOTES, 'UTF-8') ?>">Search by moves or by FEN</a>.
        Want to practice? Every line can be drilled from memory, or played out against Stockfish at
        six strengths, right in your browser.
    </p>
</section>
<?php
$body = ob_get_clean();
$title = t('home.title');
$description = t('home.description', ['count' => $totalFmt]);
$canonical = $siteUrl . $baseUrl . I18n::url('/');
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
            'inLanguage'  => I18n::locale(),
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
