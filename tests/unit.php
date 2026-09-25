<?php
declare(strict_types=1);

/**
 * Unit tests for critical-path PHP classes — Cache, Opening::suggestSimilar.
 *
 * Framework-free for portability — uses a tiny `assert_eq` / `assert_true`
 * pattern matching what `tests/run.php` already does for parser tests.
 *
 * Run from CLI:
 *
 *   php tests/unit.php
 */

require __DIR__ . '/../lib/Autoload.php';

$pass = $fail = 0;
$msgs = [];

function ok(string $name): void  { global $pass; $pass++; echo "  ✓ $name\n"; }
function bad(string $name, string $why): void {
    global $fail, $msgs; $fail++;
    echo "  ✗ $name — $why\n";
    $msgs[] = $name . ': ' . $why;
}
function assertEq($expected, $actual, string $name): void {
    if ($expected === $actual) ok($name);
    else bad($name, sprintf('expected %s, got %s',
        var_export($expected, true),
        var_export($actual, true)));
}
function assertTrue(bool $cond, string $name): void {
    if ($cond) ok($name);
    else bad($name, 'condition was false');
}

echo "=== Unit tests ===\n\n";

// ----------------------------------------------------------------------
// Cache — disk-backed remember()
// ----------------------------------------------------------------------
echo "[Cache]\n";
$key = 'test_unit_' . bin2hex(random_bytes(4));
Cache::forget($key);
$first  = Cache::remember($key, 60, fn() => ['v' => 1]);
$second = Cache::remember($key, 60, fn() => ['v' => 2]);  // should serve cache, not recompute
assertEq(1, $first['v'],  'remember() returns computed on miss');
assertEq(1, $second['v'], 'remember() returns cached on hit');
Cache::forget($key);
$third = Cache::remember($key, 60, fn() => ['v' => 3]);
assertEq(3, $third['v'],  'forget() invalidates the cache');
// Empty results must not be cached.
$key2 = 'test_unit_' . bin2hex(random_bytes(4));
Cache::forget($key2);
Cache::remember($key2, 60, fn() => []);
assertEq(null, Cache::get($key2, 60), 'empty result not cached');

// ----------------------------------------------------------------------
// Opening::suggestSimilar — fuzzy-slug matcher (touches DB)
// ----------------------------------------------------------------------
echo "\n[Opening::suggestSimilar]\n";
try {
    require_once __DIR__ . '/../lib/db.php';
    $r = Opening::suggestSimilar('sicilan-defence', 3);   // typo
    assertTrue(is_array($r),        'returns array');
    assertTrue(count($r) > 0,       'finds at least one match for typo');
    $foundSicilian = false;
    foreach ($r as $row) {
        if (strpos((string) $row['slug'], 'sicilian') !== false) $foundSicilian = true;
    }
    assertTrue($foundSicilian, 'top match contains "sicilian"');
} catch (Throwable $e) {
    bad('suggestSimilar', 'DB unavailable: ' . $e->getMessage());
}

echo "\n=== Summary ===\n";
echo "Passed: $pass   Failed: $fail   Total: " . ($pass + $fail) . "\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($msgs as $m) echo "  - $m\n";
    exit(1);
}
