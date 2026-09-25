#!/usr/bin/env bash
# Upload the site from this folder to the Pi (Git Bash on Windows, or any Unix shell).
#
#   deploy/deploy.sh            code only — keeps the Pi's config.php, database, caches, logs
#   deploy/deploy.sh --initial  also sends db/cache/*.json and db/sitemap_cache.xml, the old
#                               site's snapshots that tools/seed.php verifies against
#
# Nothing to restart afterwards: OPcache picks up changed files within 2 s.
set -euo pipefail
cd "$(dirname "$0")/.."

HOST=${DEPLOY_HOST:-pi}
SITE=/var/www/chesscodex
# Windows' own OpenSSH talks to the ssh-agent holding the passphrase-protected key;
# Git Bash's bundled ssh doesn't.
SSH=ssh
[ -x /c/Windows/System32/OpenSSH/ssh.exe ] && SSH=/c/Windows/System32/OpenSSH/ssh.exe

EXCLUDES=(
    --exclude=./.git --exclude=./deploy --exclude=./backups --exclude=./config.php
    --exclude='./db/*.sqlite*' --exclude=./db/log --exclude=./db/og_cache --exclude=./db/backups
    --exclude=./_composer_vendor
)
if [ "${1:-}" != "--initial" ]; then
    EXCLUDES+=(--exclude=./db/cache --exclude=./db/sitemap_cache.xml)
fi

echo "Uploading to $HOST:$SITE …"
tar "${EXCLUDES[@]}" -czf - . | "$SSH" "$HOST" "umask 022 && tar -xzf - -C $SITE --no-overwrite-dir"
echo "Done."
