<?php
declare(strict_types=1);

/**
 * Which opening pages search engines may index.
 */
class Indexing
{
    /**
     * Lines without a description are indexed only among this many most
     * played (the sitemap's top and mid parts). The rest have no text of
     * their own and are noindex since October 2026, when Google rated the
     * whole site down; db/indexed-rest.tsv keeps the few that still earned
     * clicks or top-10 impressions, or go by another name.
     */
    public const INDEXED_LINES = 1500;

    /** Whether a line's page is indexed; $rank among the lines without a description (see lineRank). */
    public static function indexedLine(bool $described, int $rank, string $slug): bool
    {
        static $keep = null;
        if ($keep === null) {
            $keep = [];
            foreach (array_slice(file(__DIR__ . '/../db/indexed-rest.tsv', FILE_IGNORE_NEW_LINES) ?: [], 1) as $line) {
                $keep[explode("\t", $line)[0]] = true;
            }
        }
        return $described || $rank <= self::INDEXED_LINES || isset($keep[$slug]);
    }

    /**
     * A line's place among those without a description, in the sitemap's order (most played first).
     *
     * @param array<string, mixed> $o
     */
    public static function lineRank(array $o): int
    {
        $stmt = chess_codex_db()->prepare(
            "SELECT COUNT(*) FROM codex_openings WHERE (description IS NULL OR description = '')
               AND (popularity > :p OR (popularity = :p AND (move_count < :m OR (move_count = :m AND id < :id))))"
        );
        $stmt->execute(['p' => (int) $o['popularity'], 'm' => (int) $o['move_count'], 'id' => (int) $o['id']]);
        return (int) $stmt->fetchColumn() + 1;
    }
}
