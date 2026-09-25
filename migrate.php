<?php
declare(strict_types=1);

/**
 * Migration runner front-controller. Replaces the old one-shot
 * migrate_NNN.php files — drop new migrations into db/migrations/ and hit:
 *
 *   /migrate.php?token=<seed_token>
 *
 * Idempotent: re-runs are no-ops once everything is applied. Refuses any
 * statement that targets a table not prefixed `codex_`.
 *
 * Safe to leave on the server long-term (token-protected, no admin UI).
 */

require __DIR__ . '/lib/Autoload.php';
require_once __DIR__ . '/lib/db.php';

$config   = require __DIR__ . '/config.php';
$expected = (string) ($config['seed_token'] ?? '');
$given    = (string) ($_GET['token'] ?? '');
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden.\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "=== Chess Codex migrations ===\n\n";

try {
    $results = Migrations::runAll();
} catch (Throwable $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
    exit;
}

$applied = $skipped = $failed = 0;
foreach ($results as $r) {
    $status = $r['status'];
    $reason = $r['reason'] ?? '';
    printf("  %-7s  %s%s\n", strtoupper($status), $r['version'], $reason ? "  ($reason)" : '');
    if ($status === 'applied') $applied++;
    elseif ($status === 'skip') $skipped++;
    else $failed++;
}

echo "\n";
echo "Applied: $applied   Skipped: $skipped   Failed/Refused: $failed\n";
if (empty($results)) {
    echo "(no migrations in db/migrations/ yet)\n";
}
