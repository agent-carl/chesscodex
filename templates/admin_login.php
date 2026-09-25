<?php
/** @var string $baseUrl */
/** @var string $siteUrl */
/** @var string|null $error */
$baseEsc = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
ob_start();
?>
<div class="admin-login">
    <h1>Admin login</h1>
    <?php if (!empty($error)): ?>
        <p class="admin-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <form method="post" action="<?= $baseEsc ?>/admin/login" autocomplete="off">
        <label>
            Username
            <input type="text" name="username" required autofocus autocomplete="username">
        </label>
        <label>
            Password
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button type="submit">Log in</button>
    </form>
</div>
<?php
$body = ob_get_clean();
$title = 'Admin login · Chess Codex';
$noindex = true;
require __DIR__ . '/layout.php';
