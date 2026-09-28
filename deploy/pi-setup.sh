#!/bin/bash
# One-time server setup for Caissa Codex on the Raspberry Pi (Debian 13 "trixie").
#
#   sudo bash pi-setup.sh
#
# Installs nginx + PHP 8.4-FPM + SQLite + cloudflared, wires up the configs in
# this folder, the nightly database backup and automatic cloudflared updates.
# Safe to re-run. Doesn't touch the site files — deploy/deploy.sh uploads those.
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo: sudo bash $0"; exit 1; }

HERE="$(cd "$(dirname "$0")" && pwd)"
SITE=/var/www/chesscodex
OWNER=${SUDO_USER:-ivan}
# "CloudFlare Software Packaging 2025" — the key that signs pkg.cloudflare.com.
CF_FPR=CC94B39C77AE7342A68B89628A682D308D4E5E73

echo "== 1/7 Cloudflare package repository"
if [ ! -f /usr/share/keyrings/cloudflare-main.gpg ]; then
    curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg -o /tmp/cloudflare-main.gpg
    FPR=$(gpg --show-keys --with-colons /tmp/cloudflare-main.gpg | awk -F: '/^fpr/ {print $10; exit}')
    if [ "$FPR" != "$CF_FPR" ]; then
        echo "Cloudflare key fingerprint is $FPR, expected $CF_FPR — aborting."
        exit 1
    fi
    install -m 644 /tmp/cloudflare-main.gpg /usr/share/keyrings/cloudflare-main.gpg
    rm -f /tmp/cloudflare-main.gpg
fi
echo 'deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main' \
    > /etc/apt/sources.list.d/cloudflared.list

echo "== 2/7 Packages"
# Don't let package scripts start services mid-install: nginx's default site
# would grab port 80, which AdGuard Home already uses.
printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d
chmod 755 /usr/sbin/policy-rc.d
trap 'rm -f /usr/sbin/policy-rc.d' EXIT
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    nginx php8.4-fpm php8.4-cli php8.4-sqlite3 php8.4-gd php8.4-mbstring php8.4-curl php8.4-opcache \
    fonts-dejavu-core sqlite3 cloudflared
rm -f /usr/sbin/policy-rc.d

echo "== 3/7 Site folder $SITE"
# $OWNER deploys the code; PHP (www-data) reads it and writes only under db/.
usermod -aG www-data "$OWNER"
install -d -o "$OWNER" -g www-data -m 2750 "$SITE"
for d in db db/cache db/log db/og_cache db/backups; do
    install -d -o "$OWNER" -g www-data -m 2770 "$SITE/$d"
done

echo "== 4/7 PHP-FPM"
TZ_NAME=$(timedatectl show -p Timezone --value 2>/dev/null || echo UTC)
for sapi in fpm cli; do
    sed "s#@TIMEZONE@#$TZ_NAME#" "$HERE/php/90-chesscodex.ini" > "/etc/php/8.4/$sapi/conf.d/90-chesscodex.ini"
done
install -m 644 "$HERE/php/chesscodex-pool.conf" /etc/php/8.4/fpm/pool.d/chesscodex.conf
if [ -f /etc/php/8.4/fpm/pool.d/www.conf ]; then
    mv /etc/php/8.4/fpm/pool.d/www.conf /etc/php/8.4/fpm/pool.d/www.conf.disabled
fi
php-fpm8.4 -t
systemctl enable php8.4-fpm
systemctl restart php8.4-fpm

echo "== 5/7 nginx"
install -m 644 "$HERE/nginx/chesscodex-headers.conf" "$HERE/nginx/chesscodex-php.conf" /etc/nginx/snippets/
install -m 644 "$HERE/nginx/chesscodex.conf" /etc/nginx/sites-available/chesscodex
ln -sf ../sites-available/chesscodex /etc/nginx/sites-enabled/chesscodex
rm -f /etc/nginx/sites-enabled/default           # would take port 80 from AdGuard Home
sed -i 's/^worker_processes auto;/worker_processes 2;/' /etc/nginx/nginx.conf   # 2 are plenty behind the tunnel
nginx -t
systemctl enable nginx
systemctl restart nginx

echo "== 6/7 Nightly database backup (03:30)"
install -m 755 "$HERE/chesscodex-backup" /usr/local/sbin/chesscodex-backup
install -m 644 "$HERE/systemd/chesscodex-backup.service" "$HERE/systemd/chesscodex-backup.timer" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now chesscodex-backup.timer

echo "== 7/7 Cloudflare Tunnel service (the token is added separately)"
install -m 644 "$HERE/systemd/cloudflared.service" /etc/systemd/system/cloudflared.service
# cloudflared's QUIC transport wants bigger UDP buffers than Debian's default.
printf 'net.core.rmem_max = 7500000\nnet.core.wmem_max = 7500000\n' > /etc/sysctl.d/90-cloudflared.conf
sysctl -q -p /etc/sysctl.d/90-cloudflared.conf
# Keep cloudflared patched like the rest of the system.
cat > /etc/apt/apt.conf.d/53unattended-upgrades-cloudflared <<'CONF'
// cloudflared is internet-facing: install its updates automatically too.
Unattended-Upgrade::Origins-Pattern {
        "origin=cloudflared,label=cloudflared";
};
CONF
systemctl daemon-reload

echo
echo "Installed: $(nginx -v 2>&1 | cut -d/ -f2), PHP $(php -r 'echo PHP_VERSION;'), SQLite $(sqlite3 --version | cut -d' ' -f1), $(cloudflared --version 2>&1 | head -n1)"
echo "Services:  nginx=$(systemctl is-active nginx)  php8.4-fpm=$(systemctl is-active php8.4-fpm)  backup-timer=$(systemctl is-active chesscodex-backup.timer)"
echo "DONE"
