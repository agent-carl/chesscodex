<?php
declare(strict_types=1);

/**
 * Dynamic Open Graph / Twitter Card image generator. Produces a 1200×630 PNG
 * for either:
 *   /og.php             → site-wide branded card (homepage, search)
 *   /og.php?slug=<slug> → opening-specific card with ECO + name
 *
 * Cached on disk (db/og_cache/) for 30 days per slug so repeated crawler
 * hits don't burn GD time. A card older than this file is redrawn, and the
 * og:image URL carries ?v=<hash of this file> (layout.php, opening.php), so
 * after an edit Cloudflare and social sites fetch the new card instead of
 * the one they keep for 30 days.
 *
 * Requires PHP GD with PNG + TTF support. OVH shared hosting ships both.
 */

require __DIR__ . '/lib/db.php';

header('Content-Type: image/png');
header('Cache-Control: public, max-age=2592000, immutable');

if (!function_exists('imagecreatetruecolor')) {
    // GD missing — fall back to the static branded card.
    http_response_code(500);
    exit;
}

$slug = (string) ($_GET['slug'] ?? '');
$slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug)) ?: '';

// Verify the slug refers to a real opening BEFORE we agree to cache anything
// under that key. Otherwise an attacker can write 1000s of og_cache/<random>.png
// files by spraying random slugs. Unknown slugs fall back to the generic
// site-wide card, cached under the fixed "__site__" key.
$validatedSlug = '';
if ($slug !== '') {
    $stmtCheck = chess_codex_db()->prepare(
        "SELECT 1 FROM codex_openings WHERE slug = :s LIMIT 1"
    );
    $stmtCheck->execute(['s' => $slug]);
    if ($stmtCheck->fetchColumn() !== false) $validatedSlug = $slug;
}

$cacheDir = __DIR__ . '/db/og_cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
$cacheKey  = $validatedSlug !== '' ? $validatedSlug : '__site__';
$cachePath = $cacheDir . '/' . $cacheKey . '.png';
// Defensive: only treat the cached file as a hit if it's non-trivially sized.
// A 0-byte file means a previous write was interrupted — better regenerate.
if (is_file($cachePath) && filesize($cachePath) > 1024 && (time() - filemtime($cachePath)) < 2592000
    && filemtime($cachePath) >= filemtime(__FILE__)) {
    header('X-Cache: HIT');
    readfile($cachePath);
    exit;
}
// Re-point $slug to the validated one (used by the rendering below).
$slug = $validatedSlug;

// ----------------------------------------------------------------------
// Render
// ----------------------------------------------------------------------
$W = 1200; $H = 630;
$im = imagecreatetruecolor($W, $H);
imageantialias($im, true);

$bg     = imagecolorallocate($im, 0x16, 0x1f, 0x2e);  // deep navy
$accent = imagecolorallocate($im, 0x6e, 0xa3, 0xd4);  // codex accent
$fg     = imagecolorallocate($im, 0xfa, 0xf7, 0xf0);  // warm white
$muted  = imagecolorallocate($im, 0x9a, 0xbb, 0xd9);

imagefilledrectangle($im, 0, 0, $W, $H, $bg);

// Subtle 8x8 board pattern across the right third.
$sq = 60;
$boardLeft = $W - $sq * 8 - 80;
$boardTop  = ($H - $sq * 8) / 2;
$light = imagecolorallocatealpha($im, 0xfa, 0xf7, 0xf0, 110);
$dark  = imagecolorallocatealpha($im, 0x6e, 0xa3, 0xd4, 115);
for ($r = 0; $r < 8; $r++) {
    for ($c = 0; $c < 8; $c++) {
        $color = (($r + $c) % 2 === 0) ? $light : $dark;
        imagefilledrectangle(
            $im,
            (int) ($boardLeft + $c * $sq),
            (int) ($boardTop  + $r * $sq),
            (int) ($boardLeft + ($c + 1) * $sq),
            (int) ($boardTop  + ($r + 1) * $sq),
            $color
        );
    }
}

// Pick text content.
if ($slug !== '') {
    $stmt = chess_codex_db()->prepare(
        "SELECT eco, name FROM codex_openings WHERE slug = :s LIMIT 1"
    );
    $stmt->execute(['s' => $slug]);
    $row = $stmt->fetch();
    if ($row) {
        $eco   = (string) $row['eco'];
        $title = (string) $row['name'];
        $sub   = 'Caissa Codex · chesscodex.org';
    } else {
        $eco   = '';
        $title = 'Caissa Codex';
        $sub   = 'Chess openings encyclopedia · chesscodex.org';
    }
} else {
    $eco   = '';
    $title = 'Caissa Codex';
    $sub   = 'Chess openings encyclopedia · chesscodex.org';
}

$padL = 80;

// ECO tag (if any).
if ($eco !== '') {
    $tagW = 130; $tagH = 50;
    imagefilledrectangle($im, $padL, 80, $padL + $tagW, 80 + $tagH, $accent);
    // Center the ECO code in the tag using the built-in font.
    $ecoFontW = imagefontwidth(5) * strlen($eco);
    $ecoFontH = imagefontheight(5);
    imagestring(
        $im, 5,
        (int) ($padL + ($tagW - $ecoFontW) / 2),
        (int) (80 + ($tagH - $ecoFontH) / 2),
        $eco, $bg
    );
}

// Title — large, manually wrapped to fit width. We use the built-in pixel
// font (no TTF dependency) which keeps this script bulletproof on shared
// hosting where dejavu paths vary.
//
// Word-wrap at ~26 chars per line, max 3 lines.
$lines = [];
$words = preg_split('/\s+/', trim($title)) ?: [];
$cur = '';
foreach ($words as $w) {
    $candidate = $cur === '' ? $w : $cur . ' ' . $w;
    if (mb_strlen($candidate) > 26 && $cur !== '') {
        $lines[] = $cur;
        $cur = $w;
        if (count($lines) >= 2) break;
    } else {
        $cur = $candidate;
    }
}
if ($cur !== '' && count($lines) < 3) $lines[] = $cur;

// Built-in font 5 is 9×15 px. Scale 6× by drawing each character as a filled
// rectangle of its bits. Easier route: just draw font 5 multiple times offset.
// Simpler: imagettftext if a font is available; else use imagestring scaled.
$ttf = self_first_existing_font();

$y = 200;
foreach ($lines as $line) {
    if ($ttf !== null) {
        // 60px size for title
        $bbox = imagettfbbox(54, 0, $ttf, $line);
        imagettftext($im, 54, 0, $padL, $y + 50, $fg, $ttf, $line);
        $y += 80;
    } else {
        // Fallback: built-in font scaled by pixel duplication via a temp image.
        $tmp = imagecreatetruecolor(800, 30);
        imagefilledrectangle($tmp, 0, 0, 800, 30, $bg);
        imagestring($tmp, 5, 0, 5, $line, $fg);
        imagecopyresampled($im, $tmp, $padL, $y, 0, 0, 800, 90, 800, 30);
        imagedestroy($tmp);
        $y += 90;
    }
}

// Subtitle line.
if ($ttf !== null) {
    imagettftext($im, 22, 0, $padL, $H - 80, $muted, $ttf, $sub);
} else {
    $tmp = imagecreatetruecolor(700, 20);
    imagefilledrectangle($tmp, 0, 0, 700, 20, $bg);
    imagestring($tmp, 4, 0, 0, $sub, $muted);
    imagecopyresampled($im, $tmp, $padL, $H - 90, 0, 0, 700, 40, 700, 20);
    imagedestroy($tmp);
}

// Save to cache then stream.
@imagepng($im, $cachePath, 6);
header('X-Cache: MISS');
imagepng($im, null, 6);
imagedestroy($im);

/**
 * Picks the first existing TrueType font we can find on the host. Returns
 * null if none — caller falls back to imagestring.
 */
function self_first_existing_font(): ?string
{
    static $resolved = false;
    static $path = null;
    if ($resolved) return $path;
    $resolved = true;

    $candidates = [
        // Common Linux/OVH paths
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        // Windows local-dev paths
        'C:\\Windows\\Fonts\\arialbd.ttf',
        'C:\\Windows\\Fonts\\arial.ttf',
    ];
    foreach ($candidates as $c) {
        if (@is_file($c)) { $path = $c; return $path; }
    }
    return null;
}
