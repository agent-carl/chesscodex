<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    #[TestDox('remember() computes on a miss and serves the cache on a hit; forget() drops the entry')]
    public function testRememberAndForget(): void
    {
        $key = 'test_unit_' . bin2hex(random_bytes(4));
        Cache::forget($key);
        $this->assertSame(['v' => 1], Cache::remember($key, 60, fn (): array => ['v' => 1]));
        $this->assertSame(['v' => 1], Cache::remember($key, 60, fn (): array => ['v' => 2]), 'served from the cache');
        Cache::forget($key);
        $this->assertSame(['v' => 3], Cache::remember($key, 60, fn (): array => ['v' => 3]), 'computed again after forget()');
        Cache::forget($key);
    }

    #[TestDox('empty results are not cached')]
    public function testEmptyResultsAreNotCached(): void
    {
        $key = 'test_unit_' . bin2hex(random_bytes(4));
        Cache::remember($key, 60, fn (): array => []);
        $this->assertNull(Cache::get($key, 60));
    }
}
