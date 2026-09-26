<?php
declare(strict_types=1);

/**
 * Router for `php -S` that stands in for deploy/nginx/chesscodex.conf when
 * testing locally (started by tools/dev/serve.sh). Mirrors the nginx rules —
 * blocked paths, the PHP entry points, the service worker's scope header —
 * and sends the security headers from deploy/nginx/chesscodex-headers.conf,
 * so the strict CSP applies locally too. Not deployed.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = $_SERVER['DOCUMENT_ROOT'];

$deny = static function (int $code): bool {
    http_response_code($code);
    echo $code, "\n";
    return true;
};

// Security headers, straight from the nginx snippet.
$conf = (string) @file_get_contents(__DIR__ . '/../../deploy/nginx/chesscodex-headers.conf');
preg_match_all('/^add_header\s+(\S+)\s+"(.*)"\s+always;/m', $conf, $h, PREG_SET_ORDER);
foreach ($h as [, $name, $value]) header("$name: $value");

$wellKnown = strncmp($path, '/.well-known/', 13) === 0;
if (!$wellKnown && preg_match('#/\.#', $path)) return $deny(403);
if (preg_match('#^/(db|lib|tools|tests|deploy|docs|_composer_vendor)(/|$)#', $path)) return $deny(403);
if (preg_match('#\.(sql|md|tsv|neon|lock|log)$#', $path)) return $deny(403);
if (preg_match('#^/(config\.php|config\.example\.php|composer\.json|VERSION)$#', $path)) return $deny(403);

if (in_array($path, ['/index.php', '/og.php', '/prewarm.php', '/migrate.php'], true)) {
    require $root . $path;      // nginx allows the maintenance scripts from 127.0.0.1
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
