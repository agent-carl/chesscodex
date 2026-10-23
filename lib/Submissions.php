<?php
declare(strict_types=1);

/**
 * CRUD over codex_submissions — user-suggested descriptions waiting for
 * admin review.
 */
final class Submissions
{
    /** Starts the text of a problem report (the suggest form's "A mistake on this page"). */
    public const REPORT_MARK = '[report] ';

    /** At most this many suggestions still pending from the last 24 hours, from everyone together (/api/suggest answers 429). */
    public const DAILY_CAP = 50;

    /** Authors' IP addresses are kept this long, for spotting abuse, then erased. */
    public const IP_KEEP_DAYS = 30;

    /** How many suggestions made since $since ('Y-m-d H:i:s') still wait for review. */
    public static function pendingSince(string $since): int
    {
        $stmt = chess_codex_db()->prepare("SELECT COUNT(*) FROM codex_submissions WHERE status = 'pending' AND created_at >= :s");
        $stmt->execute(['s' => $since]);
        return (int) $stmt->fetchColumn();
    }

    public static function create(
        int $openingId,
        ?string $authorName,
        ?string $authorEmail,
        string $markdown,
        ?string $ip = null
    ): int {
        chess_codex_db()->prepare(
            'UPDATE codex_submissions SET ip_address = NULL WHERE ip_address IS NOT NULL AND created_at < :cut'
        )->execute(['cut' => date('Y-m-d H:i:s', time() - self::IP_KEEP_DAYS * 86400)]);
        $stmt = chess_codex_db()->prepare(
            "INSERT INTO codex_submissions
                (opening_id, author_name, author_email, markdown, status, created_at, ip_address)
             VALUES (:oid, :name, :email, :md, 'pending', :now, :ip)"
        );
        $stmt->execute([
            'oid'   => $openingId,
            'name'  => $authorName !== null && $authorName !== '' ? mb_substr($authorName, 0, 100) : null,
            'email' => $authorEmail !== null && $authorEmail !== '' ? mb_substr($authorEmail, 0, 255) : null,
            'md'    => $markdown,
            'now'   => date('Y-m-d H:i:s'),
            'ip'    => $ip,
        ]);
        return (int) chess_codex_db()->lastInsertId();
    }

    /**
     * Filterable submissions search for the admin dashboard.
     * $filters keys (all optional): status, opening_q (LIKE on name/slug),
     * author_q (LIKE on author_name/email), since (Y-m-d).
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function search(array $filters, int $limit = 100): array
    {
        $where  = [];
        $params = [];
        if (!empty($filters['status']) && in_array($filters['status'], ['pending', 'accepted', 'rejected'], true)) {
            $where[] = 's.status = :st';
            $params['st'] = $filters['status'];
        }
        if (!empty($filters['opening_q'])) {
            $where[] = '(o.name LIKE :oq OR o.slug LIKE :oq2)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['opening_q']) . '%';
            $params['oq']  = $like;
            $params['oq2'] = $like;
        }
        if (!empty($filters['author_q'])) {
            $where[] = "(COALESCE(s.author_name, '') LIKE :aq OR COALESCE(s.author_email, '') LIKE :aq2)";
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['author_q']) . '%';
            $params['aq']  = $like;
            $params['aq2'] = $like;
        }
        if (!empty($filters['since'])) {
            $where[] = 's.created_at >= :since';
            $params['since'] = (string) $filters['since'] . ' 00:00:00';
        }
        $sql = "SELECT s.*, o.name AS opening_name, o.slug AS opening_slug, o.eco
                FROM codex_submissions s
                JOIN codex_openings o ON o.id = s.opening_id
                " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
                ORDER BY s.created_at DESC
                LIMIT :lim";
        $stmt = chess_codex_db()->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
        $stmt->bindValue(':lim', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $rows = chess_codex_db()->query(
            "SELECT status, COUNT(*) AS n FROM codex_submissions GROUP BY status"
        )->fetchAll();
        $counts = ['pending' => 0, 'accepted' => 0, 'rejected' => 0];
        foreach ($rows as $r) $counts[$r['status']] = (int) $r['n'];
        return $counts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = chess_codex_db()->prepare(
            "SELECT s.*, o.name AS opening_name, o.slug AS opening_slug, o.eco
             FROM codex_submissions s
             JOIN codex_openings o ON o.id = s.opening_id
             WHERE s.id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Mark the submission accepted, with optional reviewer notes. The opening's
     * description is not touched: accepted text goes live only once it is
     * copied into db/descriptions/<slug>.md and imported.
     */
    public static function accept(int $id, ?string $notes = null): bool
    {
        $stmt = chess_codex_db()->prepare(
            "UPDATE codex_submissions
             SET status = 'accepted', reviewed_at = :now, review_notes = :n
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute(['now' => date('Y-m-d H:i:s'), 'n' => $notes, 'id' => $id]);
        $ok = $stmt->rowCount() > 0;
        if ($ok) {
            Logger::info('admin.submission.accept', [
                'submission_id' => $id,
                'admin'         => Auth::user(),
                'notes'         => $notes,
            ]);
        }
        return $ok;
    }

    public static function reject(int $id, ?string $notes = null): bool
    {
        $stmt = chess_codex_db()->prepare(
            "UPDATE codex_submissions
             SET status = 'rejected', reviewed_at = :now, review_notes = :n
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute(['now' => date('Y-m-d H:i:s'), 'n' => $notes, 'id' => $id]);
        $ok = $stmt->rowCount() > 0;
        if ($ok) {
            Logger::info('admin.submission.reject', [
                'submission_id' => $id,
                'admin'         => Auth::user(),
                'notes'         => $notes,
            ]);
        }
        return $ok;
    }
}
