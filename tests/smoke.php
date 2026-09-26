<?php
declare(strict_types=1);

/**
 * Smoke tests — HTTP-level checks that every public route returns the
 * expected status code and content type. Run from CLI after a deploy:
 *
 *   php tests/smoke.php
 *
 * Or from HTTP (token-protected):
 *
 *   /tests/smoke.php?token=<seed_token>
 *
 * Doesn't assert content correctness — just "is this route up and
 * responding sanely". A failure here means deploy was broken.
 */

$rootUrl = 'https://test.av-webdevs.com';

// Token gate for HTTP usage. CLI bypasses.
if (php_sapi_name() !== 'cli') {
    $config = require __DIR__ . '/../config.php';
    $expected = (string) ($config['seed_token'] ?? '');
    $given    = (string) ($_GET['token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        echo "Forbidden.\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$tests = [
    // [method, path, expected status, contains string]
    ['GET', '/',                                  200, 'Caissa Codex'],
    ['GET', '/about',                             200, 'high school student'],
    ['GET', '/openings',                          200, 'A–Z'],
    ['GET', '/openings/sicilian-defense',         200, 'Sicilian'],
    ['GET', '/search',                            200, 'search'],
    ['GET', '/play/sicilian-defense',             200, 'Stockfish'],
    ['GET', '/random',                            302, ''],
    ['GET', '/robots.txt',                        200, 'Sitemap:'],
    ['GET', '/sitemap.xml',                       200, '<urlset'],
    ['GET', '/api/search?name=naj',               200, 'Najdorf'],
    ['GET', '/api/stats?play=e2e4,c7c5',          200, '{'],
    ['GET', '/api/suggest',                       405, 'POST only'],
    ['GET', '/admin',                             302, ''],
    ['GET', '/admin/login',                       200, 'Admin login'],
    ['GET', '/openings/this-does-not-exist',      404, 'Did you mean'],
    ['GET', '/nonsense-page',                     404, 'Page not found'],
    ['GET', '/og.php?slug=sicilian-defense',      200, ''],   // PNG, just check 200
];

$pass = $fail = 0;
echo "=== Smoke tests against $rootUrl ===\n\n";

foreach ($tests as [$method, $path, $expectedStatus, $contains]) {
    $url = $rootUrl . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,    // we want to see the 302 directly
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_USERAGENT      => 'chess-codex-smoke/1.0',
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $statusOk   = ($code === $expectedStatus);
    $containsOk = ($contains === '' || strpos($body, $contains) !== false);
    $ok = $statusOk && $containsOk;
    $marker = $ok ? '✓' : '✗';
    printf("  %s  %-3d %-50s  %s\n",
        $marker, $code, $method . ' ' . $path,
        $ok ? '' : 'expected ' . $expectedStatus . ($contains ? ', "' . $contains . '"' : '')
    );
    if ($ok) $pass++; else $fail++;
}

echo "\n";
echo "Passed: $pass   Failed: $fail   Total: " . count($tests) . "\n";
if ($fail > 0) {
    if (php_sapi_name() === 'cli') exit(1);
}
