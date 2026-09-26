<?php
/** @var array  $families  Letter => [family => ['name', 'slug', 'eco', 'lines']] */
/** @var array  $counts    Letter => named lines under it */
/** @var int    $total */
/** @var string $baseUrl */
/** @var string $siteUrl */
$esc     = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$baseEsc = $esc($baseUrl);
$letterUrl = static fn (string $l): string => $baseEsc . $esc(I18n::url('/openings/letter/' . strtolower($l)));
$familyCount = array_sum(array_map('count', $families));
ob_start();
?>
<article class="openings-index">
    <header class="openings-index-header">
        <h1>List of chess openings A–Z</h1>
        <p class="lede">
            All <?= $familyCount ?> chess openings, with the <?= number_format($total) ?> named lines filed under them.
            Pick an opening, or open a letter for every named line. Or use
            <a href="<?= $baseEsc . $esc(I18n::url('/search')) ?>">search</a>
            if you know the moves, browse by
            <a href="<?= $baseEsc . $esc(I18n::url('/eco')) ?>">ECO code</a>, or see the
            <a href="<?= $baseEsc . $esc(I18n::url('/rankings')) ?>">rankings from Lichess games</a>.
        </p>
        <nav class="openings-jump" aria-label="Every named line, by letter">
            <?php foreach ($counts as $letter => $n): ?>
                <a href="<?= $letterUrl((string) $letter) ?>">
                    <?= $esc($letter) ?>
                    <span class="openings-jump-count"><?= $n ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </header>

    <?php foreach ($families as $letter => $list): ?>
        <section class="openings-letter" id="letter-<?= $esc($letter) ?>">
            <h2><?= $esc($letter) ?>
                <a class="openings-letter-count" href="<?= $letterUrl((string) $letter) ?>">all <?= $counts[$letter] ?> lines →</a>
            </h2>
            <ul class="openings-letter-list">
                <?php foreach ($list as $f): ?>
                    <li>
                        <a href="<?= $baseEsc . $esc(I18n::url('/openings/' . $f['slug'])) ?>">
                            <span class="eco-tag"><?= $esc($f['eco']) ?></span>
                            <span class="openings-letter-name"><?= $esc($f['name']) ?></span>
                            <span class="openings-letter-plies"><?= $f['lines'] === 1 ? '1 line' : $f['lines'] . ' lines' ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</article>
<?php
$body = ob_get_clean();
$title = 'List of Chess Openings A–Z: ' . $familyCount . ' Openings, ' . number_format($total) . ' Named Lines';
$description = 'All ' . $familyCount . ' chess openings from A to Z, with the ' . number_format($total)
             . ' named variations filed under them — each with its moves, ECO code and Lichess win rates.';
$canonical = $siteUrl . $baseUrl . I18n::url('/openings');
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'CollectionPage',
    'name'     => 'List of chess openings A–Z',
    'url'      => $canonical,
    'description' => $description,
    'inLanguage'  => I18n::locale(),
];
require __DIR__ . '/layout.php';
