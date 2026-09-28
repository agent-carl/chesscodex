<?php
/** @var array  $codes  Opening::ecoCodes(): code => [count, label, slug, moves] */
/** @var string $baseUrl */
/** @var string $siteUrl */
$baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
$esc     = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$total   = array_sum(array_column($codes, 'count'));
ob_start();
?>
<article class="openings-index">
    <header class="openings-index-header">
        <h1>ECO codes · A00–E99</h1>
        <p class="lede">
            The ECO system files every chess opening under one of 500 codes in five volumes.
            Here are all of them with the <?= number_format($total) ?> named lines behind them —
            or browse the <a href="<?= $baseEsc . $esc(I18n::url('/openings')) ?>">openings A–Z</a>.
        </p>
        <nav class="openings-jump" aria-label="Jump to volume">
            <?php foreach (['A', 'B', 'C', 'D', 'E'] as $vol): ?>
                <a href="#volume-<?= $vol ?>"><?= $vol ?>
                    <span class="openings-jump-count"><?= $esc(t('group.' . $vol)) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </header>

    <?php foreach (['A', 'B', 'C', 'D', 'E'] as $vol): ?>
        <section class="openings-letter" id="volume-<?= $vol ?>">
            <h2><?= $vol ?> · <?= $esc(t('group.' . $vol)) ?>
                <span class="openings-letter-count"><?= $vol ?>00–<?= $vol ?>99</span>
            </h2>
            <ul class="openings-letter-list eco-code-list">
                <?php for ($i = 0; $i < 100; $i++):
                    $code = sprintf('%s%02d', $vol, $i);
                    $c = $codes[$code] ?? null;
                ?>
                    <li>
                        <?php if ($c): ?>
                        <?php /* The moves tell apart the codes that share a name (A13–A39 are all "English Opening"). */ ?>
                        <a href="<?= $baseEsc . $esc(I18n::url('/eco/' . $code)) ?>">
                            <span class="eco-tag"><?= $code ?></span>
                            <span class="eco-code-text">
                                <span class="openings-letter-name"><?= $esc($c['label']) ?></span>
                                <span class="eco-code-meta"><code><?= $esc(Opening::keepNumbers((string) ($c['moves'] ?? ''))) ?></code> · <?= $c['count'] === 1 ? '1 line' : $c['count'] . ' lines' ?></span>
                            </span>
                        </a>
                        <?php else: ?>
                        <span class="eco-tag"><?= $code ?></span>
                        <span class="openings-letter-name">No named lines</span>
                        <?php endif; ?>
                    </li>
                <?php endfor; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</article>
<?php
$body = ob_get_clean();
$title = 'ECO Codes: Every Chess Opening Code from A00 to E99';
$description = 'All 500 ECO codes from A00 to E99 with the openings filed under each — '
             . number_format($total) . ' named lines with moves, interactive boards and Lichess win rates.';
$canonical = $siteUrl . $baseUrl . I18n::url('/eco');
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'       => 'CollectionPage',
            'name'        => 'ECO codes A00–E99',
            'url'         => $canonical,
            'description' => $description,
            'inLanguage'  => I18n::locale(),
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => t('site.name'), 'item' => $siteUrl . $baseUrl . I18n::url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'ECO codes', 'item' => $canonical],
            ],
        ],
    ],
];
require __DIR__ . '/layout.php';
