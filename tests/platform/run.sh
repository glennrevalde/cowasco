#!/bin/bash
# Setting up a new company on the platform, end to end.
#
#   bash tests/platform/run.sh [database prefix]        (default prefix: prov_t)
#
# Makes a throw-away copy of the program (the platform's switch file is written in the copy, never in this folder),
# a template database <prefix> from database/accounting_system_clean.sql, runs tests/platform/provision_test.php,
# then removes the copy, the template, the master database and every company database it made.
# Needs the mysql client with rights to create and drop databases (as the other tests).
set -u
HERE="$(cd "$(dirname "$0")/../.." && pwd)"
DB="${1:-prov_t}"
COPY="$(mktemp -d)"
cleanup() {
    for d in $(mysql -N -e "SELECT schema_name FROM information_schema.schemata WHERE schema_name = '$DB' OR schema_name LIKE '${DB}\\_%'"); do
        mysql -e "DROP DATABASE \`$d\`"
    done
    rm -rf "$COPY"
}
trap cleanup EXIT
cleanup; COPY="$(mktemp -d)"

rsync -a --exclude=.git --exclude='storage/platform/' --exclude='storage/companies/' "$HERE/" "$COPY/"
sed -e "s/\`cooperative_system\`/\`$DB\`/g" "$HERE/database/accounting_system_clean.sql" | mysql --default-character-set=utf8mb4 || exit 1
DB_NAME="$DB" php "$COPY/tests/platform/provision_test.php" "$COPY"
