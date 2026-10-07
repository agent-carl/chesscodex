<?php
/**
 * Stockfish's evaluation of the line's final position (EngineEval, computed
 * on the Pi by tools/engine-eval.php), at the end of the Lichess statistics
 * column. Nothing until the position has been evaluated.
 * Included by templates/partials/opening_stats.php, in the page's scope.
 */
$eval = EngineEval::forFen((string) ($o['fen'] ?? ''));
if ($eval):
    $score = EngineEval::scoreText($eval);
    $lean  = $eval['mate'] !== null ? $eval['mate'] : ($eval['cp'] >= 30 ? 1 : ($eval['cp'] <= -30 ? -1 : 0));
    $practice = $statsTotal > 0 ? EngineEval::practice($eval, $stats) : null;
    ?>
            <section class="engine-eval" aria-labelledby="engine-eval-title">
                <h2 id="engine-eval-title" class="engine-eval-title">Engine evaluation</h2>
                <p class="engine-eval-score">
                    <span class="engine-eval-num" data-lean="<?= $lean > 0 ? 'white' : ($lean < 0 ? 'black' : 'equal') ?>"><?= htmlspecialchars($score, ENT_QUOTES, 'UTF-8') ?></span>
                    <?= htmlspecialchars(EngineEval::verdict($eval), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <?php if ($practice !== null): ?><p class="engine-eval-practice"><?= htmlspecialchars($practice, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                <p class="engine-eval-line">Best play: <span class="engine-eval-pv"><?= htmlspecialchars(EngineEval::lineText((string) $o['fen'], $eval['pv']), ENT_QUOTES, 'UTF-8') ?></span></p>
                <p class="engine-eval-src"><small><?= htmlspecialchars($eval['engine'], ENT_QUOTES, 'UTF-8') ?>, depth <?= (int) $eval['depth'] ?>,
                    after the line's last move · scores are in pawns, from White's side</small></p>
            </section>
<?php endif; ?>
