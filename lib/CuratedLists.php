<?php
declare(strict_types=1);

/**
 * The homepage's picks: the popular-openings and gambit strips, the
 * first-move tree and the opening of the day.
 */
class CuratedLists
{
    /**
     * The homepage's popular-openings strip: a curated list, cached on disk
     * for 6h. Deliberately not ranked by the popularity column — by Lichess
     * game counts the top is generic first-move umbrellas ("King's Pawn Game",
     * "Queen's Pawn Game", "Indian Defense"), several of them twice under the
     * same name.
     *
     * @return list<array<string, mixed>>
     */
    public static function topPopular(int $limit = 12): array
    {
        // Keyed by the list itself, so an edit to it shows at once.
        $cached = Cache::remember('popular_' . substr(md5(serialize(self::FAMOUS_OPENINGS)), 0, 8), 21600,
            static fn (): array => self::resolveCuratedNames(self::FAMOUS_OPENINGS, 50));
        return array_values(array_slice($cached, 0, $limit));
    }

    /**
     * The homepage's gambit strip, curated for the same reason (and without
     * Queen's Gambit Accepted, which the popular strip shows): by game count
     * seven of the top twelve "Gambit" names are Queen's Gambit lines, five
     * of them Declined variations.
     *
     * @return list<array<string, mixed>>
     */
    public static function topGambits(int $limit = 12): array
    {
        $cached = Cache::remember('gambits_' . substr(md5(serialize(self::FAMOUS_GAMBITS)), 0, 8), 21600,
            static fn (): array => self::resolveCuratedNames(self::FAMOUS_GAMBITS, 50));
        return array_values(array_slice($cached, 0, $limit));
    }

    /**
     * The homepage's "Openings by first move", from Lichess game counts:
     * 'main' — White's four most played first moves, each with Black's main
     * replies (3% of its games or more; up to seven after the two big first
     * moves, 1. e4 and 1. d4, and four after the others) and, after the two
     * biggest replies that have a fifth of its games, White's main second
     * moves (5% of the reply's games or more, up to five); 'other' — the next
     * eight first moves. Every step is a named line: san ("1…c5", also split
     * into no "1…" and move "c5"), name, slug, label — the name without what
     * the step above already says ("Closed" under 1…c5 Sicilian Defense, ''
     * for the same name) — and share: its part of the games after the step
     * above (of all games for a first move). Cached on disk for 6 h.
     *
     * @return array{main: list<array<string, mixed>>, other: list<array<string, mixed>>}
     */
    public static function firstMoveTree(): array
    {
        return Cache::remember('first-moves-v2', 21600, static function (): array {
            $rows = chess_codex_db()->query(
                'SELECT name, slug, pgn_canon, popularity FROM codex_openings
                 WHERE move_count <= 3 ORDER BY popularity DESC, id ASC'
            )->fetchAll();
            $next = [];   // the moves before => the named lines one move on, most played first
            $seen = [];
            foreach ($rows as $r) {
                $canon = (string) $r['pgn_canon'];
                if (isset($seen[$canon])) continue;
                $seen[$canon] = true;
                $next[implode(' ', array_slice(explode(' ', $canon), 0, -1))][] = $r;
            }
            $share = static fn (array $r, array $of): float => (int) $r['popularity'] / max(1, (int) $of['popularity']);
            // Every game starts with one of the first moves, so their games add up to all of them.
            $allGames = ['popularity' => array_sum(array_map(static fn (array $r): int => (int) $r['popularity'], $next[''] ?? []))];
            $node = static function (array $r, ?array $above) use ($share, $allGames): array {
                $tokens = explode(' ', (string) $r['pgn_canon']);
                $ply    = count($tokens);
                $name   = (string) $r['name'];
                $label  = $name;
                if ($above !== null) {
                    $prev = (string) $above['name'];
                    if ($name === $prev) {
                        $label = '';
                    } elseif (str_starts_with($name, $prev . ': ') || str_starts_with($name, $prev . ', ')) {
                        $label = substr($name, strlen($prev) + 2);
                    } elseif (str_contains($name, ': ') && OpeningNames::family($name) === OpeningNames::family($prev)) {
                        $label = substr($name, strlen(OpeningNames::family($name)) + 2);
                    }
                }
                $no = (intdiv($ply - 1, 2) + 1) . ($ply % 2 === 1 ? '.' : '…');
                return [
                    'san'   => $no . ($ply % 2 === 1 ? ' ' : '') . end($tokens),
                    'no'    => $no,
                    'move'  => end($tokens),
                    'name'  => $name,
                    'label' => $label,
                    'slug'  => (string) $r['slug'],
                    'share' => $share($r, $above ?? $allGames),
                ];
            };

            $tree = ['main' => [], 'other' => []];
            foreach ($next[''] ?? [] as $i => $first) {
                if ($i >= 4) {
                    if (count($tree['other']) < 8) $tree['other'][] = $node($first, null);
                    continue;
                }
                $replies   = [];
                $branching = 0;
                foreach ($next[(string) $first['pgn_canon']] ?? [] as $reply) {
                    if ($share($reply, $first) < 0.03 || count($replies) >= ($i < 2 ? 7 : 4)) break;
                    $item = $node($reply, $first) + ['seconds' => []];
                    if ($branching < 2 && $share($reply, $first) >= 0.2) {
                        $branching++;
                        foreach ($next[(string) $reply['pgn_canon']] ?? [] as $second) {
                            if ($share($second, $reply) < 0.05 || count($item['seconds']) >= 5) break;
                            $item['seconds'][] = $node($second, $reply);
                        }
                    }
                    $replies[] = $item;
                }
                $tree['main'][] = $node($first, null) + ['replies' => $replies];
            }
            return $tree;
        });
    }

    /**
     * Famous openings everyone has heard of, for the homepage strip.
     * Order here = display order.
     */
    private const FAMOUS_OPENINGS = [
        'Sicilian Defense',
        'Italian Game',
        'Ruy Lopez',
        'French Defense',
        'Caro-Kann Defense',
        "Queen's Gambit Declined",
        "Queen's Gambit Accepted",
        "King's Indian Defense",
        'Nimzo-Indian Defense',
        'English Opening',
        "Queen's Pawn Game: London System",
        'Scandinavian Defense',
        'Slav Defense',
        'Pirc Defense',
        'Catalan Opening',
        "Queen's Pawn Game",
    ];

    // Lichess names in full: most famous gambits are a variation of another
    // opening ("Italian Game: Evans Gambit"), and resolveCuratedNames() only
    // matches a whole name or its "Name: …" lines.
    private const FAMOUS_GAMBITS = [
        "King's Gambit",
        "Queen's Gambit",
        'Italian Game: Evans Gambit',
        'Sicilian Defense: Smith-Morra Gambit',
        'Latvian Gambit',
        'Indian Defense: Budapest Defense',
        "Queen's Gambit Declined: Albin Countergambit",
        'Englund Gambit',
        'Benko Gambit',
        'Danish Gambit',
        'Scotch Game: Göring Gambit',
        'Four Knights Game: Halloween Gambit',
        'Blackmar-Diemer Gambit',
        "Bird Opening: From's Gambit",
        'Semi-Slav Defense: Marshall Gambit',
    ];

    /**
     * Looks up each name with both exact match AND a "starts with" fallback,
     * preserving the input order. Prefers shortest variation (root) when the
     * name has multiple matches. Returns at most $limit rows.
     *
     * @param list<string> $names
     * @return list<array<string, mixed>>
     */
    private static function resolveCuratedNames(array $names, int $limit): array
    {
        $pdo  = chess_codex_db();
        $stmt = $pdo->prepare(
            "SELECT id, eco, name, slug, depth, move_count, popularity, pgn_moves
             FROM codex_openings
             WHERE name = :n
                OR name LIKE :prefix
             ORDER BY (CASE WHEN name = :n2 THEN 0 ELSE 1 END) ASC,
                      move_count ASC, id ASC
             LIMIT 1"
        );
        $out  = [];
        $seen = [];
        foreach ($names as $name) {
            $stmt->execute([
                'n'      => $name,
                'n2'     => $name,
                'prefix' => $name . ':%',
            ]);
            $row = $stmt->fetch();
            if ($row && !isset($seen[(int) $row['id']])) {
                $out[] = $row;
                $seen[(int) $row['id']] = true;
                if (count($out) >= $limit) break;
            }
        }
        return $out;
    }

    /**
     * Daily "opening of the day" — deterministic per calendar day so the
     * featured card on the homepage stays stable through a day's visits
     * (good for caching) while still rotating freshness signal to Google.
     * Picks from openings with a curated description — these render the
     * most polished featured card. Falls back to any shallow root opening
     * if that set is empty.
     *
     * @return array<string, mixed>|null
     */
    public static function ofTheDay(): ?array
    {
        // Seed from today's date so all users get the same opening today,
        // a different one tomorrow.
        $seed = (int) date('Ymd');
        $pdo  = chess_codex_db();

        // Preferred set: openings that have a substantial curated description.
        // Characters, not bytes: SQLite's LENGTH counts characters for text.
        $charLength = 'LENGTH';
        $candidates = $pdo->query(
            "SELECT id, eco, name, slug, move_count, description, pgn_moves
             FROM codex_openings
             WHERE description IS NOT NULL
               AND $charLength(description) > 200
             ORDER BY id"
        )->fetchAll();

        if (empty($candidates)) {
            // Fallback: just any shallow root opening (move_count <= 4).
            $candidates = $pdo->query(
                "SELECT id, eco, name, slug, move_count, description, pgn_moves
                 FROM codex_openings
                 WHERE move_count BETWEEN 2 AND 4
                 ORDER BY id"
            )->fetchAll();
        }
        if (empty($candidates)) return null;
        return $candidates[$seed % count($candidates)];
    }
}
