#!/bin/bash
# Re-apply server configs from this folder after they change. Safe to re-run.
#
#   sudo bash pi-update.sh      nginx site + headers, SSH login notifier, backups, USB rescue copy, persistent journal
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

echo "== backups: nightly database copy, weekly SD card image on the USB drive"
install -m 755 "$HERE/chesscodex-backup" /usr/local/sbin/chesscodex-backup
install -m 755 "$HERE/sd-image-backup" /usr/local/sbin/sd-image-backup
install -m 644 "$HERE/systemd/sd-image-backup.service" "$HERE/systemd/sd-image-backup.timer" /etc/systemd/system/

echo "== bootable rescue copy on the USB drive (lay the drive out once with usb-rescue-setup.sh)"
install -m 755 "$HERE/usb-rescue-sync" "$HERE/usb-rescue-alert" /usr/local/sbin/
install -m 644 "$HERE/systemd/usb-rescue-sync.service" "$HERE/systemd/usb-rescue-sync.timer" \
    "$HERE/systemd/usb-rescue-alert.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now sd-image-backup.timer usb-rescue-sync.timer
systemctl enable usb-rescue-alert.service

echo "== journal kept across reboots"
install -D -m 644 "$HERE/systemd/journald-persistent.conf" /etc/systemd/journald.conf.d/60-persistent.conf
systemctl restart systemd-journald
journalctl --flush
journalctl --disk-usage
echo "DONE"
