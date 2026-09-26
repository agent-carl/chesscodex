<?php
declare(strict_types=1);

/**
 * Sets codex_openings.popularity to the number of Lichess games that reached
 * each opening (white wins + draws + black wins), read from the stats cache —
 * no Lichess requests. Search suggestions, the "Related" links on opening
 * pages and the identifier's continuations are ordered by it. Openings
 * without cached stats keep their value.
 *
 *   php tools/backfill-popularity.php
 *
 * Run on the Pi after warming the stats cache (deploy/prewarm-all.sh calls it
 * when it finishes).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/Autoload.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/ChessEngine.php';
require_once __DIR__ . '/../lib/StatsCache.php';

$pdo  = chess_codex_db();
$rows = $pdo->query('SELECT id, slug, pgn_moves, popularity FROM codex_openings')->fetchAll();

$update    = $pdo->prepare('UPDATE codex_openings SET popularity = :p WHERE id = :id');
$withStats = 0;
$changed   = 0;
$failed    = [];
$pdo->beginTransaction();
foreach ($rows as $r) {
    try {
        $stats = StatsCache::cached(ChessEngine::fromPgn((string) $r['pgn_moves'])->uciHistory());
    } catch (Throwable $e) {
        $failed[] = "{$r['slug']}: {$e->getMessage()}";
        continue;
    }
    if ($stats === null) continue;
    $withStats++;
    $games = $stats['white'] + $stats['draws'] + $stats['black'];
    if ($games !== (int) $r['popularity']) {
        $update->execute(['p' => $games, 'id' => (int) $r['id']]);
        $changed++;
    }
}
$pdo->commit();

printf("%d openings, %d with cached stats, popularity changed for %d.\n", count($rows), $withStats, $changed);
foreach (array_slice($failed, 0, 5) as $msg) echo "  skipped — $msg\n";
