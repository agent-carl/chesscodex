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

$o = $opening;
// Lines that share a name + ECO code get their final moves appended, so each
// page has its own <title> instead of up to 14 identical ones.
$lineTail = Opening::distinguishingTail($o);
$title = $o['name'] . ' (' . $o['eco'] . ')' . ($lineTail !== '' ? ' – ' . $lineTail : '');
// Short titles also name what the page offers ("Sicilian Defense (B20): Moves
// & Win Rates"); on long ones search results would only cut it off.
if ($lineTail === '' && mb_strlen($title) <= 42) $title .= ': Moves & Win Rates';
$baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');

// ----------------------------------------------------------------------
// Build a factual "overview" paragraph from data. Only rendered when there
// is no admin-written description — for pages with curated content we skip
// the auto-text entirely so the human prose isn't preceded by a robotic
// summary saying the same things.
//
// Even within the auto-generated path, sentence templates are chosen per
// opening (gambit / root / variation / group) and rotated by id, so 3,690
// pages don't read like clones of each other.
// ----------------------------------------------------------------------
$ecoGroup      = substr((string) $o['eco'], 0, 1);
$ecoGroupLabel = t('group.' . $ecoGroup);
$plies         = (int) $o['move_count'];
$plyCountText  = $plies === 1 ? '1 ply' : ($plies . ' plies');
$movesPretty   = trim((string) $o['pgn_moves']);

$rootAncestor  = !empty($ancestors) ? $ancestors[0] : null;
$childCount    = count($children);
$siblingCount  = count($siblings);

$nameEsc       = htmlspecialchars((string) $o['name'], ENT_QUOTES, 'UTF-8');
$ecoEsc        = htmlspecialchars((string) $o['eco'], ENT_QUOTES, 'UTF-8');
$groupEsc      = htmlspecialchars((string) $ecoGroupLabel, ENT_QUOTES, 'UTF-8');
$movesEsc      = '<code class="opening-overview-moves">' . htmlspecialchars($movesPretty, ENT_QUOTES, 'UTF-8') . '</code>';
$plyEsc        = htmlspecialchars($plyCountText, ENT_QUOTES, 'UTF-8');
$isGambit      = stripos((string) $o['name'], 'Gambit') !== false;

// Stable per-opening rotation seed — same opening always gets the same
// variants; different openings get different variants.
$rotation      = (int) $o['id'];

// Group-specific second sentences that characterise the family in plain
// chess vocabulary. Five distinct paragraphs, one per ECO letter.
$groupBlurbs = [
    'A' => 'The %s group covers flank openings — systems that delay the central pawn break and instead develop pieces and pawns on the wings.',
    'B' => 'The %s group covers semi-open games — replies to 1.e4 where Black declines the symmetrical 1…e5 in favour of an asymmetric pawn structure.',
    'C' => 'The %s group covers open games — classical 1.e4 e5 openings where both sides contest the centre directly with pawns.',
    'D' => 'The %s group covers closed and semi-closed games — 1.d4 systems characterised by long strategic manoeuvring rather than early tactics.',
    'E' => 'The %s group covers Indian defences — hypermodern lines where Black challenges the centre with pieces rather than pawns.',
];
$groupSentence = sprintf(
    $groupBlurbs[$ecoGroup] ?? 'The %s group is one of the five top-level ECO families.',
    '<strong>' . $groupEsc . '</strong>'
);

// 1. Intro sentence — branches by gambit / root / variation, with rotation.
if ($isGambit) {
    $introTemplates = [
        '<strong>%s</strong> is a gambit, classified under ECO code %s within the %s family. As with all gambits, one side sacrifices material early — usually a pawn — in exchange for development, open lines, or initiative.',
        '<strong>%s</strong> is a sacrificial opening line filed under ECO %s in the %s group. The defining idea is the early concession of material to seize the initiative and unbalance the position.',
        '<strong>%s</strong> belongs to the gambit family of chess openings (ECO %s, %s). It trades material for attacking chances — a classic trade-off that has fascinated players from the Romantic era to modern correspondence chess.',
    ];
    $intro = sprintf($introTemplates[$rotation % count($introTemplates)], $nameEsc, $ecoEsc, $groupEsc);
} elseif ($rootAncestor === null && $childCount >= 4) {
    $introTemplates = [
        '<strong>%s</strong> is one of the foundational chess openings, sitting at the root of an entire branch of theory under ECO code %s in the %s group.',
        '<strong>%s</strong> is a major root opening in the %s family (ECO %s). It serves as the entry point for a wide subtree of named variations.',
        '<strong>%s</strong> is a top-level opening within the %s family, catalogued as ECO %s — a starting point from which dozens of named sub-variations branch out.',
    ];
    $intro = sprintf($introTemplates[$rotation % count($introTemplates)], $nameEsc, $groupEsc, $ecoEsc);
} elseif ($rootAncestor === null) {
    $introTemplates = [
        '<strong>%s</strong> is a chess opening at the root of the %s family, classified under ECO code %s.',
        '<strong>%s</strong> sits at the top of the %s tree, indexed as ECO %s.',
    ];
    $intro = sprintf($introTemplates[$rotation % count($introTemplates)], $nameEsc, $groupEsc, $ecoEsc);
} else {
    $introTemplates = [
        '<strong>%s</strong> is a variation within the %s family, indexed as ECO %s.',
        '<strong>%s</strong> is a named branch in the %s group of chess theory, classified under ECO %s.',
        '<strong>%s</strong> is a specific line in the %s family (ECO %s), arising from a recognised theoretical position.',
    ];
    $intro = sprintf($introTemplates[$rotation % count($introTemplates)], $nameEsc, $groupEsc, $ecoEsc);
}

// 2. Move-sequence sentence — varies phrasing.
$moveTemplates = [
    'It is reached after %s of play, with the move sequence %s.',
    'The line arises from %s — specifically the moves %s.',
    'Play reaches this position via %s, after %s of opening development.',
    'It begins with %s and takes %s to reach the canonical position.',
];
$mTpl = $moveTemplates[$rotation % count($moveTemplates)];
$moveSentence = ($rotation % 4 === 2)
    ? sprintf($mTpl, $movesEsc, $plyEsc)
    : sprintf($mTpl, $plyEsc, $movesEsc);

// 3. Parent/ancestor sentence — only for non-root openings.
$parentSentence = '';
if ($parent) {
    $parentLink = '<a href="' . htmlspecialchars($baseUrl . I18n::url('/openings/' . $parent['slug']), ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars((string) $parent['name'], ENT_QUOTES, 'UTF-8') . '</a>';
    if ($rootAncestor && (int) $rootAncestor['id'] !== (int) $parent['id']) {
        $rootLink = '<a href="' . htmlspecialchars($baseUrl . I18n::url('/openings/' . $rootAncestor['slug']), ENT_QUOTES, 'UTF-8') . '">'
                  . htmlspecialchars((string) $rootAncestor['name'], ENT_QUOTES, 'UTF-8') . '</a>';
        $parentTpls = [
            'It is a variation of %s, which itself descends from the root opening %s.',
            'In the opening tree, this line branches off from %s, part of the broader %s system.',
            'The line refines %s, which traces back to the root opening %s.',
        ];
        $parentSentence = sprintf($parentTpls[$rotation % count($parentTpls)], $parentLink, $rootLink);
    } else {
        $parentTpls = [
            'It is a direct variation of %s.',
            'It branches immediately from %s in the opening tree.',
            'The line is a first-level refinement of %s.',
        ];
        $parentSentence = sprintf($parentTpls[$rotation % count($parentTpls)], $parentLink);
    }
}

// 4. Children/variations sentence — phrasing depends on count.
$childSentence = '';
if ($childCount > 0) {
    $sampleNames = array_slice(array_map(static fn ($c) =>
        '<a href="' . htmlspecialchars($baseUrl . I18n::url('/openings/' . $c['slug']), ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($opening_short_name((string) $c['name'], (string) $o['name']), ENT_QUOTES, 'UTF-8')
        . '</a>', $children), 0, 3);
    $sampleStr = implode(', ', $sampleNames);
    $moreSuffix = $childCount > 3 ? ', and others' : '';
    if ($childCount === 1) {
        $childSentence = sprintf('It continues primarily into one named line, %s.', $sampleStr);
    } elseif ($childCount <= 5) {
        $childTpls = [
            'It branches into %d notable variations — %s%s.',
            '%d named variations grow from this line, including %s%s.',
            'The line splits into %d documented sub-systems: %s%s.',
        ];
        $childSentence = sprintf($childTpls[$rotation % count($childTpls)], $childCount, $sampleStr, $moreSuffix);
    } else {
        $childTpls = [
            'It is the root of %d named variations, with %s%s among the most studied.',
            '%d distinct sub-variations branch from this opening; prominent examples include %s%s.',
            'The line has %d documented children — %s%s being widely played.',
        ];
        $childSentence = sprintf($childTpls[$rotation % count($childTpls)], $childCount, $sampleStr, $moreSuffix);
    }
}

// 5. Total-subtree sentence — only when subtree is meaningfully larger.
$subtreeSentence = '';
if ($descendantCount > $childCount) {
    if ($descendantCount >= 100) {
        $subtreeTpls = [
            'This is a deep theory tree with %d total sub-variations across all depths — one of the larger branches in chess theory.',
            'Across every depth of the tree this branch holds %d documented sub-variations, reflecting decades of analytical work.',
        ];
    } elseif ($descendantCount >= 30) {
        $subtreeTpls = [
            'Below the direct variations, the full subtree contains %d documented sub-variations.',
            'Counted recursively, this branch covers %d sub-variations at all depths.',
        ];
    } else {
        $subtreeTpls = [
            'In total, the branch covers %d sub-variations across all depths.',
            'Together the deeper variations number %d across the full subtree.',
        ];
    }
    $subtreeSentence = sprintf($subtreeTpls[$rotation % count($subtreeTpls)], (int) $descendantCount);
}

// 6. Closing CTA — varies phrasing, always present.
$ctaTpls = [
    'Step through the moves on the interactive board below, review the live Lichess statistics, or play this position against Stockfish at one of six difficulty levels.',
    'Use the board to walk through every move, check how the position has scored on Lichess, or take it head-to-head against Stockfish.',
    'Explore the position on the interactive board, study its real-world results from Lichess, and challenge the engine when you are ready.',
];
$cta = $ctaTpls[$rotation % count($ctaTpls)];

$overviewParts = array_filter([$intro, $groupSentence, $moveSentence, $parentSentence, $childSentence, $subtreeSentence, $cta]);
$overviewHtml  = '<p>' . implode('</p><p>', $overviewParts) . '</p>';

$island = [
    'id'          => (int) $o['id'],
    'pgn'         => $o['pgn_moves'],
    'name'        => $o['name'],
    'statsApiUrl' => $baseUrl . '/api/stats',
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

<aside class="recent-strip" id="recent-strip" hidden aria-label="Recently viewed openings">
    <span class="recent-strip-label">Recently viewed:</span>
    <ul class="recent-strip-list" id="recent-strip-list"></ul>
</aside>

<article class="opening">
    <?php if (!empty($ancestors)): ?>
    <?php
    // Each row shows only the "delta" of its name relative to the direct
    // parent — repeating the full chain on every line is unreadable.
    $prevName = null;
    // Build a compact summary string for the closed state so the user sees
    // the rough position without expanding: e.g. "Queen's Pawn › Gambit › Korchnoi".
    $summaryParts = [];
    foreach ($ancestors as $a) {
        $summaryParts[] = $opening_short_name((string) $a['name'], $prevName);
        $prevName = (string) $a['name'];
    }
    $summaryParts[] = $opening_short_name((string) $o['name'], $prevName);
    $compactSummary = implode(' › ', $summaryParts);
    $prevName = null; // reset for the expanded render below
    ?>
    <details class="opening-tree-details">
        <summary class="opening-tree-summary">
            <span class="opening-tree-summary-label">Tree path</span>
            <span class="opening-tree-summary-depth"><?= count($ancestors) + 1 ?> levels</span>
            <span class="opening-tree-summary-preview" title="<?= htmlspecialchars($compactSummary, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($compactSummary, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="opening-tree-summary-chevron" aria-hidden="true">&#9662;</span>
        </summary>
        <div class="opening-tree" aria-label="Position in tree">
            <ol class="opening-tree-list">
                <li class="opening-tree-item" style="--depth: 0;">
                    <a href="<?= $baseEsc . htmlspecialchars(I18n::url('/'), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="opening-tree-icon" aria-hidden="true">&#8962;</span>
                        <span class="opening-tree-name"><?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                </li>
                <?php foreach ($ancestors as $depth => $a):
                    $display = $opening_short_name((string) $a['name'], $prevName);
                ?>
                    <li class="opening-tree-item" style="--depth: <?= $depth + 1 ?>;">
                        <a href="<?= $baseEsc . htmlspecialchars(I18n::url('/openings/' . $a['slug']), ENT_QUOTES, 'UTF-8') ?>"
                           title="<?= htmlspecialchars($a['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <span class="opening-tree-name"><?= htmlspecialchars($display, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="opening-tree-eco eco-tag"><?= htmlspecialchars($a['eco'], ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                    </li>
                <?php
                    $prevName = (string) $a['name'];
                endforeach;
                $currentDisplay = $opening_short_name((string) $o['name'], $prevName);
                ?>
                <li class="opening-tree-item is-current" style="--depth: <?= count($ancestors) + 1 ?>;"
                    aria-current="page"
                    title="<?= htmlspecialchars($o['name'], ENT_QUOTES, 'UTF-8') ?>">
                    <span class="opening-tree-marker" aria-hidden="true">&#9670;</span>
                    <span class="opening-tree-name"><?= htmlspecialchars($currentDisplay, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="opening-tree-eco eco-tag"><?= htmlspecialchars($o['eco'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="opening-tree-here">you are here</span>
                </li>
            </ol>
        </div>
    </details>
    <?php endif; ?>
    <header class="opening-header">
        <a class="eco-tag" href="<?= $baseEsc . htmlspecialchars(I18n::url('/eco/' . $o['eco']), ENT_QUOTES, 'UTF-8') ?>"
           title="All openings under ECO <?= htmlspecialchars($o['eco'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($o['eco'], ENT_QUOTES, 'UTF-8') ?></a>
        <h1><?= htmlspecialchars($o['name'], ENT_QUOTES, 'UTF-8') ?></h1>
        <?php
        // Share row — no third-party JS, no tracking. Each button is a plain
        // intent URL; "Copy link" uses navigator.clipboard via inline JS at
        // the bottom of the page. Hidden on print and very narrow screens.
        $shareUrl  = $siteUrl . $baseUrl . I18n::url('/openings/' . $o['slug']);
        $shareText = $o['name'] . ' (' . $o['eco'] . ') — Chess Codex';
        $shareUrlEnc  = rawurlencode($shareUrl);
        $shareTextEnc = rawurlencode($shareText);
        ?>
        <div class="opening-tools" data-opening-tools
             data-pgn="<?= htmlspecialchars(trim((string) $o['pgn_moves']), ENT_QUOTES, 'UTF-8') ?>"
             data-slug="<?= htmlspecialchars((string) $o['slug'], ENT_QUOTES, 'UTF-8') ?>"
             data-canonical-url="<?= htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8') ?>">
            <button type="button" class="opening-tool-btn" data-tool-copy-pgn
                    title="Copy the PGN move sequence to clipboard">
                <span aria-hidden="true">⎘</span> Copy PGN
            </button>
            <button type="button" class="opening-tool-btn" data-tool-copy-fen disabled
                    title="Copy the FEN of the final position (enabled once board loads)">
                <span aria-hidden="true">⎘</span> Copy FEN
            </button>
            <a class="opening-tool-btn" target="_blank" rel="noopener" data-tool-lichess
               title="Open this position in Lichess analysis (engine + explorer)">
                <span aria-hidden="true">↗</span> Open in Lichess
            </a>
        </div>
        <div class="opening-share" data-share-url="<?= htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8') ?>">
            <span class="opening-share-label">Share:</span>
            <a class="opening-share-btn" target="_blank" rel="noopener nofollow"
               title="Share on Twitter / X"
               href="https://twitter.com/intent/tweet?url=<?= $shareUrlEnc ?>&amp;text=<?= $shareTextEnc ?>">X</a>
            <a class="opening-share-btn" target="_blank" rel="noopener nofollow"
               title="Share on Reddit"
               href="https://www.reddit.com/submit?url=<?= $shareUrlEnc ?>&amp;title=<?= $shareTextEnc ?>">Reddit</a>
            <a class="opening-share-btn" target="_blank" rel="noopener nofollow"
               title="Share on Telegram"
               href="https://t.me/share/url?url=<?= $shareUrlEnc ?>&amp;text=<?= $shareTextEnc ?>">Telegram</a>
            <a class="opening-share-btn" target="_blank" rel="noopener nofollow"
               title="Share on Facebook"
               href="https://www.facebook.com/sharer/sharer.php?u=<?= $shareUrlEnc ?>">Facebook</a>
            <button type="button" class="opening-share-btn opening-share-copy" data-share-copy>Copy link</button>
        </div>
        <?php if ($parent && empty($ancestors)): ?>
            <p class="parent-link"><?= htmlspecialchars(t('opening.parent'), ENT_QUOTES, 'UTF-8') ?>
                <a href="<?= htmlspecialchars($baseUrl . I18n::url('/openings/' . $parent['slug']), ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($parent['name'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            </p>
        <?php endif; ?>
    </header>

    <div class="opening-grid">
        <div class="opening-board-wrap">
            <div id="board" class="opening-board"></div>
            <div class="board-controls">
                <button id="board-reset" type="button"><?= htmlspecialchars(t('opening.board.reset'), ENT_QUOTES, 'UTF-8') ?></button>
                <button id="board-flip" type="button" title="Flip board orientation"
                        aria-label="Flip board orientation">
                    <span aria-hidden="true">⇅</span> Flip
                </button>
                <a class="board-cta" href="<?= htmlspecialchars($baseUrl . I18n::url('/play/' . $o['slug']), ENT_QUOTES, 'UTF-8') ?>"
                   data-prefetch="<?= $baseEsc ?>/vendor/stockfish.js <?= $baseEsc ?>/vendor/stockfish.wasm"><?= htmlspecialchars(t('opening.board.cta'), ENT_QUOTES, 'UTF-8') ?></a>
                <span class="board-hint"><?= htmlspecialchars(t('opening.board.hint'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>

        <aside class="opening-moves">
            <h2><?= htmlspecialchars(t('opening.moves'), ENT_QUOTES, 'UTF-8') ?></h2>
            <ol id="move-list" class="move-list" aria-label="<?= htmlspecialchars(t('opening.moves'), ENT_QUOTES, 'UTF-8') ?>"></ol>
            <p class="move-list-hint">
                Tip: use <kbd class="kbd-hint">←</kbd> <kbd class="kbd-hint">→</kbd> arrow keys to step through moves.
            </p>
        </aside>
    </div>

    <?php if (empty($o['description'])): ?>
    <section class="opening-overview">
        <h2>Overview</h2>
        <div class="opening-overview-body"><?= $overviewHtml ?></div>
        <dl class="opening-facts">
            <div><dt>ECO code</dt><dd><a href="<?= $baseEsc . htmlspecialchars(I18n::url('/eco/' . $o['eco']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $o['eco'], ENT_QUOTES, 'UTF-8') ?></a></dd></div>
            <div><dt>Group</dt><dd><?= htmlspecialchars((string) $ecoGroupLabel, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($ecoGroup, ENT_QUOTES, 'UTF-8') ?>)</dd></div>
            <div><dt>Plies</dt><dd><?= $plies ?></dd></div>
            <?php if ($parent): ?>
                <div><dt>Parent</dt><dd>
                    <a href="<?= htmlspecialchars($baseUrl . I18n::url('/openings/' . $parent['slug']), ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars((string) $parent['name'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </dd></div>
            <?php endif; ?>
            <?php if ($childCount > 0): ?>
                <div><dt>Direct variations</dt><dd><?= $childCount ?></dd></div>
            <?php endif; ?>
            <?php if ($descendantCount > $childCount): ?>
                <div><dt>Total in subtree</dt><dd><?= $descendantCount ?></dd></div>
            <?php endif; ?>
        </dl>
        <p class="opening-overview-notice">
            <span class="opening-overview-notice-icon" aria-hidden="true">&#9998;</span>
            <span>
                <strong>This overview is generated automatically</strong> from the opening's metadata.
                Know this line well? <a href="#suggest-form-details" class="opening-overview-notice-cta">Write
                a description</a> — submissions are reviewed before publishing.
            </span>
        </p>
    </section>
    <?php endif; ?>

    <?php
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
        <div class="stats-bar" role="img"<?= $statsTotal > 0 ? ' aria-label="' . htmlspecialchars($barLabel($wPct, $dPct, $bPct), ENT_QUOTES, 'UTF-8') . '"' : ' hidden' ?>>
            <span class="stats-bar-w" style="width:<?= $wPct ?>%"></span>
            <span class="stats-bar-d" style="width:<?= $dPct ?>%"></span>
            <span class="stats-bar-b" style="width:<?= $bPct ?>%"></span>
        </div>
        <p class="stats-totals"<?= $statsTotal > 0 ? '' : ' hidden' ?>><?php if ($statsTotal > 0): ?><?= number_format($statsTotal) ?> games · White <?= $wPct ?>% / Draw <?= $dPct ?>% / Black <?= $bPct ?>%<?php endif; ?></p>
        <table class="stats-moves"<?= $statsTotal > 0 && $statsRows ? '' : ' hidden' ?>>
            <thead>
                <tr><th><?= htmlspecialchars(t('opening.moves'), ENT_QUOTES, 'UTF-8') ?></th><th>Games</th><th>W / D / B</th></tr>
            </thead>
            <tbody><?php foreach ($statsTotal > 0 ? $statsRows : [] as [$san, $mt, $mw, $md, $mb]): ?>
                <tr><td><?= htmlspecialchars((string) $san, ENT_QUOTES, 'UTF-8') ?></td><td><?= number_format($mt) ?></td><td><div class="stats-bar inline" role="img" aria-label="<?= htmlspecialchars($barLabel($mw, $md, $mb), ENT_QUOTES, 'UTF-8') ?>"><span class="stats-bar-w" style="width:<?= $mw ?>%"></span><span class="stats-bar-d" style="width:<?= $md ?>%"></span><span class="stats-bar-b" style="width:<?= $mb ?>%"></span></div></td></tr>
            <?php endforeach; ?></tbody>
        </table>
        <p class="stats-attribution"<?= $statsTotal > 0 ? '' : ' hidden' ?>><small><?= htmlspecialchars(t('opening.stats.attribution'), ENT_QUOTES, 'UTF-8') ?> <span class="stats-cached-at"><?= $statsTotal > 0 ? htmlspecialchars((string) $stats['cached_at'], ENT_QUOTES, 'UTF-8') : '' ?></span></small></p>
    </section>

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

        <details class="suggest-form" id="suggest-form-details">
            <summary>
                <?= empty($o['description'])
                    ? 'Write a description'
                    : 'Suggest an improvement' ?>
            </summary>
            <div class="suggest-form-body">
                <p class="suggest-hint">
                    Markdown supported (headings <code>###</code>, <strong>bold</strong>, lists, links).
                    Submissions are reviewed before publishing.
                </p>
                <form id="suggest-form" method="post" action="<?= $baseEsc ?>/api/suggest">
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
                    <label>Description
                        <textarea name="markdown" rows="10" required minlength="30" maxlength="5000"
                                  placeholder="### Origin&#10;…&#10;&#10;### Strategic ideas&#10;…"></textarea>
                    </label>
                    <p class="suggest-actions">
                        <button type="submit">Submit for review</button>
                        <span class="suggest-status" aria-live="polite"></span>
                    </p>
                </form>
            </div>
        </details>
    </section>

    <?php if ($children): ?>
    <?php $pageSize = 20; $needsToggle = count($children) > $pageSize; ?>
    <section class="opening-children" data-children-collapsed="<?= $needsToggle ? '1' : '0' ?>">
        <h2><?= htmlspecialchars(t('opening.variations', ['count' => count($children)]), ENT_QUOTES, 'UTF-8') ?></h2>
        <ul class="child-list">
            <?php foreach ($children as $i => $c):
                $childDisplay = $opening_short_name((string) $c['name'], (string) $o['name']);
                $childPly     = (int) ($c['move_count'] ?? 0);
            ?>
                <li<?= ($needsToggle && $i >= $pageSize) ? ' class="is-overflow" hidden' : '' ?>>
                    <a href="<?= htmlspecialchars($baseUrl . I18n::url('/openings/' . $c['slug']), ENT_QUOTES, 'UTF-8') ?>"
                       title="<?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?> (<?= $childPly ?>-ply)">
                        <span class="eco-tag"><?= htmlspecialchars($c['eco'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="child-list-name"><?= htmlspecialchars($childDisplay, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($childPly > 0): ?>
                            <span class="child-list-plies"><?= $childPly ?>-ply</span>
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

    <?php if (!empty($siblings)): ?>
    <section class="opening-related">
        <h2>Related variations <span class="opening-related-meta">at the same level</span></h2>
        <p class="opening-related-lede">Sister lines that share the same parent <?= $parent ? '<a href="' . htmlspecialchars($baseUrl . I18n::url('/openings/' . $parent['slug']), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $parent['name'], ENT_QUOTES, 'UTF-8') . '</a>' : 'opening' ?>:</p>
        <ul class="child-list opening-related-list">
            <?php foreach ($siblings as $s):
                $sibDisplay = $opening_short_name((string) $s['name'], $parent['name'] ?? null);
                $sibPly     = (int) ($s['move_count'] ?? 0);
            ?>
                <li>
                    <a href="<?= htmlspecialchars($baseUrl . I18n::url('/openings/' . $s['slug']), ENT_QUOTES, 'UTF-8') ?>"
                       title="<?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?> (<?= $sibPly ?>-ply)">
                        <span class="eco-tag"><?= htmlspecialchars($s['eco'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="child-list-name"><?= htmlspecialchars($sibDisplay, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($sibPly > 0): ?>
                            <span class="child-list-plies"><?= $sibPly ?>-ply</span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
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
                Show all <?= (int) $descendantCount ?> sub-variations (full subtree)
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
                                <span class="opening-subtree-plies"><?= (int) $d['move_count'] ?>-ply</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </details>
    </section>
    <?php endif; ?>
</article>

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

// SEO description: front-load the keywords search engines and humans both
// scan — full name, ECO code, group, plies, opening moves, variations
// count. layout.php trims it to 160 chars at a word boundary.
// The first moves, cut after a whole move ("1. e4 e5 2. Nf3 Nc6…").
$moveSnippet = $movesPretty;
if (mb_strlen($moveSnippet) > 24) {
    $cut = mb_substr($moveSnippet, 0, 25);
    $cut = mb_substr($cut, 0, (int) mb_strrpos($cut, ' '));
    $moveSnippet = preg_replace('/\s*\d+\.+$/', '', $cut) . '…';   // no dangling "3."
}
$descParts = [];
$descParts[] = $o['name'] . ' (ECO ' . $o['eco'] . ', ' . $ecoGroupLabel . ')';
// Same-name lines share their first moves; their ending is what differs.
$descParts[] = $lineTail !== ''
    ? $plyCountText . ' line ending ' . $lineTail
    : $plyCountText . ' chess opening starting ' . $moveSnippet;
if ($parent && empty($ancestors) === false) {
    $descParts[] = 'variation of ' . $parent['name'];
}
if ($childCount > 0) {
    $descParts[] = $childCount . ($childCount === 1 ? ' variation' : ' variations');
}
$description = implode(' · ', $descParts) . '. Interactive board, Lichess statistics, play vs Stockfish.';

// OG image absolute URL — also reused inside JSON-LD as `image`.
$ogImageUrl = $siteUrl . $baseUrl . '/og.php?slug=' . urlencode($o['slug']);

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
                '@type'  => 'ImageObject',
                'url'    => $ogImageUrl,
                'width'  => 1200,
                'height' => 630,
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
            // Edits aren't tracked, so dateModified stays at the publish
            // date: "today" on every crawl is a fake freshness signal.
            'datePublished' => '2026-01-01T00:00:00+01:00',
            'dateModified'  => '2026-01-01T00:00:00+01:00',
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
