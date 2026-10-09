<?php
declare(strict_types=1);

/**
 * The JSON and POST endpoints under /api/ — search, statistics, sub-variation
 * tree, repertoire PGN, view counter, suggestions. Part of Routes (a trait, so
 * the route table in index.php names them Routes::apiX like the pages).
 */
trait RoutesApi
{

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
                // Lines sharing this name: the moves that end this one ('' for the name's main line).
                'tail'  => Opening::distinguishingTail($match),
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

    /** /api/pgn?slugs=a,b&side=white — chosen lines as one PGN tree (the repertoire download). */
    public static function apiPgn(): void
    {
        global $siteUrl, $baseUrl;
        $lines = Opening::bySlugs(explode(',', (string) ($_GET['slugs'] ?? '')));
        $side  = ($_GET['side'] ?? '') === 'black' ? 'Black' : 'White';
        if ($lines === []) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "No known openings in ?slugs=.\n";
            return;
        }
        header('Content-Type: application/x-chess-pgn; charset=utf-8');
        header('Content-Disposition: attachment; filename="repertoire-' . strtolower($side) . '.pgn"');
        header('X-Robots-Tag: noindex');
        echo PgnTree::build($lines, "My repertoire ($side)", $siteUrl . $baseUrl . I18n::url('/repertoire'),
            ['Annotator' => 'Caissa Codex (chesscodex.org)']);
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


    /** POST from public/site.js: one view of the page named in its <body>. */
    public static function apiView(): void
    {
        require_once __DIR__ . '/RateLimit.php';
        header('Cache-Control: no-store');
        http_response_code(204);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !RateLimit::check('view', 120)) return;
        $type = (string) ($_POST['t'] ?? '');
        $id   = (int) ($_POST['id'] ?? 0);
        if (!in_array($type, Views::PAGE_TYPES, true) || $id < 0 || $id > 1000000) return;
        Views::track($type, $id > 0 ? $id : null);
    }

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
        $isReport  = ($_POST['kind'] ?? '') === 'report';   // a mistake on the page, not a description

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
        $minLength = $isReport ? 10 : 30;
        if ($markdown === '' || mb_strlen($markdown) < $minLength) {
            http_response_code(400);
            echo json_encode(['error' => ($isReport ? 'Please say a little more' : 'Description is too short')
                . " (minimum $minLength characters)."]);
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
                $isReport ? Submissions::REPORT_MARK . $markdown : $markdown,
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            echo json_encode(['ok' => true, 'id' => $id]);
        } catch (Throwable $e) {
            error_log('Submission failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not save your submission. Please try again later.']);
        }
    }
}
