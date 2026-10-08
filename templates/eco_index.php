<?php
/** @var array  $codes  Opening::ecoCodes(): code => [count, label, slug, moves] */
/** @var string $baseUrl */
/** @var string $siteUrl */
$baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
$esc     = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$total   = array_sum(array_column($codes, 'count'));
$all     = [];
foreach (['A', 'B', 'C', 'D', 'E'] as $vol) {
    for ($i = 0; $i < 100; $i++) $all[] = sprintf('%s%02d', $vol, $i);
}
$empty = array_values(array_diff($all, array_keys($codes)));
// Every code from $from to $to has lines of $family (B20–B99: the Sicilian).
$spans = static function (string $family, string $from, string $to) use ($codes): bool {
    for ($i = (int) substr($from, 1); $i <= (int) substr($to, 1); $i++) {
        $c = $codes[sprintf('%s%02d', $from[0], $i)] ?? null;
        if ($c === null || !str_starts_with((string) $c['label'], $family)) return false;
    }
    return true;
};
ob_start();
?>
<article class="openings-index">
    <header class="openings-index-header">
        <h1>ECO codes · A00–E99</h1>
        <p class="lede">
            The ECO system files every chess opening under one of 500 codes in five volumes.
            Here are all of them with the <?= number_format($total) ?> named lines behind them —
            or browse the <a href="<?= $baseEsc . $esc(I18n::url('/openings')) ?>">openings A–Z</a>.
            <a href="#what-are-eco-codes">What ECO codes are</a> is explained below the list.
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

    <section class="about-section eco-about" id="what-are-eco-codes">
        <h2>What are ECO codes?</h2>
        <p>
            ECO stands for the <em>Encyclopaedia of Chess Openings</em>, a reference work on opening
            theory published by the Serbian company Šahovski Informator (Chess Informant). Its first
            edition came out in five volumes between 1974 and 1979: C in 1974, B in 1975, D in 1976,
            E in 1978 and A in 1979. The lines in it are taken from master games and from analysis
            published in <em>Informant</em>, chosen by editors who are mostly grandmasters. Its chief
            editor from the first edition on was Aleksandar Matanović (1930–2023).
        </p>
        <p>
            Instead of the traditional opening names, the encyclopaedia uses codes: a letter from A to E
            for each of the five volumes, followed by a number from 00 to 99 — 500 codes in all. Other
            chess publications and websites took up the system, and "ECO" is often used as shorthand for
            the codes themselves. "ECO code" is a registered trademark of Chess Informant.
        </p>
        <h2>What the five volumes cover</h2>
        <ul>
            <li><strong>A · Flank openings</strong> — the English, the Réti, Bird's Opening, the Dutch,
                the Benoni, the Benko Gambit, the Old Indian and irregular first moves.</li>
            <li><strong>B · Semi-open games other than the French</strong> — the Sicilian, the
                Caro-Kann, the Pirc, the Modern, Alekhine's Defense and the Scandinavian.</li>
            <li><strong>C · Open games and the French</strong> — the Ruy Lopez, the Italian Game, the
                Scotch, the Four Knights, Petrov's Defense, the Philidor, the Vienna, the King's Gambit
                and the French Defense.</li>
            <li><strong>D · Closed and semi-closed games</strong> — the Queen's Gambit, accepted and
                declined, the Slav, other Queen's Pawn games and the Grünfeld.</li>
            <li><strong>E · Indian defenses</strong> other than the Grünfeld and the Old Indian — the
                Nimzo-Indian, the Queen's Indian, the King's Indian, the Bogo-Indian and the Catalan.</li>
        </ul>
        <h2>ECO codes on this site</h2>
        <p>
            The codes here come with the Lichess chess-openings dataset, which gives each of its
            <?= number_format($total) ?> named lines one code. A code usually holds several named lines,
            and a big opening fills many codes<?php if ($spans('Sicilian Defense', 'B20', 'B99')): ?>:
            the Sicilian Defense has lines under every code from
            <a href="<?= $baseEsc . $esc(I18n::url('/eco/B20')) ?>">B20</a> to
            <a href="<?= $baseEsc . $esc(I18n::url('/eco/B99')) ?>">B99</a><?php endif; ?>.
            <?= count($codes) ?> of the 500 codes have at least one named line<?= $empty
                ? '; ' . $esc(implode(' and ', $empty)) . ' ' . (count($empty) === 1 ? 'has' : 'have') . ' none'
                : '' ?>.
        </p>
        <h2>How to find the ECO code of a game</h2>
        <p>
            Paste the game's moves or its whole PGN into the
            <a href="<?= $baseEsc . $esc(I18n::url('/search')) ?>">opening identifier</a>, or play the
            first moves on its board. It names the longest named line the game follows, with its ECO
            code, and the move with which the game leaves the named lines.
        </p>
    </section>
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
