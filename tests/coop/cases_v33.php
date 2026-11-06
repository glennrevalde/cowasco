<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v5.xlsx at the level of the program's rules:
 *  - Loan Collection and the Savings Transaction Center under the Teller menu, and every transaction of the teller in
 *    them on the Daily Collection & Disbursement Summary;
 *  - the Loan Release Form: Cash or Cheque release from the cash or bank account chosen, the deductions added, deleted
 *    or changed;
 *  - the release posted through Banking (a Cash Voucher, or a check of Write Checks) with its class, its voucher ID and
 *    the monitoring of the loan release vouchers; voided, never edited or deleted;
 *  - the Internal Ledger (additional interest and penalty by the product set-up) and the Loan Ledger Form.
 */

require_once APP_ROOT . '/modules/coop/_loan_release.php';

/** an approved application of a member who has share capital (the Regular Loan needs 1,000 paid-up) */
function v33App(string $last, string $first, string $product = 'REG', string $amount = '15000', string $term = '12'): array
{
    Auth::actAs($GLOBALS['adminId']);
    $m = newMember($last, $first);
    $sc = openAccount($m, 'SC', '2026-01-02');
    shift(today());
    receipt($m, today(), [['kind' => 'savings', 'account_id' => $sc, 'amount' => '2000']]);
    return ['member' => $m, 'sc' => $sc, 'app' => approvedLoan($m, $product, $amount, $term, today())];
}

/** the release as the Loan Release Form posts it, under the head office class */
function v33Release(int $appId, array $r): array
{
    global $ids;
    $start = date('Y-m-d', strtotime(today() . ' +1 month'));
    return CoopLedger::withClass((int)$ids['class.HO'], fn() => Loans::release($appId, $r + ['release_date' => today(), 'start_date' => $start], 'loan.release'));
}

function v33Report(string $key, array $f): array
{
    $def = Report::get($key);
    return ($def['run'])($f + ['user_id' => null, 'branch' => '', 'view' => '', 'kind' => '']);
}

test('Teller menu: Loan Collection and the Savings Transaction Center are under Teller (the page keys and the access stay); a switched-off line still closes them', function () {
    eq(Access::pageDef('loan.collection')['module'], 'teller', 'Loan Collection is in the Teller menu');
    eq(Access::pageDef('sav.center')['module'], 'teller', 'the Savings Transaction Center is in the Teller menu');
    eq((string)DB::value("SELECT m.code FROM pages p JOIN modules m ON m.id = p.module_id WHERE p.page_key = 'loan.collection'"), 'teller', 'the upgrade moved the page of Loan Collection');
    eq((string)DB::value("SELECT m.code FROM pages p JOIN modules m ON m.id = p.module_id WHERE p.page_key = 'sav.center'"), 'teller', 'and the page of the Savings Transaction Center');
    $menu = build_menu();
    $teller = array_merge(...array_map(fn($g) => array_column($g, 'key'), array_values($menu['teller']['groups'] ?? [])));
    check(in_array('loan.collection', $teller, true) && in_array('sav.center', $teller, true), 'both are listed in the Teller menu');
    $loans = array_merge([], ...array_map(fn($g) => array_column($g, 'key'), array_values($menu['loan']['groups'] ?? [])));
    $sav = array_merge([], ...array_map(fn($g) => array_column($g, 'key'), array_values($menu['sav']['groups'] ?? [])));
    check(!in_array('loan.collection', $loans, true), 'no longer in the Loans menu');
    check(!in_array('sav.center', $sav, true), 'no longer in the Savings & Deposits menu');
    check(array_search('loan.collection', $teller, true) < array_search('teller.or', $teller, true), 'after Teller Shift & Cash Count, before the official receipt');
    asUser('teller1');
    check(Access::page('loan.collection') && Access::page('sav.center'), 'the teller keeps the access of the role');
    Auth::actAs($GLOBALS['adminId']);
    // the line of business of a page is the one its key names: Loans off closes Loan Collection, Teller off does not
    Settings::set('disabled_modules', 'loan');
    Access::reset();
    check(!Access::page('loan.collection'), 'Loans switched off: Loan Collection is closed');
    check(Access::page('sav.center'), 'the Savings Transaction Center stays open');
    Settings::set('disabled_modules', 'teller');
    Access::reset();
    check(Access::page('loan.collection') && Access::page('sav.center'), 'Teller switched off: the loans and savings pages stay with their own lines');
    check(!Access::page('teller.or'), 'the official receipt page closes with the Teller line');
    Settings::set('disabled_modules', '');
    Access::reset();
});

test('Daily Collection & Disbursement Summary: every Loan Collection and Savings Transaction Center transaction of the teller, by source, error corrections taken back, net cash = the drawer', function () {
    global $ids;
    $a = v33App('Bacalso', 'Remy', 'REG', '24000');
    $rel = v33Release($a['app'], ['release_mode' => 'cash', 'cash_account_id' => $GLOBALS['adminCash']]);
    $loanId = (int)$rel['loan_id'];
    $m = $a['member'];
    $b = v33App('Lagunzad', 'Nora', 'EMR', '6000', '3');
    asUser('teller1');
    svCloseShift();
    $shiftId = shift(today());
    Teller::refreshShift($shiftId);
    $s0 = Teller::shift($shiftId);
    $f = ['from' => today(), 'to' => today(), 'user_id' => $ids['user.teller1']];
    // what the teller already did today in the other tests: the figures below are what this test adds
    $bySource = function () use ($f): array {
        $out = [];
        foreach (v33Report('teller_daily', $f)['rows'] as $r) {
            if (empty($r['_class'])) {
                $out[$r['src']] = $r;
            }
        }
        return $out;
    };
    $was = $bySource();
    $before = v33Report('teller_daily', $f + ['view' => 'summary']);
    $base = $before['rows'][0] ?? ['cash' => '0', 'check' => '0', 'other' => '0', 'out_cash' => '0', 'out_other' => '0', 'n' => 0];

    // Loan Collection: a cash payslip and a check payslip, each corrected (the cash one from the drawer, the check one by a journal voucher)
    $p1 = lcPay($m, $loanId, '1000');
    $p2 = lcPay($m, $loanId, '500', ['mode' => 'check', 'cheques' => [['cheque_no' => '7713', 'bank' => 'BPI', 'date' => today(), 'amount' => '500']]]);
    lcEc((int)lcPayRow((int)$p1['txn_id'])['id']);
    lcEc((int)lcPayRow((int)$p2['txn_id'])['id']);
    // the Savings Transaction Center: cash deposits and a withdrawal, a GL-opposite (memo) deposit, an error corrected deposit
    $rs = svOpen($m, 'RS');
    DB::run("INSERT IGNORE INTO sav_txn_codes (code, description, opposite_kind, opposite_account_id, entry_side, is_active) VALUES ('V33DD', 'Direct deposit (test)', 'gl', :b, 'credit', 1)", ['b' => $ids['bank']]);
    svPost(['account_id' => $rs, 'code' => 'CSD', 'amount' => '2000']);
    $wrong = svPost(['account_id' => $rs, 'code' => 'CSD', 'amount' => '100']);
    svPost(['account_id' => $rs, 'code' => 'CSW', 'amount' => '300']);
    svPost(['account_id' => $rs, 'code' => 'V33DD', 'amount' => '700']);
    svPost(['account_id' => $rs, 'code' => 'ECSD', 'amount' => '100', 'corrects_id' => $wrong['sav_txn_id']]);
    // a loan released in cash from the teller's drawer
    $relB = CoopLedger::withClass((int)$ids['class.HO'], fn() => Loans::release($b['app'], ['release_date' => today(), 'start_date' => Coop::addDays(today(), 15), 'release_mode' => 'cash',
        'cash_account_id' => $ids['cash.HO'], 'shift_id' => $shiftId], 'loan.release'));
    $netB = (string)Loans::loan((int)$relB['loan_id'])['net_proceeds'];

    $rep = v33Report('teller_daily', $f);
    $by = $bySource();
    $cell = fn(array $set, string $src, string $col) => isset($set[$src][$col]) && $set[$src][$col] !== '' ? (string)$set[$src][$col] : '0';
    $v = fn(string $src, string $col) => bcsub($cell($by, $src, $col), $cell($was, $src, $col), 2);
    eqm($v('Loan Collection', 'cash'), '1000', 'Loan Collection: the cash payslip');
    eqm($v('Loan Collection', 'check'), '500', 'Loan Collection: the check payslip');
    eqm($v('Loan Collection: error corrections', 'cash'), '-1000', 'the correction of the cash payslip is taken back from the cash');
    eqm($v('Loan Collection: error corrections', 'check'), '-500', 'the correction of the check payslip (a journal voucher) is taken back from the checks');
    eqm($v('Savings Transaction Center: deposits', 'cash'), '2100', 'the cash deposits of the center');
    eqm($v('Savings Transaction Center: deposits', 'other'), '700', 'the GL-opposite deposit: collected, not in the drawer');
    eqm($v('Savings Transaction Center: withdrawals', 'out_cash'), '300', 'the cash withdrawal');
    eqm($v('Savings Transaction Center: error corrections', 'cash'), '-100', 'the corrected deposit is taken back');
    eqm($v('Loan releases from the drawer', 'out_cash'), $netB, 'the loan released from the drawer is paid out cash');
    check((bool)array_filter($rep['rows'], fn($r) => ($r['_class'] ?? '') === 'subtotal-row'), 'a subtotal for the teller\'s day');
    $sum = v33Report('teller_daily', $f + ['view' => 'summary'])['rows'][0];
    $netCash = bcsub(bcsub((string)$sum['cash'], (string)$base['cash'], 2), bcsub((string)$sum['out_cash'], (string)$base['out_cash'], 2), 2);
    eqm($netCash, bcsub('1700', $netB, 2), 'net cash: 1,000 - 1,000 + 2,100 - 100 - 300 less the loan released');
    Teller::refreshShift($shiftId);
    $s1 = Teller::shift($shiftId);
    eqm(bcsub(bcsub((string)$s1['receipts_total'], (string)$s0['receipts_total'], 2), bcsub((string)$s1['disbursements_total'], (string)$s0['disbursements_total'], 2), 2), $netCash,
        'the summary\'s net cash is what the teller\'s drawer took in and paid out');
    eqm($rep['totals']['cash'], bcadd((string)$sum['cash'], '0', 2), 'the grand total adds up the sources, not the subtotals');
    svCloseShift();
    Auth::actAs($GLOBALS['adminId']);
    assertLedgerBalanced('after the teller\'s day');
    assertSlGl('after the teller\'s day');
});

test('Loan Release Form: Cash release is a Cash Voucher of Banking from the cash account chosen, with the voucher ID, the class on every line and the deductions changed by the user', function () {
    global $ids;
    $a = v33App('Alberca', 'Apolonio');
    $plan = lr_plan(Loans::application($a['app']), ['term' => '12', 'term_unit' => 'months', 'release' => today(), 'charges' => null]);
    eq(array_column($plan['rows'], 'name'), ['Service Fee', 'Share Capital Build-up'], 'the form opens with the product\'s deductions');
    eqm($plan['net_proceeds'], '13800', '15,000 less 3 % service fee (450) and 5 % share capital (750)');
    // the user deletes the service fee, adds the filing fee and brings the share capital down to 4 %
    $grid = [['charge_id' => $ids['charge.CBU'], 'rate' => '4', 'amount' => ''], ['charge_id' => $ids['charge.FF'], 'rate' => '', 'amount' => '']];
    $plan = lr_plan(Loans::application($a['app']), ['term' => '12', 'term_unit' => 'months', 'release' => today(), 'charges' => $grid]);
    eqm($plan['rows'][0]['amount'], '600', '4 % of 15,000');
    eqm($plan['rows'][1]['amount'], '100', 'the filing fee of the charge');
    eqm($plan['net_proceeds'], '14300', 'net proceeds 15,000 - 600 - 100');
    $dup = lr_plan(Loans::application($a['app']), ['term' => '12', 'release' => today(), 'charges' => [['charge_id' => $ids['charge.FF']], ['charge_id' => $ids['charge.FF']]]]);
    check(str_contains((string)$dup['error'], 'twice'), 'a charge listed twice is refused on the form');
    refuses(fn() => v33Release($a['app'], ['release_mode' => 'cash', 'cash_account_id' => $ids['cash.HO'], 'charges' => [['charge_id' => $ids['charge.FF']], ['charge_id' => $ids['charge.FF']]]]), 'twice', 'and on the release');
    refuses(fn() => v33Release($a['app'], ['release_mode' => 'cash', 'cash_account_id' => acct('1210'), 'charges' => $grid]), 'cash or bank account', 'the cash account must be a cash / bank account of the chart');
    $grid[] = ['charge_id' => $ids['charge.SF'], 'rate' => '', 'amount' => '275.50'];
    $cashBefore = gl($ids['cash.BR2']);
    $feeBefore = gl(acct('4420'));
    $r = v33Release($a['app'], ['release_mode' => 'cash', 'cash_account_id' => $ids['cash.BR2'], 'charges' => $grid]);
    $t = Txn::load((int)$r['txn_id']);
    $loan = Loans::loan((int)$r['loan_id']);
    eq($t['txn_type'], 'cash_voucher', 'released in cash: a Cash Voucher (Banking > Cash Voucher)');
    check(str_starts_with((string)$t['txn_no'], (string)DB::value("SELECT prefix FROM sequences WHERE seq_key = 'cash_voucher'")), 'numbered in the cash voucher series: ' . $t['txn_no']);
    eq((int)$t['account_id'], (int)$ids['cash.BR2'], 'paid from the cash account chosen on the form');
    eqm(bcsub($cashBefore, gl($ids['cash.BR2']), 2), '14024.50', 'cash paid out: 15,000 - 600 - 100 - 275.50');
    eqm($t['total'], '14024.50', 'the voucher is for the net proceeds');
    eq($loan['voucher_no'], $r['voucher_no'], 'the loan keeps its voucher ID');
    check(str_starts_with((string)$r['voucher_no'], 'LR-'), 'the voucher ID is of the LR- series: ' . $r['voucher_no']);
    eq((int)$t['class_id'], (int)$ids['class.HO'], 'the class of the form is on the voucher');
    eq((int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE txn_id = :t AND (class_id IS NULL OR class_id <> :c)', ['t' => $t['id'], 'c' => $ids['class.HO']]), 0, 'and on every line of its entry');
    eqm(bcsub(gl(acct('4420')), $feeBefore, 2), '-100', 'the filing fee added on the form is income');
    $applied = DB::pairs('SELECT name, CONCAT(amount, \'|\', basis_text) FROM loan_charges_applied WHERE loan_id = :l', ['l' => $loan['id']]);
    eq($applied['Share Capital Build-up'] ?? '', '600.00|4.00 %', 'the share capital at the rate typed');
    eq($applied['Filing Fee'] ?? '', '100.00|fixed 100.00', 'the filing fee added');
    eq($applied['Service Fee'] ?? '', '275.50|as entered', 'the service fee typed as an amount');
    check(DB::value('SELECT sav_account_id FROM loan_charges_applied WHERE loan_id = :l AND charge_type = \'savings\'', ['l' => $loan['id']]) !== null, 'the share capital account credited is kept with the charge');
    $audit = DB::row("SELECT reason FROM audit_trail WHERE record_type = 'loan_account' AND record_id = :l AND action = 'release'", ['l' => $loan['id']]);
    check(str_contains((string)$audit['reason'], 'Deductions changed') && str_contains((string)$audit['reason'], 'Filing Fee: added'), 'the deductions changed are in the audit trail: ' . $audit['reason']);
    eq(LoanVouchers::loanOf((int)$t['id'])['loan_no'], $loan['loan_no'], 'the voucher is known as the release of the loan');
    check(Txn::isCoop($t), 'and treated as a cooperative voucher');
    assertLedgerBalanced('after the cash release');
    assertSlGl('after the cash release');
});

test('Cheque release: a check of Write Checks numbered with the cheque no., drawn on the bank account chosen; never edited or deleted, voided with the loan; a cheque no. used once', function () {
    global $ids;
    $a = v33App('Abac', 'Nilda');
    $bankBefore = gl($ids['bank']);
    $r = v33Release($a['app'], ['release_mode' => 'cheque', 'cash_account_id' => $ids['bank'], 'cheque_no' => '546345435']);
    $t = Txn::load((int)$r['txn_id']);
    eq($t['txn_type'], 'check', 'released by cheque: a check (Banking > Write Checks)');
    eq($t['txn_no'], '546345435', 'numbered with the cheque no.');
    eq(Loans::loan((int)$r['loan_id'])['cheque_no'], '546345435', 'the loan keeps the cheque no.');
    eqm(bcsub($bankBefore, gl($ids['bank']), 2), '13800', 'the bank account is credited with the net proceeds');
    eq((int)DB::value("SELECT COUNT(*) FROM transaction_lines WHERE txn_id = :t AND line_kind = 'expense'", ['t' => $t['id']]), 3, 'the lines of the check: the receivable, the service fee, the share capital');
    $b = v33App('Reyes', 'Cielo');
    refuses(fn() => v33Release($b['app'], ['release_mode' => 'cheque', 'cash_account_id' => $ids['bank'], 'cheque_no' => '546345435']), 'already used', 'the same cheque no. of the same bank account');
    // corrected by voiding only
    $_POST = [];
    refuses(fn() => Txn::save('check', ['txn_date' => today(), 'account_id' => $ids['bank'], 'total' => '1'], [], (int)$t['id']), 'release of loan', 'the check of a release is not edited');
    refuses(fn() => Txn::delete((int)$t['id'], 'wrong'), 'never deleted', 'nor deleted');
    $sc = $a['sc'];
    $scBefore = Savings::account($sc)['balance'];
    Txn::void((int)$t['id'], 'Wrong bank account');
    eq(Loans::loan((int)$r['loan_id'])['status'], 'cancelled', 'voiding the check cancels the loan');
    eq(Loans::application($a['app'])['status'], 'approved', 'the application is approved again');
    eqm(bcsub((string)$scBefore, (string)Savings::account($sc)['balance'], 2), '750', 'the share capital build-up goes back');
    eqm(gl($ids['bank']), $bankBefore, 'the bank is as it was');
    // released again, from another cheque
    $r2 = v33Release($a['app'], ['release_mode' => 'cheque', 'cash_account_id' => $ids['bank'], 'cheque_no' => '546345436']);
    eq(Txn::load((int)$r2['txn_id'])['txn_no'], '546345436', 'released again with the new cheque');
    check($r2['voucher_no'] !== $r['voucher_no'], 'with a voucher ID of its own');
    // a loan with payments: its voucher can no longer be voided
    lcPay($a['member'], (int)$r2['loan_id'], '500');
    refuses(fn() => Txn::void((int)$r2['txn_id'], 'late'), 'payments', 'a released loan with a payment keeps its voucher');
    assertLedgerBalanced('after the cheque releases');
    assertSlGl('after the cheque releases');
});

test('Loan release vouchers: the monitoring list has every voucher of a release (Banking and before), the approver and the preparer; the teller\'s cash comes from the drawer of the shift only', function () {
    global $ids;
    $rows = DB::all("SELECT l.loan_no, t.txn_type, t.is_voided FROM loan_accounts l JOIN transactions t ON t.id = l.release_txn_id WHERE l.release_mode <> 'opening'");
    $types = array_unique(array_column($rows, 'txn_type'));
    check(in_array('cash_voucher', $types, true) && in_array('check', $types, true), 'cash vouchers and checks of the releases');
    eq((int)DB::value('SELECT COUNT(*) FROM loan_accounts WHERE release_txn_id IS NOT NULL AND voucher_no IS NULL AND release_mode <> \'opening\''), 0, 'every release has a voucher ID');
    $loan = DB::row("SELECT l.*, t.txn_type FROM loan_accounts l JOIN transactions t ON t.id = l.release_txn_id WHERE t.txn_type = 'check' AND t.is_voided = 0 ORDER BY l.id DESC LIMIT 1");
    $t = Txn::load((int)$loan['release_txn_id']);
    eq(LoanVouchers::preparedBy($loan, $t), (string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $t['created_by']]), 'Prepared by: the user who released the loan');
    $appr = (string)DB::value("SELECT u.full_name FROM loan_approvals a JOIN users u ON u.id = a.approver_id WHERE a.application_id = :a AND a.action = 'approved' ORDER BY a.level_no DESC, a.id DESC LIMIT 1", ['a' => $loan['application_id']]);
    eq(LoanVouchers::approvedBy($loan, $t), $appr, 'Approved by: the approver of the loan');
    eq(LoanVouchers::label('check'), 'Check Voucher', 'a check prints as the Check Voucher');
    eq(LoanVouchers::label('cash_voucher'), 'Cash Voucher', 'a cash voucher as the Cash Voucher');
    $acc = LoanVouchers::accounts();
    check(isset($acc['bank'][$ids['bank']]) && !isset($acc['cash'][$ids['bank']]), 'Cheque: the bank accounts');
    check(isset($acc['cash'][$ids['cash.HO']]) && !isset($acc['bank'][$ids['cash.HO']]), 'Cash: the cash accounts (drawers, vault, funds)');
    // the teller's drawer without an open shift: the release is refused by the page (Teller::requireShift); with the shift it is a cash-out of the shift
    $a = v33App('Fortuna', 'Teodora', 'EMR', '5000', '3');
    asUser('teller1');
    svCloseShift();
    $shiftId = shift(today());
    $r = CoopLedger::withClass((int)$ids['class.HO'], fn() => Loans::release($a['app'], ['release_date' => today(), 'start_date' => Coop::addDays(today(), 15), 'release_mode' => 'cash',
        'cash_account_id' => $ids['cash.HO'], 'shift_id' => $shiftId], 'loan.release'));
    $rc = DB::row('SELECT * FROM coop_receipts WHERE txn_id = :t', ['t' => $r['txn_id']]);
    check($rc && $rc['receipt_kind'] === 'cv' && (int)$rc['shift_id'] === $shiftId && $rc['receipt_no'] === Txn::load((int)$r['txn_id'])['txn_no'], 'a cash-out of the shift, numbered with the cash voucher');
    svCloseShift();
    Auth::actAs($GLOBALS['adminId']);
    // a release that pays nothing out stays a loan release voucher (a journal entry)
    $b = v33App('Sayson', 'Joan', 'EMR', '1000', '1');
    $r0 = v33Release($b['app'], ['release_mode' => 'cash', 'cash_account_id' => $ids['cash.HO'], 'charges' => [['charge_id' => $ids['charge.SF'], 'amount' => '1000']]]);
    eq(Txn::load((int)$r0['txn_id'])['txn_type'], 'loan_release', 'nothing paid out: a loan release voucher');
    eq(Txn::load((int)$r0['txn_id'])['txn_no'], $r0['voucher_no'], 'numbered with its voucher ID');
    assertLedgerBalanced('after the voucher checks');
});

test('Internal Ledger: the additional interest and the penalty by the product set-up (missed amortizations, past maturity), the balances; the Loan Ledger Form in passbook view or every entry', function () {
    global $ids;
    $a = v33App('Camillo', 'Ariel', 'REG', '12000', '6');
    $rel = date('Y-m-d', strtotime(today() . ' -100 days'));
    $r = CoopLedger::withClass((int)$ids['class.HO'], fn() => Loans::release($a['app'], ['release_date' => $rel, 'start_date' => date('Y-m-d', strtotime($rel . ' +1 month')),
        'release_mode' => 'cash', 'cash_account_id' => $GLOBALS['adminCash']], 'loan.release'));
    $loanId = (int)$r['loan_id'];
    Loans::refreshStatuses();
    $loan = Loans::loan($loanId);
    // arrears (a diminishing loan, "auto"): each overdue amortization's principal x 18 % x days / 365
    $exp = '0';
    foreach (DB::all('SELECT * FROM loan_schedule WHERE loan_id = :l AND due_date < :d', ['l' => $loanId, 'd' => today()]) as $s) {
        $days = (int)((strtotime(today()) - strtotime($s['due_date'])) / 86400);
        $exp = bcadd($exp, r2s(bcdiv(bcmul(bcmul((string)$s['principal'], '18', 10), (string)$days, 10), '36500', 10)), 2);
    }
    $ai = Loans::additionalInterest($loan, today());
    eq($ai['mode'], 'arrears', 'a diminishing loan: interest on the missed amortizations');
    eqm($ai['total'], $exp, 'the additional interest by the formula');
    // a payment on the first overdue amortization lowers the base from its date
    $il = Loans::internalLedger($loan, today());
    eqm($il['posted']['p'], $loan['principal_balance'], 'the posted principal balance');
    eqm($il['posted']['i'], $loan['interest_balance'], 'the posted interest balance');
    eqm($il['additional']['interest'], $exp, 'the Internal Ledger adds the additional interest');
    eqm($il['additional']['penalty'], Loans::penaltyThrough($loan, today())['new'], 'and the penalty not yet charged');
    eqm($il['balance']['i'], bcadd((string)$loan['interest_balance'], $exp, 2), 'the interest balance with the additional interest');
    check((bool)array_filter($il['rows'], fn($x) => $x['computed']), 'the computed rows are marked');
    // the product says none: no additional interest
    DB::update('loan_products', ['addl_interest' => 'none'], 'id = :id', ['id' => $ids['loan.REG']]);
    Loans::resetProducts();
    eqm(Loans::additionalInterest($loan, today())['total'], '0', 'none on the product: no additional interest');
    // maturity: only the days after the maturity date, on the principal balance
    DB::update('loan_products', ['addl_interest' => 'maturity'], 'id = :id', ['id' => $ids['loan.REG']]);
    eqm(Loans::additionalInterest($loan, today())['total'], '0', 'maturity: nothing before the maturity date');
    $after = Coop::addDays($loan['due_date'], 10);
    $m = Loans::additionalInterest($loan, $after);
    eqm($m['total'], r2s(bcdiv(bcmul(bcmul((string)$loan['principal_balance'], '18', 10), '10', 10), '36500', 10)), '10 days after maturity on the principal balance');
    DB::update('loan_products', ['addl_interest' => 'auto'], 'id = :id', ['id' => $ids['loan.REG']]);
    Loans::resetProducts();
    // the Loan Ledger Form
    lcPenalties($loanId);
    lcPay($a['member'], $loanId, '3000');
    $pb = Loans::ledgerForm(Loans::loan($loanId), false);
    $all = Loans::ledgerForm(Loans::loan($loanId), true);
    eq(count($pb['rows']), 2, 'passbook view: the release and the payment');
    check(count($all['rows']) > count($pb['rows']), 'View All: the penalty charged too');
    eqm($pb['rows'][0]['release'], '12000', 'Release');
    eq($pb['rows'][0]['ref'], (string)Loans::loan($loanId)['pn_no'], 'the reference of the release is the promissory note no.');
    $pay = DB::row("SELECT * FROM loan_txns WHERE loan_id = :l AND kind = 'payment' ORDER BY id DESC LIMIT 1", ['l' => $loanId]);
    eqm($pb['totals']['prin'], $pay['principal_cr'], 'Prin. Payments');
    eqm($pb['totals']['int'], $pay['interest_cr'], 'Int. Payments');
    eqm($pb['totals']['fines'], $pay['penalty_cr'], 'Fines/Surc.');
    eqm(end($pb['rows'])['bal'], Loans::loan($loanId)['principal_balance'], 'Prin. Balance');
    assertLedgerBalanced('after the internal ledger');
    assertSlGl('after the internal ledger');
});
