<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v20.xlsx (version 55) at the level of the program's rules:
 * Upload Bill Balances (Water Billing > Billing):
 *  - the file in either layout: one row per member with a balance column for each of the four billing types (a member
 *    with 1, 2, 3 or 4 of them), or one row per balance; each balance a line of the review, with its row of the file;
 *  - every line checked before anything is recorded (the account and its name, the billing type, the balance, the
 *    dates, the charge category, the member's recurring plan, the installments left, the member's fixed share
 *    capital billing, the same balance uploaded before or twice in the file);
 *  - then recorded, every line or none: a recurring bill, an installment contract with its schedule, a one-time bill
 *    (each with its invoice, Dr A/R, Cr the legacy clearing account) and a share capital bill (no journal entry);
 *  - the bills are open items of the cashier and of the Individual Statement of Account;
 *  - the migration 2027_04_18.
 * The page (the review grid, correcting and removing lines, recording) is checked over HTTP (tests/water/e2e_http.php)
 * and in the browser (docs/TEST_EVIDENCE.md).
 */
require_once APP_ROOT . '/water/common/importReviewHelpers.php';
require_once APP_ROOT . '/water/common/billBalanceUploadHelpers.php';
require_once APP_ROOT . '/water/common/individualSoaHelpers.php';
require_once APP_ROOT . '/water/process/recurringBillingProcess.php';

/** The accounts, bill codes, plans and fixed billings of the version 55 tests (made once) */
function v55Fixture(): array
{
    static $f = null;
    if ($f !== null) {
        return $f;
    }
    $wt = $GLOBALS['wt'];
    $now = date('Y-m-d H:i:s');
    $code = fn(string $name, string $type) => (int)(DB::value('SELECT code_id FROM bill_codes WHERE code = :c', ['c' => $name])
        ?: DB::insert('bill_codes', ['code' => $name, 'description' => $name . ' (test)', 'type' => $type, 'ar_account' => $wt['ar'], 'income_account' => $wt['sales'],
            'cost_price' => 0, 'sales_price' => '0.00', 'allow_member_discount' => 0, 'created_at' => $now, 'updated_at' => $now]));
    $plan = fn(int $codeId, string $amount) => (int)DB::insert('recurring_plans', ['bill_code_id' => $codeId, 'amount' => $amount, 'frequency' => 'Monthly',
        'first_billing_date' => '2026-01-01', 'term_days' => 15, 'status' => 'active', 'created_by' => $GLOBALS['adminId'], 'created_at' => $now, 'updated_at' => $now]);
    $f = ['garbage' => $code('V55 GARBAGE FEE', 'Charges'), 'pipe' => $code('V55 PIPE', 'Inventory'), 'fee' => $code('V55 CONNECTION FEE', 'Service'),
        'twice' => $code('V55 TWO PLANS', 'Charges'), 'none' => $code('V55 NO PLAN', 'Charges')];
    $f['plan'] = $plan($f['garbage'], '60.00');
    $plan($f['twice'], '40.00');
    $plan($f['twice'], '80.00');
    // an account enrolled in the garbage plan; one not enrolled (and not a member)
    $f['enrolled'] = wt_account(955001, 'Dimaculangan', 'Aurora', null);
    $f['sub'] = (int)DB::insert('recurring_subscriptions', ['plan_id' => $f['plan'], 'member_id' => $f['enrolled'], 'subscription_start_date' => '2026-01-01',
        'next_billing_date' => date('Y-m-01', strtotime(today() . ' +1 month')), 'last_billed_date' => null, 'status' => 'active', 'created_by' => $GLOBALS['adminId'], 'created_at' => $now, 'updated_at' => $now]);
    $f['plain'] = wt_account(955002, 'Macaraeg', 'Benito', null);
    // members: one on fixed share capital billing, one not, one with two share capital accounts on fixed billing
    $next = date('Y-m-01', strtotime(today() . ' +1 month'));
    $f['m1'] = v42Member('Lacson', 'Teodora', 955003);
    $f['sc1'] = v42Save($f['m1'], 'monthly', $next, 1, '100.00');
    $f['m2'] = v42Member('Panganiban', 'Isidro', 955004);
    $f['m3'] = v42Member('Quisumbing', 'Delia', 955005);
    $second = openAccount($f['m3']['member'], 'SC');
    DB::run("UPDATE sav_accounts SET status = 'active' WHERE id = :a", ['a' => $second]);
    v42Save($f['m3'], 'monthly', $next, 1, '50.00');
    v42Save(['sc' => $second] + $f['m3'], 'monthly', $next, 1, '75.00');
    $f['m3second'] = (string)DB::value('SELECT account_no FROM sav_accounts WHERE id = :a', ['a' => $second]);
    $f['date'] = date('Y-m-d', strtotime(today() . ' -10 days'));
    $f['class'] = (int)DB::value('SELECT id FROM classes WHERE is_active = 1 ORDER BY id LIMIT 1');
    return $f;
}

/** The lines of a CSV text as the review shows them: read as the page reads it (aliases and both layouts) */
function v55Read(string $csv): array
{
    return bbu_expand(CsvReview::readText($csv, BBU_FILE_COLUMNS, BBU_ALIASES), bbu_codes(DB::pdo()));
}

/** One line of the review (the columns of BBU_COLUMNS) */
function v55Line(int $line, array $v): array
{
    $r = ['_line' => $line, '_ref' => 'Row ' . ($line + 1)];
    foreach (BBU_COLUMNS as $k => $_) {
        $r[$k] = (string)($v[$k] ?? '');
    }
    return $r;
}

function v55Common(array $f, array $over = []): array
{
    return $over + ['bill_date' => $f['date'], 'due_date' => '', 'recurring_code' => '', 'installment_code' => '', 'one_time_code' => '', 'installments' => '1', 'payment_mode' => 'Monthly'];
}

test('Version 55: Upload Bill Balances reads either layout: one row per member with a column for each billing type (1, 2, 3 or 4 of them), or one row per balance', function () {
    v55Fixture();
    $wide = "Account No,Member Name,Recurring,Recurring Code,Installment Balance,Installment Code,Installments,Payment Mode,One-Time Balance,One-Time Code,Share Capital,Bill Date,Due Date\n"
        . "W955001,\"Dimaculangan, Aurora\",120.00,v55 garbage fee,,,,,,,,2026-10-31,\n"
        . "W955002,Macaraeg Benito,,,6000,V55 PIPE,6,quarterly,1500,V55 CONNECTION FEE,,10/31/2026,11/15/2026\n"
        . "W955003,\"Lacson, Teodora\",60,V55 GARBAGE FEE,2400,V55 PIPE,4,M,350,V55 CONNECTION FEE,200,,\n"
        . "W955004,,,,,,,,,,,,\n";
    $lines = v55Read($wide);
    eq(count($lines), 1 + 2 + 4 + 1, 'one line for each balance: 1, 2 and 4 balances, and the row with none kept as a line');
    eq(array_column($lines, '_line'), range(1, 8), 'the lines numbered in the order of the file');
    eq(array_column($lines, '_ref'), ['Row 2', 'Row 3', 'Row 3', 'Row 4', 'Row 4', 'Row 4', 'Row 4', 'Row 5'], 'each line with the row of the file it came from');
    eq(array_column($lines, 'billing_type'), ['Recurring', 'Installment', 'One-Time', 'Recurring', 'Installment', 'One-Time', 'Fixed Share Capital', ''], 'the billing type of each balance column');
    eq([$lines[0]['charge_code'], $lines[0]['balance']], ['V55 GARBAGE FEE', '120.00'], 'the charge category spelled as Bill Codes has it');
    eq([$lines[1]['installments'], $lines[1]['payment_mode'], $lines[1]['due_date']], ['6', 'Quarterly', '11/15/2026'], 'the installments left and the payment mode on the installment line');
    eq([$lines[2]['installments'], $lines[2]['payment_mode'], $lines[2]['bill_date']], ['', '', '10/31/2026'], '...not on the one-time line; the dates of the row on each of its lines');
    eq([$lines[6]['charge_code'], $lines[6]['balance']], ['', '200'], 'a share capital balance has no charge category');
    $long = "account,name,type,charge category,amount,date,due,installments left,mode,sc account\n"
        . "W955001,,recurring,V55 GARBAGE FEE,99.50,,,,,\n"
        . "W955003,,FSC,,300,,,,,\n"
        . "W955002,,one time,V55 CONNECTION FEE,25,,,,,\n"
        . "W955002,,Instalment,V55 PIPE,1000,,,10,semi-annually,\n"
        . "W955002,,water,V55 PIPE,1,,,,,\n";
    $lines = v55Read($long);
    eq(array_column($lines, 'billing_type'), ['Recurring', 'Fixed Share Capital', 'One-Time', 'Installment', 'water'], 'one line per row; the billing type in any of its usual words (a word not known stays as typed)');
    eq($lines[3]['payment_mode'], 'Semi-annually', 'the payment mode in its usual word');
    $mixed = v55Read("account_no,billing_type,balance,one_time_balance,one_time_code\nW955002,Recurring,10,20,V55 CONNECTION FEE\n");
    eq(array_column($mixed, 'billing_type'), ['Recurring', 'One-Time'], 'a mix of both layouts on a row: a line for each');
    refuses(fn() => v55Read("balance,billing_type\n10,Recurring\n"), 'account_no', 'a file with no account column');
});

test('Version 55: Upload Bill Balances checks every line before anything is recorded (account, name, billing type, balance, dates, charge category, plans, installments, fixed billing)', function () {
    $f = v55Fixture();
    $pdo = DB::pdo();
    $d = $f['date'];
    $rows = [
        v55Line(1, ['account_no' => 'W955001', 'member_name' => 'Aurora Dimaculangan', 'billing_type' => 'Recurring', 'charge_code' => 'V55 GARBAGE FEE', 'balance' => '120']),
        v55Line(2, ['account_no' => 'W999999', 'billing_type' => 'One-Time', 'charge_code' => 'V55 CONNECTION FEE', 'balance' => '10']),
        v55Line(3, ['account_no' => 'W955002', 'member_name' => 'Somebody Else', 'billing_type' => 'Monthly', 'balance' => 'ten']),
        v55Line(4, ['account_no' => 'W955002', 'billing_type' => 'One-Time', 'charge_code' => 'V55 CONNECTION FEE', 'balance' => '0', 'bill_date' => date('Y-m-d', strtotime(today() . ' +1 day'))]),
        v55Line(5, ['account_no' => 'W955002', 'billing_type' => 'One-Time', 'charge_code' => 'NOT A CODE', 'balance' => '5', 'due_date' => date('Y-m-d', strtotime($d . ' -1 day'))]),
        v55Line(6, ['account_no' => 'W955002', 'billing_type' => 'Recurring', 'charge_code' => 'V55 NO PLAN', 'balance' => '5']),
        v55Line(7, ['account_no' => 'W955002', 'billing_type' => 'Recurring', 'charge_code' => 'V55 TWO PLANS', 'balance' => '5']),
        v55Line(8, ['account_no' => 'W955002', 'billing_type' => 'Installment', 'charge_code' => 'V55 PIPE', 'balance' => '500', 'installments' => '41', 'payment_mode' => 'Quarterly']),
        v55Line(9, ['account_no' => 'W955002', 'billing_type' => 'Fixed Share Capital', 'balance' => '100']),
        v55Line(10, ['account_no' => 'W955004', 'billing_type' => 'Fixed Share Capital', 'balance' => '100']),
        v55Line(11, ['account_no' => 'W955005', 'billing_type' => 'Fixed Share Capital', 'balance' => '100']),
        v55Line(12, ['account_no' => 'W955005', 'billing_type' => 'Fixed Share Capital', 'balance' => '100', 'share_capital_account' => $f['m3second']]),
        v55Line(13, ['account_no' => 'W955002', 'billing_type' => 'Recurring', 'charge_code' => 'V55 GARBAGE FEE', 'balance' => '45']),
        v55Line(14, ['account_no' => 'W955002', 'billing_type' => 'One-Time', 'balance' => '45']),
        v55Line(15, ['account_no' => 'W955002', 'billing_type' => 'Installment', 'charge_code' => 'V55 PIPE', 'balance' => '900', 'installments' => '3', 'payment_mode' => 'Monthly',
            'due_date' => date('Y-m-d', strtotime($d . ' -1 month'))]),
        v55Line(16, ['account_no' => 'W955001', 'billing_type' => 'Recurring', 'charge_code' => 'V55 GARBAGE FEE', 'balance' => '30']),
    ];
    $water = DB::value("SELECT code FROM bill_codes WHERE code_id = (SELECT setting_value FROM water_billing_settings WHERE setting_key = 'water_billing_bill_code_id')");
    if ($water) {
        $rows[] = v55Line(17, ['account_no' => 'W955002', 'billing_type' => 'One-Time', 'charge_code' => (string)$water, 'balance' => '5']);
    }
    $by = array_column(bbu_check($pdo, $rows, v55Common($f)), null, '_line');
    $errs = fn(int $l) => implode(' | ', $by[$l]['_errors']);
    eq($by[1]['_errors'], [], 'line 1: good (the name in another order)');
    eq([$by[1]['_ok']['type'], $by[1]['_ok']['subscription_id'], $by[1]['_ok']['plan_id'], $by[1]['_ok']['bill_date'], $by[1]['_ok']['due_date']],
        ['recurring', $f['sub'], $f['plan'], $d, $d], '...on the member\'s subscription; the form\'s bill date; no due date given: the bill date');
    eq([$by[1]['_group'], $by[1]['_figure']], ['recurring', 120.0], '...added up under Recurring');
    eq($by[1]['_off'], ['installments', 'payment_mode', 'share_capital_account'], '...the cells a recurring balance does not use');
    check(str_contains($errs(2), 'No water-service account W999999'), 'line 2: no such account');
    check(str_contains($errs(3), 'is Macaraeg, Benito, not Somebody Else') && str_contains($errs(3), 'not a billing type') && str_contains($errs(3), 'not an amount'), 'line 3: the name, the billing type, the balance (' . $errs(3) . ')');
    check(str_contains($errs(4), 'more than zero') && str_contains($errs(4), 'after today'), 'line 4: a zero balance, a bill date after today (' . $errs(4) . ')');
    check(str_contains($errs(5), 'No charge category NOT A CODE') && str_contains($errs(5), 'before the bill date'), 'line 5: an unknown charge category, a due date before the bill date (' . $errs(5) . ')');
    check(str_contains($errs(6), 'No recurring plan bills V55 NO PLAN'), 'line 6: no recurring plan of the charge category');
    check(str_contains($errs(7), '2 recurring plans bill V55 TWO PLANS'), 'line 7: two plans of the charge category, the member enrolled in neither');
    check(str_contains($errs(8), 'At most 40 quarterly installments'), 'line 8: an installment contract of more than 10 years');
    check(str_contains($errs(9), 'not a cooperative member'), 'line 9: share capital of an account whose holder is not a member');
    check(str_contains($errs(10), 'not on Fixed Share Capital Billing'), 'line 10: a member not on fixed billing');
    check(str_contains($errs(11), '2 share capital accounts on fixed billing'), 'line 11: a member with two accounts on fixed billing, no account named');
    eq([$by[12]['_errors'], $by[12]['_ok']['sav_account_no'] ?? null], [[], $f['m3second']], 'line 12: ...the account named');
    eq([$by[13]['_errors'], $by[13]['_ok']['subscription_id'], $by[13]['_ok']['plan_id']], [[], null, $f['plan']], 'line 13: not enrolled: the balance on a subscription to be made (ended)');
    check(str_contains(implode(' ', $by[13]['_notes']), 'nothing more is billed'), '...said so on the line');
    check(str_contains($errs(14), 'Enter the charge category of the one-time balance'), 'line 14: no charge category and none for the blank cells');
    eq([$by[15]['_errors'], count($by[15]['_ok']['dates']), array_map(fn($v) => number_format((float)$v, 2, '.', ''), $by[15]['_ok']['amounts'])], [[], 3, ['300.00', '300.00', '300.00']], 'line 15: three monthly installments of 300.00');
    check(str_contains(implode(' ', $by[15]['_notes']), 'fell due before the bill date'), '...the first due date may be before the bill date (the installments in arrears said)');
    check(str_contains($errs(16), 'Line 1 has a V55 GARBAGE FEE recurring balance of this account on the same date'), 'line 16: one recurring bill a date on a subscription');
    if ($water) {
        check(str_contains($errs(17), 'water bill\'s'), 'line 17: the water bill\'s own bill code refused');
    }
    // the values for the blank cells: a charge category per billing type, the installments and payment mode
    $by = array_column(bbu_check($pdo, [$rows[13], v55Line(20, ['account_no' => 'W955002', 'billing_type' => 'Installment', 'balance' => '1000'])],
        v55Common($f, ['one_time_code' => 'V55 CONNECTION FEE', 'installment_code' => 'V55 PIPE', 'installments' => '4', 'payment_mode' => 'Quarterly'])), null, '_line');
    eq([$by[14]['_errors'], $by[14]['_ok']['code']], [[], 'V55 CONNECTION FEE'], 'the one-time charge category for the blank cells');
    eq([$by[20]['_errors'], $by[20]['_ok']['installments'], $by[20]['_ok']['mode'], $by[20]['_ok']['dates'][1]], [[], 4, 'quarterly', date('Y-m-d', strtotime($d . ' +3 months'))],
        'the installment charge category, installments and payment mode for the blank cells');
    // what stops the whole upload: the class, when a line makes a journal entry
    check(bbu_class_problem('') !== null && bbu_class_problem($f['class']) === null, 'the class of the journal entries is required');
    // closed books refuse the invoices (to a user who may not change a closed period), not a share capital bill
    $closing = Settings::closingDate();
    Settings::set('closing_date', date('Y-m-d', strtotime($d . ' +1 day')));
    try {
        asUser('book');
        $by = array_column(bbu_check($pdo, [$rows[0], $rows[11]], v55Common($f)), null, '_line');
        Auth::actAs($GLOBALS['adminId']);
        check(str_contains(implode(' ', $by[1]['_errors']), 'books are closed') && $by[12]['_errors'] === [], 'books closed: the recurring balance refused, the share capital balance (no journal entry) not');
        $by = array_column(bbu_check($pdo, [$rows[0]], v55Common($f)), null, '_line');
        eq($by[1]['_errors'], [], '...allowed with the permission to change closed periods (as every posting)');
    } finally {
        Auth::actAs($GLOBALS['adminId']);
        Settings::set('closing_date', $closing ?: '');
    }
});

test('Version 55: Upload Bill Balances records every line or none: a recurring bill, an installment contract, a one-time bill (each with its invoice, Dr A/R Cr clearing) and a share capital bill (no journal entry)', function () {
    $f = v55Fixture();
    $pdo = DB::pdo();
    $d = $f['date'];
    $due = date('Y-m-d', strtotime($d . ' +15 days'));
    $good = [
        v55Line(1, ['account_no' => 'W955001', 'billing_type' => 'Recurring', 'charge_code' => 'V55 GARBAGE FEE', 'balance' => '120.00', 'due_date' => $due]),
        v55Line(2, ['account_no' => 'W955002', 'billing_type' => 'Recurring', 'charge_code' => 'V55 GARBAGE FEE', 'balance' => '45.00']),
        v55Line(3, ['account_no' => 'W955002', 'billing_type' => 'Installment', 'charge_code' => 'V55 PIPE', 'balance' => '1000.00', 'installments' => '3', 'payment_mode' => 'Monthly', 'due_date' => $due]),
        v55Line(4, ['account_no' => 'W955003', 'billing_type' => 'One-Time', 'charge_code' => 'V55 CONNECTION FEE', 'balance' => '1500.00', 'due_date' => $due]),
        v55Line(5, ['account_no' => 'W955003', 'billing_type' => 'Fixed Share Capital', 'balance' => '200.00', 'due_date' => $due]),
    ];
    $bad = v55Line(6, ['account_no' => 'W999999', 'billing_type' => 'One-Time', 'charge_code' => 'V55 CONNECTION FEE', 'balance' => '1']);
    $count = fn() => [(int)DB::value('SELECT COUNT(*) FROM recurring_bills'), (int)DB::value('SELECT COUNT(*) FROM installment_bills'), (int)DB::value('SELECT COUNT(*) FROM one_time_bills'),
        (int)DB::value('SELECT COUNT(*) FROM share_capital_bills'), (int)DB::value('SELECT COUNT(*) FROM transactions'), (int)DB::value('SELECT COUNT(*) FROM water_balance_uploads')];
    $before = $count();
    refuses(fn() => bbu_post($pdo, array_merge($good, [$bad]), v55Common($f), $f['class'], (int)$GLOBALS['adminId'], 'balances.csv'), 'No water-service account', 'recording with a line in red');
    eq($count(), $before, '...nothing recorded');
    try {
        bbu_post($pdo, $good, v55Common($f), 0, (int)$GLOBALS['adminId'], 'balances.csv');
        check(false, 'recorded without a class');
    } catch (Throwable $e) {
        check(str_contains($e->getMessage(), 'class'), 'the class is required for the invoices (' . $e->getMessage() . ')');
    }
    eq($count(), $before, '...nothing recorded');
    $stock = (float)DB::value('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock');
    $r = bbu_post($pdo, $good, v55Common($f), $f['class'], (int)$GLOBALS['adminId'], 'balances.csv');
    eq([$r['count'], round($r['total'], 2)], [5, 2865.0], 'five balances, 2,865.00');
    eq(array_map(fn($v) => [$v[0], round($v[1], 2)], $r['by_type']), ['recurring' => [2, 165.0], 'installment' => [1, 1000.0], 'one_time' => [1, 1500.0], 'share_capital' => [1, 200.0]], '...by billing type');
    check(preg_match('/^BAL-\d{5}$/', $r['upload_no']) === 1, 'the upload numbered ' . $r['upload_no']);
    $after = $count();
    eq([$after[0] - $before[0], $after[1] - $before[1], $after[2] - $before[2], $after[3] - $before[3], $after[4] - $before[4], $after[5] - $before[5]], [2, 1, 1, 1, 4, 1],
        'two recurring bills, an installment contract, a one-time bill and a share capital bill; four invoices (none for the share capital bill); one upload');
    $u = bbu_upload($pdo, $r['upload_id']);
    eq([$u['file_name'], (int)$u['line_count'], $u['total_amount'], (int)$u['class_id']], ['balances.csv', 5, '2865.00', $f['class']], 'the upload kept with its file, count, total and class');
    eq(array_map(fn($l) => [(int)$l['line_no'], (int)$l['file_row'], $l['billing_type']], $u['lines']), [[1, 2, 'recurring'], [2, 3, 'recurring'], [3, 4, 'installment'], [4, 5, 'one_time'], [5, 6, 'share_capital']],
        '...and its lines (the line, the row of the file, the billing type)');
    $line = array_column($u['lines'], null, 'line_no');
    // recurring, on the member's subscription
    $rb = DB::row('SELECT * FROM recurring_bills WHERE bill_id = :b', ['b' => $line[1]['bill_id']]);
    eq([(int)$rb['subscription_id'], (int)$rb['plan_id'], (int)$rb['member_id'], $rb['bill_date'], $rb['due_date'], (int)$rb['term_days'], $rb['total_amount'], $rb['balance'], $rb['status']],
        [$f['sub'], $f['plan'], $f['enrolled'], $d, $due, 15, '120.00', '120.00', 'unpaid'], 'the recurring bill on the member\'s subscription, unpaid, due on the due date');
    check($rb['global_ref_number'] !== null && $rb['journal_voucher_id'] !== null, '...numbered, with its journal entry');
    // recurring, not enrolled: an ended subscription holds it
    $rb2 = DB::row('SELECT * FROM recurring_bills WHERE bill_id = :b', ['b' => $line[2]['bill_id']]);
    $sub2 = DB::row('SELECT * FROM recurring_subscriptions WHERE subscription_id = :s', ['s' => $rb2['subscription_id']]);
    eq([(int)$sub2['plan_id'], (int)$sub2['member_id'], $sub2['status'], $rb2['due_date']], [$f['plan'], $f['plain'], 'ended', $d], 'not enrolled: the bill on an ended subscription to the plan (due on the bill date)');
    // installment
    $ib = DB::row('SELECT * FROM installment_bills WHERE installment_id = :b', ['b' => $line[3]['bill_id']]);
    eq([(int)$ib['member_id'], $ib['payment_mode'], $ib['total_amount'], (int)$ib['term'], $ib['start_date'], $ib['status'], $ib['amortization_type']],
        [$f['plain'], 'monthly', '1000.00', 3, $d, 'active', 'fixed'], 'the installment contract: dated the bill date, 3 months, 1,000.00');
    eq(array_map(fn($s) => [$s['due_date'], $s['amount'], $s['status']], DB::all('SELECT * FROM installment_schedules WHERE installment_id = :b ORDER BY due_date', ['b' => $ib['installment_id']])),
        [[$due, '333.34', 'pending'], [date('Y-m-d', strtotime($due . ' +1 month')), '333.33', 'pending'], [date('Y-m-d', strtotime($due . ' +2 months')), '333.33', 'pending']], '...its three installments from the due date');
    eq((float)DB::value('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock'), $stock, '...a material\'s balance moves no stock');
    // one-time
    $ob = DB::row('SELECT * FROM one_time_bills WHERE bill_id = :b', ['b' => $line[4]['bill_id']]);
    eq([(int)$ob['member_id'], (int)$ob['bill_code_id'], $ob['total_amount'], $ob['gross_amount'], $ob['discount_amount'], $ob['due_date'], (int)$ob['term_days'], $ob['status'], $ob['source_type'], (int)$ob['source_id']],
        [$f['m1']['water'], $f['fee'], '1500.00', '1500.00', '0.00', $due, 15, 'unpaid', 'balance_upload', $r['upload_id']], 'the one-time bill, marked as uploaded (no discount taken)');
    // share capital: on the member's fixed billing, no journal entry
    $sb = DB::row('SELECT * FROM share_capital_bills WHERE bill_id = :b', ['b' => $line[5]['bill_id']]);
    eq([(int)$sb['plan_id'], (int)$sb['member_id'], (int)$sb['sav_account_id'], $sb['bill_date'], $sb['due_date'], $sb['amount'], $sb['status']],
        [$f['sc1'], $f['m1']['member'], $f['m1']['sc'], $d, $due, '200.00', 'unpaid'], 'the share capital bill on the member\'s fixed billing');
    // the invoices: Dr the bill code's A/R, Cr the legacy clearing account; no income
    $clearing = WaterPosting::legacyClearingAccount();
    foreach ([['recurring', $line[1]['bill_id'], '120.00'], ['recurring', $line[2]['bill_id'], '45.00'], ['installment', $line[3]['bill_id'], '1000.00'], ['one_time', $line[4]['bill_id'], '1500.00']] as [$src, $id, $amt]) {
        $p = WaterPosting::billPostings($src, (int)$id);
        check(count($p) === 1, $src . ' #' . $id . ': one invoice');
        $j = DB::all('SELECT account_id, debit, credit, class_id FROM journal_lines WHERE txn_id = :t ORDER BY debit DESC', ['t' => $p[0]['txn_id']]);
        eq(array_map(fn($x) => [(int)$x['account_id'], $x['debit'], $x['credit'], (int)$x['class_id']], $j), [[$GLOBALS['wt']['ar'], $amt, '0.00', $f['class']], [$clearing, '0.00', $amt, $f['class']]],
            '...Dr A/R ' . $amt . ', Cr the legacy clearing account, in the class chosen');
        eq($p[0]['txn_date'], $d, '...dated the bill date');
    }
    assertLedgerBalanced('after the upload of balances');
    // the bills are open items: the cashier and the Individual Statement of Account
    eq(WaterAccounts::openBalance($f['plain'])['total'] >= 1045.0, true, 'the not-enrolled account owes its recurring and installment balances');
    $soa = array_column(isoa_open_invoices_of_account($pdo, $f['m1']['water'], today()), null, 'key');
    check(isset($soa['one_time:' . $line[4]['bill_id']], $soa['sharecap:' . $line[5]['bill_id']]), 'the Individual Statement of Account lists the one-time and the share capital balances');
    $soa2 = array_column(isoa_open_invoices_of_account($pdo, $f['plain'], today()), null, 'type');
    check(isset($soa2['recurring'], $soa2['installment']), '...and the recurring and the installment balances (the contract dated the bill date)');
    eq(array_map(fn($b) => (int)$b['bill_id'], ShareCapitalBilling::openBillsForWaterAccount($f['m1']['water'])), [(int)$line[5]['bill_id']], 'the share capital balance is an open bill of the member\'s water account at the cashier');
    // nothing more is billed on the ended subscription
    // (its next billing date is the bill date, already past: an active subscription would be billed now)
    rb_process_recurring_bills($pdo, today(), (int)$GLOBALS['adminId']);
    eq((int)DB::value('SELECT COUNT(*) FROM recurring_bills WHERE subscription_id = :s', ['s' => $sub2['subscription_id']]), 1, 'the ended subscription bills nothing more');
    // only share capital lines: no class needed, no journal entry
    $tx = (int)DB::value('SELECT COUNT(*) FROM transactions');
    $r2 = bbu_post($pdo, [v55Line(1, ['account_no' => 'W955005', 'billing_type' => 'Fixed Share Capital', 'balance' => '80', 'share_capital_account' => $f['m3second']])], v55Common($f), null, (int)$GLOBALS['adminId'], '');
    eq([$r2['count'], (int)DB::value('SELECT COUNT(*) FROM transactions')], [1, $tx], 'share capital balances only: recorded with no class and no journal entry');
});

test('Version 55: Upload Bill Balances does not take the same balance twice: an earlier upload, the same bill date on a subscription or a fixed billing; a cancelled bill may be uploaded again', function () {
    $f = v55Fixture();
    $pdo = DB::pdo();
    $d = $f['date'];
    $rows = [
        v55Line(1, ['account_no' => 'W955003', 'billing_type' => 'One-Time', 'charge_code' => 'V55 CONNECTION FEE', 'balance' => '1500.00', 'due_date' => date('Y-m-d', strtotime($d . ' +15 days'))]),
        v55Line(2, ['account_no' => 'W955003', 'billing_type' => 'One-Time', 'charge_code' => 'V55 CONNECTION FEE', 'balance' => '700.00']),
        v55Line(3, ['account_no' => 'W955003', 'billing_type' => 'Fixed Share Capital', 'balance' => '50.00']),
        v55Line(4, ['account_no' => 'W955001', 'billing_type' => 'Recurring', 'charge_code' => 'V55 GARBAGE FEE', 'balance' => '10.00']),
        v55Line(5, ['account_no' => 'W955002', 'billing_type' => 'Installment', 'charge_code' => 'V55 PIPE', 'balance' => '77.00']),
        v55Line(6, ['account_no' => 'W955002', 'billing_type' => 'Installment', 'charge_code' => 'V55 PIPE', 'balance' => '77.00']),
    ];
    $by = array_column(bbu_check($pdo, $rows, v55Common($f)), null, '_line');
    check(str_contains(implode(' ', $by[1]['_errors']), 'Uploaded already: BAL-'), 'line 1: the same account, billing type, charge category, date and balance uploaded before (' . implode(' ', $by[1]['_errors']) . ')');
    eq($by[2]['_errors'], [], 'line 2: the same date, another balance: allowed');
    check(str_contains(implode(' ', $by[2]['_notes']), 'Make sure it is not the same bill'), '...with a note naming the earlier upload');
    check(str_contains(implode(' ', $by[3]['_errors']), 'already has a bill dated'), 'line 3: the fixed billing has a bill of that date');
    check(str_contains(implode(' ', $by[4]['_errors']), 'already has a recurring bill dated'), 'line 4: the subscription has a recurring bill of that date');
    eq([$by[5]['_errors'], $by[6]['_errors']], [[], []], 'lines 5 and 6: the same installment balance twice in the file: allowed');
    check(str_contains(implode(' ', $by[6]['_notes']), 'Line 5 has the same'), '...line 6 says so');
    // a bill cancelled since: its balance may be uploaded again
    $bill = (int)DB::value("SELECT l.bill_id FROM water_balance_upload_lines l WHERE l.billing_type = 'one_time' AND l.water_account_id = :w ORDER BY l.line_id LIMIT 1", ['w' => $f['m1']['water']]);
    DB::run("UPDATE one_time_bills SET status = 'cancelled' WHERE bill_id = :b", ['b' => $bill]);
    $by = array_column(bbu_check($pdo, [$rows[0]], v55Common($f)), null, '_line');
    eq($by[1]['_errors'], [], 'the uploaded bill cancelled: the balance may be uploaded again');
    DB::run("UPDATE one_time_bills SET status = 'unpaid' WHERE bill_id = :b", ['b' => $bill]);
    // the uploads listed newest first with their totals by billing type
    $list = bbu_uploads($pdo, 5);
    check(count($list) >= 2 && (int)$list[0]['upload_id'] > (int)$list[1]['upload_id'], 'the uploads newest first');
    eq(array_sum(array_map(fn($u) => (int)$u['line_count'], $list)), array_sum(array_map(fn($u) => array_sum(array_column($u['by_type'], 0)), $list)), '...their counts by billing type add up to their line counts');
    // the templates: both layouts with example rows
    check(count(bbu_samples($pdo, 'wide')) >= 1 && count(bbu_samples($pdo, 'long')) >= 1, 'the templates have example rows in both layouts');
    $types = [];
    foreach (bbu_samples($pdo, 'wide') as $s) {
        $types[] = count(array_filter(['recurring_balance', 'installment_balance', 'one_time_balance', 'share_capital_balance'], fn($k) => ($s[$k] ?? '') !== ''));
    }
    check(in_array(1, $types, true) || in_array(2, $types, true), '...the one-row-per-member example has members with fewer than four balances');
});

test('Version 55: migration 2027_04_18 adds the uploads, their lines and the page after Fixed Share Capital Billing with the rights of One-Time Billing (run twice)', function () {
    $pdo = DB::pdo();
    run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_04_18_bill_balance_upload.sql');
    run_sql_file($pdo, APP_ROOT . '/database/migrations/2027_04_18_bill_balance_upload.sql');
    foreach (['water_balance_uploads' => ['upload_no', 'file_name', 'line_count', 'total_amount', 'class_id', 'created_by', 'created_at'],
              'water_balance_upload_lines' => ['upload_id', 'line_no', 'file_row', 'billing_type', 'water_account_id', 'bill_code_id', 'sav_account_id', 'bill_id', 'amount', 'bill_date', 'due_date', 'installments']] as $t => $cols) {
        $have = array_column(DB::all('SHOW COLUMNS FROM ' . $t), 'Field');
        eq(array_values(array_diff($cols, $have)), [], $t . ': its columns');
    }
    $page = DB::row("SELECT * FROM pages WHERE page_key = 'water.bill_balance_upload'");
    $sc = DB::row("SELECT * FROM pages WHERE page_key = 'water.share_capital_billing'");
    check($page && (int)$page['show_in_menu'] === 1 && $page['menu_group'] === 'Billing' && (int)$page['sort_order'] > (int)$sc['sort_order'], 'the page in the Billing menu after Fixed Share Capital Billing');
    eq((int)DB::value("SELECT COUNT(*) FROM pages WHERE page_key = 'water.bill_balance_upload'"), 1, '...once');
    $roles = fn(string $key) => DB::all("SELECT r.role_id, r.can_create FROM role_page_access r JOIN pages p ON p.id = r.page_id WHERE p.page_key = :k AND r.allowed = 1 ORDER BY r.role_id", ['k' => $key]);
    eq($roles('water.bill_balance_upload'), $roles('water.one_time_billing'), 'given to the roles that have One-Time Billing, with the same Create right');
    eq(Schema::pending(), [], 'no database update pending');
    eq(WaterPages::processPage('billBalanceUpload'), 'bill_balance_upload', 'its process belongs to the page');
});
