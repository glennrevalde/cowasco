<?php
declare(strict_types=1);

/**
 * The savings requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v3.xlsx at the level of the program's rules:
 * the Savings Transaction Center (one transaction number series, the transaction code as the basis of the journal
 * entry, the user's or the profile's cash account, error corrections, memos, back entries, deletion), returned
 * checks, closing and deleting accounts, the passbook (line numbers, the fold, the header, the reset of the print
 * line, error corrections printed negative) and the accrued interest withdrawal of a time deposit (CAIW).
 */

/** posts a transaction of the Savings Account Transaction form under the head office class */
function svPost(array $f): array
{
    global $ids;
    return CoopLedger::withClass((int)$ids['class.HO'], fn() => SavTxn::post($f + ['date' => today()], 'sav.transact'));
}

/** an account opened and approved (a teller's own opening waits for a supervisor) */
function svOpen(int $memberId, string $productKey): int
{
    $id = openAccount($memberId, $productKey, today());
    DB::update('sav_accounts', ['status' => 'active', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
    return $id;
}

/** closes the current user's open shift with the cash expected (another user takes the same drawer next) */
function svCloseShift(): void
{
    $s = Teller::openShift();
    if (!$s) {
        return;
    }
    Teller::refreshShift((int)$s['id']);
    Teller::close((int)$s['id'], ['_total' => Teller::expectedCash(Teller::shift((int)$s['id']))], '', 'teller.shift');
}

function svRow(int $id): array
{
    return DB::row('SELECT * FROM sav_txns WHERE id = :id', ['id' => $id]);
}

/** the journal lines of a voucher as [account_id => debit - credit] */
function svGl(int $txnId): array
{
    $out = [];
    foreach (DB::all('SELECT account_id, SUM(debit - credit) AS net FROM journal_lines WHERE txn_id = :t GROUP BY account_id', ['t' => $txnId]) as $r) {
        $out[(int)$r['account_id']] = bcadd((string)$r['net'], '0', 2);
    }
    return $out;
}

test('Savings Transaction Center: one transaction number series; the code decides the entry (User Account = the teller\'s cash, a GL account of its own); cash in the teller\'s shift', function () {
    global $ids;
    asUser('teller1');
    $shiftId = shift(today());
    Teller::refreshShift($shiftId);
    $s0 = Teller::shift($shiftId);
    $m = newMember('Tabelino', 'Sarah');
    $rs = svOpen($m, 'RS');
    $a = Savings::account($rs);
    $liab = Savings::ledgerAccount($a);
    $cash = (int)$ids['cash.HO'];

    $r1 = svPost(['account_id' => $rs, 'code' => 'CSD', 'amount' => '10000']);
    check(preg_match('/^\d{7}$/', $r1['trans_no']) === 1, 'the transaction number is the next of the series (7 digits): ' . $r1['trans_no']);
    $t1 = Txn::load((int)$r1['txn_id']);
    eq($t1['txn_type'], 'coop_sv', 'the voucher is a Savings Transaction');
    eq($t1['txn_no'], $r1['trans_no'], 'the voucher carries the transaction number');
    eq(svRow($r1['sav_txn_id'])['trans_no'], $r1['trans_no'], 'the member\'s ledger row carries it too');
    $g = svGl((int)$r1['txn_id']);
    eqm($g[$cash] ?? '0', '10000', 'CSD (User Account): Dr the teller\'s cash');
    eqm($g[$liab] ?? '0', '-10000', 'CSD: Cr the member\'s savings');
    $rc = DB::row('SELECT * FROM coop_receipts WHERE txn_id = :t', ['t' => $r1['txn_id']]);
    check($rc && $rc['receipt_kind'] === 'sd' && $rc['receipt_no'] === $r1['trans_no'] && (int)$rc['shift_id'] === $shiftId, 'a savings deposit slip SD of the shift, numbered by the transaction no.');

    $r2 = svPost(['account_id' => $rs, 'code' => 'CSW', 'amount' => '1115']);
    eq((int)$r2['trans_no'], (int)$r1['trans_no'] + 1, 'the next transaction takes the next number');
    $g = svGl((int)$r2['txn_id']);
    eqm($g[$liab] ?? '0', '1115', 'CSW: Dr the member\'s savings');
    eqm($g[$cash] ?? '0', '-1115', 'CSW: Cr the teller\'s cash');
    Teller::refreshShift($shiftId);
    $s = Teller::shift($shiftId);
    eqm(bcsub($s['receipts_total'], $s0['receipts_total'], 2), '10000', 'the shift counts the deposit as cash received');
    eqm(bcsub($s['disbursements_total'], $s0['disbursements_total'], 2), '1115', 'and the withdrawal as cash paid out');

    // a code whose opposite account is a GL account of its own: a direct bank deposit
    $bank = (int)DB::value("SELECT id FROM accounts WHERE account_type = 'Bank' AND id NOT IN (:a, :b) ORDER BY code LIMIT 1", ['a' => $cash, 'b' => (int)$ids['cash.BR2']]);
    DB::insert('sav_txn_codes', ['code' => 'UBDD', 'description' => 'Unionbank Direct Deposit', 'opposite_kind' => 'gl', 'opposite_account_id' => $bank, 'entry_side' => 'credit', 'is_active' => 1]);
    $r3 = svPost(['account_id' => $rs, 'code' => 'UBDD', 'amount' => '2500']);
    $g = svGl((int)$r3['txn_id']);
    eqm($g[$bank] ?? '0', '2500', 'UBDD (GL Account): Dr the bank account of the code');
    eqm($g[$liab] ?? '0', '-2500', 'and Cr the member\'s savings');
    check(!DB::value('SELECT id FROM coop_receipts WHERE txn_id = :t', ['t' => $r3['txn_id']]), 'a direct bank deposit is not in the teller\'s drawer');
    eqm(Savings::account($rs)['balance'], '11385', 'balance 10,000 - 1,115 + 2,500');
    refuses(fn() => svPost(['account_id' => $rs, 'code' => 'CSD', 'amount' => '100', 'date' => Coop::addDays(today(), -1)]), 'Back Entry', 'an earlier date without Back Entry');
    asUser('gm');
    assertLedgerBalanced('after the savings transactions');
    assertSlGl('after the savings transactions');
});

test('User profile cash account: a teller without a cash account of their own transacts with the cash account of their profile', function () {
    global $ids;
    $roleId = (int)DB::value("SELECT id FROM roles WHERE name = 'Teller'");
    $prof = coop_fx_new_account('1011', 'Cash on Hand - Teller Profile', 'Bank', 'Asset', 'Debit');
    DB::update('roles', ['coop_cash_account_id' => $prof], 'id = :id', ['id' => $roleId]);
    $u = coop_fx_user('teller9', 'Glen Teller', 'Teller', $ids['branch.HO'], null);
    eq(Teller::userCashAccount($u), $prof, 'no cash account of their own: the profile\'s');
    eq(Teller::userCashAccount((int)$ids['user.teller1']), (int)$ids['cash.HO'], 'a teller\'s own cash account still comes first');
    Auth::actAs($u);
    shift(today());
    $m = newMember('Glenn', 'Profile');
    $rs = svOpen($m, 'RS');
    $r = svPost(['account_id' => $rs, 'code' => 'CSD', 'amount' => '300']);
    eqm(svGl((int)$r['txn_id'])[$prof] ?? '0', '300', 'the deposit is in the profile\'s cash account');
    $s = Teller::openShift();
    Teller::close((int)$s['id'], ['_total' => Dec::add($s['opening_cash'], '300', 2)], '', 'teller.shift');
    DB::update('roles', ['coop_cash_account_id' => null], 'id = :id', ['id' => $roleId]);
    asUser('gm');
});

test('Error correct: the erroneous transaction is negated in full (negative in its column), the entry reversed, never twice; a voided correction frees it again', function () {
    global $ids;
    asUser('teller1');
    shift(today());
    $m = newMember('Mekeni', 'Kmf');
    $rs = svOpen($m, 'RS');
    $liab = Savings::ledgerAccount(Savings::account($rs));
    $cash = (int)$ids['cash.HO'];
    svPost(['account_id' => $rs, 'code' => 'CSD', 'amount' => '20000']);
    $w = svPost(['account_id' => $rs, 'code' => 'CSW', 'amount' => '8033.31']);
    refuses(fn() => svPost(['account_id' => $rs, 'code' => 'ECSW', 'amount' => '8033.31']), 'erroneous', 'an error correction without the transaction it corrects');
    refuses(fn() => svPost(['account_id' => $rs, 'code' => 'ECSW', 'amount' => '8000', 'corrects_id' => $w['sav_txn_id']]), 'whole transaction', 'a partial negation');
    $first = (int)DB::value("SELECT id FROM sav_txns WHERE account_id = :a AND txn_code = 'CSD'", ['a' => $rs]);
    refuses(fn() => svPost(['account_id' => $rs, 'code' => 'ECSW', 'amount' => '20000', 'corrects_id' => $first]), 'corrects CSW', 'ECSW on a CSD transaction');
    $ec = svPost(['account_id' => $rs, 'code' => 'ECSW', 'amount' => '8033.31', 'corrects_id' => $w['sav_txn_id']]);
    $row = svRow($ec['sav_txn_id']);
    eqm($row['debit'], '-8033.31', 'ECSW is written -8,033.31 in the withdrawal column (as the passbook prints it)');
    eqm($row['credit'], '0', 'nothing in the deposit column');
    eqm($row['balance_after'], '20000', 'the balance goes back up');
    eq((int)svRow($w['sav_txn_id'])['corrected_by_id'], $ec['sav_txn_id'], 'the erroneous withdrawal is marked corrected');
    $g = svGl((int)$ec['txn_id']);
    eqm($g[$cash] ?? '0', '8033.31', 'the reversal: Dr the teller\'s cash');
    eqm($g[$liab] ?? '0', '-8033.31', 'Cr the member\'s savings');
    refuses(fn() => svPost(['account_id' => $rs, 'code' => 'ECSW', 'amount' => '8033.31', 'corrects_id' => $w['sav_txn_id']]), 'already corrected', 'negating it twice');
    eq(DB::value("SELECT receipt_kind FROM coop_receipts WHERE txn_id = :t", ['t' => $ec['txn_id']]), 'sd', 'cash comes back into the drawer (SD)');
    // ECSD of a deposit: negative in the deposit column, the balance goes down
    $d = svPost(['account_id' => $rs, 'code' => 'CSD', 'amount' => '3878.60']);
    $ec2 = svPost(['account_id' => $rs, 'code' => 'ECSD', 'amount' => '3878.60', 'corrects_id' => $d['sav_txn_id']]);
    eqm(svRow($ec2['sav_txn_id'])['credit'], '-3878.60', 'ECSD: -3,878.60 in the deposit column');
    eqm(Savings::account($rs)['balance'], '20000', 'the deposit is undone');
    // voiding the correction: the original may be corrected again
    asUser('gm');
    Txn::void((int)$ec2['txn_id'], 'Corrected the wrong deposit', Auth::user());
    check(svRow($d['sav_txn_id'])['corrected_by_id'] === null, 'a voided correction frees the transaction it negated');
    eqm(Savings::account($rs)['balance'], '23878.60', 'and the deposit counts again');
    // a check deposit still clearing that is negated stops floating
    asUser('teller1');
    $ck = svPost(['account_id' => $rs, 'code' => 'CK3', 'amount' => '5000', 'cheques' => [['cheque_no' => '881234', 'bank' => 'BDO', 'date' => today(), 'amount' => '5000']]]);
    eqm(Savings::account($rs)['uncleared'], '5000', 'the check floats');
    $eck = svPost(['account_id' => $rs, 'code' => 'ECK3', 'amount' => '5000', 'corrects_id' => $ck['sav_txn_id']]);
    $acc = Savings::account($rs);
    eqm($acc['uncleared'], '0', 'negated: nothing floats');
    eqm($acc['balance'], '23878.60', 'and the balance is back');
    $coci = (int)Savings::code('CK3')['opposite_account_id'];
    eqm(svGl((int)$eck['txn_id'])[$coci] ?? '0', '-5000', 'ECK3 takes the check out of the clearing account');
    asUser('gm');
    assertLedgerBalanced('after the error corrections');
    assertSlGl('after the error corrections');
});

test('Check deposits, memos, back entries and deletion: cheque details required; a memo takes the GL account chosen; a back entry needs a supervisor; a printed transaction is corrected, not deleted', function () {
    global $ids;
    asUser('teller1');
    shift(today());
    $m = newMember('Memo', 'Teller');
    $rs = svOpen($m, 'RS');
    $liab = Savings::ledgerAccount(Savings::account($rs));
    refuses(fn() => svPost(['account_id' => $rs, 'code' => 'CK3', 'amount' => '1000']), 'cheque details', 'a check deposit without its cheques');
    refuses(fn() => svPost(['account_id' => $rs, 'code' => 'CK3', 'amount' => '1000', 'cheques' => [['cheque_no' => 'A1', 'bank' => 'BPI', 'amount' => '600']]]), 'add up', 'cheques that do not add up');
    $ck = svPost(['account_id' => $rs, 'code' => 'CK3', 'amount' => '1000', 'cheques' => [['cheque_no' => 'A1', 'bank' => 'BPI', 'amount' => '600'], ['cheque_no' => 'A2', 'bank' => 'BPI', 'amount' => '400']]]);
    $row = svRow($ck['sav_txn_id']);
    eq(count(json_decode((string)$row['cheque_json'], true)), 2, 'the cheque details are kept with the transaction');
    eq($row['clearing_date'], Coop::addDays(today(), 3), 'CK3 clears in 3 days');
    eq(DB::value('SELECT payment_mode FROM coop_receipts WHERE txn_id = :t', ['t' => $ck['txn_id']]), 'check', 'on the teller\'s list as a check (not drawer cash)');

    svPost(['account_id' => $rs, 'code' => 'CSD', 'amount' => '2000']);
    $income = (int)DB::value("SELECT id FROM accounts WHERE category = 'Income' AND is_active = 1 ORDER BY code LIMIT 1");
    refuses(fn() => svPost(['account_id' => $rs, 'code' => 'DM', 'amount' => '50']), 'GL account', 'a memo without its GL account');
    refuses(fn() => svPost(['account_id' => $rs, 'code' => 'CM', 'amount' => '200', 'gl_account_id' => $income]), 'Supervisor approval', 'a teller\'s memo needs a supervisor');
    asUser('gm');
    $cm = svPost(['account_id' => $rs, 'code' => 'CM', 'amount' => '200', 'gl_account_id' => $income]);
    $g = svGl((int)$cm['txn_id']);
    eqm($g[$income] ?? '0', '200', 'CM: Dr the GL account chosen');
    eqm($g[$liab] ?? '0', '-200', 'CM: Cr the member\'s savings');

    asUser('teller1');
    refuses(fn() => svPost(['account_id' => $rs, 'code' => 'CSD', 'amount' => '100', 'date' => Coop::addDays(today(), -1), 'back_entry' => true]), 'back-dated', 'a back entry beyond the days the policy allows');
    Settings::set('coop_backdate_days', '3');
    $be = svPost(['account_id' => $rs, 'code' => 'CSD', 'amount' => '100', 'date' => Coop::addDays(today(), -1), 'back_entry' => true]);
    Settings::set('coop_backdate_days', '0');
    $row = svRow($be['sav_txn_id']);
    check((int)$row['is_back_entry'] === 1 && $row['txn_date'] === Coop::addDays(today(), -1), 'the back entry is dated yesterday and marked');
    eq((int)DB::value('SELECT is_back_entry FROM coop_receipts WHERE txn_id = :t', ['t' => $be['txn_id']]), 1, 'its slip is marked back entry');

    // deletion: the teller's own transaction of today, not yet in the passbook
    $x = svPost(['account_id' => $rs, 'code' => 'CSD', 'amount' => '75']);
    $before = Savings::account($rs)['balance'];
    SavTxn::delete((int)$x['sav_txn_id'], 'Wrong account', 'sav.transact');
    eq((int)svRow($x['sav_txn_id'])['is_voided'], 1, 'the deleted transaction is voided, kept on file');
    eqm(Savings::account($rs)['balance'], Dec::sub($before, '75', 2), 'and the account is reversed');
    $y = svPost(['account_id' => $rs, 'code' => 'CSD', 'amount' => '80']);
    DB::update('sav_txns', ['pb_printed' => 1, 'pb_page' => 1, 'pb_line' => 1], 'id = :id', ['id' => $y['sav_txn_id']]);
    refuses(fn() => SavTxn::delete((int)$y['sav_txn_id'], 'Oops', 'sav.transact'), 'Error Correct', 'a transaction already printed in the passbook');
    asUser('gm');
    assertLedgerBalanced('after memos, back entries and deletions');
    assertSlGl('after memos, back entries and deletions');
});

test('R Check, close and delete account: a returned check is debited (clearing account, or the bank once cleared); closing pays the balance out with the closing code; only an unused account is deleted', function () {
    global $ids;
    asUser('teller1');
    shift(today());
    $m = newMember('Returned', 'Check');
    $rs = svOpen($m, 'RS');
    $liab = Savings::ledgerAccount(Savings::account($rs));
    svPost(['account_id' => $rs, 'code' => 'CSD', 'amount' => '9000']);
    $ck = svPost(['account_id' => $rs, 'code' => 'CK4', 'amount' => '3000', 'cheques' => [['cheque_no' => '700001', 'bank' => 'Metrobank', 'amount' => '3000']]]);
    $coci = (int)Savings::code('CK4')['opposite_account_id'];
    $rt = SavTxn::returnCheck((int)$ck['sav_txn_id'], today(), 'DAIF', null, 'RTC', 'sav.center');
    $g = svGl((int)$rt['txn_id']);
    eqm($g[$coci] ?? '0', '-3000', 'a check still clearing comes out of the clearing account');
    eqm($g[$liab] ?? '0', '3000', 'and is debited to the member\'s savings');
    $acc = Savings::account($rs);
    check(Dec::cmp($acc['uncleared'], '0') === 0 && Dec::cmp($acc['balance'], '9000') === 0, 'nothing floats and the balance is the cash deposit');
    check(!empty(svRow($rt['sav_txn_id'])['trans_no']), 'the returned check is a transaction of the center');
    refuses(fn() => SavTxn::returnCheck((int)$ck['sav_txn_id'], today(), 'again', null, 'RTC', 'sav.center'), 'already returned', 'the same check returned twice');
    // a check already cleared: the bank charged it back
    $ck2 = svPost(['account_id' => $rs, 'code' => 'CK4', 'amount' => '1200', 'cheques' => [['cheque_no' => '700002', 'bank' => 'Metrobank', 'amount' => '1200']]]);
    $bank = (int)DB::value("SELECT id FROM accounts WHERE code = '1010'") ?: (int)DB::value("SELECT id FROM accounts WHERE account_type = 'Bank' ORDER BY code DESC LIMIT 1");
    Savings::clearCheck((int)$ck2['sav_txn_id'], $bank, today());
    refuses(fn() => SavTxn::returnCheck((int)$ck2['sav_txn_id'], today(), 'DAIF', null, 'RTC', 'sav.center'), 'bank account', 'a cleared check without the bank that charged it back');
    $rt2 = SavTxn::returnCheck((int)$ck2['sav_txn_id'], today(), 'DAIF', $bank, 'RTC', 'sav.center');
    eqm(svGl((int)$rt2['txn_id'])[$bank] ?? '0', '-1200', 'a cleared check: Cr the bank');

    // closing with a balance: paid out with the closing code, then closed
    svCloseShift();
    asUser('gm');
    shift(today());
    $out = CoopLedger::withClass((int)$ids['class.HO'], fn() => SavTxn::closeAccount($rs, 'Member request', '', 'sav.transact'));
    $acc = Savings::account($rs);
    eq($acc['status'], 'closed', 'the account is closed');
    eqm($acc['balance'], '0', 'with nothing left');
    eq(svRow($out['sav_txn_id'])['txn_code'], 'CSW', 'the balance was paid out with the closing code CSW');
    eqm($out['amount'], '9000', 'the whole balance');
    // a closing fee: charged first with the fee code, to the code's GL account or Other Cooperative Income
    $rs2 = svOpen($m, 'RS');
    svPost(['account_id' => $rs2, 'code' => 'CSD', 'amount' => '1500']);
    refuses(fn() => CoopLedger::withClass((int)$ids['class.HO'], fn() => SavTxn::closeAccount($rs2, 'Moving away', '2000', 'sav.transact')), 'more than the balance', 'a fee above the balance');
    $out2 = CoopLedger::withClass((int)$ids['class.HO'], fn() => SavTxn::closeAccount($rs2, 'Moving away', '50', 'sav.transact'));
    $fee = DB::row("SELECT * FROM sav_txns WHERE account_id = :a AND txn_code = 'CF' AND is_voided = 0", ['a' => $rs2]);
    check($fee && !empty($fee['trans_no']), 'the closing fee is a transaction of the center (CF)');
    eqm($fee['debit'], '50', 'the fee');
    eqm(svGl((int)$fee['txn_id'])[Accts::get('coop_other_income')] ?? '0', '-50', 'CF has no GL account of its own: Cr Other Cooperative Income');
    eqm($out2['amount'], '1450', 'the rest paid out with the closing code');
    eq(Savings::account($rs2)['status'], 'closed', 'and the account closed');
    // the closing codes are a setting of Savings Transaction Codes
    Settings::set('coop_sav_closing_code', 'CTDW');
    eq(Coop::setting('sav_closing_code', 'CSW'), 'CTDW', 'the closing withdrawal code is set');
    Settings::set('coop_sav_closing_code', 'CSW');
    // delete: only an account with no transaction at all
    $spare = openAccount($m, 'SS', today());
    SavTxn::deleteAccount($spare, 'Opened by mistake', 'sav.accounts');
    check(!DB::value('SELECT id FROM sav_accounts WHERE id = :a', ['a' => $spare]), 'an unused account is deleted');
    refuses(fn() => SavTxn::deleteAccount($rs, 'Not needed', 'sav.accounts'), 'has transactions', 'an account with transactions');
    svCloseShift();
    assertLedgerBalanced('after returned checks and closing');
    assertSlGl('after returned checks and closing');
});

test('Time deposit in the center and its accrued interest withdrawal (CAIW): numbered transactions; the interest paid comes out of the accrual, the principal stays; the settlement pays the rest; a void puts it back', function () {
    global $ids;
    asUser('teller1');
    shift(today());
    $m = newMember('Tamides', 'Mario');
    [$pid, $txnId] = CoopLedger::withClass((int)$ids['class.HO'], fn() => TimeDeposits::place($m, (int)$ids['sav.TD'], '200000', 365, today(), ['kind' => 'cash', 'cash_account_id' => (int)$ids['cash.HO']],
        ['shift_id' => Teller::openShift()['id'], 'rate' => '4.0000'], 'sav.td'));
    // 200 days have passed since the placement
    DB::update('td_placements', ['placement_date' => Coop::addDays(today(), -200), 'maturity_date' => Coop::addDays(today(), 165)], 'id = :id', ['id' => $pid]);
    $t = Txn::load($txnId);
    eq($t['txn_type'], 'coop_sv', 'the placement is a Savings Transaction');
    $row = DB::row('SELECT * FROM sav_txns WHERE txn_id = :t', ['t' => $txnId]);
    check($row['trans_no'] !== null && $row['trans_no'] === $t['txn_no'] && $row['txn_code'] === 'CTD', 'with its transaction number and code CTD');
    asUser('gm');
    TimeDeposits::accrue(Coop::addDays(today(), -30), Coop::addDays(today(), -30), 'sav.td');
    $td = TimeDeposits::placement($pid);
    $earned = TimeDeposits::earnedThrough($td, today());
    eqm(SavTxn::tdWithdrawable($td), $earned, 'the accrued interest that can be withdrawn: earned to date');
    asUser('teller1');
    refuses(fn() => SavTxn::tdInterestWithdraw($pid, Dec::add($earned, '1', 2), today(), 'sav.td'), 'can be withdrawn', 'more than the accrued interest');
    $accAcct = (int)DB::value("SELECT accrued_account_id FROM sav_products WHERE id = :p", ['p' => $ids['sav.TD']]);
    $glAcc0 = gl($accAcct);
    $amount = '1000.00';
    $w = SavTxn::tdInterestWithdraw($pid, $amount, today(), 'sav.td');
    $caiw = svRow($w['sav_txn_id']);
    check($caiw['txn_code'] === 'CAIW' && $caiw['trans_no'] === $w['trans_no'] && $caiw['description'] === 'TD Accrued Interest withdrawal', 'CAIW on the center with its number and memo');
    eqm(Savings::account((int)$td['account_id'])['balance'], '200000', 'the principal stays');
    $td = TimeDeposits::placement($pid);
    eqm($td['interest_paid'], $amount, 'the interest paid is kept');
    eqm(bcsub(gl($accAcct), $glAcc0, 2), $amount, 'the withdrawal comes out of the accrued interest payable');
    eqm(SavTxn::tdWithdrawable($td), Dec::sub($earned, $amount, 2), 'what is left to withdraw');
    eq(DB::value('SELECT receipt_kind FROM coop_receipts WHERE txn_id = :t', ['t' => $w['txn_id']]), 'sw', 'paid from the teller\'s drawer (SW)');
    // a void puts the interest back
    asUser('gm');
    Txn::void((int)$w['txn_id'], 'Wrong certificate', Auth::user());
    $td = TimeDeposits::placement($pid);
    eqm($td['interest_paid'], '0', 'the void puts the interest back as unpaid');
    asUser('teller1');
    $w2 = SavTxn::tdInterestWithdraw($pid, '500', today(), 'sav.td');
    // pre-termination pays the interest due less what was withdrawn
    svCloseShift();
    asUser('gm');
    shift(today());
    [$sTxn] = CoopLedger::withClass((int)$ids['class.HO'], fn() => TimeDeposits::settle($pid, today(), 'payout', ['cash_account_id' => (int)$ids['cash.HO'], 'shift_id' => Teller::openShift()['id']], 'sav.td'));
    $st = Txn::load($sTxn);
    eq($st['txn_type'], 'coop_sv', 'the closing is a Savings Transaction too');
    check(DB::value("SELECT trans_no FROM sav_txns WHERE txn_id = :t AND txn_code = 'CTDW'", ['t' => $sTxn]) !== null, 'CTDW with its transaction number');
    $td = TimeDeposits::placement($pid);
    eq($td['status'], 'preterminated', 'closed before maturity');
    svCloseShift();
    assertLedgerBalanced('after the time deposit transactions');
    assertSlGl('after the time deposit transactions');
});

test('Passbook: line numbers, the account name and number on every page, the fold left blank, a dash in the empty column, error corrections negative, and the print line reset', function () {
    global $ids;
    $pbType = (int)DB::value("SELECT id FROM passbook_types WHERE name = 'Regular Passbook'");
    DB::update('passbook_types', ['columns_json' => json_encode(['line' => 100, 'date' => 400, 'code' => 1500, 'credit' => 2700, 'debit' => 4500, 'balance' => 6300, 'teller' => 8000]),
        'header_json' => json_encode(['name' => [700, 300], 'number' => [700, 7000]]), 'skip_lines' => '12', 'start_line_skip' => 0, 'max_lines' => 25, 'dash_empty' => 1], 'id = :id', ['id' => $pbType]);
    asUser('teller1');
    shift(today());
    $m = newMember('Passbook', 'Lines');
    $rs = svOpen($m, 'RS');
    for ($i = 1; $i <= 11; $i++) {
        svPost(['account_id' => $rs, 'code' => 'CSD', 'amount' => (string)(1000 * $i)]);
    }
    $w = svPost(['account_id' => $rs, 'code' => 'CSW', 'amount' => '8033.31']);
    svPost(['account_id' => $rs, 'code' => 'ECSW', 'amount' => '8033.31', 'corrects_id' => $w['sav_txn_id']]);
    $acct = Savings::account($rs);
    $pb = Passbook::open('savings', $acct['account_no']);
    $plan = Passbook::plan($pb);
    eq(array_column($plan['lines'], 'line'), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 14], 'the lines go 1 to 11, skip the fold (12) and go on at 13');
    eq($plan['lines'][0]['teller'], (string)(int)$ids['user.teller1'], 'the teller is printed by ID number');
    $html = Passbook::render($pb, $plan['page'], $plan['lines'], Passbook::headerDue($pb, $plan));
    check(str_contains($html, '>' . h($acct['member_name']) . '<') && str_contains($html, '>' . h($acct['account_no']) . '<'), 'the account name and number are on the page (a new page: the header first)');
    check(str_contains($html, '>-8,033.31<'), 'the error correction prints -8,033.31 under withdrawals');
    check(str_contains($html, '>13<') && !str_contains($html, '>12<'), 'line numbers are printed as a guide, none on the fold');
    check(str_contains($html, '>-<'), 'a dash fills the empty amount column');
    $map = [];
    foreach ($plan['lines'] as $l) {
        $map[$l['id']] = $l['line'];
    }
    Passbook::commit($pb, $plan['page'], $map, 'sav.passbook');
    $pb = Passbook::open('savings', $acct['account_no']);
    eq(Passbook::next($pb), [1, 15], 'the next line is 15');
    // a misprint: the print line goes back to line 5, and the transactions from the fifth print again
    $fifth = (int)DB::value('SELECT id FROM sav_txns WHERE account_id = :a ORDER BY id LIMIT 1 OFFSET 4', ['a' => $rs]);
    $n = Passbook::resetLine($pb, 1, 5, $fifth, 'Paper jam on line 5', 'sav.passbook');
    eq($n, 9, 'nine transactions print again');
    $pb = Passbook::open('savings', $acct['account_no']);
    $plan = Passbook::plan($pb);
    eq([$plan['page'], $plan['lines'][0]['line'], $plan['lines'][0]['id']], [1, 5, $fifth], 'printing starts again on page 1 line 5 with the fifth transaction');
    refuses(fn() => Passbook::resetLine($pb, 1, 30, null, 'x', 'sav.passbook'), 'line from 1 to 25', 'a line beyond the page');
    svCloseShift();
    // the inside cover: account name, number, address, contact and branch at their positions
    DB::update('passbook_types', ['cover_json' => json_encode(['name' => [1500, 3000], 'number' => [2100, 3000], 'address' => [2700, 3000], 'contact' => [3300, 3000], 'branch' => [3900, 3000]])], 'id = :id', ['id' => $pbType]);
    $pb = Passbook::open('savings', $acct['account_no']);
    $cover = Passbook::renderCover($pb);
    check(str_contains($cover, '>' . h($acct['account_no']) . '<') && str_contains($cover, 'top:1.4583in') && str_contains($cover, 'Head Office'), 'the cover prints the account number at its position and the branch');
    asUser('gm');
});
