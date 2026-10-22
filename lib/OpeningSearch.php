<?php
declare(strict_types=1);

/**
 * Finding lines: by moves, by position, by name (the autocomplete), and
 * the suggestions on the 404 page.
 */
class OpeningSearch
{
    /**
     * Find the deepest opening whose canonical PGN equals the query, or is
     * a strict prefix of it (closest known ancestor when the query went past
     * documented theory). Returns null if no opening matches.
     *
     * @return array<string, mixed>|null
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
            "SELECT id, eco, name, slug, depth, pgn_canon, pgn_moves, move_count
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
     *
     * @return list<array<string, mixed>>
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
             WHERE fen >= :lo AND fen < :hi
             ORDER BY move_count ASC, id ASC
             LIMIT 50"
        );
        // $core ends with a space, so "starts with $core" is the range up to
        // the same text ending in "!" — answered by the index on fen.
        $stmt->bindValue(':lo', $core, PDO::PARAM_STR);
        $stmt->bindValue(':hi', substr($core, 0, -1) . '!', PDO::PARAM_STR);
        $stmt->execute();
        // The range compares bytes, so this is exact already; kept as a guard.
        return array_values(array_filter(
            $stmt->fetchAll(),
            static fn ($r) => strncmp((string) $r['fen'], $core, strlen($core)) === 0
        ));
    }

    /**
     * Common short names and spellings people type, in OpeningNames::nameKey() form.
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
     * Name autocomplete. Every word of the query has to match a word of the
     * name — as the whole word, its beginning, a part of it, or with one typo
     * (two in long words); typo matches rank below the rest. A whole-name
     * match comes first, then names that start with the query, then names
     * where a query word opens a part of the name ("sicilian dragon" → the
     * Dragon Variation before the Hyperaccelerated Dragon), then popularity.
     * Each name appears once. ALSO matches the ECO code when the query looks
     * like one ("B20", "C45").
     *
     * @return list<array<string, mixed>>
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

        $rows = Cache::remember('name-index-v2', 21600, static function (): array {
            $stmt = chess_codex_db()->query(
                "SELECT id, eco, name, slug, depth, move_count, popularity FROM codex_openings"
            );
            return array_map([self::class, 'indexRow'], $stmt->fetchAll());
        });
        return self::rankByName($rows, $q, $limit);
    }

    /**
     * Queries whose best answer is not the line that bears the name. The
     * dataset's bare "London System" is the King's Indian setup (A48); the
     * London most players mean, and play most, is 1.d4 d5 2.Nf3 Nf6 3.Bf4.
     * Query key → nameKey of the name to put first.
     */
    private const NAME_PREFERRED = [
        'london'        => 'queens pawn game london system',
        'london system' => 'queens pawn game london system',
    ];

    /**
     * Adds what rankByName() matches on: 'key' (nameKey of the name),
     * 'starts', the first word of each part of the name ("Sicilian Defense",
     * "Dragon Variation", "Yugoslav Attack"), which gets a bonus, and
     * 'aliases', the nameKeys of its other names (OpeningNames::aliases()).
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function indexRow(array $r): array
    {
        $starts = [];
        foreach (preg_split('/[:,]/', (string) $r['name']) ?: [] as $part) {
            $w = explode(' ', OpeningNames::nameKey($part))[0];
            if ($w !== '') $starts[] = $w;
        }
        $r['key']     = OpeningNames::nameKey((string) $r['name']);
        $r['starts']  = $starts;
        $r['aliases'] = array_map([OpeningNames::class, 'nameKey'], OpeningNames::aliases((string) $r['name']));
        return $r;
    }

    /**
     * The matching and ordering behind searchByName(), on rows prepared by
     * indexRow(). Separate so the tests can run it without a database.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function rankByName(array $rows, string $q, int $limit = 10): array
    {
        $words = [];
        foreach (explode(' ', OpeningNames::nameKey($q)) as $w) {
            if ($w === '') continue;
            array_push($words, ...explode(' ', self::NAME_ALIASES[$w] ?? $w));
        }
        if (count($words) === 0 || count($words) > 8) return [];
        $query = implode(' ', $words);

        $scored = [];
        foreach ($rows as $row) {
            $aliases   = $row['aliases'] ?? [];
            // An alias's words count as the name's ("russian game" finds Petrov's Defense).
            $nameWords = array_merge(explode(' ', $row['key']), ...array_map(static fn (string $a): array => explode(' ', $a), $aliases));
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
            if ($row['key'] === $query || in_array($query, $aliases, true)) $score += 1000;
            elseif (str_starts_with($row['key'], $query)
                || array_filter($aliases, static fn (string $a): bool => str_starts_with($a, $query))) $score += 500;
            if (str_starts_with($nameWords[0], $words[0])) $score += 200;
            if ((self::NAME_PREFERRED[$query] ?? null) === $row['key']) $score += 1500;
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
            unset($row['key'], $row['starts'], $row['aliases']);
            $byName[$row['name']] ??= $row;
            if (count($byName) === $limit) break;
        }
        return array_values($byName);
    }

    /**
     * One typo (two in words of 8+ letters) between a query word and a name
     * word, or the same against the start of the name word while the query
     * is still being typed. Two swapped neighbouring letters ("indain") are
     * one typo. Short words (under 4 letters) never count.
     */
    private static function isTypo(string $qw, string $nw): bool
    {
        $len = strlen($qw);
        if ($len < 4) return false;
        $max = $len >= 8 ? 2 : 1;
        if (abs(strlen($nw) - $len) <= $max && self::typoDistance($qw, $nw) <= $max) return true;
        return $len >= 5 && strlen($nw) > $len && self::typoDistance($qw, substr($nw, 0, $len)) <= $max;
    }

    /** Levenshtein distance, with one swap of neighbouring letters counted as one edit. */
    private static function typoDistance(string $a, string $b): int
    {
        $d = levenshtein($a, $b);
        if ($d < 2 || strlen($a) !== strlen($b)) return $d;
        $diff = [];
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            if ($a[$i] !== $b[$i]) $diff[] = $i;
        }
        // Exactly one swap: "ai" ↔ "ia" at neighbouring positions.
        if (count($diff) === 2 && $diff[1] === $diff[0] + 1
            && $a[$diff[0]] === $b[$diff[1]] && $a[$diff[1]] === $b[$diff[0]]) return 1;
        // One swap plus one other typo, for long words.
        foreach ($diff as $k => $i) {
            if (!isset($diff[$k + 1]) || $diff[$k + 1] !== $i + 1) continue;
            if ($a[$i] === $b[$i + 1] && $a[$i + 1] === $b[$i]) return min($d, count($diff) - 1);
        }
        return $d;
    }

    /**
     * Fuzzy slug-suggester for the 404 page. Returns a few opening rows whose
     * slugs look similar to the failed URL — useful when a visitor lands on
     * /openings/sicilan-defence (typo) and we can suggest /openings/sicilian-defense.
     *
     * Strategy: tokenize the failed slug on hyphens, score every opening by
     * how many tokens match (as substrings or prefixes) against its slug, then
     * fall back to plain LIKE substring matching. Cheap enough to run per 404.
     *
     * @return list<array<string, mixed>>
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
        $sql = "SELECT id, eco, name, slug, move_count, pgn_moves
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
}
