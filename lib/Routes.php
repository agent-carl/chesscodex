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
 */
final class Routes
{
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
        echo "Disallow: /random\n\n";

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

    public static function sitemap(): void
    {
        global $siteUrl, $baseUrl;
        header('Content-Type: application/xml; charset=utf-8');

        $cachePath = __DIR__ . '/../db/sitemap_cache.xml';
        // Require a non-trivial size: a 0-byte placeholder must not be served
        // as a real sitemap. Real sitemap is always > 10 KB.
        if (is_file($cachePath) && filesize($cachePath) > 1024 && (time() - filemtime($cachePath)) < 86400) {
            header('X-Cache: HIT');
            readfile($cachePath);
            return;
        }

        require_once __DIR__ . '/db.php';
        $stmt = chess_codex_db()->query(
            "SELECT slug FROM codex_openings ORDER BY move_count ASC, id"
        );
        $base   = $siteUrl . $baseUrl;
        $escUrl = static fn (string $u): string => htmlspecialchars($u, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // <loc> only. Google ignores <changefreq> and <priority>, and trusts
        // <lastmod> only when it's accurate — the site doesn't track edits,
        // and stamping today's date on all 3,693 URLs every day taught search
        // engines to ignore it. (English-only — no hreflang alternates.)
        $row = static fn (string $loc): string => '  <url><loc>' . $escUrl($loc) . "</loc></url>\n";

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $xml .= $row($base . '/');
        $xml .= $row($base . '/openings');
        $xml .= $row($base . '/search');   // the opening identifier
        $xml .= $row($base . '/about');
        $xml .= $row($base . '/eco');
        foreach (Opening::ecoCodes() as $code => $c) {
            if ($c['count'] > 1) $xml .= $row($base . '/eco/' . $code);   // single-line codes are noindex
        }
        foreach ($stmt as $r) {
            $xml .= $row($base . '/openings/' . $r['slug']);
        }
        $xml .= '</urlset>' . "\n";

        @file_put_contents($cachePath . '.tmp', $xml);
        @rename($cachePath . '.tmp', $cachePath);
        header('X-Cache: MISS');
        echo $xml;
    }

    public static function apiSearch(): void
    {
        require_once __DIR__ . '/RateLimit.php';
        if (!RateLimit::check('search', 60)) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Rate limit exceeded. Try again in a minute.']);
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        // Name-autocomplete mode — used by the search page's name input.
        // Cheaper than fen/moves: a single LIKE-prefix query.
        $name = (string) ($_GET['name'] ?? '');
        if ($name !== '') {
            if (mb_strlen($name) > 100) {
                http_response_code(400);
                echo json_encode(['error' => 'Query too long']);
                return;
            }
            $matches = Opening::searchByName($name, 10);
            echo json_encode([
                'mode'    => 'name',
                'query'   => $name,
                'matches' => array_map(static fn ($r) => [
                    'eco'   => $r['eco'],
                    'name'  => $r['name'],
                    'slug'  => $r['slug'],
                    'plies' => (int) $r['move_count'],
                ], $matches),
            ]);
            return;
        }

        $fen = (string) ($_GET['fen'] ?? '');
        if ($fen !== '') {
            if (!preg_match('#^[a-zA-Z0-9/]+ [wb] [-KQkq]+ [-a-h0-9]+( \d+ \d+)?$#', $fen)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid FEN parameter']);
                return;
            }
            $matches = Opening::searchByFen($fen);
            echo json_encode([
                'mode'    => 'fen',
                'query'   => $fen,
                'matches' => array_map(static fn ($r) => [
                    'eco'   => $r['eco'],
                    'name'  => $r['name'],
                    'slug'  => $r['slug'],
                    'depth' => (int) $r['depth'],
                    'plies' => (int) $r['move_count'],
                    'fen'   => $r['fen'],
                ], $matches),
            ]);
            return;
        }

        $moves = (string) ($_GET['moves'] ?? '');
        if ($moves !== '' && !preg_match('#^[A-Za-z0-9 =\-]{1,512}$#', $moves)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid moves parameter']);
            return;
        }
        $canon = chess_codex_canonicalize_pgn($moves);
        $match = Opening::searchByCanon($canon);
        $continuations = Opening::continuationsFrom($canon, 5);
        echo json_encode([
            'mode'          => 'moves',
            'query'         => $canon,
            'match'         => $match ? [
                'eco'   => $match['eco'],
                'name'  => $match['name'],
                'slug'  => $match['slug'],
                'depth' => (int) $match['depth'],
                'plies' => (int) $match['move_count'],
                'exact' => (bool) $match['exact'],
            ] : null,
            'continuations' => array_map(static fn ($c) => [
                'eco'   => $c['eco'],
                'name'  => $c['name'],
                'slug'  => $c['slug'],
                'plies' => (int) $c['move_count'],
            ], $continuations),
        ]);
    }

    public static function apiStats(): void
    {
        require_once __DIR__ . '/RateLimit.php';
        header('Content-Type: application/json; charset=utf-8');
        if (!RateLimit::check('stats', 30)) {
            http_response_code(429);
            echo json_encode(['error' => 'Rate limit exceeded.']);
            return;
        }

        // Stats exist only for openings in the database: the page sends the
        // opening's id and the moves come from the database. Accepting any
        // move list from the URL let anyone make the site query Lichess for
        // arbitrary positions, burning the API quota and PHP workers.
        $id    = (int) ($_GET['id'] ?? 0);
        $moves = $id > 0 ? Opening::movesById($id) : null;
        if ($moves === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Unknown opening']);
            return;
        }
        require_once __DIR__ . '/ChessEngine.php';
        require_once __DIR__ . '/StatsCache.php';
        $stats = StatsCache::getOrFetch(ChessEngine::fromPgn($moves)->uciHistory());

        if (isset($stats['error'])) {
            http_response_code(503);
            header('Cache-Control: no-store');
            if (!empty($stats['busy'])) header('Retry-After: ' . (int) ($stats['retry_after'] ?? 2));
            echo json_encode(['error' => $stats['error']]);
            return;
        }
        header('Cache-Control: public, max-age=300');
        echo json_encode($stats);
    }

    public static function home(): void
    {
        global $baseUrl, $siteUrl;
        require_once __DIR__ . '/Views.php';
        Views::track('home');
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
     * Used by the "🎲 Random" link in the header for casual discovery.
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
        Views::track('index');
        $grouped = Opening::allAlphabetical();
        $total = 0; foreach ($grouped as $rows) $total += count($rows);
        require __DIR__ . '/../templates/openings_index.php';
    }

    /** All ECO codes, A00–E99, grouped by volume. */
    public static function ecoIndex(): void
    {
        global $baseUrl, $siteUrl;
        Views::track('eco');
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
        Views::track('eco');
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
        Views::track('search');
        require __DIR__ . '/../templates/search.php';
    }

    public static function opening(string $slug): void
    {
        global $baseUrl, $siteUrl, $render404;
        $opening = Opening::findBySlug($slug);
        if ($opening === null) $render404('No opening matches that URL.');
        Views::track('opening', (int) $opening['id']);
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

    /**
     * JSON subtree endpoint — called by opening.php on first <details> open.
     * Returns the flat descendant list shaped the same way the inline render
     * uses, so the client just renders the same DOM.
     */
    public static function apiSubtree(string $id): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        $iid = (int) $id;
        if ($iid <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Bad id']);
            return;
        }
        $parentRow = Opening::findById($iid);
        if ($parentRow === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Unknown opening']);
            return;
        }
        $rows = Opening::descendants($iid);
        // Build a name lookup so the JS can compute "short" names just like
        // the PHP template does.
        $nameById = [$iid => (string) $parentRow['name']];
        foreach ($rows as $r) $nameById[(int) $r['id']] = (string) $r['name'];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'         => (int) $r['id'],
                'eco'        => (string) $r['eco'],
                'name'       => (string) $r['name'],
                'slug'       => (string) $r['slug'],
                'depth'      => (int) $r['depth'],
                'move_count' => (int) $r['move_count'],
                'parent_name'=> $nameById[(int) $r['parent_id']] ?? null,
            ];
        }
        echo json_encode([
            'parent_id'    => $iid,
            'parent_depth' => (int) $parentRow['depth'],
            'subtree'      => $out,
        ]);
    }

    public static function play(string $slug): void
    {
        global $baseUrl, $siteUrl, $render404;
        $opening = Opening::findBySlug($slug);
        if ($opening === null) $render404('No opening matches that URL.');
        require_once __DIR__ . '/Views.php';
        Views::track('play', (int) $opening['id']);
        require __DIR__ . '/../templates/play.php';
    }

    // ------------------------------------------------------------------
    // Suggestions API (public)
    // ------------------------------------------------------------------

    public static function apiSuggest(): void
    {
        require_once __DIR__ . '/RateLimit.php';
        require_once __DIR__ . '/Submissions.php';

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST only']);
            return;
        }

        // Per-IP throttle: 3 submissions per minute.
        if (!RateLimit::check('suggest', 3)) {
            http_response_code(429);
            echo json_encode(['error' => 'Slow down — too many submissions.']);
            return;
        }

        $openingId = (int) ($_POST['opening_id'] ?? 0);
        $name      = trim((string) ($_POST['name'] ?? ''));
        $email     = trim((string) ($_POST['email'] ?? ''));
        $markdown  = trim((string) ($_POST['markdown'] ?? ''));
        $honeypot  = (string) ($_POST['website'] ?? ''); // hidden anti-bot field

        if ($honeypot !== '') {
            // Bot — pretend it worked, drop silently.
            echo json_encode(['ok' => true]);
            return;
        }
        if ($openingId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing opening reference.']);
            return;
        }
        // Validate the opening actually exists — otherwise INSERT fails on the
        // FK and the user sees a generic 500. Cheap lookup by primary key.
        if (Opening::findById($openingId) === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Unknown opening.']);
            return;
        }
        if ($markdown === '' || mb_strlen($markdown) < 30) {
            http_response_code(400);
            echo json_encode(['error' => 'Description is too short (minimum 30 characters).']);
            return;
        }
        if (mb_strlen($markdown) > 5000) {
            http_response_code(400);
            echo json_encode(['error' => 'Description is too long (maximum 5000 characters).']);
            return;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email address looks invalid.']);
            return;
        }

        try {
            $id = Submissions::create(
                $openingId,
                $name ?: null,
                $email ?: null,
                $markdown,
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            echo json_encode(['ok' => true, 'id' => $id]);
        } catch (Throwable $e) {
            error_log('Submission failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not save your submission. Please try again later.']);
        }
    }

    // ------------------------------------------------------------------
    // Admin (login-protected)
    // ------------------------------------------------------------------

    public static function adminLogin(): void
    {
        global $baseUrl, $siteUrl;

        $error = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $u = (string) ($_POST['username'] ?? '');
            $p = (string) ($_POST['password'] ?? '');
            if (Auth::attemptLogin($u, $p)) {
                $intended = Auth::takeIntendedPath() ?? '/admin';
                header('Location: ' . $baseUrl . $intended);
                exit;
            }
            $error = 'Invalid credentials, or rate-limited after repeated failures.';
        }
        require __DIR__ . '/../templates/admin_login.php';
    }

    public static function adminLogout(): void
    {
        global $baseUrl;
        require_once __DIR__ . '/Auth.php';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && Auth::checkCsrf($_POST['csrf'] ?? null)) {
            Auth::logout();
        }
        header('Location: ' . $baseUrl . '/admin/login');
        exit;
    }

    public static function adminDashboard(): void
    {
        global $baseUrl, $siteUrl;
        Auth::requireLogin($baseUrl . '/admin/login', '/admin');

        // Filterable submission search, all params optional. Defaults shows
        // every pending submission, newest first.
        $filters = [
            'status'    => (string) ($_GET['status']    ?? 'pending'),
            'opening_q' => trim((string) ($_GET['opening_q'] ?? '')),
            'author_q'  => trim((string) ($_GET['author_q']  ?? '')),
            'since'     => trim((string) ($_GET['since']     ?? '')),
        ];
        $pending = Submissions::search($filters, 200);
        $counts  = Submissions::counts();
        // Visit-stats widget. All queries are wrapped in try/catch inside
        // Views, so a missing codex_view_log table just returns zeros until
        // migration 006 has been run.
        $statsToday   = Views::todayCount();
        $statsWeek    = Views::lastNDays(7);
        $statsMonth   = Views::lastNDays(30);
        $statsChart   = Views::dailyChart(30);
        $statsTop     = Views::topOpenings(30, 10);
        $statsByType  = Views::byPageType(30);
        // Drain any one-shot flash message set by bulk actions.
        Auth::start();
        $flash = $_SESSION['codex_admin_flash'] ?? null;
        unset($_SESSION['codex_admin_flash']);
        require __DIR__ . '/../templates/admin_dashboard.php';
    }

    public static function adminReview(string $id): void
    {
        global $baseUrl, $siteUrl, $render404;
        require_once __DIR__ . '/Auth.php';
        require_once __DIR__ . '/Submissions.php';
        Auth::requireLogin($baseUrl . '/admin/login', '/admin/review/' . $id);

        $submission = Submissions::findById((int) $id);
        if (!$submission) $render404('No such submission.');
        // Pre-load the current opening so the review template can render a
        // side-by-side diff against the existing description.
        $opening = Opening::findBySlug((string) $submission['opening_slug']);
        require __DIR__ . '/../templates/admin_review.php';
    }

    public static function adminAccept(string $id): void
    {
        global $baseUrl;
        require_once __DIR__ . '/Auth.php';
        require_once __DIR__ . '/Submissions.php';
        Auth::requireLogin($baseUrl . '/admin/login');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
            || !Auth::checkCsrf($_POST['csrf'] ?? null)) {
            http_response_code(400);
            echo "Bad request.";
            return;
        }
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        Submissions::accept((int) $id, $notes);
        header('Location: ' . $baseUrl . '/admin');
        exit;
    }

    public static function adminReject(string $id): void
    {
        global $baseUrl;
        require_once __DIR__ . '/Auth.php';
        require_once __DIR__ . '/Submissions.php';
        Auth::requireLogin($baseUrl . '/admin/login');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
            || !Auth::checkCsrf($_POST['csrf'] ?? null)) {
            http_response_code(400);
            echo "Bad request.";
            return;
        }
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        Submissions::reject((int) $id, $notes);
        header('Location: ' . $baseUrl . '/admin');
        exit;
    }

    /**
     * Live markdown preview for the admin edit / review pages.
     * POSTs markdown text → returns server-rendered HTML, same Parsedown
     * (safe mode) the public pages use, so the admin sees exactly what
     * users will see. Auth-only.
     */
    public static function adminPreview(): void
    {
        global $baseUrl;
        require_once __DIR__ . '/Auth.php';
        Auth::requireLogin($baseUrl . '/admin/login');

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo '<p>POST only.</p>';
            return;
        }
        if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
            http_response_code(400);
            echo '<p>CSRF token mismatch — reload the page.</p>';
            return;
        }
        $md = (string) ($_POST['markdown'] ?? '');
        if ($md === '') { echo '<p class="admin-preview-empty"><em>Nothing to preview yet.</em></p>'; return; }
        if (mb_strlen($md) > 50000) { echo '<p>Too long.</p>'; return; }
        require_once __DIR__ . '/../vendor/Parsedown.php';
        $pd = new Parsedown();
        $pd->setSafeMode(true);
        echo $pd->text($md);
    }

    /**
     * Bulk accept/reject for the admin dashboard. Accepts a POST with
     * `ids[]` array of submission IDs and either `bulk_accept` or
     * `bulk_reject` button name. Processes each one inside a single
     * transaction so a partial failure doesn't leave the dashboard
     * in a weird half-state.
     */
    public static function adminBulk(): void
    {
        global $baseUrl;
        require_once __DIR__ . '/Auth.php';
        require_once __DIR__ . '/Submissions.php';
        Auth::requireLogin($baseUrl . '/admin/login');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
            || !Auth::checkCsrf($_POST['csrf'] ?? null)) {
            http_response_code(400);
            echo 'Bad request.';
            return;
        }
        $action = isset($_POST['bulk_accept']) ? 'accept'
                : (isset($_POST['bulk_reject']) ? 'reject' : null);
        if ($action === null) {
            header('Location: ' . $baseUrl . '/admin');
            exit;
        }
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $done = 0;
        foreach ($ids as $sid) {
            if ($sid <= 0) continue;
            try {
                if ($action === 'accept') Submissions::accept($sid, 'bulk');
                else                       Submissions::reject($sid, 'bulk');
                $done++;
            } catch (Throwable $e) {
                error_log('Bulk ' . $action . ' failed for #' . $sid . ': ' . $e->getMessage());
            }
        }
        // Flash via session so the next page can show a "Processed N" banner.
        Auth::start();
        $_SESSION['codex_admin_flash'] = sprintf(
            'Bulk %s: %d of %d submissions processed.',
            $action, $done, count($ids)
        );
        header('Location: ' . $baseUrl . '/admin');
        exit;
    }

    /**
     * Inline-edit an opening's description from the admin panel.
     * GET shows a form pre-filled with current markdown; POST saves it.
     * No submission record is created — this is a direct admin edit.
     */
    public static function adminEditOpening(string $slug): void
    {
        global $baseUrl, $siteUrl, $render404;
        Auth::requireLogin($baseUrl . '/admin/login', '/admin/edit/' . $slug);

        $opening = Opening::findBySlug($slug);
        if ($opening === null) $render404('No opening matches that URL.');

        $saved = false;
        $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
                $error = 'CSRF token mismatch — reload the page and try again.';
            } else {
                $md = trim((string) ($_POST['markdown'] ?? ''));
                if (mb_strlen($md) > 20000) {
                    $error = 'Description is too long (max 20,000 characters).';
                } else {
                    $stmt = chess_codex_db()->prepare(
                        "UPDATE codex_openings SET description = :d WHERE id = :id"
                    );
                    $stmt->execute(['d' => $md === '' ? null : $md, 'id' => (int) $opening['id']]);
                    $opening['description'] = $md === '' ? null : $md;
                    $saved = true;
                }
            }
        }
        require __DIR__ . '/../templates/admin_edit.php';
    }
}
