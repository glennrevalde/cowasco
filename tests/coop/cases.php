<?php
declare(strict_types=1);

/**
 * The test cases (included by run.php, which provides test(), check(), eqm(), eq(), refuses(), gl(), the fixtures in
 * $ids and the helpers newMember(), openAccount(), shift(), receipt(), asUser()).
 *
 * Dates: the cases record back-dated business as the administrator (who may back-date); teller cases use today.
 */

// the administrator tells from a drawer of their own, so the tellers' drawers stay free
$adminCash = coop_fx_new_account('1009', 'Cash on Hand - Accounting Office', 'Bank', 'Asset', 'Debit');
DB::update('users', ['coop_cash_account_id' => $adminCash], 'id = :id', ['id' => $adminId]);
$GLOBALS['adminCash'] = $adminCash;
$today = today();

// =============================================================================================== 1. decimal engine
test('Decimal engine: exact sums, half-away-from-zero rounding, the reference ISC / PR figures', function () {
    eq(Dec::add('0.1', '0.2'), '0.3', '0.1 + 0.2');
    eq(Dec::round('2.675', 2), '2.68', 'round 2.675 (a float gives 2.67)');
    eq(Dec::round('-2.675', 2), '-2.68', 'round -2.675');
    eq(Dec::fix('1250.5'), '1250.50', 'fix');
    eq(Dec::money('-1234567.891'), '(1,234,567.89)', 'money format');
    eq(Dec::sum(array_fill(0, 10000, '0.01')), '100', 'ten thousand centavos');
    eq(Dec::pct('1759.44', '9'), '158.35', 'ISC: ADB 1,759.44 x 9 % (reference workbook)');
    eq(Dec::pct('2697.03', '6'), '161.82', 'PR: income 2,697.03 x 6 % (reference workbook)');
    eq(Dec::div('2', '3', 4), '0.6667', 'division rounds half up at the scale');
    refuses(fn() => Dec::div('1', '0'), 'Division by zero', 'division by zero');
    refuses(fn() => Dec::of('12abc'), 'Not a number', 'non-numeric input');
    // the pure-PHP engine gives exactly the bcmath results
    $samples = [['20000', '0.015'], ['1833.5999', '12'], ['-75.125', '3.3333'], ['999999999.99', '0.000001'], ['0.1', '-0.2'], ['123456789.123456', '987.654321']];
    $bc = [];
    foreach ($samples as [$a, $b]) {
        $bc[] = [Dec::add($a, $b), Dec::sub($a, $b), Dec::mul($a, $b, 6), Dec::div($a, $b, 6), Dec::round($a, 2), Dec::cmp($a, $b)];
    }
    $was = Dec::$forcePhp;
    Dec::$forcePhp = true;
    foreach ($samples as $i => [$a, $b]) {
        $php = [Dec::add($a, $b), Dec::sub($a, $b), Dec::mul($a, $b, 6), Dec::div($a, $b, 6), Dec::round($a, 2), Dec::cmp($a, $b)];
        eq($php, $bc[$i], "pure PHP = bcmath for $a, $b");
    }
    Dec::$forcePhp = $was;
});

// =============================================================================================== 2. amortization
test('Amortization: equal amortization 20,000 at 18 % for 12 months gives 1,833.60 (reference workbook)', function () {
    $s = Loans::schedule('20000', '18', '12', 'months', 'monthly', 'diminishing', 'equal_amortization', '2026-02-15');
    eq(count($s['rows']), 12, 'instalments');
    eqm($s['rows'][0]['total'], '1833.60', 'monthly amortization');
    eqm($s['rows'][0]['interest'], '300.00', 'first month interest 20,000 x 1.5 %');
    eqm($s['rows'][0]['principal'], '1533.60', 'first month principal');
    // independent check of the annuity with bcmath: PMT = P i / (1 - (1+i)^-n)
    $i = '0.015';
    $f = bcpow('1.015', '12', 20);
    $pmt = bcdiv(bcmul('20000', $i, 20), bcsub('1', bcdiv('1', $f, 20), 20), 20);
    eqm($s['rows'][5]['total'], r2s($pmt), 'PMT by bcmath');
    $sumP = '0';
    $sumI = '0';
    foreach ($s['rows'] as $r) {
        $sumP = bcadd($sumP, $r['principal'], 2);
        $sumI = bcadd($sumI, $r['interest'], 2);
    }
    eqm($sumP, '20000', 'principal repaid in full');
    eqm($sumI, $s['total_interest'], 'total interest = sum of the rows');
    eqm($s['total_interest'], '2003.20', 'total interest (reference workbook: 2,003.20)');
    eqm($s['rows'][11]['balance_after'], '0', 'nothing left after the last instalment');
    eq($s['rows'][0]['due_date'], '2026-02-15', 'first due date');
    eq($s['rows'][11]['due_date'], '2027-01-15', 'last due date');
    eq(Coop::addMonths('2026-01-31', 1), '2026-02-28', 'month-end due dates stay in the month');
});

test('Amortization: fixed principal, straight add-on, lump sum and daily modes', function () {
    $s = Loans::schedule('12000', '12', '12', 'months', 'monthly', 'diminishing', 'equal_principal', '2026-02-01');
    eqm($s['rows'][0]['principal'], '1000', 'equal principal');
    eqm($s['rows'][0]['interest'], '120', 'interest on 12,000 at 1 %');
    eqm($s['rows'][11]['interest'], '10', 'interest on the last 1,000');
    eqm($s['total_interest'], '780', 'total interest 120 + 110 + ... + 10');
    $s = Loans::schedule('10000', '12', '6', 'months', 'semi_monthly', 'straight', 'equal_amortization', '2026-02-15');
    eq(count($s['rows']), 12, 'semi-monthly instalments over 6 months');
    eqm($s['total_interest'], '600', 'add-on interest 10,000 x 12 % x 6/12');
    eqm($s['rows'][0]['total'], bcadd('833.33', '50', 2), 'first semi-monthly amortization');
    $sumP = array_reduce($s['rows'], fn($c, $r) => bcadd($c, $r['principal'], 2), '0');
    eqm($sumP, '10000', 'rounding difference goes to the last instalment');
    eq($s['rows'][1]['due_date'], '2026-03-02', 'semi-monthly = every 15 days');
    $s = Loans::schedule('10000', '12', '90', 'days', 'lump_sum', 'diminishing', 'equal_amortization', '2026-03-01');
    eq(count($s['rows']), 1, 'lump sum: one payment');
    eqm($s['total_interest'], r2s(bcdiv(bcmul('10000', bcmul('0.12', '90', 10), 10), '365', 10)), 'lump sum interest = P x r x 90 / 365');
    eq($s['rows'][0]['due_date'], '2026-05-30', 'due 90 days later');
    eq(Coop::installments('daily', '30', 'days'), 30, 'daily: thirty payments in thirty days');
    eq(Coop::installments('weekly', '3', 'months'), 13, 'weekly over three months');
});

// =============================================================================================== 3. members
test('Members: profile, duplicate check, status workflow, history and audit trail', function () {
    global $ids;
    $d = ['member_kind' => 'individual', 'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'Santos', 'suffix' => '', 'branch_id' => $ids['branch.HO'],
        'member_type_id' => (int)DB::value("SELECT id FROM member_types WHERE code = 'REG'"), 'birth_date' => '1980-05-20', 'membership_date' => null];
    $id = Members::save($d, ['contacts' => [['contact_type' => 'mobile', 'country_code' => '63', 'number' => '09171234567', 'is_primary' => 1]],
        'beneficiaries' => [['name' => 'Maria Dela Cruz', 'relationship' => 'Spouse', 'share_pct' => '100', 'is_primary' => 1]],
        'finances' => [['kind' => 'income', 'name' => 'Salary', 'member_amount' => '25000', 'spouse_amount' => '15000'], ['kind' => 'expense', 'name' => 'Household', 'member_amount' => '18000', 'spouse_amount' => '0']]], null, 'coop.member');
    $m = Coop::member($id);
    eq($m['full_name'], 'Dela Cruz, Juan S.', 'full name as the reference lists it');
    eq($m['status'], 'applicant', 'a new member is an applicant');
    eq($m['member_no'], '100001', 'member number from the sequence');
    eq($m['mobile'], '09171234567', 'mobile taken from the contacts tab');
    check((int)DB::value('SELECT member_id FROM other_names WHERE id = :o', ['o' => $m['other_name_id']]) === $id, 'the member is an Other Name of the ledger');
    eqm(Members::ndi($id)['ndi'], '22000', 'net disposable income 40,000 - 18,000');
    refuses(fn() => Members::save($d, [], null, 'coop.member'), 'already exists', 'same name and birth date');
    refuses(fn() => Members::changeStatus($id, 'resigned', '2026-01-10', 'test', '', 'coop.members'), 'cannot be made', 'applicant cannot resign');
    refuses(fn() => Members::changeStatus($id, 'active', '2026-01-10', 'Board resolution', '', 'coop.members'), 'below the minimum', 'activation needs the minimum share capital');
    refuses(fn() => Members::changeStatus($id, 'active', '2026-01-10', '', '', 'coop.members'), 'reason', 'a status change needs a reason');
    $_POST['waive_capital'] = '1';
    Members::changeStatus($id, 'active', '2026-01-10', 'Board resolution 2026-01', 'BR-2026-01', 'coop.members');
    $_POST = [];
    eq(Coop::member($id)['status'], 'active', 'activated');
    eq(Coop::member($id)['membership_date'], '2026-01-10', 'membership date set on activation');
    eq((int)DB::value('SELECT COUNT(*) FROM member_status_history WHERE member_id = :m', ['m' => $id]), 2, 'status history rows');
    check((bool)DB::value("SELECT id FROM audit_trail WHERE record_type = 'member' AND record_id = :m AND action = 'status_change'", ['m' => $id]), 'status change in the audit trail');
    Members::setHold($id, true, 'Court order', 'coop.members');
    refuses(fn() => Coop::assertTransactable(Coop::member($id)), 'on hold', 'a member on hold cannot transact');
    Members::setHold($id, false, '', 'coop.members');
    Coop::assertTransactable(Coop::member($id));
    check(true, 'released from hold');
    $GLOBALS['juan'] = $id;
});

// =============================================================================================== 4. teller receipt, savings, share capital
test('Official receipt: share capital and savings in one receipt, one balanced voucher, shift totals', function () {
    global $ids, $today;
    $m = newMember('Reyes', 'Ana', ['membership_date' => '2025-12-01']);
    $sc = openAccount($m, 'SC', $today);
    $rs = openAccount($m, 'RS', $today);
    eq(Savings::account($sc)['status'], 'active', 'opened and approved by a user who may approve');
    asUser('teller1');
    $shiftId = Teller::open($today, '5000', null, 'teller.shift');
    $r = receipt($m, $today, [['kind' => 'savings', 'account_id' => $sc, 'amount' => '1000'], ['kind' => 'savings', 'account_id' => $rs, 'amount' => '5000'],
        ['kind' => 'charge', 'account_id' => Accts::get('membership_fee_income'), 'amount' => '300', 'description' => 'Membership fee']], ['mode' => 'cash', 'cash_received' => '6500']);
    eqm($r['total'], '6300', 'receipt total');
    eqm($r['change'], '200', 'change');
    eq($r['receipt_no'], '000001', 'first OR number of the head office series');
    $t = Txn::load($r['txn_id']);
    eq($t['txn_type'], 'coop_or', 'posted as an official receipt');
    eq($t['txn_no'], '000001', 'voucher number = OR number');
    eqm(gl($ids['cash.HO']), '6300', 'teller cash debited');
    eqm(glMember(acct('3010'), $m), '-1000', 'share capital credited under the member');
    eqm(glMember(acct('2300'), $m), '-5000', 'savings credited under the member');
    eqm(gl(acct('4440')), '-300', 'membership fee income');
    eqm(Savings::account($rs)['balance'], '5000', 'savings ledger balance');
    eqm(Savings::account($sc)['balance'], '1000', 'share capital ledger balance');
    eq(DB::value('SELECT or_no FROM sav_txns WHERE account_id = :a', ['a' => $rs]), '000001', 'the savings row carries the OR number');
    eqm(Teller::shift($shiftId)['receipts_total'], '6300', 'shift receipts');
    refuses(fn() => receipt($m, $today, [['kind' => 'savings', 'account_id' => $rs, 'amount' => '100']], ['mode' => 'cash'], ['receipt_no' => '000001']), 'already used', 'an OR number is used once');
    refuses(fn() => receipt($m, Coop::addDays($today, -1), [['kind' => 'savings', 'account_id' => $rs, 'amount' => '100']]), 'Back-dated', 'a teller cannot back-date');
    refuses(fn() => receipt($m, $today, [['kind' => 'savings', 'account_id' => $rs, 'amount' => '10']]), 'minimum deposit', 'below the minimum deposit');
    $GLOBALS['ana'] = ['member' => $m, 'sc' => $sc, 'rs' => $rs, 'shift' => $shiftId];
    assertLedgerBalanced('after the receipt');
});

test('Savings withdrawal: available balance, minimum balance, supervisor approval for share capital', function () {
    global $ids, $today;
    ['member' => $m, 'sc' => $sc, 'rs' => $rs, 'shift' => $shiftId] = $GLOBALS['ana'];
    asUser('teller1');
    refuses(fn() => Teller::disburse($m, $today, [['kind' => 'savings', 'account_id' => $rs, 'amount' => '4950']], ['mode' => 'cash'], [], 'teller.disburse'), 'minimum balance', 'must leave the minimum balance of 100');
    $d = Teller::disburse($m, $today, [['kind' => 'savings', 'account_id' => $rs, 'amount' => '1000', 'description' => 'Cash withdrawal']], ['mode' => 'cash'], [], 'teller.disburse');
    eq(Txn::load($d['txn_id'])['txn_type'], 'coop_cv', 'posted as a member disbursement');
    eqm(Savings::account($rs)['balance'], '4000', 'balance after the withdrawal');
    eqm(gl($ids['cash.HO']), '5300', 'cash credited');
    eqm(Teller::shift($shiftId)['disbursements_total'], '1000', 'shift disbursements');
    refuses(fn() => Teller::disburse($m, $today, [['kind' => 'savings', 'account_id' => $sc, 'amount' => '200']], ['mode' => 'cash'], [], 'teller.disburse'), 'Supervisor approval is required', 'share capital withdrawal needs a supervisor');
    $_POST = ['approver_username' => 'manager', 'approver_password' => 'wrong'];
    refuses(fn() => Teller::disburse($m, $today, [['kind' => 'savings', 'account_id' => $sc, 'amount' => '200']], ['mode' => 'cash'], [], 'teller.disburse'), 'wrong', 'a wrong supervisor password');
    $_POST = ['approver_username' => 'manager', 'approver_password' => 'Demo@12345'];
    $d2 = Teller::disburse($m, $today, [['kind' => 'savings', 'account_id' => $sc, 'amount' => '200']], ['mode' => 'cash'], [], 'teller.disburse');
    $_POST = [];
    eq((int)Txn::load($d2['txn_id'])['approved_by'], $ids['user.gm'], 'the approving supervisor is on the voucher');
    eqm(Savings::account($sc)['balance'], '800', 'share capital after the approved withdrawal');
    check((bool)DB::value("SELECT id FROM audit_trail WHERE record_type = 'transaction' AND record_id = :t AND approved_by = 'manager'", ['t' => $d2['txn_id']]), 'approval in the audit trail');
    assertLedgerBalanced('after the withdrawals');
    assertSlGl('after the withdrawals');
});

test('Teller shift close: cash count by denomination, shortage posted with supervisor approval', function () {
    global $ids;
    ['shift' => $shiftId] = $GLOBALS['ana'];
    asUser('teller1');
    $s = Teller::shift($shiftId);
    $expected = Teller::expectedCash($s);
    eqm($expected, bcsub(bcadd('5000', '6300', 2), '1200', 2), 'expected cash = opening + receipts - disbursements');
    // counted: 10,050 = 50 short
    $denoms = ['1000' => 10, '50' => 1];
    refuses(fn() => Teller::close($shiftId, $denoms, 'end of day', 'teller.shift'), 'Supervisor approval', 'a shortage needs a supervisor');
    $_POST = ['approver_username' => 'manager', 'approver_password' => 'Demo@12345'];
    $res = Teller::close($shiftId, $denoms, 'end of day', 'teller.shift');
    $_POST = [];
    eqm($res['counted'], '10050', 'counted cash');
    eqm($res['over_short'], '-50', 'short 50');
    eqm(gl(Accts::get('cash_over_short')), '50', 'cash short expensed');
    eqm(gl($ids['cash.HO']), '5050', 'drawer account moved by receipts - disbursements - shortage (the opening cash was already in it)');
    eq(Teller::shift($shiftId)['status'], 'closed', 'shift closed');
    refuses(fn() => receipt($GLOBALS['ana']['member'], today(), [['kind' => 'savings', 'account_id' => $GLOBALS['ana']['rs'], 'amount' => '100']]), 'Open your teller shift', 'no receipt without an open shift');
});

// =============================================================================================== 5. checks, transfers
test('Check deposit: held until cleared, cleared to the bank, a returned check reverses it', function () {
    global $ids, $adminCash;
    $m = newMember('Garcia', 'Ben');
    $rs = openAccount($m, 'RS', '2026-03-01');
    shift('2026-03-02');
    receipt($m, '2026-03-02', [['kind' => 'savings', 'account_id' => $rs, 'amount' => '2000']]);
    receipt($m, '2026-03-02', [['kind' => 'savings', 'account_id' => $rs, 'amount' => '10000']], ['mode' => 'check', 'cheques' => [['cheque_no' => '0012345', 'bank' => 'Land Bank', 'amount' => '10000']]]);
    $a = Savings::account($rs);
    eqm($a['balance'], '12000', 'ledger balance includes the check');
    eqm($a['uncleared'], '10000', 'the check is uncleared');
    eqm(Savings::available($a), '2000', 'available balance excludes it');
    eqm(gl(acct('1110')), '10000', 'checks and other cash items');
    eq(DB::value("SELECT clearing_date FROM sav_txns WHERE account_id = :a AND is_cleared = 0", ['a' => $rs]), '2026-03-06', 'clears after 4 days (code CK4)');
    refuses(fn() => Savings::withdraw($rs, 'CSW', '3000', '2026-03-03', $adminCash), 'Insufficient', 'cannot withdraw the uncleared check');
    $chk = (int)DB::value('SELECT id FROM sav_txns WHERE account_id = :a AND is_cleared = 0', ['a' => $rs]);
    Savings::clearCheck($chk, $ids['bank'], '2026-03-06');
    eqm(Savings::account($rs)['uncleared'], '0', 'cleared');
    eqm(gl(acct('1110')), '0', 'COCI emptied');
    receipt($m, '2026-03-07', [['kind' => 'savings', 'account_id' => $rs, 'amount' => '5000']], ['mode' => 'check', 'cheques' => [['cheque_no' => '777', 'bank' => 'BDO', 'amount' => '5000']]]);
    $chk2 = (int)DB::value('SELECT id FROM sav_txns WHERE account_id = :a AND is_cleared = 0', ['a' => $rs]);
    Savings::returnCheck($chk2, '2026-03-10', 'Account closed');
    eqm(Savings::account($rs)['balance'], '12000', 'returned check taken back out');
    eq(DB::value("SELECT txn_code FROM sav_txns WHERE account_id = :a ORDER BY id DESC LIMIT 1", ['a' => $rs]), 'RTC', 'returned-check code');
    eqm(gl(acct('1110')), '0', 'COCI back to zero');
    $GLOBALS['ben'] = ['member' => $m, 'rs' => $rs];
    assertSlGl('after check clearing');
});

test('Transfer between two members: one journal voucher, both subsidiary ledgers', function () {
    global $ids;
    ['member' => $ben, 'rs' => $from] = $GLOBALS['ben'];
    $m2 = newMember('Lim', 'Carla');
    $to = openAccount($m2, 'RS', '2026-03-01');
    [$txnId] = Savings::transfer($from, $to, '2500', '2026-03-12', 'Family support');
    eq(Txn::load($txnId)['txn_type'], 'coop_jv', 'a cooperative journal voucher');
    eqm(Savings::account($from)['balance'], '9500', 'transferred out');
    eqm(Savings::account($to)['balance'], '2500', 'transferred in');
    eqm(glMember(acct('2300'), $ben), '-9500', 'GL under the first member');
    eqm(glMember(acct('2300'), $m2), '-2500', 'GL under the second member');
    refuses(fn() => Savings::transfer($from, $from, '1', '2026-03-12', ''), 'two different', 'same account');
    refuses(fn() => Savings::transfer($from, $to, '9450', '2026-03-12', ''), 'Insufficient', 'not beyond the available balance');
});

// =============================================================================================== 6. interest
test('Savings interest: average daily balance, rate tier, posting once, void restores the ledger', function () {
    $m = newMember('Mendoza', 'Celia');
    $rs = openAccount($m, 'RS', '2025-12-15');
    shift('2026-01-01');
    receipt($m, '2026-01-01', [['kind' => 'savings', 'account_id' => $rs, 'amount' => '8000']]);
    receipt($m, '2026-02-15', [['kind' => 'savings', 'account_id' => $rs, 'amount' => '4000']]);
    $b = Savings::balances($rs, '2026-01-01', '2026-03-31');
    // 8,000 for 45 days (Jan 1 - Feb 14) and 12,000 for 45 days (Feb 15 - Mar 31) = 10,000 average over 90 days
    eqm($b['adb'], '10000', 'average daily balance');
    eq($b['days'], 90, 'days in the period');
    eqm($b['lowest'], '8000', 'lowest balance');
    $prev = Savings::postInterest($GLOBALS['ids']['sav.RS'], '2026-01-01', '2026-03-31', '2026-03-31', false, 'sav.interest');
    $line = array_values(array_filter($prev['lines'], fn($l) => (int)$l['account']['id'] === $rs))[0] ?? null;
    check($line !== null, 'the account earns interest');
    eqm($line['rate'], '1.5', 'the 10,000-and-over tier (1.5 %)');
    $expected = r2s(bcdiv(bcmul(bcmul('10000', '1.5', 10), '90', 10), '36500', 10));
    eqm($line['interest'], $expected, 'interest = 10,000 x 1.5 % x 90 / 365');
    eqm($expected, '36.99', 'worked figure');
    $run = Savings::postInterest($GLOBALS['ids']['sav.RS'], '2026-01-01', '2026-03-31', '2026-03-31', true, 'sav.interest');
    eqm(Savings::account($rs)['balance'], bcadd('12000', $expected, 2), 'interest credited');
    eqm(gl(acct('7100')), $run['total_interest'], 'interest expense = total of the run');
    refuses(fn() => Savings::postInterest($GLOBALS['ids']['sav.RS'], '2026-01-01', '2026-03-31', '2026-03-31', true, 'sav.interest'), 'already been posted', 'the same period cannot be posted twice');
    Txn::void((int)$run['txn_id'], 'Wrong posting date');
    eqm(Savings::account($rs)['balance'], '12000', 'void takes the interest back out of the member ledger');
    eq(DB::value('SELECT status FROM sav_interest_runs WHERE id = :r', ['r' => $run['run_id']]), 'voided', 'the run is marked void');
    eqm(gl(acct('7100')), '0', 'and out of the general ledger');
    $run2 = Savings::postInterest($GLOBALS['ids']['sav.RS'], '2026-01-01', '2026-03-31', '2026-04-01', true, 'sav.interest');
    check($run2['run_id'] > 0, 'posted again after the void');
    $GLOBALS['celia'] = ['member' => $m, 'rs' => $rs];
    assertSlGl('after interest');
});

test('Dormancy: no member transaction for the product period, fee charged once per quarter, reactivated by a deposit', function () {
    global $ids;
    $m = newMember('Torres', 'Dino', ['membership_date' => '2023-06-01']);
    $rs = openAccount($m, 'RS', '2023-06-01');
    shift('2024-01-05');
    receipt($m, '2024-01-05', [['kind' => 'savings', 'account_id' => $rs, 'amount' => '1500']]);
    $r = Savings::updateDormancy('2026-04-02', true, 'sav.accounts');
    check($r['dormant'] >= 1, 'marked dormant');
    eq(Savings::account($rs)['status'], 'dormant', 'status dormant');
    eqm(Savings::account($rs)['balance'], '1475', 'dormancy fee of 25 charged');
    Savings::updateDormancy('2026-05-15', true, 'sav.accounts');
    eqm(Savings::account($rs)['balance'], '1475', 'not charged again in the same quarter');
    receipt($m, '2026-05-20', [['kind' => 'savings', 'account_id' => $rs, 'amount' => '100']]);
    eq(Savings::account($rs)['status'], 'active', 'a deposit reactivates the account');
    eqm(gl(acct('4460')), '-25', 'dormancy fee income');
});

// =============================================================================================== 7. time deposits
test('Time deposit: placement, rate by term, monthly accrual (once), maturity credited to savings', function () {
    global $ids;
    $m = newMember('Navarro', 'Elena');
    $rs = openAccount($m, 'RS', '2026-01-02');
    shift('2026-01-10');
    [$pid, $txn] = TimeDeposits::place($m, $ids['sav.TD'], '100000', 180, '2026-01-10', ['kind' => 'cash', 'cash_account_id' => $GLOBALS['adminCash']], ['maturity_action' => 'credit_savings', 'credit_account_id' => $rs], 'sav.td');
    $td = TimeDeposits::placement($pid);
    eqm($td['rate'], '4', 'the 180-364 day tier');
    $int = r2s(bcdiv(bcmul(bcmul('100000', '4', 10), '180', 10), '36500', 10));
    eqm($int, '1972.60', 'worked interest 100,000 x 4 % x 180 / 365');
    eqm($td['maturity_value'], bcadd('100000', $int, 2), 'maturity value');
    eq($td['maturity_date'], '2026-07-09', 'maturity date');
    eqm(gl(acct('2310')), '-100000', 'time deposit liability');
    $a1 = TimeDeposits::accrue('2026-01-31', '2026-01-31', 'sav.td');
    $exp1 = r2s(bcdiv(bcmul(bcmul('100000', '4', 10), '21', 10), '36500', 10));
    eqm($a1['amount'], $exp1, 'January accrual (21 days)');
    $again = TimeDeposits::accrue('2026-01-31', '2026-01-31', 'sav.td');
    eq($again['count'], 0, 'the same month is not accrued twice');
    $a2 = TimeDeposits::accrue('2026-02-28', '2026-02-28', 'sav.td');
    $exp2 = bcsub(r2s(bcdiv(bcmul(bcmul('100000', '4', 10), '49', 10), '36500', 10)), $exp1, 2);
    eqm($a2['amount'], $exp2, 'February accrual (the difference to 49 days)');
    eqm(gl(acct('2320')), bcmul(bcadd($exp1, $exp2, 2), '-1', 2), 'accrued interest payable');
    [$stx] = TimeDeposits::settle($pid, '2026-07-09', 'credit_savings', ['credit_account_id' => $rs], 'sav.td');
    eqm(Savings::account($rs)['balance'], bcadd('100000', $int, 2), 'principal and interest credited to savings');
    eqm(gl(acct('2310')), '0', 'time deposit paid');
    eqm(gl(acct('2320')), '0', 'accrual used up');
    eqm(gl(acct('7110')), $int, 'total interest expense = the interest paid');
    eq(TimeDeposits::placement($pid)['status'], 'paid', 'placement settled');
    eq(Savings::account((int)$td['account_id'])['status'], 'closed', 'placement account closed');
    refuses(fn() => TimeDeposits::settle($pid, '2026-07-10', 'payout', ['cash_account_id' => $GLOBALS['adminCash']], 'sav.td'), 'not active', 'cannot settle twice');
    assertSlGl('after the time deposit');
});

test('Time deposit: pre-termination at the reduced rate with the fee; renewal and its void', function () {
    global $ids;
    $m = newMember('Ocampo', 'Fe');
    shift('2026-03-01');
    [$pid] = TimeDeposits::place($m, $ids['sav.TD'], '50000', 90, '2026-03-01', ['kind' => 'cash', 'cash_account_id' => $GLOBALS['adminCash']], [], 'sav.td');
    eqm(TimeDeposits::placement($pid)['rate'], '3.5', 'the 90-179 day tier');
    $cashBefore = gl($GLOBALS['adminCash']);
    TimeDeposits::settle($pid, '2026-04-15', 'payout', ['cash_account_id' => $GLOBALS['adminCash']], 'sav.td');
    $int = r2s(bcdiv(bcmul(bcmul('50000', '1', 10), '45', 10), '36500', 10));
    $net = bcsub(bcadd('50000', $int, 2), '100', 2);
    eqm(bcsub($cashBefore, gl($GLOBALS['adminCash']), 2), $net, 'paid: principal + 45 days at 1 % - 100 fee');
    eq(TimeDeposits::placement($pid)['status'], 'preterminated', 'pre-terminated');
    eqm(gl(acct('4450')), '-100', 'pre-termination fee income');
    [$p2] = TimeDeposits::place($m, $ids['sav.TD'], '20000', 30, '2026-05-01', ['kind' => 'cash', 'cash_account_id' => $GLOBALS['adminCash']], [], 'sav.td');
    [$tx, $newId] = TimeDeposits::settle($p2, '2026-05-31', 'renew_all', [], 'sav.td');
    $i2 = r2s(bcdiv(bcmul(bcmul('20000', '3', 10), '30', 10), '36500', 10));
    eqm(TimeDeposits::placement($newId)['principal'], bcadd('20000', $i2, 2), 'renewed with the interest');
    eq((int)TimeDeposits::placement($p2)['renewed_to_id'], $newId, 'linked to the renewal');
    Txn::void($tx, 'Member asked for a payout instead');
    eq(TimeDeposits::placement($p2)['status'], 'active', 'void puts the original placement back');
    eq(TimeDeposits::placement($newId)['status'], 'cancelled', 'and cancels the renewal');
    eqm(Savings::account((int)TimeDeposits::placement($p2)['account_id'])['balance'], '20000', 'original placement balance restored');
    assertSlGl('after the renewal void');
});

// =============================================================================================== 8. loans
function loanApp(int $memberId, string $productKey, string $amount, string $term, string $date, array $extra = []): int
{
    global $ids;
    $p = Loans::product($ids['loan.' . $productKey]);
    return Loans::saveApplication(['member_id' => $memberId, 'product_id' => $ids['loan.' . $productKey], 'application_date' => $date, 'purpose' => 'Business capital',
        'term' => $term, 'term_unit' => 'months', 'mode_of_payment' => $p['mode_of_payment'], 'applied_amount' => $amount] + $extra, $extra['_collaterals'] ?? [], $extra['_comakers'] ?? [], null, 'loan.application');
}

/** application -> validated -> for approval -> approved by the administrator (one level) */
function approvedLoan(int $memberId, string $productKey, string $amount, string $term, string $date, string $override = ''): int
{
    $app = loanApp($memberId, $productKey, $amount, $term, $date);
    $v = Loans::validateApplication($app, $override, 'loan.applications');
    if (!$v['ok']) {
        throw new AssertionFailed('validation failed: ' . json_encode(array_values(array_filter($v['checks'], fn($c) => !$c['ok']))));
    }
    Loans::submitForApproval($app, 'loan.applications');
    Loans::act($app, 'approved', null, 'ok', 'loan.applications');
    return $app;
}

function releaseLoan(int $appId, string $date, string $start, array $extra = []): array
{
    return Loans::release($appId, ['release_date' => $date, 'start_date' => $start, 'release_mode' => 'cash', 'cash_account_id' => $GLOBALS['adminCash']] + $extra, 'loan.release');
}

test('Loan application: eligibility checks, override with a reason, two-level approval by amount', function () {
    global $ids;
    $m = newMember('Pascual', 'Gino');
    // no share capital yet: the Regular Loan needs 1,000 paid-up
    $app = loanApp($m, 'REG', '50000', '12', '2026-02-01');
    $v = Loans::validateApplication($app, '', 'loan.applications');
    eq($v['ok'], false, 'not eligible yet');
    $failed = array_column(array_filter($v['checks'], fn($c) => !$c['ok']), 'check');
    check((bool)array_filter($failed, fn($c) => str_contains($c, 'share capital')), 'share capital check failed');
    asUser('loans');
    $v2 = Loans::validateApplication($app, 'Committee waived', 'loan.applications');
    eq($v2['ok'], false, 'a loan officer cannot override (no Approve right)');
    asUser('gm');
    $v3 = Loans::validateApplication($app, 'Board approved the exception', 'loan.applications');
    eq($v3['ok'], true, 'the manager overrides with a reason');
    check((bool)DB::value("SELECT id FROM audit_trail WHERE record_type = 'loan_application' AND record_id = :a AND reason LIKE 'Override:%'", ['a' => $app]), 'the override is in the audit trail');
    Loans::cancelApplication($app, 'Member withdrew', 'loan.applications');
    eq(Loans::application($app)['status'], 'cancelled', 'cancelled with a reason');

    // 150,000 needs the committee (level 1) and the manager (level 2)
    Auth::actAs($GLOBALS['adminId']);
    $m2 = newMember('Quinto', 'Hilda');
    $sc = openAccount($m2, 'SC', '2026-01-02');
    shift('2026-01-05');
    receipt($m2, '2026-01-05', [['kind' => 'savings', 'account_id' => $sc, 'amount' => '20000']]);
    $app2 = loanApp($m2, 'REG', '150000', '24', '2026-02-01');
    eq(Loans::validateApplication($app2, '', 'loan.applications')['ok'], true, 'eligible');
    Loans::submitForApproval($app2, 'loan.applications');
    eq(count(Loans::levelsFor('150000', $ids['loan.REG'])), 2, 'two levels for 150,000');
    asUser('ccom');
    refuses(fn() => Loans::act($app2, 'approved', '160000', 'more', 'loan.applications'), 'cannot exceed', 'approved amount above the applied amount');
    Loans::act($app2, 'approved', '140000', 'Reduced to 140,000', 'loan.applications');
    eq(Loans::application($app2)['status'], 'for_approval', 'still waiting for level 2');
    refuses(fn() => Loans::act($app2, 'approved', null, 'again', 'loan.applications'), 'already approved', 'the same approver twice');
    asUser('loans');
    refuses(fn() => Loans::act($app2, 'approved', null, 'x', 'loan.applications'), 'not allowed', 'a loan officer cannot approve');
    asUser('gm');
    Loans::act($app2, 'approved', null, 'Approved', 'loan.applications');
    eq(Loans::application($app2)['status'], 'approved', 'approved after both levels');
    eqm(Loans::application($app2)['approved_amount'], '140000', 'the approved amount stands');
    eq((int)DB::value("SELECT COUNT(*) FROM loan_approvals WHERE application_id = :a AND action = 'approved'", ['a' => $app2]), 2, 'two approvals recorded');
    $GLOBALS['hilda'] = ['member' => $m2, 'app' => $app2, 'sc' => $sc];
});

test('Loan release: 100,000 less 3 % service fee and 5 % share capital = 92,000 net; one release only', function () {
    global $ids;
    $m = newMember('Ramos', 'Ivan');
    $sc = openAccount($m, 'SC', '2026-01-02');
    shift('2026-01-05');
    receipt($m, '2026-01-05', [['kind' => 'savings', 'account_id' => $sc, 'amount' => '2000']]);
    $app = approvedLoan($m, 'REG', '100000', '12', '2026-02-01');
    $plan = Loans::releasePlan(Loans::application($app), ['term' => '12', 'term_unit' => 'months', 'mode_of_payment' => 'monthly', 'rate' => '18', 'amort_type' => 'equal_amortization', 'start_date' => '2026-03-05']);
    eqm($plan['total_charges'], '8000', 'charges 3,000 + 5,000');
    eqm($plan['net_proceeds'], '92000', 'net proceeds (reference)');
    $cashBefore = gl($GLOBALS['adminCash']);
    $r = releaseLoan($app, '2026-02-05', '2026-03-05');
    $loan = Loans::loan($r['loan_id']);
    // version 33: a cash release is a Cash Voucher of Banking, with the voucher ID of the loan release (LR- series)
    eq(Txn::load($r['txn_id'])['txn_type'], 'cash_voucher', 'posted as a Banking cash voucher');
    check(str_starts_with((string)$loan['voucher_no'], 'LR-'), 'the voucher ID of the release is an LR- number');
    eq($loan['loan_no'], '01-6-0000001', 'loan number {BRANCH}-{PRODUCT}-{SEQ}');
    eqm(bcsub($cashBefore, gl($GLOBALS['adminCash']), 2), '92000', 'cash paid out');
    eqm(glMember(acct('1210'), $m), '100000', 'loans receivable under the member');
    eqm(gl(acct('4410')), '-3000', 'service fee income');
    eqm(Savings::account($sc)['balance'], '7000', 'share capital build-up credited (2,000 + 5,000)');
    $pmt = r2s(bcdiv(bcmul('100000', '0.015', 20), bcsub('1', bcdiv('1', bcpow('1.015', '12', 20), 20), 20), 20));
    eqm(Loans::scheduleRows($r['loan_id'])[0]['total'], $pmt, 'monthly amortization by the annuity formula');
    eq($loan['due_date'], '2027-02-05', 'maturity');
    eqm($loan['interest_balance'], $loan['total_interest'], 'scheduled interest on the ledger');
    refuses(fn() => releaseLoan($app, '2026-02-05', '2026-03-05'), 'Only an approved application', 'the same application cannot be released twice');
    // a release without payments can be voided: the loan is cancelled and the share capital build-up goes back
    $app2 = approvedLoan(newMember('Santos', 'Jo', ['membership_date' => '2025-01-01']), 'EMR', '5000', '3', '2026-02-01');
    $r2 = releaseLoan($app2, '2026-02-06', '2026-02-21');
    Txn::void($r2['txn_id'], 'Released to the wrong member');
    eq(Loans::loan($r2['loan_id'])['status'], 'cancelled', 'void cancels the loan');
    eq(Loans::application($app2)['status'], 'approved', 'the application is approved again');
    $GLOBALS['ivan'] = ['member' => $m, 'loan' => $r['loan_id'], 'sc' => $sc];
    assertLedgerBalanced('after the releases');
    assertSlGl('after the releases');
});

test('Loan collection: penalty after the grace period, allocation penalty-interest-principal, the rest to the principal (version 35)', function () {
    global $ids;
    $m = newMember('Uy', 'Kim');
    $sc = openAccount($m, 'SC', '2026-01-02');
    shift('2026-01-05');
    receipt($m, '2026-01-05', [['kind' => 'savings', 'account_id' => $sc, 'amount' => '1500']]);
    $app = approvedLoan($m, 'REG', '20000', '12', '2026-01-10');
    $r = releaseLoan($app, '2026-01-15', '2026-02-15');
    $loanId = $r['loan_id'];
    $rows = Loans::scheduleRows($loanId);
    // paid on Apr 20: three amortizations due (Feb 15, Mar 15, Apr 15); 3-day grace; 36 % a year on the arrears
    $pen = '0';
    foreach ([[0, 61], [1, 33], [2, 2]] as [$k, $days]) {
        $pen = bcadd($pen, r2s(bcdiv(bcmul(bcmul($rows[$k]['total'], '36', 10), (string)$days, 10), '36500', 10)), 2);
    }
    $dues = Loans::dues(Loans::loan($loanId), '2026-04-20');
    eq($dues['count_due'], 3, 'three amortizations due');
    eqm($dues['penalty_due'], $pen, 'penalty from the end of the grace period');
    $intDue = bcadd(bcadd($rows[0]['interest'], $rows[1]['interest'], 2), $rows[2]['interest'], 2);
    $prnDue = bcadd(bcadd($rows[0]['principal'], $rows[1]['principal'], 2), $rows[2]['principal'], 2);
    eqm($dues['interest_due'], $intDue, 'interest due');
    eqm($dues['principal_due'], $prnDue, 'principal due');
    asUser('teller1');
    shift(today(), '1000');
    refuses(fn() => receipt($m, today(), [['kind' => 'loan', 'loan_id' => $loanId, 'amount' => '1000', 'override' => ['penalty' => '0', 'interest' => '0', 'principal' => '1000']]]), 'override', 'a teller cannot override the allocation');
    Auth::actAs($GLOBALS['adminId']);
    refuses(fn() => receipt($m, '2026-04-20', [['kind' => 'loan', 'loan_id' => $loanId, 'amount' => '1000', 'override' => ['penalty' => '0', 'interest' => '0', 'principal' => '900']]]), 'add up', 'an override must add up to the amount');
    $cash = gl($GLOBALS['adminCash']);
    $rc = receipt($m, '2026-04-20', [['kind' => 'loan', 'loan_id' => $loanId, 'amount' => '6000']]);
    $lt = DB::row("SELECT * FROM loan_txns WHERE loan_id = :l AND kind = 'payment' AND is_voided = 0", ['l' => $loanId]);
    eqm($lt['penalty_cr'], $pen, 'penalty paid first');
    // version 35: what is left after the dues goes to the principal (not to the interest of the next amortization)
    $advance = bcsub(bcsub(bcsub('6000', $pen, 2), $intDue, 2), $prnDue, 2);
    eqm($lt['interest_cr'], $intDue, 'then the interest due, and no interest of the amortizations not yet due');
    eqm($lt['principal_cr'], bcadd($prnDue, $advance, 2), 'then the principal due and the rest, all to the principal');
    eqm(bcsub(gl($GLOBALS['adminCash']), $cash, 2), '6000', 'cash received');
    eqm(gl(acct('4430')), bcmul($pen, '-1', 2), 'penalty income');
    $rows2 = Loans::scheduleRows($loanId);
    eq([(int)$rows2[0]['is_paid'], (int)$rows2[1]['is_paid'], (int)$rows2[2]['is_paid'], (int)$rows2[3]['is_paid']], [1, 1, 1, 0], 'three amortizations settled, the fourth partly');
    eqm($rows2[3]['interest_paid'], '0', 'the advance paid no interest of the next instalment');
    eqm($rows2[3]['principal_paid'], bccomp($advance, $rows2[3]['principal'], 2) > 0 ? $rows2[3]['principal'] : $advance, 'it went to the principal of the next instalment');
    eq(Loans::loan($loanId)['status'], 'current', 'current again');
    $GLOBALS['kim'] = ['member' => $m, 'loan' => $loanId, 'payment_txn' => $rc['txn_id'], 'rows' => $rows, 'pen' => $pen];
    assertSlGl('after the collection');
});

test('Loan payment void: schedule and balances rebuilt; payoff cancels the unearned interest', function () {
    ['member' => $m, 'loan' => $loanId, 'payment_txn' => $ptx, 'rows' => $rows, 'pen' => $pen] = $GLOBALS['kim'];
    $before = Loans::loan($loanId);
    Txn::void($ptx, 'Posted to the wrong loan');
    $l = Loans::loan($loanId);
    eqm($l['principal_balance'], '20000', 'principal back');
    eqm($l['penalty_balance'], $pen, 'the penalty charged stays on the ledger (it was earned)');
    eq((int)DB::value('SELECT COUNT(*) FROM loan_schedule WHERE loan_id = :l AND is_paid = 1', ['l' => $loanId]), 0, 'no amortization paid');
    eq($l['status'], 'past_due', 'past due again');
    // full payment on May 10
    $d = Loans::dues($l, '2026-05-10');
    $itd = r2s(bcdiv(bcmul(bcmul('20000', '18', 10), '25', 10), '36500', 10));
    eqm($d['interest_to_date'], $itd, 'interest to date: 25 days since the Apr 15 amortization');
    $rc = receipt($m, '2026-05-10', [['kind' => 'loan', 'loan_id' => $loanId, 'payoff' => 1, 'amount' => '0']]);
    $l2 = Loans::loan($loanId);
    eq($l2['status'], 'paid', 'fully paid');
    eqm($l2['principal_balance'], '0', 'no principal left');
    eqm($l2['interest_balance'], '0', 'unearned interest cancelled');
    check((bool)DB::value("SELECT id FROM loan_txns WHERE loan_id = :l AND kind = 'rebate' AND txn_id = :t", ['l' => $loanId, 't' => $rc['txn_id']]), 'rebate row on the same voucher');
    eqm($rc['total'], $d['payoff'], 'receipt = payoff amount');
    refuses(fn() => receipt($m, '2026-05-11', [['kind' => 'loan', 'loan_id' => $loanId, 'amount' => '10']]), 'paid', 'no payment on a paid loan');
    assertSlGl('after the payoff');
});

test('Loan interest accrual and penalty batch: once per date, cleared by the next collection', function () {
    $m = newMember('Valdez', 'Lea');
    $sc = openAccount($m, 'SC', '2026-01-02');
    shift('2026-01-05');
    receipt($m, '2026-01-05', [['kind' => 'savings', 'account_id' => $sc, 'amount' => '3000']]);
    $app = approvedLoan($m, 'REG', '50000', '12', '2026-05-20');
    $r = releaseLoan($app, '2026-06-01', '2026-07-01');
    $acc = Loans::accrueInterest('2026-06-30', '2026-06-30', 'loan.penalties');
    $mine = array_values(array_filter($acc['lines'], fn($x) => (int)$x['member_id'] === $m))[0] ?? null;
    $exp = r2s(bcdiv(bcmul(bcmul('50000', '18', 10), '29', 10), '36500', 10));
    eqm($mine['amount'] ?? '0', $exp, 'interest earned June 1 - 30 (29 days)');
    $again = Loans::accrueInterest('2026-06-30', '2026-06-30', 'loan.penalties');
    eq(array_values(array_filter($again['lines'], fn($x) => (int)$x['member_id'] === $m)), [], 'nothing accrued twice');
    eqm(Loans::loan($r['loan_id'])['interest_accrued'], $exp, 'accrued on the loan');
    $row1 = Loans::scheduleRows($r['loan_id'])[0];
    receipt($m, '2026-07-01', [['kind' => 'loan', 'loan_id' => $r['loan_id'], 'amount' => $row1['total']]]);
    eqm(Loans::loan($r['loan_id'])['interest_accrued'], '0', 'the collection cleared the accrual');
    eqm(glMember(Accts::get('loan_interest_receivable'), $m), '0', 'interest receivable of the member cleared');
    eqm(bcmul(glMember(acct('4400'), $m), '-1', 2), $row1['interest'], 'interest income = the scheduled interest (accrual + rest)');
    // penalties: batch through Aug 20 (the Aug 1 amortization unpaid), run twice
    $p1 = Loans::postPenalties('2026-08-20', 'loan.penalties');
    $l = Loans::loan($r['loan_id']);
    $row2 = Loans::scheduleRows($r['loan_id'])[1];
    $exp2 = r2s(bcdiv(bcmul(bcmul($row2['total'], '36', 10), '16', 10), '36500', 10));
    eqm($l['penalty_balance'], $exp2, 'penalty on the Aug 1 amortization, 16 days after the grace');
    Loans::postPenalties('2026-08-20', 'loan.penalties');
    eqm(Loans::loan($r['loan_id'])['penalty_balance'], $exp2, 'running the batch again adds nothing');
    $GLOBALS['lea'] = ['member' => $m, 'loan' => $r['loan_id']];
    assertSlGl('after accruals');
});

test('Waiver: requested by one user, approved by another; write-off and recovery', function () {
    global $ids;
    ['loan' => $loanId] = $GLOBALS['lea'];
    asUser('loans');
    $w = Loans::requestWaiver($loanId, '2026-08-21', '0', Loans::loan($loanId)['penalty_balance'], 'Hospitalized', 'loan.waivers');
    refuses(fn() => Loans::approveWaiver($w, true, 'ok', 'loan.waivers'), 'Approve right', 'the loan officer cannot approve');
    asUser('ccom');
    Loans::approveWaiver($w, true, 'Approved by the committee', 'loan.waivers');
    eqm(Loans::loan($loanId)['penalty_balance'], '0', 'penalty waived');
    eq(DB::value('SELECT status FROM loan_waivers WHERE id = :w', ['w' => $w]), 'posted', 'waiver posted');
    Auth::actAs($GLOBALS['adminId']);
    $before = Loans::loan($loanId)['principal_balance'];
    Loans::writeOff($loanId, '2026-09-30', 'Board resolution 2026-09: uncollectible', false, 'loan.restructure');
    eq(Loans::loan($loanId)['status'], 'written_off', 'written off');
    eqm(gl(acct('7120')), $before, 'provision for probable losses');
    $cash = gl($GLOBALS['adminCash']);
    Loans::recover($loanId, '2026-10-01', '5000', $GLOBALS['adminCash'], [], 'loan.restructure');
    eqm(gl(acct('4480')), '-5000', 'recovery income');
    eqm(bcsub(gl($GLOBALS['adminCash']), $cash, 2), '5000', 'cash recovered');
    assertSlGl('after the write-off');
});

test('Loan renewal: the new loan pays off the old one, which is closed as renewed', function () {
    ['member' => $m, 'loan' => $old, 'sc' => $sc] = $GLOBALS['ivan'];
    $app = loanApp($m, 'REG', '120000', '12', '2026-04-01');
    $v = Loans::validateApplication($app, 'Renewal of an existing loan', 'loan.applications');
    eq($v['ok'], true, 'the open-loan limit is overridden for a renewal');
    Loans::submitForApproval($app, 'loan.applications');
    Loans::act($app, 'approved', null, 'Renewal', 'loan.applications');
    asUser('gm');
    Loans::act($app, 'approved', null, 'Renewal (level 2)', 'loan.applications');
    Auth::actAs($GLOBALS['adminId']);
    $d = Loans::dues(Loans::loan($old), '2026-04-05');
    $r = releaseLoan($app, '2026-04-05', '2026-05-05', ['previous_loan_id' => $old]);
    $new = Loans::loan($r['loan_id']);
    eqm($new['previous_loan_payoff'], $d['payoff'], 'payoff of the old loan');
    eqm($new['net_proceeds'], bcsub(bcsub('120000', '9600', 2), $d['payoff'], 2), 'net = 120,000 - charges 9,600 - payoff');
    $o = Loans::loan($old);
    eq($o['status'], 'renewed', 'old loan closed as renewed');
    eq($new['status'], 'current', 'a renewal is an ordinary current loan');
    eqm($o['principal_balance'], '0', 'old loan principal paid');
    eq((int)$o['restructured_to_id'], (int)$r['loan_id'], 'linked forward');
    eq((int)$new['restructured_from_id'], $old, 'linked back');
    assertLedgerBalanced('after the renewal');
    assertSlGl('after the renewal');
});

// =============================================================================================== 9. year-end distribution
test('Net surplus allocation: statutory funds, ISC and PR pools, posted once after the year end', function () {
    $id = Distribution::savePeriod(['fiscal_year' => 2025, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'net_surplus' => '100000',
        'reserve_pct' => '10', 'educ_pct' => '10', 'educ_apex_pct' => '50', 'cdf_pct' => '3', 'optional_pct' => '7', 'isc_amount' => '30000'], null, 'dist.surplus');
    $p = DB::row('SELECT * FROM dist_periods WHERE id = :id', ['id' => $id]);
    eqm($p['reserve_amt'], '10000', 'reserve fund 10 %');
    eqm($p['educ_amt'], '10000', 'education and training 10 %');
    eqm($p['cdf_amt'], '3000', 'community development 3 %');
    eqm($p['optional_amt'], '7000', 'optional 7 %');
    eqm($p['pr_amount'], '40000', 'patronage refund = what is left after ISC');
    refuses(fn() => Distribution::savePeriod(['fiscal_year' => 2024, 'period_from' => '2024-01-01', 'period_to' => '2024-12-31', 'net_surplus' => '100000',
        'reserve_pct' => '10', 'educ_pct' => '10', 'educ_apex_pct' => '50', 'cdf_pct' => '3', 'optional_pct' => '7', 'isc_amount' => '80001'], null, 'dist.surplus'), 'cannot exceed', 'ISC above what is left');
    refuses(fn() => Distribution::postPeriod($id, '2026-01-31', 'dist.surplus'), 'Approve', 'not posted before approval');
    Distribution::approvePeriod($id, 'GA-2026-03', '2026-01-25', 'BOD and General Assembly', 'dist.surplus');
    refuses(fn() => Distribution::postPeriod($id, '2025-12-31', 'dist.surplus'), 'after the end', 'not inside the fiscal year');
    $tx = Distribution::postPeriod($id, '2026-01-31', 'dist.surplus');
    eqm(gl(acct('3200')), '100000', 'retained earnings debited');
    eqm(gl(acct('3300')), '-10000', 'reserve fund');
    eqm(gl(acct('3310')), '-5000', 'education fund kept');
    eqm(gl(acct('2350')), '-5000', 'education fund due to the federation (50 %)');
    eqm(gl(acct('2330')), '-30000', 'ISC payable');
    eqm(gl(acct('2340')), '-40000', 'PR payable');
    refuses(fn() => Distribution::postPeriod($id, '2026-01-31', 'dist.surplus'), 'once', 'posted once');
    refuses(fn() => Txn::void($tx, 'test'), 'adjusting entry', 'an allocation is not voided');
    $GLOBALS['period'] = $id;
});

test('Interest on share capital: average daily balance x rate, maker-checker, posted once, reversed by adjusting entry', function () {
    global $ids;
    $s1 = newMember('Yap', 'Mila', ['membership_date' => '2024-06-01']);
    $s2 = newMember('Zamora', 'Nico', ['membership_date' => '2024-06-01']);
    $a1 = openAccount($s1, 'SC', '2024-12-01');
    $a2 = openAccount($s2, 'SC', '2024-12-01');
    shift('2025-01-01');
    receipt($s1, '2025-01-01', [['kind' => 'savings', 'account_id' => $a1, 'amount' => '1000']]);
    receipt($s1, '2025-07-02', [['kind' => 'savings', 'account_id' => $a1, 'amount' => '2000']]);
    receipt($s2, '2025-04-01', [['kind' => 'savings', 'account_id' => $a2, 'amount' => '5000']]);
    $adb1 = r2s(bcdiv(bcadd(bcmul('1000', '182', 2), bcmul('3000', '183', 2), 2), '365', 10));
    $adb2 = r2s(bcdiv(bcmul('5000', '275', 2), '365', 10));
    asUser('book');
    $b = Distribution::compute('isc', ['period_id' => $GLOBALS['period'], 'date_from' => '2025-01-01', 'date_to' => '2025-12-31', 'method' => 'rate', 'rate' => '10', 'pool' => '0',
        'basis' => 'adb', 'credit_mode' => 'share_capital', 'credit_product_id' => null, 'description' => 'ISC 2025'], [], null, 'dist.isc');
    $line = fn($m) => DB::row('SELECT * FROM dist_batch_lines WHERE batch_id = :b AND member_id = :m', ['b' => $b, 'm' => $m]);
    eqm($line($s1)['basis_amount'], $adb1, 'ADB of the first member (1,000 then 3,000 from Jul 2)');
    eqm($line($s2)['basis_amount'], $adb2, 'ADB of the second member (5,000 from Apr 1)');
    eqm($line($s1)['computed'], r2s(bcdiv(bcmul($adb1, '10', 10), '100', 10)), 'ISC 10 % of the ADB');
    eq(Distribution::validate($b, 'dist.isc'), [], 'validation passes');
    refuses(fn() => Distribution::approve($b, 'GA-2026-03', 'dist.isc'), 'Approve right', 'the bookkeeper cannot approve');
    asUser('gm');
    Distribution::approve($b, 'GA-2026-03', 'dist.isc');
    Auth::actAs($GLOBALS['adminId']);
    $total = DB::value('SELECT total_amount FROM dist_batches WHERE id = :b', ['b' => $b]);
    $tx = Distribution::post($b, '2026-02-01', 0, 'dist.isc');
    eqm(Savings::account($a1)['balance'], bcadd('3000', $line($s1)['net_amount'], 2), 'ISC credited to share capital');
    eqm(gl(acct('2330')), bcsub($total, '30000', 2), 'ISC payable reduced by the batch');
    refuses(fn() => Distribution::post($b, '2026-02-01', 0, 'dist.isc'), 'once', 'a batch is posted once');
    refuses(fn() => Txn::void($tx, 'x'), 'adjusting entry', 'a posted batch is not voided');
    Distribution::reverse($b, '2026-02-02', 'Wrong rate', 'dist.isc');
    eqm(Savings::account($a1)['balance'], '3000', 'reversed out of the member account');
    eqm(gl(acct('2330')), '-30000', 'ISC payable restored');
    eq(DB::value('SELECT status FROM dist_batches WHERE id = :b', ['b' => $b]), 'cancelled', 'batch cancelled');
    // pool method: the rounding difference goes to the largest basis, the pool is distributed exactly
    $b2 = Distribution::compute('isc', ['period_id' => $GLOBALS['period'], 'date_from' => '2025-01-01', 'date_to' => '2025-12-31', 'method' => 'pool', 'rate' => '0', 'pool' => '1000',
        'basis' => 'adb', 'credit_mode' => 'share_capital', 'credit_product_id' => null], [], null, 'dist.isc');
    eqm(DB::value('SELECT total_amount FROM dist_batches WHERE id = :b', ['b' => $b2]), '1000', 'pool distributed to the centavo');
    $l1 = DB::row('SELECT * FROM dist_batch_lines WHERE batch_id = :b AND member_id = :m', ['b' => $b2, 'm' => $s1]);
    Distribution::exclude($b2, (int)$l1['id'], true, 'Delinquent per committee', null, 'dist.isc');
    eqm(DB::value('SELECT total_amount FROM dist_batches WHERE id = :b', ['b' => $b2]), '1000', 'still the full pool after removing a member');
    eqm(DB::value('SELECT net_amount FROM dist_batch_lines WHERE batch_id = :b AND member_id = :m', ['b' => $b2, 'm' => $s2]), '1000', 'the remaining member takes the pool');
});

test('Patronage refund: income earned from each member in the period, kept as payable, paid on a disbursement voucher', function () {
    global $ids;
    $income = [acct('4400'), acct('4430')];
    $b = Distribution::compute('pr', ['period_id' => $GLOBALS['period'], 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'method' => 'rate', 'rate' => '6', 'pool' => '0',
        'credit_mode' => 'payable', 'credit_product_id' => null, 'description' => 'PR on interest and penalties'], ['income_accounts' => $income], null, 'dist.pr');
    $kim = $GLOBALS['kim']['member'];
    $earned = bcmul(bcadd(glMember(acct('4400'), $kim), glMember(acct('4430'), $kim), 2), '-1', 2);
    $l = DB::row('SELECT * FROM dist_batch_lines WHERE batch_id = :b AND member_id = :m', ['b' => $b, 'm' => $kim]);
    eqm($l['basis_amount'], $earned, 'basis = interest and penalties the member paid (from the ledger)');
    eqm($l['computed'], r2s(bcdiv(bcmul($earned, '6', 10), '100', 10)), 'refund 6 %');
    eq(Distribution::validate($b, 'dist.pr'), [], 'validated');
    asUser('gm');
    Distribution::approve($b, 'GA-2026-03', 'dist.pr');
    Auth::actAs($GLOBALS['adminId']);
    Distribution::post($b, '2026-02-01', 0, 'dist.pr');
    eqm(glMember(acct('2340'), $kim), bcmul($l['net_amount'], '-1', 2), 'payable kept under the member');
    shift('2026-02-02');
    Teller::disburse($kim, '2026-02-03', [['kind' => 'payable', 'account_id' => acct('2340'), 'amount' => $l['net_amount'], 'description' => 'Patronage refund 2026', 'batch_line_id' => $l['id']]], ['mode' => 'cash'], [], 'teller.disburse');
    eqm(glMember(acct('2340'), $kim), '0', 'paid out');
    check((bool)DB::value('SELECT paid_txn_id FROM dist_batch_lines WHERE id = :l', ['l' => $l['id']]), 'the line is marked paid');
});

// =============================================================================================== 10. security, integrity, concurrency
test('Branch isolation: a branch teller sees and touches only the members of the branch', function () {
    global $ids;
    $br2 = newMember('Agbayani', 'Rhea', ['branch' => 'BR2']);
    asUser('teller2');
    $found = array_column(Coop::searchMembers(''), 'id');
    check(in_array($br2, $found, true), 'the branch member is listed');
    check(!in_array($GLOBALS['juan'], $found, true), 'a head office member is not');
    refuses(fn() => Savings::open($GLOBALS['juan'], $ids['sav.RS'], null, today(), null, 'sav.accounts'), 'another branch', 'cannot open an account for a head office member');
    refuses(fn() => Teller::receipt($GLOBALS['ana']['member'], today(), [['kind' => 'savings', 'account_id' => $GLOBALS['ana']['rs'], 'amount' => '100']], ['mode' => 'cash'], [], 'teller.or'), 'another branch', 'cannot receive for a head office member');
    asUser('gm');
    $all = array_column(Coop::searchMembers(''), 'id');
    check(in_array($GLOBALS['juan'], $all, true) && in_array($br2, $all, true), 'a user with all-branch access sees both');
    Auth::actAs($GLOBALS['adminId']);
    $acct = openAccount($br2, 'RS', '2026-06-01');
    asUser('teller2');
    Teller::open(today(), '1000', null, 'teller.shift');
    $r = receipt($br2, today(), [['kind' => 'savings', 'account_id' => $acct, 'amount' => '500']]);
    eq(substr($r['receipt_no'], 0, 1), 'P', 'the branch OR series (prefix P)');
    eq((int)DB::value('SELECT class_id FROM transactions WHERE id = :t', ['t' => $r['txn_id']]), $ids['class.BR2'], 'the posting carries the branch class');
});

test('Duplicate submission: one-time form token, unique numbers', function () {
    $_SESSION['once'] = [];
    $field = Coop::onceField();
    preg_match('/value="([^"]+)"/', $field, $mm);
    $_POST['_once'] = $mm[1];
    Coop::consumeOnce();
    check(true, 'first submission accepted');
    refuses(fn() => Coop::consumeOnce(), 'already submitted', 'the same form sent again is refused');
    $_POST = [];
    $a = Seq::next('coop_or');
    $b = Seq::next('coop_or');
    check($a !== $b, 'sequence numbers never repeat');
});

test('Concurrency: two withdrawals at the same moment on one account, only the one the balance covers goes through', function () {
    $m = newMember('Bautista', 'Omar');
    $rs = openAccount($m, 'RS', '2026-06-01');
    shift('2026-06-02');
    receipt($m, '2026-06-02', [['kind' => 'savings', 'account_id' => $rs, 'amount' => '1100']]);
    // two separate PHP processes withdraw 800 each from a balance of 1,100 (minimum balance 100)
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/worker.php') . ' withdraw ' . $rs . ' 800 2026-06-03 ' . (int)$GLOBALS['adminCash'];
    $env = ['DB_NAME' => $GLOBALS['CONFIG']['db']['name'], 'PATH' => getenv('PATH')];
    $procs = [];
    for ($i = 0; $i < 2; $i++) {
        $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $GLOBALS['pipes'][$i] = $pipes;
    }
    $outs = [];
    foreach ($procs as $i => $p) {
        $outs[] = trim(stream_get_contents($GLOBALS['pipes'][$i][1]) . stream_get_contents($GLOBALS['pipes'][$i][2]));
        proc_close($p);
    }
    $ok = count(array_filter($outs, fn($o) => str_starts_with($o, 'OK')));
    $refused = count(array_filter($outs, fn($o) => str_contains($o, 'Insufficient')));
    eq([$ok, $refused], [1, 1], 'one withdrawal went through, the other was refused (' . implode(' | ', $outs) . ')');
    eqm(Savings::account($rs)['balance'], '300', 'balance 1,100 - 800');
    eqm(glMember(acct('2300'), $m), '-300', 'general ledger agrees');
    // and the row lock itself: a second connection cannot lock the account while the first holds it
    DB::begin();
    DB::row('SELECT id FROM sav_accounts WHERE id = :a FOR UPDATE', ['a' => $rs]);
    $c = $GLOBALS['CONFIG']['db'];
    $other = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $waited = false;
    try {
        $other->query('SELECT id FROM sav_accounts WHERE id = ' . (int)$rs . ' FOR UPDATE')->fetchAll();
    } catch (PDOException $e) {
        $waited = str_contains($e->getMessage(), 'Lock wait timeout');
    }
    DB::rollBack();
    check($waited, 'the second connection waited for the lock');
});

test('Immutability: posted entries are voided with a reason, never deleted; the audit trail cannot be changed', function () {
    $m = newMember('Castro', 'Paz');
    $rs = openAccount($m, 'RS', '2026-06-01');
    shift('2026-06-02');
    $dep = receipt($m, '2026-06-02', [['kind' => 'savings', 'account_id' => $rs, 'amount' => '1000']]);
    Savings::withdraw($rs, 'CSW', '800', '2026-06-03', $GLOBALS['adminCash']);
    $t = (int)$dep['txn_id'];
    refuses(fn() => Txn::delete($t, 'cleanup'), 'void', 'a cooperative voucher cannot be deleted');
    refuses(fn() => Txn::void($t, ''), 'reason', 'a void needs a reason');
    refuses(fn() => Txn::void($t, 'test'), 'overdraw', 'voiding the deposit would overdraw the account after the withdrawal');
    eqm(Savings::account($rs)['balance'], '200', 'nothing changed');
    $blocked = false;
    try {
        DB::run("UPDATE audit_trail SET reason = 'changed' WHERE id = (SELECT id FROM (SELECT MAX(id) AS id FROM audit_trail) x)");
    } catch (Throwable $e) {
        $blocked = true;
    }
    check($blocked, 'the audit trail refuses an UPDATE (trigger)');
    $blocked = false;
    try {
        DB::run('DELETE FROM audit_trail ORDER BY id DESC LIMIT 1');
    } catch (Throwable $e) {
        $blocked = true;
    }
    check($blocked, 'the audit trail refuses a DELETE (trigger)');
});

test('Closed period: no member transaction on or before the closing date without the permission', function () {
    ['rs' => $rs] = $GLOBALS['celia'];
    ['rs' => $other] = $GLOBALS['ben'];
    Settings::set('closing_date', '2026-01-31');
    asUser('book');
    refuses(fn() => Savings::transfer($rs, $other, '10', '2026-01-20', 'late'), 'closed', 'refused for the bookkeeper');
    Auth::actAs($GLOBALS['adminId']);
    Settings::set('closing_date', '');
    check(true, 'closing date cleared');
});

test('Ledger integrity at the end: every voucher balances, subsidiary ledgers = control accounts', function () {
    assertLedgerBalanced('final');
    assertSlGl('final');
    $coop = (int)DB::value("SELECT COUNT(*) FROM transactions WHERE txn_type IN ('coop_or','coop_cv','loan_release','coop_jv')");
    check($coop > 40, "cooperative vouchers posted to the one general ledger ($coop)");
    $orphans = (int)DB::value('SELECT COUNT(*) FROM sav_txns s LEFT JOIN transactions t ON t.id = s.txn_id WHERE s.txn_id IS NULL OR t.id IS NULL');
    eq($orphans, 0, 'every savings ledger row has its general ledger voucher');
    $mismatch = DB::all('SELECT s.id FROM sav_txns s JOIN transactions t ON t.id = s.txn_id WHERE s.is_voided <> t.is_voided');
    eq($mismatch, [], 'voided vouchers and voided ledger rows agree');
    $mismatch = DB::all("SELECT l.id FROM loan_txns l JOIN transactions t ON t.id = l.txn_id WHERE l.is_voided <> t.is_voided");
    eq($mismatch, [], 'and for the loan ledger');
});
