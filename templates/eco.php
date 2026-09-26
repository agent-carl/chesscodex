<?php
/** @var string      $code      e.g. "B20" */
/** @var array       $info      Opening::ecoCodes()[$code]: count, label, slug */
/** @var array       $lines     Opening::byEco($code), shortest first */
/** @var string|null $prevCode */
/** @var string|null $nextCode */
/** @var string $baseUrl */
/** @var string $siteUrl */
$baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
$esc     = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$openingUrl = static fn (string $slug): string => $baseUrl . I18n::url('/openings/' . $slug);

$vol      = $code[0];
$n        = count($lines);
$root     = $lines[0];
$families = count(array_unique(array_map([Opening::class, 'family'], array_column($lines, 'name'))));

// Lichess numbers for the shortest line's position, when cached (never
// fetched from here: this page must not wait on Lichess).
require_once __DIR__ . '/../lib/ChessEngine.php';
require_once __DIR__ . '/../lib/StatsCache.php';
$rootStats = StatsCache::cached(ChessEngine::fromPgn((string) $root['pgn_moves'])->uciHistory());
$rootGames = $rootStats ? $rootStats['white'] + $rootStats['black'] + $rootStats['draws'] : 0;
$pct = static fn (int $x): string => number_format($rootGames > 0 ? $x * 100 / $rootGames : 0, 1);

ob_start();
?>
<article class="eco-page">
    <p class="eco-back"><a href="<?= $baseEsc . $esc(I18n::url('/eco')) ?>#volume-<?= $vol ?>">← All ECO codes</a></p>
    <h1>ECO <?= $code ?>: <?= $esc($info['label']) ?></h1>

    <section class="about-section">
        <p>
            ECO <?= $code ?> is part of volume <?= $vol ?> — <?= $esc(t('group.' . $vol)) ?>.
            The Lichess chess-openings dataset files <?= $n === 1 ? 'one named line' : $n . ' named lines' ?>
            under it<?= $families > 1 ? ', from ' . $families . ' different openings' : '' ?>.
            <?= $n === 1 ? 'It is' : 'The shortest is' ?>
            <a href="<?= $esc($openingUrl($root['slug'])) ?>"><?= $esc($root['name']) ?></a>:
            <code><?= $esc($root['pgn_moves']) ?></code>.
        </p>
        <?php if ($rootGames > 0): ?>
        <p>
            That position has come up in <?= number_format($rootGames) ?> Lichess games between
            players rated 1600 to 2500: White won <?= $pct($rootStats['white']) ?>%,
            <?= $pct($rootStats['draws']) ?>% were drawn and Black won <?= $pct($rootStats['black']) ?>%.
        </p>
        <?php endif; ?>
    </section>

    <?php if ($n > 1): ?>
    <table class="eco-lines">
        <thead>
            <tr><th>Opening</th><th>Moves</th><th>Length</th></tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $l): ?>
            <tr>
                <td><a href="<?= $esc($openingUrl($l['slug'])) ?>"><?= $esc($l['name']) ?></a></td>
                <td><code><?= $esc($l['pgn_moves']) ?></code></td>
                <td><?= Opening::movesLabel((int) $l['move_count']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <nav class="eco-nav" aria-label="Neighbouring ECO codes">
        <?php if ($prevCode): ?>
            <a href="<?= $baseEsc . $esc(I18n::url('/eco/' . $prevCode)) ?>">← ECO <?= $prevCode ?></a>
        <?php else: ?><span></span><?php endif; ?>
        <?php if ($nextCode): ?>
            <a href="<?= $baseEsc . $esc(I18n::url('/eco/' . $nextCode)) ?>">ECO <?= $nextCode ?> →</a>
        <?php endif; ?>
    </nav>
</article>
<?php
$body = ob_get_clean();
$title = 'ECO ' . $code . ': ' . $info['label'] . ($n > 1 ? ' — ' . $n . ' Lines with Moves' : '');
$description = 'ECO ' . $code . ' — ' . $info['label'] . ': '
             . ($n === 1 ? 'one named line, ' : $n . ' named lines starting ')
             . $root['pgn_moves'] . ', each with its moves, an interactive board and Lichess win rates.';
// A code with a single line would only repeat that opening's own page.
$noindex   = $n === 1;
$canonical = $siteUrl . $baseUrl . I18n::url('/eco/' . $code);
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'       => 'CollectionPage',
            'name'        => 'ECO ' . $code . ': ' . $info['label'],
            'url'         => $canonical,
            'description' => $description,
            'inLanguage'  => I18n::locale(),
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => t('site.name'), 'item' => $siteUrl . $baseUrl . I18n::url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'ECO codes', 'item' => $siteUrl . $baseUrl . I18n::url('/eco')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => 'ECO ' . $code, 'item' => $canonical],
            ],
        ],
    ],
];
require __DIR__ . '/layout.php';
