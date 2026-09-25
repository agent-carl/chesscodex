#!/bin/bash
# Re-apply server configs from this folder after they change. Safe to re-run.
#
#   sudo bash pi-update.sh      nginx site + headers, SSH login notifier, persistent journal
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo: sudo bash $0"; exit 1; }

HERE="$(cd "$(dirname "$0")" && pwd)"

echo "== nginx"
install -m 644 "$HERE/nginx/chesscodex-headers.conf" "$HERE/nginx/chesscodex-php.conf" /etc/nginx/snippets/
install -m 644 "$HERE/nginx/chesscodex.conf" /etc/nginx/sites-available/chesscodex
nginx -t
systemctl reload nginx

echo "== SSH login notifier"
install -m 755 "$HERE/ssh-login-notify" /usr/local/sbin/ssh-login-notify

echo "== journal kept across reboots"
install -D -m 644 "$HERE/systemd/journald-persistent.conf" /etc/systemd/journald.conf.d/60-persistent.conf
systemctl restart systemd-journald
journalctl --flush
journalctl --disk-usage
echo "DONE"
