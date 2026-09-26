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
    --exclude=.git --exclude=deploy --exclude=docs --exclude=backups --exclude=config.php
    --exclude=.claude --exclude=.dev --exclude=tools/dev --exclude=CLAUDE.md --exclude=.ignore
    --exclude='db/*.sqlite' --exclude='db/*.sqlite-*' --exclude=db/log --exclude=db/og_cache --exclude=db/backups
    --exclude=_composer_vendor
)
if [ "${1:-}" != "--initial" ]; then
    EXCLUDES+=(--exclude=db/cache --exclude=db/sitemap_cache.xml)
fi

# Archive the folder's *contents*, not "." itself: extracting "." would reset
# the mode of the site root on the Pi and drop its setgid bit.
# Afterwards: everything we own goes to group www-data (PHP reads it), folders
# keep setgid so new files inherit that group, and db/ stays writable by PHP.
REMOTE="umask 022 && tar -xzf - -C $SITE --no-overwrite-dir \
 && find $SITE -user \$(id -un) ! -group www-data -exec chgrp www-data {} + \
 && find $SITE -user \$(id -un) -type d -exec chmod g+s {} + \
 && chmod 2750 $SITE && chmod 2770 $SITE/db $SITE/db/cache $SITE/db/log $SITE/db/og_cache $SITE/db/backups"

echo "Uploading to $HOST:$SITE …"
# shellcheck disable=SC2046  # top-level names contain no spaces
tar "${EXCLUDES[@]}" -czf - $(ls -A) | "$SSH" "$HOST" "$REMOTE"
echo "Done."
