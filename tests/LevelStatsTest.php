<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class LevelStatsTest extends TestCase
{
    /** @return array{white: int, draws: int, black: int, games: int} */
    private static function l(int $w, int $d, int $b): array
    {
        return ['white' => $w, 'draws' => $d, 'black' => $b, 'games' => $w + $d + $b];
    }

    #[TestDox('trend: exactly 2000 games per band is enough, 1999 is not')]
    public function testTrendMinGames(): void
    {
        $this->assertNotNull(LevelStats::trend(['beginners' => self::l(1000, 0, 1000), 'experts' => self::l(1000, 0, 1000)], 'white'));
        $this->assertNull(LevelStats::trend(['beginners' => self::l(1000, 0, 999), 'experts' => self::l(1000, 0, 1000)], 'white'));
        $this->assertNull(LevelStats::trend(['beginners' => self::l(1000, 0, 1000), 'experts' => self::l(1000, 0, 999)], 'white'));
        $this->assertNull(LevelStats::trend([], 'white'));
    }

    #[TestDox('trend: a change of 2 points is "little", 3 points is a trend')]
    public function testTrendThreePointBoundary(): void
    {
        $low = self::l(5000, 0, 5000);   // 50%
        $this->assertStringStartsWith('Rating changes little here',
            (string) LevelStats::trend(['beginners' => $low, 'experts' => self::l(5200, 0, 4800)], 'white'));
        $this->assertStringStartsWith('The stronger the players, the better this line does for White',
            (string) LevelStats::trend(['beginners' => $low, 'experts' => self::l(5300, 0, 4700)], 'white'));
        $this->assertStringStartsWith('The stronger the players, the worse this line does for White',
            (string) LevelStats::trend(['beginners' => $low, 'experts' => self::l(4700, 0, 5300)], 'white'));
    }

    #[TestDox('trend: masters are mentioned from 300 games; with equal ends the sentence joins with ", and"')]
    public function testTrendMasters(): void
    {
        $flat = ['beginners' => self::l(5000, 0, 5000), 'experts' => self::l(5000, 0, 5000)];
        $this->assertStringNotContainsString('master', (string) LevelStats::trend($flat + ['masters' => self::l(150, 0, 149)], 'white'));
        $this->assertSame('Rating changes little here: Black scores 50% both under 1400 and at 2200 and up, and 60% in master games (wins plus half the draws).',
            LevelStats::trend($flat + ['masters' => self::l(120, 0, 180)], 'black'));
    }

    #[TestDox('trend: the side decides which column is scored, draws count half')]
    public function testTrendSideAndDraws(): void
    {
        $levels = ['beginners' => self::l(3000, 4000, 3000), 'experts' => self::l(2000, 4000, 4000)];
        $this->assertSame('The stronger the players, the better this line does for Black: Black scores 50% under 1400, 60% at 2200 and up (wins plus half the draws).',
            LevelStats::trend($levels, 'black'));
        $this->assertStringContainsString('White scores 50% under 1400, 40% at 2200 and up', (string) LevelStats::trend($levels, 'white'));
    }

    #[TestDox('LEVELS: every Lichess band has ratings, the masters database has none')]
    public function testLevelsShape(): void
    {
        $this->assertSame(['beginners', 'intermediate', 'advanced', 'experts', 'masters'], array_keys(LevelStats::LEVELS));
        $this->assertSame(
            ['beginners' => '0,1000,1200', 'intermediate' => '1400,1600', 'advanced' => '1800,2000', 'experts' => '2200,2500', 'masters' => null],
            array_map(static fn (array $level): ?string => $level['ratings'], LevelStats::LEVELS));
    }
}
