<?php
declare(strict_types=1);

/**
 * Google Search Console from the command line, for the sc-domain:chesscodex.org
 * property, through a service account with read-only access.
 *
 *   php tools/gsc.php                clicks and impressions by day, top queries, pages and countries, sitemaps
 *   php tools/gsc.php --days 90      the same over 90 days (default 28)
 *   php tools/gsc.php inspect        index status of the main pages
 *   php tools/gsc.php inspect URL…   index status of these URLs (quota: 2,000 a day)
 *
 * The key is the service account's JSON file, kept outside the repo:
 * %USERPROFILE%\.chesscodex\gsc-key.json, or the path in $GSC_KEY. Its Google
 * Cloud project needs the Search Console API enabled, and the account has to
 * be a user (restricted is enough) of the property. Search numbers lag 2–3
 * days and their dates are Pacific Time.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const PROPERTY = 'sc-domain:chesscodex.org';
const SITE     = 'https://chesscodex.org';
const API      = 'https://searchconsole.googleapis.com';
const SCOPE    = 'https://www.googleapis.com/auth/webmasters.readonly';

// `inspect` without URLs checks these: the hub pages and the ten best-known openings.
const MAIN_PAGES = [
    '/', '/openings', '/search', '/eco', '/about',
    '/openings/sicilian-defense', '/openings/french-defense', '/openings/caro-kann-defense',
    '/openings/italian-game', '/openings/ruy-lopez', '/openings/queens-gambit-declined',
    '/openings/queens-gambit-accepted', '/openings/kings-indian-defense',
    '/openings/nimzo-indian-defense', '/openings/english-opening',
];

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/** @return array{int, array<mixed>} HTTP status and the decoded JSON body */
function http_json(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ctx = stream_context_create(['http' => [
        'method'        => $method,
        'header'        => implode("\r\n", $headers),
        'content'       => $body ?? '',
        'timeout'       => 60,
        'ignore_errors' => true,   // hand back error bodies instead of false
    ]]);
    $raw      = @file_get_contents($url, false, $ctx);
    $response = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    $status = 0;
    foreach ($response as $h) {   // the last status line wins if there was a redirect
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int) $m[1];
    }
    if ($raw === false || $status === 0) fail("No answer from $url");
    return [$status, json_decode($raw, true) ?: []];
}

/** A one-hour OAuth token for the service account (JWT bearer grant). */
function access_token(): string
{
    $path = getenv('GSC_KEY') ?: (getenv('USERPROFILE') ?: getenv('HOME')) . '/.chesscodex/gsc-key.json';
    $key  = json_decode((string) @file_get_contents($path), true);
    if (!is_array($key) || ($key['type'] ?? '') !== 'service_account' || empty($key['private_key'])) {
        fail("No service-account key at $path (GSC_KEY can point elsewhere).");
    }
    $b64      = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $tokenUri = $key['token_uri'] ?? 'https://oauth2.googleapis.com/token';
    $now      = time();
    $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
        'iss'   => $key['client_email'],
        'scope' => SCOPE,
        'aud'   => $tokenUri,
        'iat'   => $now,
        'exp'   => $now + 3600,
    ], JSON_UNESCAPED_SLASHES));
    if (!openssl_sign($unsigned, $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
        fail('Could not sign with the key: ' . openssl_error_string());
    }
    [$status, $body] = http_json('POST', $tokenUri, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $unsigned . '.' . $b64($signature),
    ]));
    if ($status !== 200 || empty($body['access_token'])) {
        fail("Google refused the key (HTTP $status): " . ($body['error_description'] ?? $body['error'] ?? 'no details'));
    }
    return $body['access_token'];
}

function api(string $token, string $method, string $path, ?array $body = null): array
{
    $headers = ["Authorization: Bearer $token"];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    [$status, $json] = http_json($method, API . $path, $headers,
        $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES));
    if ($status !== 200) {
        $message = $json['error']['message'] ?? 'no details';
        if ($status === 403) $message .= ' — is the service account a user of ' . PROPERTY . ' in Search Console?';
        fail("Search Console API: HTTP $status, $message");
    }
    return $json;
}

function local_time(?string $iso): string
{
    return $iso === null ? '—' : date('Y-m-d H:i', (int) strtotime($iso));
}

function summary(string $token, int $days): void
{
    $site  = '/webmasters/v3/sites/' . rawurlencode(PROPERTY);
    $start = date('Y-m-d', strtotime("-$days days"));
    $end   = date('Y-m-d');
    $rows  = static fn (array $dimensions, int $limit): array => api($token, 'POST', "$site/searchAnalytics/query",
        ['startDate' => $start, 'endDate' => $end, 'rowLimit' => $limit, 'dataState' => 'all']
        + ($dimensions === [] ? [] : ['dimensions' => $dimensions])
    )['rows'] ?? [];

    echo "Search, $start … $end (fresh data included):\n";
    $total = $rows([], 1)[0] ?? null;
    if ($total === null) {
        echo "  no impressions yet\n";
    } else {
        printf("  %d clicks, %d impressions, CTR %.1f%%, average position %.1f\n",
            $total['clicks'], $total['impressions'], $total['ctr'] * 100, $total['position']);
        echo "  by day:\n";
        foreach ($rows(['date'], 1000) as $r) {
            printf("    %s %5d clicks %7d impressions  position %.1f\n",
                $r['keys'][0], $r['clicks'], $r['impressions'], $r['position']);
        }
        foreach (['query' => 'top queries', 'page' => 'top pages', 'country' => 'top countries'] as $dimension => $title) {
            echo "  $title (clicks / impressions / position):\n";
            foreach ($rows([$dimension], 10) as $r) {
                printf("    %5d %7d %6.1f  %s\n", $r['clicks'], $r['impressions'], $r['position'], $r['keys'][0]);
            }
        }
    }

    echo "Sitemaps:\n";
    foreach (api($token, 'GET', "$site/sitemaps")['sitemap'] ?? [] as $s) {
        $urls = array_sum(array_map(static fn (array $c): int => (int) ($c['submitted'] ?? 0), $s['contents'] ?? []));
        printf("  %s — submitted %s, read %s, %d URLs, %d errors, %d warnings%s\n",
            $s['path'], local_time($s['lastSubmitted'] ?? null), local_time($s['lastDownloaded'] ?? null),
            $urls, $s['errors'] ?? 0, $s['warnings'] ?? 0, empty($s['isPending']) ? '' : ', pending');
    }
}

function inspect(string $token, array $urls): void
{
    foreach ($urls as $url) {
        $r = api($token, 'POST', '/v1/urlInspection/index:inspect', [
            'inspectionUrl' => $url,
            'siteUrl'       => PROPERTY,
            'languageCode'  => 'en-US',
        ])['inspectionResult']['indexStatusResult'] ?? [];
        printf("%-8s %-36s %-24s %s\n",
            $r['verdict'] ?? '?',
            $r['coverageState'] ?? '?',
            isset($r['lastCrawlTime']) ? 'crawled ' . local_time($r['lastCrawlTime']) : 'not crawled',
            str_starts_with($url, SITE . '/') ? substr($url, strlen(SITE)) : $url);
    }
}

date_default_timezone_set('Europe/Oslo');
$args = array_slice($argv, 1);
if (($args[0] ?? '') === 'inspect') {
    $urls = array_slice($args, 1) ?: array_map(static fn (string $p): string => SITE . $p, MAIN_PAGES);
    inspect(access_token(), $urls);
} elseif ($args === [] || $args[0] === '--days') {
    summary(access_token(), max(1, (int) ($args[1] ?? 28)));
} else {
    fail('Usage: php tools/gsc.php [--days N] | inspect [URL…]');
}
