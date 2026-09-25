<?php
declare(strict_types=1);

/**
 * Parsing helpers for the Lichess ECO TSV dataset.
 * Used by db/seed.php and db/sample_parse.php.
 */

/** Characters seen in the actual TSV. Extend if a future import surfaces more. */
const CHESS_CODEX_TRANSLIT = [
    'á' => 'a', 'ä' => 'a',
    'é' => 'e',
    'ó' => 'o', 'ö' => 'o', 'ø' => 'o',
    'ü' => 'u',
    'ć' => 'c',
    '–' => '-',
];

function chess_codex_slugify(string $name): string
{
    $s = strtr($name, CHESS_CODEX_TRANSLIT);
    // Drop apostrophes (ASCII + curly) so "Anderssen's Opening" → "anderssens-opening"
    // rather than "anderssen-s-opening". Done before the non-alnum replacement.
    $s = str_replace(["'", "\u{2019}", "\u{2018}", '`'], '', $s);
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^a-z0-9]+/u', '-', $s);
    return trim($s, '-');
}

/**
 * Strip move numbers ("1.", "12...") and SAN annotations (!, ?, +, #, !!, !?, ?!)
 * from a PGN string, returning a space-separated list of canonical SAN tokens.
 * Used both for ply counting and for prefix-based parent detection.
 */
function chess_codex_canonicalize_pgn(string $pgn): string
{
    $s = preg_replace('/\d+\.(\.\.)?/', ' ', $pgn);
    $s = preg_replace('/[!?+#]+/', '', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

function chess_codex_count_plies(string $pgn): int
{
    $canon = chess_codex_canonicalize_pgn($pgn);
    if ($canon === '') {
        return 0;
    }
    return count(explode(' ', $canon));
}

/**
 * Given a list of {key => canonical_pgn}, returns {key => parent_key|null}
 * where parent_key is the row whose canonical PGN is the longest STRICT prefix
 * of this row's canonical PGN, restricted to rows in the same ECO group.
 *
 * O(n^2) per group — fine for ~800 rows per group, well under 1s.
 */
function chess_codex_resolve_parents(array $rows): array
{
    $byGroup = [];
    foreach ($rows as $key => $row) {
        $byGroup[$row['eco_group']][$key] = $row;
    }

    $parents = [];
    foreach ($byGroup as $group => $groupRows) {
        foreach ($groupRows as $key => $row) {
            $bestParent = null;
            $bestLen = -1;
            $myCanon = $row['canon'];
            foreach ($groupRows as $otherKey => $other) {
                if ($otherKey === $key) {
                    continue;
                }
                $otherCanon = $other['canon'];
                if ($otherCanon === '' || strlen($otherCanon) >= strlen($myCanon)) {
                    continue;
                }
                if (strncmp($myCanon, $otherCanon, strlen($otherCanon)) !== 0) {
                    continue;
                }
                // require a space boundary so "Nf3" doesn't prefix-match "Nf3 d5"-prefix-of "Nf3xe5"
                if ($myCanon[strlen($otherCanon)] !== ' ') {
                    continue;
                }
                if (strlen($otherCanon) > $bestLen) {
                    $bestLen = strlen($otherCanon);
                    $bestParent = $otherKey;
                }
            }
            $parents[$key] = $bestParent;
        }
    }
    return $parents;
}
