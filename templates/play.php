<?php
/** @var array $opening */
/** @var string $baseUrl */
/** @var string $siteUrl */
ob_start();

$o = $opening;
$baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');

$island = [
    'pgn'             => $o['pgn_moves'],
    'name'            => $o['name'],
    'eco'             => $o['eco'],
    'slug'            => $o['slug'],
    // Versioned like every other asset: the file is served "immutable".
    'stockfishJsUrl'  => $baseUrl . '/vendor/stockfish.js?v=' . substr((string) @hash_file('xxh3', __DIR__ . '/../vendor/stockfish.js'), 0, 8),
    'stockfishWasmUrl' => $baseUrl . '/vendor/stockfish.wasm',
    'openingUrl'      => $baseUrl . I18n::url('/openings/' . $o['slug']),
    'i18n'            => [
        'thinking'  => t('play.engine.thinking'),
        'ready'     => t('play.engine.ready'),
        'failed'    => t('play.engine.failed'),
        'turn_you'  => t('play.turn.you'),
        'turn_engine'=> t('play.turn.engine'),
        'white'     => t('play.color.white'),
        'black'     => t('play.color.black'),
        'r_check'   => t('play.result.checkmate'),
        'r_stale'   => t('play.result.stalemate'),
        'r_repeat'  => t('play.result.repetition'),
        'r_material'=> t('play.result.material'),
        'r_draw'    => t('play.result.draw'),
        'r_resigned'=> t('play.result.resigned'),
    ],
];
?>
<article class="play">
    <header class="play-header">
        <span class="eco-tag"><?= htmlspecialchars($o['eco'], ENT_QUOTES, 'UTF-8') ?></span>
        <h1><?= htmlspecialchars(t('play.h1', ['name' => $o['name']]), ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="parent-link">
            <a href="<?= $baseEsc . htmlspecialchars(I18n::url('/openings/' . $o['slug']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('play.back'), ENT_QUOTES, 'UTF-8') ?></a>
        </p>
    </header>

    <div class="play-color-pick" role="radiogroup" aria-label="Pick your color">
        <span class="play-color-pick-label">Play as:</span>
        <button type="button" class="play-color-btn is-active" data-play-color="white" aria-pressed="true">
            <span class="play-color-piece" aria-hidden="true">&#9817;&#xFE0E;</span> White
        </button>
        <button type="button" class="play-color-btn" data-play-color="black" aria-pressed="false">
            <span class="play-color-piece" aria-hidden="true">&#9823;&#xFE0E;</span> Black
        </button>
    </div>

    <div class="play-grid">
        <div class="play-board-wrap">
            <div id="play-board" class="opening-board"></div>
            <div class="board-controls">
                <button id="play-new" type="button"><?= htmlspecialchars(t('play.button.new'), ENT_QUOTES, 'UTF-8') ?></button>
                <button id="play-undo" type="button"><?= htmlspecialchars(t('play.button.undo'), ENT_QUOTES, 'UTF-8') ?></button>
                <button id="play-flip" type="button"><?= htmlspecialchars(t('play.button.flip'), ENT_QUOTES, 'UTF-8') ?></button>
                <button id="play-resign" type="button"><?= htmlspecialchars(t('play.button.resign'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </div>

        <aside class="play-sidebar">
            <section class="play-game" id="play-game">
                <h2><?= htmlspecialchars(t('play.game'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="play-turn" aria-live="polite"></p>
                <p class="play-result" hidden role="status" aria-live="assertive"></p>
                <p class="play-eval" hidden></p>
                <label class="play-eval-toggle"><input type="checkbox" id="play-show-eval"> Show the engine's evaluation</label>
                <ol id="play-moves" class="move-list" aria-label="<?= htmlspecialchars(t('play.game'), ENT_QUOTES, 'UTF-8') ?>"></ol>
                <p class="play-export">
                    <button type="button" id="play-copy-pgn">Copy PGN</button>
                    <a id="play-lichess" href="https://lichess.org/analysis" target="_blank" rel="noopener">Analyse on Lichess ↗</a>
                </p>
            </section>

            <section class="play-status" id="play-status">
                <h2><?= htmlspecialchars(t('play.engine'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="play-engine-state" data-state="loading" aria-live="polite"><?= htmlspecialchars(t('play.engine.loading'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="play-current-level" id="play-current-level">
                    <?= htmlspecialchars(t('play.difficulty'), ENT_QUOTES, 'UTF-8') ?>
                    <strong class="play-current-level-name"><?= htmlspecialchars(t('play.diff.intermediate'), ENT_QUOTES, 'UTF-8') ?></strong>
                    <span class="play-current-level-elo"><?= htmlspecialchars(t('play.difficulty.elo', ['elo' => '~1900']), ENT_QUOTES, 'UTF-8') ?></span>
                </p>
                <div class="play-difficulty" id="play-difficulty" role="radiogroup" aria-label="Engine difficulty">
                    <button type="button" data-level="0"  data-mt="100"  data-novice="1" data-name="<?= htmlspecialchars(t('play.diff.novice'), ENT_QUOTES, 'UTF-8') ?>"       data-elo="~600"  data-desc="<?= htmlspecialchars(t('play.diff.novice.desc'), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="diff-name"><?= htmlspecialchars(t('play.diff.novice'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="diff-elo">~600</span>
                    </button>
                    <button type="button" data-level="0"  data-mt="200"  data-name="<?= htmlspecialchars(t('play.diff.beginner'), ENT_QUOTES, 'UTF-8') ?>"     data-elo="~1100" data-desc="<?= htmlspecialchars(t('play.diff.beginner.desc'), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="diff-name"><?= htmlspecialchars(t('play.diff.beginner'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="diff-elo">~1100</span>
                    </button>
                    <button type="button" data-level="5"  data-mt="500"  data-name="<?= htmlspecialchars(t('play.diff.casual'), ENT_QUOTES, 'UTF-8') ?>"       data-elo="~1500" data-desc="<?= htmlspecialchars(t('play.diff.casual.desc'), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="diff-name"><?= htmlspecialchars(t('play.diff.casual'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="diff-elo">~1500</span>
                    </button>
                    <button type="button" data-level="10" data-mt="1000" data-name="<?= htmlspecialchars(t('play.diff.intermediate'), ENT_QUOTES, 'UTF-8') ?>" data-elo="~1900" data-desc="<?= htmlspecialchars(t('play.diff.intermediate.desc'), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="diff-name"><?= htmlspecialchars(t('play.diff.intermediate'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="diff-elo">~1900</span>
                    </button>
                    <button type="button" data-level="15" data-mt="1500" data-name="<?= htmlspecialchars(t('play.diff.strong'), ENT_QUOTES, 'UTF-8') ?>"       data-elo="~2300" data-desc="<?= htmlspecialchars(t('play.diff.strong.desc'), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="diff-name"><?= htmlspecialchars(t('play.diff.strong'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="diff-elo">~2300</span>
                    </button>
                    <button type="button" data-level="20" data-mt="2500" data-name="<?= htmlspecialchars(t('play.diff.master'), ENT_QUOTES, 'UTF-8') ?>"       data-elo="~2700+" data-desc="<?= htmlspecialchars(t('play.diff.master.desc'), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="diff-name"><?= htmlspecialchars(t('play.diff.master'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="diff-elo">~2700+</span>
                    </button>
                </div>
                <p class="play-difficulty-desc" id="play-difficulty-desc"><?= htmlspecialchars(t('play.diff.intermediate.desc'), ENT_QUOTES, 'UTF-8') ?></p>
            </section>

        </aside>
    </div>
</article>

<script type="application/json" id="play-data">
<?= htmlspecialchars(json_encode($island, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_NOQUOTES, 'UTF-8') ?>
</script>
<script type="module" src="<?= $baseEsc ?>/public/play.min.js?v=<?= @filemtime(__DIR__ . '/../public/play.min.js') ?: 1 ?>"></script>
<?php
$body = ob_get_clean();
$needsBoard = true;
$title = t('play.title', ['name' => $o['name']]);
$description = t('play.description', ['name' => $o['name']]);
$noindex = true;
require __DIR__ . '/layout.php';
