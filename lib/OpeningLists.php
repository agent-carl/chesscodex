<?php
declare(strict_types=1);

/**
 * The browse indexes: ECO codes, lines by letter and by opening, the
 * described lines, the counts per ECO group.
 */
class OpeningLists
{
    /**
     * Every ECO code that has named lines, in code order: code => [
     *   'count' => lines filed under it,
     *   'label' => what they are, see ecoLabel() (A00 has 143 lines from
     *              over a dozen irregular openings: "X, Y & more"),
     *   'slug'  => its shortest line,
     *   'moves' => that line's moves ("1. c4 e5"), which tell apart the
     *              many codes that share a label ("English Opening"),
     * ]. Cached on disk for an hour.
     *
     * @return array<string, array{count: int, label: string, slug: string, moves: string}>
     */
    public static function ecoCodes(): array
    {
        return Cache::remember('eco_codes_v3', 3600, static function (): array {
            $rows = chess_codex_db()->query(
                'SELECT eco, name, slug, pgn_moves FROM codex_openings ORDER BY eco, move_count, id'
            )->fetchAll();
            $codes = [];
            foreach ($rows as $r) {
                $e = (string) $r['eco'];
                $codes[$e]['count'] = ($codes[$e]['count'] ?? 0) + 1;
                $codes[$e]['slug'] ??= (string) $r['slug'];
                $codes[$e]['moves'] ??= trim((string) $r['pgn_moves']);
                $codes[$e]['names'][] = (string) $r['name'];
            }
            foreach ($codes as &$c) {
                $c['label'] = OpeningNames::ecoLabel($c['names']);
                unset($c['names']);
            }
            unset($c);
            return $codes;
        });
    }

    /**
     * The lines with a written description (id, slug, name, eco, fen,
     * pgn_moves, move_count, popularity), most played first: the openings
     * /train and /how-to-play-against list, and whose trainer pages are
     * indexed.
     *
     * @return list<array<string, mixed>>
     */
    public static function described(): array
    {
        return chess_codex_db()->query(
            "SELECT id, slug, name, eco, fen, pgn_moves, uci, move_count, popularity FROM codex_openings
             WHERE description IS NOT NULL AND description <> '' ORDER BY popularity DESC, move_count, id"
        )->fetchAll();
    }

    /**
     * Counts per ECO group (A..E), keyed by group letter.
     * Cached on disk for 1h via the Cache helper.
     *
     * @return array<string, int>
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
     * Returns *all* openings grouped by the first letter of `name`. Used by the
     * alphabetical browse index. Cached on disk for 6h — data only changes on
     * import. Output shape: ['A' => [row, ...], 'B' => [...], ...]. Rows that
     * share a name + ECO code carry their distinguishing moves in 'tail'
     * (up to 14 rows would otherwise read the same).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function allAlphabetical(): array
    {
        return Cache::remember('alphabetical-tails-v2', 21600, static function (): array {
            $rows = chess_codex_db()->query(
                "SELECT id, eco, name, slug, move_count, pgn_moves
                 FROM codex_openings
                 ORDER BY name ASC, id ASC"
            )->fetchAll();
            $tails = OpeningNames::lineTails($rows, true);
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
     *
     * @return array<string, array<string, array{name: string, lines: int, root: bool, plies: int, slug?: mixed, eco?: mixed}>>
     */
    public static function familiesByLetter(): array
    {
        $out = [];
        foreach (self::allAlphabetical() as $letter => $rows) {
            foreach ($rows as $r) {
                $family = OpeningNames::family((string) $r['name']);
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
}
