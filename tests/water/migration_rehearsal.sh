#!/bin/bash
# Migration rehearsal: fresh target, dry run, real run, second run (idempotent), the optional membership import twice,
# verification after each step, then the rollback of the run on a copy. Every step's output is kept in OUT.
#
#   tests/water/migration_rehearsal.sh <source water db> <target db> <out dir> [savings product code]
#
# The target database is DROPPED and created again. MySQL credentials come from config/config.php / DB_* variables.
set -u
SRC=$1; DST=$2; OUT=$3; PROD=${4:-}
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
mkdir -p "$OUT"
export DB_NAME=$DST
step() { echo "== $1"; }
run() { # name, command...
  local name=$1; shift
  "$@" > "$OUT/$name.log" 2>&1; local rc=$?
  echo "   $name: exit $rc  ($(grep -m1 -E '^Result:' "$OUT/$name.log" || tail -1 "$OUT/$name.log"))"
  return $rc
}
cd "$ROOT"
step "fresh target $DST"
run 00_install php tools/install.php --drop
if [ -n "$PROD" ]; then
  php -r 'require "app/bootstrap.php"; Auth::actAs((int)DB::value("SELECT id FROM users WHERE username = \"admin\"")); if (!DB::value("SELECT id FROM sav_products WHERE code = :c", ["c" => $argv[1]])) { DB::insert("sav_products", ["code" => $argv[1], "name" => "Regular Savings", "product_type" => "regular", "is_active" => 1, "calendar_days" => 365, "liability_account_id" => Accts::get("savings_deposits")]); } echo "savings product ", $argv[1], " ready\n";' "$PROD" >> "$OUT/00_install.log" 2>&1
fi
step "dry run"
run 01_dry_run php tools/migrate_water.php --source="$SRC" --cutover=2026-10-13 --dry-run
mysql_count() { php -r 'require "app/bootstrap.php"; echo DB::value($argv[1]);' "$1"; }
echo "   transactions after the dry run: $(mysql_count 'SELECT COUNT(*) FROM transactions') (must be 0), water accounts: $(mysql_count 'SELECT COUNT(*) FROM water_accounts') (must be 0)"
step "real run"
run 02_real_run php tools/migrate_water.php --source="$SRC" --cutover=2026-10-13
step "second run (must write nothing)"
run 03_second_run php tools/migrate_water.php --source="$SRC" --cutover=2026-10-13
grep -E "^   done: read" "$OUT/03_second_run.log" | awk '{print "     " $0}'
if [ -n "$PROD" ]; then
  step "membership import"
  run 04_membership php tools/migrate_water.php --source="$SRC" --cutover=2026-10-13 --phase=membership --import-membership --savings-product="$PROD"
  run 05_membership_again php tools/migrate_water.php --source="$SRC" --cutover=2026-10-13 --phase=membership --import-membership --savings-product="$PROD"
  run 06_verify_after_import php tools/migrate_water.php --source="$SRC" --cutover=2026-10-13 --phase=verify
fi
echo "   counts: water_accounts=$(mysql_count 'SELECT COUNT(*) FROM water_accounts') parties=$(mysql_count 'SELECT COUNT(*) FROM parties') members=$(mysql_count 'SELECT COUNT(*) FROM members') sav_accounts=$(mysql_count 'SELECT COUNT(*) FROM sav_accounts') water_postings=$(mysql_count 'SELECT COUNT(*) FROM water_postings')"
echo "   trial balance difference (debits - credits): $(mysql_count 'SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines')"

# ---- rollback rehearsal: undo the runs newest first, then compare every table with a fresh install
counts() { php -r 'require "app/bootstrap.php"; foreach (DB::column("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \"BASE TABLE\" ORDER BY table_name") as $t) { if (in_array($t, ["audit_trail", "water_migration_log", "sequences", "settings"], true)) continue; echo $t, "\t", DB::value("SELECT COUNT(*) FROM `$t`"), "\n"; }'; }
step "rollback (newest run first)"
for RUN in $(php -r 'require "app/bootstrap.php"; foreach (DB::column("SELECT run_id FROM water_migration_log WHERE status = \"done\" AND phase <> \"verify\" AND rows_written > 0 GROUP BY run_id ORDER BY MAX(id) DESC") as $r) echo $r, "\n";'); do
  run "07_rollback_$RUN" php tools/rollback_water.php --run="$RUN" --source="$SRC"
done
counts > "$OUT/counts_after_rollback.tsv"
export DB_NAME=${DST}_fresh
php tools/install.php --drop > /dev/null 2>&1
if [ -n "$PROD" ]; then
  php -r 'require "app/bootstrap.php"; Auth::actAs(1); DB::insert("sav_products", ["code" => $argv[1], "name" => "Regular Savings", "product_type" => "regular", "is_active" => 1, "calendar_days" => 365, "liability_account_id" => Accts::get("savings_deposits")]);' "$PROD"
fi
counts > "$OUT/counts_fresh.tsv"
export DB_NAME=$DST
if diff "$OUT/counts_fresh.tsv" "$OUT/counts_after_rollback.tsv" > "$OUT/counts_diff.txt"; then
  echo "   rollback: every table has the row count of a fresh install (PASS)"
else
  echo "   rollback: tables differ from a fresh install (see counts_diff.txt):"; sed 's/^/     /' "$OUT/counts_diff.txt" | head -20
fi
