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
 *   php tools/gsc.php coverage       index status of every sitemap URL (see coverage() for options)
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

function home_file(string $name): string
{
    return (getenv('USERPROFILE') ?: getenv('HOME')) . '/.chesscodex/' . $name;
}

/** @return array<string, list<string>> the live sitemap's URLs by part (pages, eco, …) */
function sitemap_urls(): array
{
    $get = static function (string $url): SimpleXMLElement {
        $ctx = stream_context_create(['http' => ['timeout' => 60, 'header' => 'User-Agent: chesscodex-gsc']]);
        $xml = @simplexml_load_string((string) @file_get_contents($url, false, $ctx));
        return $xml === false ? fail("Could not read $url") : $xml;
    };
    $parts = [];
    foreach ($get(SITE . '/sitemap.xml')->sitemap as $s) {
        $loc = (string) $s->loc;
        foreach ($get($loc)->url as $u) $parts[basename($loc, '.xml')][] = (string) $u->loc;
    }
    return $parts;
}

/** @return array<string, int> impressions by page over the whole Search Analytics history (16 months) */
function impressions_by_page(string $token): array
{
    $site  = '/webmasters/v3/sites/' . rawurlencode(PROPERTY);
    $pages = [];
    for ($start = 0; ; $start += 25000) {
        $rows = api($token, 'POST', "$site/searchAnalytics/query", [
            'startDate' => date('Y-m-d', strtotime('-16 months')), 'endDate' => date('Y-m-d'),
            'dimensions' => ['page'], 'rowLimit' => 25000, 'startRow' => $start, 'dataState' => 'all',
        ])['rows'] ?? [];
        foreach ($rows as $r) {
            $url = strtok($r['keys'][0], '#');
            $pages[$url] = ($pages[$url] ?? 0) + (int) $r['impressions'];
        }
        if (count($rows) < 25000) return $pages;
    }
}

/**
 * URL Inspection for many URLs, $parallel at a time and under the 600-a-minute
 * limit. Calls $done(url, result) for each answer; returns why it stopped early
 * (the daily quota), or null when every URL got an answer.
 */
function inspect_parallel(array $urls, int $parallel, callable $done): ?string
{
    $token    = access_token();
    $tokenAt  = time();
    $multi    = curl_multi_init();
    $queue    = $urls;
    $running  = [];
    $retried  = [];
    $stop     = null;
    $lastAt   = 0.0;
    $pausedAt = 0;
    $add = static function (string $url) use (&$token, &$tokenAt, $multi, &$running, &$lastAt): void {
        if (time() - $tokenAt > 3000) { $token = access_token(); $tokenAt = time(); }
        $wait = $lastAt + 0.11 - microtime(true);   // at most ~545 requests a minute
        if ($wait > 0) usleep((int) ($wait * 1e6));
        $lastAt = microtime(true);
        $ch = curl_init(API . '/v1/urlInspection/index:inspect');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['inspectionUrl' => $url, 'siteUrl' => PROPERTY, 'languageCode' => 'en-US'], JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
        ]);
        curl_multi_add_handle($multi, $ch);
        $running[(int) $ch] = [$ch, $url];
    };
    while ($running !== [] || ($queue !== [] && $stop === null)) {
        while ($stop === null && $queue !== [] && count($running) < $parallel) $add(array_shift($queue));
        curl_multi_exec($multi, $active);
        curl_multi_select($multi, 1.0);
        while ($info = curl_multi_info_read($multi)) {
            $ch = $info['handle'];
            [, $url] = $running[(int) $ch];
            unset($running[(int) $ch]);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $json   = json_decode((string) curl_multi_getcontent($ch), true) ?: [];
            curl_multi_remove_handle($multi, $ch);
            $transient = $status === 429 || $status >= 500 || $status === 0;
            if ($status === 200) {
                $done($url, $json['inspectionResult']['indexStatusResult'] ?? []);
            } elseif ($transient && !isset($retried[$url])) {
                $retried[$url] = true;   // one more try at the end of the queue, after a pause on 429
                $queue[] = $url;
                if ($status === 429 && time() - $pausedAt > 60) { sleep(60); $pausedAt = time(); }
            } elseif ($status === 429) {
                $stop ??= 'quota: ' . ($json['error']['message'] ?? 'HTTP 429');
            } elseif (!$transient) {
                $stop ??= "HTTP $status: " . ($json['error']['message'] ?? 'no details');
            }   // a second server error: skipped, the next run asks again
        }
    }
    curl_multi_close($multi);
    return $stop;
}

/**
 * Which sitemap URLs Google has indexed. URLs with search impressions count as
 * indexed without spending quota; the rest go through URL Inspection, whose
 * answers are kept in %USERPROFILE%\.chesscodex\gsc-inspect.json and reused
 * for --max-age days, so a run stopped by the daily quota resumes the next day.
 *
 *   --max-age N    reuse inspections up to N days old (default 3; 0 = check all again)
 *   --parallel N   requests at a time (default 10)
 *   --limit N      inspect at most N URLs this run
 *   --out FILE     one line per URL, tab-separated (default %USERPROFILE%\.chesscodex\gsc-coverage.tsv)
 */
function coverage(array $args): void
{
    $opt = static function (string $name, string $default) use ($args): string {
        $i = array_search("--$name", $args, true);
        return $i === false ? $default : (string) ($args[$i + 1] ?? $default);
    };
    $maxAge    = (float) $opt('max-age', '3');
    $parallel  = max(1, (int) $opt('parallel', '10'));
    $out       = $opt('out', home_file('gsc-coverage.tsv'));
    $cacheFile = home_file('gsc-inspect.json');
    $cache     = json_decode((string) @file_get_contents($cacheFile), true) ?: [];
    $save      = static function () use (&$cache, $cacheFile): void {
        file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    };

    $parts   = sitemap_urls();
    $all     = array_merge(...array_values($parts));
    $shown   = impressions_by_page(access_token());
    $todo    = array_values(array_filter($all, static fn (string $u): bool =>
        !isset($shown[$u]) && (time() - ($cache[$u]['checked'] ?? 0)) > $maxAge * 86400));
    $indexed = count(array_intersect_key($shown, array_flip($all)));
    $fresh   = count($all) - $indexed - count($todo);
    $todo    = array_slice($todo, 0, (int) $opt('limit', (string) count($todo)));
    printf("Sitemap: %d URLs; %d have impressions, %d were inspected less than %s days ago; inspecting %d now\n",
        count($all), $indexed, $fresh, $maxAge, count($todo));

    $n     = 0;
    $begun = time();
    $stop  = inspect_parallel($todo, $parallel, static function (string $url, array $r) use (&$cache, &$n, $save, $todo, $begun): void {
        $cache[$url] = [
            'verdict'  => $r['verdict'] ?? '?',
            'coverage' => $r['coverageState'] ?? '?',
            'crawled'  => $r['lastCrawlTime'] ?? null,
            'checked'  => time(),
        ];
        if (++$n % 50 === 0) {
            $save();
            printf("  %d / %d inspected, %d s\n", $n, count($todo), time() - $begun);
        }
    });
    $save();
    printf("Inspected now: %d in %d s%s\n", $n, time() - $begun, $stop === null ? '' : " — stopped, $stop");

    $states = [];
    $lines  = ["part\turl\tindexed\tstatus\timpressions\tlast crawl"];
    echo "\npart                   URLs  indexed  not indexed  unchecked\n";
    foreach ($parts as $part => $urls) {
        $count = ['yes' => 0, 'no' => 0, '?' => 0];
        foreach ($urls as $url) {
            $c = $cache[$url] ?? null;
            [$indexed, $state] = match (true) {
                isset($shown[$url]) => ['yes', 'has impressions'],
                $c === null         => ['?', 'not inspected yet'],
                default             => [$c['verdict'] === 'PASS' ? 'yes' : 'no', $c['coverage']],
            };
            $count[$indexed]++;
            if ($indexed === 'no') $states[$state] = ($states[$state] ?? 0) + 1;
            $lines[] = implode("\t", [$part, substr($url, strlen(SITE)) ?: '/', $indexed, $state,
                $shown[$url] ?? 0, isset($c['crawled']) ? local_time($c['crawled']) : '']);
        }
        printf("%-20s %6d %8d %12d %10d\n", $part, count($urls), $count['yes'], $count['no'], $count['?']);
    }
    arsort($states);
    echo "\nNot indexed, by reason:\n";
    foreach ($states as $state => $count) printf("  %5d  %s\n", $count, $state);
    $outside = array_diff_key($shown, array_flip($all));
    printf("\nOutside the sitemap but with impressions: %d URLs\n", count($outside));
    file_put_contents($out, implode("\n", $lines) . "\n");
    echo "Every URL: $out\n";
}

date_default_timezone_set('Europe/Oslo');
$args = array_slice($argv, 1);
if (($args[0] ?? '') === 'inspect') {
    $urls = array_slice($args, 1) ?: array_map(static fn (string $p): string => SITE . $p, MAIN_PAGES);
    inspect(access_token(), $urls);
} elseif (($args[0] ?? '') === 'coverage') {
    coverage(array_slice($args, 1));
} elseif ($args === [] || $args[0] === '--days') {
    summary(access_token(), max(1, (int) ($args[1] ?? 28)));
} else {
    fail('Usage: php tools/gsc.php [--days N] | inspect [URL…] | coverage [--max-age N] [--parallel N] [--limit N] [--out FILE]');
}
