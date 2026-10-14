<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class SeoTest extends TestCase
{
    #[TestDox('leadSentence: the first sentence of a description, plain text')]
    public function testLeadSentenceTheFirstSentenceOfADescription(): void
    {
        $this->assertSame('The Vienna Game (1.e4 e5 2.Nc3) is a flexible open game.',
            Opening::leadSentence("The Vienna Game (1.e4 e5 2.Nc3) is a flexible open game. Instead of the usual 2.Nf3, White develops.\n\n### Origins\n\nText."));
        $this->assertSame('The Najdorf (5...a6) is the main line of the Sicilian Defense.',
            Opening::leadSentence('The Najdorf (5...a6) is the main line of the [Sicilian Defense](/openings/sicilian-defense). It is popular.'));
        $this->assertSame("The Scachs d'amor line is old.", Opening::leadSentence("The *Scachs d'amor* line is old."), 'single paragraph, emphasis unwrapped');
        $this->assertSame(null, Opening::leadSentence(''));
        $this->assertSame(null, Opening::leadSentence("### Origins\n\nText."), 'starts with a heading');
    }

    #[TestDox('aliases: other names from db/aliases.tsv; the title takes one with new words')]
    public function testAliasesOtherNamesFromDbAliasesTsv(): void
    {
        $this->assertSame(['Spanish Opening', 'Spanish Game'], Opening::aliases('Ruy Lopez'));
        $this->assertSame([], Opening::aliases('Ruy Lopez: Morphy Defense'), 'only the exact name');
        $this->assertSame('Spanish Opening', Opening::titleAlias('Ruy Lopez'));
        $this->assertSame(null, Opening::titleAlias('Dutch Defense: Stonewall Variation'), 'Stonewall Dutch has no new word');
        $this->assertSame(null, Opening::titleAlias('Sicilian Defense'));
    }

    #[TestDox('article: "the" before a name, none before a person\'s possessive')]
    public function testArticleTheBeforeANameNoneBefore(): void
    {
        $this->assertSame('the ', Opening::article('Caro-Kann Defense'));
        $this->assertSame('the ', Opening::article('Ruy Lopez: Morphy Defense'));
        $this->assertSame('the ', Opening::article("King's Gambit Accepted"));
        $this->assertSame('the ', Opening::article("Queen's Pawn Game: London System"));
        $this->assertSame('the ', Opening::article("Bishop's Opening"));
        $this->assertSame('the ', Opening::article("Van't Kruijs Opening"));
        $this->assertSame('',     Opening::article("Petrov's Defense: Classical Attack"));
        $this->assertSame('',     Opening::article("Anderssen's Opening"));
    }

    #[TestDox('Replies: the main answers and which scores best, from the side to move')]
    public function testRepliesTheMainAnswersAndWhichScores(): void
    {
        $m = static fn (string $san, int $w, int $d, int $b): array => ['san' => $san, 'white' => $w, 'draws' => $d, 'black' => $b];
        // Black to move; 10,000 games. Nc6 is under 5%, h6 under 100 games.
        $top = [$m('e6', 5100, 600, 4300), $m('c5', 1800, 200, 2000), $m('Nc6', 200, 20, 180), $m('h6', 50, 0, 40)];
        $main = Replies::main($top, 16000, false);
        $this->assertSame(['e6', 'c5'], array_column($main, 'san'));
        $this->assertSame(52.5, round($main[1]['score'], 1), 'score: wins plus half the draws, for Black');
        [$most, $best, $worst] = Replies::picks($main);
        $this->assertSame(['e6', 'c5', 'e6'], [$most['san'], $best['san'], $worst['san']]);
        $this->assertSame([], Replies::main([$m('e6', 500, 0, 500), $m('c5', 50, 0, 40)], 1090, false), 'one main answer is no choice');
    
        $none = static fn (string $san): ?array => null;
        $this->assertSame('<strong>3…c5</strong> scores best for Black: 52.5%, with Black winning 50.0% and White 45.0% of 4,000 games.'
            . ' The most played answer, <strong>3…e6</strong>, scores lowest: 46.0% in 10K games.'
            . ' Stockfish\'s first choice is <strong>3…Bf5</strong>.',
            Replies::html($main, false, '3…', $none, 'Bf5'));
        $named = static fn (string $san): ?array => $san === 'e6' ? ['Main Line', '/openings/x'] : null;
        $mainWhite = Replies::main([$m('e6', 5100, 600, 4300), $m('c5', 1800, 200, 2000)], 14000, true);
        $this->assertSame('The most played answer, <strong>4. e6</strong> (<a href="/openings/x">Main Line</a>), also scores best for White:'
            . ' 54.0%, with White winning 51.0% and Black 43.0% of 10K games.'
            . ' Of the main answers, <strong>4. c5</strong> scores lowest: 47.5%. Stockfish\'s first choice is also <strong>4. e6</strong>.',
            Replies::html($mainWhite, true, '4. ', $named, 'e6'));
    }

    #[TestDox('nameSide: whose choice the name records')]
    public function testNameSideWhoseChoiceTheNameRecords(): void
    {
        $this->assertSame('white', Opening::nameSide("Queen's Pawn Game: London System", 5));
        $this->assertSame('black', Opening::nameSide('Sicilian Defense', 2));
        $this->assertSame('white', Opening::nameSide('Sicilian Defense: Closed', 3), 'the last part decides');
        $this->assertSame('black', Opening::nameSide("King's Indian Defense", 5), 'filed after 3. Nc3, still Black\'s');
        $this->assertSame('black', Opening::nameSide("King's Gambit Declined: Falkbeer Countergambit", 4));
        $this->assertSame('white', Opening::nameSide('Italian Game: Two Knights Defense, Fried Liver Attack', 11));
    }

    #[TestDox('name search finds a line by another of its names')]
    public function testNameSearchFindsALineByAnother(): void
    {
        $rows = array_map([Opening::class, 'indexRow'], [
            ['id' => 1, 'eco' => 'C42', 'name' => "Petrov's Defense", 'slug' => 'petrovs-defense', 'depth' => 1, 'move_count' => 4, 'popularity' => 100],
            ['id' => 2, 'eco' => 'C40', 'name' => "King's Knight Opening", 'slug' => 'kings-knight-opening', 'depth' => 1, 'move_count' => 3, 'popularity' => 900],
            ['id' => 3, 'eco' => 'A90', 'name' => 'Dutch Defense: Stonewall Variation', 'slug' => 'dutch-stonewall', 'depth' => 2, 'move_count' => 14, 'popularity' => 50],
            ['id' => 4, 'eco' => 'A80', 'name' => 'Dutch Defense', 'slug' => 'dutch-defense', 'depth' => 1, 'move_count' => 2, 'popularity' => 5000],
        ]);
        $this->assertSame("Petrov's Defense", Opening::rankByName($rows, 'russian game')[0]['name'] ?? null);
        $this->assertSame('Dutch Defense: Stonewall Variation', Opening::rankByName($rows, 'stonewall dutch')[0]['name'] ?? null);
        $this->assertSame(false, array_key_exists('aliases', Opening::rankByName($rows, 'dutch')[0]), 'index fields stay internal');
    }

    #[TestDox('gambitKey: a line counts once per gambit')]
    public function testGambitKeyALineCountsOncePerGambit(): void
    {
        $this->assertSame('Danish Gambit', Rankings::gambitKey('Danish Gambit Accepted: Copenhagen Defense'));
        $this->assertSame('Danish Gambit', Rankings::gambitKey('Danish Gambit'));
        $this->assertSame('Greco Gambit', Rankings::gambitKey('Italian Game: Classical Variation, Greco Gambit, Modern Line'));
        $this->assertSame('Vienna Gambit', Rankings::gambitKey('Vienna Game: Vienna Gambit'));
        $this->assertSame('Vienna Gambit', Rankings::gambitKey('Vienna Gambit, with Max Lange Defense'));
        $this->assertSame('Paulsen Countergambit', Rankings::gambitKey('Elephant Gambit: Paulsen Countergambit'), 'a countergambit first');
        $this->assertSame('Falkbeer Countergambit', Rankings::gambitKey("King's Gambit Declined: Falkbeer Countergambit"));
        $this->assertSame('Ruy Lopez', Rankings::gambitKey('Ruy Lopez'));
    }

    #[TestDox('ecoLabel: the variation most lines of a code share, else the family')]
    public function testEcoLabelTheVariationMostLinesOfA(): void
    {
        $najdorf = ['Sicilian Defense: Najdorf Variation', 'Sicilian Defense: Najdorf Variation, Adams Attack',
            'Sicilian Defense: Najdorf Variation, English Attack', 'Sicilian Defense: Scheveningen Variation, Delayed Keres Attack'];
        $this->assertSame('Sicilian Defense: Najdorf Variation', Opening::ecoLabel($najdorf));
        $this->assertSame('Sicilian Defense', Opening::ecoLabel(['Sicilian Defense: Najdorf Variation', 'Sicilian Defense: Scheveningen Variation']), 'no majority');
        $this->assertSame("Petrov's Defense", Opening::ecoLabel(["Petrov's Defense", "Petrov's Defense", "Petrov's Defense: Classical Attack"]));
        $this->assertSame('Van Geet Opening & Polish Opening', Opening::ecoLabel(['Van Geet Opening', 'Van Geet Opening: Battambang', 'Polish Opening']));
        $this->assertSame('A, B & more', Opening::ecoLabel(['A', 'A: x', 'B', 'C']));
    }

    #[TestDox('Router::matches tells pages from 404s')]
    public function testRouterMatchesTellsPagesFrom404s(): void
    {
        $r = new Router();
        $r->add('/search', static function () {});
        $r->add('#^/openings/([a-z0-9-]+)/?$#', static function () {});
        $this->assertSame(true,  $r->matches('/search'));
        $this->assertSame(true,  $r->matches('/openings/italian-game'));
        $this->assertSame(false, $r->matches('/Openings/Italian-Game'));
        $this->assertSame(false, $r->matches('/uk'));
    }
}
