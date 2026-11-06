<?php
declare(strict_types=1);

/**
 * Water Billing inside the cooperative (included by run.php after the multipurpose cases).
 *
 * Section 7 of the integration brief, second scenario: one cooperative member is also a water customer and a supplier.
 * Juana of the multipurpose cases (member, store customer, rice supplier, saver, borrower, patronage refund) gets two
 * water-service accounts; a nonmember neighbour gets one. Water bills are computed by the water tariff, posted through
 * the one posting bridge (WaterPosting) and collected by the water collection service, exactly as the water pages do.
 * Checked: one Party ID on every document of every role, the member's name and number on the water documents, the
 * Party 360 with the water service beside the other roles and nothing counted twice, the subsidiary ledgers against
 * the one general ledger, lines of business (water out of the patronage refund), idempotent postings, voids that keep
 * their documents, and a material issuance through the one inventory ledger.
 */
require_once APP_ROOT . '/water/database/config.php';          // the water code's own helpers (no session in the CLI)
require_once APP_ROOT . '/water/common/billingHelpers.php';
require_once APP_ROOT . '/water/common/waterRateBillingHelpers.php';
require_once APP_ROOT . '/water/common/waterBillingSnapshotHelpers.php';
require_once APP_ROOT . '/water/common/waterReadingWorkflowHelpers.php';
require_once APP_ROOT . '/water/common/paymentService.php';
require_once APP_ROOT . '/water/process/collectionAccountingHelpers.php';
require_once APP_ROOT . '/water/process/inventoryIssuanceAccountingHelpers.php';

// ------------------------------------------------------------------ water set-up of the test database
run_sql_file(DB::pdo(), APP_ROOT . '/tests/water/fixture_water_reference.sql');
$GLOBALS['wt'] = [
    'ar' => coop_fx_new_account('1170', 'Accounts Receivable - Water', 'Accounts Receivable', 'Asset', 'Debit'),
    'sales' => coop_fx_new_account('4170', 'Water Sales', 'Income', 'Income', 'Credit'),
    'penalty' => coop_fx_new_account('4171', 'Water Penalties and Surcharges', 'Income', 'Income', 'Credit'),
    'cash' => pt_acct('1000') ?: (int)DB::value("SELECT id FROM accounts WHERE account_type = 'Bank' ORDER BY code LIMIT 1"),
    'unit' => (int)DB::value("SELECT id FROM business_units WHERE code = 'WU'"),
];
DB::tx(function () {
    $wt = $GLOBALS['wt'];
    $code = DB::insert('bill_codes', ['code' => 'WATER BILL', 'description' => 'Water Bill', 'type' => 'Service', 'ar_account' => $wt['ar'], 'income_account' => $wt['sales'],
        'cost_price' => 0, 'sales_price' => 0, 'allow_member_discount' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    foreach (['water_billing_bill_code_id' => $code, 'water_billing_default_ar_account_id' => $wt['ar'], 'water_billing_default_income_account_id' => $wt['sales'],
              'collection_penalty_account_id' => $wt['penalty'], 'manual_penalty_enabled' => 1, 'collection_max_backdate_days' => 3650] as $k => $v) {
        DB::run('INSERT INTO water_billing_settings (setting_key, setting_value, created_at, updated_at) VALUES (:k, :v, NOW(), NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', ['k' => $k, 'v' => (string)$v]);
    }
    DB::run("UPDATE default_accounts SET account_id = :a WHERE setting_key = 'water_ar'", ['a' => $wt['ar']]);
    DB::run("UPDATE default_accounts SET account_id = :a WHERE setting_key = 'water_income'", ['a' => $wt['sales']]);
    DB::run("UPDATE default_accounts SET account_id = :a WHERE setting_key = 'water_penalty'", ['a' => $wt['penalty']]);
    DB::insert('bill_periods', ['period_id' => 1, 'bp_code' => '090126_093026', 'start_date' => '2026-09-01 00:00:00', 'end_date' => '2026-09-30 23:59:00', 'status' => 'open',
        'opened_by' => $GLOBALS['adminId'], 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00']);
    $GLOBALS['wt']['code'] = $code;
});
DB::run('UPDATE users SET coop_cash_account_id = :c WHERE id = :u', ['c' => $GLOBALS['wt']['cash'], 'u' => $GLOBALS['adminId']]);

/** A water-service account (the former water "member"); $partyId links it to an existing party (never by name) */
function wt_account(int $key, string $last, string $first, ?int $partyId, int $rc = 1): int
{
    DB::insert('water_accounts', ['pkey' => $key, 'lastname' => $last, 'firstname' => $first, 'status' => 'A', 'account_number' => 'W' . $key, 'zone' => 1,
        'rc_id' => $rc, 'pipe_size_id' => 2, 'connection_status' => 'active', 'datecreated' => date('Y-m-d H:i:s')]);
    WaterAccounts::ensureParty($key, $partyId, $partyId ? 'Same person as the cooperative member (test)' : '');
    return $key;
}

/** A reading of the period and its bill posted the way Post Billing posts it; returns [reading id, amount, invoice id] */
function wt_bill(int $account, int $consumption, string $billingDate = '2026-10-01'): array
{
    $pdo = DB::pdo();
    $period = DB::row('SELECT * FROM bill_periods WHERE period_id = 1');
    $r = DB::tx(fn() => wr_save_draft_reading($pdo, $period, $account, $consumption, 'INDIVIDUAL', 'test', (int)$GLOBALS['adminId']));
    $reading = DB::row('SELECT * FROM bill_readings WHERE reading_id = :r', ['r' => $r['reading_id']]);
    $details = water_snapshot_details(water_snapshot_from_reading($reading));
    $j = DB::tx(function () use ($pdo, $reading, $details, $account, $billingDate) {
        $j = wc_create_water_billing_journal_from_breakdown($pdo, (int)$reading['reading_id'], $account, $details, $billingDate, 1, 1, 1);
        DB::run("UPDATE bill_readings SET is_billed = 1, billing_date = :d, due_date = '2026-10-15', term_days = 15, billing_journal_voucher_id = :j, branch_id = 1, segment_id = 1, division_id = 1 WHERE reading_id = :r",
            ['d' => $billingDate, 'j' => $j['journal_head_id'] ?? null, 'r' => $reading['reading_id']]);
        return $j;
    });
    return [(int)$reading['reading_id'], (string)$details['amount_due'], (int)($j['txn_id'] ?? 0)];
}

/** The water collection service (the cashier's Pay button) */
function wt_collect(int $account, array $items, string $date = '2026-10-05', array $extra = []): array
{
    $total = 0.0;
    foreach ($items as $i) {
        $total += (float)($i['payment_amount'] ?? 0);
    }
    $_SESSION['user_id'] = $_SESSION['operator_id'] = (int)$GLOBALS['adminId'];
    return ps_process_payment(DB::pdo(), ['member_id' => $account, 'items' => $items, 'transaction_date' => $date, 'tendered' => $total + (float)($extra['penalty_amount'] ?? 0),
        'class_id' => (int)DB::value('SELECT id FROM classes WHERE is_active = 1 ORDER BY id LIMIT 1'),
        'idempotency_key' => $extra['key'] ?? ('t' . bin2hex(random_bytes(10)))] + $extra, (int)$GLOBALS['adminId']);
}

// =============================================================================================== 12. Water Billing
test('Water: one member is also a water customer (two service accounts) and a supplier; a nonmember neighbour has water only', function () {
    $j = $GLOBALS['juana'];
    $wt = $GLOBALS['wt'];
    $party = (int)$j['party'];
    $memberNo = Coop::member($j['member'])['member_no'];
    $prBefore = Distribution::patronage('purchases', [], '2026-01-01', '2026-12-31')[$j['member']] ?? '0';
    $w1 = wt_account(900001, 'Dela Cruz', 'Juana', $party);
    $w2 = wt_account(900002, 'Dela Cruz', 'Juana', $party);               // her second house, its own meter and account
    eq((int)DB::value('SELECT party_id FROM water_accounts WHERE pkey = :a', ['a' => $w1]), $party, 'water account 1 under the member\'s Party ID');
    eq((int)DB::value('SELECT party_id FROM water_accounts WHERE pkey = :a', ['a' => $w2]), $party, 'water account 2 under the same Party ID');
    eq(count(WaterAccounts::ofParty($party)), 2, 'the party holds two water-service accounts');
    eq((string)DB::value("SELECT how FROM party_crosswalk WHERE source = 'water_acct' AND source_id = :i", ['i' => $w1]), 'linked', 'crosswalk: linked to the existing party (a person decided), not created');
    $n = wt_account(900003, 'Santos', 'Pedro', null);                      // a nonmember: his own new party
    $np = (int)DB::value('SELECT party_id FROM water_accounts WHERE pkey = :a', ['a' => $n]);
    check($np > 0 && $np !== $party, 'the nonmember gets his own party');
    check(!Party::isMember($np), 'a water customer is not automatically a cooperative member');

    // bills: the tariff of the water program (rate code 1, 1/2" pipe) decides the amounts
    [$r1, $amt1, $inv1] = wt_bill($w1, 23);
    [$r2, $amt2, $inv2] = wt_bill($w2, 8);
    [$r3, $amt3, $inv3] = wt_bill($n, 15);
    eqm($amt1, compute_water_billing_details(DB::pdo(), $w1, 23, true)['amount_due'], 'bill 1 = the tariff for 23 m3');
    foreach ([[$inv1, $amt1], [$inv2, $amt2], [$inv3, $amt3]] as [$inv, $amt]) {
        $t = Txn::load($inv);
        eqm($t['total'], $amt, 'invoice ' . $t['txn_no'] . ' = the bill');
        eq((int)$t['business_unit_id'], $wt['unit'], 'invoice ' . $t['txn_no'] . ' in the Water Utility');
        eq((int)$t['account_id'], $wt['ar'], 'invoice ' . $t['txn_no'] . ' on the water A/R account');
    }
    // the member's name and number on her water documents; the nonmember's own name
    $t1 = Txn::load($inv1);
    eq((int)$t1['party_id'], $party, 'water invoice under the member\'s Party ID');
    eq($t1['cp_name'], Party::get($party)['legal_name'], 'water invoice: her registered name as issued');
    eq($t1['cp_member_no'], $memberNo, 'water invoice: her member no.');
    eq((int)$t1['customer_id'], (int)$j['customer'], 'water A/R on her one customer record (no second customer)');
    eq(Txn::load($inv3)['cp_member_no'] ?? null, null, 'nonmember invoice carries no member no.');

    // posting the same bill again gives the same document back (a retry, a second click, an import replay)
    $again = WaterPosting::postBill('water', $r1, ['water_account_id' => $w1, 'txn_date' => '2026-10-01', 'lines' => [['account_id' => $wt['sales'], 'amount' => 999]]]);
    eq((int)$again['txn_id'], $inv1, 'same event key: the existing invoice, nothing posted twice');
    eq((int)DB::value("SELECT COUNT(*) FROM water_postings WHERE source_type = 'water_bill' AND source_id = :r", ['r' => (string)$r1]), 1, 'one posting row for the bill');

    // collections: account 1 partly, account 2 in full with a penalty, the nonmember in full
    $c1 = wt_collect($w1, [['type' => 'water', 'id' => $r1, 'payment_amount' => '100.00']]);
    $c2 = wt_collect($w2, [['type' => 'water', 'id' => $r2, 'payment_amount' => $amt2]], '2026-10-05', ['apply_penalty' => 1, 'penalty_amount' => '15.00']);
    $c3 = wt_collect($n, [['type' => 'water', 'id' => $r3, 'payment_amount' => $amt3]]);
    $p1 = Txn::load((int)WaterPosting::byEvent(WaterPosting::collectionKey($c1['receipt_no']))['txn_id']);
    eq($p1['txn_no'], $c1['receipt_no'], 'payment number = official receipt number');
    eq((int)$p1['party_id'], $party, 'her water payment under her Party ID');
    eq($p1['cp_member_no'], $memberNo, 'water payment: her member no.');
    eqm(Balances::open($t1), bcsub($amt1, '100', 2), 'water invoice 1 open after the partial payment');
    $dup = wt_collect($w1, [['type' => 'water', 'id' => $r1, 'payment_amount' => '100.00']], '2026-10-05', ['key' => (string)DB::value('SELECT idempotency_key FROM payment_transactions WHERE receipt_no = :r', ['r' => $c1['receipt_no']])]);
    check($dup['duplicate'] === true && $dup['receipt_no'] === $c1['receipt_no'], 'the same request again returns the first receipt');
    eq((int)DB::value("SELECT COUNT(*) FROM water_postings WHERE source_type = 'collection' AND source_id = :r", ['r' => $c1['receipt_no']]), 1, 'and posts nothing again');
    $pen = WaterPosting::byEvent(WaterPosting::collectionKey($c2['receipt_no']) . '#PEN');
    check($pen !== null, 'penalty on receipt 2 is a finance charge');
    eqm(Balances::open(Txn::load((int)$pen['txn_id'])), '0', 'and is settled by the same receipt');

    // ---- one Party ID across every role: store, supplier, member vouchers, water
    $docs = $GLOBALS['juanaDocs'];
    $all = [$docs['bill'], $docs['chk'], $docs['inv'], $docs['pay'], $docs['sr'], $docs['inv2'], $docs['or'], $docs['release'], $inv1, $inv2, (int)$p1['id']];
    eq((int)DB::value('SELECT COUNT(DISTINCT party_id) FROM transactions WHERE id IN (' . implode(',', $all) . ')'), 1, 'one Party ID on the store, supplier, member and water documents');
    // ---- the Party 360: water beside the other roles, nothing counted twice, nothing netted
    $st = PartyStatement::build($party, '2026-01-01', today());
    $sec = array_column($st['sections'], null, 'key');
    check(isset($sec['water']) && count($sec['water']['groups']) === 2, 'Party 360: a Water service section with both service accounts');
    $waterOpen = bcadd((string)WaterAccounts::openBalance($w1)['total'], (string)WaterAccounts::openBalance($w2)['total'], 2);
    eqm($sec['water']['closing'], $waterOpen, 'Party 360 water = what the water records show open');
    eqm(bcadd((string)$sec['ar']['closing'], (string)$sec['water']['closing'], 2), Balances::customerBalance((int)$j['customer']), 'store A/R + water = her customer ledger (each counted once)');
    eqm($sec['ap']['closing'], Balances::vendorBalance((int)$j['vendor']), 'Party 360 A/P = supplier ledger');
    eq($sec['water']['side'], 'owes', 'water: the party owes the cooperative');
    eqm(WaterAccounts::ledgerBalance($w1), WaterAccounts::openBalance($w1)['total'], 'water account 1: ledger = water records');
    // ---- lines of business: water is outside the patronage refund, the store and member figures are untouched
    eqm(Distribution::patronage('purchases', [], '2026-01-01', '2026-12-31')[$j['member']] ?? '0', $prBefore, 'patronage basis unchanged by water bills (the Water Utility does not count)');
    eq((int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE txn_id IN (' . implode(',', [$inv1, $inv2, $inv3, (int)$p1['id']]) . ') AND (business_unit_id IS NULL OR business_unit_id <> :u)', ['u' => $wt['unit']]), 0, 'every water ledger line carries the Water Utility unit');
    $is = pt_report('income_statement', ['from' => '2026-01-01', 'to' => today()]);
    BusinessUnits::$reportFilter = $wt['unit'];          // what the Line of business filter of the report page sets
    $isWu = pt_report('income_statement', ['from' => '2026-01-01', 'to' => today(), 'business_unit_id' => $wt['unit']]);
    BusinessUnits::$reportFilter = null;
    check(!str_contains(json_encode($isWu), 'Sales Income') || !str_contains(json_encode($isWu), '"4000"'), 'the Water Utility income statement leaves the store sales out');
    $waterRev = bcmul(gl($wt['sales']), '-1', 2);
    check(str_contains(json_encode($isWu), "Water Sales"), "the Water Utility income statement shows Water Sales");
    eqm($waterRev, bcadd(bcadd($amt1, $amt2, 2), $amt3, 2), 'water revenue = the three bills (once)');
    check(str_contains(json_encode($is), 'Water Sales'), 'the whole-cooperative income statement includes water revenue');
    // ---- ledgers
    foreach (CoopRecon::slVsGl(today()) as $r) {
        eqm($r['difference'], '0', 'SL = GL: ' . $r['ledger'] . ' ' . $r['code']);
    }
    $arSub = '0';
    foreach (DB::column("SELECT t.id FROM transactions t WHERE t.account_id = :a AND t.txn_type IN ('invoice', 'finance_charge') AND t.is_voided = 0", ['a' => $wt['ar']]) as $tid) {
        $arSub = bcadd($arSub, (string)Balances::open(Txn::load((int)$tid)), 2);
    }
    eqm(gl($wt['ar']), $arSub, 'water A/R control = open water invoices');
    assertLedgerBalanced('water member scenario');
    assertSlGl('water member scenario');
    $GLOBALS['wtDocs'] = compact('w1', 'w2', 'n', 'r1', 'r2', 'r3', 'inv1', 'inv2', 'inv3', 'c1', 'c2', 'c3');
});

test('Water: corrections keep their documents; a reversed receipt reopens the bill; a material issuance moves the one stock ledger', function () {
    $d = $GLOBALS['wtDocs'];
    $wt = $GLOBALS['wt'];
    // reverse the partial receipt of account 1: payment voided (kept), the bill open again, re-collected with a new receipt
    $_SESSION['user_id'] = $_SESSION['operator_id'] = (int)$GLOBALS['adminId'];
    ps_reverse_or_refund_payment(DB::pdo(), $d['c1']['receipt_no'], 'reversal', 'Keyed on the wrong account (test)', '2026-10-06', (int)$GLOBALS['adminId']);
    $w = WaterPosting::byEvent(WaterPosting::collectionKey($d['c1']['receipt_no']));
    eq($w['status'], 'voided', 'the receipt\'s posting is voided');
    eq((int)DB::value('SELECT is_voided FROM transactions WHERE id = :t', ['t' => $w['txn_id']]), 1, 'its payment document is kept, marked void');
    eqm(Balances::open(Txn::load($d['inv1'])), Txn::load($d['inv1'])['total'], 'the water invoice is fully open again');
    eqm(WaterAccounts::ledgerBalance($d['w1']), WaterAccounts::openBalance($d['w1'])['total'], 'water records and ledger still agree');
    $again = null;
    try {
        ps_reverse_or_refund_payment(DB::pdo(), $d['c1']['receipt_no'], 'reversal', 'again', '2026-10-06', (int)$GLOBALS['adminId']);
    } catch (Throwable $e) {
        $again = $e->getMessage();
        if (DB::inTransaction()) {
            DB::rollBack();
        }
    }
    check($again !== null && str_contains($again, 'Only active payments'), 'reversing the same receipt twice is refused (' . $again . ')');
    eqm(Balances::open(Txn::load($d['inv1'])), Txn::load($d['inv1'])['total'], 'and changes nothing');
    // account 1's bill is unpaid again: it is corrected (the invoice voided and a revision -R1 posted, both kept and linked)
    $invNo = (string)Txn::load($d['inv1'])['txn_no'];
    $voided = WaterPosting::voidBill('water', $d['r1'], 'Meter misread (test)', 'replaced');
    eq(count($voided), 1, 'the original invoice is voided');
    eq((int)Txn::load($d['inv1'])['is_voided'], 1, 'and kept, marked void');
    $rev = WaterPosting::postBill('water', $d['r1'], ['water_account_id' => $d['w1'], 'txn_date' => '2026-10-06', 'doc_no' => $invNo, 'revision' => 1,
        'replaces' => WaterPosting::eventKey('water', $d['r1']), 'reason' => 'Meter misread (test)', 'lines' => [['account_id' => $wt['sales'], 'amount' => 300, 'description' => 'Corrected water bill']]]);
    eq((string)Txn::load($rev['txn_id'])['txn_no'], $invNo . '-R1', 'the revision is numbered invoice-R1');
    eq((int)WaterPosting::byEvent($rev['event_key'])['reversal_of_id'], (int)WaterPosting::byEvent(WaterPosting::eventKey('water', $d['r1']))['id'], 'the revision names what it replaced');
    refuses(fn() => WaterPosting::postBill('water', $d['r1'], ['water_account_id' => $d['w1'], 'txn_date' => '2026-10-06', 'lines' => [['account_id' => $wt['sales'], 'amount' => 1]]]), 'voided', 'posting the voided original again');
    // a bill with collections applied cannot be voided
    refuses(fn() => WaterPosting::voidBill('water', $d['r3'], 'should be refused'), 'collections applied', 'voiding a collected bill');
    // the documents of water events belong to the water module: the cooperative pages cannot settle, edit, void or
    // delete them (the water records and the ledger would part); the water corrections above can
    $j = $GLOBALS['juana'];
    $openInv = $rev['txn_id'];
    check(!in_array($openInv, array_map(fn($x) => (int)$x['id'], Balances::openReceivables((int)$j['customer'])), true), 'Receive Payments does not offer the open water bill of the same customer');
    refuses(fn() => Txn::save('payment', ['txn_date' => '2026-10-07', 'customer_id' => (int)$j['customer'], 'account_id' => (int)Accts::get('undeposited_funds'), 'account2_id' => (int)Accts::get('ar'),
        'total' => 100], [], null, ['keep_totals' => true, 'applications' => [['target_txn_id' => $openInv, 'amount' => 100]]]), 'collected on Water Billing', 'a cooperative payment applied to a water bill');
    refuses(fn() => Txn::save('invoice', ['txn_date' => '2026-10-07', 'customer_id' => (int)$j['customer']], [['line_kind' => 'item', 'account_id' => $wt['sales'], 'description' => 'x', 'qty' => 1, 'rate' => 1, 'amount' => 1]], $openInv), 'water module', 'editing a water invoice on the invoice page');
    refuses(fn() => Txn::void($openInv, 'should be refused'), 'water module', 'voiding a water invoice from the ledger');
    refuses(fn() => Txn::delete($openInv, 'should be refused'), 'water module', 'deleting a water invoice from the ledger');
    eq((int)Txn::load($openInv)['is_voided'], 0, 'the water invoice is untouched by the refused attempts');
    // the issuance of a material through the one inventory ledger
    $item = pt_item('WMTR-12', 'Water meter 1/2"', 'inventory', '1200', '750', false);
    $bill = pt_bill((int)$GLOBALS['juana']['vendor'], '2026-10-01', [['item_id' => $item, 'qty' => 5, 'rate' => 750]]);
    $onHand = fn() => (float)DB::value('SELECT COALESCE(SUM(qty_on_hand), 0) FROM item_site_stock WHERE item_id = :i', ['i' => $item]);
    eq($onHand(), 5.0, 'five meters received from the supplier (who is also the member)');
    $iss = DB::insert('inventory_issuances', ['issuance_no' => 'ISS-T-1', 'issuance_date' => '2026-10-03', 'recipient' => 'Field crew', 'department' => 'Operations', 'purpose' => 'Connection of account W900003',
        'expense_account_id' => pt_acct('6000') ?: (int)Accts::get('default_expense'), 'status' => 'active', 'created_by' => $GLOBALS['adminId'], 'created_at' => date('Y-m-d H:i:s')]);
    $r = WaterPosting::postIssuance($iss, ['txn_date' => '2026-10-03', 'expense_account_id' => pt_acct('6000') ?: (int)Accts::get('default_expense'), 'doc_no' => 'ISS-T-1',
        'lines' => [['item_id' => $item, 'qty' => 2, 'description' => 'Water meter']], 'site_id' => pt_site()]);
    eq($onHand(), 3.0, 'two issued to the field');
    eqm((string)DB::value('SELECT COALESCE(SUM(debit), 0) FROM journal_lines WHERE txn_id = :t', ['t' => $r['txn_id']]), '1500', 'charged at average cost 2 x 750');
    eq((int)WaterPosting::postIssuance($iss, ['txn_date' => '2026-10-03', 'expense_account_id' => (int)Accts::get('default_expense'), 'lines' => [['item_id' => $item, 'qty' => 2]]])['txn_id'], (int)$r['txn_id'], 'the same issuance again: nothing moves twice');
    WaterPosting::reverseIssuance($iss, 'Returned unused (test)');
    eq($onHand(), 5.0, 'the reversal returns the stock');
    eqm((string)DB::value('SELECT COALESCE(SUM(total_cost), 0) FROM inventory_movements WHERE item_id = :i', ['i' => $item]), '3750', 'valuation 5 x 750');
    assertLedgerBalanced('water corrections and materials');
    assertSlGl('water corrections and materials');
});
