<?php
/**
 * An opening page's results by rating band and in master games.
 * Included by templates/opening.php, in its scope (all its variables).
 */
?>
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
    // The side whose move ends the line is the one that chooses it.
    $trend = $levels ? LevelStats::trend($levels, $plies % 2 === 1 ? 'white' : 'black') : null;
    if ($levels): ?>
    <section class="opening-levels">
        <h2>By rating</h2>
        <?php if ($trend !== null): ?><p class="levels-trend"><?= htmlspecialchars($trend, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <table class="stats-moves stats-levels">
            <thead><tr><th>Players</th><th>Games</th><th><span class="th-long">White / Draw / Black</span><span class="th-short">W / D / B</span></th></tr></thead>
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
