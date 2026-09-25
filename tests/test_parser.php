<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/parser.php';

it('slugify ASCII', function () {
    assert_eq('amar-opening', chess_codex_slugify('Amar Opening'));
    assert_eq('amar-opening-paris-gambit', chess_codex_slugify('Amar Opening: Paris Gambit'));
});

it('slugify drops apostrophes (anderssens-opening, not anderssen-s-opening)', function () {
    assert_eq('anderssens-opening', chess_codex_slugify("Anderssen's Opening"));
});

it('slugify ASCII-folds Latin diacritics', function () {
    assert_eq('kadas-opening-bucker-gambit', chess_codex_slugify('Kádas Opening: Bücker Gambit'));
    assert_eq('reti-opening', chess_codex_slugify('Réti Opening'));
});

it('canonicalize_pgn strips move numbers and annotations', function () {
    assert_eq(
        'Nh3 d5 g3 e5 f4',
        chess_codex_canonicalize_pgn('1. Nh3 d5 2. g3 e5 3. f4')
    );
    assert_eq(
        'e4 e5 Nf3',
        chess_codex_canonicalize_pgn('1. e4! e5?! 2. Nf3+')
    );
});

it('count_plies', function () {
    assert_eq(0, chess_codex_count_plies(''));
    assert_eq(1, chess_codex_count_plies('1. Nh3'));
    assert_eq(5, chess_codex_count_plies('1. Nh3 d5 2. g3 e5 3. f4'));
});

it('resolve_parents picks longest prefix within ECO group', function () {
    $rows = [
        ['eco_group' => 'A', 'canon' => 'Nh3'],
        ['eco_group' => 'A', 'canon' => 'Nh3 d5'],
        ['eco_group' => 'A', 'canon' => 'Nh3 d5 g3 e5 f4'],
        ['eco_group' => 'B', 'canon' => 'e4 c5'], // different group, ignored
    ];
    $parents = chess_codex_resolve_parents($rows);
    assert_eq(null, $parents[0], 'first row is root');
    assert_eq(0,    $parents[1], 'second row\'s parent is row 0');
    assert_eq(1,    $parents[2], 'third row\'s parent is row 1');
    assert_eq(null, $parents[3], 'different ECO group → no parent');
});
