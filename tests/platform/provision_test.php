<?php
declare(strict_types=1);

/**
 * Setting up a new company on the platform (Provision), end to end, on a template at the program's version.
 *
 * Run it through tests/platform/run.sh, which makes a throw-away copy of the program (the platform's switch file is
 * written under storage/platform of the copy) and a template database from database/accounting_system_clean.sql.
 * Run directly, it must be given the copy:  DB_NAME=<template db> php provision_test.php <program copy>
 */
if (PHP_SAPI !== 'cli' || !isset($argv[1]) || !is_file($argv[1] . '/app/bootstrap.php')) {
    exit("Run tests/platform/run.sh\n");
}
$_SESSION = [];
require $argv[1] . '/app/bootstrap.php';

$pass = 0;
$fail = 0;
function check(bool $ok, string $what): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $what . "\n";
}

$tpl = Sql::to(DB::pdo()->query('SELECT DATABASE()')->fetchColumn());
check(Schema::pendingOn($tpl) === [], 'the template is at the program\'s version');

// the template describes itself: none of this may reach the new company
$tpl->run("UPDATE settings SET setting_value = 'TEMPLATE MULTI-PURPOSE COOPERATIVE' WHERE setting_key = 'coop_registered_name'");
$tpl->run("UPDATE settings SET setting_value = 'Template General Manager' WHERE setting_key = 'coop_signatory_gm'");
$tpl->run("UPDATE settings SET setting_value = 'CDA-TEMPLATE-001' WHERE setting_key = 'coop_cda_reg_no'");

$admin = (int)DB::value("SELECT id FROM users WHERE username = 'admin'");
Auth::actAs($admin);
$p = Provision::setupPrimary(['username' => 'superadmin', 'full_name' => 'Platform Admin', 'email' => '', 'password' => 'Super#Admin2026x'],
    ['name' => 'TEMPLATE COOPERATIVE', 'address' => '', 'contact_person' => '', 'phone' => '', 'email' => '', 'tin' => ''], $admin, false);
$saId = (int)Master::sql()->value("SELECT id FROM super_admins WHERE username = 'superadmin'");
$owner = ['username' => 'testowner', 'full_name' => 'Test Owner', 'email' => '', 'password' => 'Owner#Pass2026x'];

echo "New company\n";
$id = Provision::create(['name' => 'TEST COMPANY', 'address' => 'Purok Caimito, Cogon Road', 'contact_person' => '', 'phone' => '0917 000 0000',
    'email' => 'test@example.test', 'tin' => '315-619-693-0001', 'notes' => '', 'require_approval' => false], $owner, (int)$p['company_id'], $saId);
$c = Master::company($id, true);
check($c['status'] === 'active', 'TEST COMPANY is active (' . $c['status'] . ($c['last_error'] ? ': ' . $c['last_error'] : '') . ')');
$steps = Master::sql()->pairs('SELECT step, ok FROM company_provisioning_log WHERE company_id = :c', ['c' => $id]);
foreach (['copy_structure', 'copy_setup', 'triggers', 'views', 'owner_account', 'verify', 'register', 'activate'] as $s) {
    check((int)($steps[$s] ?? 0) === 1, "step $s");
}
$new = Sql::to((string)$c['db_name']);
check(Schema::pendingOn($new) === [], 'the new database matches the program (no upgrade script pending)');
check(WaterSchema::missingViews($new->pdo) === [], 'the water views chartofaccntstbl and operator are there');
check(Audit::triggersInPlace($new), 'the audit trail protection is in place');

// every foreign key of the new database points at a row that is there
$broken = [];
foreach ($new->all("SELECT table_name AS t, column_name AS c, referenced_table_name AS rt, referenced_column_name AS rc
                      FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL") as $fk) {
    $n = (int)$new->value("SELECT COUNT(*) FROM `{$fk['t']}` x LEFT JOIN `{$fk['rt']}` y ON y.`{$fk['rc']}` = x.`{$fk['c']}`
                            WHERE x.`{$fk['c']}` IS NOT NULL AND y.`{$fk['rc']}` IS NULL");
    if ($n) {
        $broken[] = "{$fk['t']}.{$fk['c']} ($n)";
    }
}
check($broken === [], 'every foreign key resolves' . ($broken ? ': ' . implode(', ', $broken) : ''));
// and the links the program keeps without a foreign key
$links = [
    'accounts.type_id -> account_types' => 'SELECT COUNT(*) FROM accounts a LEFT JOIN account_types t ON t.id = a.type_id WHERE a.type_id IS NOT NULL AND t.id IS NULL',
    'sav_txn_codes.opposite_account_id -> accounts' => 'SELECT COUNT(*) FROM sav_txn_codes s LEFT JOIN accounts a ON a.id = s.opposite_account_id WHERE s.opposite_account_id IS NOT NULL AND a.id IS NULL',
    'bill_codes accounts' => 'SELECT COUNT(*) FROM bill_codes b LEFT JOIN accounts a ON a.id = b.ar_account LEFT JOIN accounts i ON i.id = b.income_account
                              WHERE (b.ar_account IS NOT NULL AND a.id IS NULL) OR (b.income_account IS NOT NULL AND i.id IS NULL)',
    'water billing settings -> accounts' => "SELECT COUNT(*) FROM water_billing_settings w LEFT JOIN accounts a ON a.id = w.setting_value
                                             WHERE w.setting_key LIKE '%account_id' AND w.setting_value <> '' AND a.id IS NULL",
    'water billing settings -> the Water Bill code' => "SELECT COUNT(*) FROM water_billing_settings w LEFT JOIN bill_codes b ON b.code_id = w.setting_value
                                                       WHERE w.setting_key = 'water_billing_bill_code_id' AND b.code_id IS NULL",
    'water rates -> rate codes and bill codes' => 'SELECT COUNT(*) FROM water_meter_members m LEFT JOIN rate_code r ON r.rc_id = m.rc_id LEFT JOIN bill_codes b ON b.code_id = m.bill_code_id
                                                   WHERE r.rc_id IS NULL OR (m.bill_code_id IS NOT NULL AND b.code_id IS NULL)',
    'discount privileges -> discounts and bill codes' => 'SELECT COUNT(*) FROM discount_privilege_rules d LEFT JOIN discounts x ON x.discount_id = d.discount_id
                                                          LEFT JOIN bill_codes b ON b.code_id = d.bill_code_id WHERE x.discount_id IS NULL OR (d.bill_code_id IS NOT NULL AND b.code_id IS NULL)',
    'bill codes linked to an item (items are not copied)' => 'SELECT COUNT(*) FROM bill_codes WHERE item_id IS NOT NULL',
    'business units with a usual site (sites are not copied)' => 'SELECT COUNT(*) FROM business_units WHERE default_site_id IS NOT NULL',
];
foreach ($links as $what => $sql) {
    check((int)$new->value($sql) === 0, $what . ': none broken');
}

// the set-up lists came along, as the template has them
foreach (array_keys(Provision::COPY) as $t) {
    $want = (int)$tpl->value("SELECT COUNT(*) FROM `$t`");
    $got = (int)$new->value("SELECT COUNT(*) FROM `$t`");
    if ($want !== $got) {
        check(false, "$t: $got rows copied of $want");
    }
}
check(true, count(Provision::COPY) . ' set-up lists copied with all their rows');
check((int)$new->value("SELECT COUNT(*) FROM business_units WHERE code = 'WU'") === 1, 'the Water Utility business unit is there');
check((int)$new->value('SELECT COUNT(*) FROM account_types WHERE is_system = 1') > 0, 'the account types are there');
check(array_sum(array_map('count', Provision::copyGroups())) === count(Provision::COPY), 'the New company page shows every copied list once');

// the new company describes itself
$s = $new->pairs('SELECT setting_key, setting_value FROM settings');
check(($s['company_name'] ?? '') === 'TEST COMPANY' && ($s['company_tin'] ?? '') === '315-619-693-0001', 'company name and TIN are the new company\'s');
check(($s['coop_registered_name'] ?? '') === '' && ($s['coop_signatory_gm'] ?? '') === '' && ($s['coop_cda_reg_no'] ?? '') === '',
    'the template\'s registered name, General Manager and CDA number are not copied');
$bp = $new->row('SELECT name, address1, telephoneNo, tinNo, email FROM bussinessprofile');
check($bp && $bp['name'] === 'TEST COMPANY' && $bp['tinNo'] === '315-619-693-0001' && $bp['address1'] === 'Purok Caimito, Cogon Road',
    'the water institution profile is the new company');

// nothing beyond the set-up lists
foreach (['customers', 'vendors', 'items', 'water_accounts', 'members', 'loan_products', 'sav_products', 'classes', 'transactions', 'journal_lines'] as $t) {
    if ((int)$new->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t', ['t' => $t])) {
        check((int)$new->value("SELECT COUNT(*) FROM `$t`") === 0, "$t: empty");
    }
}
check((int)$new->value('SELECT COUNT(*) FROM users') === 1, 'the owner is the only user');

echo "Set it up again (a company whose set-up failed)\n";
$m = Master::sql();
$failedId = $m->insert('companies', ['name' => 'FAILED BEFORE', 'status' => 'failed', 'template_company_id' => (int)$p['company_id'],
    'created_by' => $saId, 'last_error' => 'Step verify: an earlier version could not finish']);
$m->insert('company_codes', ['company_id' => $failedId, 'code' => CompanyCode::generate('FAILED BEFORE'), 'status' => 'active', 'created_by' => 'test']);
Provision::retry($failedId, ['username' => 'secondowner', 'full_name' => 'Second Owner', 'email' => '', 'password' => 'Owner#Pass2026x']);
$f = Master::company($failedId, true);
check($f['status'] === 'active', 'set up again: active (' . $f['status'] . ')');
check(Schema::pendingOn(Sql::to((string)$f['db_name'])) === [], 'its database matches the program');

echo "A template that needs a program update\n";
$tpl->exec('DROP VIEW operator');
$refused = '';
try {
    Provision::create(['name' => 'OTHER COMPANY', 'address' => '', 'contact_person' => '', 'phone' => '', 'email' => '', 'tin' => '', 'notes' => '', 'require_approval' => false],
        null, (int)$p['company_id'], $saId);
} catch (Throwable $e) {
    $refused = $e->getMessage();
}
check(str_contains($refused, 'needs a database update first') && str_contains($refused, '2026_12_01_water_billing.sql'), 'refused: ' . $refused);
check((int)$m->value("SELECT COUNT(*) FROM companies WHERE name = 'OTHER COMPANY'") === 0, 'and nothing was made');
check(WaterSchema::ensureViews($tpl->pdo) === '', 'the template\'s views made again');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
