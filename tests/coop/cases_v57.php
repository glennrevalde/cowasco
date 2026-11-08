<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v22.xlsx (version 57) at the level of the program's rules:
 *  - Water Billing > Billing > Bill Accounts Center: every bill of the members and customers (water, one-time,
 *    installment, recurring and fixed share capital bills), the open ones by default, the closed (fully paid) ones on
 *    request; Account No., Name, Description, Bill Date, Invoice No., Due Date, Amount, Paid, Balance, Status; the
 *    filters (bill status, bill code, zone, address sub-group, members or customers, bill type, bill dates, search);
 *    the export (Excel, CSV) and the printout; the page and its rights (the migration 2027_05_02);
 *  - Financial > Chart of Accounts > Import: the accounts of a file reviewed (a line with a problem in red, its cells
 *    corrected, its lines removed), saved every one or none, main accounts first; the accounts on the chart updated or
 *    left as they are; the chart's own Export read back unchanged; an Excel workbook read as a CSV file;
 *  - the water bill / collection receipt on Letter bond paper, portrait, with no margins of the browser (no header or
 *    footer), black text in a bold dot-matrix font; the pre-printed layout of the same paper, and a layout saved as
 *    the former default read as the default of now.
 * The pages (the center, the import, the address sub-group edited on the member form, the receipts) are checked over
 * HTTP (tests/water/e2e_http.php) and in the browser (docs/TEST_EVIDENCE.md).
 */

/** The bills of the version 57 tests (made once): three accounts, every kind of bill, open, paid, partly paid, cancelled */
function v57Fixture(): array
{
    static $f = null;
    if ($f !== null) {
        return $f;
    }
    $wt = $GLOBALS['wt'];
    $now = date('Y-m-d H:i:s');
    $uid = (int)$GLOBALS['adminId'];
    $in = static fn(int $days): string => date('Y-m-d', strtotime(today() . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));
    $code = fn(string $name, string $type) => (int)(DB::value('SELECT code_id FROM bill_codes WHERE code = :c', ['c' => $name])
        ?: DB::insert('bill_codes', ['code' => $name, 'description' => $name . ' (test)', 'type' => $type, 'ar_account' => $wt['ar'], 'income_account' => $wt['sales'],
            'cost_price' => 0, 'sales_price' => '0.00', 'allow_member_discount' => 0, 'created_at' => $now, 'updated_at' => $now]));
    $f = ['fee' => $code('V57 SERVICE FEE', 'Service'), 'pipe' => $code('V57 METER', 'Inventory'), 'garbage' => $code('V57 GARBAGE', 'Charges')];
    // a customer and a member in zone 97 (two address sub-groups), a customer in zone 98 with none
    $f['cust'] = wt_account(957001, 'Valenzuela', 'Corazon', null);
    $f['m'] = v42Member('Buenaflor', 'Teodoro', 957002);
    $f['other'] = wt_account(957003, 'Zamora', 'Lito', null);
    DB::run("UPDATE water_accounts SET zone = 97, address_subgroup = 'V57 Purok 1' WHERE pkey = 957001");
    DB::run("UPDATE water_accounts SET zone = 97, address_subgroup = 'V57 Purok 2' WHERE pkey = 957002");
    DB::run('UPDATE water_accounts SET zone = 98, address_subgroup = NULL WHERE pkey = 957003');
    $f['member_no'] = (string)DB::value('SELECT member_no FROM members WHERE id = :m', ['m' => $f['m']['member']]);
    // water bills: the customer's unpaid and overdue (due 2026-10-15), the member's paid
    [$f['w1'], $f['w1amt']] = wt_bill(957001, 12);
    [$f['w2'], $f['w2amt']] = wt_bill(957002, 9);
    DB::run('UPDATE bill_readings SET amount_paid = amount_due, is_paid = 1 WHERE reading_id = :r', ['r' => $f['w2']]);
    // every bill numbered from the one billing sequence, as the billing pages number them (OTB-<reference> ...)
    $ref = fn() => DB::insert('global_billing_sequence', ['created_at' => $now]);
    // one-time bills: the customer's partly paid and not yet due; a cancelled and a deleted one
    $ot = fn(int $acct, string $total, string $paid, string $status, string $date, ?string $due) => DB::insert('one_time_bills', ['global_ref_number' => $ref(), 'member_id' => $acct, 'bill_code_id' => $f['fee'],
        'quantity' => 1, 'bill_date' => $date, 'due_date' => $due, 'term_days' => 0, 'total_amount' => $total, 'gross_amount' => $total, 'amount_paid' => $paid,
        'status' => $status, 'created_by' => $uid, 'created_at' => $now]);
    $f['ot1'] = $ot(957001, '500.00', '200.00', 'unpaid', $in(-5), $in(10));
    $f['ot2'] = $ot(957003, '75.00', '0.00', 'cancelled', $in(-5), $in(10));
    $f['ot3'] = $ot(957003, '80.00', '0.00', 'deleted', $in(-5), $in(10));
    // an installment contract of three amortizations: paid, partly paid and overdue, not yet due
    $f['ins'] = DB::insert('installment_bills', ['global_ref_number' => $ref(), 'member_id' => 957003, 'bill_code_id' => $f['pipe'], 'quantity' => 1, 'payment_mode' => 'monthly', 'total_amount' => '3000.00',
        'gross_amount' => '3000.00', 'term' => 3, 'amortization_type' => 'fixed', 'start_date' => $in(-40), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $f['s'] = [];
    foreach ([[-40, '1000.00', 'paid'], [-10, '300.00', 'partial'], [20, '0.00', 'pending']] as [$d, $paid, $st]) {
        $f['s'][] = DB::insert('installment_schedules', ['installment_id' => $f['ins'], 'due_date' => $in($d), 'amount' => '1000.00', 'amount_paid' => $paid, 'status' => $st]);
    }
    // a recurring bill of the customer, paid
    $plan = DB::insert('recurring_plans', ['bill_code_id' => $f['garbage'], 'amount' => '60.00', 'frequency' => 'Monthly', 'first_billing_date' => '2026-01-01', 'term_days' => 15,
        'status' => 'active', 'created_by' => $uid, 'created_at' => $now, 'updated_at' => $now]);
    $sub = DB::insert('recurring_subscriptions', ['plan_id' => $plan, 'member_id' => 957001, 'subscription_start_date' => '2026-01-01', 'next_billing_date' => '2026-11-01',
        'status' => 'active', 'created_by' => $uid, 'created_at' => $now, 'updated_at' => $now]);
    $f['rec'] = DB::insert('recurring_bills', ['global_ref_number' => $ref(), 'subscription_id' => $sub, 'plan_id' => $plan, 'member_id' => 957001, 'bill_code_id' => $f['garbage'],
        'billing_period_start' => '2026-10-01', 'billing_period_end' => '2026-10-31', 'bill_date' => '2026-10-01', 'due_date' => '2026-10-15', 'term_days' => 15,
        'total_amount' => '60.00', 'gross_amount' => '60.00', 'amount_paid' => '60.00', 'balance' => '0.00', 'status' => 'paid', 'created_by' => $uid, 'created_at' => $now, 'updated_at' => $now]);
    // the member's fixed share capital bills: one open (no due date), one paid
    $scPlan = v42Save($f['m'], 'monthly', $in(40), 1, '100.00');
    $sc = fn(string $date, string $paid, string $status) => DB::insert('share_capital_bills', ['plan_id' => $scPlan, 'member_id' => $f['m']['member'], 'sav_account_id' => $f['m']['sc'],
        'bill_date' => $date, 'due_date' => null, 'amount' => '100.00', 'amount_paid' => $paid, 'status' => $status, 'created_at' => $now]);
    $f['sc1'] = $sc('2026-10-01', '0.00', 'unpaid');
    $f['sc2'] = $sc('2026-09-01', '100.00', 'paid');
    $f['sc_acct'] = (string)DB::value('SELECT account_no FROM sav_accounts WHERE id = :a', ['a' => $f['m']['sc']]);
    // the invoice numbers of the center
    $no = fn(string $sql, int $id) => (string)DB::value($sql, ['i' => $id]);
    $f['no'] = ['w1' => 'WTR-' . ($no('SELECT global_ref_number FROM bill_readings WHERE reading_id = :i', $f['w1']) ?: $f['w1']),
        'w2' => 'WTR-' . ($no('SELECT global_ref_number FROM bill_readings WHERE reading_id = :i', $f['w2']) ?: $f['w2']),
        'ot1' => 'OTB-' . $no('SELECT global_ref_number FROM one_time_bills WHERE bill_id = :i', $f['ot1']),
        'ot2' => 'OTB-' . $no('SELECT global_ref_number FROM one_time_bills WHERE bill_id = :i', $f['ot2']),
        'ot3' => 'OTB-' . $no('SELECT global_ref_number FROM one_time_bills WHERE bill_id = :i', $f['ot3']),
        'rec' => 'REC-' . $no('SELECT global_ref_number FROM recurring_bills WHERE bill_id = :i', $f['rec']),
        'sc1' => 'SCB-' . $f['sc1'], 'sc2' => 'SCB-' . $f['sc2']];
    $insRef = $no('SELECT global_ref_number FROM installment_bills WHERE installment_id = :i', $f['ins']);
    foreach ($f['s'] as $k => $sid) {
        $f['no']['s' . $k] = 'INS-' . $insRef . '-' . $sid;
    }
    return $f;
}

/** The lines the filters find (every page) */
function v57Rows(array $filters): array
{
    return BillAccounts::rows(BillAccounts::filters($filters), 0, BillAccounts::EXPORT_MAX);
}

test('Version 57: the Bill Accounts Center shows every bill of the members and customers, the open ones by default, with Amount, Paid, Balance and the status of each', function () {
    $f = v57Fixture();
    $open = v57Rows(['zone' => '97']);
    eq(array_column($open, 'invoice_no'), [$f['no']['w1'], $f['no']['ot1'], $f['no']['sc1']],
        'by default the open bills: the water bill, the one-time bill partly paid, the open share capital bill (sorted by account no., then bill date)');
    $by = array_column($open, null, 'invoice_no');
    $w = $open[0];
    eq([$w['account_no'], $w['name'], $w['kind'], $w['description'], $w['bill_date'], $w['due_date']], ['W957001', 'Valenzuela, Corazon', 'Customer', 'Water consumption, 12 m³', '2026-10-01', '2026-10-15'],
        'the account no., the name, members or customers, the description, the bill date and the due date of a water bill');
    eq([Dec::fix($w['amount']), Dec::fix($w['paid']), Dec::fix($w['balance']), $w['status'], $w['state']], [Dec::fix($f['w1amt']), '0.00', Dec::fix($f['w1amt']), 'Overdue', 'overdue'], '...its amount, paid, balance and status');
    $o = $by[$f['no']['ot1']];
    eq([$o['description'], Dec::fix($o['amount']), Dec::fix($o['paid']), Dec::fix($o['balance']), $o['status']], ['V57 SERVICE FEE (test)', '500.00', '200.00', '300.00', 'Not yet due, partly paid'],
        'a one-time bill partly paid and not yet due');
    $s = $by[$f['no']['sc1']];
    eq([$s['account_no'], $s['name'], $s['kind'], $s['description'], $s['due_date'], $s['status']], ['W957002', 'Buenaflor, Teodoro', 'Member', 'Share capital ' . $f['sc_acct'] . ' (Oct 2026)', null, 'Open'],
        'a share capital bill on the member\'s water-service account, with no due date: Open');
    $paid = v57Rows(['zone' => '97', 'status' => 'paid']);
    eq(array_column($paid, 'invoice_no'), [$f['no']['rec'], $f['no']['sc2'], $f['no']['w2']],
        'Closed (fully paid): the recurring bill, the paid share capital bill (September) and the member\'s water bill (October)');
    eq(array_values(array_unique(array_merge(array_column($paid, 'status'), array_map(fn($l) => Dec::fix($l['balance']), $paid)))), ['Paid', '0.00'], '...each Paid, nothing left');
    eq($paid[0]['description'], 'V57 GARBAGE (test), Oct 2026', 'a recurring bill with its service month');
    eq(count(v57Rows(['zone' => '97', 'status' => 'all'])), 6, 'Open and closed: the six bills of zone 97');
    // the installment: each amortization a bill, dated on its due date; the cancelled and the deleted one-time bills
    $ins = v57Rows(['zone' => '98', 'status' => 'all']);
    eq(array_column($ins, 'invoice_no'), [$f['no']['s0'], $f['no']['s1'], $f['no']['s2']], 'each amortization of the installment is a bill (INS-reference-amortization)');
    eq(array_column($ins, 'description'), ['V57 METER (test), amortization 1 of 3', 'V57 METER (test), amortization 2 of 3', 'V57 METER (test), amortization 3 of 3'], '...named by its place in the contract');
    eq(array_column($ins, 'status'), ['Paid', 'Overdue, partly paid', 'Not yet due'], '...paid, overdue and partly paid, not yet due');
    eq(array_column(v57Rows(['zone' => '98', 'status' => 'overdue']), 'invoice_no'), [$f['no']['s1']], 'Open and overdue');
    eq(array_column(v57Rows(['zone' => '98', 'status' => 'not_due']), 'invoice_no'), [$f['no']['s2']], 'Open, not yet due');
    eq(array_column(v57Rows(['zone' => '98', 'status' => 'cancelled']), 'invoice_no'), [$f['no']['ot2']], 'Cancelled: the cancelled one-time bill; the deleted one is never shown');
    check(!in_array($f['no']['ot3'], array_column(v57Rows(['status' => 'all', 'q' => 'Zamora']), 'invoice_no'), true), 'a deleted bill is not a bill');
    // the totals of the lines found
    $t = BillAccounts::totals(BillAccounts::filters(['zone' => '98', 'status' => 'all']));
    eq([$t['n'], $t['accounts'], Dec::fix($t['amount']), Dec::fix($t['paid']), Dec::fix($t['balance']), $t['overdue'], Dec::fix($t['overdue_balance'])],
        [3, 1, '3000.00', '1300.00', '1700.00', 1, '700.00'], 'the count, the accounts, Amount, Paid and Balance of the lines found, and the overdue ones');
});

test('Version 57: the Bill Accounts Center filters by bill status, bill code, zone, address sub-group, members or customers, bill type, bill dates and the words searched', function () {
    $f = v57Fixture();
    $n = fn(array $x) => count(v57Rows($x));
    eq([$n(['subgroup' => 'V57 Purok 1', 'status' => 'all']), $n(['subgroup' => 'V57 Purok 2', 'status' => 'all'])], [3, 3], 'by address sub-group: the customer\'s three bills, the member\'s three (the share capital bills on his water-service account)');
    check(in_array($f['no']['s0'], array_column(v57Rows(['subgroup' => BillAccounts::NONE, 'status' => 'all']), 'invoice_no'), true), '"No address sub-group" finds the account with none');
    eq([$n(['zone' => '97', 'holder' => 'member', 'status' => 'all']), $n(['zone' => '97', 'holder' => 'customer', 'status' => 'all'])], [3, 3], 'members or customers');
    eq(array_column(v57Rows(['code' => (string)$f['pipe'], 'status' => 'all']), 'invoice_no'), [$f['no']['s0'], $f['no']['s1'], $f['no']['s2']], 'by bill code');
    eq(array_column(v57Rows(['code' => BillAccounts::SC_CODE, 'zone' => '97', 'status' => 'all']), 'invoice_no'), [$f['no']['sc2'], $f['no']['sc1']], 'the bill code "Fixed share capital"');
    eq($n(['code' => (string)BillAccounts::waterCodeId(), 'zone' => '97', 'status' => 'all']), 2, 'the bill code of the water bills');
    eq(array_column(v57Rows(['type' => 'recurring', 'zone' => '97', 'status' => 'all']), 'invoice_no'), [$f['no']['rec']], 'by bill type');
    eq($n(['zone' => '97', 'status' => 'all', 'from' => '2026-10-01', 'to' => '2026-10-01']), 4, 'by bill dates (from, to): the bills of October 1');
    // the search: every word of the name, or the account no., the invoice no. or the member no.
    eq($n(['q' => 'corazon valenzuela', 'status' => 'all']), 3, 'every word of the name, in any order');
    eq($n(['q' => 'W957003', 'status' => 'all']), 3, 'the account no.');
    eq(array_column(v57Rows(['q' => $f['no']['ot1'], 'status' => 'all']), 'invoice_no'), [$f['no']['ot1']], 'the invoice no.');
    eq($n(['q' => $f['member_no'], 'status' => 'all']), 3, 'the member no.');
    // sorted, paged
    $bal = v57Rows(['zone' => '98', 'status' => 'all', 'sort' => 'balance']);
    eq(array_map(fn($l) => Dec::fix($l['balance']), $bal), ['1000.00', '700.00', '0.00'], 'sorted by balance, largest first');
    eq(array_column(BillAccounts::rows(BillAccounts::filters(['zone' => '98', 'status' => 'all']), 1, 1), 'invoice_no'), [$f['no']['s1']], 'a page of lines (from, how many)');
    // unknown values fall back; nothing can match
    $x = BillAccounts::filters(['status' => 'x', 'code' => 'drop table', 'zone' => 'z', 'holder' => 'q', 'from' => '2026-13-40', 'sort' => 'nope', 'pg' => '-3']);
    eq([$x['status'], $x['code'], $x['zone'], $x['holder'], $x['from'], $x['sort'], $x['pg']], ['open', '', '', '', '', 'account', 1], 'an unknown filter value falls back to its default');
    eq($n(['code' => BillAccounts::SC_CODE, 'holder' => 'customer']), 0, 'a share capital bill is never a customer\'s');
    check(BillAccounts::filtered(BillAccounts::filters(['zone' => '97'])) && !BillAccounts::filtered(BillAccounts::filters([])), 'the filters differ from the first view');
    eq(BillAccounts::describe(BillAccounts::filters(['status' => 'paid', 'zone' => '97', 'holder' => 'member', 'q' => 'abc'])), 'Closed (fully paid), zone 97, members only, search "abc"', 'the filters in words, for the printout');
    // the choices of the filters
    $o = BillAccounts::options();
    check(isset($o['codes'][(string)$f['pipe']], $o['codes'][BillAccounts::SC_CODE], $o['zones']['97'], $o['zones'][BillAccounts::NONE], $o['subgroups']['V57 Purok 1'], $o['subgroups'][BillAccounts::NONE]),
        'the bill codes, the zones and the address sub-groups to choose from');
});

test('Version 57: the Bill Accounts Center is exported to Excel or CSV and printed with the lines found; it is a page of Water Billing > Billing given to the roles of the billing pages', function () {
    $f = v57Fixture();
    $flt = BillAccounts::filters(['zone' => '98', 'status' => 'all']);
    [$rows, $bold, $money] = BillAccounts::sheet($flt, BillAccounts::rows($flt, 0, 100), BillAccounts::totals($flt), 'Bill Accounts');
    eq($rows[2], ['Account No.', 'Name', 'Description', 'Bill Date', 'Invoice No.', 'Due Date', 'Amount', 'Paid', 'Balance', 'Status'], 'the columns of the center, in its order');
    eq([count($rows), $rows[3][0], $rows[3][4], $rows[3][6], $rows[3][9]], [7, 'W957003', $f['no']['s0'], 1000.0, 'Paid'], 'one row per bill, the amounts as numbers');
    eq([$rows[6][0], $rows[6][6], $rows[6][7], $rows[6][8]], ['Total: 3 bills of 1 account', 3000.0, 1300.0, 1700.0], 'the total row');
    eq([$bold, $money], [[0, 2, 6], [6, 7, 8]], 'the title, the heading and the total in bold; Amount, Paid and Balance as money');
    $tmp = tempnam(sys_get_temp_dir(), 'ba') . '.xlsx';
    (new Xlsx())->sheet('Bill Accounts', $rows, $bold, $money)->save($tmp);
    $back = XlsxReader::rows($tmp);
    @unlink($tmp);
    eq([$back[2][4], $back[3][4], $back[3][1]], ['Invoice No.', $f['no']['s0'], 'Zamora, Lito'], 'the Excel workbook reads back');
    // the page: Water Billing > Billing, after Billing Management; the rights of the billing pages (the migration)
    $def = Access::pageDef('bill.accounts');
    eq([$def['module'] ?? null, $def['group'] ?? null, $def['name'] ?? null], ['water', 'Billing', 'Bill Accounts Center'], 'a page of Water Billing > Billing');
    $keys = array_keys(Access::allPageDefs());
    eq(array_search('bill.accounts', $keys, true), array_search('water.billing_management', $keys, true) + 1, '...after Billing Management in the menu');
    eq(url('bill.accounts'), base_url() . '/index.php?p=bill.accounts', 'opened through the program (not a water page file)');
    $page = (int)DB::value("SELECT id FROM pages WHERE page_key = 'bill.accounts'");
    $src = DB::column("SELECT DISTINCT r.role_id FROM role_page_access r JOIN pages p ON p.id = r.page_id WHERE r.allowed = 1 AND p.page_key IN ('water.billing_list', 'water.generate_soa', 'water.water_bills_collection')");
    $got = DB::column('SELECT role_id FROM role_page_access WHERE page_id = :p AND allowed = 1', ['p' => $page]);
    sort($src);
    sort($got);
    check($page > 0 && $src && $got === $src, 'given to every role of Billing List, Generate SOA / Post Billing or Collections (Cashier)');
    run_sql_file(DB::pdo(), APP_ROOT . '/database/migrations/2027_05_02_bill_accounts_center.sql');
    eq((int)DB::value("SELECT COUNT(*) FROM pages WHERE page_key = 'bill.accounts'"), 1, 'the migration can be run again');
    eq(Schema::pending(), [], 'nothing pending');
});

// ======================================================================================== the chart of accounts

/** The rows of a CSV text as the import reads them */
function v57Coa(string $csv): array
{
    return CsvReview::readText($csv, CoaImport::cols(), CoaImport::aliases());
}

test('Version 57: Chart of Accounts > Import reads the accounts of a file in its own words and shows the problems of each line before anything is saved', function () {
    $csv = "Account Code,Account Title,Account Type,Parent Code,Normal,With SL,Active,Remarks\n"
        . "9100,V57 Loans Receivable,Current Asset,,,y,yes,Loans to members\n"
        . "9101,V57 Loans Receivable - Regular,Other Current Asset,9100,,,,\n"
        . "9102,V57 Loans Receivable - Emergency,Other Current Asset,V57 Loans Receivable,,,,\n"
        . "9150,V57 Allowance for Doubtful Loans,Other Current Asset,,Cr,,,\n"
        . "9160,V57 Strange,Assets Galore,,,,,\n"
        . "9101,V57 Duplicate,Other Current Asset,,,,,\n"
        . "9170,V57 Interest Income,Income,9100,,,,\n"
        . "9180,V57 Seminars,Expense,9999,,,,\n"
        . "9190,V57 Deposits,Other Current Liability,,,Maybe,,\n"
        . "9195,V57 Under a bad line,Other Current Asset,9160,,,,\n"
        . "9196,V57 Under a code twice,Other Current Asset,9101,,,,\n"
        . ",V57 No code,Expense,,,,,\n";
    $before = (int)DB::value('SELECT COUNT(*) FROM accounts');
    [$rows, $sum, $general] = CoaImport::check(v57Coa($csv), ['existing' => 'update']);
    eq((int)DB::value('SELECT COUNT(*) FROM accounts'), $before, 'checking saves nothing');
    $by = [];
    foreach ($rows as $r) {
        $by[$r['_line']] = $r;
    }
    eq([$by[2]['type'], $by[2]['with_sl'], $by[2]['is_active'], $by[2]['description'], $by[2]['_notes']], ['Other Current Asset', 'Yes', 'Yes', 'Loans to members', ['Type "Current Asset" read as Other Current Asset.']],
        'the columns by their other names; a type, Yes and No in their usual words, written as the program writes them');
    eq([$by[5]['normal_balance'], $by[3]['_errors'], $by[4]['parent'], $by[4]['_errors']], ['Credit', ['The code 9101 is on 2 lines (3, 7): correct or remove all but one.'], '9100', []],
        'Cr is Credit; the same code twice is a problem on both lines; a main account named by its name');
    eq($by[6]['_errors'], ['"Assets Galore" is not an account type. Choose one of Account Types (Bank, Accounts Receivable, Other Current Asset, Fixed Asset, Expense ...).'], 'an unknown type');
    eq($by[8]['_errors'], ['A sub-account is under a main account of its category: 9100 is Asset, this account Income.'], 'a sub-account under a main account of another category');
    eq($by[9]['_errors'], ['No account on the chart or in the file has the code "9999".'], 'a main account that is nowhere');
    eq($by[10]['_errors'], ['With SL is Yes or No.'], 'With SL not Yes or No');
    eq($by[11]['_errors'], ['The main account 9160 (line 6) has a problem: correct it first.'], 'a sub-account under a line with a problem waits for it');
    eq($by[12]['_errors'], ['The code 9101 is on more than one line of the file: correct those lines first.'], 'a sub-account under a code that is on two lines');
    eq($by[13]['_errors'], ['Enter the account code.'], 'no code');
    eq([$sum['rows'], $sum['bad'], $sum['new']], [12, 9, 3], 'twelve lines, nine with a problem, three new accounts ready');
    eq(CsvReview::named($by[8]['_errors'], CoaImport::cols(), $by[8]['_cells']), ['parent' => true, 'type' => true], 'the cells a problem names are marked in red');
    // corrected and removed as the review does, then checked again
    $fixed = array_values(array_filter(v57Coa($csv), fn($r) => !in_array($r['_line'], [7, 12, 13], true)));
    foreach ($fixed as &$r) {
        match ($r['_line']) {
            6 => $r['type'] = 'Other Asset', 8 => $r['parent'] = '', 9 => $r['parent'] = '', 10 => $r['with_sl'] = 'No', default => null,
        };
    }
    unset($r);
    [, $sum2] = CoaImport::check($fixed, ['existing' => 'update']);
    eq([$sum2['bad'], $sum2['new']], [0, 9], 'corrected and removed: every line ready');
});

test('Version 57: Chart of Accounts > Import saves every account or none (main accounts first); an account on the chart is updated from its line or left as it is; the rules of the account form', function () {
    $csv = "code,name,type,parent,normal_balance,with_sl,is_active,description,bank_account_no\n"
        . "9201,V57 Sub first in the file,Expense,9200,,,,,\n"
        . "9200,V57 Main after its sub,Expense,,,,,Main,\n"
        . "9210,V57 Bank,Bank,,,,,,1234-5678\n";
    $before = (int)DB::value('SELECT COUNT(*) FROM accounts');
    $r = CoaImport::post(v57Coa($csv), ['existing' => 'update'], 'v57.csv', 'coa.accounts');
    eq([$r['added'], $r['updated']], [3, 0], 'three accounts added');
    $a = DB::row("SELECT a.*, p.code AS parent_code FROM accounts a LEFT JOIN accounts p ON p.id = a.parent_id WHERE a.code = '9201'");
    eq([$a['parent_code'], $a['account_type'], $a['category'], $a['normal_balance'], (int)$a['is_active'], (int)$a['with_sl']], ['9200', 'Expense', 'Expense', 'Debit', 1, 0],
        'a sub-account above its main account in the file: saved after it, under it; the normal balance of its type; active; no SL');
    eq((string)DB::value("SELECT bank_account_no FROM accounts WHERE code = '9210'"), '1234-5678', 'the bank account no.');
    eq((int)DB::value("SELECT COUNT(*) FROM audit_trail WHERE action = 'import_create' AND record_type = 'account' AND record_ref LIKE '92%V57%'"), 3, 'each account in the audit trail');
    // a file with a problem saves nothing
    $bad = "code,name,type\n9220,V57 Good,Expense\n9221,V57 Bad,Nonsense\n";
    refuses(fn() => CoaImport::post(v57Coa($bad), ['existing' => 'update'], 'v57.csv', 'coa.accounts'), 'have a problem', 'a file with a line in red is not saved');
    eq((int)DB::value("SELECT COUNT(*) FROM accounts WHERE code = '9220'"), 0, '...not even its good lines');
    // the accounts on the chart: updated (blank cells keep what is saved), or left as they are
    $upd = "code,name,type,description\n9200,V57 Main renamed,Expense,\n9210,V57 Bank,Bank,Our bank\n";
    [$rows] = CoaImport::check(v57Coa($upd), ['existing' => 'update']);
    eq(array_map(fn($r) => strip_tags(html_entity_decode((string)$r['_info'])), $rows), ['On the chart as 9200 V57 Main after its sub: its name will be updated.', 'On the chart as 9210 V57 Bank: its description will be updated.'],
        'what will change on each account of the chart');
    $r = CoaImport::post(v57Coa($upd), ['existing' => 'skip'], 'v57.csv', 'coa.accounts');
    eq([$r['added'], $r['updated'], $r['skipped'], (string)DB::value("SELECT name FROM accounts WHERE code = '9200'")], [0, 0, 2, 'V57 Main after its sub'], '"Leave it as it is": nothing changes');
    $r = CoaImport::post(v57Coa($upd), ['existing' => 'update'], 'v57.csv', 'coa.accounts');
    $m = DB::row("SELECT * FROM accounts WHERE code = '9200'");
    eq([$r['updated'], $m['name'], $m['description'], (string)DB::value("SELECT description FROM accounts WHERE code = '9210'"), (string)DB::value("SELECT bank_account_no FROM accounts WHERE code = '9210'")],
        [2, 'V57 Main renamed', 'Main', 'Our bank', '1234-5678'], '"Update it from the file": the name and description changed; the blank cells kept what was saved');
    // the rules of the account form
    $sys = DB::row('SELECT * FROM accounts WHERE is_system = 1 ORDER BY code LIMIT 1');
    $other = $sys['category'] === 'Expense' ? 'Income' : 'Expense';
    $expMain = (string)DB::value("SELECT code FROM accounts WHERE category = 'Expense' AND parent_id IS NULL AND code NOT LIKE '92%' ORDER BY code LIMIT 1");
    $rules = "code,name,type,parent,is_active\n"
        . $sys['code'] . ',' . $sys['name'] . ',' . $other . ",,\n"
        . $sys['code'] . "x,V57 x,Expense,,\n"
        . "9200,V57 Main renamed,Expense," . $expMain . ",\n";
    [$rows] = CoaImport::check(v57Coa($rules), ['existing' => 'update']);
    eq($rows[0]['_errors'], ['The account ' . $sys['code'] . ' is one the modules post to: it keeps a type that behaves as ' . $sys['account_type'] . '.'], 'an account the modules post to keeps its base type');
    eq($rows[2]['_errors'], ['The account 9200 has sub-accounts of its own: it cannot be a sub-account too.'], 'an account with sub-accounts is not made a sub-account (one level)');
    [$rows] = CoaImport::check(v57Coa("code,name,type,is_active\n" . $sys['code'] . ',' . $sys['name'] . ',' . AccountTypes::nameOf($sys) . ",No\n"), ['existing' => 'update']);
    eq($rows[0]['_errors'], ['The account ' . $sys['code'] . ' is one the modules post to: it stays active.'], 'an account the modules post to stays active');
    $used = (string)DB::value('SELECT a.code FROM accounts a JOIN journal_lines j ON j.account_id = a.id WHERE a.is_system = 0 AND a.parent_id IS NULL ORDER BY a.code LIMIT 1');
    $u = DB::row('SELECT * FROM accounts WHERE code = :c', ['c' => $used]);
    [$rows] = CoaImport::check(v57Coa("code,name,type\n" . $used . ',' . $u['name'] . ',' . ($u['category'] === 'Expense' ? 'Income' : 'Expense') . "\n"), ['existing' => 'update']);
    check(str_contains(implode(' ', $rows[0]['_errors']), 'has transactions: it cannot be moved to another category'), 'an account with transactions keeps its category');
    eq((int)DB::value('SELECT COUNT(*) FROM accounts') - $before, 3, 'only the three accounts were added');
    Accts::reset();
});

test('Version 57: Chart of Accounts > Import reads the chart\'s own Export back unchanged, and an Excel workbook as a CSV file', function () {
    // the Export of the chart, as modules/coa/accounts.php writes it
    $fh = fopen('php://temp', 'w+');
    fputcsv($fh, ['Code', 'Name', 'Type', 'Behaves as', 'Category', 'Normal balance', 'Parent', 'With SL', 'Balance', 'Total with sub-accounts', 'Active'], ',', '"', '');
    $n = 0;
    foreach (DB::all('SELECT a.*, p.code AS parent_code FROM accounts a LEFT JOIN accounts p ON p.id = a.parent_id ORDER BY a.code') as $a) {
        fputcsv($fh, [$a['code'], $a['name'], AccountTypes::nameOf($a), $a['account_type'], $a['category'], $a['normal_balance'], $a['parent_code'], (int)$a['with_sl'] ? 'Yes' : '', '0', '', $a['is_active']], ',', '"', '');
        $n++;
    }
    rewind($fh);
    [$rows, $sum] = CoaImport::check(v57Coa((string)stream_get_contents($fh)), ['existing' => 'update']);
    eq([$sum['rows'], $sum['bad'], $sum['same'], $sum['update'], $sum['new']], [$n, 0, $n, 0, 0], 'every account of the chart read back: the same, nothing to change');
    // an Excel workbook: its first worksheet, read as a CSV file would be
    $sheet = [array_keys(CoaImport::cols())];
    foreach (CoaImport::samples() as $s) {
        $sheet[] = array_values($s);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'coa') . '.xlsx';
    (new Xlsx())->sheet('Chart', $sheet)->save($tmp);
    $rows = CsvReview::readText(XlsxReader::csvText($tmp), CoaImport::cols(), CoaImport::aliases());
    @unlink($tmp);
    eq([count($rows), $rows[1]['code'], $rows[1]['parent'], $rows[3]['normal_balance']], [count(CoaImport::samples()), '1001', '1000', 'Credit'], 'the template\'s rows read from an Excel workbook');
    [, $sum] = CoaImport::check($rows, ['existing' => 'skip']);
    eq($sum['bad'], 0, 'the template has no problem');
});

// ======================================================================================== the receipts

test('Version 57: the water bill / collection receipt prints on Letter bond paper, portrait, two copies one above the other, black and bold, with no header or footer of the browser', function () {
    $col = WaterReceiptLayout::defaultLayout('collection');
    eq([$col['setup']['page_width'], $col['setup']['page_height'], $col['setup']['copies'], $col['setup']['copy_left'], $col['setup']['copy_top']], [12240, 15840, 2, 0, 7920],
        'the pre-printed water bill: Letter 8.5 x 11 in, portrait, the second copy 5.5 in below the first');
    eq([$col['setup']['font'], $col['setup']['font_size_pt'], $col['setup']['margin_top'], $col['setup']['margin_left']], ['mono_bold', '11.0', 0, 0], 'in bold Courier New, 11 pt');
    eq(WaterReceiptLayout::warnings('collection', $col), [], 'nothing to warn about');
    $s = WaterReceiptLayout::sample('collection');
    $html = WaterReceiptLayout::render('collection', $col, $s['values'], $s['lines']);
    check(str_contains($html, 'wrl-bold') && substr_count($html, 'data-f="account"') === 2, 'the entries in bold, on both copies');
    $css = WaterReceiptLayout::css($col);
    check(str_contains($css, '@page{size:8.5in 11in;margin:0}') && str_contains($css, 'color:#000'), 'printed at the size of the paper with no margins of the browser (so no header or footer), in black');
    // the former default (22 x 10 cm) saved as it was is read as the default of now; a layout of the user's own stays
    $former = WaterReceiptLayout::formerCollectionLayout();
    Settings::set('water_receipt_layout_collection', json_encode(WaterReceiptLayout::clean('collection', $former)));
    check(!WaterReceiptLayout::isCustom('collection') && WaterReceiptLayout::get('collection') === WaterReceiptLayout::defaultLayout('collection'), 'a saved layout that is the former default is the default');
    $mine = $former;
    $mine['setup']['margin_top'] = 144;
    Settings::set('water_receipt_layout_collection', json_encode($mine));
    check(WaterReceiptLayout::isCustom('collection') && WaterReceiptLayout::get('collection')['setup']['page_width'] === 12474, 'a layout the cooperative changed stays as it is');
    check((bool)array_filter(WaterReceiptLayout::warnings('collection', WaterReceiptLayout::get('collection')), fn($w) => str_contains($w, 'former 22 x 10 cm form')), '...with a word that the default now prints on Letter paper');
    Settings::set('water_receipt_layout_collection', '');
    eq(WaterReceiptLayout::clean('collection', ['setup' => ['font' => 'mono_bold'], 'header' => [], 'body' => [], 'footer' => []])['setup']['font'], 'mono_bold', 'the bold dot-matrix font is a font of the layouts');
    // the plain-paper receipt: its page has no margins of the browser and its text is black
    $paper = (string)file_get_contents(APP_ROOT . '/water/resources/css/cowassco_measured_receipt.css');
    check(str_contains($paper, '@page { size: 8.5in 11in; margin: 0; }'), 'the plain-paper receipt: Letter, portrait, no margins of the browser');
    check(!preg_match('/color:\s*(?!#000|var\(--wb-ink\)|#111827|#fff|#d1d5db|#991b1b|inherit)#[0-9a-f]{3,6}/i', (string)preg_replace('/\.receipt-toolbar[^{]*\{[^}]*\}/', '', $paper)), '...every text of the receipt black');
});
