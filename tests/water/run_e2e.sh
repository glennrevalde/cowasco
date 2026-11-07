#!/bin/bash
# Builds a fresh test database, migrates the former water database into it, serves this folder with PHP's built-in
# server and runs the HTTP end-to-end tests. Nothing outside the test database is changed.
#
#   tests/water/run_e2e.sh <former water database> [test database name] [port]
set -u
SRC=${1:?former water database name}
DB=${2:-coop_water_e2e_test}
PORT=${3:-8091}
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
cd "$ROOT"
export DB_NAME=$DB
echo "== fresh install: $DB"
php tools/install.php --drop > /tmp/e2e_install_$$.log 2>&1 || { cat /tmp/e2e_install_$$.log; exit 1; }
echo "== migrate $SRC -> $DB"
php tools/migrate_water.php --source="$SRC" --cutover="$(date +%Y-%m-%d)" > /tmp/e2e_migrate_$$.log 2>&1 || { tail -30 /tmp/e2e_migrate_$$.log; exit 1; }
grep -E "^Result:" /tmp/e2e_migrate_$$.log
echo "== web server on 127.0.0.1:$PORT"
PHP_CLI_SERVER_WORKERS=4 setsid php -S 127.0.0.1:$PORT -t "$ROOT" > /tmp/e2e_server_$$.log 2>&1 < /dev/null &
SERVER=$!
trap 'kill $SERVER 2>/dev/null' EXIT
sleep 1
php tests/water/e2e_http.php --base="http://127.0.0.1:$PORT"
RC=$?
grep -iE "PHP (Fatal|Warning|Notice|Deprecated)" /tmp/e2e_server_$$.log | head -20
exit $RC
