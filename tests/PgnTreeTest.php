<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class PgnTreeTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function line(string $name, string $eco, string $moves, int $pop): array
    {
        return ['name' => $name, 'eco' => $eco, 'pgn_moves' => $moves, 'popularity' => $pop];
    }

    /** The movetext after the tag section, on one line. */
    private static function movetext(string $pgn): string
    {
        return trim(str_replace("\n", ' ', explode("\n\n", $pgn, 2)[1]));
    }

    #[TestDox('build: seven required tags, extra headers after them, a result of *')]
    public function testHeaders(): void
    {
        $pgn = PgnTree::build([], 'Event "X"', 'https://example.org', ['ECO' => 'B90']);
        $this->assertSame(
            "[Event \"Event \\\"X\\\"\"]\n[Site \"https://example.org\"]\n[Date \"????.??.??\"]\n[Round \"-\"]\n"
            . "[White \"?\"]\n[Black \"?\"]\n[Result \"*\"]\n[ECO \"B90\"]\n\n*\n",
            $pgn);
    }

    #[TestDox('build: the most popular branch is the main line, the rest are variations')]
    public function testMainLineAndVariations(): void
    {
        $pgn = PgnTree::build([
            self::line('Open Game', 'C20', '1. e4 e5', 10),
            self::line('Sicilian Defense', 'B20', '1. e4 c5', 90),
            self::line("Queen's Pawn Game", 'D00', '1. d4 d5', 5),
        ], 'E', 'S');
        $this->assertSame(
            "1. e4 (1. d4 d5 { Queen's Pawn Game (D00) }) 1... c5 { Sicilian Defense (B20) } (1... e5 { Open Game (C20) }) *",
            self::movetext($pgn));
    }

    #[TestDox('build: a name at the end of a line is a comment, Black\'s next move is numbered again')]
    public function testCommentRenumbersBlack(): void
    {
        $pgn = PgnTree::build([
            self::line("King's Pawn Game", 'B00', '1. e4', 100),
            self::line('Open Game', 'C20', '1. e4 e5 2. Nf3 Nc6', 50),
        ], 'E', 'S');
        $this->assertSame("1. e4 { King's Pawn Game (B00) } 1... e5 2. Nf3 Nc6 { Open Game (C20) } *", self::movetext($pgn));
    }

    #[TestDox('build: braces in a name cannot close the comment; two names join with a semicolon')]
    public function testBracesInNames(): void
    {
        $pgn = PgnTree::build([
            self::line('A {odd}', 'A00', '1. a3', 1),
            self::line('B', 'A01', '1. a3', 1),
        ], 'E', 'S');
        $this->assertSame('1. a3 { A (odd) (A00); B (A01) } *', self::movetext($pgn));
    }

    #[TestDox('build: move numbers glued to the move ("1.e4") and empty movetext are tolerated')]
    public function testMoveNumberForms(): void
    {
        $pgn = PgnTree::build([
            self::line('X', 'A00', '1.e4 e5 2.Nf3', 1),
            self::line('Empty', 'A00', '', 1),
        ], 'E', 'S');
        $this->assertSame('1. e4 e5 2. Nf3 { X (A00) } *', self::movetext($pgn));
    }

    #[TestDox('build: long movetext wraps at 80 columns')]
    public function testWrap(): void
    {
        $moves = '1. Nf3 Nf6 2. Ng1 Ng8 3. Nf3 Nf6 4. Ng1 Ng8 5. Nf3 Nf6 6. Ng1 Ng8 7. Nf3 Nf6 8. Ng1 Ng8';
        $body = explode("\n\n", PgnTree::build([self::line('Loop', 'A04', $moves, 1)], 'E', 'S'), 2)[1];
        foreach (explode("\n", rtrim($body)) as $row) $this->assertLessThanOrEqual(80, strlen($row));
        $this->assertGreaterThan(1, substr_count($body, "\n"));
    }
}
