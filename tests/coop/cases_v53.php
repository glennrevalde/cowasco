<?php
declare(strict_types=1);

/**
 * Version 53: the accounting update (G-SMaRT Accounting versions 26 to 34) on the cooperative and water program.
 * The accounting features come in as they are; what the cooperative and the water module already did to the
 * accounting stays: the member's price level (own, else the members' level of the line of business), the
 * inter-unit elimination of the statements, the account types of the cash flow statement, the time filter of the
 * water collection report, and the bills the water module posts from its own records. The pages are checked over
 * HTTP by the crawl (docs/TEST_EVIDENCE.md, version 53).
 */

/** A report run as its page runs it: the filters read from the query string, then the report's own hook */
function v53_report(string $key, array $get = []): array
{
    $saved = $_GET;
    $_GET = $get;
    try {
        $def = Report::get($key);
        check($def !== null, "the report $key is defined");
        $f = Report::filters($def);
        return [$def['run']($f), $f, $def];
    } finally {
        $_GET = $saved;
    }
}

/** The rows of a report with an amount in the column, by name */
function v53_by_name(array $rows, string $col): array
{
    $out = [];
    foreach ($rows as $r) {
        if (isset($r['name']) && array_key_exists($col, $r) && $r[$col] !== '' && $r[$col] !== null) {
            $out[(string)$r['name']] = r2s((string)$r[$col]);
        }
    }
    return $out;
}

const V53_PAGES = ['rpt.pos_ending_inventory', 'rpt.sales_item_group', 'rpt.pos_sales_item_group', 'rpt.cmp_is_month', 'rpt.cmp_is_quarter',
    'rpt.cmp_is_year', 'rpt.cmp_is_custom', 'rpt.cmp_bs_month', 'rpt.cmp_bs_quarter', 'rpt.cmp_bs_year', 'rpt.cmp_bs_custom', 'rpt.avg_cost_changes',
    'rpt.trial_balance_class', 'rpt.cash_flow_class'];

test('Version 53: the accounting update is installed; its migration runs twice without a change; the program asks for no other update', function () {
    Schema::flush();
    eq(Schema::pending(), [], 'no database update pending after the migration');
    check(ItemGroups::ready(), 'item groups: the columns and the table are there');
    check(Clearing::ticksReady(), 'Make Deposits: the table of the receipts ticked to settle is there');
    foreach (['received_via' => 'transactions', 'group_variance_account_id' => 'items', 'group_role' => 'transaction_lines'] as $col => $table) {
        check((bool)DB::value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c", ['t' => $table, 'c' => $col]),
            "$table.$col is there");
    }
    eq((int)DB::value('SELECT COUNT(*) FROM pages WHERE page_key IN (' . implode(',', array_map(fn($k) => DB::pdo()->quote($k), V53_PAGES)) . ')'), 14, 'the fourteen new report pages');
    foreach (V53_PAGES as $k) {
        check(Access::pageDef($k) !== null, "$k is in the menu registry");
    }
    $va = Accts::get('group_price_variance');
    eq((string)DB::value('SELECT name FROM accounts WHERE id = :a', ['a' => $va]), 'Item Group Price Variance', 'the module default account Item Group Price Variance');
    eq((string)DB::value("SELECT name FROM modules WHERE code = 'coa'"), 'Financial', 'the menu is still called Financial');

    // run again: nothing doubles, nothing moves
    $before = [DB::value('SELECT COUNT(*) FROM pages'), DB::value('SELECT COUNT(*) FROM role_page_access'), DB::value("SELECT COUNT(*) FROM accounts WHERE name = 'Item Group Price Variance'"),
        DB::value('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock'), DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines')];
    run_sql_file(DB::pdo(), APP_ROOT . '/database/migrations/2027_04_04_accounting_update.sql');
    $after = [DB::value('SELECT COUNT(*) FROM pages'), DB::value('SELECT COUNT(*) FROM role_page_access'), DB::value("SELECT COUNT(*) FROM accounts WHERE name = 'Item Group Price Variance'"),
        DB::value('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock'), DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines')];
    eq($after, $before, 'run twice: the same pages, page access, account, stock and ledger');
    Accts::reset();
    Access::reset();
});

test('Version 53: an item group sold to a member: the member\'s price level on the group price and on its items, the variance to Item Group Price Variance, the group sales recorded', function () {
    $grp = (int)DB::value("SELECT id FROM items WHERE code = 'GRP-WFH'");
    check($grp > 0, 'the sample item group GRP-WFH');
    DB::update('items', ['sales_price' => 40000], 'id = :i', ['i' => $grp]);
    $members = ItemGroups::members($grp);
    eq(array_column($members, 'code'), ['BAG-001', 'LAP-001', 'MOU-001'], 'its items');
    $vendor = (int)DB::value('SELECT id FROM vendors ORDER BY id LIMIT 1');
    pt_bill($vendor, '2026-10-05', array_map(fn($m) => ['item_id' => (int)$m['id'], 'qty' => 3, 'rate' => (float)$m['cost']], $members));

    $mid = newMember('Ramos', 'Teodoro', ['mobile' => '09171112233']);
    $cid = (int)MemberDefaults::customerOf(Coop::member($mid))['id'];
    $lvl = DB::insert('price_levels', ['name' => 'Members less 10% (v53)', 'adjust_pct' => -10, 'is_active' => 1]);
    Lookup::reset();
    DB::tx(fn() => MemberDefaults::saveCustomer($mid, ['price_level_id' => $lvl], '', 'coop.member'));

    eqm(ItemGroups::price($grp, $cid), '36000.00', 'the group price with the member\'s level: 40,000.00 less 10%');
    eqm(Lookup::priceFor($grp, $cid), '36000.00', '...the price every sales form and the POS look up');
    $cons = '0';
    foreach ($members as $m) {
        $cons = bcadd($cons, bcmul((string)round((float)$m['sales_price'] * 0.9, 2), '1', 2), 2);
    }
    eqm(ItemGroups::consolidated($grp, $cid), $cons, 'the consolidated price: the items at the member\'s prices');

    // the members' level of the line of business, when the member has none of their own
    if (BusinessUnits::ready()) {
        DB::run('UPDATE customers SET price_level_id = NULL WHERE id = :c', ['c' => $cid]);
        $units = DB::pairs('SELECT id, member_price_level_id FROM business_units');
        DB::run('UPDATE business_units SET member_price_level_id = :p', ['p' => $lvl]);
        BusinessUnits::reset();
        eqm(ItemGroups::price($grp, $cid), '36000.00', 'no level of the member\'s own: the members\' level of the line of business');
        foreach ($units as $u => $p) {
            DB::update('business_units', ['member_price_level_id' => $p], 'id = :id', ['id' => $u]);
        }
        BusinessUnits::reset();
        DB::run('UPDATE customers SET price_level_id = :p WHERE id = :c', ['p' => $lvl, 'c' => $cid]);
    }

    $inv = pt_sale('invoice', $cid, '2026-10-06', [['item_id' => $grp, 'qty' => 1]]);
    $lines = Txn::lines($inv);
    eq(count(array_filter($lines, fn($l) => ($l['group_role'] ?? '') === 'member')), 3, 'saved as the three items of the group');
    $var = array_values(array_filter($lines, fn($l) => ($l['group_role'] ?? '') === 'variance'));
    eq(count($var), 1, 'and one price variance line');
    eqm($var[0]['amount'], bcsub('36000', $cons, 2), 'the variance: the group price less the consolidated price');
    eq((int)$var[0]['account_id'], Accts::get('group_price_variance'), 'posted to Item Group Price Variance');
    eqm(Txn::load($inv)['subtotal'], '36000.00', 'the invoice sells the group at the member\'s group price');
    $igs = DB::row('SELECT * FROM item_group_sales WHERE txn_id = :t', ['t' => $inv]);
    check($igs !== null, 'the group sold is recorded (Sales by Item Group)');
    eqm($igs['amount'], '36000.00', '...its amount');
    eqm($igs['consolidated'], $cons, '...its consolidated price');
    eq((int)$igs['customer_id'], $cid, '...for the member\'s customer record');
    $varGl = (string)DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE txn_id = :t AND account_id = :a', ['t' => $inv, 'a' => Accts::get('group_price_variance')]);
    eqm($varGl, bcsub($cons, '36000', 2), 'the ledger: the variance on the price variance account');
    eq((int)DB::value('SELECT COUNT(*) FROM inventory_movements WHERE txn_id = :t', ['t' => $inv]), 3, 'each item of the group leaves the stock');
    check((float)DB::value("SELECT COALESCE(SUM(debit), 0) FROM journal_lines WHERE txn_id = :t AND account_id = :a", ['t' => $inv, 'a' => Accts::get('cogs')]) > 0, 'with its cost of goods sold');
    assertLedgerBalanced('item group invoice');

    // the report
    [$r] = v53_report('sales_item_group', ['dates' => 'custom', 'from' => '2026-10-06', 'to' => '2026-10-06', 'group_id' => (string)$grp]);
    $tot = $r['totals'] ?? [];
    eqm($tot['amount'] ?? 0, '36000.00', 'Sales by Item Group shows the sale');

    // the document shown by its groups
    $shown = ItemGroups::collapse($lines);
    $g = array_values(array_filter($shown, fn($l) => !empty($l['_group'])));
    eq(count($g), 1, 'shown as one group line');
    eqm($g[0]['amount'], '36000.00', '...at the group\'s amount');

    // a void takes the group sale out of the report
    Txn::void($inv, 'Wrong customer');
    [$r] = v53_report('sales_item_group', ['dates' => 'custom', 'from' => '2026-10-06', 'to' => '2026-10-06', 'group_id' => (string)$grp]);
    eqm($r['totals']['amount'] ?? 0, '0', 'voided: no longer counted');
});

test('Version 53: an inventory part with no unit cost is not sold from a sales form; the bills the water module posts from its own records keep their rules', function () {
    $item = DB::insert('items', ['item_type' => 'inventory', 'code' => 'NOCOST-53', 'name' => 'Fitting with no cost', 'description' => 'Fitting with no cost', 'unit' => 'pc',
        'sales_price' => 75, 'cost' => 0, 'avg_cost' => 0, 'income_account_id' => Accts::get('sales_income'), 'cogs_account_id' => Accts::get('cogs'),
        'asset_account_id' => Accts::get('inventory_asset'), 'is_active' => 1]);
    $cust = (int)DB::value('SELECT id FROM customers WHERE is_active = 1 ORDER BY id LIMIT 1');
    refuses(fn() => pt_sale('invoice', $cust, '2026-10-07', [['item_id' => $item, 'qty' => 1, 'rate' => 75]]), 'no unit cost', 'an invoice is refused');
    refuses(fn() => pt_sale('sales_receipt', $cust, '2026-10-07', [['item_id' => $item, 'qty' => 1, 'rate' => 75]]), 'no unit cost', 'and a cash sale');

    // a water bill with a material line goes through as before (WaterPosting passes skip_policy)
    $h = ['txn_date' => '2026-10-07', 'customer_id' => $cust, 'site_id' => pt_site(), 'account_id' => Accts::get('ar'), 'due_date' => '2026-10-07'];
    $id = Txn::save('invoice', $h, [['line_kind' => 'item', 'item_id' => $item, 'description' => 'Fitting', 'qty' => 1, 'rate' => 75, 'amount' => 75, 'tax_amount' => 0]],
        null, ['skip_policy' => true]);
    check($id > 0, 'a bill made by the water module with the same item is posted');
    Txn::void($id, 'Test only');

    // switched off: the sale goes through
    Settings::set('block_zero_cost_sales', '0');
    try {
        $ok = pt_sale('invoice', $cust, '2026-10-07', [['item_id' => $item, 'qty' => 1, 'rate' => 75]]);
        check($ok > 0, 'with "Block sales of items with no unit cost" off, it is sold');
        Txn::void($ok, 'Test only');
    } finally {
        Settings::set('block_zero_cost_sales', '1');
    }
    assertLedgerBalanced('no-cost checks');
});

test('Version 53: the statements by class add up to the plain statements; the cash flow statements follow the account types and the comparative income statement the inter-unit elimination', function () {
    $from = '2026-01-01';
    $to = '2026-12-31';
    // Trial Balance by Class: its Total columns are the Trial Balance
    [$tb] = v53_report('trial_balance', ['asof' => $to]);
    [$tbc] = v53_report('trial_balance_class', ['asof' => $to]);
    eqm($tbc['totals']['debit'], (string)$tb['totals']['debit'], 'Trial Balance by Class: total debit = Trial Balance');
    eqm($tbc['totals']['credit'], (string)$tb['totals']['credit'], '...total credit = Trial Balance');
    [$tbn] = v53_report('trial_balance_class', ['asof' => $to, 'net_only' => '1']);
    check(count($tbn['columns']) < count($tbc['columns']), 'One column per class: a single column for each class');

    // Statement of Cash Flows by Class: its Total is the Statement of Cash Flows
    [$cf] = v53_report('cash_flow', ['dates' => 'custom', 'from' => $from, 'to' => $to]);
    [$cfc] = v53_report('cash_flow_class', ['dates' => 'custom', 'from' => $from, 'to' => $to]);
    $plain = v53_by_name($cf['rows'], 'amount');
    $total = v53_by_name($cfc['rows'], 'total');
    foreach (['Net Income', 'Net cash provided by operating activities', 'Net cash provided by investing activities', 'Net cash provided by financing activities',
              'NET CASH INCREASE (DECREASE) FOR THE PERIOD', 'Cash at beginning of period', 'CASH AT END OF PERIOD'] as $line) {
        eqm($total[$line] ?? '0', $plain[$line] ?? '0', "Cash Flows by Class, Total: $line = Statement of Cash Flows");
    }
    foreach ($plain as $name => $v) {
        if (isset($total[$name])) {
            eqm($total[$name], $v, "...and the line $name");
        }
    }
    check(str_contains($cf['notes'], 'account type'), 'the plain statement still names the account types (Financial > Account Types)');
    check(str_contains($cfc['notes'], 'account type'), 'and so does the statement by class');
    check(!str_contains($cf['notes'] . $cfc['notes'], 'Does not tie') && !str_contains($cfc['notes'], 'total does not tie'), 'both tie to the cash accounts');

    // the cash accounts: the account types counted as cash, Bank and the user's own (Cash)
    $cashTypes = DB::column("SELECT name FROM account_types WHERE cash_flow = 'cash'");
    foreach (fin_cash_accounts() as $aid) {
        if ($aid) {
            $a = DB::row('SELECT * FROM accounts WHERE id = :a', ['a' => $aid]);
            check(in_array(AccountTypes::forAccount($a)['name'], $cashTypes, true) || in_array(AccountTypes::forAccount(AccountTypes::mainOf($a, array_column(fin_accounts(), null, 'id')))['name'], $cashTypes, true),
                'cash account ' . $a['code'] . ' is of a type counted as cash');
        }
    }

    // Comparative Income Statement by Month: its Total is the Income Statement of the range
    [$is] = v53_report('income_statement', ['dates' => 'custom', 'from' => $from, 'to' => $to]);
    [$cmp] = v53_report('cmp_is_month', ['dates' => 'custom', 'from' => $from, 'to' => $to]);
    $isNet = null;
    foreach ($is['rows'] as $r) {
        if (($r['name'] ?? '') === 'NET INCOME') {
            $isNet = $r['amount'] ?? $r['total'] ?? null;
        }
    }
    $cmpNet = null;
    foreach ($cmp['rows'] as $r) {
        if (($r['name'] ?? '') === 'NET INCOME') {
            $cmpNet = $r['total'] ?? null;
        }
    }
    check($isNet !== null && $cmpNet !== null, 'both show the net income');
    eqm($cmpNet, (string)$isNet, 'Comparative Income Statement by Month, Total = Income Statement');
    [$bs] = v53_report('cmp_bs_quarter', ['dates' => 'custom', 'from' => $from, 'to' => $to]);
    check(count($bs['columns']) >= 5, 'Comparative Balance Sheet by Quarter: a column per quarter');
});

test('Version 53: the report dates: a time after each date on the accounting reports, the date-time boxes of the water collection report unchanged', function () {
    // the water collection report: one box with a date and a time ('range_time'), as before
    $saved = $_GET;
    try {
        $_GET = ['dates' => 'custom', 'from' => '2026-10-01T08:00', 'to' => '2026-10-01T17:30'];
        $f = Report::filters(Report::get('water_collection_summary'));
        eq([$f['from'], $f['from_dt'], $f['to_dt']], ['2026-10-01', '2026-10-01 08:00:00', '2026-10-01 17:30:59'], 'water collection: the moments of the two boxes');
        check(empty($f['timed']), '...not the accounting reports\' time boxes');
        // an accounting report with times ('times' => true): a date box and a time box each
        $_GET = ['dates' => 'custom', 'from' => '2026-10-01', 'from_time' => '08:01', 'to' => '2026-10-01', 'to_time' => '14:00'];
        $f = Report::filters(Report::get('pos_ending_inventory'));
        eq([$f['from_dt'], $f['to_dt'], $f['timed']], ['2026-10-01 08:01:00', '2026-10-01 14:00:59', true], 'Ending Inventory - POS Cashier: from the start of 08:01 to the end of 14:00');
        $_GET = ['dates' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-02'];
        $f = Report::filters(Report::get('pos_ending_inventory'));
        eq([$f['from_dt'], $f['to_dt'], $f['timed']], ['2026-10-01 00:00:00', '2026-10-02 23:59:59', false], 'no time: whole days');
    } finally {
        $_GET = $saved;
    }
    [$r] = v53_report('pos_ending_inventory', ['dates' => 'today']);
    check(isset($r['rows']), 'Ending Inventory - POS Cashier runs');
    [$r] = v53_report('avg_cost_changes', ['dates' => 'custom', 'from' => '2026-01-01', 'to' => '2026-12-31']);
    check(isset($r['rows']), 'Average Cost Changes runs');
    [$r] = v53_report('pos_sales_item_group', ['dates' => 'today']);
    check(isset($r['rows']), 'POS Sales by Item Group runs');
});

test('Version 53: the Inventory Center history: on one date the stock received before the stock sold; the balance and the average after each line in that order', function () {
    $item = DB::insert('items', ['item_type' => 'inventory', 'code' => 'ORDER-53', 'name' => 'Valve 1/2"', 'description' => 'Valve 1/2"', 'unit' => 'pc',
        'sales_price' => 150, 'cost' => 100, 'avg_cost' => 0, 'income_account_id' => Accts::get('sales_income'), 'cogs_account_id' => Accts::get('cogs'),
        'asset_account_id' => Accts::get('inventory_asset'), 'is_active' => 1]);
    $vendor = (int)DB::value('SELECT id FROM vendors ORDER BY id LIMIT 1');
    $cust = (int)DB::value('SELECT id FROM customers WHERE is_active = 1 ORDER BY id LIMIT 1');
    pt_bill($vendor, '2026-10-08', [['item_id' => $item, 'qty' => 10, 'rate' => 100]]);
    // on the 9th the sale is entered before the delivery of the same day
    $sale = pt_sale('invoice', $cust, '2026-10-09', [['item_id' => $item, 'qty' => 12, 'rate' => 150]]);
    $bill = pt_bill($vendor, '2026-10-09', [['item_id' => $item, 'qty' => 10, 'rate' => 130]]);
    $h = Stock::history($item);
    $first = (int)$h[0]['txn_id'];
    // the delivery of the 9th has two lines: the 10 received, and the 2 sold below zero settled at the cost received
    // (60.00 to cost of goods sold, so the average left is the cost received)
    eq(array_map(fn($m) => (int)$m['txn_id'], $h), [$first, $bill, $bill, $sale], 'the history: the delivery of the 9th before the sale of the 9th');
    eq(array_map(fn($m) => (float)$m['balance'], $h), [10.0, 20.0, 20.0, 8.0], 'the balance after each line: 10, 20, 8 (never below zero)');
    eqm((string)DB::value("SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE txn_id = :t AND memo LIKE 'Stock sold below zero%' AND account_id = :a", ['t' => $bill, 'a' => Accts::get('cogs')]),
        '60', 'the shortfall settled: 2 x (130 - 100) to cost of goods sold');
    $after = Stock::balancesAfter($item);
    eq(array_map(fn($m) => (float)$after[(int)$m['id']]['qty'], $h), [10.0, 20.0, 20.0, 8.0], 'the Inventory Center balances follow the same order');
    eqm($after[(int)$h[1]['id']]['avg'], '112', 'the average after the delivery: (1,000 + 1,300 - 60) / 20');
    eqm($after[(int)$h[3]['id']]['avg'], '130', 'after the sale: the cost received, (2,240 - 1,200) / 8');
    eqm((string)DB::value('SELECT avg_cost FROM items WHERE id = :i', ['i' => $item]), '130', 'the item\'s average cost agrees');
    eqm(Stock::onHand($item), '8', 'on hand: 8');
    // the sale was costed before the delivery of its own date: the data check lists the item, Rebuild Data replays it
    check(in_array('ORDER-53', array_column(Maintenance::outOfDateOrder(), 'code'), true), 'Verify Data lists the item as costed out of order');
    assertLedgerBalanced('Inventory Center order');
});
