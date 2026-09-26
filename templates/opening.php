<?php
/** @var array $opening */
/** @var array|null $parent */
/** @var array $children */
/** @var array $ancestors        Root → ... → direct parent. Empty for root opening. */
/** @var array $siblings         Other children of the same parent. */
/** @var array $descendants      Inline subtree rows. Empty if subtree is lazy-loaded. */
/** @var int   $descendantCount  Total descendants — populated even when $descendants is empty. */
/** @var string $baseUrl */
/** @var string $siteUrl */
ob_start();

/**
 * Lichess names nested variations like "<Parent>: <Suffix>" or
 * "<Parent>, <Suffix>". For breadcrumbs / subtree lists we only want to show
 * the suffix — repeating the parent's name on every level is unreadable.
 *
 * Falls back to the full name when there's no obvious prefix to strip
 * (defensive: the data isn't perfectly consistent).
 */
$opening_short_name = static function (string $name, ?string $parentName): string {
    if ($parentName === null || $parentName === '') return $name;
    foreach ([': ', ', '] as $sep) {
        $prefix = $parentName . $sep;
        if (strncmp($name, $prefix, strlen($prefix)) === 0) {
            return substr($name, strlen($prefix));
        }
    }
    return $name;
};

/**
 * A breadcrumb name, after the one before it: the part that name already
 * said goes ("…: Modern Variations" → "Modern Variations", "…, Main Line" →
 * "Main Line"), and so does the opening's own name when both belong to it
 * ("Sicilian Defense: Najdorf Variation" after "Sicilian Defense: Modern
 * Variations, Main Line" → "Najdorf Variation").
 */
$opening_crumb = static function (string $name, ?string $prevName) use ($opening_short_name): string {
    $short = $opening_short_name($name, $prevName);
    if ($short !== $name || $prevName === null) return $short;
    $family = Opening::family($name);
    if (str_starts_with($name, $family . ': ') && $family === Opening::family($prevName)) {
        return substr($name, strlen($family) + 2);
    }
    return $name;
};

$o = $opening;
// Lines that share a name + ECO code get their final moves appended, so each
// page has its own <title> instead of up to 14 identical ones.
$lineTail = Opening::distinguishingTail($o);
$title = $o['name'] . ' (' . $o['eco'] . ')' . ($lineTail !== '' ? ' – ' . $lineTail : '');
// Titles also say what the page is and offers, in the words people search
// with, as far as 60 characters allow: "Italian Game (C50) – Chess Opening
// Moves & Win Rates", "Queen's Gambit Declined (D30): Chess Moves & Win
// Rates", "Sicilian Defense: Najdorf Variation (B90): Moves & Win Rates".
if ($lineTail === '') {
    $suffixes = [': Chess Moves & Win Rates', ': Moves & Win Rates'];
    if (stripos((string) $o['name'], 'Opening') === false) array_unshift($suffixes, ' – Chess Opening Moves & Win Rates');
    foreach ($suffixes as $suffix) {
        if (mb_strlen($title . $suffix) <= 60) { $title .= $suffix; break; }
    }
}
$baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');

// ----------------------------------------------------------------------
// The overview: what the line is, how it scores on Lichess and where it
// leads — facts from the data only, no filler. Rendered only when there is
// no written description, so human prose isn't preceded by a summary.
// ----------------------------------------------------------------------
$ecoGroup      = substr((string) $o['eco'], 0, 1);
$ecoGroupLabel = t('group.' . $ecoGroup);
$plies         = (int) $o['move_count'];
$movesPretty   = trim((string) $o['pgn_moves']);

$rootAncestor  = !empty($ancestors) ? $ancestors[0] : null;
$childCount    = count($children);
$siblingCount  = count($siblings);

$nameEsc       = htmlspecialchars((string) $o['name'], ENT_QUOTES, 'UTF-8');
$ecoEsc        = htmlspecialchars((string) $o['eco'], ENT_QUOTES, 'UTF-8');
$movesEsc      = '<code class="opening-overview-moves">' . htmlspecialchars($movesPretty, ENT_QUOTES, 'UTF-8') . '</code>';
// og.php draws the share card and the diagram; ?v= changes when it does.
$ogVer         = substr((string) @hash_file('xxh3', __DIR__ . '/../og.php'), 0, 8);
$diagramUrl    = $baseUrl . Opening::diagramPath((string) $o['slug']);
// "5…a6": the move that reaches the position.
$sans          = preg_split('/\s+/', trim((string) preg_replace('/\d+\.+\s*/', ' ', $movesPretty))) ?: [];
$lastMoveText  = $plies > 0 && $sans ? (intdiv($plies - 1, 2) + 1) . (($plies - 1) % 2 === 0 ? '. ' : '…') . end($sans) : '';
$isGambit      = Opening::isGambit((string) $o['name']);
// "Queen's Gambit Declined: …" and the like: named after a gambit that isn't taken.
$isDeclined    = !$isGambit && stripos((string) $o['name'], 'Gambit') !== false;

// Lichess numbers from the server-side cache, printed into the HTML: they
// show at once, and search engines see them at all (/api/ is closed to
// crawlers). app.js fetches only when nothing is cached yet or the
// numbers are over a week old — same markup as its renderStats().
require_once __DIR__ . '/../lib/ChessEngine.php';
require_once __DIR__ . '/../lib/StatsCache.php';
$stats      = StatsCache::cached(ChessEngine::fromPgn((string) $o['pgn_moves'])->uciHistory());
$statsTotal = $stats ? $stats['white'] + $stats['black'] + $stats['draws'] : 0;
$pct        = static fn (int $n, int $of): string => number_format($of > 0 ? $n * 100 / $of : 0, 1, '.', '');
$barLabel   = static fn (string $w, string $d, string $b): string => "White $w% · Draw $d% · Black $b%";
[$wPct, $dPct, $bPct] = [$pct($stats['white'] ?? 0, $statsTotal), $pct($stats['draws'] ?? 0, $statsTotal), $pct($stats['black'] ?? 0, $statsTotal)];
$statsRows  = [];
foreach ($stats['top_moves'] ?? [] as $m) {
    $mt = (int) $m['white'] + (int) $m['black'] + (int) $m['draws'];
    if ($mt > 0) $statsRows[] = [$m['san'], $mt, $pct((int) $m['white'], $mt), $pct((int) $m['draws'], $mt), $pct((int) $m['black'], $mt)];
}

// Moves from here that reach a named line — the variations one move on, and
// the lines a move transposes into — for the statistics table: SAN => link.
$trans     = Opening::transpositions((int) $o['id']);
$nextLines = [];
$lastSan   = static function (string $pgn): string {
    $tokens = preg_split('/\s+/', trim($pgn)) ?: [];
    return (string) preg_replace('/^\d+\.+/', '', (string) end($tokens));
};
foreach ($children as $c) {
    if ((int) $c['move_count'] !== $plies + 1) continue;
    $nextLines[$lastSan((string) $c['pgn_moves'])] ??= [
        'name' => $opening_short_name((string) $c['name'], (string) $o['name']) === (string) $o['name']
            ? 'same name' : $opening_short_name((string) $c['name'], (string) $o['name']),
        'url'  => $baseUrl . I18n::url('/openings/' . $c['slug']),
    ];
}
foreach ($trans['to'] as [$t, $san]) {
    $nextLines[(string) $san] ??= ['name' => (string) $t['name'], 'url' => $baseUrl . I18n::url('/openings/' . $t['slug'])];
}
foreach ($nextLines as $san => $line) {
    if ($line['name'] === 'same name') unset($nextLines[$san]);
}

$h    = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$link = static fn (array $r, ?string $text = null): string =>
    '<a href="' . $h($baseUrl . I18n::url('/openings/' . $r['slug'])) . '">' . $h($text ?? $r['name']) . '</a>';
$kind = $isGambit ? 'a gambit' : ($isDeclined ? 'a declined gambit' : null);
$overview = [$parent
    ? sprintf('<strong>%s</strong> (ECO %s, %s) is %s %s, reached after %s — %s.', $nameEsc, $ecoEsc, $h($ecoGroupLabel),
        $kind ?? 'a variation', ($kind ? 'in ' : 'of ') . $link($parent), $movesEsc, Opening::movesLabel($plies))
    : sprintf('<strong>%s</strong> (ECO %s, %s) is %s that starts %s.', $nameEsc, $ecoEsc, $h($ecoGroupLabel), $kind ?? 'a chess opening', $movesEsc)];
if ($statsTotal > 0) {
    $sentence = sprintf('In %s rated Lichess games between players rated 1600 to 2500, White won %s%%, Black %s%% and %s%% were drawn.',
        number_format($statsTotal), $wPct, $bPct, $dPct);
    $top      = $stats['top_moves'][0] ?? null;
    $topGames = $top ? (int) $top['white'] + (int) $top['black'] + (int) $top['draws'] : 0;
    if ($topGames > 0) {
        $whiteNext = $plies % 2 === 0;
        $sentence .= sprintf(' %s most played move here is <strong>%s</strong>, in %d%% of those games.',
            $whiteNext ? "White's" : "Black's",
            $h((intdiv($plies, 2) + 1) . ($whiteNext ? '. ' : '…') . $top['san']),
            (int) round($topGames * 100 / $statsTotal));
    }
    $overview[] = $sentence;
}
if ($childCount > 0) {
    // The most played, by full name; deeper lines that keep this very name say nothing new.
    $byGames = array_filter($children, static fn (array $c): bool => $c['name'] !== $o['name']);
    usort($byGames, static fn (array $a, array $b): int => (int) $b['popularity'] <=> (int) $a['popularity']);
    $named = array_map($link, array_slice($byGames, 0, 2));
    $sentence = match (true) {
        $childCount === 1 => 'It continues into one named variation' . ($named ? ', ' . $named[0] : '') . '.',
        $named === []     => sprintf('It branches into %d named variations.', $childCount),
        default           => sprintf('It branches into %d named variations; the most played %s %s.', $childCount,
                                 count($named) === 1 ? 'is' : 'are', implode(' and ', $named)),
    };
    if ($descendantCount > $childCount) $sentence .= sprintf(' In all, %d named lines continue from it.', $descendantCount);
    $overview[] = $sentence;
}
$overviewHtml = '<p>' . implode('</p><p>', $overview) . '</p>';

$island = [
    'id'          => (int) $o['id'],
    'pgn'         => $o['pgn_moves'],
    'name'        => $o['name'],
    'statsApiUrl' => $baseUrl . '/api/stats',
    'plies'       => $plies,
    'nextLines'   => (object) $nextLines,
    'i18n'        => [
        'loading'     => t('opening.stats.loading'),
        'no_games'    => t('opening.stats.no_games'),
        'failed'      => t('opening.stats.failed'),
        'attribution' => t('opening.stats.attribution'),
        'show_more'   => t('opening.variations.show', ['count' => count($children)]),
        'show_less'   => t('opening.variations.hide'),
    ],
];
?>
<!-- Hidden data for the Recently viewed history. JS reads this on every
     opening page load and appends to localStorage['codex-recent']. -->
<meta name="codex-current-opening"
      data-slug="<?= htmlspecialchars((string) $o['slug'], ENT_QUOTES, 'UTF-8') ?>"
      data-name="<?= htmlspecialchars((string) $o['name'], ENT_QUOTES, 'UTF-8') ?>"
      data-eco="<?= htmlspecialchars((string) $o['eco'], ENT_QUOTES, 'UTF-8') ?>">

<article class="opening">
    <?php
    // Breadcrumb. Lichess files one name at several depths ("Sicilian
    // Defense" is 1…c5, 2. Nf3, 2…d6 and more), so each name appears once, as
    // its shortest line, and loses the part the name before it already said:
    // "Sicilian Defense › Modern Variations › Main Line › Najdorf Variation".
    $crumbs    = [];
    $crumbSeen = [];
    $prevName  = null;
    foreach ($ancestors as $a) {
        $name = (string) $a['name'];
        if (isset($crumbSeen[$name])) continue;
        $crumbSeen[$name] = true;
        $crumbs[] = [$a, $opening_crumb($name, $prevName)];
        $prevName = $name;
    }
    $currentCrumb = $opening_crumb((string) $o['name'], $prevName);
    if (isset($crumbSeen[(string) $o['name']]) && $parent) {
        // Same name as a line above it: the moves tell it apart.
        $currentCrumb .= ' (' . Opening::movesFrom((string) $o['pgn_moves'], (int) $parent['move_count']) . ')';
    }
    ?>
    <nav class="opening-breadcrumb" aria-label="Breadcrumb">
        <ol>
            <li><a href="<?= $baseEsc . htmlspecialchars(I18n::url('/openings'), ENT_QUOTES, 'UTF-8') ?>">Openings</a></li>
            <?php foreach ($crumbs as [$a, $label]): ?>
                <li><a href="<?= $baseEsc . htmlspecialchars(I18n::url('/openings/' . $a['slug']), ENT_QUOTES, 'UTF-8') ?>"
                       title="<?= htmlspecialchars($a['name'] . ' (' . $a['eco'] . ')', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a></li>
            <?php endforeach; ?>
            <li aria-current="page"><?= htmlspecialchars($currentCrumb, ENT_QUOTES, 'UTF-8') ?></li>
        </ol>
    </nav>
    <header class="opening-header">
        <a class="eco-tag" href="<?= $baseEsc . htmlspecialchars(I18n::url('/eco/' . $o['eco']), ENT_QUOTES, 'UTF-8') ?>"
           title="All openings under ECO <?= htmlspecialchars($o['eco'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($o['eco'], ENT_QUOTES, 'UTF-8') ?></a>
        <h1><?= htmlspecialchars($o['name'], ENT_QUOTES, 'UTF-8') ?><?php if ($lineTail !== ''): ?>
            <?php /* Same-name lines: the moves that tell this one apart, as in the title. */ ?>
            <span class="opening-title-tail"><?= htmlspecialchars($lineTail, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></h1>
    </header>

    <?php
    // Share and export — no third-party JS, no tracking: copy buttons, the
    // device's own share sheet where the browser has one (app.js), the PGN.
    $shareUrl  = $siteUrl . $baseUrl . I18n::url('/openings/' . $o['slug']);
    $shareText = $o['name'] . ' (' . $o['eco'] . ') — Caissa Codex';
    // The side that makes the line's last move is the one choosing it.
    $ownSide   = $plies % 2 === 1 ? 'white' : 'black';
    ?>
    <div class="opening-grid">
        <div class="opening-board-wrap">
            <div id="board" class="opening-board"></div>
            <div class="board-controls" role="group" aria-label="Board">
                <button id="board-reset" type="button" title="Back to the starting position"><?= htmlspecialchars(t('opening.board.reset'), ENT_QUOTES, 'UTF-8') ?></button>
                <button id="board-prev" type="button" class="board-step" aria-label="Previous move" title="Previous move (←)">‹</button>
                <button id="board-next" type="button" class="board-step" aria-label="Next move" title="Next move (→)">›</button>
                <button id="board-flip" type="button" title="Turn the board around">
                    <span aria-hidden="true">⇅</span> Flip
                </button>
            </div>
            <p class="board-hint"><?= htmlspecialchars(t('opening.board.hint'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <aside class="opening-side">
            <h2><?= htmlspecialchars(t('opening.moves'), ENT_QUOTES, 'UTF-8') ?></h2>
            <ol id="move-list" class="move-list move-list-paired" aria-label="<?= htmlspecialchars(t('opening.moves'), ENT_QUOTES, 'UTF-8') ?>"></ol>

            <div class="opening-actions">
                <?php /* nofollow: the 3,690 play pages are noindex, no crawl needed. */ ?>
                <a class="board-cta" rel="nofollow" href="<?= htmlspecialchars($baseUrl . I18n::url('/play/' . $o['slug']), ENT_QUOTES, 'UTF-8') ?>"
                   data-prefetch="<?= $baseEsc ?>/vendor/stockfish.js <?= $baseEsc ?>/vendor/stockfish.wasm"><?= htmlspecialchars(t('opening.board.cta'), ENT_QUOTES, 'UTF-8') ?></a>
                <a class="board-cta board-cta-secondary" rel="nofollow"
                   href="<?= htmlspecialchars($baseUrl . I18n::url('/train/' . $o['slug']), ENT_QUOTES, 'UTF-8') ?>"
                   title="Play this line's moves from memory, with review on a schedule">Practice the line</a>
                <div class="repertoire-toggle"
                     data-slug="<?= htmlspecialchars((string) $o['slug'], ENT_QUOTES, 'UTF-8') ?>"
                     data-name="<?= htmlspecialchars((string) $o['name'], ENT_QUOTES, 'UTF-8') ?>"
                     data-eco="<?= htmlspecialchars((string) $o['eco'], ENT_QUOTES, 'UTF-8') ?>"
                     data-pgn="<?= htmlspecialchars(trim((string) $o['pgn_moves']), ENT_QUOTES, 'UTF-8') ?>">
                    <a class="repertoire-toggle-label" rel="nofollow" href="<?= htmlspecialchars($baseUrl . I18n::url('/repertoire'), ENT_QUOTES, 'UTF-8') ?>">My repertoire:</a>
                    <?php foreach (['white' => 'White', 'black' => 'Black'] as $side => $sideLabel): ?>
                        <button type="button" class="opening-tool-btn" data-repertoire-side="<?= $side ?>" aria-pressed="false"
                                title="<?= $side === $ownSide
                                    ? "For when you play $sideLabel: $sideLabel makes this line's last move"
                                    : "For when you play $sideLabel: to prepare against this line" ?>">+ <?= $sideLabel ?></button>
                    <?php endforeach; ?>
                </div>
                <details class="opening-more">
                    <summary>Copy &amp; export</summary>
                    <div class="opening-more-menu opening-share" data-opening-tools
                         data-pgn="<?= htmlspecialchars(trim((string) $o['pgn_moves']), ENT_QUOTES, 'UTF-8') ?>"
                         data-slug="<?= htmlspecialchars((string) $o['slug'], ENT_QUOTES, 'UTF-8') ?>"
                         data-canonical-url="<?= htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8') ?>"
                         data-share-url="<?= htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8') ?>"
                         data-share-title="<?= htmlspecialchars($shareText, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="button" data-tool-copy-pgn title="Copy the moves as PGN">
                            <span aria-hidden="true">⎘</span> Copy PGN</button>
                        <button type="button" data-tool-copy-fen disabled title="Copy the FEN of the final position">
                            <span aria-hidden="true">⎘</span> Copy FEN</button>
                        <button type="button" data-share-copy><span aria-hidden="true">⎘</span> Copy link</button>
                        <button type="button" data-share-native hidden><span aria-hidden="true">↗</span> Share…</button>
                        <a href="<?= $baseEsc . htmlspecialchars(I18n::url('/openings/' . $o['slug']) . '.pgn', ENT_QUOTES, 'UTF-8') ?>"
                           download rel="nofollow" title="One PGN file, for a Lichess study or ChessBase">
                            <span aria-hidden="true">⤓</span> Download PGN
                            <small><?= $descendantCount > 0
                                ? 'this line and its ' . (int) $descendantCount . ' named ' . ($descendantCount === 1 ? 'continuation' : 'continuations')
                                : 'this line' ?></small></a>
                        <a target="_blank" rel="noopener" data-tool-lichess title="Engine analysis and the opening explorer on Lichess">
                            <span aria-hidden="true">↗</span> Open in Lichess analysis</a>
                    </div>
                </details>
            </div>
        </aside>
    </div>

    <?php
    // One form for both: a description of the line, or a mistake on the page.
    // Reports reach the admin queue marked (Submissions::REPORT_MARK) and are
    // never published. Shown in the Overview when there is no description yet.
    $renderSuggest = static function () use ($o, $baseEsc): void { ?>
        <details class="suggest-form" id="suggest-form-details">
            <summary><?= empty($o['description']) ? 'Write a description or report a mistake' : 'Suggest an improvement or report a mistake' ?></summary>
            <div class="suggest-form-body">
                <form id="suggest-form" method="post" action="<?= $baseEsc ?>/api/suggest">
                    <fieldset class="suggest-kind">
                        <legend>What would you like to send?</legend>
                        <label><input type="radio" name="kind" value="description" checked>
                            <?= empty($o['description']) ? 'A description of this line' : 'A better description' ?></label>
                        <label><input type="radio" name="kind" value="report">
                            A mistake on this page — moves, name, statistics…</label>
                    </fieldset>
                    <p class="suggest-hint" data-kind-hint="description">
                        Markdown supported (headings <code>###</code>, <strong>bold</strong>, lists, links).
                        Submissions are reviewed before publishing.
                    </p>
                    <p class="suggest-hint" data-kind-hint="report" hidden>
                        Say what is wrong and, if you know, what is right. Reports are read by a person and never published.
                    </p>
                    <input type="hidden" name="opening_id" value="<?= (int) $o['id'] ?>">
                    <!-- honeypot: real users leave this empty -->
                    <input type="text" name="website" tabindex="-1" autocomplete="off"
                           style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
                    <div class="suggest-row">
                        <label>Your name (optional)
                            <input type="text" name="name" maxlength="100">
                        </label>
                        <label>Your email (optional, not published)
                            <input type="email" name="email" maxlength="255">
                        </label>
                    </div>
                    <label><span data-kind-hint="description">Description</span><span data-kind-hint="report" hidden>What is wrong</span>
                        <textarea name="markdown" rows="8" required minlength="30" maxlength="5000"
                                  data-placeholder-description="### Origin&#10;…&#10;&#10;### Strategic ideas&#10;…"
                                  data-placeholder-report="The line is also called …, and the ECO code should be …"
                                  placeholder="### Origin&#10;…&#10;&#10;### Strategic ideas&#10;…"></textarea>
                    </label>
                    <p class="suggest-actions">
                        <button type="submit">Send</button>
                        <span class="suggest-status" aria-live="polite"></span>
                    </p>
                </form>
            </div>
        </details>
    <?php };
    ?>
    <?php if (empty($o['description'])): ?>
    <section class="opening-overview">
        <h2>Overview</h2>
        <figure class="opening-diagram">
            <img src="<?= htmlspecialchars($diagramUrl, ENT_QUOTES, 'UTF-8') ?>" width="720" height="720"
                 loading="lazy" decoding="async"
                 alt="Chess diagram: <?= $nameEsc ?><?= $lastMoveText !== '' ? ' after ' . htmlspecialchars($lastMoveText, ENT_QUOTES, 'UTF-8') : '' ?>">
            <?php if ($lastMoveText !== ''): ?><figcaption>After <?= htmlspecialchars($lastMoveText, ENT_QUOTES, 'UTF-8') ?></figcaption><?php endif; ?>
        </figure>
        <div class="opening-overview-body"><?= $overviewHtml ?></div>
        <div class="opening-overview-notice">
            <p><strong>Generated from the data</strong> — the Lichess opening list and opening explorer.
                Know this line well? Write about it; submissions are reviewed before publishing.</p>
            <?php $renderSuggest(); ?>
        </div>
    </section>
    <?php endif; ?>

    <?php
    // "3…" or "4. ": the number in front of the next move from this position.
    $nextMoveNo = (intdiv($plies, 2) + 1) . ($plies % 2 === 0 ? '. ' : '…');
    $pctsText   = static fn (string $w, string $d, string $b): string =>
        round((float) $w) . ' / ' . round((float) $d) . ' / ' . round((float) $b);
    $updated    = static fn (string $at): string => $at !== '' && ($ts = strtotime($at)) ? date('M j, Y', $ts) : '';
    ?>
    <section class="opening-stats" id="opening-stats" aria-busy="<?= $stats ? 'false' : 'true' ?>" aria-live="polite"
             data-stats="<?= $stats ? ($stats['fresh'] ? 'fresh' : 'stale') : 'none' ?>">
        <h2><?= htmlspecialchars(t('opening.stats.title'), ENT_QUOTES, 'UTF-8') ?></h2>
        <?php if ($stats && $statsTotal === 0): ?>
            <p class="stats-status" data-state="empty"><?= htmlspecialchars(t('opening.stats.no_games'), ENT_QUOTES, 'UTF-8') ?></p>
        <?php elseif ($stats): ?>
            <p class="stats-status" data-state="done" hidden></p>
        <?php else: ?>
            <p class="stats-status" data-state="loading"><?= htmlspecialchars(t('opening.stats.loading'), ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <div class="stats-bar" role="img"<?= $statsTotal > 0 ? ' aria-label="' . htmlspecialchars($barLabel($wPct, $dPct, $bPct), ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($barLabel($wPct, $dPct, $bPct), ENT_QUOTES, 'UTF-8') . '"' : ' hidden' ?>>
            <span class="stats-bar-w" style="width:<?= $wPct ?>%"></span>
            <span class="stats-bar-d" style="width:<?= $dPct ?>%"></span>
            <span class="stats-bar-b" style="width:<?= $bPct ?>%"></span>
        </div>
        <p class="stats-totals"<?= $statsTotal > 0 ? '' : ' hidden' ?>><?php if ($statsTotal > 0): ?><?= number_format($statsTotal) ?> games · White <?= $wPct ?>% / Draw <?= $dPct ?>% / Black <?= $bPct ?>%<?php endif; ?></p>
        <table class="stats-moves"<?= $statsTotal > 0 && $statsRows ? '' : ' hidden' ?>>
            <caption class="stats-moves-caption">The moves played next — click one to see it on the board.</caption>
            <thead>
                <tr><th>Next move</th><th>Games</th><th>White / Draw / Black</th></tr>
            </thead>
            <tbody><?php foreach ($statsTotal > 0 ? $statsRows : [] as [$san, $mt, $mw, $md, $mb]):
                $next = $nextLines[$san] ?? null; ?>
                <tr>
                    <td class="stats-move">
                        <button type="button" class="stats-move-btn" data-san="<?= htmlspecialchars((string) $san, ENT_QUOTES, 'UTF-8') ?>"
                                title="Show <?= htmlspecialchars($nextMoveNo . $san, ENT_QUOTES, 'UTF-8') ?> on the board"><?= htmlspecialchars($nextMoveNo . $san, ENT_QUOTES, 'UTF-8') ?></button>
                        <?php if ($next): ?><a class="stats-move-line" href="<?= htmlspecialchars($next['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($next['name'], ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
                    </td>
                    <td><?= number_format($mt) ?></td>
                    <td><div class="stats-bar inline" role="img" aria-label="<?= htmlspecialchars($barLabel($mw, $md, $mb), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($barLabel($mw, $md, $mb), ENT_QUOTES, 'UTF-8') ?>"><span class="stats-bar-w" style="width:<?= $mw ?>%"></span><span class="stats-bar-d" style="width:<?= $md ?>%"></span><span class="stats-bar-b" style="width:<?= $mb ?>%"></span></div>
                        <span class="stats-pcts" aria-hidden="true"><?= $pctsText($mw, $md, $mb) ?></span></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table>
        <p class="stats-attribution"<?= $statsTotal > 0 ? '' : ' hidden' ?>><small><?= htmlspecialchars(t('opening.stats.attribution'), ENT_QUOTES, 'UTF-8') ?> <span class="stats-cached-at"><?= $statsTotal > 0 ? htmlspecialchars($updated((string) $stats['cached_at']), ENT_QUOTES, 'UTF-8') : '' ?></span></small></p>
    </section>

    <?php
    // By level: four Lichess rating bands and the masters database, fetched
    // for the most-played lines by tools/fetch-levels.php.
    $levels = LevelStats::forOpening((int) $o['id']);
    $masterGames = $levels['masters']['games_list'] ?? [];
    // One style for players: "Carlsen, Magnus" and "Carlsen, M." both → "Carlsen, M.".
    $player = static function (string $n): string {
        if (!preg_match('/^([^,]+),\s*(.+)$/u', trim($n), $m)) return trim($n);
        $initials = array_map(static fn (string $w): string => mb_substr($w, 0, 1) . '.', preg_split('/[\s.]+/u', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        return trim($m[1]) . ', ' . implode(' ', $initials);
    };
    if ($levels): ?>
    <section class="opening-levels">
        <h2>By rating</h2>
        <table class="stats-moves stats-levels">
            <thead><tr><th>Players</th><th>Games</th><th>White / Draw / Black</th></tr></thead>
            <tbody>
            <?php foreach ($levels as $key => $l):
                [$lw, $ld, $lb] = [$pct($l['white'], $l['games']), $pct($l['draws'], $l['games']), $pct($l['black'], $l['games'])]; ?>
                <tr>
                    <td><?= htmlspecialchars(LevelStats::LEVELS[$key]['label'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $l['games'] > 0 ? htmlspecialchars(Rankings::compact($l['games']), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                    <td><?php if ($l['games'] > 0): ?>
                        <div class="stats-bar inline" role="img" aria-label="<?= htmlspecialchars($barLabel($lw, $ld, $lb), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($barLabel($lw, $ld, $lb), ENT_QUOTES, 'UTF-8') ?>"><span class="stats-bar-w" style="width:<?= $lw ?>%"></span><span class="stats-bar-d" style="width:<?= $ld ?>%"></span><span class="stats-bar-b" style="width:<?= $lb ?>%"></span></div>
                        <span class="stats-pcts" aria-hidden="true"><?= $pctsText($lw, $ld, $lb) ?></span>
                    <?php else: ?>no games<?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($masterGames): ?>
            <h3>Master games</h3>
            <ul class="master-games">
                <?php foreach ($masterGames as $g):
                    $result = match ($g['winner']) { 'white' => '1–0', 'black' => '0–1', default => '½–½' }; ?>
                    <li><a href="https://lichess.org/<?= rawurlencode((string) $g['id']) ?>" target="_blank" rel="noopener nofollow">
                        <?= htmlspecialchars($player((string) $g['white']), ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars($player((string) $g['black']), ENT_QUOTES, 'UTF-8') ?></a>
                        <span class="master-games-meta"><?= $g['year'] > 0 ? (int) $g['year'] . ' · ' : '' ?><?= $result ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="stats-attribution"><small>Rated blitz, rapid and classical Lichess games by rating band, and
            over-the-board games of players rated 2200+ from the Lichess masters database ·
            updated <?= htmlspecialchars($updated((string) max(array_column($levels, 'fetched_at'))), ENT_QUOTES, 'UTF-8') ?></small></p>
    </section>
    <?php endif; ?>

    <?php
    // Admin-only inline edit link. Same trick as in layout.php — only touch
    // the session if the admin cookie is present, otherwise we turn every
    // public opening page into a Set-Cookie + uncacheable.
    $isAdmin = false;
    if (isset($_COOKIE['codex_admin'])) {
        require_once __DIR__ . '/../lib/Auth.php';
        $isAdmin = Auth::isLoggedIn();
    }
    $editLink = $isAdmin
        ? '<a class="admin-edit-link" href="' . $baseEsc . '/admin/edit/' . htmlspecialchars($o['slug'], ENT_QUOTES, 'UTF-8') . '">'
          . (empty($o['description']) ? 'Write description →' : 'Edit description →') . '</a>'
        : '';
    ?>
    <section class="opening-description">
        <?php /* No "Description" heading without a description: an empty
                 section on every page reads as thin content. */ ?>
        <?php if (!empty($o['description'])): ?>
        <h2>
            <?= htmlspecialchars(t('opening.description.title'), ENT_QUOTES, 'UTF-8') ?>
            <?= $editLink ?>
        </h2>
        <?php elseif ($editLink !== ''): ?>
        <p><?= $editLink ?></p>
        <?php endif; ?>
        <?php if (!empty($o['description'])):
            require_once __DIR__ . '/../vendor/Parsedown.php';
            $pd = new Parsedown();
            $pd->setSafeMode(true);
            $renderedDesc = $pd->text((string) $o['description']);

            // Auto-TOC: extract <h2>/<h3> from the rendered description, add
            // anchor IDs, and emit a small TOC at the top so long pieces of
            // theory (1000+ words) become scannable. Skip when there are
            // fewer than 3 headings — TOC for one entry is overkill.
            $toc       = [];
            $usedSlugs = [];
            $renderedDesc = preg_replace_callback(
                '#<(h[23])>(.*?)</\1>#i',
                static function ($m) use (&$toc, &$usedSlugs) {
                    $level = strtolower($m[1]);
                    $text  = trim(strip_tags($m[2]));
                    if ($text === '') return $m[0];
                    $slug  = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text));
                    $slug  = trim($slug, '-');
                    if ($slug === '') $slug = 'section';
                    $base  = $slug; $n = 1;
                    while (isset($usedSlugs[$slug])) $slug = $base . '-' . (++$n);
                    $usedSlugs[$slug] = true;
                    $toc[] = ['level' => $level, 'text' => $text, 'slug' => $slug];
                    return '<' . $level . ' id="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">' . $m[2] . '</' . $level . '>';
                },
                $renderedDesc
            );
        ?>
            <?php if (count($toc) >= 3): ?>
                <nav class="description-toc" aria-label="Table of contents">
                    <p class="description-toc-title">In this section</p>
                    <ol>
                        <?php foreach ($toc as $entry): ?>
                            <li class="description-toc-<?= $entry['level'] ?>">
                                <a href="#<?= htmlspecialchars($entry['slug'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($entry['text'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
            <?php endif; ?>
            <div class="description-body"><?= $renderedDesc ?></div>
        <?php endif; ?>
        <?php /* No placeholder text here when description is empty — the
                 Overview section above already invites readers to write one. */ ?>

        <?php if (!empty($o['description'])) $renderSuggest(); ?>
    </section>

    <?php
    // A variation's label under this line: its name without the part this
    // page's name already says — or, when Lichess gives it this very name, the
    // moves that make it different ("6. Bg5"). The meta is the move(s) from
    // this position that reach it.
    $variationLabel = static function (array $c, string $underName, int $fromPly) use ($opening_crumb): array {
        $moves = Opening::movesFrom((string) $c['pgn_moves'], $fromPly);
        $label = $opening_crumb((string) $c['name'], $underName);
        if ((string) $c['name'] === $underName) $label = 'Same name, after ' . $moves;
        return [$label, $moves];
    };
    ?>
    <?php if ($children): ?>
    <?php $pageSize = 20; $needsToggle = count($children) > $pageSize; ?>
    <section class="opening-children" data-children-collapsed="<?= $needsToggle ? '1' : '0' ?>">
        <h2><?= htmlspecialchars(t('opening.variations', ['count' => count($children)]), ENT_QUOTES, 'UTF-8') ?></h2>
        <ul class="child-list">
            <?php foreach ($children as $i => $c):
                [$childLabel, $childMoves] = $variationLabel($c, (string) $o['name'], $plies);
            ?>
                <li<?= ($needsToggle && $i >= $pageSize) ? ' class="is-overflow" hidden' : '' ?>>
                    <a href="<?= htmlspecialchars($baseUrl . I18n::url('/openings/' . $c['slug']), ENT_QUOTES, 'UTF-8') ?>"
                       title="<?= htmlspecialchars($c['name'] . ' (' . $c['eco'] . ')', ENT_QUOTES, 'UTF-8') ?>">
                        <span class="eco-tag"><?= htmlspecialchars($c['eco'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="child-list-name"><?= htmlspecialchars($childLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($childMoves !== '' && !str_starts_with($childLabel, 'Same name')): ?>
                            <span class="child-list-plies"><?= htmlspecialchars($childMoves, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($needsToggle): ?>
            <button type="button" class="children-toggle"
                data-show-text="<?= htmlspecialchars(t('opening.variations.show', ['count' => count($children)]), ENT_QUOTES, 'UTF-8') ?>"
                data-hide-text="<?= htmlspecialchars(t('opening.variations.hide'), ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars(t('opening.variations.show', ['count' => count($children)]), ENT_QUOTES, 'UTF-8') ?>
            </button>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if (!empty($siblings) && $parent): ?>
    <section class="opening-related">
        <h2>Other lines from the same position</h2>
        <p class="opening-related-lede">Instead of <?= htmlspecialchars($movesFromParent = Opening::movesFrom((string) $o['pgn_moves'], (int) $parent['move_count']), ENT_QUOTES, 'UTF-8') ?>,
            the position after
            <a href="<?= htmlspecialchars($baseUrl . I18n::url('/openings/' . $parent['slug']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $parent['name'], ENT_QUOTES, 'UTF-8') ?></a>
            also goes on to:</p>
        <ul class="child-list opening-related-list">
            <?php foreach ($siblings as $sib):
                [$sibLabel, $sibMoves] = $variationLabel($sib, (string) $parent['name'], (int) $parent['move_count']);
                // A sister line of another opening keeps its whole name.
                if (Opening::family((string) $sib['name']) !== Opening::family((string) $parent['name'])) $sibLabel = (string) $sib['name'];
            ?>
                <li>
                    <a href="<?= htmlspecialchars($baseUrl . I18n::url('/openings/' . $sib['slug']), ENT_QUOTES, 'UTF-8') ?>"
                       title="<?= htmlspecialchars($sib['name'] . ' (' . $sib['eco'] . ')', ENT_QUOTES, 'UTF-8') ?>">
                        <span class="eco-tag"><?= htmlspecialchars($sib['eco'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="child-list-name"><?= htmlspecialchars($sibLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($sibMoves !== '' && !str_starts_with($sibLabel, 'Same name')): ?>
                            <span class="child-list-plies"><?= htmlspecialchars($sibMoves, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php
    // Other move orders into this position, and moves from it into other named lines.
    $moveAt  = static fn (int $ply, string $san): string => (intdiv($ply, 2) + 1) . ($ply % 2 === 0 ? '. ' : '…') . $san;
    ?>
    <?php if ($trans['from'] || $trans['to']): ?>
    <section class="opening-transpositions">
        <h2>Transpositions</h2>
        <?php foreach (['from' => 'This position is also reached from these lines, by another move order:',
                        'to'   => 'From here, a move transposes into another named line:'] as $dir => $lede):
            if (!$trans[$dir]) continue; ?>
            <p class="opening-related-lede"><?= htmlspecialchars($lede, ENT_QUOTES, 'UTF-8') ?></p>
            <ul class="child-list">
                <?php foreach ($trans[$dir] as [$t, $san]):
                    // The move is played from the "from" line's position.
                    $move = $moveAt($dir === 'from' ? (int) $t['move_count'] : $plies, (string) $san); ?>
                    <li>
                        <a href="<?= htmlspecialchars($baseUrl . I18n::url('/openings/' . $t['slug']), ENT_QUOTES, 'UTF-8') ?>">
                            <span class="eco-tag"><?= htmlspecialchars((string) $t['eco'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="child-list-name"><?= htmlspecialchars((string) $t['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="child-list-plies"><?= $dir === 'from' ? 'then ' : 'after ' ?><?= htmlspecialchars($move, ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($descendantCount > count($children)): ?>
    <?php $subtreeLazy = empty($descendants); ?>
    <section class="opening-subtree">
        <details<?= $subtreeLazy ? ' data-lazy="' . (int) $o['id'] . '"'
                     . ' data-api="' . $baseEsc . '/api/subtree/"'
                     . ' data-href="' . $baseEsc . htmlspecialchars(I18n::url('/openings/'), ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                 data-parent-depth="<?= (int) $o['depth'] ?>">
            <summary>
                <span class="opening-subtree-icon" aria-hidden="true">&#9660;</span>
                All <?= (int) $descendantCount ?> named lines that continue from here
            </summary>
            <?php if ($subtreeLazy): ?>
                <p class="opening-subtree-status" data-state="idle" aria-live="polite">
                    <span class="opening-subtree-status-text">Loading sub-variations…</span>
                </p>
                <ul class="opening-subtree-list" hidden aria-busy="true"></ul>
            <?php else: ?>
                <?php
                // Build a quick lookup so each row can ask "what's my parent's
                // name?" and strip the redundant prefix.
                $nameById = [(int) $o['id'] => (string) $o['name']];
                foreach ($descendants as $d) {
                    $nameById[(int) $d['id']] = (string) $d['name'];
                }
                ?>
                <ul class="opening-subtree-list">
                    <?php foreach ($descendants as $d):
                        $parentName = $nameById[(int) $d['parent_id']] ?? null;
                        $display    = $opening_short_name((string) $d['name'], $parentName);
                    ?>
                        <li style="--depth-indent: <?= max(0, (int) $d['depth'] - (int) $o['depth'] - 1) ?>;">
                            <a href="<?= $baseEsc . htmlspecialchars(I18n::url('/openings/' . $d['slug']), ENT_QUOTES, 'UTF-8') ?>"
                               title="<?= htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8') ?>">
                                <span class="eco-tag"><?= htmlspecialchars($d['eco'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="opening-subtree-name"><?= htmlspecialchars($display, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="opening-subtree-plies"><?= Opening::movesLabel((int) $d['move_count']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </details>
    </section>
    <?php endif; ?>
</article>

<aside class="recent-strip" id="recent-strip" hidden aria-label="Recently viewed openings">
    <h2 class="section-label">Recently viewed</h2>
    <ul class="recent-strip-list" id="recent-strip-list"></ul>
</aside>

<script defer src="<?= $baseEsc ?>/public/opening.min.js?v=<?= @filemtime(__DIR__ . '/../public/opening.min.js') ?: 1 ?>"></script>

<script type="application/json" id="opening-data">
<?= htmlspecialchars(json_encode($island, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_NOQUOTES, 'UTF-8') ?>
</script>
<script type="module" src="<?= $baseEsc ?>/public/app.min.js?v=<?= @filemtime(__DIR__ . '/../public/app.min.js') ?: 1 ?>"></script>
<?php
$body = ob_get_clean();
$needsBoard = true;
// OG type "article" gives Facebook / LinkedIn / Telegram richer cards
// (author, published time) than the default "website".
$ogType = 'article';

$canonical = $siteUrl . $baseUrl . I18n::url('/openings/' . $o['slug']);

// SEO description, in the order searchers scan it: name and ECO code, how
// the line scores on Lichess (the one thing the title can't say), its first
// moves, the variations. Whole parts only, up to 160 characters, so search
// results never show it cut mid-phrase.
// The first moves, cut after a whole move ("1. e4 e5 2. Nf3 Nc6…").
$moveSnippet = $movesPretty;
if (mb_strlen($moveSnippet) > 24) {
    $cut = mb_substr($moveSnippet, 0, 25);
    $cut = mb_substr($cut, 0, (int) mb_strrpos($cut, ' '));
    $moveSnippet = preg_replace('/\s*\d+\.+$/', '', $cut) . '…';   // no dangling "3."
}
$descParts = [$o['name'] . ' (' . $o['eco'] . ')'];
if ($statsTotal > 0) {
    $descParts[] = sprintf('White wins %d%%, Black %d%%, draws %d%% in %s Lichess games',
        (int) round((float) $wPct), (int) round((float) $bPct), (int) round((float) $dPct),
        Rankings::compact($statsTotal));
}
// Same-name lines share their first moves; their ending is what differs.
$descParts[] = $lineTail !== '' ? 'line ending ' . $lineTail : $moveSnippet;
if ($childCount > 0) {
    $descParts[] = $childCount . ($childCount === 1 ? ' variation' : ' variations');
}
$descParts[] = 'Interactive board' . ($statsTotal > 0 ? '' : ', Lichess statistics') . ', play vs Stockfish.';
$description = '';
foreach ($descParts as $i => $part) {
    $next = $description === '' ? $part : $description . ' · ' . $part;
    if (mb_strlen($next) > 160 && $i > 0) continue;   // skip a part that doesn't fit, try the shorter ones after it
    $description = $next;
}
// Lines with almost no games stay out of search engines (and the sitemap).
$noindex = Opening::isThin($o);

// OG image absolute URL for the JSON-LD `image`: the same URL as og:image in
// layout.php, including its ?v= (hash of og.php).
$ogImageUrl = $siteUrl . $baseUrl . '/og.php?slug=' . urlencode($o['slug']) . '&v=' . $ogVer;

$breadcrumbs = [
    ['name' => t('site.name'), 'url' => $siteUrl . $baseUrl . I18n::url('/')],
    ['name' => 'ECO ' . $o['eco'], 'url' => $siteUrl . $baseUrl . I18n::url('/eco/' . $o['eco'])],
];
if ($parent) {
    $breadcrumbs[] = ['name' => $parent['name'], 'url' => $siteUrl . $baseUrl . I18n::url('/openings/' . $parent['slug'])];
}
$breadcrumbs[] = ['name' => $o['name'], 'url' => $canonical];

$siteRootUrl  = $siteUrl . $baseUrl . I18n::url('/');
$publisherLd  = [
    '@type' => 'Organization',
    'name'  => t('site.name'),
    'url'   => $siteRootUrl,
    'logo'  => [
        '@type' => 'ImageObject',
        'url'   => $siteUrl . $baseUrl . '/public/icon-512.png',
    ],
];

$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'            => 'Article',
            'headline'         => $title,
            'name'             => $o['name'],
            'description'      => $description,
            'url'              => $canonical,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
            'image'            => [
                // The square diagram too: Google prefers several aspect ratios.
                ['@type' => 'ImageObject', 'url' => $siteUrl . $diagramUrl, 'width' => 720, 'height' => 720],
                ['@type' => 'ImageObject', 'url' => $ogImageUrl, 'width' => 1200, 'height' => 630],
            ],
            'inLanguage'    => I18n::locale(),
            'articleSection'=> $ecoGroupLabel,
            'keywords'      => implode(', ', array_filter([
                $o['name'],
                $o['eco'],
                $ecoGroupLabel,
                'chess opening',
                'chess theory',
                $parent['name'] ?? null,
            ])),
            // Full ISO 8601 with offset — Google flags date-only values.
            // dateModified is when the Lichess numbers on the page were last
            // fetched: a real change, unlike "today" on every crawl.
            // The site went live on 2026-09-25.
            'datePublished' => '2026-09-25T12:00:00+02:00',
            'dateModified'  => $statsTotal > 0 && !empty($stats['cached_at'])
                ? (new DateTimeImmutable((string) $stats['cached_at']))->format(DATE_ATOM)
                : '2026-09-25T12:00:00+02:00',
            'author'        => $publisherLd,
            'publisher'     => $publisherLd,
            'isPartOf'      => [
                '@type' => 'WebSite',
                'name'  => t('site.name'),
                'url'   => $siteRootUrl,
            ],
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(static function ($i, $b) {
                return [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'name'     => $b['name'],
                    'item'     => $b['url'],
                ];
            }, array_keys($breadcrumbs), $breadcrumbs),
        ],
    ],
];

require __DIR__ . '/layout.php';
