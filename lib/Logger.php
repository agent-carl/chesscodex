<?php
declare(strict_types=1);

/**
 * Structured logging — single-line JSON per event, appended to
 * db/log/<YYYY-MM-DD>.log. Cheap to grep, future-proof for ingestion
 * into Loki / Elastic / Sentry if the site ever grows beyond hobby
 * scale. PHP's native error_log() is reserved for fatals; this is
 * the everywhere-else channel.
 *
 * Usage:
 *   Logger::info('admin.login', ['user' => $u]);
 *   Logger::warn('lichess.429', ['slug' => $slug]);
 *   Logger::error('db.fail',   ['msg' => $e->getMessage()]);
 */
final class Logger
{
    public const LEVEL_INFO  = 'info';
    public const LEVEL_WARN  = 'warn';
    public const LEVEL_ERROR = 'error';

    public static function info(string $event, array $ctx = []): void  { self::write(self::LEVEL_INFO,  $event, $ctx); }
    public static function warn(string $event, array $ctx = []): void  { self::write(self::LEVEL_WARN,  $event, $ctx); }
    public static function error(string $event, array $ctx = []): void { self::write(self::LEVEL_ERROR, $event, $ctx); }

    private static function write(string $level, string $event, array $ctx): void
    {
        try {
            $dir = __DIR__ . '/../db/log';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $path = $dir . '/' . date('Y-m-d') . '.log';
            $line = json_encode([
                'ts'    => date('c'),
                'level' => $level,
                'event' => $event,
                'ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
                'path'  => $_SERVER['REQUEST_URI'] ?? null,
                'ctx'   => $ctx,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line !== false) {
                @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
            }
        } catch (Throwable $e) {
            // Logger must never crash callers. Fall back to PHP's error_log.
            @error_log('[Logger] ' . $level . ' ' . $event . ' — ' . $e->getMessage());
        }
    }
}
