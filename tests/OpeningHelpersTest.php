<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** The static helpers of Opening that take a string or a row and need no database. */
final class OpeningHelpersTest extends TestCase
{
    #[TestDox('movesLabel: plies to full moves, singular for one')]
    public function testMovesLabel(): void
    {
        $this->assertSame('0 moves', Opening::movesLabel(0));
        $this->assertSame('1 move',  Opening::movesLabel(1));
        $this->assertSame('1 move',  Opening::movesLabel(2));
        $this->assertSame('2 moves', Opening::movesLabel(3), 'an odd ply count rounds up');
        $this->assertSame('10 moves', Opening::movesLabel(20));
    }

    #[TestDox('movesFrom: numbered moves after the parent, Black first as "3…Bc5"')]
    public function testMovesFrom(): void
    {
        $pgn = '1. e4 e5 2. Nf3 Nc6 3. Bc4 Bc5';
        $this->assertSame('1. e4 e5 2. Nf3 Nc6 3. Bc4 Bc5', Opening::movesFrom($pgn, 0));
        $this->assertSame('3. Bc4 Bc5', Opening::movesFrom($pgn, 4));
        $this->assertSame('2…Nc6 3. Bc4 Bc5', Opening::movesFrom($pgn, 3));
        $this->assertSame('3…Bc5', Opening::movesFrom($pgn, 5));
        $this->assertSame('', Opening::movesFrom($pgn, 6), 'nothing after the last move');
        $this->assertSame('', Opening::movesFrom($pgn, 40));
        $this->assertSame('1. e4 e5', Opening::movesFrom('1. e4 e5', -3), 'a negative ply counts as 0');
        $this->assertSame('', Opening::movesFrom('', 0));
        $this->assertSame('6…b5', Opening::movesFrom('1. e4 e5 2. Nf3 Nc6 3. Bb5 a6 4. Ba4 Nf6 5. O-O Be7 6. Re1 b5', 11));
        $this->assertSame('1. e4 e5', Opening::movesFrom('1.e4 e5', 0), 'a move number glued to the move');
    }

    #[TestDox('keepNumbers: a no-break space after "N." only')]
    public function testKeepNumbers(): void
    {
        $this->assertSame("1.\u{00A0}e4 e5 2.\u{00A0}Nf3", Opening::keepNumbers('1. e4 e5 2. Nf3'));
        $this->assertSame("12.\u{00A0}Qd2", Opening::keepNumbers('12. Qd2'));
        $this->assertSame('e4 e5', Opening::keepNumbers('e4 e5'));
        $this->assertSame('', Opening::keepNumbers(''));
    }

    #[TestDox('family: the part before the first colon or comma')]
    public function testFamily(): void
    {
        $this->assertSame('Sicilian Defense', Opening::family('Sicilian Defense: Najdorf Variation'));
        $this->assertSame('Vienna Gambit', Opening::family('Vienna Gambit, with Max Lange Defense'));
        $this->assertSame('Caro-Kann Defense', Opening::family('Caro-Kann Defense'));
        $this->assertSame('', Opening::family(''));
        $this->assertSame('', Opening::family(': odd'));
    }

    #[TestDox('uci: split on spaces, empty for a missing or blank value')]
    public function testUci(): void
    {
        $this->assertSame(['e2e4', 'e7e5'], Opening::uci(['uci' => 'e2e4 e7e5']));
        $this->assertSame(['e2e4'], Opening::uci(['uci' => '  e2e4 ']));
        $this->assertSame([], Opening::uci(['uci' => '']));
        $this->assertSame([], Opening::uci(['uci' => '   ']));
        $this->assertSame([], Opening::uci([]));
        $this->assertSame([], Opening::uci(['uci' => null]));
    }

    #[TestDox('article: "the" except before a person\'s possessive; King\'s, Queen\'s, Bishop\'s, Knight\'s keep it')]
    public function testArticle(): void
    {
        $this->assertSame('the ', Opening::article('Caro-Kann Defense'));
        $this->assertSame('',     Opening::article("Petrov's Defense"));
        $this->assertSame('',     Opening::article("Alekhine's Defense: Four Pawns Attack"));
        $this->assertSame('the ', Opening::article("King's Indian Defense"));
        $this->assertSame('the ', Opening::article("Queen's Gambit"));
        $this->assertSame('the ', Opening::article("Bishop's Opening"));
        $this->assertSame('the ', Opening::article("Knight's Tour"));
        $this->assertSame('the ', Opening::article("Sicilian Defense: Smith-Morra Gambit, Chicago Defense's Line"), 'only a leading possessive counts');
        $this->assertSame('the ', Opening::article(''));
    }

    #[TestDox('nameSide: defenses and countergambits are Black\'s whatever the ply, a trailing comma part decides')]
    public function testNameSide(): void
    {
        $this->assertSame('black', Opening::nameSide('Queen\'s Gambit Declined: Albin Countergambit', 3));
        $this->assertSame('black', Opening::nameSide('Scotch Game: Scotch Gambit, Dubois Réti Defense', 8));
        $this->assertSame('white', Opening::nameSide('Defense Opening: Sicilian Attack', 3), '"Defense" mid-name does not count');
        $this->assertSame('black', Opening::nameSide('Pirc Defence', 3), 'British spelling');
        $this->assertSame('white', Opening::nameSide('', 1));
        $this->assertSame('black', Opening::nameSide('', 0));
    }

    #[TestDox('isGambit: case-insensitive, Declined lines and non-gambits excluded')]
    public function testIsGambit(): void
    {
        $this->assertTrue(Opening::isGambit('GAMBIT lines'));
        $this->assertTrue(Opening::isGambit('Queen\'s Gambit Declined: Albin Countergambit'), 'a countergambit is a gambit');
        $this->assertFalse(Opening::isGambit("Queen's Gambit  Declined"), 'several spaces before Declined');
        $this->assertFalse(Opening::isGambit(''));
    }

    #[TestDox('nameKey: lowercase, accents folded, apostrophes dropped, punctuation to single spaces')]
    public function testNameKey(): void
    {
        $this->assertSame('queens gambit declined', Opening::nameKey("Queen's Gambit Declined"));
        $this->assertSame('queens gambit', Opening::nameKey('Queen’s Gambit'), 'curly apostrophe');
        $this->assertSame('caro kann defense', Opening::nameKey('Caro-Kann Defense'));
        $this->assertSame('grunfeld defense', Opening::nameKey('Grünfeld Defense'));
        $this->assertSame('reti opening', Opening::nameKey('  Réti   Opening '));
        $this->assertSame('', Opening::nameKey('?!'));
    }

    #[TestDox('lineTails: same name AND eco unless $ignoreEco; tails are the shortest unique ending')]
    public function testLineTails(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'X', 'eco' => 'A00', 'pgn_moves' => '1. e4 e5 2. Nf3 Nc6 3. Bc4 Bc5'],
            ['id' => 2, 'name' => 'X', 'eco' => 'A00', 'pgn_moves' => '1. e4 e5 2. Nf3 Nc6 3. Bb5 Bc5'],
            ['id' => 3, 'name' => 'X', 'eco' => 'A01', 'pgn_moves' => '1. d4 d5'],
        ];
        $t = Opening::lineTails($rows);
        $this->assertSame(['3. Bc4 Bc5', '3. Bb5 Bc5'], [$t[1], $t[2]]);
        $this->assertArrayNotHasKey(3, $t, 'a different ECO is a different group');
        $t = Opening::lineTails($rows, true);
        $this->assertSame([1, 2, 3], array_keys($t), 'ignoring ECO puts all three in one group');
        $this->assertSame('1. d4 d5', $t[3], 'a line that shares no ending with the others still gets a tail');
        $this->assertSame([], Opening::lineTails([]));
        $this->assertSame([], Opening::lineTails([$rows[0]]));
    }

    #[TestDox('lineTails: a line that is a prefix of another is told apart by its whole move list')]
    public function testLineTailsPrefix(): void
    {
        $t = Opening::lineTails([
            ['id' => 1, 'name' => 'X', 'eco' => 'B10', 'pgn_moves' => '1. e4 c6'],
            ['id' => 2, 'name' => 'X', 'eco' => 'B10', 'pgn_moves' => '1. e4 c6 2. d4 d5'],
        ]);
        $this->assertSame('1. e4 c6', $t[1]);
        $this->assertSame('2. d4 d5', $t[2]);
    }

    #[TestDox('ecoLabel: a single name, empty-colon names and the 60% threshold')]
    public function testEcoLabel(): void
    {
        $this->assertSame('Sicilian Defense: Najdorf Variation',
            Opening::ecoLabel(['Sicilian Defense: Najdorf Variation', 'Sicilian Defense: Najdorf Variation, English Attack', 'Sicilian Defense: Najdorf Variation, Opocensky', 'Sicilian Defense: Scheveningen', 'Sicilian Defense: Najdorf Variation, X']));
        $this->assertSame('Sicilian Defense',
            Opening::ecoLabel(['Sicilian Defense', 'Sicilian Defense: A', 'Sicilian Defense: B']), 'no variation reaches 60%');
        $this->assertSame('Sicilian Defense', Opening::ecoLabel(['Sicilian Defense']));
        $this->assertSame('A & B', Opening::ecoLabel(['A', 'B']));
    }

    #[TestDox('leadSentence: empty, heading, links, emphasis, no full stop, "1.e4"')]
    public function testLeadSentence(): void
    {
        $this->assertNull(Opening::leadSentence(''));
        $this->assertNull(Opening::leadSentence("  \n\n  "));
        $this->assertNull(Opening::leadSentence("## Heading\n\nText."));
        $this->assertSame('See Najdorf for more.', Opening::leadSentence('See [Najdorf](/x) for **more**. Next one.'));
        $this->assertSame('No full stop here', Opening::leadSentence('No full stop here'));
        $this->assertSame('It starts 1.e4 c5 and goes on.', Opening::leadSentence('It starts 1.e4 c5 and goes on. Then more.'));
        $this->assertSame('Is it good?', Opening::leadSentence('Is it good? Yes.'), 'question mark before a capital ends the sentence');
    }

    #[TestDox('titleAlias: the first alias with a word the name lacks, else null')]
    public function testTitleAlias(): void
    {
        $this->assertSame('Spanish Opening', Opening::titleAlias('Ruy Lopez'));
        $this->assertNull(Opening::titleAlias('Definitely Not An Opening'));
    }
}
