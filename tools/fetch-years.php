<?php
declare(strict_types=1);
/**
 * Fetches how often the most-played lines were played each year (YearStats):
 * one explorer request per line with its month-by-month history, after one
 * for every game (the starting position). One request at a time, ~3 s apart
 * — about 20 a minute, as the Lichess explorer allows. Lines fetched less
 * than --max-age days ago are skipped, so a stopped run resumes where it
 * left off.
 *
 *   php tools/fetch-years.php [--limit 1500] [--max-age 30]
 *
 * On the server (the Lichess token is in its config.php):
 *   nohup php tools/fetch-years.php > ~/fetch-years.log 2>&1 < /dev/null &
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
$limit  = (int) ($opts['limit'] ?? 1500);
$maxAge = (int) ($opts['max-age'] ?? 30) * 86400;

/** Runs $fetch with the usual retries; false after an error that a retry won't fix. */
$attempt = static function (callable $fetch, string $what): mixed {
    for ($try = 1; $try <= 3; $try++) {
        try {
            return $fetch();
        } catch (LichessBusyException $e) {          // a 429 cool-down under way
            sleep(max(2, $e->retryAfter));
        } catch (LichessRateLimitedException) {
            sleep(61);
        } catch (Throwable $e) {
            echo "  $what: {$e->getMessage()}\n";
            return false;
        }
    }
    return false;
};

YearStats::ensureTable();
$lastMonth = $attempt(static fn (): string => YearStats::fetchAndStore(0, []), 'every game');
if (!is_string($lastMonth) || $lastMonth === '') {
    fwrite(STDERR, "No history for every game, stopping.\n");
    exit(1);
}
echo date('H:i'), "  every game: history through $lastMonth\n";
sleep(3);

$stmt = chess_codex_db()->prepare(
    'SELECT id, slug, pgn_moves FROM codex_openings ORDER BY popularity DESC, id LIMIT :n'
);
$stmt->bindValue('n', $limit, PDO::PARAM_INT);
$stmt->execute();
$lines = $stmt->fetchAll();

$done = $skipped = $failed = 0;
$started = time();
foreach ($lines as $i => $line) {
    $at = YearStats::fetchedAt((int) $line['id']);
    if ($at !== null && time() - strtotime($at) < $maxAge) {
        $skipped++;
    } else {
        $uci = ChessEngine::fromPgn((string) $line['pgn_moves'])->uciHistory();
        $ok  = $attempt(static fn (): string => YearStats::fetchAndStore((int) $line['id'], $uci, $lastMonth), (string) $line['slug']);
        $ok === false ? $failed++ : $done++;
        sleep(3);
    }
    if (($i + 1) % 50 === 0 || $i + 1 === count($lines)) {
        printf("%s  %d/%d lines, %d fetched, %d fresh skipped, %d failed, %d min\n",
            date('H:i'), $i + 1, count($lines), $done, $skipped, $failed, intdiv(time() - $started, 60));
    }
}
