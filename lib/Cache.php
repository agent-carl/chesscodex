<?php
declare(strict_types=1);

/**
 * Disk-backed key/value cache for computed lookups (counts, popular lists,
 * sitemap XML, OG images, etc). All entries live under db/cache/<key>.json
 * or db/cache/<key>.<ext>. Writes are atomic via temp-file + rename so a
 * crash mid-write never produces a half-baked cache.
 *
 * `remember()` is the main API:
 *
 *   $rows = Cache::remember('popular', 6 * 3600, fn() => Opening::queryPopular());
 *
 * On hit it returns cached data; on miss it calls $compute, stores the
 * result, and returns it. Empty arrays/strings are NOT cached — that's a
 * safety net against accidentally serving "" or "[]" after a transient
 * DB failure.
 */
final class Cache
{
    private static function dir(): string
    {
        $d = __DIR__ . '/../db/cache';
        if (!is_dir($d)) @mkdir($d, 0755, true);
        return $d;
    }

    private static function path(string $key, string $ext = 'json'): string
    {
        $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $key);
        return self::dir() . '/' . $safe . '.' . $ext;
    }

    /**
     * Get-or-set: return cached value if fresh, else compute, store, return.
     * $ttl is in seconds. $compute MUST return an array (callers can wrap
     * scalars in [v => x] if they need to cache primitives).
     */
    public static function remember(string $key, int $ttl, callable $compute): array
    {
        $cached = self::get($key, $ttl);
        if ($cached !== null) return $cached;
        $fresh = $compute();
        if (!is_array($fresh)) {
            throw new InvalidArgumentException('Cache::remember compute must return array');
        }
        if (!empty($fresh)) self::set($key, $fresh);
        return $fresh;
    }

    /**
     * Raw fetch. Returns null on miss / stale / empty / parse error.
     */
    public static function get(string $key, int $ttl): ?array
    {
        $path = self::path($key);
        if (!is_file($path)) return null;
        if ((time() - filemtime($path)) >= $ttl) return null;
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') return null;
        $data = json_decode($raw, true);
        if (!is_array($data) || count($data) === 0) return null;
        return $data;
    }

    public static function set(string $key, array $value): void
    {
        $path = self::path($key);
        $tmp  = $path . '.tmp';
        if (@file_put_contents($tmp, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, $path);
        }
    }

    public static function forget(string $key): void
    {
        @unlink(self::path($key));
    }
}
