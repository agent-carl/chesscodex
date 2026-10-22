<?php
declare(strict_types=1);

/**
 * Opening rows by slug, id and ECO code, and their moves in UCI.
 */
class OpeningRows
{
    /**
     * @return array<string, mixed>|null
     */
    public static function findBySlug(string $slug): ?array
    {
        $stmt = chess_codex_db()->prepare(
            'SELECT id, eco, name, slug, parent_id, depth, fen, pgn_moves, uci, move_count, popularity, description
             FROM codex_openings WHERE slug = :slug LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
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
     * The opening's moves in UCI (['e2e4', 'c7c5', 'g1f3']), or null for an unknown id.
     *
     * @return list<string>|null
     */
    public static function uciById(int $id): ?array
    {
        $stmt = chess_codex_db()->prepare('SELECT uci FROM codex_openings WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $uci = $stmt->fetchColumn();
        return $uci === false ? null : self::uci(['uci' => $uci]);
    }

    /**
     * A row's moves in UCI as a list, from its uci column ("e2e4 c7c5 g1f3",
     * written by tools/seed.php) — the key of its Lichess statistics.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public static function uci(array $row): array
    {
        $uci = trim((string) ($row['uci'] ?? ''));
        return $uci === '' ? [] : explode(' ', $uci);
    }

    /**
     * All lines filed under one ECO code, shortest first, with their cached Lichess game counts.
     *
     * @return list<array<string, mixed>>
     */
    public static function byEco(string $eco): array
    {
        $stmt = chess_codex_db()->prepare(
            'SELECT id, eco, name, slug, pgn_moves, uci, move_count, popularity FROM codex_openings
             WHERE eco = :e ORDER BY move_count, name, id'
        );
        $stmt->execute(['e' => $eco]);
        return $stmt->fetchAll();
    }

    /**
     * Rows (slug, name, eco, pgn_moves, popularity) for up to 200 slugs, in the order given.
     *
     * @param array<mixed> $slugs
     * @return list<array<string, mixed>>
     */
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

    /**
     * Pick a random opening slug. Used by the /random route as a quick
     * "discover" feature — single redirect, no JS required.
     * Excludes leaf-style depth-0 stubs to keep results interesting.
     */
    public static function randomSlug(): ?string
    {
        $random = 'RANDOM()';
        $stmt = chess_codex_db()->query(
            "SELECT slug FROM codex_openings
             WHERE move_count >= 2
             ORDER BY $random
             LIMIT 1"
        );
        $s = $stmt->fetchColumn();
        return $s !== false ? (string) $s : null;
    }
}
