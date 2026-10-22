<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Rankings' pure helpers (compact(), gambitKey() and lineTails() are covered in RankingsTest and SeoTest). */
final class RankingsHelpersTest extends TestCase
{
    #[TestDox('score: wins plus half the draws as 0–1, zero games is 0, not a division by zero')]
    public function testScore(): void
    {
        $r = ['white' => 40, 'draws' => 20, 'black' => 40, 'games' => 100];
        $this->assertEqualsWithDelta(0.5, Rankings::score($r, 'white'), 1e-12);
        $this->assertEqualsWithDelta(0.5, Rankings::score($r, 'black'), 1e-12);
        $this->assertEqualsWithDelta(0.7, Rankings::score(['white' => 70, 'draws' => 0, 'black' => 30, 'games' => 100], 'white'), 1e-12);
        $this->assertEqualsWithDelta(0.0, Rankings::score(['white' => 0, 'draws' => 0, 'black' => 0, 'games' => 0], 'white'), 1e-12);
        $this->assertEqualsWithDelta(1.0, Rankings::score(['white' => 0, 'draws' => 10, 'black' => 0, 'games' => 5], 'white'), 1e-12, 'a stale games total is trusted');
    }

    #[TestDox('compact: unit boundaries and zero')]
    public function testCompactBoundaries(): void
    {
        $this->assertSame('0', Rankings::compact(0));
        $this->assertSame('9,999', Rankings::compact(9999));
        $this->assertSame('10K', Rankings::compact(10000));
        $this->assertSame('1M', Rankings::compact(1_000_000));
        $this->assertSame('1B', Rankings::compact(999_950_000));
        $this->assertSame('999.9M', Rankings::compact(999_949_999));
        $this->assertSame('12.3M', Rankings::compact(12_345_678));
    }

    #[TestDox('gambitKey: an empty name, a countergambit after a gambit, "Accepted" only at the end')]
    public function testGambitKeyEdges(): void
    {
        $this->assertSame('', Rankings::gambitKey(''));
        $this->assertSame('Queen\'s Gambit', Rankings::gambitKey("Queen's Gambit Accepted"));
        $this->assertSame('Accepted Gambit Line', Rankings::gambitKey('Accepted Gambit Line'));
        $this->assertSame('Albin Countergambit', Rankings::gambitKey("Queen's Gambit Declined: Albin Countergambit"));
        $this->assertSame('Sicilian Defense', Rankings::gambitKey('Sicilian Defense'), 'no gambit: the name unchanged');
        $this->assertSame('Smith-Morra Gambit', Rankings::gambitKey('Sicilian Defense: Smith-Morra Gambit Accepted'));
        $this->assertSame('Smith-morra gambit', Rankings::gambitKey('Sicilian Defense: Smith-morra gambit accepted'), 'case-insensitive');
    }
}
