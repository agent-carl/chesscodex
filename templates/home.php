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
    <p class="hero-meta"><?= htmlspecialchars(t('home.hero.meta', ['count' => $totalFmt]), ENT_QUOTES, 'UTF-8') ?></p>
    <p class="hero-actions">
        <a class="hero-action-link" href="<?= $baseEsc . htmlspecialchars(I18n::url('/openings'), ENT_QUOTES, 'UTF-8') ?>">Browse all openings A–Z →</a>
        <a class="hero-action-link" href="<?= $baseEsc . htmlspecialchars(I18n::url('/search'), ENT_QUOTES, 'UTF-8') ?>">Search by moves or name →</a>
    </p>
</section>

<!-- Recently viewed openings — JS-populated. Hidden when empty so the
     first-time visitor doesn't see a blank slot. -->
<section class="home-recent" id="home-recent" hidden aria-label="Recently viewed">
    <header class="home-recent-head">
        <h2>Pick up where you left off</h2>
        <button type="button" class="home-recent-clear" id="home-recent-clear" aria-label="Clear recently viewed history">Clear</button>
    </header>
    <ul class="home-recent-list" id="home-recent-list"></ul>
</section>
<?php /* Not deferred: fills the section before the content below is painted. */ ?>
<script src="<?= $baseEsc ?>/public/home.min.js?v=<?= @filemtime(__DIR__ . '/../public/home.min.js') ?: 1 ?>" data-base="<?= $baseEsc ?>"></script>

<?php if ($featured): ?>
<section class="home-featured">
    <header class="home-featured-head">
        <span class="home-featured-tag">Opening of the day</span>
        <span class="home-featured-date"><?= date('F j, Y') ?></span>
    </header>
    <a class="home-featured-card" href="<?= $baseEsc . htmlspecialchars(I18n::url('/openings/' . $featured['slug']), ENT_QUOTES, 'UTF-8') ?>">
        <span class="eco-tag"><?= htmlspecialchars($featured['eco'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="home-featured-name"><?= htmlspecialchars($featured['name'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php if (!empty($featured['description'])):
            // Snippet: first ~200 chars of description, plain-text only.
            $snippet = strip_tags((string) $featured['description']);
            $snippet = preg_replace('/\s+/', ' ', $snippet);
            if (mb_strlen($snippet) > 200) $snippet = rtrim(mb_substr($snippet, 0, 200), ' .,;:-') . '…';
        ?>
            <span class="home-featured-snippet"><?= htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
        <span class="home-featured-cta">Study this opening →</span>
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
$renderStrip = static function (string $h2, string $lede, string $modifier, array $rows) use ($baseEsc) {
    if (empty($rows)) return; ?>
    <section class="home-popular <?= $modifier ?>">
        <header class="home-popular-head">
            <h2><?= htmlspecialchars($h2, ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="home-popular-lede"><?= htmlspecialchars($lede, ENT_QUOTES, 'UTF-8') ?></p>
        </header>
        <ul class="home-popular-list">
            <?php foreach ($rows as $p): ?>
                <li>
                    <a href="<?= $baseEsc . htmlspecialchars(I18n::url('/openings/' . $p['slug']), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="eco-tag"><?= htmlspecialchars($p['eco'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="home-popular-name"><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="home-popular-plies"><?= (int) $p['move_count'] ?>-ply</span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php };

$renderStrip(
    'Most popular openings',
    'The lines you have probably heard of — start here if you do not know where to look.',
    'home-popular-classic',
    $popular
);
$renderStrip(
    'Famous gambits',
    'Sharp, sacrificial openings where one side gives up material for initiative.',
    'home-popular-gambits',
    $gambits
);
?>
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
            'url'         => $canonical,
            'description' => $description,
            'inLanguage'  => I18n::locale(),
            'publisher'   => $publisherLd,
            // SearchAction is the schema that gets Google to render an
            // in-result sitelinks search-box for the homepage.
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => $siteUrl . $baseUrl . I18n::url('/search') . '?moves={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
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
