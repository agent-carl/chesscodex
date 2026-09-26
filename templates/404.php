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
                <?php foreach ($suggestions as $s): ?>
                    <li>
                        <a href="<?= $baseEsc . htmlspecialchars(I18n::url('/openings/' . $s['slug']), ENT_QUOTES, 'UTF-8') ?>"
                           title="<?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <span class="eco-tag"><?= htmlspecialchars($s['eco'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="child-list-name"><?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="child-list-plies"><?= Opening::movesLabel((int) $s['move_count']) ?></span>
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
