<?php
declare(strict_types=1);

/**
 * Builds the openings table from the Lichess TSVs in db/*.tsv. CLI-only
 * replacement for the old token-gated /seed.php.
 *
 *   php tools/seed.php            create the schema and import (refuses if openings exist)
 *   php tools/seed.php --force    drop ALL codex_* tables and rebuild — loses descriptions,
 *                                 submissions, view counts and the stats cache
 *   php tools/seed.php --verify   only compare the database with the old site's snapshots
 *
 * Reproduces the original import exactly, so ids and URLs stay the same:
 *   - ids follow TSV order (a.tsv → e.tsv);
 *   - duplicate slugs get -2, -3, … in (move_count, eco, pgn) order;
 *   - parent = row whose canonical PGN is the longest strict prefix, within
 *     the same ECO letter (chess_codex_resolve_parents); depth = hops to root.
 * FEN is computed server-side so /search can match positions.
 *
 * After importing, rows are checked against db/cache/alphabetical.json and
 * db/sitemap_cache.xml, which were written by the original deployment.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/Autoload.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/parser.php';
require_once __DIR__ . '/../lib/ChessEngine.php';

$force  = in_array('--force', $argv, true);
$verify = in_array('--verify', $argv, true);

$pdo    = chess_codex_db();
$driver = chess_codex_db_driver();
$root   = dirname(__DIR__);

$existing = seed_openings_count($pdo, $driver);

if ($verify) {
    exit(seed_verify($pdo, $root) ? 0 : 1);
}
if ($existing > 0 && !$force) {
    fwrite(STDERR, "codex_openings already has $existing rows — nothing to do.\n"
        . "Re-run with --force to rebuild from scratch (wipes descriptions, submissions and stats),\n"
        . "or with --verify to check the data.\n");
    exit(1);
}

// ---------------------------------------------------------------- schema
echo "Creating schema ($driver)…\n";
if ($driver === 'sqlite') {
    foreach (['codex_view_log', 'codex_submissions', 'codex_stats_cache', 'codex_opening_lines',
              'codex_openings', 'codex_migrations'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS $table");
    }
    $pdo->exec((string) file_get_contents($root . '/db/schema.sqlite.sql'));
    $pdo->exec('PRAGMA journal_mode = WAL');   // persistent: readers never block the writer
    // SQLite creates the file 0644 whatever the umask. When the web server runs
    // as another user in the file's group (www-data on the Pi), it needs group
    // write — and the -wal/-shm files inherit the database file's mode.
    $file = (string) $pdo->query('PRAGMA database_list')->fetch()['file'];
    if ($file !== '') @chmod($file, 0664);
} else {
    $sql = (string) file_get_contents($root . '/db/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    foreach (preg_split('/;\s*\n/', $sql) ?: [] as $stmt) {
        if (trim($stmt) !== '') $pdo->exec($stmt);
    }
}

// ---------------------------------------------------------------- parse TSVs
echo "Reading db/*.tsv…\n";
$rows      = [];
$fenFailed = [];
$id        = 0;
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
        try {
            $fen = ChessEngine::fromPgn($pgn)->fen();
        } catch (Throwable $e) {
            $fen = null;
            $fenFailed[] = "$id $name: " . $e->getMessage();
        }

        $rows[$id] = [
            'eco'        => $eco,
            'name'       => $name,
            'fen'        => $fen,
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
    'INSERT INTO codex_openings (id, eco, name, slug, parent_id, depth, fen, pgn_moves, pgn_canon, move_count, popularity)
     VALUES (:id, :eco, :name, :slug, NULL, :depth, :fen, :pgn, :canon, :mc, 0)'
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
if ($driver === 'sqlite') $pdo->exec('ANALYZE');

echo "Done: " . count($rows) . " openings, " . count(array_filter($parents, fn ($p) => $p !== null))
    . " with a parent, FEN computed for " . (count($rows) - count($fenFailed)) . ".\n";
foreach (array_slice($fenFailed, 0, 5) as $msg) echo "  FEN failed — $msg\n";

exit(seed_verify($pdo, $root) ? 0 : 1);

// ================================================================ helpers

function seed_openings_count(PDO $pdo, string $driver): int
{
    $exists = $driver === 'sqlite'
        ? $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'codex_openings'")->fetchColumn()
        : $pdo->query("SHOW TABLES LIKE 'codex_openings'")->fetchColumn();
    if (!$exists) return 0;
    return (int) $pdo->query('SELECT COUNT(*) FROM codex_openings')->fetchColumn();
}

/**
 * Compare the database with snapshots the original deployment left behind:
 * db/cache/alphabetical.json (id, eco, name, slug, move_count of every row),
 * db/cache/{popular,gambits}.json (depth for a sample) and the sitemap (URLs).
 */
function seed_verify(PDO $pdo, string $root): bool
{
    echo "\nVerifying against the old site's snapshots…\n";
    $db = [];
    foreach ($pdo->query('SELECT id, eco, name, slug, move_count, depth FROM codex_openings') as $r) {
        $db[(int) $r['id']] = $r;
    }
    $ok = true;

    $alpha = json_decode((string) @file_get_contents("$root/db/cache/alphabetical.json"), true);
    if (is_array($alpha)) {
        $seen = 0;
        $diff = [];
        foreach ($alpha as $group) {
            foreach ($group as $old) {
                $seen++;
                $new = $db[(int) $old['id']] ?? null;
                foreach (['eco', 'name', 'slug', 'move_count'] as $f) {
                    if ($new === null || (string) $new[$f] !== (string) $old[$f]) {
                        $diff[] = "id {$old['id']} $f: old=" . json_encode($old[$f] ?? null, JSON_UNESCAPED_UNICODE)
                            . ' new=' . json_encode($new[$f] ?? null, JSON_UNESCAPED_UNICODE);
                    }
                }
            }
        }
        printf("  alphabetical.json: %d rows checked, %d differences\n", $seen, count($diff));
        foreach (array_slice($diff, 0, 10) as $d) echo "    $d\n";
        $ok = $ok && $diff === [] && $seen === count($db);
    } else {
        echo "  alphabetical.json: not found, skipped\n";
    }

    $depthChecked = 0;
    $depthDiff    = [];
    foreach (['popular', 'gambits'] as $file) {
        $list = json_decode((string) @file_get_contents("$root/db/cache/$file.json"), true);
        foreach (is_array($list) ? $list : [] as $old) {
            $depthChecked++;
            $new = $db[(int) $old['id']] ?? null;
            if ($new === null || (int) $new['depth'] !== (int) $old['depth']) {
                $depthDiff[] = "id {$old['id']} ({$old['slug']}): old depth {$old['depth']}, new " . ($new['depth'] ?? 'missing');
            }
        }
    }
    printf("  depth (popular/gambits.json): %d checked, %d differences\n", $depthChecked, count($depthDiff));
    foreach (array_slice($depthDiff, 0, 10) as $d) echo "    $d\n";
    $ok = $ok && $depthDiff === [];

    $sitemap = (string) @file_get_contents("$root/db/sitemap_cache.xml");
    if ($sitemap !== '' && preg_match_all('#/openings/([a-z0-9-]+)</loc>#', $sitemap, $m)) {
        $oldSlugs = array_flip($m[1]);
        $newSlugs = array_flip(array_column($db, 'slug'));
        $missing  = array_diff_key($oldSlugs, $newSlugs);
        $extra    = array_diff_key($newSlugs, $oldSlugs);
        printf("  sitemap: %d old URLs, %d missing now, %d new\n", count($oldSlugs), count($missing), count($extra));
        foreach (array_slice(array_keys($missing), 0, 5) as $s) echo "    missing: /openings/$s\n";
        $ok = $ok && $missing === [];
    }

    echo $ok ? "OK — ids, slugs and tree match the original site.\n" : "MISMATCH — see above.\n";
    return $ok;
}
