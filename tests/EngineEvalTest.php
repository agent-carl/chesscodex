<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class EngineEvalTest extends TestCase
{
    #[TestDox('scoreText: sign, two decimals, mate as #N')]
    public function testScoreText(): void
    {
        $this->assertSame('+0.35', EngineEval::scoreText(['cp' => 35, 'mate' => null]));
        $this->assertSame('−1.20', EngineEval::scoreText(['cp' => -120, 'mate' => null]));
        $this->assertSame('0.00',  EngineEval::scoreText(['cp' => 0, 'mate' => null]));
        $this->assertSame('+0.01', EngineEval::scoreText(['cp' => 1, 'mate' => null]));
        $this->assertSame('#3',    EngineEval::scoreText(['cp' => null, 'mate' => 3]));
        $this->assertSame('−#2',   EngineEval::scoreText(['cp' => null, 'mate' => -2]));
        $this->assertSame('#3',    EngineEval::scoreText(['cp' => 500, 'mate' => 3]), 'mate wins over cp');
    }

    #[TestDox('verdict: equal, slightly better, better, clearly better, mate')]
    public function testVerdict(): void
    {
        $v = static fn (int $cp): string => EngineEval::verdict(['cp' => $cp, 'mate' => null]);
        $this->assertSame('The position is about equal', $v(0));
        $this->assertSame('The position is about equal', $v(29));
        $this->assertSame('The position is about equal', $v(-29));
        $this->assertSame('White is slightly better', $v(30));
        $this->assertSame('Black is slightly better', $v(-69));
        $this->assertSame('White is better', $v(70));
        $this->assertSame('Black is better', $v(-149));
        $this->assertSame('White is clearly better', $v(150));
        $this->assertSame('Black is clearly better', $v(-400));
        $this->assertSame('White mates in 4', EngineEval::verdict(['cp' => null, 'mate' => 4]));
        $this->assertSame('Black mates in 2', EngineEval::verdict(['cp' => null, 'mate' => -2]));
    }

    #[TestDox('lineText: numbers from the side to move, White or Black first')]
    public function testLineText(): void
    {
        $white = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 3';
        $black = 'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq - 0 3';
        $this->assertSame('3. Nf3 Nf6 4. d4', EngineEval::lineText($white, ['Nf3', 'Nf6', 'd4']));
        $this->assertSame('3... Nf6 4. d4 d5', EngineEval::lineText($black, ['Nf6', 'd4', 'd5']));
        $this->assertSame('3... Nf6', EngineEval::lineText($black, ['Nf6']));
        $this->assertSame('', EngineEval::lineText($white, []));
        $this->assertSame('1. e4', EngineEval::lineText('8/8/8/8/8/8/8/8', ['e4']), 'a short FEN falls back to White, move 1');
    }

    #[TestDox('practice: exactly at the thresholds')]
    public function testPracticeThresholds(): void
    {
        $stats = ['white' => 4000, 'draws' => 1000, 'black' => 5000];   // Black scores 55%
        $this->assertNotNull(EngineEval::practice(['cp' => 150, 'mate' => null], $stats), '1.5 pawns counts');
        $this->assertNull(EngineEval::practice(['cp' => 149, 'mate' => null], $stats));
        $this->assertNull(EngineEval::practice(['cp' => 300, 'mate' => null], ['white' => 0, 'draws' => 0, 'black' => 0]), 'zero games');
        $this->assertNull(EngineEval::practice(['cp' => 300, 'mate' => null], ['white' => 500, 'draws' => 0, 'black' => 499]), '999 games');
        $this->assertStringContainsString('White scores 55.0%',
            (string) EngineEval::practice(['cp' => -300, 'mate' => null], ['white' => 5000, 'draws' => 1000, 'black' => 4000]));
        // Black at exactly 45% with the engine for White: still shown.
        $this->assertNotNull(EngineEval::practice(['cp' => 300, 'mate' => null], ['white' => 5500, 'draws' => 0, 'black' => 4500]));
        $this->assertNull(EngineEval::practice(['cp' => 300, 'mate' => null], ['white' => 5501, 'draws' => 0, 'black' => 4499]));
    }
}
