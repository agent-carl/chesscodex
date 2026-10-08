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
