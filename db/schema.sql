-- Chess Codex — full schema for the MySQL backend (the SQLite twin is
-- db/schema.sqlite.sql). MySQL 8, utf8mb4 throughout. Applied by tools/seed.php.
-- Includes what later arrived via db/migrations/: codex_openings.description,
-- codex_submissions, codex_view_log.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS codex_view_log;
DROP TABLE IF EXISTS codex_submissions;
DROP TABLE IF EXISTS codex_stats_cache;
DROP TABLE IF EXISTS codex_opening_lines;
DROP TABLE IF EXISTS codex_openings;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE codex_openings (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    eco         CHAR(3)      NOT NULL,
    name        VARCHAR(255) NOT NULL,
    slug        VARCHAR(255) NOT NULL,
    parent_id   INT UNSIGNED NULL,
    depth       INT UNSIGNED NOT NULL DEFAULT 0,
    -- fen is nullable in session 1: client computes it from pgn_moves via chess.js.
    -- Will be populated server-side in a later session if/when we need FEN-indexed lookups.
    fen         TEXT         NULL,
    pgn_moves   TEXT         NOT NULL,
    pgn_canon   VARCHAR(255) NOT NULL DEFAULT '',
    move_count  INT UNSIGNED NOT NULL DEFAULT 0,
    popularity  INT UNSIGNED NOT NULL DEFAULT 0,
    description MEDIUMTEXT   NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_codex_openings_slug (slug),
    KEY idx_codex_openings_eco (eco),
    KEY idx_codex_openings_parent (parent_id),
    KEY idx_codex_openings_canon (pgn_canon),
    CONSTRAINT fk_codex_openings_parent
        FOREIGN KEY (parent_id) REFERENCES codex_openings (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE codex_opening_lines (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    opening_id  INT UNSIGNED NOT NULL,
    name        VARCHAR(255) NOT NULL,
    pgn         TEXT         NOT NULL,
    evaluation  VARCHAR(16)  NULL,
    frequency   INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_lines_opening (opening_id),
    CONSTRAINT fk_lines_opening
        FOREIGN KEY (opening_id) REFERENCES codex_openings (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE codex_stats_cache (
    fen_hash        CHAR(64)     NOT NULL,
    white_wins      INT UNSIGNED NOT NULL DEFAULT 0,
    black_wins      INT UNSIGNED NOT NULL DEFAULT 0,
    draws           INT UNSIGNED NOT NULL DEFAULT 0,
    top_moves_json  JSON         NULL,
    fetched_at      DATETIME     NOT NULL,
    PRIMARY KEY (fen_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE codex_submissions (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    opening_id   INT UNSIGNED NOT NULL,
    author_name  VARCHAR(100) NULL,
    author_email VARCHAR(255) NULL,
    markdown     MEDIUMTEXT   NOT NULL,
    status       ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
    created_at   DATETIME     NOT NULL,
    ip_address   VARCHAR(45)  NULL,
    reviewed_at  DATETIME     NULL,
    review_notes TEXT         NULL,
    PRIMARY KEY (id),
    KEY idx_codex_submissions_status (status, created_at),
    CONSTRAINT fk_submissions_opening
        FOREIGN KEY (opening_id) REFERENCES codex_openings (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- opening_id = 0 for non-opening pages (home, search, …), so no foreign key.
CREATE TABLE codex_view_log (
    log_date    DATE         NOT NULL,
    page_type   VARCHAR(20)  NOT NULL,
    opening_id  INT UNSIGNED NOT NULL DEFAULT 0,
    views       INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (log_date, page_type, opening_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
