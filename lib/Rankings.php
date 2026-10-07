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

    /** One line each for the /rankings hub. */
    public const BLURBS = [
        'best-openings-for-white' => 'The 50 lines that score best for White, among those played a million times or more.',
        'best-openings-for-black' => 'The 50 defenses and replies that score best for Black, by the same rule.',
        'popular-openings'        => 'The 100 most-played named lines, from 1.e4 down.',
        'gambits'                 => 'Every gambit and countergambit A–Z, and the 100 most played with how often each side wins.',
    ];

    /** Games a line needs before it's ranked by score. */
    public const MIN_GAMES = 1000000;

    /**
     * Every opening with cached results — id, slug, name, eco, pgn_moves,
     * move_count, games, white, draws, black, updated (Y-m-d of the numbers)
     * — most-played first. Replaying
     * all 3,690 lines to find their cache keys takes ~0.3 s, so the list is
     * kept for six hours.
     */
    public static function all(): array
    {
        return Cache::remember('rankings-results-v2', 21600, static function (): array {
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
                    'updated' => substr((string) ($s['cached_at'] ?? ''), 0, 10),
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
    /**
     * Games a line needs at one level (LevelStats) to be ranked there: the
     * masters database is a few million games, the Lichess bands billions.
     */
    public const MIN_GAMES_AT_LEVEL = ['masters' => 300, 'default' => 50000];

    public static function rows(string $page, ?string $level = null): array
    {
        $all = self::all();
        if ($level !== null) {
            // The numbers of that level instead of the 1600–2500 ones, for the
            // lines tools/fetch-levels.php has fetched.
            $at  = LevelStats::allAtLevel($level);
            $min = self::MIN_GAMES_AT_LEVEL[$level] ?? self::MIN_GAMES_AT_LEVEL['default'];
            $all = array_values(array_filter(array_map(
                static fn (array $r): ?array => isset($at[$r['id']]) && $at[$r['id']]['games'] >= $min
                    ? array_merge($r, $at[$r['id']]) : null,
                $all)));
        }
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
                    ($level !== null || $r['games'] >= self::MIN_GAMES) && ($r['move_count'] % 2 === 1) === ($side === 'white'));
                $lines = self::onePerName($lines);
                foreach ($lines as &$r) $r['score'] = self::score($r, $side);
                unset($r);
                usort($lines, static fn (array $a, array $b): int =>
                    [$b['score'], $b['games']] <=> [$a['score'], $a['games']]);
                return array_slice($lines, 0, $level !== null ? 30 : 50);
        }
        return [];
    }

    /**
     * Every gambit line (Opening::isGambit), A–Z as in Opening::allAlphabetical()
     * — with its "tail" where several lines share a name — plus its cached
     * Lichess game count where there is one (0 otherwise).
     */
    public static function allGambits(): array
    {
        $games = [];
        foreach (self::all() as $r) $games[$r['id']] = $r['games'];
        $out = [];
        foreach (Opening::allAlphabetical() as $rows) {
            foreach ($rows as $r) {
                if (!Opening::isGambit((string) $r['name'])) continue;
                $r['games'] = $games[(int) $r['id']] ?? 0;
                $out[] = $r;
            }
        }
        return $out;
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
    /**
     * Where Stockfish and practice part ways among the 100 most-played gambits
     * (rows('gambits')), scored for the side that plays the gambit:
     *  'dubious' — a pawn or more worse on the engine, yet 47% or more in the
     *              1600–2500 games: + 'side', 'cp' (White's side), 'score';
     *  'fading'  — the score falls by 3 points or more from under 1400 to 2200
     *              and up: + 'side', 'low', 'high', 'masters' (null below 300 games).
     * Eight of each, one per opening family, most played first / biggest fall
     * first; kept six hours.
     */
    public static function gambitInsights(): array
    {
        return Cache::remember('gambit-insights-v1', 21600, static function (): array {
            $rows  = self::rows('gambits');
            $evals = EngineEval::forOpenings(array_column($rows, 'id'));
            $low   = LevelStats::allAtLevel('beginners');
            $high  = LevelStats::allAtLevel('experts');
            $mast  = LevelStats::allAtLevel('masters');
            $pct   = static fn (array $l, string $side): float => self::score($l, $side) * 100;
            $dubious = $fading = [];
            foreach ($rows as $r) {
                $side = self::gambitSide($r);
                $e    = $evals[$r['id']] ?? null;
                if ($e && $e['mate'] === null && $e['cp'] !== null
                    && ($side === 'white' ? $e['cp'] : -$e['cp']) <= -100 && $pct($r, $side) >= 47) {
                    $dubious[] = $r + ['side' => $side, 'cp' => $e['cp'], 'score' => $pct($r, $side)];
                }
                $a = $low[$r['id']] ?? null;
                $b = $high[$r['id']] ?? null;
                if ($a && $b && $a['games'] >= 10000 && $b['games'] >= 10000) {
                    [$sa, $sb] = [(int) round($pct($a, $side)), (int) round($pct($b, $side))];
                    $m = $mast[$r['id']] ?? null;
                    if ($sa - $sb >= 3) {
                        $fading[] = $r + ['side' => $side, 'low' => $sa, 'high' => $sb,
                                          'masters' => $m && $m['games'] >= 300 ? (int) round($pct($m, $side)) : null];
                    }
                }
            }
            usort($fading, static fn (array $x, array $y): int => [$y['low'] - $y['high'], $y['games']] <=> [$x['low'] - $x['high'], $x['games']]);
            // One line per opening family, so three Englund lines don't fill a list.
            $onePerFamily = static function (array $rows): array {
                $seen = [];
                return array_values(array_filter($rows, static function (array $r) use (&$seen): bool {
                    $f = Opening::family((string) $r['name']);
                    return !isset($seen[$f]) && ($seen[$f] = true);
                }));
            };
            return ['dubious' => array_slice($onePerFamily($dubious), 0, 8), 'fading' => array_slice($onePerFamily($fading), 0, 8)];
        });
    }

    /**
     * The side that plays a line's gambit: the one whose move first brings
     * "Gambit" (or "Countergambit") into the names along the line — so in
     * "King's Gambit Accepted: King's Knight's Gambit" it is White's 2. f4,
     * though Black and then White moved last. When that name ends in
     * "Accepted" or "Defense", its last move was the other side's.
     */
    public static function gambitSide(array $line): string
    {
        static $plies = null;
        $plies ??= chess_codex_db()->prepare('SELECT move_count FROM codex_openings WHERE id = :id');
        $chain   = [...Opening::ancestors((int) $line['id']), $line];
        $counter = stripos((string) $line['name'], 'countergambit') !== false;
        foreach ($chain as $c) {
            $name = (string) $c['name'];
            if ($counter ? stripos($name, 'countergambit') === false : !Opening::isGambit($name)) continue;
            $plies->execute(['id' => (int) $c['id']]);
            $mover = (int) $plies->fetchColumn() % 2 === 1 ? 'white' : 'black';
            // The move that took the gambit, or a defense named after it ("Scotch
            // Gambit, Dubois Réti Defense" first appears after 4. d4 exd4).
            if (preg_match('/accepted$|[:,]\s+(?!with\b)[^:,]*defense$/i', $name)) $mover = $mover === 'white' ? 'black' : 'white';
            return $mover;
        }
        return (int) $line['move_count'] % 2 === 1 ? 'white' : 'black';
    }

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
