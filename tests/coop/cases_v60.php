<?php
declare(strict_types=1);

/**
 * Version 60: the accounting update, the accounting side of the program brought up to G-SMaRT Accounting version 40
 * (its versions 35 to 40), with the cooperative's member links on the documents and the water billing link left as
 * they are:
 *  - v35/v36: a processing order whose outputs cost more than 2% off its inputs and labor is refused; a back-dated,
 *    edited, voided or deleted processing order has the later inventory transactions costed again in date order
 *    (a setting extends it to every inventory transaction or switches it off);
 *  - v36: a supplier on an expense line (Customer / Supplier), carried to the general ledger line;
 *  - v37/v38: "Add new" in the drop-downs (customers, suppliers, accounts, items), never a member;
 *  - v38: the Cash Count Sheet, the General Ledger's Cashier filter, the item group's price variance in the gross sales;
 *  - v39: Save as PDF; v40: files attached to transactions.
 * The pages are checked over HTTP (tests/water/e2e_http.php) and in the browser (docs/TEST_EVIDENCE.md).
 */

function v60Item(string $code, string $name, string $cost = '0', string $price = '0'): int
{
    return pt_item($code, $name, 'inventory', $price, $cost, false);
}

function v60Vendor(string $name): int
{
    return (int)DB::insert('vendors', ['name' => $name, 'is_active' => 1]);
}

/** A processing order saved as Disassemble / Process Items saves it (modules/inventory/process.php) */
function v60Process(string $date, array $inputs, array $outputs, string $method, array $costs = [], ?int $id = null): int
{
    [$in, $out, $cs] = Processing::cleanLines($inputs, $outputs, $costs, $method);
    [$lines, $est, $alloc] = Processing::orderLines($in, $out, $cs, $method);
    Processing::assertReconciled((float)$est['pool'], (float)$alloc['allocated'], (float)$est['materials'], (float)$est['extra']);
    return Txn::save(Processing::TXN_TYPE, ['txn_date' => $date, 'site_id' => pt_site(), 'process_type' => 'co_product', 'alloc_method' => $method,
        'is_pending' => 0, 'status' => 'completed', 'subtotal' => $est['materials'], 'total' => $est['pool']], $lines, $id, ['audit_action' => $id ? 'edit' : 'create', 'reason' => $id ? 'v60 test: costs revised' : null]);
}

/** The cost of goods sold a transaction posted */
function v60Cogs(int $txnId, int $itemId): string
{
    $acct = (int)DB::value('SELECT COALESCE(cogs_account_id, :d) FROM items WHERE id = :i', ['d' => Accts::get('cogs'), 'i' => $itemId]);
    return r2s((string)DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE txn_id = :t AND account_id = :a', ['t' => $txnId, 'a' => $acct]));
}

test('Version 60: Disassemble / Process Items, the outputs must cost what the inputs and labor cost within 2% (a setting); the check is made on processing orders only', function () {
    Auth::actAs($GLOBALS['adminId']);
    eq([Settings::get('processing_cost_tolerance'), Settings::get('auto_recost_scope'), Settings::get('auto_recost_limit'), Processing::tolerance()], ['2', 'process', '2000', 2.0],
        'the settings: 2%, costing again on processing orders only, at most 2,000 transactions');
    eq(Processing::reconcile(1000.0, 1015.0), [15.0, 1.5, true], 'outputs 1,015.00 against inputs 1,000.00: 1.5%, allowed');
    eq(Processing::reconcile(1000.0, 1030.0), [30.0, 3.0, false], '3%: refused');
    eq(Processing::reconcile(15581.25, 156565.90), [140984.65, 904.84, false], 'the case reported: the outputs at ten times the inputs');
    eq(Processing::reconcile(0.0, 0.0), [0.0, 0.0, true], 'nothing against nothing');
    $raw = v60Item('V60-RAW', 'V60 Raw sack', '50', '0');
    $out = v60Item('V60-OUT', 'V60 Packed rice', '0', '120');
    $v = v60Vendor('V60 Grain Supplier');
    pt_bill($v, '2026-09-01', [['item_id' => $raw, 'qty' => 10, 'rate' => 50]]);
    // "Current average cost": the basis is a cost PER UNIT; a total typed there costs every unit at that total
    refuses(fn() => v60Process('2026-09-02', [['item_id' => $raw, 'qty' => 2]], [['item_id' => $out, 'qty' => 2, 'alloc_basis' => 1000]], 'average_cost'),
        'may differ from the input cost by at most 2%', 'outputs valued at 2,000.00 against inputs of 100.00: refused, nothing saved');
    eq((int)DB::value("SELECT COUNT(*) FROM transactions WHERE txn_type = 'item_processing' AND txn_date = '2026-09-02'"), 0, '...nothing saved');
    try {
        v60Process('2026-09-02', [['item_id' => $raw, 'qty' => 2]], [['item_id' => $out, 'qty' => 2, 'alloc_basis' => 1000]], 'average_cost');
    } catch (UserError $e) {
        check(str_contains($e->getMessage(), 'The outputs are costed at 2,000.00, but the inputs cost 100.00 (inventory 100.00 plus labor and other costs 0.00): a difference of 1,900.00 (1,900.00%)'),
            'the message: the outputs, the inputs, the difference and its percentage: ' . $e->getMessage());
    }
    $p1 = v60Process('2026-09-02', [['item_id' => $raw, 'qty' => 2]], [['item_id' => $out, 'qty' => 2, 'alloc_basis' => 50.5]], 'average_cost');
    check($p1 > 0, 'outputs at 101.00 against 100.00 (1%): saved');
    // the posting engine checks too (an order changed outside the page)
    $ln = DB::row("SELECT * FROM transaction_lines WHERE txn_id = :t AND line_kind = 'output'", ['t' => $p1]);
    DB::update('transaction_lines', ['alloc_basis' => 500], 'id = :id', ['id' => $ln['id']]);
    refuses(fn() => Posting::post($p1), 'at most 2%', 'the posting engine refuses the order posted again with a basis of 500.00 a unit');
    DB::update('transaction_lines', ['alloc_basis' => 50.5], 'id = :id', ['id' => $ln['id']]);
    Settings::set('processing_cost_tolerance', '1000');
    Settings::flush();
    $p2 = v60Process('2026-09-03', [['item_id' => $raw, 'qty' => 1]], [['item_id' => $out, 'qty' => 1, 'alloc_basis' => 70]], 'average_cost');
    check($p2 > 0, 'with the setting at 1000% the same kind of order is saved');
    Txn::void($p2, 'v60 test');
    Settings::set('processing_cost_tolerance', '2');
    Settings::flush();
    // a bill, a check or a sale is never checked against it
    $bill = pt_bill($v, '2026-09-04', [['item_id' => $out, 'qty' => 1, 'rate' => 999]]);
    check($bill > 0, 'a bill at any cost is saved');
    Txn::void($bill, 'v60 test');
    assertLedgerBalanced('the ledger balances');
});

test('Version 60: a back-dated, edited, voided or deleted processing order has the later inventory transactions costed again in date order; the setting extends it to every inventory transaction or switches it off', function () {
    Auth::actAs($GLOBALS['adminId']);
    Settings::set('auto_recost_scope', 'process');
    Settings::flush();
    $raw = v60Item('V60-RC-RAW', 'V60 Raw cacao', '50', '0');
    $out = v60Item('V60-RC-OUT', 'V60 Cacao tablea', '0', '150');
    $cust = (int)DB::insert('customers', ['name' => 'V60 Cafe Buyer', 'is_active' => 1]);
    $v = v60Vendor('V60 Cacao Farm');
    pt_bill($v, '2026-09-01', [['item_id' => $raw, 'qty' => 10, 'rate' => 50]]);
    $labor = acct('6000');
    $proc = v60Process('2026-09-10', [['item_id' => $raw, 'qty' => 10]], [['item_id' => $out, 'qty' => 10]], 'quantity');
    $sale = pt_sale('invoice', $cust, '2026-09-20', [['item_id' => $out, 'qty' => 4, 'rate' => 150]]);
    eqm(v60Cogs($sale, $out), '200.00', 'the sale on 09/20: 4 at 50.00');
    // the order of 09/10 is edited: labor of 100.00 added, the outputs cost 60.00 a unit; the sale after it follows
    $lines = DB::all('SELECT * FROM transaction_lines WHERE txn_id = :t ORDER BY id', ['t' => $proc]);
    $in = array_values(array_map(fn($l) => ['id' => $l['id'], 'item_id' => $l['item_id'], 'qty' => $l['qty']], array_filter($lines, fn($l) => $l['line_kind'] === 'input')));
    $o = array_values(array_map(fn($l) => ['id' => $l['id'], 'item_id' => $l['item_id'], 'qty' => $l['qty']], array_filter($lines, fn($l) => $l['line_kind'] === 'output')));
    $auditBefore = (int)DB::value("SELECT COUNT(*) FROM audit_trail WHERE action = 'recost'");
    // the documents of the other tests from the date on: their entries must come back the same (water, cooperative, sales)
    // the items costed out of date order before (the Inventory Center test of version 53): their documents are put right
    $outOfOrder = array_map('intval', array_column(Maintenance::outOfDateOrder(), 'id'));
    $others = DB::pairs("SELECT t.id, CONCAT(ROUND(SUM(j.debit), 2), '/', ROUND(SUM(j.credit), 2), '/', COUNT(j.id)) FROM transactions t JOIN journal_lines j ON j.txn_id = t.id
                          WHERE t.txn_date >= '2026-09-10' AND t.id NOT IN (:p, :s) AND NOT EXISTS (SELECT 1 FROM inventory_movements m WHERE m.txn_id = t.id AND m.item_id IN (:r, :o))
                          GROUP BY t.id", ['p' => $proc, 's' => $sale, 'r' => $raw, 'o' => $out]);
    v60Process('2026-09-10', $in, $o, 'quantity', [['account_id' => $labor, 'amount' => 100, 'description' => 'Labor']], $proc);
    eqm(v60Cogs($sale, $out), '240.00', 'the sale on 09/20 costed again: 4 at 60.00');
    eqm((string)DB::value('SELECT avg_cost FROM items WHERE id = :i', ['i' => $out]), '60.00', 'the average of the tablea: 60.00');
    $audit = DB::row("SELECT * FROM audit_trail WHERE action = 'recost' ORDER BY id DESC LIMIT 1");
    check((int)DB::value("SELECT COUNT(*) FROM audit_trail WHERE action = 'recost'") === $auditBefore + 1 && str_contains((string)$audit['reason'], 'dated from 09/10/2026'),
        'the audit trail: recost, dated from 09/10/2026 (' . ($audit['reason'] ?? '') . ')');
    $after = DB::pairs("SELECT t.id, CONCAT(ROUND(SUM(j.debit), 2), '/', ROUND(SUM(j.credit), 2), '/', COUNT(j.id)) FROM transactions t JOIN journal_lines j ON j.txn_id = t.id
                         WHERE t.id IN (" . implode(',', array_map('intval', array_keys($others) ?: [0])) . ') GROUP BY t.id');
    $changed = [];
    $fixed = [];
    foreach ($others as $tid => $sig) {
        if (($after[$tid] ?? null) !== $sig) {
            $its = array_map('intval', DB::column('SELECT DISTINCT item_id FROM transaction_lines WHERE txn_id = :t AND item_id IS NOT NULL', ['t' => $tid]));
            if ($outOfOrder && array_intersect($its, $outOfOrder)) {
                $fixed[] = $tid;
                continue;
            }
            $changed[$tid] = DB::value('SELECT CONCAT(txn_type, " ", COALESCE(txn_no, ""), " ", txn_date) FROM transactions WHERE id = :t', ['t' => $tid]) . ': ' . $sig . ' -> ' . ($after[$tid] ?? 'none');
        }
    }
    eq($changed, [], count($others) . ' documents of the other items from 09/10 on (water bills, cooperative and sales documents among them) posted again with the same entries');
    $stillOut = array_map('intval', array_column(Maintenance::outOfDateOrder(), 'id'));
    $o53 = (int)DB::value("SELECT id FROM items WHERE code = 'ORDER-53'");
    check(count($fixed) === 2 && in_array($o53, $outOfOrder, true) && !in_array($o53, $stillOut, true),
        'the 2 documents of the item costed out of date order (ORDER-53: the sale of 10/09 entered before the delivery of that day) are put right, as Rebuild data would (the items costed out of order before 09/10 are left to Rebuild data; '
        . count($fixed) . ' fixed; out of order before: ' . implode(',', $outOfOrder) . '; after: ' . implode(',', $stillOut) . ')');
    $order53 = (int)DB::value("SELECT id FROM items WHERE code = 'ORDER-53'");
    if ($order53) {
        $inv = (int)DB::value("SELECT t.id FROM transactions t JOIN transaction_lines l ON l.txn_id = t.id WHERE t.txn_type = 'invoice' AND l.item_id = :i LIMIT 1", ['i' => $order53]);
        eqm(v60Cogs($inv, $order53), '1380.00', '...its sale of 12 costed at the average of the day, (1,000 + 1,300) / 20 = 115.00');
    }
    assertLedgerBalanced('the ledger balances after the costing again');
    // a new transaction dated after everything starts nothing
    $n = (int)DB::value("SELECT COUNT(*) FROM audit_trail WHERE action = 'recost'");
    pt_bill($v, today(), [['item_id' => $raw, 'qty' => 1, 'rate' => 50]]);
    eq((int)DB::value("SELECT COUNT(*) FROM audit_trail WHERE action = 'recost'"), $n, 'a bill of today: nothing after it, nothing costed again');
    // the standard: a back-dated bill does not start it ...
    $b2 = v60Item('V60-RC-B', 'V60 Brown sugar', '100', '200');
    $bb = pt_bill($v, '2026-09-01', [['item_id' => $b2, 'qty' => 5, 'rate' => 100]]);
    $s2 = pt_sale('invoice', $cust, '2026-09-20', [['item_id' => $b2, 'qty' => 2, 'rate' => 200]]);
    $late = pt_bill($v, '2026-09-05', [['item_id' => $b2, 'qty' => 5, 'rate' => 200]]);
    eqm(v60Cogs($s2, $b2), '200.00', 'Disassemble / Process Items only (the standard): a back-dated bill leaves the later sale at 100.00 a unit');
    // ... with "Every inventory transaction" it does
    Settings::set('auto_recost_scope', 'all');
    Settings::flush();
    $bl = DB::all('SELECT * FROM transaction_lines WHERE txn_id = :t', ['t' => $late]);
    Txn::save('bill', ['txn_date' => '2026-09-05', 'vendor_id' => $v, 'account_id' => Accts::get('ap'), 'site_id' => pt_site(), 'due_date' => '2026-09-05'],
        parse_item_lines([['id' => $bl[0]['id'], 'item_id' => $b2, 'qty' => 5, 'rate' => 200]], 'purchase'), $late, ['reason' => 'v60 test: saved again']);
    eqm(v60Cogs($s2, $b2), '300.00', 'Every inventory transaction: the bill saved again, the sale follows the dates (2 at 150.00)');
    // and Never
    Settings::set('auto_recost_scope', 'off');
    Settings::flush();
    $n = (int)DB::value("SELECT COUNT(*) FROM audit_trail WHERE action = 'recost'");
    Txn::void($late, 'v60 test');
    eq((int)DB::value("SELECT COUNT(*) FROM audit_trail WHERE action = 'recost'"), $n, 'Never: a voided bill costs nothing again');
    eqm(v60Cogs($s2, $b2), '300.00', '...the sale keeps its cost until Rebuild data');
    // the limit
    $r = Recost::from('2026-09-01', 1);
    eq([$r['skipped'], $r['count'] > 1], [true, true], 'more transactions than the limit: nothing is costed again (Rebuild data is asked for)');
    Settings::set('auto_recost_scope', 'process');
    Settings::flush();
    eq(array_keys(Recost::SCOPES), ['process', 'all', 'off'], 'the three choices of the setting');
    assertLedgerBalanced('the ledger balances');
});

test('Version 60: a supplier on an expense line (Customer / Supplier) goes to the general ledger line; the customers as on Customer:Job before, no member\'s supplier record added', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    require_once APP_ROOT . '/modules/txn/_common.php';
    require_once APP_ROOT . '/modules/txn/_grids.php';
    $sup = v60Vendor('V60 Hardware Depot');
    $cust = (int)DB::insert('customers', ['name' => 'V60 Job Site', 'is_active' => 1]);
    $exp = acct('6020');
    $bank = acct('1010');
    $opts = expense_name_options();
    check(isset($opts['v' . $sup]) && $opts['v' . $sup] === 'V60 Hardware Depot (Supplier)' && $opts['c' . $cust] === 'V60 Job Site (Customer)', 'the list: customers and suppliers, each with its kind');
    // a member: on the list as before (its customer record, by its member type), not by its supplier record
    $mid = (int)DB::value("SELECT id FROM members WHERE party_id IS NOT NULL AND status = 'active' ORDER BY id LIMIT 1");
    Members::ensureCustomerRoles($mid);
    Members::ensureVendorRoles($mid);
    Lookup::reset();
    $party = (int)DB::value('SELECT party_id FROM members WHERE id = :m', ['m' => $mid]);
    $mc = (int)DB::value('SELECT id FROM customers WHERE party_id = :p AND parent_id IS NULL', ['p' => $party]);
    $mv = (int)DB::value('SELECT id FROM vendors WHERE party_id = :p', ['p' => $party]);
    $opts = expense_name_options();
    check($mc && isset($opts['c' . $mc]) && str_contains($opts['c' . $mc], 'Member)') && !str_ends_with($opts['c' . $mc], '(Customer)'), 'a member is listed by its member type, as on Customer:Job before (' . ($opts['c' . $mc] ?? '-') . ')');
    check($mv && !isset($opts['v' . $mv]), 'the member\'s own supplier record is not added to the expense lines');
    // a check: the cost on the supplier's ledger, the bank line to the payee
    $lines = parse_expense_lines([
        ['account_id' => $exp, 'amount' => '1500', 'description' => 'Paint', 'name' => 'v' . $sup, 'is_billable' => '1'],
        ['account_id' => $exp, 'amount' => '500', 'description' => 'Site cleaning', 'name' => 'c' . $cust, 'is_billable' => '1'],
    ]);
    eq([$lines[0]['vendor_id'], $lines[0]['customer_id'], $lines[0]['is_billable'], $lines[1]['customer_id'], $lines[1]['vendor_id'], $lines[1]['is_billable']], [$sup, null, 0, $cust, null, 1],
        'a supplier line: the supplier, never billable; a customer line: the customer, billable');
    refuses(fn() => parse_expense_lines([['account_id' => $exp, 'amount' => '10', 'name' => 'v999999']]), 'from the list', 'a supplier that is not there is refused');
    $payee = v60Vendor('V60 Contractor Payee');
    $chk = Txn::save('check', ['txn_date' => '2026-10-05', 'account_id' => $bank, 'vendor_id' => $payee, 'total' => 2000], $lines);
    $jl = DB::all('SELECT account_id, debit, credit, vendor_id, customer_id, other_name_id FROM journal_lines WHERE txn_id = :t ORDER BY id', ['t' => $chk]);
    $byAmt = [];
    foreach ($jl as $j) {
        $byAmt[(string)(float)$j['debit'] . '/' . (string)(float)$j['credit']] = $j;
    }
    eq([(int)$byAmt['1500/0']['vendor_id'], $byAmt['1500/0']['customer_id'], (int)$byAmt['500/0']['customer_id'], (int)$byAmt['0/2000']['vendor_id']], [$sup, null, $cust, $payee],
        'the general ledger: the paint on the supplier, the cleaning on the customer, the bank line to the payee of the check (another supplier)');
    eqm((string)DB::value('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines WHERE vendor_id = :v AND account_id = :a', ['v' => $sup, 'a' => $exp]), '1500.00', 'the supplier\'s subsidiary ledger of the expense account');
    $saved = Txn::lines($chk);
    eq([$saved[0]['vendor_name'], $saved[1]['customer_name']], ['V60 Hardware Depot', 'V60 Job Site'], 'the saved check shows the supplier and the customer of its lines');
    eq(expense_name_code($saved[0]) . ' ' . expense_name_code($saved[1]), 'v' . $sup . ' c' . $cust, 'opened again, each line chooses its name');
    assertLedgerBalanced('the ledger balances');
});

test('Version 60: "Add new" in the drop-downs: customers, suppliers, accounts and items for those who may create them; the customer and name boxes of this system; an account box narrowed to its types; never a member', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    $allowed = QuickAdd::allowed();
    eq(array_keys($allowed), ['customer', 'vendor', 'account', 'item'], 'the administrator may add the four kinds');
    check(str_contains($allowed['customer'], 'p=cust.center') && str_contains($allowed['customer'], 'popup=1'), 'each opens the list\'s own form in the window');
    eq([QuickAdd::infer('customer_id', Lookup::customerOptions(false)), QuickAdd::infer('vendor_id', Lookup::vendors())], [' data-add="customer"', ' data-add="vendor"'],
        'the customer box of this system (members by their member type) and the supplier box get the lines');
    $names = Names::options();
    check((bool)array_filter(array_keys($names), fn($k) => $k[0] === 'm'), 'the name list holds members (m codes) as before');
    eq(QuickAdd::infer('name', $names), ' data-add="name"', 'a name box with the members gets "Add new" (a customer or a supplier)');
    eq(QuickAdd::infer('site_id', Lookup::sites()), '', 'other lists do not');
    $banks = DB::pairs("SELECT id, name FROM accounts WHERE account_type = 'Bank' AND is_active = 1");
    eq(QuickAdd::infer('account_id', $banks), ' data-add="account" data-add-types="Bank"', 'a bank account box: a new Bank account only');
    eq(QuickAdd::infer('account_id', Accts::all()), ' data-add="account"', 'a box of every account: any type');
    $opts = AccountTypes::optionsHtml(null, true, ['Bank']);
    check(str_contains($opts, 'data-base="Bank"') && !str_contains($opts, 'data-base="Expense"'), 'the account form in the window offers the types that behave as Bank only (Cash among them)');
    $rec = QuickAdd::record('vendor', v60Vendor('V60 New Supplier'));
    eq([$rec['kind'], $rec['label']], ['vendor', 'V60 New Supplier'], 'what the transaction is handed back');
    $item = QuickAdd::record('item', v60Item('V60-QA', 'V60 Quick item', '12', '20'));
    eq([$item['label'], $item['grid']['sales']['price'], $item['grid']['purchase']['cost']], ['V60-QA - V60 Quick item', 20.0, 12.0], '...an item with its price and cost for the line');
    eq(array_keys(QuickAdd::ITEM_TYPES), ['service', 'inventory', 'non_inventory', 'assembly', 'other_charge', 'subtotal', 'group', 'discount', 'payment'], 'the kinds of item');
});

test('Version 60: the Cash Count Sheet of a shift, the General Ledger\'s Cashier filter, the price variance of an item group in the gross sales', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    eq([CashCount::ready(), CashCount::label('1000'), CashCount::label('0.25'), CashCount::key('1000.00'), CashCount::key('0.10')], [true, '1,000', '0.25', '1000', '0.10'], 'the denominations');
    $term = (int)(DB::value('SELECT id FROM pos_terminals ORDER BY id LIMIT 1') ?: DB::insert('pos_terminals', ['code' => 'V60', 'name' => 'V60 Counter', 'site_id' => pt_site(), 'cash_account_id' => acct('1010')]));
    $shift = (int)DB::insert('pos_shifts', ['terminal_id' => $term, 'cashier_id' => $GLOBALS['adminId'], 'business_date' => today(), 'opened_at' => date('Y-m-d 08:00:00'), 'opening_float' => 1000, 'status' => 'open']);
    $_POST = ['cc_used' => '1', 'cc' => ['bill' => ['1000' => '2', '500' => '1', '20' => '3'], 'coin' => ['5' => '4', '0.25' => '2']]];
    $sheet = CashCount::fromPost();
    eqm($sheet['total'], '2580.50', 'the sheet: 2 x 1,000 + 500 + 3 x 20 + 4 x 5 + 2 x 0.25 = 2,580.50');
    CashCount::save($shift, $sheet['rows']);
    eq([CashCount::forShift($shift)['bill']['1000'] ?? null, CashCount::forShift($shift)['coin']['0.25'] ?? null], [2, 2], 'kept with the shift');
    $_POST = ['cc_used' => '1', 'cc' => ['bill' => ['100' => 'ten']]];
    refuses(fn() => CashCount::fromPost(), 'whole numbers', 'a quantity that is not a whole number');
    $_POST = ['cc' => ['bill' => ['100' => '1']]];
    eq(CashCount::fromPost(), null, 'a sheet opened and closed again (not "Use this count") changes nothing');
    $_POST = [];
    // the gross sales of a shift: an item group's price variance line is part of them
    $tx = (int)DB::insert('transactions', ['txn_type' => 'pos_sale', 'txn_date' => today(), 'txn_no' => 'V60-POS-1', 'pos_shift_id' => $shift, 'terminal_id' => $term,
        'total' => 115, 'status' => 'completed', 'created_by' => $GLOBALS['adminId']]);
    $it = v60Item('V60-GRP-A', 'V60 Burger', '0', '100');
    DB::insert('transaction_lines', ['txn_id' => $tx, 'line_no' => 1, 'line_kind' => 'item', 'item_id' => $it, 'qty' => 1, 'rate' => 100, 'amount' => 100]);
    DB::insert('transaction_lines', ['txn_id' => $tx, 'line_no' => 2, 'line_kind' => 'item', 'item_id' => null, 'qty' => 0, 'rate' => 0, 'amount' => 15, 'group_role' => 'variance']);
    $sum = Pos::summary($shift);
    eqm($sum['gross_sales'], '115.00', 'Gross sales 115.00: the item 100.00 and the group\'s price variance 15.00');
    // the General Ledger of one cashier: only what that cashier entered, the beginning balance too
    asUser('teller1');
    $je = Txn::save('journal', ['txn_date' => '2026-10-10', 'memo' => 'V60 cashier entry'], [
        ['line_kind' => 'journal', 'account_id' => acct('6020'), 'amount' => 77, 'description' => 'V60'],
        ['line_kind' => 'journal', 'account_id' => acct('1010'), 'amount' => -77, 'description' => 'V60'],
    ]);
    Auth::actAs($GLOBALS['adminId']);
    $def = Report::get('general_ledger');
    $all = $def['run'](['from' => '2026-10-01', 'to' => '2026-10-31', 'account_id' => acct('6020'), 'class_id' => null, 'q' => '', 'user_id' => 0, 'columns' => '']);
    $one = $def['run'](['from' => '2026-10-01', 'to' => '2026-10-31', 'account_id' => acct('6020'), 'class_id' => null, 'q' => '', 'user_id' => $ids['user.teller1'], 'columns' => '']);
    $nums = fn(array $d) => array_values(array_filter(array_map(fn($r) => $r['num'] ?? null, $d['rows'])));
    check(count($nums($all)) > 1 && in_array((string)DB::value('SELECT txn_no FROM transactions WHERE id = :t', ['t' => $je]), $nums($all), true), 'every entry of the month on 6020');
    eq($nums($one), [(string)DB::value('SELECT txn_no FROM transactions WHERE id = :t', ['t' => $je])], 'the cashier Teresa Villanueva: her entry only');
    check(str_contains($one['notes'], 'Only the transactions entered by the cashier Teresa Villanueva'), 'the note names the cashier');
    eq(Report::get('general_ledger')['filters'], ['range', 'account', 'class', 'cashier', 'search'], 'the filters of the General Ledger');
    Txn::void($je, 'v60 test');
});

test('Version 60: files attached to a transaction, kept in the database in pieces; program files refused; counted on the lists; in the backup as hex under 1 MB a statement; deleted with the transaction', function () {
    Auth::actAs($GLOBALS['adminId']);
    eq(Attachments::TYPES, ['journal', 'opening_balance', 'check', 'cash_voucher', 'bill', 'purchase_order', 'sales_order', 'invoice'], 'the documents that take files');
    check(Attachments::ready() && Attachments::enabled('check') && !Attachments::enabled('coop_or'), 'a cooperative document (official receipt) is not among them');
    $je = Txn::save('journal', ['txn_date' => '2026-10-11', 'memo' => 'V60 with files'], [
        ['line_kind' => 'journal', 'account_id' => acct('6020'), 'amount' => 10, 'description' => 'V60'],
        ['line_kind' => 'journal', 'account_id' => acct('1010'), 'amount' => -10, 'description' => 'V60'],
    ]);
    $tmp = tempnam(sys_get_temp_dir(), 'v60');
    $big = random_bytes(600000);
    file_put_contents($tmp, $big);
    $a1 = Attachments::store($tmp, 'scan of the signed voucher.pdf', $je);
    file_put_contents($tmp, "Receipt no. 123\n");
    $a2 = Attachments::store($tmp, '..\\..\\receipt.txt', $je);
    $r1 = Attachments::find($a1);
    eq([$r1['file_name'], (int)$r1['file_size'], (int)$r1['chunks'], $r1['mime_type'], $r1['sha1']], ['scan of the signed voucher.pdf', 600000, 3, 'application/pdf', sha1($big)], 'a 600 KB file in 3 pieces of 256 KB at most');
    eq(Attachments::find($a2)['file_name'], 'receipt.txt', 'a name without its folders');
    $back = implode('', DB::column('SELECT data FROM txn_attachment_chunks WHERE attachment_id = :a ORDER BY seq', ['a' => $a1]));
    eq(sha1($back), sha1($big), 'read back byte for byte');
    eq(Attachments::counts([$je, 999999]), [$je => 2], 'the lists count the files of each document');
    file_put_contents($tmp, 'MZ');
    refuses(fn() => Attachments::store($tmp, 'setup.exe', $je), 'program file', 'a program file is refused');
    @unlink($tmp);
    $empty = tempnam(sys_get_temp_dir(), 'v60e');
    refuses(fn() => Attachments::store($empty, 'empty.pdf', $je), 'is empty', 'an empty file is refused');
    @unlink($empty);
    eq([Attachments::size(600000), Attachments::size(512), Attachments::iniBytes('memory_limit') !== 0], ['586 KB', '512 bytes', true], 'sizes in words');
    // the backup: the pieces written as hex, no statement over 1 MB
    $b = Backup::create('v60 attachments', false, true, 'v60_');
    $sql = file_get_contents($b['path']);
    $stmts = array_filter(explode(";\n", $sql), fn($s) => str_contains($s, 'INSERT INTO `txn_attachment_chunks`'));
    check($stmts && str_contains(implode('', $stmts), '0x'), 'the content of the files is written as hex');
    eq(max(array_map('strlen', $stmts)) < 1048576, true, 'every statement of the files under 1 MB (MySQL 5.5\'s default packet), the largest ' . number_format(max(array_map('strlen', $stmts))) . ' bytes');
    @unlink($b['path']);
    // removed one at a time (audit trail), all with the transaction
    Attachments::remove(Attachments::find($a2), Txn::load($je), 'coa.journal');
    check((bool)DB::value("SELECT COUNT(*) FROM audit_trail WHERE action = 'remove_file' AND record_id = :t", ['t' => $je]), 'Remove File in the audit trail');
    Txn::void($je, 'v60 test');
    eq(Attachments::counts([$je]), [$je => 1], 'voiding keeps the files');
    DB::run('DELETE FROM journal_lines WHERE txn_id = :t', ['t' => $je]);
    DB::run('DELETE FROM transaction_lines WHERE txn_id = :t', ['t' => $je]);
    DB::run('DELETE FROM transactions WHERE id = :t', ['t' => $je]);
    eq([(int)DB::value('SELECT COUNT(*) FROM txn_attachments WHERE transaction_id = :t', ['t' => $je]), (int)DB::value('SELECT COUNT(*) FROM txn_attachment_chunks WHERE attachment_id = :a', ['a' => $a1])], [0, 0],
        'deleting a transaction deletes its files and their pieces');
});

test('Version 60: the FB POS lists, the update script runs twice, nothing pending', function () {
    Auth::actAs($GLOBALS['adminId']);
    check(FbPos::ready(), 'the FB POS columns and table are there');
    $g1 = (int)DB::insert('fb_pos_groups', ['name' => 'V60 Burgers', 'sort_order' => 2]);
    $g2 = (int)DB::insert('fb_pos_groups', ['name' => 'V60 Drinks', 'sort_order' => 1, 'is_active' => 0]);
    eq([FbPos::groups(true)[$g1]['name'] ?? null, isset(FbPos::groups(true)[$g2]), FbPos::groupOptions($g2)[$g2] ?? null, isset(FbPos::groupOptions()[$g2])], ['V60 Burgers', false, 'V60 Drinks', false],
        'the groups: a hidden one is offered only for an item already in it');
    eq([FbPos::initials('Pork Burger'), FbPos::initials('cola')], ['PB', 'C'], 'the letters of an item without a picture');
    eq(FbPos::imageUrl(['id' => 1, 'image_file' => 'nothing.jpg']), '', 'no picture on disk: no address');
    run_sql_file(DB::pdo(), APP_ROOT . '/database/migrations/2027_05_09_accounting_update_v40.sql');
    eq((int)DB::value("SELECT COUNT(*) FROM pages WHERE page_key IN ('pos.fb', 'pos.fb_menu', 'rpt.pos_cash_count')"), 3, 'the script runs again; each page once');
    Schema::flush();
    eq(Schema::pending(), [], 'nothing pending');
    foreach (['pos.fb' => 'pos.register', 'pos.fb_menu' => 'pos.terminals', 'rpt.pos_cash_count' => 'pos.shifts'] as $new => $old) {
        $roles = fn(string $k) => array_map('intval', DB::column('SELECT a.role_id FROM role_page_access a JOIN pages p ON p.id = a.page_id WHERE p.page_key = :k AND a.allowed = 1', ['k' => $k]));
        check($roles($old) && !array_diff($roles($old), $roles($new)), $new . ': every role that may open ' . $old . ' may open it');
    }
});
