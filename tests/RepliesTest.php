<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class RepliesTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function m(string $san, int $w, int $d, int $b): array
    {
        return ['san' => $san, 'white' => $w, 'draws' => $d, 'black' => $b];
    }

    #[TestDox('main: no games in the position, no moves, nothing qualifies')]
    public function testMainEmptyInputs(): void
    {
        $this->assertSame([], Replies::main([], 1000, true));
        $this->assertSame([], Replies::main([self::m('e5', 500, 0, 500), self::m('c5', 500, 0, 500)], 0, true), 'zero total');
        $this->assertSame([], Replies::main([self::m('e5', 500, 0, 500), self::m('c5', 500, 0, 500)], -5, true));
    }

    #[TestDox('main: the share cut-off is 5% of the position, the game floor is Opening::FEW_GAMES')]
    public function testMainThresholds(): void
    {
        $few = Opening::FEW_GAMES;
        $total = 100000;
        $edge = (int) (Replies::MIN_SHARE * $total);   // exactly 5%
        $rows = Replies::main([
            self::m('a', $edge, 0, 0),
            self::m('b', $edge - 1, 0, 0),
            self::m('c', 60000, 0, 0),
        ], $total, true);
        $this->assertSame(['c', 'a'], array_column($rows, 'san'));
        // A tiny position: 5% would be below the floor, so the floor decides.
        $small = Replies::main([self::m('a', $few, 0, 0), self::m('b', $few - 1, 0, 0), self::m('c', $few, 0, 0)], $few * 3, true);
        $this->assertSame(['a', 'c'], array_column($small, 'san'));
    }

    #[TestDox('main: sorted by games, win and loss swap with the side to move, score counts half the draws')]
    public function testMainSortAndPercentages(): void
    {
        $top = [self::m('a', 1000, 0, 0), self::m('b', 2000, 2000, 6000)];
        $white = Replies::main($top, 20000, true);
        $this->assertSame(['b', 'a'], array_column($white, 'san'));
        $this->assertEqualsWithDelta(20.0, $white[0]['win'], 1e-9);
        $this->assertEqualsWithDelta(60.0, $white[0]['loss'], 1e-9);
        $this->assertEqualsWithDelta(30.0, $white[0]['score'], 1e-9);
        $black = Replies::main($top, 20000, false);
        $this->assertEqualsWithDelta(60.0, $black[0]['win'], 1e-9);
        $this->assertEqualsWithDelta(20.0, $black[0]['loss'], 1e-9);
        $this->assertEqualsWithDelta(70.0, $black[0]['score'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $black[1]['win'], 1e-9, 'a reply Black never won');
        $this->assertSame(10000, $black[0]['games']);
    }

    #[TestDox('picks: a tie in score goes to the more played reply; all rows equal gives the first three times')]
    public function testPicksTies(): void
    {
        $r = static fn (string $san, int $games, float $score): array => ['san' => $san, 'games' => $games, 'win' => 0.0, 'loss' => 0.0, 'score' => $score];
        $rows = [$r('a', 900, 50.0), $r('b', 500, 50.0), $r('c', 400, 50.0)];
        $this->assertSame(['a', 'a', 'a'], array_column(Replies::picks($rows), 'san'));
        $rows = [$r('a', 900, 50.0), $r('b', 500, 55.0), $r('c', 400, 55.0), $r('d', 300, 40.0), $r('e', 200, 40.0)];
        $this->assertSame(['a', 'b', 'd'], array_column(Replies::picks($rows), 'san'), 'first of equal best, first of equal worst');
    }

    #[TestDox('html: the best reply is also the worst only when all score alike; no engine move, empty engine move')]
    public function testHtmlVariants(): void
    {
        $r = static fn (string $san, int $games, float $score): array => ['san' => $san, 'games' => $games, 'win' => $score, 'loss' => 100 - $score, 'score' => $score];
        $none = static fn (string $san): ?array => null;
        $same = [$r('e5', 5000, 50.0), $r('c5', 3000, 50.0)];
        $html = Replies::html($same, true, '1. ', $none, null);
        $this->assertSame('The most played answer, <strong>1. e5</strong>, also scores best for White: 50.0%, with White winning 50.0% and Black 50.0% of 5,000 games.', $html);
        $this->assertSame($html, Replies::html($same, true, '1. ', $none, ''), 'an empty engine move is no engine move');
    }

    #[TestDox('html: the most played reply scores lowest, best reply is a different one')]
    public function testHtmlMostPlayedIsWorst(): void
    {
        $r = static fn (string $san, int $games, float $score): array => ['san' => $san, 'games' => $games, 'win' => $score, 'loss' => 100 - $score, 'score' => $score];
        $none = static fn (string $san): ?array => null;
        $rows = [$r('e5', 5000, 45.0), $r('c5', 3000, 55.0)];
        $this->assertSame('<strong>3…c5</strong> scores best for Black: 55.0%, with Black winning 55.0% and White 45.0% of 3,000 games.'
            . ' The most played answer, <strong>3…e5</strong>, scores lowest: 45.0% in 5,000 games.',
            Replies::html($rows, false, '3…', $none, null));
        // Three replies: the middle one is the most played.
        $rows = [$r('e5', 5000, 50.0), $r('c5', 3000, 55.0), $r('d5', 2000, 40.0)];
        $this->assertStringContainsString('The most played, <strong>1. e5</strong>, scores 50.0% in 5,000 games, and <strong>1. d5</strong> scores lowest: 40.0%.',
            Replies::html($rows, true, '1. ', $none, null));
    }

    #[TestDox('html: SAN, move number and line name are escaped')]
    public function testHtmlEscapes(): void
    {
        $r = static fn (string $san, int $games, float $score): array => ['san' => $san, 'games' => $games, 'win' => $score, 'loss' => 100 - $score, 'score' => $score];
        $lineOf = static fn (string $san): array => ['Q&A "Gambit"', '/openings/a?b=1&c=2'];
        $html = Replies::html([$r('e5', 5000, 60.0), $r('c5', 3000, 40.0)], true, '1. ', $lineOf, '<script>');
        $this->assertStringContainsString('<a href="/openings/a?b=1&amp;c=2">Q&amp;A &quot;Gambit&quot;</a>', $html);
        $this->assertStringContainsString("Stockfish's first choice is <strong>1. &lt;script&gt;</strong>.", $html);
    }
}
