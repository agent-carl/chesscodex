<?php
declare(strict_types=1);

/**
 * Dynamic Open Graph / Twitter Card image generator. Produces a PNG:
 *   /og.php                          → 1200×630 site-wide card (homepage, search)
 *   /og.php?slug=<slug>              → 1200×630 card: ECO, name and the position
 *   /og.php?slug=<slug>&kind=diagram → 720×720 diagram of the position, shown on
 *                                      the opening page (and to Google Images)
 *
 * Cached on disk (db/og_cache/) for 30 days per slug so repeated crawler
 * hits don't burn GD time. A card drawn before this file was last deployed
 * is redrawn — by ctime, since rsync keeps the laptop's mtime, which can be
 * older than cards the previous code drew. The og:image URL carries
 * ?v=<hash of this file> (layout.php, opening.php), so after an edit
 * Cloudflare and social sites fetch the new card instead of the one they
 * keep for 30 days.
 *
 * Requires PHP GD with PNG + TTF support. OVH shared hosting ships both.
 */

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/ChessEngine.php';

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

$diagram = ($_GET['kind'] ?? '') === 'diagram';
if ($diagram && $validatedSlug === '') {   // nothing to draw
    http_response_code(404);
    exit;
}

$cacheDir = __DIR__ . '/db/og_cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
$cacheKey  = ($diagram ? 'diagram-' : '') . ($validatedSlug !== '' ? $validatedSlug : '__site__');
$cachePath = $cacheDir . '/' . $cacheKey . '.png';
// Defensive: only treat the cached file as a hit if it's non-trivially sized.
// A 0-byte file means a previous write was interrupted — better regenerate.
if (is_file($cachePath) && filesize($cachePath) > 1024 && (time() - filemtime($cachePath)) < 2592000
    && filemtime($cachePath) >= filectime(__FILE__)) {
    header('X-Cache: HIT');
    readfile($cachePath);
    exit;
}
// Re-point $slug to the validated one (used by the rendering below).
$slug = $validatedSlug;

// ----------------------------------------------------------------------
// Render
// ----------------------------------------------------------------------
$row = null;
if ($slug !== '') {
    $stmt = chess_codex_db()->prepare(
        "SELECT eco, name, fen, pgn_moves FROM codex_openings WHERE slug = :s LIMIT 1"
    );
    $stmt->execute(['s' => $slug]);
    $row = $stmt->fetch() ?: null;
}
$fen = $row ? (string) $row['fen'] : 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';
try {
    $history  = $row ? ChessEngine::fromPgn((string) $row['pgn_moves'])->uciHistory() : [];
    $lastMove = $history ? (string) end($history) : null;
} catch (Throwable) {
    $lastMove = null;
}
$pieceFont = og_piece_font();

if ($diagram) {
    // 84 px squares, rank numbers on the left and files below.
    $im = imagecreatetruecolor(720, 720);
    imagefilledrectangle($im, 0, 0, 720, 720, imagecolorallocate($im, 0xfa, 0xf7, 0xf0));
    og_draw_board($im, $fen, 32, 16, 84, $lastMove, $pieceFont);
    $label = imagecolorallocate($im, 0x6b, 0x6b, 0x6b);
    for ($i = 0; $i < 8; $i++) {
        if ($pieceFont !== null) {
            imagettftext($im, 13, 0, 10, 16 + $i * 84 + 48, $label, $pieceFont, (string) (8 - $i));
            imagettftext($im, 13, 0, 32 + $i * 84 + 36, 712, $label, $pieceFont, chr(97 + $i));
        } else {
            imagestring($im, 4, 12, 16 + $i * 84 + 34, (string) (8 - $i), $label);
            imagestring($im, 4, 32 + $i * 84 + 38, 696, chr(97 + $i), $label);
        }
    }
    @imagepng($im, $cachePath, 6);
    header('X-Cache: MISS');
    imagepng($im, null, 6);
    exit;
}

$W = 1200; $H = 630;
$im = imagecreatetruecolor($W, $H);
imageantialias($im, true);

$bg     = imagecolorallocate($im, 0x16, 0x1f, 0x2e);  // deep navy
$accent = imagecolorallocate($im, 0x6e, 0xa3, 0xd4);  // codex accent
$fg     = imagecolorallocate($im, 0xfa, 0xf7, 0xf0);  // warm white
$muted  = imagecolorallocate($im, 0x9a, 0xbb, 0xd9);

imagefilledrectangle($im, 0, 0, $W, $H, $bg);

// The position on the right: the opening's, or the starting one on the site card.
$sq = 60;
$boardLeft = $W - $sq * 8 - 80;
$boardTop  = intdiv($H - $sq * 8, 2);
og_draw_board($im, $fen, $boardLeft, $boardTop, $sq, $lastMove, $pieceFont);

if ($row) {
    $eco   = (string) $row['eco'];
    $title = (string) $row['name'];
    $sub   = 'Caissa Codex · chesscodex.org';
} else {
    $eco   = '';
    $title = 'Caissa Codex';
    $sub   = 'Chess openings · chesscodex.org';
}

$padL = 80;
// Text stays left of the board, 40 px clear of it.
$maxW = (int) $boardLeft - 40 - $padL;
// TrueType when the host has a font (DejaVu on the Pi, Arial on Windows),
// else GD's built-in bitmap fonts, scaled up pixel by pixel.
$ttf = self_first_existing_font();

// ECO tag (if any): the code centred in an accent box.
$tagTop = 80; $tagH = 56;
if ($eco !== '') {
    if ($ttf !== null) {
        $b     = imagettfbbox(26, 0, $ttf, $eco) ?: array_fill(0, 8, 0);
        $codeW = $b[2] - $b[0];
        $tagW  = max(120, $codeW + 44);
        imagefilledrectangle($im, $padL, $tagTop, $padL + $tagW, $tagTop + $tagH, $accent);
        imagettftext($im, 26, 0,
            (int) ($padL + ($tagW - $codeW) / 2 - $b[0]),
            (int) ($tagTop + ($tagH - ($b[1] - $b[5])) / 2 - $b[5]),
            $bg, $ttf, $eco);
    } else {
        $codeW = imagefontwidth(5) * strlen($eco) * 2;
        $tagW  = max(120, $codeW + 44);
        imagefilledrectangle($im, $padL, $tagTop, $padL + $tagW, $tagTop + $tagH, $accent);
        og_pixel_text($im, $eco, 5, 2, (int) ($padL + ($tagW - $codeW) / 2),
            (int) ($tagTop + ($tagH - imagefontheight(5) * 2) / 2), $bg, $accent);
    }
}

// Title: wrapped by measured width at the largest size whose lines fit
// between the tag and the subtitle. Lines may also break after a hyphen
// ("Bobotsov-Korchnoi-Petrosian" is too wide for one line); if nothing fits,
// the last line ends with an ellipsis.
$titleTop = 176; $titleH = 320;
$pieces   = og_pieces($title);
if ($ttf !== null) {
    foreach ([54, 50, 46, 42, 38, 34, 30] as $size) {
        $measure  = static fn (string $s): int => og_ttf_width($ttf, $size, $s);
        $lines    = og_wrap($pieces, $maxW, $measure);
        $b        = imagettfbbox($size, 0, $ttf, 'Hgjy') ?: array_fill(0, 8, 0);
        $ascent   = -$b[5];
        $step     = (int) round($size * 4 / 3 * 1.15);   // GD draws at 96 dpi: 1 pt = 4/3 px
        $maxLines = intdiv($titleH - $ascent - $b[1], $step) + 1;
        if (count($lines) <= $maxLines && max([0, ...array_map($measure, $lines)]) <= $maxW) break;
    }
    foreach (og_fit_lines($lines, $maxLines, $maxW, $measure, '…') as $i => $line) {
        imagettftext($im, $size, 0, $padL, $titleTop + $ascent + $i * $step, $fg, $ttf, $line);
    }
} else {
    // Built-in font 5 (9×15 px) at 3×, or 2× for long titles.
    foreach ([3, 2] as $scale) {
        $measure  = static fn (string $s): int => imagefontwidth(5) * strlen($s) * $scale;
        $lines    = og_wrap($pieces, $maxW, $measure);
        $step     = 20 * $scale;
        $maxLines = intdiv($titleH - 15 * $scale, $step) + 1;
        if (count($lines) <= $maxLines && max([0, ...array_map($measure, $lines)]) <= $maxW) break;
    }
    foreach (og_fit_lines($lines, $maxLines, $maxW, $measure, '...') as $i => $line) {
        og_pixel_text($im, $line, 5, $scale, $padL, $titleTop + $i * $step, $fg, $bg);
    }
}

// Subtitle, shrunk (22 → 16 pt) until it fits left of the board.
if ($ttf !== null) {
    foreach ([22, 20, 18, 16] as $subSize) {
        if (og_ttf_width($ttf, $subSize, $sub) <= $maxW) break;
    }
    [$subLine] = og_fit_lines([$sub], 1, $maxW,
        static fn (string $s): int => og_ttf_width($ttf, $subSize, $s), '…');
    imagettftext($im, $subSize, 0, $padL, $H - 80, $muted, $ttf, $subLine);
} else {
    $subScale = imagefontwidth(4) * strlen($sub) * 2 <= $maxW ? 2 : 1;
    og_pixel_text($im, $sub, 4, $subScale, $padL, $H - 80 - imagefontheight(4) * $subScale, $muted, $bg);
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

/**
 * A font with DejaVu's chess glyphs (U+2654–265F), as on the Pi. Not Segoe
 * UI Symbol: its solid pawn has a hollow body and reads as a white one.
 */
function og_piece_font(): ?string
{
    // DejaVu on the Pi; Segoe UI Symbol for the local copy on Windows.
    foreach (['/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
              '/usr/share/fonts/dejavu/DejaVuSans.ttf', 'C:/Windows/Fonts/seguisym.ttf'] as $f) {
        if (@is_file($f)) return $f;
    }
    return null;
}

/**
 * The position of $fen as a diagram: $sq-pixel squares from ($x, $y), the
 * last move's squares tinted as on the site's board. A white piece is the
 * solid glyph in white under the outline glyph in black; without a font the
 * board is drawn empty.
 */
function og_draw_board(GdImage $im, string $fen, int $x, int $y, int $sq, ?string $lastMove, ?string $font): void
{
    $colors = [
        'light' => imagecolorallocate($im, 0xf0, 0xd9, 0xb5), 'dark'   => imagecolorallocate($im, 0xb5, 0x88, 0x63),
        'hiL'   => imagecolorallocate($im, 0xcd, 0xd2, 0x6a), 'hiD'    => imagecolorallocate($im, 0xaa, 0xa2, 0x3a),
        'white' => imagecolorallocate($im, 0xff, 0xff, 0xff), 'black'  => imagecolorallocate($im, 0x00, 0x00, 0x00),
    ];
    $hi = $lastMove !== null ? [substr($lastMove, 0, 2), substr($lastMove, 2, 2)] : [];
    for ($r = 0; $r < 8; $r++) {
        for ($f = 0; $f < 8; $f++) {
            $lightSq = ($r + $f) % 2 === 0;
            $name    = chr(97 + $f) . (8 - $r);
            $color   = in_array($name, $hi, true) ? ($lightSq ? $colors['hiL'] : $colors['hiD']) : ($lightSq ? $colors['light'] : $colors['dark']);
            imagefilledrectangle($im, $x + $f * $sq, $y + $r * $sq, $x + ($f + 1) * $sq - 1, $y + ($r + 1) * $sq - 1, $color);
        }
    }
    if ($font === null) return;

    $solid   = ['k' => '♚', 'q' => '♛', 'r' => '♜', 'b' => '♝', 'n' => '♞', 'p' => '♟'];
    $outline = ['k' => '♔', 'q' => '♕', 'r' => '♖', 'b' => '♗', 'n' => '♘', 'p' => '♙'];
    $size    = $sq * 0.6;    // points; GD renders at 96 dpi, so ~0.8 of the square
    // One baseline for every piece, from the king's box, as in printed diagrams.
    $kb      = imagettfbbox($size, 0, $font, $solid['k']) ?: array_fill(0, 8, 0);
    $baseOff = intdiv($sq - ($kb[1] - $kb[5]), 2) - $kb[5];
    foreach (explode('/', explode(' ', $fen)[0]) as $r => $rank) {
        $f = 0;
        foreach (str_split($rank) as $ch) {
            if (ctype_digit($ch)) { $f += (int) $ch; continue; }
            $p  = strtolower($ch);
            $b  = imagettfbbox($size, 0, $font, $solid[$p]) ?: array_fill(0, 8, 0);
            $gx = $x + $f * $sq + intdiv($sq - ($b[2] - $b[0]), 2) - $b[0];
            $gy = $y + $r * $sq + $baseOff;
            if (ctype_upper($ch)) {
                imagettftext($im, $size, 0, $gx, $gy, $colors['white'], $font, $solid[$p]);
                imagettftext($im, $size, 0, $gx, $gy, $colors['black'], $font, $outline[$p]);
            } else {
                imagettftext($im, $size, 0, $gx, $gy, $colors['black'], $font, $solid[$p]);
            }
            $f++;
        }
    }
}

/** Width in px of $s drawn with imagettftext() from x = 0 (bearing included). */
function og_ttf_width(string $ttf, float $size, string $s): int
{
    $b = imagettfbbox($size, 0, $ttf, $s);
    return $b ? max($b[2], $b[4]) : 0;
}

/**
 * Splits a title into the pieces a line may break between: words, and the
 * parts of hyphenated words (after each "-"). Returns [[text, spaceBefore], …].
 */
function og_pieces(string $text): array
{
    $pieces = [];
    foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w => $word) {
        foreach (preg_split('/(?<=-)(?=.)/u', $word) ?: [$word] as $p => $part) {
            $pieces[] = [$part, $w > 0 && $p === 0];
        }
    }
    return $pieces;
}

/** Greedy wrap of og_pieces() into lines no wider than $maxW where possible. */
function og_wrap(array $pieces, int $maxW, callable $measure): array
{
    $lines = [];
    $cur   = '';
    foreach ($pieces as [$text, $space]) {
        $candidate = $cur === '' ? $text : $cur . ($space ? ' ' : '') . $text;
        if ($cur !== '' && $measure($candidate) > $maxW) {
            $lines[] = $cur;
            $cur     = $text;
        } else {
            $cur = $candidate;
        }
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines;
}

/**
 * Keeps at most $maxLines lines. The last kept line of a longer title, and
 * any line still wider than $maxW, lose words (or letters, for one long
 * word) until they fit with $ellipsis appended.
 */
function og_fit_lines(array $lines, int $maxLines, int $maxW, callable $measure, string $ellipsis): array
{
    $cut   = count($lines) > $maxLines;
    $lines = array_slice($lines, 0, max(1, $maxLines));
    $last  = count($lines) - 1;
    foreach ($lines as $i => $line) {
        if ($measure($line) <= $maxW && !($cut && $i === $last)) continue;
        $s = $line;
        while ($s !== '' && $measure(rtrim($s, ' ,:;-') . $ellipsis) > $maxW) {
            $space = mb_strrpos($s, ' ');
            $s = $space !== false ? mb_substr($s, 0, $space) : mb_substr($s, 0, -1);
        }
        $lines[$i] = rtrim($s, ' ,:;-') . $ellipsis;
    }
    return $lines;
}

/** Draws $s in built-in font $font, scaled $scale× pixel by pixel, top-left at ($x, $y). */
function og_pixel_text(GdImage $im, string $s, int $font, int $scale, int $x, int $y, int $fg, int $bg): void
{
    $w   = max(1, imagefontwidth($font) * strlen($s));
    $h   = imagefontheight($font);
    $tmp = imagecreatetruecolor($w, $h);
    imagefilledrectangle($tmp, 0, 0, $w, $h, $bg);
    imagestring($tmp, $font, 0, 0, $s, $fg);
    imagecopyresized($im, $tmp, $x, $y, 0, 0, $w * $scale, $h * $scale, $w, $h);
}
