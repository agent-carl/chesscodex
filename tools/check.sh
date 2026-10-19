#!/usr/bin/env bash
# One command before a commit or deploy, with short output: PHPStan (which
# also catches PHP syntax errors), the PHPUnit tests, ESLint on the JavaScript,
# and the minified assets rebuilt by esbuild. One line per step, details only
# on failure; exits non-zero on failure. Needs the packages: `composer install`
# and `npm ci`.
#
#   bash tools/check.sh
set -uo pipefail
cd "$(dirname "$0")/.."
fail=0

# 1. Static analysis (phpstan.neon).
if out=$(_composer_vendor/bin/phpstan analyse --memory-limit=1G --no-progress --error-format=raw 2>&1); then
    echo "phpstan: no errors"
else
    printf '%s\n' "$out" | grep -E 'identifier=|^\?:|rror' | head -n 20
    fail=1
fi

# 2. Unit tests (phpunit.xml.dist).
if out=$(_composer_vendor/bin/phpunit --colors=never 2>&1); then
    echo "phpunit: $(printf '%s\n' "$out" | grep -o 'OK ([^)]*)')"
else
    printf '%s\n' "$out" | tail -n 30
    fail=1
fi

# 3. JavaScript lint (eslint.config.mjs): public/*.js, not the minified output.
if out=$(npx --no-install eslint . 2>&1); then
    echo "eslint: no problems"
else
    printf '%s\n' "$out" | tail -n 30
    fail=1
fi

# 4. Minified assets: rebuild, then report which ones changed (commit those too).
npm run --silent build > /dev/null || fail=1
changed=$(git status --porcelain -- 'public/*.min.js' 'public/*.min.css' vendor/chess.min.js | awk '{print $2}' | tr '\n' ' ')
echo "build: ${changed:-all up to date}"

[ "$fail" -eq 0 ] && echo "OK" || echo "FAILED"
exit "$fail"
