<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class TrendChartTest extends TestCase
{
    /**
     * @param array<int, float> $shares year => share
     * @param array<int, int> $months   year => months, 12 where missing
     * @return array<int, array{games: int, white: int, draws: int, black: int, months: int, share: float}>
     */
    private static function years(array $shares, array $months = []): array
    {
        $out = [];
        foreach ($shares as $year => $s) {
            $out[$year] = ['games' => (int) round($s * 1e6), 'white' => 0, 'draws' => 0, 'black' => 0, 'months' => $months[$year] ?? 12, 'share' => $s];
        }
        return $out;
    }

    /**
     * The y-axis labels of a chart, top to bottom of the markup.
     *
     * @return list<string>
     */
    private static function ticks(string $html): array
    {
        preg_match('/<div class="trend-axis-y"[^>]*>(.*?)<\/div>/s', $html, $m);
        preg_match_all('/>([^<>]+)<\/span>/', $m[1] ?? '', $t);
        return $t[1];
    }

    #[TestDox('scale: round gridlines above the highest share, three to five of them')]
    public function testScaleTicks(): void
    {
        $this->assertSame(['0%', '2%', '4%', '6%'], self::ticks(TrendChart::years(self::years([2020 => 0.01, 2021 => 0.045]), 'c')), '4.5% needs headroom');
        $this->assertSame(['0%', '0.5%', '1%', '1.5%'], self::ticks(TrendChart::years(self::years([2020 => 0.001, 2021 => 0.012]), 'c')));
        $this->assertSame(['0%', '50%', '100%', '150%'], self::ticks(TrendChart::years(self::years([2020 => 0.2, 2021 => 1.0]), 'c')), 'a 100% share');
        $this->assertSame(['0%', '0.0005%', '0.001%', '0.0015%'], self::ticks(TrendChart::years(self::years([2020 => 0.0000050, 2021 => 0.0000120]), 'c')), 'tiny shares keep their decimals');
    }

    #[TestDox('years: all-zero shares do not divide by zero')]
    public function testAllZero(): void
    {
        $html = TrendChart::years(self::years([2020 => 0.0, 2021 => 0.0, 2022 => 0.0]), 'c');
        $this->assertStringContainsString('<figure class="trend">', $html);
        $this->assertStringNotContainsString('NAN', $html);
        $this->assertStringNotContainsString('INF', $html);
    }

    #[TestDox('years: no full year draws no line; one full year plus a partial draws only the dot')]
    public function testPartialYears(): void
    {
        $onlyPartial = TrendChart::years(self::years([2025 => 0.01, 2026 => 0.02], [2025 => 6, 2026 => 3]), 'c');
        $this->assertStringNotContainsString('<polyline', $onlyPartial);
        $this->assertStringNotContainsString('class="trend-value', $onlyPartial);
        $this->assertSame(2, substr_count($onlyPartial, 'trend-dot is-partial'));
        $this->assertStringContainsString('2026 (Jan–Mar)', $onlyPartial);
        $this->assertStringContainsString('2025 (Jan–Jun)', $onlyPartial);

        $oneFull = TrendChart::years(self::years([2025 => 0.01, 2026 => 0.02], [2026 => 1]), 'c');
        $this->assertStringNotContainsString('class="trend-line"', $oneFull, 'one full year is no line');
        $this->assertStringContainsString('trend-line is-partial', $oneFull, 'but it joins the partial year');
        $this->assertStringContainsString('2026 (Jan)', $oneFull, 'a single month is "Jan", not "Jan–Jan"');
    }

    #[TestDox('years: the peak is labelled only when it stands out and has two years of room each side')]
    public function testPeakLabel(): void
    {
        $values = static fn (string $html): int => substr_count($html, 'class="trend-value');
        $peak = TrendChart::years(self::years([2018 => 0.01, 2019 => 0.02, 2020 => 0.05, 2021 => 0.02, 2022 => 0.01]), 'c');
        $this->assertSame(3, $values($peak));
        $noRoom = TrendChart::years(self::years([2018 => 0.01, 2019 => 0.05, 2020 => 0.02, 2021 => 0.015, 2022 => 0.01]), 'c');
        $this->assertSame(2, $values($noRoom), 'peak one year from the start');
        $flat = TrendChart::years(self::years([2018 => 0.02, 2019 => 0.0203, 2020 => 0.0205, 2021 => 0.0203, 2022 => 0.02]), 'c');
        $this->assertSame(2, $values($flat), 'a peak under 5% above the ends does not stand out');
    }

    #[TestDox('years: the label is escaped; ends are marked is-start and is-end')]
    public function testEscapeAndEdges(): void
    {
        $html = TrendChart::years(self::years([2020 => 0.01, 2021 => 0.02, 2022 => 0.03]), 'Sicilian "Najdorf" <b>');
        $this->assertStringContainsString('aria-label="Sicilian &quot;Najdorf&quot; &lt;b&gt;"', $html);
        $this->assertStringContainsString('trend-value is-start', $html);
        $this->assertStringContainsString('trend-value is-end', $html);
        $this->assertSame(3, substr_count($html, '<tr data-x='));
    }

    #[TestDox('sparkline: an all-zero or empty series is no chart')]
    public function testSparklineEmpty(): void
    {
        $this->assertSame('', TrendChart::sparkline([]));
        $this->assertSame('', TrendChart::sparkline([2020 => 0.0, 2021 => 0.0]));
        $this->assertStringContainsString('points="0,100 100,10"', TrendChart::sparkline([2020 => 0.0, 2021 => 0.5]));
    }
}
