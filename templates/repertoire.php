<?php
/** @var string $baseUrl */
/** @var string $siteUrl */
$esc     = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$baseEsc = $esc($baseUrl);
ob_start();
?>
<article class="repertoire" id="repertoire" data-base="<?= $baseEsc ?>">
    <header>
        <h1>My repertoire</h1>
        <p class="lede">The lines you saved with <strong>+ Repertoire</strong> on opening pages, by the side
            that chooses them. They are kept in this browser only — nothing is sent anywhere until you
            download them.</p>
    </header>
    <?php foreach (['white' => 'White', 'black' => 'Black'] as $side => $Side): ?>
        <section class="repertoire-side" data-side="<?= $side ?>">
            <h2>As <?= $Side ?> <span class="repertoire-count"></span></h2>
            <p class="repertoire-actions" hidden>
                <a class="opening-tool-btn" data-repertoire-train href="#">Practise these lines</a>
                <a class="opening-tool-btn" data-repertoire-pgn href="#" rel="nofollow" download>Download PGN</a>
            </p>
            <ul class="child-list repertoire-list"></ul>
            <p class="repertoire-empty">No <?= $Side ?> lines yet. Open any opening and press <strong>+ Repertoire</strong>.</p>
        </section>
    <?php endforeach; ?>
</article>
<script type="module" src="<?= $baseEsc ?>/public/repertoire.min.js?v=<?= @filemtime(__DIR__ . '/../public/repertoire.min.js') ?: 1 ?>"></script>
<?php
$body        = ob_get_clean();
$title       = 'My repertoire · Caissa Codex';
$description = 'Your saved chess opening lines, by side, to practise or download as PGN.';
$noindex     = true;
require __DIR__ . '/layout.php';
