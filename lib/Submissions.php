<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * CRUD over codex_submissions — user-suggested descriptions waiting for
 * admin review.
 */
final class Submissions
{
    public static function create(
        int $openingId,
        ?string $authorName,
        ?string $authorEmail,
        string $markdown,
        ?string $ip = null
    ): int {
        $stmt = chess_codex_db()->prepare(
            "INSERT INTO codex_submissions
                (opening_id, author_name, author_email, markdown, status, created_at, ip_address)
             VALUES (:oid, :name, :email, :md, 'pending', NOW(), :ip)"
        );
        $stmt->execute([
            'oid'   => $openingId,
            'name'  => $authorName !== null && $authorName !== '' ? mb_substr($authorName, 0, 100) : null,
            'email' => $authorEmail !== null && $authorEmail !== '' ? mb_substr($authorEmail, 0, 255) : null,
            'md'    => $markdown,
            'ip'    => $ip,
        ]);
        return (int) chess_codex_db()->lastInsertId();
    }

    /** @return array<int, array<string, mixed>> */
    public static function findByStatus(string $status, int $limit = 100): array
    {
        $stmt = chess_codex_db()->prepare(
            "SELECT s.*, o.name AS opening_name, o.slug AS opening_slug, o.eco
             FROM codex_submissions s
             JOIN codex_openings o ON o.id = s.opening_id
             WHERE s.status = :st
             ORDER BY s.created_at DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':st', $status, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Filterable submissions search for the admin dashboard.
     * $filters keys (all optional): status, opening_q (LIKE on name/slug),
     * author_q (LIKE on author_name/email), since (Y-m-d).
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
            $where[] = '(COALESCE(s.author_name, "") LIKE :aq OR COALESCE(s.author_email, "") LIKE :aq2)';
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

    public static function counts(): array
    {
        $rows = chess_codex_db()->query(
            "SELECT status, COUNT(*) AS n FROM codex_submissions GROUP BY status"
        )->fetchAll();
        $counts = ['pending' => 0, 'accepted' => 0, 'rejected' => 0];
        foreach ($rows as $r) $counts[$r['status']] = (int) $r['n'];
        return $counts;
    }

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
     * Accept the submission: replace the opening's description, mark
     * submission accepted with optional reviewer notes.
     */
    public static function accept(int $id, ?string $notes = null): bool
    {
        $pdo = chess_codex_db();
        $sub = self::findById($id);
        if (!$sub || $sub['status'] !== 'pending') return false;

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE codex_openings SET description = :d WHERE id = :id")
                ->execute(['d' => $sub['markdown'], 'id' => (int) $sub['opening_id']]);
            $pdo->prepare(
                "UPDATE codex_submissions
                 SET status = 'accepted', reviewed_at = NOW(), review_notes = :n
                 WHERE id = :id"
            )->execute(['n' => $notes, 'id' => $id]);
            $pdo->commit();
            Logger::info('admin.submission.accept', [
                'submission_id' => $id,
                'opening_id'    => (int) $sub['opening_id'],
                'opening_slug'  => $sub['opening_slug'] ?? null,
                'admin'         => Auth::user(),
                'notes'         => $notes,
            ]);
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            Logger::error('admin.submission.accept.fail', ['id' => $id, 'msg' => $e->getMessage()]);
            throw $e;
        }
    }

    public static function reject(int $id, ?string $notes = null): bool
    {
        $stmt = chess_codex_db()->prepare(
            "UPDATE codex_submissions
             SET status = 'rejected', reviewed_at = NOW(), review_notes = :n
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute(['n' => $notes, 'id' => $id]);
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
