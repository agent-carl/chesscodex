<?php
declare(strict_types=1);
/**
 * Stockfish's evaluation of every opening's final position (EngineEval):
 * score, best move and main line, most-played lines first. Positions already
 * in codex_engine_eval are skipped, so a stopped run resumes where it left
 * off; --redo evaluates them again (after an engine upgrade, say).
 *
 *   php tools/engine-eval.php [--depth 30] [--limit N] [--threads 4] [--hash 64]
 *                             [--engine PATH] [--redo] [--shard I/N]
 *
 * --shard I/N takes every N-th position starting at I (1..N), so N copies can
 * run side by side on a many-core machine: one engine per position scales far
 * better than many threads on one position. The results can then be copied
 * to the Pi's database.
 *
 * On the Pi, at the lowest CPU priority so the site always comes first:
 *   nohup chrt -i 0 php tools/engine-eval.php --engine ~/bin/stockfish > ~/engine-eval.log 2>&1 < /dev/null &
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/Autoload.php';
require_once __DIR__ . '/../lib/db.php';

$opts    = getopt('', ['depth:', 'limit:', 'threads:', 'hash:', 'engine:', 'redo', 'shard:']);
$depth   = (int) ($opts['depth'] ?? 30);
$limit   = (int) ($opts['limit'] ?? 0);
$threads = (int) ($opts['threads'] ?? 4);
$hash    = (int) ($opts['hash'] ?? 64);
$engine  = (string) ($opts['engine'] ?? 'stockfish');
$redo    = isset($opts['redo']);
[$shard, $shards] = array_map('intval', explode('/', (string) ($opts['shard'] ?? '1/1')) + [1 => 1]);
if ($shards < 1 || $shard < 1 || $shard > $shards) {
    fwrite(STDERR, "--shard must be I/N with 1 <= I <= N\n");
    exit(1);
}
const PV_PLIES = 10;   // main line shown on the page

EngineEval::ensureTable();
$rows = chess_codex_db()->query(
    'SELECT slug, fen, pgn_moves FROM codex_openings ORDER BY popularity DESC, id'
)->fetchAll();

$proc = proc_open([$engine], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "Can't start $engine\n");
    exit(1);
}
[$in, $out] = [$pipes[0], $pipes[1]];
$send = static function (string $cmd) use ($in): void { fwrite($in, $cmd . "\n"); fflush($in); };
/** Engine output lines up to and including the first one starting with $until. */
$readUntil = static function (string $until) use ($out): array {
    $lines = [];
    while (($line = fgets($out)) !== false) {
        $lines[] = $line = rtrim($line);
        if (str_starts_with($line, $until)) return $lines;
    }
    throw new RuntimeException('The engine quit');
};

$send('uci');
$name = 'Stockfish';
foreach ($readUntil('uciok') as $line) {
    if (preg_match('/^id name (.+)$/', $line, $m)) $name = trim($m[1]);
}
$send("setoption name Threads value $threads");
$send("setoption name Hash value $hash");
$send('isready');
$readUntil('readyok');
echo "$name, depth $depth, $threads threads, {$hash} MB hash\n";

$done = $skipped = 0;
$started = time();
$todo = [];
foreach ($rows as $k => $r) {
    if ($k % $shards !== $shard - 1) continue;
    $fen = (string) ($r['fen'] ?: ChessEngine::fromPgn((string) $r['pgn_moves'])->fen());
    if (!$redo && EngineEval::depthOf($fen) !== null) { $skipped++; continue; }
    $todo[] = ['slug' => (string) $r['slug'], 'fen' => $fen];
    if ($limit > 0 && count($todo) >= $limit) break;
}
echo count($todo) . " positions to evaluate, $skipped already done\n";

foreach ($todo as $i => $t) {
    $send('ucinewgame');
    $send('position fen ' . $t['fen']);
    $send("go depth $depth");
    // The deepest full (not upper/lower bound) line of the principal variation.
    $best = null;
    foreach ($readUntil('bestmove') as $line) {
        if (str_contains($line, 'bound ')) continue;
        if (!preg_match('/^info .*\bdepth (\d+) .*\bscore (cp|mate) (-?\d+) .*\bpv (.+)$/', $line, $m)) continue;
        if (preg_match('/\bmultipv (\d+)/', $line, $mp) && $mp[1] !== '1') continue;
        $best = ['depth' => (int) $m[1], 'kind' => $m[2], 'score' => (int) $m[3], 'pv' => explode(' ', trim($m[4]))];
    }
    if ($best === null) {
        echo "  {$t['slug']}: no legal moves (mate or stalemate), skipped\n";
        continue;
    }
    // The engine scores for the side to move; the table keeps White's view.
    $sign = explode(' ', $t['fen'])[1] === 'b' ? -1 : 1;
    $board = new ChessEngine($t['fen']);
    $pvSan = [];
    try {
        foreach (array_slice($best['pv'], 0, PV_PLIES) as $uci) $pvSan[] = $board->applyUci($uci);
    } catch (RuntimeException $e) {
        echo "  {$t['slug']}: line cut short ({$e->getMessage()})\n";
    }
    if (!$pvSan) continue;
    // Several shards share one database: wait out another shard's write.
    for ($try = 1; ; $try++) {
        try {
            EngineEval::store(
                $t['fen'], $best['depth'],
                $best['kind'] === 'cp' ? $sign * $best['score'] : null,
                $best['kind'] === 'mate' ? $sign * $best['score'] : null,
                $pvSan, $name
            );
            break;
        } catch (PDOException $e) {
            if ($try >= 30 || !str_contains($e->getMessage(), 'locked')) throw $e;
            usleep(500000);
        }
    }
    $done++;
    if ($done % 10 === 0 || $i + 1 === count($todo)) {
        $per  = (time() - $started) / $done;
        $left = (int) round($per * (count($todo) - $i - 1) / 3600);
        printf("%s  %d/%d  %.0f s each, ~%d h left  (last: %s %s)\n", date('H:i'), $i + 1, count($todo),
            $per, $left, $t['slug'], EngineEval::scoreText(EngineEval::forFen($t['fen'])));
    }
}
$send('quit');
proc_close($proc);
echo "Done: $done evaluated, $skipped skipped\n";
