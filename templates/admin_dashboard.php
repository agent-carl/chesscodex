<?php
/** @var string $baseUrl */
/** @var string $siteUrl */
/** @var array  $pending */
/** @var array  $counts */
/** @var array  $filters       Search filters from $_GET */
/** @var string|null $flash */
/** @var int    $statsToday */
/** @var int    $statsWeek */
/** @var int    $statsMonth */
/** @var array  $statsChart   List of ['date' => 'YYYY-MM-DD', 'views' => int] */
/** @var array  $statsTop     Top viewed openings */
/** @var array  $statsByType  Map page_type => total views */
$baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
$csrf    = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
ob_start();
?>
<div class="admin-page">
    <header class="admin-header">
        <h1>Admin</h1>
        <div class="admin-meta">
            <span>Pending: <strong><?= (int) $counts['pending'] ?></strong></span>
            <span>Accepted: <?= (int) $counts['accepted'] ?></span>
            <span>Rejected: <?= (int) $counts['rejected'] ?></span>
            <form method="post" action="<?= $baseEsc ?>/admin/logout" class="admin-logout">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <button type="submit">Log out</button>
            </form>
        </div>
    </header>

    <?php if (!empty($flash)): ?>
        <p class="admin-flash admin-flash-ok"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php
    // -------------------------------------------------------------------
    // Visit stats panel — private, admin-only. Counts exclude bots and
    // the admin's own sessions (cookie check in Views::shouldSkip).
    // -------------------------------------------------------------------
    $chartMax = 0;
    foreach ($statsChart as $row) $chartMax = max($chartMax, (int) $row['views']);
    ?>
    <section class="admin-stats">
        <header class="admin-stats-head">
            <h2>Visit stats</h2>
            <p class="admin-stats-note">Bots and your own visits are excluded. No IPs or sessions are stored.</p>
        </header>

        <div class="admin-stats-kpis">
            <div class="admin-stats-kpi">
                <div class="admin-stats-kpi-label">Today</div>
                <div class="admin-stats-kpi-value"><?= number_format($statsToday) ?></div>
            </div>
            <div class="admin-stats-kpi">
                <div class="admin-stats-kpi-label">Last 7 days</div>
                <div class="admin-stats-kpi-value"><?= number_format($statsWeek) ?></div>
            </div>
            <div class="admin-stats-kpi">
                <div class="admin-stats-kpi-label">Last 30 days</div>
                <div class="admin-stats-kpi-value"><?= number_format($statsMonth) ?></div>
            </div>
        </div>

        <h3 class="admin-stats-sub">Daily views (last 30 days)</h3>
        <?php if ($chartMax === 0): ?>
            <p class="admin-empty">No views recorded yet — give it a day for crawlers + real visitors to register.</p>
        <?php else: ?>
            <ol class="admin-stats-chart" aria-label="Daily views chart">
                <?php foreach ($statsChart as $row):
                    $h = $chartMax > 0 ? round($row['views'] * 100 / $chartMax) : 0;
                ?>
                    <li title="<?= htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8') ?>: <?= (int) $row['views'] ?> views">
                        <div class="admin-stats-chart-track">
                            <div class="admin-stats-chart-bar<?= $row['views'] > 0 ? '' : ' is-empty' ?>" style="height: <?= $h ?>%;">
                                <span class="admin-stats-chart-value"><?= (int) $row['views'] ?: '' ?></span>
                            </div>
                        </div>
                        <div class="admin-stats-chart-label"><?= htmlspecialchars(substr($row['date'], 5), ENT_QUOTES, 'UTF-8') ?></div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <?php if (!empty($statsByType)): ?>
            <h3 class="admin-stats-sub">By page type (last 30 days)</h3>
            <ul class="admin-stats-types">
                <?php foreach ($statsByType as $type => $views): ?>
                    <li>
                        <span class="admin-stats-type-name"><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="admin-stats-type-value"><?= number_format((int) $views) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (!empty($statsTop)): ?>
            <h3 class="admin-stats-sub">Top viewed openings (last 30 days)</h3>
            <ol class="admin-stats-top">
                <?php foreach ($statsTop as $top): ?>
                    <li>
                        <span class="eco-tag"><?= htmlspecialchars($top['eco'], ENT_QUOTES, 'UTF-8') ?></span>
                        <a href="<?= $baseEsc ?>/openings/<?= htmlspecialchars($top['slug'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                            <?= htmlspecialchars($top['name'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <span class="admin-stats-top-count"><?= number_format((int) $top['total']) ?> views</span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <h2>Submissions</h2>
    <form method="get" action="<?= $baseEsc ?>/admin" class="admin-filters">
        <label>
            Status
            <select name="status">
                <?php foreach (['pending', 'accepted', 'rejected', ''] as $s):
                    $label = $s === '' ? 'all' : $s;
                ?>
                    <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>"<?= ($filters['status'] ?? 'pending') === $s ? ' selected' : '' ?>>
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Opening
            <input type="search" name="opening_q" placeholder="name or slug…"
                   value="<?= htmlspecialchars((string) ($filters['opening_q'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <label>
            Author
            <input type="search" name="author_q" placeholder="name or email…"
                   value="<?= htmlspecialchars((string) ($filters['author_q'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <label>
            Since
            <input type="date" name="since"
                   value="<?= htmlspecialchars((string) ($filters['since'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <button type="submit">Filter</button>
        <a class="admin-edit-cancel" href="<?= $baseEsc ?>/admin">Reset</a>
    </form>
    <?php if (empty($pending)): ?>
        <p class="admin-empty">No pending submissions.</p>
    <?php else: ?>
        <form method="post" action="<?= $baseEsc ?>/admin/bulk" class="admin-bulk-form" id="admin-bulk-form">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <div class="admin-bulk-bar">
                <label class="admin-bulk-checkall">
                    <input type="checkbox" id="admin-bulk-checkall">
                    Select all
                </label>
                <span class="admin-bulk-selected" id="admin-bulk-selected">0 selected</span>
                <button type="submit" name="bulk_accept" value="1" class="admin-btn-accept" disabled
                        data-confirm="Accept selected submissions? Each one will replace the opening's description.">
                    Bulk accept
                </button>
                <button type="submit" name="bulk_reject" value="1" class="admin-btn-reject" disabled
                        data-confirm="Reject selected submissions?">
                    Bulk reject
                </button>
            </div>

            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="admin-cell-check"></th>
                        <th>When</th>
                        <th>Opening</th>
                        <th>Author</th>
                        <th>Preview</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $s): ?>
                    <tr>
                        <td class="admin-cell-check">
                            <input type="checkbox" name="ids[]" value="<?= (int) $s['id'] ?>"
                                   class="admin-bulk-check" aria-label="Select submission #<?= (int) $s['id'] ?>">
                        </td>
                        <td><?= htmlspecialchars($s['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <span class="eco-tag"><?= htmlspecialchars($s['eco'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?= htmlspecialchars($s['opening_name'], ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td><?= htmlspecialchars($s['author_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="admin-preview">
                            <?= htmlspecialchars(mb_substr($s['markdown'], 0, 120), ENT_QUOTES, 'UTF-8') ?><?php if (mb_strlen($s['markdown']) > 120) echo '…'; ?>
                        </td>
                        <td><a class="admin-review-link" href="<?= $baseEsc ?>/admin/review/<?= (int) $s['id'] ?>">Review →</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </form>
    <?php endif; ?>
</div>

<script defer src="<?= $baseEsc ?>/public/admin.min.js?v=<?= @filemtime(__DIR__ . '/../public/admin.min.js') ?: 1 ?>"></script>
<?php
$body = ob_get_clean();
$title = 'Admin dashboard · Caissa Codex';
$noindex = true;
require __DIR__ . '/layout.php';
