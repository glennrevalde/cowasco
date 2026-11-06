<?php
declare(strict_types=1);

/**
 * The Loan Collection Form of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v4.xlsx at the level of the program's rules:
 * the figures of the form (balances, dues as of the date, the number of amortizations), payments by cash, check and
 * bank, the error correction of an erroneous payslip (a ledger row of its own with the amounts negative, the journal
 * entry reversed line for line, the schedule, balances, status and last payment date put back, the cash paid back
 * from the drawer, a payoff opening the loan again), the passbook and the payslip validation of a correction, the
 * deletion of a collection, the list of collections, and the margins of the slip validation.
 */

/** a member with share capital and a Regular Loan released 75 days ago (two amortizations due today) */
function lcLoan(string $last, string $first, string $amount = '24000'): array
{
    Auth::actAs($GLOBALS['adminId']);
    $m = newMember($last, $first);
    $sc = openAccount($m, 'SC', '2026-01-02');
    shift(today());
    receipt($m, today(), [['kind' => 'savings', 'account_id' => $sc, 'amount' => '2500']]);
    $rel = date('Y-m-d', strtotime(today() . ' -75 days'));
    $app = approvedLoan($m, 'REG', $amount, '12', $rel);
    $r = releaseLoan($app, $rel, date('Y-m-d', strtotime($rel . ' +1 month')));
    return ['member' => $m, 'loan' => (int)$r['loan_id']];
}

/** a payment saved by the form: one loan, the head office class */
function lcPay(int $memberId, int $loanId, string $amount, array $pay = ['mode' => 'cash'], array $item = []): array
{
    global $ids;
    return CoopLedger::withClass((int)$ids['class.HO'], fn() => Teller::receipt($memberId, today(), [['kind' => 'loan', 'loan_id' => $loanId, 'amount' => $amount] + $item], $pay, [], 'loan.collection'));
}

/** Error Correct on the form */
function lcEc(int $loanTxnId, string $memo = 'Posted by mistake'): array
{
    global $ids;
    return CoopLedger::withClass((int)$ids['class.HO'], fn() => LoanTxn::errorCorrect($loanTxnId, today(), $memo, 'loan.collection'));
}

function lcPayRow(int $txnId): array
{
    return DB::row("SELECT * FROM loan_txns WHERE txn_id = :t AND kind = 'payment'", ['t' => $txnId]);
}

/** the penalties through today posted, as the receipt does first, and the status of the loan as of today */
function lcPenalties(int $loanId): void
{
    global $ids;
    CoopLedger::withClass((int)$ids['class.HO'], fn() => DB::tx(function () use ($loanId) {
        Loans::chargePenalty(Loans::loan($loanId, true), today());
        Loans::refreshBalances($loanId);
    }));
}

/** what an error correction must put back: the balances, the schedule paid, the status and the last payment date */
function lcState(int $loanId): array
{
    $l = Loans::loan($loanId);
    $s = DB::row('SELECT COALESCE(SUM(principal_paid),0) AS p, COALESCE(SUM(interest_paid),0) AS i, COALESCE(SUM(is_paid),0) AS n FROM loan_schedule WHERE loan_id = :l', ['l' => $loanId]);
    return ['principal' => Dec::fix($l['principal_balance']), 'interest' => Dec::fix($l['interest_balance']), 'penalty' => Dec::fix($l['penalty_balance']), 'status' => $l['status'],
        'last_payment' => $l['last_payment_date'], 'schedule_principal' => Dec::fix($s['p']), 'schedule_interest' => Dec::fix($s['i']), 'amortizations_paid' => (int)$s['n'],
        'accrued' => Dec::fix($l['interest_accrued'])];
}

test('Loan Collection Form: the figures of the form (balances, dues as of the date, number of amortizations, last payment, payoff) and the open loans', function () {
    ['member' => $m, 'loan' => $loanId] = lcLoan('Tajanlangit', 'Glenn');
    $loan = Loans::loan($loanId);
    $d = Loans::dues($loan, today());
    $f = LoanTxn::dueDetails($loan, today());
    eqm($f['principal']['balance'], $loan['principal_balance'], 'Principal: the loan balance');
    eqm($f['interest']['balance'], $loan['interest_balance'], 'Interest: the interest balance');
    eqm($f['principal']['due'], $d['principal_due'], 'Principal: the amortization due as of the date (the schedule)');
    eqm($f['interest']['due'], $d['interest_due'], 'Interest: the interest due by the schedule');
    eqm($f['penalty']['due'], $d['penalty_due'], 'Penalty: the penalty due as of the date');
    eqm($f['penalty']['balance'], bcadd((string)$loan['penalty_balance'], (string)$d['penalty_new'], 2), 'Penalty: posted plus earned through the date');
    $inst = DB::row('SELECT principal, interest FROM loan_schedule WHERE loan_id = :l AND is_paid = 0 ORDER BY inst_no LIMIT 1', ['l' => $loanId]);
    eq($f['principal']['count'], r2s(bcdiv((string)$d['principal_due'], (string)$inst['principal'], 6)), '#of Amort. of the principal: the due over one amortization');
    eq($f['interest']['count'], r2s(bcdiv((string)$d['interest_due'], (string)$inst['interest'], 6)), '#of Amort. of the interest');
    eq((int)$d['count_due'], 2, 'two amortizations due 75 days after the release');
    check(bccomp($f['principal']['count'], '1.95', 2) > 0 && bccomp($f['principal']['count'], '2.05', 2) < 0, 'about two amortizations of principal (the instalments differ by centavos): ' . $f['principal']['count']);
    eq($f['last_payment'], null, 'no payment yet');
    eqm($f['payoff'], $d['payoff'], 'the payoff of the loan');
    eqm($f['total_due'], $d['total_due'], 'the total due');
    check(in_array($loanId, array_map('intval', array_column(LoanTxn::openLoans($m), 'id')), true), 'the loan is among the member\'s open loans');
    $GLOBALS['lc'] = ['member' => $m, 'loan' => $loanId];
});

test('Loan Collection Form: a check payment and its error correction (EC row negative, journal reversed, balances, schedule, status and last payment back, the cheques off the clearing account); the passbook, the validation and the list show both', function () {
    global $ids;
    ['member' => $m, 'loan' => $loanId] = $GLOBALS['lc'];
    $loan = Loans::loan($loanId);
    asUser('teller1');
    shift(today(), '1000');
    lcPenalties($loanId);
    $before = lcState($loanId);
    $recv = (int)$loan['receivable_account_id'];
    $recv0 = gl($recv);

    // the amount of a check payment is the total of its cheques
    refuses(fn() => lcPay($m, $loanId, '3000', ['mode' => 'check', 'cheques' => [['cheque_no' => 'C-101', 'bank' => 'BPI', 'date' => today(), 'amount' => '1800']]]), 'must add up', 'the cheques must add up to the payment');
    $r = lcPay($m, $loanId, '3000', ['mode' => 'check', 'cheques' => [['cheque_no' => 'C-101', 'bank' => 'BPI', 'date' => today(), 'amount' => '1800'], ['cheque_no' => 'C-102', 'bank' => 'BDO', 'date' => today(), 'amount' => '1200']]]);
    $p = lcPayRow((int)$r['txn_id']);
    $after = lcState($loanId);
    eqm(bcadd(bcadd((string)$p['penalty_cr'], (string)$p['interest_cr'], 2), (string)$p['principal_cr'], 2), '3000', 'the payment applied to the loan');
    check(bccomp((string)$p['penalty_cr'], '0', 2) > 0, 'penalties first (the default order of the application of payment)');
    eq($after['last_payment'], today(), 'the last payment date');
    eqm(DB::value('SELECT check_pending FROM coop_receipts WHERE txn_id = :t', ['t' => $r['txn_id']]), '3000', 'the cheques wait in the clearing account');
    eqm(bcsub(gl($recv), $recv0, 2), bcmul((string)$p['principal_cr'], '-1', 2), 'the principal paid off Loans Receivable');

    // the payslip validation of the payment
    $v = LoanTxn::validation((int)$r['txn_id']);
    eq($v['lines'][0][0], $r['receipt_no'], 'validation line 1: the OR number');
    eq($v['lines'][0][1], $loan['loan_no'], 'validation line 1: the loan');
    eq($v['lines'][1][1], 'LP', 'validation line 2: LP (loan payment)');
    eq($v['lines'][1][2], '3,000.00', 'validation line 2: the amount');
    eq($v['lines'][1][3], (string)$ids['user.teller1'], 'validation line 2: the teller by ID');

    // another user's payment needs a supervisor (a user who cannot approve on the page asks one)
    asUser('loans');
    refuses(fn() => lcEc((int)$p['id']), 'Supervisor approval is required', 'correcting another teller\'s payment needs a supervisor');
    asUser('teller1');
    $yesterday = date('Y-m-d', strtotime(today() . ' -1 day'));
    refuses(fn() => LoanTxn::errorCorrect((int)$p['id'], $yesterday, 'x', 'loan.collection'), 'Back-dated', 'a teller does not back-date a correction');
    Auth::actAs($GLOBALS['adminId']);
    refuses(fn() => LoanTxn::errorCorrect((int)$p['id'], $yesterday, 'x', 'loan.collection'), 'before the payment', 'a correction is not dated before the payment');
    asUser('teller1');
    $ec = lcEc((int)$p['id'], 'Posted to the wrong member');
    $e = DB::row('SELECT * FROM loan_txns WHERE id = :id', ['id' => $ec['loan_txn_id']]);
    eq($e['kind'], 'correction', 'Error Correct: a row of its own on the loan ledger');
    eq((int)$e['corrects_id'], (int)$p['id'], 'linked to the payment it negates');
    eq((int)DB::value('SELECT corrected_by_id FROM loan_txns WHERE id = :id', ['id' => $p['id']]), (int)$e['id'], 'and the payment to its correction');
    foreach (['principal_cr', 'interest_cr', 'penalty_cr'] as $k) {
        eqm($e[$k], bcmul((string)$p[$k], '-1', 2), "the correction: $k negative");
    }
    eqm($ec['amount'], '-3000', 'the correction is -3,000.00');
    eq(lcState($loanId), $before, 'the balances, the schedule, the status and the last payment date are back as before the payment');
    $t = Txn::load((int)$ec['txn_id']);
    eq($t['txn_type'], 'coop_jv', 'a check payment is corrected by a journal voucher');
    $g1 = svGl((int)$r['txn_id']);
    $g2 = svGl((int)$ec['txn_id']);
    eq(count($g2), count($g1), 'the correction has the accounts of the receipt');
    foreach ($g1 as $a => $net) {
        eqm($g2[$a] ?? '0', bcmul($net, '-1', 2), 'the receipt\'s journal entry reversed: account ' . DB::value('SELECT code FROM accounts WHERE id = :a', ['a' => $a]));
    }
    eqm(gl($recv), $recv0, 'Loans Receivable back');
    eqm(DB::value('SELECT check_pending FROM coop_receipts WHERE txn_id = :t', ['t' => $r['txn_id']]), '0', 'the cheques no longer wait for clearing');
    check((bool)DB::value("SELECT id FROM audit_trail WHERE action = 'error_correct' AND record_type = 'loan_txn' AND record_id = :r", ['r' => $e['id']]), 'the correction is in the audit trail');

    // its validation, the passbook and the list
    $v2 = LoanTxn::validation((int)$ec['txn_id']);
    eq($v2['lines'][1][1], 'ECLP', 'the correction validates as ECLP');
    eq($v2['lines'][1][2], '-3,000.00', 'negative');
    $lines = Passbook::pending(Passbook::open('loan', (string)$loan['loan_no']));
    $pl = array_values(array_filter($lines, fn($x) => $x['id'] === (int)$p['id']))[0] ?? null;
    $el = array_values(array_filter($lines, fn($x) => $x['id'] === (int)$e['id']))[0] ?? null;
    check($pl && $el, 'the passbook keeps both lines');
    eq($pl['ref'], $r['receipt_no'], 'the payment prints with its OR number');
    eq([$el['code'], substr($el['ref'], 0, 3)], ['EC', 'EC '], 'the correction prints as EC');
    eqm($el['total'], '-3000', 'in the payment columns, negative');
    eq($el['teller'], (string)$ids['user.teller1'], 'with the teller\'s ID');
    $list = LoanTxn::collections(today(), today(), (string)$loan['loan_no']);
    $amounts = array_map(fn($x) => bcadd((string)$x['amount'], '0', 2), $list);
    sort($amounts);
    eq($amounts, ['-3000.00', '3000.00'], 'the list of loan collections shows the payment and its correction');
    eq(LoanTxn::ref(array_values(array_filter($list, fn($x) => $x['kind'] === 'correction'))[0]), $ec['ref'], 'the correction is listed by its voucher number');

    // what cannot be done
    refuses(fn() => lcEc((int)$p['id']), 'already corrected', 'a payment is corrected once');
    refuses(fn() => LoanTxn::delete((int)$p['id'], 'Oops', 'loan.collection'), 'Delete the correction first', 'a corrected payment is not deleted');
    refuses(fn() => Txn::void((int)$r['txn_id'], 'Oops'), 'error correction', 'nor voided while its correction stands');
    refuses(fn() => LoanTxn::delete((int)$e['id'], '', 'loan.collection'), 'reason', 'a deletion needs the reason');

    // deleting the correction (the teller's own, today) puts the payment back
    LoanTxn::delete((int)$e['id'], 'Corrected the wrong payment', 'loan.collection');
    eq((int)DB::value('SELECT is_voided FROM loan_txns WHERE id = :id', ['id' => $e['id']]), 1, 'the correction is void');
    check(DB::value('SELECT corrected_by_id FROM loan_txns WHERE id = :id', ['id' => $p['id']]) === null, 'the payment is no longer marked corrected');
    eq(lcState($loanId), $after, 'the payment stands again');
    eqm(DB::value('SELECT COALESCE(SUM(check_pending),0) FROM coop_receipts WHERE txn_id = :t', ['t' => $r['txn_id']]), '0', 'the receipt keeps its clearing figure (the check is re-entered or cleared by the bookkeeper)');

    // a collection printed in the passbook is corrected, not deleted; another user's needs a supervisor
    DB::update('loan_txns', ['pb_printed' => 1, 'pb_page' => 1, 'pb_line' => 7], 'id = :id', ['id' => $p['id']]);
    refuses(fn() => LoanTxn::delete((int)$p['id'], 'Oops', 'loan.collection'), 'printed in the passbook', 'a printed collection is not deleted');
    DB::update('loan_txns', ['pb_printed' => 0, 'pb_page' => null, 'pb_line' => null], 'id = :id', ['id' => $p['id']]);
    asUser('loans');
    refuses(fn() => LoanTxn::delete((int)$p['id'], 'Oops', 'loan.collection'), 'Supervisor approval is required', 'another teller\'s collection needs a supervisor');
    asUser('teller1');
    assertLedgerBalanced('after the check payment and its correction');
    assertSlGl('after the check payment and its correction');
});

test('Loan Collection Form: Error Correct of a cash payment pays it back from the drawer by a cash voucher; a payoff corrected opens the loan again (rebate undone, holds back); a receipt with other items and a cleared check are corrected another way; a bank payment by a journal voucher', function () {
    global $ids;
    ['member' => $m, 'loan' => $loanId] = $GLOBALS['lc'];
    asUser('teller1');
    $sid = shift(today(), '1000');
    Teller::refreshShift($sid);
    $cash0 = Teller::expectedCash(Teller::shift($sid));
    $r = lcPay($m, $loanId, '1000');
    Teller::refreshShift($sid);
    eqm(bcsub(Teller::expectedCash(Teller::shift($sid)), $cash0, 2), '1000', 'the cash is in the drawer');
    $ec = lcEc((int)lcPayRow((int)$r['txn_id'])['id'], 'Wrong amount');
    $t = Txn::load((int)$ec['txn_id']);
    eq($t['txn_type'], 'coop_cv', 'a cash payment is paid back by a cash voucher');
    $cv = DB::row('SELECT * FROM coop_receipts WHERE txn_id = :t', ['t' => $ec['txn_id']]);
    check($cv && $cv['receipt_kind'] === 'cv' && (int)$cv['shift_id'] === $sid && $cv['receipt_no'] === $ec['ref'], 'the voucher of the teller\'s shift, numbered in the voucher series');
    Teller::refreshShift($sid);
    eqm(Teller::expectedCash(Teller::shift($sid)), $cash0, 'the drawer is back where it was');

    // a payoff corrected: the loan is open again with its balances, the rebate of the unearned interest undone
    lcPenalties($loanId);
    $before = lcState($loanId);
    $hold = DB::insert('sav_holds', ['account_id' => (int)DB::value("SELECT a.id FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND p.product_type = 'share_capital' LIMIT 1", ['m' => $m]),
        'loan_account_id' => $loanId, 'amount' => '500.00', 'reason' => 'Collateral of the loan', 'placed_by' => Auth::id()]);
    $po = CoopLedger::withClass((int)$ids['class.HO'], fn() => Teller::receipt($m, today(), [['kind' => 'loan', 'loan_id' => $loanId, 'payoff' => true, 'amount' => '0']], ['mode' => 'cash'], [], 'loan.collection'));
    eq(Loans::loan($loanId)['status'], 'paid', 'paid off');
    check(DB::value('SELECT released_at FROM sav_holds WHERE id = :h', ['h' => $hold]) !== null, 'the payoff released the hold on the deposits');
    check(!in_array($loanId, array_map('intval', array_column(LoanTxn::openLoans($m), 'id')), true), 'no longer among the open loans');
    $pid = (int)lcPayRow((int)$po['txn_id'])['id'];
    check(in_array($pid, array_map('intval', array_column(LoanTxn::correctableForMember($m), 'id')), true), 'the payoff can still be corrected on the form');
    $ec2 = lcEc($pid, 'Paid off the wrong loan');
    eq((int)DB::value("SELECT COUNT(*) FROM loan_txns WHERE txn_id = :t AND kind = 'correction'", ['t' => $ec2['txn_id']]),
        (int)DB::value('SELECT COUNT(*) FROM loan_txns WHERE txn_id = :t', ['t' => $po['txn_id']]), 'every row the payoff made (the payment and the rebate) is negated');
    eq(lcState($loanId), $before, 'the loan is open again with its balances, schedule and status');
    eq(Loans::loan($loanId)['closed_on'], null, 'no closing date');
    check(DB::value('SELECT released_at FROM sav_holds WHERE id = :h', ['h' => $hold]) === null, 'the hold on the deposits is back');
    eqm(DB::value('SELECT hold_amount FROM sav_accounts WHERE id = (SELECT account_id FROM sav_holds WHERE id = :h)', ['h' => $hold]), '500', 'and counted on the account');

    // what is corrected another way
    $rs = svOpen($m, 'RS');
    $mix = CoopLedger::withClass((int)$ids['class.HO'], fn() => Teller::receipt($m, today(), [['kind' => 'loan', 'loan_id' => $loanId, 'amount' => '500'], ['kind' => 'savings', 'account_id' => $rs, 'amount' => '200']], ['mode' => 'cash'], [], 'teller.or'));
    refuses(fn() => lcEc((int)lcPayRow((int)$mix['txn_id'])['id']), 'other items', 'a receipt that also holds other items is voided instead');
    $chk = lcPay($m, $loanId, '700', ['mode' => 'check', 'cheques' => [['cheque_no' => 'C-201', 'bank' => 'PNB', 'date' => today(), 'amount' => '700']]]);
    CoopLedger::withClass((int)$ids['class.HO'], fn() => Teller::clearReceiptCheck((int)Teller::receiptByTxn((int)$chk['txn_id'])['id'], $GLOBALS['adminCash'], today()));
    refuses(fn() => lcEc((int)lcPayRow((int)$chk['txn_id'])['id']), 'already cleared', 'a check cleared to the bank is not error-corrected');
    // the bank mode: deposited directly to the cooperative's bank; the member shows the deposit slip
    $bank = lcPay($m, $loanId, '400', ['mode' => 'bank', 'cash_account_id' => $GLOBALS['adminCash']]);
    eq(Teller::receiptByTxn((int)$bank['txn_id'])['payment_mode'], 'bank', 'a bank payment');
    $ec3 = lcEc((int)lcPayRow((int)$bank['txn_id'])['id']);
    eq(Txn::load((int)$ec3['txn_id'])['txn_type'], 'coop_jv', 'a bank payment is corrected by a journal voucher (no cash moves)');
    refuses(fn() => Teller::receipt($m, today(), [['kind' => 'loan', 'loan_id' => $loanId, 'amount' => '100']], ['mode' => 'bank'], [], 'loan.collection'), 'bank account', 'a bank payment names the bank account');
    assertLedgerBalanced('after the cash, payoff and bank corrections');
    assertSlGl('after the cash, payoff and bank corrections');
});

test('Loan Collection Form: Override Payment applies the amount as entered (permission, must add up, within the balances); the list of collections by date range and search', function () {
    global $ids;
    ['member' => $m, 'loan' => $loanId] = lcLoan('Albaran', 'Loreta', '12000');
    $loan = Loans::loan($loanId);
    asUser('teller1');
    shift(today(), '1000');
    refuses(fn() => lcPay($m, $loanId, '1000', ['mode' => 'cash'], ['override' => ['penalty' => '0', 'interest' => '0', 'principal' => '1000']]), 'override', 'a teller without the permission cannot override');
    Auth::actAs($GLOBALS['adminId']);
    refuses(fn() => lcPay($m, $loanId, '1000', ['mode' => 'cash'], ['override' => ['penalty' => '0', 'interest' => '100', 'principal' => '800']]), 'add up', 'the overridden amounts add up to the payment');
    refuses(fn() => lcPay($m, $loanId, '20000', ['mode' => 'cash'], ['override' => ['penalty' => '0', 'interest' => '0', 'principal' => '20000']]), 'cannot exceed', 'not more than the principal balance');
    $r = lcPay($m, $loanId, '1000', ['mode' => 'cash'], ['override' => ['penalty' => '0', 'interest' => '0', 'principal' => '1000']]);
    $p = lcPayRow((int)$r['txn_id']);
    eqm($p['principal_cr'], '1000', 'all to the principal, as overridden');
    eqm($p['penalty_cr'], '0', 'nothing to the penalty');
    check((bool)DB::value("SELECT id FROM audit_trail WHERE action = 'override' AND record_type = 'loan_account' AND record_id = :l AND reason LIKE :r", ['l' => $loanId, 'r' => '%OR ' . $r['receipt_no'] . '%']), 'the override is in the audit trail with the receipt');
    // the list: by date range and search
    $all = LoanTxn::collections(today(), today());
    check(in_array((int)$p['id'], array_map('intval', array_column($all, 'id')), true), 'today\'s list has the payment');
    eq(count(LoanTxn::collections(today(), today(), (string)$loan['loan_no'])), 1, 'search by the loan number');
    eq(count(LoanTxn::collections(today(), today(), 'Albaran')), 1, 'search by the member\'s name');
    eq(count(LoanTxn::collections(today(), today(), (string)$r['receipt_no'])) >= 1, true, 'search by the OR number');
    $y = date('Y-m-d', strtotime(today() . ' -1 day'));
    eq(count(LoanTxn::collections($y, $y, (string)$loan['loan_no'])), 0, 'not in yesterday\'s list');
    // the figures as the teller types: the payment applied penalties, interest, then principal
    $d = Loans::dues(Loans::loan($loanId), today());
    [$pen, $int, $prn] = Loans::allocate($d, '500', null);
    $order = array_map('trim', explode(',', Coop::setting('allocation_order', 'penalty,interest,principal')));
    eq($order, ['penalty', 'interest', 'principal'], 'the default order: due penalties, due interest, due principal');
    eqm(bcadd(bcadd((string)$pen, (string)$int, 2), (string)$prn, 2), '500', 'the payment applied in full');
    eqm($pen, bccomp((string)$d['penalty_due'], '500', 2) < 0 ? (string)$d['penalty_due'] : '500', 'the penalty due is paid first');
    if (bccomp((string)$d['penalty_due'], '500', 2) < 0) {
        eqm($int, bccomp(bcadd((string)$d['penalty_due'], (string)$d['interest_due'], 2), '500', 2) < 0 ? (string)$d['interest_due'] : bcsub('500', (string)$d['penalty_due'], 2), 'then the interest due');
    }
});

test('Slip validation: margins (top, right, bottom, left), line spacing and font from Passbook Types; the former twips positions until they are set', function () {
    if (!function_exists('coop_slip_settings')) {
        $GLOBALS['PAGE'] = ['name' => 'Tests'];
        require_once APP_ROOT . '/modules/coop/_coop.php';
    }
    foreach (['slip_margin_top', 'slip_margin_right', 'slip_margin_bottom', 'slip_margin_left', 'slip_line_gap'] as $k) {
        DB::run('DELETE FROM settings WHERE setting_key = :k', ['k' => 'coop_' . $k]);
    }
    Settings::set('coop_sav_validation_top', '720');
    Settings::set('coop_sav_validation_left', '1440');
    Settings::set('coop_sav_validation_gap', '360');
    Settings::flush();
    $s = coop_slip_settings();
    eq([$s['top'], $s['left'], $s['gap']], [0.5, 1.0, 0.25], 'until the margins are set: the twips of version 31 (720 = 0.5 in, 1440 = 1 in, 360 = 0.25 in)');
    eq([$s['right'], $s['bottom']], [0.25, 0.25], 'right and bottom default to a quarter inch');
    Settings::set('coop_slip_margin_top', '1.2');
    Settings::set('coop_slip_margin_right', '0.4');
    Settings::set('coop_slip_margin_bottom', '0.3');
    Settings::set('coop_slip_margin_left', '2.25');
    Settings::set('coop_slip_line_gap', '0.2');
    Settings::set('coop_sav_validation_font', '12');
    Settings::flush();
    $s = coop_slip_settings();
    eq([$s['top'], $s['right'], $s['bottom'], $s['left'], $s['gap'], $s['font']], [1.2, 0.4, 0.3, 2.25, 0.2, 12.0], 'the margins, the line spacing and the font as set');
    Settings::set('coop_slip_margin_left', '9');
    Settings::flush();
    eq(coop_slip_settings()['left'], 5.0, 'a margin is at most 5 inches');
});
