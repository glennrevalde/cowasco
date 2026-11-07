<?php
declare(strict_types=1);

/**
 * Version 54 (workbook v19): the Loan Due panel of Water Billing > Collections (Cashier), the Member type filter of the
 * sales reports, and Sales by Customer Detail Group by Customer/Member Type. The pages are checked over HTTP by the
 * end-to-end run (section v54) and the crawl.
 */

/** A report run as its page runs it (filters from the query string) */
function v54_report(string $key, array $get): array
{
    $saved = $_GET;
    $_GET = $get;
    try {
        $def = Report::get($key);
        check($def !== null, "the report $key is defined");
        return $def['run'](Report::filters($def));
    } finally {
        $_GET = $saved;
    }
}

/** The detail lines of a sales report, as "customer amount" */
function v54_lines(array $r): array
{
    $out = [];
    foreach ($r['rows'] as $row) {
        if (isset($row['num'], $row['amount']) && ($row['num'] ?? '') !== '') {
            $out[] = $row['name'] . ' ' . r2s((string)$row['amount']);
        }
    }
    sort($out);
    return $out;
}

function v54_sorted(array $a): array
{
    sort($a);
    return $a;
}

/** A sale date nothing else uses */
function v54_date(): string
{
    foreach (['2026-11-27', '2026-11-28', '2026-11-29', '2026-12-03', '2026-12-04'] as $d) {
        if (!(int)DB::value("SELECT COUNT(*) FROM transactions WHERE txn_date = :d AND txn_type IN ('invoice','sales_receipt','pos_sale','credit_memo','pos_return')", ['d' => $d])) {
            return $d;
        }
    }
    throw new RuntimeException('no free sale date');
}

test('Version 54: the Member type filter of Sales by Customer Detail: all members, one member type, the non-members (with the walk-in sales)', function () {
    $date = v54_date();
    $GLOBALS['v54date'] = $date;
    $item = pt_item('V54-SVC', 'Delivery service (v54)', 'service', '100', '0', false);
    $regular = newMember('Bautista', 'Ramon');
    $assoc = newMember('Villareal', 'Lorna');
    $assocType = (int)DB::value("SELECT id FROM member_types WHERE code = 'ASI'");
    DB::run('UPDATE members SET member_type_id = :t WHERE id = :m', ['t' => $assocType, 'm' => $assoc]);
    $custOf = fn(int $m) => (int)MemberDefaults::customerOf(Coop::member($m))['id'];
    $retailType = (int)(DB::value("SELECT id FROM customer_types WHERE name = 'Retail'") ?: DB::insert('customer_types', ['name' => 'Retail']));
    $retail = DB::insert('customers', ['name' => 'Sunrise Sari-Sari Store (v54)', 'customer_type_id' => $retailType, 'is_active' => 1]);
    $plain = DB::insert('customers', ['name' => 'Dela Pena Hardware (v54)', 'is_active' => 1]);
    $GLOBALS['v54'] = ['regular' => $custOf($regular), 'assoc' => $custOf($assoc), 'retail' => $retail, 'plain' => $plain, 'item' => $item, 'assocType' => $assocType, 'retailType' => $retailType];
    pt_sale('invoice', $custOf($regular), $date, [['item_id' => $item, 'qty' => 1, 'rate' => 100]]);
    pt_sale('invoice', $custOf($assoc), $date, [['item_id' => $item, 'qty' => 2, 'rate' => 100]]);
    pt_sale('invoice', $retail, $date, [['item_id' => $item, 'qty' => 3, 'rate' => 100]]);
    pt_sale('sales_receipt', $plain, $date, [['item_id' => $item, 'qty' => 4, 'rate' => 100]]);
    // a walk-in cash sale (no customer)
    Txn::save('sales_receipt', ['txn_date' => $date, 'customer_id' => null, 'site_id' => pt_site(), 'account_id' => Accts::get('undeposited_funds'), 'payment_method_id' => pt_pm('Cash')],
        parse_item_lines([['item_id' => $item, 'qty' => 5, 'rate' => 100]], 'sales', []));

    $job = DB::insert('customers', ['name' => 'Bautista Farm Job (v54)', 'parent_id' => $custOf($regular), 'is_active' => 1]);
    $base = ['dates' => 'custom', 'from' => $date, 'to' => $date];
    $rName = DB::value('SELECT name FROM customers WHERE id = :c', ['c' => $custOf($regular)]);
    $aName = DB::value('SELECT name FROM customers WHERE id = :c', ['c' => $custOf($assoc)]);
    eq(v54_lines(v54_report('sales_customer_detail', $base)), v54_sorted([$aName . ' 200.00', 'Dela Pena Hardware (v54) 400.00', $rName . ' 100.00', 'Sunrise Sari-Sari Store (v54) 300.00', 'Walk-in 500.00']), 'no filter: the five sales');
    eq(v54_lines(v54_report('sales_customer_detail', $base + ['member_type' => 'members'])), v54_sorted([$aName . ' 200.00', $rName . ' 100.00']), 'All members: the two members\' sales');
    eq(v54_lines(v54_report('sales_customer_detail', $base + ['member_type' => (string)DB::value("SELECT id FROM member_types WHERE code = 'REG'")])), v54_sorted([$rName . ' 100.00']), 'Member type Regular');
    eq(v54_lines(v54_report('sales_customer_detail', $base + ['member_type' => (string)$assocType])), v54_sorted([$aName . ' 200.00']), 'Member type Associate - Individual');
    eq(v54_lines(v54_report('sales_customer_detail', $base + ['member_type' => 'non'])), v54_sorted(['Dela Pena Hardware (v54) 400.00', 'Sunrise Sari-Sari Store (v54) 300.00', 'Walk-in 500.00']), 'Non-members, the walk-in sale with them');
    eq(v54_lines(v54_report('sales_customer_detail', $base + ['member_type' => 'non', 'customer_type_id' => (string)$retailType])), v54_sorted(['Sunrise Sari-Sari Store (v54) 300.00']), 'with a customer type as well: both apply (no walk-in)');
    eq(v54_lines(v54_report('sales_customer_detail', $base + ['member_type' => 'junk'])), v54_lines(v54_report('sales_customer_detail', $base)), 'a value that is no member type is ignored');

    // a job of a member's customer is the member's (made before the first report: the ids are kept for the request)
    check(in_array($job, Report::customerIds(['member_type' => 'members']), true), 'a job under a member\'s customer counts as the member\'s');

    // the filter is shown wherever the customer type is, and named in the report's heading
    $def = Report::get('sales_customer_detail');
    check(Report::showsMemberType($def), 'Sales by Customer Detail shows the Member type filter');
    check(Report::showsMemberType(Report::get('sales_customer_summary')) && Report::showsMemberType(Report::get('ar_aging_summary')), '...as do the other reports with a Customer type filter');
    check(!Report::showsMemberType(Report::get('trial_balance')), 'not the reports without one');
    $f = Report::filters($def);
    $_GET = $base + ['member_type' => (string)$assocType];
    $f = Report::filters($def);
    $_GET = [];
    check(str_contains(Report::basis($def, $f), 'Member type Associate - Individual'), 'the heading names the member type');
    $opts = Report::memberTypeOptions();
    eq([array_key_first($opts), array_key_last($opts)], ['members', 'non'], 'the choices: All members, each member type, Non-members');
    assertLedgerBalanced('member type sales');
});

test('Version 54: Sales by Customer Detail Group by Customer/Member Type: members by member type, the others by customer type, a subtotal per customer and per type', function () {
    $date = $GLOBALS['v54date'];
    $v = $GLOBALS['v54'];
    $r = v54_report('sales_customer_type_detail', ['dates' => 'custom', 'from' => $date, 'to' => $date]);
    $groups = [];
    $subtotals = [];
    foreach ($r['rows'] as $row) {
        $cls = $row['_class'] ?? '';
        if ($cls === 'group-row') {
            $groups[] = $row['type'];
        } elseif ($cls === 'subtotal-row type-total') {
            $subtotals[$row['type']] = r2s((string)$row['amount']);
        }
    }
    $assocName = (string)DB::value('SELECT name FROM member_types WHERE id = :t', ['t' => $v['assocType']]);
    eq($groups, ['Regular members', $assocName . ' members', 'Retail customers', 'Customers with no type', 'Walk-in sales'],
        'the types in order: the member types (by their order), the customer types, no type, walk-in');
    eq($subtotals, ['Total Regular members' => '100.00', 'Total ' . $assocName . ' members' => '200.00', 'Total Retail customers' => '300.00', 'Total Customers with no type' => '400.00', 'Total Walk-in sales' => '500.00'],
        'a subtotal per type');
    eqm($r['totals']['amount'], '1500', 'the grand total');
    $plain = v54_report('sales_customer_detail', ['dates' => 'custom', 'from' => $date, 'to' => $date]);
    eqm($r['totals']['amount'], (string)$plain['totals']['amount'], '...that of Sales by Customer Detail for the same dates');
    $custRows = array_values(array_filter($r['rows'], fn($x) => ($x['_class'] ?? '') === 'subtotal-row indent-1'));
    eq(count($custRows), 5, 'a subtotal per customer as well');
    // the filters apply to it too
    $m = v54_report('sales_customer_type_detail', ['dates' => 'custom', 'from' => $date, 'to' => $date, 'member_type' => 'members']);
    eqm($m['totals']['amount'], '300', 'with All members: the two member types only');
    check(Access::pageDef('rpt.sales_customer_type_detail') !== null, 'the page is in the menu registry (Customers > Sales Reports)');
    eq((int)DB::value("SELECT COUNT(*) FROM pages WHERE page_key = 'rpt.sales_customer_type_detail'"), 1, 'and in the pages of the database');
    $roles = fn($k) => DB::column('SELECT a.role_id FROM role_page_access a JOIN pages p ON p.id = a.page_id WHERE p.page_key = :k AND a.allowed = 1 ORDER BY a.role_id', ['k' => $k]);
    eq($roles('rpt.sales_customer_type_detail'), $roles('rpt.sales_customer_detail'), 'given to the roles that open Sales by Customer Detail');
    $before = DB::value('SELECT COUNT(*) FROM role_page_access');
    run_sql_file(DB::pdo(), APP_ROOT . '/database/migrations/2027_04_11_sales_by_member_type.sql');
    eq(DB::value('SELECT COUNT(*) FROM role_page_access'), $before, 'its script runs again without a change');
    Schema::flush();
    eq(Schema::pending(), [], 'no database update pending');
});

test('Version 54: the loans due of a water-service account\'s member: principal, interest and penalty due by loan, as the Loan Collection form has them', function () {
    $l = lcLoan('Tajanlangit', 'Glenda', '24000');
    $l2 = lcLoan('Tajanlangit', 'Glenda B.', '12000');
    // both loans to one member: move the second to the first member
    DB::run('UPDATE loan_accounts SET member_id = :m WHERE id = :l', ['m' => $l['member'], 'l' => $l2['loan']]);
    $party = (int)DB::value('SELECT party_id FROM members WHERE id = :m', ['m' => $l['member']]);
    $water = wt_account(95401, 'Tajanlangit', 'Glenda', $party);
    $members = LoanTxn::membersOfWaterAccount($water);
    eq(array_map('intval', array_column($members, 'id')), [$l['member']], 'the water-service account\'s member (the same name)');
    $date = today();
    $s = LoanTxn::dueSummary(array_column($members, 'id'), $date);
    eq(count($s['loans']), 2, 'both open loans');
    $sum = ['principal' => '0', 'interest' => '0', 'penalty' => '0'];
    foreach ($s['loans'] as $row) {
        $loan = DB::row('SELECT * FROM loan_accounts WHERE id = :l', ['l' => $row['loan_id']]);
        $d = LoanTxn::dueDetails($loan, $date);
        eqm($row['principal'], $d['principal']['due'], 'loan ' . $row['loan_no'] . ': the principal due of the Loan Collection form');
        eqm($row['interest'], $d['interest']['due'], '...the interest due');
        eqm($row['penalty'], $d['penalty']['due'], '...the penalty due');
        eqm($row['total'], bcadd(bcadd($row['principal'], $row['interest'], 2), $row['penalty'], 2), '...and their total');
        check($row['name'] !== '' && $row['loan_no'] !== '', '...named by its loan product and number');
        foreach ($sum as $k => $x) {
            $sum[$k] = bcadd($x, $row[$k], 2);
        }
    }
    check(bccomp($s['totals']['principal'], '0', 2) > 0 && bccomp($s['totals']['interest'], '0', 2) > 0, 'amortizations have fallen due: principal and interest due');
    eqm($s['totals']['principal'], $sum['principal'], 'Principal: the total of the loans');
    eqm($s['totals']['interest'], $sum['interest'], 'Interest: the total of the loans');
    eqm($s['totals']['penalty'], $sum['penalty'], 'Penalties: the total of the loans');
    eqm($s['totals']['total'], bcadd(bcadd($sum['principal'], $sum['interest'], 2), $sum['penalty'], 2), 'Total');
    // as of the release date nothing is due yet
    $early = LoanTxn::dueSummary(array_column($members, 'id'), (string)DB::value('SELECT release_date FROM loan_accounts WHERE id = :l', ['l' => $l['loan']]));
    eqm($early['totals']['principal'], '0', 'as of the release date: no principal due');
    // nothing is collected or posted by looking
    $before = [DB::value('SELECT COUNT(*) FROM transactions'), DB::value('SELECT COUNT(*) FROM loan_txns'), DB::value('SELECT principal_balance FROM loan_accounts WHERE id = :l', ['l' => $l['loan']])];
    LoanTxn::dueSummary(array_column($members, 'id'), $date);
    eq([DB::value('SELECT COUNT(*) FROM transactions'), DB::value('SELECT COUNT(*) FROM loan_txns'), DB::value('SELECT principal_balance FROM loan_accounts WHERE id = :l', ['l' => $l['loan']])], $before, 'display only: nothing posted');
    // a water-service account with no member: no loans
    $other = wt_account(95402, 'Lim', 'Arnel', null);
    eq(LoanTxn::membersOfWaterAccount($other), [], 'a water-service account of a non-member has no member');
    eq(LoanTxn::dueSummary([], $date)['loans'], [], '...and no loans');
    // a paid-off loan is left out
    DB::run("UPDATE loan_accounts SET status = 'paid' WHERE id = :l", ['l' => $l2['loan']]);
    eq(count(LoanTxn::dueSummary(array_column($members, 'id'), $date)['loans']), 1, 'a paid loan is not listed');
    DB::run("UPDATE loan_accounts SET status = 'current' WHERE id = :l", ['l' => $l2['loan']]);
});
