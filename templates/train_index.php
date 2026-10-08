<?php
/** @var array  $openings  Opening::described(), most played first. */
/** @var string $baseUrl */
/** @var string $siteUrl */
$esc     = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$baseEsc = $esc($baseUrl);
// The side that makes a line's last move chooses it, and practises it by default.
$bySide = ['white' => [], 'black' => []];
foreach ($openings as $o) $bySide[(int) $o['move_count'] % 2 === 1 ? 'white' : 'black'][] = $o;
$counts = Cache::remember('train-index-counts-v1', 21600, static function () use ($openings): array {
    $out = [];
    foreach ($openings as $o) $out[$o['slug']] = Opening::continuationCount($o);
    return $out;
});
// First moves only: "1. e4 e5 2. Nf3 Nc6 3. Bb5 a6 4. Ba4…".
$moves = static function (string $pgn): string {
    if (mb_strlen($pgn) <= 40) return $pgn;
    $cut = mb_substr($pgn, 0, (int) mb_strrpos(mb_substr($pgn, 0, 41), ' '));
    return preg_replace('/\s*\d+\.+$/', '', $cut) . '…';
};
$sideTitle = ['white' => 'Openings for White', 'black' => 'Defenses for Black'];
ob_start();
?>
<article class="openings-index train-index">
    <header class="openings-index-header">
        <h1>Chess opening trainer</h1>
        <p class="lede">
            Pick an opening and play your side's moves from memory: the trainer answers with the other
            side's moves, shows the right move after two wrong tries and brings every line back for
            review on a schedule. Free, no account — your progress stays in this browser.
        </p>
        <nav class="openings-jump" aria-label="Jump to side">
            <?php foreach ($sideTitle as $side => $label): ?>
                <a href="#train-<?= $side ?>"><?= $esc($label) ?>
                    <span class="openings-jump-count"><?= count($bySide[$side]) ?></span></a>
            <?php endforeach; ?>
        </nav>
    </header>

    <?php foreach ($sideTitle as $side => $label): if (!$bySide[$side]) continue; ?>
        <section class="openings-letter" id="train-<?= $side ?>">
            <h2><?= $esc($label) ?> <span class="openings-letter-count"><?= count($bySide[$side]) ?></span></h2>
            <ul class="openings-letter-list eco-code-list">
                <?php foreach ($bySide[$side] as $o): $n = $counts[$o['slug']] ?? 1; ?>
                    <li>
                        <a href="<?= $baseEsc . $esc(I18n::url('/train/' . $o['slug'])) ?>">
                            <span class="eco-tag"><?= $esc($o['eco']) ?></span>
                            <span class="eco-code-text">
                                <span class="openings-letter-name"><?= $esc($o['name']) ?></span>
                                <span class="eco-code-meta"><code><?= $esc(Opening::keepNumbers($moves(trim((string) $o['pgn_moves'])))) ?></code>
                                    · <?= $n === 1 ? '1 line' : number_format(min($n, 300)) . ' lines' ?></span>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>

    <section class="about-section train-about">
        <h2>How the trainer works</h2>
        <p>
            Each opening comes with the named lines that continue it, from the Lichess chess-openings
            dataset. You start with the opening's own moves; tick "Drill all" to go through its lines too,
            those due for review first. Play the moves for your side — the side that chooses the line,
            or the other one with "Play the other side" — and the trainer plays the replies.
        </p>
        <p>
            Two wrong tries show the move. A line you play without a mistake comes back after 1, 3, 7, 16
            and 35 days; a mistake brings it back the next day. The schedule is kept in your browser, so
            nothing is sent anywhere and no account is needed.
        </p>
        <h2>Practise your own repertoire</h2>
        <p>
            On any opening page, add the line to your repertoire with <strong>+ White</strong> or
            <strong>+ Black</strong>. <a href="<?= $baseEsc . $esc(I18n::url('/repertoire')) ?>">My repertoire</a>
            then drills all the lines you saved for one side together.
        </p>
        <h2>Any other line</h2>
        <p>
            Every one of the <?= number_format(array_sum(Opening::countsByGroup())) ?> named lines has a
            <strong>Practice the line</strong> button on its page. Find a line by name or by its moves in the
            <a href="<?= $baseEsc . $esc(I18n::url('/search')) ?>">opening identifier</a>, or browse the
            <a href="<?= $baseEsc . $esc(I18n::url('/openings')) ?>">openings A–Z</a>.
        </p>
    </section>
</article>
<?php
$body        = ob_get_clean();
$title       = 'Chess Opening Trainer: Practice Openings Move by Move';
$description = 'Free chess opening trainer: play the moves of ' . count($openings)
             . ' openings from memory, with the replies played for you and spaced-repetition review. No account needed.';
$canonical   = $siteUrl . $baseUrl . I18n::url('/train');
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'               => 'WebApplication',
            'name'                => 'Chess opening trainer',
            'url'                 => $canonical,
            'description'         => $description,
            'applicationCategory' => 'GameApplication',
            'operatingSystem'     => 'Any',
            'isAccessibleForFree' => true,
            'inLanguage'          => I18n::locale(),
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => t('site.name'), 'item' => $siteUrl . $baseUrl . I18n::url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Chess opening trainer', 'item' => $canonical],
            ],
        ],
    ],
];
require __DIR__ . '/layout.php';
