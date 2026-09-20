#!/usr/bin/env bash
#
# Run the LinkEasy test suites.
#
#   ./tests/run.sh              everything that works offline
#   ./tests/run.sh php          server suite
#   ./tests/run.sh node         desktop/worker suite (offline parts)
#   ./tests/run.sh integration  live worker-protocol suite against a running server
#
# The integration suite needs a server; set LINKEASY_TEST_SERVER (defaults to the
# local demo server on :8080) plus LINKEASY_TEST_EMAIL / LINKEASY_TEST_PASSWORD.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-all}"
FAILED=0

heading() { printf '\n\033[1m== %s\033[0m\n' "$1"; }

run_php() {
    heading "PHP suite (server behaviour, SQLite, offline)"
    if ! command -v php >/dev/null 2>&1; then
        echo "php is not installed — skipping."
        return
    fi

    php "$ROOT/tests/php/run.php" || FAILED=1

    heading "PHP syntax check"
    local count=0
    while IFS= read -r file; do
        count=$((count + 1))
        php -l "$file" >/dev/null 2>&1 || { echo "syntax error: $file"; FAILED=1; }
    done < <(find "$ROOT/php-app" "$ROOT/tests" -name '*.php' -not -path '*/storage/*')
    echo "$count files checked."
}

run_node() {
    heading "Node suite (worker + desktop, offline)"
    if ! command -v node >/dev/null 2>&1; then
        echo "node is not installed — skipping."
        return
    fi

    ( cd "$ROOT/windows-app" && node --test tests/unit/ ) || FAILED=1

    heading "JavaScript syntax check"
    local count=0
    while IFS= read -r file; do
        count=$((count + 1))
        node --check "$file" >/dev/null 2>&1 || { echo "syntax error: $file"; FAILED=1; }
    done < <(find "$ROOT/windows-app/src" "$ROOT/windows-app/tests" "$ROOT/installer" -name '*.js' 2>/dev/null)
    echo "$count files checked."
}

run_integration() {
    heading "Worker protocol integration (live server)"

    local server="${LINKEASY_TEST_SERVER:-http://127.0.0.1:8080}"
    export LINKEASY_TEST_SERVER="$server"

    if ! curl -fsS "$server/health" >/dev/null 2>&1; then
        echo "No server answering at $server/health — skipping."
        echo "Start one with:  cd php-app && DB_DRIVER=sqlite PUBLISHING_PROVIDER=simulated \\"
        echo "                 APP_URL=$server php -S 0.0.0.0:8080 -t public public/router.php"
        return
    fi

    if [ -z "${LINKEASY_TEST_EMAIL:-}" ] || [ -z "${LINKEASY_TEST_PASSWORD:-}" ]; then
        echo "Set LINKEASY_TEST_EMAIL and LINKEASY_TEST_PASSWORD to run this suite."
        return
    fi

    ( cd "$ROOT/windows-app" && node --test tests/integration/protocol.test.js ) || FAILED=1
}

case "$TARGET" in
    php)         run_php ;;
    node)        run_node ;;
    integration) run_integration ;;
    all)         run_php; run_node ;;
    *)           echo "Usage: $0 [php|node|integration|all]"; exit 2 ;;
esac

if [ "$FAILED" -eq 0 ]; then
    printf '\n\033[32mAll selected suites passed.\033[0m\n'
else
    printf '\n\033[31mOne or more suites failed.\033[0m\n'
fi

exit "$FAILED"
