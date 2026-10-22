<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class SearchTest extends TestCase
{
    /**
     * The top names rankByName() gives for $q over a fixed list, indexed the way searchByName() does it.
     *
     * @return list<string>
     */
    private static function names(string $q): array
    {
        static $rows = null;
        $rows ??= array_map(static fn (int $i, string $name): array => Opening::indexRow([
            'id' => $i + 1, 'eco' => 'A00', 'name' => $name, 'slug' => 's' . $i,
            'depth' => 0, 'move_count' => 3, 'popularity' => 1000 - $i,   // popularity = order given
        ]), array_keys(self::NAMES), self::NAMES);
        return array_column(Opening::rankByName($rows, $q, 10), 'name');
    }

    private const NAMES = [
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
    ];

    #[TestDox('nameKey drops apostrophes, accents and hyphens')]
    public function testNameKeyDropsApostrophesAccentsAndHyphens(): void
    {
        $this->assertSame('queens gambit', Opening::nameKey("Queen's Gambit"));
        $this->assertSame('caro kann defense', Opening::nameKey('Caro-Kann Defense'));
        $this->assertSame('grunfeld defense', Opening::nameKey('Grünfeld Defense'));
    }

    #[TestDox('name search works without apostrophes')]
    public function testNameSearchWorksWithoutApostrophes(): void
    {
        $this->assertSame("Queen's Gambit", self::names('queens gambit')[0]);
        $this->assertSame("King's Indian Defense", self::names('kings indian')[0]);
        $this->assertSame("Bishop's Opening", self::names('bishops opening')[0]);
        $this->assertSame('Caro-Kann Defense', self::names('caro kann')[0]);
    }

    #[TestDox('name search puts the named variation before lines that only contain the word')]
    public function testNameSearchPutsTheNamedVariationBefore(): void
    {
        $this->assertSame('Sicilian Defense: Dragon Variation', self::names('sicilian dragon')[0]);
        $this->assertSame('Italian Game: Two Knights Defense, Fried Liver Attack', self::names('fried liver')[0]);
    }

    #[TestDox('name search forgives a typo and knows the usual short names')]
    public function testNameSearchForgivesATypoAndKnows(): void
    {
        $this->assertSame('Sicilian Defense: Najdorf Variation', self::names('naidorf')[0]);
        $this->assertSame("Queen's Gambit Declined", self::names('QGD')[0]);
        $this->assertSame("King's Indian Defense", self::names('kid')[0]);
        $this->assertSame('Ruy Lopez', self::names('spanish')[0]);
        $this->assertSame('Grünfeld Defense', self::names('gruenfeld')[0]);
    }

    #[TestDox('name search finds nothing for unrelated words')]
    public function testNameSearchFindsNothingForUnrelatedWords(): void
    {
        $this->assertSame([], self::names('zzzz qqqq'));
    }
}
