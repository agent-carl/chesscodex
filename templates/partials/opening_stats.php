<?php
/**
 * An opening page's Lichess statistics: the result bar and the table of
 * next moves (second column beside the board on a wide screen).
 * Included by templates/opening.php, in its scope (all its variables).
 */
?>
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
                    <tr><th>Next move</th><th>Games</th><th><span class="th-long">White / Draw / Black</span><span class="th-short">W / D / B</span></th></tr>
                </thead>
                <tbody><?php foreach ($statsTotal > 0 ? $statsRows : [] as [$san, $mt, $mw, $md, $mb]):
                    $next = $nextLines[$san] ?? null; ?>
                    <tr>
                        <td class="stats-move">
                            <button type="button" class="stats-move-btn" data-san="<?= htmlspecialchars((string) $san, ENT_QUOTES, 'UTF-8') ?>"
                                    title="Show <?= htmlspecialchars($nextMoveNo . $san, ENT_QUOTES, 'UTF-8') ?> on the board"><?= htmlspecialchars($nextMoveNo . $san, ENT_QUOTES, 'UTF-8') ?></button>
                            <?php if ($next): ?><a class="stats-move-line" href="<?= htmlspecialchars($next['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($next['name'], ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
                        </td>
                        <td><span class="n-long"><?= number_format($mt) ?></span><span class="n-short"><?= htmlspecialchars(Rankings::compact((int) $mt), ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><div class="stats-bar inline" role="img" aria-label="<?= htmlspecialchars($barLabel($mw, $md, $mb), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($barLabel($mw, $md, $mb), ENT_QUOTES, 'UTF-8') ?>"><span class="stats-bar-w" style="width:<?= $mw ?>%"></span><span class="stats-bar-d" style="width:<?= $md ?>%"></span><span class="stats-bar-b" style="width:<?= $mb ?>%"></span></div>
                            <span class="stats-pcts" aria-hidden="true"><?= $pctsText($mw, $md, $mb) ?></span></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table>
            <p class="stats-attribution"<?= $statsTotal > 0 ? '' : ' hidden' ?>><small><?= htmlspecialchars(t('opening.stats.attribution'), ENT_QUOTES, 'UTF-8') ?> <span class="stats-cached-at"><?= $statsTotal > 0 ? htmlspecialchars($updated((string) $stats['cached_at']), ENT_QUOTES, 'UTF-8') : '' ?></span></small></p>
        </section>
