<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v10.xlsx (version 39) at the level of the program's rules:
 *  - Import Member / Import Savings / Import Time Deposit: CSV files checked row by row (the review), then saved all
 *    or none: members Active; accounts Active with their balance entered by the savings transaction code chosen;
 *  - Batch Deposit / Batch Withdrawal from a CSV file (account no. and amount);
 *  - Hold and Unhold an amount of a savings account;
 *  - the accounts of the official receipt's fees and other items;
 *  - the menus (Financial, Members & Others, the new order; Member Disbursement and Cooperative Vouchers out of it).
 * The pages (the review grid, the toolbar buttons, the GL Account box of a new code) are checked in the browser
 * (docs/TEST_EVIDENCE.md).
 */

/** A row of a review, as the grid sends it back */
function v39Row(int $line, array $cols, array $values): array
{
    $r = ['_line' => $line];
    foreach (array_keys($cols) as $k) {
        $r[$k] = (string)($values[$k] ?? '');
    }
    return $r;
}

test('Import Member: every required field checked, the member type and dates as written in the file, the same member twice refused; uploaded all or none, Active', function () {
    Auth::actAs($GLOBALS['adminId']);
    $cols = CsvImports::cols('members');
    $req = array_keys(array_filter($cols, fn($c) => !empty($c['req'])));
    eq($req, ['last_name', 'first_name', 'middle_name', 'birth_date', 'member_type', 'membership_date', 'barangay', 'city', 'province'], 'the required fields of the workbook (residence address: barangay, municipality / city, province)');
    // the file as a spreadsheet saves it: headings in words, dates in two ways, the type by its name
    $csv = "Member Code,Last Name,First Name,Middle Name,Date of Birth,Sex,Civil Status,Member Type,Membership Date,Barangay,Municipality/City,Province\n"
        . "V39-001,Imported,Ana,Santos,1985-04-12,F,married,REG,2015-06-01,Poblacion,San Isidro,Nueva Ecija\n"
        . "V39-002,Imported,Ben,-,03/28/1990,Male,Single,Regular,01/15/2019,San Roque,Cabanatuan City,Nueva Ecija\n"
        . "V39-003,Imported,Carl,Reyes,1979-13-40,M,,REG,2010-02-02,Poblacion,San Isidro,Nueva Ecija\n"
        . "V39-001,Imported,Dina,Cruz,1992-07-07,F,,XYZ,2020-01-01,,San Isidro,Nueva Ecija\n";
    $rows = CsvReview::readText($csv, $cols, CsvImports::aliases('members'));
    eq([count($rows), $rows[0]['member_no'], $rows[0]['city'], $rows[1]['birth_date']], [4, 'V39-001', 'San Isidro', '03/28/1990'], 'the headings in words are read into the columns');
    [$checked, $sum] = CsvImports::check('members', $rows, []);
    eq([$sum['rows'], $sum['bad']], [4, 2], 'two rows with a problem');
    eq([$checked[1]['birth_date'], $checked[1]['membership_date'], $checked[1]['member_type'], $checked[1]['gender'], $checked[1]['civil_status']], ['1990-03-28', '2019-01-15', 'REG', 'M', 'single'], 'MM/DD/YYYY dates, the type by its name, "Male" and "Single" are understood');
    check(in_array('The date of birth "1979-13-40" is not a date.', $checked[2]['_errors'], true), 'a date that is not a date');
    eq(count($checked[3]['_errors']), 3, 'no barangay, a member type not set up, the member no. also on line 2');
    refuses(fn() => CsvImports::post('members', $rows, [], 'members.csv', 'coop.members'), 'row(s) have a problem', 'a file with a problem: nothing is saved');
    eq((int)DB::value("SELECT COUNT(*) FROM members WHERE member_no LIKE 'V39-%'"), 0, 'all or none: no member saved');
    // corrected in the review (the date) and a row removed
    $rows[2]['birth_date'] = '1979-12-04';
    unset($rows[3]);
    $r = CsvImports::post('members', array_values($rows), [], 'members.csv', 'coop.members');
    eq([$r['count'], $r['refs']], [3, ['V39-001', 'V39-002', 'V39-003']], 'three members uploaded');
    $m = DB::row("SELECT * FROM members WHERE member_no = 'V39-002'");
    eq([$m['status'], $m['full_name'], $m['middle_name'], $m['birth_date'], $m['membership_date'], $m['res_barangay'], $m['res_city'], $m['res_province']],
        ['active', 'Imported, Ben', null, '1990-03-28', '2019-01-15', 'San Roque', 'Cabanatuan City', 'Nueva Ecija'], 'Active, "-" leaves the middle name blank, the residence address');
    // the same person again: by the member no., or by the name and date of birth
    [$again] = CsvImports::check('members', [v39Row(2, $cols, ['member_no' => 'V39-001', 'last_name' => 'X', 'first_name' => 'Y', 'middle_name' => 'Z', 'birth_date' => '1990-01-01', 'member_type' => 'REG', 'membership_date' => '2020-01-01', 'barangay' => 'a', 'city' => 'b', 'province' => 'c']),
        v39Row(3, $cols, ['last_name' => 'Imported', 'first_name' => 'Ana', 'middle_name' => 'Santos', 'birth_date' => '1985-04-12', 'member_type' => 'REG', 'membership_date' => '2020-01-01', 'barangay' => 'a', 'city' => 'b', 'province' => 'c'])], []);
    check(str_contains(implode(' ', $again[0]['_errors']), 'already on file'), 'a member no. already on file');
    check(str_contains(implode(' ', $again[1]['_errors']), 'is already a member: V39-001.'), 'the same name and date of birth');
    eq((int)DB::value("SELECT COUNT(*) FROM coop_imports WHERE kind = 'members' AND rows_count = 3"), 1, 'the import is logged');
    // a row without a member no. above a row whose member no. is the next number of the sequence
    $next = Seq::peek('member');
    $two = [v39Row(2, $cols, ['last_name' => 'Sequence', 'first_name' => 'First', 'middle_name' => 'A', 'birth_date' => '1991-01-01', 'member_type' => 'REG', 'membership_date' => '2020-01-01', 'barangay' => 'a', 'city' => 'b', 'province' => 'c']),
        v39Row(3, $cols, ['member_no' => $next, 'last_name' => 'Sequence', 'first_name' => 'Second', 'middle_name' => 'B', 'birth_date' => '1992-02-02', 'member_type' => 'REG', 'membership_date' => '2020-01-01', 'barangay' => 'a', 'city' => 'b', 'province' => 'c'])];
    $r = CsvImports::post('members', $two, [], 'members2.csv', 'coop.members');
    check($r['refs'][1] === $next && $r['refs'][0] !== $next && $r['refs'][0] !== '', 'the member no. of the file is kept; the row without one takes the next free number (' . implode(', ', $r['refs']) . ')');
});

test('Import Savings: each account opened Active, its balance entered on the cut-off date with the savings transaction code chosen (one voucher per date and code against the code\'s GL account)', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    $ini = Savings::code('INI');
    eq([$ini['entry_side'], $ini['opposite_kind'], (int)$ini['opposite_account_id']], ['credit', 'gl', Accts::get('opening_equity')], 'the code INI Initial Savings Import, against Opening Balance Equity (migration)');
    check(isset(CsvImports::importCodes()['INI']) && !isset(CsvImports::importCodes()['CSD']), 'the codes offered: deposit codes with a GL account (not the teller\'s cash)');
    $cols = CsvImports::cols('savings');
    $opt = ['savings_txn_code' => 'INI', 'cut_off_date' => '2026-09-30'];
    $rows = [
        v39Row(2, $cols, ['member_code' => 'V39-001', 'member_name' => 'Imported, Ana S.', 'savings_code' => '01', 'amount' => '12,500.00', 'savings_txn_code' => '', 'cut_off_date' => '']),
        v39Row(3, $cols, ['member_code' => 'V39-001', 'member_name' => 'Ana Imported', 'savings_code' => '03', 'amount' => '5000', 'savings_txn_code' => 'INI', 'cut_off_date' => '10/15/2026']),
        v39Row(4, $cols, ['member_code' => 'V39-002', 'member_name' => 'Somebody Else', 'savings_code' => '01', 'amount' => '100']),
        v39Row(5, $cols, ['member_code' => 'V39-002', 'member_name' => 'Ben Imported', 'savings_code' => '04', 'amount' => '100']),
        v39Row(6, $cols, ['member_code' => 'V39-003', 'member_name' => 'Carl Imported', 'savings_code' => '01', 'amount' => '100', 'savings_txn_code' => 'CSD']),
        v39Row(7, $cols, ['member_code' => 'V39-001', 'member_name' => 'Ana Imported', 'savings_code' => '01', 'amount' => '10']),
        v39Row(8, $cols, ['member_code' => 'V39-003', 'member_name' => 'Carl R. Imported', 'savings_code' => '02', 'amount' => '0']),
        v39Row(9, $cols, ['member_code' => 'V39-002', 'member_name' => 'Imported', 'savings_code' => '02', 'amount' => '10']),
    ];
    [$c, $sum] = CsvImports::check('savings', $rows, $opt);
    eq([$c[0]['savings_txn_code'], $c[0]['cut_off_date'], $c[0]['amount'], $c[1]['cut_off_date']], ['INI', '2026-09-30', '12500.00', '2026-10-15'], 'blank cells take the code and date of the page; amounts and dates as written');
    eq(array_map(fn($r) => count($r['_errors']), $c), [0, 0, 1, 1, 1, 1, 0, 1], 'the rows with a problem');
    check(str_contains($c[7]['_errors'][0], 'is not the name of member V39-002'), 'the surname alone is not the member\'s name (another member may share it)');
    check(str_contains($c[2]['_errors'][0], 'is not the name of member V39-002'), 'the name is checked against the member code');
    check(str_contains($c[3]['_errors'][0], 'is a time deposit'), 'a time deposit product is imported on the Time Deposit Center');
    check(str_contains($c[4]['_errors'][0], 'has no GL account of its own'), 'a cash code is refused');
    check(str_contains($c[5]['_errors'][0], 'row on line 2 already'), 'the same member and product twice');
    eq($c[6]['_notes'], ['No balance: the account is opened without an entry.'], 'a zero balance opens the account only');
    $txns = (int)DB::value('SELECT COUNT(*) FROM transactions');
    $r = CsvImports::post('savings', [$rows[0], $rows[1], $rows[6]], $opt, 'savings.csv', 'sav.accounts');
    eq([$r['count'], $r['total']], [3, '17500.00'], 'three accounts, 17,500.00');
    eq((int)DB::value('SELECT COUNT(*) FROM transactions') - $txns, 2, 'one voucher per cut-off date and code');
    $m = (int)DB::value("SELECT id FROM members WHERE member_no = 'V39-001'");
    $a = DB::row('SELECT * FROM sav_accounts WHERE member_id = :m AND product_id = :p', ['m' => $m, 'p' => $ids['sav.RS']]);
    $t = DB::row('SELECT * FROM sav_txns WHERE account_id = :a', ['a' => $a['id']]);
    eq([$a['status'], Dec::fix($a['balance']), $t['txn_code'], $t['txn_date'], Dec::fix($t['credit']), (int)$t['opposite_account_id']], ['active', '12500.00', 'INI', '2026-09-30', '12500.00', Accts::get('opening_equity')], 'Active, the balance entered with INI on the cut-off date');
    $gl = DB::all('SELECT account_id, debit, credit FROM journal_lines WHERE txn_id = :t ORDER BY id', ['t' => $t['txn_id']]);
    eq(array_map(fn($l) => [(int)$l['account_id'], Dec::fix($l['debit']), Dec::fix($l['credit'])], $gl), [[acct('2300'), '0.00', '12500.00'], [Accts::get('opening_equity'), '12500.00', '0.00']], 'the journal entry: the savings product credited, the code\'s GL account debited');
    $z = DB::row("SELECT a.* FROM sav_accounts a JOIN members m ON m.id = a.member_id WHERE m.member_no = 'V39-003' AND a.product_id = :p", ['p' => $ids['sav.SS']]);
    eq([$z['status'], (int)DB::value('SELECT COUNT(*) FROM sav_txns WHERE account_id = :a', ['a' => $z['id']])], ['active', 0], 'the zero balance: an Active account with no entry');
    [$c2] = CsvImports::check('savings', [$rows[0]], $opt);
    check(str_contains(implode(' ', $c2[0]['_errors']), 'with transactions'), 'imported again: the member already has the account');
});

test('Import Time Deposit: each placement Active with its term, rate and maturity, its principal entered with the code chosen; the term in days, months or years', function () {
    Auth::actAs($GLOBALS['adminId']);
    eq([CsvImports::term('180'), CsvImports::term('6 months'), CsvImports::term('1Y'), CsvImports::term('soon')], [[180, 'days'], [6, 'months'], [1, 'years'], null], 'the terms');
    eq([CsvImports::termLabel('180'), CsvImports::termLabel('6M'), CsvImports::termLabel('1 year')], ['180 days', '6 months', '1 year'], 'written in words on the placement');
    $cols = CsvImports::cols('time_deposits');
    $opt = ['savings_txn_code' => 'INI', 'product' => '04', 'post_date' => today()];
    $d1 = Coop::addDays(today(), -60);
    $rows = [
        v39Row(2, $cols, ['member_code' => 'V39-001', 'member_name' => 'Ana Imported', 'td_term' => '180', 'amount' => '50000', 'date_opened' => $d1, 'maturity_date' => Coop::addDays($d1, 180), 'certificate_no' => 'V39-TD-1']),
        v39Row(3, $cols, ['member_code' => 'V39-002', 'member_name' => 'Ben Imported', 'td_term' => '6 months', 'amount' => '100000', 'date_opened' => '2026-07-01', 'maturity_date' => '2026-12-28', 'accrued_interest' => '1,000.00']),
        v39Row(4, $cols, ['member_code' => 'V39-003', 'member_name' => 'Carl Imported', 'td_term' => '1 year', 'amount' => '25000', 'date_opened' => Coop::addDays(today(), -10), 'maturity_date' => Coop::addMonths(Coop::addDays(today(), -10), 12), 'rate' => '5.125%', 'certificate_no' => 'V39-TD-1']),
        v39Row(5, $cols, ['member_code' => 'V39-003', 'member_name' => 'Carl Imported', 'td_term' => '90', 'amount' => '5000', 'date_opened' => Coop::addDays(today(), -5), 'maturity_date' => Coop::addDays(today(), -30)]),
    ];
    [$c, $sum] = CsvImports::check('time_deposits', $rows, $opt);
    eq(array_map(fn($r) => count($r['_errors']), $c), [0, 0, 1, 1], 'the certificate no. twice, a maturity before the date opened');
    eq([$c[0]['_days'], Dec::fix($c[0]['_rate'], 2), $c[1]['_days'], Dec::fix($c[1]['_rate'], 2)], [180, '4.00', 180, '4.00'], 'the rate of the product for the amount and term');
    check(str_contains($c[1]['_notes'][0] ?? '', 'the maturity date given (12/28/2026) is kept'), 'a term of 6 months that ends on another day: the maturity date given is kept, with a note');
    $rows[2]['certificate_no'] = 'V39-TD-3';
    $r = CsvImports::post('time_deposits', [$rows[0], $rows[1], $rows[2]], $opt, 'td.csv', 'sav.td');
    eq([$r['count'], $r['total']], [3, '175000.00'], 'three placements, 175,000.00');
    $p = DB::row("SELECT t.*, a.status AS acct_status FROM td_placements t JOIN sav_accounts a ON a.id = t.account_id WHERE t.certificate_no = 'V39-TD-3'");
    eq([$p['status'], $p['acct_status'], Dec::fix($p['rate'], 4), (int)$p['term_days'], $p['term_label']], ['active', 'active', '5.1250', Coop::daysBetween($rows[2]['date_opened'], $rows[2]['maturity_date']), '1 year'], 'Active, the rate of the file, the term in words');
    $b = DB::row("SELECT t.* FROM td_placements t JOIN sav_accounts a ON a.id = t.account_id JOIN members m ON m.id = a.member_id WHERE m.member_no = 'V39-002' AND t.status = 'active'");
    eq([Dec::fix($b['accrued_interest']), (string)DB::value('SELECT txn_code FROM sav_txns WHERE account_id = :a', ['a' => $b['account_id']])], ['1000.00', 'INI'], 'the accrued interest at the posting date; the principal entered with INI');
    DB::update('members', ['status' => 'applicant'], "member_no = 'V39-003'");
    [$c] = CsvImports::check('time_deposits', [v39Row(2, $cols, ['member_code' => 'V39-003', 'member_name' => 'Carl Imported', 'td_term' => '90', 'amount' => '20000', 'date_opened' => today(), 'maturity_date' => Coop::addDays(today(), 90)])], $opt);
    check(str_contains($c[0]['_errors'][0] ?? '', 'placed for an active member'), 'an applicant has no time deposit (placing it would refuse)');
    DB::update('members', ['status' => 'active'], "member_no = 'V39-003'");
    $gl = DB::row('SELECT SUM(debit) AS dr, SUM(credit) AS cr FROM journal_lines WHERE txn_id = :t', ['t' => $b['placement_txn_id']]);
    eq([Dec::fix($gl['dr']), Dec::fix($gl['cr'])], ['176000.00', '176000.00'], 'one voucher: time deposits and the interest payable credited, the code\'s GL account debited');
});

test('Batch Deposit and Batch Withdrawal: one transaction of the Savings Transaction Center per row (account no. and amount); the withdrawable balance, holds and restrictions checked; all or none', function () {
    Auth::actAs($GLOBALS['adminId']);
    // a deposit code and a withdrawal code against a GL account (no teller cash needed)
    foreach ([['BDP', 'Payroll Deduction Deposit (batch)', 'credit'], ['BWD', 'Payroll Release (batch)', 'debit']] as [$code, $desc, $side]) {
        if (!DB::value('SELECT id FROM sav_txn_codes WHERE code = :c', ['c' => $code])) {
            DB::insert('sav_txn_codes', ['code' => $code, 'description' => $desc, 'opposite_kind' => 'gl', 'opposite_account_id' => acct('2000'), 'entry_side' => $side, 'is_check_deposit' => 0, 'clearing_days' => 0, 'is_error_correct' => 0, 'is_system' => 0, 'is_active' => 1, 'is_memo' => 0]);
        }
    }
    check(isset(CsvImports::batchCodes('deposit')['BDP']) && !isset(CsvImports::batchCodes('deposit')['BWD']) && isset(CsvImports::batchCodes('withdrawal')['BWD']), 'deposit codes for a batch deposit, withdrawal codes for a batch withdrawal');
    $m1 = (int)DB::value("SELECT id FROM members WHERE member_no = 'V39-001'");
    $a1 = DB::row("SELECT a.* FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND p.product_type = 'ordinary'", ['m' => $m1]);
    $m3 = (int)DB::value("SELECT id FROM members WHERE member_no = 'V39-003'");
    $a3 = DB::row("SELECT a.* FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND p.product_type = 'special'", ['m' => $m3]);
    $cols = CsvImports::cols('deposit');
    $dep = [v39Row(2, $cols, ['account_no' => $a1['account_no'], 'amount' => '500']), v39Row(3, $cols, ['account_no' => $a3['account_no'], 'amount' => '6,000.00', 'memo' => 'Bonus']), v39Row(4, $cols, ['account_no' => 'NOPE', 'amount' => '1'])];
    [$c, $sum, $g] = CsvImports::check('deposit', $dep, ['code' => 'BDP', 'date' => today(), 'memo' => 'Payroll October']);
    eq([$g, array_map(fn($r) => count($r['_errors']), $c)], [[], [0, 0, 1]], 'an account no. not on file');
    [, , $g2] = CsvImports::check('deposit', $dep, ['code' => 'BWD', 'date' => today()]);
    check((bool)$g2, 'a withdrawal code is refused for a batch deposit');
    $n0 = (int)DB::value('SELECT COUNT(*) FROM sav_txns');
    $r = CoopLedger::withClass(null, fn() => CsvImports::post('deposit', [$dep[0], $dep[1]], ['code' => 'BDP', 'date' => today(), 'memo' => 'Payroll October'], 'dep.csv', 'sav.transact'));
    eq([$r['count'], $r['total'], (int)DB::value('SELECT COUNT(*) FROM sav_txns') - $n0], [2, '6500.00', 2], 'two deposits');
    $t = DB::row('SELECT * FROM sav_txns WHERE account_id = :a ORDER BY id DESC LIMIT 1', ['a' => $a3['id']]);
    eq([$t['txn_code'], Dec::fix($t['credit']), $t['description'], (bool)$t['trans_no']], ['BDP', '6000.00', 'Bonus', true], 'a transaction of the Savings Transaction Center with its number; the row\'s own memo');
    $t1 = DB::row('SELECT * FROM sav_txns WHERE account_id = :a ORDER BY id DESC LIMIT 1', ['a' => $a1['id']]);
    eq($t1['description'], 'Payroll October', 'the memo of the batch');
    // withdrawals: what is withdrawable, the holds, the restriction, the rows before on the same account
    Savings::placeHold((int)$a1['id'], '12000', 'Collateral', null, 'sav.accounts');
    $avail = Savings::available(Savings::account((int)$a1['id']));
    eqm($avail, Dec::sub('13000', '12000', 2), 'with 12,000.00 on hold, 1,000.00 can be withdrawn');
    $wd = [v39Row(2, $cols, ['account_no' => $a1['account_no'], 'amount' => '600']), v39Row(3, $cols, ['account_no' => $a1['account_no'], 'amount' => '600'])];
    [$c] = CsvImports::check('withdrawal', $wd, ['code' => 'BWD', 'date' => today()]);
    eq([count($c[0]['_errors']), count($c[1]['_errors'])], [0, 1], 'the second row would take the account below what is withdrawable');
    check(str_contains($c[1]['_errors'][0], '12,000.00 on hold'), 'the hold is named');
    refuses(fn() => CsvImports::post('withdrawal', $wd, ['code' => 'BWD', 'date' => today()], 'wd.csv', 'sav.transact'), 'row(s) have a problem', 'all or none');
    Savings::restrict((int)$a3['id'], true, 'Garnishment', 'sav.accounts');
    [$c] = CsvImports::check('withdrawal', [v39Row(2, $cols, ['account_no' => $a3['account_no'], 'amount' => '10'])], ['code' => 'BWD', 'date' => today()]);
    check(str_contains($c[0]['_errors'][0] ?? '', 'restricted'), 'a restricted account is refused');
    Savings::restrict((int)$a3['id'], false, '', 'sav.accounts');
    [$c] = CsvImports::check('withdrawal', [v39Row(2, $cols, ['account_no' => $a3['account_no'], 'amount' => '10'])], ['code' => 'BWD', 'date' => today()]);
    check(str_contains($c[0]['_errors'][0] ?? '', 'lock-in period'), 'the rules of the posting: a special savings account within its lock-in period');
    $r = CoopLedger::withClass(null, fn() => CsvImports::post('withdrawal', [$wd[0]], ['code' => 'BWD', 'date' => today()], 'wd.csv', 'sav.transact'));
    eqm(Savings::account((int)$a1['id'])['balance'], '12400', 'the withdrawal posted: 13,000.00 less 600.00');
    [$c] = CsvImports::check('withdrawal', [v39Row(2, $cols, ['account_no' => $a1['account_no'], 'amount' => '350'])], ['code' => 'BWD', 'date' => today()]);
    check(str_contains($c[0]['_errors'][0] ?? '', 'must stay as the minimum balance') && str_contains($c[0]['_info'], 'withdrawable 300.00'), 'the minimum balance stays: 400.00 not held, 300.00 withdrawable');
});

test('Hold and Unhold: an amount of the balance earmarked (at most what is withdrawable), the rest withdrawable; released one hold or every hold', function () {
    Auth::actAs($GLOBALS['adminId']);
    $m1 = (int)DB::value("SELECT id FROM members WHERE member_no = 'V39-001'");
    $a = DB::row("SELECT a.* FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND p.product_type = 'ordinary'", ['m' => $m1]);
    eqm($a['hold_amount'], '12000', '12,000.00 on hold from the batch test');
    refuses(fn() => Savings::placeHold((int)$a['id'], '500', 'More', null, 'sav.accounts'), 'can be held', 'more than what is withdrawable (400.00) is refused');
    refuses(fn() => Savings::placeHold((int)$a['id'], '100', ' ', null, 'sav.accounts'), 'reason', 'the reason is needed');
    $h2 = Savings::placeHold((int)$a['id'], '400', 'Court order', null, 'sav.accounts');
    eqm(Savings::available(Savings::account((int)$a['id'])), '0', 'every peso held');
    refuses(fn() => SavTxn::post(['account_id' => (int)$a['id'], 'code' => 'BWD', 'amount' => '1', 'date' => today()], 'sav.transact'), '', 'nothing can be withdrawn');
    Savings::releaseHold($h2, 'Order lifted', 'sav.accounts');
    eqm(Savings::available(Savings::account((int)$a['id'])), '400', 'Unhold: 400.00 withdrawable again');
    eq((int)DB::value("SELECT COUNT(*) FROM audit_trail WHERE record_type = 'sav_account' AND record_id = :a AND action IN ('hold', 'release_hold') AND page_key = 'sav.accounts'", ['a' => $a['id']]), 3, 'each hold and unhold in the audit trail');
    foreach (DB::column('SELECT id FROM sav_holds WHERE account_id = :a AND released_at IS NULL', ['a' => $a['id']]) as $hid) {
        Savings::releaseHold((int)$hid, 'All', 'sav.accounts');
    }
    eqm(Savings::account((int)$a['id'])['hold_amount'], '0', 'every hold released');
});

test('Official receipt: every GL account as a fee or other item except those of the savings, time deposits, loans receivable and the receivables and inventory of sales', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    $list = Teller::receiptAccounts();
    $out = Teller::receiptExcluded();
    foreach (['2300', '2305', '2310', '2320', '3010', '1210', '1211', '1212', '1220', '1100', '1200'] as $code) {
        check(!isset($list[acct($code)]) && isset($out[acct($code)]), 'account ' . $code . ' is not offered');
    }
    foreach (['4400', '2000', '3900', '1001'] as $code) {
        check(isset($list[acct($code)]), 'account ' . $code . ' is offered');
    }
    eq(count($list) + count($out), count(Accts::all()), 'every other active account is offered');
    $m = (int)DB::value("SELECT id FROM members WHERE member_no = 'V39-002'");
    shift(today());
    refuses(fn() => receipt($m, today(), [['kind' => 'charge', 'account_id' => acct('2300'), 'amount' => '100', 'description' => 'x']]), 'kept by the ledger of savings deposits', 'a charge to the savings deposits account is refused');
    $r = receipt($m, today(), [['kind' => 'charge', 'account_id' => acct('2000'), 'amount' => '100', 'description' => 'Other payable']]);
    check(!empty($r['txn_id']), 'a charge to another liability account is received');
    svCloseShift();
});

test('Menus: Financial and Members & Others in the cooperative\'s order; Member Disbursement and Cooperative Vouchers out of the menu (their vouchers still open)', function () {
    Auth::actAs($GLOBALS['adminId']);
    // the migration once more (an earlier test ran the party migration of version 23 again, which set its own order); safe to run twice
    $ini = (int)DB::value("SELECT COUNT(*) FROM sav_txn_codes WHERE code = 'INI'");
    run_sql_file($GLOBALS['pdo'], APP_ROOT . '/database/migrations/2027_02_21_menus_imports_codes.sql');
    eq((int)DB::value("SELECT COUNT(*) FROM sav_txn_codes WHERE code = 'INI'"), $ini, 'run twice: the code INI is not added again');
    $mods = Access::modules();
    eq(array_map(fn($m) => $m[0], array_values($mods)), ['Home', 'Financial', 'Members & Others', 'Teller', 'Savings & Deposits', 'Loans', 'Water Billing', 'Banking', 'Company', 'Customers', 'Point of Sale',
        'Vendors', 'Inventory', 'Fixed Assets', 'Parties', 'BIR Reports', 'Year-end Distribution', 'CDA Compliance', 'System Administration'], 'the 19 menus in the order of the workbook');
    eq(DB::pairs('SELECT code, name FROM modules WHERE code IN (\'coa\', \'coop\') ORDER BY code'), ['coa' => 'Financial', 'coop' => 'Members & Others'], 'the names in the database (access pages)');
    eq(array_values(DB::column('SELECT code FROM modules ORDER BY sort_order')), array_keys($mods), 'the same order in the database');
    $menu = build_menu();
    eq(array_keys($menu), array_values(array_filter(array_keys($mods), fn($k) => isset($menu[$k]))), 'the sidebar follows it');
    $names = [];
    foreach ($menu as $code => $mm) {
        foreach ($mm['groups'] as $items) {
            foreach ($items as $it) {
                $names[] = $it['key'];
            }
        }
    }
    check(!in_array('teller.disburse', $names, true) && !in_array('coop.vouchers', $names, true), 'Member Disbursement and Cooperative Vouchers are not in the menu');
    check(Access::page('teller.disburse') && Access::page('coop.vouchers'), 'their pages still open (the vouchers made, a settlement, an unclaimed distribution)');
    eq((int)DB::value("SELECT COUNT(*) FROM pages WHERE page_key IN ('teller.disburse', 'coop.vouchers') AND show_in_menu = 0"), 2, 'out of the menu in the database too');
    eq(Txn::page('coop_jv'), 'coop.vouchers', 'a cooperative journal voucher still opens on its page');
});
