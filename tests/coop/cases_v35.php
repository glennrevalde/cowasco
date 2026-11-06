<?php
declare(strict_types=1);

/**
 * The requests of the second ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v6.xlsx (version 35) at the level of the
 * program's rules:
 *  - a loan application that was never released can be deleted (Loan Application Center), with what belongs to it;
 *  - a passbook with unprinted lines on two or more pages prints them all in one run: page by page, the teller is
 *    asked to turn the passbook to the next page; the pages that printed right are marked printed together;
 *  - a payment pays the penalties due, then the interest due, then the principal; with no penalty and no interest
 *    due, the whole payment goes to the principal (an advance is not taken as interest not yet due).
 * The print windows (the validation closing itself, the form cleared after printing, "Turn the passbook to page 2")
 * are checked in the browser (docs/TEST_EVIDENCE.md).
 */

/** A savings account on a small passbook (lines 1 to 3 and 6 to 9 of a page: 7 lines) with $n deposits not printed */
function v35Book(string $last, int $n, array $layoutOpt = []): array
{
    Auth::actAs($GLOBALS['adminId']);
    $lay = Passbook::defaultLayout('savings');
    $lay['opt'] = $layoutOpt + $lay['opt'];
    $type = v34Type('Run passbook ' . $last, 'savings', ['start_line_skip' => 4, 'line_skip' => 2, 'max_lines' => 9], $lay);
    $m = newMember($last, 'Run');
    $rs = openAccount($m, 'RS', '2026-02-01');
    DB::update('sav_accounts', ['passbook_type_id' => $type], 'id = :id', ['id' => $rs]);
    shift(today());
    for ($i = 1; $i <= $n; $i++) {
        receipt($m, today(), [['kind' => 'savings', 'account_id' => $rs, 'amount' => (string)(100 * $i)]]);
    }
    return ['member' => $m, 'account' => $rs, 'no' => (string)Savings::account($rs)['account_no'], 'type' => $type];
}

/** What the confirm form of a run posts: row id => [page, line] */
function v35Posted(array $run, ?int $upto = null): array
{
    $out = [];
    foreach ($run['pages'] as $p) {
        if ($upto !== null && $p['page'] > $upto) {
            break;
        }
        foreach ($p['lines'] as $l) {
            $out[(int)$l['id']] = [(int)$p['page'], (int)$l['line']];
        }
    }
    return $out;
}

test('Delete a loan application: one never released goes with its co-makers, collateral, approvals, requirements and credit investigation; a released one stays', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    if (!function_exists('coop_app_delete_modal')) {
        $GLOBALS['PAGE'] = ['name' => 'Tests'];
        require_once APP_ROOT . '/modules/coop/_coop.php';
    }
    $m = newMember('Domingo', 'Arnel');
    $co = newMember('Comaker', 'Uno');
    $app = Loans::saveApplication(['member_id' => $m, 'product_id' => $ids['loan.PRD'], 'application_date' => today(), 'purpose' => 'Business capital', 'term' => '18', 'term_unit' => 'months',
        'mode_of_payment' => 'monthly', 'applied_amount' => '60000'], [['collateral_type' => 'Vehicle', 'description' => 'Honda Click', 'fmv' => '95000', 'loan_value' => '60000']],
        [['comaker_member_id' => $co, 'amount_guaranteed' => '30000']], null, 'loan.application');
    $no = Loans::application($app)['application_no'];
    DB::insert('loan_application_docs', ['application_id' => $app, 'requirement' => 'Valid government ID', 'is_complete' => 1]);
    DB::insert('loan_approvals', ['application_id' => $app, 'level_no' => 1, 'approver_id' => $GLOBALS['adminId'], 'action' => 'approved', 'approved_amount' => '60000']);
    DB::update('loan_applications', ['status' => 'approved', 'approved_amount' => '60000'], 'id = :id', ['id' => $app]);
    $ci = DB::insert('loan_ci', ['application_id' => $app, 'member_id' => $m, 'date_reported' => today(), 'current_address' => 'Cebu City']);
    DB::insert('loan_ci_lines', ['ci_id' => $ci, 'kind' => 'asset', 'name' => 'Motorcycle', 'amount' => '95000']);
    $count = fn(string $t, string $k, int $id) => (int)DB::value("SELECT COUNT(*) FROM $t WHERE $k = :i", ['i' => $id]);
    eq([$count('loan_comakers', 'application_id', $app), $count('loan_collaterals', 'application_id', $app), $count('loan_approvals', 'application_id', $app), $count('loan_ci_lines', 'ci_id', $ci)],
        [1, 1, 1, 1], 'the application has a co-maker, collateral, an approval and an investigation');
    eq(Loans::deleteBlocker(Loans::application($app)), null, 'an approved application not yet released can be deleted');
    refuses(fn() => Loans::deleteApplication($app, '  ', 'loan.application'), 'reason', 'the reason is required');
    $before = (int)DB::value('SELECT COUNT(*) FROM loan_applications');
    Loans::deleteApplication($app, 'Encoded twice', 'loan.application');
    eq(Loans::application($app), null, 'the application is gone');
    eq((int)DB::value('SELECT COUNT(*) FROM loan_applications'), $before - 1, 'one application less');
    eq([$count('loan_comakers', 'application_id', $app), $count('loan_collaterals', 'application_id', $app), $count('loan_approvals', 'application_id', $app),
        $count('loan_application_docs', 'application_id', $app), $count('loan_ci', 'application_id', $app), $count('loan_ci_lines', 'ci_id', $ci)], [0, 0, 0, 0, 0, 0],
        'its co-makers, collateral, approvals, requirements and credit investigation go with it');
    $a = DB::row("SELECT * FROM audit_trail WHERE action = 'delete' AND record_type = 'loan_application' AND record_id = :a ORDER BY id DESC LIMIT 1", ['a' => $app]);
    check((bool)$a, 'the delete is in the audit trail');
    eq((string)$a['reason'], 'Encoded twice', 'with its reason');
    check(str_contains((string)$a['record_ref'], $no), 'and the application number');
    $next = loanApp($m, 'REG', '5000', '6', today());
    check(Loans::application($next)['application_no'] !== $no, 'the number of the deleted application is not used again');
    refuses(fn() => Loans::deleteApplication($app, 'again', 'loan.application'), 'not found', 'a deleted application cannot be deleted twice');
    $html = coop_app_delete_modal('deleteApp', null);
    check(str_contains($html, 'name="action" value="delete"') && str_contains($html, 'name="reason"') && str_contains($html, 'data-del-no'), 'the Loan Application Center\'s delete window asks for the reason');
    // a released loan's application stays
    $sc = openAccount($m, 'SC', '2026-01-02');
    shift(today());
    receipt($m, today(), [['kind' => 'savings', 'account_id' => $sc, 'amount' => '2500']]);
    Loans::deleteApplication($next, 'Replaced', 'loan.application');
    $rel = approvedLoan($m, 'EMR', '5000', '3', today());
    $r = releaseLoan($rel, today(), date('Y-m-d', strtotime(today() . ' +15 days')));
    refuses(fn() => Loans::deleteApplication($rel, 'x', 'loan.application'), 'released', 'the application of a released loan cannot be deleted');
    // a release posted and then voided: the cancelled loan account still points to the application
    Txn::void($r['txn_id'], 'Released to the wrong member');
    eq(Loans::application($rel)['status'], 'approved', 'after the void the application is approved again');
    refuses(fn() => Loans::deleteApplication($rel, 'x', 'loan.application'), 'Cancel the application instead', 'an application whose release was voided stays on record');
    check(Loans::deleteBlocker(Loans::application($rel)) !== null, 'and its Delete button is not shown');
    svCloseShift();
});

test('Payment allocation: nothing due, the whole payment goes to the principal; with dues, 1. penalties, 2. interest, 3. principal', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    // a loan released today: the first amortization is due next month, nothing is due
    $m = newMember('Tajanlangit', 'Glen');
    $sc = openAccount($m, 'SC', '2026-01-02');
    shift(today());
    receipt($m, today(), [['kind' => 'savings', 'account_id' => $sc, 'amount' => '2500']]);
    $app = approvedLoan($m, 'REG', '100000', '12', today());
    $r = releaseLoan($app, today(), date('Y-m-d', strtotime(today() . ' +1 month')));
    $loan = Loans::loan($r['loan_id']);
    $d = Loans::dues($loan, today());
    eq([Dec::fix($d['penalty_due']), Dec::fix($d['interest_due']), Dec::fix($d['principal_due'])], ['0.00', '0.00', '0.00'], 'nothing is due yet');
    [$pen, $int, $prn, $ex] = Loans::allocate($d, '16000', null);
    eq([Dec::fix($pen), Dec::fix($int), Dec::fix($prn), Dec::fix($ex)], ['0.00', '0.00', '16000.00', '0.00'], 'the whole 16,000 goes to the principal (before, part of it went to the interest of the next amortizations)');
    $p = lcPay($m, $loan['id'], '16000');
    $row = lcPayRow((int)$p['txn_id']);
    eq([Dec::fix($row['principal_cr']), Dec::fix($row['interest_cr']), Dec::fix($row['penalty_cr'])], ['16000.00', '0.00', '0.00'], 'saved so: principal 16,000');
    $after = Loans::loan($loan['id']);
    eqm($after['principal_balance'], Dec::sub($loan['principal_balance'], '16000', 2), 'the principal balance is 16,000 less');
    eqm($after['interest_balance'], $loan['interest_balance'], 'the interest balance is unchanged');
    eqm(DB::value('SELECT COALESCE(SUM(principal_paid),0) FROM loan_schedule WHERE loan_id = :l', ['l' => $loan['id']]), '16000', 'the schedule: 16,000 of principal paid, oldest amortization first');
    eqm(DB::value('SELECT COALESCE(SUM(interest_paid),0) FROM loan_schedule WHERE loan_id = :l', ['l' => $loan['id']]), '0', 'no interest taken ahead');
    assertSlGl('after a principal payment');

    // a loan with amortizations past due: penalties, then interest, then principal
    $x = lcLoan('Prioridad', 'Pago', '24000');
    lcPenalties($x['loan']);
    $l2 = Loans::loan($x['loan']);
    $d2 = Loans::dues($l2, today());
    check(Dec::isPos($d2['penalty_due']) && Dec::isPos($d2['interest_due']) && Dec::isPos($d2['principal_due']), 'penalties, interest and principal are due');
    $small = Dec::round(Dec::div($d2['penalty_due'], '2', 4), 2);
    eq(array_map([Dec::class, 'fix'], array_slice(Loans::allocate($d2, $small, null), 0, 3)), [Dec::fix($small), '0.00', '0.00'], '1. a payment below the penalty due pays the penalty only');
    $two = Dec::add($d2['penalty_due'], Dec::round(Dec::div($d2['interest_due'], '2', 4), 2), 2);
    eq(array_map([Dec::class, 'fix'], array_slice(Loans::allocate($d2, $two, null), 0, 3)), [Dec::fix($d2['penalty_due']), Dec::fix(Dec::sub($two, $d2['penalty_due'], 2)), '0.00'], '2. then the interest due');
    $big = Dec::add($d2['total_due'], '3000', 2);
    [$pen, $int, $prn, $ex] = Loans::allocate($d2, $big, null);
    eq([Dec::fix($pen), Dec::fix($int), Dec::fix($prn), Dec::fix($ex)], [Dec::fix($d2['penalty_due']), Dec::fix($d2['interest_due']), Dec::fix(Dec::add($d2['principal_due'], '3000', 2)), '0.00'],
        '3. then the principal: the principal due and everything above it (no interest of the amortizations not yet due)');
    // beyond the whole principal: the interest not yet due, as a payoff takes it; beyond the payoff, an excess
    [$pen, $int, $prn, $ex] = Loans::allocate($d2, $d2['payoff'], null);
    eqm($prn, $d2['principal_balance'], 'the payoff amount pays the whole principal');
    eqm(Dec::add(Dec::add($pen, $int, 2), $prn, 2), $d2['payoff'], 'and the interest to date: nothing is left over');
    eqm($ex, '0', 'no excess');
    eqm(Loans::allocate($d2, Dec::add($d2['payoff'], '100', 2), null)[3], '100', 'more than the payoff is an excess (refused on saving)');
    // a straight (add-on) loan: beyond the principal, the remaining scheduled interest
    $d3 = ['penalty_due' => '0', 'interest_due' => '0', 'principal_due' => '0', 'principal_balance' => '1000', 'interest_balance' => '120', 'interest_method' => 'straight', 'interest_to_date' => '0'];
    eq(array_map([Dec::class, 'fix'], Loans::allocate($d3, '1050', null)), ['0.00', '50.00', '1000.00', '0.00'], 'a straight loan: the principal first, then its scheduled interest');
    // the override still splits by hand
    eq(array_map([Dec::class, 'fix'], Loans::allocate($d2, '500', ['penalty' => '0', 'interest' => '500', 'principal' => '0'])), ['0.00', '500.00', '0.00', '0.00'], 'Override Payment splits it as typed');
    svCloseShift();
});

test('Passbook run: unprinted lines on several pages print in one run, page by page (turn the passbook to page 2, 3 ...), and are marked printed together', function () {
    global $ids;
    if (!function_exists('coop_pb_run_box')) {
        $GLOBALS['PAGE'] = ['name' => 'Tests'];
        require_once APP_ROOT . '/modules/coop/_coop.php';
    }
    $b = v35Book('Paginas', 16);
    $pb = Passbook::open('savings', $b['no']);
    $run = Passbook::run($pb);
    eq([$run['count'], array_column($run['pages'], 'page')], [16, [1, 2, 3]], '16 lines on pages 1, 2 and 3 (7 lines a page)');
    eq(array_map(fn($p) => array_column($p['lines'], 'line'), $run['pages']), [[1, 2, 3, 6, 7, 8, 9], [1, 2, 3, 6, 7, 8, 9], [1, 2]], 'each page from line 1, the fold lines 4 and 5 left blank');
    eq(array_column($run['pages'], 'header'), [true, true, true], 'the header prints first on every new page');
    eq([$run['pages'][0]['forward'] !== null, $run['pages'][1]['forward'] !== null, $run['pages'][2]['forward']], [true, true, null], 'a full page carries its balance to the next one');
    eqm($run['pages'][0]['forward'], $run['pages'][0]['lines'][6]['balance'], 'the balance of its last line');
    eq(Passbook::runWords($run), 'page 1 (lines 1 to 9), page 2 (lines 1 to 9) and page 3 (lines 1 to 2)', 'the pages in words');
    eq(array_column(Passbook::plan($pb)['lines'], 'id'), array_column($run['pages'][0]['lines'], 'id'), 'the first page of the run is what one page prints');
    // the Update Passbook window: the pages, one Print button, the question for all or the first pages
    $box = coop_pb_run_box($pb, $run, 'pbT', '<input type="hidden" name="action" value="pb_commit">', 'X', true);
    check(str_contains($box, '16 lines on 3 pages, printed in one run') && str_contains($box, 'turn the passbook to page 2'), 'it says the lines print on 3 pages in one run, turning the passbook');
    check(str_contains($box, 'Print the 3 pages'), 'one Print button for the 3 pages');
    eq(substr_count($box, 'name="upto"'), 3, 'three answers: every page, pages 1 and 2, page 1');
    check(str_contains($box, 'Yes, every page (pages 1 to 3)') && str_contains($box, 'Only pages 1 and 2; page 3 prints again later') && str_contains($box, 'Only page 1; pages 2 and 3 print again later'), 'the answers in words');
    eq(substr_count($box, 'name="pg['), 16, 'every line is posted with its page');
    check(str_contains($box, '&amp;pg=3') && str_contains($box, 'Print page 3 again'), 'a page that came out wrong prints again alone');
    // a stale print (an earlier-dated entry came in between) is refused
    $posted = v35Posted($run);
    $bad = $posted;
    $first = array_key_first($bad);
    $bad[$first] = [1, 2];
    refuses(fn() => Passbook::commitRun($pb, $bad, 3, 'sav.passbook'), 'changed after they were printed', 'lines that no longer match the run are refused');
    // only page 1 printed right
    [$n, $pages] = Passbook::commitRun($pb, $posted, 1, 'sav.passbook');
    eq([$n, $pages], [7, [1]], 'page 1 alone: 7 lines marked');
    $pb = Passbook::open('savings', $b['no']);
    $run2 = Passbook::run($pb);
    eq([array_column($run2['pages'], 'page'), $run2['count'], $run2['pages'][0]['header']], [[2, 3], 9, true], 'the rest prints from page 2, line 1, after its header');
    // the rest in one go
    [$n, $pages] = Passbook::commitRun($pb, v35Posted($run2), 3, 'sav.passbook');
    eq([$n, $pages], [9, [2, 3]], 'pages 2 and 3 marked together');
    $acct = Savings::account($b['account']);
    eq([(int)$acct['pb_page'], (int)$acct['pb_last_line']], [3, 2], 'the next line is page 3, line 3');
    eq((int)DB::value("SELECT COUNT(*) FROM passbook_prints WHERE account_id = :a AND ledger = 'savings' AND print_type = 'lines'", ['a' => $b['account']]), 3, 'one print record per page');
    eq(Passbook::run(Passbook::open('savings', $b['no']))['count'], 0, 'nothing left to print');
    refuses(fn() => Passbook::commitRun(Passbook::open('savings', $b['no']), v35Posted($run2), 3, 'sav.passbook'), 'Nothing was printed', 'the same run cannot be marked twice');
    // a run that goes on from the middle of a page: no header there, the header on the next page
    receipt($b['member'], today(), [['kind' => 'savings', 'account_id' => $b['account'], 'amount' => '50']]);
    for ($i = 0; $i < 7; $i++) {
        receipt($b['member'], today(), [['kind' => 'savings', 'account_id' => $b['account'], 'amount' => '60']]);
    }
    $run3 = Passbook::run(Passbook::open('savings', $b['no']));
    eq(array_map(fn($p) => [$p['page'], $p['first'], $p['last'], $p['header']], $run3['pages']), [[3, 3, 9, false], [4, 1, 3, true]],
        'page 3 goes on at line 3 without a header (the page has one), then page 4 from line 1 after its header');
    svCloseShift();
});

test('Passbook run: the header modes of the design on the pages that follow (on a new page, with every printing, never) and a loan passbook over two pages', function () {
    global $ids;
    $b = v35Book('Cabecera', 9, ['header' => 'never']);
    $run = Passbook::run(Passbook::open('savings', $b['no']));
    eq(array_column($run['pages'], 'header'), [false, false], 'a preprinted header is never printed, not on page 2 either');
    $b2 = v35Book('Siempre', 9, ['header' => 'every']);
    $run = Passbook::run(Passbook::open('savings', $b2['no']));
    eq(array_column($run['pages'], 'header'), [true, true], 'with every printing: on each page of the run');
    // a loan passbook on a small page: the release and the payments over two pages
    $t = v34Type('Small loan passbook', 'loan', ['start_line_skip' => 0, 'line_skip' => 0, 'max_lines' => 3]);
    $x = lcLoan('Pasbuk', 'Dos', '24000');
    $loan = Loans::loan($x['loan']);
    $oldType = (int)DB::value('SELECT passbook_type_id FROM loan_products WHERE id = :p', ['p' => $loan['product_id']]);
    DB::update('loan_products', ['passbook_type_id' => $t], 'id = :id', ['id' => $loan['product_id']]);
    try {
        asUser('teller1');
        shift(today());
        lcPay($x['member'], $loan['id'], '1500');
        lcPay($x['member'], $loan['id'], '1500');
        lcPay($x['member'], $loan['id'], '1500');
        $pb = Passbook::open('loan', (string)$loan['loan_no']);
        $run = Passbook::run($pb);
        eq([array_column($run['pages'], 'page'), array_map(fn($p) => array_column($p['lines'], 'code'), $run['pages'])], [[1, 2], [['LR', 'LP', 'LP'], ['LP']]],
            'the release and two payments on page 1, the third payment on page 2');
        eq(array_column($run['pages'], 'header'), [true, true], 'the loan header (Loan Type, Term, Due Date) on each new page');
        $html = Passbook::render($pb, 2, $run['pages'][1]['lines'], true);
        $f = v34Fields($html);
        eq([$f['loan_type'][0] ?? '', $f['line'] ?? []], [$loan['product_name'], ['1']], 'page 2: the header, then line 1');
        [$n, $pages] = Passbook::commitRun($pb, v35Posted($run), 2, 'sav.passbook');
        eq([$n, $pages], [4, [1, 2]], 'all four lines marked on pages 1 and 2');
        eq(Passbook::next(Passbook::open('loan', (string)$loan['loan_no'])), [2, 2], 'the next loan entry prints on page 2, line 2');
        svCloseShift();
    } finally {
        DB::update('loan_products', ['passbook_type_id' => $oldType ?: null], 'id = :id', ['id' => $loan['product_id']]);
    }
});
