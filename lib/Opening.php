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
            'SELECT id, eco, name, slug, depth FROM codex_openings WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Direct children (depth + 1), sorted by name. */
    public static function children(int $parentId): array
    {
        $stmt = chess_codex_db()->prepare(
            'SELECT id, eco, name, slug FROM codex_openings
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
     * Top-N openings by popularity. Cached on disk for 6h.
     *
     * If the popularity column isn't populated (default state — we'd need a
     * separate import pass to fill it), we fall back to a curated list of
     * well-known opening names. That way the homepage strip is never empty,
     * and once you backfill popularity from Lichess stats it auto-switches.
     */
    public static function topPopular(int $limit = 12): array
    {
        $cached = Cache::remember('popular', 21600, static function (): array {
            $stmt = chess_codex_db()->prepare(
                "SELECT id, eco, name, slug, depth, move_count, popularity
                 FROM codex_openings
                 WHERE popularity > 0
                 ORDER BY popularity DESC, move_count ASC, id ASC
                 LIMIT 100"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();
            if (empty($rows)) {
                $rows = self::resolveCuratedNames(self::FAMOUS_OPENINGS, 50);
            }
            return $rows;
        });
        return array_slice($cached, 0, $limit);
    }

    /**
     * Top popular gambits. Same caching pattern as topPopular() — falls back
     * to a curated list of well-known gambit names if popularity is empty.
     */
    public static function topGambits(int $limit = 12): array
    {
        $cached = Cache::remember('gambits', 21600, static function (): array {
            $stmt = chess_codex_db()->prepare(
                "SELECT id, eco, name, slug, depth, move_count, popularity
                 FROM codex_openings
                 WHERE (name LIKE '%Gambit%' OR name LIKE '%Countergambit%')
                   AND popularity > 0
                 ORDER BY popularity DESC, move_count ASC, name ASC
                 LIMIT 100"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();
            if (empty($rows)) {
                $rows = self::resolveCuratedNames(self::FAMOUS_GAMBITS, 50);
            }
            return $rows;
        });
        return array_slice($cached, 0, $limit);
    }

    /**
     * Curated fallback: top "famous" openings everyone has heard of. Used when
     * the `popularity` column isn't filled. Order here = display order.
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

    private const FAMOUS_GAMBITS = [
        "King's Gambit",
        "Queen's Gambit",
        "Queen's Gambit Accepted",
        'Evans Gambit',
        'Smith-Morra Gambit',
        'Latvian Gambit',
        'Budapest Gambit',
        'Albin Countergambit',
        "Englund Gambit",
        'Benko Gambit',
        'Danish Gambit',
        'Goring Gambit',
        'Halloween Gambit',
        'Blackmar-Diemer Gambit',
        'From Gambit',
        'Marshall Gambit',
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
            "SELECT id, eco, name, slug, depth, move_count, popularity
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
     * Name autocomplete. Splits the query into tokens and requires each token
     * to be a substring of the name (LIKE %tok%). Sorted by exact-prefix-first,
     * then popularity. ALSO matches the ECO code when the query looks like one
     * (3 alphanumeric chars like "B20" or "C45") — handy chess-savvy shortcut.
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

        $tokens = array_values(array_filter(preg_split('/\s+/', $q) ?: []));
        if (count($tokens) === 0 || count($tokens) > 6) return [];

        $pdo = chess_codex_db();
        $where = [];
        $params = [];
        foreach ($tokens as $i => $t) {
            $where[] = "name LIKE :t$i";
            $params["t$i"] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $t) . '%';
        }
        // Prefer rows where the first token is a prefix of the whole name.
        $params['prefix'] = str_replace(['%', '_'], ['\\%', '\\_'], $tokens[0]) . '%';

        $sql = "SELECT id, eco, name, slug, depth, move_count,
                       (CASE WHEN name LIKE :prefix THEN 0 ELSE 1 END) AS rank_prefix
                FROM codex_openings
                WHERE " . implode(' AND ', $where) . "
                ORDER BY rank_prefix ASC, popularity DESC, move_count ASC, name ASC
                LIMIT :lim";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
        $stmt->bindValue(':lim', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
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
            "SELECT id, eco, name, slug, move_count, description
             FROM codex_openings
             WHERE description IS NOT NULL
               AND $charLength(description) > 200
             ORDER BY id"
        )->fetchAll();

        if (empty($candidates)) {
            // Fallback: just any shallow root opening (move_count <= 4).
            $candidates = $pdo->query(
                "SELECT id, eco, name, slug, move_count, description
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
            "SELECT id, eco, name, slug, move_count
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
     * import. Output shape: ['A' => [row, ...], 'B' => [...], ...].
     */
    public static function allAlphabetical(): array
    {
        return Cache::remember('alphabetical', 21600, static function (): array {
            $stmt = chess_codex_db()->query(
                "SELECT id, eco, name, slug, move_count
                 FROM codex_openings
                 ORDER BY name ASC, id ASC"
            );
            $grouped = [];
            foreach ($stmt as $row) {
                $first = mb_strtoupper(mb_substr((string) $row['name'], 0, 1));
                if (!preg_match('/^[A-Z]$/u', $first)) $first = '#';
                $grouped[$first][] = $row;
            }
            ksort($grouped);
            return $grouped;
        });
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
