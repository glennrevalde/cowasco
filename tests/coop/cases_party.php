<?php
declare(strict_types=1);

/**
 * Multipurpose cooperative: one party with many roles, names on documents, lines of business, the Consolidated
 * Party Statement, posted-document rules and the legacy upgrade (included by run.php after the cooperative cases).
 *
 * The acceptance scenario of the improvement brief: one member buys on credit, pays with a sales receipt, delivers
 * goods as a supplier, is paid by check, saves, borrows and repays, and receives an approved patronage refund; every
 * document shows the member's name, everything is under one Party ID, the subsidiary ledgers agree with the general
 * ledger, the statements are balanced and the Party 360 agrees with the subsidiary ledgers. Then nonmembers, two
 * branches, lines of business, reversals, permissions and the upgrade of data recorded before the Party Master.
 */
require_once APP_ROOT . '/modules/txn/_common.php';

// ------------------------------------------------------------------ helpers of these cases
function pt_acct(string $code): int
{
    return (int)DB::value('SELECT id FROM accounts WHERE code = :c', ['c' => $code]);
}

function pt_site(): int
{
    return (int)DB::value("SELECT id FROM sites WHERE code = 'WH'") ?: (int)DB::value('SELECT id FROM sites ORDER BY id LIMIT 1');
}

function pt_pm(string $name): ?int
{
    $id = DB::value('SELECT id FROM payment_methods WHERE name = :n', ['n' => $name]);
    return $id ? (int)$id : null;
}

/** An item: inventory (rice, sugar) or a service, counting for patronage refund or not */
function pt_item(string $code, string $name, string $type, string $price, string $cost, bool $patronage): int
{
    $id = DB::value('SELECT id FROM items WHERE code = :c', ['c' => $code]);
    if ($id) {
        return (int)$id;
    }
    return DB::insert('items', ['item_type' => $type, 'code' => $code, 'name' => $name, 'description' => $name, 'unit' => 'pc',
        'sales_price' => $price, 'cost' => $cost, 'income_account_id' => pt_acct('4000'), 'cogs_account_id' => $type === 'inventory' ? pt_acct('5000') : null,
        'asset_account_id' => $type === 'inventory' ? pt_acct('1200') : null, 'expense_account_id' => pt_acct('5000'), 'is_active' => 1, 'patronage_eligible' => $patronage ? 1 : 0]);
}

function pt_sale(string $type, int $customerId, string $date, array $rows, array $extra = []): int
{
    $h = ['txn_date' => $date, 'customer_id' => $customerId, 'site_id' => pt_site()]
        + ($type === 'sales_receipt' ? ['account_id' => Accts::get('undeposited_funds'), 'payment_method_id' => pt_pm('Cash')] : ['account_id' => Accts::get('ar'), 'due_date' => $date]);
    return Txn::save($type, array_merge($h, $extra), parse_item_lines($rows, 'sales', ['customer_id' => $customerId]));
}

function pt_bill(int $vendorId, string $date, array $rows): int
{
    return Txn::save('bill', ['txn_date' => $date, 'vendor_id' => $vendorId, 'account_id' => Accts::get('ap'), 'site_id' => pt_site(), 'due_date' => $date],
        parse_item_lines($rows, 'purchase'));
}

/** Report run with the filters a report expects */
function pt_report(string $key, array $f): array
{
    $def = Report::get($key);
    $base = ['asof' => today(), 'from' => '2026-01-01', 'to' => today(), 'class_id' => null, 'business_unit_id' => null, 'account_id' => null, 'q' => '', 'columns' => '',
        'customer_id' => null, 'vendor_id' => null, 'item_id' => null, 'site_id' => null];
    return ($def['run'])(array_merge($base, $f));
}

/** The text of a document print-out */
function pt_print(int $txnId): string
{
    ob_start();
    txn_print_body(Txn::load($txnId));
    return strip_tags((string)ob_get_clean());
}

$ptTr = (int)DB::value("SELECT id FROM business_units WHERE code = 'TR'");
$ptSc = (int)DB::value("SELECT id FROM business_units WHERE code = 'SC'");
$GLOBALS['pt'] = ['tr' => $ptTr, 'sc' => $ptSc];
$GLOBALS['pt']['rice'] = pt_item('RICE-25', 'Rice 25 kg', 'inventory', '150', '100', true);
$GLOBALS['pt']['soap'] = pt_item('SOAP-1', 'Laundry soap', 'inventory', '200', '120', false);
// the accounts between lines of business (flagged as inter-unit in their case; made here, before any report reads the chart)
$GLOBALS['pt']['dueFrom'] = coop_fx_new_account('1160', 'Due from Consumer Store', 'Other Current Asset', 'Asset', 'Debit');
$GLOBALS['pt']['dueTo'] = coop_fx_new_account('2160', 'Due to Savings and Credit', 'Other Current Liability', 'Liability', 'Credit');
$GLOBALS['pt']['iuIncome'] = coop_fx_new_account('4160', 'Inter-unit Service Income', 'Other Income', 'Income', 'Credit');
$GLOBALS['pt']['iuExpense'] = coop_fx_new_account('6160', 'Inter-unit Service Charges', 'Expense', 'Expense', 'Debit');
// users with exactly the roles the cases need
foreach ([['accountant', 'Ana Cruz', 'Accountant'], ['arclerk', 'Ben Garcia', 'A/R Clerk']] as [$u, $n, $r]) {
    $uid = DB::value('SELECT id FROM users WHERE username = :u', ['u' => $u]);
    $GLOBALS['ids']['user.' . $u] = $uid ? (int)$uid : coop_fx_user($u, $n, $r, null, null);
}
Access::reset();

// =============================================================================================== 11. the Party Master
test('Party Master: a member, a customer and a supplier record of one person share one Party ID; nothing is linked by name alone', function () {
    global $ids;
    $m = newMember('Dela Cruz', 'Juana', ['middle_name' => 'Pascual', 'mobile' => '09181234567', 'membership_date' => '2024-03-01']);
    $member = Coop::member($m);
    $party = Party::get((int)$member['party_id']);
    check($party !== null, 'the member has a party');
    eq($party['legal_name'], 'Juana P. Dela Cruz', 'legal name printed on documents, in the order it is written');
    eq($party['display_name'], $member['full_name'], 'the lists keep "Last, First M."');
    eq((int)DB::value('SELECT party_id FROM other_names WHERE member_id = :m', ['m' => $m]), (int)$party['id'], 'the member\'s ledger name is the same party');
    check(str_starts_with($party['party_no'], 'P-'), 'Party ID from its own sequence');
    // a new customer with the same mobile number is not made silently: the candidates are offered
    $cands = Party::candidatesFor(['legal_name' => 'Juana Dela Cruz', 'display_name' => 'Juana Dela Cruz', 'mobile' => '0918 123 4567']);
    check(isset($cands[(int)$party['id']]), 'the existing member is proposed for a customer with the same mobile number');
    check(in_array('Same mobile number', $cands[(int)$party['id']]['basis'], true), 'basis: same mobile number');
    // a supplier who typed the same mobile number in the phone box, with brackets and dashes, is proposed too
    $cands = Party::candidatesFor(['legal_name' => 'JPD Rice Trading', 'display_name' => 'JPD Rice Trading', 'phone' => '(0918) 123-4567']);
    check(isset($cands[(int)$party['id']]) && in_array('Same phone number', $cands[(int)$party['id']]['basis'], true), 'the member is proposed for a supplier whose phone is the member\'s mobile number');
    // a member is a customer of the cooperative from the start (so the member can be chosen on sales, the POS and
    // Customer:Job): the customer record is made on the member's own party; so is its supplier record (v3: the member
    // is recorded once, on the member form, and is a supplier of the cooperative from the start too)
    $cust = (int)DB::value('SELECT id FROM customers WHERE party_id = :p AND parent_id IS NULL', ['p' => $party['id']]);
    check($cust > 0, 'the member is listed as a customer at once');
    $vend = (int)DB::value('SELECT id FROM vendors WHERE party_id = :p', ['p' => $party['id']]);
    check($vend > 0, 'the member is listed as a supplier at once');
    refuses(fn() => Party::grant((int)$party['id'], 'supplier', 'Delivers rice to the store'), 'already a supplier', 'one active supplier role');
    eq((int)DB::value('SELECT party_id FROM customers WHERE id = :c', ['c' => $cust]), (int)$party['id'], 'customer record on the same party');
    eq((int)DB::value('SELECT party_id FROM vendors WHERE id = :v', ['v' => $vend]), (int)$party['id'], 'supplier record on the same party');
    $roles = array_column(Party::roles((int)$party['id']), 'role');
    eq($roles, ['member', 'customer', 'supplier'], 'three roles, each a record of its own');
    refuses(fn() => Party::grant((int)$party['id'], 'customer'), 'already a customer', 'one active customer role');
    refuses(fn() => Party::grant((int)$party['id'], 'member'), 'Member Information', 'membership is made on the member page');
    eq((int)DB::value('SELECT COUNT(*) FROM party_crosswalk WHERE party_id = :p AND source IN (\'member\',\'customer\',\'vendor\')', ['p' => $party['id']]), 3, 'crosswalk: member, customer and supplier IDs');
    eq((int)DB::value("SELECT COUNT(*) FROM party_role_log WHERE party_id = :p AND action IN ('granted','linked')", ['p' => $party['id']]), 3, 'role history');
    // a person with the same name but a different birth date and mobile is not linked: it is proposed for review only
    $other = Party::create(['legal_name' => 'Juana Dela Cruz', 'party_type' => 'person', 'birth_date' => '1990-01-01'], null, null);
    check($other !== (int)$party['id'], 'a separate party');
    Party::findMatches();
    [$a, $b] = [min($other, (int)$party['id']), max($other, (int)$party['id'])];
    $rev = DB::row('SELECT * FROM party_match_reviews WHERE party_a = :a AND party_b = :b', ['a' => $a, 'b' => $b]);
    check($rev && $rev['status'] === 'open', 'proposed on Identity Review, not linked');
    check(str_contains($rev['basis'], 'Same name'), 'basis: same name');
    refuses(fn() => Party::markDistinct((int)$rev['id'], ''), 'reason', 'deciding needs a reason');
    Party::markDistinct((int)$rev['id'], 'Different birth dates and addresses');
    eq(DB::value('SELECT status FROM party_match_reviews WHERE id = :i', ['i' => $rev['id']]), 'distinct', 'recorded as different');
    Party::findMatches();
    eq(DB::value('SELECT status FROM party_match_reviews WHERE id = :i', ['i' => $rev['id']]), 'distinct', 'a decided pair stays decided');
    $GLOBALS['juana'] = ['member' => $m, 'party' => (int)$party['id'], 'customer' => $cust, 'vendor' => $vend, 'stranger' => $other];
});

test('Acceptance scenario: the member buys on credit, pays, delivers goods, is paid by check, saves, borrows, repays and gets a patronage refund', function () {
    global $ids;
    $j = $GLOBALS['juana'];
    $m = $j['member'];
    $pt = $GLOBALS['pt'];
    $memberNo = Coop::member($m)['member_no'];
    // as a supplier: delivers 10 bags of rice at 100 (a bill), and is paid by check
    $bill = pt_bill($j['vendor'], '2026-08-03', [['item_id' => $pt['rice'], 'qty' => 10, 'rate' => 100]]);
    $chk = Txn::save('bill_payment', ['txn_date' => '2026-08-10', 'vendor_id' => $j['vendor'], 'account_id' => pt_acct('1010'), 'account2_id' => Accts::get('ap'),
        'payment_method_id' => pt_pm('Check'), 'total' => 1000, 'memo' => 'Rice delivery'], [], null, ['keep_totals' => true, 'applications' => [['target_txn_id' => $bill, 'amount' => 1000]]]);
    // as a customer: buys 2 bags on credit (invoice) and pays; buys 1 bag and soap with a sales receipt
    $inv = pt_sale('invoice', $j['customer'], '2026-08-12', [['item_id' => $pt['rice'], 'qty' => 2, 'rate' => 150]]);
    $pay = Txn::save('payment', ['txn_date' => '2026-08-20', 'customer_id' => $j['customer'], 'account_id' => Accts::get('undeposited_funds'), 'account2_id' => Accts::get('ar'), 'total' => 300],
        [], null, ['keep_totals' => true, 'applications' => [['target_txn_id' => $inv, 'amount' => 300]]]);
    $sr = pt_sale('sales_receipt', $j['customer'], '2026-08-21', [['item_id' => $pt['rice'], 'qty' => 1, 'rate' => 150], ['item_id' => $pt['soap'], 'qty' => 1, 'rate' => 200]]);
    // a second invoice left open (what the member still owes the store)
    $inv2 = pt_sale('invoice', $j['customer'], '2026-08-25', [['item_id' => $pt['rice'], 'qty' => 1, 'rate' => 150]]);
    // as a member: share capital and savings, a loan and a repayment
    $sc = openAccount($m, 'SC', '2026-08-01');
    $rs = openAccount($m, 'RS', '2026-08-01');
    shift('2026-08-01');
    $or = receipt($m, '2026-08-02', [['kind' => 'savings', 'account_id' => $sc, 'amount' => '2000'], ['kind' => 'savings', 'account_id' => $rs, 'amount' => '3000']]);
    $app = approvedLoan($m, 'REG', '12000', '12', '2026-08-05');
    $rel = releaseLoan($app, '2026-08-06', '2026-09-06');
    $sched = Loans::scheduleRows($rel['loan_id']);
    $repay = receipt($m, '2026-09-06', [['kind' => 'loan', 'loan_id' => $rel['loan_id'], 'amount' => $sched[0]['total']]]);

    // ---- names on every document: the member's registered name, never a generic word, with the member no.
    $name = Party::get($j['party'])['legal_name'];
    $docs = ['bill' => [$bill, 'Supplier'], 'bill payment (check)' => [$chk, 'Pay to'], 'invoice' => [$inv, 'Bill to'], 'payment' => [$pay, 'Received from'],
        'sales receipt' => [$sr, 'Customer'], 'official receipt' => [$or['txn_id'], 'Received from'], 'loan release' => [$rel['txn_id'], 'Pay to']];
    foreach ($docs as $what => [$id, $label]) {
        $t = Txn::load($id);
        eq((int)$t['party_id'], $j['party'], "$what: the party");
        eq($t['cp_name'], $name, "$what: name as issued");
        eq($t['cp_member_no'], $memberNo, "$what: member no. on the document");
        $pp = printed_party($t);
        eq($pp['label'], $label, "$what: role label");
        check($pp['name'] !== 'Member' && $pp['name'] === $name, "$what: the printed name is the member's name");
    }
    foreach ([$bill, $chk, $inv, $sr] as $id) {
        $text = pt_print($id);
        check(str_contains($text, $name), Txn::ref(Txn::load($id)) . ' print-out shows ' . $name);
        check(str_contains($text, 'Member no. ' . $memberNo) || str_contains($text, 'member no. ' . $memberNo), Txn::ref(Txn::load($id)) . ' print-out shows the member no.');
    }
    // ---- one party: every document of every role is under the one Party ID
    eq((int)DB::value('SELECT COUNT(DISTINCT party_id) FROM transactions WHERE id IN (' . implode(',', [$bill, $chk, $inv, $pay, $sr, $inv2, $or['txn_id'], $rel['txn_id'], $repay['txn_id']]) . ')'), 1, 'one Party ID on all nine documents');
    // ---- lines of business: store documents in the store, member vouchers in savings and credit, on the ledger lines too
    eq((int)Txn::load($inv)['business_unit_id'], $pt['tr'], 'invoice in the Consumer Store');
    eq((int)Txn::load($or['txn_id'])['business_unit_id'], $pt['sc'], 'official receipt in Savings and Credit');
    eq((int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE txn_id = :t AND (business_unit_id IS NULL OR business_unit_id <> :u)', ['t' => $inv, 'u' => $pt['tr']]), 0, 'every ledger line of the invoice carries the unit');
    // ---- subsidiary ledgers agree with the general ledger
    foreach (CoopRecon::slVsGl(today()) as $r) {
        eqm($r['difference'], '0', 'SL = GL: ' . $r['ledger'] . ' ' . $r['code']);
    }
    eqm((string)DB::value('SELECT COALESCE(SUM(total_cost),0) FROM inventory_movements'), gl(pt_acct('1200')), 'inventory movements = Inventory control account');
    eqm(Balances::customerBalance($j['customer']), '150', 'A/R: the open invoice');
    eqm(Balances::vendorBalance($j['vendor']), '0', 'A/P: the bill was paid');
    assertLedgerBalanced('acceptance scenario');
    assertSlGl('acceptance scenario');
    // ---- the statements
    $tb = pt_report('trial_balance', ['asof' => today()]);
    eqm((string)$tb['totals']['debit'], (string)$tb['totals']['credit'], 'trial balance: debits = credits');
    $bs = pt_report('balance_sheet', ['asof' => today()]);
    check(str_contains($bs['notes'], 'Assets = Liabilities + Equity'), 'balance sheet balances');
    $sfc = pt_report('coop_sfc', ['asof' => today()]);
    check(str_contains($sfc['notes'], 'Total assets equal'), 'statement of financial condition balances');

    // ---- the patronage refund on purchases that count: rice counts, soap does not
    $GLOBALS['juanaDocs'] = compact('bill', 'chk', 'inv', 'pay', 'sr', 'inv2', 'sc', 'rs') + ['or' => $or['txn_id'], 'loan' => $rel['loan_id'], 'release' => $rel['txn_id']];
    $earned = Distribution::patronage('purchases', [], '2026-01-01', '2026-12-31');
    eqm($earned[$m] ?? '0', '600', 'purchases that count: rice 300 + 150 + 150 (the soap is left out)');
    $deliv = Distribution::patronage('deliveries', [], '2026-01-01', '2026-12-31');
    eqm($deliv[$m] ?? '0', '1000', 'deliveries that count: 10 bags of rice');
    $inc = Distribution::patronage('income', ['income_accounts' => [pt_acct('4000')]], '2026-01-01', '2026-12-31');
    eqm($inc[$m] ?? '0', '600', 'income basis: sales income on the member\'s customer record, items that count only');
    $b = Distribution::compute('pr', ['period_id' => null, 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'method' => 'rate', 'rate' => '10', 'pool' => '0',
        'credit_mode' => 'payable', 'credit_product_id' => null, 'description' => 'PR on store purchases', 'basis' => 'purchases'], ['member_types' => []], null, 'dist.pr');
    $line = DB::row('SELECT * FROM dist_batch_lines WHERE batch_id = :b AND member_id = :m', ['b' => $b, 'm' => $m]);
    eqm($line['basis_amount'], '600', 'batch basis');
    eqm($line['net_amount'], '60', 'refund 10 %');
    eq(Distribution::validate($b, 'dist.pr'), [], 'validated');
    asUser('gm');
    Distribution::approve($b, 'BOD-2026-09 approving the store patronage refund', 'dist.pr');
    Auth::actAs($GLOBALS['adminId']);
    Distribution::post($b, '2026-09-30', pt_acct('3200'), 'dist.pr');
    // ---- the Party 360 agrees with each subsidiary ledger
    $st = PartyStatement::build($j['party'], '2026-01-01', today());
    $sec = array_column($st['sections'], null, 'key');
    eqm($sec['ar']['closing'], Balances::customerBalance($j['customer']), 'Party 360 A/R = customer ledger');
    eqm($sec['ap']['closing'], Balances::vendorBalance($j['vendor']), 'Party 360 A/P = supplier ledger');
    eqm($sec['savings']['closing'], Savings::account($rs)['balance'], 'Party 360 savings = savings ledger');
    eqm($sec['share_capital']['closing'], Savings::account($sc)['balance'], 'Party 360 share capital = share capital ledger');
    $loan = Loans::loan($rel['loan_id']);
    eqm($sec['loans']['groups'][0]['principal'], $loan['principal_balance'], 'Party 360 loan principal = loan ledger');
    eqm($sec['dist_payable']['closing'], '60', 'Party 360: patronage refund payable');
    eq($sec['ar']['side'], 'owes', 'A/R: party owes the cooperative');
    eq($sec['savings']['side'], 'owed', 'savings: cooperative owes the party');
    eq($sec['share_capital']['side'], 'equity', 'share capital: member equity interest');
    eqm($st['totals']['equity'], Savings::account($sc)['balance'], 'equity kept apart');
    eqm($st['totals']['net'], bcsub($st['totals']['owes'], $st['totals']['owed'], 2), 'net position shown for information only');
    check(count($st['sections']) >= 6, 'separate histories and subtotals for each role');
    // paid out on a disbursement voucher
    shift('2026-10-01');
    Teller::disburse($m, '2026-10-01', [['kind' => 'payable', 'account_id' => Accts::get('pr_payable'), 'amount' => $line['net_amount'], 'description' => 'Patronage refund 2026', 'batch_line_id' => $line['id']]],
        ['mode' => 'cash'], [], 'teller.disburse');
    $st2 = PartyStatement::build($j['party'], '2026-01-01', today());
    eqm(array_column($st2['sections'], null, 'key')['dist_payable']['closing'] ?? '0', '0', 'refund paid: nothing payable');
    // the export opens as an Excel workbook
    [$rows] = PartyStatement::sheetRows($st);
    $file = tempnam(sys_get_temp_dir(), 'pst');
    (new Xlsx())->sheet('Party statement', $rows, [0], [3, 4, 5])->save($file);
    $zip = new ZipArchive();
    check($zip->open($file) === true && $zip->locateName('xl/worksheets/sheet1.xml') !== false, 'Excel export written');
    $zip->close();
    @unlink($file);
    assertLedgerBalanced('after the patronage refund');
});

test('Nonmembers: a nonmember customer and supplier, member and nonmember prices, members-only and approval rules, nothing in patronage', function () {
    global $ids;
    $pt = $GLOBALS['pt'];
    $cid = DB::insert('customers', ['name' => 'Pedro Walker', 'billing_address' => 'Poblacion', 'is_active' => 1]);
    $pid = Party::attach('customer', $cid);
    check(!Party::isActiveMember($pid), 'a nonmember party');
    $vid = DB::insert('vendors', ['name' => 'Agri Supply Trading', 'company_name' => 'Agri Supply Trading Corp.', 'is_active' => 1]);
    $vpid = Party::attach('vendor', $vid);
    eq(Party::get($vpid)['party_type'], 'organization', 'a company is an organization');
    eq(Party::get($vpid)['legal_name'], 'Agri Supply Trading Corp.', 'its registered name prints');
    refuses(fn() => newMember('Supply', 'Agri', ['link_party_id' => $vpid]), 'organization', 'an individual member never joins an organization\'s party');
    $inv = pt_sale('invoice', $cid, '2026-09-01', [['item_id' => $pt['rice'], 'qty' => 1, 'rate' => 150]]);
    eq(Txn::load($inv)['cp_member_no'], null, 'no member no. for a nonmember');
    eq(Txn::load($inv)['cp_name'], 'Pedro Walker', 'nonmember name printed');
    // prices: members get the member level of the store, nonmembers and walk-ins the nonmember level, dated levels expire
    $lvlM = DB::insert('price_levels', ['name' => 'Member price', 'adjust_pct' => -10, 'is_active' => 1]);
    $lvlN = DB::insert('price_levels', ['name' => 'Nonmember price', 'adjust_pct' => 5, 'is_active' => 1]);
    DB::update('business_units', ['member_price_level_id' => $lvlM, 'nonmember_price_level_id' => $lvlN], 'id = :id', ['id' => $pt['tr']]);
    BusinessUnits::reset();
    $juanaCust = $GLOBALS['juana']['customer'];
    eqm((string)Lookup::priceFor($pt['rice'], $juanaCust, $pt['tr'], '2026-09-01'), '135', 'member price: 10 % off 150');
    eqm((string)Lookup::priceFor($pt['rice'], $cid, $pt['tr'], '2026-09-01'), '157.5', 'nonmember price: 5 % on top');
    eqm((string)Lookup::priceFor($pt['rice'], null, $pt['tr'], '2026-09-01'), '157.5', 'walk-in: nonmember price');
    DB::update('price_levels', ['valid_to' => '2026-08-31'], 'id = :id', ['id' => $lvlM]);
    eqm((string)Lookup::priceFor($pt['rice'], $juanaCust, $pt['tr'], '2026-09-01'), '150', 'a promotion that ended: standard price');
    eqm((string)Lookup::priceFor($pt['rice'], $juanaCust, $pt['tr'], '2026-08-15'), '135', 'inside its dates: the promotion price');
    DB::update('business_units', ['member_price_level_id' => null, 'nonmember_price_level_id' => null], 'id = :id', ['id' => $pt['tr']]);
    // who may transact: members only, then nonmembers with approval
    DB::update('business_units', ['nonmember_policy' => 'members'], 'id = :id', ['id' => $pt['tr']]);
    BusinessUnits::reset();
    refuses(fn() => pt_sale('invoice', $cid, '2026-09-02', [['item_id' => $pt['rice'], 'qty' => 1, 'rate' => 150]]), 'members only', 'members-only line refuses a nonmember');
    refuses(fn() => pt_sale('sales_receipt', $cid, '2026-09-02', [['item_id' => $pt['rice'], 'qty' => 1, 'rate' => 150]]), 'members only', 'and a cash sale');
    $ok = pt_sale('invoice', $juanaCust, '2026-09-02', [['item_id' => $pt['rice'], 'qty' => 1, 'rate' => 150]]);
    check($ok > 0, 'a member buys');
    // an edit cannot move the member's sale to the nonmember either; an edit that keeps the member goes through
    $editTo = fn(int $cust, string $why) => Txn::save('invoice', ['txn_date' => '2026-09-02', 'customer_id' => $cust, 'site_id' => pt_site(), 'account_id' => Accts::get('ar'), 'due_date' => '2026-09-02'],
        parse_item_lines([['item_id' => $pt['rice'], 'qty' => 1, 'rate' => 150]], 'sales', ['customer_id' => $cust]), $ok, ['reason' => $why]);
    refuses(fn() => $editTo($cid, 'Wrong customer picked'), 'members only', 'an edit that changes the customer to a nonmember is refused');
    eq((int)Txn::load($ok)['customer_id'], $juanaCust, 'the invoice stays with the member');
    check($editTo($juanaCust, 'Same customer, saved again') === $ok, 'an edit that keeps the member is accepted');
    DB::update('business_units', ['nonmember_policy' => 'approval'], 'id = :id', ['id' => $pt['tr']]);
    BusinessUnits::reset();
    asUser('arclerk');
    refuses(fn() => pt_sale('invoice', $cid, '2026-09-02', [['item_id' => $pt['rice'], 'qty' => 1, 'rate' => 150]]), 'Supervisor approval is required', 'a clerk needs a supervisor for a nonmember');
    Auth::actAs($GLOBALS['adminId']);
    $okN = pt_sale('invoice', $cid, '2026-09-02', [['item_id' => $pt['rice'], 'qty' => 1, 'rate' => 150]]);
    check($okN > 0, 'a user who may approve sells to the nonmember');
    DB::update('business_units', ['nonmember_policy' => 'allow'], 'id = :id', ['id' => $pt['tr']]);
    BusinessUnits::reset();
    // nonmember purchases never count for patronage refund
    $earned = Distribution::patronage('purchases', [], '2026-01-01', '2026-12-31');
    eq(array_keys($earned), [$GLOBALS['juana']['member']], 'only the member who bought is in the basis (the nonmember\'s purchases are not)');
    eqm($earned[$GLOBALS['juana']['member']] ?? '0', '750', 'the member: 600 before plus the 150 sale just made');
    // a line of business whose member business does not count
    DB::update('business_units', ['patronage_eligible' => 0], 'id = :id', ['id' => $pt['tr']]);
    eqm(Distribution::patronage('purchases', [], '2026-01-01', '2026-12-31')[$GLOBALS['juana']['member']] ?? '0', '0', 'a line of business that does not count');
    DB::update('business_units', ['patronage_eligible' => 1], 'id = :id', ['id' => $pt['tr']]);
    $GLOBALS['walker'] = ['customer' => $cid, 'party' => $pid, 'vendor' => $vid, 'invoice' => $inv];
    assertLedgerBalanced('nonmember cases');
});

test('Two branches and lines of business: the party statement by branch, unit statements, inter-unit eliminations', function () {
    global $ids;
    $pt = $GLOBALS['pt'];
    $j = $GLOBALS['juana'];
    $inv = pt_sale('invoice', $j['customer'], '2026-09-10', [['item_id' => $pt['rice'], 'qty' => 1, 'rate' => 150]], ['class_id' => $ids['class.BR2']]);
    $all = array_column(PartyStatement::build($j['party'], '2026-01-01', today())['sections'], null, 'key');
    $br2 = array_column(PartyStatement::build($j['party'], '2026-01-01', today(), ['class_id' => $ids['class.BR2']])['sections'], null, 'key');
    eqm($br2['ar']['increase'], '150', 'branch filter: only the Poblacion invoice');
    check(bccomp($all['ar']['increase'], $br2['ar']['increase'], 2) > 0, 'all branches together have more');
    $tr = array_column(PartyStatement::build($j['party'], '2026-01-01', today(), ['business_unit_id' => $pt['tr']])['sections'], null, 'key');
    check(!isset($tr['savings']), 'unit filter: no savings in the Consumer Store');
    check(isset($tr['ar']), 'unit filter: the store receivable');
    // inter-unit: savings and credit lends the store 5,000
    $dueFrom = $pt['dueFrom'];
    $dueTo = $pt['dueTo'];
    DB::run('UPDATE accounts SET is_interunit = 1 WHERE id IN (:a, :b)', ['a' => $dueFrom, 'b' => $dueTo]);
    BusinessUnits::reset();
    Txn::save('journal', ['txn_date' => '2026-09-15', 'memo' => 'Cash lent to the store', 'business_unit_id' => $pt['sc']], [
        ['line_kind' => 'journal', 'account_id' => $dueFrom, 'amount' => 5000, 'description' => 'Due from the store'],
        ['line_kind' => 'journal', 'account_id' => pt_acct('1010'), 'amount' => -5000, 'description' => 'Cash given'],
    ]);
    Txn::save('journal', ['txn_date' => '2026-09-15', 'memo' => 'Cash received from savings and credit', 'business_unit_id' => $pt['tr']], [
        ['line_kind' => 'journal', 'account_id' => pt_acct('1010'), 'amount' => 5000, 'description' => 'Cash received'],
        ['line_kind' => 'journal', 'account_id' => $dueTo, 'amount' => -5000, 'description' => 'Due to savings and credit'],
    ]);
    BusinessUnits::$reportFilter = null;
    require_once APP_ROOT . '/app/reports/finance.php';
    $whole = fin_balances(null, today());
    check(!isset($whole[$dueFrom]) && !isset($whole[$dueTo]), 'whole cooperative: both inter-unit balances eliminated');
    $bs = pt_report('balance_sheet', ['asof' => today()]);
    check(str_contains($bs['notes'], 'eliminated') && str_contains($bs['notes'], 'Assets = Liabilities + Equity'), 'consolidated balance sheet: eliminated and balanced');
    BusinessUnits::$reportFilter = $pt['sc'];
    $sc = fin_balances(null, today());
    eqm((string)($sc[$dueFrom] ?? 0), '5000', 'Savings and Credit alone: the 5,000 due from the store');
    $tb = pt_report('trial_balance', ['asof' => today(), 'business_unit_id' => $pt['sc']]);
    eqm((string)$tb['totals']['debit'], (string)$tb['totals']['credit'], 'unit trial balance balances');
    BusinessUnits::$reportFilter = null;
    // operations by line of business: the units add up to the whole cooperative
    $sop = pt_report('sop_business_unit', ['from' => '2026-01-01', 'to' => today()]);
    $ns = end($sop['rows']);
    $sum = '0';
    foreach ($sop['columns'] as $c) {
        if (str_starts_with($c['key'], 'u')) {
            $sum = bcadd($sum, (string)$ns[$c['key']], 2);
        }
    }
    eqm($ns['total'], $sum, 'net surplus of the units = the whole cooperative (no inter-unit income or expense)');
    // savings and credit charges the store 1,000 for bookkeeping: income of one unit, expense of the other
    DB::run('UPDATE accounts SET is_interunit = 1 WHERE id IN (:a, :b)', ['a' => $pt['iuIncome'], 'b' => $pt['iuExpense']]);
    BusinessUnits::reset();
    Txn::save('journal', ['txn_date' => '2026-09-20', 'memo' => 'Bookkeeping fee charged to the store', 'business_unit_id' => $pt['sc']], [
        ['line_kind' => 'journal', 'account_id' => $dueFrom, 'amount' => 1000, 'description' => 'Due from the store'],
        ['line_kind' => 'journal', 'account_id' => $pt['iuIncome'], 'amount' => -1000, 'description' => 'Bookkeeping fee'],
    ]);
    Txn::save('journal', ['txn_date' => '2026-09-20', 'memo' => 'Bookkeeping fee of savings and credit', 'business_unit_id' => $pt['tr']], [
        ['line_kind' => 'journal', 'account_id' => $pt['iuExpense'], 'amount' => 1000, 'description' => 'Bookkeeping fee'],
        ['line_kind' => 'journal', 'account_id' => $dueTo, 'amount' => -1000, 'description' => 'Due to savings and credit'],
    ]);
    $sop2 = pt_report('sop_business_unit', ['from' => '2026-01-01', 'to' => today()]);
    $byName = array_column($sop2['rows'], null, 'name');
    $iuInc = $byName['4160 Inter-unit Service Income'] ?? null;
    check($iuInc !== null && bccomp((string)$iuInc['u' . $pt['sc']], '1000', 2) === 0, 'the fee is income of Savings and Credit');
    check($iuInc !== null && bccomp((string)$iuInc['elim'], '-1000', 2) === 0 && bccomp((string)$iuInc['total'], '0', 2) === 0, 'eliminated from the whole cooperative');
    $ns2 = end($sop2['rows']);
    eqm($ns2['total'], $ns['total'], 'the charge between the units does not change the cooperative\'s net surplus');
    eqm((string)$ns2['elim'], '0', 'income and expense eliminations net to zero');
    $is = pt_report('income_statement', ['from' => '2026-01-01', 'to' => today()]);
    check(!in_array('4160 - Inter-unit Service Income', array_column($is['rows'], 'name'), true) && !in_array('6160 - Inter-unit Service Charges', array_column($is['rows'], 'name'), true)
        && str_contains($is['notes'], 'eliminated'), 'whole-cooperative income statement: the inter-unit fee and its cost are left out');
    BusinessUnits::$reportFilter = $pt['tr'];
    $trBal = fin_balances('2026-01-01', today());
    eqm((string)($trBal[$pt['iuExpense']] ?? 0), '1000', 'the Consumer Store alone shows the fee it was charged');
    $isTr = pt_report('income_statement', ['from' => '2026-01-01', 'to' => today()]);
    check(in_array('6160 - Inter-unit Service Charges', array_column($isTr['rows'], 'name'), true), 'and its own income statement lists it');
    BusinessUnits::$reportFilter = null;
    $whole2 = fin_balances(null, today());
    check(!isset($whole2[$pt['iuIncome']]) && !isset($whole2[$pt['iuExpense']]) && !isset($whole2[$dueFrom]), 'trial balance of the whole cooperative: no inter-unit balance left');
    $gl = pt_report('general_ledger', ['from' => '2026-09-01', 'to' => today(), 'account_id' => $pt['iuIncome']]);
    check(str_contains($gl['notes'], 'listed as booked'), 'the general ledger lists inter-unit entries as booked and says so');
    $coopSop = pt_report('coop_sop', ['from' => '2026-01-01', 'to' => today()]);
    $coopNs = '0';
    foreach ($coopSop['rows'] as $r) {
        if (in_array($r['name'], ['NET SURPLUS', 'NET LOSS'], true)) {
            $coopNs = (string)$r['amount'];
        }
    }
    eqm($ns['total'], $coopNs, 'the same net surplus as the Statement of Operations');
    assertLedgerBalanced('lines of business');
});

test('Posted documents: locked against changes and deletion except by the permission with a reason; voids keep the document', function () {
    global $ids;
    $w = $GLOBALS['walker'];
    $inv = $w['invoice'];
    $t = Txn::load($inv);
    $lines = array_map(fn($l) => array_intersect_key($l, array_flip(['id', 'line_kind', 'item_id', 'account_id', 'description', 'qty', 'rate', 'amount', 'tax_code_id', 'tax_amount', 'site_id', 'uom_id', 'uom_factor', 'uom_qty', 'uom_rate', 'discount_kind', 'discount_pct', 'discount_amount'])), Txn::lines($inv));
    asUser('book');
    Txn::save('invoice', ['txn_date' => $t['txn_date'], 'customer_id' => $t['customer_id'], 'memo' => 'Delivered to the gate'], $lines, $inv);
    eq(Txn::load($inv)['memo'], 'Delivered to the gate', 'a memo change is allowed (the ledger does not change)');
    $changed = $lines;
    $changed[0]['rate'] = 140;
    $changed[0]['amount'] = 140;
    refuses(fn() => Txn::save('invoice', ['txn_date' => $t['txn_date'], 'customer_id' => $t['customer_id']], $changed, $inv), 'locked', 'an amount change is refused');
    refuses(fn() => Txn::delete($inv, 'Mistake'), 'locked', 'deleting a posted document is refused');
    asUser('accountant');
    refuses(fn() => Txn::save('invoice', ['txn_date' => $t['txn_date'], 'customer_id' => $t['customer_id']], $changed, $inv), 'reason', 'the permission still needs a reason');
    Txn::save('invoice', ['txn_date' => $t['txn_date'], 'customer_id' => $t['customer_id']], $changed, $inv, ['reason' => 'Price agreed before delivery']);
    eqm(Txn::load($inv)['total'], '140', 'changed by the user who may, with the reason');
    $aud = DB::row("SELECT * FROM audit_trail WHERE record_type = 'transaction' AND record_id = :t ORDER BY id DESC LIMIT 1", ['t' => $inv]);
    eq($aud['action'], 'edit_posted', 'audit: edit of a posted document');
    eq($aud['reason'], 'Price agreed before delivery', 'audit: the reason');
    check(str_contains((string)$aud['changes'], '150'), 'audit: the amount before');
    // void keeps the document and its number, removes its effect, and the void is recorded
    Auth::actAs($GLOBALS['adminId']);
    $no = Txn::load($inv)['txn_no'];
    Txn::void($inv, 'Customer returned the goods');
    $v = Txn::load($inv);
    eq((int)$v['is_voided'], 1, 'voided');
    eq($v['txn_no'], $no, 'number kept');
    eq((int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE txn_id = :t', ['t' => $inv]), 0, 'no effect on the ledger');
    eq($v['cp_name'], 'Pedro Walker', 'the name it was issued to stays');
    // a correction after posting is a separate reversing document: a credit memo
    $cm = Txn::save('credit_memo', ['txn_date' => '2026-09-20', 'customer_id' => $w['customer'], 'account_id' => Accts::get('ar'), 'site_id' => pt_site(), 'restock' => 1],
        parse_item_lines([['item_id' => $GLOBALS['pt']['rice'], 'qty' => 1, 'rate' => 150]], 'sales', ['customer_id' => $w['customer']]));
    eq(Txn::load($cm)['cp_role'], 'customer', 'credit memo made out to the customer');
    // cooperative vouchers are never deleted (unchanged rule)
    refuses(fn() => Txn::delete($GLOBALS['juanaDocs']['or'], 'x'), 'never deleted', 'an official receipt is voided, not deleted');
    assertLedgerBalanced('posted-document rules');
});

test('Names on issued documents: an edited master does not change old documents; an authorized reissue corrects them with a reason', function () {
    global $ids;
    $j = $GLOBALS['juana'];
    $inv = $GLOBALS['juanaDocs']['inv'];
    $before = Txn::load($inv)['cp_name'];
    // the member's surname changes (marriage): the member record leads the party
    $m = Coop::member($j['member']);
    Members::save(array_merge(array_intersect_key($m, array_flip(['member_kind', 'first_name', 'middle_name', 'suffix', 'branch_id', 'member_type_id', 'birth_date', 'mobile', 'res_street', 'res_barangay', 'res_city', 'res_province', 'subscribed_shares', 'par_value'])),
        ['last_name' => 'Santiago']), [], $j['member'], 'coop.member');
    eq(Party::get($j['party'])['legal_name'], 'Juana P. Santiago', 'the party follows the member');
    eq(Txn::load($inv)['cp_name'], $before, 'the old invoice keeps the name it was issued with');
    $new = pt_sale('invoice', $j['customer'], '2026-09-21', [['item_id' => $GLOBALS['pt']['rice'], 'qty' => 1, 'rate' => 150]]);
    eq(Txn::load($new)['cp_name'], 'Juana P. Santiago', 'a new document prints the new name');
    // the customer record's own name does not overwrite a member's legal name
    $c = DB::row('SELECT * FROM customers WHERE id = :c', ['c' => $j['customer']]);
    DB::update('customers', ['name' => 'Juana (store account)'], 'id = :id', ['id' => $j['customer']]);
    Party::syncFrom('customer', $j['customer'], $c);
    eq(Party::get($j['party'])['legal_name'], 'Juana P. Santiago', 'the member record leads, not the customer record');
    // reissue: permission, reason, history; amounts and ledger untouched
    $jl = DB::value('SELECT GROUP_CONCAT(CONCAT(account_id, ":", debit, ":", credit) ORDER BY id) FROM journal_lines WHERE txn_id = :t', ['t' => $inv]);
    asUser('teller1');
    refuses(fn() => Txn::reissue($inv, [], 'Married name'), 'not allowed', 'a teller cannot reissue');
    asUser('book');
    refuses(fn() => Txn::reissue($inv, [], ''), 'reason', 'a reason is required');
    Txn::reissue($inv, [], 'Married name: reissued at the member\'s request');
    $t = Txn::load($inv);
    eq($t['cp_name'], 'Juana P. Santiago', 'reissued with the current name');
    eq((int)$t['cp_reissue_no'], 1, 'reissue no. 1');
    eq($t['cp_source'], 'reissue', 'marked as a reissue');
    $r = DB::row('SELECT * FROM document_reissues WHERE txn_id = :t', ['t' => $inv]);
    eq($r['old_name'], $before, 'history: the old name');
    eq($r['new_name'], 'Juana P. Santiago', 'history: the new name');
    eq(DB::value('SELECT GROUP_CONCAT(CONCAT(account_id, ":", debit, ":", credit) ORDER BY id) FROM journal_lines WHERE txn_id = :t', ['t' => $inv]), $jl, 'the ledger did not change');
    check(str_contains(pt_print($inv), 'Reissue no. 1'), 'the print-out shows the reissue');
    refuses(fn() => Txn::reissue($inv, [], 'again'), 'same', 'nothing to correct');
    Auth::actAs($GLOBALS['adminId']);
});

test('Roles and identity: revoke and restore keep history; linking duplicates needs the permission and moves records and documents; a wrong link is moved back', function () {
    global $ids;
    $w = $GLOBALS['walker'];
    // revoke and restore the nonmember's customer role: the record and its documents stay
    Party::revoke('customer', $w['customer'], 'Account closed at the customer\'s request');
    eq((int)DB::value('SELECT is_active FROM customers WHERE id = :c', ['c' => $w['customer']]), 0, 'inactive');
    eq((int)DB::value('SELECT COUNT(*) FROM transactions WHERE customer_id = :c', ['c' => $w['customer']]) > 0, true, 'documents stay');
    $again = Party::grant($w['party'], 'customer', 'Reopened');
    eq($again, $w['customer'], 'given again: the same record, number and history');
    eq((int)DB::value('SELECT is_active FROM customers WHERE id = :c', ['c' => $w['customer']]), 1, 'active again');
    $acts = DB::column("SELECT action FROM party_role_log WHERE party_id = :p AND role = 'customer' ORDER BY id", ['p' => $w['party']]);
    eq(array_slice($acts, -2), ['revoked', 'restored'], 'history: revoked, then restored');
    refuses(fn() => Party::revoke('member', $GLOBALS['juana']['member'], 'x'), 'Member Center', 'membership ends through its status');
    // the same person recorded twice as a customer (legacy): proposed, linked by a user with the permission
    $dupC = DB::insert('customers', ['name' => 'P. Walker', 'mobile' => '09991112222', 'is_active' => 1]);
    $dupP = Party::attach('customer', $dupC);
    Party::update($w['party'], ['mobile' => '0999-111-2222'], '');
    $sale = pt_sale('invoice', $dupC, '2026-09-22', [['item_id' => $GLOBALS['pt']['rice'], 'qty' => 1, 'rate' => 150]]);
    Party::findMatches();
    [$a, $b] = [min($dupP, $w['party']), max($dupP, $w['party'])];
    check((bool)DB::value("SELECT 1 FROM party_match_reviews WHERE party_a = :a AND party_b = :b AND status = 'open' AND basis LIKE '%mobile%'", ['a' => $a, 'b' => $b]), 'proposed: same mobile number');
    asUser('book');
    refuses(fn() => Party::merge($w['party'], $dupP, 'Same person'), 'not allowed', 'a bookkeeper cannot link parties');
    asUser('gm');
    refuses(fn() => Party::merge($w['party'], $dupP, ''), 'reason', 'linking needs a reason');
    Party::merge($w['party'], $dupP, 'Same mobile number and address; confirmed by phone');
    Auth::actAs($GLOBALS['adminId']);
    eq((int)DB::value('SELECT party_id FROM customers WHERE id = :c', ['c' => $dupC]), $w['party'], 'the record moved to the party kept');
    eq((int)Txn::load($sale)['party_id'], $w['party'], 'its documents moved too');
    eq(Txn::load($sale)['cp_name'], 'P. Walker', 'the name printed on them stays as issued');
    eq(Party::get($dupP)['status'], 'merged', 'the other party is kept as merged');
    eq((int)Party::live($dupP)['id'], $w['party'], 'its number leads to the party kept');
    eq((int)DB::value("SELECT party_id FROM party_crosswalk WHERE source = 'party' AND source_id = :p", ['p' => $dupP]), $w['party'], 'crosswalk: old Party ID to the party kept');
    eq(DB::value('SELECT status FROM party_match_reviews WHERE party_a = :a AND party_b = :b', ['a' => $a, 'b' => $b]), 'merged', 'the review is closed as linked');
    // two memberships cannot be merged
    $m2 = newMember('Dela Cruz', 'Juan', ['membership_date' => '2024-03-01']);
    asUser('gm');
    refuses(fn() => Party::merge($GLOBALS['juana']['party'], (int)Coop::member($m2)['party_id'], 'Same family'), 'Both parties are members', 'two members are never merged');
    // a record linked by mistake goes back to a party of its own
    $back = Party::move('customer', $dupC, null, 'Linked by mistake: a different P. Walker');
    Auth::actAs($GLOBALS['adminId']);
    check($back !== $w['party'], 'a new party for the record');
    eq((int)Txn::load($sale)['party_id'], $back, 'and its documents');
    eq((int)DB::value("SELECT COUNT(*) FROM party_role_log WHERE ref_id = :c AND role = 'customer' AND action IN ('moved_out','moved_in')", ['c' => $dupC]), 2, 'moved out and in, both logged');
    // a party nobody deals with any more: inactive only when no role is active, and active again with a new role
    refuses(fn() => Party::update($back, ['status' => 'inactive'], 'No longer buys'), 'still has an active role', 'not while a role is active');
    Party::revoke('customer', $dupC, 'Moved away');
    refuses(fn() => Party::update($back, ['status' => 'inactive'], ''), 'reason', 'making it inactive needs a reason');
    Party::update($back, ['status' => 'inactive'], 'Moved away');
    eq(Party::get($back)['status'], 'inactive', 'inactive');
    check(!isset(Party::options()[$back]), 'an inactive party leaves the pick lists');
    Party::restore('customer', $dupC, 'Came back');
    eq(Party::get($back)['status'], 'active', 'restoring its role makes it active again');
    // the possible duplicates of one party are recorded for review from the Party Center, without a full scan
    $c3 = DB::insert('customers', ['name' => 'Walker Hardware', 'mobile' => '+63 999 111 2222', 'is_active' => 1]);
    $p3 = Party::attach('customer', $c3);
    eq(Party::proposeFor($p3), 2, 'two new pairs: the Walker party and the record moved out of it share the mobile number');
    eq(Party::proposeFor($p3), 0, 'asked again: nothing new');
    [$a, $b] = [min($p3, $w['party']), max($p3, $w['party'])];
    eq(DB::value('SELECT status FROM party_match_reviews WHERE party_a = :a AND party_b = :b', ['a' => $a, 'b' => $b]), 'open', 'waiting on Identity Review');
});

test('Permissions: confidential member details, page access of the new pages, lines of business switched off', function () {
    global $ids;
    $p = Party::get($GLOBALS['juana']['party']);
    asUser('teller1');
    $v = Party::visible($p);
    check($v['_masked'] && $v['mobile'] !== $p['mobile'] && str_ends_with((string)$v['mobile'], substr((string)$p['mobile'], -3)), 'a teller sees the mobile number partly hidden');
    check(Access::page('party.center') && !Access::can('party.center', 'create'), 'a teller reads the Party Center only');
    check(!Access::page('party.review'), 'a teller does not open Identity Review');
    asUser('member_off');
    check(!Party::visible($p)['_masked'], 'the membership officer sees the member\'s details');
    asUser('book');
    check(Access::page('party.statement') && !Access::special('party_merge') && Access::special('doc_reissue') && !Access::special('edit_posted_documents'), 'bookkeeper: statement and reissue, no linking, no changing posted documents');
    asUser('gm');
    check(Access::special('party_merge') && Access::can('coop.business_units', 'edit'), 'general manager: linking and lines of business');
    Auth::actAs($GLOBALS['adminId']);
    // a line the cooperative does not run: its entry pages close for everybody, its reports stay
    Settings::set('disabled_modules', 'pos');
    Access::reset();
    check(!Access::page('pos.register'), 'the POS register is closed (even to the administrator)');
    $posReport = (string)DB::value("SELECT p.page_key FROM pages p JOIN modules m ON m.id = p.module_id WHERE m.code = 'pos' AND p.page_type = 'report' LIMIT 1");
    check($posReport === '' || Access::page($posReport), 'its reports stay open');
    Settings::set('disabled_modules', '');
    Access::reset();
    check(Access::page('pos.register'), 'switched on again');
});

test('Upgrade of data recorded before the Party Master: one party per record, never merged by name, crosswalk, snapshots, nothing posted changes, safe to run twice', function () {
    global $ids;
    // a customer and a document recorded the old way (no party, no snapshot), named exactly like a member
    $mid = newMember('Legacy', 'Lorna', ['membership_date' => '2023-01-01']);
    $fullName = Coop::member($mid)['full_name'];
    $cid = DB::insert('customers', ['name' => $fullName, 'billing_address' => 'Old address', 'tin' => '123-456-789-000', 'is_active' => 1]);
    $vid = DB::insert('vendors', ['name' => 'Old Supplier', 'address' => 'Somewhere', 'is_active' => 1]);
    $inv = pt_sale('invoice', $cid, '2026-07-01', [['item_id' => $GLOBALS['pt']['rice'], 'qty' => 1, 'rate' => 150]]);
    // take it back to the state of version 26: no party on the records, no snapshot on the document
    $autoParty = (int)DB::value('SELECT party_id FROM customers WHERE id = :c', ['c' => $cid]);
    DB::run('UPDATE customers SET party_id = NULL WHERE id = :c', ['c' => $cid]);
    DB::run('UPDATE vendors SET party_id = NULL WHERE id = :v', ['v' => $vid]);
    DB::run('UPDATE transactions SET party_id = NULL, cp_role = NULL, cp_name = NULL, cp_address = NULL, cp_tin = NULL, cp_member_no = NULL, cp_source = NULL, cp_issued_at = NULL WHERE id = :t', ['t' => $inv]);
    DB::run('DELETE FROM party_crosswalk WHERE party_id = :p', ['p' => $autoParty]);
    DB::run('DELETE FROM party_role_log WHERE party_id = :p', ['p' => $autoParty]);
    DB::run('DELETE FROM parties WHERE id = :p', ['p' => $autoParty]);
    $jlBefore = DB::value('SELECT MD5(GROUP_CONCAT(CONCAT_WS(":", id, txn_id, account_id, debit, credit, txn_date) ORDER BY id)) FROM journal_lines');
    $noBefore = DB::value('SELECT MD5(GROUP_CONCAT(CONCAT_WS(":", id, txn_no, total) ORDER BY id)) FROM transactions');
    $countBefore = (int)DB::value('SELECT COUNT(*) FROM parties');
    run_sql_file($GLOBALS['pdo'], APP_ROOT . '/database/migrations/2026_11_15_multipurpose_party.sql');
    $newParty = (int)DB::value('SELECT party_id FROM customers WHERE id = :c', ['c' => $cid]);
    check($newParty > 0, 'the old customer got a party');
    check($newParty !== (int)Coop::member($mid)['party_id'], 'not merged with the member of the same name');
    check((int)DB::value('SELECT party_id FROM vendors WHERE id = :v', ['v' => $vid]) > 0, 'the old supplier got a party');
    eq((int)DB::value("SELECT party_id FROM party_crosswalk WHERE source = 'customer' AND source_id = :c", ['c' => $cid]), $newParty, 'crosswalk from the old customer ID');
    $t = Txn::load($inv);
    eq((int)$t['party_id'], $newParty, 'the document points to the party');
    eq($t['cp_name'], $fullName, 'snapshot: the name it had');
    eq($t['cp_tin'], '123-456-789-000', 'snapshot: the TIN');
    eq($t['cp_source'], 'upgrade', 'snapshot marked as taken at the upgrade');
    eq(DB::value('SELECT MD5(GROUP_CONCAT(CONCAT_WS(":", id, txn_id, account_id, debit, credit, txn_date) ORDER BY id)) FROM journal_lines'), $jlBefore, 'posted journal lines unchanged');
    eq(DB::value('SELECT MD5(GROUP_CONCAT(CONCAT_WS(":", id, txn_no, total) ORDER BY id)) FROM transactions'), $noBefore, 'document numbers and totals unchanged');
    eq((int)DB::value('SELECT COUNT(*) FROM parties'), $countBefore + 2, 'exactly two parties made');
    // run again: nothing more happens
    run_sql_file($GLOBALS['pdo'], APP_ROOT . '/database/migrations/2026_11_15_multipurpose_party.sql');
    eq((int)DB::value('SELECT COUNT(*) FROM parties'), $countBefore + 2, 'a second run makes no party');
    eq((int)DB::value('SELECT COUNT(*) FROM party_crosswalk WHERE source_id = :c AND source = \'customer\'', ['c' => $cid]), 1, 'and no second crosswalk row');
    // the possible duplicate is proposed, never linked by the upgrade
    Party::findMatches();
    $a = min($newParty, (int)Coop::member($mid)['party_id']);
    $b = max($newParty, (int)Coop::member($mid)['party_id']);
    eq(DB::value('SELECT status FROM party_match_reviews WHERE party_a = :a AND party_b = :b', ['a' => $a, 'b' => $b]), 'open', 'same name: proposed for review');
});

test('Ledger integrity after the multipurpose cases: every document balances, every subsidiary ledger agrees with its control account', function () {
    assertLedgerBalanced('end of the multipurpose cases');
    assertSlGl('end of the multipurpose cases');
    foreach (CoopRecon::slVsGl(today()) as $r) {
        eqm($r['difference'], '0', 'SL = GL: ' . $r['ledger'] . ' ' . $r['code']);
    }
    eq((int)DB::value('SELECT COUNT(*) FROM transactions WHERE party_id IS NULL AND (customer_id IS NOT NULL OR vendor_id IS NOT NULL OR other_name_id IS NOT NULL)'), 0, 'every document with a name has its party');
    eq((int)DB::value('SELECT COUNT(*) FROM customers WHERE party_id IS NULL') + (int)DB::value('SELECT COUNT(*) FROM vendors WHERE party_id IS NULL')
        + (int)DB::value('SELECT COUNT(*) FROM members WHERE party_id IS NULL'), 0, 'every role record has its party');
    eq((int)DB::value('SELECT COUNT(*) FROM (SELECT party_no FROM parties GROUP BY party_no HAVING COUNT(*) > 1) x'), 0, 'Party IDs are unique');
    eq((int)DB::value('SELECT COUNT(*) FROM journal_lines j JOIN transactions t ON t.id = j.txn_id WHERE COALESCE(j.business_unit_id, 0) <> COALESCE(t.business_unit_id, 0)'), 0, 'ledger lines carry their document\'s line of business');
});
