<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    #[TestDox('slugify ASCII')]
    public function testSlugifyASCII(): void
    {
        $this->assertSame('amar-opening', chess_codex_slugify('Amar Opening'));
        $this->assertSame('amar-opening-paris-gambit', chess_codex_slugify('Amar Opening: Paris Gambit'));
    }

    #[TestDox('slugify drops apostrophes (anderssens-opening, not anderssen-s-opening)')]
    public function testSlugifyDropsApostrophesAnderssensOpeningNotAnderssen(): void
    {
        $this->assertSame('anderssens-opening', chess_codex_slugify("Anderssen's Opening"));
    }

    #[TestDox('slugify ASCII-folds Latin diacritics')]
    public function testSlugifyASCIIFoldsLatinDiacritics(): void
    {
        $this->assertSame('kadas-opening-bucker-gambit', chess_codex_slugify('Kádas Opening: Bücker Gambit'));
        $this->assertSame('reti-opening', chess_codex_slugify('Réti Opening'));
    }

    #[TestDox('canonicalize_pgn strips move numbers and annotations')]
    public function testCanonicalizePgnStripsMoveNumbersAndAnnotations(): void
    {
        $this->assertSame(
            'Nh3 d5 g3 e5 f4',
            chess_codex_canonicalize_pgn('1. Nh3 d5 2. g3 e5 3. f4')
        );
        $this->assertSame(
            'e4 e5 Nf3',
            chess_codex_canonicalize_pgn('1. e4! e5?! 2. Nf3+')
        );
    }

    #[TestDox('count_plies')]
    public function testCountPlies(): void
    {
        $this->assertSame(0, chess_codex_count_plies(''));
        $this->assertSame(1, chess_codex_count_plies('1. Nh3'));
        $this->assertSame(5, chess_codex_count_plies('1. Nh3 d5 2. g3 e5 3. f4'));
    }

    #[TestDox('resolve_parents picks longest prefix within ECO group')]
    public function testResolveParentsPicksLongestPrefixWithinECO(): void
    {
        $rows = [
            ['eco_group' => 'A', 'canon' => 'Nh3'],
            ['eco_group' => 'A', 'canon' => 'Nh3 d5'],
            ['eco_group' => 'A', 'canon' => 'Nh3 d5 g3 e5 f4'],
            ['eco_group' => 'B', 'canon' => 'e4 c5'], // different group, ignored
        ];
        $parents = chess_codex_resolve_parents($rows);
        $this->assertSame(null, $parents[0], 'first row is root');
        $this->assertSame(0,    $parents[1], 'second row\'s parent is row 0');
        $this->assertSame(1,    $parents[2], 'third row\'s parent is row 1');
        $this->assertSame(null, $parents[3], 'different ECO group → no parent');
    }
}
