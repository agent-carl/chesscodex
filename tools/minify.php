<?php
declare(strict_types=1);

/**
 * One-shot minifier for our public/ assets. Run from CLI:
 *
 *   php tools/minify.php
 *
 * Produces:
 *   public/style.min.css   ← public/css/*.css joined in filename order (the
 *                            numbered prefixes keep the cascade order)
 *   public/<foo>.min.js    ← from public/<foo>.js (app, search, play, sw, theme, opening, admin, home, site, …)
 *
 * Re-run after every edit to a source file. Templates reference the .min
 * variants (with ?v=mtime cache-bust), so updates ship correctly.
 *
 * Conservative minification: strips comments + collapses whitespace where
 * safe. No identifier renaming, no AST rewriting — keeps code valid even
 * for ES modules with relative-path imports.
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

function minify_css(string $src): string
{
    $s = preg_replace('!/\*.*?\*/!s', '', $src);
    $s = preg_replace('/\s+/', ' ', $s);
    $s = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $s);
    $s = preg_replace('/;}/', '}', $s);
    return trim((string) $s);
}

function minify_js(string $src): string
{
    // Strip /* ... */ block comments. (Be aware: this also strips JSDoc but
    // we don't use JSDoc.)
    $s = preg_replace('!/\*.*?\*/!s', '', $src);
    // Strip // line comments — only when they start the line (with optional
    // leading whitespace), to avoid breaking URLs like https:// in strings.
    $s = preg_replace('/^\s*\/\/.*$/m', '', (string) $s);
    // Collapse runs of blank lines.
    $s = preg_replace("/\n\s*\n+/", "\n", (string) $s);
    // Trim trailing whitespace per line.
    $s = preg_replace('/[ \t]+$/m', '', (string) $s);
    return trim((string) $s);
}

$pub = __DIR__ . '/../public';
$tasks = [
    ['css/*.css',   'style.min.css',   'css'],
    ['app.js',      'app.min.js',      'js'],
    ['search.js',   'search.min.js',   'js'],
    ['play.js',     'play.min.js',     'js'],
    ['sw.js',       'sw.min.js',       'js'],
    ['theme.js',    'theme.min.js',    'js'],
    ['opening.js',  'opening.min.js',  'js'],
    ['admin.js',    'admin.min.js',    'js'],
    ['home.js',     'home.min.js',     'js'],
    ['train.js',    'train.min.js',    'js'],
    ['repertoire.js', 'repertoire.min.js', 'js'],
    ['site.js',     'site.min.js',     'js'],
];

$totalSrc = 0; $totalMin = 0;
foreach ($tasks as [$in, $out, $kind]) {
    $inPaths = glob("$pub/$in") ?: [];   // sorted; a plain file name matches itself
    $outPath = "$pub/$out";
    if (!$inPaths) {
        fwrite(STDERR, "  skip  $in (missing)\n");
        continue;
    }
    $src = implode('', array_map('file_get_contents', $inPaths));
    $min = $kind === 'css' ? minify_css($src) : minify_js($src);
    file_put_contents($outPath, $min);
    $sLen = strlen($src); $mLen = strlen($min);
    $totalSrc += $sLen; $totalMin += $mLen;
    $pct = $sLen > 0 ? round(($sLen - $mLen) * 100 / $sLen, 1) : 0;
    printf("  ok    %-12s -> %-16s  %5d -> %5d  (-%.1f%%)\n", $in, $out, $sLen, $mLen, $pct);
}

if ($totalSrc > 0) {
    $pct = round(($totalSrc - $totalMin) * 100 / $totalSrc, 1);
    printf("\ntotal: %d -> %d  (-%.1f%%)\n", $totalSrc, $totalMin, $pct);
}
