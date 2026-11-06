<?php
declare(strict_types=1);

/**
 * More test cases (included by run.php after cases.php): payroll batches, passbook printing, opening-balance import,
 * restructuring through an application, the disclosure statement's effective interest rate, the regulatory figures,
 * the SL vs GL check and the dashboard alerts.
 */

// =============================================================================================== 12. batch deductions
test('Batch deductions (payroll): savings and loan lines checked, nothing posted while a line has an error, one voucher', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    $m = newMember('Valdez', 'Lorna', ['membership_date' => '2025-01-01']);
    $rs = openAccount($m, 'RS', '2026-02-01');
    $sc = openAccount($m, 'SC', '2026-02-01');
    shift('2026-02-02');
    receipt($m, '2026-02-02', [['kind' => 'savings', 'account_id' => $sc, 'amount' => '1500']]);
    $app = approvedLoan($m, 'REG', '12000', '6', '2026-02-03');
    $r = releaseLoan($app, '2026-02-05', '2026-03-05');
    $loan = Loans::loan($r['loan_id']);
    $text = "reference,amount,remarks\n" . Savings::account($rs)['account_no'] . ",500.00,March payroll\n" . $loan['loan_no'] . ",\"1,000.00\",March amortization\nNOPE-123,25\n"
        . DB::value('SELECT member_no FROM members WHERE id = :m', ['m' => $m]) . ",300\n";
    $rows = SavBatch::parse($text);
    eq(count($rows), 4, 'heading skipped, four lines read');
    $bid = SavBatch::create(['batch_date' => '2026-03-31', 'contra_account_id' => acct('1230'), 'description' => 'March payroll', 'source' => 'payroll'], $rows, null, 'sav.batch');
    $c = SavBatch::check($bid);
    eq($c['errors'], 2, 'the unknown reference and the bare member number are flagged');
    refuses(fn() => SavBatch::post($bid, 'sav.batch'), 'have errors', 'nothing is posted while a line is wrong');
    eq((int)DB::value("SELECT COUNT(*) FROM sav_batches WHERE id = :b AND status = 'draft'", ['b' => $bid]), 1, 'still a draft');
    // with the product chosen, a member number resolves to the member's account of that product
    $c = SavBatch::check($bid, $ids['sav.SC']);
    eq($c['errors'], 1, 'member number resolved to the share capital account');
    DB::run("DELETE FROM sav_batch_lines WHERE batch_id = :b AND error_text IS NOT NULL", ['b' => $bid]);
    SavBatch::check($bid, $ids['sav.SC']);
    $before = Savings::account($rs)['balance'];
    $txn = SavBatch::post($bid, 'sav.batch');
    eqm(bcsub(Savings::account($rs)['balance'], $before, 2), '500', 'savings credited');
    eqm(Savings::account($sc)['balance'], '2400', 'share capital: 1,500 + 600 capital build-up on the loan + 300 from the batch');
    eq((int)DB::value("SELECT COUNT(*) FROM loan_txns WHERE loan_id = :l AND kind = 'payment' AND txn_id = :t", ['l' => $loan['id'], 't' => $txn]), 1, 'loan payment on the same voucher');
    eqm(gl(acct('1230')), '1800', 'payroll deductions receivable = batch total');
    refuses(fn() => SavBatch::post($bid, 'sav.batch'), 'only once', 'a batch is posted once');
    assertLedgerBalanced('after the batch');
    assertSlGl('after the batch');
});

// =============================================================================================== 13. passbook
test('Passbook: printing starts on line 1, Start Line Skip leaves the fold blank, confirmed page by page, reprint and new passbook logged', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    $type = DB::insert('passbook_types', ['name' => 'Test passbook 5 lines', 'description' => 'small pages', 'columns_json' => '{"date":200,"code":1400,"debit":2600,"credit":4400,"balance":6200,"teller":8000}',
        'start_line_skip' => 2, 'line_skip' => 1, 'max_lines' => 5]);
    $m = newMember('Zamora', 'Pia', ['membership_date' => '2025-01-01']);
    $rs = openAccount($m, 'RS', '2026-02-01');
    DB::update('sav_accounts', ['passbook_type_id' => $type], 'id = :id', ['id' => $rs]);
    shift('2026-02-02');
    for ($i = 1; $i <= 6; $i++) {
        receipt($m, '2026-02-' . str_pad((string)(2 + $i), 2, '0', STR_PAD_LEFT), [['kind' => 'savings', 'account_id' => $rs, 'amount' => (string)(100 * $i)]]);
    }
    $no = Savings::account($rs)['account_no'];
    $pb = Passbook::open('savings', $no);
    $plan = Passbook::plan($pb);
    eq($plan['page'], 1, 'page 1');
    eq(array_column($plan['lines'], 'line'), [1, 3, 4, 5], 'printing starts on line 1; line 2 (Start Line Skip 2, one line) is skipped; 4 lines fit');
    eq($plan['remaining'], 2, 'two lines wait for the next page');
    check(str_contains(Passbook::render($pb, 1, $plan['lines']), 'in'), 'the page is laid out in inches');
    Passbook::commit($pb, 1, array_column($plan['lines'], 'line', 'id'), 'sav.passbook');
    $pb = Passbook::open('savings', $no);
    $plan2 = Passbook::plan($pb);
    eq([$plan2['page'], array_column($plan2['lines'], 'line')], [2, [1, 3]], 'page 2 starts on line 1');
    refuses(fn() => Passbook::commit(Passbook::open('savings', $no), 1, [$plan['lines'][0]['id'] => 3], 'sav.passbook'), 'someone else', 'a line already printed cannot be committed again');
    // a misprint: from the second line of page 1 on (line 3), the lines go back to unprinted
    $n = Passbook::unmark($pb, (int)$plan['lines'][1]['id'], 'Paper jam', 'sav.passbook');
    eq($n, 3, 'three lines to print again');
    $plan3 = Passbook::plan(Passbook::open('savings', $no));
    eq([$plan3['page'], $plan3['lines'][0]['line']], [1, 3], 'they print again where they were');
    Passbook::replace(Passbook::open('savings', $no), 'PB-NEW-1', 'Passbook lost', 'sav.passbook');
    $a = Savings::account($rs);
    eq([(int)$a['pb_page'], (int)$a['pb_last_line'], $a['passbook_no']], [1, 0, 'PB-NEW-1'], 'a new passbook starts again on page 1');
    eq((int)DB::value("SELECT COUNT(*) FROM passbook_prints WHERE account_id = :a AND ledger = 'savings'", ['a' => $rs]), 3, 'print, reprint and replacement logged');
});

// =============================================================================================== 14. opening balances
test('Opening balances import: members, savings, time deposits and loans checked first, posted all or nothing, ledgers agree', function () {
    Auth::actAs($GLOBALS['adminId']);
    $asOf = '2026-03-31';
    $members = CoopImport::parse("member_no,last_name,first_name,gender,branch,membership_date,subscribed_shares,par_value\n"
        . "900001,Abad,Celso,Male,01,2015-06-01,100,100\n900002,Bernal,Diana,Female,99,2016-01-10,50,100\n");
    $c = CoopImport::check('members', $members, $asOf);
    eq($c['ok'], false, 'the unknown branch is caught');
    check(str_contains(implode(' ', $c['rows'][1]['errors']), 'branch 99'), 'and named on its row');
    refuses(fn() => CoopImport::run('members', $members, $asOf, null, 'members.csv', 'coop.import'), 'errors', 'a file with an error is not imported');
    eq((int)DB::value("SELECT COUNT(*) FROM members WHERE member_no IN ('900001','900002')"), 0, 'not even the good row');
    $members[1]['branch'] = '02';
    CoopImport::run('members', $members, $asOf, null, 'members.csv', 'coop.import');
    $m1 = (int)DB::value("SELECT id FROM members WHERE member_no = '900001'");
    $m2 = (int)DB::value("SELECT id FROM members WHERE member_no = '900002'");
    check($m1 > 0 && $m2 > 0, 'members created');
    eq(Coop::member($m1)['status'], 'active', 'imported as active members');
    eqm(Coop::member($m2)['subscribed_capital'], '5000', 'subscription 50 x 100');

    $sav = CoopImport::parse("member_no,product,balance,opened_on\n900001,01,12500.50,2015-06-01\n900001,03,10000,2015-06-01\n900002,03,5000,2016-01-10\n");
    $c = CoopImport::check('savings', $sav, $asOf);
    eq($c['ok'], true, 'savings file is clean');
    eqm(bcadd((string)$c['total'], '0', 2), '27500.50', 'total of the file');
    $cap = gl(acct('3010'));
    $id = CoopImport::run('savings', $sav, $asOf, null, 'savings.csv', 'coop.import');
    $imp = DB::row('SELECT * FROM coop_imports WHERE id = :i', ['i' => $id]);
    eqm(bcsub($cap, gl(acct('3010')), 2), '15000', 'share capital credited 15,000');
    eqm(glMember(acct('2300'), $m1), '-12500.50', 'savings deposits carry the member name');
    // liabilities brought over before the assets: the offset is a debit until the opening trial balance is entered
    eqm(gl(Accts::get('opening_equity')), '27500.50', 'against opening balance equity (debited)');
    eq((int)DB::value("SELECT COUNT(*) FROM sav_txns WHERE txn_id = :t AND txn_code = 'OB'", ['t' => $imp['txn_id']]), 3, 'one opening entry per account');
    refuses(fn() => Txn::void((int)$imp['txn_id'], 'try'), 'opening balances', 'an import voucher is not voided');
    $again = CoopImport::check('savings', $sav, $asOf);
    eq($again['ok'], false, 'the same balances cannot be brought over twice');

    $td = CoopImport::parse("member_no,product,principal,rate,placement_date,term_days,certificate_no,accrued_interest\n900002,04,50000,4.5,2026-02-01,360,OLD-TD-77,370.00\n");
    CoopImport::run('time_deposits', $td, $asOf, null, 'td.csv', 'coop.import');
    $pl = DB::row("SELECT * FROM td_placements WHERE certificate_no = 'OLD-TD-77'");
    eq([$pl['placement_date'], $pl['maturity_date']], ['2026-02-01', '2027-01-27'], 'placement keeps its own dates');
    eqm($pl['accrued_interest'], '370', 'accrued interest brought over');

    $loans = CoopImport::parse("member_no,product,principal_balance,first_due,remaining_term,rate,loan_no,original_principal,penalty_balance,accrued_interest\n"
        . "900001,6,30000,2026-03-15,10,18,OLD-900-1,50000,125.50,225.00\n");
    CoopImport::run('loans', $loans, $asOf, null, 'loans.csv', 'coop.import');
    $l = Loans::byNo('OLD-900-1');
    eqm($l['principal_balance'], '30000', 'loan balance');
    eqm($l['penalty_balance'], '125.50', 'penalty carried in the loan ledger');
    eqm($l['interest_accrued'], '225', 'accrued interest stands in the receivable');
    eq($l['release_mode'], 'opening', 'marked as an opening balance');
    eq(count(Loans::scheduleRows((int)$l['id'])), 10, 'schedule rebuilt from the cut-off: 10 amortizations');
    eq(Loans::scheduleRows((int)$l['id'])[0]['due_date'], '2026-03-15', 'the first amortization before the cut-off is already due');
    $d = Loans::dues($l, '2026-04-01');
    eq($d['count_due'], 1, 'one amortization in arrears at the cut-off');
    eqm(Loans::penaltyThrough($l, '2026-03-31')['new'], '0', 'no penalty counted again up to the cut-off');
    assertLedgerBalanced('after the imports');
    assertSlGl('after the imports');
    check(CoopRecon::balanced(today()), 'every member ledger agrees with the general ledger (reconciliation service)');
});

// =============================================================================================== 15. restructuring
test('Restructuring through an application: the loan paid off is left out of the limits; the new loan is flagged restructured', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    $m = newMember('Yap', 'Manuel', ['membership_date' => '2024-01-01']);
    $sc = openAccount($m, 'SC', '2026-01-02');
    shift('2026-01-05');
    receipt($m, '2026-01-05', [['kind' => 'savings', 'account_id' => $sc, 'amount' => '2000']]);
    $old = releaseLoan(approvedLoan($m, 'REG', '20000', '12', '2026-01-10'), '2026-01-15', '2026-02-15')['loan_id'];
    // nothing paid: the loan is in arrears today
    $plain = loanApp($m, 'REG', '25000', '12', '2026-06-01');
    $v = Loans::validateApplication($plain, '', 'loan.applications');
    eq($v['ok'], false, 'a new loan of the same product is refused while the old one is open and in arrears');
    Loans::cancelApplication($plain, 'Replaced by the restructuring', 'loan.applications');
    $app = loanApp($m, 'REG', '25000', '12', '2026-06-01', ['previous_loan_id' => $old, 'is_restructure' => 1]);
    $v = Loans::validateApplication($app, '', 'loan.applications');
    eq($v['ok'], true, 'the loan being paid off counts neither against the limit nor as arrears');
    refuses(fn() => loanApp(newMember('Other', 'Person'), 'REG', '5000', '6', '2026-06-01', ['previous_loan_id' => $old]), 'same member', 'only the member\'s own loan can be restructured');
    Loans::submitForApproval($app, 'loan.applications');
    Loans::act($app, 'approved', null, 'Restructured by the credit committee', 'loan.applications');
    $due = Loans::dues(Loans::loan($old), '2026-06-15');
    $r = releaseLoan($app, '2026-06-15', '2026-07-15', ['previous_loan_id' => $old, 'restructure' => true]);
    $new = Loans::loan($r['loan_id']);
    eq($new['status'], 'restructured', 'the new loan is flagged restructured');
    eq(Loans::loan($old)['status'], 'renewed', 'the old loan is closed');
    eqm($new['previous_loan_payoff'], $due['payoff'], 'paid off with penalty and interest to the release date');
    eqm($new['net_proceeds'], bcsub(bcsub('25000', '2000', 2), $due['payoff'], 2), 'net = 25,000 - 8 % charges - payoff');
    assertLedgerBalanced('after the restructuring');
    assertSlGl('after the restructuring');
});

// =============================================================================================== 16. disclosure
test('Disclosure statement: the effective interest rate discounts the amortizations back to the net proceeds', function () {
    $pay = array_fill(0, 12, '1833.60');
    $e = Loans::eir('20000', $pay, 12.0);
    eq($e['annual'], '18.00', 'no charges: the EIR is the contract rate');
    $e2 = Loans::eir('18400', $pay, 12.0);
    check((float)$e2['annual'] > 18.0, 'charges deducted raise the EIR (' . $e2['annual'] . ' %)');
    // check by discounting with bcmath at the rate returned
    $r = bcdiv($e2['period'], '100', 12);
    $pv = '0';
    foreach ($pay as $k => $amt) {
        $pv = bcadd($pv, bcdiv($amt, bcpow(bcadd('1', $r, 12), (string)($k + 1), 12), 12), 12);
    }
    check(abs((float)bcsub($pv, '18400', 6)) < 1.0, 'the payments discounted at ' . $e2['period'] . ' % a month come back to 18,400 (' . r2s($pv) . ')');
    eq(Loans::eir('20000', ['100'], 12.0), null, 'payments that do not even repay the principal have no EIR');
    eq(Loans::periodsPerYear('semi_monthly'), 24.0, 'semi-monthly: 24 periods a year');
});

// =============================================================================================== 17. regulatory figures and reconciliation
test('Regulatory figures: CAPR lines from the ledgers; the reconciliation catches a control-account entry made outside the member pages', function () {
    Auth::actAs($GLOBALS['adminId']);
    $to = today();
    $from = date('Y-01-01');
    eq(CoopMetrics::value('metric:members_total', $from, $to), (string)DB::value("SELECT COUNT(*) FROM members WHERE status IN ('active','suspended') AND membership_date <= :d", ['d' => $to]), 'members at the date');
    eqm(CoopMetrics::value('metric:loans_outstanding', $from, $to), DB::value("SELECT COALESCE(SUM(principal_balance),0) FROM loan_accounts WHERE status IN ('current','past_due','restructured')"), 'loans outstanding = the loan ledgers');
    eqm(CoopMetrics::value('account:1210', $from, $to), gl(acct('1210')), 'an account source gives the GL balance');
    eqm(CoopMetrics::value('account:3010', $from, $to), bcmul(gl(acct('3010')), '-1', 2), 'credit accounts come out positive');
    eq(CoopMetrics::value('text:Multi-Purpose', $from, $to), 'Multi-Purpose', 'a fixed text');
    refuses(fn() => CoopMetrics::value('metric:no_such_figure', $from, $to), 'Unknown figure', 'an unknown figure is refused');
    $capr = CoopMetrics::report('capr', $from, $to);
    check(count($capr) >= 25 && !array_filter($capr, fn($l) => $l['error']), 'every CAPR line of the standard mapping computes (' . count($capr) . ' lines)');

    check(CoopRecon::balanced($to), 'the ledgers agree before');
    $txn = CoopLedger::post('coop_jv', ['txn_date' => $to, 'memo' => 'Direct entry to the savings control account'], [
        ['account_id' => $GLOBALS['adminCash'], 'debit' => '100.00', 'memo' => 'test'], ['account_id' => acct('2300'), 'credit' => '100.00', 'memo' => 'test']]);
    $row = array_values(array_filter(CoopRecon::slVsGl($to), fn($r) => $r['code'] === '2300'))[0];
    eqm($row['difference'], '-100', 'the savings ledgers are 100 short of the control account');
    check(!CoopRecon::balanced($to), 'and the reconciliation says so');
    CoopLedger::reverse($txn, $to, 'Test entry reversed');
    check(CoopRecon::balanced($to), 'balanced again after the reversing entry');
});

test('Dashboard alerts: what needs attention is counted for the user who can act on it', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    $m = newMember('Alcantara', 'Rex', ['membership_date' => '2024-01-01']);
    $sc = openAccount($m, 'SC', '2026-01-02');
    shift('2026-01-05');
    receipt($m, '2026-01-05', [['kind' => 'savings', 'account_id' => $sc, 'amount' => '2000']]);
    $app = loanApp($m, 'REG', '10000', '6', today());
    Loans::validateApplication($app, '', 'loan.applications');
    Loans::submitForApproval($app, 'loan.applications');
    $texts = fn() => array_column(CoopAlerts::all(false), 'count', 'text');
    asUser('gm');
    check(($texts()['Loan applications waiting for approval'] ?? 0) >= 1, 'the manager sees the application waiting for approval');
    check(($texts()['Loans past due (amount in arrears)'] ?? 0) >= 1, 'and the loans past due');
    asUser('teller1');
    check(!isset($texts()['Loan applications waiting for approval']), 'a teller (no approve right) does not');
    $a = CoopAlerts::all(false)[0] ?? null;
    check($a === null || (isset($a['level'], $a['text'], $a['count'], $a['link']) && in_array($a['level'], ['bad', 'warn', 'info'], true)), 'each alert has a level, a text, a count and a link');
    Auth::actAs($GLOBALS['adminId']);
    Loans::cancelApplication($app, 'Test done', 'loan.applications');
});

test('Certificate of balances: each account on a past date from the ledgers; later postings are left out', function () {
    Auth::actAs($GLOBALS['adminId']);
    $m = newMember('Villanueva', 'Celia', ['membership_date' => '2024-01-01']);
    $sc = openAccount($m, 'SC', '2026-01-02');
    $rs = openAccount($m, 'RS', '2026-01-02');
    shift('2026-01-05');
    receipt($m, '2026-01-05', [['kind' => 'savings', 'account_id' => $sc, 'amount' => '2000'], ['kind' => 'savings', 'account_id' => $rs, 'amount' => '3000']]);
    $b = Members::balancesAsOf($m, '2026-01-10');
    eqm($b['totals']['share_capital'], '2000', 'share capital on 10 Jan');
    eqm($b['totals']['deposits'], '3000', 'deposits on 10 Jan');
    eq($b['loans'], [], 'no loan yet');
    $r = releaseLoan(approvedLoan($m, 'REG', '10000', '6', '2026-01-12'), '2026-01-15', '2026-02-15');
    shift('2026-03-02');
    receipt($m, '2026-03-02', [['kind' => 'savings', 'account_id' => $rs, 'amount' => '1000']]);
    eqm(Members::balancesAsOf($m, '2026-01-10')['totals']['deposits'], '3000', 'a later deposit does not change the balance on an earlier date');
    $feb = Members::balancesAsOf($m, '2026-02-28');
    eqm($feb['totals']['deposits'], '3000', 'deposits on 28 Feb');
    eqm($feb['loans'][0]['principal_balance'], '10000', 'loan principal on 28 Feb (nothing paid)');
    $now = Members::balancesAsOf($m, today());
    eqm($now['totals']['deposits'], '4000', 'deposits today');
    eqm($now['totals']['share_capital'], Savings::account($sc)['balance'], 'share capital today, including the build-up taken from the loan, equals the account balance');
    Loans::refreshBalances($r['loan_id']);
    eqm($now['totals']['loans'], Loans::loan($r['loan_id'])['principal_balance'], 'loan principal today equals the principal balance on the loan account');
});

test('Statement of Changes in Equity: the allocation moves the surplus to the funds; the ending total is the equity on the Statement of Financial Condition', function () {
    $def = Report::get('coop_sce');
    $r = $def['run'](['from' => '2026-01-01', 'to' => today()]);
    $rows = array_column($r['rows'], null, 'name');
    $alloc = array_values(array_filter($r['rows'], fn($x) => str_starts_with($x['name'], 'Allocation of net surplus')))[0] ?? null;
    check($alloc !== null, 'the 2025 allocation posted in 2026 has its own line');
    eqm($alloc['fund'], '25000', 'statutory funds credited: reserve 10,000 + education 5,000 kept + community 3,000 + optional 7,000');
    eqm($alloc['other'], '-100000', 'the undivided surplus debited with the whole allocation');
    eqm($alloc['total'], '-75000', 'equity goes down by what left for payables: federation share 5,000 + ISC 30,000 + PR 40,000');
    $end = end($r['rows']);
    $sfc = array_values(array_filter(crpt_sfc_rows(today())[0], fn($x) => $x['name'] === 'TOTAL MEMBERS\' EQUITY'))[0];
    eqm($end['total'], $sfc['amount'], 'the ending total equals members\' equity on the Statement of Financial Condition');
    check(!str_contains($r['notes'], 'does not agree'), 'the ending balance agrees with the ledger');
});

test('Members who have left: paid out only as an approved settlement; only loan payments received; a deceased member is paid to the person named', function () {
    Auth::actAs($GLOBALS['adminId']);
    $today = today();
    $m = newMember('Salazar', 'Andres', ['membership_date' => '2024-01-01']);
    $sc = openAccount($m, 'SC', '2026-01-02');
    $rs = openAccount($m, 'RS', '2026-01-02');
    shift('2026-01-05');
    receipt($m, '2026-01-05', [['kind' => 'savings', 'account_id' => $sc, 'amount' => '3000'], ['kind' => 'savings', 'account_id' => $rs, 'amount' => '1500']]);
    Members::changeStatus($m, 'resigned', $today, 'Moved abroad', 'BR-2026-11', 'coop.members');
    asUser('teller1');
    shift($today);
    refuses(fn() => receipt($m, $today, [['kind' => 'savings', 'account_id' => $rs, 'amount' => '100']]), 'only loan payments', 'no deposit from a member who resigned');
    refuses(fn() => Teller::disburse($m, $today, [['kind' => 'savings', 'account_id' => $sc, 'amount' => '3000']], ['mode' => 'cash'], [], 'teller.disburse'), 'Supervisor approval is required: settlement', 'the settlement needs a supervisor');
    $_POST = ['approver_username' => 'manager', 'approver_password' => 'Demo@12345'];
    $d = Teller::disburse($m, $today, [['kind' => 'savings', 'account_id' => $sc, 'amount' => '3000'], ['kind' => 'savings', 'account_id' => $rs, 'amount' => '1500']], ['mode' => 'cash'], [], 'teller.disburse');
    $_POST = [];
    eqm(Savings::account($sc)['balance'], '0', 'share capital refunded in full');
    eqm(Savings::account($rs)['balance'], '0', 'savings paid out in full, the minimum balance too');
    check((int)Txn::load($d['txn_id'])['approved_by'] > 0, 'the approving supervisor is on the voucher');
    // a deceased member: the claim is paid to the beneficiary named on the voucher
    Auth::actAs($GLOBALS['adminId']);
    $m2 = newMember('Dela Rosa', 'Lito', ['membership_date' => '2024-01-01']);
    $sc2 = openAccount($m2, 'SC', '2026-01-02');
    receipt($m2, '2026-01-05', [['kind' => 'savings', 'account_id' => $sc2, 'amount' => '2000']]);
    Members::changeStatus($m2, 'deceased', $today, 'Death certificate received', '', 'coop.members');
    asUser('teller1');
    refuses(fn() => receipt($m2, $today, [['kind' => 'savings', 'account_id' => $sc2, 'amount' => '100']]), 'only loan payments', 'no deposit for a deceased member');
    $_POST = ['approver_username' => 'manager', 'approver_password' => 'Demo@12345'];
    refuses(fn() => Teller::disburse($m2, $today, [['kind' => 'savings', 'account_id' => $sc2, 'amount' => '2000']], ['mode' => 'cash'], [], 'teller.disburse'), 'who receives', 'the receiver of a deceased member\'s claim is required');
    $d2 = Teller::disburse($m2, $today, [['kind' => 'savings', 'account_id' => $sc2, 'amount' => '2000']], ['mode' => 'cash'], ['received_by' => 'Marites Dela Rosa (spouse)'], 'teller.disburse');
    $_POST = [];
    eq(Txn::load($d2['txn_id'])['payee_name'], 'Marites Dela Rosa (spouse)', 'the beneficiary is on the voucher');
    eqm(Savings::account($sc2)['balance'], '0', 'the share capital of the deceased member is paid out');
    refuses(fn() => Teller::disburse($m2, $today, [['kind' => 'savings', 'account_id' => $sc2, 'amount' => '1']], ['mode' => 'cheque'], ['received_by' => 'x'], 'teller.disburse'), 'bank account the cheque', 'a cheque needs its bank account');
    Auth::actAs($GLOBALS['adminId']);
});

test('Check deposits wait in the clearing account of their own code, and Check Clearing empties it', function () {
    Auth::actAs($GLOBALS['adminId']);
    $coci2 = coop_fx_new_account('1111', 'Checks and Other Cash Items - Out of Town', 'Other Current Asset', 'Asset', 'Debit');
    DB::insert('sav_txn_codes', ['code' => 'CKOT', 'description' => 'Out-of-town Check Deposit 7 Days Clearing', 'opposite_kind' => 'gl', 'opposite_account_id' => $coci2, 'entry_side' => 'credit',
        'is_check_deposit' => 1, 'clearing_days' => 7, 'is_error_correct' => 0, 'related_code' => null, 'is_system' => 0]);
    $m = newMember('Ilagan', 'Paz', ['membership_date' => '2024-01-01']);
    $sc = openAccount($m, 'SC', '2026-01-02');
    $rs = openAccount($m, 'RS', '2026-01-02');
    shift('2026-01-05');
    $coci = acct('1110');
    $before = gl($coci);
    $r = receipt($m, '2026-02-02', [['kind' => 'savings', 'account_id' => $rs, 'amount' => '1000', 'code' => 'CKOT'], ['kind' => 'savings', 'account_id' => $sc, 'amount' => '500']],
        ['mode' => 'check', 'cheques' => [['cheque_no' => '778899', 'bank' => 'Rural Bank', 'date' => '2026-02-02', 'amount' => '1500']]]);
    eqm(gl($coci2), '1000', 'the out-of-town check waits in its own clearing account');
    eqm(bcsub(gl($coci), $before, 2), '500', 'the other check deposit waits in the default clearing account');
    foreach (DB::all('SELECT id FROM sav_txns WHERE txn_id = :t', ['t' => $r['txn_id']]) as $t) {
        Savings::clearCheck((int)$t['id'], $GLOBALS['adminCash'], '2026-02-12');
    }
    eqm(gl($coci2), '0', 'Check Clearing empties the out-of-town clearing account');
    eqm(bcsub(gl($coci), $before, 2), '0', 'and the default one');
    // a loan payment by check waits in the default clearing account until it is cleared on Check Clearing
    receipt($m, '2026-02-03', [['kind' => 'savings', 'account_id' => $sc, 'amount' => '1500']]);
    $loan = releaseLoan(approvedLoan($m, 'REG', '10000', '6', '2026-02-03'), '2026-02-04', '2026-03-04')['loan_id'];
    $r2 = receipt($m, '2026-03-04', [['kind' => 'loan', 'loan_id' => $loan, 'amount' => '800']], ['mode' => 'check', 'cheques' => [['cheque_no' => '778900', 'bank' => 'Rural Bank', 'date' => '2026-03-04', 'amount' => '800']]]);
    $rec = DB::row('SELECT * FROM coop_receipts WHERE txn_id = :t', ['t' => $r2['txn_id']]);
    eqm($rec['check_pending'], '800', 'the receipt records the check waiting to clear');
    check(in_array((int)$rec['id'], array_map(fn($x) => (int)$x['id'], Teller::checksToClear()), true), 'it is listed on Check Clearing');
    $clr = Teller::clearReceiptCheck((int)$rec['id'], $GLOBALS['adminCash'], '2026-03-08');
    eqm(bcsub(gl($coci), $before, 2), '0', 'clearing it empties the clearing account');
    refuses(fn() => Teller::clearReceiptCheck((int)$rec['id'], $GLOBALS['adminCash'], '2026-03-08'), 'not waiting', 'a check is cleared once');
    refuses(fn() => Txn::void($r2['txn_id'], 'Returned check'), 'Void that voucher first', 'the receipt of a cleared check is not voided before its clearing');
    Txn::void($clr, 'Cleared by mistake');
    check(in_array((int)$rec['id'], array_map(fn($x) => (int)$x['id'], Teller::checksToClear()), true), 'voiding the clearing puts the check back on the list');
    Txn::void($r2['txn_id'], 'Returned check: DAIF');
    eqm(bcsub(gl($coci), $before, 2), '0', 'the returned check leaves the clearing account through the void of its receipt');
    eqm(Loans::loan($loan)['principal_balance'], '10000', 'and the payment comes off the loan');
});

test('Loan set-up rules in effect: a charge applies from its effective date; products set to income when collected are not accrued', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    $p = Loans::product($ids['loan.REG']);
    $before = count(Loans::charges($p, '10000', '12', '2026-11-30'));
    $cid = DB::insert('loan_charges', ['code' => 'NEWF', 'name' => 'New filing fee', 'charge_type' => 'financial', 'account_id' => acct('4420'), 'charge_by' => 'fixed_amount', 'value' => '150', 'effective_date' => '2026-12-01', 'is_active' => 1]);
    DB::insert('loan_product_charges', ['product_id' => $p['id'], 'charge_id' => $cid, 'sort_order' => 9]);
    eq(count(Loans::charges($p, '10000', '12', '2026-11-30')), $before, 'not charged before its effective date');
    eq(count(Loans::charges($p, '10000', '12', '2026-12-01')), $before + 1, 'charged from its effective date');
    DB::run('DELETE FROM loan_product_charges WHERE charge_id = :c', ['c' => $cid]);
    $through = today();
    $all = Loans::accrueInterest($through, $through, 'loan.penalties', false);
    DB::run("UPDATE loan_products SET interest_posting = 'none' WHERE id = :p", ['p' => $p['id']]);
    $without = Loans::accrueInterest($through, $through, 'loan.penalties', false);
    DB::run("UPDATE loan_products SET interest_posting = 'monthly' WHERE id = :p", ['p' => $p['id']]);
    $regLoans = array_map(fn($l) => $l['loan_no'], array_filter($all['lines'], fn($l) => (int)DB::value('SELECT product_id FROM loan_accounts WHERE loan_no = :n', ['n' => $l['loan_no']]) === (int)$p['id']));
    check(count($regLoans) > 0, 'Regular Loans have interest to accrue (' . count($regLoans) . ')');
    check(!array_intersect($regLoans, array_column($without['lines'], 'loan_no')), 'none of them is accrued when the product says income when collected');
});

test('Ledger integrity after the additional cases', function () {
    assertLedgerBalanced('final (additional cases)');
    assertSlGl('final (additional cases)');
    $unbalanced = DB::all('SELECT txn_id, SUM(debit) d, SUM(credit) c FROM journal_lines GROUP BY txn_id HAVING ABS(SUM(debit) - SUM(credit)) > 0.001');
    eq($unbalanced, [], 'no voucher in the whole ledger is out of balance');
});
