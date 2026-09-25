<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Simple migration runner. Loads .sql files from db/migrations/, applies
 * any that haven't been recorded as run in codex_migrations, and logs each.
 *
 * Filename convention:    db/migrations/<NNN>_<description>.sql
 *                         e.g. 008_add_revisions.sql
 *
 * Each file is one or more SQL statements separated by ";\n". Single
 * statements only (no PROCEDURE / multi-statement DELIMITER blocks).
 *
 * Refuses to run statements that touch tables not prefixed `codex_`.
 * Idempotent: a file already in codex_migrations is silently skipped.
 *
 * Driver: run from a one-shot token-protected /migrate.php endpoint,
 * or by including this class and calling Migrations::runAll() from CLI.
 */
final class Migrations
{
    public static function runAll(): array
    {
        $pdo = chess_codex_db();
        self::ensureTrackingTable($pdo);

        $applied = self::appliedVersions($pdo);
        $files   = self::availableFiles();
        $results = [];

        foreach ($files as $version => $path) {
            if (isset($applied[$version])) {
                $results[] = ['version' => $version, 'status' => 'skip', 'reason' => 'already-applied'];
                continue;
            }
            $sql = (string) @file_get_contents($path);
            if ($sql === '') {
                $results[] = ['version' => $version, 'status' => 'fail', 'reason' => 'empty-or-unreadable'];
                continue;
            }
            // Defence in depth: refuse anything that mentions a table not
            // starting with codex_. Naive regex, but our migrations are
            // pure CREATE TABLE / ALTER TABLE / CREATE INDEX statements.
            if (preg_match('/\b(?:CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|CREATE\s+INDEX|DROP\s+INDEX)\b[^;]*?\b(?!codex_)([a-zA-Z_][a-zA-Z0-9_]*)\b/i', $sql, $m)) {
                $results[] = ['version' => $version, 'status' => 'refuse', 'reason' => 'non-codex-table:' . $m[1]];
                continue;
            }
            try {
                foreach (self::splitStatements($sql) as $stmt) {
                    if ($stmt === '') continue;
                    $pdo->exec($stmt);
                }
                $pdo->prepare("INSERT INTO codex_migrations (version, applied_at) VALUES (:v, NOW())")
                    ->execute(['v' => $version]);
                $results[] = ['version' => $version, 'status' => 'applied'];
            } catch (Throwable $e) {
                $results[] = ['version' => $version, 'status' => 'fail', 'reason' => $e->getMessage()];
                break;  // stop on first failure to keep history linear
            }
        }
        return $results;
    }

    private static function ensureTrackingTable(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS codex_migrations (
                version    VARCHAR(20)  NOT NULL,
                applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return array<string, string> version => filepath, sorted ascending */
    private static function availableFiles(): array
    {
        $dir = __DIR__ . '/../db/migrations';
        if (!is_dir($dir)) return [];
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (!preg_match('/^(\d{3,4})_.+\.sql$/', $name, $m)) continue;
            $out[$m[1]] = $dir . '/' . $name;
        }
        ksort($out);
        return $out;
    }

    /** @return array<string, true> versions already in codex_migrations */
    private static function appliedVersions(PDO $pdo): array
    {
        $rows = $pdo->query("SELECT version FROM codex_migrations")->fetchAll();
        $out = [];
        foreach ($rows as $r) $out[(string) $r['version']] = true;
        return $out;
    }

    /**
     * Naive SQL splitter — splits on top-level semicolons. Doesn't handle
     * strings containing `;`, stored-procedure DELIMITER blocks, etc. Fine
     * for our pure-DDL migrations.
     */
    private static function splitStatements(string $sql): array
    {
        $sql   = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;   // line comments
        $parts = preg_split('/;\s*\n/', $sql) ?: [];
        return array_map('trim', $parts);
    }
}
