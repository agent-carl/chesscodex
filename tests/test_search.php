<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/Autoload.php';
require_once __DIR__ . '/../lib/db.php';

/** Rows the way searchByName() indexes them; popularity = order given. */
function search_rows(array $names): array
{
    $rows = [];
    foreach ($names as $i => $name) {
        $rows[] = Opening::indexRow([
            'id' => $i + 1, 'eco' => 'A00', 'name' => $name, 'slug' => 's' . $i,
            'depth' => 0, 'move_count' => 3, 'popularity' => 1000 - $i,
        ]);
    }
    return $rows;
}

function search_names(array $rows, string $q): array
{
    return array_column(Opening::rankByName($rows, $q, 10), 'name');
}

$searchRows = search_rows([
    "King's Pawn Game",
    'Sicilian Defense',
    'Sicilian Defense: Hyperaccelerated Dragon',
    'Sicilian Defense: Accelerated Dragon',
    "Queen's Gambit Declined",
    'Sicilian Defense: Dragon Variation',
    "Queen's Gambit Accepted",
    "Queen's Gambit",
    "King's Indian Defense",
    'Benoni Defense: Benoni-Indian Defense, Kingside Move Order',
    "Bishop's Opening",
    'Sicilian Defense: Najdorf Variation',
    'Caro-Kann Defense',
    'Italian Game: Anti-Fried Liver Defense',
    'Italian Game: Two Knights Defense, Fried Liver Attack',
    'Grünfeld Defense',
    'Ruy Lopez',
]);

it('nameKey drops apostrophes, accents and hyphens', function () {
    assert_eq('queens gambit', Opening::nameKey("Queen's Gambit"));
    assert_eq('caro kann defense', Opening::nameKey('Caro-Kann Defense'));
    assert_eq('grunfeld defense', Opening::nameKey('Grünfeld Defense'));
});

it('name search works without apostrophes', function () use ($searchRows) {
    assert_eq("Queen's Gambit", search_names($searchRows, 'queens gambit')[0]);
    assert_eq("King's Indian Defense", search_names($searchRows, 'kings indian')[0]);
    assert_eq("Bishop's Opening", search_names($searchRows, 'bishops opening')[0]);
    assert_eq('Caro-Kann Defense', search_names($searchRows, 'caro kann')[0]);
});

it('name search puts the named variation before lines that only contain the word', function () use ($searchRows) {
    assert_eq('Sicilian Defense: Dragon Variation', search_names($searchRows, 'sicilian dragon')[0]);
    assert_eq('Italian Game: Two Knights Defense, Fried Liver Attack', search_names($searchRows, 'fried liver')[0]);
});

it('name search forgives a typo and knows the usual short names', function () use ($searchRows) {
    assert_eq('Sicilian Defense: Najdorf Variation', search_names($searchRows, 'naidorf')[0]);
    assert_eq("Queen's Gambit Declined", search_names($searchRows, 'QGD')[0]);
    assert_eq("King's Indian Defense", search_names($searchRows, 'kid')[0]);
    assert_eq('Ruy Lopez', search_names($searchRows, 'spanish')[0]);
    assert_eq('Grünfeld Defense', search_names($searchRows, 'gruenfeld')[0]);
});

it('name search finds nothing for unrelated words', function () use ($searchRows) {
    assert_eq([], search_names($searchRows, 'zzzz qqqq'));
});
