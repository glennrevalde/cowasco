<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v23.xlsx (version 58), on the Consolidated Import file of
 * version 56 (its sheet Import, rows under the member):
 *  - AE11: "a cut-off date for the importation of transactions so as put date of the transactions especially on Loan
 *    Payments and Savings Balances" (the column Cut-Off Date the user added, AD1): the cut-off date of a row dates what
 *    the row brings; on the member's row it is the member's, for every line of the member without a date of its own;
 *  - Q14, Q15: "where can put the Time Deposit of the Members for Upload, its Principal, Accrued Interest, Date
 *    Deposit, Term, Interest Rate and Maturity Date": time deposits in the same file, a line of the review each,
 *    checked as Import Time Deposit checks them, saved Active with their principal and accrued interest entered.
 * The page is checked over HTTP (tests/water/e2e_http.php) and in the browser (docs/TEST_EVIDENCE.md).
 */

/** The dates of the version 58 tests (relative to today, so the tests never date a balance after today) */
function v58Dates(): array
{
    $d = fn(int $days) => date('Y-m-d', strtotime(today() . ' ' . $days . ' days'));
    return ['page' => $d(-5), 'a' => $d(-20), 'b' => $d(-10), 'c' => $d(-8), 'own' => $d(-3), 'deposited' => $d(-70), 'release' => Coop::addMonths(today(), -6), 'tomorrow' => $d(1)];
}

/** A member's row of the file, in the words of the workbook v23 (its sheet Import) */
function v58Member(string $no, string $last, string $first, array $more = []): array
{
    return ['member_no' => $no, 'last_name' => $last, 'first_name' => $first, 'middle_name' => 'Santos', 'birth_date' => '1985-04-12', 'gender' => 'M', 'civil_status' => 'married',
        'member_type' => 'REG', 'membership_date' => '2015-06-01', 'barangay' => 'Poblacion', 'city' => 'San Isidro', 'province' => 'Nueva Ecija'] + $more;
}

/** The rows of the cut-off date file: the column Cut-Off Date last, as the user added it */
function v58CutRows(array $d): array
{
    $loan = ['loan_name' => 'Regular Loan', 'loan_principal' => '30000.00', 'loan_term' => '12 months', 'loan_mode' => 'Monthly', 'loan_amort_type' => 'Diminishing, equal amortization',
        'loan_rate' => '18', 'loan_release_date' => $d['release'], 'loan_principal_paid' => '10000.00', 'loan_interest_paid' => '1500.00', 'loan_penalties_paid' => '0'];
    $td = ['td_principal' => '20000.00', 'td_accrued_interest' => '100.00', 'td_date_deposited' => $d['deposited'], 'td_term' => '180 days', 'td_rate' => '4.5'];
    return [
        // 1. the member's row with its cut-off date: every line of the member without a date of its own
        v58Member('V58-001', 'Ocampo', 'Rodel') + ['savings_code' => '01', 'savings_balance' => '1000.00', 'Cut-Off Date' => $d['a']],
        ['savings_code' => '03', 'savings_balance' => '500.00'],
        // a row under the member with a cut-off date of its own: the loan's totals paid as of that date
        $loan + ['Cut-Off Date' => $d['b']],
        // a time deposit under the member: the member's cut-off date
        $td,
        // 2. a member with no cut-off date: the page's; its time deposit with a date of its own column
        v58Member('V58-002', 'Reyes', 'Maria Luisa', ['gender' => 'F', 'civil_status' => 'single']) + ['savings_code' => '01', 'savings_balance' => '700.00'],
        ['td_principal' => '15000.00', 'td_date_deposited' => $d['deposited'], 'td_maturity_date' => date('Y-m-d', strtotime($d['deposited'] . ' +1 year')), 'td_cut_off_date' => $d['own'], 'Cut-Off Date' => $d['c']],
        // 3. a cut-off date that is not a date, 4. one after today
        v58Member('V58-003', 'Lim', 'Carlos') + ['savings_code' => '01', 'savings_balance' => '100.00', 'Cut-Off Date' => '13/45/2026'],
        v58Member('V58-004', 'Tan', 'Rosa') + ['savings_code' => '01', 'savings_balance' => '100.00', 'Cut-Off Date' => $d['tomorrow']],
    ];
}

test('Version 58: the Cut-Off Date column of the Consolidated Import file is recognised (as the user wrote it, and in other words); a line\'s own date column comes first', function () {
    $g = fn(string $h) => ConsolidatedImport::guess($h);
    eq([$g('Cut-Off Date'), $g('Cutoff Date'), $g('cut_off_date'), $g('As of Date'), $g('Balance Date'), $g('Transaction Date')],
        array_fill(0, 6, ['cut_off_date', 1]), 'the cut-off date of the row (the column the user added to the workbook)');
    eq([$g('Savings Cut-off Date'), $g('Loan Cut-off Date'), $g('TD Cut-off Date'), $g('savings2_cut_off_date')],
        [['savings_cut_off_date', 1], ['loan_cut_off_date', 1], ['td_cut_off_date', 1], ['savings_cut_off_date', 2]], 'the date column of a savings account, a loan or a time deposit');
    $f = ConsolidatedImport::fields();
    eq([$f['cut_off_date']['section'], $f['cut_off_date']['type'], $f['cut_off_date']['rep'], $f['cut_off_date']['more']], ['member', 'date', false, false], 'a column of the member, shown in the grid of the members');
    eq(ConsolidatedImport::LINE_DATE, ['savings' => 'savings_cut_off_date', 'td' => 'td_cut_off_date', 'loan' => 'loan_cut_off_date', 'bill' => 'bill_date'], 'the date column of each kind of line');
});

test('Version 58: the cut-off date of the member\'s row dates every line of the member without a date of its own; a row under the member dates its own lines; the page\'s date last; a wrong date in red on the member', function () {
    v56Fixture();
    $d = v58Dates();
    $b = v56Read(v56Csv(v58CutRows($d)), 'v58_cutoff.csv');
    $id = (int)$b['id'];
    $cols = array_column($b['mapping'], 'field');
    check(in_array('cut_off_date', $cols, true) && in_array('td_principal', $cols, true), 'the columns Cut-Off Date and td_principal recognised');
    ConsolidatedImport::setOptions($b, ['cut_off_date' => $d['page'], 'savings_txn_code' => 'INI', 'balancing_account' => '3900']);
    $sum = v56Check($id);
    eq([$sum['kinds']['member']['lines'], $sum['kinds']['savings']['lines'], $sum['kinds']['td']['lines'], $sum['kinds']['loan']['lines']], [4, 5, 2, 1], '4 members, 5 savings accounts, 2 time deposits, 1 loan');
    $m = v56Lines($id, 'member');
    $sv = v56Lines($id, 'savings');
    $td = v56Lines($id, 'td');
    $ln = v56Lines($id, 'loan');
    $byNo = [];
    foreach ($m as $x) {
        $byNo[$x['values']['member_no']] = $x;
    }
    eq([$byNo['V58-001']['values']['cut_off_date'] ?? null, $byNo['V58-002']['values']['cut_off_date'] ?? null], [$d['a'], null], 'the cut-off date of the member\'s row kept on the member (none for member 2)');
    $lineOf = fn(array $lines, int $memberLine) => array_values(array_filter($lines, fn($x) => $x['member_line'] === $memberLine));
    $sav1 = $lineOf($sv, $byNo['V58-001']['id']);
    eq(array_map(fn($x) => $x['values']['savings_cut_off_date'] ?? '', $sav1), ['', ''], 'member 1: its savings lines have no date of their own (the member\'s)');
    check(str_ends_with(strip_tags($sav1[0]['info']), 'as of ' . fdate($d['a'])) && str_ends_with(strip_tags($sav1[1]['info']), 'as of ' . fdate($d['a'])), '...each says the date of its balance: ' . strip_tags($sav1[1]['info']));
    check(str_contains(strip_tags($byNo['V58-001']['info']), 'cut-off date ' . fdate($d['a'])) && str_contains(html_entity_decode(strip_tags($byNo['V58-002']['info']), ENT_QUOTES), 'cut-off date ' . fdate($d['page']) . " (the page's)"),
        'the member line says its cut-off date (the page\'s for member 2)');
    eq(array_values(array_map(fn($x) => $x['values']['loan_cut_off_date'] ?? '', $ln)), [$d['b']], 'the loan\'s row has its own cut-off date: on the loan line');
    check(str_ends_with(strip_tags(array_values($ln)[0]['info']), 'as of ' . fdate($d['b'])), '...the loan as of it');
    $td1 = $lineOf($td, $byNo['V58-001']['id'])[0];
    $td2 = $lineOf($td, $byNo['V58-002']['id'])[0];
    eq([$td1['values']['td_cut_off_date'] ?? '', $td2['values']['td_cut_off_date'] ?? ''], ['', $d['own']], 'the time deposit\'s own cut-off date column comes before the row\'s');
    // the wrong dates: on the member's line, its cell
    eq([$byNo['V58-003']['errors'], $byNo['V58-003']['cells']], [['The cut-off date "13/45/2026" is not a date: write it as YYYY-MM-DD or MM/DD/YYYY.'], ['cut_off_date']], 'a cut-off date that is not a date: in red, its cell');
    eq([$byNo['V58-004']['errors'], $byNo['V58-004']['cells']], [['The cut-off date ' . fdate($d['tomorrow']) . ' is after today.'], ['cut_off_date']], 'a cut-off date after today');
    // the member's date corrected in the review: checked again at once
    $r = ConsolidatedImport::edit(ConsolidatedImport::batch($id), $byNo['V58-003']['id'], 'cut_off_date', $d['a']);
    $after = array_values(array_filter($r['lines'], fn($x) => $x['kind'] === 'member'))[0];
    eq([$after['errors'], $after['values']['cut_off_date']], [[], $d['a']], 'the date corrected: good');
    $sav3 = $lineOf(v56Lines($id, 'savings'), $byNo['V58-003']['id'])[0];
    check(str_contains($sav3['info'], 'Regular Savings'), 'its savings line checked again: ' . $sav3['info']);
    ConsolidatedImport::remove(ConsolidatedImport::batch($id), $byNo['V58-004']['id']);
    $sum = v56Check($id);
    $bad = [];
    foreach (ConsolidatedImport::KINDS as $k => $_) {
        foreach (v56Lines($id, $k) as $x) {
            if ($x['errors']) {
                $bad[] = $k . ' ' . $x['line'] . ': ' . implode(' ', $x['errors']);
            }
        }
    }
    eq($bad, [], 'every line good');
    eq([$sum['ready'], $sum['general']], [true, []], 'ready to save');
    $voucher = (int)DB::value('SELECT COALESCE(MAX(id), 0) FROM transactions');
    $imports = (int)DB::value('SELECT COALESCE(MAX(id), 0) FROM coop_imports');
    $last = v56Save($id);
    eq(end($last)['done'], true, 'saved');
    $txnDates = fn(string $no) => DB::all('SELECT p.code, t.txn_date, t.credit FROM sav_txns t JOIN sav_accounts a ON a.id = t.account_id JOIN sav_products p ON p.id = a.product_id
                                           JOIN members m ON m.id = a.member_id WHERE m.member_no = :n ORDER BY p.code, t.id', ['n' => $no]);
    eq(array_map(fn($x) => [$x['code'], $x['txn_date'], Dec::fix($x['credit'])], $txnDates('V58-001')),
        [['01', $d['a'], '1000.00'], ['03', $d['a'], '500.00'], ['04', $d['a'], '20000.00']], 'member 1: the savings balances and the time deposit entered on the member\'s cut-off date');
    eq(array_map(fn($x) => [$x['code'], $x['txn_date']], $txnDates('V58-002')), [['01', $d['page']], ['04', $d['own']]], 'member 2: the savings balance on the page\'s date, the time deposit on its own');
    eq(array_map(fn($x) => [$x['code'], $x['txn_date']], $txnDates('V58-003')), [['01', $d['a']]], 'member 3: on the date corrected in the review');
    $loan = DB::row('SELECT l.* FROM loan_accounts l JOIN members m ON m.id = l.member_id WHERE m.member_no = :n', ['n' => 'V58-001']);
    eq([$loan['last_penalty_date'], str_contains((string)$loan['notes'], 'as of ' . fdate($d['b']))], [$d['b'], true], 'the loan: its totals paid as of the cut-off date of its row');
    eqm($loan['principal_balance'], '20000.00', '...what is left of the principal');
    eq((string)DB::value("SELECT t.txn_date FROM transactions t WHERE t.id = :t", ['t' => $loan['release_txn_id']]), $d['b'], '...its voucher dated the cut-off date');
    $acc = DB::row("SELECT a.* FROM td_accruals a JOIN td_placements p ON p.id = a.placement_id JOIN sav_accounts s ON s.id = p.account_id JOIN members m ON m.id = s.member_id WHERE m.member_no = 'V58-001'");
    eq([$acc['accrual_date'], Dec::fix($acc['amount'])], [$d['a'], '100.00'], 'the accrued interest of the time deposit booked on the cut-off date');
    eq(array_map(fn($x) => $x['kind'] . ' ' . $x['as_of'], DB::all("SELECT kind, as_of FROM coop_imports WHERE id > :i AND kind <> 'members' ORDER BY kind, as_of", ['i' => $imports])),
        ['loans ' . $d['b'], 'savings ' . $d['a'], 'savings ' . $d['page'], 'time_deposits ' . $d['a'], 'time_deposits ' . $d['own']], 'one import voucher for each cut-off date');
    eqm(DB::value('SELECT COALESCE(SUM(debit) - SUM(credit), 0) FROM journal_lines WHERE txn_id > :v', ['v' => $voucher]), '0', 'the vouchers balanced');
});

test('Version 58: time deposits in the Consolidated Import (principal, accrued interest, date deposited, term, interest rate, maturity date), checked as Import Time Deposit checks them', function () {
    $g = fn(string $h) => ConsolidatedImport::guess($h);
    eq([$g('TD Principal'), $g('Time Deposit Principal'), $g('TD Accrued Interest'), $g('Date Deposit'), $g('TD Term'), $g('TD Interest Rate'), $g('Maturity Date'), $g('TD Certificate No')],
        [['td_principal', 1], ['td_principal', 1], ['td_accrued_interest', 1], ['td_date_deposited', 1], ['td_term', 1], ['td_rate', 1], ['td_maturity_date', 1], ['td_certificate_no', 1]],
        'the columns the workbook names: principal, accrued interest, date deposit, term, interest rate, maturity date');
    eq([$g('td2_principal'), $g('Time Deposit 2 Principal'), $g('td_principal_3'), ConsolidatedImport::secondName('td_principal')], [['td_principal', 2], ['td_principal', 2], ['td_principal', 3], 'td2_principal'],
        'a second and a third time deposit on one row');
    eq([$g('Term'), $g('Principal'), $g('Interest Rate')], [['loan_term', 1], ['loan_principal', 1], ['loan_rate', 1]], 'the words of a loan stay a loan\'s');
    $f = ConsolidatedImport::fields();
    eq(array_values(array_map(fn($k) => $f[$k]['label'], ['td_principal', 'td_accrued_interest', 'td_date_deposited', 'td_term', 'td_rate', 'td_maturity_date'])),
        ['Time deposit principal', 'Time deposit accrued interest', 'Date deposited', 'Time deposit term', 'Time deposit interest rate', 'Time deposit maturity date'], 'the columns of a time deposit');
    eq([$f['td_principal']['req'], $f['td_date_deposited']['req'], $f['td_term']['req'], $f['td_maturity_date']['req'], $f['td_principal']['rep']], [true, true, false, false, true], 'needed: the principal and the date deposited (the term or the maturity date)');
    eq(array_keys(ConsolidatedImport::KINDS), ['member', 'savings', 'td', 'loan', 'bill'], 'a kind of line of its own, after savings');

    $d = v58Dates();
    $dep = $d['deposited'];
    $td = fn(array $v) => $v + ['td_principal' => '20000.00', 'td_date_deposited' => $dep, 'td_term' => '180', 'td_rate' => '4.5'];
    $rows = [
        v58Member('V58-101', 'Garcia', 'Ana', ['gender' => 'F']) + ['savings_code' => '01', 'savings_balance' => '200'] + $td(['td_certificate_no' => 'V58-TD-1', 'td_accrued_interest' => '150.75']),
        $td(['td_product' => '01']),                                                  // not a time deposit product
        $td(['td_principal' => 'abc']),                                               // not a number
        $td(['td_date_deposited' => $d['page'], 'td_cut_off_date' => $d['a']]),       // deposited after its cut-off date
        ['td_principal' => '20000', 'td_date_deposited' => $dep],                     // neither the term nor the maturity date
        $td(['td_term' => '', 'td_maturity_date' => date('Y-m-d', strtotime($dep . ' -1 day'))]), // matures before it was deposited
        $td(['td_principal' => '5000']),                                              // under the minimum placement
        $td(['td_certificate_no' => 'V58-TD-1']),                                     // the certificate no. of line 2 again
        $td(['td_term' => '6 months', 'td_rate' => '', 'td_product' => 'Time Deposit']), // the maturity date from the term, the rate from the product, the product by its name
        $td(['td_term' => '', 'td_maturity_date' => date('Y-m-d', strtotime($dep . ' +365 days')), 'td_accrued_interest' => '-5']), // the term from the maturity date; a negative accrued interest
    ];
    $b = v56Read(v56Csv($rows), 'v58_td.csv');
    $id = (int)$b['id'];
    ConsolidatedImport::setOptions($b, ['cut_off_date' => $d['page'], 'savings_txn_code' => 'INI', 'balancing_account' => '3900']);
    v56Check($id);
    $lines = array_values(v56Lines($id, 'td'));
    eq(count($lines), 10, 'a line for each time deposit');
    $e = fn(int $i) => [$lines[$i]['errors'], $lines[$i]['cells']];
    eq([$lines[0]['errors'], $lines[0]['notes']], [[], []], 'line 1 good');
    check(str_contains($lines[0]['info'], '180 days at 4.5 %') && str_contains($lines[0]['info'], 'Active'), '...180 days at 4.5 %, Active: ' . strip_tags($lines[0]['info']));
    eq($e(1), [['Product 01 is not a time deposit product.'], ['td_product']], 'a savings product as a time deposit: in red, its cell');
    eq($e(2), [['The principal "abc" is not a number.'], ['td_principal']], 'a principal that is not a number');
    eq($e(3), [['The time deposit was deposited after its cut-off date ' . fdate($d['a']) . '.'], ['td_date_deposited']], 'deposited after its cut-off date');
    eq($e(4)[0], ['Enter the term of the time deposit, or its maturity date.'], 'neither the term nor the maturity date: said once');
    eq($e(5), [['The maturity date is not after the date deposited.'], ['td_date_deposited', 'td_maturity_date']], 'a maturity date before the date deposited (the term not asked for as well)');
    eq($e(6), [['The minimum placement for Time Deposit is 10,000.00.'], ['td_principal']], 'under the minimum placement of the product');
    eq($e(7), [['Certificate no. V58-TD-1 is also on line 3 of the file.'], ['td_certificate_no']], 'a certificate no. twice in the file');
    eq([$lines[8]['errors'], $lines[9]['errors']], [[], ['The accrued interest "-5" is not an amount.']], 'the term in months; a negative accrued interest');
    check(str_contains($lines[8]['info'], '4 %'), 'the rate of the product for 6 months (4 %): ' . strip_tags($lines[8]['info']));
    // corrected in the review: checked again at once
    $r = ConsolidatedImport::edit(ConsolidatedImport::batch($id), $lines[9]['id'], 'td_accrued_interest', '80.00');
    $x = array_values(array_filter($r['lines'], fn($l) => $l['id'] === $lines[9]['id']))[0];
    check(!$x['errors'] && str_contains($x['info'], '365 days at 4.5 %'), 'the accrued interest corrected; the term from the maturity date: ' . strip_tags($x['info']));
    $r = ConsolidatedImport::edit(ConsolidatedImport::batch($id), $lines[2]['id'], 'td_principal', '25,000.00');
    $x = array_values(array_filter($r['lines'], fn($l) => $l['id'] === $lines[2]['id']))[0];
    eq($x['errors'], [], 'the principal corrected: good');
    $r = ConsolidatedImport::edit(ConsolidatedImport::batch($id), $lines[4]['id'], 'td_maturity_date', date('Y-m-d', strtotime($dep . ' +90 days')));
    $x = array_values(array_filter($r['lines'], fn($l) => $l['id'] === $lines[4]['id']))[0];
    check(!$x['errors'] && str_contains($x['info'], '90 days'), 'the maturity date entered: 90 days');
    foreach ([1, 3, 5, 6, 7] as $i) {
        ConsolidatedImport::remove(ConsolidatedImport::batch($id), $lines[$i]['id']);
    }
    $sum = v56Check($id);
    eq([$sum['bad'], $sum['ready'], $sum['kinds']['td']['lines'], $sum['kinds']['td']['total']], [0, true, 5, '105000.00'], 'the 5 time deposits left are good, 105,000.00 of principal');
    $GLOBALS['v58td'] = $id;
});

test('Version 58: the time deposits of the Consolidated Import are saved Active with their certificate, rate and maturity date; the principal and the accrued interest entered on the cut-off date', function () {
    $id = (int)$GLOBALS['v58td'];
    $d = v58Dates();
    asUser('loans');
    $miss = ConsolidatedImport::missingRights(ConsolidatedImport::batch($id));
    Auth::actAs($GLOBALS['adminId']);
    check(in_array('Approve on the Time Deposit Center', $miss, true), 'saving time deposits needs Approve on the Time Deposit Center: ' . implode('; ', $miss));
    $liab = acct('2310');
    $payable = acct('2320');
    [$l0, $p0] = [gl($liab), gl($payable)];
    $voucher = (int)DB::value('SELECT COALESCE(MAX(id), 0) FROM transactions');
    $steps = v56Save($id);
    eq(end($steps)['done'], true, 'saved');
    $tds = DB::all("SELECT p.* FROM td_placements p JOIN sav_accounts s ON s.id = p.account_id JOIN members m ON m.id = s.member_id WHERE m.member_no = 'V58-101' ORDER BY p.id");
    eq(count($tds), 5, 'five time deposits placed');
    $t = $tds[0];
    eq([$t['certificate_no'], Dec::fix($t['principal']), Dec::fix($t['rate'], 4), (int)$t['term_days'], $t['placement_date'], $t['maturity_date'], $t['status'], Dec::fix($t['accrued_interest'])],
        ['V58-TD-1', '20000.00', '4.5000', 180, $d['deposited'], date('Y-m-d', strtotime($d['deposited'] . ' +180 days')), 'active', '150.75'], 'the first: its certificate, principal, rate, term, dates and accrued interest');
    eqm($t['maturity_value'], r2s(bcadd('20000', bcdiv(bcmul('20000', bcmul('0.045', '180', 6), 6), '365', 6), 6)), '...its maturity value (simple interest, 365 days)');
    eq([(int)$tds[3]['term_days'], $tds[3]['term_label'], Dec::fix($tds[3]['rate'], 4)], [(int)Coop::daysBetween($d['deposited'], Coop::addMonths($d['deposited'], 6)), '6 months', '4.0000'], 'the one of 6 months: its term and the rate of the product');
    eq((string)DB::value("SELECT status FROM sav_accounts WHERE id = :a", ['a' => $t['account_id']]), 'active', 'its account Active');
    eqm(DB::value('SELECT credit FROM sav_txns WHERE account_id = :a', ['a' => $t['account_id']]), '20000.00', '...the principal entered');
    eq((string)DB::value('SELECT txn_date FROM sav_txns WHERE account_id = :a', ['a' => $t['account_id']]), $d['page'], '...on the cut-off date of the page');
    eqm(bcsub(gl($liab), $l0, 2), '-105000.00', 'the time deposits liability credited with the 5 principals');
    eqm(bcsub(gl($payable), $p0, 2), '-230.75', 'the accrued interest payable credited with the accrued interest (150.75 and 80.00)');
    eq([(int)$tds[4]['term_days'], $tds[4]['term_label'], $tds[4]['maturity_date']], [365, '365 days', date('Y-m-d', strtotime($d['deposited'] . ' +365 days'))], 'the one given by its maturity date: 365 days');
    eqm(DB::value('SELECT COALESCE(SUM(debit) - SUM(credit), 0) FROM journal_lines WHERE txn_id > :v', ['v' => $voucher]), '0', 'the vouchers balanced');
    $lines = v56Lines($id, 'td', 'saved');
    check(count($lines) === 5 && !array_filter($lines, fn($x) => !str_starts_with($x['saved_ref'], 'Time deposit ')), 'each line with its certificate: ' . implode(', ', array_column($lines, 'saved_ref')));
    eq(array_values($lines)[0]['saved_ref'], 'Time deposit V58-TD-1', '...the first');
    check((bool)DB::value("SELECT id FROM audit_trail WHERE page_key = 'coop.consolidated_import' AND record_ref LIKE '%5 time deposit(s)%'"), 'the audit trail counts the time deposits');
    // the same file again: the certificate no. is on file now
    $again = v56Read(v56Csv([v58Member('V58-101', 'Garcia', 'Ana', ['gender' => 'F']) + ['td_principal' => '20000', 'td_date_deposited' => $d['deposited'], 'td_term' => '180', 'td_certificate_no' => 'V58-TD-1']]), 'v58_td_again.csv');
    v56Check((int)$again['id']);
    $x = array_values(v56Lines((int)$again['id'], 'td'))[0];
    eq($x['errors'], ['Certificate no. V58-TD-1 is already on file.'], 'the same certificate read again: on file');
    ConsolidatedImport::cancel(ConsolidatedImport::batch((int)$again['id']));
});

test('Version 58: the templates of the Consolidated Import have the Cut-Off Date column and a time deposit; the Columns sheet explains them', function () {
    foreach (['long', 'wide'] as $layout) {
        $rows = ConsolidatedImport::samples($layout);
        $keys = [];
        foreach ($rows as $r) {
            $keys += array_flip(array_keys($r));
        }
        foreach (['cut_off_date', 'td_principal', 'td_accrued_interest', 'td_date_deposited', 'td_term', 'td_rate', 'td_maturity_date'] as $k) {
            check(isset($keys[$k]), "the $layout template has $k");
        }
        $cut = $rows[0]['cut_off_date'];
        check($cut < today() && substr(Coop::addDays($cut, 1), 8, 2) === '01', "$layout: the cut-off date of the example is the end of last month ($cut)");
    }
    $long = ConsolidatedImport::samples('long');
    eq(array_keys($long[2]), ['td_principal', 'td_accrued_interest', 'td_date_deposited', 'td_term', 'td_rate', 'td_maturity_date', 'td_product'], 'rows under the member: the time deposit on its own row, after the savings');
    eqm(r2s(bcdiv(bcmul('50000', bcmul('0.045', '60', 6), 6), '365', 6)), $long[2]['td_accrued_interest'], 'the accrued interest of the example: 60 days of 4.5 % on 50,000.00');
    // the example read back: its time deposit and its cut-off date good
    $b = v56Read(v56Csv($long), 'v58_template.csv');
    $id = (int)$b['id'];
    v56Check($id);
    $td = array_values(v56Lines($id, 'td'));
    eq([count($td), $td[0]['errors']], [1, []], 'the time deposit of the template is good');
    $m = array_values(v56Lines($id, 'member'))[0];
    eq($m['values']['cut_off_date'], $long[0]['cut_off_date'], 'the cut-off date on the member');
    ConsolidatedImport::cancel(ConsolidatedImport::batch($id));
    // the page's help and the Columns sheet: every column with its part
    $f = ConsolidatedImport::fields();
    eq([ConsolidatedImport::SECTIONS[$f['td_principal']['section']], ConsolidatedImport::SECTIONS[$f['cut_off_date']['section']]], ['Time deposits', 'Member'], 'the parts of the columns');
    check(str_contains($f['cut_off_date']['hint'], 'totals paid on the loans') && str_contains($f['cut_off_date']['hint'], 'savings balances'), 'the cut-off date explained');
});
