<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Stockfish's evaluation of each opening's final position — score, best move
 * and main line — computed on the Pi by tools/engine-eval.php and kept in
 * codex_engine_eval, keyed by FEN. Pages only read the table.
 *
 * Scores are from White's side: +0.35 means White is a third of a pawn up.
 */
final class EngineEval
{
    public static function ensureTable(): void
    {
        chess_codex_db()->exec(
            'CREATE TABLE IF NOT EXISTS codex_engine_eval (
                fen          VARCHAR(100) NOT NULL PRIMARY KEY,
                depth        INTEGER      NOT NULL,
                score_cp     INTEGER      NULL,
                mate         INTEGER      NULL,
                pv_san       TEXT         NOT NULL,
                engine       VARCHAR(32)  NOT NULL,
                evaluated_at VARCHAR(19)  NOT NULL
            )'
        );
    }

    /**
     * ['depth', 'cp', 'mate', 'pv' (SAN list), 'engine', 'evaluated_at'] for a
     * position; null when it wasn't evaluated (or there's no table yet).
     */
    public static function forFen(string $fen): ?array
    {
        try {
            $stmt = chess_codex_db()->prepare(
                'SELECT depth, score_cp, mate, pv_san, engine, evaluated_at FROM codex_engine_eval WHERE fen = :fen'
            );
            $stmt->execute(['fen' => $fen]);
            $r = $stmt->fetch();
        } catch (PDOException) {
            return null;
        }
        if (!$r) return null;
        return [
            'depth' => (int) $r['depth'],
            'cp'    => $r['score_cp'] === null ? null : (int) $r['score_cp'],
            'mate'  => $r['mate'] === null ? null : (int) $r['mate'],
            'pv'    => preg_split('/\s+/', trim((string) $r['pv_san']), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            'engine' => (string) $r['engine'],
            'evaluated_at' => (string) $r['evaluated_at'],
        ];
    }

    /** Depth of the stored evaluation of a position; null when there is none. */
    public static function depthOf(string $fen): ?int
    {
        $stmt = chess_codex_db()->prepare('SELECT depth FROM codex_engine_eval WHERE fen = :fen');
        $stmt->execute(['fen' => $fen]);
        $d = $stmt->fetchColumn();
        return $d === false ? null : (int) $d;
    }

    public static function store(string $fen, int $depth, ?int $cp, ?int $mate, array $pvSan, string $engine): void
    {
        $upsert = 'ON CONFLICT (fen) DO UPDATE SET depth = excluded.depth, score_cp = excluded.score_cp,
               mate = excluded.mate, pv_san = excluded.pv_san, engine = excluded.engine, evaluated_at = excluded.evaluated_at';
        chess_codex_db()->prepare(
            "INSERT INTO codex_engine_eval (fen, depth, score_cp, mate, pv_san, engine, evaluated_at)
             VALUES (:fen, :depth, :cp, :mate, :pv, :engine, :at) $upsert"
        )->execute([
            'fen' => $fen, 'depth' => $depth, 'cp' => $cp, 'mate' => $mate,
            'pv' => implode(' ', $pvSan), 'engine' => $engine, 'at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** "+0.35", "−1.20", "0.00", "#3", "−#2" — the usual engine notation. */
    public static function scoreText(array $e): string
    {
        if ($e['mate'] !== null) return ($e['mate'] < 0 ? '−' : '') . '#' . abs($e['mate']);
        $v = $e['cp'] / 100;
        if (abs($v) < 0.005) return '0.00';
        return ($v > 0 ? '+' : '−') . number_format(abs($v), 2);
    }

    /** Plain-words verdict for the score, as chess books put it. */
    public static function verdict(array $e): string
    {
        if ($e['mate'] !== null) return ($e['mate'] > 0 ? 'White' : 'Black') . ' mates in ' . abs($e['mate']);
        $v = abs($e['cp']) / 100;
        $side = $e['cp'] > 0 ? 'White' : 'Black';
        return match (true) {
            $v < 0.3 => 'The position is about equal',
            $v < 0.7 => "$side is slightly better",
            $v < 1.5 => "$side is better",
            default  => "$side is clearly better",
        };
    }

    /**
     * The main line with move numbers, from the position's side to move:
     * "3. Nf3 Nf6 4. d4" or "3... Nf6 4. d4".
     */
    public static function lineText(string $fen, array $pv): string
    {
        $parts = explode(' ', $fen);
        $white = ($parts[1] ?? 'w') === 'w';
        $n = (int) ($parts[5] ?? 1);
        $out = [];
        foreach ($pv as $i => $san) {
            if ($white) $out[] = $n . '. ' . $san;
            else $out[] = ($i === 0 ? $n . '... ' : '') . $san;
            if (!$white) $n++;
            $white = !$white;
        }
        return implode(' ', $out);
    }
}
