<?php
/**
 * Stockfish's evaluation of the line's final position, under the board
 * (EngineEval, computed on the Pi by tools/engine-eval.php). Nothing until
 * the position has been evaluated.
 * Included by templates/opening.php, in its scope (all its variables).
 */
$eval = EngineEval::forFen((string) ($o['fen'] ?? ''));
if ($eval):
    $score = EngineEval::scoreText($eval);
    $lean  = $eval['mate'] !== null ? $eval['mate'] : ($eval['cp'] >= 30 ? 1 : ($eval['cp'] <= -30 ? -1 : 0));
    ?>
            <section class="engine-eval" aria-labelledby="engine-eval-title">
                <h2 id="engine-eval-title" class="engine-eval-title">Engine evaluation</h2>
                <p class="engine-eval-score">
                    <span class="engine-eval-num" data-lean="<?= $lean > 0 ? 'white' : ($lean < 0 ? 'black' : 'equal') ?>"><?= htmlspecialchars($score, ENT_QUOTES, 'UTF-8') ?></span>
                    <?= htmlspecialchars(EngineEval::verdict($eval), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <p class="engine-eval-line">Best play: <span class="engine-eval-pv"><?= htmlspecialchars(EngineEval::lineText((string) $o['fen'], $eval['pv']), ENT_QUOTES, 'UTF-8') ?></span></p>
                <p class="engine-eval-src"><small><?= htmlspecialchars($eval['engine'], ENT_QUOTES, 'UTF-8') ?>, depth <?= (int) $eval['depth'] ?>,
                    after the line's last move · scores are in pawns, from White's side</small></p>
            </section>
<?php endif; ?>
