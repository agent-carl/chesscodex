<?php
declare(strict_types=1);

require_once __DIR__ . '/Opening.php';
require_once __DIR__ . '/parser.php';

/**
 * All HTTP route handlers as static methods. Each method either echoes its
 * own response (API/text endpoints) or sets up template + data and includes
 * the appropriate template at the bottom.
 *
 * Handlers expect global $baseUrl, $siteUrl, $render404 (closure) to be in
 * scope from index.php.
 *
 * The pages are here; the JSON/POST endpoints are in RoutesApi.php and the
 * admin area in RoutesAdmin.php (traits, so index.php still routes to
 * Routes::method for all of them).
 */
final class Routes
{
    use RoutesApi;
    use RoutesAdmin;
    public static function robots(): void
    {
        global $siteUrl, $baseUrl;
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        // Explicit Allow for the indexable namespaces, then Disallow the
        // dynamic / private ones. Putting Allow first makes Google's parser
        // unambiguously treat /openings as crawlable.
        // /play/ is NOT disallowed: its pages carry <meta robots noindex>,
        // which crawlers can only obey if they may fetch them — blocked, the
        // 3,690 linked /play/ URLs could be indexed without content. /search
        // (the opening identifier) is an ordinary indexable page.
        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "Allow: /openings\n";
        echo "Allow: /about\n";
        echo "Disallow: /admin\n";
        echo "Disallow: /api/\n";
        echo "Disallow: /random\n";
        echo "Disallow: /*.pgn$\n\n";   // PGN downloads of opening trees

        // Common abusive crawlers — block to save bandwidth + reduce noise
        // in stats. These ignore robots.txt half the time but it documents
        // intent for those that respect it.
        echo "User-agent: AhrefsBot\nDisallow: /\n\n";
        echo "User-agent: SemrushBot\nDisallow: /\n\n";
        echo "User-agent: MJ12bot\nDisallow: /\n\n";
        echo "User-agent: DotBot\nDisallow: /\n\n";
        echo "User-agent: PetalBot\nDisallow: /\n\n";

        echo "Sitemap: {$siteUrl}{$baseUrl}/sitemap.xml\n";
    }

    /**
     * The sitemap is split into parts, so Search Console reports how many
     * URLs of each kind are indexed: the plain pages, the ECO pages, the
     * openings with a written description, and the other openings by
     * popularity (the top 500, the next 1,000, the rest).
     */
    public const SITEMAP_PARTS = ['pages', 'eco', 'openings-described', 'openings-top', 'openings-mid', 'openings-rest'];

    /** /sitemap.xml — the index of the parts. */
    public static function sitemap(): void
    {
        self::serveSitemap('index');
    }

    /** /sitemaps/<part>.xml */
    public static function sitemapPart(string $part): void
    {
        global $render404;
        if (!in_array($part, self::SITEMAP_PARTS, true)) $render404();
        self::serveSitemap($part);
    }

    /**
     * Serves one sitemap file from db/cache/sitemap/, rebuilding all of them
     * once a day (delete that folder to rebuild sooner, e.g. after adding URLs).
     */
    private static function serveSitemap(string $name): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        $dir  = __DIR__ . '/../db/cache/sitemap';
        $path = "$dir/$name.xml";
        // A file under 100 bytes is a broken write, never a real sitemap.
        if (is_file($path) && filesize($path) > 100 && is_file("$dir/index.xml")
            && (time() - filemtime("$dir/index.xml")) < 86400) {
            header('X-Cache: HIT');
            readfile($path);
            return;
        }
        $files = self::buildSitemaps();
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        foreach ($files as $file => $xml) {
            @file_put_contents("$dir/$file.xml.tmp", $xml);
            @rename("$dir/$file.xml.tmp", "$dir/$file.xml");
        }
        header('X-Cache: MISS');
        echo $files[$name];
    }

    /** @return array<string, string> file name (without .xml) => XML, the index under 'index' */
    private static function buildSitemaps(): array
    {
        global $siteUrl, $baseUrl;
        require_once __DIR__ . '/db.php';
        $base   = $siteUrl . $baseUrl;
        $escUrl = static fn (string $u): string => htmlspecialchars($u, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // No <changefreq> or <priority>: Google ignores them. <lastmod> only
        // where it's accurate — stamping today's date on every URL every day
        // taught search engines to ignore it. (English-only — no hreflang.)
        // $image: the page's position diagram, for Google Images.
        $row = static fn (string $loc, ?string $lastmod = null, ?string $image = null): string => '  <url><loc>' . $escUrl($loc) . '</loc>'
            . ($lastmod ? '<lastmod>' . $lastmod . '</lastmod>' : '')
            . ($image ? '<image:image><image:loc>' . $escUrl($image) . '</image:loc></image:image>' : '') . "</url>\n";
        $rows    = array_fill_keys(self::SITEMAP_PARTS, '');
        $lastmod = array_fill_keys(self::SITEMAP_PARTS, null);

        $rows['pages'] .= $row($base . '/');
        $rows['pages'] .= $row($base . '/openings');
        $rows['pages'] .= $row($base . '/search');   // the opening identifier
        $rows['pages'] .= $row($base . '/about');
        foreach (array_keys(Opening::allAlphabetical()) as $letter) {
            if (preg_match('/^[A-Z]$/', (string) $letter)) $rows['pages'] .= $row($base . '/openings/letter/' . strtolower((string) $letter));
        }
        $rows['pages'] .= $row($base . '/rankings');
        foreach (array_keys(Rankings::LABELS) as $path) $rows['pages'] .= $row($base . '/' . $path);
        foreach (['best-openings-for-white', 'best-openings-for-black'] as $path) {
            foreach (array_keys(LevelStats::LEVELS) as $level) $rows['pages'] .= $row($base . '/' . $path . '/' . $level);
        }

        $rows['eco'] .= $row($base . '/eco');
        foreach (Opening::ecoCodes() as $code => $c) {
            if ($c['count'] > 1) $rows['eco'] .= $row($base . '/eco/' . $code);   // single-line codes are noindex
        }

        // Opening pages change when their Lichess numbers are refreshed, so
        // that date is their <lastmod>. Not the thin lines (noindex on their
        // pages, see Opening::isThin).
        $updated = array_column(Rankings::all(), 'updated', 'slug');
        $stmt = chess_codex_db()->query(
            "SELECT slug, name, popularity, (description IS NOT NULL AND description <> '') AS described
             FROM codex_openings ORDER BY popularity DESC, move_count ASC, id"
        );
        $rank = 0;
        foreach ($stmt as $r) {
            if (Opening::isThin($r)) continue;
            if ($r['described']) {
                $part = 'openings-described';
            } else {
                $rank++;
                $part = $rank <= 500 ? 'openings-top' : ($rank <= 1500 ? 'openings-mid' : 'openings-rest');
            }
            $date = $updated[$r['slug']] ?? null;
            $rows[$part] .= $row($base . '/openings/' . $r['slug'], $date, $base . Opening::diagramPath((string) $r['slug'], true));
            if ($date !== null && ($lastmod[$part] === null || $date > $lastmod[$part])) $lastmod[$part] = $date;
        }

        $files = [];
        $index = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
               . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (self::SITEMAP_PARTS as $part) {
            $files[$part] = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
                . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n"
                . $rows[$part] . '</urlset>' . "\n";
            $index .= '  <sitemap><loc>' . $escUrl("$base/sitemaps/$part.xml") . '</loc>'
                . ($lastmod[$part] ? '<lastmod>' . $lastmod[$part] . '</lastmod>' : '') . "</sitemap>\n";
        }
        $files['index'] = $index . '</sitemapindex>' . "\n";
        return $files;
    }

    public static function home(): void
    {
        global $baseUrl, $siteUrl;
        require_once __DIR__ . '/Views.php';
        Views::mark('home');
        $counts = Opening::countsByGroup();
        $groups = [
            ['letter' => 'A', 'label_key' => 'group.A'],
            ['letter' => 'B', 'label_key' => 'group.B'],
            ['letter' => 'C', 'label_key' => 'group.C'],
            ['letter' => 'D', 'label_key' => 'group.D'],
            ['letter' => 'E', 'label_key' => 'group.E'],
        ];
        foreach ($groups as &$g) {
            $g['count'] = $counts[$g['letter']] ?? 0;
            $g['root_slug'] = Opening::rootSlugForGroup($g['letter']);
            $g['label'] = t($g['label_key']);
        }
        unset($g);
        $popular = Opening::topPopular(12);
        $gambits = Opening::topGambits(12);
        $featured = Opening::ofTheDay();
        require __DIR__ . '/../templates/home.php';
    }

    public static function about(): void
    {
        global $baseUrl, $siteUrl;
        require __DIR__ . '/../templates/about.php';
    }

    /**
     * Random-opening redirector — picks a random slug and 302s to it.
     * Used by the "Random" link in the header for casual discovery.
     */
    public static function random(): void
    {
        global $baseUrl, $render404;
        $slug = Opening::randomSlug();
        if ($slug === null) $render404('No openings indexed yet.');
        header('Location: ' . $baseUrl . I18n::url('/openings/' . $slug));
        exit;
    }

    /**
     * Alphabetical browse index — flat list of every opening grouped by first
     * letter. The big page that lets users discover openings they don't know
     * the moves of.
     */
    public static function openingsIndex(): void
    {
        global $baseUrl, $siteUrl;
        Views::mark('index');
        $counts   = array_map('count', Opening::allAlphabetical());
        $total    = array_sum($counts);
        $families = Opening::familiesByLetter();
        require __DIR__ . '/../templates/openings_index.php';
    }

    /** /openings/letter/a — every named line starting with that letter. */
    public static function openingsLetter(string $letter): void
    {
        global $baseUrl, $siteUrl, $render404;
        $grouped = Opening::allAlphabetical();
        $letter  = strtoupper($letter);
        if (!isset($grouped[$letter])) $render404('No openings start with that letter.');
        Views::mark('index');
        $rows   = $grouped[$letter];
        $counts = array_map('count', $grouped);
        require __DIR__ . '/../templates/openings_letter.php';
    }

    /** /openings/<slug>.pgn — the line and its named continuations as one PGN tree. */
    public static function openingPgn(string $slug): void
    {
        global $siteUrl, $baseUrl, $render404;
        $o = Opening::findBySlug($slug);
        if ($o === null) $render404('No opening by that name.');
        $pgn = PgnTree::build(
            Opening::withContinuations($o),
            $o['name'] . ' — named lines',
            $siteUrl . $baseUrl . I18n::url('/openings/' . $o['slug']),
            ['ECO' => $o['eco'], 'Opening' => $o['name'], 'Annotator' => 'Caissa Codex (chesscodex.org)']
        );
        header('Content-Type: application/x-chess-pgn; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $o['slug'] . '.pgn"');
        header('X-Robots-Tag: noindex');
        header('Cache-Control: public, max-age=0, s-maxage=86400');
        echo $pgn;
    }

    /** /train/<slug> — practise the line (or its named continuations) move by move. */
    public static function train(string $slug): void
    {
        global $baseUrl, $siteUrl, $render404;
        $opening = Opening::findBySlug($slug);
        if ($opening === null) $render404('No opening matches that URL.');
        Views::mark('train', (int) $opening['id']);
        $lines = array_slice(Opening::withContinuations($opening), 0, 300);
        require __DIR__ . '/../templates/train.php';
    }

    /** /repertoire — the lines saved with "+ Repertoire" (kept in the browser). */
    public static function repertoire(): void
    {
        global $baseUrl, $siteUrl;
        Views::mark('repertoire');
        require __DIR__ . '/../templates/repertoire.php';
    }

    /** /rankings — the hub the header links to. */
    public static function rankingsIndex(): void
    {
        global $baseUrl, $siteUrl;
        Views::mark('ranking');
        require __DIR__ . '/../templates/rankings_index.php';
    }

    /** Rankings from the cached Lichess numbers: best for White / Black, most popular, gambits. */
    public static function ranking(string $page, ?string $level = null): void
    {
        global $baseUrl, $siteUrl;
        Views::mark('ranking');
        $rows = Rankings::rows($page, $level);
        require __DIR__ . '/../templates/ranking.php';
    }

    /** All ECO codes, A00–E99, grouped by volume. */
    public static function ecoIndex(): void
    {
        global $baseUrl, $siteUrl;
        Views::mark('eco');
        $codes = Opening::ecoCodes();
        require __DIR__ . '/../templates/eco_index.php';
    }

    /** One ECO code: the named lines filed under it. */
    public static function eco(string $code): void
    {
        global $baseUrl, $siteUrl, $render404;
        if ($code !== strtoupper($code)) {           // /eco/b20 → /eco/B20
            header('Location: ' . $baseUrl . I18n::url('/eco/' . strtoupper($code)), true, 301);
            exit;
        }
        $codes = Opening::ecoCodes();
        if (!isset($codes[$code])) $render404('No named lines under that ECO code.');
        Views::mark('eco');
        $info  = $codes[$code];
        $lines = Opening::byEco($code);
        $keys  = array_keys($codes);
        $pos   = (int) array_search($code, $keys, true);
        $prevCode = $keys[$pos - 1] ?? null;
        $nextCode = $keys[$pos + 1] ?? null;
        require __DIR__ . '/../templates/eco.php';
    }

    public static function search(): void
    {
        global $baseUrl, $siteUrl;
        require_once __DIR__ . '/Views.php';
        Views::mark('search');
        require __DIR__ . '/../templates/search.php';
    }

    public static function opening(string $slug): void
    {
        global $baseUrl, $siteUrl, $render404;
        $opening = Opening::findBySlug($slug);
        if ($opening === null) $render404('No opening matches that URL.');
        Views::mark('opening', (int) $opening['id']);
        $parent      = $opening['parent_id'] ? Opening::findById((int) $opening['parent_id']) : null;
        $children    = Opening::children((int) $opening['id']);
        $ancestors   = Opening::ancestors((int) $opening['id']);
        $siblings    = Opening::siblings((int) $opening['id'], (int) ($opening['parent_id'] ?? 0));
        // Hybrid: render the subtree inline when small (cheap, full SEO),
        // lazy-load via /api/subtree/<id> when big (avoids 50+ KB of unused
        // HTML on every page load for root openings like Sicilian Defense).
        $descendantCount = Opening::descendantCount((int) $opening['id']);
        $descendants     = ($descendantCount > 0 && $descendantCount <= 50)
            ? Opening::descendants((int) $opening['id'])
            : [];
        require __DIR__ . '/../templates/opening.php';
    }

    public static function play(string $slug): void
    {
        global $baseUrl, $siteUrl, $render404;
        $opening = Opening::findBySlug($slug);
        if ($opening === null) $render404('No opening matches that URL.');
        require_once __DIR__ . '/Views.php';
        Views::mark('play', (int) $opening['id']);
        require __DIR__ . '/../templates/play.php';
    }
}
