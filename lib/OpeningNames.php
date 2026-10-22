<?php
declare(strict_types=1);

/**
 * What to call a line in text: the moves that tell same-name lines
 * apart, its family, other names, the article it takes, its moves numbered.
 */
class OpeningNames
{
    /**
     * Lichess gives several distinct move orders the same name (14 lines are
     * all "Italian Game: Classical Variation, Giuoco Pianissimo", C54; seven
     * are "Caro-Kann Defense", under B10, B12 and B15). For those, returns
     * the shortest run of final moves that no other line with that name ends
     * with, e.g. "6…a6 7. Re1", so titles and descriptions can tell the pages
     * apart. '' when the name is unique, and for the name's main line — the
     * shortest, then the most played, as in the breadcrumb — whose page is
     * the one people mean by the name: "Caro-Kann Defense" is 1. e4 c6.
     *
     * @param array<string, mixed> $opening
     */
    public static function distinguishingTail(array $opening): string
    {
        $group = self::sameName((string) $opening['name']);
        if (count($group) < 2 || (int) $group[0]['id'] === (int) $opening['id']) return '';
        $others = [];
        foreach ($group as $r) {
            if ((int) $r['id'] !== (int) $opening['id']) $others[] = (string) $r['pgn_moves'];
        }
        return self::tailAgainst((string) $opening['pgn_moves'], $others);
    }

    /** How many named lines carry this exact name: 1 when it is unique. */
    public static function nameCount(string $name): int
    {
        return count(self::sameName($name));
    }

    /**
     * Every line with this exact name, its main line (see distinguishingTail()) first.
     *
     * @return list<array<string, mixed>>
     */
    private static function sameName(string $name): array
    {
        static $memo = [];
        if (!isset($memo[$name])) {
            $stmt = chess_codex_db()->prepare(
                'SELECT id, pgn_moves FROM codex_openings WHERE name = :n
                 ORDER BY move_count ASC, popularity DESC, id ASC'
            );
            $stmt->execute(['n' => $name]);
            $memo[$name] = $stmt->fetchAll();
        }
        return $memo[$name];
    }

    /**
     * The side whose choice a line's name records: the side that made its
     * last move — except that a name whose last part is a Defense or a
     * Countergambit is Black's even where Lichess files it after White's
     * reply ("King's Indian Defense" is 1. d4 Nf6 2. c4 g6 3. Nc3).
     */
    public static function nameSide(string $name, int $plies): string
    {
        $last = trim((string) preg_replace('/^.*[:,]/', '', $name));
        if (preg_match('/(Defen[cs]e|Countergambit)$/', $last)) return 'black';
        return $plies % 2 === 1 ? 'white' : 'black';
    }

    /**
     * The article an opening's name takes in a sentence: "a variation of the
     * Caro-Kann Defense", but "of Petrov's Defense" — names that open with a
     * person's possessive take none (King's, Queen's and Bishop's do).
     */
    public static function article(string $name): string
    {
        return preg_match("/^(?!(?:King|Queen|Bishop|Knight)'s )\\S+'s /", $name) ? '' : 'the ';
    }

    /**
     * distinguishingTail() for many rows at once, without a query per row:
     * id => tail for each row (id, name, eco, pgn_moves) that shares its name
     * and ECO code with another row of $rows — or only its name, with
     * $ignoreEco, for lists where two "Benko Gambit" entries would otherwise
     * differ only by their ECO tag.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, string>
     */
    public static function lineTails(array $rows, bool $ignoreEco = false): array
    {
        $groups = [];
        foreach ($rows as $r) $groups[$r['name'] . ($ignoreEco ? '' : "\n" . $r['eco'])][(int) $r['id']] = (string) $r['pgn_moves'];
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

    /**
     * The shortest numbered ending of $pgn that none of $otherPgns ends with.
     *
     * @param list<string> $otherPgns
     */
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

    /** "1. e4 e5 2. Nf3" with a no-break space after each move number, so a wrapped line never ends in "2." */
    public static function keepNumbers(string $moves): string
    {
        return (string) preg_replace('/(\d+\.) /', "\$1\u{00A0}", $moves);
    }

    /** "Sicilian Defense: Najdorf Variation" → "Sicilian Defense". */
    public static function family(string $name): string
    {
        return trim(preg_split('/[:,]/', $name, 2)[0] ?? '');
    }

    /**
     * Other names a line goes by (db/aliases.tsv: "Spanish Opening" for the
     * Ruy Lopez, "Stonewall Dutch"), each checked against the line's
     * Wikipedia article. [] for most lines.
     *
     * @return list<string>
     */
    public static function aliases(string $name): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            $fh = @fopen(__DIR__ . '/../db/aliases.tsv', 'r');
            if ($fh) {
                fgetcsv($fh, null, "\t", '"', '');   // header
                while (($row = fgetcsv($fh, null, "\t", '"', '')) !== false) {
                    if (count($row) >= 2 && $row[0] !== '') $map[$row[0]][] = trim((string) $row[1]);
                }
                fclose($fh);
            }
        }
        return $map[$name] ?? [];
    }

    /**
     * The first alias with a word the name lacks ("Spanish Opening" for the
     * Ruy Lopez, not "Najdorf Sicilian"), for the page title; null if none.
     */
    public static function titleAlias(string $name): ?string
    {
        $words = explode(' ', self::nameKey($name));
        foreach (self::aliases($name) as $alias) {
            if (array_diff(explode(' ', self::nameKey($alias)), $words)) return $alias;
        }
        return null;
    }

    /**
     * What to call an ECO code, from the names of its lines (shortest first):
     * the variation at least 60% of them belong to, when they are all one
     * opening ("Sicilian Defense: Najdorf Variation" for B90, not just
     * "Sicilian Defense"); otherwise the opening, or "X & Y" / "X, Y & more"
     * when the code mixes several.
     *
     * @param list<string> $names
     */
    public static function ecoLabel(array $names): string
    {
        $families = $variations = [];
        foreach ($names as $name) {
            $family = self::family((string) $name);
            $families[$family] = ($families[$family] ?? 0) + 1;
            if (str_contains((string) $name, ':')) {
                $variation = trim(explode(',', (string) $name, 2)[0]);
                $variations[$variation] = ($variations[$variation] ?? 0) + 1;
            }
        }
        arsort($families);   // stable: ties keep shortest-line order
        $top = array_keys($families);
        if (count($top) === 1) {
            arsort($variations);
            $variation = array_key_first($variations);
            return $variation !== null && $variations[$variation] >= 0.6 * count($names) ? $variation : $top[0];
        }
        return count($top) === 2 ? $top[0] . ' & ' . $top[1] : $top[0] . ', ' . $top[1] . ' & more';
    }

    /**
     * The first sentence of a description's opening paragraph, as plain text
     * (links and emphasis unwrapped), for the meta description. Null when
     * the description is empty or starts with a heading.
     */
    public static function leadSentence(string $markdown): ?string
    {
        $para = trim(explode("\n\n", trim($markdown), 2)[0]);
        if ($para === '' || str_starts_with($para, '#')) return null;
        $para = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $para);
        $para = (string) preg_replace('/\*{1,2}([^*]+)\*{1,2}/', '$1', $para);
        $para = trim((string) preg_replace('/\s+/', ' ', $para));
        // A sentence ends at ". " before a capital, a quote or a bracket;
        // "1.e4" and "5...a6" have no space after the dot.
        return preg_match('/^(.+?[.!?])(?=\s+["“(A-Z]|$)/u', $para, $m) ? $m[1] : $para;
    }

    /**
     * "1. e4 c5 2. Nf3" → ['e4', 'c5', 'Nf3'] (check marks kept).
     *
     * @return list<string>
     */
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

    /**
     * Numbered SAN starting at 0-based ply $firstPly: "6…a6 7. Re1".
     *
     * @param array<int, string> $sans
     */
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
}
