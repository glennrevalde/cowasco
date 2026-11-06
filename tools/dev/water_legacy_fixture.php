<?php
declare(strict_types=1);

/**
 * Rehearsal of the ledger side of tools/migrate_water.php (phase 7) with legacy transactions.
 *
 *   php tools/dev/water_legacy_fixture.php [--from=mnssmc_test] [--source=mnssmc_fixture] [--target=coop_water_fx] [--keep-source]
 *
 *   --keep-source  keeps the fixture copy of the former database afterwards (it is dropped by default)
 *
 *  1. makes a COPY of the former water database (--from) as --source (dropped and recreated), and loads
 *     tools/dev/water_legacy_fixture.sql into it (posted bills, receipts and their legacy journal);
 *  2. builds a fresh integrated database --target the way tests/coop/run.php does (schema, triggers, master data,
 *     the 2026_11_01, 2026_11_15 and 2026_12_01 migrations);
 *  3. runs the migration from --source into --target with cutover 2026-08-31;
 *  4. checks that the open invoices, the opening-balance journal and the legacy ledger reconcile, and prints a
 *     PASS / FAIL table. Exit code 0 when every check passes.
 *
 * Developer tool: both databases are throw-away. Never point --target at a live database.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = getopt('', ['from:', 'source:', 'target:', 'keep-source']);
$from = (string)($opts['from'] ?? 'mnssmc_test');
$source = (string)($opts['source'] ?? 'mnssmc_fixture');
$target = (string)($opts['target'] ?? 'coop_water_fx');
putenv('DB_NAME=' . $target);

require __DIR__ . '/../../app/bootstrap.php';
require APP_ROOT . '/tools/lib.php';
require APP_ROOT . '/tools/seed.php';
require APP_ROOT . '/tools/water_migration_lib.php';

foreach ([$from, $source, $target] as $n) {
    if (!DB::validName($n)) {
        fwrite(STDERR, "Invalid database name $n\n");
        exit(1);
    }
}
if ($source === $from || $target === $from || $target === $source || in_array($target, ['accounting_system', 'cooperative_system'], true)) {
    fwrite(STDERR, "Refusing: --from, --source and --target must be three different throw-away databases.\n");
    exit(1);
}
$_SESSION = [];
$t0 = microtime(true);
$server = server_pdo();

// ---------------------------------------------------------------------------------------- 1. the source copy + fixture
out("Copying `$from` to `$source` ...");
$server->exec("DROP DATABASE IF EXISTS `$source`");
$server->exec("CREATE DATABASE `$source` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$server->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
$server->exec('SET FOREIGN_KEY_CHECKS = 0');
$st = $server->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name");
$st->execute([$from]);
$n = 0;
foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $server->exec("CREATE TABLE `$source`.`$t` LIKE `$from`.`$t`");
    $server->exec("INSERT INTO `$source`.`$t` SELECT * FROM `$from`.`$t`");
    $n++;
}
$server->exec('SET FOREIGN_KEY_CHECKS = 1');
out("  $n tables copied. Loading the fixture ...");
$srcPdo = DB::connect($source);
$srcPdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
run_sql_file($srcPdo, __DIR__ . '/water_legacy_fixture.sql');
$fx = $srcPdo->query('SELECT (SELECT COUNT(*) FROM bill_readings) r, (SELECT COUNT(*) FROM one_time_bills) o, (SELECT COUNT(*) FROM payment_transactions) p, (SELECT COUNT(*) FROM journalentries_copy1) j')->fetch();
out("  fixture: {$fx['r']} bill readings, {$fx['o']} one-time bill, {$fx['p']} receipts, {$fx['j']} legacy journal lines");

// ---------------------------------------------------------------------------------------- 2. a fresh target
out("Building `$target` (schema, triggers, master data, cooperative + party + water migrations) ...");
$server->exec("DROP DATABASE IF EXISTS `$target`");
$server->exec("CREATE DATABASE `$target` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = DB::pdo();
run_sql_file($pdo, APP_ROOT . '/database/schema.sql');
run_sql_file($pdo, APP_ROOT . '/database/triggers.sql');
DB::tx(fn() => seed_master_data());
run_sql_file($pdo, APP_ROOT . '/database/migrations/2026_11_01_cooperative.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2026_11_15_multipurpose_party.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2026_12_01_water_billing.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2026_12_20_member_lists_class_je_changes.sql');
Settings::flush();
Accts::reset();
out(sprintf('  ready (%.1fs)', microtime(true) - $t0));

// ---------------------------------------------------------------------------------------- 3. the migration (its own process, like the real thing)
$cutover = '2026-08-31';
$cmd = 'DB_NAME=' . escapeshellarg($target) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tools/migrate_water.php') . ' --source=' . escapeshellarg($source) . ' --cutover=' . $cutover;
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $cmd = 'set DB_NAME=' . $target . '&& ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tools/migrate_water.php') . ' --source=' . $source . ' --cutover=' . $cutover;
}
out('Running: ' . $cmd);
passthru($cmd, $rc);
out('  migration exit code ' . $rc);

// ---------------------------------------------------------------------------------------- 4. the reconciliation checks
Settings::flush();
Accts::reset();
$adminId = (int)DB::value("SELECT id FROM users WHERE username = 'admin'");
Auth::actAs($adminId);
$rows = [];
$allOk = $rc === 0;
$check = function (string $what, $expected, $actual, ?bool $ok = null) use (&$rows, &$allOk) {
    $ok ??= is_float($expected) || is_float($actual) ? abs((float)$expected - (float)$actual) < 0.005 : (string)$expected === (string)$actual;
    $fmt = fn($v) => is_float($v) ? wm_money($v) : (string)$v;
    $rows[] = [$what, $fmt($expected), $fmt($actual), $ok ? 'PASS' : 'FAIL'];
    if (!$ok) {
        $allOk = false;
    }
};
$check('migration exit code', 0, $rc);
$ar = (int)DB::value("SELECT account_id FROM water_coa_map WHERE legacy_accnt_id = 11784");
$cash = (int)DB::value("SELECT account_id FROM water_coa_map WHERE legacy_accnt_id = 11118");
$income = (int)DB::value("SELECT account_id FROM water_coa_map WHERE legacy_accnt_id = 40210");
$clearing = (int)DB::value("SELECT account_id FROM default_accounts WHERE setting_key = 'water_legacy_clearing'");
$check('legacy accounts 11784 / 11118 / 40210 mapped', 'yes', $ar && $cash && $income ? 'yes' : 'no');

$openInvoices = r2((float)DB::value("SELECT COALESCE(SUM(t.total), 0) FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE w.status = 'posted' AND t.is_voided = 0 AND t.txn_type = 'invoice'"));
$legacyAr = r2((float)DB::value("SELECT COALESCE(SUM(Debit - Credit), 0) FROM legacy_journalentries WHERE Status = 'A' AND AccountID = 11784 AND DATE(TransactionDate) <= :d", ['d' => $cutover]));
$check('open invoices posted (count)', 3, (int)DB::value("SELECT COUNT(*) FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE w.status = 'posted' AND t.txn_type = 'invoice'"));
$check('SUM(open invoices) = legacy A/R balance (11784)', $legacyAr, $openInvoices);
$check('SUM(open invoices) = expected 700.00', 700.0, $openInvoices);
$check('every open invoice credits the clearing account', 0, (int)DB::value("SELECT COUNT(*) FROM water_postings w JOIN journal_lines j ON j.txn_id = w.txn_id WHERE w.txn_type = 'invoice' AND j.credit > 0 AND j.account_id <> :c", ['c' => $clearing]));
$check('every open invoice debits A/R 11784', 0, (int)DB::value("SELECT COUNT(*) FROM water_postings w JOIN journal_lines j ON j.txn_id = w.txn_id WHERE w.txn_type = 'invoice' AND j.debit > 0 AND j.account_id <> :a", ['a' => $ar]));

$ob = DB::row("SELECT w.txn_id FROM water_postings w WHERE w.event_key = 'LEGACY-OB'");
$check('opening balance journal exists', 'yes', $ob ? 'yes' : 'no');
if ($ob) {
    $s = DB::row('SELECT COALESCE(SUM(debit), 0) AS dr, COALESCE(SUM(credit), 0) AS cr FROM journal_lines WHERE txn_id = :t', ['t' => $ob['txn_id']]);
    $check('opening journal: debits = credits', r2((float)$s['dr']), r2((float)$s['cr']));
    $check('opening journal: total (debits) = 1,320.00', 1320.0, r2((float)$s['dr']));
    $check('opening journal: no A/R line (legacy A/R fully explained by open bills)', 0, (int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE txn_id = :t AND account_id = :a', ['t' => $ob['txn_id'], 'a' => $ar]));
    $check('opening journal: Dr cash 11118 = 620.00', 620.0, r2((float)DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE txn_id = :t AND account_id = :a', ['t' => $ob['txn_id'], 'a' => $cash])));
    $check('opening journal: Cr income 40210 = 1,320.00', -1320.0, r2((float)DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE txn_id = :t AND account_id = :a', ['t' => $ob['txn_id'], 'a' => $income])));
    $check('opening journal: Dr clearing = 700.00', 700.0, r2((float)DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE txn_id = :t AND account_id = :a', ['t' => $ob['txn_id'], 'a' => $clearing])));
    $check('opening journal dated at the cutover', $cutover, (string)DB::value('SELECT txn_date FROM transactions WHERE id = :t', ['t' => $ob['txn_id']]));
}
$gl = fn(int $acct) => r2((float)DB::value('SELECT COALESCE(SUM(j.debit - j.credit), 0) FROM journal_lines j JOIN transactions t ON t.id = j.txn_id WHERE j.account_id = :a AND t.is_voided = 0', ['a' => $acct]));
$check('GL A/R 11784 = legacy A/R', $legacyAr, $gl($ar));
$check('GL cash 11118 = legacy 620.00', 620.0, $gl($cash));
$check('GL income 40210 = legacy -1,320.00', -1320.0, $gl($income));
$check('clearing account nets to 0.00', 0.0, $gl($clearing));
$check('voided legacy voucher (Status V) ignored: GL A/R unaffected by 999.00', $legacyAr, $gl($ar));

// per account: ledger balance = water open balance
foreach ([114842 => 350.0, 114843 => 350.0, 114844 => 0.0] as $acct => $expected) {
    $water = WaterAccounts::openBalance($acct);
    $ledger = WaterAccounts::ledgerBalance($acct);
    $check("account $acct: water open balance = expected", $expected, (float)$water['total']);
    $check("account $acct: WaterAccounts::ledgerBalance = water open balance", (float)$water['total'], $ledger);
}
$check('customers made only for accounts with open bills', 2, (int)DB::value('SELECT COUNT(*) FROM water_accounts WHERE customer_id IS NOT NULL'));
$check('A/R subledger (customer balances) = GL A/R', $gl($ar), r2((float)DB::value("SELECT COALESCE(SUM(t.total), 0) - COALESCE((SELECT SUM(pa.amount) FROM payment_applications pa JOIN transactions p ON p.id = pa.payment_txn_id WHERE p.is_voided = 0), 0) FROM transactions t WHERE t.txn_type IN ('invoice', 'finance_charge') AND t.is_voided = 0 AND t.account_id = :a", ['a' => $ar])));
$check('history rows: payment_transactions copied', 2, (int)DB::value('SELECT COUNT(*) FROM payment_transactions'));
$check('history rows: legacy_journalentries copied', (int)$fx['j'], (int)DB::value('SELECT COUNT(*) FROM legacy_journalentries'));
$check('history rows: cashier_id remapped to the migrated user', (int)DB::value("SELECT user_id FROM water_user_map WHERE legacy_operator_id = 2"), (int)DB::value('SELECT cashier_id FROM payment_transactions WHERE transaction_id = 9001'));
$check('verify phase logged as done', 'done', (string)DB::value("SELECT status FROM water_migration_log WHERE phase = 'verify' ORDER BY id DESC LIMIT 1"));

// a second run must change nothing
out('Second run (must write nothing) ...');
exec($cmd . ' 2>&1', $out2, $rc2);
$check('second run exit code', 0, $rc2);
$check('second run: history phase wrote 0 rows', 0, (int)DB::value("SELECT rows_written FROM water_migration_log WHERE phase = 'history' ORDER BY id DESC LIMIT 1"));
$check('second run: still 3 open invoices and 1 opening journal', 4, (int)DB::value("SELECT COUNT(*) FROM water_postings"));
$check('second run: GL A/R unchanged', $legacyAr, $gl($ar));

out('');
out("PHASE 7 FIXTURE CHECKS  ($source -> $target, cutover $cutover)");
out(wm_table(['Check', 'Expected', 'Actual', 'Result'], $rows));
out('Result: ' . ($allOk ? 'PASS' : 'FAIL') . sprintf('  (%.1fs)', microtime(true) - $t0));
if (!isset($opts['keep-source'])) {
    $server->exec("DROP DATABASE IF EXISTS `$source`");
    out("Dropped `$source` (use --keep-source to keep it); `$target` is kept for inspection.");
}
exit($allOk ? 0 : 1);
