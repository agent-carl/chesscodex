<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ChessEngine.php';
require_once __DIR__ . '/StatsCache.php';

/**
 * Ranked lists for /gambits, /best-openings-for-white, /best-openings-for-black
 * and /popular-openings, built from the cached Lichess numbers
 * (codex_stats_cache) — they never call Lichess.
 */
final class Rankings
{
    /** The ranking pages (path => link text), in the order they link to each other. */
    public const LABELS = [
        'best-openings-for-white' => 'Best openings for White',
        'best-openings-for-black' => 'Best openings for Black',
        'popular-openings'        => 'Most popular openings',
        'gambits'                 => 'Chess gambits',
    ];

    /** Games a line needs before it's ranked by score. */
    public const MIN_GAMES = 1000000;

    /**
     * Every opening with cached results — id, slug, name, eco, pgn_moves,
     * move_count, games, white, draws, black — most-played first. Replaying
     * all 3,690 lines to find their cache keys takes ~0.3 s, so the list is
     * kept for six hours.
     */
    public static function all(): array
    {
        return Cache::remember('rankings-results', 21600, static function (): array {
            $rows = chess_codex_db()->query(
                'SELECT id, slug, name, eco, pgn_moves, move_count FROM codex_openings'
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                try {
                    $s = StatsCache::cached(ChessEngine::fromPgn((string) $r['pgn_moves'])->uciHistory());
                } catch (Throwable) {
                    continue;
                }
                $games = $s ? $s['white'] + $s['draws'] + $s['black'] : 0;
                if ($games === 0) continue;
                $out[] = [
                    'id' => (int) $r['id'], 'slug' => (string) $r['slug'], 'name' => (string) $r['name'],
                    'eco' => (string) $r['eco'], 'pgn_moves' => (string) $r['pgn_moves'],
                    'move_count' => (int) $r['move_count'], 'games' => $games,
                    'white' => $s['white'], 'draws' => $s['draws'], 'black' => $s['black'],
                ];
            }
            usort($out, static fn (array $a, array $b): int => $b['games'] <=> $a['games']);
            return $out;
        });
    }

    /**
     * The rows of one ranking page, one line per name (its most-played one).
     * Best-for pages rank the lines whose last move is that side's — the
     * positions White (or Black) chooses to play — by that side's score.
     */
    public static function rows(string $page): array
    {
        $all = self::all();
        switch ($page) {
            case 'popular-openings':
                return array_slice(self::onePerName($all), 0, 100);
            case 'gambits':
                $gambits = array_filter($all, static fn (array $r): bool => Opening::isGambit($r['name']));
                return array_slice(self::onePerName($gambits), 0, 100);
            case 'best-openings-for-white':
            case 'best-openings-for-black':
                $side  = $page === 'best-openings-for-white' ? 'white' : 'black';
                $lines = array_filter($all, static fn (array $r): bool =>
                    $r['games'] >= self::MIN_GAMES && ($r['move_count'] % 2 === 1) === ($side === 'white'));
                $lines = self::onePerName($lines);
                foreach ($lines as &$r) $r['score'] = self::score($r, $side);
                unset($r);
                usort($lines, static fn (array $a, array $b): int =>
                    [$b['score'], $b['games']] <=> [$a['score'], $a['games']]);
                return array_slice($lines, 0, 50);
        }
        return [];
    }

    /** $side's score in the line's games: wins plus half the draws, 0–1. */
    public static function score(array $r, string $side): float
    {
        return ($r[$side] + $r['draws'] / 2) / max(1, $r['games']);
    }

    /** 1,527,871,757 → "1.5B", 2,345,678 → "2.3M", 845,210 → "845K", 9,870 → "9,870". */
    public static function compact(int $n): string
    {
        // The thresholds sit where rounding reaches the next unit, so there's no "1000K".
        $one = static fn (float $x): string => rtrim(rtrim(number_format($x, 1, '.', ''), '0'), '.');
        if ($n >= 999_950_000) return $one($n / 1e9) . 'B';
        if ($n >= 999_500)     return $one($n / 1e6) . 'M';
        if ($n >= 10_000)      return round($n / 1000) . 'K';
        return number_format($n);
    }

    /** Keeps the first row of each name — the most-played one, as all() is sorted. */
    private static function onePerName(array $rows): array
    {
        $seen = [];
        $out  = [];
        foreach ($rows as $r) {
            if (isset($seen[$r['name']])) continue;
            $seen[$r['name']] = true;
            $out[] = $r;
        }
        return $out;
    }
}
