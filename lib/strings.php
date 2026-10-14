<?php
declare(strict_types=1);

/**
 * A UI string from lib/lang/en.php by key, with {placeholder} substitution:
 * t('error.500.title'), t('some.key', ['n' => 3]). An unknown key comes back
 * as itself.
 */
function t(string $key, array $replace = []): string
{
    static $strings = null;
    if ($strings === null) {
        $loaded  = @include __DIR__ . '/lang/en.php';
        $strings = is_array($loaded) ? $loaded : [];
    }
    $s = $strings[$key] ?? $key;
    foreach ($replace as $k => $v) $s = str_replace('{' . $k . '}', (string) $v, $s);
    return $s;
}
