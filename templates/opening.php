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
/** @var array<string, mixed> $page  OpeningPage::build(): what the page prints, by the names below */
ob_start();

// The partials (templates/partials/opening_*.php) and layout.php read these by name too.
[
    'title'              => $title,
    'lineTail'           => $lineTail,
    'aliases'            => $aliases,
    'lead'               => $lead,
    'crumbs'             => $crumbs,
    'currentCrumb'       => $currentCrumb,
    'plies'              => $plies,
    'diagramUrl'         => $diagramUrl,
    'lastMoveText'       => $lastMoveText,
    'movePairs'          => $movePairs,
    'shareUrl'           => $shareUrl,
    'shareText'          => $shareText,
    'ownSide'            => $ownSide,
    'stats'              => $stats,
    'statsTotal'         => $statsTotal,
    'statsRows'          => $statsRows,
    'wPct'               => $wPct,
    'dPct'               => $dPct,
    'bPct'               => $bPct,
    'pct'                => $pct,
    'barLabel'           => $barLabel,
    'trans'              => $trans,
    'nextLines'          => $nextLines,
    'overviewHtml'       => $overviewHtml,
    'renderedDesc'       => $renderedDesc,
    'toc'                => $toc,
    'answersTitle'       => $answersTitle,
    'answersHtml'        => $answersHtml,
    'whiteToMove'        => $whiteToMove,
    'yearsSentence'      => $yearsSentence,
    'yearsChart'         => $yearsChart,
    'yearsLast'          => $yearsLast,
    'yearsUntil'         => $yearsUntil,
    'opening_short_name' => $opening_short_name,
    'opening_crumb'      => $opening_crumb,
    'island'             => $island,
    'canonical'          => $canonical,
    'noindex'            => $noindex,
    'description'        => $description,
    'jsonLd'             => $jsonLd,
] = $page;
$o       = $opening;
$baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
?>
<!-- Hidden data for the Recently viewed history. JS reads this on every
     opening page load and appends to localStorage['codex-recent']. -->
<meta name="codex-current-opening"
      data-slug="<?= htmlspecialchars((string) $o['slug'], ENT_QUOTES, 'UTF-8') ?>"
      data-name="<?= htmlspecialchars((string) $o['name'], ENT_QUOTES, 'UTF-8') ?>"
      data-eco="<?= htmlspecialchars((string) $o['eco'], ENT_QUOTES, 'UTF-8') ?>">

<article class="opening">
    <?php /* Each name once, as its shortest line, without what the one before
             it said (OpeningPage::crumb()). */ ?>
    <nav class="opening-breadcrumb" aria-label="Breadcrumb">
        <ol>
            <li><a href="<?= $baseEsc . htmlspecialchars('/openings', ENT_QUOTES, 'UTF-8') ?>">Openings</a></li>
            <?php foreach ($crumbs as [$a, $label]): ?>
                <li><a href="<?= $baseEsc . htmlspecialchars('/openings/' . $a['slug'], ENT_QUOTES, 'UTF-8') ?>"
                       title="<?= htmlspecialchars($a['name'] . ' (' . $a['eco'] . ')', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a></li>
            <?php endforeach; ?>
            <li aria-current="page"><?= htmlspecialchars($currentCrumb, ENT_QUOTES, 'UTF-8') ?></li>
        </ol>
    </nav>
    <header class="opening-header">
        <a class="eco-tag" href="<?= $baseEsc . htmlspecialchars('/eco/' . $o['eco'], ENT_QUOTES, 'UTF-8') ?>"
           title="All openings under ECO <?= htmlspecialchars($o['eco'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($o['eco'], ENT_QUOTES, 'UTF-8') ?></a>
        <h1><?= htmlspecialchars($o['name'], ENT_QUOTES, 'UTF-8') ?><?php if ($lineTail !== ''): ?>
            <?php /* Same-name lines: the moves that tell this one apart, as in the title. */ ?>
            <span class="opening-title-tail"><?= htmlspecialchars($lineTail, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></h1>
        <?php if ($aliases): ?>
            <p class="opening-aka">Also known as <?= implode(' or ', array_map(
                static fn (string $a): string => '<strong>' . htmlspecialchars($a, ENT_QUOTES, 'UTF-8') . '</strong>', $aliases)) ?></p>
        <?php endif; ?>
        <?php if ($lead !== null): ?>
            <p class="opening-lead"><?= htmlspecialchars($lead, ENT_QUOTES, 'UTF-8') ?>
                <a href="#description">Read the guide</a></p>
        <?php endif; ?>
    </header>

    <?php /* Share and export — no third-party JS, no tracking: copy buttons, the
             device's own share sheet where the browser has one (app.js), the PGN. */ ?>
    <div class="opening-grid">
        <div class="opening-board-wrap">
            <div id="board" class="opening-board"><img class="board-still" src="<?= htmlspecialchars($diagramUrl, ENT_QUOTES, 'UTF-8') ?>" width="720" height="720"
                 fetchpriority="high" alt="Chess diagram: <?= htmlspecialchars((string) $o['name'], ENT_QUOTES, 'UTF-8') ?><?= $lastMoveText !== '' ? ' after ' . htmlspecialchars($lastMoveText, ENT_QUOTES, 'UTF-8') : '' ?>"></div>
            <div class="board-controls" role="group" aria-label="Board">
                <button id="board-reset" type="button" title="Back to the starting position"><?= htmlspecialchars(t('opening.board.reset'), ENT_QUOTES, 'UTF-8') ?></button>
                <button id="board-prev" type="button" class="board-step" aria-label="Previous move" title="Previous move (←)">‹</button>
                <button id="board-next" type="button" class="board-step" aria-label="Next move" title="Next move (→)">›</button>
                <button id="board-flip" type="button" title="Turn the board around">
                    <span aria-hidden="true">⇅</span> Flip
                </button>
            </div>
            <p class="board-explore" id="board-explore" aria-live="polite" hidden></p>
            <p class="board-hint"><?= htmlspecialchars(t('opening.board.hint'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <aside class="opening-side">
            <h2><?= htmlspecialchars(t('opening.moves'), ENT_QUOTES, 'UTF-8') ?></h2>
            <?php /* The same list app.js builds (and rebuilds), so the line's moves are in the HTML. */ ?>
            <ol id="move-list" class="move-list move-list-paired" aria-label="<?= htmlspecialchars(t('opening.moves'), ENT_QUOTES, 'UTF-8') ?>">
                <?php foreach ($movePairs as $k => $pairSans): ?>
                <li><span class="move-no"><?= $k + 1 ?>.</span><?php foreach ($pairSans as $j => $san): ?><button type="button" data-idx="<?= 2 * $k + $j + 1 ?>"><?= htmlspecialchars($san, ENT_QUOTES, 'UTF-8') ?></button><?php endforeach; ?></li>
                <?php endforeach; ?>
            </ol>

            <div class="opening-actions">
                <?php /* nofollow: the 3,690 play pages are noindex, no crawl needed; so are the
                         trainers, except those of the described lines (templates/train.php). */ ?>
                <a class="board-cta" rel="nofollow" href="<?= htmlspecialchars($baseUrl . '/play/' . $o['slug'], ENT_QUOTES, 'UTF-8') ?>"
                   data-prefetch="<?= $baseEsc ?>/vendor/stockfish.js?v=<?= substr((string) @hash_file('xxh3', __DIR__ . '/../vendor/stockfish.js'), 0, 8) ?> <?= $baseEsc ?>/vendor/stockfish.wasm"><?= htmlspecialchars(t('opening.board.cta'), ENT_QUOTES, 'UTF-8') ?></a>
                <a class="board-cta board-cta-secondary"<?= empty($o['description']) ? ' rel="nofollow"' : '' ?>
                   href="<?= htmlspecialchars($baseUrl . '/train/' . $o['slug'], ENT_QUOTES, 'UTF-8') ?>"
                   title="Play this line's moves from memory, with review on a schedule">Practice the line</a>
                <div class="repertoire-toggle"
                     data-slug="<?= htmlspecialchars((string) $o['slug'], ENT_QUOTES, 'UTF-8') ?>"
                     data-name="<?= htmlspecialchars((string) $o['name'], ENT_QUOTES, 'UTF-8') ?>"
                     data-eco="<?= htmlspecialchars((string) $o['eco'], ENT_QUOTES, 'UTF-8') ?>"
                     data-pgn="<?= htmlspecialchars(trim((string) $o['pgn_moves']), ENT_QUOTES, 'UTF-8') ?>">
                    <a class="repertoire-toggle-label" rel="nofollow" href="<?= htmlspecialchars($baseUrl . '/repertoire', ENT_QUOTES, 'UTF-8') ?>">My repertoire:</a>
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
                        <a href="<?= $baseEsc . htmlspecialchars('/openings/' . $o['slug'] . '.pgn', ENT_QUOTES, 'UTF-8') ?>"
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

        <?php require __DIR__ . '/partials/opening_stats.php'; ?>

        <?php /* In the grid: on a wide screen it fills the board's column under the board. */ ?>
        <?php require __DIR__ . '/partials/opening_levels.php'; ?>
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
                            A mistake on this page (moves, name, statistics…)</label>
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
                    <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true"
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
        <div class="opening-overview-body"><?= $overviewHtml ?></div>
        <div class="opening-overview-notice">
            <p>Written from the Lichess opening list and explorer. Know this line well? Write about it.</p>
            <?php $renderSuggest(); ?>
        </div>
    </section>
    <?php endif; ?>


    <section class="opening-description" id="description">
        <?php /* No "Description" heading without a description: an empty
                 section on every page reads as thin content. */ ?>
        <?php if (!empty($o['description'])): ?>
        <h2>
            <?= htmlspecialchars(t('opening.description.title'), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <?php endif; ?>
        <?php if (!empty($o['description'])): ?>
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

    <?php if ($answersTitle !== null): ?>
    <section class="opening-answers" aria-labelledby="opening-answers-title">
        <h2 id="opening-answers-title"><?= htmlspecialchars($answersTitle, ENT_QUOTES, 'UTF-8') ?></h2>
        <p><?= $answersHtml ?></p>
        <p class="opening-answers-note"><small>Main answers: those played in at least 5% of these games. Score: <?= $whiteToMove ? 'White' : 'Black' ?>'s wins
            plus half the draws, in rated Lichess games between players rated 1600 to 2500.
            <a href="<?= $baseEsc . htmlspecialchars('/how-to-play-against', ENT_QUOTES, 'UTF-8') ?>">How to play against other popular openings</a></small></p>
    </section>
    <?php endif; ?>

    <?php if ($yearsSentence !== null): ?>
    <section class="opening-years" aria-labelledby="opening-years-title">
        <h2 id="opening-years-title">Popularity by year</h2>
        <p><?= htmlspecialchars($yearsSentence, ENT_QUOTES, 'UTF-8') ?></p>
        <?= $yearsChart ?>
        <p class="trend-caption">Share of rated Lichess games between players rated 1600 to 2500 (blitz, rapid and classical)
            that reach this position, by year<?= $yearsUntil !== '' ? '; ' . $yearsLast . ': January to ' . $yearsUntil : '' ?>.
            <a href="<?= $baseEsc . htmlspecialchars('/opening-trends', ENT_QUOTES, 'UTF-8') ?>">Which openings are rising and falling</a></p>
    </section>
    <?php endif; ?>


    <?php require __DIR__ . '/partials/opening_lines.php'; ?>
</article>

<aside class="recent-strip" id="recent-strip" hidden aria-label="Recently viewed openings">
    <h2 class="section-label">Recently viewed</h2>
    <ul class="recent-strip-list" id="recent-strip-list"></ul>
</aside>

<script type="module" src="<?= $baseEsc ?>/public/opening.min.js?v=<?= @filemtime(__DIR__ . '/../public/opening.min.js') ?: 1 ?>"></script>
<?php if ($yearsSentence !== null): ?>
<script type="module" src="<?= $baseEsc ?>/public/charts.min.js?v=<?= @filemtime(__DIR__ . '/../public/charts.min.js') ?: 1 ?>"></script>
<?php endif; ?>

<script type="application/json" id="opening-data">
<?= htmlspecialchars(json_encode($island, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ENT_NOQUOTES, 'UTF-8') ?>
</script>
<script type="module" src="<?= $baseEsc ?>/public/app.min.js?v=<?= @filemtime(__DIR__ . '/../public/app.min.js') ?: 1 ?>"></script>
<?php
$body = ob_get_clean();
$needsBoard = true;
// OG type "article" gives Facebook / LinkedIn / Telegram richer cards
// (author, published time) than the default "website".
$ogType = 'article';

require __DIR__ . '/layout.php';
