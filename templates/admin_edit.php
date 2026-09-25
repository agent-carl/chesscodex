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

    <form method="post" class="admin-edit-form" id="admin-edit-form">
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

<script>
// Live preview: POSTs markdown to /admin/preview every ~350 ms while typing,
// renders the response into #admin-edit-preview. Uses the same Parsedown
// (safe mode) as the public site, so what the admin sees is exactly what
// the user will see after Save.
(function () {
    const ta = document.getElementById('markdown');
    const out = document.getElementById('admin-edit-preview');
    const form = document.getElementById('admin-edit-form');
    if (!ta || !out || !form) return;
    const csrf = form.querySelector('input[name="csrf"]').value;
    let timer = null;
    let inflight = 0;
    async function render() {
        const md = ta.value;
        const turn = ++inflight;
        try {
            const fd = new FormData();
            fd.append('csrf', csrf);
            fd.append('markdown', md);
            const res = await fetch('<?= $baseEsc ?>/admin/preview', { method: 'POST', body: fd });
            if (turn !== inflight) return; // user kept typing
            if (!res.ok) { out.innerHTML = '<p><em>Preview failed: HTTP ' + res.status + '</em></p>'; return; }
            out.innerHTML = await res.text();
        } catch (e) {
            if (turn === inflight) out.innerHTML = '<p><em>Preview unavailable (network).</em></p>';
        }
    }
    ta.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(render, 350); });
    if (ta.value.trim() !== '') render();
})();
</script>
<?php
$body = ob_get_clean();
$title = 'Edit · ' . $opening['name'] . ' · Chess Codex';
$noindex = true;
require __DIR__ . '/layout.php';
