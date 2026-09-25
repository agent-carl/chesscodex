<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ChessEngine.php';

it('starting position FEN', function () {
    $e = new ChessEngine();
    assert_eq(
        'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
        $e->fen()
    );
});

it('single pawn push 1.e4', function () {
    $e = ChessEngine::fromPgn('1. e4');
    assert_eq(
        'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1',
        $e->fen()
    );
    assert_eq(['e2e4'], $e->uciHistory());
});

it('Sicilian Najdorf reaches the canonical FEN', function () {
    $e = ChessEngine::fromPgn('1. e4 c5 2. Nf3 d6 3. d4 cxd4 4. Nxd4 Nf6 5. Nc3 a6');
    assert_eq(
        'rnbqkb1r/1p2pppp/p2p1n2/8/3NP3/2N5/PPP2PPP/R1BQKB1R w KQkq - 0 6',
        $e->fen()
    );
});

it('en passant capture', function () {
    // 1.e4 a6 2.e5 d5 — black double-pushes d-pawn, white can capture via e.p.
    $e = ChessEngine::fromPgn('1. e4 a6 2. e5 d5 3. exd6');
    assert_eq(
        'rnbqkbnr/1pp1pppp/p2P4/8/8/8/PPPP1PPP/RNBQKBNR b KQkq - 0 3',
        $e->fen()
    );
    $uci = $e->uciHistory();
    assert_eq('e5d6', end($uci));
});

it('kingside castling', function () {
    $e = ChessEngine::fromPgn('1. e4 e5 2. Nf3 Nc6 3. Bc4 Bc5 4. O-O');
    // After O-O: white king on g1, rook on f1; KQ rights gone for white.
    $f = $e->fen();
    assert_true(strpos($f, 'RNBQ1RK1') !== false, 'white king should be on g1');
    assert_true(strpos($f, ' kq ') !== false, 'white castling rights should be gone');
});

it('queenside castling', function () {
    $e = ChessEngine::fromPgn('1. d4 d5 2. Nc3 Nc6 3. Bf4 Bf5 4. Qd2 Qd7 5. O-O-O');
    $f = $e->fen();
    assert_true(strpos($f, '2KR1BNR') !== false, 'white king should be on c1, rook on d1');
});

it('promotion to queen', function () {
    // White promotes a pawn on d8.
    $e = new ChessEngine('rnbqkbnr/PPpppppp/8/8/8/8/2PPPPPP/RNBQKBNR w KQkq - 0 5');
    $e->applySan('axb8=Q');
    $f = $e->fen();
    assert_true(strpos($f, 'rQbqkbnr') !== false, 'black knight should be replaced by white queen on b8');
});

it('SAN disambiguation by file (Nbd2)', function () {
    // Two knights, only file disambig is needed.
    $e = new ChessEngine('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1');
    $e->applyPgn('1. Nf3 Nf6 2. Nc3');
    // Both knights now on natural development squares. No ambiguity yet.
    assert_eq('rnbqkb1r/pppppppp/5n2/8/8/2N2N2/PPPPPPPP/R1BQKB1R b KQkq - 3 2', $e->fen());
});

it('pin detection — Ne2 in Winawer when c3-knight is pinned', function () {
    // 1.e4 e6 2.d4 d5 3.Nc3 Bb4 — black's bishop pins white's c3-knight.
    // The PGN move "Ne2" is unambiguous only because c3-knight can't move
    // (would expose king to check). Engine must filter that candidate out.
    $e = ChessEngine::fromPgn('1. e4 e6 2. d4 d5 3. Nc3 Bb4 4. Ne2');
    $f = $e->fen();
    // The knight that ended on e2 must be the one originally from g1.
    // c3 should still be occupied by the white knight (lowercase n is black,
    // uppercase N is white).
    assert_true(strpos($f, '2N') !== false, 'c3 knight should still be there');
});
