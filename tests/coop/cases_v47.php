<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v17.xlsx (version 47) at the level of the program's rules:
 *  - Billing Adjustments > Edit opens the bill itself: the bill as the editor shows it (be_bill_document), the
 *    correction worked out before it is saved (be_preview) with the save's own functions, so the preview and the
 *    save agree; a due date before the bill date refused; the term (days) and the disconnection date follow a new
 *    due date;
 *  - the Individual Statement of Account: every unpaid invoice of the account whatever its billing period
 *    (isoa_open_invoices), dated on or before the statement date, with its status; a partly paid bill by its
 *    balance, a paid or deleted one left out; the search by name or account number, members or customers.
 * The pages (the bill editor, the tab of Generate SOA / Post Billing, the statement and its print) are checked over
 * HTTP (tests/water/e2e_http.php) and in the browser (docs/TEST_EVIDENCE.md).
 */

require_once APP_ROOT . '/water/common/billEditorHelpers.php';
require_once APP_ROOT . '/water/common/individualSoaHelpers.php';
require_once APP_ROOT . '/water/common/oneTimeBillImportHelpers.php';
require_once APP_ROOT . '/water/common/importReviewHelpers.php';

/** the error message of a refused call ('' when it was not refused) */
function v47Refused(callable $fn): string
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '';
}

/** A reading of $periodId and its bill posted the way Post Billing posts it (wt_bill() for another period) */
function v47Bill(int $account, int $periodId, int $present, string $billingDate, string $dueDate): int
{
    $pdo = DB::pdo();
    $period = DB::row('SELECT * FROM bill_periods WHERE period_id = :p', ['p' => $periodId]);
    $r = DB::tx(fn() => wr_save_draft_reading($pdo, $period, $account, $present, 'INDIVIDUAL', 'test', (int)$GLOBALS['adminId']));
    $reading = DB::row('SELECT * FROM bill_readings WHERE reading_id = :r', ['r' => $r['reading_id']]);
    $details = water_snapshot_details(water_snapshot_from_reading($reading));
    DB::tx(function () use ($pdo, $reading, $details, $account, $billingDate, $dueDate) {
        $j = wc_create_water_billing_journal_from_breakdown($pdo, (int)$reading['reading_id'], $account, $details, $billingDate, 1, 1, 1);
        DB::run('UPDATE bill_readings SET is_billed = 1, billing_date = :d, due_date = :due, term_days = 15, billing_journal_voucher_id = :j WHERE reading_id = :r',
            ['d' => $billingDate, 'due' => $dueDate, 'j' => $j['journal_head_id'] ?? null, 'r' => $reading['reading_id']]);
    });
    return (int)$reading['reading_id'];
}

test('Billing Adjustments > Edit: the bill itself; the preview works out what the save writes; a due date before the bill date is refused; the term and the disconnection date follow the due date', function () {
    $pdo = DB::pdo();
    $uid = (int)$GLOBALS['adminId'];
    $wt = $GLOBALS['wt'];
    $classId = (int)DB::value('SELECT id FROM classes WHERE is_active = 1 ORDER BY id LIMIT 1');

    // a posted water bill: its reading, tariff lines and amount; another reading worked out, then saved
    wt_account(4701, 'Editor', 'Ana', null);
    [$rid, $amount] = wt_bill(4701, 12);
    $doc = be_bill_document($pdo, 'water', $rid);
    eq([$doc['type_label'], $doc['account']['name'], $doc['account']['kind'], $doc['water']['previous'], $doc['water']['present'], $doc['editable'], $doc['status']],
        ['Water Bill', 'Editor, Ana', 'Customer', 0, 12, true, 'Unpaid'], 'the water bill as the editor shows it');
    check($doc['water']['tiers'] !== [] && abs($doc['total'] - (float)$amount) < 0.005, 'its tariff lines and its amount (' . $amount . ')');
    $expect = round((float)compute_water_billing_details($pdo, 4701, 20, true)['amount_due'], 2);
    $p = be_preview($pdo, 'water', $rid, ['pres_reading' => '20']);
    eq([$p['total'], $p['water']['consumption'], $p['change']], [$expect, 20, round($expect - (float)$amount, 2)], 'the preview: 20 m3 at the tariff');
    eq((float)DB::value('SELECT amount_due FROM bill_readings WHERE reading_id = :r', ['r' => $rid]), round((float)$amount, 2), 'the preview writes nothing');
    check(str_contains(v47Refused(fn() => be_preview($pdo, 'water', $rid, ['pres_reading' => '12.5'])), 'whole number'), 'a reading with decimals is refused');
    eq(v47Refused(function () use ($pdo, $rid) {
        $pdo->exec('START TRANSACTION READ ONLY');
        try {
            be_preview($pdo, 'water', $rid, ['pres_reading' => '25']);
        } finally {
            $pdo->exec('ROLLBACK');
        }
    }), '', 'the preview only reads: it runs in a read-only transaction');
    check(str_contains(v47Refused(fn() => be_preview($pdo, 'water', $rid, ['pres_reading' => ''])), 'present reading'), 'an empty reading is refused');
    $r = WaterPosting::withClass($classId, fn() => DB::tx(fn() => bl_update_bill($pdo, 'water', $rid, ['pres_reading' => '20', 'reason' => 'Meter misread (test)'], $uid)));
    eq([round((float)$r['new_amount'], 2), (float)DB::value('SELECT amount_due FROM bill_readings WHERE reading_id = :r', ['r' => $rid])], [$expect, $expect], 'the save writes the amount the preview showed');

    // a one-time bill (a service charge) with disconnection days: amount and due date corrected
    DB::insert('bill_codes', ['code' => 'EDIT FEE', 'description' => 'Service charge', 'type' => 'Service', 'ar_account' => $wt['ar'], 'income_account' => $wt['sales'],
        'cost_price' => 0, 'sales_price' => '0.00', 'allow_member_discount' => 0, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    wt_account(4702, 'Fee', 'Bert', null);
    otbi_post($pdo, [['_line' => 2, 'account_no' => 'W4702', 'member_name' => '', 'charge_code' => 'EDIT FEE', 'amount' => '120.00', 'quantity' => '', 'bill_date' => '', 'term_days' => '']],
        ['charge_code' => '', 'amount' => '', 'bill_date' => today(), 'term_days' => '15'], $classId, $uid, 'test.csv');
    $bid = (int)DB::value('SELECT MAX(bill_id) FROM one_time_bills WHERE member_id = 4702');
    DB::run('UPDATE one_time_bills SET disconnection_days = 5 WHERE bill_id = :b', ['b' => $bid]);
    $doc = be_bill_document($pdo, 'one_time', $bid);
    eq([$doc['type_label'], $doc['line']['code'], $doc['line']['quantity_based'], $doc['line']['quantity'], $doc['total'], $doc['term_days']],
        ['One-Time Bill', 'EDIT FEE', false, 1.0, 120.0, 15], 'the one-time bill: its charge, one unit, its amount and term');
    $due = date('Y-m-d', strtotime(today() . ' +20 days'));
    $p = be_preview($pdo, 'one_time', $bid, ['total_amount' => '135.25', 'due_date' => $due]);
    eq([$p['total'], $p['term_days'], $p['disconnection_date'], $p['change']], [135.25, 20, date('Y-m-d', strtotime($due . ' +5 days')), 15.25],
        'preview: 135.25 due in 20 days; the term and the disconnection date follow');
    check(str_contains(v47Refused(fn() => be_preview($pdo, 'one_time', $bid, ['total_amount' => '135.25', 'due_date' => date('Y-m-d', strtotime(today() . ' -1 day'))])), 'before the bill date'),
        'a due date before the bill date is refused');
    check(str_contains(v47Refused(fn() => be_preview($pdo, 'one_time', $bid, ['total_amount' => '0'])), 'greater than zero'), 'a zero amount is refused');
    check(str_contains(v47Refused(fn() => WaterPosting::withClass($classId, fn() => DB::tx(fn() => bl_update_bill($pdo, 'one_time', $bid,
        ['total_amount' => '135.25', 'due_date' => date('Y-m-d', strtotime(today() . ' -1 day')), 'reason' => 'Too early (test)'], $uid)))), 'before the bill date'), '...and the save refuses it too');
    WaterPosting::withClass($classId, fn() => DB::tx(fn() => bl_update_bill($pdo, 'one_time', $bid, ['total_amount' => '135.25', 'due_date' => $due, 'reason' => 'Fee schedule (test)'], $uid)));
    $b = DB::row('SELECT * FROM one_time_bills WHERE bill_id = :b', ['b' => $bid]);
    eq([$b['total_amount'], $b['due_date'], (int)$b['term_days'], $b['disconnection_date'], (int)$b['bill_edit_count']],
        ['135.25', $due, 20, date('Y-m-d', strtotime($due . ' +5 days')), 1], 'saved as previewed: the amount, the due date, the term and the disconnection date');
    $rev = WaterPosting::byEvent(WaterPosting::eventKey('one_time', $bid, 1));
    check($rev && $rev['status'] === 'posted' && abs((float)Txn::load((int)$rev['txn_id'])['total'] - 135.25) < 0.005, 'its invoice replaced by -R1 for 135.25');
    eq(be_parse_key('one_time-' . $bid), ['one_time', $bid], 'the editor\'s address');
    eq([be_parse_key('one_time-0'), be_parse_key('payroll-3'), be_parse_key("water-1'")], [null, null, null], 'anything else is not a bill');
});

test('Individual Statement of Account: every unpaid invoice of the account whatever its billing period, dated on or before the statement date; partly paid by its balance, paid or deleted left out; found by name or account number', function () {
    $pdo = DB::pdo();
    $uid = (int)$GLOBALS['adminId'];
    wt_account(4710, 'Statement', 'Sol', null);
    [$r1, $a1] = wt_bill(4710, 10);                                  // period 1 (September), due 2026-10-15
    DB::insert('bill_periods', ['period_id' => 47, 'bp_code' => '100126_103126', 'start_date' => '2026-10-01 00:00:00', 'end_date' => '2026-10-31 23:59:00', 'status' => 'open',
        'opened_by' => $uid, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    $r2 = v47Bill(4710, 47, 18, '2026-11-01', '2026-11-15');             // period 47 (October)
    $a2 = (string)DB::value('SELECT amount_due FROM bill_readings WHERE reading_id = :r', ['r' => $r2]);

    $inv = isoa_open_invoices($pdo, 4710, '2026-12-31');
    eq(array_column($inv, 'key'), ['water:' . $r1, 'water:' . $r2], 'both water bills, of two billing periods, oldest first');
    eq(array_column($inv, 'balance'), [number_format((float)$a1, 2, '.', ''), number_format((float)$a2, 2, '.', '')], 'each by its balance');
    eq(isoa_totals($inv)['total'], isoa_money((int)round(((float)$a1 + (float)$a2) * 100)), 'the total of the statement');
    check(str_contains($inv[0]['detail'], '090126_093026') && str_contains($inv[1]['detail'], '100126_103126'), 'each names its billing period');
    eq(array_column(isoa_open_invoices($pdo, 4710, '2026-10-20'), 'key'), ['water:' . $r1], 'a statement dated before the second bill lists the first only');
    $first = isoa_open_invoices($pdo, 4710, '2026-10-20')[0];
    eq([$first['status'], $first['days_overdue'], $first['status_label']], ['overdue', 5, 'Overdue 5 days'], 'due 2026-10-15: overdue 5 days on 2026-10-20');
    eq(isoa_open_invoices($pdo, 4710, '2026-11-15')[1]['status'], 'due', 'due on the statement date');
    eq(isoa_open_invoices($pdo, 4710, '2026-11-02')[1]['status'], 'current', 'not yet due');

    // part of the first bill paid: its balance; then all of it: gone
    $part = round((float)$a1 / 2, 2);
    wt_collect(4710, [['type' => 'water', 'id' => $r1, 'payment_amount' => number_format($part, 2, '.', '')]], today());
    $inv = isoa_open_invoices($pdo, 4710, '2026-12-31');
    eq([$inv[0]['paid'], $inv[0]['balance']], [number_format($part, 2, '.', ''), number_format((float)$a1 - $part, 2, '.', '')], 'a partly paid bill: what was paid and its balance');
    wt_collect(4710, [['type' => 'water', 'id' => $r1, 'payment_amount' => number_format((float)$a1 - $part, 2, '.', '')]], today());
    eq(array_column(isoa_open_invoices($pdo, 4710, '2026-12-31'), 'key'), ['water:' . $r2], 'a paid bill is left out');
    DB::run("UPDATE bill_readings SET bill_lifecycle_status = 'deleted' WHERE reading_id = :r", ['r' => $r2]);
    eq(isoa_open_invoices($pdo, 4710, '2026-12-31'), [], 'a deleted bill is left out');
    DB::run("UPDATE bill_readings SET bill_lifecycle_status = 'active' WHERE reading_id = :r", ['r' => $r2]);
    eq(isoa_open_invoices($pdo, 4711, '2026-12-31'), [], 'another account has none of them');

    // the search: by name or account number; customers (not members) and members
    $ids = fn(array $rows) => array_map(fn($a) => (int)$a['id'], $rows);
    check(in_array(4710, $ids(isoa_search_accounts($pdo, 'statement', 'all')), true), 'found by the last name');
    check(in_array(4710, $ids(isoa_search_accounts($pdo, 'W4710', 'all')), true), 'found by the account number');
    $hit = array_values(array_filter(isoa_search_accounts($pdo, 'statement', 'customer'), fn($a) => (int)$a['id'] === 4710));
    eq([$hit[0]['kind'] ?? '', $hit[0]['open_count'] ?? -1], ['Customer', 1], 'a customer, with its one unpaid invoice counted');
    check(!in_array(4710, $ids(isoa_search_accounts($pdo, 'statement', 'member')), true), '...not among the members');
    check(!in_array(4710, $ids(isoa_search_accounts($pdo, '%', 'all')), true), 'a % typed is looked for as a %, not as every account');
    eq(isoa_charge_name('WATER METER', 'Water Meter'), 'Water Meter', 'a charge named once when its code and description are alike');
    eq(isoa_charge_name('CF', 'Connection fee'), 'Connection fee (CF)', '...and by its description and code otherwise');
});
