<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Edge cases of lib/parser.php that ParserTest does not reach. */
final class ParserEdgeTest extends TestCase
{
    #[TestDox('slugify: curly and backtick apostrophes, en dash, runs of punctuation, empty')]
    public function testSlugifyEdges(): void
    {
        $this->assertSame('queens-gambit', chess_codex_slugify('Queen’s Gambit'));
        $this->assertSame('queens-gambit', chess_codex_slugify('Queen‘s Gambit'));
        $this->assertSame('queens-gambit', chess_codex_slugify('Queen`s Gambit'));
        $this->assertSame('a-b', chess_codex_slugify('A–B'));
        $this->assertSame('caro-kann-defense', chess_codex_slugify('Caro-Kann  Defense'));
        $this->assertSame('a-b', chess_codex_slugify(' :: A, B :: '));
        $this->assertSame('', chess_codex_slugify(''));
        $this->assertSame('', chess_codex_slugify('???'));
        $this->assertSame('nimzo-larsen-attack-2', chess_codex_slugify('Nimzo-Larsen Attack 2'));
    }

    #[TestDox('slugify: a capital letter with a diacritic is folded like its lowercase form')]
    public function testSlugifyCapitalDiacritics(): void
    {
        $this->assertSame(['o-hanlon', 'orsted-opening'], [chess_codex_slugify('Ó Hanlon'), chess_codex_slugify('Ørsted Opening')]);
    }

    #[TestDox('canonicalize_pgn: black-move numbers "5...", glued numbers, check and mate marks, extra spaces')]
    public function testCanonicalizeEdges(): void
    {
        $this->assertSame('e4 e5 Nf3 Nc6', chess_codex_canonicalize_pgn('1.e4 e5 2.Nf3 Nc6'));
        $this->assertSame('Nf6 d4', chess_codex_canonicalize_pgn('4... Nf6 5. d4'));
        $this->assertSame('Qxf7', chess_codex_canonicalize_pgn('9. Qxf7#'));
        $this->assertSame('e4 e5', chess_codex_canonicalize_pgn("  1. e4!!   e5?? \n"));
        $this->assertSame('O-O', chess_codex_canonicalize_pgn('O-O+'));
        $this->assertSame('', chess_codex_canonicalize_pgn(''));
        $this->assertSame('', chess_codex_canonicalize_pgn('1.'));
    }

    #[TestDox('count_plies: whitespace-only is zero, annotations do not add plies')]
    public function testCountPliesEdges(): void
    {
        $this->assertSame(0, chess_codex_count_plies('   '));
        $this->assertSame(0, chess_codex_count_plies('1.'));
        $this->assertSame(2, chess_codex_count_plies('1. e4! e5?!'));
        $this->assertSame(3, chess_codex_count_plies('1.e4 e5 2.Nf3'));
    }

    #[TestDox('resolve_parents: empty input, equal PGNs, word boundary, keys kept as given')]
    public function testResolveParentsEdges(): void
    {
        $this->assertSame([], chess_codex_resolve_parents([]));

        $rows = [
            'a' => ['eco_group' => 'C', 'canon' => 'e4 e5'],
            'b' => ['eco_group' => 'C', 'canon' => 'e4 e5'],         // same moves: not a STRICT prefix
            'c' => ['eco_group' => 'C', 'canon' => 'e4 e5 Nf3'],
            'd' => ['eco_group' => 'C', 'canon' => 'e4 e50'],        // "e4 e5" is a string prefix but not at a move boundary
        ];
        $p = chess_codex_resolve_parents($rows);
        $this->assertSame(null, $p['a']);
        $this->assertSame(null, $p['b']);
        $this->assertContains($p['c'], ['a', 'b'], 'either of two equal rows is a valid parent');
        $this->assertSame(null, $p['d']);
    }

    #[TestDox('resolve_parents: an empty canonical PGN is never a parent')]
    public function testResolveParentsEmptyIsNeverParent(): void
    {
        $p = chess_codex_resolve_parents([
            ['eco_group' => 'A', 'canon' => ''],
            ['eco_group' => 'A', 'canon' => 'Nh3'],
        ]);
        $this->assertSame([null, null], [$p[0], $p[1]]);
    }
}
