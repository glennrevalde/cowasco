<?php
declare(strict_types=1);

/**
 * Automated tests of the cooperative module, run against a real MySQL / MariaDB database.
 *
 *   php tests/coop/run.php                 builds a fresh test database (coop_test), runs every test
 *   php tests/coop/run.php --keep          keeps the test database afterwards (to look at it in phpMyAdmin)
 *   php tests/coop/run.php --filter=loan   runs only the tests whose name contains "loan"
 *   DB_NAME=other php tests/coop/run.php   uses another database name (it is DROPPED and recreated)
 *
 * The results are printed and written to tests/coop/RESULTS.md. Expected figures are worked out in the tests
 * themselves with bcmath, independently of the Dec class the application uses, or taken from the reference
 * workbook (ADDITIONAL_MODULE_FOR_COOP_SYSTEMS_v1.xlsx).
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$opts = getopt('', ['keep', 'filter:', 'pure-php']);
if (!getenv('DB_NAME')) {
    putenv('DB_NAME=coop_test');
}
require __DIR__ . '/../../app/bootstrap.php';
require APP_ROOT . '/tools/lib.php';
require APP_ROOT . '/tools/seed.php';
require APP_ROOT . '/tools/coop_fixtures.php';
require APP_ROOT . '/views/layout.php';

if (isset($opts['pure-php'])) {
    Dec::$forcePhp = true;
}
$_SESSION = [];
$dbName = $GLOBALS['CONFIG']['db']['name'];
if (in_array($dbName, ['accounting_system'], true)) {
    fwrite(STDERR, "Refusing to run the tests on the database `$dbName`. Set DB_NAME to a throw-away database.\n");
    exit(1);
}

// ---------------------------------------------------------------------------------------- the test database
$started = microtime(true);
$server = server_pdo();
$server->exec("DROP DATABASE IF EXISTS `$dbName`");
$server->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = DB::pdo();
run_sql_file($pdo, APP_ROOT . '/database/schema.sql');
run_sql_file($pdo, APP_ROOT . '/database/triggers.sql');
DB::tx(fn() => seed_master_data());
run_sql_file($pdo, APP_ROOT . '/database/migrations/2026_11_01_cooperative.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2026_11_15_multipurpose_party.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2026_12_01_water_billing.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2026_12_20_member_lists_class_je_changes.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2026_12_27_member_defaults_savings_center.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_01_03_loan_collection_error_correct.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_01_10_teller_menu_loan_release_vouchers.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_01_17_passbook_designer.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_01_24_account_types.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_01_31_adjustment_passbook.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_02_21_menus_imports_codes.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_03_07_water_ladder_billing.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_03_14_share_capital_billing.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_03_21_share_capital_terms_batch.sql');
run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_03_28_regular_joint_subgroups_receipts.sql');
Settings::flush();
Accts::reset();
$adminId = (int)DB::value("SELECT id FROM users WHERE username = 'admin'");
Auth::actAs($adminId);
$ids = DB::tx(fn() => coop_fx_setup());
// the accounts of the water cases (tests/coop/cases_water.php), made before any report reads the chart (a report keeps it for the request)
foreach ([['1170', 'Accounts Receivable - Water', 'Accounts Receivable', 'Asset', 'Debit'], ['4170', 'Water Sales', 'Income', 'Income', 'Credit'],
          ['4171', 'Water Penalties and Surcharges', 'Income', 'Income', 'Credit']] as [$c, $n, $t, $cat, $nb]) {
    coop_fx_new_account($c, $n, $t, $cat, $nb);
}
$setupSeconds = round(microtime(true) - $started, 1);

// ---------------------------------------------------------------------------------------- tiny test harness
$results = [];
$current = '';
$filter = $opts['filter'] ?? '';

function test(string $name, callable $fn): void
{
    global $results, $current, $filter;
    if ($filter !== '' && stripos($name, $filter) === false) {
        return;
    }
    $current = $name;
    $t0 = microtime(true);
    $checks = $GLOBALS['CHECKS'] = 0;
    try {
        $fn();
        $results[] = ['name' => $name, 'ok' => true, 'checks' => $GLOBALS['CHECKS'], 'ms' => (int)((microtime(true) - $t0) * 1000), 'msg' => ''];
        echo "  PASS  $name ({$GLOBALS['CHECKS']} checks)\n";
    } catch (Throwable $e) {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        $where = $e instanceof AssertionFailed ? '' : ' [' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
        $results[] = ['name' => $name, 'ok' => false, 'checks' => $GLOBALS['CHECKS'], 'ms' => (int)((microtime(true) - $t0) * 1000), 'msg' => $e->getMessage() . $where];
        echo "  FAIL  $name\n        " . $e->getMessage() . $where . "\n";
    }
    Auth::actAs($GLOBALS['adminId']);
    $_POST = [];
}

final class AssertionFailed extends RuntimeException
{
}

function check(bool $cond, string $what): void
{
    $GLOBALS['CHECKS']++;
    if (!$cond) {
        throw new AssertionFailed($what);
    }
}

/** money equality as exact decimal strings (2 places) */
function eqm($actual, $expected, string $what): void
{
    $a = bcadd((string)$actual, '0', 2);
    $e = bcadd((string)$expected, '0', 2);
    check($a === $e, "$what: expected $e, got $a");
}

function eq($actual, $expected, string $what): void
{
    check($actual === $expected, "$what: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
}

/** The callable must stop with a user error whose text contains $needle */
function refuses(callable $fn, string $needle, string $what): void
{
    try {
        $fn();
    } catch (UserError $e) {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        check(stripos($e->getMessage(), $needle) !== false, "$what: refused, but with \"" . $e->getMessage() . "\" instead of \"$needle\"");
        return;
    }
    check(false, "$what: was accepted, it should have been refused (\"$needle\")");
}

/** bcmath helpers for the expected values: round half away from zero to 2 places */
function r2s(string $v): string
{
    $neg = str_starts_with($v, '-');
    $abs = $neg ? substr($v, 1) : $v;
    $r = bcadd($abs, '0.005', 2);
    return $neg && bccomp($r, '0', 2) !== 0 ? '-' . $r : $r;
}

/** GL balance of an account (debit - credit) from the posted journal lines */
function gl(int $accountId, ?string $asOf = null): string
{
    return (string)DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a' . ($asOf ? ' AND txn_date <= :d' : ''), ['a' => $accountId] + ($asOf ? ['d' => $asOf] : []));
}

function glMember(int $accountId, int $memberId): string
{
    $on = (int)DB::value('SELECT other_name_id FROM members WHERE id = :m', ['m' => $memberId]);
    return (string)DB::value('SELECT COALESCE(SUM(debit - credit),0) FROM journal_lines WHERE account_id = :a AND other_name_id = :o', ['a' => $accountId, 'o' => $on]);
}

function acct(string $code): int
{
    return coop_fx_acct($code);
}

/** Every posted transaction balances (debits = credits), and the whole ledger does */
function assertLedgerBalanced(string $what): void
{
    $bad = DB::all('SELECT txn_id, SUM(debit) d, SUM(credit) c FROM journal_lines GROUP BY txn_id HAVING ROUND(SUM(debit),2) <> ROUND(SUM(credit),2)');
    check(!$bad, "$what: unbalanced transactions " . json_encode($bad));
    $t = DB::row('SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines');
    eqm($t['d'], $t['c'], "$what: trial balance debits = credits");
}

/** Subsidiary ledgers agree with the general ledger control accounts */
function assertSlGl(string $what): void
{
    foreach (DB::all("SELECT DISTINCT COALESCE(p.liability_account_id, 0) AS acct, p.product_type FROM sav_products p") as $r) {
        $acctId = (int)$r['acct'] ?: Savings::liabilityAccount(['liability_account_id' => null, 'product_type' => $r['product_type']]);
        $sl = (string)DB::value('SELECT COALESCE(SUM(a.balance),0) FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE COALESCE(p.liability_account_id, 0) = :a', ['a' => (int)$r['acct']]);
        $glBal = bcmul(gl($acctId), '-1', 2);
        eqm($sl, $glBal, "$what: savings SL = GL for account " . DB::value('SELECT code FROM accounts WHERE id = :a', ['a' => $acctId]));
    }
    $recv = acct('1210');
    $sl = (string)DB::value("SELECT COALESCE(SUM(principal_balance),0) FROM loan_accounts WHERE receivable_account_id = :a AND status IN ('current','past_due','restructured')", ['a' => $recv]);
    eqm($sl, gl($recv), "$what: loans SL principal = GL Loans Receivable");
    $irec = Accts::get('loan_interest_receivable');
    $sl = (string)DB::value("SELECT COALESCE(SUM(interest_accrued),0) FROM loan_accounts WHERE status IN ('current','past_due','restructured')");
    eqm($sl, gl($irec), "$what: accrued interest SL = GL Interest Receivable");
}

/** A fresh member with share capital, savings, both at the head office unless told otherwise */
function newMember(string $last, string $first, array $extra = []): int
{
    global $ids;
    return DB::tx(fn() => coop_fx_member($ids, $last, $first, $extra));
}

function openAccount(int $memberId, string $productKey, string $date = '2026-01-02'): int
{
    global $ids;
    return Savings::open($memberId, $ids['sav.' . $productKey], null, $date, null, 'sav.accounts');
}

/** Opens the teller shift of the current user (closing any left open) */
function shift(string $date = '2026-01-02', string $opening = '10000'): int
{
    $s = Teller::openShift();
    if ($s) {
        return (int)$s['id'];
    }
    return Teller::open($date, $opening, null, 'teller.shift');
}

/** Official receipt for one member */
function receipt(int $memberId, string $date, array $items, array $pay = ['mode' => 'cash'], array $opt = []): array
{
    return Teller::receipt($memberId, $date, $items, $pay, $opt, 'teller.or');
}

function asUser(string $key): void
{
    global $ids;
    Auth::actAs($ids['user.' . $key]);
}

echo "\nCooperative module tests on `$dbName` (" . DB::value('SELECT VERSION()') . ', PHP ' . PHP_VERSION . ', decimal engine ' . (Dec::$forcePhp || !function_exists('bcadd') ? 'pure PHP' : 'bcmath') . ")\n";
echo "Set-up: fresh database, schema, triggers, master data, cooperative and multipurpose migrations and fixtures in {$setupSeconds}s\n\n";

require __DIR__ . '/cases.php';
require __DIR__ . '/cases_more.php';
require __DIR__ . '/cases_party.php';
require __DIR__ . '/cases_water.php';
require __DIR__ . '/cases_v30.php';
require __DIR__ . '/cases_v31_members.php';
require __DIR__ . '/cases_v31_savings.php';
require __DIR__ . '/cases_v32_loans.php';
require __DIR__ . '/cases_v33.php';
require __DIR__ . '/cases_v34.php';
require __DIR__ . '/cases_v35.php';
require __DIR__ . '/cases_v36.php';
require __DIR__ . '/cases_v37.php';
require __DIR__ . '/cases_v38.php';
require __DIR__ . '/cases_v39.php';
require __DIR__ . '/cases_v40.php';
require __DIR__ . '/cases_v41.php';
require __DIR__ . '/cases_v42.php';
require __DIR__ . '/cases_v43.php';
require __DIR__ . '/cases_v44.php';
require __DIR__ . '/cases_v46.php';
require __DIR__ . '/cases_v47.php';
require __DIR__ . '/cases_v49.php';

// ---------------------------------------------------------------------------------------- summary
$pass = count(array_filter($results, fn($r) => $r['ok']));
$fail = count($results) - $pass;
$checks = array_sum(array_column($results, 'checks'));
$total = round(microtime(true) - $started, 1);
echo "\n$pass passed, $fail failed, $checks checks, {$total}s\n";

$md = "# Cooperative module: automated test results\n\n"
    . '- Run: ' . date('Y-m-d H:i:s') . ' (' . date_default_timezone_get() . ")\n"
    . '- Database: ' . DB::value('SELECT VERSION()') . " (test database `$dbName`, rebuilt from database/schema.sql + triggers + seed + the 2026_11_01, 2026_11_15, 2026_12_01 (water), 2026_12_20, 2026_12_27, 2027_01_03, 2027_01_10, 2027_01_17, 2027_01_24, 2027_01_31, 2027_02_21, 2027_03_07, 2027_03_14 and 2027_03_21 migrations)\n"
    . '- PHP ' . PHP_VERSION . ', decimal engine: ' . (Dec::$forcePhp || !function_exists('bcadd') ? 'pure PHP' : 'bcmath') . "\n"
    . "- Command: `php tests/coop/run.php" . (isset($opts['pure-php']) ? ' --pure-php' : '') . ($filter !== '' ? " --filter=$filter" : '') . "`\n"
    . "- Result: **$pass passed, $fail failed** ($checks individual checks, {$total}s)\n\n"
    . "| # | Test | Result | Checks | ms |\n|---|------|--------|--------|----|\n";
foreach ($results as $i => $r) {
    $md .= '| ' . ($i + 1) . ' | ' . str_replace('|', '/', $r['name']) . ' | ' . ($r['ok'] ? 'PASS' : 'FAIL: ' . str_replace(['|', "\n"], ['/', ' '], $r['msg'])) . ' | ' . $r['checks'] . ' | ' . $r['ms'] . " |\n";
}
if ($filter === '') {
    file_put_contents(__DIR__ . '/RESULTS' . (isset($opts['pure-php']) ? '_pure_php' : '') . '.md', $md);
}
if (!isset($opts['keep'])) {
    $server->exec("DROP DATABASE IF EXISTS `$dbName`");
}
exit($fail ? 1 : 0);
