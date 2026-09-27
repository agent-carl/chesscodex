-- Chess Codex — full schema for the SQLite backend (config.php → db.driver = 'sqlite').
-- Same tables and columns as the MySQL deployment, including the ones that
-- used to arrive via db/migrations/ (description, codex_submissions,
-- codex_view_log). Applied by tools/seed.php.
--
-- NOCASE on name/slug mirrors MySQL's case-insensitive utf8mb4_unicode_ci
-- for = and ORDER BY; LIKE gets full MySQL semantics (accent-insensitive)
-- from the PHP function registered in lib/db.php.

CREATE TABLE codex_openings (
    id          INTEGER PRIMARY KEY,
    eco         TEXT    NOT NULL,
    name        TEXT    NOT NULL COLLATE NOCASE,
    slug        TEXT    NOT NULL COLLATE NOCASE UNIQUE,
    parent_id   INTEGER NULL REFERENCES codex_openings (id) ON DELETE SET NULL ON UPDATE CASCADE,
    depth       INTEGER NOT NULL DEFAULT 0,
    fen         TEXT    NULL,
    pgn_moves   TEXT    NOT NULL,
    pgn_canon   TEXT    NOT NULL DEFAULT '',
    move_count  INTEGER NOT NULL DEFAULT 0,
    popularity  INTEGER NOT NULL DEFAULT 0,
    description TEXT    NULL
);
CREATE INDEX idx_codex_openings_eco    ON codex_openings (eco);
CREATE INDEX idx_codex_openings_parent ON codex_openings (parent_id);
CREATE INDEX idx_codex_openings_canon  ON codex_openings (pgn_canon);
CREATE INDEX idx_codex_openings_fen    ON codex_openings (fen);

CREATE TABLE codex_opening_lines (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    opening_id  INTEGER NOT NULL REFERENCES codex_openings (id) ON DELETE CASCADE ON UPDATE CASCADE,
    name        TEXT    NOT NULL,
    pgn         TEXT    NOT NULL,
    evaluation  TEXT    NULL,
    frequency   INTEGER NULL
);
CREATE INDEX idx_lines_opening ON codex_opening_lines (opening_id);

CREATE TABLE codex_stats_cache (
    fen_hash        TEXT    PRIMARY KEY,
    white_wins      INTEGER NOT NULL DEFAULT 0,
    black_wins      INTEGER NOT NULL DEFAULT 0,
    draws           INTEGER NOT NULL DEFAULT 0,
    top_moves_json  TEXT    NULL,
    fetched_at      TEXT    NOT NULL          -- 'Y-m-d H:i:s'
);

CREATE TABLE codex_submissions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    opening_id   INTEGER NOT NULL REFERENCES codex_openings (id) ON DELETE CASCADE ON UPDATE CASCADE,
    author_name  TEXT    NULL,
    author_email TEXT    NULL,
    markdown     TEXT    NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'accepted', 'rejected')),
    created_at   TEXT    NOT NULL,            -- 'Y-m-d H:i:s'
    ip_address   TEXT    NULL,
    reviewed_at  TEXT    NULL,
    review_notes TEXT    NULL
);
CREATE INDEX idx_codex_submissions_status ON codex_submissions (status, created_at);

-- opening_id = 0 for non-opening pages (home, search, …), so no foreign key.
CREATE TABLE codex_view_log (
    log_date    TEXT    NOT NULL,             -- 'Y-m-d'
    page_type   TEXT    NOT NULL,
    opening_id  INTEGER NOT NULL DEFAULT 0,
    views       INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (log_date, page_type, opening_id)
) WITHOUT ROWID;

CREATE TABLE codex_migrations (
    version    TEXT PRIMARY KEY,
    applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
