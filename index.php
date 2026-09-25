<?php
declare(strict_types=1);

require __DIR__ . '/lib/Autoload.php';   // class-map autoloader: lib/<Class>.php
// Pre-include I18n + Router + Routes — guaranteed-used on every request.
// Anything else (Auth, Views, etc.) loads on first reference through the autoloader.
require __DIR__ . '/lib/I18n.php';
require __DIR__ . '/lib/Router.php';
require __DIR__ . '/lib/Routes.php';

$config  = require __DIR__ . '/config.php';
$baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
$siteUrl = rtrim((string) ($config['site_url'] ?? ''), '/');
if ($siteUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $siteUrl = $scheme . '://' . $host;
}

set_exception_handler(function (Throwable $e) use ($baseUrl, $siteUrl) {
    error_log('[chess-codex] uncaught: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $title = t('error.500.title');
    $body  = '<h1>' . htmlspecialchars(t('error.500.h1'), ENT_QUOTES, 'UTF-8') . '</h1>'
           . '<p>' . htmlspecialchars(t('error.500.body'), ENT_QUOTES, 'UTF-8') . '</p>'
           . '<p><a href="' . htmlspecialchars($baseUrl . '/', ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(t('error.back_home'), ENT_QUOTES, 'UTF-8') . '</a></p>';
    $noindex = true;
    require __DIR__ . '/templates/layout.php';
    exit;
});

// Callers pass a friendly message string but the 404 template renders its
// own fixed copy + similar-opening suggestions, so the message argument is
// ignored. The argument is kept for source-readability — `$render404('No
// such opening.')` reads better than `$render404()`.
$render404 = static function (string $reason = '') use ($baseUrl, $siteUrl) {
    http_response_code(404);
    $title = t('error.404.title');
    $suggestions  = [];
    $failedSlug   = '';
    $rawPath404   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '';
    if (preg_match('#/openings/([^/]+)/?$#', $rawPath404, $m404)) {
        $failedSlug = (string) $m404[1];
        try {
            $suggestions = Opening::suggestSimilar($failedSlug, 5);
        } catch (Throwable $e) { /* don't let the 404 itself error out */ }
    }
    $noindex = true;
    require __DIR__ . '/templates/404.php';
    exit;
};

// Strip query string and base prefix from REQUEST_URI to get the route path.
$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if ($baseUrl !== '' && strpos($rawPath, $baseUrl) === 0) {
    $rawPath = substr($rawPath, strlen($baseUrl));
}
if ($rawPath === '' || $rawPath === false) $rawPath = '/';

// One URL per page: "/openings/x/" answers 301 → "/openings/x" instead of
// serving a duplicate. Only plain [A-Za-z0-9-] segments ("/eco/B20/"), so a
// crafted path like "/\evil.com/" can't turn this into an open redirect.
if (preg_match('#^(/[A-Za-z0-9-]+)+/$#', $rawPath)
    && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    $query = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
    header('Location: ' . $baseUrl . rtrim($rawPath, '/') . ($query !== '' ? '?' . $query : ''), true, 301);
    exit;
}

// I18n::detect is now a pass-through (English-only); keep the call so the
// shape of the dispatch is unchanged if locales ever come back.
[$locale, $path] = I18n::detect($rawPath);

$router = new Router();
$router->add('/robots.txt',          [Routes::class, 'robots']);
$router->add('/sitemap.xml',         [Routes::class, 'sitemap']);
$router->add('/random',              [Routes::class, 'random']);
$router->add('/api/search',          [Routes::class, 'apiSearch']);
$router->add('/api/stats',           [Routes::class, 'apiStats']);
$router->add('/api/suggest',         [Routes::class, 'apiSuggest']);
$router->add('#^/api/subtree/(\d+)$#', [Routes::class, 'apiSubtree']);
$router->add('/admin',               [Routes::class, 'adminDashboard']);
$router->add('/admin/login',         [Routes::class, 'adminLogin']);
$router->add('/admin/logout',        [Routes::class, 'adminLogout']);
$router->add('#^/admin/review/(\d+)$#',        [Routes::class, 'adminReview']);
$router->add('#^/admin/review/(\d+)/accept$#', [Routes::class, 'adminAccept']);
$router->add('#^/admin/review/(\d+)/reject$#', [Routes::class, 'adminReject']);
$router->add('#^/admin/edit/([a-z0-9-]+)/?$#', [Routes::class, 'adminEditOpening']);
$router->add('/admin/preview',       [Routes::class, 'adminPreview']);
$router->add('/admin/bulk',          [Routes::class, 'adminBulk']);
$router->add('/search',              [Routes::class, 'search']);
$router->add('/openings',            [Routes::class, 'openingsIndex']);
$router->add('/eco',                 [Routes::class, 'ecoIndex']);
$router->add('#^/eco/([A-Ea-e][0-9]{2})$#', [Routes::class, 'eco']);
$router->add('/about',               [Routes::class, 'about']);
$router->add('/',                    [Routes::class, 'home']);
$router->add('#^/openings/([a-z0-9-]+)/?$#', [Routes::class, 'opening']);
$router->add('#^/play/([a-z0-9-]+)/?$#',     [Routes::class, 'play']);
$router->setNotFound(static function () use ($render404) { $render404(); });

$router->dispatch($path);
