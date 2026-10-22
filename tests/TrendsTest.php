<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class TrendsTest extends TestCase
{
    /**
     * YearStats::forOpening()-shaped years from shares, 2016 on; the last one partial when $lastMonths < 12.
     *
     * @param array<int, float> $shares
     * @return array<int, array{games: int, share: float, white: int, draws: int, black: int, months: int}>
     */
    private static function years(array $shares, int $lastMonths = 12): array
    {
        $years = [];
        foreach (array_values($shares) as $i => $s) {
            $years[2016 + $i] = ['games' => (int) round($s * 1e8), 'share' => $s, 'white' => 0, 'draws' => 0, 'black' => 0,
                                 'months' => $i === count($shares) - 1 ? $lastMonths : 12];
        }
        return $years;
    }

    #[TestDox('percent keeps two significant digits below 1%')]
    public function testPercentKeepsTwoSignificantDigitsBelow1(): void
    {
        $this->assertSame('13.3%',   YearStats::percent(0.1333));
        $this->assertSame('1.9%',    YearStats::percent(0.019));
        $this->assertSame('0.48%',   YearStats::percent(0.00476));
        $this->assertSame('0.05%',   YearStats::percent(0.0005));
        $this->assertSame('0.0071%', YearStats::percent(0.0000712));
        $this->assertSame('1%',      YearStats::percent(0.009996));
        $this->assertSame('0%',      YearStats::percent(0.0));
    }

    #[TestDox('change: ×N from double on, else a signed percent, never −0%')]
    public function testChangeNFromDoubleOnElseA(): void
    {
        $this->assertSame('×2.7', YearStats::change(2.72));
        $this->assertSame('+65%', YearStats::change(1.65));
        $this->assertSame('−24%', YearStats::change(0.76));
        $this->assertSame('0%',   YearStats::change(0.998));
        $this->assertSame('0%',   YearStats::change(1.004));
    }

    #[TestDox('sentence: gained, doubled, lost, peaked, steady — from the full years only')]
    public function testSentenceGainedDoubledLostPeakedSteadyFrom(): void
    {
        $ck = self::years([0.0346, 0.0395, 0.0427, 0.042, 0.0411, 0.0451, 0.0504, 0.0537, 0.056, 0.0572, 0.0584], 4);
        $this->assertSame('The Caro-Kann Defense has gained ground since 2016: 5.7% of Lichess games in 2025, up from 3.5%.',
            YearStats::sentence($ck, 'The Caro-Kann Defense'));
        $englund = self::years([0.005, 0.0052, 0.0059, 0.007, 0.0086, 0.0115, 0.0122, 0.013, 0.0133, 0.0136]);
        $this->assertSame('This line is played more than twice as often as in 2016: 1.4% of Lichess games in 2025, up from 0.5%.',
            YearStats::sentence($englund, 'This line'));
        $stafford = self::years([0.000078, 0.0001, 0.0002, 0.0006, 0.0021, 0.003, 0.0024, 0.0019, 0.0016, 0.0015]);
        $this->assertSame("Petrov's Defense: Stafford Gambit is played more than 19 times as often as in 2016: 0.15% of Lichess games in 2025, up from 0.0078% (its peak: 0.3% in 2021).",
            YearStats::sentence($stafford, "Petrov's Defense: Stafford Gambit"));
        $kg = self::years([0.0232, 0.0219, 0.02, 0.0193, 0.0183, 0.0186, 0.0192, 0.0187, 0.0182, 0.0176]);
        $this->assertSame("The King's Gambit has lost ground since 2016: 1.8% of Lichess games in 2025, down from 2.3%.",
            YearStats::sentence($kg, "The King's Gambit"));
        $sic = self::years([0.144, 0.1485, 0.1521, 0.156, 0.1542, 0.1473, 0.1396, 0.137, 0.1352, 0.1332]);
        $this->assertSame('The Sicilian Defense peaked in 2019 at 15.6% of Lichess games and has slipped since: 13.3% in 2025.',
            YearStats::sentence($sic, 'The Sicilian Defense'));
        $dip = self::years([0.00006, 0.00005, 0.00005, 0.00006, 0.00006, 0.00006, 0.00006, 0.00007, 0.00007, 0.00007]);
        $this->assertSame('This line dipped to 0.005% of Lichess games in 2017 and has recovered since: 0.007% in 2025.',
            YearStats::sentence($dip, 'This line'));
        $between = self::years([0.010, 0.011, 0.0118, 0.0112, 0.0108, 0.0105, 0.0109, 0.0111, 0.0106, 0.0104]);
        $this->assertSame('This line has stayed between 1.0% and 1.2% of Lichess games a year since 2016 (1.0% in 2025).',
            YearStats::sentence($between, 'This line'));
        $steady = self::years([0.0258, 0.0264, 0.0273, 0.0268, 0.0268, 0.0262, 0.0267, 0.0268, 0.0268, 0.027]);
        $this->assertSame('This line holds steady at about 2.7% of Lichess games a year since 2016.',
            YearStats::sentence($steady, 'This line'));
        $this->assertSame(null, YearStats::sentence(array_slice($ck, 1, null, true), 'X'), 'no 2016');
        $this->assertSame(null, YearStats::sentence(self::years([0.01, 0.01, 0.01]), 'X'), 'too few years');
    }

    #[TestDox('year chart: a row per year, the partial year dashed and named by its months')]
    public function testYearChartARowPerYearThe(): void
    {
        $html = TrendChart::years(self::years([0.0346, 0.0395, 0.0427, 0.042, 0.0411, 0.0451, 0.0504, 0.0537, 0.056, 0.0572, 0.0584], 4), 'Chart');
        $this->assertSame(11, substr_count($html, '<tr data-x='));
        $this->assertTrue(str_contains($html, '2026 (Jan–Apr)'), 'partial year label');
        $this->assertTrue(str_contains($html, 'trend-line is-partial'), 'dashed partial segment');
        $this->assertTrue(str_contains($html, '>3.5%</span>') && str_contains($html, '>5.7%</span>'), 'first and last full years labelled');
        $this->assertTrue(str_contains($html, '>8%</span>'), 'a round top gridline above 5.84%');
        $this->assertSame('', TrendChart::years(self::years([0.01]), 'Chart'), 'one year is no chart');
    }

    #[TestDox('sparkline: one point per year, from zero up')]
    public function testSparklineOnePointPerYearFromZero(): void
    {
        $svg = TrendChart::sparkline([2016 => 0.01, 2017 => 0.02]);
        $this->assertTrue(str_contains($svg, 'points="0,55 100,10"'), $svg);
        $this->assertSame('', TrendChart::sparkline([2016 => 0.01]));
    }
}
