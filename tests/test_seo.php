<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/Autoload.php';

it('isThin: rarely played lines are thin, mates and traps never', function () {
    assert_eq(true,  Opening::isThin(['name' => 'Sicilian Defense: Acton Extension', 'popularity' => 98]));
    assert_eq(false, Opening::isThin(['name' => 'Sicilian Defense', 'popularity' => 100]));
    assert_eq(false, Opening::isThin(['name' => "Barnes Opening: Fool's Mate", 'popularity' => 0]));
    assert_eq(false, Opening::isThin(['name' => 'Ruy Lopez: Open, Tarrasch Trap', 'popularity' => 6]));
    assert_eq(true,  Opening::isThin(['name' => 'Some Opening: Mateo Gambit', 'popularity' => 3]));
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
