<?php
declare(strict_types=1);

/**
 * Tiny per-IP rate limiter backed by JSON state in the system temp dir.
 * Sliding 60-second window, allow N requests per minute per IP.
 *
 * Not as robust as Redis or memcached but works on shared hosting with no
 * extras, and is more than sufficient to deter casual abuse of /api/* —
 * the things we're protecting (Lichess token quota, search query cost) are
 * cheap-but-non-zero.
 */
final class RateLimit
{
    private const WINDOW_SECONDS = 60;

    /**
     * Returns true if the request is allowed, false if rate-limited.
     * Caller decides how to respond on false (typically HTTP 429).
     */
    public static function check(string $bucket, int $maxPerMinute, ?string $ip = null): bool
    {
        $ip = $ip ?? self::clientIp();
        $key = preg_replace('/[^a-zA-Z0-9_-]/', '_', $bucket . '_' . $ip);
        $path = sys_get_temp_dir() . '/codex_ratelimit_' . $key . '.json';

        $now = time();
        $fh = @fopen($path, 'c+');
        if ($fh === false) return true; // tmp unavailable — fail open

        try {
            if (!flock($fh, LOCK_EX)) return true;
            $raw = stream_get_contents($fh);
            $hits = $raw ? json_decode($raw, true) : [];
            if (!is_array($hits)) $hits = [];

            // Drop entries outside the window.
            $cutoff = $now - self::WINDOW_SECONDS;
            $hits = array_values(array_filter($hits, fn ($t) => $t >= $cutoff));

            if (count($hits) >= $maxPerMinute) {
                flock($fh, LOCK_UN);
                return false;
            }
            $hits[] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($hits));
            fflush($fh);
            flock($fh, LOCK_UN);
            return true;
        } finally {
            fclose($fh);
        }
    }

    private static function clientIp(): string
    {
        // Prefer X-Forwarded-For only if behind a known proxy. On OVH shared
        // hosting REMOTE_ADDR is the real client.
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
