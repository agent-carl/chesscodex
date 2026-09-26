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
 * Pairs of lines one ply apart whose boards differ in 2–4 squares (a move,
 * capture, en passant or castling) are candidates; the move is rebuilt as
 * SAN and replayed with ChessEngine to confirm it. Direct continuations (the
 * target's moves start with the source's) are left out. Offline and
 * deterministic — rerun after the opening data changes:
 *
 *   php tools/build-transpositions.php
 *
 * Output: {"<from id>": [[<to id>, "<SAN>"], …], …}
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/Autoload.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/ChessEngine.php';

$started = microtime(true);
$lines = [];
foreach (chess_codex_db()->query('SELECT id, fen, pgn_canon, move_count FROM codex_openings') as $r) {
    $lines[] = [
        'id'    => (int) $r['id'],
        'fen'   => (string) $r['fen'],
        'key'   => position_key((string) $r['fen']),
        'board' => board((string) $r['fen']),
        'canon' => (string) $r['pgn_canon'],
        'ply'   => (int) $r['move_count'],
    ];
}
$byPly = [];
foreach ($lines as $l) $byPly[$l['ply']][] = $l;

$out = [];
$found = 0;
foreach ($byPly as $ply => $sources) {
    foreach ($byPly[$ply + 1] ?? [] as $to) {
        foreach ($sources as $from) {
            $diff = 64 - substr_count($from['board'] ^ $to['board'], "\0");
            if ($diff < 2 || $diff > 4) continue;
            // A direct continuation, not a transposition.
            if (str_starts_with($to['canon'], $from['canon'] . ' ')) continue;
            $san = replay_move($from, $to);
            if ($san === null) continue;
            $out[$from['id']][] = [$to['id'], $san];
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

/** The 64 squares, a8 first, '.' for empty. */
function board(string $fen): string
{
    $rows = explode('/', explode(' ', $fen)[0]);
    $b = '';
    foreach ($rows as $row) {
        $b .= preg_replace_callback('/\d/', static fn (array $m): string => str_repeat('.', (int) $m[0]), $row);
    }
    return $b;
}

/** The SAN of the move that turns $from's position into $to's, or null if there is none. */
function replay_move(array $from, array $to): ?string
{
    $white = str_contains(explode(' ', $from['fen'])[1], 'w');
    $mine  = static fn (string $p): bool => $p !== '.' && (ctype_upper($p) === $white);
    $sq    = static fn (int $i): string => chr(ord('a') + $i % 8) . (8 - intdiv($i, 8));
    $vacated = $arrived = [];
    for ($i = 0; $i < 64; $i++) {
        [$a, $b] = [$from['board'][$i], $to['board'][$i]];
        if ($a === $b) continue;
        if ($mine($a) && $b === '.') $vacated[] = $i;
        if ($mine($b)) $arrived[] = $i;
    }
    $candidates = [];
    if (count($vacated) === 2 && count($arrived) === 2) {
        // Castling: the king moved two files.
        foreach ($arrived as $i) {
            if (strtoupper($to['board'][$i]) === 'K') $candidates[] = $i % 8 === 6 ? 'O-O' : 'O-O-O';
        }
    } elseif (count($vacated) === 1 && count($arrived) === 1) {
        [$f, $t] = [$vacated[0], $arrived[0]];
        $piece   = strtoupper($from['board'][$f]);
        $capture = $from['board'][$t] !== '.' || ($piece === 'P' && $f % 8 !== $t % 8);
        $dest    = $sq($t) . ($piece === 'P' && strtoupper($to['board'][$t]) !== 'P' ? '=' . strtoupper($to['board'][$t]) : '');
        if ($piece === 'P') {
            $candidates[] = ($capture ? $sq($f)[0] . 'x' : '') . $dest;
        } else {
            $x = $capture ? 'x' : '';
            foreach (['', $sq($f)[0], $sq($f)[1], $sq($f)] as $dis) $candidates[] = $piece . $dis . $x . $dest;
        }
    }
    foreach ($candidates as $san) {
        try {
            $engine = new ChessEngine($from['fen']);
            $engine->applySan($san);
        } catch (Throwable) {
            continue;
        }
        if (position_key($engine->fen()) === $to['key']) return $san;
    }
    return null;
}
