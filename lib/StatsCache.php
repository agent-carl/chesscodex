<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/LichessExplorer.php';

/**
 * Cache-aside layer over codex_stats_cache. Keyed by SHA-256 of the
 * canonical UCI play string ("e2e4,c7c5,g1f3,..."). The schema column is
 * called `fen_hash` for historical reasons — semantically it's an opaque
 * position-id and we never invert it.
 */
final class StatsCache
{
    private const TTL_FRESH_DAYS    = 7;   // <7d: serve directly
    private const TTL_HARD_MAX_DAYS = 30;  // >30d: must refresh

    /**
     * Returns ['white'=>int, 'black'=>int, 'draws'=>int, 'top_moves'=>array, 'cached_at'=>string].
     * On Lichess failure with no cache: returns ['error' => '...'].
     * On Lichess failure with stale cache: returns the stale data.
     */
    public static function getOrFetch(array $uciMoves): array
    {
        $key  = self::keyFor($uciMoves);
        $row  = self::loadRow($key);
        $now  = time();

        if ($row !== null) {
            $ageDays = ($now - strtotime($row['fetched_at'])) / 86400;
            if ($ageDays < self::TTL_FRESH_DAYS) {
                return self::shape($row);
            }
            if ($ageDays < self::TTL_HARD_MAX_DAYS) {
                // Stale-but-acceptable: try to refresh, fall back to stale.
                try {
                    $fresh = self::refresh($key, $uciMoves);
                    return self::shape($fresh);
                } catch (Throwable $e) {
                    error_log('StatsCache stale-refresh failed: ' . $e->getMessage());
                    return self::shape($row);
                }
            }
        }

        // No cache or hard-expired: must fetch.
        try {
            $fresh = self::refresh($key, $uciMoves);
            return self::shape($fresh);
        } catch (Throwable $e) {
            error_log('StatsCache hard-fetch failed: ' . $e->getMessage());
            if ($row !== null) return self::shape($row); // return very stale rather than nothing
            return [
                'error'  => 'Statistics unavailable right now.',
                'detail' => $e->getMessage(),
            ];
        }
    }

    public static function keyFor(array $uciMoves): string
    {
        return hash('sha256', implode(',', $uciMoves));
    }

    private static function loadRow(string $key): ?array
    {
        $stmt = chess_codex_db()->prepare(
            'SELECT fen_hash, white_wins, black_wins, draws, top_moves_json, fetched_at
             FROM codex_stats_cache WHERE fen_hash = :k LIMIT 1'
        );
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Hits Lichess, writes a fresh row, returns the row in DB shape. */
    private static function refresh(string $key, array $uciMoves): array
    {
        $data = LichessExplorer::fetch($uciMoves);
        $white  = (int) ($data['white']  ?? 0);
        $black  = (int) ($data['black']  ?? 0);
        $draws  = (int) ($data['draws']  ?? 0);
        $topMoves = [];
        foreach (($data['moves'] ?? []) as $m) {
            $topMoves[] = [
                'uci'   => (string) ($m['uci'] ?? ''),
                'san'   => (string) ($m['san'] ?? ''),
                'white' => (int) ($m['white'] ?? 0),
                'black' => (int) ($m['black'] ?? 0),
                'draws' => (int) ($m['draws'] ?? 0),
            ];
        }
        $json = json_encode($topMoves, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = chess_codex_db()->prepare(
            'INSERT INTO codex_stats_cache (fen_hash, white_wins, black_wins, draws, top_moves_json, fetched_at)
             VALUES (:k, :w, :b, :d, :tm, NOW())
             ON DUPLICATE KEY UPDATE
                white_wins = VALUES(white_wins),
                black_wins = VALUES(black_wins),
                draws      = VALUES(draws),
                top_moves_json = VALUES(top_moves_json),
                fetched_at = VALUES(fetched_at)'
        );
        $stmt->execute([
            'k'  => $key,
            'w'  => $white,
            'b'  => $black,
            'd'  => $draws,
            'tm' => $json,
        ]);

        return [
            'fen_hash'       => $key,
            'white_wins'     => $white,
            'black_wins'     => $black,
            'draws'          => $draws,
            'top_moves_json' => $json,
            'fetched_at'     => date('Y-m-d H:i:s'),
        ];
    }

    /** Shape a DB row for the JSON response. */
    private static function shape(array $row): array
    {
        return [
            'white'     => (int) $row['white_wins'],
            'black'     => (int) $row['black_wins'],
            'draws'     => (int) $row['draws'],
            'top_moves' => json_decode($row['top_moves_json'] ?? '[]', true) ?: [],
            'cached_at' => $row['fetched_at'],
        ];
    }
}
