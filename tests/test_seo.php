<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/Autoload.php';

it('leadSentence: the first sentence of a description, plain text', function () {
    assert_eq('The Vienna Game (1.e4 e5 2.Nc3) is a flexible open game.',
        Opening::leadSentence("The Vienna Game (1.e4 e5 2.Nc3) is a flexible open game. Instead of the usual 2.Nf3, White develops.\n\n### Origins\n\nText."));
    assert_eq('The Najdorf (5...a6) is the main line of the Sicilian Defense.',
        Opening::leadSentence('The Najdorf (5...a6) is the main line of the [Sicilian Defense](/openings/sicilian-defense). It is popular.'));
    assert_eq("The Scachs d'amor line is old.", Opening::leadSentence("The *Scachs d'amor* line is old."), 'single paragraph, emphasis unwrapped');
    assert_eq(null, Opening::leadSentence(''));
    assert_eq(null, Opening::leadSentence("### Origins\n\nText."), 'starts with a heading');
});

it('aliases: other names from db/aliases.tsv; the title takes one with new words', function () {
    assert_eq(['Spanish Opening', 'Spanish Game'], Opening::aliases('Ruy Lopez'));
    assert_eq([], Opening::aliases('Ruy Lopez: Morphy Defense'), 'only the exact name');
    assert_eq('Spanish Opening', Opening::titleAlias('Ruy Lopez'));
    assert_eq(null, Opening::titleAlias('Dutch Defense: Stonewall Variation'), 'Stonewall Dutch has no new word');
    assert_eq(null, Opening::titleAlias('Sicilian Defense'));
});

it('article: "the" before a name, none before a person\'s possessive', function () {
    assert_eq('the ', Opening::article('Caro-Kann Defense'));
    assert_eq('the ', Opening::article('Ruy Lopez: Morphy Defense'));
    assert_eq('the ', Opening::article("King's Gambit Accepted"));
    assert_eq('the ', Opening::article("Queen's Pawn Game: London System"));
    assert_eq('the ', Opening::article("Bishop's Opening"));
    assert_eq('the ', Opening::article("Van't Kruijs Opening"));
    assert_eq('',     Opening::article("Petrov's Defense: Classical Attack"));
    assert_eq('',     Opening::article("Anderssen's Opening"));
});

it('Replies: the main answers and which scores best, from the side to move', function () {
    $m = static fn (string $san, int $w, int $d, int $b): array => ['san' => $san, 'white' => $w, 'draws' => $d, 'black' => $b];
    // Black to move; 10,000 games. Nc6 is under 5%, h6 under 100 games.
    $top = [$m('e6', 5100, 600, 4300), $m('c5', 1800, 200, 2000), $m('Nc6', 200, 20, 180), $m('h6', 50, 0, 40)];
    $main = Replies::main($top, 16000, false);
    assert_eq(['e6', 'c5'], array_column($main, 'san'));
    assert_eq(52.5, round($main[1]['score'], 1), 'score: wins plus half the draws, for Black');
    [$most, $best, $worst] = Replies::picks($main);
    assert_eq(['e6', 'c5', 'e6'], [$most['san'], $best['san'], $worst['san']]);
    assert_eq([], Replies::main([$m('e6', 500, 0, 500), $m('c5', 50, 0, 40)], 1090, false), 'one main answer is no choice');

    $none = static fn (string $san): ?array => null;
    assert_eq('<strong>3…c5</strong> scores best for Black: 52.5%, with Black winning 50.0% and White 45.0% of 4,000 games.'
        . ' The most played answer, <strong>3…e6</strong>, scores lowest: 46.0% in 10K games.'
        . ' Stockfish\'s first choice is <strong>3…Bf5</strong>.',
        Replies::html($main, false, '3…', $none, 'Bf5'));
    $named = static fn (string $san): ?array => $san === 'e6' ? ['Main Line', '/openings/x'] : null;
    $mainWhite = Replies::main([$m('e6', 5100, 600, 4300), $m('c5', 1800, 200, 2000)], 14000, true);
    assert_eq('The most played answer, <strong>4. e6</strong> (<a href="/openings/x">Main Line</a>), also scores best for White:'
        . ' 54.0%, with White winning 51.0% and Black 43.0% of 10K games.'
        . ' Of the main answers, <strong>4. c5</strong> scores lowest: 47.5%. Stockfish\'s first choice is also <strong>4. e6</strong>.',
        Replies::html($mainWhite, true, '4. ', $named, 'e6'));
});

it('name search finds a line by another of its names', function () {
    $rows = array_map([Opening::class, 'indexRow'], [
        ['id' => 1, 'eco' => 'C42', 'name' => "Petrov's Defense", 'slug' => 'petrovs-defense', 'depth' => 1, 'move_count' => 4, 'popularity' => 100],
        ['id' => 2, 'eco' => 'C40', 'name' => "King's Knight Opening", 'slug' => 'kings-knight-opening', 'depth' => 1, 'move_count' => 3, 'popularity' => 900],
        ['id' => 3, 'eco' => 'A90', 'name' => 'Dutch Defense: Stonewall Variation', 'slug' => 'dutch-stonewall', 'depth' => 2, 'move_count' => 14, 'popularity' => 50],
        ['id' => 4, 'eco' => 'A80', 'name' => 'Dutch Defense', 'slug' => 'dutch-defense', 'depth' => 1, 'move_count' => 2, 'popularity' => 5000],
    ]);
    assert_eq("Petrov's Defense", Opening::rankByName($rows, 'russian game')[0]['name'] ?? null);
    assert_eq('Dutch Defense: Stonewall Variation', Opening::rankByName($rows, 'stonewall dutch')[0]['name'] ?? null);
    assert_eq(false, array_key_exists('aliases', Opening::rankByName($rows, 'dutch')[0]), 'index fields stay internal');
});

it('gambitKey: a line counts once per gambit', function () {
    assert_eq('Danish Gambit', Rankings::gambitKey('Danish Gambit Accepted: Copenhagen Defense'));
    assert_eq('Danish Gambit', Rankings::gambitKey('Danish Gambit'));
    assert_eq('Greco Gambit', Rankings::gambitKey('Italian Game: Classical Variation, Greco Gambit, Modern Line'));
    assert_eq('Vienna Gambit', Rankings::gambitKey('Vienna Game: Vienna Gambit'));
    assert_eq('Vienna Gambit', Rankings::gambitKey('Vienna Gambit, with Max Lange Defense'));
    assert_eq('Paulsen Countergambit', Rankings::gambitKey('Elephant Gambit: Paulsen Countergambit'), 'a countergambit first');
    assert_eq('Falkbeer Countergambit', Rankings::gambitKey("King's Gambit Declined: Falkbeer Countergambit"));
    assert_eq('Ruy Lopez', Rankings::gambitKey('Ruy Lopez'));
});

it('ecoLabel: the variation most lines of a code share, else the family', function () {
    $najdorf = ['Sicilian Defense: Najdorf Variation', 'Sicilian Defense: Najdorf Variation, Adams Attack',
        'Sicilian Defense: Najdorf Variation, English Attack', 'Sicilian Defense: Scheveningen Variation, Delayed Keres Attack'];
    assert_eq('Sicilian Defense: Najdorf Variation', Opening::ecoLabel($najdorf));
    assert_eq('Sicilian Defense', Opening::ecoLabel(['Sicilian Defense: Najdorf Variation', 'Sicilian Defense: Scheveningen Variation']), 'no majority');
    assert_eq("Petrov's Defense", Opening::ecoLabel(["Petrov's Defense", "Petrov's Defense", "Petrov's Defense: Classical Attack"]));
    assert_eq('Van Geet Opening & Polish Opening', Opening::ecoLabel(['Van Geet Opening', 'Van Geet Opening: Battambang', 'Polish Opening']));
    assert_eq('A, B & more', Opening::ecoLabel(['A', 'A: x', 'B', 'C']));
});

it('Router::matches tells pages from 404s', function () {
    $r = new Router();
    $r->add('/search', static function () {});
    $r->add('#^/openings/([a-z0-9-]+)/?$#', static function () {});
    assert_eq(true,  $r->matches('/search'));
    assert_eq(true,  $r->matches('/openings/italian-game'));
    assert_eq(false, $r->matches('/Openings/Italian-Game'));
    assert_eq(false, $r->matches('/uk'));
});
