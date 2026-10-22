<?php
declare(strict_types=1);

/**
 * How often a line is played year by year: the share of Lichess games (rated
 * blitz, rapid and classical, 1600+, the games behind a page's main numbers)
 * that reach its position, from the explorer's month-by-month history.
 * Fetched slowly by tools/fetch-years.php for the most-played lines and kept
 * in codex_year_stats, with every game (the starting position) as opening 0.
 * Pages only read the table; they never call Lichess.
 */
final class YearStats
{
    /** First year shown: from 2016 on Lichess has 35M+ such games a year (2015: 10M). */
    public const FIRST_YEAR = 2016;

    /** opening_id of the row set that counts every game. */
    private const ALL_GAMES = 0;

    public static function ensureTable(): void
    {
        chess_codex_db()->exec(
            'CREATE TABLE IF NOT EXISTS codex_year_stats (
                opening_id INTEGER     NOT NULL,
                year       INTEGER     NOT NULL,
                white_wins INTEGER     NOT NULL DEFAULT 0,
                draws      INTEGER     NOT NULL DEFAULT 0,
                black_wins INTEGER     NOT NULL DEFAULT 0,
                months     INTEGER     NOT NULL,
                fetched_at VARCHAR(19) NOT NULL,
                PRIMARY KEY (opening_id, year)
            )'
        );
    }

    /**
     * Asks Lichess for one line's history (every game when $openingId is 0)
     * and stores it by year, counting no month after $lastMonth ('Y-m', '' =
     * all). Returns the last month in the answer: the explorer runs months
     * behind, and a line fetched later must not count months the totals lack.
     *
     * @param list<string> $uciMoves
     */
    public static function fetchAndStore(int $openingId, array $uciMoves, string $lastMonth = ''): string
    {
        $history = LichessExplorer::fetchHistory($uciMoves)['history'] ?? [];
        $years = [];
        $last  = '';
        foreach ($history as $m) {
            $month = (string) ($m['month'] ?? '');
            if (!preg_match('/^(\d{4})-\d{2}$/', $month, $ym) || ($lastMonth !== '' && $month > $lastMonth)) continue;
            $y = (int) $ym[1];
            $years[$y] ??= ['w' => 0, 'd' => 0, 'b' => 0, 'm' => 0];
            $years[$y]['w'] += (int) ($m['white'] ?? 0);
            $years[$y]['d'] += (int) ($m['draws'] ?? 0);
            $years[$y]['b'] += (int) ($m['black'] ?? 0);
            $years[$y]['m']++;
            $last = max($last, $month);
        }
        if (!$years) throw new RuntimeException('no history in the answer');

        $db = chess_codex_db();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM codex_year_stats WHERE opening_id = :id')->execute(['id' => $openingId]);
            $insert = $db->prepare(
                'INSERT INTO codex_year_stats (opening_id, year, white_wins, draws, black_wins, months, fetched_at)
                 VALUES (:id, :y, :w, :d, :b, :m, :t)'
            );
            $now = date('Y-m-d H:i:s');
            foreach ($years as $y => $r) {
                $insert->execute(['id' => $openingId, 'y' => $y, 'w' => $r['w'], 'd' => $r['d'], 'b' => $r['b'], 'm' => $r['m'], 't' => $now]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $last;
    }

    /** When the line's history was fetched ('Y-m-d H:i:s'), or null. */
    public static function fetchedAt(int $openingId): ?string
    {
        $stmt = chess_codex_db()->prepare('SELECT MAX(fetched_at) FROM codex_year_stats WHERE opening_id = :id');
        $stmt->execute(['id' => $openingId]);
        $at = $stmt->fetchColumn();
        return $at === false || $at === null ? null : (string) $at;
    }

    /**
     * year => ['games', 'share' (0–1), 'white', 'draws', 'black', 'months'] from
     * FIRST_YEAR on, for one line; [] without numbers. A year counts only when
     * the line and every game cover the same months of it.
     *
     * @return array<int, array{games: int, white: int, draws: int, black: int, months: int, share: float}>
     */
    public static function forOpening(int $openingId): array
    {
        $all = self::rows(self::ALL_GAMES);
        $out = [];
        foreach (self::rows($openingId) as $year => $r) {
            $total = $all[$year] ?? null;
            if ($year < self::FIRST_YEAR || $total === null || $total['games'] === 0 || $total['months'] !== $r['months']) continue;
            $out[$year] = $r + ['share' => $r['games'] / $total['games']];
        }
        return $out;
    }

    /**
     * opening_id => [year => share] of the full years from FIRST_YEAR on, for
     * every line with numbers by year (one query; the rankings use it).
     *
     * @return array<int, array<int, float>>
     */
    public static function allShares(): array
    {
        $all = self::rows(self::ALL_GAMES);
        try {
            $rows = chess_codex_db()->query(
                'SELECT opening_id, year, white_wins + draws + black_wins AS games, months FROM codex_year_stats
                 WHERE opening_id <> ' . self::ALL_GAMES . ' AND months = 12 AND year >= ' . self::FIRST_YEAR . '
                 ORDER BY opening_id, year'
            )->fetchAll();
        } catch (PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $total = $all[(int) $r['year']] ?? null;
            if ($total === null || $total['months'] !== 12 || $total['games'] === 0) continue;
            $out[(int) $r['opening_id']][(int) $r['year']] = (int) $r['games'] / $total['games'];
        }
        return $out;
    }

    /**
     * opening_id => the day its numbers by year were fetched ('Y-m-d'), for the sitemap.
     *
     * @return array<int, string>
     */
    public static function fetchedDates(): array
    {
        try {
            $rows = chess_codex_db()->query(
                'SELECT opening_id, MAX(fetched_at) AS at FROM codex_year_stats WHERE opening_id <> ' . self::ALL_GAMES . ' GROUP BY opening_id'
            )->fetchAll();
        } catch (PDOException) {
            return [];
        }
        return array_combine(array_map(static fn (array $r): int => (int) $r['opening_id'], $rows),
                             array_map(static fn (array $r): string => substr((string) $r['at'], 0, 10), $rows));
    }

    /** The last year with all twelve months counted, or null. */
    public static function lastFullYear(): ?int
    {
        $full = array_keys(array_filter(self::rows(self::ALL_GAMES), static fn (array $y): bool => $y['months'] === 12));
        return $full ? max($full) : null;
    }

    /** The last month counted ('Y-m'): the year of the last row, through its number of months. */
    public static function lastMonth(): ?string
    {
        $all = self::rows(self::ALL_GAMES);
        if (!$all) return null;
        $year = max(array_keys($all));
        return sprintf('%d-%02d', $year, $all[$year]['months']);
    }

    /** A share as a percentage, two significant digits below 1%: 13.3%, 1.9%, 0.48%, 0.0071%. */
    public static function percent(float $share): string
    {
        $p = $share * 100;
        if ($p >= 1) return number_format($p, 1) . '%';
        if ($p <= 0) return '0%';
        $decimals = min(8, 1 - (int) floor(log10($p)));
        return rtrim(rtrim(number_format($p, $decimals), '0'), '.') . '%';
    }

    /** A change of share as a ratio, read as ×2.7 from double on, else +65% / −24% / 0%. */
    public static function change(float $ratio): string
    {
        if ($ratio >= 2) return '×' . number_format($ratio, 1);
        $pct = (int) round(($ratio - 1) * 100);
        return ($pct > 0 ? '+' : ($pct < 0 ? '−' : '')) . abs($pct) . '%';
    }

    /**
     * One sentence on how the line's share moved from FIRST_YEAR to the last
     * full year, with its peak when that stands out; null without every full
     * year from FIRST_YEAR on. $subject starts the sentence ("The Caro-Kann
     * Defense", "This line"). Plain text.
     *
     * @param array<int, array{games: int, white: int, draws: int, black: int, months: int, share: float}> $years
     */
    public static function sentence(array $years, string $subject): ?string
    {
        $shares = [];
        foreach ($years as $year => $y) {
            if ($y['months'] === 12) $shares[$year] = $y['share'];
        }
        if (!isset($shares[self::FIRST_YEAR]) || count($shares) < 5) return null;
        $from = self::FIRST_YEAR;
        $to   = max(array_keys($shares));
        if (count($shares) !== $to - $from + 1) return null;
        [$first, $last] = [$shares[$from], $shares[$to]];
        if ($first <= 0 || $last <= 0) return null;
        $peak    = max($shares);
        $peakAt  = (int) array_search($peak, $shares, true);
        $low     = min($shares);
        $lowAt   = (int) array_search($low, $shares, true);
        $inside  = static fn (int $year): bool => $year !== $from && $year !== $to;
        $p       = static fn (float $s): string => self::percent($s);
        $ratio   = $last / $first;
        $peakNote = $inside($peakAt) && $peak >= 1.1 * max($first, $last) ? " (its peak: {$p($peak)} in $peakAt)" : '';

        if ($ratio >= 1.2) {
            $times = (int) floor($ratio);
            $how = $ratio >= 2
                ? 'is played more than ' . ([2 => 'twice', 3 => 'three times', 4 => 'four times', 5 => 'five times', 6 => 'six times',
                    7 => 'seven times', 8 => 'eight times', 9 => 'nine times'][$times] ?? "$times times") . " as often as in $from"
                : "has gained ground since $from";
            return "$subject $how: {$p($last)} of Lichess games in $to, up from {$p($first)}$peakNote.";
        }
        if ($ratio <= 1 / 1.2) {
            $how = $ratio <= 0.5 ? "is played less than half as often as in $from" : "has lost ground since $from";
            return "$subject $how: {$p($last)} of Lichess games in $to, down from {$p($first)}$peakNote.";
        }
        if ($inside($peakAt) && $peak >= 1.15 * $last) {
            return "$subject peaked in $peakAt at {$p($peak)} of Lichess games and has slipped since: {$p($last)} in $to.";
        }
        if ($inside($lowAt) && $low <= $last / 1.15) {
            return "$subject dipped to {$p($low)} of Lichess games in $lowAt and has recovered since: {$p($last)} in $to.";
        }
        return $peak / $low < 1.1
            ? "$subject holds steady at about {$p($last)} of Lichess games a year since $from."
            : "$subject has stayed between {$p($low)} and {$p($peak)} of Lichess games a year since $from ({$p($last)} in $to).";
    }

    /** How many lines have numbers by year. */
    public static function lineCount(): int
    {
        try {
            return (int) chess_codex_db()->query(
                'SELECT COUNT(DISTINCT opening_id) FROM codex_year_stats WHERE opening_id <> ' . self::ALL_GAMES
            )->fetchColumn();
        } catch (PDOException) {
            return 0;
        }
    }

    /**
     * year => ['games', 'white', 'draws', 'black', 'months'] for one opening id, as stored.
     *
     * @return array<int, array{games: int, white: int, draws: int, black: int, months: int}>
     */
    private static function rows(int $openingId): array
    {
        static $memo = [];
        if (isset($memo[$openingId])) return $memo[$openingId];
        try {
            $stmt = chess_codex_db()->prepare(
                'SELECT year, white_wins, draws, black_wins, months FROM codex_year_stats WHERE opening_id = :id ORDER BY year'
            );
            $stmt->execute(['id' => $openingId]);
            $rows = $stmt->fetchAll();
        } catch (PDOException) {
            $rows = [];
        }
        $out = [];
        foreach ($rows as $r) {
            $w = (int) $r['white_wins']; $d = (int) $r['draws']; $b = (int) $r['black_wins'];
            $out[(int) $r['year']] = ['games' => $w + $d + $b, 'white' => $w, 'draws' => $d, 'black' => $b, 'months' => (int) $r['months']];
        }
        return $memo[$openingId] = $out;
    }
}
