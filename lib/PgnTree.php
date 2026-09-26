<?php
declare(strict_types=1);

/**
 * Many opening lines as one PGN game: a move tree whose main line at every
 * branch is the most-played continuation, with the others as variations and
 * each named line's name as a comment where it ends. Imports as a single
 * chapter into a Lichess study or ChessBase.
 */
final class PgnTree
{
    /**
     * @param array $lines   rows with name, eco, pgn_moves and popularity (orders the branches)
     * @param array $headers extra tag pairs after the seven required ones, e.g. ['ECO' => 'B90']
     */
    public static function build(array $lines, string $event, string $site, array $headers = []): string
    {
        $root = ['kids' => [], 'weight' => 0, 'names' => []];
        foreach ($lines as $l) {
            $node = &$root;
            foreach (self::sans((string) $l['pgn_moves']) as $san) {
                $node['kids'][$san] ??= ['kids' => [], 'weight' => 0, 'names' => []];
                $node = &$node['kids'][$san];
                $node['weight'] = max($node['weight'], (int) ($l['popularity'] ?? 0));
            }
            $node['names'][] = $l['name'] . ' (' . $l['eco'] . ')';
            unset($node);
        }

        $tags = ['Event' => $event, 'Site' => $site, 'Date' => '????.??.??', 'Round' => '-',
                 'White' => '?', 'Black' => '?', 'Result' => '*'] + $headers;
        $out = '';
        foreach ($tags as $k => $v) {
            $out .= '[' . $k . ' "' . addcslashes((string) $v, '"\\') . "\"]\n";
        }
        return $out . "\n" . wordwrap(trim(self::line($root, 0, false) . ' *'), 80, "\n") . "\n";
    }

    /** Movetext from $node on, the heaviest branch first; $ply 0 = White to move. */
    private static function line(array $node, int $ply, bool $numberBlack): string
    {
        if ($node['kids'] === []) return '';
        $kids = $node['kids'];
        uasort($kids, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
        $sans = array_keys($kids);

        $main = $kids[$sans[0]];
        $out  = self::move((string) $sans[0], $ply, $numberBlack) . self::comment($main);
        foreach (array_slice($sans, 1) as $san) {
            $alt  = $kids[$san];
            $rest = self::line($alt, $ply + 1, $alt['names'] !== []);
            $out .= ' (' . self::move((string) $san, $ply, true) . self::comment($alt)
                  . ($rest !== '' ? ' ' . $rest : '') . ')';
        }
        // After a comment or a variation, Black's next move needs its number again.
        $rest = self::line($main, $ply + 1, $main['names'] !== [] || count($sans) > 1);
        return $out . ($rest !== '' ? ' ' . $rest : '');
    }

    private static function move(string $san, int $ply, bool $numberBlack): string
    {
        $n = intdiv($ply, 2) + 1;
        if ($ply % 2 === 0) return $n . '. ' . $san;
        return ($numberBlack ? $n . '... ' : '') . $san;
    }

    private static function comment(array $node): string
    {
        if ($node['names'] === []) return '';
        return ' { ' . str_replace(['{', '}'], ['(', ')'], implode('; ', $node['names'])) . ' }';
    }

    /** "1. e4 c5 2. Nf3" → ['e4', 'c5', 'Nf3']. */
    private static function sans(string $pgn): array
    {
        $sans = [];
        foreach (preg_split('/\s+/', trim($pgn)) ?: [] as $t) {
            $t = (string) preg_replace('/^\d+\.+/', '', $t);
            if ($t !== '') $sans[] = $t;
        }
        return $sans;
    }
}
