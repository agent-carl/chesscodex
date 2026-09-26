<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * How an opening scores at each playing level — four Lichess rating bands and
 * the masters database — plus a few master games, fetched slowly by
 * tools/fetch-levels.php for the most-played lines and kept in
 * codex_level_stats. Pages only read the table; they never call Lichess.
 */
final class LevelStats
{
    /** URL slug => label and Lichess rating groups (null: the masters database). */
    public const LEVELS = [
        'beginners'    => ['label' => 'Under 1400',  'ratings' => '0,1000,1200'],
        'intermediate' => ['label' => '1400–1799',   'ratings' => '1400,1600'],
        'advanced'     => ['label' => '1800–2199',   'ratings' => '1800,2000'],
        'experts'      => ['label' => '2200 and up', 'ratings' => '2200,2500'],
        'masters'      => ['label' => 'Masters, over the board', 'ratings' => null],
    ];

    /** Master games kept per line. */
    private const TOP_GAMES = 5;

    public static function ensureTable(): void
    {
        chess_codex_db()->exec(
            'CREATE TABLE IF NOT EXISTS codex_level_stats (
                opening_id     INTEGER     NOT NULL,
                level          VARCHAR(16) NOT NULL,
                white_wins     INTEGER     NOT NULL DEFAULT 0,
                draws          INTEGER     NOT NULL DEFAULT 0,
                black_wins     INTEGER     NOT NULL DEFAULT 0,
                top_games_json TEXT        NULL,
                fetched_at     VARCHAR(19) NOT NULL,
                PRIMARY KEY (opening_id, level)
            )'
        );
    }

    /**
     * level => ['white', 'draws', 'black', 'games', 'games_list', 'fetched_at'] for
     * one opening, in LEVELS order; [] when nothing was fetched (or no table yet).
     */
    public static function forOpening(int $openingId): array
    {
        try {
            $stmt = chess_codex_db()->prepare(
                'SELECT level, white_wins, draws, black_wins, top_games_json, fetched_at
                 FROM codex_level_stats WHERE opening_id = :id'
            );
            $stmt->execute(['id' => $openingId]);
            $rows = $stmt->fetchAll();
        } catch (PDOException) {
            return [];
        }
        $byLevel = [];
        foreach ($rows as $r) {
            $byLevel[(string) $r['level']] = [
                'white' => (int) $r['white_wins'], 'draws' => (int) $r['draws'], 'black' => (int) $r['black_wins'],
                'games' => (int) $r['white_wins'] + (int) $r['draws'] + (int) $r['black_wins'],
                'games_list' => json_decode((string) ($r['top_games_json'] ?? ''), true) ?: [],
                'fetched_at' => (string) $r['fetched_at'],
            ];
        }
        return array_intersect_key(array_replace(self::LEVELS, $byLevel), $byLevel);
    }

    /** opening_id => ['white', 'draws', 'black', 'games'] at one level, for the rankings. */
    public static function allAtLevel(string $level): array
    {
        try {
            $stmt = chess_codex_db()->prepare(
                'SELECT opening_id, white_wins, draws, black_wins FROM codex_level_stats WHERE level = :l'
            );
            $stmt->execute(['l' => $level]);
        } catch (PDOException) {
            return [];
        }
        $out = [];
        foreach ($stmt as $r) {
            $games = (int) $r['white_wins'] + (int) $r['draws'] + (int) $r['black_wins'];
            if ($games > 0) {
                $out[(int) $r['opening_id']] = ['white' => (int) $r['white_wins'], 'draws' => (int) $r['draws'],
                                                'black' => (int) $r['black_wins'], 'games' => $games];
            }
        }
        return $out;
    }

    /** When the line's numbers for $level were fetched ('Y-m-d H:i:s'), or null. */
    public static function fetchedAt(int $openingId, string $level): ?string
    {
        $stmt = chess_codex_db()->prepare(
            'SELECT fetched_at FROM codex_level_stats WHERE opening_id = :id AND level = :l'
        );
        $stmt->execute(['id' => $openingId, 'l' => $level]);
        $at = $stmt->fetchColumn();
        return $at === false ? null : (string) $at;
    }

    /** Asks Lichess for one line at one level (waiting for the shared lock) and stores the answer. */
    public static function fetchAndStore(int $openingId, array $uciMoves, string $level): void
    {
        require_once __DIR__ . '/LichessExplorer.php';
        $ratings = self::LEVELS[$level]['ratings'];
        $data = $ratings === null
            ? LichessExplorer::fetchMasters($uciMoves, self::TOP_GAMES, true)
            : LichessExplorer::fetchRatings($uciMoves, $ratings, true);

        $games = [];
        foreach (array_slice($data['topGames'] ?? [], 0, self::TOP_GAMES) as $g) {
            $games[] = [
                'id'     => (string) ($g['id'] ?? ''),
                'white'  => (string) ($g['white']['name'] ?? '?'), 'wr' => (int) ($g['white']['rating'] ?? 0),
                'black'  => (string) ($g['black']['name'] ?? '?'), 'br' => (int) ($g['black']['rating'] ?? 0),
                'winner' => $g['winner'] ?? null,
                'year'   => (int) ($g['year'] ?? 0),
            ];
        }
        $row = [
            'id' => $openingId, 'l' => $level,
            'w'  => (int) ($data['white'] ?? 0), 'd' => (int) ($data['draws'] ?? 0), 'b' => (int) ($data['black'] ?? 0),
            'g'  => $games ? json_encode($games, JSON_UNESCAPED_UNICODE) : null,
            't'  => date('Y-m-d H:i:s'),
        ];
        $upsert = chess_codex_db_driver() === 'sqlite'
            ? 'ON CONFLICT (opening_id, level) DO UPDATE SET white_wins = excluded.white_wins, draws = excluded.draws,
               black_wins = excluded.black_wins, top_games_json = excluded.top_games_json, fetched_at = excluded.fetched_at'
            : 'ON DUPLICATE KEY UPDATE white_wins = VALUES(white_wins), draws = VALUES(draws),
               black_wins = VALUES(black_wins), top_games_json = VALUES(top_games_json), fetched_at = VALUES(fetched_at)';
        chess_codex_db()->prepare(
            "INSERT INTO codex_level_stats (opening_id, level, white_wins, draws, black_wins, top_games_json, fetched_at)
             VALUES (:id, :l, :w, :d, :b, :g, :t) $upsert"
        )->execute($row);
    }
}
