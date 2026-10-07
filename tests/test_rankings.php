<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/Autoload.php';

it('isGambit: gambits and countergambits yes, "… Gambit Declined" no', function () {
    assert_eq(true,  Opening::isGambit('Italian Game: Evans Gambit'));
    assert_eq(true,  Opening::isGambit("Queen's Gambit Accepted"));
    assert_eq(true,  Opening::isGambit('Queen\'s Gambit Declined: Albin Countergambit'));
    assert_eq(false, Opening::isGambit("Queen's Gambit Declined: Orthodox Defense"));
    assert_eq(false, Opening::isGambit("King's Gambit Declined"));
    assert_eq(false, Opening::isGambit('Sicilian Defense'));
});

it('compact game counts never read "1000K"', function () {
    assert_eq('9,870', Rankings::compact(9870));
    assert_eq('845K',  Rankings::compact(845210));
    assert_eq('999K',  Rankings::compact(999499));
    assert_eq('1M',    Rankings::compact(999500));
    assert_eq('2.3M',  Rankings::compact(2345678));
    assert_eq('1.5B',  Rankings::compact(1527871757));
});

it('lineTails tells same-name lines apart and skips unique names', function () {
    $tails = Opening::lineTails([
        ['id' => 1, 'name' => 'X', 'eco' => 'A00', 'pgn_moves' => '1. e4 e5 2. Nf3 Nc6'],
        ['id' => 2, 'name' => 'X', 'eco' => 'A00', 'pgn_moves' => '1. e4 e5 2. Nf3 Nf6'],
        ['id' => 3, 'name' => 'Y', 'eco' => 'A00', 'pgn_moves' => '1. d4'],
    ]);
    assert_eq(['2. Nf3 Nc6', '2. Nf3 Nf6'], [$tails[1], $tails[2]]);
    assert_eq(false, isset($tails[3]));
});

it('LevelStats::trend: which way the line goes as the players get stronger', function () {
    $l = static fn (int $w, int $d, int $b): array => ['white' => $w, 'draws' => $d, 'black' => $b, 'games' => $w + $d + $b];
    // Black's line: 55% under 1400, 49% at 2200 and up.
    $down = ['beginners' => $l(4300, 400, 5300), 'experts' => $l(4700, 800, 4500)];
    assert_eq('The stronger the players, the worse this line does for Black: Black scores 55% under 1400, 49% at 2200 and up (wins plus half the draws).',
        LevelStats::trend($down, 'black'));
    // White's line, with enough master games to mention them.
    $up = ['beginners' => $l(4500, 400, 5100), 'experts' => $l(5000, 600, 4400), 'masters' => $l(150, 200, 100)];
    assert_eq('The stronger the players, the better this line does for White: White scores 47% under 1400, 53% at 2200 and up and 56% in master games (wins plus half the draws).',
        LevelStats::trend($up, 'white'));
    $flat = ['beginners' => $l(4800, 400, 4800), 'experts' => $l(4700, 600, 4700)];
    assert_eq('Rating changes little here: White scores 50% both under 1400 and at 2200 and up (wins plus half the draws).',
        LevelStats::trend($flat, 'white'));
    // Too few games at one end, or a band missing: nothing.
    assert_eq(null, LevelStats::trend(['beginners' => $l(900, 100, 900), 'experts' => $l(4700, 600, 4700)], 'white'));
    assert_eq(null, LevelStats::trend(['masters' => $l(150, 200, 100)], 'white'));
});

it('EngineEval::practice: only when the clearly worse side still scores', function () {
    $stats = ['white' => 4600, 'draws' => 400, 'black' => 5000];   // Black 52%
    assert_eq('Even so, Black scores 52.0% here in practice: wins plus half the draws in rated Lichess games between players rated 1600 to 2500.',
        EngineEval::practice(['cp' => 201, 'mate' => null], $stats));
    assert_eq(null, EngineEval::practice(['cp' => 120, 'mate' => null], $stats));                                  // not clearly better
    assert_eq('Even so, White scores 48.0% here in practice: wins plus half the draws in rated Lichess games between players rated 1600 to 2500.',
        EngineEval::practice(['cp' => -201, 'mate' => null], $stats));                                             // the other way round
    assert_eq(null, EngineEval::practice(['cp' => 201, 'mate' => null], ['white' => 6000, 'draws' => 400, 'black' => 3600]));   // Black 38%
    assert_eq(null, EngineEval::practice(['cp' => null, 'mate' => 3], $stats));
    assert_eq(null, EngineEval::practice(['cp' => 201, 'mate' => null], ['white' => 400, 'draws' => 40, 'black' => 500]));  // too few games
});
