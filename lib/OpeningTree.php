<?php
declare(strict_types=1);

/**
 * Where a line sits among the others: its parent chain, children,
 * sister lines, the lines that continue it and its transpositions.
 */
class OpeningTree
{
    /**
     * Named lines one move away from this one by a different move order
     * (db/transpositions.json, from tools/build-transpositions.php):
     * 'from' — lines whose position plus a move gives this one; 'to' — lines
     * a move from this position leads into. Each item: [row, SAN], the row
     * with id, eco, name, slug, move_count.
     *
     * @return array{from: list<array{0: array<string, mixed>, 1: string}>, to: list<array{0: array<string, mixed>, 1: string}>}
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

    /**
     * The line and every named line that continues it (slug, name, eco,
     * pgn_moves, popularity), shortest first — what a PGN download of the
     * tree holds, and what the trainer drills.
     *
     * @param array<string, mixed> $opening
     * @return list<array<string, mixed>>
     */
    public static function withContinuations(array $opening): array
    {
        $canon = chess_codex_canonicalize_pgn((string) $opening['pgn_moves']);
        $stmt = chess_codex_db()->prepare(
            "SELECT slug, name, eco, pgn_moves, popularity FROM codex_openings
             WHERE pgn_canon = :c OR (pgn_canon >= :lo AND pgn_canon < :hi) ORDER BY move_count, id"
        );
        // "Starts with the line and a space" as a range (' ' < '!'): the
        // index on pgn_canon answers it, no LIKE over every row.
        $stmt->execute(['c' => $canon, 'lo' => $canon . ' ', 'hi' => $canon . '!']);
        return $stmt->fetchAll();
    }

    /**
     * How many lines withContinuations() gives for this line, itself included.
     *
     * @param array<string, mixed> $opening
     */
    public static function continuationCount(array $opening): int
    {
        $canon = chess_codex_canonicalize_pgn((string) $opening['pgn_moves']);
        $stmt = chess_codex_db()->prepare(
            'SELECT COUNT(*) FROM codex_openings WHERE pgn_canon = :c OR (pgn_canon >= :lo AND pgn_canon < :hi)'
        );
        $stmt->execute(['c' => $canon, 'lo' => $canon . ' ', 'hi' => $canon . '!']);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Direct children (depth + 1), sorted by name.
     *
     * @return list<array<string, mixed>>
     */
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
     * Walk the parent_id chain from the given opening up to the root. Returns
     * ancestors in root-first order (e.g. [Sicilian, Sicilian: Open, Najdorf]).
     * Caps at 20 hops to defend against any accidental cycle in data.
     *
     * @return list<array<string, mixed>>
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
     * Other openings that share the same parent — sibling variations. Used
     * for the "Related" internal-link section, which gives Google a denser
     * link graph and helps users discover sister lines.
     *
     * @return list<array<string, mixed>>
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
     *
     * @return list<array<string, mixed>>
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
     * Up to $limit openings whose canonical PGN starts with $canonQuery
     * followed by at least one more ply — i.e., possible continuations
     * from the player's current position.
     *
     * @return list<array<string, mixed>>
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
                   WHERE pgn_canon >= :lo AND pgn_canon < :hi
                     AND move_count > :myplies
                   ORDER BY move_count ASC, popularity DESC
                   LIMIT :lim"
        );

        if ($canonQuery === '') {
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':lo', $canonQuery . ' ', PDO::PARAM_STR);   // a range, as in withContinuations()
            $stmt->bindValue(':hi', $canonQuery . '!', PDO::PARAM_STR);
            $stmt->bindValue(':myplies', substr_count($canonQuery, ' ') + 1, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
