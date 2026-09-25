#!/bin/bash
# Save the R2 credentials for the nightly off-site backup, install the updated
# backup script and run one backup right away as a test.
#
#   sudo bash set-r2-backup.sh
#
# The keys come from Cloudflare → R2 → Manage API tokens → Create User API
# token (permission "Object Read & Write", only the chesscodex-backups bucket).
# Input is hidden, never lands in shell history or the process list, and is
# stored root-only in /etc/chesscodex-backup-r2.conf.
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo: sudo bash $0"; exit 1; }

HERE="$(cd "$(dirname "$0")" && pwd)"
ACCOUNT=4a6e6e115cd1b4c2b57868fc744ba009
BUCKET=chesscodex-backups

read -rsp "Access Key ID: " KEY_ID; echo
read -rsp "Secret Access Key: " SECRET; echo
KEY_ID=$(printf '%s' "$KEY_ID" | tr -d '[:space:]')
SECRET=$(printf '%s' "$SECRET" | tr -d '[:space:]')
# R2 access key IDs are 32 characters, secrets 64.
if ! [[ "$KEY_ID" =~ ^[A-Za-z0-9]{32}$ && "$SECRET" =~ ^[A-Za-z0-9]{64}$ ]]; then
    echo "That doesn't look like an R2 key pair (got ${#KEY_ID} and ${#SECRET} characters," \
         "expected 32 and 64). Copy them again from Cloudflare. Nothing changed."
    exit 1
fi

umask 077
printf 'R2_ACCOUNT=%s\nR2_BUCKET=%s\nR2_KEY_ID=%s\nR2_SECRET=%s\n' \
    "$ACCOUNT" "$BUCKET" "$KEY_ID" "$SECRET" > /etc/chesscodex-backup-r2.conf
unset KEY_ID SECRET
echo "Saved /etc/chesscodex-backup-r2.conf (root only)."

install -m 755 "$HERE/chesscodex-backup" /usr/local/sbin/chesscodex-backup

echo "== Test backup"
if systemctl start chesscodex-backup.service; then
    journalctl -u chesscodex-backup -n 3 --no-pager -o cat
    echo "DONE — the backup is on the Pi and in R2."
else
    journalctl -u chesscodex-backup -n 10 --no-pager -o cat
    echo "FAILED — see the lines above."
    exit 1
fi
