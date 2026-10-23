<?php
declare(strict_types=1);

/**
 * Router for `php -S` that stands in for the production nginx config when
 * testing locally (started by tools/dev/serve.sh). Mirrors the nginx rules —
 * blocked paths, the PHP entry points, the service worker's scope header —
 * and, when the server config sits next to this checkout, sends its security
 * headers too, so the strict CSP applies locally. Not deployed.
 */

// The path as sent, like nginx sees it (parse_url() would read "//x/…" as a host).
$path = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0] ?: '/';
$root = $_SERVER['DOCUMENT_ROOT'];

$deny = static function (int $code): bool {
    http_response_code($code);
    echo $code, "\n";
    return true;
};

// Security headers, straight from the server's nginx snippet: $CHESSCODEX_OPS,
// by default ../pi-home/chesscodex next to this checkout; none without it.
$ops = getenv('CHESSCODEX_OPS') ?: dirname(__DIR__, 3) . '/pi-home/chesscodex';
$conf = (string) @file_get_contents($ops . '/nginx/chesscodex-headers.conf');
preg_match_all('/^add_header\s+(\S+)\s+"(.*)"\s+always;/m', $conf, $h, PREG_SET_ORDER);
foreach ($h as [, $name, $value]) header("$name: $value");

$wellKnown = strncmp($path, '/.well-known/', 13) === 0;
if (!$wellKnown && preg_match('#/\.#', $path)) return $deny(403);
if (preg_match('#^/(db|lib|tools|tests|deploy|docs|_composer_vendor|node_modules)(/|$)#', $path)) return $deny(403);
if (preg_match('#\.(sql|md|tsv|neon|lock|log|dist)$#', $path)) return $deny(403);
if (preg_match('#^/(config\.php|config\.example\.php|composer\.json|package(-lock)?\.json|VERSION)$#', $path)) return $deny(403);

if (in_array($path, ['/index.php', '/og.php', '/prewarm.php'], true)) {
    require $root . $path;      // nginx allows prewarm.php from 127.0.0.1 only
    return true;
}
if (preg_match('#\.php$#', $path)) return $deny(404);

if ($path === '/public/sw.js') {
    header('Content-Type: application/javascript');
    header('Service-Worker-Allowed: /');
    header('Cache-Control: no-cache, must-revalidate, max-age=0');
    readfile($root . $path);
    return true;
}
if ($path !== '/' && is_file($root . $path)) return false;   // static file, served by php -S
if ($wellKnown) return $deny(404);

require $root . '/index.php';                                  // pretty URLs → front controller
return true;
