<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v12.xlsx (version 41) at the level of the program's rules:
 *  - the member search of Bill Readings (individual) and of the Statement of Account;
 *  - Ladder Rate Billing beside Straight Rate Billing on Rate Codes (the workbook's example, with the discounts);
 *  - the official receipt amounts, changed only by a user allowed to;
 *  - the Collection Summary over a period of dates and times;
 *  - the migration 2027_03_07.
 * The pages (several charges on one form, the bill issued again with a new category or date, the receipt fields
 * read-only, the date and time fields) are checked over HTTP (tests/water/e2e_http.php) and in the browser
 * (docs/TEST_EVIDENCE.md).
 */
require_once APP_ROOT . '/water/common/readingHelpers.php';

/** The rate tiers of a rate code and pipe size, as the bills read them */
function v41Rates(int $rc, int $pipe = 2): array
{
    return water_rate_validate_tiers(water_rate_get_rates_for_configuration(DB::pdo(), $rc, $pipe), true, false);
}

test('Ladder Rate Billing: the workbook\'s 250 m3 on RESIDENTIAL ASSOCIATE adds up each tier (13,230.50); Straight Rate Billing keeps 250 x 55.40 (13,850.00)', function () {
    $rates = v41Rates(2);
    eq(array_map(fn($r) => [(int)$r['from_cb'], $r['to_cb'] === null ? null : (int)$r['to_cb'], (string)(float)$r['amount'], $r['bill_type']], $rates),
        [[0, 5, '145.5', 'FIXED'], [6, 10, '31.8', 'VARIABLE'], [11, 20, '34.3', 'VARIABLE'], [21, 30, '44.8', 'VARIABLE'], [31, 40, '50.1', 'VARIABLE'], [41, null, '55.4', 'VARIABLE']],
        'the tiers of the workbook');
    $ladder = water_rate_compute_from_configuration($rates, 250, null, 'ladder');
    // 145.50 + 5 x 31.80 + 10 x 34.30 + 10 x 44.80 + 10 x 50.10 + 210 x 55.40
    eq([$ladder['base_charge'], $ladder['amount_due'], $ladder['billing_method'], $ladder['billing_method_label'], $ladder['pricing_method']],
        [13230.5, 13230.5, 'ladder', 'Ladder Rate Billing', 'ladder_tiers_added_up'], 'the ladder');
    eq(array_map(fn($l) => [(int)$l['tier_consumption'], $l['gross_amount']], $ladder['gross_lines']),
        [[5, 145.5], [5, 159.0], [10, 343.0], [10, 448.0], [10, 501.0], [210, 11634.0]], 'one line per tier, its own cubic meters');
    $straight = water_rate_compute_from_configuration($rates, 250, null, 'straight');
    eq([$straight['base_charge'], $straight['billing_method'], $straight['billing_method_label']], [13850.0, 'straight', 'Straight Rate Billing'], 'the straight rate: all of it at 55.40');
    eq(water_rate_compute_from_configuration($rates, 250)['base_charge'], 13850.0, 'a rate code without a computation set is billed straight, as before');
});

test('Ladder Rate Billing at the tier boundaries: the minimum charge, the first cubic meter of a tier, the last one', function () {
    $rates = v41Rates(2);
    $both = fn(int $m3) => [water_rate_compute_from_configuration($rates, $m3, null, 'ladder')['base_charge'], water_rate_compute_from_configuration($rates, $m3, null, 'straight')['base_charge']];
    eq($both(0), [145.5, 145.5], '0 m3: the minimum charge on both');
    eq($both(5), [145.5, 145.5], '5 m3: still the minimum charge');
    eq($both(6), [177.3, 190.8], '6 m3: 145.50 + 31.80 on the ladder, 6 x 31.80 straight');
    eq($both(10), [304.5, 318.0], '10 m3: 145.50 + 5 x 31.80 / 10 x 31.80');
    eq($both(11), [338.8, 377.3], '11 m3: one cubic meter of the third tier / 11 x 34.30');
    eq($both(30), [1095.5, 1344.0], '30 m3: 145.50 + 159.00 + 343.00 + 448.00 / 30 x 44.80');
    eq($both(41), [1651.9, 2271.4], '41 m3: the open last tier starts (one cubic meter at 55.40) / 41 x 55.40');
    eq([water_rate_billing_method('LADDER '), water_rate_billing_method('straight'), water_rate_billing_method('tiered'), water_rate_billing_method(null)],
        ['ladder', 'straight', 'straight', 'straight'], 'anything but ladder is read as Straight Rate Billing');
});

test('Ladder Rate Billing with the member discounts: the free cubic meters come off the top of the ladder, a percentage after them', function () {
    $rates = v41Rates(2);
    $free = ['rules' => [['discount_method' => 'free_cubic', 'discount_value' => 10, 'discount_type' => 'Senior (test)']]];
    $l = water_rate_compute_from_configuration($rates, 30, $free, 'ladder');
    // 30 m3 on the ladder 1,095.50; the 20 m3 billed after the 10 free: 145.50 + 159.00 + 343.00 = 647.50
    eq([$l['base_charge'], $l['free_cubic_discount_amount'], $l['amount_due'], $l['billing_method']], [1095.5, 448.0, 647.5, 'ladder'], 'ladder: 10 free m3');
    $s = water_rate_compute_from_configuration($rates, 30, $free, 'straight');
    eq([$s['base_charge'], $s['free_cubic_discount_amount'], $s['amount_due'], $s['billing_method']], [1344.0, 658.0, 686.0, 'straight'], 'straight: 30 x 44.80 less 20 x 34.30');
    $pct = ['rules' => [['discount_method' => 'percent', 'discount_value' => 20, 'max_discount_amount' => 500, 'discount_type' => 'PWD (test)']]];
    $p = water_rate_compute_from_configuration($rates, 30, $pct, 'ladder');
    eq([$p['monetary_discount_amount'], $p['amount_due'], $p['billing_method_label']], [219.1, 876.4, 'Ladder Rate Billing'], 'ladder: 20% of 1,095.50, the method kept (a discount rule has its own method)');
    $both = ['rules' => [$free['rules'][0], $pct['rules'][0]]];
    $b = water_rate_compute_from_configuration($rates, 30, $both, 'ladder');
    eq([$b['free_cubic_discount_amount'], $b['monetary_discount_amount'], $b['amount_due']], [448.0, 129.5, 518.0], 'ladder: the free cubic meters, then 20% of the 647.50 left');
    eq(water_rate_compute_from_configuration($rates, 45, $pct, 'ladder')['monetary_discount_amount'], 0.0, 'above 30 m3 no percentage discount, on the ladder as on the straight rate');
});

test('Ladder Rate Billing set on a rate code: the bills of its accounts follow it (and go back when it is set back)', function () {
    $acct = wt_account(941101, 'Ladderton', 'Rosa', null, 2);
    DB::run("UPDATE rate_code SET billing_method = 'ladder' WHERE rc_id = 2");
    $d = compute_water_billing_details(DB::pdo(), $acct, 250, true);
    eq([$d['base_charge'], $d['billing_method'], $d['rate_code']['billing_method']], [13230.5, 'ladder', 'ladder'], 'the account of a ladder rate code');
    eq(compute_water_billing_details(DB::pdo(), wt_account(941102, 'Straightman', 'Jose', null, 1), 250, true)['billing_method'], 'straight', 'another rate code keeps its own computation');
    DB::run("UPDATE rate_code SET billing_method = 'straight' WHERE rc_id = 2");
    eq(compute_water_billing_details(DB::pdo(), $acct, 250, true)['base_charge'], 13850.0, 'set back to Straight Rate Billing');
});

test('Bill Readings and the Statement of Account: the member search answers (each word in the name, the account number or the address)', function () {
    $pdo = DB::pdo();
    wt_account(941103, 'Villanueva', 'Corazon', null);
    $keys = fn(string $q, int $limit = 12) => array_map('intval', array_column(searchMembers($pdo, $q, $limit), 'pkey'));
    check(in_array(941103, $keys('corazon villanueva'), true), 'first name then last name');
    check(in_array(941103, $keys('Villanueva, Cor'), true), '"last name, first name" as the list shows it');
    check(in_array(941103, $keys('W941103'), true), 'the account number');
    check(in_array(941103, $keys('941103'), true), 'the account ID');
    eq($keys('corazon zzqx'), [], 'every word must be found');
    eq($keys('villa%'), [], 'a % is a character, not a wildcard');
    check(count($keys('a', 1)) <= 1, 'the number of results is limited (the limit no longer breaks the search)');
    $row = searchMembers($pdo, 'corazon villanueva')[0];
    eq([$row['account_number'], trim((string)$row['full_name'])], ['W941103', 'Villanueva, Corazon'], 'the account number comes with the name');
});

test('Collection Summary over a period of dates and times: From and To carry a time; with times a receipt counts at the moment it was recorded, whole days by business date', function () {
    $saved = $_GET;
    $_GET = ['from' => '2026-10-12T08:30', 'to' => '2026-10-12 17:45:10'];
    eq([Report::dateTimeParam('from', '2026-10-01', '00:00'), Report::dateTimeParam('to', '2026-10-31', '23:59')], [['2026-10-12', '08:30'], ['2026-10-12', '17:45']], 'a date and time');
    $_GET = ['from' => '2026-10-12', 'to' => '2026-13-01T08:00', 'x' => '2026-10-12T25:00'];
    eq([Report::dateTimeParam('from', '2026-10-01', '00:00'), Report::dateTimeParam('to', '2026-10-31', '23:59'), Report::dateTimeParam('x', '2026-10-31', '23:59')],
        [['2026-10-12', '00:00'], ['2026-10-31', '23:59'], ['2026-10-31', '23:59']], 'a date alone starts or ends the day; an impossible one is not taken');
    $def = Report::get('water_collection_summary');
    check(!empty($def['range_time']), 'the Collection Summary is a period of dates and times');
    $r = DB::row("SELECT receipt_no, payment_date AS at, COALESCE(business_date, DATE(payment_date)) AS bday FROM payment_transactions WHERE transaction_status = 'active' ORDER BY transaction_id LIMIT 1");
    check($r !== null, 'a receipt of the water cases');
    $at = strtotime((string)$r['at']);
    $run = function (string $from, string $to, array $more = []) use ($def) {
        $_GET = ['dates' => 'custom', 'from' => $from, 'to' => $to, 'view' => 'receipts', 'pstatus' => 'active'] + $more;
        $f = Report::filters($def);
        return [$f, array_column(($def['run'])($f)['rows'], 'receipt')];
    };
    [$f, $rows] = $run(date('Y-m-d\TH:i', $at), date('Y-m-d\TH:i', $at));
    eq([$f['from_dt'], $f['to_dt'], $f['from'], $f['to']], [date('Y-m-d H:i:00', $at), date('Y-m-d H:i:59', $at), date('Y-m-d', $at), date('Y-m-d', $at)], 'the moments of the period');
    check(in_array($r['receipt_no'], $rows, true), 'the receipt in the minute it was recorded');
    check(!in_array($r['receipt_no'], $run(date('Y-m-d\TH:i', $at + 60), date('Y-m-d', $at + 86400) . 'T23:58')[1], true), 'not in a period starting a minute later');
    check(!in_array($r['receipt_no'], $run(date('Y-m-d', $at - 86400) . 'T00:01', date('Y-m-d\TH:i', $at - 60))[1], true), 'not in a period ending a minute before');
    check(in_array($r['receipt_no'], $run($r['bday'], $r['bday'])[1], true), 'whole days count by business date (as before)');
    // a receipt recorded another day than its business date (back-dated): whole days by its business date, times by the moment recorded
    $pt = DB::row('SELECT * FROM payment_transactions WHERE receipt_no = :r', ['r' => $r['receipt_no']]);
    DB::run('UPDATE payment_transactions SET business_date = :d WHERE receipt_no = :r', ['d' => date('Y-m-d', $at - 5 * 86400), 'r' => $r['receipt_no']]);
    check(in_array($r['receipt_no'], $run(date('Y-m-d', $at - 5 * 86400), date('Y-m-d', $at - 5 * 86400))[1], true), 'back-dated: on its business day');
    check(!in_array($r['receipt_no'], $run(date('Y-m-d', $at - 5 * 86400) . 'T00:00', date('Y-m-d', $at - 5 * 86400) . 'T23:00')[1], true), 'back-dated: not at a time of its business day it was never recorded at');
    check(in_array($r['receipt_no'], $run(date('Y-m-d\TH:i', $at - 60), date('Y-m-d\TH:i', $at + 60))[1], true), 'back-dated: at the moment it was recorded');
    check(in_array($r['receipt_no'], $run(date('Y-m-d', $at), date('Y-m-d', $at), ['rec' => '1'])[1], true), 'a line of a times-period opened for a whole day keeps the moment recorded');
    DB::run('UPDATE payment_transactions SET business_date = :d WHERE receipt_no = :r', ['d' => $pt['business_date'], 'r' => $r['receipt_no']]);
    $_GET = ['dates' => 'custom', 'from' => '2026-10-12T08:00', 'to' => '2026-10-12T17:00'];
    eq(Report::basis($def, Report::filters($def)), '10/12/2026 08:00 AM through 10/12/2026 05:00 PM', 'the period printed with its times');
    $_GET = ['dates' => 'custom', 'from' => '2026-10-12', 'to' => '2026-10-13'];
    eq(Report::basis($def, Report::filters($def)), '10/12/2026 through 10/13/2026', 'whole days printed as dates');
    $_GET = $saved;
});

test('Official receipt: the paid bill-code amounts are changed only by a user whose role has the permission (administrators always)', function () {
    global $ids;
    eq(Access::SPECIAL['water_receipt_amounts'][1] ?? null, 'bool', 'a yes/no special permission of the roles');
    check(Access::special('water_receipt_amounts', (int)$GLOBALS['adminId']) === true, 'the administrator');
    $teller = (int)$ids['user.teller1'];
    check(!Access::special('water_receipt_amounts', $teller), 'a teller without it');
    $role = (int)DB::value('SELECT role_id FROM user_roles WHERE user_id = :u LIMIT 1', ['u' => $teller]);
    DB::run("INSERT INTO role_special_permissions (role_id, perm_key, perm_value) VALUES (:r, 'water_receipt_amounts', '1')", ['r' => $role]);
    Access::reset();
    check(Access::special('water_receipt_amounts', $teller) === true, 'the teller once the role has it');
    DB::run("DELETE FROM role_special_permissions WHERE role_id = :r AND perm_key = 'water_receipt_amounts'", ['r' => $role]);
    Access::reset();
});

test('Migration 2027_03_07: the computation of the rate codes, Straight Rate Billing for those on file, safe to run again', function () {
    $col = DB::row("SELECT COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'rate_code' AND column_name = 'billing_method'");
    eq([$col['COLUMN_TYPE'] ?? null, trim((string)($col['COLUMN_DEFAULT'] ?? ''), "'"), $col['IS_NULLABLE'] ?? null], ['varchar(10)', 'straight', 'NO'], 'rate_code.billing_method');
    eq((int)DB::value("SELECT COUNT(*) FROM rate_code WHERE billing_method <> 'straight'"), 0, 'every rate code on file is Straight Rate Billing');
    DB::run("UPDATE rate_code SET billing_method = 'ladder' WHERE rc_id = 5");
    DB::run("UPDATE rate_code SET billing_method = 'tiers' WHERE rc_id = 6");
    run_sql_file(DB::pdo(), APP_ROOT . '/database/migrations/2027_03_07_water_ladder_billing.sql');
    eq(DB::pairs('SELECT rc_id, billing_method FROM rate_code WHERE rc_id IN (5, 6) ORDER BY rc_id'), [5 => 'ladder', 6 => 'straight'], 'run again: a ladder kept, a value of neither method read as straight');
    DB::run("UPDATE rate_code SET billing_method = 'straight' WHERE rc_id = 5");
    check(in_array('database/migrations/2027_03_07_water_ladder_billing.sql', Schema::files(), true), 'the schema check knows the migration');
});
