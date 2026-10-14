<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class RankingsTest extends TestCase
{
    #[TestDox('isGambit: gambits and countergambits yes, "… Gambit Declined" no')]
    public function testIsGambitGambitsAndCountergambitsYesGambitDeclined(): void
    {
        $this->assertSame(true,  Opening::isGambit('Italian Game: Evans Gambit'));
        $this->assertSame(true,  Opening::isGambit("Queen's Gambit Accepted"));
        $this->assertSame(true,  Opening::isGambit('Queen\'s Gambit Declined: Albin Countergambit'));
        $this->assertSame(false, Opening::isGambit("Queen's Gambit Declined: Orthodox Defense"));
        $this->assertSame(false, Opening::isGambit("King's Gambit Declined"));
        $this->assertSame(false, Opening::isGambit('Sicilian Defense'));
    }

    #[TestDox('compact game counts never read "1000K"')]
    public function testCompactGameCountsNeverRead1000K(): void
    {
        $this->assertSame('9,870', Rankings::compact(9870));
        $this->assertSame('845K',  Rankings::compact(845210));
        $this->assertSame('999K',  Rankings::compact(999499));
        $this->assertSame('1M',    Rankings::compact(999500));
        $this->assertSame('2.3M',  Rankings::compact(2345678));
        $this->assertSame('1.5B',  Rankings::compact(1527871757));
    }

    #[TestDox('lineTails tells same-name lines apart and skips unique names')]
    public function testLineTailsTellsSameNameLinesApartAnd(): void
    {
        $tails = Opening::lineTails([
            ['id' => 1, 'name' => 'X', 'eco' => 'A00', 'pgn_moves' => '1. e4 e5 2. Nf3 Nc6'],
            ['id' => 2, 'name' => 'X', 'eco' => 'A00', 'pgn_moves' => '1. e4 e5 2. Nf3 Nf6'],
            ['id' => 3, 'name' => 'Y', 'eco' => 'A00', 'pgn_moves' => '1. d4'],
        ]);
        $this->assertSame(['2. Nf3 Nc6', '2. Nf3 Nf6'], [$tails[1], $tails[2]]);
        $this->assertSame(false, isset($tails[3]));
    }

    #[TestDox('LevelStats::trend: which way the line goes as the players get stronger')]
    public function testLevelStatsTrendWhichWayTheLineGoes(): void
    {
        $l = static fn (int $w, int $d, int $b): array => ['white' => $w, 'draws' => $d, 'black' => $b, 'games' => $w + $d + $b];
        // Black's line: 55% under 1400, 49% at 2200 and up.
        $down = ['beginners' => $l(4300, 400, 5300), 'experts' => $l(4700, 800, 4500)];
        $this->assertSame('The stronger the players, the worse this line does for Black: Black scores 55% under 1400, 49% at 2200 and up (wins plus half the draws).',
            LevelStats::trend($down, 'black'));
        // White's line, with enough master games to mention them.
        $up = ['beginners' => $l(4500, 400, 5100), 'experts' => $l(5000, 600, 4400), 'masters' => $l(150, 200, 100)];
        $this->assertSame('The stronger the players, the better this line does for White: White scores 47% under 1400, 53% at 2200 and up and 56% in master games (wins plus half the draws).',
            LevelStats::trend($up, 'white'));
        $flat = ['beginners' => $l(4800, 400, 4800), 'experts' => $l(4700, 600, 4700)];
        $this->assertSame('Rating changes little here: White scores 50% both under 1400 and at 2200 and up (wins plus half the draws).',
            LevelStats::trend($flat, 'white'));
        // Too few games at one end, or a band missing: nothing.
        $this->assertSame(null, LevelStats::trend(['beginners' => $l(900, 100, 900), 'experts' => $l(4700, 600, 4700)], 'white'));
        $this->assertSame(null, LevelStats::trend(['masters' => $l(150, 200, 100)], 'white'));
    }

    #[TestDox('EngineEval::practice: only when the clearly worse side still scores')]
    public function testEngineEvalPracticeOnlyWhenTheClearlyWorse(): void
    {
        $stats = ['white' => 4600, 'draws' => 400, 'black' => 5000];   // Black 52%
        $this->assertSame('Even so, Black scores 52.0% here in practice: wins plus half the draws in rated Lichess games between players rated 1600 to 2500.',
            EngineEval::practice(['cp' => 201, 'mate' => null], $stats));
        $this->assertSame(null, EngineEval::practice(['cp' => 120, 'mate' => null], $stats));                                  // not clearly better
        $this->assertSame('Even so, White scores 48.0% here in practice: wins plus half the draws in rated Lichess games between players rated 1600 to 2500.',
            EngineEval::practice(['cp' => -201, 'mate' => null], $stats));                                             // the other way round
        $this->assertSame(null, EngineEval::practice(['cp' => 201, 'mate' => null], ['white' => 6000, 'draws' => 400, 'black' => 3600]));   // Black 38%
        $this->assertSame(null, EngineEval::practice(['cp' => null, 'mate' => 3], $stats));
        $this->assertSame(null, EngineEval::practice(['cp' => 201, 'mate' => null], ['white' => 400, 'draws' => 40, 'black' => 500]));  // too few games
    }
}
