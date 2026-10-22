<?php
declare(strict_types=1);

/**
 * Ranked lists for /gambits, /best-openings-for-white, /best-openings-for-black,
 * /popular-openings and /best-gambits-for-beginners, built from the cached
 * Lichess numbers (codex_stats_cache, codex_level_stats) — they never call Lichess.
 */
final class Rankings
{
    /** The ranking pages (path => link text), in the order they link to each other. */
    public const LABELS = [
        'best-openings-for-white'    => 'Best openings for White',
        'best-openings-for-black'    => 'Best openings for Black',
        'popular-openings'           => 'Most popular openings',
        'gambits'                    => 'Chess gambits',
        'best-gambits-for-beginners' => 'Best gambits for beginners',
        'how-to-play-against'        => 'How to play against popular openings',
        'opening-trends'             => 'Opening trends',
    ];

    /** One line each for the /rankings hub. */
    public const BLURBS = [
        'best-openings-for-white'    => 'The 50 lines that score best for White, among those played a million times or more.',
        'best-openings-for-black'    => 'The 50 defenses and replies that score best for Black, by the same rule.',
        'popular-openings'           => 'The 100 most-played named lines, from 1.e4 down.',
        'gambits'                    => 'Every gambit and countergambit A–Z, and the 100 most played with how often each side wins.',
        'best-gambits-for-beginners' => 'The gambits that score best for White and for Black among players rated under 1400, and how they do at 2200 and up.',
        'how-to-play-against'        => 'The answer that scores best against each of the described openings, the most played one and Stockfish\'s choice.',
        'opening-trends'             => 'The openings played more and less than ten years ago, by their share of Lichess games each year.',
    ];

    /** Lines per list on /opening-trends, and the share of the games a line needs at the larger end of its change. */
    public const TRENDS = 20;
    public const TREND_MIN_SHARE = 0.0005;

    /** How many gambits per side /best-gambits-for-beginners lists. */
    public const BEST_GAMBITS = 20;

    /** Games a line needs before it's ranked by score. */
    public const MIN_GAMES = 1000000;

    /**
     * Every opening with cached results — id, slug, name, eco, pgn_moves,
     * move_count, games, white, draws, black, updated (Y-m-d of the numbers)
     * — most-played first. It looks up all 3,690 lines, so the list is kept
     * for six hours.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return Cache::remember('rankings-results-v2', 21600, static function (): array {
            $rows = chess_codex_db()->query(
                'SELECT id, slug, name, eco, pgn_moves, uci, move_count FROM codex_openings'
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $s = StatsCache::cached(Opening::uci($r));
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

    /**
     * @return list<array<string, mixed>>
     */
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
     *
     * @return list<array<string, mixed>>
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

    /**
     * $side's score in the line's games: wins plus half the draws, 0–1.
     *
     * @param array<string, mixed> $r
     */
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
     *
     * @return array{dubious: list<array<string, mixed>>, fading: list<array<string, mixed>>}
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
            $family = static fn (array $r): string => Opening::family((string) $r['name']);
            return ['dubious' => array_slice(self::firstPer($dubious, $family), 0, 8), 'fading' => array_slice(self::firstPer($fading, $family), 0, 8)];
        });
    }

    /**
     * /opening-trends: how each line's share of the games moved from
     * YearStats::FIRST_YEAR ('from') to the last full year ('to'). 'rising'
     * and 'falling': the biggest changes either way — at least a fifth, one
     * line per family, TRENDS each, among lines with TREND_MIN_SHARE of the
     * games at the larger end; 'gained' and 'lost': the lines whose share
     * moved most in points; 'firstMoves': White's first moves, most played
     * first; 'lines': how many lines have numbers by year. Each row: id, slug,
     * name, eco, moves, years (year => share), first, last, ratio. Kept six hours.
     *
     * @return array<string, mixed>
     */
    public static function trends(): array
    {
        return Cache::remember('opening-trends-v1', 21600, static function (): array {
            $from   = YearStats::FIRST_YEAR;
            $to     = YearStats::lastFullYear();
            $shares = YearStats::allShares();
            $out    = ['from' => $from, 'to' => $to, 'lines' => count($shares), 'rising' => [], 'falling' => [],
                       'gained' => null, 'lost' => null, 'firstMoves' => []];
            if ($to === null || $to - $from < 4 || !$shares) return $out;

            $ids  = implode(',', array_map('intval', array_keys($shares)));
            $rows = chess_codex_db()->query("SELECT id, slug, name, eco, pgn_moves, move_count FROM codex_openings WHERE id IN ($ids)")->fetchAll();
            $lines = [];
            foreach ($rows as $r) {
                $years = $shares[(int) $r['id']];
                if (count($years) !== $to - $from + 1 || ($years[$from] ?? 0) <= 0 || ($years[$to] ?? 0) <= 0) continue;
                $row = ['id' => (int) $r['id'], 'slug' => (string) $r['slug'], 'name' => (string) $r['name'], 'eco' => (string) $r['eco'],
                        'moves' => (string) $r['pgn_moves'], 'years' => $years, 'first' => $years[$from], 'last' => $years[$to], 'ratio' => $years[$to] / $years[$from]];
                if ((int) $r['move_count'] === 1) {
                    $out['firstMoves'][] = $row;
                } else {
                    $lines[] = $row;
                }
            }
            usort($out['firstMoves'], static fn (array $a, array $b): int => $b['last'] <=> $a['last']);

            $family = static fn (array $r): string => Opening::family((string) $r['name']);
            $rising  = array_filter($lines, static fn (array $r): bool => $r['ratio'] >= 1.2 && $r['last'] >= self::TREND_MIN_SHARE);
            $falling = array_filter($lines, static fn (array $r): bool => $r['ratio'] <= 1 / 1.2 && $r['first'] >= self::TREND_MIN_SHARE);
            usort($rising, static fn (array $a, array $b): int => $b['ratio'] <=> $a['ratio']);
            usort($falling, static fn (array $a, array $b): int => $a['ratio'] <=> $b['ratio']);
            $out['rising']  = array_slice(self::firstPer($rising, $family), 0, self::TRENDS);
            $out['falling'] = array_slice(self::firstPer($falling, $family), 0, self::TRENDS);

            foreach ($lines as $r) {
                $move = $r['last'] - $r['first'];
                if ($move > 0 && ($out['gained'] === null || $move > $out['gained']['last'] - $out['gained']['first'])) $out['gained'] = $r;
                if ($move < 0 && ($out['lost'] === null || $move < $out['lost']['last'] - $out['lost']['first'])) $out['lost'] = $r;
            }
            return $out;
        });
    }

    /**
     * /how-to-play-against: the described openings (Opening::described()),
     * most played first, by the side to move after them — 'black' answers
     * White's openings, 'white' Black's defenses — each with 'games' (in the
     * position), 'moveNo' ("3…"), 'best' and 'most' (Replies::picks() rows)
     * and 'engine' (Stockfish's first move, null if not evaluated). Only the
     * lines whose page asks "How to play against …": the name's main line,
     * with the other side to move (Opening::nameSide()), and two or more main
     * answers (Replies::main()). Kept six hours.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function answers(): array
    {
        return Cache::remember('answers-v1', 21600, static function (): array {
            $out = ['black' => [], 'white' => []];
            foreach (Opening::described() as $o) {
                $plies = (int) $o['move_count'];
                $whiteToMove = $plies % 2 === 0;
                if (Opening::distinguishingTail($o) !== ''
                    || Opening::nameSide((string) $o['name'], $plies) === ($whiteToMove ? 'white' : 'black')) continue;
                $stats = StatsCache::cached(Opening::uci($o));
                $total = $stats ? $stats['white'] + $stats['black'] + $stats['draws'] : 0;
                $main = $total > 0 ? Replies::main($stats['top_moves'] ?? [], $total, $whiteToMove) : [];
                if ($main === []) continue;
                [$most, $best] = Replies::picks($main);
                $eval = EngineEval::forFen((string) $o['fen']);
                $out[$whiteToMove ? 'white' : 'black'][] = [
                    'slug'      => (string) $o['slug'],
                    'name'      => (string) $o['name'],
                    'eco'       => (string) $o['eco'],
                    'pgn_moves' => (string) $o['pgn_moves'],
                    'games'     => $total,
                    'moveNo'    => (intdiv($plies, 2) + 1) . ($whiteToMove ? '. ' : '…'),
                    'best'      => $best,
                    'most'      => $most,
                    'engine'    => $eval && !empty($eval['pv']) ? (string) $eval['pv'][0] : null,
                ];
            }
            return $out;
        });
    }

    /**
     * /best-gambits-for-beginners: per side ('white', 'black'), the gambits
     * that score best for the side playing them among players rated under
     * 1400 — lines with at least MIN_GAMES_AT_LEVEL games there, one per
     * gambit (gambitKey) — each with 'low' (score under 1400, 0–100), 'games'
     * (games under 1400), 'high' (score at 2200+, null below 10,000 games)
     * and 'eval' (EngineEval row or null). BEST_GAMBITS each; kept six hours.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function bestGambitsForBeginners(): array
    {
        return Cache::remember('best-gambits-beginners-v2', 21600, static function (): array {
            $low  = LevelStats::allAtLevel('beginners');
            $high = LevelStats::allAtLevel('experts');
            $min  = self::MIN_GAMES_AT_LEVEL['default'];
            $out  = ['white' => [], 'black' => []];
            foreach (self::all() as $r) {
                $l = $low[$r['id']] ?? null;
                if ($l === null || $l['games'] < $min || !Opening::isGambit($r['name'])) continue;
                $side = self::gambitSide($r);
                $h    = $high[$r['id']] ?? null;
                $out[$side][] = array_merge($r, [
                    'side'  => $side,
                    'low'   => self::score($l, $side) * 100,
                    'games' => $l['games'],
                    'high'  => $h && $h['games'] >= 10000 ? self::score($h, $side) * 100 : null,
                ]);
            }
            foreach ($out as &$rows) {
                usort($rows, static fn (array $a, array $b): int => [$b['low'], $b['games']] <=> [$a['low'], $a['games']]);
                $rows = array_slice(self::firstPer($rows, static fn (array $r): string => self::gambitKey($r['name'])), 0, self::BEST_GAMBITS);
                $evals = EngineEval::forOpenings(array_column($rows, 'id'));
                foreach ($rows as &$r) $r['eval'] = $evals[$r['id']] ?? null;
                unset($r);
            }
            unset($rows);
            return $out;
        });
    }

    /**
     * The gambit a line plays, so the lines of one gambit count once: its
     * countergambit if it has one, else its first gambit, without "Accepted"
     * — "Danish Gambit Accepted: Copenhagen Defense" → "Danish Gambit",
     * "Vienna Game: Vienna Gambit" and "Vienna Gambit, with Max Lange
     * Defense" → "Vienna Gambit", "King's Gambit Declined: Falkbeer
     * Countergambit" → "Falkbeer Countergambit".
     */
    public static function gambitKey(string $name): string
    {
        $parts = array_map('trim', preg_split('/[:,]/', $name) ?: [$name]);
        $pick  = null;
        foreach ($parts as $part) {
            if (stripos($part, 'countergambit') !== false) { $pick = $part; break; }
            if ($pick === null && stripos($part, 'gambit') !== false) $pick = $part;
        }
        return $pick === null ? $name : (string) preg_replace('/\s+Accepted$/i', '', $pick);
    }

    /**
     * The side that plays a line's gambit: the one whose move first brings
     * "Gambit" (or "Countergambit") into the names along the line — so in
     * "King's Gambit Accepted: King's Knight's Gambit" it is White's 2. f4,
     * though Black and then White moved last. When that name ends in
     * "Accepted" or "Defense", its last move was the other side's.
     *
     * @param array<string, mixed> $line
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

    /**
     * @param array<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
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

    /**
     * The first row for each key, in order: one line per opening family, say.
     *
     * @param array<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function firstPer(array $rows, callable $key): array
    {
        $first = [];
        foreach ($rows as $r) $first[$key($r)] ??= $r;
        return array_values($first);
    }
}
