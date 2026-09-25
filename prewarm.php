<?php
declare(strict_types=1);

/**
 * Pre-warm the Lichess Opening Explorer cache for openings that don't yet
 * have a cached entry. Iterates rows of codex_openings, computes the UCI
 * play sequence via ChessEngine (which is already populated), then asks
 * StatsCache to fetch — populating codex_stats_cache as a side effect.
 *
 * Usage:
 *   /prewarm.php?token=<seed_token>          ← process up to ~25s worth
 *   /prewarm.php?token=...&n=50              ← target N openings this run
 *   /prewarm.php?token=...&n=200&offset=500  ← continue from offset
 *
 * Strategy: each run grabs `n` openings ordered by id ascending starting
 * from `offset`. Skips any whose stats are already cached (cheap check).
 * Throttle in LichessExplorer (600 ms) limits us to ~50 fetches/run.
 *
 * Run multiple times until "no new entries needed" appears. Or set up a
 * cron via OVH panel to hit this URL daily.
 */

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/ChessEngine.php';
require_once __DIR__ . '/lib/StatsCache.php';

$config = require __DIR__ . '/config.php';

$expected = (string) ($config['seed_token'] ?? '');
$given    = (string) ($_GET['token'] ?? '');
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden.\n";
    exit;
}

@ini_set('output_buffering', '0');
@set_time_limit(120);
while (ob_get_level() > 0) ob_end_flush();
ob_implicit_flush(true);
header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');

$pad = str_repeat(' ', 4096) . "\n";
function emit(string $s): void { global $pad; echo $s . "\n" . $pad; @flush(); }

// ---------------------------------------------------------------------------
// Lightweight log rotation. Run once per prewarm: delete db/log/*.log older
// than 30 days. Cheap (filemtime + unlink), bounded by directory size, and
// keeps disk usage flat on shared hosting where we have no real cron.
// ---------------------------------------------------------------------------
$logDir = __DIR__ . '/db/log';
if (is_dir($logDir)) {
    $cutoff = time() - 30 * 86400;
    $rotated = 0;
    foreach ((array) @glob($logDir . '/*.log') as $f) {
        if (is_file($f) && @filemtime($f) < $cutoff) {
            if (@unlink($f)) $rotated++;
        }
    }
    if ($rotated > 0) emit("[log-rotate] removed $rotated stale log file(s) > 30d");
}

$n      = max(1, min(200, (int) ($_GET['n']      ?? 50)));
$offset = max(0,             (int) ($_GET['offset'] ?? 0));

emit("=== prewarm ===");
emit("processing up to $n openings starting at offset $offset");

$pdo = chess_codex_db();
$total = (int) $pdo->query("SELECT COUNT(*) FROM codex_openings")->fetchColumn();
emit("total openings: $total");

$stmt = $pdo->prepare("SELECT id, slug, pgn_moves FROM codex_openings ORDER BY id LIMIT :n OFFSET :o");
$stmt->bindValue(':n', $n, PDO::PARAM_INT);
$stmt->bindValue(':o', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

if (count($rows) === 0) {
    emit("no rows at this offset — done.");
    emit("DONE");
    exit;
}

$cacheCheck = $pdo->prepare("SELECT 1 FROM codex_stats_cache WHERE fen_hash = :h LIMIT 1");

$fetched = $skipped = $failed = 0;
$startTs = microtime(true);

foreach ($rows as $row) {
    // Hard time budget: stop at ~25s to leave room for output flushing.
    if (microtime(true) - $startTs > 25.0) {
        emit("[time-budget] hit 25s, stopping early");
        break;
    }

    try {
        $engine = ChessEngine::fromPgn((string) $row['pgn_moves']);
        $uci = $engine->uciHistory();
        if (count($uci) === 0) { $skipped++; continue; }

        $key = StatsCache::keyFor($uci);
        $cacheCheck->execute(['h' => $key]);
        if ($cacheCheck->fetchColumn()) { $skipped++; continue; }

        $data = StatsCache::getOrFetch($uci);
        if (isset($data['error'])) {
            $failed++;
            emit("[fail] {$row['slug']}: " . $data['error']);
        } else {
            $fetched++;
        }
    } catch (Throwable $e) {
        $failed++;
        emit("[fail] {$row['slug']}: " . $e->getMessage());
    }
}

emit("");
emit("fetched=$fetched  skipped(already-cached)=$skipped  failed=$failed");
emit("processed " . count($rows) . " rows in " . round(microtime(true) - $startTs, 2) . "s");

$nextOffset = $offset + count($rows);
if ($nextOffset < $total) {
    emit("");
    emit("Not done yet. Continue with:");
    emit("  /prewarm.php?token=...&n=$n&offset=$nextOffset");
} else {
    emit("");
    emit("Reached end of table. Cache fully warmed.");
}

emit("DONE");
