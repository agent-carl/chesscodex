<?php
declare(strict_types=1);

/**
 * Tiny test runner — no PHPUnit, no Composer. Loads every test_*.php in
 * this directory; each registers tests via the global `it()` helper.
 *
 * Usage (CLI):    php tests/run.php
 * Usage (browser): /tests/run.php?token=<seed_token>
 */

if (php_sapi_name() !== 'cli') {
    $config = require __DIR__ . '/../config.php';
    $expected = (string) ($config['seed_token'] ?? '');
    $given    = (string) ($_GET['token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden.\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$GLOBALS['__tests']  = [];
$GLOBALS['__failed'] = 0;
$GLOBALS['__passed'] = 0;

function it(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = ['name' => $name, 'fn' => $fn];
}

function assert_eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($msg ? "$msg: " : '')
            . "expected " . var_export($expected, true)
            . ", got " . var_export($actual, true));
    }
}

function assert_true($v, string $msg = ''): void
{
    if ($v !== true) {
        throw new RuntimeException(($msg ? "$msg: " : '') . "expected true, got " . var_export($v, true));
    }
}

foreach (glob(__DIR__ . '/test_*.php') ?: [] as $file) {
    require_once $file;
}

echo "running " . count($GLOBALS['__tests']) . " tests…\n\n";
foreach ($GLOBALS['__tests'] as $t) {
    try {
        ($t['fn'])();
        echo "  \xE2\x9C\x93  {$t['name']}\n";
        $GLOBALS['__passed']++;
    } catch (Throwable $e) {
        echo "  \xE2\x9C\x97  {$t['name']}\n      " . $e->getMessage() . "\n";
        $GLOBALS['__failed']++;
    }
}

echo "\n";
echo "passed: {$GLOBALS['__passed']}\nfailed: {$GLOBALS['__failed']}\n";
exit($GLOBALS['__failed'] > 0 ? 1 : 0);
