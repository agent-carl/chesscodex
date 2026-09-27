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
 *   vendor/chess.min.js    ← vendor/chess.js without comments and indentation
 *                            (its licence header kept)
 *
 * Imports of vendor modules in the .min.js files get the same ?v=<hash> the
 * layout's modulepreload uses ($asset in templates/layout.php), so a board
 * page fetches each module once, and a changed vendor file gets a new URL.
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
    // calc() and friends need the spaces around + and -: set their insides aside.
    $math = [];
    $s = preg_replace_callback('/\b(?:calc|min|max|clamp)(\((?:[^()]++|(?1))*\))/', static function (array $m) use (&$math): string {
        $math[] = $m[0];
        return "\x00" . (count($math) - 1) . "\x00";
    }, (string) $s);
    $s = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', (string) $s);
    $s = preg_replace('/;}/', '}', (string) $s);
    $s = preg_replace_callback('/\x00(\d+)\x00/', static fn (array $m): string => $math[(int) $m[1]], (string) $s);
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

/** First 8 hex of the xxh3 hash, as $asset() in templates/layout.php. */
function asset_version(string $abs): string
{
    $hash = @hash_file('xxh3', $abs);
    return $hash !== false ? substr($hash, 0, 8) : '1';
}

/** "import … from '../vendor/x.js'" → "…/vendor/x.js?v=<hash>". */
function version_vendor_imports(string $js, string $vendorDir): string
{
    return (string) preg_replace_callback(
        "#(from\s+'\.\./vendor/)([\w.-]+\.js)(')#",
        static function (array $m) use ($vendorDir): string {
            $abs = "$vendorDir/{$m[2]}";
            return is_file($abs) ? $m[1] . $m[2] . '?v=' . asset_version($abs) . $m[3] : $m[0];
        },
        $js
    );
}

$pub = __DIR__ . '/../public';
$vendor = __DIR__ . '/../vendor';

// chess.js: comments (after the licence header) and leading indentation out.
// The file has no semicolons, so lines stay as they are; its template
// literals are all on one line, so no string loses spaces.
$chessSrc = (string) file_get_contents("$vendor/chess.js");
$headerEnd = strpos($chessSrc, '*/') + 2;
$chessMin = substr($chessSrc, 0, $headerEnd) . "\n"
    . preg_replace('/^[ \t]+/m', '', minify_js(substr($chessSrc, $headerEnd)));
file_put_contents("$vendor/chess.min.js", $chessMin);
printf("  ok    %-12s -> %-16s  %5d -> %5d\n", 'chess.js', 'chess.min.js', strlen($chessSrc), strlen($chessMin));
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
    $min = $kind === 'css' ? minify_css($src) : version_vendor_imports(minify_js($src), $vendor);
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
