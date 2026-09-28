<?php
/** @var string $letter   Upper-case. */
/** @var array  $rows     The named lines starting with it (Opening::allAlphabetical()). */
/** @var array  $counts   Letter => named lines under it. */
/** @var string $baseUrl */
/** @var string $siteUrl */
$esc     = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$baseEsc = $esc($baseUrl);
$n       = count($rows);
$firstName = Opening::family((string) $rows[0]['name']);
$lastName  = Opening::family((string) $rows[$n - 1]['name']);
ob_start();
?>
<article class="openings-index">
    <header class="openings-index-header">
        <h1>Chess openings starting with <?= $esc($letter) ?></h1>
        <p class="lede">
            <?= number_format($n) ?> named lines<?= $firstName !== $lastName ? ', from ' . $esc($firstName) . ' to ' . $esc($lastName) : '' ?>.
            Back to the <a href="<?= $baseEsc . $esc(I18n::url('/openings')) ?>">list of chess openings A–Z</a>.
        </p>
        <nav class="openings-jump" aria-label="Other letters">
            <?php foreach ($counts as $l => $c): ?>
                <?php if ($l === $letter): ?>
                    <strong aria-current="page"><?= $esc($l) ?> <span class="openings-jump-count"><?= $c ?></span></strong>
                <?php else: ?>
                    <a href="<?= $baseEsc . $esc(I18n::url('/openings/letter/' . strtolower((string) $l))) ?>">
                        <?= $esc($l) ?> <span class="openings-jump-count"><?= $c ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </header>

    <?php
    // Grouped by opening, each line named by what follows the opening's name
    // ("Queen's Gambit Accepted" › "Alekhine Defense") — the full names all
    // start the same way and a list of them is unreadable.
    $byFamily = [];
    foreach ($rows as $row) $byFamily[Opening::family((string) $row['name'])][] = $row;
    $familyId = static fn (string $f): string => 'opening-' . str_replace(' ', '-', Opening::nameKey($f));
    ?>
    <?php if (count($byFamily) > 1): ?>
    <nav class="openings-families" aria-label="Openings starting with <?= $esc($letter) ?>">
        <?php foreach ($byFamily as $family => $lines): ?>
            <a href="#<?= $esc($familyId($family)) ?>"><?= $esc($family) ?> <span class="openings-jump-count"><?= count($lines) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <?php foreach ($byFamily as $family => $lines): ?>
    <section class="openings-letter openings-family" id="<?= $esc($familyId($family)) ?>">
        <h2><?= $esc($family) ?> <span class="openings-letter-count"><?= count($lines) === 1 ? '1 line' : count($lines) . ' lines' ?></span></h2>
        <ul class="openings-letter-list">
            <?php foreach ($lines as $row):
                $name  = (string) $row['name'];
                $label = $name === $family ? $family : ltrim(substr($name, strlen($family)), ':, ');
            ?>
                <li>
                    <a href="<?= $baseEsc . $esc(I18n::url('/openings/' . $row['slug'])) ?>" title="<?= $esc($name . ' (' . $row['eco'] . ')') ?>">
                        <span class="eco-tag"><?= $esc($row['eco']) ?></span>
                        <span class="openings-letter-name"><?= $esc($label) ?><?php if (($row['tail'] ?? '') !== ''): ?>
                            <span class="openings-letter-tail"><?= $esc(Opening::keepNumbers((string) $row['tail'])) ?></span><?php endif; ?></span>
                        <span class="openings-letter-plies"><?= Opening::movesLabel((int) $row['move_count']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endforeach; ?>
</article>
<?php
$body = ob_get_clean();
$title = 'Chess Openings Starting with ' . $letter . ': ' . number_format($n) . ' Named Lines | Caissa Codex';
$description = 'All ' . number_format($n) . ' named chess openings and variations starting with ' . $letter
             . ($firstName !== $lastName ? ', from ' . $firstName . ' to ' . $lastName : '')
             . ' — moves, ECO codes and Lichess win rates.';
$canonical = $siteUrl . $baseUrl . I18n::url('/openings/letter/' . strtolower($letter));
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'       => 'CollectionPage',
            'name'        => 'Chess openings starting with ' . $letter,
            'url'         => $canonical,
            'description' => $description,
            'inLanguage'  => I18n::locale(),
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => t('site.name'), 'item' => $siteUrl . $baseUrl . I18n::url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'List of chess openings', 'item' => $siteUrl . $baseUrl . I18n::url('/openings')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => 'Starting with ' . $letter, 'item' => $canonical],
            ],
        ],
    ],
];
require __DIR__ . '/layout.php';
