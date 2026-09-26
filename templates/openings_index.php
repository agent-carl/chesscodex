<?php
/** @var array  $grouped  Letter => rows[] */
/** @var int    $total */
/** @var string $baseUrl */
/** @var string $siteUrl */
$baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
ob_start();
?>
<article class="openings-index">
    <header class="openings-index-header">
        <h1>All openings · A–Z</h1>
        <p class="lede">
            <?= number_format($total) ?> openings indexed.
            Jump to any letter, or use
            <a href="<?= $baseEsc . htmlspecialchars(I18n::url('/search'), ENT_QUOTES, 'UTF-8') ?>">search</a>
            if you know the moves, or browse by
            <a href="<?= $baseEsc . htmlspecialchars(I18n::url('/eco'), ENT_QUOTES, 'UTF-8') ?>">ECO code</a>.
        </p>
        <nav class="openings-jump" aria-label="Jump to letter">
            <?php foreach ($grouped as $letter => $rows): ?>
                <a href="#letter-<?= htmlspecialchars((string) $letter, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string) $letter, ENT_QUOTES, 'UTF-8') ?>
                    <span class="openings-jump-count"><?= count($rows) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </header>

    <?php foreach ($grouped as $letter => $rows): ?>
        <section class="openings-letter" id="letter-<?= htmlspecialchars((string) $letter, ENT_QUOTES, 'UTF-8') ?>">
            <h2><?= htmlspecialchars((string) $letter, ENT_QUOTES, 'UTF-8') ?>
                <span class="openings-letter-count"><?= count($rows) ?> openings</span>
            </h2>
            <ul class="openings-letter-list">
                <?php foreach ($rows as $row): ?>
                    <li>
                        <a href="<?= $baseEsc . htmlspecialchars(I18n::url('/openings/' . $row['slug']), ENT_QUOTES, 'UTF-8') ?>">
                            <span class="eco-tag"><?= htmlspecialchars($row['eco'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="openings-letter-name"><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="openings-letter-plies"><?= (int) $row['move_count'] ?>-ply</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</article>
<?php
$body = ob_get_clean();
$title = 'All openings A–Z · Caissa Codex';
$description = 'Alphabetical index of all ' . number_format($total) . ' chess openings indexed in Caissa Codex.';
$canonical = $siteUrl . $baseUrl . I18n::url('/openings');
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'CollectionPage',
    'name'     => 'All chess openings A–Z',
    'url'      => $canonical,
    'description' => $description,
    'inLanguage'  => I18n::locale(),
];
require __DIR__ . '/layout.php';
