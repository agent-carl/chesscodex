#!/bin/bash
# Warm the Lichess statistics cache for every opening, one request at a time
# (run on the Pi as the site owner; no sudo). Opening pages print cached
# numbers into their HTML, so this is what makes them visible to search
# engines. Takes about an hour for all 3,690 openings; already-cached ones are
# skipped, so it's safe to stop (Ctrl+C) and run again. At the end it sets each
# opening's popularity from the cached game counts (tools/backfill-popularity.php).
#
#   nohup bash ~/deploy/prewarm-all.sh > ~/prewarm.log 2>&1 &
#   tail -f ~/prewarm.log
#
# Goes through PHP-FPM (not the PHP CLI) so it shares the Lichess lock and the
# 429 cool-down with visitors' requests. The token travels in the POST body,
# not the URL, so it stays out of the nginx access log.
set -uo pipefail

SITE=${SITE:-/var/www/chesscodex}
URL=${URL:-http://127.0.0.1:8081/prewarm.php}
TOKEN=$(php -r '$c = require "'"$SITE"'/config.php"; echo $c["seed_token"] ?? "";')
[ -n "$TOKEN" ] || { echo "seed_token is empty in $SITE/config.php"; exit 1; }

offset=0
while :; do
    out=$(printf 'token=%s' "$TOKEN" | curl -sS -m 90 --data-binary @- \
          -H 'Content-Type: application/x-www-form-urlencoded' "$URL?n=60&offset=$offset") || {
        echo "$(date '+%F %T') request failed, retrying in 30 s"; sleep 30; continue; }
    summary=$(printf '%s\n' "$out" | tr -s ' ' | grep -E '^(fetched=|\[fail\]|\[busy\])')
    [ -n "$summary" ] && printf '%s offset=%s %s\n' "$(date '+%F %T')" "$offset" "$summary"

    retry=$(printf '%s\n' "$out" | sed -n 's/^RETRY_AFTER=\([0-9]*\).*/\1/p')
    next=$(printf '%s\n' "$out" | sed -n 's/^NEXT_OFFSET=\([0-9]*\).*/\1/p')
    if [ -n "$retry" ]; then
        echo "Lichess asked to slow down, waiting $((retry + 5)) s"
        sleep $((retry + 5))
    fi
    if [ -z "$next" ]; then
        printf '%s\n' "$out" | grep -q 'Reached end of table' && {
            echo "$(date '+%F %T') done"
            php "$SITE/tools/backfill-popularity.php"
            exit $?
        }
        echo "unexpected answer:"; printf '%s\n' "$out" | tr -s ' ' | tail -5; exit 1
    fi
    offset=$next
done
