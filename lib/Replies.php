<?php
declare(strict_types=1);

/**
 * How the side to move answers a line, from the moves Lichess players chose
 * next in its position (StatsCache: rated games between players rated 1600
 * to 2500). Opening pages turn it into "How to play against …".
 */
final class Replies
{
    /** A main reply has this share of the position's games, and Opening::FEW_GAMES or more. */
    public const MIN_SHARE = 0.05;

    /**
     * The main replies, most played first, each with san, games and the
     * percentages for the side to move: win, loss and score (wins plus half
     * the draws). Empty when fewer than two qualify: one reply is no choice.
     */
    public static function main(array $topMoves, int $total, bool $whiteToMove): array
    {
        $rows = [];
        foreach ($topMoves as $m) {
            $games = (int) $m['white'] + (int) $m['draws'] + (int) $m['black'];
            if ($total <= 0 || $games < Opening::FEW_GAMES || $games < self::MIN_SHARE * $total) continue;
            [$win, $loss] = $whiteToMove ? [(int) $m['white'], (int) $m['black']] : [(int) $m['black'], (int) $m['white']];
            $rows[] = [
                'san'   => (string) $m['san'],
                'games' => $games,
                'win'   => $win * 100 / $games,
                'loss'  => $loss * 100 / $games,
                'score' => ($win + (int) $m['draws'] / 2) * 100 / $games,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['games'] <=> $a['games']);
        return count($rows) >= 2 ? $rows : [];
    }

    /** [most played, highest score, lowest score] of main()'s rows; a tie goes to the more played reply. */
    public static function picks(array $main): array
    {
        [$best, $worst] = [$main[0], $main[0]];
        foreach ($main as $r) {
            if ($r['score'] > $best['score']) $best = $r;
            if ($r['score'] < $worst['score']) $worst = $r;
        }
        return [$main[0], $best, $worst];
    }

    /**
     * The paragraph under "How to play against …", as HTML: the reply that
     * scores best for the side to move, the most played one and the one that
     * scores lowest, then Stockfish's first choice ($engineSan). $moveNo is
     * "3…" or "4. "; $lineOf(san) gives the named line a reply leads to as
     * [name, url], or null.
     */
    public static function html(array $main, bool $whiteToMove, string $moveNo, callable $lineOf, ?string $engineSan): string
    {
        [$most, $best, $worst] = self::picks($main);
        [$side, $other] = $whiteToMove ? ['White', 'Black'] : ['Black', 'White'];
        $h     = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $move  = static fn (array $r): string => '<strong>' . $h($moveNo . $r['san']) . '</strong>';
        $pc    = static fn (float $p): string => number_format($p, 1) . '%';
        $games = static fn (array $r): string => Rankings::compact((int) $r['games']) . ' games';

        $line  = $lineOf($best['san']);
        $named = $line ? ' (<a href="' . $h($line[1]) . '">' . $h($line[0]) . '</a>)' : '';
        $result = sprintf('%s, with %s winning %s and %s %s of %s', $pc($best['score']), $side, $pc($best['win']), $other, $pc($best['loss']), $games($best));

        if ($best['san'] === $most['san']) {
            $text = sprintf('The most played answer, %s%s, also scores best for %s: %s.', $move($best), $named, $side, $result);
            if ($worst['san'] !== $best['san']) {
                $text .= sprintf(' Of the main answers, %s scores lowest: %s.', $move($worst), $pc($worst['score']));
            }
        } else {
            $text = sprintf('%s%s scores best for %s: %s.', $move($best), $named, $side, $result);
            $text .= $worst['san'] === $most['san']
                ? sprintf(' The most played answer, %s, scores lowest: %s in %s.', $move($most), $pc($most['score']), $games($most))
                : sprintf(' The most played, %s, scores %s in %s, and %s scores lowest: %s.',
                    $move($most), $pc($most['score']), $games($most), $move($worst), $pc($worst['score']));
        }
        if ($engineSan !== null && $engineSan !== '') {
            $text .= $engineSan === $best['san']
                ? sprintf(' Stockfish\'s first choice is also %s.', $move($best))
                : sprintf(' Stockfish\'s first choice is %s.', '<strong>' . $h($moveNo . $engineSan) . '</strong>');
        }
        return $text;
    }
}
