<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Privacy-respectful visit tracker. Aggregates counts per (date, page_type,
 * opening_id) — never stores IPs, user agents, or session identifiers. Bots
 * are filtered out by User-Agent regex; the admin's own visits are skipped
 * by checking the admin session cookie.
 *
 * track() is fire-and-forget: any DB error is swallowed so a hiccup in the
 * stats path never blocks the page render.
 */
final class Views
{
    /** Track a single page view. Cheap: one UPSERT per request. */
    public static function track(string $pageType, ?int $openingId = null): void
    {
        if (self::shouldSkip()) return;

        try {
            $stmt = chess_codex_db()->prepare(
                "INSERT INTO codex_view_log (log_date, page_type, opening_id, views)
                 VALUES (CURDATE(), :pt, :oid, 1)
                 ON DUPLICATE KEY UPDATE views = views + 1"
            );
            $stmt->execute([
                'pt'  => substr($pageType, 0, 20),
                'oid' => $openingId ?? 0,
            ]);
        } catch (Throwable $e) {
            // Stats are nice-to-have. Never crash the page on DB errors.
            error_log('Views::track failed: ' . $e->getMessage());
        }
    }

    /**
     * Total views today (all page types combined).
     */
    public static function todayCount(): int
    {
        try {
            $stmt = chess_codex_db()->query(
                "SELECT COALESCE(SUM(views), 0) FROM codex_view_log WHERE log_date = CURDATE()"
            );
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /** Total views in the last N days. */
    public static function lastNDays(int $days): int
    {
        try {
            $stmt = chess_codex_db()->prepare(
                "SELECT COALESCE(SUM(views), 0) FROM codex_view_log
                 WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL :d DAY)"
            );
            $stmt->bindValue(':d', max(1, $days), PDO::PARAM_INT);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /**
     * Per-day totals for the last N days. Returns [['date' => 'YYYY-MM-DD',
     * 'views' => int], ...] sorted by date ASC. Days with no views are
     * filled with zero so the chart renders cleanly.
     */
    public static function dailyChart(int $days = 30): array
    {
        $out = [];
        try {
            $stmt = chess_codex_db()->prepare(
                "SELECT log_date, SUM(views) AS v
                 FROM codex_view_log
                 WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL :d DAY)
                 GROUP BY log_date
                 ORDER BY log_date ASC"
            );
            $stmt->bindValue(':d', max(1, $days), PDO::PARAM_INT);
            $stmt->execute();
            $byDate = [];
            foreach ($stmt as $r) $byDate[(string) $r['log_date']] = (int) $r['v'];
            // Fill zero-days
            for ($i = $days - 1; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i day"));
                $out[] = ['date' => $d, 'views' => $byDate[$d] ?? 0];
            }
        } catch (Throwable $e) { /* swallow */ }
        return $out;
    }

    /**
     * Most-viewed openings over the past N days. Joins on codex_openings so
     * we get the human-readable name + eco + slug back in one query.
     */
    public static function topOpenings(int $days = 30, int $limit = 10): array
    {
        try {
            $stmt = chess_codex_db()->prepare(
                "SELECT o.id, o.slug, o.name, o.eco, SUM(v.views) AS total
                 FROM codex_view_log v
                 INNER JOIN codex_openings o ON o.id = v.opening_id
                 WHERE v.log_date >= DATE_SUB(CURDATE(), INTERVAL :d DAY)
                   AND v.page_type = 'opening'
                   AND v.opening_id > 0
                 GROUP BY o.id, o.slug, o.name, o.eco
                 ORDER BY total DESC
                 LIMIT :lim"
            );
            $stmt->bindValue(':d',   max(1, $days), PDO::PARAM_INT);
            $stmt->bindValue(':lim', max(1, min(100, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) { return []; }
    }

    /**
     * Page-type breakdown over the last N days. Returns
     * ['home' => 123, 'opening' => 456, ...].
     */
    public static function byPageType(int $days = 30): array
    {
        try {
            $stmt = chess_codex_db()->prepare(
                "SELECT page_type, SUM(views) AS v
                 FROM codex_view_log
                 WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL :d DAY)
                 GROUP BY page_type
                 ORDER BY v DESC"
            );
            $stmt->bindValue(':d', max(1, $days), PDO::PARAM_INT);
            $stmt->execute();
            $out = [];
            foreach ($stmt as $r) $out[(string) $r['page_type']] = (int) $r['v'];
            return $out;
        } catch (Throwable $e) { return []; }
    }

    // ----------------------------------------------------------------------
    // Internal: bot + admin detection
    // ----------------------------------------------------------------------

    /**
     * True when we should NOT increment the counter — bots, the admin's own
     * sessions, prefetch hints, and similar non-human traffic.
     */
    private static function shouldSkip(): bool
    {
        // 1. The admin session cookie is present → don't count own visits.
        if (isset($_COOKIE['codex_admin'])) return true;

        // 2. Browser prefetch / prerender requests (Chrome / Edge hint).
        $purpose = $_SERVER['HTTP_PURPOSE']      ?? $_SERVER['HTTP_X_PURPOSE'] ?? '';
        $secPurp = $_SERVER['HTTP_SEC_PURPOSE']  ?? '';
        if (stripos($purpose, 'prefetch') !== false || stripos($secPurp, 'prefetch') !== false) {
            return true;
        }

        // 3. Known bot User-Agent patterns.
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($ua === '') return true; // no UA = almost certainly a bot
        return self::isBotUserAgent($ua);
    }

    private static function isBotUserAgent(string $ua): bool
    {
        // Case-insensitive substring match — cheap and catches the long tail.
        // Sorted by approximate frequency so the common ones short-circuit.
        static $patterns = [
            'bot',          // googlebot, bingbot, ahrefsbot, semrushbot, mj12bot, dotbot…
            'crawler',
            'spider',
            'slurp',        // yahoo
            'duckduck',
            'baiduspider',
            'yandex',
            'facebookexternal',
            'whatsapp',
            'telegram',
            'twitterbot',
            'linkedinbot',
            'discordbot',
            'pinterest',
            'embedly',
            'pingdom',
            'uptimerobot',
            'statuscake',
            'lighthouse',
            'pagespeed',
            'gtmetrix',
            'curl/',
            'wget/',
            'python-requests',
            'go-http-client',
            'java/',
            'httpclient',
            'apache-httpclient',
            'okhttp',
            'headlesschrome',
            'phantomjs',
            'preview',
        ];
        $low = strtolower($ua);
        foreach ($patterns as $p) {
            if (strpos($low, $p) !== false) return true;
        }
        return false;
    }
}
