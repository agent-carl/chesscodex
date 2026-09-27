<?php
declare(strict_types=1);

/**
 * Loads opening descriptions from db/descriptions/<slug>.md into
 * codex_openings.description. The files are the source of truth for these
 * texts; the admin editor still works for everything else.
 *
 *   php tools/import-descriptions.php [--dry-run] [--force]
 *
 * An opening whose description is empty gets the file's text. One that
 * already has a different description (edited in the admin, or accepted from
 * a submission) is skipped unless --force is given, so nothing written on
 * the site is overwritten by accident.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/Autoload.php';
require_once __DIR__ . '/../lib/db.php';

$dryRun = in_array('--dry-run', $argv, true);
$force  = in_array('--force', $argv, true);

$pdo    = chess_codex_db();
$find   = $pdo->prepare('SELECT id, description FROM codex_openings WHERE slug = :s');
$update = $pdo->prepare('UPDATE codex_openings SET description = :d WHERE id = :id');

$counts = ['set' => 0, 'same' => 0, 'skipped' => 0, 'missing' => 0];
foreach (glob(__DIR__ . '/../db/descriptions/*.md') ?: [] as $file) {
    $slug = basename($file, '.md');
    $text = trim(str_replace("\r\n", "\n", (string) file_get_contents($file)));
    $find->execute(['s' => $slug]);
    $row = $find->fetch();
    if (!$row) {
        echo "missing  $slug (no such opening)\n";
        $counts['missing']++;
        continue;
    }
    $current = trim((string) ($row['description'] ?? ''));
    if ($current === $text) {
        $counts['same']++;
        continue;
    }
    if ($current !== '' && !$force) {
        echo "skipped  $slug (has a different description; --force to replace)\n";
        $counts['skipped']++;
        continue;
    }
    if (!$dryRun) $update->execute(['d' => $text, 'id' => (int) $row['id']]);
    echo ($dryRun ? 'would set' : 'set     ') . " $slug\n";
    $counts['set']++;
}

printf("%s: %d set, %d unchanged, %d skipped, %d missing\n",
    $dryRun ? 'Dry run' : 'Done', $counts['set'], $counts['same'], $counts['skipped'], $counts['missing']);
exit($counts['missing'] > 0 ? 1 : 0);
