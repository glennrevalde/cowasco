<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v13.xlsx (version 42) at the level of the program's rules:
 *  - the balances of the transaction history in the order of the transaction dates (a back-dated one in its place);
 *  - Fixed Share Capital Billing: the members with share capital, the frequency, start date, posting day and amount,
 *    the bills posted on their day with no journal entry, hold / resume / edit / delete, the collection by the water
 *    cashier (a deposit by the savings transaction code of the page, on the Savings Account Transaction and waiting
 *    for the passbook), the reversal of the receipt;
 *  - the Collection Summary: Share capital beside the bills paid (Summary) and a column for each code name (Detailed);
 *  - the migration 2027_03_14.
 * The pages (the billing page, the cashier, the report filter) are checked over HTTP (tests/water/e2e_http.php) and in
 * the browser (docs/TEST_EVIDENCE.md).
 */

require_once APP_ROOT . '/water/common/collectionReceiptHelpers.php';

/** A member with an open share capital account (and a regular savings account), linked to a water-service account */
function v42Member(string $last, string $first, int $waterKey): array
{
    $m = newMember($last, $first);
    $sc = (int)(DB::value("SELECT a.id FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND p.product_type = 'share_capital' AND a.status IN ('active', 'dormant') ORDER BY a.id LIMIT 1", ['m' => $m])
        ?: openAccount($m, 'SC'));
    DB::run("UPDATE sav_accounts SET status = 'active' WHERE id = :a AND status = 'pending'", ['a' => $sc]);
    $party = (int)DB::value('SELECT party_id FROM members WHERE id = :m', ['m' => $m]);
    $w = $waterKey > 0 ? wt_account($waterKey, $last, $first, $party) : 0;
    return ['member' => $m, 'sc' => $sc, 'water' => $w, 'party' => $party];
}

function v42Bills(int $planId): array
{
    return DB::all('SELECT bill_id, bill_date, amount, amount_paid, status FROM share_capital_bills WHERE plan_id = :p ORDER BY bill_date', ['p' => $planId]);
}

function v42Save(array $who, string $freq, string $start, int $day, string $amount, ?int $planId = null): int
{
    return ShareCapitalBilling::save(['member_id' => $who['member'], 'sav_account_id' => $who['sc'], 'frequency' => $freq, 'start_date' => $start,
        'posting_day' => $day, 'amount' => $amount], $planId, 'water.share_capital_billing');
}

test('Inventory Center history: a back-dated movement takes its place by date, and the balances after each movement follow the dates', function () {
    $j = $GLOBALS['juana'];
    $item = pt_item('V42BD', 'Back-dated test item', 'inventory', '150', '100', false);
    pt_bill($j['vendor'], '2026-03-10', [['item_id' => $item, 'qty' => 10, 'rate' => 100]]);
    pt_sale('invoice', $j['customer'], '2026-03-20', [['item_id' => $item, 'qty' => 4, 'rate' => 150]]);
    pt_bill($j['vendor'], '2026-03-05', [['item_id' => $item, 'qty' => 6, 'rate' => 80]]);       // recorded last, dated first
    $moves = DB::all('SELECT id, txn_date, qty, qty_after FROM inventory_movements WHERE item_id = :i ORDER BY txn_date, id', ['i' => $item]);
    eq(array_map(fn($m) => [$m['txn_date'], (float)$m['qty'], (float)$m['qty_after']], $moves),
        [['2026-03-05', 6.0, 12.0], ['2026-03-10', 10.0, 10.0], ['2026-03-20', -4.0, 6.0]], 'the stored figures are those of the moment each was recorded (wrong in date order)');
    $after = Stock::balancesAfter($item);
    eq(array_map(fn($m) => $after[(int)$m['id']]['qty'], $moves), [6.0, 16.0, 12.0], 'on hand after each movement in date order: 6, 16, 12');
    eq(end($after)['qty'], Stock::onHand($item), 'the last balance is the quantity on hand');
    eq(array_map(fn($m) => $after[(int)$m['id']]['avg'], $moves), [80.0, 92.5, 90.0], 'the average after each: 480/6, 1,480/16, 1,080/12');
    $site = pt_site();
    eq(array_map(fn($m) => Stock::balancesAfter($item, $site)[(int)$m['id']]['qty'], $moves), [6.0, 16.0, 12.0], 'the same at the site');
});

test('Fixed Share Capital Billing: the posting dates (the posting day on or after the start date, every frequency after, the last day of a shorter month)', function () {
    eq([ShareCapitalBilling::dayIn('2027-02-10', 31), ShareCapitalBilling::dayIn('2028-02-01', 30), ShareCapitalBilling::dayIn('2026-11-30', 15)],
        ['2027-02-28', '2028-02-29', '2026-11-15'], 'the posting day in a month (the last day when the month is shorter)');
    eq([ShareCapitalBilling::firstDate('2026-11-01', 1), ShareCapitalBilling::firstDate('2026-11-02', 2), ShareCapitalBilling::firstDate('2026-11-02', 1), ShareCapitalBilling::firstDate('2026-02-15', 31)],
        ['2026-11-01', '2026-11-02', '2026-12-01', '2026-02-28'], 'the first posting date: the workbook\'s 2026-11-01 day 1 and 2026-11-02 day 2 bill on the start date');
    $series = function (string $start, int $day, string $freq, int $n): array {
        $d = ShareCapitalBilling::firstDate($start, $day);
        $out = [$d];
        while (count($out) < $n) {
            $out[] = $d = ShareCapitalBilling::nextDate($d, $freq, $day);
        }
        return $out;
    };
    eq($series('2026-11-01', 1, 'monthly', 4), ['2026-11-01', '2026-12-01', '2027-01-01', '2027-02-01'], 'monthly');
    eq($series('2026-11-02', 2, 'quarterly', 4), ['2026-11-02', '2027-02-02', '2027-05-02', '2027-08-02'], 'quarterly');
    eq($series('2026-05-31', 31, 'semi_annual', 4), ['2026-05-31', '2026-11-30', '2027-05-31', '2027-11-30'], 'semi-annually: day 31 in November is the 30th, back to the 31st in May');
    eq($series('2027-01-31', 31, 'monthly', 4), ['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'], 'monthly on the 31st: never drifts to the 28th');
    eq($series('2028-02-29', 29, 'annual', 3), ['2028-02-29', '2029-02-28', '2030-02-28'], 'annually from a leap day');
    eq(ShareCapitalBilling::seriesFrom('2026-11-01', 'monthly', 1, '2027-03-15'), '2027-04-01', 'the first of the series on or after a date');
    eq(ShareCapitalBilling::seriesFrom('2026-11-01', 'monthly', 5, '2026-11-01', ShareCapitalBilling::monthEnd('2026-11-01')), '2026-12-05', 'after the month of the last bill (a month is never billed twice)');
});

test('Fixed Share Capital Billing: only a member\'s open share capital account; one billing per account; the amount, frequency and day checked', function () {
    $a = v42Member('Bond', 'James', 942001);
    $other = v42Member('Cuneta', 'Sharon', 0);
    $rs = (int)DB::value("SELECT a.id FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND p.product_type <> 'share_capital' ORDER BY a.id LIMIT 1", ['m' => $a['member']])
        ?: openAccount($a['member'], 'RS');
    $base = ['member_id' => $a['member'], 'sav_account_id' => $a['sc'], 'frequency' => 'monthly', 'start_date' => '2030-01-01', 'posting_day' => 1, 'amount' => '50'];
    refuses(fn() => ShareCapitalBilling::save(['sav_account_id' => $rs] + $base, null, 'water.share_capital_billing'), 'not a share capital account', 'a savings account is refused');
    refuses(fn() => ShareCapitalBilling::save(['sav_account_id' => $other['sc']] + $base, null, 'water.share_capital_billing'), 'Choose a share capital account of', 'another member\'s account is refused');
    refuses(fn() => ShareCapitalBilling::save(['amount' => '0'] + $base, null, 'water.share_capital_billing'), 'fixed amount', 'no amount');
    refuses(fn() => ShareCapitalBilling::save(['posting_day' => 32] + $base, null, 'water.share_capital_billing'), 'posting day', 'day 32');
    refuses(fn() => ShareCapitalBilling::save(['frequency' => 'weekly'] + $base, null, 'water.share_capital_billing'), 'frequency', 'a frequency not on the list');
    refuses(fn() => ShareCapitalBilling::save(['start_date' => '2030-02-30'] + $base, null, 'water.share_capital_billing'), 'start date', 'not a date');
    $id = ShareCapitalBilling::save($base, null, 'water.share_capital_billing');
    refuses(fn() => ShareCapitalBilling::save($base, null, 'water.share_capital_billing'), 'already on fixed billing', 'a second billing of the same account');
    eq(ShareCapitalBilling::plan($id)['next_bill_date'], '2030-01-01', 'the first posting date');
    $eligible = ShareCapitalBilling::eligibleMembers();
    check(isset($eligible[$a['member']]) && in_array($a['sc'], array_map(fn($x) => (int)$x['id'], $eligible[$a['member']]['accounts']), true), 'the member and the account are offered');
    eq(array_map(fn($w) => (int)$w['pkey'], $eligible[$a['member']]['water_accounts']), [942001], 'with the water-service account of the same party (where the cashier collects)');
    eq($eligible[$other['member']]['water_accounts'], [], 'a member with no water-service account: none (the page warns)');
    DB::run("UPDATE sav_accounts SET status = 'closed' WHERE id = :a", ['a' => $other['sc']]);
    check(!isset(ShareCapitalBilling::eligibleMembers()[$other['member']]), 'a member whose share capital account is closed is not offered');
    ShareCapitalBilling::delete($id, 'Test done', 'water.share_capital_billing');
});

test('Fixed Share Capital Billing: the bills are posted on their posting day with NO journal entry, once only, and the past days of a new billing at once', function () {
    $a = v42Member('James', 'Lebron', 942002);
    $txns = (int)DB::value('SELECT COUNT(*) FROM transactions');
    $sav = (int)DB::value('SELECT COUNT(*) FROM sav_txns');
    $y = (int)date('Y') + 2;
    $id = v42Save($a, 'monthly', "$y-01-31", 31, '50');
    eq(v42Bills($id), [], 'nothing before the first posting day');
    eq(ShareCapitalBilling::postDue("$y-04-30", [$id]), 4, 'up to April 30: four bills');
    eq(array_map(fn($b) => [$b['bill_date'], $b['amount'], $b['status']], v42Bills($id)),
        [["$y-01-31", '50.00', 'unpaid'], [ShareCapitalBilling::dayIn("$y-02-01", 31), '50.00', 'unpaid'], ["$y-03-31", '50.00', 'unpaid'], ["$y-04-30", '50.00', 'unpaid']], 'on the 31st, the last day of a shorter month');
    eq(ShareCapitalBilling::postDue("$y-04-30", [$id]), 0, 'run again: nothing more (one bill per posting date)');
    eq(ShareCapitalBilling::postDue("$y-04-30"), 0, 'every billing: nothing more either');
    eq([(int)DB::value('SELECT COUNT(*) FROM transactions'), (int)DB::value('SELECT COUNT(*) FROM sav_txns')], [$txns, $sav], 'no journal entry and no savings row for a bill');
    eq(ShareCapitalBilling::plan($id)['next_bill_date'], "$y-05-31", 'the next posting date');
    // a billing started in the past: the contribution expected since the start date is posted at once
    $b = v42Member('Past', 'Starter', 0);
    $start = date('Y-m-01', strtotime('-2 months'));
    $pid = v42Save($b, 'monthly', $start, 1, '25');
    eq(count(v42Bills($pid)), 3, 'two months back, day 1: three bills (two past months and this one)');
    eq((int)DB::value('SELECT COUNT(*) FROM transactions'), $txns, 'still no journal entry');
    ShareCapitalBilling::delete($pid, 'Test done', 'water.share_capital_billing');
    $GLOBALS['v42'] = ['lebron' => $a, 'lebron_plan' => $id, 'y' => $y];
});

test('Fixed Share Capital Billing: hold stops the bills (the days on hold are not billed), resume starts again, an edit never bills a month twice, delete cancels the unpaid bills', function () {
    ['lebron_plan' => $id, 'y' => $y] = $GLOBALS['v42'];
    ShareCapitalBilling::hold($id, 'Member asked to pause', 'water.share_capital_billing');
    eq(ShareCapitalBilling::plan($id)['status'], 'on_hold', 'on hold');
    eq(ShareCapitalBilling::postDue("$y-08-31", [$id]), 0, 'on hold: nothing is posted');
    refuses(fn() => ShareCapitalBilling::hold($id, '', 'water.share_capital_billing'), 'already on hold', 'held twice');
    $next = ShareCapitalBilling::resume($id, 'water.share_capital_billing');
    eq($next, "$y-05-31", 'resumed: the next posting day after the last bill (the start is still ahead)');
    refuses(fn() => ShareCapitalBilling::resume($id, 'water.share_capital_billing'), 'not on hold', 'resumed twice');
    eq(ShareCapitalBilling::postDue("$y-05-31", [$id]), 1, 'May 31 posted');
    // an edit of the schedule: from the month after the last bill, on the new day; the bills posted keep their amount
    $who = $GLOBALS['v42']['lebron'];
    v42Save($who, 'monthly', "$y-01-31", 15, '75', $id);
    eq(ShareCapitalBilling::plan($id)['next_bill_date'], "$y-06-15", 'day 15 from June (May was billed on the 31st)');
    v42Save($who, 'quarterly', "$y-01-31", 15, '200', $id);
    eq(ShareCapitalBilling::plan($id)['next_bill_date'], "$y-08-15", 'quarterly from the first posting day (Feb 15, the 15th on or after Jan 31: Feb, May, Aug), after May');
    eq(array_unique(array_column(v42Bills($id), 'amount')), ['50.00'], 'the bills already posted keep their amount');
    eq(ShareCapitalBilling::postDue("$y-08-15", [$id]), 1, 'August 15 posted');
    eq(DB::value('SELECT amount FROM share_capital_bills WHERE plan_id = :p AND bill_date = :d', ['p' => $id, 'd' => "$y-08-15"]), '200.00', 'the new amount from then on');
    refuses(fn() => ShareCapitalBilling::delete($id, '', 'water.share_capital_billing'), 'reason', 'a delete needs a reason');
});

test('Fixed Share Capital Billing at the water cashier: the bill is a receivable of the member\'s water account; the collection deposits to share capital by the code of the page (CSD), journal and passbook', function () {
    $a = v42Member('Bond', 'Jamie', 942003);
    $start = date('Y-m-01', strtotime('-1 month'));
    $id = v42Save($a, 'monthly', $start, 1, '50');
    $bills = v42Bills($id);
    eq(count($bills), 2, 'last month and this month posted');
    $open = ShareCapitalBilling::openBillsForWaterAccount($a['water']);
    eq(array_map(fn($b) => (int)$b['bill_id'], $open), array_map(fn($b) => (int)$b['bill_id'], $bills), 'both open on the member\'s water-service account');
    check(ShareCapitalBilling::billForWaterAccount((int)$bills[0]['bill_id'], 942001) === null, 'not on another member\'s water account');
    $balance = (string)DB::value('SELECT balance FROM sav_accounts WHERE id = :a', ['a' => $a['sc']]);
    $liab = Savings::ledgerAccount(Savings::account($a['sc']));
    $glBefore = gl($liab);
    $cash = $GLOBALS['wt']['cash'];
    $cashBefore = gl($cash);
    $water0 = (int)DB::value("SELECT COUNT(*) FROM water_postings");
    // the product's minimum deposit (100.00) does not apply to the contribution billed (50.00)
    $r = wt_collect($a['water'], [['type' => 'sharecap', 'id' => (int)$bills[0]['bill_id'], 'payment_amount' => '50.00']], today());
    $pt = DB::row('SELECT * FROM payment_transactions WHERE receipt_no = :r', ['r' => $r['receipt_no']]);
    eq([$pt['payment_type'], $pt['base_amount'], $pt['share_capital_amount'], $pt['total_paid']], ['sharecap', '0.00', '50.00', '50.00'], 'the receipt: share capital only');
    check(trim((string)$pt['journal_head_id']) === '', 'no water journal for a receipt of share capital only');
    eq((int)DB::value("SELECT COUNT(*) FROM water_postings"), $water0, 'nothing in the water postings');
    $col = DB::row('SELECT * FROM share_capital_collections WHERE receipt_no = :r', ['r' => $r['receipt_no']]);
    eq([(int)$col['transaction_id'], (int)$col['bill_id'], $col['amount'], $col['status']], [(int)$pt['transaction_id'], (int)$bills[0]['bill_id'], '50.00', 'active'], 'the collection linked to the receipt');
    $sv = DB::row('SELECT * FROM sav_txns WHERE id = :i', ['i' => $col['sav_txn_id']]);
    eq([$sv['txn_code'], $sv['credit'], $sv['or_no'], $sv['ref_type'], (int)$sv['pb_printed'], $sv['trans_no']], ['CSD', '50.00', $r['receipt_no'], 'sc_bill', 0, $col['trans_no']],
        'on the Savings Account Transaction: CSD, the receipt as OR, numbered in the savings series, waiting for the passbook');
    eqm(DB::value('SELECT balance FROM sav_accounts WHERE id = :a', ['a' => $a['sc']]), bcadd($balance, '50', 2), 'the share capital balance');
    eqm(gl($liab), bcsub($glBefore, '50', 2), 'Cr share capital in the books (debit less credit)');
    eqm(gl($cash), bcadd($cashBefore, '50', 2), 'Dr the cashier\'s cash (CSD is a "User Account" code)');
    eq(DB::value('SELECT txn_type FROM transactions WHERE id = :t', ['t' => $col['ledger_txn_id']]), 'coop_sv', 'a savings transaction document');
    eq(DB::row('SELECT amount_paid, status FROM share_capital_bills WHERE bill_id = :b', ['b' => $bills[0]['bill_id']]), ['amount_paid' => '50.00', 'status' => 'paid'], 'the bill paid');
    assertLedgerBalanced('after a share capital collection');
    assertSlGl('after a share capital collection');
    // part of the second bill with a water bill on one receipt
    [$reading, $due] = wt_bill($a['water'], 12, today());
    $r2 = wt_collect($a['water'], [['type' => 'water', 'id' => $reading, 'payment_amount' => $due], ['type' => 'sharecap', 'id' => (int)$bills[1]['bill_id'], 'payment_amount' => '20.00']], today());
    $pt2 = DB::row('SELECT * FROM payment_transactions WHERE receipt_no = :r', ['r' => $r2['receipt_no']]);
    eq([$pt2['payment_type'], $pt2['share_capital_amount']], ['mixed', '20.00'], 'a mixed receipt');
    eqm($pt2['base_amount'], $due, 'its bills paid: the water bill');
    eqm($pt2['total_paid'], bcadd((string)$due, '20', 2), 'the total: both');
    check(trim((string)$pt2['journal_head_id']) !== '', 'the water journal for the water bill');
    eq(DB::row('SELECT amount_paid, status FROM share_capital_bills WHERE bill_id = :b', ['b' => $bills[1]['bill_id']]), ['amount_paid' => '20.00', 'status' => 'partial'], 'the second bill partly paid');
    try {
        wt_collect($a['water'], [['type' => 'sharecap', 'id' => (int)$bills[1]['bill_id'], 'payment_amount' => '40.00']], today());
        check(false, 'more than the balance (30.00) was accepted');
    } catch (Exception $e) {
        check(str_contains($e->getMessage(), 'exceeds its current balance of 30.00'), 'more than the balance (30.00) is refused: ' . $e->getMessage());
    }
    assertLedgerBalanced('after a mixed receipt');
    // the official receipt prints it under its own code name (not the bill code SHARE CAPITAL)
    $labels = array_column(cr_fetch_receipt(DB::pdo(), $r2['receipt_no'])['official_receipt_services'] ?? [], 'description');
    check(in_array('FIXED SHARE CAPITAL', $labels, true), 'the receipt line FIXED SHARE CAPITAL (' . implode(', ', $labels) . ')');
    $GLOBALS['v42']['cashier'] = ['who' => $a, 'plan' => $id, 'bills' => $bills, 'r1' => $r['receipt_no'], 'r2' => $r2['receipt_no'], 'due' => $due];
});

test('Fixed Share Capital Billing: a receipt reversed (error correction) takes the deposit back with ECSD and its journal entry, and the bill is open again', function () {
    ['who' => $a, 'bills' => $bills, 'r2' => $r2] = $GLOBALS['v42']['cashier'];
    $balance = (string)DB::value('SELECT balance FROM sav_accounts WHERE id = :a', ['a' => $a['sc']]);
    $liab = Savings::ledgerAccount(Savings::account($a['sc']));
    $glBefore = gl($liab);
    ps_reverse_or_refund_payment(DB::pdo(), $r2, 'reversal', 'Wrong member keyed (test)', today(), (int)$GLOBALS['adminId']);
    $col = DB::row('SELECT * FROM share_capital_collections WHERE receipt_no = :r', ['r' => $r2]);
    eq($col['status'], 'reversed', 'the collection reversed');
    $ec = DB::row('SELECT * FROM sav_txns WHERE id = :i', ['i' => $col['reversal_sav_txn_id']]);
    eq([$ec['txn_code'], $ec['credit'], (int)$ec['corrects_id']], ['ECSD', '-20.00', (int)$col['sav_txn_id']], 'ECSD negates the deposit');
    eq((int)DB::value('SELECT corrected_by_id FROM sav_txns WHERE id = :i', ['i' => $col['sav_txn_id']]), (int)$ec['id'], 'the deposit marked corrected');
    eqm(DB::value('SELECT balance FROM sav_accounts WHERE id = :a', ['a' => $a['sc']]), bcsub($balance, '20', 2), 'the share capital balance back');
    eqm(gl($liab), bcadd($glBefore, '20', 2), 'Dr share capital in the books (debit less credit)');
    eq(DB::row('SELECT amount_paid, status FROM share_capital_bills WHERE bill_id = :b', ['b' => $bills[1]['bill_id']]), ['amount_paid' => '0.00', 'status' => 'unpaid'], 'the bill unpaid again');
    eq(DB::value('SELECT transaction_status FROM payment_transactions WHERE receipt_no = :r', ['r' => $r2]), 'reversed', 'the receipt reversed');
    assertLedgerBalanced('after the reversal');
    assertSlGl('after the reversal');
    // deleting the billing cancels its unpaid bill; the paid one stays
    $n = ShareCapitalBilling::delete($GLOBALS['v42']['cashier']['plan'], 'Left the program (test)', 'water.share_capital_billing');
    eq($n, 1, 'one unpaid bill cancelled');
    eq(array_column(v42Bills($GLOBALS['v42']['cashier']['plan']), 'status'), ['paid', 'cancelled'], 'the paid bill kept, the unpaid cancelled');
    eq(ShareCapitalBilling::openBillsForWaterAccount($a['water']), [], 'nothing left at the cashier');
});

test('Fixed Share Capital Billing guards: an edit never back-bills, a billing added again never bills a month twice, a closed account is not billed, the import codes are not offered', function () {
    $a = v42Member('Guard', 'Gina', 942004);
    // semi-annually from five months back: one bill (that month), the next one six months after it
    $start = date('Y-m-01', strtotime(date('Y-m-01') . ' -5 months'));
    $id = v42Save($a, 'semi_annual', $start, 1, '300');
    eq(array_column(v42Bills($id), 'bill_date'), [$start], 'one semi-annual bill five months back');
    // changed to monthly: the months the semi-annual bill covered are not billed (nothing before today)
    v42Save($a, 'monthly', $start, 1, '50', $id);
    $after = array_column(v42Bills($id), 'bill_date');
    check(count(array_filter($after, fn($d) => $d !== $start && $d < today())) === 0, 'no bill between the last bill and today (' . implode(', ', $after) . ')');
    eq(ShareCapitalBilling::plan($id)['next_bill_date'], date('Y-m-01', strtotime($start . ' +6 months')), 'monthly from the month the semi-annual bill does not cover (six months after it)');
    // deleted and added again from the same start: the months already billed are not billed again
    $paidMonth = $start;
    ShareCapitalBilling::delete($id, 'Re-added (test)', 'water.share_capital_billing');
    DB::run("UPDATE share_capital_bills SET status = 'paid', amount_paid = amount WHERE plan_id = :p AND bill_date = :d", ['p' => $id, 'd' => $paidMonth]);
    $id2 = v42Save($a, 'monthly', $start, 1, '50');
    $months = DB::column("SELECT DATE_FORMAT(bill_date, '%Y-%m') FROM share_capital_bills WHERE sav_account_id = :a AND status <> 'cancelled'", ['a' => $a['sc']]);
    eq(count($months), count(array_unique($months)), 'each month billed once for the account (' . implode(', ', $months) . ')');
    check(!in_array($paidMonth, array_column(v42Bills($id2), 'bill_date'), true), 'the month paid under the deleted billing is not billed again');
    // a closed account (or a member who left) is not billed; its days pass unbilled
    $y = (int)date('Y') + 3;
    $b = v42Member('Closed', 'Carlo', 0);
    $id3 = v42Save($b, 'monthly', "$y-01-01", 1, '50');
    DB::run("UPDATE sav_accounts SET status = 'closed' WHERE id = :a", ['a' => $b['sc']]);
    eq(ShareCapitalBilling::postDue("$y-03-31", [$id3]), 0, 'a closed account: nothing posted');
    eq(ShareCapitalBilling::plan($id3)['next_bill_date'], "$y-04-01", 'its days passed unbilled');
    // the collection codes: cash deposits, never a code that debits equity (the import codes)
    $codes = ShareCapitalBilling::codes();
    check(isset($codes['CSD']) && !isset($codes['INI']) && !isset($codes['MIG']), 'CSD offered, INI and MIG not (' . implode(', ', array_keys($codes)) . ')');
    refuses(fn() => ShareCapitalBilling::saveSettings('INI', null, 'water.share_capital_billing'), 'deposit code', 'INI refused as the collection code');
});

test('Fixed Share Capital Billing: the deposit of a water receipt is corrected only through the receipt; a pledged balance does not block it', function () {
    $a = v42Member('Pledge', 'Paolo', 942005);
    $id = v42Save($a, 'monthly', date('Y-m-01'), 1, '50');
    $bill = v42Bills($id)[0];
    // a share capital bill is a contribution, never overdue: no penalty hint at the cashier
    $q = ps_build_quote(DB::pdo(), $a['water'], [['type' => 'sharecap', 'id' => (int)$bill['bill_id'], 'payment_amount' => '50.00']], date('Y-m-d', strtotime(today() . ' +0 day')));
    check(empty($q['has_overdue_receivable']) && (int)($q['penalty_eligible_count'] ?? 0) === 0, 'the bill is not overdue and no penalty is suggested');
    $r = wt_collect($a['water'], [['type' => 'sharecap', 'id' => (int)$bill['bill_id'], 'payment_amount' => '50.00']], today());
    $col = DB::row('SELECT * FROM share_capital_collections WHERE receipt_no = :r', ['r' => $r['receipt_no']]);
    refuses(fn() => SavTxn::delete((int)$col['sav_txn_id'], 'Keyed twice', 'sav.center'), 'Collection Corrections', 'deleting the deposit in the savings module');
    refuses(fn() => Txn::void((int)$col['ledger_txn_id'], 'Keyed twice'), 'Collection Corrections', 'voiding its savings document');
    refuses(fn() => SavTxn::post(['account_id' => $a['sc'], 'code' => 'ECSD', 'amount' => '50', 'date' => today(), 'corrects_id' => (int)$col['sav_txn_id']], 'sav.center'), 'Collection Corrections', 'an error correction in the savings module');
    check(!in_array((int)$col['sav_txn_id'], array_map(fn($x) => (int)$x['id'], SavTxn::correctable($a['sc'])), true), 'not offered for error correction in the savings module');
    // the whole balance pledged (on hold): the receipt is still reversed
    DB::run('UPDATE sav_accounts SET hold_amount = balance WHERE id = :a', ['a' => $a['sc']]);
    ps_reverse_or_refund_payment(DB::pdo(), $r['receipt_no'], 'reversal', 'Wrong member keyed (test)', today(), (int)$GLOBALS['adminId']);
    eq(DB::value('SELECT status FROM share_capital_collections WHERE collection_id = :c', ['c' => $col['collection_id']]), 'reversed', 'reversed with the balance pledged');
    DB::run('UPDATE sav_accounts SET hold_amount = 0 WHERE id = :a', ['a' => $a['sc']]);
    assertLedgerBalanced('after the guarded reversal');
});

test('Collection Summary: Summary shows the share capital beside the bills paid; Detailed a column for each code name collected, never put together', function () {
    ['r1' => $r1, 'r2' => $r2, 'due' => $due] = $GLOBALS['v42']['cashier'];
    $run = function (array $more) {
        $def = Report::get('water_collection_summary');
        $saved = $_GET;
        $_GET = ['dates' => 'custom', 'from' => today(), 'to' => today(), 'pstatus' => 'all'] + $more;
        $out = ($def['run'])(Report::filters($def));
        $_GET = $saved;
        return $out;
    };
    $sum = $run([]);
    $keys = array_column($sum['columns'], 'key');
    check(in_array('share', $keys, true) && in_array('base', $keys, true), 'Summary: Bills paid and Share capital (' . implode(', ', $keys) . ')');
    $row = array_values(array_filter($sum['rows'], fn($r) => ($r['receipt'] ?? '') === $r1))[0];
    eq([$row['base'], $row['share'], $row['total'], $row['ptype']], ['0.00', '50.00', '50.00', 'Share capital'], 'the receipt of share capital only');
    check(str_starts_with($row['ledger'], 'Share capital deposit #'), 'its ledger document: the share capital deposit');
    $det = $run(['rtype' => 'detailed']);
    $labels = array_column($det['columns'], 'label', 'key');
    $codeKeys = array_keys(array_filter($labels, fn($l) => strtoupper($l) === $l && $l !== ''));
    check(in_array('WATER BILL', $labels, true) && in_array('FIXED SHARE CAPITAL', $labels, true), 'Detailed: WATER BILL and FIXED SHARE CAPITAL columns (' . implode(', ', $labels) . ')');
    check(!in_array('Bills paid', $labels, true), 'no Bills paid column in Detailed');
    $wk = array_search('WATER BILL', $labels, true);
    $sk = array_search('FIXED SHARE CAPITAL', $labels, true);
    $d2 = array_values(array_filter($det['rows'], fn($r) => ($r['receipt'] ?? '') === $r2))[0];
    eqm($d2[$wk], $due, 'the reversed mixed receipt (receipt status All): its water bill under WATER BILL');
    eqm($d2[$sk], '20', 'its share capital under FIXED SHARE CAPITAL');
    foreach ($det['rows'] as $r) {
        if (!empty($r['_class'])) {
            continue;
        }
        $sumCodes = '0';
        foreach ($codeKeys as $k) {
            $sumCodes = bcadd($sumCodes, (string)$r[$k], 2);
        }
        eqm(bcadd(bcadd($sumCodes, (string)$r['penalty'], 2), (string)$r['compromise'], 2), $r['total'], 'receipt ' . $r['receipt'] . ': the code names, penalty and compromise add up to the total');
    }
    eqm($det['totals']['total'], $sum['totals']['total'], 'the same total collected in Summary and Detailed');
    $byDate = $run(['rtype' => 'detailed', 'view' => 'date']);
    eqm($byDate['totals'][$sk], $det['totals'][$sk], 'by date: the share capital column summed as on the receipts');
    eqm($byDate['totals'][$wk], $det['totals'][$wk], 'by date: the water bill column summed as on the receipts');
    $sumBy = $run(['view' => 'ptype']);
    check(in_array('Share capital', array_column($sumBy['rows'], 'who'), true), 'by payment type: a Share capital line');
});

test('Migration 2027_03_14: the three tables, the receipt column and types, the page after One-Time Billing with its rights (run twice)', function () {
    foreach (['share_capital_plans', 'share_capital_bills', 'share_capital_collections'] as $t) {
        eq((int)DB::value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t', ['t' => $t]), 1, $t);
    }
    check(str_contains((string)DB::value("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payment_transactions' AND column_name = 'payment_type'"), "'sharecap'"), 'payment type sharecap');
    check(str_contains((string)DB::value("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payment_transaction_items' AND column_name = 'source_type'"), "'sharecap'"), 'item source sharecap');
    eq(DB::value("SELECT column_default FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payment_transactions' AND column_name = 'share_capital_amount'"), '0.00', 'share_capital_amount, 0.00 by default');
    $page = DB::row("SELECT p.*, o.sort_order AS otb_sort, o.module_id AS otb_module FROM pages p JOIN pages o ON o.page_key = 'water.one_time_billing' WHERE p.page_key = 'water.share_capital_billing'");
    eq([$page['name'], $page['menu_group'], (int)$page['module_id'] === (int)$page['otb_module']], ['Fixed Share Capital Billing', 'Billing', true], 'the page, in Water Billing > Billing');
    // right after One-Time Billing in the menu (the migration puts it 5 after it; a new installation numbers the pages in their order)
    check((int)$page['sort_order'] > (int)$page['otb_sort'] && (int)DB::value('SELECT COUNT(*) FROM pages WHERE module_id = :m AND sort_order > :a AND sort_order < :b',
        ['m' => $page['module_id'], 'a' => $page['otb_sort'], 'b' => $page['sort_order']]) === 0, 'right after One-Time Billing (' . $page['otb_sort'] . ', ' . $page['sort_order'] . ')');
    eq((int)DB::value("SELECT COUNT(*) FROM role_page_access r JOIN pages p ON p.id = r.page_id WHERE p.page_key = 'water.share_capital_billing'"),
        (int)DB::value("SELECT COUNT(*) FROM role_page_access r JOIN pages p ON p.id = r.page_id WHERE p.page_key = 'water.one_time_billing'"), 'the roles of One-Time Billing');
    eq(isset(WaterPages::PAGES['share_capital_billing']), true, 'in the water page list');
    eq(WaterPages::processPage('saveShareCapitalBilling'), 'share_capital_billing', 'its endpoint mapped to it');
    $before = DB::value('SELECT COUNT(*) FROM pages') . '/' . DB::value('SELECT COUNT(*) FROM role_page_access') . '/' . DB::value('SELECT COUNT(*) FROM share_capital_plans');
    run_sql_file(DB::pdo(), APP_ROOT . '/database/migrations/2027_03_14_share_capital_billing.sql');
    eq(DB::value('SELECT COUNT(*) FROM pages') . '/' . DB::value('SELECT COUNT(*) FROM role_page_access') . '/' . DB::value('SELECT COUNT(*) FROM share_capital_plans'), $before, 'a second run changes nothing');
    Schema::flush();
    eq(Schema::pending(), [], 'no update pending');
});
