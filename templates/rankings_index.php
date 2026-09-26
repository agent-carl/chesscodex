<?php
/** @var string $baseUrl */
/** @var string $siteUrl */
$esc     = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$baseEsc = $esc($baseUrl);
ob_start();
?>
<article class="ranking-page">
    <header>
        <h1>Chess opening rankings</h1>
        <p class="lede">Rankings built from rated Lichess games between players rated 1600 to 2500: which lines score best for each side, which are played most, and the most-played gambits.</p>
    </header>
    <ul class="home-popular-list">
        <?php foreach (Rankings::LABELS as $path => $label): ?>
            <li>
                <a href="<?= $baseEsc . $esc(I18n::url('/' . $path)) ?>">
                    <span class="home-popular-name"><strong><?= $esc($label) ?></strong><br>
                        <small><?= $esc(Rankings::BLURBS[$path]) ?></small></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</article>
<?php
$body        = ob_get_clean();
$title       = 'Chess Opening Rankings from Lichess Games | Caissa Codex';
$description = 'Best chess openings for White and for Black, the most popular openings and the most-played gambits, ranked from rated Lichess games.';
$canonical   = $siteUrl . $baseUrl . I18n::url('/rankings');
$jsonLd = [
    '@context'    => 'https://schema.org',
    '@type'       => 'CollectionPage',
    'name'        => 'Chess opening rankings',
    'url'         => $canonical,
    'description' => $description,
    'inLanguage'  => I18n::locale(),
];
require __DIR__ . '/layout.php';
