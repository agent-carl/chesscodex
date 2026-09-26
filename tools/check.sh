#!/usr/bin/env bash
# One command before a commit or deploy, with short output: PHP syntax, the
# unit tests, minified assets rebuilt from their sources, JavaScript syntax.
# Prints one line per step (details only on failure); exits non-zero on failure.
#
#   bash tools/check.sh
set -uo pipefail
cd "$(dirname "$0")/.."
fail=0

# 1. PHP syntax — everything except vendor/ and the local test copy.
n=0
while IFS= read -r f; do
    n=$((n + 1))
    out=$(php -l "$f" 2>&1) || { echo "$out"; fail=1; }
done < <(find . -name '*.php' -not -path './vendor/*' -not -path './.dev/*' -not -path './.git/*')
echo "php -l: $n files"

# 2. Unit tests.
out=$(php tests/run.php 2>&1)
if printf '%s\n' "$out" | grep -q '^failed: 0$'; then
    echo "tests: $(printf '%s\n' "$out" | grep '^passed:')"
else
    printf '%s\n' "$out" | tail -n 20
    fail=1
fi

# 3. Minified assets: rebuild, then report which ones changed (commit those too).
php tools/minify.php > /dev/null || fail=1
changed=$(git status --porcelain -- 'public/*.min.js' 'public/*.min.css' | awk '{print $2}' | tr '\n' ' ')
echo "minify: ${changed:-all up to date}"

# 4. JavaScript syntax (sources and minified files).
if command -v node > /dev/null; then
    n=0
    for f in public/*.js; do
        n=$((n + 1))
        out=$(node --check "$f" 2>&1) || { echo "$f: $out" | head -n 5; fail=1; }
    done
    echo "node --check: $n files"
else
    echo "node --check: skipped (node not installed)"
fi

[ "$fail" -eq 0 ] && echo "OK" || echo "FAILED"
exit "$fail"
