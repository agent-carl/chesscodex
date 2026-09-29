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

echo "== smooth fan curve (the kernel's fan steps in config.txt stay as a safety net from 80 °C)"
install -m 755 "$HERE/fan-curve" /usr/local/sbin/fan-curve
install -m 644 "$HERE/systemd/fan-curve.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable fan-curve.service
systemctl restart fan-curve.service
CFG=/boot/firmware/config.txt
if ! grep -q '^dtparam=fan_temp0=80000$' "$CFG"; then
    cp -a "$CFG" "$CFG.bak-$(date +%F)"
    sed -i '/^# Fan: /d; /^dtparam=fan_temp[0-3]/d' "$CFG"
    [ "$(grep '^\[' "$CFG" | tail -n 1)" = "[all]" ] || echo "[all]" >> "$CFG"
    cat >> "$CFG" <<'EOF'
# Fan: the fan-curve service runs it smoothly; these steps only act from 80 C,
# at full speed, if that service isn't running.
dtparam=fan_temp0=80000
dtparam=fan_temp0_hyst=5000
dtparam=fan_temp0_speed=255
dtparam=fan_temp1=82000
dtparam=fan_temp1_hyst=5000
dtparam=fan_temp1_speed=255
dtparam=fan_temp2=84000
dtparam=fan_temp2_hyst=5000
dtparam=fan_temp2_speed=255
dtparam=fan_temp3=86000
dtparam=fan_temp3_hyst=5000
dtparam=fan_temp3_speed=255
EOF
    echo "config.txt: fan safety steps moved to 80 °C and up (backup $CFG.bak-$(date +%F)) — reboot to apply"
fi

echo "== journal kept across reboots"
install -D -m 644 "$HERE/systemd/journald-persistent.conf" /etc/systemd/journald.conf.d/60-persistent.conf
systemctl restart systemd-journald
journalctl --flush
journalctl --disk-usage
echo "DONE"
