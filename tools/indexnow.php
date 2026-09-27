<?php
declare(strict_types=1);

/**
 * Tells the search engines that share IndexNow (Bing, Yandex, Seznam, Naver,
 * Yep) about new or changed pages, so they crawl them without waiting for the
 * sitemap. The key is the <32 hex chars>.txt file in the site root — it has
 * to be deployed before the first submission.
 *
 *   php tools/indexnow.php                    every URL in the live sitemap
 *   php tools/indexnow.php URL [URL…]         only these URLs
 *   php tools/indexnow.php --dry-run [URL…]   check the key and the list, send nothing
 *
 * Send a URL only when it is new or its content changed: engines may start
 * ignoring a site that keeps resubmitting unchanged pages.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const SITE     = 'https://chesscodex.org';
const ENDPOINT = 'https://api.indexnow.org/indexnow';
const BATCH    = 10000;   // most URLs the protocol accepts per request

/** @return array{int, string} HTTP status (0 = no answer) and body */
function indexnow_http(string $url, ?string $json = null): array
{
    $ctx = stream_context_create(['http' => [
        'method'        => $json === null ? 'GET' : 'POST',
        'header'        => $json === null ? '' : "Content-Type: application/json; charset=utf-8\r\n",
        'content'       => $json ?? '',
        'timeout'       => 60,
        'ignore_errors' => true,   // hand back 4xx bodies instead of false
        'user_agent'    => 'chesscodex-indexnow',
    ]]);
    $body    = @file_get_contents($url, false, $ctx);
    $headers = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    $status = 0;
    foreach ($headers as $h) {   // the last status line wins if there was a redirect
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int) $m[1];
    }
    return [$status, $body === false ? '' : $body];
}

$args   = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$urls   = array_values(array_diff($args, ['--dry-run']));

$keys = [];
foreach (glob(dirname(__DIR__) . '/*.txt') ?: [] as $file) {
    $name = basename($file, '.txt');
    if (preg_match('/^[0-9a-f]{32}$/', $name) && trim((string) file_get_contents($file)) === $name) {
        $keys[] = $name;
    }
}
if (count($keys) !== 1) {
    fwrite(STDERR, 'Need exactly one IndexNow key file (<32 hex chars>.txt holding its own name) in the site root, found ' . count($keys) . ".\n");
    exit(1);
}
$key         = $keys[0];
$keyLocation = SITE . "/$key.txt";

[$status, $body] = indexnow_http($keyLocation);
if ($status !== 200 || trim($body) !== $key) {
    fwrite(STDERR, "$keyLocation answers $status without the key — deploy first (bash deploy/deploy.sh).\n");
    exit(1);
}

if ($urls === []) {
    [$status, $xml] = indexnow_http(SITE . '/sitemap.xml');
    preg_match_all('#<loc>([^<]+)</loc>#', $xml, $m);
    $urls = array_map(static fn (string $u): string => htmlspecialchars_decode($u, ENT_XML1), $m[1]);
    // /sitemap.xml is an index: collect the URLs of every part.
    if (str_contains($xml, '<sitemapindex')) {
        $parts = $urls;
        $urls  = [];
        foreach ($parts as $part) {
            [$partStatus, $partXml] = indexnow_http($part);
            preg_match_all('#<url><loc>([^<]+)</loc>#', $partXml, $m);
            if ($partStatus !== 200 || $m[1] === []) {
                fwrite(STDERR, "Couldn't read $part (HTTP $partStatus).\n");
                exit(1);
            }
            foreach ($m[1] as $u) $urls[] = htmlspecialchars_decode($u, ENT_XML1);
        }
    }
    if ($status !== 200 || $urls === []) {
        fwrite(STDERR, "Couldn't read " . SITE . "/sitemap.xml (HTTP $status).\n");
        exit(1);
    }
}
$foreign = array_filter($urls, static fn (string $u): bool => !str_starts_with($u, SITE . '/'));
if ($foreign !== []) {
    fwrite(STDERR, 'Not on ' . SITE . ': ' . implode(', ', array_slice($foreign, 0, 3)) . "\n");
    exit(1);
}
$urls = array_values(array_unique($urls));

if ($dryRun) {
    printf("Key OK at %s. Would send %d URLs (%s … %s).\n", $keyLocation, count($urls), $urls[0], end($urls));
    exit(0);
}

$meaning = [
    200 => 'accepted',
    202 => 'received, the key is still being validated',
    400 => 'bad request',
    403 => 'key not valid — key file missing or different (a brand-new key was refused once: send one URL, wait a minute, retry)',
    422 => "URLs don't belong to the host, or the key doesn't match",
    429 => 'too many requests — try again later',
];
$ok = true;
foreach (array_chunk($urls, BATCH) as $batch) {
    $json = json_encode([
        'host'        => parse_url(SITE, PHP_URL_HOST),
        'key'         => $key,
        'keyLocation' => $keyLocation,
        'urlList'     => $batch,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    [$status, $body] = indexnow_http(ENDPOINT, $json);
    printf("%d URLs → HTTP %d, %s\n", count($batch), $status, $meaning[$status] ?? trim(substr($body, 0, 200)));
    $ok = $ok && ($status === 200 || $status === 202);
}
exit($ok ? 0 : 1);
