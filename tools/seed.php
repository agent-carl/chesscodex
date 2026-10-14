<?php
declare(strict_types=1);

/**
 * Builds the openings table from the Lichess TSVs in db/*.tsv.
 *
 *   php tools/seed.php            create the schema and import (refuses if openings exist)
 *   php tools/seed.php --force    drop the codex_* tables and rebuild — loses descriptions,
 *                                 submissions and the stats cache
 *
 * Reproduces the original import exactly, so ids and URLs stay the same:
 *   - ids follow TSV order (a.tsv → e.tsv);
 *   - duplicate slugs get -2, -3, … in (move_count, eco, pgn) order;
 *   - parent = row whose canonical PGN is the longest strict prefix, within
 *     the same ECO letter (chess_codex_resolve_parents); depth = hops to root.
 * The moves are played with p-chess/chess for the final position's FEN (so
 * /search can match positions) and the moves in UCI (the key of a line's
 * Lichess statistics).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../_composer_vendor/autoload.php';

use PChess\Chess\Chess;

$force  = in_array('--force', $argv, true);

$pdo    = chess_codex_db();
$root   = dirname(__DIR__);

$existing = seed_openings_count($pdo);

if ($existing > 0 && !$force) {
    fwrite(STDERR, "codex_openings already has $existing rows — nothing to do.\n"
        . "Re-run with --force to rebuild from scratch (wipes descriptions, submissions and stats).\n");
    exit(1);
}

// ---------------------------------------------------------------- schema
echo "Creating schema…\n";
// codex_view_log and codex_migrations: tables of retired features in older databases.
foreach (['codex_view_log', 'codex_submissions', 'codex_stats_cache', 'codex_opening_lines',
          'codex_openings', 'codex_migrations'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS $table");
}
$pdo->exec((string) file_get_contents($root . '/db/schema.sqlite.sql'));
$pdo->exec('PRAGMA journal_mode = WAL');   // persistent: readers never block the writer
// SQLite creates the file 0644 whatever the umask. When the web server runs
// as another user in the file's group (www-data on the server), it needs group
// write — and the -wal/-shm files inherit the database file's mode.
$file = (string) $pdo->query('PRAGMA database_list')->fetch()['file'];
if ($file !== '') @chmod($file, 0664);

// ---------------------------------------------------------------- parse TSVs
echo "Reading db/*.tsv…\n";
$rows   = [];
$failed = [];
$id     = 0;
foreach (['a', 'b', 'c', 'd', 'e'] as $letter) {
    $fh = fopen("$root/db/$letter.tsv", 'r');
    if ($fh === false) {
        fwrite(STDERR, "Missing db/$letter.tsv\n");
        exit(1);
    }
    fgets($fh); // header: eco	name	pgn
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') continue;
        [$eco, $name, $pgn] = explode("\t", $line) + ['', '', ''];
        $id++;

        $canon = chess_codex_canonicalize_pgn($pgn);
        [$fen, $uci] = seed_play($pgn);
        if ($fen === null) $failed[] = "$id $name: $pgn";

        $rows[$id] = [
            'eco'        => $eco,
            'name'       => $name,
            'fen'        => $fen,
            'uci'        => $uci,
            'pgn_moves'  => $pgn,
            'pgn_canon'  => $canon,
            'move_count' => chess_codex_count_plies($pgn),
            // keys chess_codex_resolve_parents() works on:
            'eco_group'  => substr($eco, 0, 1),
            'canon'      => $canon,
        ];
    }
    fclose($fh);
}

// Names repeat (e.g. seven "Caro-Kann Defense" lines). The original import
// numbered the duplicates shortest-line-first — by (move_count, eco, pgn) —
// and existing URLs depend on it, so keep that order rather than TSV order.
$order = array_keys($rows);
usort($order, static fn (int $a, int $b): int =>
    [$rows[$a]['move_count'], $rows[$a]['eco'], $rows[$a]['pgn_moves']]
    <=> [$rows[$b]['move_count'], $rows[$b]['eco'], $rows[$b]['pgn_moves']]);
$usedSlugs = [];
foreach ($order as $rowId) {
    $base = chess_codex_slugify($rows[$rowId]['name']);
    $slug = $base;
    for ($n = 2; isset($usedSlugs[$slug]); $n++) {
        $slug = "$base-$n";
    }
    $usedSlugs[$slug] = true;
    $rows[$rowId]['slug'] = $slug;
}

$parents = chess_codex_resolve_parents($rows);
$depth = static function (int $id) use (&$depth, $parents): int {
    return $parents[$id] === null ? 0 : 1 + $depth($parents[$id]);
};

// ---------------------------------------------------------------- insert
echo 'Importing ' . count($rows) . " openings…\n";
$pdo->beginTransaction();
$insert = $pdo->prepare(
    'INSERT INTO codex_openings (id, eco, name, slug, parent_id, depth, fen, pgn_moves, uci, pgn_canon, move_count, popularity)
     VALUES (:id, :eco, :name, :slug, NULL, :depth, :fen, :pgn, :uci, :canon, :mc, 0)'
);
foreach ($rows as $rowId => $r) {
    $insert->execute([
        'id'    => $rowId,
        'eco'   => $r['eco'],
        'name'  => $r['name'],
        'slug'  => $r['slug'],
        'depth' => $depth($rowId),
        'fen'   => $r['fen'],
        'pgn'   => $r['pgn_moves'],
        'uci'   => $r['uci'],
        'canon' => $r['pgn_canon'],
        'mc'    => $r['move_count'],
    ]);
}
// Parents in a second pass: a parent can come later in TSV order than its
// child, which a single pass would trip over with foreign keys enforced.
$setParent = $pdo->prepare('UPDATE codex_openings SET parent_id = :p WHERE id = :id');
foreach ($parents as $rowId => $parentId) {
    if ($parentId !== null) $setParent->execute(['p' => $parentId, 'id' => $rowId]);
}
$pdo->commit();
$pdo->exec('ANALYZE');

echo "Done: " . count($rows) . " openings, " . count(array_filter($parents, fn ($p) => $p !== null))
    . " with a parent, moves played for " . (count($rows) - count($failed)) . ".\n";
foreach (array_slice($failed, 0, 5) as $msg) echo "  illegal move in $msg\n";
exit($failed === [] ? 0 : 1);

// ================================================================ helpers

function seed_openings_count(PDO $pdo): int
{
    $exists = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'codex_openings'")->fetchColumn();
    if (!$exists) return 0;
    return (int) $pdo->query('SELECT COUNT(*) FROM codex_openings')->fetchColumn();
}

/** The FEN after the moves and the moves in UCI ("e2e4 c7c5"); [null, ''] when a move is illegal. */
function seed_play(string $pgn): array
{
    $chess = new Chess();
    $uci   = [];
    foreach (preg_split('/\s+/', trim((string) preg_replace('/\d+\.(\.\.)?/', ' ', $pgn))) ?: [] as $san) {
        $move = $chess->move($san);
        if ($move === null) return [null, ''];
        $uci[] = $move->from . $move->to . ($move->promotion ?? '');
    }
    return [$chess->fen(), implode(' ', $uci)];
}
