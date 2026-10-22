<?php
/** @var string $baseUrl */
/** @var string $siteUrl */
/** @var array  $pending */
/** @var array  $counts */
/** @var array  $filters       Search filters from $_GET */
/** @var string|null $flash */
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

    <p>Visits: <a href="https://dash.cloudflare.com/?to=/:account/web-analytics" rel="noopener">Cloudflare Web Analytics</a>.</p>

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
        <a class="admin-filter-reset" href="<?= $baseEsc ?>/admin">Reset</a>
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
                        data-confirm="Accept selected submissions? They are only marked accepted; the descriptions stay as they are.">
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
