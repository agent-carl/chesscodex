<?php
// Copy this file to config.php and fill in real values. config.php must be
// kept out of version control (see .gitignore).

return [
    'db' => [
        // The SQLite database file; create the tables + openings with
        // `php tools/seed.php`. Keep it under db/, which is never served over
        // HTTP; PHP needs write access to the file AND the folder.
        'path'     => __DIR__ . '/db/chesscodex.sqlite',
    ],

    // Public-facing prefix where the app is mounted. Empty string = web root.
    // If you upload to https://example.com/chess-codex/, set this to '/chess-codex'.
    'base_url' => '',

    // Full origin (scheme + host) used in canonical URLs, sitemap, OG tags.
    // Leave empty to auto-detect from request headers (works in most cases).
    // Override only if behind a reverse proxy that loses scheme/host.
    'site_url' => '',

    // Long random string. The seed.php endpoint refuses to run unless the
    // request carries ?token=<this value>. Generate once with e.g.
    // `php -r "echo bin2hex(random_bytes(32));"` and keep it secret.
    'seed_token' => 'CHANGE_ME_TO_A_LONG_RANDOM_HEX_STRING',

    // Lichess personal API token. Required by /api/stats — Lichess rejects
    // anonymous explorer requests with HTTP 401 since 2024.
    // Create at https://lichess.org/account/oauth/token/create (no scopes).
    // Format: lip_XXXXXXXXXXXXXXXXXXXX
    'lichess_token' => '',

    // Admin credentials for the /admin description-review panel.
    // Generate the hash on any machine with PHP installed via:
    //   php -r "echo password_hash('your-password', PASSWORD_BCRYPT, ['cost' => 12]) . PHP_EOL;"
    // Paste the resulting $2y$12$... string verbatim into password_hash below.
    'admin' => [
        'username'      => 'CHANGE_ME',
        'password_hash' => 'CHANGE_ME',
    ],
];
