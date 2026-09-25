<?php
/** @var string $baseUrl */
/** @var string $siteUrl */
/** @var array  $submission */
/** @var array|null $opening   Current opening (for side-by-side diff). */
$baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
$csrf    = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
$s       = $submission;

require_once __DIR__ . '/../vendor/Parsedown.php';
$pd = new Parsedown();
$pd->setSafeMode(true); // submissions come from public users
$renderedNew = $pd->text((string) $s['markdown']);

$currentMd  = (string) ($opening['description'] ?? '');
$renderedCur = $currentMd !== '' ? $pd->text($currentMd) : '';

ob_start();
?>
<div class="admin-page admin-review">
    <p><a href="<?= $baseEsc ?>/admin">← Back to dashboard</a></p>

    <h1>Review submission #<?= (int) $s['id'] ?></h1>

    <dl class="admin-fields">
        <dt>Opening</dt>
        <dd>
            <span class="eco-tag"><?= htmlspecialchars($s['eco'], ENT_QUOTES, 'UTF-8') ?></span>
            <a href="<?= $baseEsc ?>/openings/<?= htmlspecialchars($s['opening_slug'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                <?= htmlspecialchars($s['opening_name'], ENT_QUOTES, 'UTF-8') ?> ↗
            </a>
        </dd>
        <dt>Status</dt>
        <dd><strong><?= htmlspecialchars($s['status'], ENT_QUOTES, 'UTF-8') ?></strong></dd>
        <dt>Submitted</dt>
        <dd><?= htmlspecialchars($s['created_at'], ENT_QUOTES, 'UTF-8') ?> (IP <?= htmlspecialchars($s['ip_address'] ?? '—', ENT_QUOTES, 'UTF-8') ?>)</dd>
        <dt>Author</dt>
        <dd><?= htmlspecialchars($s['author_name'] ?? '(anonymous)', ENT_QUOTES, 'UTF-8') ?><?php if (!empty($s['author_email'])): ?> · <?= htmlspecialchars($s['author_email'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></dd>
    </dl>

    <h2>Side-by-side diff</h2>
    <div class="admin-diff">
        <div class="admin-diff-col">
            <h3 class="admin-diff-heading">
                Current
                <?php if ($currentMd === ''): ?>
                    <span class="admin-diff-empty">empty</span>
                <?php else: ?>
                    <span class="admin-diff-meta"><?= number_format(mb_strlen($currentMd)) ?> chars</span>
                <?php endif; ?>
            </h3>
            <div class="description-body admin-rendered">
                <?php if ($renderedCur === ''): ?>
                    <p class="admin-diff-empty-body"><em>No description yet — accepting will create one.</em></p>
                <?php else: ?>
                    <?= $renderedCur ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="admin-diff-col admin-diff-col-new">
            <h3 class="admin-diff-heading">
                Submitted
                <span class="admin-diff-meta"><?= number_format(mb_strlen((string) $s['markdown'])) ?> chars</span>
            </h3>
            <div class="description-body admin-rendered"><?= $renderedNew ?></div>
        </div>
    </div>

    <details class="admin-raw-details">
        <summary>Raw markdown (submitted)</summary>
        <pre class="admin-raw"><?= htmlspecialchars((string) $s['markdown'], ENT_QUOTES, 'UTF-8') ?></pre>
    </details>
    <?php if ($currentMd !== ''): ?>
        <details class="admin-raw-details">
            <summary>Raw markdown (current)</summary>
            <pre class="admin-raw"><?= htmlspecialchars($currentMd, ENT_QUOTES, 'UTF-8') ?></pre>
        </details>
    <?php endif; ?>

    <?php if ($s['status'] === 'pending'): ?>
        <h2>Actions</h2>
        <div class="admin-actions">
            <form method="post" action="<?= $baseEsc ?>/admin/review/<?= (int) $s['id'] ?>/accept">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <label>Optional reviewer note<input type="text" name="notes" maxlength="255"></label>
                <button type="submit" class="admin-btn-accept">Accept &amp; publish</button>
            </form>
            <form method="post" action="<?= $baseEsc ?>/admin/review/<?= (int) $s['id'] ?>/reject">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <label>Optional reason for rejection<input type="text" name="notes" maxlength="255"></label>
                <button type="submit" class="admin-btn-reject">Reject</button>
            </form>
        </div>
    <?php else: ?>
        <p class="admin-resolved">
            Already reviewed: <strong><?= htmlspecialchars($s['status'], ENT_QUOTES, 'UTF-8') ?></strong>
            on <?= htmlspecialchars((string) $s['reviewed_at'], ENT_QUOTES, 'UTF-8') ?>
            <?php if (!empty($s['review_notes'])): ?>
                <br>Notes: <em><?= htmlspecialchars($s['review_notes'], ENT_QUOTES, 'UTF-8') ?></em>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</div>
<?php
$body = ob_get_clean();
$title = 'Review submission · Chess Codex';
$noindex = true;
require __DIR__ . '/layout.php';
