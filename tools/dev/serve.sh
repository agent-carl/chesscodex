#!/usr/bin/env bash
# Local copy of the site on http://127.0.0.1:8099 for testing changes before a
# deploy: php -S with tools/dev/router.php (the nginx rules and security
# headers). The copy lives in .dev/site (git-ignored) with its own config.php —
# SQLite, no Lichess token, admin login disabled — and a database from the
# newest backup, so nothing touches the live site.
#
#   bash tools/dev/serve.sh              copy the code, start the server
#   bash tools/dev/serve.sh --sync       only re-copy the code (server keeps running)
#   bash tools/dev/serve.sh --fresh-db   pull the newest DB backup from the Pi first
#
# Needs PHP 8.3+ with pdo_sqlite (loaded with -d when it isn't on by default).
set -euo pipefail
cd "$(dirname "$0")/../.."

DEV=.dev/site
PORT=${PORT:-8099}
MODE=${1:-}

if [ "$MODE" = "--fresh-db" ] || ! ls backups/chesscodex-*.sqlite.gz >/dev/null 2>&1; then
    bash deploy/pull-backup.sh
    rm -f "$DEV/db/chesscodex.sqlite"
fi

mkdir -p "$DEV"
# Files kept only in this checkout (listed in .git/info/exclude) are left out too.
LOCAL_ONLY=(); [ -f .git/info/exclude ] && LOCAL_ONLY=(--exclude-from=.git/info/exclude)
tar "${LOCAL_ONLY[@]}" --exclude=.git --exclude=.dev --exclude=deploy --exclude=docs \
    --exclude=backups --exclude=config.php --exclude='db/*.sqlite' --exclude='db/*.sqlite-*' \
    --exclude=db/cache --exclude=db/og_cache --exclude=db/log --exclude=db/sitemap_cache.xml \
    -cf - . | tar -C "$DEV" -xf -
mkdir -p "$DEV/db/cache" "$DEV/db/og_cache" "$DEV/db/log"
# og.php redraws a card older than its own ctime, but on Windows that is the
# creation time, which an overwrite keeps: drop the drawn cards instead.
rm -f "$DEV"/db/og_cache/*.png

if [ ! -f "$DEV/db/chesscodex.sqlite" ]; then
    gzip -dc "$(ls -1t backups/chesscodex-*.sqlite.gz | head -n1)" > "$DEV/db/chesscodex.sqlite"
fi
if [ ! -f "$DEV/config.php" ]; then
    cat > "$DEV/config.php" <<PHP
<?php
// Local test copy (tools/dev/serve.sh) — never deployed.
return [
    'db'            => ['driver' => 'sqlite', 'path' => __DIR__ . '/db/chesscodex.sqlite'],
    'base_url'      => '',
    'site_url'      => 'http://127.0.0.1:$PORT',
    'seed_token'    => 'local',
    'lichess_token' => '',
    'admin'         => ['username' => 'CHANGE_ME', 'password_hash' => 'CHANGE_ME'],
];
PHP
fi

if [ "$MODE" = "--sync" ]; then
    echo "synced into $DEV"
    exit 0
fi

EXT=()
php -m | grep -qi '^pdo_sqlite$' || EXT=(-d extension=pdo_sqlite -d extension=sqlite3)
echo "http://127.0.0.1:$PORT"
exec php "${EXT[@]}" -d output_buffering=4096 -S "127.0.0.1:$PORT" -t "$DEV" tools/dev/router.php
