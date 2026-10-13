<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

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
     */
    public static function fetchAndStore(int $openingId, array $uciMoves, string $lastMonth = ''): string
    {
        require_once __DIR__ . '/LichessExplorer.php';
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

    /** The last month counted ('Y-m'): the year of the last row, through its number of months. */
    public static function lastMonth(): ?string
    {
        $all = self::rows(self::ALL_GAMES);
        if (!$all) return null;
        $year = max(array_keys($all));
        return sprintf('%d-%02d', $year, $all[$year]['months']);
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

    /** year => ['games', 'white', 'draws', 'black', 'months'] for one opening id, as stored. */
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
