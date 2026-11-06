<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v11.xlsx (version 40) at the level of the program's rules:
 *  - Import Loans: the loans of the former system made Active from their release terms and the totals paid up to the
 *    cut-off date (taken as paid on the due dates), the past due interest of a matured loan and the overdue penalties
 *    worked out unless given, a journal voucher against the balancing GL account chosen;
 *  - Import Member: the full template with every other detail of the member;
 *  - the checker of the reviews: the rows with a problem, their cells named.
 * The pages (the grid in red, the check as a cell is corrected) are checked in the browser (docs/TEST_EVIDENCE.md).
 */

/** A row of the loan import, as the review sends it back */
function v40Loan(int $line, array $v): array
{
    $r = ['_line' => $line];
    foreach (array_keys(LoanImport::cols()) as $k) {
        $r[$k] = (string)($v[$k] ?? '');
    }
    return $r;
}

/** The totals paid laid on a schedule in order (the test's own reckoning of what the import presumes) */
function v40Lay(array $rows, string $pPaid, string $iPaid): array
{
    foreach ($rows as &$r) {
        $r['principal_paid'] = Dec::min($r['principal'], $pPaid);
        $pPaid = Dec::sub($pPaid, $r['principal_paid'], 2);
        $r['interest_paid'] = Dec::min($r['interest'], $iPaid);
        $iPaid = Dec::sub($iPaid, $r['interest_paid'], 2);
    }
    unset($r);
    return [$rows, $iPaid];
}

test('Import Loans: the terms as a former system writes them (months or days, the mode, the amortization type, a rate a year or a month)', function () {
    eq([LoanImport::term('12'), LoanImport::term('12 months'), LoanImport::term('180 days'), LoanImport::term('90D'), LoanImport::term('1 year'), LoanImport::term('soon')],
        [['12', 'months'], ['12', 'months'], ['180', 'days'], ['90', 'days'], ['12', 'months'], null], 'the terms');
    eq([LoanImport::mode('Monthly'), LoanImport::mode('semi monthly'), LoanImport::mode('Semi-annual'), LoanImport::mode('lump sum'), LoanImport::mode('Weekly-ish')],
        ['monthly', 'semi_monthly', 'semi_annual', 'lump_sum', null], 'the modes of payment');
    eq([LoanImport::amort('Diminishing, equal amortization'), LoanImport::amort('equal principal'), LoanImport::amort('Straight (add-on)'), LoanImport::amort('add-on'), LoanImport::amort('Diminishing'), LoanImport::amort('Balloon')],
        ['equal_amortization', 'equal_principal', 'straight', 'straight', 'equal_amortization', null], 'the amortization types');
    eq([LoanImport::rate('18'), LoanImport::rate('18%'), LoanImport::rate('18% a year'), LoanImport::rate('1.5% a month'), LoanImport::rate('1.25/mo'), LoanImport::rate('abc')],
        ['18.0000', '18.0000', '18.0000', '18.0000', '15.0000', null], 'the rates: a month times 12');
});

test('Import Loans: a diminishing loan rebuilt from its release (schedule, the totals paid on the due dates in order, the arrears), Active, one voucher against the balancing account', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    $mid = newMember('Loanimport', 'Ana');
    $no = (string)DB::value('SELECT member_no FROM members WHERE id = :id', ['id' => $mid]);
    $release = Coop::addMonths(today(), -7);
    $cut = today();
    $row = v40Loan(2, ['member_code' => $no, 'member_name' => 'Ana Loanimport', 'loan_name' => 'Regular Loan', 'principal' => '60,000.00', 'term' => '12 months', 'mode' => 'Monthly',
        'amort_type' => 'Diminishing, equal amortization', 'rate' => '18', 'release_date' => $release, 'principal_paid' => '30,000.00', 'interest_paid' => '5,000.00', 'penalties_paid' => '0', 'cut_off_date' => '']);
    $opt = ['cut_off_date' => $cut, 'balancing_account' => '3900'];
    [$c, $sum, $g] = LoanImport::check([$row], $opt);
    eq([$g, $c[0]['_errors'], $c[0]['loan_name'], $c[0]['mode'], $c[0]['amort_type'], $c[0]['cut_off_date'], $c[0]['balancing_account']], [[], [], '6', 'monthly', 'equal_amortization', $cut, '3900'], 'read as written, the page fills the cut-off date and the account');
    // the test's own reckoning
    $sched = Loans::schedule('60000', '18', '12', 'months', 'monthly', 'diminishing', 'equal_amortization', Coop::nthDue($release, 'monthly', 1));
    [$laid] = v40Lay($sched['rows'], '30000', '5000');
    $arrP = '0';
    $arrI = '0';
    foreach ($laid as $s) {
        if ($s['due_date'] <= $cut) {
            $arrP = Dec::add($arrP, Dec::sub($s['principal'], $s['principal_paid'], 2), 2);
            $arrI = Dec::add($arrI, Dec::sub($s['interest'], $s['interest_paid'], 2), 2);
        }
    }
    $res = $c[0]['_res'];
    eq([$res['principal_balance'], $res['interest_balance'], $res['arrears_principal'], $res['arrears_interest'], $res['maturity']],
        ['30000.00', Dec::fix(Dec::sub($sched['total_interest'], '5000', 2)), Dec::fix($arrP), Dec::fix($arrI), $sched['due_date']], 'the balances and the arrears at the cut-off');
    $txns = (int)DB::value('SELECT COUNT(*) FROM transactions');
    $r = LoanImport::post([$row], $opt, 'loans.csv', 'loan.accounts');
    eq([$r['count'], $r['total']], [1, '30000.00'], 'one loan, 30,000.00 of principal balance');
    eq((int)DB::value('SELECT COUNT(*) FROM transactions') - $txns, 1, 'one voucher');
    $loan = Loans::byNo($r['refs'][0]);
    eq([$loan['principal_balance'], $loan['interest_balance'], $loan['penalty_balance'], $loan['release_mode'], $loan['release_date'], $loan['due_date'], $loan['last_penalty_date']],
        ['30000.00', Dec::fix(Dec::sub($sched['total_interest'], '5000', 2)), '0.00', 'opening', $release, $sched['due_date'], $cut], 'the loan');
    eq($loan['status'], Dec::isPos(Dec::add($arrP, $arrI, 2)) && (bool)DB::value('SELECT COUNT(*) FROM loan_schedule WHERE loan_id = :l AND is_paid = 0 AND due_date < :d', ['l' => $loan['id'], 'd' => $cut]) ? 'past_due' : 'current', 'past due by its schedule, or current');
    $gl = DB::all('SELECT account_id, debit, credit FROM journal_lines WHERE txn_id = :t ORDER BY id', ['t' => $loan['release_txn_id']]);
    eq(array_map(fn($l) => [(int)$l['account_id'], Dec::fix($l['debit']), Dec::fix($l['credit'])], $gl), [[acct('1210'), '30000.00', '0.00'], [acct('3900'), '0.00', '30000.00']], 'Dr Loans Receivable, Cr the balancing account');
    // the schedule as paid, the ledger with the payments on the due dates
    $paidRows = count(array_filter($laid, fn($s) => Dec::cmp($s['principal_paid'], $s['principal']) >= 0 && Dec::cmp($s['interest_paid'], $s['interest']) >= 0));
    eq((int)DB::value('SELECT COUNT(*) FROM loan_schedule WHERE loan_id = :l AND is_paid = 1', ['l' => $loan['id']]), $paidRows, 'the amortizations fully covered are paid');
    $pays = DB::all("SELECT * FROM loan_txns WHERE loan_id = :l AND kind = 'payment' ORDER BY txn_date, id", ['l' => $loan['id']]);
    check($pays && !array_filter($pays, fn($p) => $p['txn_id'] !== null || (int)$p['pb_printed'] !== 1), 'the payments of the former system: no voucher, marked as printed in the passbook');
    check(in_array($laid[0]['due_date'], array_column($pays, 'txn_date'), true), 'a payment on the first due date');
    eqm(array_sum(array_map(fn($p) => (float)$p['principal_cr'], $pays)), '30000', 'the principal paid');
    eqm(array_sum(array_map(fn($p) => (float)$p['interest_cr'], $pays)), '5000', 'the interest paid');
    check(!array_filter(LoanTxn::collections($release, $cut), fn($x) => (int)$x['loan_id'] === (int)$loan['id']), 'not in the Loan Collection list (no money was collected here)');
    // a collection after the import: penalty, interest, principal as on any loan
    shift(today());
    $dues = Loans::dues($loan, today());
    [$pen, $int, $prn] = Loans::allocate($dues, '2000');
    receipt($mid, today(), [['kind' => 'loan', 'loan_id' => (int)$loan['id'], 'amount' => '2000']]);
    $after = Loans::loan((int)$loan['id']);
    eqm($after['principal_balance'], Dec::sub('30000', $prn, 2), 'collected afterwards: the principal');
    eqm($after['interest_balance'], Dec::sub($loan['interest_balance'], $int, 2), 'and the interest');
    svCloseShift();
});

test('Import Loans: the overdue penalties worked out by the product\'s terms on the past-due amortizations, less the penalties paid; or as given', function () {
    Auth::actAs($GLOBALS['adminId']);
    $mid = newMember('Loanimport', 'Ben');
    $no = (string)DB::value('SELECT member_no FROM members WHERE id = :id', ['id' => $mid]);
    $release = Coop::addMonths(today(), -5);
    $base = ['member_code' => $no, 'member_name' => 'Loanimport, Ben', 'loan_name' => '2', 'principal' => '12000', 'term' => '6 months', 'mode' => 'Semi-monthly', 'amort_type' => 'Straight (add-on)',
        'rate' => '1% a month', 'release_date' => $release, 'principal_paid' => '6000', 'interest_paid' => '360', 'penalties_paid' => '50', 'cut_off_date' => today()];
    [$c] = LoanImport::check([v40Loan(2, $base)], ['balancing_account' => '3900']);
    eq($c[0]['_errors'], [], 'no problem');
    // principal_arrears at 24 % a year, no grace: each unpaid principal from its due date
    $sched = Loans::schedule('12000', '12', '6', 'months', 'semi_monthly', 'straight', 'equal_amortization', Coop::nthDue($release, 'semi_monthly', 1));
    [$laid] = v40Lay($sched['rows'], '6000', '360');
    $pen = '0';
    foreach ($laid as $s) {
        $unpaid = Dec::sub($s['principal'], $s['principal_paid'], 2);
        if ($s['due_date'] < today() && Dec::isPos($unpaid)) {
            $pen = Dec::add($pen, Dec::round(Dec::div(Dec::mul(Dec::mul($unpaid, '24', 10), (string)Coop::daysBetween($s['due_date'], today()), 10), '36500', 10), 2), 2);
        }
    }
    eq([$c[0]['_res']['penalty_worked'], $c[0]['_res']['penalty']], [Dec::fix($pen), Dec::fix(Dec::max('0', Dec::sub($pen, '50', 2)))], 'worked out, less the 50.00 already paid');
    check(str_contains(implode(' ', $c[0]['_notes']), 'Overdue penalties worked out'), 'the review says how');
    [$c2] = LoanImport::check([v40Loan(2, ['overdue_penalties' => '420.00'] + $base)], ['balancing_account' => '3900']);
    eq([$c2[0]['_res']['penalty'], $c2[0]['_res']['penalty_from']], ['420.00', 'given'], 'as given in the file');
    $r = LoanImport::post([v40Loan(2, ['overdue_penalties' => '420.00'] + $base)], ['balancing_account' => '3900'], 'p.csv', 'loan.accounts');
    $loan = Loans::byNo($r['refs'][0]);
    eq([$loan['penalty_balance'], (string)DB::value("SELECT SUM(penalty_dr) FROM loan_txns WHERE loan_id = :l AND kind = 'penalty'", ['l' => $loan['id']])], ['420.00', '470.00'], 'the penalty balance; charged 470.00 of which 50.00 paid');
    eqm(Loans::penaltyThrough($loan, today())['new'], '0', 'no penalty counted twice: new penalties run from the day after the cut-off');
});

test('Import Loans: the past due interest of a matured loan, worked out on the principal unpaid from the maturity date at the loan\'s rate, less the interest paid beyond the schedule; or as given', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    $mid = newMember('Loanimport', 'Cara');
    $no = (string)DB::value('SELECT member_no FROM members WHERE id = :id', ['id' => $mid]);
    $release = Coop::addMonths(today(), -10);
    $base = ['member_code' => $no, 'member_name' => 'Cara Loanimport', 'loan_name' => '6', 'principal' => '10000', 'term' => '6', 'mode' => 'monthly', 'amort_type' => 'equal amortization',
        'rate' => '18', 'release_date' => $release, 'principal_paid' => '4000', 'interest_paid' => '0', 'penalties_paid' => '0', 'cut_off_date' => today(), 'overdue_penalties' => '0'];
    $sched = Loans::schedule('10000', '18', '6', 'months', 'monthly', 'diminishing', 'equal_amortization', Coop::nthDue($release, 'monthly', 1));
    $days = Coop::daysBetween($sched['due_date'], today());
    $pdi = Dec::round(Dec::div(Dec::mul(Dec::mul('6000', '18', 10), (string)$days, 10), '36500', 10), 2);
    // all the scheduled interest paid and 20.00 more
    $rows = [v40Loan(2, ['interest_paid' => Dec::fix(Dec::add($sched['total_interest'], '20', 2))] + $base)];
    [$c] = LoanImport::check($rows, ['balancing_account' => '3900']);
    eq($c[0]['_errors'], [], 'no problem');
    eq([$c[0]['_res']['pdi_worked'], $c[0]['_res']['excess_interest'], $c[0]['_res']['pdi'], $c[0]['_res']['interest_balance']], [Dec::fix($pdi), '20.00', Dec::fix(Dec::sub($pdi, '20', 2)), Dec::fix(Dec::sub($pdi, '20', 2))],
        '6,000.00 x 18 % x ' . $days . ' days / 365, less the 20.00 paid beyond the schedule');
    $r = LoanImport::post($rows, ['balancing_account' => '3900'], 'm.csv', 'loan.accounts');
    $loan = Loans::byNo($r['refs'][0]);
    $extra = DB::row('SELECT * FROM loan_schedule WHERE loan_id = :l ORDER BY inst_no DESC LIMIT 1', ['l' => $loan['id']]);
    eq([$extra['due_date'], Dec::fix($extra['interest']), Dec::fix($extra['interest_paid']), Dec::fix($extra['principal'])], [today(), Dec::fix($pdi), '20.00', '0.00'], 'the past due interest is an amortization due at the cut-off date');
    eq([$loan['interest_balance'], $loan['status']], [Dec::fix(Dec::sub($pdi, '20', 2)), 'past_due'], 'the interest balance; past due');
    $d = Loans::dues($loan, today());
    eqm($d['interest_due'], Dec::sub($pdi, '20', 2), 'collected as interest due');
    eq(Loans::ledgerKind(['kind' => 'pastdue']), 'Past due interest', 'its name in the ledger');
    // the Internal Ledger's additional interest: none to the cut-off date (it came with the import), then from it
    eq(Loans::importedThrough($loan), today(), 'brought over as of the cut-off date');
    eqm(Loans::additionalInterest($loan, today())['total'], '0', 'no additional interest again for the days before the cut-off');
    $later = Coop::addDays(today(), 10);
    $exp = '0';
    foreach (DB::all('SELECT principal - principal_paid AS u FROM loan_schedule WHERE loan_id = :l', ['l' => $loan['id']]) as $u) {
        if (Dec::isPos($u['u'])) {
            $exp = Dec::add($exp, Dec::round(Dec::div(Dec::mul(Dec::mul($u['u'], '18', 10), '10', 10), '36500', 10), 2), 2);
        }
    }
    $ai = Loans::additionalInterest($loan, $later);
    eq([Dec::fix($ai['total']), array_unique(array_column($ai['detail'], 'from')), array_unique(array_column($ai['detail'], 'days'))], [Dec::fix($exp), [today()], [10]], '10 days later: 10 days of it, from the cut-off date');
    check(Loans::importedThrough(Loans::loan((int)DB::value("SELECT id FROM loan_accounts WHERE release_mode <> 'opening' ORDER BY id LIMIT 1"))) === null, 'none for a loan released here');
    // as given; the product charging no additional interest
    [$c] = LoanImport::check([v40Loan(2, ['past_due_interest' => '150.00', 'principal' => '10001'] + $base)], ['balancing_account' => '3900']);
    eq([$c[0]['_res']['pdi'], $c[0]['_res']['pdi_from']], ['150.00', 'given'], 'the past due interest as given');
    DB::update('loan_products', ['addl_interest' => 'none'], 'id = :id', ['id' => $ids['loan.REG']]);
    [$c] = LoanImport::check([v40Loan(2, ['principal' => '10002'] + $base)], ['balancing_account' => '3900']);
    eq($c[0]['_res']['pdi'], '0.00', 'none when the product charges no additional interest');
    DB::update('loan_products', ['addl_interest' => 'auto'], 'id = :id', ['id' => $ids['loan.REG']]);
});

test('Import Loans: what is refused (product, mode, type, rate, dates, amounts, the same loan twice, a fully paid loan, too much paid, the member, the closing date)', function () {
    Auth::actAs($GLOBALS['adminId']);
    $mid = newMember('Loanimport', 'Dan');
    $no = (string)DB::value('SELECT member_no FROM members WHERE id = :id', ['id' => $mid]);
    $base = ['member_code' => $no, 'member_name' => 'Dan Loanimport', 'loan_name' => '6', 'principal' => '5000', 'term' => '6 months', 'mode' => 'Monthly', 'amort_type' => 'Straight',
        'rate' => '12', 'release_date' => Coop::addMonths(today(), -2), 'principal_paid' => '0', 'interest_paid' => '0', 'penalties_paid' => '0', 'cut_off_date' => today()];
    $rows = [
        v40Loan(2, ['loan_name' => 'Nope', 'mode' => 'Weekly-ish', 'amort_type' => 'Balloon', 'rate' => 'abc', 'release_date' => '2026-13-01'] + $base),
        v40Loan(3, ['principal_paid' => '6000'] + $base),
        v40Loan(4, ['principal' => '5001', 'principal_paid' => '5001', 'interest_paid' => '300.06'] + $base),
        v40Loan(5, ['principal' => '5002', 'interest_paid' => '999'] + $base),
        v40Loan(6, ['member_name' => 'Somebody Else'] + $base),
        v40Loan(7, ['principal' => '5003'] + $base),
        v40Loan(8, ['principal' => '5003'] + $base),
        v40Loan(9, ['release_date' => Coop::addDays(today(), 1)] + $base),
        v40Loan(10, ['principal_paid' => '', 'principal' => '5004'] + $base),
    ];
    [$c] = LoanImport::check($rows, ['balancing_account' => '3900']);
    $e = fn(int $i) => implode(' ', $c[$i]['_errors']);
    check(count($c[0]['_errors']) === 5, 'the product, the mode, the type, the rate, the date: ' . $e(0));
    check(str_contains($e(1), 'more than the principal loan'), 'more principal paid than lent');
    check(str_contains($e(2), 'cover the whole loan'), 'a fully paid loan is not imported');
    check(str_contains($e(3), 'more than the scheduled interest'), 'more interest paid than the schedule and no past due interest');
    check(str_contains($e(4), 'is not the name of member'), 'the name is checked');
    check($c[5]['_errors'] === [] && str_contains($e(6), 'also on line 7'), 'the same loan twice in the file');
    check(str_contains($e(7), 'after the cut-off date'), 'released after the cut-off date');
    check(str_contains($e(8), 'Enter the principal paid'), 'the totals paid are required (0 when none)');
    LoanImport::post([$rows[5]], ['balancing_account' => '3900'], 'd.csv', 'loan.accounts');
    [$c] = LoanImport::check([$rows[5]], ['balancing_account' => '3900']);
    check(str_contains(implode(' ', $c[0]['_errors']), 'already on file as'), 'imported again: refused');
    [, , $g] = LoanImport::check([$rows[6]], ['balancing_account' => '']);
    check((bool)$g, 'the balancing account is required');
    Settings::set('closing_date', Coop::addDays(today(), -1));
    $closed = [v40Loan(2, ['principal' => '5008', 'cut_off_date' => Coop::addDays(today(), -1)] + $base)];
    asUser('book');
    [$c] = LoanImport::check($closed, ['balancing_account' => '3900']);
    Auth::actAs($GLOBALS['adminId']);
    check(str_contains(implode(' ', $c[0]['_errors']), 'the cut-off date must be after it'), 'a cut-off date in closed books, for the bookkeeper');
    [$c] = LoanImport::check($closed, ['balancing_account' => '3900']);
    check(!str_contains(implode(' ', $c[0]['_errors']), 'the cut-off date must be after it'), 'allowed with the permission to change closed periods (as every posting)');
    Settings::set('closing_date', '');
    [$c] = LoanImport::check([v40Loan(2, ['principal' => '5010', 'cut_off_date' => Coop::addDays(today(), 1)] + $base)], ['balancing_account' => '3900']);
    check(str_contains(implode(' ', $c[0]['_errors']), 'cannot be after today'), 'a cut-off date after today');
    DB::update('members', ['status' => 'resigned'], 'id = :id', ['id' => $mid]);
    [$c] = LoanImport::check([v40Loan(2, ['principal' => '5009'] + $base)], ['balancing_account' => '3900']);
    check(str_contains(implode(' ', $c[0]['_errors']), 'a loan is imported for an active member'), 'a member who has left');
    DB::update('members', ['status' => 'active'], 'id = :id', ['id' => $mid]);
});

test('Import Member, the full template: every other detail of the member as a column the file may have; saved on the member and its lists', function () {
    Auth::actAs($GLOBALS['adminId']);
    $cols = CsvImports::cols('members');
    $extras = CsvReview::extras($cols);
    check(count($extras) >= 50 && isset($extras['prov_city'], $extras['spouse_first_name'], $extras['beneficiary2_share'], $extras['account_officer'], $extras['education']), 'the full template\'s columns (' . count($extras) . ')');
    check(!array_filter(array_keys($extras), fn($k) => !empty($cols[$k]['req'])), 'none of them required');
    $cluster = (int)DB::value('SELECT id FROM member_clusters WHERE is_active = 1 ORDER BY id LIMIT 1') ?: DB::insert('member_clusters', ['name' => 'Cluster A', 'is_active' => 1]);
    $clusterName = (string)DB::value('SELECT name FROM member_clusters WHERE id = :id', ['id' => $cluster]);
    $csv = "Last Name,First Name,Middle Name,Date of Birth,Member Type,Membership Date,Barangay,Municipality/City,Province,Salutation,Prov City,Education,Employment Type,Employer Name,"
        . "PMES Date,Subscribed Shares,Cluster,Account Officer,Landline,Mobile,Spouse Last Name,Spouse First Name,Spouse Birth Date,Beneficiary1 Name,Beneficiary1 Relationship,Beneficiary1 Share,Beneficiary2 Name,Beneficiary2 Share\n"
        . "Fullv40,Ana,Cruz,1990-01-01,REG,2020-01-01,Poblacion,San Isidro,Nueva Ecija,mrs,Cabanatuan City,college,Self-employed,Ana's Store,"
        . "2019-12-01,25,$clusterName,admin,044-940-1234,09171112222,Fullv40,Ben,1988-05-05,Ben Fullv40,Spouse,60,Carl Fullv40,40\n";
    $rows = CsvReview::readText($csv, $cols, CsvImports::aliases('members'));
    eq(array_keys(array_diff_key(CsvReview::shown($cols, $rows), array_filter($cols, fn($c) => empty($c['extra'])))),
        ['salutation', 'prov_city', 'education', 'employment_type', 'employer_name', 'pmes_date', 'subscribed_shares', 'cluster', 'account_officer', 'landline', 'spouse_last_name', 'spouse_first_name', 'spouse_birth_date',
            'beneficiary1_name', 'beneficiary1_relationship', 'beneficiary1_share', 'beneficiary2_name', 'beneficiary2_share'], 'the review shows the details the file has, and only those');
    [$c] = CsvImports::check('members', $rows, []);
    eq([$c[0]['_errors'], $c[0]['salutation'], $c[0]['education'], $c[0]['employment_type'], $c[0]['subscribed_shares']], [[], 'Mrs.', 'College', 'self_employed', '25'], 'read as written');
    $r = CsvImports::post('members', $rows, [], 'full.csv', 'coop.members');
    $m = DB::row('SELECT * FROM members WHERE member_no = :n', ['n' => $r['refs'][0]]);
    eq([$m['salutation'], $m['prov_city'], $m['education'], $m['employment_type'], $m['employer_name'], $m['pmes_date'], (int)$m['subscribed_shares'], (int)$m['cluster_id'], (int)$m['account_officer_id'], $m['nationality'], $m['status']],
        ['Mrs.', 'Cabanatuan City', 'College', 'self_employed', 'Ana\'s Store', '2019-12-01', 25, $cluster, $GLOBALS['adminId'], 'Filipino', 'active'], 'on the member');
    eq(DB::all('SELECT kind, last_name, first_name, birth_date FROM member_dependents WHERE member_id = :m', ['m' => $m['id']]), [['kind' => 'spouse', 'last_name' => 'Fullv40', 'first_name' => 'Ben', 'birth_date' => '1988-05-05']], 'the spouse');
    eq(array_map(fn($b) => [$b['name'], Dec::fix($b['share_pct'])], DB::all('SELECT name, share_pct FROM member_beneficiaries WHERE member_id = :m ORDER BY id', ['m' => $m['id']])), [['Ben Fullv40', '60.00'], ['Carl Fullv40', '40.00']], 'the beneficiaries');
    eq(DB::column("SELECT number FROM member_contacts WHERE member_id = :m ORDER BY is_primary DESC, id", ['m' => $m['id']]), ['09171112222', '044-940-1234'], 'the mobile and the landline');
    // what the details must be
    $bad = "Last Name,First Name,Middle Name,Date of Birth,Member Type,Membership Date,Barangay,Municipality/City,Province,Subscribed Shares,Cluster,Account Class,Beneficiary1 Name,Beneficiary1 Share,Beneficiary2 Name,Beneficiary2 Share,Beneficiary2 Relationship,Spouse First Name\n"
        . "Badv40,Ana,Cruz,1991-01-01,REG,2020-01-01,Poblacion,San Isidro,Nueva Ecija,12.5,Nowhere,Gold,A,70,B,40,,Ben\n"
        . "Badv40,Bea,Cruz,1992-01-01,REG,2020-01-01,Poblacion,San Isidro,Nueva Ecija,,,,,,,,Son,\n";
    [$c] = CsvImports::check('members', CsvReview::readText($bad, $cols, CsvImports::aliases('members')), []);
    $e = implode(' | ', $c[0]['_errors']);
    foreach (['whole number', 'Cluster: "Nowhere"', 'Account class: "Gold" is not on the list', 'add up to 110 %', 'Spouse: enter the last name'] as $w) {
        check(str_contains($e, $w), 'refused: ' . $w);
    }
    check(str_contains(implode(' ', $c[1]['_errors']), 'Beneficiary 2: enter the name'), 'a beneficiary\'s details without the name');
});

test('The checker of the reviews: a row\'s problems name its cells (by the column\'s name in the message, or as the check says), the answer to the page by line', function () {
    Auth::actAs($GLOBALS['adminId']);
    $named = new ReflectionMethod('CsvReview', 'named');
    $cols = CsvImports::cols('members');
    $csv = "Last Name,First Name,Middle Name,Date of Birth,Member Type,Membership Date,Barangay,Municipality/City,Province,Spouse First Name,Beneficiary1 Name,Beneficiary1 Share,Beneficiary2 Name,Beneficiary2 Share\n"
        . "Cellv40,Ana,Cruz,1991-01-01,NOPE,2020-01-01,Poblacion,San Isidro,Nueva Ecija,Ben,A,70,B,40\n";
    [$c] = CsvImports::check('members', CsvReview::readText($csv, $cols, CsvImports::aliases('members')), []);
    $k = array_keys($named->invoke(null, $c[0]['_errors'], $cols, $c[0]['_cells']));
    sort($k);
    eq($k, ['beneficiary1_share', 'beneficiary2_share', 'member_type', 'spouse_first_name', 'spouse_last_name'],
        'the member type by its name; the spouse\'s names (not the member\'s) and the shares as the check says');
    $lc = LoanImport::cols();
    $mid = newMember('Cellv40', 'Ben');
    $no = (string)DB::value('SELECT member_no FROM members WHERE id = :id', ['id' => $mid]);
    [$c] = LoanImport::check([v40Loan(2, ['member_code' => $no, 'member_name' => 'Someone Else', 'loan_name' => 'Nope', 'principal' => '5000', 'term' => '6', 'mode' => 'Monthly', 'amort_type' => 'Straight', 'rate' => 'abc',
        'release_date' => Coop::addMonths(today(), -2), 'principal_paid' => '0', 'interest_paid' => '0', 'penalties_paid' => '0', 'cut_off_date' => today()]),
        v40Loan(3, ['member_code' => $no, 'member_name' => 'Ben Cellv40', 'loan_name' => '6', 'principal' => '5000', 'term' => '6', 'mode' => 'Monthly', 'amort_type' => 'Straight', 'rate' => '12',
        'release_date' => Coop::addMonths(today(), -2), 'principal_paid' => '5000', 'interest_paid' => '300', 'penalties_paid' => '0', 'cut_off_date' => today()])], ['balancing_account' => '3900']);
    eq(array_keys($named->invoke(null, $c[0]['_errors'], $lc, $c[0]['_cells'])), ['member_name', 'loan_name', 'rate'], 'the loan: the name, the loan, the rate');
    eq(array_keys($named->invoke(null, $c[1]['_errors'], $lc, $c[1]['_cells'])), ['principal_paid', 'interest_paid', 'penalties_paid'], 'a fully paid loan: the amounts paid (not the cut-off date)');
});

test('Import Loans, after the review: the past due interest of a loan not yet matured in date order and collected first, the loan no. of the former system never drawn again, the accrued interest, a lump sum, the voucher by class and by cut-off date', function () {
    Auth::actAs($GLOBALS['adminId']);
    $mid = newMember('Loanorder', 'Eva');
    $m = DB::row('SELECT * FROM members WHERE id = :id', ['id' => $mid]);
    $no = (string)$m['member_no'];
    $cut = Coop::addDays(today(), -15);
    $base = ['member_code' => $no, 'member_name' => 'Eva Loanorder', 'loan_name' => '6', 'principal' => '60000', 'term' => '12 months', 'mode' => 'Monthly', 'amort_type' => 'Diminishing, equal amortization',
        'rate' => '18', 'release_date' => Coop::addMonths(today(), -7), 'principal_paid' => '20000', 'interest_paid' => '3000', 'penalties_paid' => '0', 'cut_off_date' => $cut, 'past_due_interest' => '300', 'overdue_penalties' => '0'];
    $r = LoanImport::post([v40Loan(2, $base)], ['balancing_account' => '3900'], 'o.csv', 'loan.accounts');
    $loan = Loans::byNo($r['refs'][0]);
    $sched = DB::all('SELECT inst_no, due_date, principal, interest FROM loan_schedule WHERE loan_id = :l ORDER BY inst_no', ['l' => $loan['id']]);
    $dates = array_column($sched, 'due_date');
    $sorted = $dates;
    sort($sorted);
    eq($dates, $sorted, 'the schedule in date order by its numbers');
    $pdiRow = array_values(array_filter($sched, fn($s) => !Dec::isPos($s['principal'])))[0];
    eq([$pdiRow['due_date'], Dec::fix($pdiRow['interest'])], [$cut, '300.00'], 'the past due interest due at the cut-off date');
    eq(count(array_filter($sched, fn($s) => $s['due_date'] <= $cut)), (int)$pdiRow['inst_no'], 'after the amortizations due by then, before those due after');
    $d = Loans::dues($loan, today());
    eq($d['last_due'], max(array_filter($dates, fn($x) => $x <= today())), 'the last due date is the latest due date passed');
    // the whole amount due collected: the past due interest paid with it, nothing of an amortization not yet due
    shift(today());
    receipt($mid, today(), [['kind' => 'loan', 'loan_id' => (int)$loan['id'], 'amount' => $d['total_due']]]);
    $after = Loans::loan((int)$loan['id']);
    eq([(int)DB::value('SELECT is_paid FROM loan_schedule WHERE loan_id = :l AND inst_no = :n', ['l' => $loan['id'], 'n' => $pdiRow['inst_no']]),
        (int)DB::value('SELECT COUNT(*) FROM loan_schedule WHERE loan_id = :l AND due_date > :d AND (principal_paid > 0 OR interest_paid > 0)', ['l' => $loan['id'], 'd' => today()]), $after['status']],
        [1, 0, 'current'], 'the past due interest paid, no amortization ahead paid, the loan current');
    svCloseShift();

    // a loan no. of the former system equal to the next number of this system: kept, and never drawn for another loan
    $branch = Coop::branch($m['branch_id'] ? (int)$m['branch_id'] : null);
    $next = strtr(Coop::setting('loan_account_format', '{BRANCH}-{PRODUCT}-{SEQ}'), ['{BRANCH}' => $branch ? str_pad(preg_replace('/\D/', '', (string)$branch['code']) ?: '0', 2, '0', STR_PAD_LEFT) : '00',
        '{BRANCHC}' => $branch ? (string)$branch['code'] : '', '{PRODUCT}' => '6', '{SEQ}' => Seq::peek('loan'), '{YEAR}' => date('Y')]);
    $two = [v40Loan(2, ['principal' => '5000', 'principal_paid' => '0', 'interest_paid' => '0', 'past_due_interest' => '', 'cut_off_date' => today()] + $base),
        v40Loan(3, ['principal' => '5001', 'principal_paid' => '0', 'interest_paid' => '0', 'past_due_interest' => '', 'cut_off_date' => today(), 'loan_no' => $next] + $base)];
    $r = LoanImport::post($two, ['balancing_account' => '3900'], 'n.csv', 'loan.accounts');
    check(in_array($next, $r['refs'], true) && count(array_unique($r['refs'])) === 2, 'the number of the file kept (' . $next . '), another drawn for the other loan');
    check(Coop::loanNo(['code' => '6'], $m['branch_id'] ? (int)$m['branch_id'] : null) !== $next, 'a number on file is passed over by the next release');

    // what the review now refuses
    [$c] = LoanImport::check([v40Loan(2, ['principal' => '7001', 'pn_no' => str_repeat('P', 31), 'balancing_account' => '1210', 'cut_off_date' => today(), 'past_due_interest' => ''] + $base)], ['balancing_account' => '3900']);
    $e = implode(' ', $c[0]['_errors']);
    check(str_contains($e, 'PN no. is longer than 30') && str_contains($e, 'is a loans receivable account'), 'a PN no. over 30 characters; a loans receivable as the balancing account');

    // the accrued interest: in the interest receivable, and the interest accrual does not book it again
    $mid2 = newMember('Loanorder', 'Fe');
    $no2 = (string)DB::value('SELECT member_no FROM members WHERE id = :id', ['id' => $mid2]);
    $acc = ['principal' => '30000', 'past_due_interest' => '', 'interest_paid' => '1000', 'principal_paid' => '10000', 'cut_off_date' => today()];
    [$c] = LoanImport::check([v40Loan(2, $acc + ['accrued_interest' => '999999'] + $base)], ['balancing_account' => '3900']);
    check(str_contains(implode(' ', $c[0]['_errors']), 'more than the interest unpaid'), 'no more accrued interest than is unpaid');
    $r = LoanImport::post([v40Loan(2, $acc + $base), v40Loan(3, $acc + ['member_code' => $no2, 'member_name' => 'Fe Loanorder', 'accrued_interest' => '250.00'] + $base)], ['balancing_account' => '3900'], 'a.csv', 'loan.accounts');
    [$la, $lb] = [Loans::byNo($r['refs'][0]), Loans::byNo($r['refs'][1])];
    eq([$la['interest_accrued'], $lb['interest_accrued']], ['0.00', '250.00'], 'the accrued interest on the loan');
    $gl = DB::all('SELECT account_id, debit, credit FROM journal_lines WHERE txn_id = :t', ['t' => $lb['release_txn_id']]);
    $sum = fn(int $a, string $col) => array_sum(array_map(fn($l) => (float)$l[$col], array_filter($gl, fn($l) => (int)$l['account_id'] === $a)));
    eqm($sum(Accts::get('loan_interest_receivable'), 'debit'), '250', 'Dr Loan Interest Receivable');
    eqm($sum(acct('3900'), 'credit'), Dec::add(Dec::add($la['principal_balance'], $lb['principal_balance'], 2), '250', 2), 'Cr the balancing account with it');
    $prev = Loans::accrueInterest(today(), today(), 'loan.penalties', false);
    $line = fn(string $n) => Dec::of(array_values(array_filter($prev['lines'], fn($x) => $x['loan_no'] === $n))[0]['amount'] ?? '0');
    eqm(Dec::sub($line($la['loan_no']), $line($lb['loan_no']), 2), '250', 'the interest accrual leaves out what was accrued before the cut-off');

    // a lump sum: the first due date given is its maturity
    [$c] = LoanImport::check([v40Loan(2, ['principal' => '8000', 'mode' => 'Lump sum', 'amort_type' => 'Straight', 'term' => '12 months', 'release_date' => Coop::addMonths(today(), -2), 'first_due' => Coop::addMonths(today(), 4),
        'principal_paid' => '0', 'interest_paid' => '0', 'past_due_interest' => '', 'cut_off_date' => today()] + $base)], ['balancing_account' => '3900']);
    eq([$c[0]['_errors'], $c[0]['_res']['maturity'] ?? null], [[], Coop::addMonths(today(), 4)], 'a lump sum due on the first due date given');

    // two cut-off dates: a voucher and an import record each, with their own loans; each line in the member's class
    $mid3 = newMember('Loanorder', 'Gil');
    $no3 = (string)DB::value('SELECT member_no FROM members WHERE id = :id', ['id' => $mid3]);
    $g = ['member_code' => $no3, 'member_name' => 'Gil Loanorder', 'principal_paid' => '0', 'interest_paid' => '0', 'past_due_interest' => ''] + $base;
    $r = LoanImport::post([v40Loan(2, ['principal' => '4000', 'cut_off_date' => today()] + $g), v40Loan(3, ['principal' => '4001', 'cut_off_date' => Coop::addDays(today(), -1)] + $g)], ['balancing_account' => '3900'], 'g.csv', 'loan.accounts');
    $imps = DB::all("SELECT summary_json, txn_id FROM coop_imports WHERE kind = 'loans' ORDER BY id DESC LIMIT 2");
    eq(array_map(fn($i) => count(json_decode($i['summary_json'], true)['loans']), $imps), [1, 1], 'each import record lists its own loans');
    check($imps[0]['txn_id'] !== $imps[1]['txn_id'], 'a voucher per cut-off date');
    $cls = Coop::branchClassId(DB::value('SELECT branch_id FROM members WHERE id = :id', ['id' => $mid3]) ? (int)DB::value('SELECT branch_id FROM members WHERE id = :id', ['id' => $mid3]) : Coop::defaultBranchId());
    eq(array_unique(array_map(fn($l) => $l['class_id'] === null ? null : (int)$l['class_id'], DB::all('SELECT class_id FROM journal_lines WHERE txn_id = :t', ['t' => $imps[0]['txn_id']]))), [$cls], 'every line in the class of the member\'s branch');
    // the payments of the former system are not collections (dashboard, Loan Collections report)
    $today = Dec::of(DB::value("SELECT COALESCE(SUM(principal_cr + interest_cr + penalty_cr),0) FROM loan_txns WHERE kind = 'payment' AND is_voided = 0 AND txn_id IS NOT NULL AND txn_date = :d", ['d' => today()]));
    eqm(CoopAlerts::kpis()['collections_today'], $today, 'collections today: the receipts only');
});

test('Import Member, after the review: a column a problem names is shown so it can be filled in; the employer contact up to 40 characters', function () {
    Auth::actAs($GLOBALS['adminId']);
    $cols = CsvImports::cols('members');
    $csv = "Last Name,First Name,Middle Name,Date of Birth,Member Type,Membership Date,Barangay,Municipality/City,Province,Spouse First Name,Employer Contact\n"
        . "Showv40,Ana,Cruz,1991-01-01,REG,2020-01-01,Poblacion,San Isidro,Nueva Ecija,Ben," . str_repeat('9', 41) . "\n";
    [$c] = CsvImports::check('members', CsvReview::readText($csv, $cols, CsvImports::aliases('members')), []);
    check(isset(CsvReview::shown($cols, $c)['spouse_last_name']), 'Spouse last name shown (named by the problem, not in the file)');
    check(str_contains(implode(' ', $c[0]['_errors']), 'Employer contact is longer than 40'), 'the employer contact as long as its column');
});
