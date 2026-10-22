<?php
declare(strict_types=1);

/**
 * Charts of shares by year (styles: public/css/19-trends.css). The SVG layer
 * has viewBox 0 0 100 100 stretched over the plot and draws only the lines
 * and the wash (strokes keep their width through vector-effect); dots, values
 * and axes are HTML placed in percent, so their text keeps its CSS size at any
 * width. The table under a chart holds every number, and public/charts.js
 * reads its rows for the crosshair tooltip.
 */
final class TrendChart
{
    /**
     * One line's share of games by year, with the numbers in a table under it.
     * $years: YearStats::forOpening(); a year of fewer than 12 months (the
     * last) is drawn as provisional. $label names the chart for screen readers.
     *
     * @param array<int, array{games: int, white: int, draws: int, black: int, months: int, share: float}> $years
     */
    public static function years(array $years, string $label): string
    {
        $esc  = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $list = array_keys($years);
        $n    = count($list);
        $shares = array_column($years, 'share');
        if (count($shares) < 2) return '';
        [$top, $ticks] = self::scale(max($shares));

        $x = $y = [];
        foreach ($list as $i => $year) {
            $x[$year] = round($i * 100 / ($n - 1), 2);
            $y[$year] = round($years[$year]['share'] / $top * 100, 2);
        }
        $full    = array_values(array_filter($list, static fn (int $yr): bool => $years[$yr]['months'] === 12));
        $partial = array_values(array_filter($list, static fn (int $yr): bool => $years[$yr]['months'] < 12));
        $svgPoints = static fn (array $yrs): string => implode(' ', array_map(
            static fn (int $yr): string => $x[$yr] . ',' . round(100 - $y[$yr], 2), $yrs));

        // Values written on the chart: the first and last full years, and the
        // peak when it stands out and has room between them.
        $labelled = [];
        if ($full) {
            $labelled = [$full[0], end($full)];
            $fullShares = array_combine($full, array_map(static fn (int $yr): float => $years[$yr]['share'], $full));
            $peakAt = (int) array_search(max($fullShares), $fullShares, true);
            $peakIx = array_search($peakAt, $list, true);
            $roomy  = static fn (int $other): bool => abs($peakIx - array_search($other, $list, true)) >= 2;
            if ($fullShares[$peakAt] >= 1.05 * max($fullShares[$full[0]], $fullShares[end($full)])
                && $roomy($full[0]) && $roomy(end($full))) {
                $labelled[] = $peakAt;
            }
            $labelled = array_values(array_unique($labelled));
        }
        $edge = static fn (int $yr): string => $yr === $list[0] ? ' is-start' : ($yr === $list[$n - 1] ? ' is-end' : '');
        $at   = static fn (int $yr): string => 'left:' . $x[$yr] . '%;bottom:' . $y[$yr] . '%';

        $h  = '<figure class="trend">';
        $h .= '<div class="trend-frame">';
        $h .= '<div class="trend-axis-y" aria-hidden="true">';
        foreach ($ticks as [$pos, $text]) $h .= '<span style="bottom:' . $pos . '%">' . $esc($text) . '</span>';
        $h .= '</div>';
        $h .= '<div class="trend-plot" tabindex="0" role="img" aria-label="' . $esc($label) . '">';
        $h .= '<div class="trend-grid" aria-hidden="true">';
        foreach ($ticks as [$pos]) $h .= '<i style="bottom:' . $pos . '%"></i>';
        $h .= '</div>';
        $h .= '<svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">';
        if (count($full) >= 2) {
            $h .= '<path class="trend-area" d="M' . $x[$full[0]] . ',100 L' . str_replace(' ', ' L', $svgPoints($full))
                . ' L' . $x[end($full)] . ',100 Z"/>';
            $h .= '<polyline class="trend-line" points="' . $svgPoints($full) . '"/>';
        }
        if ($full && $partial) {
            $h .= '<polyline class="trend-line is-partial" points="' . $svgPoints([end($full), $partial[0]]) . '"/>';
        }
        $h .= '</svg>';
        foreach ($labelled as $yr) $h .= '<span class="trend-dot" style="' . $at($yr) . '"></span>';
        foreach ($partial as $yr) $h .= '<span class="trend-dot is-partial" style="' . $at($yr) . '"></span>';
        foreach ($labelled as $yr) {
            $h .= '<span class="trend-value' . $edge($yr) . '" style="' . $at($yr) . '">'
                . $esc(YearStats::percent($years[$yr]['share'])) . '</span>';
        }
        $h .= '<span class="trend-cross" hidden></span><span class="trend-dot is-hover" hidden></span>';
        $h .= '</div>';
        $h .= '<div class="trend-tip" role="status" aria-live="polite" hidden></div>';
        $h .= '<div class="trend-axis-x" aria-hidden="true">';
        foreach ($list as $i => $yr) {
            $minor = ($n - 1 - $i) % 2 === 1 ? ' is-minor' : '';
            $h .= '<span class="' . trim($minor . $edge($yr)) . '" style="left:' . $x[$yr] . '%">' . $yr . '</span>';
        }
        $h .= '</div></div>';

        $h .= '<details class="trend-table"><summary>Numbers by year</summary><table>';
        $h .= '<thead><tr><th scope="col">Year</th><th scope="col">Share of games</th><th scope="col">Games</th></tr></thead><tbody>';
        foreach ($list as $yr) {
            $months = $years[$yr]['months'];
            $when   = $months < 12 ? $yr . ' (Jan' . ($months > 1 ? '–' . date('M', (int) mktime(0, 0, 0, $months, 1)) : '') . ')' : (string) $yr;
            $h .= '<tr data-x="' . $x[$yr] . '" data-y="' . $y[$yr] . '"><th scope="row">' . $esc($when) . '</th>'
                . '<td>' . $esc(YearStats::percent($years[$yr]['share'])) . '</td>'
                . '<td>' . $esc(number_format($years[$yr]['games'])) . '</td></tr>';
        }
        $h .= '</tbody></table></details>';
        return $h . '</figure>';
    }

    /**
     * A small line of shares by year for a table row, from zero up to its
     * highest year; the numbers themselves stay in the row.
     *
     * @param array<int, float> $shares year => share
     */
    public static function sparkline(array $shares): string
    {
        $values = array_values($shares);
        $n = count($values);
        $max = $values ? max($values) : 0;
        if ($n < 2 || $max <= 0) return '';
        $points = [];
        foreach ($values as $i => $v) {
            $points[] = round($i * 100 / ($n - 1), 2) . ',' . round(100 - $v / $max * 90, 2);
        }
        return '<svg class="spark" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
            . '<polyline points="' . implode(' ', $points) . '"/></svg>';
    }

    /**
     * The y scale for shares up to $max: the top (a share, with room above the
     * highest point for its value) and the gridlines as [percent of the plot
     * height, label], three to five of them on round numbers.
     *
     * @return array{0: float, 1: list<array{0: float, 1: string}>}
     */
    private static function scale(float $max): array
    {
        $maxPct = max($max * 100, 1e-9);
        $raw    = $maxPct / 3;
        $mag    = 10 ** floor(log10($raw));
        $step   = 10 * $mag;
        foreach ([1, 2, 2.5, 5] as $m) {
            if ($m * $mag >= $raw * (1 - 1e-9)) { $step = $m * $mag; break; }
        }
        $steps = (int) ceil($maxPct / $step - 1e-9);
        if ($steps * $step < $maxPct * 1.08) $steps++;
        $decimals = 0;
        while ($decimals < 8 && abs(round($step, $decimals) - $step) > $step * 1e-6) $decimals++;
        $ticks = [];
        for ($k = 0; $k <= $steps; $k++) {
            $text = number_format($k * $step, $decimals);
            if ($decimals > 0) $text = rtrim(rtrim($text, '0'), '.');
            $ticks[] = [round($k * 100 / $steps, 2), $text . '%'];
        }
        return [$steps * $step / 100, $ticks];
    }
}
