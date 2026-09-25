<?php
declare(strict_types=1);

/**
 * Thin wrapper around https://explorer.lichess.ovh/lichess.
 *
 * Lichess rate-limits the explorer, so requests go out one at a time,
 * THROTTLE_MS apart, under a file lock held for the whole request. A
 * visitor who finds a request in flight gets LichessBusyException right
 * away (the API answers 503 + Retry-After and the page retries) instead
 * of queueing on the lock: a queue of blocked PHP workers let a few
 * clients stall the whole site, whose pool has only 4.
 *
 * Errors (timeout, non-200, malformed JSON) bubble up as exceptions so the
 * caller can choose between serving stale cache or surfacing the failure.
 */
final class LichessBusyException extends RuntimeException {}

final class LichessExplorer
{
    private const ENDPOINT     = 'https://explorer.lichess.ovh/lichess';
    private const TIMEOUT_S    = 5;
    private const THROTTLE_MS  = 600;
    private const TOP_MOVES    = 8;

    private static ?string $token = null;

    private static function token(): string
    {
        if (self::$token === null) {
            $config = require __DIR__ . '/../config.php';
            self::$token = (string) ($config['lichess_token'] ?? '');
        }
        return self::$token;
    }

    /**
     * @param string[] $uciMoves e.g. ['e2e4', 'c7c5', 'g1f3']
     * @param bool $wait queue for the lock instead of throwing
     *                   LichessBusyException (batch jobs like prewarm.php)
     * @return array<string, mixed> decoded JSON from Lichess
     */
    public static function fetch(array $uciMoves, bool $wait = false): array
    {
        $token = self::token();
        if ($token === '') {
            throw new RuntimeException('Lichess API token is not set in config.php (lichess_token).');
        }

        $params = http_build_query([
            'play'       => implode(',', $uciMoves),
            'moves'      => self::TOP_MOVES,
            'topGames'   => 0,
            'recentGames'=> 0,
            'speeds'     => 'blitz,rapid,classical',
            'ratings'    => '1600,1800,2000,2200,2500',
            'variant'    => 'standard',
        ]);
        $url = self::ENDPOINT . '?' . $params;

        $lock = self::lock($wait);
        try {
            $body = function_exists('curl_init')
                ? self::fetchViaCurl($url)
                : self::fetchViaStream($url);
        } finally {
            self::unlock($lock);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Lichess returned non-JSON: ' . substr($body, 0, 200));
        }
        return $data;
    }

    private static function fetchViaCurl(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_S,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_S,
            CURLOPT_USERAGENT      => 'chess-codex/1.0 (https://chesscodex.org)',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Authorization: Bearer ' . self::token(),
            ],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("curl error $errno: $err");
        }
        if ($code !== 200) {
            throw new RuntimeException("Lichess HTTP $code (curl)");
        }
        return (string) $body;
    }

    private static function fetchViaStream(string $url): string
    {
        if (!ini_get('allow_url_fopen')) {
            throw new RuntimeException('Neither curl nor allow_url_fopen is available on this host.');
        }
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => self::TIMEOUT_S,
                'header'  => "User-Agent: chess-codex/1.0 (https://chesscodex.org)\r\n"
                           . "Accept: application/json\r\n"
                           . 'Authorization: Bearer ' . self::token() . "\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            $err = error_get_last()['message'] ?? 'unknown error';
            throw new RuntimeException("file_get_contents failed: $err");
        }
        // $http_response_header is auto-populated by the stream wrapper.
        $statusLine = $http_response_header[0] ?? '';
        if (!preg_match('#HTTP/[\d.]+ 200#', $statusLine)) {
            throw new RuntimeException("Lichess HTTP non-200 (stream): $statusLine");
        }
        return $body;
    }

    /**
     * Take the Lichess lock and wait out the rest of THROTTLE_MS since the
     * previous request. The timestamp of that request is the lock file's
     * content. Returns null when the lock file can't be opened (best-effort).
     *
     * @return resource|null
     */
    private static function lock(bool $wait)
    {
        $fh = @fopen(sys_get_temp_dir() . '/chess_codex_lichess.lock', 'c+');
        if ($fh === false) return null;
        if (!flock($fh, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new LichessBusyException('Another Lichess request is in progress.');
        }
        $last = (float) (stream_get_contents($fh) ?: '0');
        $waitMs = (int) max(0, self::THROTTLE_MS - (int) ((microtime(true) - $last) * 1000));
        if ($waitMs > 0) usleep($waitMs * 1000);
        return $fh;
    }

    /** @param resource|null $fh */
    private static function unlock($fh): void
    {
        if ($fh === null) return;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) microtime(true));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
