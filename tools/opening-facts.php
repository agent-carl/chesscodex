<?php
declare(strict_types=1);

/**
 * Prints the numbers a description may quote, straight from the database:
 * ECO, moves, all Lichess games and the results by rating band and among
 * masters. No Lichess requests. Use it (on the Pi, where the stats are
 * freshest) when writing or checking db/descriptions/*.md.
 *
 *   php tools/opening-facts.php <slug> [<slug> …]
 *   php tools/opening-facts.php --described     all slugs in db/descriptions
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/Autoload.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/ChessEngine.php';
require_once __DIR__ . '/../lib/StatsCache.php';
require_once __DIR__ . '/../lib/LevelStats.php';

$slugs = array_slice($argv, 1);
if ($slugs === ['--described']) {
    $slugs = array_map(fn($f) => basename($f, '.md'), glob(__DIR__ . '/../db/descriptions/*.md') ?: []);
}
if (!$slugs) {
    fwrite(STDERR, "usage: php tools/opening-facts.php <slug> [<slug> …] | --described\n");
    exit(2);
}

$find = chess_codex_db()->prepare('SELECT id, eco, name, pgn_moves FROM codex_openings WHERE slug = :s');
$line = static function (string $label, int $w, int $d, int $b): string {
    $t = max(1, $w + $d + $b);
    return sprintf("  %-13s %13s games  W %4.1f%%  D %4.1f%%  B %4.1f%%\n",
        $label, number_format($w + $d + $b), 100 * $w / $t, 100 * $d / $t, 100 * $b / $t);
};

$missing = 0;
foreach ($slugs as $slug) {
    $find->execute(['s' => $slug]);
    $o = $find->fetch();
    if (!$o) {
        echo "$slug: no such opening\n\n";
        $missing++;
        continue;
    }
    echo "$slug — {$o['eco']} {$o['name']}\n  moves: {$o['pgn_moves']}\n";
    $all = StatsCache::cached(ChessEngine::fromPgn((string) $o['pgn_moves'])->uciHistory());
    echo $all
        ? $line('all Lichess', $all['white'], $all['draws'], $all['black'])
        : "  all Lichess   (not cached)\n";
    foreach (LevelStats::forOpening((int) $o['id']) as $level => $s) {
        echo $line($level, $s['white'], $s['draws'], $s['black']);
    }
    echo "\n";
}
exit($missing > 0 ? 1 : 0);
