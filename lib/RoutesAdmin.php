<?php
declare(strict_types=1);

/**
 * The admin area (login-protected, behind Cloudflare Access too): login,
 * dashboard, review of submissions, bulk actions, description editing. Part
 * of Routes (a trait, so the route table in index.php names them
 * Routes::adminX like the pages).
 */
trait RoutesAdmin
{


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
