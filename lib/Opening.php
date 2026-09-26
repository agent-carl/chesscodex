<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

class Opening
{
    public static function findBySlug(string $slug): ?array
    {
        $stmt = chess_codex_db()->prepare(
            'SELECT id, eco, name, slug, parent_id, depth, fen, pgn_moves, move_count, popularity, description
             FROM codex_openings WHERE slug = :slug LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = chess_codex_db()->prepare(
            'SELECT id, eco, name, slug, depth, move_count, pgn_moves FROM codex_openings WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Named lines one move away from this one by a different move order
     * (db/transpositions.json, from tools/build-transpositions.php):
     * 'from' — lines whose position plus a move gives this one; 'to' — lines
     * a move from this position leads into. Each item: [row, SAN], the row
     * with id, eco, name, slug, move_count.
     */
    public static function transpositions(int $id): array
    {
        static $map = null;
        $map ??= json_decode((string) @file_get_contents(__DIR__ . '/../db/transpositions.json'), true) ?: [];
        $pairs = ['from' => [], 'to' => []];
        foreach ($map[$id] ?? [] as [$to, $san]) $pairs['to'][] = [(int) $to, $san];
        foreach ($map as $from => $targets) {
            foreach ($targets as [$to, $san]) {
                if ((int) $to === $id) $pairs['from'][] = [(int) $from, $san];
            }
        }
        if ($pairs['from'] === [] && $pairs['to'] === []) return $pairs;

        $ids  = array_unique(array_merge(array_column($pairs['from'], 0), array_column($pairs['to'], 0)));
        $stmt = chess_codex_db()->prepare('SELECT id, eco, name, slug, move_count FROM codex_openings WHERE id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute(array_values($ids));
        $rows = array_column($stmt->fetchAll(), null, 'id');
        foreach ($pairs as $dir => $list) {
            $pairs[$dir] = array_values(array_filter(array_map(
                static fn (array $p): ?array => isset($rows[$p[0]]) ? [$rows[$p[0]], $p[1]] : null, $list)));
        }
        return $pairs;
    }

    /** The opening's move list ("1. e4 c5 2. Nf3"), or null for an unknown id. */
    public static function movesById(int $id): ?string
    {
        $stmt = chess_codex_db()->prepare('SELECT pgn_moves FROM codex_openings WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $moves = $stmt->fetchColumn();
        return $moves === false ? null : (string) $moves;
    }

    /**
     * Lichess gives several distinct move orders the same name and ECO code
     * (14 lines are all "Italian Game: Classical Variation, Giuoco
     * Pianissimo", C54). For those, returns the shortest run of final moves
     * that no other line with that name ends with, e.g. "6…a6 7. Re1", so
     * titles and descriptions can tell the pages apart. '' when the name +
     * ECO pair is unique.
     */
    public static function distinguishingTail(array $opening): string
    {
        $stmt = chess_codex_db()->prepare(
            'SELECT pgn_moves FROM codex_openings WHERE name = :n AND eco = :e AND id <> :id'
        );
        $stmt->execute(['n' => $opening['name'], 'e' => $opening['eco'], 'id' => $opening['id']]);
        return self::tailAgainst((string) $opening['pgn_moves'], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * distinguishingTail() for many rows at once, without a query per row:
     * id => tail for each row (id, name, eco, pgn_moves) that shares its name
     * and ECO code with another row of $rows.
     */
    public static function lineTails(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) $groups[$r['name'] . "\n" . $r['eco']][(int) $r['id']] = (string) $r['pgn_moves'];
        $tails = [];
        foreach ($groups as $group) {
            if (count($group) < 2) continue;
            foreach ($group as $id => $pgn) {
                $others = $group;
                unset($others[$id]);
                $tails[$id] = self::tailAgainst($pgn, array_values($others));
            }
        }
        return $tails;
    }

    /**
     * True when the line plays or accepts a gambit. "Queen's Gambit Declined"
     * and other "… Gambit Declined" lines are not: nothing was sacrificed.
     */
    public static function isGambit(string $name): bool
    {
        return (bool) preg_match('/gambit(?!\s+declined)/i', $name);
    }

    /** The shortest numbered ending of $pgn that none of $otherPgns ends with. */
    private static function tailAgainst(string $pgn, array $otherPgns): string
    {
        $others = array_map([self::class, 'sanTokens'], $otherPgns);
        if (empty($others)) return '';

        $mine = self::sanTokens($pgn);
        $n = count($mine);
        for ($k = min(2, $n); $k < $n; $k++) {
            $tail = self::formatPlies(array_slice($mine, -$k), $n - $k);
            $clash = false;
            foreach ($others as $t) {
                if (count($t) >= $k && self::formatPlies(array_slice($t, -$k), count($t) - $k) === $tail) {
                    $clash = true;
                    break;
                }
            }
            if (!$clash) return $tail;
        }
        // Only the whole line is unique (move numbers from 1 make it so).
        return self::formatPlies($mine, 0);
    }

    /**
     * Every ECO code that has named lines, in code order: code => [
     *   'count' => lines filed under it,
     *   'label' => the opening most of them belong to — "X & Y" or
     *              "X, Y & more" when the code mixes several (A00 has 143
     *              lines from over a dozen irregular openings),
     *   'slug'  => its shortest line,
     *   'moves' => that line's moves ("1. c4 e5"), which tell apart the
     *              many codes that share a label ("English Opening"),
     * ]. Cached on disk for an hour.
     */
    public static function ecoCodes(): array
    {
        return Cache::remember('eco_codes_v2', 3600, static function (): array {
            $rows = chess_codex_db()->query(
                'SELECT eco, name, slug, pgn_moves FROM codex_openings ORDER BY eco, move_count, id'
            )->fetchAll();
            $codes = [];
            foreach ($rows as $r) {
                $e = (string) $r['eco'];
                $family = self::family((string) $r['name']);
                $codes[$e]['count'] = ($codes[$e]['count'] ?? 0) + 1;
                $codes[$e]['slug'] ??= (string) $r['slug'];
                $codes[$e]['moves'] ??= trim((string) $r['pgn_moves']);
                $codes[$e]['families'][$family] = ($codes[$e]['families'][$family] ?? 0) + 1;
            }
            foreach ($codes as &$c) {
                arsort($c['families']);   // stable: ties keep shortest-line order
                $names = array_keys($c['families']);
                $c['label'] = match (true) {
                    count($names) === 1 => $names[0],
                    count($names) === 2 => $names[0] . ' & ' . $names[1],
                    default             => $names[0] . ', ' . $names[1] . ' & more',
                };
                unset($c['families']);
            }
            unset($c);
            return $codes;
        });
    }

    /** All lines filed under one ECO code, shortest first. */
    public static function byEco(string $eco): array
    {
        $stmt = chess_codex_db()->prepare(
            'SELECT id, eco, name, slug, pgn_moves, move_count FROM codex_openings
             WHERE eco = :e ORDER BY move_count, name, id'
        );
        $stmt->execute(['e' => $eco]);
        return $stmt->fetchAll();
    }

    /**
     * The line and every named line that continues it (slug, name, eco,
     * pgn_moves, popularity), shortest first — what a PGN download of the
     * tree holds, and what the trainer drills.
     */
    public static function withContinuations(array $opening): array
    {
        require_once __DIR__ . '/parser.php';
        $canon = chess_codex_canonicalize_pgn((string) $opening['pgn_moves']);
        $stmt = chess_codex_db()->prepare(
            "SELECT slug, name, eco, pgn_moves, popularity FROM codex_openings
             WHERE pgn_canon = :c OR pgn_canon LIKE :p ORDER BY move_count, id"
        );
        $stmt->execute(['c' => $canon, 'p' => $canon . ' %']);
        return $stmt->fetchAll();
    }

    /** Rows (slug, name, eco, pgn_moves, popularity) for up to 200 slugs, in the order given. */
    public static function bySlugs(array $slugs): array
    {
        $slugs = array_slice(array_values(array_unique(array_filter($slugs,
            static fn ($s): bool => is_string($s) && preg_match('/^[a-z0-9-]{1,200}$/', $s) === 1))), 0, 200);
        if ($slugs === []) return [];
        $stmt = chess_codex_db()->prepare('SELECT slug, name, eco, pgn_moves, popularity FROM codex_openings WHERE slug IN ('
            . implode(',', array_fill(0, count($slugs), '?')) . ')');
        $stmt->execute($slugs);
        $bySlug = array_column($stmt->fetchAll(), null, 'slug');
        return array_values(array_filter(array_map(static fn (string $s): ?array => $bySlug[$s] ?? null, $slugs)));
    }

    /** "1 move", "3 moves": a line's length in full moves (move_count counts plies). */
    public static function movesLabel(int $plies): string
    {
        $n = intdiv($plies + 1, 2);
        return $n === 1 ? '1 move' : $n . ' moves';
    }

    /**
     * The moves of $pgn from 0-based ply $fromPly on, numbered: the moves
     * after the parent's position that reach a variation ("3…Bc5",
     * "6. Bg5 e6"). '' when there are none.
     */
    public static function movesFrom(string $pgn, int $fromPly): string
    {
        return self::formatPlies(array_slice(self::sanTokens($pgn), max(0, $fromPly)), max(0, $fromPly));
    }

    /** "Sicilian Defense: Najdorf Variation" → "Sicilian Defense". */
    public static function family(string $name): string
    {
        return trim(preg_split('/[:,]/', $name, 2)[0]);
    }

    /** "1. e4 c5 2. Nf3" → ['e4', 'c5', 'Nf3'] (check marks kept). */
    private static function sanTokens(string $pgn): array
    {
        $tokens = preg_split('/\s+/', trim($pgn)) ?: [];
        $sans = [];
        foreach ($tokens as $t) {
            $t = (string) preg_replace('/^\d+\.+/', '', $t);  // "1." or "12...Nf6"
            if ($t !== '') $sans[] = $t;
        }
        return $sans;
    }

    /** Numbered SAN starting at 0-based ply $firstPly: "6…a6 7. Re1". */
    private static function formatPlies(array $sans, int $firstPly): string
    {
        $out = [];
        foreach (array_values($sans) as $i => $san) {
            $ply = $firstPly + $i;
            $num = intdiv($ply, 2) + 1;
            if ($ply % 2 === 0) $out[] = $num . '. ' . $san;
            elseif ($i === 0)   $out[] = $num . '…' . $san;
            else                $out[] = $san;
        }
        return implode(' ', $out);
    }

    /** Direct children (depth + 1), sorted by name. */
    public static function children(int $parentId): array
    {
        $stmt = chess_codex_db()->prepare(
            'SELECT id, eco, name, slug, popularity, move_count, pgn_moves FROM codex_openings
             WHERE parent_id = :pid ORDER BY name'
        );
        $stmt->execute(['pid' => $parentId]);
        return $stmt->fetchAll();
    }

    /**
     * Counts per ECO group (A..E), keyed by group letter.
     * Cached on disk for 1h via the Cache helper.
     */
    public static function countsByGroup(): array
    {
        return Cache::remember('counts_by_group', 3600, static function (): array {
            $rows = chess_codex_db()->query(
                "SELECT SUBSTR(eco, 1, 1) AS grp, COUNT(*) AS n FROM codex_openings GROUP BY grp"
            )->fetchAll();
            $counts = [];
            foreach ($rows as $r) $counts[$r['grp']] = (int) $r['n'];
            return $counts;
        });
    }

    /**
     * Slug of the depth-0 root opening for an ECO group, used as the
     * landing page for the homepage's group cards.
     */
    public static function rootSlugForGroup(string $groupLetter): ?string
    {
        $stmt = chess_codex_db()->prepare(
            "SELECT slug FROM codex_openings
             WHERE SUBSTR(eco, 1, 1) = :g AND parent_id IS NULL
             ORDER BY id LIMIT 1"
        );
        $stmt->execute(['g' => $groupLetter]);
        $slug = $stmt->fetchColumn();
        return $slug !== false ? (string) $slug : null;
    }

    /**
     * Find the deepest opening whose canonical PGN equals the query, or is
     * a strict prefix of it (closest known ancestor when the query went past
     * documented theory). Returns null if no opening matches.
     */
    public static function searchByCanon(string $canonQuery): ?array
    {
        $canonQuery = trim($canonQuery);
        if ($canonQuery === '') return null;

        // Candidate set: any opening whose pgn_canon is == query, or whose
        // pgn_canon is a prefix of query (a parent in the tree).
        // We can't express "prefix-of" with a plain index, so we restrict
        // the candidate set by ply-count: candidates can't have more plies
        // than the query has tokens.
        $queryPlies = substr_count($canonQuery, ' ') + 1;

        $stmt = chess_codex_db()->prepare(
            "SELECT id, eco, name, slug, depth, pgn_canon, move_count
             FROM codex_openings
             WHERE move_count <= :p
             ORDER BY move_count DESC"
        );
        $stmt->execute(['p' => $queryPlies]);

        foreach ($stmt as $row) {
            $canon = (string) $row['pgn_canon'];
            if ($canon === '') continue;
            if ($canon === $canonQuery) {
                $row['exact'] = true;
                return $row;
            }
            // Strict prefix: query starts with canon + ' '
            if (strncmp($canonQuery, $canon . ' ', strlen($canon) + 1) === 0) {
                $row['exact'] = false;
                return $row;
            }
        }
        return null;
    }

    /**
     * Find all openings that arrive at the exact same final FEN. This is the
     * transposition lookup — the same position reached through different
     * move orders. Returns an array sorted by move_count ASC (shortest first).
     */
    public static function searchByFen(string $fen): array
    {
        // Compare only placement + side to move + castling. Halfmove/fullmove
        // counters differ between transpositions, and the en-passant field
        // depends on the tool that wrote the FEN: chess.js (and our stored
        // FENs) always set it after a double pawn push, Lichess only when the
        // capture is legal — so a FEN pasted from Lichess would never match.
        $parts = preg_split('/\s+/', trim($fen)) ?: [];
        $core  = implode(' ', array_slice($parts, 0, 3)) . ' ';
        $stmt = chess_codex_db()->prepare(
            "SELECT id, eco, name, slug, depth, move_count, fen
             FROM codex_openings
             WHERE fen LIKE :pat
             ORDER BY move_count ASC, id ASC
             LIMIT 50"
        );
        $stmt->bindValue(':pat', $core . '%', PDO::PARAM_STR);
        $stmt->execute();
        // LIKE is case-insensitive (by collation on MySQL, by our function on
        // SQLite), but FEN case means colour — re-check with exact comparison.
        return array_values(array_filter(
            $stmt->fetchAll(),
            static fn ($r) => strncmp((string) $r['fen'], $core, strlen($core)) === 0
        ));
    }

    /**
     * The homepage's popular-openings strip: a curated list, cached on disk
     * for 6h. Deliberately not ranked by the popularity column — by Lichess
     * game counts the top is generic first-move umbrellas ("King's Pawn Game",
     * "Queen's Pawn Game", "Indian Defense"), several of them twice under the
     * same name.
     */
    public static function topPopular(int $limit = 12): array
    {
        // Keyed by the list itself, so an edit to it shows at once.
        $cached = Cache::remember('popular_' . substr(md5(serialize(self::FAMOUS_OPENINGS)), 0, 8), 21600,
            static fn (): array => self::resolveCuratedNames(self::FAMOUS_OPENINGS, 50));
        return array_slice($cached, 0, $limit);
    }

    /**
     * The homepage's gambit strip, curated for the same reason (and without
     * Queen's Gambit Accepted, which the popular strip shows): by game count
     * seven of the top twelve "Gambit" names are Queen's Gambit lines, five
     * of them Declined variations.
     */
    public static function topGambits(int $limit = 12): array
    {
        $cached = Cache::remember('gambits_' . substr(md5(serialize(self::FAMOUS_GAMBITS)), 0, 8), 21600,
            static fn (): array => self::resolveCuratedNames(self::FAMOUS_GAMBITS, 50));
        return array_slice($cached, 0, $limit);
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
        'London System',
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
     * Common short names and spellings people type, in nameKey() form.
     */
    private const NAME_ALIASES = [
        'qg'       => 'queens gambit',
        'qga'      => 'queens gambit accepted',
        'qgd'      => 'queens gambit declined',
        'qid'      => 'queens indian defense',
        'kid'      => 'kings indian defense',
        'kia'      => 'kings indian attack',
        'kga'      => 'kings gambit accepted',
        'kgd'      => 'kings gambit declined',
        'nid'      => 'nimzo indian defense',
        'bdg'      => 'blackmar diemer gambit',
        'spanish'  => 'ruy lopez',
        'petroff'  => 'petrovs',
        'petrov'   => 'petrovs',
        'gruenfeld' => 'grunfeld',
        'defence'  => 'defense',
        'defences' => 'defense',
    ];

    /**
     * A name or query reduced to plain lowercase words: accents folded,
     * apostrophes dropped ("Queen's" → "queens"), everything else that is not
     * a letter or digit turned into a space ("Caro-Kann" → "caro kann").
     */
    public static function nameKey(string $s): string
    {
        $s = str_replace(["'", '’', '‘'], '', chess_codex_fold($s));
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    /**
     * Name autocomplete. Every word of the query has to match a word of the
     * name — as the whole word, its beginning, a part of it, or with one typo
     * (two in long words); typo matches rank below the rest. A whole-name
     * match comes first, then names that start with the query, then names
     * where a query word opens a part of the name ("sicilian dragon" → the
     * Dragon Variation before the Hyperaccelerated Dragon), then popularity.
     * Each name appears once. ALSO matches the ECO code when the query looks
     * like one ("B20", "C45").
     */
    public static function searchByName(string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '' || mb_strlen($q) < 2) return [];

        // ECO code mode — when the user types "B20", "C45", "E60", etc.
        // Match these directly. They're rarely meaningful as substrings of
        // names, so we treat them as a separate cheap query.
        $ecoQuery = strtoupper($q);
        if (preg_match('/^[A-E]\d{1,2}$/', $ecoQuery)) {
            $stmt = chess_codex_db()->prepare(
                "SELECT id, eco, name, slug, depth, move_count
                 FROM codex_openings WHERE eco = :e
                 ORDER BY move_count ASC, id ASC
                 LIMIT :lim"
            );
            $stmt->bindValue(':e',   $ecoQuery, PDO::PARAM_STR);
            $stmt->bindValue(':lim', max(1, min(50, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        $rows = Cache::remember('name-index', 21600, static function (): array {
            $stmt = chess_codex_db()->query(
                "SELECT id, eco, name, slug, depth, move_count, popularity FROM codex_openings"
            );
            return array_map([self::class, 'indexRow'], $stmt->fetchAll());
        });
        return self::rankByName($rows, $q, $limit);
    }

    /**
     * Adds what rankByName() matches on: 'key' (nameKey of the name) and
     * 'starts', the first word of each part of the name ("Sicilian Defense",
     * "Dragon Variation", "Yugoslav Attack"), which gets a bonus.
     */
    public static function indexRow(array $r): array
    {
        $starts = [];
        foreach (preg_split('/[:,]/', (string) $r['name']) ?: [] as $part) {
            $w = explode(' ', self::nameKey($part))[0];
            if ($w !== '') $starts[] = $w;
        }
        $r['key']    = self::nameKey((string) $r['name']);
        $r['starts'] = $starts;
        return $r;
    }

    /**
     * The matching and ordering behind searchByName(), on rows prepared by
     * indexRow(). Separate so the tests can run it without a database.
     */
    public static function rankByName(array $rows, string $q, int $limit = 10): array
    {
        $words = [];
        foreach (explode(' ', self::nameKey($q)) as $w) {
            if ($w === '') continue;
            array_push($words, ...explode(' ', self::NAME_ALIASES[$w] ?? $w));
        }
        if (count($words) === 0 || count($words) > 8) return [];
        $query = implode(' ', $words);

        $scored = [];
        foreach ($rows as $row) {
            $nameWords = explode(' ', $row['key']);
            $score = 0;
            $fuzzy = false;
            foreach ($words as $i => $qw) {
                $best = 0;
                foreach ($nameWords as $nw) {
                    if ($nw === $qw) { $best = 3; break; }
                    if (str_starts_with($nw, $qw)) { $best = max($best, 2); continue; }
                    if ($best < 1 && strlen($qw) >= 3 && str_contains($nw, $qw)) { $best = 1; continue; }
                    if ($best === 0 && self::isTypo($qw, $nw)) $best = -1;
                }
                if ($best === 0) continue 2;
                if ($best < 0) { $fuzzy = true; $best = 1; }
                $score += $best * 10;
                foreach ($row['starts'] as $start) {
                    if (str_starts_with($start, $qw)) { $score += 30; break; }
                }
            }
            if ($row['key'] === $query) $score += 1000;
            elseif (str_starts_with($row['key'], $query)) $score += 500;
            if (str_starts_with($nameWords[0], $words[0])) $score += 200;
            if ($fuzzy) $score -= 600;
            $scored[] = [$score, (int) ($row['popularity'] ?? 0), count($row['starts']), (int) $row['move_count'], $row];
        }
        usort($scored, static fn ($a, $b) =>
            [$b[0], $b[1], $a[2], $a[3], $a[4]['name']] <=> [$a[0], $a[1], $b[2], $b[3], $b[4]['name']]);

        // Several rows can share a name (one opening reached by different move
        // orders: four rows are "Sicilian Defense" — B20, B27 and B50 twice).
        // Suggest each name once, as its best-ranked (most-played) row.
        $limit = max(1, min(50, $limit));
        $byName = [];
        foreach ($scored as [, , , , $row]) {
            unset($row['key'], $row['starts']);
            $byName[$row['name']] ??= $row;
            if (count($byName) === $limit) break;
        }
        return array_values($byName);
    }

    /**
     * One typo (two in words of 8+ letters) between a query word and a name
     * word, or the same against the start of the name word while the query
     * is still being typed. Short words (under 4 letters) never count.
     */
    private static function isTypo(string $qw, string $nw): bool
    {
        $len = strlen($qw);
        if ($len < 4) return false;
        $max = $len >= 8 ? 2 : 1;
        if (abs(strlen($nw) - $len) <= $max && levenshtein($qw, $nw) <= $max) return true;
        return $len >= 5 && strlen($nw) > $len && levenshtein($qw, substr($nw, 0, $len)) <= $max;
    }

    /**
     * Walk the parent_id chain from the given opening up to the root. Returns
     * ancestors in root-first order (e.g. [Sicilian, Sicilian: Open, Najdorf]).
     * Caps at 20 hops to defend against any accidental cycle in data.
     */
    public static function ancestors(int $id): array
    {
        // Single-query CTE walks the parent_id chain from $id up to the root.
        // Replaces what used to be N sequential `WHERE id = ?` round-trips —
        // for a depth-6 opening that's one query instead of seven, shaving
        // 20-30 ms off the opening page on shared hosting. CYCLE-guard at 20
        // hops mirrors the old PHP loop's depth cap.
        $pdo  = chess_codex_db();
        try {
            $stmt = $pdo->prepare(
                "WITH RECURSIVE chain AS (
                    SELECT id, eco, name, slug, parent_id, 0 AS depth_step
                    FROM codex_openings
                    WHERE id = :id
                    UNION ALL
                    SELECT o.id, o.eco, o.name, o.slug, o.parent_id, c.depth_step + 1
                    FROM codex_openings o
                    INNER JOIN chain c ON o.id = c.parent_id
                    WHERE c.depth_step < 20
                )
                SELECT id, eco, name, slug, parent_id
                FROM chain
                WHERE depth_step > 0       -- skip the starting opening itself
                ORDER BY depth_step DESC   -- root first, then deeper"
            );
            $stmt->execute(['id' => $id]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            // Fallback: iterative N+1 in PHP. Only fires on MySQL < 8.0 or
            // if the CTE statement itself errors (cycle, syntax oddity).
            $stmt = $pdo->prepare(
                "SELECT id, eco, name, slug, parent_id FROM codex_openings WHERE id = :id LIMIT 1"
            );
            $chain = []; $seen = []; $cur = $id;
            for ($i = 0; $i < 20; $i++) {
                if (isset($seen[$cur])) break;
                $seen[$cur] = true;
                $stmt->execute(['id' => $cur]);
                $row = $stmt->fetch();
                if (!$row) break;
                $chain[] = $row;
                if (empty($row['parent_id'])) break;
                $cur = (int) $row['parent_id'];
            }
            array_shift($chain);
            return array_reverse($chain);
        }
    }

    /**
     * Daily "opening of the day" — deterministic per calendar day so the
     * featured card on the homepage stays stable through a day's visits
     * (good for caching) while still rotating freshness signal to Google.
     * Picks from openings with a curated description — these render the
     * most polished featured card. Falls back to any shallow root opening
     * if that set is empty.
     */
    public static function ofTheDay(): ?array
    {
        // Seed from today's date so all users get the same opening today,
        // a different one tomorrow.
        $seed = (int) date('Ymd');
        $pdo  = chess_codex_db();

        // Preferred set: openings that have a substantial curated description.
        // Characters, not bytes: CHAR_LENGTH on MySQL; SQLite has no CHAR_LENGTH
        // but its LENGTH already counts characters for text.
        $charLength = chess_codex_db_driver() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';
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

    /**
     * Fuzzy slug-suggester for the 404 page. Returns a few opening rows whose
     * slugs look similar to the failed URL — useful when a visitor lands on
     * /openings/sicilan-defence (typo) and we can suggest /openings/sicilian-defense.
     *
     * Strategy: tokenize the failed slug on hyphens, score every opening by
     * how many tokens match (as substrings or prefixes) against its slug, then
     * fall back to plain LIKE substring matching. Cheap enough to run per 404.
     */
    public static function suggestSimilar(string $failedSlug, int $limit = 5): array
    {
        $clean = preg_replace('/[^a-z0-9-]/', '', strtolower($failedSlug)) ?: '';
        if ($clean === '') return [];
        $tokens = array_values(array_filter(explode('-', $clean), static fn ($t) => strlen($t) >= 3));
        if (count($tokens) === 0) return [];

        $pdo = chess_codex_db();

        // Stage 1: pull a candidate pool — openings whose slug contains the
        // first 4 letters of ANY token, so a typo later in the word
        // ("sicilan", "defence") still reaches the right rows. Tokens are
        // [a-z0-9] only after the clean-up above, so nothing to escape.
        $where = [];
        $params = [];
        foreach (array_slice($tokens, 0, 4) as $i => $t) {
            $where[] = "slug LIKE :t$i";
            $params["t$i"] = '%' . substr($t, 0, 4) . '%';
        }
        $sql = "SELECT id, eco, name, slug, move_count
                FROM codex_openings
                WHERE " . implode(' OR ', $where) . "
                ORDER BY move_count ASC, popularity DESC
                LIMIT 300";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (empty($rows)) return [];

        // Stage 2: score each candidate. Token-prefix matches weigh most,
        // substring matches second, raw Levenshtein-like overlap as tie-break.
        foreach ($rows as &$row) {
            $score = 0;
            $slug  = (string) $row['slug'];
            $words = explode('-', $slug);
            foreach ($tokens as $t) {
                if (strpos($slug, $t) !== false) $score += 4;          // substring
                if (strpos($slug, $t . '-') === 0 || $slug === $t)
                    $score += 3;                                       // exact prefix
                // Bonus: token also appears at a word boundary
                if (strpos('-' . $slug . '-', '-' . $t . '-') !== false) $score += 2;
                // Typo: a slug word within 2 edits of the token
                // ("sicilan" ~ "sicilian", "defence" ~ "defense").
                if (strlen($t) >= 5) {
                    foreach ($words as $w) {
                        if ($w !== $t && abs(strlen($w) - strlen($t)) <= 2 && levenshtein($t, $w) <= 2) {
                            $score += 3;
                            break;
                        }
                    }
                }
            }
            // Penalise wildly different lengths so "sicilian-defense" doesn't
            // win over "sicilian" when query was just "sicilian".
            $row['_score'] = $score - abs(strlen($slug) - strlen($clean)) * 0.05;
        }
        unset($row);

        usort($rows, static fn ($a, $b) => $b['_score'] <=> $a['_score']);
        return array_slice($rows, 0, $limit);
    }

    /**
     * Pick a random opening slug. Used by the /random route as a quick
     * "discover" feature — single redirect, no JS required.
     * Excludes leaf-style depth-0 stubs to keep results interesting.
     */
    public static function randomSlug(): ?string
    {
        $random = chess_codex_db_driver() === 'sqlite' ? 'RANDOM()' : 'RAND()';
        $stmt = chess_codex_db()->query(
            "SELECT slug FROM codex_openings
             WHERE move_count >= 2
             ORDER BY $random
             LIMIT 1"
        );
        $s = $stmt->fetchColumn();
        return $s !== false ? (string) $s : null;
    }

    /**
     * Other openings that share the same parent — sibling variations. Used
     * for the "Related" internal-link section, which gives Google a denser
     * link graph and helps users discover sister lines.
     */
    public static function siblings(int $id, int $parentId, int $limit = 8): array
    {
        if ($parentId <= 0) return []; // root openings have no siblings here
        $stmt = chess_codex_db()->prepare(
            "SELECT id, eco, name, slug, move_count, pgn_moves
             FROM codex_openings
             WHERE parent_id = :pid AND id <> :self
             ORDER BY popularity DESC, name ASC
             LIMIT :lim"
        );
        $stmt->bindValue(':pid',  $parentId, PDO::PARAM_INT);
        $stmt->bindValue(':self', $id,       PDO::PARAM_INT);
        $stmt->bindValue(':lim',  $limit,    PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Cheap COUNT-only descendant lookup. Used to decide between rendering
     * the subtree inline (small) and lazy-loading via /api/subtree (large),
     * and to populate the "Show all N" label without paying for the rows.
     */
    public static function descendantCount(int $parentId): int
    {
        try {
            $sql = "WITH RECURSIVE tree AS (
                        SELECT id FROM codex_openings WHERE parent_id = :pid
                        UNION ALL
                        SELECT o.id FROM codex_openings o
                        INNER JOIN tree t ON o.parent_id = t.id
                    )
                    SELECT COUNT(*) FROM tree";
            $stmt = chess_codex_db()->prepare($sql);
            $stmt->execute(['pid' => $parentId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            // Fallback BFS via descendants() — slower but works on MySQL < 8.0.
            return count(self::descendants($parentId));
        }
    }

    /**
     * Flat list of every descendant of $parentId at any depth. Uses MySQL 8's
     * recursive CTE — falls back to iterative PHP traversal if the CTE fails
     * (very old MySQL on a hypothetical migration target).
     */
    public static function descendants(int $parentId, int $hardLimit = 2000): array
    {
        $pdo = chess_codex_db();
        try {
            $sql = "WITH RECURSIVE tree AS (
                        SELECT id, eco, name, slug, parent_id, depth, move_count
                        FROM codex_openings WHERE parent_id = :pid
                        UNION ALL
                        SELECT o.id, o.eco, o.name, o.slug, o.parent_id, o.depth, o.move_count
                        FROM codex_openings o
                        INNER JOIN tree t ON o.parent_id = t.id
                    )
                    SELECT * FROM tree
                    ORDER BY depth ASC, name ASC
                    LIMIT :lim";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':pid', $parentId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $hardLimit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            // Fallback: BFS in PHP. Slower but works on MySQL < 8.0.
            $out = [];
            $queue = [$parentId];
            $childStmt = $pdo->prepare(
                "SELECT id, eco, name, slug, parent_id, depth, move_count
                 FROM codex_openings WHERE parent_id = :pid ORDER BY name"
            );
            while ($queue && count($out) < $hardLimit) {
                $pid = array_shift($queue);
                $childStmt->execute(['pid' => $pid]);
                foreach ($childStmt->fetchAll() as $row) {
                    $out[] = $row;
                    $queue[] = (int) $row['id'];
                }
            }
            return $out;
        }
    }

    /**
     * Returns *all* openings grouped by the first letter of `name`. Used by the
     * alphabetical browse index. Cached on disk for 6h — data only changes on
     * import. Output shape: ['A' => [row, ...], 'B' => [...], ...]. Rows that
     * share a name + ECO code carry their distinguishing moves in 'tail'
     * (up to 14 rows would otherwise read the same).
     */
    public static function allAlphabetical(): array
    {
        return Cache::remember('alphabetical-tails', 21600, static function (): array {
            $rows = chess_codex_db()->query(
                "SELECT id, eco, name, slug, move_count, pgn_moves
                 FROM codex_openings
                 ORDER BY name ASC, id ASC"
            )->fetchAll();
            $tails = self::lineTails($rows);
            $grouped = [];
            foreach ($rows as $row) {
                $row['tail'] = $tails[(int) $row['id']] ?? '';
                unset($row['pgn_moves']);
                $first = mb_strtoupper(mb_substr((string) $row['name'], 0, 1));
                if (!preg_match('/^[A-Z]$/u', $first)) $first = '#';
                $grouped[$first][] = $row;
            }
            ksort($grouped);
            return $grouped;
        });
    }

    /**
     * The openings (the part of a name before ":" or ",") by first letter:
     * letter => [name => ['name', 'slug', 'eco', 'lines']]. The link goes to
     * the family's own line — its shortest, when several share the name — or
     * to its shortest line when none has the bare name.
     */
    public static function familiesByLetter(): array
    {
        $out = [];
        foreach (self::allAlphabetical() as $letter => $rows) {
            foreach ($rows as $r) {
                $family = self::family((string) $r['name']);
                $isRoot = $r['name'] === $family;
                $f      = $out[$letter][$family] ?? ['name' => $family, 'lines' => 0, 'root' => false, 'plies' => PHP_INT_MAX];
                $f['lines']++;
                if (($isRoot && (!$f['root'] || $r['move_count'] < $f['plies']))
                    || (!$isRoot && !$f['root'] && $r['move_count'] < $f['plies'])) {
                    [$f['slug'], $f['eco'], $f['root'], $f['plies']] = [$r['slug'], $r['eco'], $isRoot, (int) $r['move_count']];
                }
                $out[$letter][$family] = $f;
            }
        }
        return $out;
    }

    /**
     * Up to $limit openings whose canonical PGN starts with $canonQuery
     * followed by at least one more ply — i.e., possible continuations
     * from the player's current position.
     */
    public static function continuationsFrom(string $canonQuery, int $limit = 5): array
    {
        $canonQuery = trim($canonQuery);
        $stmt = chess_codex_db()->prepare(
            $canonQuery === ''
                ? "SELECT id, eco, name, slug, depth, pgn_canon, move_count
                   FROM codex_openings
                   WHERE parent_id IS NULL
                   ORDER BY popularity DESC, id ASC
                   LIMIT :lim"
                : "SELECT id, eco, name, slug, depth, pgn_canon, move_count
                   FROM codex_openings
                   WHERE pgn_canon LIKE :pat
                     AND move_count > :myplies
                   ORDER BY move_count ASC, popularity DESC
                   LIMIT :lim"
        );

        if ($canonQuery === '') {
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':pat', $canonQuery . ' %', PDO::PARAM_STR);
            $stmt->bindValue(':myplies', substr_count($canonQuery, ' ') + 1, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
