<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    #[TestDox('dispatch: exact string, regex with captured groups, first match wins')]
    public function testDispatch(): void
    {
        $log = [];
        $r = new Router();
        $r->add('/search', static function () use (&$log) { $log[] = 'search'; });
        $r->add('#^/openings/([a-z0-9-]+)/?$#', static function (string $slug) use (&$log) { $log[] = 'opening:' . $slug; });
        $r->add('#^/openings/.*#', static function () use (&$log) { $log[] = 'shadowed'; });
        $r->setNotFound(static function () use (&$log) { $log[] = '404'; });

        $r->dispatch('/search');
        $r->dispatch('/openings/italian-game');
        $r->dispatch('/openings/italian-game/');
        $r->dispatch('/openings/Bad Slug');
        $r->dispatch('/nothing');
        $this->assertSame(['search', 'opening:italian-game', 'opening:italian-game', 'shadowed', '404'], $log);
    }

    #[TestDox('dispatch: an exact route is not a prefix match; a regex route gets only the groups')]
    public function testExactIsExact(): void
    {
        $got = [];
        $r = new Router();
        $r->add('/a', static function () use (&$got) { $got[] = 'a'; });
        $r->add('#^/(\d+)/(\d+)$#', static function (string $x, string $y) use (&$got) { $got[] = $x . '+' . $y; });
        $r->dispatch('/a/');
        $r->dispatch('/ab');
        $r->dispatch('/12/34');
        $this->assertSame(['12+34'], $got);
    }

    #[TestDox('dispatch: no route and no not-found handler does nothing')]
    public function testNoNotFound(): void
    {
        $r = new Router();
        $r->dispatch('/x');
        $this->assertFalse($r->matches('/x'));
    }

    #[TestDox('matches: not-found does not count as a route')]
    public function testMatchesIgnoresNotFound(): void
    {
        $r = new Router();
        $r->setNotFound(static function () {});
        $this->assertFalse($r->matches('/anything'));
    }
}
