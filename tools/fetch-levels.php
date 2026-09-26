<?php
declare(strict_types=1);
/**
 * Fetches how the most-played lines score at each level (LevelStats: four
 * Lichess rating bands and the masters database, with a few master games).
 * One request at a time, ~3 s apart — about 20 a minute, as the Lichess
 * explorer allows; five requests per line. Rows younger than --max-age days
 * are skipped, so a stopped run resumes where it left off.
 *
 *   php tools/fetch-levels.php [--limit 500] [--max-age 30]
 *
 * On the Pi (the Lichess token is in its config.php):
 *   nohup php tools/fetch-levels.php > ~/fetch-levels.log 2>&1 &
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/Autoload.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/ChessEngine.php';
require_once __DIR__ . '/../lib/LichessExplorer.php';

$opts   = getopt('', ['limit:', 'max-age:']);
$limit  = (int) ($opts['limit'] ?? 500);
$maxAge = (int) ($opts['max-age'] ?? 30) * 86400;

LevelStats::ensureTable();
$stmt = chess_codex_db()->prepare(
    'SELECT id, slug, pgn_moves FROM codex_openings ORDER BY popularity DESC, id LIMIT :n'
);
$stmt->bindValue('n', $limit, PDO::PARAM_INT);
$stmt->execute();
$lines = $stmt->fetchAll();

$done = $skipped = $failed = 0;
$started = time();
foreach ($lines as $i => $line) {
    $uci = ChessEngine::fromPgn((string) $line['pgn_moves'])->uciHistory();
    foreach (array_keys(LevelStats::LEVELS) as $level) {
        $at = LevelStats::fetchedAt((int) $line['id'], $level);
        if ($at !== null && time() - strtotime($at) < $maxAge) { $skipped++; continue; }
        for ($try = 1; $try <= 3; $try++) {
            try {
                LevelStats::fetchAndStore((int) $line['id'], $uci, $level);
                $done++;
                break;
            } catch (LichessBusyException $e) {          // a 429 cool-down under way
                sleep(max(2, $e->retryAfter));
            } catch (LichessRateLimitedException) {
                sleep(61);
            } catch (Throwable $e) {
                $failed++;
                echo "  {$line['slug']} / $level: {$e->getMessage()}\n";
                break;
            }
        }
        sleep(3);
    }
    if (($i + 1) % 25 === 0 || $i + 1 === count($lines)) {
        printf("%s  %d/%d lines, %d fetched, %d fresh skipped, %d failed, %d min\n",
            date('H:i'), $i + 1, count($lines), $done, $skipped, $failed, intdiv(time() - $started, 60));
    }
}
