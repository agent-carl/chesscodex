<?php
declare(strict_types=1);
/**
 * Finds transpositions between named lines and writes db/transpositions.json.
 *
 * Lichess names each position once, so no two lines share a position. But
 * one move from a named position often lands on another named line that got
 * there by a different move order: from "Indian Defense" (1. d4 Nf6) the move
 * 2. c4 is its own continuation, while elsewhere a move reaches a line whose
 * moves never passed through this one. Those are the transpositions.
 *
 * Every legal move from every named position is played (p-chess/chess);
 * when it lands on the position of another named line one ply longer, that
 * line is a transposition — unless it's a direct continuation (the target's
 * moves start with the source's). Offline and deterministic — rerun after
 * the opening data changes:
 *
 *   php tools/build-transpositions.php
 *
 * Output: {"<from id>": [[<to id>, "<SAN>"], …], …}
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../_composer_vendor/autoload.php';

use PChess\Chess\Chess;

$started = microtime(true);
$lines = $byKey = [];
foreach (chess_codex_db()->query('SELECT id, fen, pgn_canon, move_count FROM codex_openings') as $r) {
    $line = [
        'id'    => (int) $r['id'],
        'fen'   => (string) $r['fen'],
        'canon' => (string) $r['pgn_canon'],
        'ply'   => (int) $r['move_count'],
    ];
    $lines[] = $line;
    $byKey[position_key($line['fen'])][] = $line;
}

$out = [];
$found = 0;
foreach ($lines as $from) {
    $chess = new Chess($from['fen']);
    foreach ($chess->moves() as $move) {
        $chess->move((string) $move->san);
        $targets = $byKey[position_key($chess->fen())] ?? [];
        $chess->undo();
        foreach ($targets as $to) {
            // One ply on, and not a direct continuation.
            if ($to['ply'] !== $from['ply'] + 1 || str_starts_with($to['canon'], $from['canon'] . ' ')) continue;
            $out[$from['id']][] = [$to['id'], (string) $move->san];
            $found++;
        }
    }
}
ksort($out);
file_put_contents(__DIR__ . '/../db/transpositions.json', json_encode($out, JSON_UNESCAPED_UNICODE) . "\n");
printf("%d lines, %d transpositions from %d lines, %.1f s\n", count($lines), $found, count($out), microtime(true) - $started);

/** Placement, side to move and castling rights — the parts that make a position. */
function position_key(string $fen): string
{
    return implode(' ', array_slice(explode(' ', $fen), 0, 3));
}
