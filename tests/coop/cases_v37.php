<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v8.xlsx (version 37) at the level of the program's rules:
 *  - Loan Adjustments: Print to Passbook. A loan adjustment is a line of the loan passbook (code AJ: a decrease as a
 *    payment, an increase negative); the adjustments of before the upgrade are passed over once;
 *  - Loan Statement of Account: the search box lists the borrowers with outstanding loans (name, loan product, loan
 *    no.), narrowed by what is typed;
 *  - Interest on Share Capital and Patronage Refund: computing posts nothing; the list is reviewed, members with an
 *    obligation to the cooperative stand out and are removed (one or several, with the reason); then it is posted.
 * The pages (the option box, the list under the search box, the review screen) are checked in the browser
 * (docs/TEST_EVIDENCE.md).
 */

/** A loan on a loan passbook type of its own, released 75 days ago (two amortizations past due) */
function v37Loan(string $last, string $first, string $amount = '24000'): array
{
    $x = lcLoan($last, $first, $amount);
    $loan = Loans::loan($x['loan']);
    $type = v34Type('Adjustment passbook ' . $last, 'loan', ['start_line_skip' => 0, 'line_skip' => 0, 'max_lines' => 12]);
    $x['old_type'] = (int)DB::value('SELECT passbook_type_id FROM loan_products WHERE id = :p', ['p' => $loan['product_id']]);
    $x['product'] = (int)$loan['product_id'];
    DB::update('loan_products', ['passbook_type_id' => $type], 'id = :id', ['id' => $loan['product_id']]);
    $x['no'] = (string)$loan['loan_no'];
    return $x;
}

function v37Adjust(int $loanId, array $amounts, string $memo, ?int $against = null): int
{
    global $ids;
    $full = [];
    foreach (['principal', 'interest', 'penalty'] as $k) {
        $full[$k] = ['amount' => $amounts[$k][0] ?? '0', 'type' => $amounts[$k][1] ?? 'decrease'];
    }
    return CoopLedger::withClass((int)$ids['class.HO'], fn() => Loans::adjust($loanId, today(), $against ?? acct('4400'), $full, $memo, 'loan.adjustments'));
}

test('Loan adjustment in the loan passbook: Print to Passbook prints it (code AJ, a decrease as a payment, an increase negative); the adjustments of before are passed over once', function () {
    $x = v37Loan('Ajustado', 'Pablo');
    try {
        Auth::actAs($GLOBALS['adminId']);
        shift(today());
        lcPay($x['member'], $x['loan'], '1500');
        // a principal decrease and an interest increase in one adjustment (the principal posts a journal voucher)
        $before = Loans::loan($x['loan']);
        v37Adjust($x['loan'], ['principal' => ['500', 'decrease'], 'interest' => ['100', 'increase']], 'Wrong amount on the release');
        $run = Passbook::run(Passbook::open('loan', $x['no']));
        $codes = array_merge(...array_map(fn($p) => array_column($p['lines'], 'code'), $run['pages']));
        eq($codes, ['LR', 'LP', 'AJ'], 'the release, the payment and the adjustment are the lines to print');
        $aj = end($run['pages'][0]['lines']);
        $ajRow = DB::row("SELECT x.*, t.txn_no FROM loan_txns x LEFT JOIN transactions t ON t.id = x.txn_id WHERE x.loan_id = :l AND x.kind = 'adjustment' ORDER BY x.id DESC LIMIT 1", ['l' => $x['loan']]);
        eq([Dec::fix($aj['prin']), Dec::fix($aj['int']), Dec::fix($aj['fines'])], ['500.00', '-100.00', '0.00'], 'a decrease prints as a payment, an increase negative');
        eqm($aj['bal'], Dec::sub($before['principal_balance'], '500', 2), 'the loan balance after the adjustment');
        eq($aj['ref'], (string)$ajRow['txn_no'], 'the reference is the voucher of the adjustment');
        // an interest-only adjustment posts no voucher: ADJ
        v37Adjust($x['loan'], ['interest' => ['50', 'decrease']], 'Interest rebate');
        $run = Passbook::run(Passbook::open('loan', $x['no']));
        $last = end($run['pages'][count($run['pages']) - 1]['lines']);
        eq([$last['code'], $last['ref'], Dec::fix($last['int']), Dec::fix($last['prin'])], ['AJ', 'ADJ', '50.00', '0.00'], 'an adjustment without a voucher prints ADJ');
        // the Loan Ledger Form, passbook view: the same lines as the passbook
        $led = Loans::ledgerForm(Loans::loan($x['loan']), false);
        $adjRows = array_values(array_filter($led['rows'], fn($r) => str_contains($r['ref'], '(adjustment)')));
        eq(count($adjRows), 2, 'the passbook view of the Loan Ledger Form shows both adjustments');
        eq([Dec::fix($adjRows[0]['prin']), Dec::fix($adjRows[0]['int']), Dec::fix($adjRows[0]['release'])], ['500.00', '-100.00', '0.00'], 'with the amounts the passbook prints');
        // the passbook printed: the lines are marked; nothing left
        $pb = Passbook::open('loan', $x['no']);
        [$n] = Passbook::commitRun($pb, v35Posted($run), (int)end($run['pages'])['page'], 'sav.passbook');
        eq($n, 4, 'the four lines are marked printed');
        eq(Passbook::run(Passbook::open('loan', $x['no']))['count'], 0, 'nothing left to print');
        // the Loan Adjustments page: the Print to Passbook window lists the lines of the loan not yet printed
        if (!function_exists('coop_pb_run_box')) {
            $GLOBALS['PAGE'] = ['name' => 'Tests'];
            require_once APP_ROOT . '/modules/coop/_coop.php';
        }
        v37Adjust($x['loan'], ['penalty' => ['20', 'increase']], 'Penalty left out');
        $pb = Passbook::open('loan', $x['no']);
        $box = coop_pb_run_box($pb, Passbook::run($pb), 'laPbConfirm', '<input type="hidden" name="action" value="pb_commit">', 'X', true);
        check(str_contains($box, '1 line on this page') && str_contains($box, 'data-pb-yes'), 'the window prints the adjustment and asks whether it printed right');
        // the upgrade: an adjustment recorded before it is passed over (marked, without a page), once
        DB::run("DELETE FROM settings WHERE setting_key = 'loan_pb_adjustments_since'");
        run_sql_file($GLOBALS['pdo'], APP_ROOT . '/database/migrations/2027_01_31_adjustment_passbook.sql');
        $old = DB::row("SELECT * FROM loan_txns WHERE loan_id = :l AND kind = 'adjustment' ORDER BY id DESC LIMIT 1", ['l' => $x['loan']]);
        eq([(int)$old['pb_printed'], $old['pb_page']], [1, null], 'the adjustment of before is passed over: marked, on no page');
        eq(Passbook::run(Passbook::open('loan', $x['no']))['count'], 0, 'it does not come out on the next printing');
        check(!in_array((int)$old['id'], array_column(array_merge(...array_values(Passbook::printedBetween(Passbook::open('loan', $x['no']), '2000-01-01', today()))), 'id'), true),
            'and it is not among the printed lines of a reprint');
        v37Adjust($x['loan'], ['penalty' => ['5', 'decrease']], 'Recorded after the upgrade');
        run_sql_file($GLOBALS['pdo'], APP_ROOT . '/database/migrations/2027_01_31_adjustment_passbook.sql');
        eq(Passbook::run(Passbook::open('loan', $x['no']))['count'], 1, 'run again, the upgrade leaves an adjustment recorded after it to be printed');
        svCloseShift();
    } finally {
        DB::update('loan_products', ['passbook_type_id' => $x['old_type'] ?: null], 'id = :id', ['id' => $x['product']]);
    }
});

test('Loan Statement of Account: the search box lists the borrowers with outstanding loans (name, loan product, loan no.), narrowed by a name, a loan no. or a product', function () {
    Auth::actAs($GLOBALS['adminId']);
    $a = lcLoan('Zubiri', 'Lorna');
    $b = lcLoan('Zubiaga', 'Marco', '12000');
    $all = Loans::searchOutstanding('', 500);
    $open = (int)DB::value("SELECT COUNT(*) FROM loan_accounts WHERE status IN ('current','past_due','restructured')");
    eq($all['total'], $open, 'clicked without typing: every loan with a balance');
    $names = array_column($all['rows'], 'name');
    $sorted = $names;
    usort($sorted, fn($x, $y) => strcmp($x, $y));
    eq($names, $sorted, 'listed by the borrower\'s name');
    $z = Loans::searchOutstanding('zubi');
    eq(array_column($z['rows'], 'name'), ['Zubiaga, Marco', 'Zubiri, Lorna'], 'letters typed: the borrowers whose name starts with them');
    eq(array_column(Loans::searchOutstanding('zubi lor')['rows'], 'name'), ['Zubiri, Lorna'], 'every word typed narrows the list');
    $loanA = Loans::loan($a['loan']);
    $byNo = Loans::searchOutstanding(substr((string)$loanA['loan_no'], -5));
    check(in_array($loanA['loan_no'], array_column($byNo['rows'], 'loan_no'), true), 'part of the loan no. finds the loan');
    eq(Loans::searchOutstanding((string)$loanA['loan_no'])['rows'][0]['loan_no'], $loanA['loan_no'], 'the exact loan no. comes first');
    $byProduct = Loans::searchOutstanding((string)$loanA['product_name']);
    $sameProduct = (int)DB::value("SELECT COUNT(*) FROM loan_accounts WHERE product_id = :p AND status IN ('current','past_due','restructured')", ['p' => $loanA['product_id']]);
    check($byProduct['total'] >= $sameProduct && in_array('Zubiri, Lorna', array_column($byProduct['rows'], 'name'), true), 'the loan product typed: the borrowers with that product');
    eq(Loans::searchOutstanding('nobodyhere')['total'], 0, 'nothing matches: an empty list');
    // a paid loan is not outstanding
    DB::update('loan_accounts', ['status' => 'paid'], 'id = :id', ['id' => $b['loan']]);
    eq(array_column(Loans::searchOutstanding('zubi')['rows'], 'name'), ['Zubiri, Lorna'], 'a fully paid loan is not listed');
    DB::update('loan_accounts', ['status' => 'current'], 'id = :id', ['id' => $b['loan']]);
    $row = Loans::searchOutstanding('zubiri')['rows'][0];
    eq([$row['product'], $row['loan_no']], [$loanA['product_name'], $loanA['loan_no']], 'each with its loan product and loan no.');
    // the report: a loan no., one borrower's name, or a word that matches several loans
    $def = Report::get('loan_soa');
    $run = fn(string $q) => $def['run'](['asof' => today(), 'q' => $q, 'class_id' => null]);
    $r = $run((string)$loanA['loan_no']);
    check(count($r['rows']) >= 2 && str_contains($r['notes'], 'Zubiri, Lorna'), 'the loan no. chosen: its statement');
    $r = $run('Zubiri Lorna');
    check(str_contains($r['notes'], (string)$loanA['loan_no']) && ($r['rows'][0]['date'] ?? '') !== '', 'a name that finds one loan: its statement');
    $r = $run('Zubi');
    eq([array_column($r['rows'], 'loan'), $r['columns'][0]['label']], [[Loans::loan($b['loan'])['loan_no'], $loanA['loan_no']], 'Loan no.'], 'a name that finds two loans: the loans to choose from');
    check(str_contains($r['rows'][0]['_links']['loan'] ?? '', 'rpt.loan_soa'), 'each opens its statement');
    $r = $run('Louis Vincent');
    check($r['rows'] === [] && str_contains($r['notes'], 'no borrower with an outstanding loan matches'), 'a name with no loan: it says so');
    check(str_contains(Loans::pickerBox('q', '', ['submit' => true]), 'data-loan-pick') && str_contains(Loans::pickerBox('q', '', []), 'a=loans'), 'the box asks the server for the borrowers as it is typed in');
    // branch scope: a branch teller sees the loans of the branch only
    asUser('teller2');
    check(!in_array('Zubiri, Lorna', array_column(Loans::searchOutstanding('zubi')['rows'], 'name'), true), 'a branch teller does not see the loans of another branch');
    Auth::actAs($GLOBALS['adminId']);
    svCloseShift();
});

test('Interest on share capital and patronage refund: computed for review (nothing posted), the members who owe the cooperative stand out and are removed with the reason, then posted', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    $late = v37Loan('Atrasado', 'Ruben', '18000');       // a loan with two amortizations past due
    DB::update('loan_products', ['passbook_type_id' => $late['old_type'] ?: null], 'id = :id', ['id' => $late['product']]);
    $good = newMember('Puntual', 'Elena');
    $hold = newMember('Retenido', 'Samuel');
    foreach ([$good, $hold] as $m) {
        $sc = openAccount($m, 'SC', '2026-01-02');
        shift(today());
        receipt($m, today(), [['kind' => 'savings', 'account_id' => $sc, 'amount' => '3000']]);
    }
    DB::update('members', ['hold_flag' => 1, 'hold_reason' => 'Unpaid store account'], 'id = :id', ['id' => $hold]);
    $o = Distribution::obligations([$late['member'], $good, $hold], today());
    check(Dec::isPos($o[$late['member']]['past_due']) && $o[$late['member']]['past_due_loans'] === [$late['no']], 'the borrower has a past-due amount on the loan');
    check(Dec::isPos($o[$late['member']]['loan_balance']), 'and a loan balance');
    eq([$o[$hold]['hold'], Distribution::hasObligation($o[$hold]), Distribution::hasObligation($o[$good])], ['Unpaid store account', true, false], 'a member on hold has an obligation; a member with none has not');
    // the reasons
    eq(Distribution::removeReason('delinquent', 'loan in arrears'), 'Delinquent: past-due loan: loan in arrears', 'a reason chosen, with its details');
    refuses(fn() => Distribution::removeReason('other', ' '), 'reason', 'another reason needs its details');
    // computing posts nothing: the batch is in review
    $txnsBefore = (int)DB::value('SELECT COUNT(*) FROM transactions');
    $bid = Distribution::compute('isc', ['period_id' => null, 'date_from' => today(), 'date_to' => today(), 'method' => 'pool', 'rate' => '0', 'pool' => '9000',
        'basis' => 'ending', 'credit_mode' => 'share_capital', 'credit_product_id' => null, 'description' => 'ISC review'], [], null, 'dist.isc');
    eq([Distribution::batch($bid)['status'], (int)DB::value('SELECT COUNT(*) FROM transactions')], ['draft', $txnsBefore], 'computed: a batch in review, nothing posted');
    $line = fn(int $m) => DB::row('SELECT * FROM dist_batch_lines WHERE batch_id = :b AND member_id = :m', ['b' => $bid, 'm' => $m]);
    check($line($late['member']) && $line($good) && $line($hold), 'the three members are on the list');
    eq(Distribution::validate($bid, 'dist.isc'), [], 'the review can be finished as it is');
    // two members removed together, with the reason: the pool is shared again among the others; the review starts again
    $n = Distribution::removeLines($bid, [(int)$line($late['member'])['id'], (int)$line($hold)['id']], Distribution::removeReason('obligation', 'committee 10/29'), 'dist.isc');
    eq($n, 2, 'two members removed');
    eq([(int)$line($late['member'])['is_excluded'], $line($late['member'])['exclude_reason'], (int)$line($hold)['is_excluded']], [1, 'Has another obligation to the cooperative: committee 10/29', 1], 'removed with the reason');
    eq(Distribution::batch($bid)['status'], 'draft', 'the list is back in review');
    $b = Distribution::batch($bid);
    eqm($b['total_amount'], '9000', 'the pool is shared in full among the members left');
    eqm(Dec::of(DB::value('SELECT COALESCE(SUM(net_amount),0) FROM dist_batch_lines WHERE batch_id = :b AND is_excluded = 0', ['b' => $bid])), '9000', 'the members left take it');
    eq([Dec::fix($line($late['member'])['net_amount']), Dec::fix($line($hold)['net_amount'])], ['0.00', '0.00'], 'the members removed get nothing');
    eq((int)DB::value("SELECT COUNT(*) FROM audit_trail WHERE record_type = 'dist_batch' AND record_id = :b AND action = 'exclude'", ['b' => $bid]), 2, 'each removal is in the audit trail');
    refuses(fn() => Distribution::removeLines($bid, [], 'x', 'dist.isc'), 'Tick the members', 'nothing ticked: refused');
    // put back, then removed again on its own
    Distribution::exclude($bid, (int)$line($hold)['id'], false, '', null, 'dist.isc');
    eq((int)$line($hold)['is_excluded'], 0, 'put back on the list');
    Distribution::removeLines($bid, [(int)$line($hold)['id']], Distribution::removeReason('standing', ''), 'dist.isc');
    eq($line($hold)['exclude_reason'], 'Not a member in good standing', 'removed again on its own');
    // reviewed, approved, posted: only the members on the list are credited
    eq(Distribution::validate($bid, 'dist.isc'), [], 'the review is finished');
    asUser('gm');
    Distribution::approve($bid, 'BR-2026-29', 'dist.isc');
    refuses(fn() => Distribution::removeLines($bid, [(int)$line($good)['id']], 'x', 'dist.isc'), 'locked', 'after the approval the list is locked');
    Auth::actAs($GLOBALS['adminId']);
    $scLate = (int)DB::value("SELECT a.id FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND p.product_type = 'share_capital'", ['m' => $late['member']]);
    $balLate = Savings::account($scLate)['balance'];
    Distribution::post($bid, today(), 0, 'dist.isc');
    eqm(Savings::account($scLate)['balance'], $balLate, 'a member removed is not credited');
    check(Dec::isPos($line($good)['net_amount']) && (bool)$line($good)['sav_txn_id'], 'a member on the list is credited');
    // the patronage refund: the same review
    $pid = Distribution::compute('pr', ['period_id' => null, 'date_from' => '2026-01-01', 'date_to' => today(), 'method' => 'rate', 'rate' => '5', 'pool' => '0',
        'credit_mode' => 'payable', 'credit_product_id' => null], ['income_accounts' => [acct('4400')]], null, 'dist.pr');
    $pl = DB::row('SELECT * FROM dist_batch_lines WHERE batch_id = :b AND member_id = :m', ['b' => $pid, 'm' => $late['member']]);
    if ($pl) {
        $other = Dec::of(DB::value('SELECT total_amount FROM dist_batches WHERE id = :b', ['b' => $pid]));
        Distribution::removeLines($pid, [(int)$pl['id']], Distribution::removeReason('delinquent', ''), 'dist.pr');
        eqm(Dec::of(DB::value('SELECT total_amount FROM dist_batches WHERE id = :b', ['b' => $pid])), Dec::sub($other, $pl['net_amount'], 2), 'patronage refund at a rate: removing a member takes off only its amount');
    } else {
        check(true, 'no patronage for the borrower in the period');
    }
    eq(Distribution::batch($pid)['status'], 'draft', 'the patronage refund is in review, not posted');
    svCloseShift();
});
