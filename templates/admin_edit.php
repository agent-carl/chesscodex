<?php
/** @var array  $opening */
/** @var string $baseUrl */
/** @var bool   $saved */
/** @var string|null $error */
$baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
$csrf    = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
ob_start();
?>
<div class="admin-page">
    <header class="admin-header">
        <h1>Edit description</h1>
        <div class="admin-meta">
            <a href="<?= $baseEsc ?>/openings/<?= htmlspecialchars($opening['slug'], ENT_QUOTES, 'UTF-8') ?>">← back to opening</a>
            <a href="<?= $baseEsc ?>/admin">admin dashboard</a>
        </div>
    </header>

    <h2>
        <span class="eco-tag"><?= htmlspecialchars($opening['eco'], ENT_QUOTES, 'UTF-8') ?></span>
        <?= htmlspecialchars($opening['name'], ENT_QUOTES, 'UTF-8') ?>
    </h2>

    <?php if ($saved): ?>
        <p class="admin-flash admin-flash-ok">Saved. Refresh the opening page to see the rendered markdown.</p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p class="admin-flash admin-flash-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post" class="admin-edit-form" id="admin-edit-form"
          data-preview="<?= $baseEsc ?>/admin/preview">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label for="markdown">Markdown
            <span class="admin-edit-hint">(headings ### , <strong>bold</strong>, lists, links — same as user submissions, but no length-min)</span>
        </label>
        <div class="admin-edit-grid">
            <div class="admin-edit-side admin-edit-side-md">
                <textarea id="markdown" name="markdown" rows="22" maxlength="20000"
                          placeholder="### Origin&#10;…"><?= htmlspecialchars((string) ($opening['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div class="admin-edit-side admin-edit-side-preview">
                <h3 class="admin-edit-preview-heading">Preview</h3>
                <div class="description-body admin-rendered" id="admin-edit-preview">
                    <p class="admin-preview-empty"><em>Start typing to see the rendered output here.</em></p>
                </div>
            </div>
        </div>
        <p class="admin-edit-actions">
            <button type="submit">Save description</button>
            <a class="admin-edit-cancel" href="<?= $baseEsc ?>/openings/<?= htmlspecialchars($opening['slug'], ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
        </p>
    </form>
</div>

<script defer src="<?= $baseEsc ?>/public/admin.min.js?v=<?= @filemtime(__DIR__ . '/../public/admin.min.js') ?: 1 ?>"></script>
<?php
$body = ob_get_clean();
$title = 'Edit · ' . $opening['name'] . ' · Chess Codex';
$noindex = true;
require __DIR__ . '/layout.php';
