#!/bin/bash
# Re-apply server configs from this folder after they change. Safe to re-run.
#
#   sudo bash pi-update.sh            nginx site + headers, SSH login notifier
#   sudo bash pi-update.sh --ssh-ca   …plus the in-browser terminal: asks for the
#                                     Cloudflare SSH CA public key
#
# The in-browser terminal (ssh.chesscodex.org): after you pass Cloudflare
# Access, Cloudflare signs a short-lived SSH certificate for the local part of
# your e-mail. sshd trusts that CA only for the principals listed per user, so
# the terminal logs in as ivan while password logins stay disabled.
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo: sudo bash $0"; exit 1; }

HERE="$(cd "$(dirname "$0")" && pwd)"
OWNER=${SUDO_USER:-ivan}
PRINCIPAL=you          # e-mail local part: you@example.com

echo "== nginx"
install -m 644 "$HERE/nginx/chesscodex-headers.conf" "$HERE/nginx/chesscodex-php.conf" /etc/nginx/snippets/
install -m 644 "$HERE/nginx/chesscodex.conf" /etc/nginx/sites-available/chesscodex
nginx -t
systemctl reload nginx

echo "== SSH login notifier"
install -m 755 "$HERE/ssh-login-notify" /usr/local/sbin/ssh-login-notify

if [ "${1:-}" = "--ssh-ca" ]; then
    echo "== In-browser terminal: Cloudflare SSH CA"
    read -rp "Paste the CA public key (one line, ecdsa-sha2-nistp256 AAAA…): " KEY
    printf '%s\n' "$(printf '%s' "$KEY" | tr -d '\r')" > /tmp/cloudflare-ca.pub
    if ! ssh-keygen -l -f /tmp/cloudflare-ca.pub >/dev/null 2>&1; then
        rm -f /tmp/cloudflare-ca.pub
        echo "That isn't an SSH public key. Nothing changed."
        exit 1
    fi
    install -m 644 /tmp/cloudflare-ca.pub /etc/ssh/cloudflare-ca.pub
    rm -f /tmp/cloudflare-ca.pub
    install -d -m 755 /etc/ssh/auth_principals
    printf '%s\n' "$PRINCIPAL" > "/etc/ssh/auth_principals/$OWNER"
    chmod 644 "/etc/ssh/auth_principals/$OWNER"
    cat > /etc/ssh/sshd_config.d/03-cloudflare-ca.conf <<'CONF'
# In-browser terminal (ssh.chesscodex.org): accept Cloudflare Access short-lived
# certificates, only for the principals listed in /etc/ssh/auth_principals/<user>.
TrustedUserCAKeys /etc/ssh/cloudflare-ca.pub
AuthorizedPrincipalsFile /etc/ssh/auth_principals/%u
CONF
    sshd -t
    systemctl reload ssh
    echo "   CA: $(ssh-keygen -l -f /etc/ssh/cloudflare-ca.pub | cut -d' ' -f1-2) — principal '$PRINCIPAL' → $OWNER"
fi
echo "DONE"
