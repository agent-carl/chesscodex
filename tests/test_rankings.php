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
