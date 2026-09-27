<?php
/** @var string $baseUrl */
/** @var string $siteUrl */
/** @var string $title */
/** @var string $failedSlug   Slug from the failed URL, if it looked like an opening. */
/** @var array  $suggestions  Up to 5 similar opening rows. */
$baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
ob_start();
?>
<article class="error-page">
    <div class="error-page-icon" aria-hidden="true">&#9818;&#xFE0E;</div>
    <h1>Page not found</h1>

    <?php if ($failedSlug !== ''): ?>
        <p class="lede">
            We couldn't find an opening at
            <code><?= htmlspecialchars('/openings/' . $failedSlug, ENT_QUOTES, 'UTF-8') ?></code>.
        </p>
    <?php else: ?>
        <p class="lede">The page you're looking for doesn't exist or was moved.</p>
    <?php endif; ?>

    <?php if (!empty($suggestions)): ?>
        <section class="error-suggestions">
            <h2>Did you mean…</h2>
            <ul class="child-list">
                <?php
                // Lines with the same name show the move that ends them instead.
                $nameCounts = array_count_values(array_map(static fn (array $r): string => (string) $r['name'], $suggestions));
                foreach ($suggestions as $s):
                    $meta = ($nameCounts[(string) $s['name']] ?? 0) > 1 && !empty($s['pgn_moves'])
                        ? htmlspecialchars(Opening::movesFrom((string) $s['pgn_moves'], max(0, (int) $s['move_count'] - 1)), ENT_QUOTES, 'UTF-8')
                        : Opening::movesLabel((int) $s['move_count']); ?>
                    <li>
                        <a href="<?= $baseEsc . htmlspecialchars(I18n::url('/openings/' . $s['slug']), ENT_QUOTES, 'UTF-8') ?>"
                           title="<?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <span class="eco-tag"><?= htmlspecialchars($s['eco'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="child-list-name"><?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="child-list-plies"><?= $meta ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <form class="error-search" action="<?= $baseEsc . htmlspecialchars(I18n::url('/search'), ENT_QUOTES, 'UTF-8') ?>" method="get" role="search">
        <?php
        $nameSearchId = 'error-search';
        $nameSearchLabel = 'Search for an opening';
        // "/openings/sicilan-najdorf" starts the search with "sicilan najdorf".
        // Only when there is no "Did you mean" list, which already answers it.
        $nameSearchValue = empty($suggestions) ? trim(str_replace('-', ' ', $failedSlug)) : '';
        require __DIR__ . '/partials/name_search.php';
        ?>
    </form>

    <section class="error-actions">
        <h2>Or go to</h2>
        <p>
            <a class="board-cta" href="<?= $baseEsc . htmlspecialchars(I18n::url('/'), ENT_QUOTES, 'UTF-8') ?>">Home page</a>
            <a class="board-cta error-action-secondary" href="<?= $baseEsc . htmlspecialchars(I18n::url('/openings'), ENT_QUOTES, 'UTF-8') ?>">All openings A–Z</a>
            <a class="board-cta error-action-secondary" href="<?= $baseEsc . htmlspecialchars(I18n::url('/random'), ENT_QUOTES, 'UTF-8') ?>" rel="nofollow">A random opening</a>
        </p>
    </section>
</article>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
