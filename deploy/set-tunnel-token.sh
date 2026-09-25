#!/bin/bash
# Save the Cloudflare Tunnel token and (re)start the tunnel.
#
#   sudo bash set-tunnel-token.sh
#
# The token is in Cloudflare Zero Trust → Networks → Tunnels → your tunnel →
# the install command ("… service install eyJ…"). Paste the token or the whole
# command. Input is hidden, never lands in shell history or the process list,
# and is stored root-only in /etc/cloudflared/tunnel.env.
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo: sudo bash $0"; exit 1; }

read -rsp "Tunnel token (or the whole install command): " INPUT
echo
TOKEN=$(printf '%s' "$INPUT" | grep -oE 'eyJ[A-Za-z0-9+/=_-]+' | head -n1 || true)
unset INPUT
if [ ${#TOKEN} -lt 100 ]; then
    echo "That doesn't look like a tunnel token (it starts with eyJ… and is 150+ characters). Nothing changed."
    exit 1
fi

install -d -m 700 /etc/cloudflared
umask 077
printf 'TUNNEL_TOKEN=%s\n' "$TOKEN" > /etc/cloudflared/tunnel.env
unset TOKEN

systemctl daemon-reload
systemctl enable cloudflared >/dev/null 2>&1
systemctl restart cloudflared
echo "Waiting for the tunnel to connect…"
for _ in $(seq 1 15); do
    if journalctl -u cloudflared --since "-60s" -o cat | grep -q "Registered tunnel connection"; then
        echo "✅ Tunnel connected to Cloudflare. DONE"
        exit 0
    fi
    sleep 2
done
echo "⚠ Not connected after 30 s. Last log lines:"
journalctl -u cloudflared --since "-60s" -o cat | tail -n 10
exit 1
