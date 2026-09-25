<?php
declare(strict_types=1);

/**
 * English-only i18n shim. The site used to support uk/de/fr URL prefixes
 * with translated strings — that infrastructure was retired (low actual
 * usage, partial coverage). What remains is a minimal compatibility layer:
 *
 *   - `t($key, $replace)`   — string lookup with {placeholder} substitution
 *   - `I18n::locale()`      — always returns 'en'
 *   - `I18n::url($path)`    — pass-through (no locale prefix to add)
 *   - `I18n::detect($path)` — pass-through (no locale prefix to strip)
 *
 * Templates and Routes that still call these methods keep working unchanged.
 * If translation ever comes back, swap this file out — the API stays the same.
 */
final class I18n
{
    public const DEFAULT_LOCALE = 'en';
    public const SUPPORTED      = ['en'];

    /** @var array<string, string>|null */
    private static ?array $strings = null;

    public static function detect(string $path): array
    {
        return [self::DEFAULT_LOCALE, $path];
    }

    public static function activate(string $locale = 'en'): void
    {
        // Always English; ignore the param. Lazy-load lang/en.php on first
        // t() call so non-page contexts (CLI scripts) don't pay for it.
    }

    public static function locale(): string
    {
        return self::DEFAULT_LOCALE;
    }

    public static function url(string $path, ?string $locale = null): string
    {
        return $path;
    }

    public static function t(string $key, array $replace = []): string
    {
        if (self::$strings === null) {
            $loaded = @include __DIR__ . '/lang/en.php';
            self::$strings = is_array($loaded) ? $loaded : [];
        }
        $s = self::$strings[$key] ?? $key;
        if ($replace) {
            foreach ($replace as $k => $v) $s = str_replace('{' . $k . '}', (string) $v, $s);
        }
        return $s;
    }
}

/** Global helper used pervasively in templates. */
function t(string $key, array $replace = []): string { return I18n::t($key, $replace); }
