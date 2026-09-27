<?php
/**
 * Variations, other lines from the same position, transpositions and the
 * whole sub-variation tree of an opening page.
 * Included by templates/opening.php, in its scope (all its variables).
 */
?>
    <?php
    // A variation's label under this line: its name without the part this
    // page's name already says — or, when Lichess gives it this very name, the
    // moves that make it different ("6. Bg5"). The meta is the move(s) from
    // this position that reach it.
    $variationLabel = static function (array $c, string $underName, int $fromPly) use ($opening_crumb): array {
        $moves = Opening::movesFrom((string) $c['pgn_moves'], $fromPly);
        $label = $opening_crumb((string) $c['name'], $underName);
        if ((string) $c['name'] === $underName) {
            $parts = preg_split('/: |, /', (string) $c['name']) ?: [(string) $c['name']];
            $label = (string) end($parts);
        }
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
                        <?php if ($childMoves !== ''): ?>
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
                        <?php if ($sibMoves !== ''): ?>
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
            <?php if ($descendantCount >= 20): ?>
                <input type="search" class="opening-subtree-filter" placeholder="Filter these <?= (int) $descendantCount ?> lines by name or ECO code"
                       aria-label="Filter the lines" autocomplete="off" spellcheck="false">
                <p class="opening-subtree-none" hidden>No line matches.</p>
            <?php endif; ?>
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
                        // Same name as its parent: the last part of it, like the Variations list.
                        if ((string) $d['name'] === $parentName) {
                            $nameParts = preg_split('/: |, /', (string) $d['name']) ?: [(string) $d['name']];
                            $display   = (string) end($nameParts);
                        }
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
