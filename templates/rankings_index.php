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
    <ul class="rankings-cards">
        <?php foreach (Rankings::LABELS as $path => $label):
            $side = preg_match('/^best-openings-for-(white|black)$/', $path, $m) ? $m[1] : null; ?>
            <li class="rankings-card">
                <a class="rankings-card-title" href="<?= $baseEsc . $esc(I18n::url('/' . $path)) ?>"><?= $esc($label) ?> →</a>
                <p><?= $esc(Rankings::BLURBS[$path]) ?></p>
                <?php if ($side): ?>
                    <p class="rankings-card-levels"><span>By level:</span>
                        <?php foreach (LevelStats::LEVELS as $key => $l): ?>
                            <a href="<?= $baseEsc . $esc(I18n::url('/best-openings-for-' . $side . '/' . $key)) ?>"><?= $esc($l['label']) ?></a>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>
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
