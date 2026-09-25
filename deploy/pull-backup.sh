#!/usr/bin/env bash
# Copy the newest nightly database backup from the Pi into ./backups/ here.
# The Pi keeps 14 copies, but on the same SD card — run this now and then.
set -euo pipefail
cd "$(dirname "$0")/.."

HOST=${DEPLOY_HOST:-pi}
SSH=ssh
[ -x /c/Windows/System32/OpenSSH/ssh.exe ] && SSH=/c/Windows/System32/OpenSSH/ssh.exe

LATEST=$("$SSH" "$HOST" 'ls -1t /var/backups/chesscodex/chesscodex-*.sqlite.gz 2>/dev/null | head -n1')
if [ -z "$LATEST" ]; then
    echo "No backups on the Pi yet (the first one runs at 03:30)."
    exit 1
fi
mkdir -p backups
"$SSH" "$HOST" "cat '$LATEST'" > "backups/$(basename "$LATEST")"
echo "Saved backups/$(basename "$LATEST") ($(du -h "backups/$(basename "$LATEST")" | cut -f1))"
